<?php
/**
 * Backend: manages the PhpRedis connection, generation tokens, circuit
 * breaker state, and server identity. This is the persistent layer the
 * ObjectCache delegates to for non-persistent-group operations.
 *
 * Circuit breaker states (DESIGN 7.2):
 * - persistent:  backend is connected and commands may be issued.
 * - runtime-only: initialization failed (no extension, bad config, connect
 *                 failure, auth failure). No commands are attempted.
 * - degraded:    a command failed mid-request. The circuit is open for the
 *                rest of the request; the next request gets a fresh start.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Persistent backend coordinator: connection, tokens, circuit breaker.
 *
 * @internal
 */
final class Backend {

	/** Reason codes for the circuit breaker. */
	public const REASON_NO_BACKEND        = 'no-backend';
	public const REASON_MISSING_EXTENSION = 'missing-extension';
	public const REASON_CONFIG_INVALID    = 'config-invalid';
	public const REASON_CONNECT_FAILED    = 'connect-failed';
	public const REASON_AUTH_FAILED       = 'auth-failed';
	public const REASON_SELECT_DB_FAILED  = 'select-db-failed';
	public const REASON_COMMAND_FAILED    = 'command-failed';

	/**
	 * @var PhpRedisAdapter|null
	 */
	private $adapter;

	/**
	 * @var Config|null
	 */
	private $config;

	/**
	 * @var KeySpace
	 */
	private $key_space;

	/**
	 * Current circuit state: one of ObjectCache::STATE_*.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Stable reason code for the current state.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Memoized tokens: namespace => string, group => string.
	 *
	 * @var array<string,string>
	 */
	private $tokens = array();

	/**
	 * Whether the namespace token has been resolved this request.
	 *
	 * @var bool
	 */
	private $namespace_token_loaded = false;

	/**
	 * Sanitized server identity or null.
	 *
	 * @var array<string,string>|null
	 */
	private $server_info;

	/**
	 * Whether an error has been logged already this request.
	 *
	 * @var bool
	 */
	private $logged = false;

	/**
	 * Redacted last error message.
	 *
	 * @var string
	 */
	private $last_error_message = '';

	/**
	 * Whether the degraded action has been fired already this request.
	 *
	 * @var bool
	 */
	private $degraded_fired = false;

	public function __construct( KeySpace $key_space, ?PhpRedisAdapter $adapter = null ) {
		$this->key_space = $key_space;
		$this->state     = ObjectCache::STATE_RUNTIME_ONLY;
		$this->reason    = self::REASON_NO_BACKEND;
		$this->adapter   = $adapter;
	}

	/**
	 * Attempts to establish the persistent connection from a Config.
	 *
	 * On failure, the backend remains in runtime-only with a stable reason so
	 * the ObjectCache continues with coherent in-memory behavior.
	 *
	 * @param Config $config
	 */
	public function initialize( Config $config ): void {
		$this->config = $config;
		$this->key_space->configure( $config );

		if ($this->adapter === null) {
			$this->adapter = new PhpRedisAdapter();
		}

		try {
			$this->adapter->connect( $config );
		} catch (BackendException $e) {
			$this->state   = ObjectCache::STATE_RUNTIME_ONLY;
			$this->reason  = $e->reason();
			$this->adapter = null;

			$this->log_error( 'Initialization failed: ' . $e->reason(), $e );

			return;
		}

		$this->state  = ObjectCache::STATE_PERSISTENT;
		$this->reason = '';

		// Capture server identity for diagnostics (no behavior forks).
		$this->server_info = $this->adapter->server_info();
	}

	/**
	 * The current circuit state.
	 *
	 * @return string One of ObjectCache::STATE_*.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * The stable reason code for the current state.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Whether the backend is currently usable for commands.
	 *
	 * @return bool
	 */
	public function is_persistent(): bool {
		return $this->state === ObjectCache::STATE_PERSISTENT;
	}

	/**
	 * Returns the adapter required by persistent-only operations.
	 *
	 * @throws \LogicException If called outside the persistent invariant.
	 */
	private function adapter(): PhpRedisAdapter {
		if ($this->adapter === null) {
			throw new \LogicException( 'Persistent backend has no adapter.' );
		}

		return $this->adapter;
	}

