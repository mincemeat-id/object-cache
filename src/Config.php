<?php
/**
 * Strict parsing and validation of MINCEMEAT_OBJECT_CACHE_CONFIG.
 *
 * The only v1 source is an array constant defined before WordPress loads the
 * drop-in. Unknown keys fail validation. Credentials are never exposed; the
 * redacted diagnostics view emits the namespace digest (not the source
 * namespace) and no passwords, usernames, DSNs, or TLS key material.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Immutable, validated configuration for the object cache.
 */
final class Config {

	/** Schema/version marker used in derived key layout. */
	public const SCHEMA_MARKER = 'mcoc1';

	/** Reason codes. */
	public const REASON_MISSING         = 'config-missing';
	public const REASON_NOT_ARRAY       = 'config-not-array';
	public const REASON_UNKNOWN_KEY     = 'config-unknown-key';
	public const REASON_NAMESPACE       = 'config-namespace';
	public const REASON_SCHEME          = 'config-scheme';
	public const REASON_HOST            = 'config-host';
	public const REASON_PORT            = 'config-port';
	public const REASON_PATH            = 'config-path';
	public const REASON_DATABASE        = 'config-database';
	public const REASON_USERNAME        = 'config-username';
	public const REASON_PASSWORD        = 'config-password';
	public const REASON_CONNECT_TIMEOUT = 'config-connect-timeout';
	public const REASON_READ_TIMEOUT    = 'config-read-timeout';
	public const REASON_PERSISTENT      = 'config-persistent';
	public const REASON_MAX_TTL         = 'config-max-ttl';
	public const REASON_TLS             = 'config-tls';
	public const REASON_DEBUG           = 'config-debug';

	/** Schemes. */
	public const SCHEME_TCP  = 'tcp';
	public const SCHEME_TLS  = 'tls';
	public const SCHEME_UNIX = 'unix';

	private const SCHEMES = array( self::SCHEME_TCP, self::SCHEME_TLS, self::SCHEME_UNIX );

	/** Upper bound for any single timeout in seconds. */
	public const MAX_TIMEOUT = 60.0;

	/** Maximum namespace length in bytes. */
	public const NAMESPACE_MAX_LENGTH = 255;

	/** Largest usable TCP port. */
	public const PORT_MIN = 1;
	public const PORT_MAX = 65535;

	/** Default maximum TTL: 30 days in seconds. */
	public const DEFAULT_MAX_TTL = 2592000;

	/**
	 * Known config keys, mapped to defaults applied when the key is absent.
	 *
	 * @var array<string,mixed>
	 */
	private const KNOWN_KEYS = array(
		'namespace'       => null,
		'scheme'          => self::SCHEME_TCP,
		'host'            => '127.0.0.1',
		'port'            => 6379,
		'path'            => null,
		'database'        => 0,
		'username'        => null,
		'password'        => null,
		'connect_timeout' => 0.25,
		'read_timeout'    => 0.25,
		'persistent'      => false,
		'max_ttl'         => self::DEFAULT_MAX_TTL,
		'tls'             => array(),
		'debug'           => false,
	);

	/** @var string */
	private $namespace;

	/** @var string */
	private $scheme;

	/** @var string */
	private $host;

	/** @var int */
	private $port;

	/** @var string|null */
	private $path;

	/** @var int */
	private $database;

	/** @var string|null */
	private $username;

	/** @var string|null */
	private $password;

	/** @var float */
	private $connect_timeout;

	/** @var float */
	private $read_timeout;

	/** @var bool */
	private $persistent;

	/** @var int */
	private $max_ttl;

	/** @var array<string,mixed> */
	private $tls;

	/** @var bool */
	private $debug;

	/** @var string */
	private $namespace_digest;

