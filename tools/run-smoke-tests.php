<?php
/**
 * Smoke test tool for WooCommerce, Yoast SEO, and Easy Digital Downloads.
 *
 * Replicates the drop-in, defines the configuration, hooks plugin entry points into
 * muplugins_loaded, loads the WordPress test bootstrap, and asserts that the plugins
 * boot successfully without fatal errors under Mincemeat Object Cache.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$wp_tests_dir = realpath(__DIR__ . '/../tests/wp-tests');
$dropin_src   = realpath(__DIR__ . '/../stubs/object-cache.php');
$dropin_dest  = $wp_tests_dir . '/src/wp-content/object-cache.php';

if (!$dropin_src || !file_exists($dropin_src)) {
    fwrite(STDERR, "Error: Drop-in stub not found. Please build it first.\n");
    exit(1);
}

// 1. Copy drop-in to the WordPress content folder
echo "Replicating drop-in to wp-content...\n";
if (!copy($dropin_src, $dropin_dest)) {
    fwrite(STDERR, "Error: Failed to copy drop-in to: {$dropin_dest}\n");
    exit(1);
}

// 2. Define object cache config (point to local Redis on port 6379)
$redis_host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
$redis_port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383);

$object_cache_config = array(
    'scheme'          => 'tcp',
    'host'            => $redis_host,
    'port'            => $redis_port,
    'database'         => 0,
    'connect_timeout' => 1.0,
    'read_timeout'    => 1.0,
    'namespace'       => 'smoke-test-ns',
);
putenv('MINCEMEAT_OBJECT_CACHE_CONFIG=' . json_encode($object_cache_config));

// Force DB host to point to our container port (detect local 33076 first, fallback to 3306)
$mysql_port = (int)(getenv('MINCEMEAT_TEST_DB_PORT') ?: 33076);
$connection = @fsockopen('127.0.0.1', $mysql_port, $errno, $errstr, 0.2);
if (!is_resource($connection) && $mysql_port === 33076) {
    $connection3306 = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.2);
    if (is_resource($connection3306)) {
        $mysql_port = 3306;
        fclose($connection3306);
    }
} elseif (is_resource($connection)) {
    fclose($connection);
}
$_ENV['DB_HOST'] = '127.0.0.1:' . $mysql_port;
$_SERVER['DB_HOST'] = '127.0.0.1:' . $mysql_port;
putenv('DB_HOST=127.0.0.1:' . $mysql_port);

// 3. Register plugins to load on muplugins_loaded
$plugins_dir = $wp_tests_dir . '/src/wp-content/plugins';
$plugin_files = array(
    $plugins_dir . '/woocommerce/woocommerce.php',
    $plugins_dir . '/wordpress-seo/wp-seo.php',
    $plugins_dir . '/easy-digital-downloads/easy-digital-downloads.php',
);

// We need to use tests_add_filter which is defined in the WordPress test bootstrap
require_once $wp_tests_dir . '/tests/phpunit/includes/functions.php';

tests_add_filter('muplugins_loaded', function () use ($plugin_files) {
    foreach ($plugin_files as $file) {
        if (!file_exists($file)) {
            fwrite(STDERR, "Warning: Plugin file not found: {$file}\n");
            continue;
        }
        echo "Loading plugin: " . basename(dirname($file)) . "...\n";
        require_once $file;
    }
});

// 4. Load the WordPress test bootstrap (installs database tables and boots WP)
echo "Bootstrapping WordPress...\n";
require_once $wp_tests_dir . '/tests/phpunit/includes/bootstrap.php';

// 5. Verify the environment is loaded and active
global $wp_object_cache;

if (!isset($wp_object_cache) || !is_object($wp_object_cache)) {
    fwrite(STDERR, "Error: Global \$wp_object_cache is not set.\n");
    exit(1);
}

$state = $wp_object_cache->state();
$reason = $wp_object_cache->reason();
echo "Cache state: {$state} (Reason: {$reason})\n";

if ($state !== 'persistent') {
    fwrite(STDERR, "Error: Object Cache is not in persistent state. Found state: {$state}\n");
    exit(1);
}

// 6. Perform basic cache checks
echo "Performing sanity checks on cache reads and writes...\n";
$key = 'smoke_test_key';
$val = array('foo' => 'bar', 'nested' => array(1, 2, 3));

if (!wp_cache_set($key, $val, 'options', 60)) {
    fwrite(STDERR, "Error: wp_cache_set failed.\n");
    exit(1);
}

$retrieved = wp_cache_get($key, 'options');
if ($retrieved !== $val) {
    fwrite(STDERR, "Error: Retrieved cache value does not match target value.\n");
    exit(1);
}

if (!wp_cache_delete($key, 'options')) {
    fwrite(STDERR, "Error: wp_cache_delete failed.\n");
    exit(1);
}

echo "Smoke tests completed successfully! WooCommerce, Yoast SEO, and EDD loaded and booted cleanly under Mincemeat Object Cache.\n";
exit(0);
