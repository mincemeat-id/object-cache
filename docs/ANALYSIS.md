# Mincemeat Object Cache - Current Analysis

Date: 2026-07-13
Scope: fresh documentation-only review after the production-readiness remediation work was completed and pushed.
Target: WordPress 6.9+, PHP 7.4 through PHP 8.5, Redis 8, Valkey 9, and PhpRedis 6.3.0.

## Summary

The project has moved beyond the old remediation phase. Runtime source, generated drop-in, deterministic package tooling, expanded CI, WordPress cache gates, redacted diagnostics, local service tooling, coverage checks, and release-candidate metadata are all present.

The next useful work is not another blocker burn-down. It is improving confidence and capability:

- Maintaining PHPStan level 8 as runtime source evolves.
- True E2E tests.
- Stronger PCOV-based coverage.
- Maintaining one-command local test and coverage workflows.
- WordPress compatibility surface hardening.
- PhpRedis 6.3.0 modernization.
- Continued performance and fault-injection work.

The detailed roadmap is in `docs/IMPROVEMENT_PLAN.md`.

## Validation Snapshot

Local validation was run on PHP 8.4.23 with PhpRedis 6.3.0 and PCOV 1.0.12.

```bash
MINCEMEAT_TEST_REDIS_HOST=127.0.0.1 \
MINCEMEAT_TEST_REDIS_PORT=6383 \
MINCEMEAT_TEST_VALKEY_PORT=6384 \
MINCEMEAT_TEST_DB_PORT=33076 \
vendor/bin/phpunit --coverage-clover build/logs/clover.xml --coverage-text
```

Result:

- `384 tests`
- `1211 assertions`
- `7 skipped`
- pass

Coverage:

- overall line coverage: `77.02%`
- `src/KeySpace.php`: `100.00%`
- `src/Config.php`: `97.66%`
- `src/ValueCodec.php`: `94.51%`
- `src/SiteHealth.php`: `84.69%`
- `src/ObjectCache.php`: `78.25%`
- `src/Lifecycle.php`: `69.57%`
- `src/PhpRedisAdapter.php`: `64.65%`
- `src/Backend.php`: `64.05%`

The existing coverage verifier passed:

```bash
php tools/verify-coverage.php
```

PHPStan level 8 passes and is the configured default:

```bash
composer stan -- --error-format=raw --level=8 --memory-limit=1G
```

The original 59 errors were resolved with explicit non-null invariant accessors, bounded integer numeric paths, and allowlisted pipeline dispatch. No baseline or ignore comments were added.

## Fresh Findings

### PHPStan Level 8 Is Complete

Persistent-state invariants are now encoded through `Backend::adapter()` and `ObjectCache::backend()`. Numeric cache operations have explicit integer returns, and `PhpRedisAdapter::pipeline()` uses an allowlisted dispatcher.

Keep `phpstan.neon` at level 8 and do not introduce baselines or ignore comments.

### Local Commands Match Docker Defaults

Local test fallbacks now match Redis 8 on `6383`, Valkey 9 on `6384`, and MariaDB on `33076`. CI exports its `6379`, `6380`, and `3306` matrix endpoints explicitly.

`composer test` runs against Docker Compose defaults without extra environment variables. `composer test:coverage` generates a fresh Clover report before enforcing coverage thresholds.

### PCOV Makes Coverage Work Actionable

PCOV is now available, and coverage output is fast enough to be a normal local signal. The biggest coverage opportunities are not the already-well-tested pure components. They are:

- `Backend`
- `PhpRedisAdapter`
- `ObjectCache`
- `Lifecycle`

The plan should raise coverage where it validates failure behavior and hot-path semantics.

### E2E Is The Missing Test Layer

Current testing is strong at PHPUnit, integration, WordPress core cache gates, and plugin bootstrap smoke tests. The missing layer is browser and WP-CLI E2E:

- activation
- drop-in install/remove
- Site Health UI
- frontend and admin page loads
- Redis outage and recovery
- multisite network admin
- foreign drop-in refusal

### WordPress 6.9 Compatibility Should Go Beyond Facades

The standard `wp_cache_*` facade signatures match WordPress 6.9, and the authoritative gate already includes WordPress salted cache helper tests.

Additional compatibility surface deserves attention:

- public `cache_hits`
- public `cache_misses`
- readable `global_groups`
- readable `blog_prefix`
- magic property behavior
- `stats()` output
- WordPress version strings in `_doing_it_wrong()` and `_deprecated_function()` calls
- default non-persistent groups used by WordPress tests and bootstrap

### PhpRedis 6.3.0 Opens Practical Improvements

The most relevant PhpRedis 6.3.0 opportunities are:

- `serverName()` and `serverVersion()` for cleaner diagnostics.
- bounded retry/backoff configuration through `OPT_MAX_RETRIES` and `OPT_BACKOFF_*`.
- `OPT_TCP_KEEPALIVE` for long-running PHP workers.
- `SCRIPT LOAD` plus `EVALSHA` for the numeric Lua script, with `NOSCRIPT` fallback.
- option verification after connect so serializer, compression, and prefix assumptions remain true.

Hash field expiration, vector commands, and Valkey `DELIFEQ` are real 6.3.0 features, but they are not automatically useful for a WordPress object-cache drop-in. Evaluate them only when they simplify a real cache behavior.

## Current Strengths

- Runtime code is generated into a standalone drop-in.
- Source and generated artifacts are separated.
- Generated drop-in checksum parity is checked.
- Package artifacts are ignored and determinism is verified in CI.
- Redis/Valkey access is isolated in the adapter layer.
- The runtime avoids broad keyspace operations during normal behavior.
- Diagnostics redact sensitive connection information.
- WordPress core cache gates run against runtime-only, Redis, Valkey, single-site, and multisite modes.
- PCOV coverage now works locally.
- The project already has a benchmark/soak tool.

## Current Risks

- E2E coverage is not yet present.
- Backend and adapter coverage are lower than the most security-sensitive pure components.
- WordPress compatibility for direct `$wp_object_cache` property access has not been fully proven.
- PhpRedis 6.3.0 is installed locally but not yet fully reflected in support policy, diagnostics, or retry/script strategy.

## Recommendation

Use `docs/IMPROVEMENT_PLAN.md` as the next work queue. Continue with PCOV threshold expansion and WordPress compatibility coverage before adding larger PhpRedis behavior changes.
