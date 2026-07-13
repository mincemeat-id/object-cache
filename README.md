# Mincemeat Object Cache

A Redis/Valkey object-cache drop-in for WordPress, built on the PhpRedis extension. Mincemeat Object Cache replaces the WordPress object cache with a Redis- or Valkey-backed implementation. The runtime lives in a generated standalone `object-cache.php` drop-in; this package provides the companion plugin (lifecycle, Site Health, and minimal WP-CLI).

## Requirements

- PHP 7.4 or later
- WordPress 6.9 or later
- The PhpRedis PHP extension
- Redis 8 or Valkey 9

## Configuration

Configure the drop-in by defining the `MINCEMEAT_OBJECT_CACHE_CONFIG` array constant in wp-config.php before WordPress loads. Supported keys:

- `namespace` (required): Cache key namespace/segment used to isolate keys on a shared Redis instance.
- `scheme`: Connection scheme. One of `tcp`, `tls`, or `unix`. Defaults to `tcp`.
- `host`: Redis/Valkey host (for `tcp` and `tls`).
- `port`: Redis/Valkey port (for `tcp` and `tls`).
- `path`: Unix socket path (for `unix`).
- `database`: Redis logical database index.
- `username`: Redis ACL username, optional.
- `password`: Redis ACL password, optional.
- `connect_timeout`: Connect timeout in seconds.
- `read_timeout`: Read timeout in seconds.
- `persistent`: Whether to use a persistent connection (boolean).
- `max_ttl`: Maximum TTL applied to cached entries. Default `2592000` (30 days).
- `tls`: TLS context array (peer name, verify peer, verify peer name, ca file, etc.) for `tls` scheme.
- `debug`: Enable debug diagnostics (boolean).

Example:

```php
define('MINCEMEAT_OBJECT_CACHE_CONFIG', [
    'namespace'       => 'mysite',
    'scheme'          => 'tcp',
    'host'            => '127.0.0.1',
    'port'            => 6379,
    'database'        => 0,
    'connect_timeout' => 1.0,
    'read_timeout'    => 1.0,
    'persistent'      => false,
    'max_ttl'         => 2592000,
    'debug'           => false,
]);
```

## Scope

Mincemeat Object Cache is an object cache only. It caches database query results, option preloads, and similar transient data. It is not a page cache and does not serve cached HTML responses.

## Diagnostics

There is no settings page. All runtime diagnostics are surfaced through WordPress Site Health (Tools -> Site Health -> Info).

## Development & Testing Ports

For local development and testing, non-default ports are used to prevent conflicts with other local databases or services:
- **Redis 8**: mapped to host port `6383` (configured as default fallback in test configurations and scripts).
- **Valkey 9**: mapped to host port `6384` (configured as default fallback in test configurations and scripts).
- **MariaDB 11.8**: mapped to host port `33076` (configured as default fallback in test configurations and scripts).

To set up the containers and run the test suite locally:
1. Start the Docker services: `docker compose up -d`
2. Run the PHPUnit test suite: `composer test`

For details on the project's production readiness roadmap, see the [Production Readiness Remediation Plan](file:///home/nerdv2/work/Mincemeat/object-cache/docs/PRODUCTION_READINESS_REMEDIATION_PLAN.md).

## License

GPL-3.0-or-later. See LICENSE.

## Status

1.0.0-dev. Under development.