	/**
	 * Transition the backend to runtime-only mode due to an external initialization failure.
	 *
	 * @param string     $reason    Stable reason category.
	 * @param \Throwable $exception Underlying exception.
	 */
	public function degrade_to_runtime_only( string $reason, \Throwable $exception ): void {
		$this->state  = ObjectCache::STATE_RUNTIME_ONLY;
		$this->reason = $reason;
		$this->log_error( 'Initialization failed: ' . $reason, $exception );
	}

	/**
	 * Returns the redacted last error message.
	 *
	 * @return string
	 */
	public function last_error(): string {
		return $this->last_error_message;
	}

	/**
	 * Sanitized server identity, or null when not connected.
	 *
	 * @return array<string,string>|null
	 */
	public function server_info(): ?array {
		return $this->server_info;
	}

	/**
	 * The configured max_ttl, or 0 when not initialized.
	 *
	 * @return int
	 */
	public function max_ttl(): int {
		return $this->config !== null ? $this->config->max_ttl() : 0;
	}

	/**
	 * Returns the configured connection scheme.
	 *
	 * @return string
	 */
	public function scheme(): string {
		return $this->config !== null ? $this->config->scheme() : 'none';
	}

	/**
	 * Returns the configuration instance, or null.
	 *
	 * @return Config|null
	 */
	public function config(): ?Config {
		return $this->config;
	}

	/**
	 * Resolves the namespace generation token, initializing it with
	 * SET NX on first use and memoizing for the request.
	 *
	 * @return string 32-char hex token, or empty string when not persistent.
	 */
	public function namespace_token(): string {
		if ( ! $this->is_persistent()) {
			return '';
		}

		if ($this->namespace_token_loaded) {
			return $this->tokens['ns'] ?? '';
		}

		$key   = $this->key_space->namespace_control_key();
		$token = $this->init_token( $key );

		$this->tokens['ns']           = $token;
		$this->namespace_token_loaded = true;

		return $token;
	}

	/**
	 * Resolves a group generation token, initializing it on first use.
	 *
	 * @param string $group Normalized group name.
	 * @return string 32-char hex token, or empty string when not persistent.
	 */
	public function group_token( string $group ): string {
		if ( ! $this->is_persistent()) {
			return '';
		}

		$cache_key = 'grp:' . $group;

		if (isset( $this->tokens[ $cache_key ] )) {
			return $this->tokens[ $cache_key ];
		}

		$key   = $this->key_space->group_control_key( $group );
		$token = $this->init_token( $key );

		$this->tokens[ $cache_key ] = $token;

		return $token;
	}

	/**
	 * Resolves group tokens for a batch of groups, coalescing into a single
	 * MGET after initializing any missing ones.
	 *
	 * @param array<int,string> $groups Normalized group names.
	 * @return array<string,string> Map of group name => token.
	 */
	public function group_tokens( array $groups ): array {
		if ( ! $this->is_persistent()) {
			return array();
		}

		$missing  = array();
		$resolved = array();

		foreach ($groups as $group) {
			$cache_key = 'grp:' . $group;
			if (isset( $this->tokens[ $cache_key ] )) {
				$resolved[ $group ] = $this->tokens[ $cache_key ];
			} else {
				$missing[] = $group;
			}
		}

		if (count( $missing ) === 0) {
			return $resolved;
		}

		$keys = array();
		foreach ($missing as $group) {
			$keys[] = $this->key_space->group_control_key( $group );
		}

		try {
			$values = $this->adapter()->mget( $keys );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );
			return array();
		}

		$still_missing = array();
		foreach ($missing as $i => $group) {
			$raw = $values[ $i ] ?? false;
			$tok = is_string( $raw ) ? trim( $raw ) : '';
			if ($tok !== '') {
				$this->tokens[ 'grp:' . $group ] = $tok;
				$resolved[ $group ]              = $tok;
			} else {
				$still_missing[] = $group;
			}
		}

		if (count( $still_missing ) > 0) {
			foreach ($still_missing as $group) {
				$key   = $this->key_space->group_control_key( $group );
				$token = $this->init_token( $key );
				if ($token === '') {
					$token = KeySpace::generate_token();
				}
				$this->tokens[ 'grp:' . $group ] = $token;
				$resolved[ $group ]              = $token;
			}
		}

