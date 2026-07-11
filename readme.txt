=== Mincemeat Object Cache ===
Contributors: mincemeat
Tags: cache, object cache, redis, valkey, performance
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0-dev
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Redis/Valkey object-cache drop-in for Mincemeat, backed by the PhpRedis extension.

== Description ==

Mincemeat Object Cache is a Redis/Valkey object-cache drop-in for Mincemeat. It replaces the WordPress object cache with a Redis- or Valkey-backed implementation built on the PhpRedis extension.

Key facts:

* Requires the PhpRedis PHP extension (v1 of this plugin uses PhpRedis only).
* Configured via the `MINCEMEAT_OBJECT_CACHE_CONFIG` array constant in wp-config.php.
* Has NO settings page. All diagnostics are surfaced through WordPress Site Health.
* The runtime cache implementation lives in the generated standalone `wp-content/object-cache.php` drop-in file. This companion plugin provides drop-in lifecycle, Site Health, and minimal WP-CLI integration.

== Installation ==

1. Install and activate the Mincemeat Object Cache companion plugin.
2. Ensure the PhpRedis PHP extension is loaded on your server.
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

== Changelog ==

= 1.0.0-dev =
* Initial development release.