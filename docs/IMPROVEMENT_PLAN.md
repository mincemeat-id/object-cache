# Mincemeat Object Cache - Improvement Plan

Date: 2026-07-13
Status: post-remediation, release-candidate quality; Milestones 1 through 6 complete.
Scope: next engineering improvements after the production-readiness remediation work was completed and pushed.

This replaces the old production-readiness remediation plan. The previous blocker set is closed: the test-specific `wp_cache_flush_group()` branch is gone, package artifacts are ignored rather than committed, package determinism is checked, artifact parity CI has been expanded, JSON test configuration is in place, and local credential/certificate hygiene has improved.

There are no newly identified P0 release blockers from this documentation-only review. The next work is quality, compatibility, test depth, and performance.

## Current Evidence

Local environment:

- PHP: `8.4.23`
- PhpRedis extension: `6.3.0`
- PCOV extension: `1.0.12`
- Redis service: Redis 8 on local port `6383`
- Valkey service: Valkey 9 on local port `6384`
- WordPress test checkout: `6.9.4-src`

Checks run during this review:

```bash
php -m | sort | rg '^(pcov|redis)$'
php --ri redis
php --ri pcov
MINCEMEAT_TEST_REDIS_HOST=127.0.0.1 \
MINCEMEAT_TEST_REDIS_PORT=6383 \
MINCEMEAT_TEST_VALKEY_PORT=6384 \
MINCEMEAT_TEST_DB_PORT=33076 \
vendor/bin/phpunit --coverage-clover build/logs/clover.xml --coverage-text
php tools/verify-coverage.php
composer stan -- --error-format=raw --level=8 --memory-limit=1G
```

Results:

- PHPUnit with explicit local ports passed: `384 tests`, `1211 assertions`, `7 skipped`.
- PCOV coverage was generated successfully.
- Overall line coverage: `77.02%` (`1632/2119`).
- Existing coverage verifier passed.
- PHPStan level 8 initially failed with `59` file errors concentrated in `Backend`, `ObjectCache`, and `PhpRedisAdapter`.
- Milestone 1 resolved those errors without a baseline or ignore comments; level 8 is now the configured default.

The level 8 failures were mostly nullable invariant issues that the runtime enforced operationally but PHPStan could not prove, plus numeric return precision. They were resolved as implementation-shape work rather than suppression work.

## Upstream Context

Primary sources checked:

- PhpRedis 6.3.0 release notes: https://github.com/phpredis/phpredis/releases/tag/6.3.0
- PhpRedis 6.3.0 changelog: https://github.com/phpredis/phpredis/blob/6.3.0/CHANGELOG.md
- PhpRedis 6.3.0 stubs and README: https://github.com/phpredis/phpredis/tree/6.3.0
- WordPress 6.9 cache API: https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/cache.php
- WordPress 6.9 cache compatibility helpers: https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/cache-compat.php
- WordPress 6.9 object cache class: https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/class-wp-object-cache.php

Important upstream facts:

- WordPress 6.9 adds salted cache helper functions in `cache-compat.php`: `wp_cache_get_salted()`, `wp_cache_set_salted()`, `wp_cache_get_multiple_salted()`, and `wp_cache_set_multiple_salted()`.
- WordPress 6.9 core cache facade signatures still match the current Mincemeat facade shape for the standard `wp_cache_*` functions.
- WordPress core's `WP_Object_Cache` still exposes compatibility surface beyond methods: public `cache_hits` and `cache_misses`, magic accessors for private/protected properties, and a `stats()` method.
- PhpRedis 6.3.0 was released on 2025-11-06 and adds or improves `serverName()`, `serverVersion()`, `getWithMeta`, `hgetwithmeta`, `HGETEX`, `HSETEX`, `HGETDEL`, Valkey `DELIFEQ`, Redis vector commands, constructor `database`, `maxRetries` socket configuration, and internal command/performance work.
- PhpRedis supports connection and retry options that are relevant to this plugin: `OPT_MAX_RETRIES`, `OPT_BACKOFF_ALGORITHM`, `OPT_BACKOFF_BASE`, `OPT_BACKOFF_CAP`, `OPT_TCP_KEEPALIVE`, `OPT_READ_TIMEOUT`, `OPT_REPLY_LITERAL`, serializer options, and compression options.

