<?php
/**
 * Integration/Compatibility tests for WordPress and major plugins.
 *
 * @package Mincemeat\ObjectCache
 * @group compatibility
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Compatibility;

use Mincemeat\ObjectCache\Tests\Integration\IntegrationTestCase;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\ValueCodec;
use Mincemeat\ObjectCache\PhpRedisAdapter;
use Mincemeat\ObjectCache\BackendException;

/**
 * Class CompatibilityTest
 *
 * @group compatibility
 */
class CompatibilityTest extends IntegrationTestCase
{
    private array $logged_messages = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged_messages = array();
        $GLOBALS['__test_error_log_callback'] = function ($msg) {
            $this->logged_messages[] = $msg;
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__test_error_log_callback']);
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // 1. WordPress Core (Single/Multisite)
    // ----------------------------------------------------------------

    public function test_wordpress_single_site_operations()
    {
        // Assert normal operations on a single site
        $this->assertTrue($this->cache->set('wp_key', 'wp_val', 'options'));
        $this->assertSame('wp_val', $this->cache->get('wp_key', 'options'));
        
        $found = null;
        $this->assertSame('wp_val', $this->cache->get('wp_key', 'options', false, $found));
        $this->assertTrue($found);

        $this->assertTrue($this->cache->delete('wp_key', 'options'));
        $this->assertFalse($this->cache->get('wp_key', 'options', false, $found));
        $this->assertFalse($found);
    }

    public function test_wordpress_multisite_blog_switching()
    {
        // Setup multisite contexts
        $ks_blog1 = new KeySpace(true, 1);
        $be_blog1 = new Backend($ks_blog1);
        $be_blog1->initialize($this->config);
        $cache_blog1 = new ObjectCache($ks_blog1, $be_blog1);

        $ks_blog2 = new KeySpace(true, 2);
        $be_blog2 = new Backend($ks_blog2);
        $be_blog2->initialize($this->config);
        $cache_blog2 = new ObjectCache($ks_blog2, $be_blog2);

        // Setup global group
        $cache_blog1->add_global_groups(array('global_shared'));
        $cache_blog2->add_global_groups(array('global_shared'));

        // Write non-global keys on Blog 1
        $cache_blog1->set('option_k', 'val_b1', 'options');
        // Write non-global keys on Blog 2
        $cache_blog2->set('option_k', 'val_b2', 'options');

        // Assert non-global keys are isolated
        $this->assertSame('val_b1', $cache_blog1->get('option_k', 'options'));
        $this->assertSame('val_b2', $cache_blog2->get('option_k', 'options'));

        // Write global key on Blog 1
        $cache_blog1->set('shared_k', 'shared_val', 'global_shared');
        // Assert global key is accessible on Blog 2
        $this->assertSame('shared_val', $cache_blog2->get('shared_k', 'global_shared'));

        // Test blog switching inside a single cache instance
        $ks_switching = new KeySpace(true, 1);
        $be_switching = new Backend($ks_switching);
        $be_switching->initialize($this->config);
        $cache_switching = new ObjectCache($ks_switching, $be_switching);
        $cache_switching->add_global_groups(array('global_shared'));

        $cache_switching->set('switch_k', 'b1_val', 'options');
        $this->assertSame('b1_val', $cache_switching->get('switch_k', 'options'));

        $cache_switching->switch_to_blog(2);
        $this->assertFalse($cache_switching->get('switch_k', 'options'));
        $cache_switching->set('switch_k', 'b2_val', 'options');
        $this->assertSame('b2_val', $cache_switching->get('switch_k', 'options'));

        $cache_switching->switch_to_blog(1);
        $this->assertSame('b1_val', $cache_switching->get('switch_k', 'options'));

        $be_blog1->close();
        $be_blog2->close();
        $be_switching->close();
    }

    // ----------------------------------------------------------------
    // 2. WooCommerce Workflows
    // ----------------------------------------------------------------

    public function test_woocommerce_product_catalog_reads()
    {
        $product_id = 456;
        $product_data = array(
            'id' => $product_id,
            'name' => 'Premium Coffee Beans',
            'price' => 14.99,
            'stock' => 120,
            'attributes' => array(
                'roast' => 'dark',
                'origin' => 'Sumatra',
            ),
        );

        $this->cache->add_global_groups(array('products'));

        // Set catalog details
        $this->assertTrue($this->cache->set("product_id_{$product_id}", $product_data, 'products'));

        // Read and assert type preservation
        $retrieved = $this->cache->get("product_id_{$product_id}", 'products');
        $this->assertSame($product_data, $retrieved);
        $this->assertSame('Premium Coffee Beans', $retrieved['name']);
        $this->assertSame(14.99, $retrieved['price']);
        $this->assertSame(120, $retrieved['stock']);
        $this->assertSame('dark', $retrieved['attributes']['roast']);
    }

    public function test_woocommerce_cart_checkout_lifecycle()
    {
        $session_id = 'wc_sess_xyz123';
        $cart_data = array(
            'items' => array(
                array('product_id' => 456, 'quantity' => 2),
            ),
            'total' => 29.98,
        );

        // Cart session write/retrieve
        $this->assertTrue($this->cache->set($session_id, $cart_data, 'session'));
        $this->assertSame($cart_data, $this->cache->get($session_id, 'session'));

        // Concurrency lock simulation (add NX)
        $lock_key = 'checkout_lock_customer_789';
        $this->assertTrue($this->cache->add($lock_key, 'locked', 'transient', 5));
        
        // Assert concurrent request cannot acquire
        $this->assertFalse($this->cache->add($lock_key, 'locked', 'transient', 5));

        // Delete lock and verify re-acquisition
        $this->assertTrue($this->cache->delete($lock_key, 'transient'));
        $this->assertTrue($this->cache->add($lock_key, 'locked2', 'transient', 5));
        $this->assertSame('locked2', $this->cache->get($lock_key, 'transient'));
    }

    public function test_woocommerce_order_creation_status_changes()
    {
        $order_id = 999;
        $order_cache = array(
            'status' => 'pending',
            'customer_id' => 789,
            'items_count' => 2,
        );

        $this->assertTrue($this->cache->set("order_{$order_id}", $order_cache, 'orders'));
        $this->assertSame($order_cache, $this->cache->get("order_{$order_id}", 'orders'));

        // Status transition -> invalidate order cache
        $this->assertTrue($this->cache->delete("order_{$order_id}", 'orders'));

        // Write new status
        $order_cache['status'] = 'completed';
        $this->assertTrue($this->cache->set("order_{$order_id}", $order_cache, 'orders'));
        $this->assertSame('completed', $this->cache->get("order_{$order_id}", 'orders')['status']);
    }

    public function test_woocommerce_store_api_rest()
    {
        // Store API transients
        $api_endpoint_cache_key = 'wc_store_api_products_list';
        $payload = array('html' => '<div class="product">...</div>', 'hash' => 'abc');

        $this->assertTrue($this->cache->set($api_endpoint_cache_key, $payload, 'transient', 60));
        $this->assertSame($payload, $this->cache->get($api_endpoint_cache_key, 'transient'));

        $this->assertTrue($this->cache->delete($api_endpoint_cache_key, 'transient'));
        $this->assertFalse($this->cache->get($api_endpoint_cache_key, 'transient'));
    }

    public function test_woocommerce_action_scheduler_cron()
    {
        $job_lock_key = 'action_scheduler_lock_job_12';

        // Acquire lock
        $this->assertTrue($this->cache->add($job_lock_key, 'processing', 'transient', 300));
        
        // Concurrent scheduler attempt
        $this->assertFalse($this->cache->add($job_lock_key, 'processing', 'transient', 300));

        // Delete lock
        $this->assertTrue($this->cache->delete($job_lock_key, 'transient'));
        $this->assertTrue($this->cache->add($job_lock_key, 'processing', 'transient', 300));
    }

    public function test_woocommerce_session_persistence_across_requests()
    {
        $session_id = 'wc_session_persisted';
        $cart_items = array('product_id' => 111, 'qty' => 5);

        $this->assertTrue($this->cache->set($session_id, $cart_items, 'session'));

        // Simulate separate request
        $new_req = $this->new_request();
        $this->assertSame($cart_items, $new_req->get($session_id, 'session'));
    }

    // ----------------------------------------------------------------
    // 3. Yoast SEO Workflows
    // ----------------------------------------------------------------

    public function test_yoast_seo_activation_schema_indexation()
    {
        // Yoast checks db schema and indexation state via transient/options
        $this->assertTrue($this->cache->set('wpseo_indexation_status', 'complete', 'transient'));
        $this->assertSame('complete', $this->cache->get('wpseo_indexation_status', 'transient'));
    }

    public function test_yoast_seo_frontend_metadata()
    {
        $post_id = 707;
        $meta_data = array(
            'title' => 'My Awesome Page Title',
            'meta_desc' => 'An amazing description of my page',
            'opengraph' => array(
                'og:title' => 'My Page',
                'og:image' => 'https://example.com/img.jpg',
            ),
            'schema' => array(
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
            ),
        );

        $this->assertTrue($this->cache->set("yoast_meta_{$post_id}", $meta_data, 'default'));
        $this->assertSame($meta_data, $this->cache->get("yoast_meta_{$post_id}", 'default'));
    }

    public function test_yoast_seo_admin_rest_requests()
    {
        $admin_dashboard_cache = array('score' => 'good', 'problems' => 0);
        $this->assertTrue($this->cache->set('wpseo_admin_score', $admin_dashboard_cache, 'transient'));
        $this->assertSame($admin_dashboard_cache, $this->cache->get('wpseo_admin_score', 'transient'));
    }

    public function test_yoast_seo_cache_invalidation_on_content_changes()
    {
        $post_id = 707;
        $this->cache->set("yoast_meta_{$post_id}", 'stale_metadata', 'default');

        // Post modified -> Yoast invalidates post meta cache
        $this->assertTrue($this->cache->delete("yoast_meta_{$post_id}", 'default'));
        $this->assertFalse($this->cache->get("yoast_meta_{$post_id}", 'default'));
    }

    // ----------------------------------------------------------------
    // 4. Easy Digital Downloads Workflows
    // ----------------------------------------------------------------

    public function test_edd_product_cart_checkout()
    {
        $cart_contents = array(
            'download_id' => 888,
            'price_id' => 2,
            'fees' => array(),
        );

        $this->assertTrue($this->cache->set('edd_cart', $cart_contents, 'default'));
        $this->assertSame($cart_contents, $this->cache->get('edd_cart', 'default'));
    }

    public function test_edd_order_payment_webhook_path()
    {
        $payment_tx = 'tx_stripe_98765';
        $lock_key = "edd_payment_lock_{$payment_tx}";

        // Webhook arrives -> set lock
        $this->assertTrue($this->cache->add($lock_key, 'processing', 'transient', 60));

        // Duplicate webhook webhook arrives concurrently -> fails to lock
        $this->assertFalse($this->cache->add($lock_key, 'processing', 'transient', 60));

        // Delete lock when webhook finishes
        $this->assertTrue($this->cache->delete($lock_key, 'transient'));
    }

    public function test_edd_cron_background_task()
    {
        $report_lock = 'edd_sales_report_lock';
        $this->assertTrue($this->cache->add($report_lock, '1', 'transient', 120));
        $this->assertFalse($this->cache->add($report_lock, '1', 'transient', 120));
    }

    public function test_edd_transient_option_invalidation()
    {
        $this->cache->set('edd_total_sales_month', 5000, 'transient');
        $this->assertSame(5000, $this->cache->get('edd_total_sales_month', 'transient'));

        // Settings updated or sale recorded -> invalidate total
        $this->assertTrue($this->cache->delete('edd_total_sales_month', 'transient'));
        $this->assertFalse($this->cache->get('edd_total_sales_month', 'transient'));
    }

    // ----------------------------------------------------------------
    // 5. Shared Compatibility
    // ----------------------------------------------------------------

    public function test_shared_backend_outage_degradation()
    {
        $config = new Config(array(
            'namespace'       => 'test-outage',
            'scheme'          => 'tcp',
            'host'            => $this->config->host(),
            'port'            => $this->config->port(),
            'connect_timeout' => 0.1,
            'read_timeout'    => 0.1,
            'debug'           => true,
        ));

        $key_space = new KeySpace(false, 1);
        $adapter = new CompatibilityMockPhpRedisAdapter();

        // Successful path first
        $adapter->get_callback = function ($key) {
            return ValueCodec::encode('good_data');
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);
        $cache = new ObjectCache($key_space, $backend);

        // Fetch works
        $this->assertSame('good_data', $cache->get('key1'));
        $this->assertSame(ObjectCache::STATE_PERSISTENT, $backend->state());

        // Introduce read-timeout failure
        $adapter->get_callback = function ($key) {
            throw new BackendException('read-timeout', 'Read timeout.');
        };

        // Cache get triggers outage, transitions to runtime-only, logs, returns fallback
        $found = null;
        $this->assertFalse($cache->get('key2', 'default', false, $found));
        $this->assertFalse($found);

        $this->assertSame(ObjectCache::STATE_DEGRADED, $backend->state());
        $this->assertSame('command-failed', $backend->reason());

        // Verify failure was logged, secret-free
        $this->assertNotEmpty($this->logged_messages);
        $this->assertStringContainsString('Backend degraded: command-failed', $this->logged_messages[count($this->logged_messages) - 1]);

        // Subsequent gets shouldn't invoke the adapter because circuit is open
        $adapter->get_callback = function ($key) {
            $this->fail('Adapter should not be called after degradation.');
        };

        $cache->get('key2');
    }

    public function test_shared_cache_flush_during_background_work()
    {
        $this->cache->set('key1', 'original_val', 'default');
        $this->assertSame('original_val', $this->cache->get('key1', 'default'));

        // Simulating a background cache flush
        $this->cache->flush();

        // Stale data is gone
        $new_req = $this->new_request();
        $this->assertFalse($new_req->get('key1', 'default'));
    }

    public function test_shared_multisite_blog_switching()
    {
        // Verify blog isolation and global groups under multisite
        $ks = new KeySpace(true, 1);
        $be = new Backend($ks);
        $be->initialize($this->config);
        $cache = new ObjectCache($ks, $be);

        $cache->add_global_groups(array('shared_global'));

        // Non-global group key set on blog 1
        $cache->set('test_key', 'blog1_val', 'default');

        // Switch to blog 2
        $cache->switch_to_blog(2);
        $this->assertFalse($cache->get('test_key', 'default'));
        $cache->set('test_key', 'blog2_val', 'default');

        // Switch back to blog 1
        $cache->switch_to_blog(1);
        $this->assertSame('blog1_val', $cache->get('test_key', 'default'));

        // Global group key set on blog 1
        $cache->set('global_key', 'shared_val', 'shared_global');

        // Switch to blog 2 -> should be accessible
        $cache->switch_to_blog(2);
        $this->assertSame('shared_val', $cache->get('global_key', 'shared_global'));

        // Flush on Blog 1
        $cache->switch_to_blog(1);
        $cache->flush();

        // Blog 1 non-global is gone
        $this->assertFalse($cache->get('test_key', 'default'));

        // Blog 2 non-global should also be gone (network-wide invalidation)
        $cache->switch_to_blog(2);
        $this->assertFalse($cache->get('test_key', 'default'));

        // Global key should be gone as flush clears global groups as well
        $this->assertFalse($cache->get('global_key', 'shared_global'));

        $be->close();
    }

    public function test_shared_plugin_update_stale_data()
    {
        // Old companion plugin writes an invalid or old magic/version envelope directly to Redis
        $old_magic_envelope = 'WRONGMAGIC' . pack('C', 0x02) . pack('C', ValueCodec::TAG_STRING) . pack('N', 5) . 'stale';
        
        $ns_tok = $this->backend->namespace_token();
        $grp_tok = $this->backend->group_token('default');
        $item_key = $this->cache->key_space()->item_key($ns_tok, $grp_tok, 'default', 'stale_key');

        $this->backend->set_unconditional($item_key, $old_magic_envelope);

        // Assert get returns false (cache miss)
        $found = null;
        $this->assertFalse($this->cache->get('stale_key', 'default', false, $found));
        $this->assertFalse($found);

        // Verify key is automatically deleted from backend due to corruption
        $this->assertFalse($this->backend->get($item_key));

        // Logging occurred
        $this->assertNotEmpty($this->logged_messages);
        $this->assertStringContainsString('Value codec decode failed: decode-magic', $this->logged_messages[count($this->logged_messages) - 1]);
    }
}

class CompatibilityMockPhpRedisAdapter extends PhpRedisAdapter
{
    public $get_callback;

    public function connect(Config $config): void
    {
    }

    public function get(string $key)
    {
        if ($this->get_callback) {
            return call_user_func($this->get_callback, $key);
        }
        return false;
    }

    public function set(string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false): bool
    {
        return true;
    }

    public function set_unconditional(string $key, string $value, ?int $ttl_ms = null): bool
    {
        return true;
    }

    public function mget(array $keys): array
    {
        return array_fill(0, count($keys), false);
    }

    public function del(string $key): int
    {
        return 1;
    }
}

namespace Mincemeat\ObjectCache;

if (!function_exists('Mincemeat\ObjectCache\error_log')) {
    function error_log(string $message): void {
        if (isset($GLOBALS['__test_error_log_callback'])) {
            call_user_func($GLOBALS['__test_error_log_callback'], $message);
        } else {
            \error_log($message);
        }
    }
}

