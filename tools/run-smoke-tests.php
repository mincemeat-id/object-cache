<?php
/**
 * Strict compatibility smoke test for pinned third-party plugins.
 *
 * The command boots real plugin entry points against the WordPress test
 * installation, runs their activation installers, rejects unexpected PHP
 * diagnostics and post-install database errors, and writes a bounded
 * machine-readable result.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

const MINCEMEAT_SMOKE_SCHEMA_VERSION = 1;
const MINCEMEAT_SMOKE_MAX_DIAGNOSTICS = 50;
const MINCEMEAT_SMOKE_MAX_OUTPUT_BYTES = 65536;

/**
 * Writes the smoke result to its deterministic report path.
 *
 * @param array<string,mixed> $report
 * @param string              $path
 */
function mincemeat_smoke_write_report( array $report, string $path ): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		fwrite( STDERR, "Unable to create the smoke report directory.\n" );
		return;
	}

	$encoded = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) || file_put_contents( $path, $encoded . "\n" ) === false ) {
		fwrite( STDERR, "Unable to write the smoke report.\n" );
	}
}

/**
 * Reads a WordPress plugin Version header without loading the plugin.
 */
function mincemeat_smoke_plugin_version( string $path ): string {
	$header = file_get_contents( $path, false, null, 0, 8192 );
	if ( ! is_string( $header ) || preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $matches ) !== 1 ) {
		return '';
	}

	return trim( $matches[1] );
}

/**
 * Separates expected plugin schema-discovery misses from actionable errors.
 *
 * WordPress records a missing-table query even when a plugin installer is
 * about to create that exact table. Only missing tables owned by the pinned
 * plugin families are accepted, and representative tables are verified after
 * all installers finish.
 *
 * @param array<int,mixed> $errors WordPress database errors.
 * @param string           $prefix Active WordPress table prefix.
 * @return array{0:array<int,mixed>,1:int} Remaining errors and accepted probes.
 */
function mincemeat_smoke_partition_installer_errors( array $errors, string $prefix ): array {
	$remaining = array();
	$accepted  = 0;
	$pattern   = "/^Table '[^']+\\." . preg_quote( $prefix, '/' ) . "(?:yoast_|woocommerce_|wc_|actionscheduler_|edd_)[^']*' doesn't exist$/";

	foreach ($errors as $error) {
		$message = is_array( $error ) ? (string) ( $error['error_str'] ?? '' ) : '';
		if (preg_match( $pattern, $message ) === 1) {
			++$accepted;
			continue;
		}
		$remaining[] = $error;
	}

	return array( $remaining, $accepted );
}

$root_dir    = dirname( __DIR__ );
$wp_tests_dir = realpath( $root_dir . '/tests/wp-tests' );
$dropin_src   = realpath( $root_dir . '/stubs/object-cache.php' );
$report_path  = getenv( 'MINCEMEAT_SMOKE_REPORT' );
if ( ! is_string( $report_path ) || $report_path === '' ) {
	$report_path = $root_dir . '/build/logs/smoke-result.json';
}

$report = array(
	'schema_version'          => MINCEMEAT_SMOKE_SCHEMA_VERSION,
	'status'                  => 'fail',
	'wordpress_version'       => null,
	'php_version'             => PHP_VERSION,
	'backend_product'         => null,
	'plugins'                 => array(),
	'php_diagnostic_count'    => 0,
	'allowed_diagnostic_count' => 0,
	'installer_database_probe_count' => 0,
	'database_error_count'    => 0,
	'captured_output_bytes'   => 0,
	'failures'                => array(),
	'diagnostics'             => array(),
);
$report_written = false;

register_shutdown_function(
	function () use (&$report, &$report_written, $report_path): void {
		if ($report_written) {
			return;
		}

		$last_error = error_get_last();
		if (is_array( $last_error ) && in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true )) {
			$report['failures'][] = 'fatal-php-error';
			$report['diagnostics'][] = array(
				'severity' => 'fatal',
				'message'  => substr( (string) $last_error['message'], 0, 500 ),
				'file'     => basename( (string) $last_error['file'] ),
				'line'     => (int) $last_error['line'],
			);
		}
		mincemeat_smoke_write_report( $report, $report_path );
	}
);

if ( ! is_string( $wp_tests_dir ) || ! is_dir( $wp_tests_dir ) ) {
	$report['failures'][] = 'wordpress-test-installation-missing';
	mincemeat_smoke_write_report( $report, $report_path );
	$report_written = true;
	fwrite( STDERR, "Smoke test failed: WordPress test installation is missing.\n" );
	exit( 1 );
}

