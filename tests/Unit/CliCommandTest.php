<?php
/**
 * Unit tests for the CliCommand class.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);



namespace Mincemeat\ObjectCache\Tests\Unit {

	use Mincemeat\ObjectCache\CliCommand;
	use Mincemeat\ObjectCache\Lifecycle;
	use PHPUnit\Framework\TestCase;
	use WP_CLI;

	/**
	 * @group unit
	 */
	class CliCommandTest extends TestCase
	{
		protected function setUp(): void
		{
			parent::setUp();
			WP_CLI::reset();
			unset( $GLOBALS['wp_object_cache'] );

			if (!defined('WP_CONTENT_DIR')) {
				define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-cli-' . uniqid());
			}
			if (!is_dir(WP_CONTENT_DIR)) {
				mkdir(WP_CONTENT_DIR, 0777, true);
			}
		}

		protected function tearDown(): void
		{
			unset( $GLOBALS['wp_object_cache'] );
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				@unlink($target);
			}
			parent::tearDown();
		}

		public function test_status()
		{
			$cmd = new CliCommand();
			$cmd->status(array(), array());

			$output = implode("\n", WP_CLI::$lines);
			$this->assertStringContainsString('Drop-in Status:', $output);
			$this->assertStringContainsString('Cache Status:', $output);
			$this->assertStringContainsString('Reason:', $output);
		}

		public function test_install_dropin_already_current()
		{
			// Install it first.
			Lifecycle::install_dropin();
			WP_CLI::reset();

			$cmd = new CliCommand();
			$cmd->install_dropin(array(), array());

			$this->assertCount(1, WP_CLI::$successes);
			$this->assertStringContainsString('already installed', WP_CLI::$successes[0]);
		}

		public function test_install_dropin_foreign_refused()
		{
			file_put_contents(WP_CONTENT_DIR . '/object-cache.php', '<?php // Owner: foreign');

			$cmd = new CliCommand();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('WP_CLI_ERROR: A foreign object-cache.php drop-in is present');

			$cmd->install_dropin(array(), array());
		}

		public function test_install_dropin_success()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				unlink($target);
			}

			$cmd = new CliCommand();
			$cmd->install_dropin(array(), array());

			$this->assertCount(1, WP_CLI::$successes);
			$this->assertStringContainsString('installed successfully', WP_CLI::$successes[0]);
			$this->assertTrue(file_exists($target));
		}

		public function test_update_dropin_already_current()
		{
			Lifecycle::install_dropin();
			WP_CLI::reset();

			$cmd = new CliCommand();
			$cmd->update_dropin(array(), array());

			$this->assertCount(1, WP_CLI::$successes);
			$this->assertStringContainsString('already up to date', WP_CLI::$successes[0]);
		}

		public function test_update_dropin_foreign_refused()
		{
			file_put_contents(WP_CONTENT_DIR . '/object-cache.php', '<?php // Owner: foreign');

			$cmd = new CliCommand();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('WP_CLI_ERROR: A foreign object-cache.php drop-in is present');

			$cmd->update_dropin(array(), array());
		}

		public function test_update_dropin_absent_refused()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				unlink($target);
			}

			$cmd = new CliCommand();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('WP_CLI_ERROR: Drop-in is not installed');

			$cmd->update_dropin(array(), array());
		}

		public function test_update_dropin_marker_spoof_refused()
		{
			// Header markers alone never establish ownership.
			file_put_contents(WP_CONTENT_DIR . '/object-cache.php', "<?php\n/**\n * Owner: mincemeat-object-cache\n * Version: 0.1.0\n * Build Hash: oldhash\n */\n");

			$cmd = new CliCommand();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('WP_CLI_ERROR: A foreign object-cache.php drop-in is present');
			$cmd->update_dropin(array(), array());
		}

		public function test_remove_dropin_absent()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				unlink($target);
			}

			$cmd = new CliCommand();
			$cmd->remove_dropin(array(), array());

			$this->assertCount(1, WP_CLI::$successes);
			$this->assertStringContainsString('No drop-in found', WP_CLI::$successes[0]);
		}

		public function test_remove_dropin_foreign_refused()
		{
			file_put_contents(WP_CONTENT_DIR . '/object-cache.php', '<?php // Owner: foreign');

			$cmd = new CliCommand();

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('WP_CLI_ERROR: A foreign object-cache.php drop-in is present');

			$cmd->remove_dropin(array(), array());
		}

		public function test_remove_dropin_success()
		{
			Lifecycle::install_dropin();
			$target = WP_CONTENT_DIR . '/object-cache.php';
			$this->assertTrue(file_exists($target));

			$cmd = new CliCommand();
			$cmd->remove_dropin(array(), array());

			$this->assertCount(1, WP_CLI::$successes);
			$this->assertStringContainsString('removed successfully', WP_CLI::$successes[0]);
			$this->assertFalse(file_exists($target));
		}
	}
}
