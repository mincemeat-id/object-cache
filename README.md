# Mincemeat Object Cache

Mincemeat Object Cache is a Redis/Valkey object-cache drop-in for WordPress, built on the PhpRedis extension. It provides a generated standalone `object-cache.php` runtime plus a small companion plugin for drop-in lifecycle, Site Health diagnostics, and WP-CLI operations.

Status: `0.1.0-rc2` public testing release candidate. The project uses ZeroVer while the public API and operational behavior settle.

## Requirements

- WordPress 6.9 or later
- PHP 7.4 through PHP 8.5, as validated by CI
- PhpRedis 6.3.0 or later
- Redis 8 or Valkey 9

Future PHP, Redis, Valkey, or WordPress versions are supported only after they are added to the test matrix.

## Configuration

Configuration lives in `wp-config.php` as a PHP array constant. There is intentionally no admin settings screen for credentials.

```php
define('MINCEMEAT_OBJECT_CACHE_CONFIG', [
    'namespace'         => 'my-site',
    'scheme'            => 'tcp',
    'host'              => '127.0.0.1',
    'port'              => 6379,
    'database'          => 0,
    'connect_timeout'   => 1.0,
    'read_timeout'      => 1.0,
    'max_retries'       => 1,
    'backoff_algorithm' => 'decorrelated_jitter',
    'backoff_base'      => 10,
    'backoff_cap'       => 100,
    'tcp_keepalive'     => true,
    'persistent'        => false,
    'max_ttl'           => 2592000,
    'debug'             => false,
]);
```

Supported keys include `namespace`, `scheme`, `host`, `port`, `path`, `database`, `username`, `password`, `connect_timeout`, `read_timeout`, `max_retries`, `backoff_algorithm`, `backoff_base`, `backoff_cap`, `tcp_keepalive`, `persistent`, `max_ttl`, `tls`, and `debug`. Use `scheme => 'tls'` for TLS connections and `scheme => 'unix'` plus `path` for Unix sockets.

## Supported Topology and Consistency

The v1 support boundary is one direct Redis 8 or Valkey 9 standalone writable
primary. Server-side replicas are acceptable when Mincemeat still connects only
to the primary; replica reads and direct replica endpoints are unsupported.
Redis Cluster, Sentinel discovery/failover, multi-primary arrangements, and
client-side read splitting are unsupported. Managed proxies are outside the
validated matrix because their routing and retry semantics cannot be detected
reliably; a proxy reporting a standalone primary identity does not imply
support.

Cache writes are best effort. Mincemeat issues each adapter operation once and
opens its request-local circuit after an exception, but PhpRedis may internally
retry up to `max_retries` times after connection trouble. A timeout or disconnect
can therefore leave a write in an ambiguous state: it may have committed even
when the current request falls back to memory or reports failure. Mincemeat does
not provide replication acknowledgement, durable-write guarantees, or
read-after-write consistency across requests.

`persistent => true` requests PhpRedis connection reuse. It becomes effective
only when the process pool honors Mincemeat's credential/database/TLS identity;
otherwise the adapter deliberately uses a request-scoped connection. Site
Health and `wp mincemeat-cache status` report the server-reported topology and
requested-versus-effective reuse state.

## Operation

Mincemeat Object Cache is an object cache only. It caches values stored through the WordPress object-cache API; it is not a page cache and does not serve cached HTML.

Diagnostics are available in WordPress under Tools -> Site Health -> Info. The companion plugin reports cache state, stable failure reason codes, drop-in integrity, supported features, backend product/version when safe, and classified connection context. Supplied hosts, ports, database indexes, Unix-socket paths, credentials, raw cache keys, and cached values are never copied into diagnostics. Debug error logging is opt-in: CLI emits at most once per process, while web requests require a shared APCu five-minute throttle and otherwise remain silent.

WP-CLI command group:

```bash
wp mincemeat-cache status
wp mincemeat-cache install-dropin
wp mincemeat-cache remove-dropin
```

The lifecycle code installs and removes only the managed drop-in. It refuses to overwrite or remove a foreign `wp-content/object-cache.php`.

## Development

Install dependencies and start local services:

```bash
composer install
docker compose up -d
```

Common checks:

```bash
composer validate --strict --no-check-lock
composer lint -- --report=summary
composer stan -- --error-format=raw
composer test
composer test:coverage
composer test:e2e
```

Local Docker defaults use Redis 8 on `6383`, Valkey 9 on `6384`, and MariaDB on `33076`. Optional ACL, TLS, and Unix-socket helper services are created by `bash tools/setup-test-services.sh` for integration scenarios.

Performance guardrails:

```bash
composer benchmark:controlled -- 127.0.0.1 6383
```

The controlled run measures the immutable RC1 runtime and two clean current
runs with the same harness, runner, and Redis image. Versioned artifacts under
`build/benchmarks/` include raw samples, command/round-trip/connection counts,
commit, CPU and runner identity, PHP INI/extensions, backend image digest, and
the repeatability/release comparisons. Connection targets are omitted.

## Release Builds

The drop-in is generated from `src/`; do not edit `stubs/object-cache.php` by hand.

```bash
php tools/build-dropin.php
php tools/build-package.php
```

Package builds produce `mincemeat-object-cache.zip`, `mincemeat-object-cache.zip.sha256`, and `manifest.json`. These release artifacts are deterministic outputs and are ignored in normal development.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
