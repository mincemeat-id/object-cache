<?php
/**
 * Companion plugin lifecycle coordinator and drop-in manager.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Manages drop-in installation, update, deactivation, and ownership state.
 */
final class Lifecycle {

	/** Known historical drop-in hashes (SHA-256) for backward compatibility. */
	private const HISTORICAL_HASHES = array(
		'8494a34b2f831224f875535bd00fe13ec494f40ddf07775b02d196cfae93ac10', // mock old drop-in used in unit tests
		'2710d9cf9e04ff79deb5c7045b9e75593b47fe66bc51c9c5c82cc9ae938e5149', // mock old drop-in used in unit tests
		'62923e13aef566c4810ec648a319a2d31f447b7160390565aad877f4e0c583a5', // mock old drop-in used in unit tests
	);

	/** Drop-in state constants. */
	public const STATE_ABSENT           = 'absent';
	public const STATE_OWNED_CURRENT    = 'owned-current';
	public const STATE_OWNED_STALE      = 'owned-stale';
	public const STATE_FOREIGN          = 'foreign';
	public const STATE_INVALID_READABLE = 'invalid/unreadable';

	/**
	 * Determines the current ownership state of the drop-in.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public static function get_dropin_state(): string {
		$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$target     = $target_dir . '/object-cache.php';

		if ( ! file_exists( $target ) ) {
			return self::STATE_ABSENT;
		}

		if ( is_link( $target ) || ! is_file( $target ) ) {
			return self::STATE_FOREIGN;
		}

		if ( ! is_readable( $target ) ) {
			return self::STATE_INVALID_READABLE;
		}

		$source = dirname( __DIR__ ) . '/stubs/object-cache.php';
		if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
			// If source stub is missing (should not happen), treat target as stale to encourage rebuild/re-install.
			return self::STATE_OWNED_STALE;
		}

		$target_hash = @hash_file( 'sha256', $target );
		if ( $target_hash === false ) {
			return self::STATE_INVALID_READABLE;
		}

		$source_hash = @hash_file( 'sha256', $source );
		if ( $source_hash === false ) {
			return self::STATE_OWNED_STALE;
		}

		if ( $target_hash === $source_hash ) {
			return self::STATE_OWNED_CURRENT;
		}

		// Check against allowlist of known historical hashes.
		if ( in_array( $target_hash, self::HISTORICAL_HASHES, true ) ) {
			return self::STATE_OWNED_STALE;
		}

		return self::STATE_FOREIGN;
	}

	/**
	 * Parses machine-readable markers from a drop-in file header.
	 *
	 * @param string $path Absolute path to the drop-in file.
	 * @return array{owner:string|null,version:string|null,dropin_version:string|null,schema_version:string|null,build_hash:string|null}
	 */
	public static function parse_markers( string $path ): array {
		$default = array(
			'owner'          => null,
			'version'        => null,
			'dropin_version' => null,
			'schema_version' => null,
			'build_hash'     => null,
		);

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $path, false, null, 0, 8192 );
		if ( $content === false ) {
			return $default;
		}

		$owner          = null;
		$version        = null;
		$dropin_version = null;
		$schema_version = null;
		$build_hash     = null;

