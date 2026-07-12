<?php
/**
 * Unit tests for SiteHealth.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\Lifecycle;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;
use Mincemeat\ObjectCache\SiteHealth;
use PHPUnit\Framework\TestCase;

class SiteHealthTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		unset( $GLOBALS['wp_object_cache'] );
		$GLOBALS['__transients'] = array();
		$GLOBALS['__mincemeat_current_user_can'] = true;

		if (!defined('WP_CONTENT_DIR')) {
			define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-sitehealth-' . uniqid());
		}
		if (!is_dir(WP_CONTENT_DIR)) {
			mkdir(WP_CONTENT_DIR, 0777, true);
		}
	}

	protected function tearDown(): void
	{
		unset( $GLOBALS['wp_object_cache'] );
		$GLOBALS['__transients'] = array();

		$target = WP_CONTENT_DIR . '/object-cache.php';
		if (file_exists($target)) {
			@chmod($target, 0644);
			@unlink($target);
		}
		parent::tearDown();
	}

	public function test_register_tests()
	{
		$registered = SiteHealth::register_tests( array() );

		$this->assertArrayHasKey( 'mincemeat_object_cache_dropin', $registered['direct'] );
		$this->assertArrayHasKey( 'mincemeat_object_cache_connection', $registered['direct'] );
		$this->assertArrayHasKey( 'mincemeat_object_cache_tls', $registered['direct'] );
		$this->assertArrayHasKey( 'mincemeat_object_cache_ttl', $registered['direct'] );
		$this->assertArrayHasKey( 'mincemeat_object_cache_eviction', $registered['direct'] );
	}

	public function test_test_dropin_absent()
	{
		// No drop-in file exists.
		$res = SiteHealth::test_dropin();
		$this->assertSame( 'critical', $res['status'] );
		$this->assertStringContainsString( 'missing', $res['label'] );
	}

	public function test_test_dropin_foreign()
	{
		file_put_contents( WP_CONTENT_DIR . '/object-cache.php', '<?php // Owner: foreign-owner' );

		$res = SiteHealth::test_dropin();
		$this->assertSame( 'critical', $res['status'] );
		$this->assertStringContainsString( 'Conflicting', $res['label'] );
	}

	public function test_test_dropin_stale()
	{
		file_put_contents( WP_CONTENT_DIR . '/object-cache.php', "<?php\n/**\n * Owner: mincemeat-object-cache\n * Version: 0.1.0\n * Build Hash: wronghash\n */\n" );

		$res = SiteHealth::test_dropin();
		$this->assertSame( 'recommended', $res['status'] );
		$this->assertStringContainsString( 'outdated', $res['label'] );
	}

	public function test_test_dropin_current()
	{
		$source = dirname(__FILE__, 3) . '/stubs/object-cache.php';
		copy( $source, WP_CONTENT_DIR . '/object-cache.php' );

		$res = SiteHealth::test_dropin();
		$this->assertSame( 'good', $res['status'] );
		$this->assertStringContainsString( 'active and up to date', $res['label'] );
	}

	public function test_test_connection_not_connected()
	{
		// With no global cache setup, it defaults to runtime-only/not-initialized or no-backend.
		$res = SiteHealth::test_connection();
		$this->assertSame( 'critical', $res['status'] );
		$this->assertStringContainsString( 'Could not connect', $res['label'] );
	}

	public function test_test_connection_invalid_config()
	{
		// Setup a cache with invalid configuration.
		$key_space = new KeySpace( false, 1 );
		$adapter   = $this->createMock( PhpRedisAdapter::class );
		$backend   = new Backend( $key_space, $adapter );

		// We will construct Config with an invalid parameter, but wait, Config constructor throws exception.
		// So Backend can initialize with a custom state and reason.
		// Let's set the backend properties using reflection or just check that connection status handles it.
		// Actually we can mock Backend or mock ObjectCache directly if they were not final, but they are final.
		// Let's check: can we trigger connection-invalid?
		// Yes, if we mock the PhpRedisAdapter connection to throw exception.
		$config = new Config( array( 'namespace' => 'test-ns' ) );
		// Let's inject PhpRedisAdapter that fails connection.
		$adapter->method( 'connect' )->willThrowException( new \Mincemeat\ObjectCache\BackendException( Backend::REASON_CONNECT_FAILED, 'Connection refused' ) );
		$backend->initialize( $config );

		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_connection();
		$this->assertSame( 'critical', $res['status'] );
		$this->assertStringContainsString( 'Could not connect', $res['label'] );
		$this->assertStringContainsString( 'connect-failed', $res['description'] );
	}

	public function test_test_connection_degraded()
	{
		$key_space = new KeySpace( false, 1 );
		$adapter   = $this->createMock( PhpRedisAdapter::class );
		$backend   = new Backend( $key_space, $adapter );

		$config = new Config( array( 'namespace' => 'test' ) );
		$adapter->method( 'is_connected' )->willReturn( true );
		$backend->initialize( $config );

		// Move it to degraded state by triggering a command failure.
		// Let's mock a method to fail.
		$adapter->method( 'get' )->willThrowException( new \RedisException( 'Lost connection' ) );

		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		// Perform a cache read that fails and triggers circuit breaker.
		$cache->get( 'test-key' );

		$this->assertSame( 'degraded', $cache->state() );

		$res = SiteHealth::test_connection();
		$this->assertSame( 'critical', $res['status'] );
		$this->assertStringContainsString( 'degraded', $res['label'] );
	}

	public function test_test_connection_supported_and_unsupported_versions()
	{
		$key_space = new KeySpace( false, 1 );
		$config    = new Config( array( 'namespace' => 'test' ) );

		// Scenario 1: Redis 7.2 (unsupported)
		$adapter = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$adapter->method( 'server_info' )->willReturn( array(
			'product'          => 'redis',
			'version'          => '7.2.4',
			'maxmemory_policy' => 'allkeys-lru',
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_connection();
		$this->assertSame( 'recommended', $res['status'] );
		$this->assertStringContainsString( 'Unsupported Redis server version 7.2.4', $res['label'] );

		// Scenario 2: Redis 8.0.1 (supported)
		$adapter = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$adapter->method( 'server_info' )->willReturn( array(
			'product'          => 'redis',
			'version'          => '8.0.1',
			'maxmemory_policy' => 'allkeys-lru',
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_connection();
		$this->assertSame( 'good', $res['status'] );
		$this->assertStringContainsString( 'Connected to persistent cache backend', $res['label'] );

		// Scenario 3: Valkey 8.0.0 (unsupported)
		$adapter = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$adapter->method( 'server_info' )->willReturn( array(
			'product'          => 'valkey',
			'version'          => '8.0.0',
			'maxmemory_policy' => 'allkeys-lru',
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_connection();
		$this->assertSame( 'recommended', $res['status'] );
		$this->assertStringContainsString( 'Unsupported Valkey server version 8.0.0', $res['label'] );

		// Scenario 4: Valkey 9.0.0 (supported)
		$adapter = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$adapter->method( 'server_info' )->willReturn( array(
			'product'          => 'valkey',
			'version'          => '9.0.0',
			'maxmemory_policy' => 'allkeys-lru',
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_connection();
		$this->assertSame( 'good', $res['status'] );
	}

	public function test_test_tls_verification()
	{
		// Non-TLS connection is not applicable (but good)
		$key_space = new KeySpace( false, 1 );
		$adapter   = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$backend = new Backend( $key_space, $adapter );
		$config  = new Config( array(
			'namespace' => 'test',
			'scheme'    => 'tcp',
		) );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_tls_verification();
		$this->assertSame( 'good', $res['status'] );
		$this->assertStringContainsString( 'not applicable', $res['label'] );

		// TLS with peer verification enabled (good)
		$config = new Config( array(
			'namespace' => 'test',
			'scheme'    => 'tls',
			'tls'       => array( 'verify_peer' => true, 'verify_peer_name' => true ),
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_tls_verification();
		$this->assertSame( 'good', $res['status'] );
		$this->assertStringContainsString( 'verification is secure', $res['label'] );

		// TLS with peer verification disabled (recommended)
		$config = new Config( array(
			'namespace' => 'test',
			'scheme'    => 'tls',
			'tls'       => array( 'verify_peer' => false ),
		) );
		$backend = new Backend( $key_space, $adapter );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$res = SiteHealth::test_tls_verification();
		$this->assertSame( 'recommended', $res['status'] );
		$this->assertStringContainsString( 'disabled', $res['label'] );
	}

	public function test_debug_information()
	{
		$key_space = new KeySpace( false, 1 );
		$adapter   = $this->createMock( PhpRedisAdapter::class );
		$adapter->method( 'is_connected' )->willReturn( true );
		$adapter->method( 'server_info' )->willReturn( array(
			'product'          => 'redis',
			'version'          => '8.0.0',
			'maxmemory_policy' => 'allkeys-lru',
		) );
		$backend = new Backend( $key_space, $adapter );
		$config  = new Config( array(
			'namespace' => 'test-ns-diagnostics',
			'scheme'    => 'tcp',
			'database'  => 3,
		) );
		$backend->initialize( $config );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		$info = SiteHealth::debug_information( array() );

		$this->assertArrayHasKey( 'mincemeat-object-cache', $info );
		$fields = $info['mincemeat-object-cache']['fields'];

		$this->assertSame( 'persistent', $fields['cache_state']['value'] );
		$this->assertSame( '3', $fields['database']['value'] );
		$this->assertStringContainsString( 'Redis 8.0.0', $fields['server_identity']['value'] );
		$this->assertStringContainsString( 'tcp://127.0.0.1:6379', $fields['endpoint']['value'] );
		$this->assertSame( 'absent', $fields['dropin_status']['value'] );
	}
}
