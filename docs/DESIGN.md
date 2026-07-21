# Mincemeat Object Cache - Design Specification

Date: 2026-07-13
Audience: maintainers, contributors, reviewers, and AI coding agents.

## Purpose

Mincemeat Object Cache is a WordPress object-cache drop-in backed by Redis or Valkey through the PhpRedis extension. It is intended to be public, inspectable, and production-safe for normal WordPress installations.

The plugin has two deliverables:

- A standalone `object-cache.php` drop-in generated from source.
- A companion WordPress plugin that manages installation, integrity checks, Site Health diagnostics, and WP-CLI lifecycle commands.

## Goals

- Correctly implement the WordPress object-cache contract for WordPress 6.9+.
- Support PHP 7.4 through PHP 8.5 without using syntax that breaks PHP 7.4.
- Support Redis 8 and Valkey 9 through PhpRedis.
- Preserve site availability when Redis/Valkey is unavailable.
- Avoid exposing credentials or cache internals through admin screens, logs, or diagnostics.
- Avoid broad keyspace operations that are unsafe on shared Redis/Valkey services.
- Keep the generated drop-in deterministic and auditable.

## Non-Goals

- This is not a page cache.
- This is not a Redis administration UI.
- This is not a general Redis client for themes or plugins.
- This does not provide a WordPress settings screen for connection credentials.
- This does not promise support for arbitrary Redis-compatible services unless they pass the test matrix.

## Architecture

```text
WordPress
  |
  | loads wp-content/object-cache.php
  v
Generated drop-in: stubs/object-cache.php
  |
  | contains generated copies of src classes and global wp_cache_* facades
  v
Mincemeat\ObjectCache\ObjectCache
  |
  +-- Config
  +-- KeySpace
  +-- RequestMemory
  +-- Metrics
  +-- CacheItem
  +-- PhpRedisAdapter
          |
          v
       Redis or Valkey

Companion plugin: mincemeat-object-cache.php
  |
  +-- lifecycle install/remove
  +-- drop-in checksum checks
  +-- Site Health diagnostics
  +-- WP-CLI commands
```

## Source Layout

| Path | Responsibility |
| --- | --- |
| `src/` | Runtime classes used to generate the drop-in. |
| `src/functions.php` | WordPress global cache facade functions. |
| `stubs/object-cache.php` | Generated drop-in. Do not edit manually. |
| `stubs/object-cache.php.sha256` | Generated checksum for drop-in integrity. |
| `mincemeat-object-cache.php` | Companion plugin entry point. |
| `tools/build-dropin.php` | Builds the standalone drop-in from source. |
| `tools/build-package.php` | Builds release package artifacts. |
| `tests/` | Unit, contract, compatibility, lifecycle, integration, and smoke tests. |
| `docs/` | Design, implementation, and release documentation. |

## Boot Sequence

1. WordPress loads `wp-content/object-cache.php` before normal plugin loading.
2. The generated drop-in defines runtime classes if they are not already loaded.
3. The drop-in reads `MINCEMEAT_OBJECT_CACHE_CONFIG`.
4. The drop-in creates the global `$wp_object_cache`.
5. WordPress cache calls route through the global `wp_cache_*` functions.
6. Runtime operations use request memory first and Redis/Valkey as the persistent backend when available.
7. If backend initialization fails, the runtime must degrade without breaking WordPress bootstrap.

## Configuration Contract

Configuration is provided as a PHP array constant in `wp-config.php` before WordPress loads:

```php
define('MINCEMEAT_OBJECT_CACHE_CONFIG', [
    'namespace'       => 'site-key',
    'scheme'          => 'tcp',
    'host'            => '127.0.0.1',
    'port'            => 6379,
    'database'        => 0,
    'connect_timeout' => 1.0,
    'read_timeout'    => 1.0,
    'max_retries'     => 1,
    'backoff_algorithm' => 'decorrelated_jitter',
    'backoff_base'    => 10,
    'backoff_cap'     => 100,
    'tcp_keepalive'   => true,
    'persistent'      => false,
    'max_ttl'         => 2592000,
    'debug'           => false,
]);
```

Supported keys:

| Key | Type | Purpose |
| --- | --- | --- |
| `namespace` | string | Required key namespace to isolate installations. |
| `scheme` | string | `tcp`, `tls`, or `unix`. |
| `host` | string | TCP/TLS host. |
| `port` | int | TCP/TLS port. |
| `path` | string | Unix socket path. |
| `database` | int | Redis logical database. |
| `username` | string | ACL username. |
| `password` | string | ACL password. |
| `connect_timeout` | float | Connection timeout in seconds. |
| `read_timeout` | float | Read timeout in seconds. |
| `max_retries` | int | Bounded reconnect retries, from 0 through 3. |
| `backoff_algorithm` | string | PhpRedis reconnect backoff algorithm. |
| `backoff_base` | int | Backoff base in milliseconds, at most 1000. |
| `backoff_cap` | int | Backoff cap in milliseconds, at most 1000. |
| `tcp_keepalive` | bool | Whether TCP socket keepalive is enabled. |
| `persistent` | bool | Whether to use persistent PhpRedis connections. |
| `max_ttl` | int | Maximum TTL applied to entries. |
| `tls` | array | PhpRedis TLS context options. |
| `debug` | bool | Enables extra diagnostics. |