## Milestone 1: PHPStan Level 8

Goal: make `composer stan` run at level 8 over runtime source without baselines or ignore comments.

Status: complete on 2026-07-13.

Findings:

- `Backend` stores the adapter as nullable, then most command methods assume `is_persistent()` proves it is non-null.
- `ObjectCache` stores the backend as nullable, then persistent branches rely on `is_persistent_group()` proving it is non-null.
- `ObjectCache::delta()` and `ObjectCache::persistent_delta()` can return inferred `float|int` because arithmetic over numeric mixed values widens the type.
- `PhpRedisAdapter::pipeline()` uses `call_user_func_array()` on a value PHPStan cannot prove is a callable Redis object.

Implemented:

1. Added explicit non-null `Backend::adapter()` and `ObjectCache::backend()` accessors for persistent invariants.
2. Persistent-only operations use those accessors so nullable state is checked at one boundary.
3. Numeric paths now coerce to bounded integers, and contract tests cover float normalization and overflow.
4. Pipeline dispatch is allowlisted to `set`, `unlink`, and `del`; arbitrary dynamic callable dispatch is gone.
5. `phpstan.neon` now defaults to level 8 without baselines or ignore comments.

Acceptance criteria:

```bash
composer stan -- --error-format=raw --level=8 --memory-limit=1G
composer stan -- --error-format=github
vendor/bin/phpunit
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
```

## Milestone 2: Local Developer Experience

Goal: the documented local commands should work against the repository's docker compose defaults without hidden environment knowledge.

Status: complete on 2026-07-13.

Findings:

- `docker-compose.yml` maps Redis 8 to `6383` and Valkey 9 to `6384`.
- A plain PHPUnit run previously hit CI-oriented defaults inside some test paths.
- CI uses explicit matrix endpoints while local fallbacks now match Docker Compose.
- `composer test:coverage` generates a fresh Clover report before verifying it.

Implemented:

1. `composer test` and authoritative gates default to Redis `6383`, Valkey `6384`, and MariaDB `33076` locally.
2. CI and scheduled matrix workflows export Redis/Valkey/MariaDB ports explicitly.
3. `composer test:coverage` runs PHPUnit with Clover generation, then verifies the fresh report.
4. README and implementation docs list all local Docker and helper-service endpoints.
5. Preflight output reports only the expected backend and selected scheme/port, never the full configuration.

Acceptance criteria:

```bash
docker compose up -d
composer test
composer test:coverage
```

Both should pass on a fresh checkout with PhpRedis and PCOV installed.

## Milestone 3: PCOV Coverage Expansion

Goal: use PCOV as the normal local and CI coverage driver, then raise coverage only where it increases confidence.

Status: complete on 2026-07-13.

Completed coverage snapshot:

| Component | Line coverage |
| --- | ---: |
| `src/KeySpace.php` | `100.00%` |
| `src/Config.php` | `97.66%` |
| `src/ValueCodec.php` | `94.51%` |
| `src/SiteHealth.php` | `84.69%` |
| `src/ObjectCache.php` | `78.89%` |
| `src/Lifecycle.php` | `83.70%` |
| `src/PhpRedisAdapter.php` | `87.78%` |
| `src/Backend.php` | `95.79%` |

Implemented:

1. Raised overall line coverage from `77.02%` to `85.13%` (`1832/2152`) and made `85%` the default verifier threshold.
2. Added critical-file regression thresholds for `Backend`, `PhpRedisAdapter`, `ObjectCache`, and `Lifecycle` alongside the existing pure-component thresholds.
3. Covered backend token races and retry exhaustion, command and pipeline degradation, script failures, and per-key batch fallbacks.
4. Covered PhpRedis connection setup failures, safe option configuration, command defaults/results, pipeline dispatch, and sanitized server identity fallback.
5. Covered non-persistent batch semantics, object clone isolation, numeric boundaries, and request-local fallback behavior in `ObjectCache`.
6. Expanded lifecycle marker, ownership, unreadable/non-file, checksum, permissions, atomic installation/removal, and WP-CLI result coverage without touching real user drop-ins.

