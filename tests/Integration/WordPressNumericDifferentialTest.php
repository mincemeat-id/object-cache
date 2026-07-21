<?php
/**
 * Differential numeric tests against the maintained WordPress core class.
 *
 * @package Mincemeat\ObjectCache
 * @group integration
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Integration;

use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\Tests\Numeric\NumericContractCases;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class WordPressNumericDifferentialTest extends TestCase
{
    public function test_wordpress_and_mincemeat_numeric_contract_matrix()
    {
        $core_class = dirname(__FILE__, 3) . '/tests/wp-tests/src/wp-includes/class-wp-object-cache.php';
        if (!is_file($core_class)) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('WordPress core numeric differential source is missing.');
            }
            $this->markTestSkipped('Run composer test:setup before the WordPress numeric differential test.');
        }

        $cases = array(
            'increments' => NumericContractCases::increments(),
            'decrements' => NumericContractCases::decrements(),
        );
        $core_results = $this->runCoreProbe($core_class, $cases);

        $cache = new ObjectCache();
        $cache->add_non_persistent_groups('numeric-differential');

        foreach ($cases['increments'] as $label => $case) {
            list($value, $offset, $expected, $core_expected) = $case;
            $this->assertSame($core_expected, $core_results['increments'][$label], 'WordPress incr: ' . $label);

            $key = 'incr-' . md5($label);
            $cache->set($key, $value, 'numeric-differential');
            $this->assertSame($expected, $cache->incr($key, $offset, 'numeric-differential'), 'Mincemeat incr: ' . $label);
        }

        foreach ($cases['decrements'] as $label => $case) {
            list($value, $offset, $expected, $core_expected) = $case;
            $this->assertSame($core_expected, $core_results['decrements'][$label], 'WordPress decr: ' . $label);

            $key = 'decr-' . md5($label);
            $cache->set($key, $value, 'numeric-differential');
            $this->assertSame($expected, $cache->decr($key, $offset, 'numeric-differential'), 'Mincemeat decr: ' . $label);
        }
    }

    /**
     * @param string $core_class
     * @param array<string,array<string,array{0:mixed,1:mixed,2:int,3:int|float}>> $cases
     * @return array<string,array<string,int|float>>
     */
    private function runCoreProbe(string $core_class, array $cases): array
    {
        $probe = <<<'PHP'
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function wp_suspend_cache_addition() { return false; }
function _doing_it_wrong() {}
function wp_load_translations_early() {}
function __($message) { return $message; }

require $argv[1];
$cases = unserialize(base64_decode($argv[2]));
$results = array('increments' => array(), 'decrements' => array());
$cache = new WP_Object_Cache();

foreach ($cases['increments'] as $label => $case) {
    $key = 'incr-' . md5($label);
    $cache->set($key, $case[0], 'numeric-differential');
    $results['increments'][$label] = $cache->incr($key, $case[1], 'numeric-differential');
}

foreach ($cases['decrements'] as $label => $case) {
    $key = 'decr-' . md5($label);
    $cache->set($key, $case[0], 'numeric-differential');
    $results['decrements'][$label] = $cache->decr($key, $case[1], 'numeric-differential');
}

echo base64_encode(serialize($results));
PHP;

        $command = escapeshellarg(PHP_BINARY)
            . ' -r ' . escapeshellarg($probe)
            . ' ' . escapeshellarg($core_class)
            . ' ' . escapeshellarg(base64_encode(serialize($cases)));

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, 'WordPress numeric probe failed: ' . $stderr);
        $decoded = unserialize(base64_decode($stdout));
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