	/**
	 * @param array<string,mixed> $input Raw config array.
	 * @throws ConfigException On any validation failure.
	 */
	public function __construct( array $input) {
		$this->reject_unknown_keys( $input );

		$namespace = $input['namespace'] ?? null;
		$this->validate_namespace( $namespace );
		$this->namespace = $namespace;

		$scheme = $input['scheme'] ?? self::SCHEME_TCP;
		$this->validate_scheme( $scheme );
		$this->scheme = $scheme;

		$host = $input['host'] ?? self::KNOWN_KEYS['host'];
		$this->validate_host( $host, $this->scheme );
		$this->host = $scheme === self::SCHEME_UNIX ? self::KNOWN_KEYS['host'] : $host;

		$port = $input['port'] ?? self::KNOWN_KEYS['port'];
		$this->validate_port( $port, $this->scheme );
		$this->port = $scheme === self::SCHEME_UNIX ? self::KNOWN_KEYS['port'] : (int) $port;

		$path = $input['path'] ?? self::KNOWN_KEYS['path'];
		$this->validate_path( $path, $this->scheme );
		$this->path = $scheme === self::SCHEME_UNIX ? $path : null;

		$database = $input['database'] ?? self::KNOWN_KEYS['database'];
		$this->validate_database( $database );
		$this->database = (int) $database;

		$username = $input['username'] ?? null;
		$this->validate_username( $username );
		$this->username = $username === null ? null : (string) $username;

		$password = $input['password'] ?? null;
		$this->validate_password( $password );
		$this->password = $password === null ? null : (string) $password;

		$ct = $input['connect_timeout'] ?? self::KNOWN_KEYS['connect_timeout'];
		$this->validate_timeout( $ct, self::REASON_CONNECT_TIMEOUT );
		$this->connect_timeout = (float) $ct;

		$rt = $input['read_timeout'] ?? self::KNOWN_KEYS['read_timeout'];
		$this->validate_timeout( $rt, self::REASON_READ_TIMEOUT );
		$this->read_timeout = (float) $rt;

		$persistent = $input['persistent'] ?? self::KNOWN_KEYS['persistent'];
		$this->validate_bool( $persistent, self::REASON_PERSISTENT );
		$this->persistent = (bool) $persistent;

		$max_ttl = $input['max_ttl'] ?? self::KNOWN_KEYS['max_ttl'];
		$this->validate_max_ttl( $max_ttl );
		$this->max_ttl = (int) $max_ttl;

		$tls = $input['tls'] ?? self::KNOWN_KEYS['tls'];
		$this->validate_tls( $tls, $this->scheme );
		$this->tls = is_array( $tls ) ? $tls : array();

		$debug = $input['debug'] ?? self::KNOWN_KEYS['debug'];
		$this->validate_bool( $debug, self::REASON_DEBUG );
		$this->debug = (bool) $debug;

		$this->namespace_digest = hash( 'sha256', $this->namespace );
	}

	/**
	 * Reads and validates MINCEMEAT_OBJECT_CACHE_CONFIG.
	 *
	 * @throws ConfigException When the constant is missing or invalid.
	 */
	public static function from_constant(): Config {
		if ( ! defined( 'MINCEMEAT_OBJECT_CACHE_CONFIG' )) {
			throw new ConfigException( self::REASON_MISSING );
		}

		$input = MINCEMEAT_OBJECT_CACHE_CONFIG;

		if ( ! is_array( $input )) {
			throw new ConfigException( self::REASON_NOT_ARRAY );
		}

		return new self( $input );
	}

	/**
	 * The source namespace string (never exposed via redacted diagnostics).
	 *
	 * @return string
	 */
	public function namespace(): string {
		return $this->namespace;
	}

	/**
	 * SHA-256 digest of the source namespace; safe for diagnostics.
	 *
	 * @return string
	 */
	public function namespace_digest(): string {
		return $this->namespace_digest;
	}

	public function scheme(): string {
		return $this->scheme;
	}

	public function host(): string {
		return $this->host;
	}

	public function port(): int {
		return $this->port;
	}

	public function path(): ?string {
		return $this->path;
	}

	public function database(): int {
		return $this->database;
	}

	public function username(): ?string {
		return $this->username;
	}

	public function password(): ?string {
		return $this->password;
	}

	public function connect_timeout(): float {
		return $this->connect_timeout;
	}

	public function read_timeout(): float {
		return $this->read_timeout;
	}

	public function persistent(): bool {
		return $this->persistent;
	}

	public function max_ttl(): int {
		return $this->max_ttl;
	}

	/**
	 * The raw TLS context array. Exposed only to the PhpRedis adapter, never
	 * to the public API.
	 *
	 * @return array<string,mixed>
	 */
	public function tls(): array {
		return $this->tls;
	}

	public function debug(): bool {
		return $this->debug;
	}

	/**
	 * Redacted diagnostics suitable for Site Health. Includes only safe
	 * identifiers; never the source namespace, username, password, DSN, or
	 * TLS key material paths.
	 *
	 * @return array<string,mixed>
	 */
	public function redacted_diagnostics(): array {
		$tls_summary = array(
			'verify_peer'      => $this->tls_verify_peer(),
			'verify_peer_name' => $this->tls_verify_peer_name(),
		);

		return array(
			'scheme'           => $this->scheme,
			'host'             => $this->scheme === self::SCHEME_UNIX ? '' : $this->host,
			'port'             => $this->scheme === self::SCHEME_UNIX ? null : $this->port,
			'database'         => $this->database,
			'namespace_digest' => substr( $this->namespace_digest, 0, 16 ),
			'connect_timeout'  => $this->connect_timeout,
			'read_timeout'     => $this->read_timeout,
			'persistent'       => $this->persistent,
			'max_ttl'          => $this->max_ttl,
			'debug'            => $this->debug,
			'tls'              => $tls_summary,
		);
	}

	/**
	 * Whether peer verification is allowed to remain disabled (Site Health
	 * warns when TLS is configured and verification is off).
	 *
	 * @return bool
	 */
	public function tls_verify_peer(): bool {
		if (isset( $this->tls['verify_peer'] ) && $this->tls['verify_peer'] === false) {
			return false;
		}

		return true;
	}