Acceptance criteria:

```bash
vendor/bin/phpunit --coverage-clover build/logs/clover.xml --coverage-text
php tools/verify-coverage.php
```

The verifier should fail when critical file coverage regresses and should document every threshold as a current baseline or a deliberate target.

## Milestone 4: E2E Tests

Goal: add browser-level and CLI-level confidence around the companion plugin and drop-in lifecycle.

Status: complete on 2026-07-13.

Previous state:

- The project has strong PHPUnit, integration, WordPress core cache gate, and smoke coverage.
- The current smoke tool boots WooCommerce, Yoast SEO, and Easy Digital Downloads through WordPress test bootstrap.
- There is not yet a true browser E2E suite that exercises admin activation, Site Health UI, frontend requests, or WP-CLI flows in a real WordPress install.

Recommended E2E scenarios:

1. Install and activate the companion plugin.
2. Install the drop-in through WP-CLI and verify `wp-content/object-cache.php`.
3. Open Site Health Info and confirm status fields are present with redacted values.
4. Load admin and frontend pages with Redis available.
5. Stop Redis during a request sequence and confirm WordPress remains available while Site Health degrades safely.
6. Restore Redis and confirm a new request returns to persistent state.
7. Verify multisite activation and network admin status.
8. Verify the plugin refuses to overwrite a foreign drop-in.
9. Verify plugin deactivation/removal only removes a managed current drop-in.

Candidate tooling:

- WordPress E2E utilities plus Playwright for browser flows.
- WP-CLI in the test container for lifecycle commands.
- Docker compose service controls for Redis/Valkey outage tests.

Implemented:

1. Added a disposable Docker Compose environment with WordPress 6.9, PHP 8.4, PhpRedis 6.3, MariaDB 11.8, and authenticated Redis 8.
2. Added Playwright coverage for browser activation, Site Health Info fields and redaction, admin/frontend availability, backend outage degradation, recovery to persistent state, and multisite network activation.
3. Added real WP-CLI coverage for drop-in install/status/remove, file presence, foreign drop-in overwrite/removal refusal, managed deactivation cleanup, and network-active status.
4. The orchestrator creates fresh named volumes, uses only local test credentials, removes state on exit, and retains browser traces/screenshots only on failure.
5. Added `composer test:e2e` and a single-version CI job, with failure artifact upload, before considering a broader matrix.

Acceptance criteria:

```bash
composer test:e2e
```

The suite should create its own WordPress state, use local-only credentials, redact output on failure, and run in CI on at least one PHP version before expanding to the full matrix.

## Milestone 5: WordPress Compatibility

Goal: stay faithful to the WordPress 6.9+ cache contract and reduce surprises for plugins that inspect `$wp_object_cache`.

Status: complete on 2026-07-13.

Findings:

- Standard facade signatures match WordPress 6.9.
- Salted cache helper tests are already included in the authoritative gate command.
- WordPress core's object cache has public/magic compatibility behavior not fully mirrored by Mincemeat.
- Core deprecation and `_doing_it_wrong()` versions use WordPress versions, while some Mincemeat messages use plugin version `1.0.0`.

Implemented:

1. Added public `cache_hits` and `cache_misses` counters matching core, while retaining the existing method accessors.
2. Added read-only magic compatibility views for `global_groups` and `blog_prefix`; these delegate to `KeySpace` so inspected values cannot drift from runtime scope decisions.
3. Added core-shaped `isset()` behavior and `stats()` HTML output with escaped group labels.
4. Added direct contract tests for all four WordPress 6.9 salted cache helpers, including array salts, falsey data, stale salts, malformed envelopes, misses, and per-key batch results.
5. Expanded real-WordPress query smoke coverage to prove second-query cache hits for `post-queries`, `term-queries`, `comment-queries`, `site-queries`, `network-queries`, and `user-queries`; site and network cases run in multisite mode.
6. Aligned invalid-key `_doing_it_wrong()` notices with WordPress `6.1.0` and reset deprecations with WordPress `3.5.0` and core replacement names.
7. Added persistent-backend compatibility coverage proving WordPress bootstrap's `counts`, `plugins`, and `theme_json` groups remain request-local.

Acceptance criteria:

```bash
vendor/bin/phpunit --group contract
vendor/bin/phpunit --group compatibility
MINCEMEAT_TEST_REDIS_HOST=127.0.0.1 \
MINCEMEAT_TEST_REDIS_PORT=6383 \
MINCEMEAT_TEST_VALKEY_PORT=6384 \
vendor/bin/phpunit --group integration
```

## Milestone 6: PhpRedis 6.3.0 Modernization

Goal: make PhpRedis 6.3.0 the deliberate target, using modern capabilities when they improve correctness, observability, or performance.

Status: complete on 2026-07-13.

Implemented:

1. PhpRedis `>=6.3.0` is now a hard Composer and runtime requirement, explicitly installed in CI and reported by diagnostics and Site Health.
2. Server identity prefers `serverName()` and `serverVersion()`, with a sanitized `INFO` fallback for Redis, Valkey, and unknown compatible services.
3. Configuration now exposes bounded command retries, reconnect backoff algorithm/base/cap, TCP keepalive, and read timeout; these settings are redacted appropriately in public diagnostics.
4. Connection setup applies and reads back serializer, compression, prefix, reply, timeout, retry/backoff, and keepalive options before accepting the connection.
5. The numeric Lua script is loaded per adapter connection and invoked with `EVALSHA`; `NOSCRIPT` recovers through `EVAL`, while ACL and other errors do not trigger unsafe fallback behavior.
6. Unit coverage exercises version rejection, option configuration and verification, modern/fallback identity, script SHA use, and `NOSCRIPT` recovery. Redis 8 and Valkey 9 remain explicit CI integration matrix targets.

Policy decision (settled):

- v1 requires PhpRedis `>=6.3.0`; older extension versions fail initialization rather than entering a partially supported capability mode.
- README, `readme.txt`, Composer, Site Health, diagnostics, CI, docs, and tests state or enforce that minimum.

Recommended capability work:

1. Server identity:
   - Prefer `serverName()` and `serverVersion()` when available.
   - Fall back to sanitized `INFO` parsing.
   - Preserve Redis, Valkey, and unknown compatible service behavior without branching cache semantics by product.
2. Connection reliability:
   - Add config keys or internal defaults for `OPT_MAX_RETRIES`, backoff algorithm/base/cap, `OPT_TCP_KEEPALIVE`, and `OPT_READ_TIMEOUT`.
   - Keep retry budgets bounded so Redis outages do not stall WordPress bootstrap.
   - Include redacted retry/backoff information in diagnostics.
3. Lua script performance:
   - Load the numeric Lua script with `SCRIPT LOAD`.
   - Use `EVALSHA`.
   - Fall back to `EVAL` on `NOSCRIPT`.
   - Cache script SHA per adapter connection, not globally across incompatible backends.
4. Option validation:
   - After connect, assert serializer is `SERIALIZER_NONE`, compression is `COMPRESSION_NONE`, prefix is empty, and reply behavior matches expectations.
   - Do not enable PhpRedis serializer or compression unless the plugin's wire-format ownership is deliberately redesigned.
5. Modern command review:
   - Evaluate `getWithMeta` for diagnostics or TTL-aware debug information, not hot-path behavior unless benchmarks justify it.
   - Treat Valkey `DELIFEQ`, hash-field expiration, and vector commands as interesting but not automatically relevant to an object-cache drop-in.
   - Keep direct Redis/Valkey operations inside the adapter layer.

Acceptance criteria:

```bash
vendor/bin/phpunit --group unit
MINCEMEAT_REQUIRE_INTEGRATION=1 vendor/bin/phpunit --group integration
php tools/benchmark-soak.php 127.0.0.1 6383 --compare
```

New PhpRedis behavior must be covered against both Redis 8 and Valkey 9 in CI.

## Milestone 7: Performance Guardrails

Goal: make performance changes measurable and prevent accidental hot-path regressions.

Current state:

- `tools/benchmark-soak.php` exists and covers scalar set, backend hit, request-memory hit, backend miss, multiple get, group flush, failed backend connection, and soak behavior.
- The baseline file is ignored, so performance history is local only.

Recommended work:

1. Stabilize benchmark inputs and output JSON.
2. Add a benchmark baseline policy:
   - local baselines ignored by default, or
   - versioned baseline snapshots for release branches only.
3. Track these hot paths:
   - request-memory hit
   - backend hit
   - backend miss
   - `get_multiple`
   - `set_multiple`
   - `delete_multiple`
   - group token resolution
   - namespace flush
   - group flush
   - failed connection/circuit-open path
4. Add command-count assertions where possible so optimization work does not accidentally add backend round trips.
5. Compare `EVAL` vs `SCRIPT LOAD`/`EVALSHA` after the PhpRedis 6.3.0 modernization.

Acceptance criteria:

```bash
php tools/benchmark-soak.php 127.0.0.1 6383 --save-baseline
php tools/benchmark-soak.php 127.0.0.1 6383 --compare
```

Release notes should include benchmark context only when the runner and environment are controlled enough to be meaningful.

## Milestone 8: Stability And Fault Injection

Goal: prove WordPress remains available and diagnostics remain redacted during realistic backend failures.

Recommended scenarios:

- Redis disconnect during `get`.
- Redis disconnect during `set`.
- Redis disconnect during pipeline execution.
- Redis disconnect during `EVALSHA`/`EVAL`.
- `NOSCRIPT` during numeric operation.
- TLS peer verification failure.
- TLS peer-name mismatch.
- ACL authentication failure.
- ACL permission denial for `EVAL`, `SCRIPT`, `UNLINK`, or `INFO`.
- Backend timeout with retry/backoff enabled.
- Maxmemory eviction of control keys.
- Corrupt value envelope in Redis.
- Persistent connection reused with changed database, ACL, TLS, or namespace identity.

Acceptance criteria:

- No raw credentials, hostnames, TLS paths, socket paths, or stack traces appear in Site Health, logs, test output, or CI artifacts.
- The object cache enters runtime-only or degraded state without throwing into ordinary WordPress request flow.
- Metrics and Site Health distinguish configuration errors, connect failures, auth failures, command failures, and degraded state.

## Suggested Execution Order

1. Fix local developer command defaults and coverage script behavior.
2. Raise PHPStan to level 8.
3. Expand PCOV thresholds for Backend, PhpRedisAdapter, ObjectCache, and Lifecycle.
4. Add direct WordPress 6.9 salted helper and core compatibility-property tests.
5. Implement PhpRedis 6.3.0 server identity and bounded retry/backoff capability detection.
6. Add `SCRIPT LOAD`/`EVALSHA` with `NOSCRIPT` fallback.
7. Add browser/WP-CLI E2E tests.
8. Add performance baselines and fault-injection expansion.

## Release Gate For Future Stable Tags

A future stable tag should require:

- PHPStan level 8 passes.
- PHPUnit, authoritative WordPress cache gates, and smoke tests pass with local documented ports and CI matrix ports.
- PCOV coverage thresholds pass and are current.
- E2E lifecycle/Site Health smoke suite passes.
- Redis 8 and Valkey 9 pass single-site and multisite integration.
- TCP, TLS, ACL, and Unix socket scenarios pass.
- Drop-in parity passes.
- Package determinism and ZIP allowlist checks pass in CI.
- Docs accurately state WordPress, PHP, Redis, Valkey, and PhpRedis support policy.
