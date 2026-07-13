# Mincemeat Object Cache - Production Readiness Analysis

Date: 2026-07-13
Scope: current repository state after the latest pushed implementation work.
Target: public WordPress Redis/Valkey object-cache plugin for WordPress 6.9+, PHP 7.4 through PHP 8.5, Redis 8, and Valkey 9.

## Executive Summary

The project has moved from prototype/remediation into a credible pre-release state. The runtime has a generated standalone drop-in, a companion plugin, Site Health diagnostics, WP-CLI lifecycle commands, Redis/Valkey integration coverage, PHP 8.5 CI coverage, and stronger static analysis than the earlier review baseline.

It is not ready for a public production tag yet. Two release-blocking issues remain:

1. Runtime source contains a test-name-specific branch in `wp_cache_flush_group()`.
2. The package builder claims reproducible output, but `mincemeat-object-cache.zip`, its checksum, and `manifest.json` change on every build.

Those issues are small in code footprint but large in trust impact. A public caching plugin must not ship runtime behavior that depends on test backtraces, and release artifacts must be deterministic or explicitly treated as generated build outputs.

## Validation Snapshot

Local validation was run on PHP 8.4.23.

| Check | Result | Notes |
| --- | --- | --- |
| `composer validate --strict --no-check-lock` | Pass | Composer metadata is valid. |
| `composer lint -- --report=summary` | Pass | PHPCS passes locally. |
| `composer stan -- --error-format=raw` | Pass | PHPStan level 6 completed. |
| `vendor/bin/phpunit` | Pass with warnings/skips | 380 tests, 1195 assertions, 6 skipped. Warnings are from missing local coverage driver. |
| Facade smoke for `wp_cache_supports('flush_group')` and `wp_cache_flush_group()` | Pass | Ordinary runtime path reports support and flushes the group. |
| `php tools/build-package.php` repeated twice | Fail determinism | ZIP/checksum/manifest changed every run because ZIP entry metadata is not normalized. |
| `git diff -- stubs/object-cache.php stubs/object-cache.php.sha256 --exit-code` | Pass | Drop-in and sidecar were stable during this validation. |

Local validation did not prove the full connection matrix. The release gate must still rely on CI coverage for PHP 7.4 through 8.5, Redis 8, Valkey 9, TCP, Unix socket, TLS, ACL, single site, and multisite.

## External Compatibility Context

WordPress 6.9 introduces cache-key consistency work for query groups and continues to expose cache compatibility helpers through `wp-includes/cache.php` and `wp-includes/cache-compat.php`.

PHP 8.5 is available as a current PHP branch, but WordPress 6.9 core communication describes PHP 8.5 support as beta-level. This plugin can and should test against PHP 8.5, while release notes should avoid implying that the entire WordPress ecosystem is fully stable on PHP 8.5.

References:

- WordPress 6.9 query cache-key consistency: https://make.wordpress.org/core/2025/11/17/consistent-cache-keys-for-query-groups-in-wordpress-6-9/
- WordPress 6.9 `cache.php`: https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/cache.php
- WordPress 6.9 `cache-compat.php`: https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/cache-compat.php
- PHP supported versions: https://www.php.net/supported-versions.php
- PHP 8.5 release page: https://www.php.net/releases/8.5/en.php
- WordPress 6.9 PHP 8.5 support note: https://make.wordpress.org/core/2025/11/21/php-8-5-support-in-wordpress-6-9/

## Current Strengths

The core design is now on the right track for a production object-cache drop-in:

- Runtime code is generated into a standalone `stubs/object-cache.php` drop-in.
- Source is organized under `src/` and covered by unit, contract, lifecycle, compatibility, integration, and smoke tests.
- The companion plugin owns lifecycle, Site Health, checksum verification, and WP-CLI operations instead of mixing admin UI state into the cache runtime.
- Redis/Valkey support covers TCP, Unix socket, TLS, ACL authentication, persistent connection identity, Lua-assisted numeric operations, and safe fallbacks.
- The object-cache facade covers modern WordPress cache helpers such as multiple get/set/delete, runtime flush, group flush, and close.
- Security posture has improved: diagnostics redact credentials, there is no settings UI that can leak connection data, and the implementation avoids broad keyspace operations like `KEYS`, `SCAN`, or database-wide flushes.
- CI now includes PHP 8.5 and newer Redis/Valkey service coverage.

## Findings

### P0: Production runtime contains a test-specific branch

`src/functions.php` and the generated `stubs/object-cache.php` inspect `debug_backtrace()` inside `wp_cache_flush_group()` and return the WordPress core compatibility fallback only when the caller function is named `test_wp_cache_flush_group`.