Sensitive fields must never be emitted raw in Site Health, test failures, package manifests, or logs.

## Cache Semantics

The runtime must follow WordPress object-cache behavior:

- `wp_cache_get()` distinguishes hits from misses through the `$found` reference parameter.
- `false`, `0`, empty strings, empty arrays, and `null` are valid cached values.
- Global groups are shared across multisite blogs.
- Non-global groups are scoped by blog ID on multisite.
- Non-persistent groups remain request-local.
- Multiple operations preserve WordPress input shape and result semantics.
- Numeric operations preserve WordPress behavior for missing and non-numeric values.
- Flush operations are scoped to the operation being requested.

Numeric increments and decrements use the following contract in request memory,
Redis, and Valkey:

| Input or condition | Behavior |
| --- | --- |
| Missing key | Return `false`; do not create an item |
| Integer | Use the exact integer value |
| Float or decimal/exponent string | Truncate toward zero before arithmetic |
| Boolean, `null`, array, object, or non-decimal string | Normalize to zero before arithmetic |
| Offset | Cast to an integer before arithmetic |
| `PHP_INT_MIN` offset | Saturate safely without attempting an unrepresentable negation |
| Negative result | Clamp to zero |
| Result above `PHP_INT_MAX` | Saturate at `PHP_INT_MAX` |
| Existing persistent TTL | Preserve it atomically |
| Corrupt persistent envelope | Return `false` without arithmetic |

This matches WordPress core for misses, integer arithmetic, non-numeric
normalization, offset coercion, and the zero floor. WordPress core can return a
float after starting from a fractional value or overflowing an integer despite
its documented `int|false` return. Mincemeat deliberately truncates fractions
and saturates overflow so every tier returns the documented integer type.

For compatibility with plugins that inspect the global cache object, the
runtime also exposes core-shaped `cache_hits`, `cache_misses`, `global_groups`,
and `blog_prefix` properties plus `stats()` output. Group and blog properties
are read-only compatibility views over the key-space state rather than mutable
copies.

The runtime should support these modern features through `wp_cache_supports()`:

- `add_multiple`
- `set_multiple`
- `get_multiple`
- `delete_multiple`
- `flush_runtime`
- `flush_group`

## Keyspace Design

Keys are namespaced to prevent collisions on shared Redis/Valkey services.

Key components should include:

- installation namespace
- blog scope where applicable
- group
- normalized cache key
- generation token where applicable

Group invalidation should rotate group generation tokens rather than scanning or deleting arbitrary backend keys. This keeps invalidation bounded and safe for shared services.

Database-wide `FLUSHDB`, `FLUSHALL`, `KEYS`, and broad `SCAN` operations are not acceptable for normal runtime behavior.

## Value Encoding

Cache values are represented by a cache item envelope that can preserve:

- stored value
- expiration metadata
- group/key context when needed
- serialization safety

The runtime must preserve exact WordPress cache semantics for falsey values and miss detection.

## Backend Adapter

The PhpRedis adapter owns all direct Redis/Valkey calls.

PhpRedis 6.3.0 is the minimum supported client version. The adapter uses its
server identity methods, bounded retry/backoff options, and per-connection Lua
script cache while preserving the same cache semantics for Redis and Valkey.
Server identity is collected only when diagnostics request it. Numeric scripts
start with `EVAL`, which populates the server cache, and use `EVALSHA` on later
calls; normal non-numeric requests do not run `INFO` or `SCRIPT LOAD`.

Required behavior:

- Validate connection configuration before connecting.
- Support TCP, TLS, and Unix socket transports.
- Support ACL username/password authentication.
- Support persistent connection identifiers without leaking credentials.
- Apply connect/read timeouts.
- Derive persistent connection pool identifiers from non-reversible digests of
  database, namespace, ACL, TLS, transport, and retry identity so changed
  connection credentials cannot reuse an incompatible PhpRedis connection.
- Fall back to a request connection when process-wide PhpRedis pooling is
  configured to ignore the supplied persistent identifier; never mutate the
  global pool pattern from the drop-in.
- Avoid throwing backend exceptions into ordinary WordPress execution paths.
- Redact host/path/credential information in diagnostics unless explicitly safe.
- Use Lua for atomic numeric or token operations only where it is portable across Redis 8 and Valkey 9.