if ( ! is_string( $dropin_src ) || ! is_file( $dropin_src ) ) {
	$report['failures'][] = 'dropin-stub-missing';
	mincemeat_smoke_write_report( $report, $report_path );
	$report_written = true;
	fwrite( STDERR, "Smoke test failed: generated drop-in is missing.\n" );
	exit( 1 );
}

$plugins_dir = $wp_tests_dir . '/src/wp-content/plugins';
$plugins     = array(
	'woocommerce' => array(
		'file'             => $plugins_dir . '/woocommerce/woocommerce.php',
		'expected_version' => '10.9.4',
		'version_constant' => 'WC_VERSION',
	),
	'wordpress-seo' => array(
		'file'             => $plugins_dir . '/wordpress-seo/wp-seo.php',
		'expected_version' => '28.0',
		'version_constant' => 'WPSEO_VERSION',
	),
	'easy-digital-downloads' => array(
		'file'             => $plugins_dir . '/easy-digital-downloads/easy-digital-downloads.php',
		'expected_version' => '3.6.9',
		'version_constant' => 'EDD_VERSION',
	),
);

foreach ($plugins as $slug => $plugin) {
	$file = $plugin['file'];
	if ( ! is_file( $file ) ) {
		$report['failures'][] = 'plugin-missing:' . $slug;
		$report['plugins'][ $slug ] = array(
			'expected_version' => $plugin['expected_version'],
			'installed_version' => null,
			'loaded_version'    => null,
		);
		continue;
	}

	$installed_version = mincemeat_smoke_plugin_version( $file );
	$report['plugins'][ $slug ] = array(
		'expected_version'  => $plugin['expected_version'],
		'installed_version' => $installed_version,
		'loaded_version'    => null,
	);
	if ($installed_version !== $plugin['expected_version']) {
		$report['failures'][] = 'plugin-version-mismatch:' . $slug;
	}
}

if (count( $report['failures'] ) > 0) {
	mincemeat_smoke_write_report( $report, $report_path );
	$report_written = true;
	fwrite( STDERR, "Smoke test failed during plugin preflight; see build/logs/smoke-result.json.\n" );
	exit( 1 );
}

$dropin_dest = $wp_tests_dir . '/src/wp-content/object-cache.php';
if ( ! copy( $dropin_src, $dropin_dest ) ) {
	$report['failures'][] = 'dropin-copy-failed';
	mincemeat_smoke_write_report( $report, $report_path );
	$report_written = true;
	fwrite( STDERR, "Smoke test failed: generated drop-in could not be installed.\n" );
	exit( 1 );
}

