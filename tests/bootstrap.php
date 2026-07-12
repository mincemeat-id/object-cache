<?php
/**
 * PHPUnit bootstrap for unit and contract tests that do not require a live
 * WordPress installation or a running cache server.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($vendor_autoload)) {
    require_once $vendor_autoload;
} else {
    spl_autoload_register(static function ($class): void {
        $prefixes = array(
            'Mincemeat\\ObjectCache\\Tests\\' => __DIR__ . '/../tests/',
            'Mincemeat\\ObjectCache\\'        => __DIR__ . '/../src/',
        );

        foreach ($prefixes as $prefix => $base_dir) {
            $len = strlen($prefix);
            if (strncmp($class, $prefix, $len) !== 0) {
                continue;
            }

            $relative = substr($class, $len);
            $relative = str_replace('\\', '/', $relative);
            $file      = $base_dir . $relative . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }
    });
}

if (getenv('MINCEMEAT_TEST_USE_DROPIN')) {
    $dropin = __DIR__ . '/../stubs/object-cache.php';
    if (file_exists($dropin)) {
        require_once $dropin;
    } else {
        fwrite(STDERR, "Error: stubs/object-cache.php not found. Run php tools/build-dropin.php first.\n");
        exit(1);
    }
} else {
    // Load the global wp_cache_* facade so contract/unit tests exercise the real
    // WordPress entry points. The facade is guarded against redeclaration.
    require_once __DIR__ . '/../src/functions.php';
}

if (!function_exists('_doing_it_wrong')) {
    /**
     * Record _doing_it_wrong() calls so contract tests may assert on them.
     *
     * @param string $function The function that was called incorrectly.
     * @param string $message  A message explaining the incorrect usage.
     * @param string $version  The version of WordPress where the message was added.
     */
    function _doing_it_wrong($function, $message, $version)
    {
        if (!isset($GLOBALS['__mincemeat_doing_it_wrong']) || !is_array($GLOBALS['__mincemeat_doing_it_wrong'])) {
            $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        }

        $GLOBALS['__mincemeat_doing_it_wrong'][] = array($function, $message, $version);
    }
}

if (!function_exists('wp_suspend_cache_addition')) {
    /**
     * Suspend or resume cache addition. Mirrors WordPress core behavior.
     *
     * Passing null queries the current state; passing a bool sets it and
     * returns the new state.
     *
     * @param bool|null $suspend Optional. Pass true to suspend, false to resume,
     *                            or null to query the current state.
     * @return bool The current suspend state.
     */
    function wp_suspend_cache_addition($suspend = null)
    {
        static $s = false;

        if (null === $suspend) {
            return $s;
        }

        $s = (bool) $suspend;

        return $s;
    }
}

if (!function_exists('is_multisite')) {
    /**
     * Determines whether Multisite is enabled. Honors a WP_MULTISITE constant
     * for the test suite; defaults to false.
     *
     * @return bool
     */
    function is_multisite()
    {
        if (defined('WP_MULTISITE')) {
            return (bool) WP_MULTISITE;
        }

        return false;
    }
}

if (!function_exists('get_current_blog_id')) {
    /**
     * Retrieves the current blog ID. Honors a global $blog_id for the test
     * suite; defaults to 1.
     *
     * @return int
     */
    function get_current_blog_id()
    {
        if (isset($GLOBALS['blog_id'])) {
            return abs((int) $GLOBALS['blog_id']);
        }

        return 1;
    }
}

if (!function_exists('_deprecated_function')) {
    /**
     * Records deprecated function calls so tests may assert on them.
     *
     * @param string $function    The deprecated function.
     * @param string $version     The version it was deprecated in.
     * @param string $replacement Optional. The replacement function.
     */
    function _deprecated_function($function, $version, $replacement = '')
    {
        if (!isset($GLOBALS['__mincemeat_deprecated']) || !is_array($GLOBALS['__mincemeat_deprecated'])) {
            $GLOBALS['__mincemeat_deprecated'] = array();
        }

        $GLOBALS['__mincemeat_deprecated'][] = array($function, $version, $replacement);
    }
}

if (!function_exists('do_action')) {
    /**
     * Minimal action hook stub. Records calls so tests can assert on them.
     *
     * @param string $tag    The action name.
     * @param mixed  ...$args Additional arguments.
     */
    function do_action($tag, ...$args)
    {
        if (!isset($GLOBALS['__mincemeat_actions']) || !is_array($GLOBALS['__mincemeat_actions'])) {
            $GLOBALS['__mincemeat_actions'] = array();
        }

        if (!isset($GLOBALS['__mincemeat_actions'][$tag])) {
            $GLOBALS['__mincemeat_actions'][$tag] = array();
        }

        $GLOBALS['__mincemeat_actions'][$tag][] = $args;
    }
}

if (!function_exists('add_filter')) {
    /**
     * Minimal add_filter stub for recording filter callbacks.
     */
    function add_filter($tag, $callback)
    {
        if (!isset($GLOBALS['__mincemeat_filters']) || !is_array($GLOBALS['__mincemeat_filters'])) {
            $GLOBALS['__mincemeat_filters'] = array();
        }
        if (!isset($GLOBALS['__mincemeat_filters'][$tag])) {
            $GLOBALS['__mincemeat_filters'][$tag] = array();
        }
        $GLOBALS['__mincemeat_filters'][$tag][] = $callback;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Minimal filter hook stub that runs recorded callbacks.
     *
     * @param string $tag      The filter name.
     * @param mixed  $value    The value to filter.
     * @param mixed  ...$args  Optional additional arguments.
     * @return mixed The filtered value.
     */
    function apply_filters($tag, $value, ...$args)
    {
        if (isset($GLOBALS['__mincemeat_filters'][$tag]) && is_array($GLOBALS['__mincemeat_filters'][$tag])) {
            foreach ($GLOBALS['__mincemeat_filters'][$tag] as $callback) {
                $value = call_user_func($callback, $value, ...$args);
            }
        }
        return $value;
    }
}

if (!function_exists('__')) {
    /**
     * Minimal translation stub.
     */
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    /**
     * Minimal esc_html stub.
     */
    function esc_html($text)
    {
        return $text;
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/abspath-stub/');
}

if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static $lines = array();
        public static $successes = array();
        public static $errors = array();

        public static function reset() {
            self::$lines = array();
            self::$successes = array();
            self::$errors = array();
        }

        public static function line($message) {
            self::$lines[] = $message;
        }

        public static function success($message) {
            self::$successes[] = $message;
        }

        public static function error($message) {
            self::$errors[] = $message;
            throw new \RuntimeException('WP_CLI_ERROR: ' . $message);
        }
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $GLOBALS['__mincemeat_current_user_can'] ?? true;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        $GLOBALS['__transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return $GLOBALS['__transients'][$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        unset($GLOBALS['__transients'][$transient]);
        return true;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return $text;
    }
}