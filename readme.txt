=== Mincemeat Object Cache ===
Contributors: mincemeat
Tags: cache, object cache, redis, valkey, performance
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0-rc2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Redis/Valkey object-cache drop-in for WordPress, backed by the PhpRedis extension.

== Description ==

Mincemeat Object Cache is a Redis/Valkey object-cache drop-in for WordPress. It replaces the runtime object cache with a Redis- or Valkey-backed implementation built on the PhpRedis extension.

Key facts:

* Requires PHP 7.4 or later. Official support is strictly limited to PHP versions actively tested in the continuous integration matrix (currently PHP 7.4 through PHP 8.5; PHP 8.5 support also depends on the wider WordPress installation stack). Future PHP versions (such as PHP 8.6+) are not officially supported until explicitly validated in the test suite.
* Requires PhpRedis 6.3.0 or later (v1 of this plugin uses PhpRedis only).
* Supports one direct Redis 8 or Valkey 9 standalone writable primary. Cluster, Sentinel discovery, direct replicas, replica reads, multi-primary routing, and managed proxies are outside the v1 support matrix.
* Configured via the `MINCEMEAT_OBJECT_CACHE_CONFIG` array constant in wp-config.php.
* Has NO settings page. All diagnostics are surfaced through WordPress Site Health.
* The runtime cache implementation lives in the generated standalone `wp-content/object-cache.php` drop-in file. This companion plugin provides drop-in lifecycle, Site Health, and minimal WP-CLI integration.

== Installation ==

1. Install and activate the Mincemeat Object Cache companion plugin.
2. Ensure PhpRedis 6.3.0 or later is loaded on your server.
3. Define the `MINCEMEAT_OBJECT_CACHE_CONFIG` array constant in wp-config.php with your Redis/Valkey connection details (host, port, database, credentials, and other supported keys).
4. The standalone drop-in is generated at `wp-content/object-cache.php`. WordPress loads it automatically on every request.
5. Verify connectivity in WordPress under Tools -> Site Health -> Info.

== Frequently Asked Questions ==

= Is this a page cache? =

No. Mincemeat Object Cache is an object cache only. It caches database query results, option preloads, and similar transient data. It is not a page cache and does not serve cached HTML responses.

= Where are settings? =

There is no settings page. All configuration is done via the `MINCEMEAT_OBJECT_CACHE_CONFIG` constant in wp-config.php, and all runtime diagnostics are surfaced through WordPress Site Health.

= Do I need Predis/Relay? =

No. Mincemeat Object Cache v1 uses the PhpRedis extension only. Predis and Relay are not required and are not loaded.

= What Redis or Valkey topology is supported? =

Version 1 supports one direct standalone writable primary. Server-side replicas are acceptable only when Mincemeat connects to the primary and never reads from replicas. Cluster, Sentinel discovery or failover, direct replica endpoints, read splitting, multi-primary systems, and managed proxies are unsupported or unverified. Site Health reports the server-provided mode and role when available.

= Are cache writes guaranteed after a timeout? =

No. Object-cache writes are best effort. PhpRedis can retry after connection trouble, so a write may have committed even when the request observes a timeout and switches to request memory. Mincemeat does not promise durable writes, replication acknowledgement, or cross-request read-after-write consistency.

== Changelog ==

= 0.1.0-rc2 =
* Hardened drop-in ownership and added packaged upgrade, rollback, recovery, and foreign-file preservation checks.
* Expanded compatibility coverage to WordPress 6.9.5 and 7.0.2, with scheduled trunk monitoring and provenance-verified core tests.
* Unified increment and decrement behavior across request memory, Redis, and Valkey, including overflow and falsey-value cases.
* Reduced cold-request Redis/Valkey commands by lazily loading diagnostics and scripts and coalescing generation-token reads.
* Made WooCommerce, Yoast SEO, and Easy Digital Downloads smoke tests fail on missing plugins, database errors, or unexpected PHP diagnostics.
* Redacted failure diagnostics more strictly, bounded outage logging, and closed partially initialized clients.
* Documented and diagnosed the supported direct standalone writable-primary topology and persistent connection reuse behavior.
* Added reproducible RC1-to-RC2 performance evidence with raw samples, environment metadata, and deterministic network-work gates.

= 0.1.0-rc1 =
* Initial release.