$redis_host = getenv( 'MINCEMEAT_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$redis_port = (int) ( getenv( 'MINCEMEAT_TEST_REDIS_PORT' ) ?: 6383 );
$object_cache_config = array(
	'scheme'          => 'tcp',
	'host'            => $redis_host,
	'port'            => $redis_port,
	'database'        => 0,
	'connect_timeout' => 1.0,
	'read_timeout'    => 1.0,
	'namespace'       => 'smoke-test-ns',
);
putenv( 'MINCEMEAT_OBJECT_CACHE_CONFIG=' . json_encode( $object_cache_config ) );

$mysql_port = (int) ( getenv( 'MINCEMEAT_TEST_DB_PORT' ) ?: 33076 );
$db_host    = '127.0.0.1:' . $mysql_port;
$_ENV['DB_HOST']    = $db_host;
$_SERVER['DB_HOST'] = $db_host;
putenv( 'DB_HOST=' . $db_host );

$php_diagnostics = array();
$allowed_diagnostics = 0;
$diagnostic_count = 0;

// Third-party exceptions must stay explicit and narrowly matched. RC2 needs none.
$third_party_diagnostic_allowlist = array();

// WordPress 6.9's source tag omits three generated script manifests. Plugin
// activation reaches the relevant core loaders, so tolerate only the exact
// missing-manifest and immediate foreach warnings from those core files.
$core_source_fixture_allowlist = array(
	array(
		'severity'       => E_WARNING,
		'message_pattern' => '/(?:(?:script-loader-react-refresh-entry|script-loader-packages|script-modules-packages)\.php.*(?:Failed to open stream|Failed opening)|(?:Failed to open stream|Failed opening).*(?:script-loader-react-refresh-entry|script-loader-packages|script-modules-packages)\.php)/i',
		'file_pattern'    => '/wp-includes\/(?:script-loader|script-modules)\.php$/',
	),
	array(
		'severity'       => E_WARNING,
		'message_pattern' => '/^(?:foreach\(\) argument must be of type array\|object, (?:bool|false) given|Invalid argument supplied for foreach\(\))$/',
		'file_pattern'    => '/wp-includes\/(?:script-loader|script-modules)\.php$/',
	),
);
$diagnostic_allowlist = array_merge( $third_party_diagnostic_allowlist, $core_source_fixture_allowlist );

set_error_handler(
	function ( int $severity, string $message, string $file, int $line ) use (&$php_diagnostics, &$allowed_diagnostics, &$diagnostic_count, $diagnostic_allowlist): bool {
		if (( error_reporting() & $severity ) === 0) {
			return false;
		}

		foreach ($diagnostic_allowlist as $allowed) {
			if ($severity === $allowed['severity'] && preg_match( $allowed['message_pattern'], $message ) === 1 && preg_match( $allowed['file_pattern'], $file ) === 1) {
				++$allowed_diagnostics;
				return true;
			}
		}

		++$diagnostic_count;
		if (count( $php_diagnostics ) < MINCEMEAT_SMOKE_MAX_DIAGNOSTICS) {
			$php_diagnostics[] = array(
				'severity' => $severity,
				'message'  => substr( $message, 0, 500 ),
				'file'     => basename( $file ),
				'line'     => $line,
			);
		}
		return true;
	}
);

$installer_database_probe_count = 0;

require_once $wp_tests_dir . '/tests/phpunit/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ($plugins, &$installer_database_probe_count): void {
		global $wpdb;
		if (isset( $wpdb ) && is_object( $wpdb )) {
			// Keep output bounded; EZSQL_ERROR remains the failure source of truth.
			$wpdb->suppress_errors( true );
		}

		foreach ($plugins as $plugin) {
			require_once $plugin['file'];
		}

		// Install component tables before init-time cron loaders query them.
		add_action(
			'plugins_loaded',
			function () use (&$installer_database_probe_count): void {
				global $wpdb;
				if (class_exists( 'WC_Install' )) {
					WC_Install::create_tables();
				}
				if (function_exists( 'edd_setup_components' ) && function_exists( 'edd_install_component_database_tables' )) {
					edd_setup_components();
					edd_install_component_database_tables();
				}
				$errors = isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? $GLOBALS['EZSQL_ERROR'] : array();
				list( $GLOBALS['EZSQL_ERROR'], $accepted ) = mincemeat_smoke_partition_installer_errors( $errors, $wpdb->prefix );
				$installer_database_probe_count += $accepted;
			},
			101
		);

		// Run the remaining activation installers after Action Scheduler's init
		// priority 1 data stores and WordPress rewrite globals are ready.
		add_action(
			'init',
			function () use (&$installer_database_probe_count): void {
				global $wpdb;
				if (class_exists( 'WC_Install' )) {
					WC_Install::install();
				}
				if (function_exists( 'wpseo_activate' )) {
					wpseo_activate( false );
				}
				if (function_exists( 'edd_install' )) {
					edd_install( false );
				}
				$errors = isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? $GLOBALS['EZSQL_ERROR'] : array();
				list( $GLOBALS['EZSQL_ERROR'], $accepted ) = mincemeat_smoke_partition_installer_errors( $errors, $wpdb->prefix );
				$installer_database_probe_count += $accepted;
			},
			6
		);
	}
);

$GLOBALS['EZSQL_ERROR'] = array();
ob_start();
try {
	require_once $wp_tests_dir . '/tests/phpunit/includes/bootstrap.php';
} catch (\Throwable $exception) {
	$captured_output = (string) ob_get_clean();
	$report['captured_output_bytes'] = strlen( $captured_output );
	$report['failures'][] = 'bootstrap-exception';
	$report['diagnostics'][] = array(
		'severity' => 'fatal',
		'message'  => substr( $exception->getMessage(), 0, 500 ),
		'file'     => basename( $exception->getFile() ),
		'line'     => $exception->getLine(),
	);
	mincemeat_smoke_write_report( $report, $report_path );
	$report_written = true;
	fwrite( STDERR, "Smoke test failed during WordPress bootstrap; see build/logs/smoke-result.json.\n" );
	exit( 1 );
}
$captured_output = (string) ob_get_clean();
$report['captured_output_bytes'] = strlen( $captured_output );
$report['installer_database_probe_count'] = $installer_database_probe_count;
if (strlen( $captured_output ) > MINCEMEAT_SMOKE_MAX_OUTPUT_BYTES) {
	$report['failures'][] = 'bootstrap-output-limit-exceeded';
}
if (stripos( $captured_output, 'WordPress database error' ) !== false || stripos( $captured_output, 'wpdberror' ) !== false) {
	$report['failures'][] = 'database-error-output';
}

