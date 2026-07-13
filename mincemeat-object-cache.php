<?php
/**
 * Plugin Name:       Mincemeat Object Cache
 * Plugin URI:        https://github.com/mincemeat-id/object-cache
 * Description:        Companion plugin for the Mincemeat Object Cache drop-in; provides drop-in lifecycle, Site Health, and minimal WP-CLI. The runtime cache lives in the generated standalone object-cache.php.
 * Version:            1.0.0-rc1
 * Requires at least:  6.9
 * Requires PHP:       7.4
 * Author:             the Mincemeat Object Cache developers
 * Author URI:         https://github.com/mincemeat-id
 * License:            GPL-3.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        mincemeat-object-cache
 * Domain Path:        /languages
 *
 * @package Mincemeat\ObjectCache
 */

defined( 'ABSPATH' ) || exit;

// Check requirements.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, '6.9', '<' ) ) {
	return;
}

// Register a PSR-4 autoloader for the Mincemeat\ObjectCache namespace.
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'Mincemeat\\ObjectCache\\';
		$base_dir = __DIR__ . '/src/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Register lifecycle hooks.
register_activation_hook( __FILE__, array( 'Mincemeat\ObjectCache\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mincemeat\ObjectCache\Lifecycle', 'deactivate' ) );

// Wire admin hooks.
if ( is_admin() ) {
	add_filter( 'site_status_tests', array( 'Mincemeat\ObjectCache\SiteHealth', 'register_tests' ) );
	add_filter( 'debug_information', array( 'Mincemeat\ObjectCache\SiteHealth', 'debug_information' ) );
	add_action( 'admin_notices', array( 'Mincemeat\ObjectCache\Lifecycle', 'admin_notices' ) );
	add_action( 'network_admin_notices', array( 'Mincemeat\ObjectCache\Lifecycle', 'admin_notices' ) );
}

// Wire WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'mincemeat-cache', 'Mincemeat\ObjectCache\CliCommand' );
}