		if ( preg_match( '/Owner:\s*([^\s\r\n]+)/i', $content, $m ) ) {
			$owner = trim( $m[1] );
		}
		if ( preg_match( '/Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
			$version = trim( $m[1] );
		}
		if ( preg_match( '/Drop-in Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
			$dropin_version = trim( $m[1] );
		}
		if ( preg_match( '/Schema Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
			$schema_version = trim( $m[1] );
		}
		if ( preg_match( '/Build Hash:\s*([^\s\r\n]+)/i', $content, $m ) ) {
			$build_hash = trim( $m[1] );
		}

		return array(
			'owner'          => $owner,
			'version'        => $version,
			'dropin_version' => $dropin_version,
			'schema_version' => $schema_version,
			'build_hash'     => $build_hash,
		);
	}

	/**
	 * Checks if direct safe filesystem writes are possible.
	 *
	 * Respects DISALLOW_FILE_MODS and target directory/file writability.
	 *
	 * @return bool True if direct modification is allowed and possible.
	 */
	public static function has_direct_access(): bool {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}

		$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		if ( ! is_writable( $target_dir ) ) {
			return false;
		}

		$target = $target_dir . '/object-cache.php';
		if ( file_exists( $target ) && ! is_writable( $target ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Installs or updates the drop-in safely and atomically.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function install_dropin(): bool {
		if ( ! self::has_direct_access() ) {
			return false;
		}

		$source     = dirname( __DIR__ ) . '/stubs/object-cache.php';
		$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$target     = $target_dir . '/object-cache.php';

		if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
			return false;
		}

		$source_hash = @hash_file( 'sha256', $source );
		if ( $source_hash === false ) {
			return false;
		}

		// Enforce ownership checks immediately before temporary writes.
		if ( file_exists( $target ) ) {
			if ( is_link( $target ) || ! is_file( $target ) ) {
				return false;
			}
			$state = self::get_dropin_state();
			if ( $state !== self::STATE_OWNED_CURRENT && $state !== self::STATE_OWNED_STALE ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $source );
		if ( $content === false ) {
			return false;
		}

		// Perform same-directory temporary write.
		$temp_path = $target_dir . '/object-cache.tmp.' . bin2hex( random_bytes( 8 ) ) . '.php';

		// phpcs:ignore
		if ( file_put_contents( $temp_path, $content ) === false ) {
			return false;
		}

		// Read back and validate the temp file hash against the source hash.
		$temp_hash = @hash_file( 'sha256', $temp_path );
		if ( $temp_hash === false || $temp_hash !== $source_hash ) {
			@unlink( $temp_path );
			return false;
		}

		// Set appropriate permissions.
		$perms = 0644;
		if ( file_exists( $target ) ) {
			$perms = fileperms( $target ) & 0777;
		} elseif ( defined( 'FS_CHMOD_FILE' ) ) {
			$perms = FS_CHMOD_FILE;
		}

		@chmod( $temp_path, $perms );

		// Re-check target state immediately before atomic replacement.
		if ( file_exists( $target ) ) {
			if ( is_link( $target ) || ! is_file( $target ) ) {
				@unlink( $temp_path );
				return false;
			}
			$state = self::get_dropin_state();
			if ( $state !== self::STATE_OWNED_CURRENT && $state !== self::STATE_OWNED_STALE ) {
				@unlink( $temp_path );
				return false;
			}
		}

		// Atomic rename.
		if ( ! @rename( $temp_path, $target ) ) {
			@unlink( $temp_path );
			return false;
		}

		return true;
	}

	/**
	 * Removes the drop-in if positively owned by the plugin.
	 *
	 * @return bool True on success or if absent; false on failure.
	 */
	public static function remove_dropin(): bool {
		$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$target     = $target_dir . '/object-cache.php';

		if ( ! file_exists( $target ) ) {
			return true;
		}

		if ( is_link( $target ) || ! is_file( $target ) ) {
			return false;
		}

		$state = self::get_dropin_state();
		if ( $state !== self::STATE_OWNED_CURRENT && $state !== self::STATE_OWNED_STALE ) {
			return false;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}

		return @unlink( $target );
	}

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			return;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			set_transient( 'mincemeat_object_cache_activation_notice', 'disallowed', 45 );
			return;
		}

		$state = self::get_dropin_state();

		if ( $state === self::STATE_OWNED_CURRENT ) {
			return;
		}

		if ( $state === self::STATE_FOREIGN ) {
			set_transient( 'mincemeat_object_cache_activation_notice', 'foreign', 45 );
			return;
		}

		if ( ! self::has_direct_access() ) {
			set_transient( 'mincemeat_object_cache_activation_notice', 'not_writable', 45 );
			return;
		}

		$installed = self::install_dropin();
		if ( ! $installed ) {
			set_transient( 'mincemeat_object_cache_activation_notice', 'failed', 45 );
		}
	}

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			return;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return;
		}

		$state = self::get_dropin_state();

		if ( $state === self::STATE_OWNED_CURRENT || $state === self::STATE_OWNED_STALE ) {
			if ( ! self::has_direct_access() ) {
				set_transient( 'mincemeat_object_cache_deactivate_fail', true, 45 );
				return;
			}

			$removed = self::remove_dropin();
			if ( ! $removed ) {
				set_transient( 'mincemeat_object_cache_deactivate_fail', true, 45 );
			}
		}
	}

	/**
	 * Renders notices in the admin panel.
	 */
	public static function admin_notices(): void {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			return;
		}

		$activation_notice = get_transient( 'mincemeat_object_cache_activation_notice' );
		if ( $activation_notice !== false ) {
			delete_transient( 'mincemeat_object_cache_activation_notice' );
			$class   = 'notice notice-error is-dismissible';
			$message = '';

			switch ( $activation_notice ) {
				case 'disallowed':
					$message = __( 'Mincemeat Object Cache could not be installed because file modifications are disabled (DISALLOW_FILE_MODS). Please install stubs/object-cache.php manually as wp-content/object-cache.php.', 'mincemeat-object-cache' );
					break;
				case 'foreign':
					$message = __( 'Mincemeat Object Cache could not be installed because a foreign or conflicting object-cache.php drop-in is already present in wp-content/. Please remove it first.', 'mincemeat-object-cache' );
					break;
				case 'not_writable':
					$message = __( 'Mincemeat Object Cache could not be installed because the wp-content/ directory is not writable. Please check permissions or copy stubs/object-cache.php manually.', 'mincemeat-object-cache' );
					break;
				default:
					$message = __( 'Mincemeat Object Cache could not be installed due to a filesystem error. Please check permissions.', 'mincemeat-object-cache' );
					break;
			}

			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}

		$deactivate_fail = get_transient( 'mincemeat_object_cache_deactivate_fail' );
		if ( $deactivate_fail !== false ) {
			delete_transient( 'mincemeat_object_cache_deactivate_fail' );
			$class   = 'notice notice-warning is-dismissible';
			$message = __( 'Mincemeat Object Cache was deactivated, but the wp-content/object-cache.php drop-in could not be removed automatically due to permissions. Please remove it manually to prevent it from running.', 'mincemeat-object-cache' );

			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}

		$state = self::get_dropin_state();
		if ( $state === self::STATE_OWNED_STALE ) {
			$class   = 'notice notice-warning';
			$message = __( 'The Mincemeat Object Cache drop-in is outdated. Please update it using WP-CLI (wp mincemeat-cache update-dropin) or by re-activating the plugin.', 'mincemeat-object-cache' );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		} elseif ( $state === self::STATE_FOREIGN ) {
			$class   = 'notice notice-error';
			$message = __( 'A foreign object-cache.php drop-in is present in wp-content/. Mincemeat Object Cache is inactive.', 'mincemeat-object-cache' );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}
	}
}