global $wp_object_cache, $wpdb, $wp_version;
$report['wordpress_version'] = isset( $wp_version ) ? (string) $wp_version : null;

foreach ($plugins as $slug => $plugin) {
	$constant = $plugin['version_constant'];
	$loaded_version = defined( $constant ) ? (string) constant( $constant ) : null;
	$report['plugins'][ $slug ]['loaded_version'] = $loaded_version;
	if ($loaded_version !== $plugin['expected_version']) {
		$report['failures'][] = 'plugin-not-loaded-exactly:' . $slug;
	}
}

$database_errors = isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] )
	? $GLOBALS['EZSQL_ERROR']
	: array();
$report['database_error_count'] = count( $database_errors );
foreach (array_slice( $database_errors, 0, MINCEMEAT_SMOKE_MAX_DIAGNOSTICS ) as $database_error) {
	$report['diagnostics'][] = array(
		'severity' => 'database',
		'message'  => substr( (string) ( $database_error['error_str'] ?? 'unknown database error' ), 0, 500 ),
	);
}
if (count( $database_errors ) > 0) {
	$report['failures'][] = 'wordpress-database-errors';
}

$report['php_diagnostic_count']     = $diagnostic_count;
$report['allowed_diagnostic_count'] = $allowed_diagnostics;
$report['diagnostics'] = array_merge( $report['diagnostics'], $php_diagnostics );
if ($diagnostic_count > 0) {
	$report['failures'][] = 'unexpected-php-diagnostics';
}

if ( ! isset( $wp_object_cache ) || ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'state' )) {
	$report['failures'][] = 'object-cache-global-missing';
} elseif ($wp_object_cache->state() !== 'persistent') {
	$report['failures'][] = 'object-cache-not-persistent';
} else {
	$server_info = $wp_object_cache->server_info();
	$report['backend_product'] = is_array( $server_info ) ? ( $server_info['product'] ?? 'unknown' ) : 'unknown';
}

if ( ! function_exists( 'WC' ) || ! is_object( WC() )) {
	$report['failures'][] = 'woocommerce-api-unavailable';
}
if ( ! function_exists( 'YoastSEO' ) || ! is_object( YoastSEO() )) {
	$report['failures'][] = 'yoast-api-unavailable';
}
if ( ! function_exists( 'EDD' ) || ! is_object( EDD() )) {
	$report['failures'][] = 'edd-api-unavailable';
}

if (isset( $wpdb ) && is_object( $wpdb )) {
	$required_tables = array(
		$wpdb->prefix . 'yoast_migrations',
		$wpdb->prefix . 'woocommerce_sessions',
		$wpdb->prefix . 'actionscheduler_actions',
		$wpdb->prefix . 'edd_customers',
	);
	foreach ($required_tables as $required_table) {
		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $required_table ) );
		if ($found_table !== $required_table) {
			$report['failures'][] = 'plugin-schema-missing:' . preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $required_table );
		}
	}
}

$key   = 'smoke_test_key';
$value = array( 'foo' => 'bar', 'nested' => array( 1, 2, 3 ) );
if ( ! wp_cache_set( $key, $value, 'options', 60 )) {
	$report['failures'][] = 'cache-set-failed';
} elseif (wp_cache_get( $key, 'options' ) !== $value) {
	$report['failures'][] = 'cache-get-mismatch';
} elseif ( ! wp_cache_delete( $key, 'options' )) {
	$report['failures'][] = 'cache-delete-failed';
}

restore_error_handler();
$report['failures'] = array_values( array_unique( $report['failures'] ) );
$report['status']   = count( $report['failures'] ) === 0 ? 'pass' : 'fail';
mincemeat_smoke_write_report( $report, $report_path );
$report_written = true;

$summary = json_encode(
	array(
		'schema_version'    => $report['schema_version'],
		'status'            => $report['status'],
		'wordpress_version' => $report['wordpress_version'],
		'php_version'       => $report['php_version'],
		'backend_product'   => $report['backend_product'],
		'plugins'           => $report['plugins'],
		'failures'          => $report['failures'],
	),
	JSON_UNESCAPED_SLASHES
);
fwrite( $report['status'] === 'pass' ? STDOUT : STDERR, (string) $summary . "\n" );
exit( $report['status'] === 'pass' ? 0 : 1 );
