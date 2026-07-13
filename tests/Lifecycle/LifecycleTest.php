<?php
/**
 * Unit tests for the Lifecycle class.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Lifecycle {

	use Mincemeat\ObjectCache\Lifecycle;
	use PHPUnit\Framework\TestCase;

	/**
	 * @group unit
	 */
	class LifecycleTest extends TestCase
	{
		private $backup_transients;
		private $backup_current_user_can;

		protected function setUp(): void
		{
			parent::setUp();

			if (!defined('WP_CONTENT_DIR')) {
				define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-lifecycle-' . uniqid());
			}
			if (!is_dir(WP_CONTENT_DIR)) {
				mkdir(WP_CONTENT_DIR, 0777, true);
			}

			if (!defined('ABSPATH')) {
				define('ABSPATH', sys_get_temp_dir() . '/abspath-mock/');
			}

			$this->backup_transients = $GLOBALS['__transients'] ?? array();
			$this->backup_current_user_can = $GLOBALS['__mincemeat_current_user_can'] ?? null;
			$GLOBALS['__transients'] = array();
			$GLOBALS['__mincemeat_current_user_can'] = true;
		}

		protected function tearDown(): void
		{
			// Restore directory permissions first so cleanup can succeed.
			@chmod(WP_CONTENT_DIR, 0777);

			$GLOBALS['__transients'] = $this->backup_transients;
			$GLOBALS['__mincemeat_current_user_can'] = $this->backup_current_user_can;

			// Clean up files in temp content dir
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				@chmod($target, 0644);
				@unlink($target);
			}
			// Clean up any remaining temp files
			foreach (glob(WP_CONTENT_DIR . '/object-cache.tmp.*.php') as $f) {
				@chmod($f, 0644);
				@unlink($f);
			}

			parent::tearDown();
		}

		public function test_get_dropin_state_absent()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				unlink($target);
			}

			$this->assertSame(Lifecycle::STATE_ABSENT, Lifecycle::get_dropin_state());
		}

		public function test_get_dropin_state_unreadable()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, '<?php // hello');
			chmod($target, 0000);

			// If running as root, chmod 0000 might still be readable. So skip if readable.
			if (is_readable($target)) {
				$this->markTestSkipped('Cannot test unreadable file because it is still readable by current process owner.');
			}

			$this->assertSame(Lifecycle::STATE_INVALID_READABLE, Lifecycle::get_dropin_state());
		}

		public function test_get_dropin_state_foreign()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n// Owner: someone-else\n");
			$this->assertSame(Lifecycle::STATE_FOREIGN, Lifecycle::get_dropin_state());
		}

		public function test_get_dropin_state_owned_current()
		{
			$source = dirname(__FILE__, 3) . '/stubs/object-cache.php';
			$target = WP_CONTENT_DIR . '/object-cache.php';
			copy($source, $target);

			$this->assertSame(Lifecycle::STATE_OWNED_CURRENT, Lifecycle::get_dropin_state());
		}

		public function test_get_dropin_state_owned_stale_wrong_hash()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n/**\n * Owner: mincemeat-object-cache\n * Version: 1.0.0-dev\n * Build Hash: wronghash\n */\n");

			$this->assertSame(Lifecycle::STATE_OWNED_STALE, Lifecycle::get_dropin_state());
		}

		public function test_has_direct_access_basic()
		{
			$this->assertTrue(Lifecycle::has_direct_access());
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_has_direct_access_respects_disallow_file_mods()
		{
			define('DISALLOW_FILE_MODS', true);
			$this->assertFalse(Lifecycle::has_direct_access());
		}

		public function test_install_dropin_success()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				unlink($target);
			}

			$result = Lifecycle::install_dropin();
			$this->assertTrue($result);
			$this->assertTrue(file_exists($target));
			$this->assertSame(Lifecycle::STATE_OWNED_CURRENT, Lifecycle::get_dropin_state());
		}

		public function test_remove_dropin_owned()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			Lifecycle::install_dropin();
			$this->assertTrue(file_exists($target));

			$result = Lifecycle::remove_dropin();
			$this->assertTrue($result);
			$this->assertFalse(file_exists($target));
		}

		public function test_remove_dropin_foreign_refused()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n// Owner: someone-else\n");

			$result = Lifecycle::remove_dropin();
			$this->assertFalse($result);
			$this->assertTrue(file_exists($target));
		}

		public function test_activate_already_current()
		{
			Lifecycle::install_dropin();
			$GLOBALS['__transients'] = array();

			Lifecycle::activate();
			$this->assertEmpty($GLOBALS['__transients']);
		}

		public function test_activate_foreign_refused()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n// Owner: someone-else\n");

			Lifecycle::activate();
			$this->assertSame('foreign', get_transient('mincemeat_object_cache_activation_notice'));
		}

		public function test_activate_failed_permissions()
		{
			// Force non-writable by removing target directory write permissions
			chmod(WP_CONTENT_DIR, 0555);

			// If running as root, chmod 0555 might still be writable.
			if (is_writable(WP_CONTENT_DIR)) {
				chmod(WP_CONTENT_DIR, 0777);
				$this->markTestSkipped('Cannot test non-writable directory because it is still writable by current process owner.');
			}

			Lifecycle::activate();
			$this->assertSame('not_writable', get_transient('mincemeat_object_cache_activation_notice'));

			chmod(WP_CONTENT_DIR, 0777);
		}

		public function test_deactivate_owned()
		{
			Lifecycle::install_dropin();
			$target = WP_CONTENT_DIR . '/object-cache.php';
			$this->assertTrue(file_exists($target));

			Lifecycle::deactivate();
			$this->assertFalse(file_exists($target));
			$this->assertEmpty($GLOBALS['__transients']);
		}

		public function test_deactivate_foreign_not_touched()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n// Owner: someone-else\n");

			Lifecycle::deactivate();
			$this->assertTrue(file_exists($target));
			$this->assertEmpty($GLOBALS['__transients']);
		}

		public function test_admin_notices_foreign()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n// Owner: someone-else\n");

			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();

			$this->assertStringContainsString('A foreign object-cache.php drop-in is present', $output);
		}

		public function test_admin_notices_stale()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			file_put_contents($target, "<?php\n/**\n * Owner: mincemeat-object-cache\n * Version: 0.1.0\n * Build Hash: oldhash\n */\n");

			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();

			$this->assertStringContainsString('drop-in is outdated', $output);
		}

		public function test_admin_notices_transients()
		{
			set_transient('mincemeat_object_cache_activation_notice', 'disallowed');
			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();
			$this->assertStringContainsString('file modifications are disabled', $output);

			set_transient('mincemeat_object_cache_activation_notice', 'foreign');
			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();
			$this->assertStringContainsString('conflicting object-cache.php drop-in is already present', $output);

			set_transient('mincemeat_object_cache_activation_notice', 'not_writable');
			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();
			$this->assertStringContainsString('directory is not writable', $output);

			set_transient('mincemeat_object_cache_activation_notice', 'failed');
			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();
			$this->assertStringContainsString('filesystem error', $output);

			set_transient('mincemeat_object_cache_deactivate_fail', true);
			ob_start();
			Lifecycle::admin_notices();
			$output = ob_get_clean();
			$this->assertStringContainsString('could not be removed automatically due to permissions', $output);
		}

		public function test_activate_checks_capability()
		{
			$GLOBALS['__mincemeat_current_user_can'] = false;
			$target = WP_CONTENT_DIR . '/object-cache.php';

			Lifecycle::activate();
			$this->assertFalse(file_exists($target));
		}

		public function test_deactivate_checks_capability()
		{
			Lifecycle::install_dropin();
			$target = WP_CONTENT_DIR . '/object-cache.php';
			$this->assertTrue(file_exists($target));

			$GLOBALS['__mincemeat_current_user_can'] = false;
			Lifecycle::deactivate();
			$this->assertTrue(file_exists($target));
		}

		public function test_install_dropin_refuses_symlink()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				@unlink($target);
			}
			$dummy = WP_CONTENT_DIR . '/dummy.php';
			file_put_contents($dummy, '<?php // dummy');
			symlink($dummy, $target);

			$this->assertTrue(is_link($target));
			$result = Lifecycle::install_dropin();
			$this->assertFalse($result);

			@unlink($target);
			@unlink($dummy);
		}

		public function test_remove_dropin_refuses_symlink()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				@unlink($target);
			}
			$dummy = WP_CONTENT_DIR . '/dummy.php';
			file_put_contents($dummy, '<?php // dummy');
			symlink($dummy, $target);

			$result = Lifecycle::remove_dropin();
			$this->assertFalse($result);

			@unlink($target);
			@unlink($dummy);
		}

		public function test_install_dropin_refuses_directory()
		{
			$target = WP_CONTENT_DIR . '/object-cache.php';
			if (file_exists($target)) {
				@unlink($target);
			}
			mkdir($target);

			$result = Lifecycle::install_dropin();
			$this->assertFalse($result);

			rmdir($target);
		}
	}
}