Why this matters:

- Production runtime behavior must not depend on test method names.
- Backtrace inspection adds overhead on a cache facade path.
- The branch encodes a testing artifact into public behavior.
- It makes future compatibility work harder because correctness depends on caller identity.

Required remediation:

- Remove the backtrace branch from source and generated drop-in.
- Keep `wp_cache_supports('flush_group') === true`.
- Keep `wp_cache_flush_group()` delegating to the object-cache instance.
- If a WordPress core test expects unsupported behavior for the stock fallback, adapt the test harness or mark that upstream compatibility test as not applicable for this external cache.

### P0: Package build is not deterministic

`tools/build-package.php` describes the ZIP as reproducible, but repeated runs changed `mincemeat-object-cache.zip`, `mincemeat-object-cache.zip.sha256`, and `manifest.json`.

Likely cause:

- `ZipArchive::addFromString()` records current timestamps and default entry attributes unless explicitly normalized.

Required remediation:

- Normalize ZIP entry mtime with a fixed timestamp.
- Normalize external attributes and file permissions.
- Keep file order stable.
- Decide whether generated ZIP artifacts are committed or produced only in release CI.
- Add CI that builds twice and compares hashes.
- Add CI parity for `manifest.json`, `mincemeat-object-cache.zip.sha256`, and package contents, not only `stubs/object-cache.php`.

### P1: Artifact parity CI is incomplete

The current artifact-parity job checks `stubs/object-cache.php`, but not the drop-in checksum, package manifest, package checksum, or package determinism.

Required remediation:

- Verify `stubs/object-cache.php.sha256` after `composer build`.
- Verify `manifest.json` and `mincemeat-object-cache.zip.sha256` after package build if those files remain committed.
- Build the package twice in clean workspaces and compare SHA-256 hashes.
- Inspect the ZIP file list against an allowlist.

### P1: Test setup tooling uses unsafe serialization

`tools/install-wp-tests.sh` injects `MINCEMEAT_OBJECT_CACHE_CONFIG` into PHP and decodes it with `unserialize()`.

Why this matters:

- It is test-only, but public repositories teach operational patterns.
- Serialized input is unnecessary and harder to validate.

Required remediation:

- Accept JSON instead.
- Decode with `json_decode(..., true)`.
- Validate expected keys and scalar types before writing config.

### P1: Local service tooling creates sensitive-looking artifacts

`tools/setup-test-services.sh` generates TLS material under `tests/certs`, uses permissive modes, and the repository root contains a `mypassword` file.

Why this matters:

- Test credentials can be low-risk, but committed or stray secret-looking files reduce confidence.
- Public plugin repositories should model clean local development hygiene.

Required remediation:

- Generate ephemeral certs in a temp or ignored build directory.
- Avoid `chmod 777`; use the narrowest permissions compatible with Docker volume access.
- Remove stray credential artifacts from the project root.
- Document all test credentials as local-only.

### P1: Public release messaging still needs hardening

The plugin is still `1.0.0-dev`, and README/readme metadata must be aligned before public distribution.

Required remediation:

- Replace development version metadata for the release candidate.
- Verify `readme.txt` headers, stable tag, tested-up-to, requires-at-least, requires-PHP, and license fields.
- Add explicit support language: tested on PHP 7.4 through 8.5; WordPress 6.9+ required; PHP 8.5 WordPress ecosystem support may still be beta depending on core/plugin stack.

### P2: Composer PHP constraint is broader than the tested support policy

`composer.json` requires `"php": ">=7.4"`, which allows future PHP versions beyond the documented test target.

Options:

- Keep the broad lower-bound constraint and document that official support is limited to CI-tested versions.
- Add a Composer `conflict` for future PHP majors until CI proves compatibility.

For a WordPress plugin, the first option is often less disruptive, but the support boundary must be explicit.

### P2: Small runtime polish remains

Potential follow-up items:

- Remove no-op state assignments and similar cleanup once release blockers are fixed.
- Add micro-benchmarks for hot facade functions and request-memory hit paths.
- Add fault-injection tests for Redis connection loss during `set`, `get`, Lua numeric operations, and group-token rotation.
- Add release checks that verify no sensitive values appear in Site Health output.

## Release Recommendation

Do not tag a public production release until P0 items are closed and verified in CI.

Recommended next milestone:

1. Remove test-specific runtime behavior.
2. Make package generation deterministic or move package artifacts out of the committed tree.
3. Expand artifact parity CI.
4. Re-run full CI matrix.
5. Update public release metadata.

After those items pass, the remaining P1/P2 items can be triaged into the first release candidate or the first patch release depending on risk tolerance.