		return $resolved;
	}

	/**
	 * Replaces the namespace generation token (used by flush).
	 *
	 * @return bool True on success.
	 */
	public function replace_namespace_token(): bool {
		if ( ! $this->is_persistent()) {
			return false;
		}

		$key   = $this->key_space->namespace_control_key();
		$token = KeySpace::generate_token();

		try {
			$ok = $this->adapter()->set_unconditional( $key, $token );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );
			return false;
		}

		if ($ok) {
			$this->tokens['ns'] = $token;
		}

		return $ok;
	}

	/**
	 * Replaces a group generation token (used by flush_group).
	 *
	 * @param string $group Normalized group name.
	 * @return bool True on success.
	 */
	public function replace_group_token( string $group ): bool {
		if ( ! $this->is_persistent()) {
			return false;
		}

		$key   = $this->key_space->group_control_key( $group );
		$token = KeySpace::generate_token();

		try {
			$ok = $this->adapter()->set_unconditional( $key, $token );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );
			return false;
		}

		if ($ok) {
			$this->tokens[ 'grp:' . $group ] = $token;
		}

		return $ok;
	}

	/**
	 * GET a single encoded value.
	 *
	 * @param string $key Backend key.
	 * @return string|false
	 */
	public function get( string $key ) {
		if ( ! $this->is_persistent()) {
			return false;
		}

		try {
			return $this->adapter()->get( $key );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return false;
		}
	}

	/**
	 * MGET a batch of encoded values.
	 *
	 * @param array<int,string> $keys
	 * @return array<int,string|false>
	 */
	public function mget( array $keys ): array {
		if ( ! $this->is_persistent()) {
			return array_fill( 0, count( $keys ), false );
		}

		try {
			return $this->adapter()->mget( $keys );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return array_fill( 0, count( $keys ), false );
		}
	}

	/**
	 * Conditional SET (NX for add, XX for replace).
	 *
	 * @param string   $key
	 * @param string   $value
	 * @param int|null $ttl_ms
	 * @param bool     $nx
	 * @param bool     $xx
	 * @return bool
	 */
	public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false ): bool {
		if ( ! $this->is_persistent()) {
			return false;
		}

		try {
			return $this->adapter()->set( $key, $value, $ttl_ms, $nx, $xx );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return false;
		}
	}

	/**
	 * Unconditional SET.
	 *
	 * @param string   $key
	 * @param string   $value
	 * @param int|null $ttl_ms
	 * @return bool
	 */
	public function set_unconditional( string $key, string $value, ?int $ttl_ms = null ): bool {
		if ( ! $this->is_persistent()) {
			return false;
		}

		try {
			return $this->adapter()->set_unconditional( $key, $value, $ttl_ms );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return false;
		}
	}

	/**
	 * DEL a single key.
	 *
	 * @param string $key
	 * @return int
	 */
	public function del( string $key ): int {
		if ( ! $this->is_persistent()) {
			return 0;
		}

		try {
			return $this->adapter()->del( $key );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return 0;
		}
	}

	/**
	 * DEL multiple keys via pipeline.
	 *
	 * @param array<int,string> $keys
	 * @return array<int,bool> Per-key success (true if deleted/cleared).
	 */
	public function del_pipeline( array $keys ): array {
		if ( ! $this->is_persistent() || count( $keys ) === 0) {
			return array_fill( 0, count( $keys ), false );
		}

		$commands = array();
		foreach ($keys as $key) {
			$commands[] = array( 'unlink', array( $key ) );
		}

		try {
			$results = $this->adapter()->pipeline( $commands );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return array_fill( 0, count( $keys ), false );
		}

		$out = array();
		foreach ($results as $i => $r) {
			$out[] = $r !== false && (int) $r > 0;
		}

		// If UNLINK produced an error result (false), fall back to DEL.
		if (in_array( false, $results, true )) {
			$commands = array();
			foreach ($keys as $key) {
				$commands[] = array( 'del', array( $key ) );
			}

			try {
				$results = $this->adapter()->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $keys ), false );
			}

			$out = array();
			foreach ($results as $i => $r) {
				$out[] = $r !== false && (int) $r > 0;
			}
		}

		return $out;
	}

	/**
	 * Set multiple keys via pipeline (all unconditional).
	 *
	 * @param array<int,array{0:string,1:string,2:?int}> $entries [key, value, ttl_ms].
	 * @return array<int,bool>
	 */
	public function set_pipeline( array $entries ): array {
		if ( ! $this->is_persistent() || count( $entries ) === 0) {
			return array_fill( 0, count( $entries ), false );
		}

		$commands = array();
		foreach ($entries as $entry) {
			$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], false, false );
		}

		try {
			$results = $this->adapter()->pipeline( $commands );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return array_fill( 0, count( $entries ), false );
		}

		$out = array();
		foreach ($results as $r) {
			$out[] = $r === true;
		}

		return $out;
	}

	/**
	 * Conditional set (NX/XX) for multiple keys via pipeline.
	 *
	 * @param array<int,array{0:string,1:string,2:?int,3:bool,4:bool}> $entries [key, value, ttl_ms, nx, xx].
	 * @return array<int,bool>
	 */
	public function set_conditional_pipeline( array $entries ): array {
		if ( ! $this->is_persistent() || count( $entries ) === 0) {
			return array_fill( 0, count( $entries ), false );
		}

		$commands = array();
		foreach ($entries as $entry) {
			$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], $entry[3], $entry[4] );
		}

		try {
			$results = $this->adapter()->pipeline( $commands );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return array_fill( 0, count( $entries ), false );
		}

		$out = array();
		foreach ($results as $r) {
			$out[] = $r === true;
		}

		return $out;
	}

	/**
	 * EVAL a Lua script.
	 *
	 * @param string             $script
	 * @param array<int,string> $keys
	 * @param array<int,mixed>  $args
	 * @return mixed
	 */
	public function eval( string $script, array $keys = array(), array $args = array() ) {
		if ( ! $this->is_persistent()) {
			return false;
		}

		try {
			return $this->adapter()->eval( $script, $keys, $args );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return false;
		}
	}

	/**
	 * Atomic increment/decrement via Lua script.
	 *
	 * @param string $item_key The backend item key.
	 * @param int    $offset   Signed offset (positive for incr, negative for decr).
	 * @return array{0:string,1:?int} Tuple [result_code, new_value].
	 *         result_code is one of LuaScripts::RESULT_*; new_value is null
	 *         for all non-success results.
	 */
	public function eval_incr( string $item_key, int $offset ): array {
		if ( ! $this->is_persistent()) {
			return array( LuaScripts::RESULT_MISSING, null );
		}

		try {
			$result = $this->adapter()->eval(
				LuaScripts::INCR_DECR,
				array( $item_key ),
				array( (string) $offset )
			);
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return array( LuaScripts::RESULT_MISSING, null );
		}

		if ($result === false) {
			$this->degrade( self::REASON_COMMAND_FAILED );

			return array( LuaScripts::RESULT_MISSING, null );
		}

		if ( ! is_array( $result ) || ! isset( $result[0] )) {
			return array( LuaScripts::RESULT_CORRUPT, null );
		}

		$code = (string) $result[0];

		if ($code === LuaScripts::RESULT_OK && isset( $result[1] )) {
			$val = $result[1];
			return array( LuaScripts::RESULT_OK, (int) $val );
		}

		return array( $code, null );
	}

	/**
	 * Closes the connection.
	 *
	 * @return void
	 */
	public function close(): void {
		if ($this->adapter !== null) {
			$this->adapter->close();
		}
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Initializes a token via SET NX and reads back the winner.
	 *
	 * If the SET NX succeeds, the generated token is the winner. If someone
	 * else won the race, we read back their token. Either way, the token is
	 * stable for this request.
	 *
	 * @param string $key The control key.
	 * @return string 32-char hex token.
	 */
	private function init_token( string $key ): string {
		if ( ! $this->is_persistent()) {
			return '';
		}

		$token = KeySpace::generate_token();

		try {
			$created = $this->adapter()->set( $key, $token, null, true );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return KeySpace::generate_token();
		}

		if ($created) {
			return $token;
		}

		// Someone else won the race; read back their token.
		try {
			$existing = $this->adapter()->get( $key );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return KeySpace::generate_token();
		}

		if (is_string( $existing ) && trim( $existing ) !== '') {
			return trim( $existing );
		}

		// Edge: control key was evicted between SET NX failure and GET.
		// Retry with a fresh token.
		$token2 = KeySpace::generate_token();
		try {
			$created2 = $this->adapter()->set( $key, $token2, null, true );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return KeySpace::generate_token();
		}

		if ($created2) {
			return $token2;
		}

		// Try one more get
		try {
			$existing2 = $this->adapter()->get( $key );
		} catch (\Throwable $e) {
			$this->degrade( self::REASON_COMMAND_FAILED, $e );

			return KeySpace::generate_token();
		}

		if (is_string( $existing2 ) && trim( $existing2 ) !== '') {
			return trim( $existing2 );
		}

		return KeySpace::generate_token();
	}

	/**
	 * Opens the circuit breaker: transitions to degraded state, fires the
	 * low-volume action once, and prevents all further backend commands.
	 *
	 * @param string          $reason
	 * @param \Throwable|null $exception
	 */
	private function degrade( string $reason, ?\Throwable $exception = null ): void {
		if ($this->state === ObjectCache::STATE_DEGRADED) {
			return;
		}

		$this->state  = ObjectCache::STATE_DEGRADED;
		$this->reason = $reason;

		$this->log_error( 'Backend degraded: ' . $reason, $exception );

		if ( ! $this->degraded_fired && function_exists( 'do_action' )) {
			$this->degraded_fired = true;
			do_action( 'mincemeat_object_cache_degraded', $reason );
		}
	}

	/**
	 * Logs a sanitized, redacted error message to the error log exactly once per request.
	 *
	 * @internal
	 * @param string          $message   The log message.
	 * @param \Throwable|null $exception Optional associated exception.
	 */
	public function log_error( string $message, ?\Throwable $exception = null ): void {
		if ( $this->logged ) {
			return;
		}
		$this->logged = true;

		$raw_msg                  = $exception !== null ? $exception->getMessage() : $message;
		$this->last_error_message = $this->redact_secrets( $raw_msg );

		$log_msg = 'Mincemeat Object Cache: ' . $message;
		if ( $this->config !== null && $this->config->debug() ) {
			if ( $exception !== null ) {
				$log_msg .= sprintf(
					' (Exception: %s, Message: %s, Code: %d)',
					get_class( $exception ),
					$this->redact_secrets( $exception->getMessage() ),
					$exception->getCode()
				);
			}
		}

		error_log( $log_msg );
	}

	/**
	 * Redacts credentials and other sensitive data from a string.
	 *
	 * @param string $msg Input string.
	 * @return string Redacted string.
	 */
	private function redact_secrets( string $msg ): string {
		if ( $this->config === null ) {
			return $msg;
		}

		$search = array();

		$password = $this->config->password();
		if ( $password !== null && $password !== '' ) {
			$search[] = $password;
		}

		$username = $this->config->username();
		if ( $username !== null && $username !== '' ) {
			$search[] = $username;
		}

		$namespace = $this->config->namespace();
		if ( $namespace !== null && $namespace !== '' ) {
			$search[] = $namespace;
		}

		$path = $this->config->path();
		if ( $path !== null && $path !== '' ) {
			$search[] = $path;
		}

		$host = $this->config->host();
		if ( $host !== null && $host !== '' && ! in_array( strtolower( $host ), array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
			$search[] = $host;
		}

		$tls = $this->config->tls();
		if ( is_array( $tls ) ) {
			foreach ( $tls as $k => $v ) {
				if ( is_string( $v ) && $v !== '' ) {
					$search[] = $v;
				}
			}
		}

		if ( count( $search ) > 0 ) {
			usort(
				$search,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$msg = str_replace( $search, '[REDACTED]', $msg );
		}

		// Redact any DSN/URL credentials style (e.g. scheme://username:password@host)
		$msg = (string) preg_replace( '/([a-zA-Z0-9+-.]+\:\/\/)?([^:@\s\/\?\#]+):([^@\s\/\?\#]+)@/', '$1[REDACTED]:[REDACTED]@', $msg );
		// Redact password only credentials like scheme://:password@host or :password@host
		$msg = (string) preg_replace( '/([a-zA-Z0-9+-.]+\:\/\/)?([^:@\s\/\?\#]*):([^@\s\/\?\#]+)@/', '$1[REDACTED]:[REDACTED]@', $msg );

		return $msg;
	}

	/**
	 * Builds a pipeline SET command array.
	 *
	 * @param string   $key
	 * @param string   $value
	 * @param int|null $ttl_ms
	 * @param bool     $nx
	 * @param bool     $xx
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_set_command( string $key, string $value, ?int $ttl_ms, bool $nx, bool $xx ): array {
		$args = array( $key, $value );

		$options = array();
		if ($nx) {
			$options[] = 'NX';
		}
		if ($xx) {
			$options[] = 'XX';
		}
		if ($ttl_ms !== null && $ttl_ms > 0) {
			$options['PX'] = $ttl_ms;
		}

		if (count( $options ) > 0) {
			$args[] = $options;
		}

		return array( 'set', $args );
	}
}
