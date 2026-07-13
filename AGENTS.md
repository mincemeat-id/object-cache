# AGENTS.md

Instructions for AI coding agents and automation working on Mincemeat Object Cache.

## Project Context

Mincemeat Object Cache is a WordPress Redis/Valkey object-cache drop-in with a companion plugin. It targets WordPress 6.9+, PHP 7.4 through PHP 8.5, Redis 8, and Valkey 9.

The project is public-release oriented. Favor correctness, conservative compatibility, redacted diagnostics, deterministic generated artifacts, and WordPress contract fidelity over cleverness.

## Read First

Before changing behavior, read:

- `docs/DESIGN.md`
- `docs/IMPLEMENTATION.md`
- `docs/PRODUCTION_READINESS_REMEDIATION_PLAN.md`
- `README.md`
- `composer.json`

Note: `/docs/` is currently ignored by `.gitignore`. If documentation changes are meant to be committed, force-add them or change the ignore policy deliberately.

## Hard Rules

- Preserve PHP 7.4 syntax in runtime source and generated drop-in.
- Do not hand-edit `stubs/object-cache.php` or `stubs/object-cache.php.sha256`.
- Edit source under `src/`, then regenerate the drop-in with `php tools/build-dropin.php`.
- Keep WordPress global cache facade signatures compatible with WordPress.
- Do not add test-name-specific branches to runtime code.
- Do not expose Redis credentials, TLS details, internal hostnames, or socket paths in diagnostics unless explicitly redacted.
- Do not use broad Redis/Valkey keyspace operations such as `KEYS`, `SCAN`, `FLUSHDB`, or `FLUSHALL` in normal runtime behavior.
- Do not store Redis credentials in WordPress options or add an admin credential settings screen.
- Do not introduce runtime dependencies that are unavailable before normal plugins load.

## Generated Artifacts

Generated artifacts are part of the release trust boundary:

- `stubs/object-cache.php`
- `stubs/object-cache.php.sha256`
- `manifest.json`
- `mincemeat-object-cache.zip`
- `mincemeat-object-cache.zip.sha256`

The drop-in artifacts should be regenerated after runtime source changes. Package artifacts currently need determinism remediation before they can be trusted as reproducible outputs.

## Validation

Use the smallest useful check first, then broaden before finishing release-impacting work.

Recommended local checks:

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

Package validation is not a release gate until package determinism is fixed:

```bash
php tools/build-package.php
```

For full release confidence, use CI for the PHP, Redis/Valkey, multisite, TLS, ACL, and Unix socket matrix.

## Runtime Design Boundaries

Keep Redis/Valkey operations inside the adapter layer. The object-cache layer should preserve WordPress semantics:

- falsey values are valid cached values
- `$found` must distinguish misses from cached falsey values
- global groups are network-wide
- non-global groups are blog-scoped on multisite
- non-persistent groups are request-local
- group flush should invalidate by generation token rotation, not broad key deletion

## Documentation Expectations

When behavior, support policy, release process, or architecture changes, update the docs in the same change. Keep docs concrete and current:

- what is true now
- what command verifies it
- what remains blocked
- which files own the behavior

## Current Release Blockers

As of 2026-07-13:

- Remove the `wp_cache_flush_group()` test-specific runtime branch.
- Fix package determinism or stop committing package artifacts.
- Expand artifact parity CI beyond `stubs/object-cache.php`.
- Clean test tooling around serialized config input and generated local credentials.

See `docs/PRODUCTION_READINESS_REMEDIATION_PLAN.md` for the full plan.
