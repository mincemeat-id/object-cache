<?php
/**
 * Key space: WordPress-compatible key validation, group normalization, and
 * identity derivation for both the in-memory runtime tier and the persistent
 * Redis/Valkey backend.
 *
 * Phase 1: in-memory scoped storage identifiers.
 * Phase 2: SHA-256 digest derivation, stable control/item key layout, and
 *          random 128-bit generation tokens for O(1) logical invalidation.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Validate identity and derive scoped storage identifiers.
 *
 * @internal This class is an implementation detail of the runtime cache and
 *           the generated drop-in. It is not part of the public API.
 */
final class KeySpace {

	/** Schema/version marker used in derived key layout. */
	public const SCHEMA_MARKER = 'mcoc1';

	/** Component markers within the derived key layout. */
	private const NS_TOKEN_MARKER    = 'nstok';
	private const GROUP_TOKEN_MARKER = 'gtok';
	private const ITEM_MARKER        = 'i';

	/** Single-site scope marker. */
	private const SINGLE_SITE_SCOPE = 's';

	/** Global scope marker. */
	private const GLOBAL_SCOPE = 'global';

	/**
	 * Random 128-bit generation token, lowercase hex (32 chars).
	 *
	 * @var string
	 */
	private $namespace_token = '';

	/**
	 * Registered global groups, keyed by group name for O(1) lookup.
	 *
	 * @var array<string,true>
	 */
	private $global_groups = array();

	/**
	 * Whether the cache is running in a multisite context.
	 *
	 * @var bool
	 */
	private $multisite;

	/**
	 * The blog prefix prepended to keys in non-global groups.
	 *
	 * @var string
	 */
	private $blog_prefix;

	/**
	 * The SHA-256 digest of the installation namespace; empty until set.
	 *
	 * @var string
	 */
	private $namespace_digest = '';

	/**
	 * Constructor.
	 *
	 * @param bool   $multisite Whether multisite is active.
	 * @param int    $blog_id   The current blog ID.
	 * @param string $namespace Optional installation namespace (Phase 3 injection).
	 */
	public function __construct( bool $multisite, int $blog_id, string $namespace = '') {
		$this->multisite   = $multisite;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

		if ($namespace !== '') {
			$this->namespace_digest = hash( 'sha256', $namespace );
		}
	}

	/**
	 * Sets or replaces the installation namespace digest from a Config.
	 *
	 * @param Config $config
	 */
	public function configure( Config $config): void {
		$this->namespace_digest = $config->namespace_digest();
	}

	/**
	 * Sets the namespace token for the current namespace generation.
	 *
	 * @param string $token 32-char lowercase hex token.
	 */
	public function set_namespace_token( string $token): void {
		$this->namespace_token = $token;
	}

	/**
	 * The currently memoized namespace token.
	 *
	 * @return string
	 */
	public function namespace_token(): string {
		return $this->namespace_token;
	}

	/**
	 * Registers one or more global groups.
	 *
	 * Accepts a scalar or array of group names, matching core behavior. Names
	 * are cast to string and deduplicated.
	 *
	 * @param string|string[] $groups A group or list of groups.
	 */
	public function add_global_groups( $groups): void {
		$groups = (array) $groups;

		foreach ($groups as $group) {
			$this->global_groups[ (string) $group ] = true;
		}
	}

	/**
	 * Whether the given group is registered as global.
	 *
	 * @param string $group Normalized group name.
	 * @return bool
	 */
	public function is_global_group( string $group): bool {
		return isset( $this->global_groups[ $group ] );
	}

	/**
	 * The registered global groups.
	 *
	 * @return array<string,true>
	 */
	public function global_groups(): array {
		return $this->global_groups;
	}

	/**
	 * Whether the cache is running in a multisite context.
	 *
	 * @return bool
	 */
	public function is_multisite(): bool {
		return $this->multisite;
	}

	/**
	 * Switches the active blog scope used to derive non-global item identity.
	 *
	 * @param int $blog_id The blog ID to switch to.
	 */
	public function switch_to_blog( int $blog_id): void {
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * The current blog prefix string (empty on single-site).
	 *
	 * @return string
	 */
	public function blog_prefix(): string {
		return $this->blog_prefix;
	}

	/**
	 * Validates a cache key against WordPress 6.9 rules.
	 *
	 * Integer keys and non-blank strings are valid. Any other type, and blank
	 * or whitespace-only strings, are invalid: `_doing_it_wrong()` is invoked
	 * when available and false is returned.
	 *
	 * @param mixed $key The key to validate.
	 * @return bool True when the key is valid.
	 */
	public function is_valid_key( $key): bool {
		if (is_int( $key )) {
			return true;
		}

		if (is_string( $key ) && '' !== trim( $key )) {
			return true;
		}

		$message = is_string( $key )
			? 'Cache key must not be an empty string.'
			: sprintf( 'Cache key must be an integer or a non-empty string, %s given.', gettype( $key ) );

		$caller = $this->invalid_key_caller();
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( $caller, $message, '1.0.0' );
		}

		return false;
	}

