# Mincemeat Object Cache - Implementation Guide

Date: 2026-07-13
Audience: maintainers, contributors, release engineers, and AI agents.

## Current Status

The implementation is release-candidate quality after the production-readiness remediation work. No current P0 release blockers are documented.

Verified during the 2026-07-13 improvement-plan review:

- Composer metadata, PHPCS, PHPStan level 8, PHPUnit, generated artifact parity, package determinism, and the broader Redis/Valkey matrix are covered by CI.
- Local PHPUnit with the Docker Compose and helper fault services passed on PHP 8.4.23: `463 tests`, `1561 assertions`, `1 skipped`.
- PCOV is available locally and produces `86.40%` (`1995/2309`) line coverage over `src/`.
- The coverage verifier enforces the overall target plus critical baselines for Backend, PhpRedisAdapter, ObjectCache, Lifecycle, KeySpace, ValueCodec, and Config.
- PHPStan level 8 is the configured default and passes without baselines or ignore comments.

Current improvement targets:

- Continued compatibility and release hardening after completion of the
  stability and fault-injection milestone.

PhpRedis 6.3.0 is the minimum required extension version and is installed
explicitly in CI. Connection setup verifies serializer, compression, prefix,
reply, timeout, retry/backoff, and keepalive options. Numeric Lua operations use
per-connection `SCRIPT LOAD`/`EVALSHA` with `EVAL` fallback after `NOSCRIPT`.
Persistent pool identifiers isolate database, namespace, ACL, TLS, transport,
and retry identities using non-reversible digests. Stock PhpRedis pooling does
not honor the identifier unless `redis.pconnect.pool_pattern` contains `i`, so
the adapter falls back to a request connection when pooling would be unsafe.
TCP keepalive is verified only for TCP/TLS connections because PhpRedis rejects
that socket option for Unix sockets.

Fault-injection coverage exercises command disconnects, pipeline failures,
Lua denial and `NOSCRIPT`, TLS trust and peer-name failures, ACL auth and
command denials, bounded timeout/retry behavior, generation-control eviction,
corrupt envelopes, and persistent-identity changes. Request error metrics count
state transitions once and carry the same state/reason classification shown by
Site Health.

The WordPress 6.9 compatibility surface includes public `cache_hits` and
`cache_misses` counters, magic read access to `global_groups` and `blog_prefix`,
core-shaped `isset()` behavior, and `stats()` output. The magic properties are
read-only views over `KeySpace`, which remains the source of truth for group and
multisite scope decisions.

## Repository Map

| Path | Edit policy |
| --- | --- |
| `src/` | Primary runtime source. Edit this for cache behavior. |
| `src/functions.php` | Global WordPress facade functions. Keep signatures WordPress-compatible. |
| `stubs/object-cache.php` | Generated. Do not edit manually. |
| `stubs/object-cache.php.sha256` | Generated. Do not edit manually. |
| `mincemeat-object-cache.php` | Companion plugin entry point and lifecycle integration. |
| `tools/build-dropin.php` | Drop-in generator. |
| `tools/build-package.php` | Package generator used by release tooling and determinism checks. |
| `tools/install-wp-tests.sh` | WordPress test-suite setup. |
| `tools/setup-test-services.sh` | Local Redis/Valkey/TLS helper. |
| `tests/` | PHPUnit coverage. |
| `docs/` | Design, implementation, analysis, and improvement planning docs. |
| `AGENTS.md` | Root instructions for future coding agents. |

## Development Rules

- Runtime and generated drop-in must remain PHP 7.4 compatible. Official support is strictly limited to PHP versions actively validated in the continuous integration matrix (currently PHP 7.4 through PHP 8.5). Future major/minor PHP releases (e.g., PHP 8.6+) are not officially supported until they have been integrated and validated in the CI test suite.
- Prefer WordPress-compatible procedural facades and conservative type annotations.
- Do not introduce runtime dependencies that are unavailable in a drop-in before plugin loading.
- Keep Redis/Valkey access inside the adapter layer.
- Preserve exact WordPress cache semantics for falsey values, multisite scope, non-persistent groups, and `$found`.
- Do not expose raw credentials in diagnostics.
- Do not add broad backend operations such as `KEYS`, `SCAN`, `FLUSHDB`, or `FLUSHALL`.
- Do not hand-edit generated artifacts.

## Local Setup

Install PHP dependencies:

```bash
composer install
```

Start local services when integration or smoke tests require them:

```bash
docker compose up -d
```

The local defaults intentionally avoid common service ports. The Docker Compose endpoints are automatic fallbacks; helper-service values are exported when those optional scenarios are enabled:

| Service | Environment variable | Local default |
| --- | --- | --- |
| Redis 8 | `MINCEMEAT_TEST_REDIS_PORT` | `6383` |
| Valkey 9 | `MINCEMEAT_TEST_VALKEY_PORT` | `6384` |
| MariaDB 11.8 | `MINCEMEAT_TEST_DB_PORT` | `33076` |
| Redis ACL helper | `MINCEMEAT_TEST_ACL_PORT` | `6381` |
| Redis TLS helper | `MINCEMEAT_TEST_TLS_PORT` | `6382` |
| Redis Unix socket helper | `MINCEMEAT_TEST_UNIX_SOCKET` | `/tmp/redis-socket/redis.sock` |
| ACL test username | `MINCEMEAT_TEST_ACL_USER` | `myuser` |
| ACL test password | `MINCEMEAT_TEST_ACL_PASS` | `mypassword` (local test only) |
| Trusted test CA | `MINCEMEAT_TEST_TLS_CA` | `tests/certs/ca.crt` |
| Untrusted test CA | `MINCEMEAT_TEST_TLS_UNTRUSTED_CA` | `tests/certs/untrusted-ca.crt` |

The default host is `127.0.0.1` (`MINCEMEAT_TEST_REDIS_HOST`). Additional helper services may be started by `tools/setup-test-services.sh` for Unix socket, ACL, and TLS scenarios. That script generates local CA files under `tests/certs/`; CI exports all matrix endpoints explicitly.

## Build Commands

Generate the drop-in:

```bash
php tools/build-dropin.php
```

Build the plugin package:

```bash
php tools/build-package.php
```

Package artifacts are intentionally ignored in the working tree. CI verifies deterministic package generation and the ZIP allowlist.

## Validation Commands

Fast local validation after `docker compose up -d`:

```bash
composer validate --strict --no-check-lock
composer lint -- --report=summary
composer stan -- --error-format=raw
composer test
```

Generate fresh PCOV coverage and verify the configured thresholds:

```bash
composer test:coverage
```

Create and compare a local performance baseline against Redis 8:

```bash
composer benchmark -- 127.0.0.1 6383 --save-baseline
composer benchmark -- 127.0.0.1 6383 --compare
```

The benchmark uses fixed workloads, one warmup, five measured samples, and
median latency. It also asserts exact adapter round-trip counts for cache hot
paths. Local snapshots live at `tests/benchmarks-baseline.json` and remain
ignored because timings are meaningful only on the same controlled runner,
PHP/PhpRedis versions, and backend product/version. Use `--json` for the
versioned machine-readable report; connection targets are intentionally omitted.

Run the isolated real-WordPress browser and WP-CLI suite (Docker Compose required):

```bash
composer test:e2e
```

The E2E orchestrator owns a dedicated Compose project and disposable volumes. It builds WordPress 6.9 with PhpRedis 6.3, uses local-only MariaDB/Redis credentials, executes Playwright in its official container, and cleans up automatically. Set `MINCEMEAT_E2E_KEEP_ENV=1` only when retaining a failed environment for debugging.

Drop-in parity:

```bash
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
```

Package determinism check:

```bash
php tools/build-package.php
sha256sum mincemeat-object-cache.zip
php tools/build-package.php
sha256sum mincemeat-object-cache.zip
git diff --exit-code manifest.json mincemeat-object-cache.zip.sha256
```

Full release validation should run in CI across:

- PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5
- Redis 8
- Valkey 9
- single site and multisite
- TCP, Unix socket, ACL, and TLS connection modes
- ACL command denials, TLS verification failures, backend outages, and
  generation-control eviction fault scenarios

## Adding Runtime Changes

1. Change source under `src/`.
2. Add or update tests in the closest matching test layer.
3. Run focused tests.
4. Run `php tools/build-dropin.php`.
5. Verify `stubs/object-cache.php` and `stubs/object-cache.php.sha256` changed only as expected.
6. Run full local validation.
7. Update docs when behavior or support policy changes.

## Adding Facade Functions

When adding or changing a `wp_cache_*` function:

- Match WordPress parameter order and default values.
- Preserve global `$wp_object_cache` behavior.
- Return WordPress-compatible values.
- Add contract tests for the function.
- Add compatibility tests against the relevant WordPress helper behavior.
- Ensure `wp_cache_supports()` advertises only features that truly work.

Do not add test-name-specific branches to runtime code. If a WordPress core compatibility test assumes the stock fallback behavior, handle that in the test harness rather than in production source.

## Release Procedure

Before a public release or stable tag:

1. Review `docs/IMPROVEMENT_PLAN.md` for any release-bound items.
2. Update plugin header version and readme stable tag.
3. Verify `readme.txt` metadata for WordPress.org conventions.
4. Run full CI.
5. Build release package in a clean environment.
6. Verify package checksum, manifest, deterministic rebuild, and ZIP allowlist.
7. Smoke-test install, activation, Site Health, WP-CLI status, drop-in install, drop-in removal, and checksum mismatch handling.

## Documentation Policy

The docs in this directory are intended to be executable planning material for maintainers and agents. Keep them concrete:

- State what is true now.
- Name release blockers explicitly.
- Link implementation expectations to files and commands.
- Remove stale review language after items are closed.
- Avoid aspirational claims that are not verified by tests or CI.