## Failure Model

The object cache is a performance layer, not a correctness dependency.

When Redis/Valkey is unavailable:

- WordPress should continue serving requests.
- Request-memory caching may continue.
- Persistent cache operations should fail closed and report misses or false according to WordPress expectations.
- Site Health should report degraded status without exposing secrets.
- Reconnection should be bounded and should not create request stalls.
- A command or pipeline failure should open the request-local circuit exactly
  once; diagnostics reads must not inflate failure counters.
- Metrics and Site Health should expose the same stable state and reason code
  without including backend exception traces or connection identity.

## Performance Guardrails

Hot-path performance is measured by `tools/benchmark-soak.php` with fixed
workloads, isolated namespaces, repeated samples, and median latency. Adapter
commands, round trips, and cold connections are asserted exactly where the
harness can observe them, so batch operations cannot silently regress into
additional network exchanges even when wall-clock timings are noisy. The cold
workloads include a new namespace, first hit, first miss, first set, and first
group before the steady-state workloads.

Benchmark baselines are machine-local by default. A comparison is valid only
when PHP, PhpRedis, backend product/version, and the controlled runner match;
benchmark output must not expose target hosts or other connection details.
Release notes may quote results only when that execution context is documented.

## Companion Plugin Responsibilities

The companion plugin is allowed to integrate with normal WordPress plugin lifecycle hooks. It should not be required for the drop-in to execute once installed.

Responsibilities:

- Copy/install the generated drop-in to `wp-content/object-cache.php`.
- Remove or replace the drop-in only if its complete SHA-256 matches either the
  bundled drop-in or an immutable public-release entry in the lifecycle
  ownership registry. Header markers alone never establish ownership.
- Validate drop-in integrity.
- Expose Site Health information.
- Provide WP-CLI operations for install/remove/status when WP-CLI is available.

The companion plugin should not:

- Store Redis credentials in the WordPress options table.
- Provide a settings page that encourages credential entry in admin.
- Perform runtime cache operations that belong in the drop-in.

## Site Health Design

Site Health should report:

- drop-in status and checksum match
- runtime availability
- backend connection status
- backend type/version when safe
- selected transport type
- redacted namespace/endpoint details
- feature support
- basic metrics and failure counters

Site Health must redact:

- password
- username when sensitive
- TLS peer values if they reveal internal infrastructure
- non-local hostnames or paths when they can expose private topology

## WP-CLI Design

WP-CLI should support release and operational workflows:

- install drop-in
- remove managed drop-in
- report status
- verify checksum

Commands must be idempotent and must not remove a non-managed third-party drop-in.

## Compatibility Policy

Runtime source and generated drop-in must remain PHP 7.4 compatible.

That means:

- no union types
- no typed properties if generation needs to support older style consistently
- no `match`
- no constructor property promotion
- no nullsafe operator
- no enums
- no readonly properties/classes

Static analysis can run on newer PHP versions, but source syntax must remain valid on PHP 7.4.

## Testing Strategy

Required layers:

- Unit tests for config, keyspace, value envelopes, request memory, and metrics.
- Contract tests for WordPress object-cache semantics.
- Compatibility tests for WordPress cache helper functions.
- Lifecycle tests for drop-in install/remove/checksum behavior.
- Integration tests against Redis and Valkey.
- Smoke tests with a real WordPress test install.
- CI matrix across PHP 7.4 through 8.5.

Release validation must include:

- PHPCS
- PHPStan
- PHPUnit
- package determinism
- generated artifact parity
- secret-redaction checks

## Release Artifact Policy

Generated files must be treated explicitly:

- `stubs/object-cache.php` is generated from source and should be verified in CI.
- `stubs/object-cache.php.sha256` must match the generated drop-in.
- Package artifacts are currently ignored in the working tree and should be produced by release tooling.
- CI must prove package output is deterministic and must verify the ZIP file allowlist.

## Security Principles

- Configuration belongs in code or environment-controlled deployment, not in a public admin form.
- Diagnostics must be useful without exposing secrets.
- Runtime must not make broad destructive backend calls.
- Tests may use local credentials, but those credentials must be clearly local-only and should not create committed secret-like files.
- Release artifacts should be reproducible enough for reviewers to verify source-to-package integrity.

## Agent Guidance

AI agents working on this repository should:

- Read this file, `docs/IMPLEMENTATION.md`, `docs/RELEASE.md`, `README.md`, and `composer.json` before changing runtime behavior.
- Change source under `src/`, then regenerate the drop-in.
- Never edit `stubs/object-cache.php` by hand.
- Preserve PHP 7.4 syntax.
- Run the smallest relevant tests first, then the full validation suite for release-impacting changes.
- Treat documentation in `docs/` as release-critical.
