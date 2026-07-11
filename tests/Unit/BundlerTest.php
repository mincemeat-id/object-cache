<?php
/**
 * Unit tests for the Mincemeat Object Cache drop-in bundler.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BundlerTest extends TestCase
{
    private $outputFile;
    private $buildScript;

    protected function setUp(): void
    {
        parent::setUp();
        $plugin_dir = dirname(dirname(__DIR__));
        $this->outputFile = $plugin_dir . '/stubs/object-cache.php';
        $this->buildScript = $plugin_dir . '/tools/build-dropin.php';
    }

    public function test_bundler_is_deterministic_and_generates_valid_markers()
    {
        $this->assertFileExists($this->buildScript, 'Build script tools/build-dropin.php must exist.');

        // Run build script first time.
        exec('php ' . escapeshellarg($this->buildScript), $output, $returnCode);
        $this->assertSame(0, $returnCode, 'Build script must run successfully.');

        $this->assertFileExists($this->outputFile, 'Drop-in stub must be generated.');
        $content1 = file_get_contents($this->outputFile);

        // Run build script second time.
        exec('php ' . escapeshellarg($this->buildScript), $output2, $returnCode2);
        $this->assertSame(0, $returnCode2, 'Build script must run successfully a second time.');
        $content2 = file_get_contents($this->outputFile);

        // Assert byte-identical output.
        $this->assertSame($content1, $content2, 'Generated drop-in must be byte-identical on successive builds.');

        // Verify markers in the comment block.
        $this->assertMatchesRegularExpression('/Owner:\s*mincemeat-object-cache/', $content1, 'Header must contain correct Owner ID.');
        $this->assertMatchesRegularExpression('/Version:\s*\d+\.\d+\.\d+/', $content1, 'Header must contain Version marker.');
        $this->assertMatchesRegularExpression('/Drop-in Version:\s*\d+\.\d+\.\d+/', $content1, 'Header must contain Drop-in Version marker.');
        $this->assertMatchesRegularExpression('/Schema Version:\s*\d+/', $content1, 'Header must contain Schema Version.');
        $this->assertMatchesRegularExpression('/Build Hash:\s*[a-f0-9]{64}/', $content1, 'Header must contain valid 64-character SHA-256 build hash.');
    }

    public function test_generated_file_is_syntactically_valid()
    {
        $this->assertFileExists($this->outputFile);

        // Run php -l on the generated file to ensure it's syntactically correct PHP 7.4.
        exec('php -l ' . escapeshellarg($this->outputFile), $output, $returnCode);
        $this->assertSame(0, $returnCode, 'Generated file must have correct PHP syntax: ' . implode("\n", $output));
    }
}