	/**
	 * Normalizes a group name: an empty group becomes 'default'.
	 *
	 * Groups are case-sensitive and never otherwise rewritten.
	 *
	 * @param string $group The raw group name.
	 * @return string The normalized group name.
	 */
	public function normalize_group( string $group): string {
		return '' === $group ? 'default' : $group;
	}

	/**
	 * Derives the in-memory storage identifier for a key within a group.
	 *
	 * For non-global groups under multisite, the active blog prefix is
	 * prepended so items cannot leak across blogs. Global groups use the key
	 * verbatim so they remain shared across the network.
	 *
	 * @param mixed  $key   The validated cache key.
	 * @param string $group The normalized group name.
	 * @return string The storage identifier.
	 */
	public function storage_id( $key, string $group): string {
		if ($this->multisite && ! isset( $this->global_groups[ $group ] )) {
			return $this->blog_prefix . $key;
		}

		return (string) $key;
	}

	/** ------------------------------------------------------------------
	 * Persistent key-space derivation (Phase 2)
	 * ---------------------------------------------------------------- */

	/**
	 * SHA-256 digest of the installation namespace (hex, lowercase, 64 chars).
	 *
	 * @return string
	 */
	public function namespace_digest(): string {
		return $this->namespace_digest;
	}

	/**
	 * SHA-256 digest of a group name (hex, lowercase, 64 chars).
	 *
	 * Group identity is case-sensitive and never normalized by removing
	 * characters; the digest is taken over the normalized group string.
	 *
	 * @param string $group Normalized group name.
	 * @return string
	 */
	public function group_digest( string $group): string {
		return hash( 'sha256', $group );
	}

	/**
	 * SHA-256 digest of a cache key (hex, lowercase, 64 chars).
	 *
	 * The original key is never normalized; `(string) $key` is hashed after
	 * WordPress validation so case and punctuation are preserved and Redis
	 * key-length concerns are eliminated.
	 *
	 * @param mixed $key The validated cache key.
	 * @return string
	 */
	public function key_digest( $key): string {
		return hash( 'sha256', (string) $key );
	}

	/**
	 * The item scope identifier for a group.
	 *
	 * Global groups share a network scope; non-global groups use the current
	 * blog ID under multisite and a stable single-site marker otherwise.
	 *
	 * @param string $group Normalized group name.
	 * @return string
	 */
	public function scope_for( string $group): string {
		if (isset( $this->global_groups[ $group ] )) {
			return self::GLOBAL_SCOPE;
		}

		if ($this->multisite) {
			return 'b' . rtrim( $this->blog_prefix, ':' );
		}

		return self::SINGLE_SITE_SCOPE;
	}

	/**
	 * The control key holding the random namespace generation token.
	 *
	 * @return string
	 */
	public function namespace_control_key(): string {
		return self::SCHEMA_MARKER . ':' . $this->namespace_digest . ':' . self::NS_TOKEN_MARKER;
	}

	/**
	 * The control key holding the random per-group generation token.
	 *
	 * Group-token identity deliberately excludes blog scope so a standard
	 * `flush_group` invalidates the group across the installation/network,
	 * while item keys remain blog/global scoped.
	 *
	 * @param string $group Normalized group name.
	 * @return string
	 */
	public function group_control_key( string $group): string {
		return self::SCHEMA_MARKER . ':' . $this->namespace_digest . ':g:' . $this->group_digest( $group ) . ':' . self::GROUP_TOKEN_MARKER;
	}

	/**
	 * The item key for a cached value.
	 *
	 * Carries both generation tokens so a change to either invalidates the
	 * item, plus scope and key digest for full logical identity.
	 *
	 * @param string $ns_token    Random namespace token (32 hex chars).
	 * @param string $group_token Random group token (32 hex chars).
	 * @param string $group       Normalized group name.
	 * @param mixed  $key         The validated cache key.
	 * @return string
	 */
	public function item_key( string $ns_token, string $group_token, string $group, $key): string {
		return self::SCHEMA_MARKER
			. ':' . $this->namespace_digest
			. ':' . self::ITEM_MARKER
			. ':' . $ns_token
			. ':' . $this->group_digest( $group )
			. ':' . $group_token
			. ':' . $this->scope_for( $group )
			. ':' . $this->key_digest( $key );
	}

	/**
	 * Generates a cryptographically secure random 128-bit hex token.
	 *
	 * @return string 32 lowercase hex characters.
	 * @throws \RuntimeException On CSPRNG failure.
	 */
	public static function generate_token(): string {
		$bytes = random_bytes( 16 );

		return bin2hex( $bytes );
	}

	/**
	 * Builds the `_doing_it_wrong` caller label for an invalid key.
	 *
	 * Uses a bounded backtrace to identify the public method that invoked
	 * validation, mirroring core's `Class::method` format. The caller's own
	 * class is used so a split between the public cache class and this
	 * component still reports the user-facing class.
	 *
	 * @return string
	 */
	private function invalid_key_caller(): string {
		$frame = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );

		// The deepest frame is this helper, the next is is_valid_key, and the
		// shallowest caller is the public method that triggered validation.
		$method = isset( $frame[2]['function'] ) ? $frame[2]['function'] : 'unknown';
		$class  = isset( $frame[2]['class'] ) ? $frame[2]['class'] : __CLASS__;

		return sprintf( '%s::%s', $class, $method );
	}
}
