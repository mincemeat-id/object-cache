<?php
/**
 * Mincemeat bootstrap wrapper for WordPress subprocess tests.
 */

declare(strict_types=1);

// Locate the real WordPress tests bootstrap.
$real_bootstrap = getenv('MINCEMEAT_REAL_BOOTSTRAP');
if (!$real_bootstrap || !file_exists($real_bootstrap)) {
    $real_bootstrap = dirname(__DIR__, 2) . '/wp-tests/tests/phpunit/includes/bootstrap.php';
}

require_once $real_bootstrap;

// Now WordPress is fully booted. Let's run preflight checks.
global $wp_object_cache;

// Get status and diagnostics
$status = \Mincemeat\ObjectCache\Api::status();
$diag = \Mincemeat\ObjectCache\Api::diagnostics();

$product = isset($diag['server']['product']) ? $diag['server']['product'] : 'none';
$cache_state = $status['state'];
$multisite = (function_exists('is_multisite') && is_multisite()) ? '1' : '0';
$php_ver = PHP_VERSION;

$config_constant = defined('MINCEMEAT_OBJECT_CACHE_CONFIG') ? MINCEMEAT_OBJECT_CACHE_CONFIG : null;
$config_digest = md5(serialize($config_constant));

$expected_backend = getenv('MINCEMEAT_EXPECTED_BACKEND'); // 'redis' or 'valkey'
$require_persistent = getenv('MINCEMEAT_REQUIRE_PERSISTENT'); // '1' or '0'
$selected_scheme = is_array($config_constant) && isset($config_constant['scheme'])
    ? (string) $config_constant['scheme']
    : 'unknown';
$selected_port = is_array($config_constant) && isset($config_constant['port'])
    ? (int) $config_constant['port']
    : null;
$selected_endpoint = $selected_scheme . ($selected_port !== null ? ':' . $selected_port : '');

// WP_CONTENT_DIR is defined by WordPress
$dropin_file = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/object-cache.php' : '';
$dropin_hash = ($dropin_file && file_exists($dropin_file)) ? md5_file($dropin_file) : 'none';

$preflight = array(
    'php'           => $php_ver,
    'multisite'     => $multisite,
    'product'       => $product,
    'state'         => $cache_state,
    'config_digest' => $config_digest,
    'hash'          => $dropin_hash,
    'expected_backend' => $expected_backend ?: 'runtime-only',
    'endpoint'      => $selected_endpoint,
);

echo "\nMINCEMEAT_PREFLIGHT: " . json_encode($preflight) . "\n";

if ($expected_backend) {
    if (strtolower($product) !== strtolower($expected_backend)) {
        fwrite(STDERR, "Preflight Failure: Expected backend product '{$expected_backend}' at {$selected_endpoint}, but got '{$product}' (State: {$cache_state}, Reason: {$status['reason']}, Last Error: " . var_export($diag['last_error'] ?? '', true) . ").\n");
        exit(99);
    }
}

if ($require_persistent === '1') {
    if ($cache_state !== \Mincemeat\ObjectCache\ObjectCache::STATE_PERSISTENT) {
        fwrite(STDERR, "Preflight Failure: Expected persistent cache state for '{$expected_backend}' at {$selected_endpoint}, but got '{$cache_state}'.\n");
        exit(99);
    }
} elseif ($require_persistent === '0') {
    if ($cache_state !== \Mincemeat\ObjectCache\ObjectCache::STATE_RUNTIME_ONLY) {
        fwrite(STDERR, "Preflight Failure: Expected runtime-only cache state at {$selected_endpoint}, but got '{$cache_state}'.\n");
        exit(99);
    }
}