	public function tls_verify_peer_name(): bool {
		if (isset( $this->tls['verify_peer_name'] ) && $this->tls['verify_peer_name'] === false) {
			return false;
		}

		return true;
	}

	/**
	 * Lists the known config keys.
	 *
	 * @return array<int,string>
	 */
	public static function known_keys(): array {
		return array_keys( self::KNOWN_KEYS );
	}

	private function reject_unknown_keys( array $input): void {
		foreach (array_keys( $input ) as $key) {
			if ( ! array_key_exists( $key, self::KNOWN_KEYS )) {
				throw new ConfigException( self::REASON_UNKNOWN_KEY, sprintf( 'Unknown config key "%s".', $this->redact_value( $key ) ) );
			}
		}
	}

	private function validate_namespace( $value): void {
		$reason = self::REASON_NAMESPACE;

		if ( ! is_string( $value )) {
			throw new ConfigException( $reason, 'Namespace must be a string.' );
		}

		if (trim( $value ) === '') {
			throw new ConfigException( $reason, 'Namespace must not be blank.' );
		}

		if (strlen( $value ) > self::NAMESPACE_MAX_LENGTH) {
			throw new ConfigException( $reason, 'Namespace exceeds the maximum length.' );
		}

		if (preg_match( '/[\x00-\x1F\x7F]/', $value ) === 1) {
			throw new ConfigException( $reason, 'Namespace must not contain control characters.' );
		}
	}

	private function validate_scheme( $value): void {
		if ( ! is_string( $value ) || ! in_array( $value, self::SCHEMES, true )) {
			throw new ConfigException( self::REASON_SCHEME, 'Scheme must be tcp, tls, or unix.' );
		}
	}

	private function validate_host( $value, string $scheme): void {
		if ($scheme === self::SCHEME_UNIX) {
			return;
		}

		if ( ! is_string( $value ) || trim( $value ) === '') {
			throw new ConfigException( self::REASON_HOST, 'Host is required for tcp/tls.' );
		}
	}

	private function validate_port( $value, string $scheme): void {
		if ($scheme === self::SCHEME_UNIX) {
			return;
		}

		$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
		if ( ! $ok) {
			throw new ConfigException( self::REASON_PORT, 'Port must be an integer.' );
		}

		$port = (int) $value;
		if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
			throw new ConfigException( self::REASON_PORT, 'Port must be between 1 and 65535.' );
		}
	}

	private function validate_path( $value, string $scheme): void {
		if ($scheme !== self::SCHEME_UNIX) {
			return;
		}

		if ( ! is_string( $value ) || trim( $value ) === '') {
			throw new ConfigException( self::REASON_PATH, 'Path is required for unix sockets.' );
		}
	}

	private function validate_database( $value): void {
		$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
		if ( ! $ok || (int) $value < 0) {
			throw new ConfigException( self::REASON_DATABASE, 'Database must be a non-negative integer.' );
		}
	}

	private function validate_username( $value): void {
		if ($value !== null && ! is_string( $value )) {
			throw new ConfigException( self::REASON_USERNAME, 'Username must be null or a string.' );
		}
	}

	private function validate_password( $value): void {
		if ($value !== null && ! is_string( $value )) {
			throw new ConfigException( self::REASON_PASSWORD, 'Password must be null or a string.' );
		}
	}

	/**
	 * @param mixed $value
	 */
	private function validate_timeout( $value, string $reason): void {
		$ok = is_int( $value )
			|| is_float( $value )
			|| ( is_string( $value ) && is_numeric( $value ) && strpos( $value, "\0" ) === false );
		if ( ! $ok) {
			throw new ConfigException( $reason, 'Timeout must be a positive number.' );
		}

		$f = (float) $value;
		if ($f <= 0 || $f > self::MAX_TIMEOUT) {
			throw new ConfigException( $reason, 'Timeout must be greater than zero and at most 60 seconds.' );
		}
	}

	private function validate_bool( $value, string $reason): void {
		if ( ! is_bool( $value ) && ! ( is_int( $value ) && ( $value === 0 || $value === 1 ) )) {
			throw new ConfigException( $reason, 'Value must be boolean.' );
		}
	}

	private function validate_max_ttl( $value): void {
		$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
		if ( ! $ok || (int) $value < 0) {
			throw new ConfigException( self::REASON_MAX_TTL, 'max_ttl must be a non-negative integer.' );
		}
	}

	private function validate_tls( $value, string $scheme): void {
		if ($value === null || $value === array()) {
			return;
		}

		if ( ! is_array( $value )) {
			throw new ConfigException( self::REASON_TLS, 'TLS context must be an array.' );
		}
	}

	/**
	 * Best-effort redaction of a value used in error messages.
	 *
	 * @param mixed $value
	 */
	private function redact_value( $value): string {
		if ( ! is_string( $value )) {
			return '(non-string)';
		}

		return $value;
	}
}
