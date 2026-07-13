# Mincemeat Object Cache - Implementation Guide

Date: 2026-07-13
Audience: maintainers, contributors, release engineers, and AI agents.

## Current Status

The implementation is pre-release and close to release-candidate quality, but not ready for a public production tag.

Verified locally:

- Composer metadata validates.
- PHPCS passes.
- PHPStan level 6 passes.
- PHPUnit passes locally with 6 skipped tests and coverage-driver warnings.
- The generated drop-in remained stable in the local drop-in parity check.

Known release blockers:

- `wp_cache_flush_group()` contains a test-specific runtime branch.
- Package ZIP generation is not deterministic.
- Artifact parity CI does not yet verify all generated release files.

## Repository Map

| Path | Edit policy |
| --- | --- |
| `src/` | Primary runtime source. Edit this for cache behavior. |
| `src/functions.php` | Global WordPress facade functions. Keep signatures WordPress-compatible. |
| `stubs/object-cache.php` | Generated. Do not edit manually. |
| `stubs/object-cache.php.sha256` | Generated. Do not edit manually. |
| `mincemeat-object-cache.php` | Companion plugin entry point and lifecycle integration. |
| `tools/build-dropin.php` | Drop-in generator. |
| `tools/build-package.php` | Package generator. Currently needs determinism fixes. |
| `tools/install-wp-tests.sh` | WordPress test-suite setup. |
| `tools/setup-test-services.sh` | Local Redis/Valkey/TLS helper. Needs hygiene improvements. |
| `tests/` | PHPUnit coverage. |
| `docs/` | Design and release-readiness docs. Currently ignored by `.gitignore`; force-add or unignore before committing. |
| `AGENTS.md` | Root instructions for future coding agents. |

## Development Rules

- Runtime and generated drop-in must remain PHP 7.4 compatible.
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

The local defaults intentionally avoid common service ports:

| Service | Host port |
| --- | --- |
| Redis 8 | `6383` |
| Valkey 9 | `6384` |
| MariaDB 11.8 | `33076` |

Additional helper services may be started by `tools/setup-test-services.sh` for Unix socket, ACL, and TLS scenarios.

## Build Commands

Generate the drop-in:

```bash
php tools/build-dropin.php
```

Build the plugin package:

```bash
php tools/build-package.php
```

Important: package generation is currently not deterministic. Do not rely on repeated package hashes until the remediation plan is complete.

## Validation Commands

Fast local validation:

```bash
composer validate --strict --no-check-lock
composer lint -- --report=summary
composer stan -- --error-format=raw
vendor/bin/phpunit
```

Drop-in parity:

```bash
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
```

Package determinism target after remediation:

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

Before a public release candidate:

1. Close all P0 remediation items.
2. Decide whether package artifacts are committed or release-only.
3. Make package builds deterministic if package artifacts are committed or if source-to-artifact verification is expected.
4. Update plugin header version and readme stable tag.
5. Verify `readme.txt` metadata for WordPress.org conventions.
6. Run full CI.
7. Build release package in a clean environment.
8. Verify package checksum and manifest.
9. Smoke-test install, activation, Site Health, WP-CLI status, drop-in install, drop-in removal, and checksum mismatch handling.

## Documentation Policy

The docs in this directory are intended to be executable planning material for maintainers and agents. Keep them concrete:

- State what is true now.
- Name release blockers explicitly.
- Link implementation expectations to files and commands.
- Remove stale review language after items are closed.
- Avoid aspirational claims that are not verified by tests or CI.

The repository currently ignores `/docs/` in `.gitignore`. If these documents are meant to ship with the project, remove that ignore rule or force-add the docs deliberately.
