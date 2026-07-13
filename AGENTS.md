# AGENTS.md

Instructions for AI coding agents and automation working on Mincemeat Object Cache.

## Context

Mincemeat Object Cache is a WordPress Redis/Valkey object-cache drop-in with a companion plugin. It targets WordPress 6.9+, PHP 7.4 through PHP 8.5, PhpRedis 6.3.0+, Redis 8, and Valkey 9.

The project is public-release oriented. Favor WordPress contract fidelity, conservative compatibility, redacted diagnostics, deterministic artifacts, and clear documentation.

## Read First

Before changing runtime behavior, read:

- `README.md`
- `docs/DESIGN.md`
- `docs/IMPLEMENTATION.md`
- `docs/RELEASE.md`
- `composer.json`

Update docs in the same change when behavior, support policy, release process, or architecture changes.

## Hard Rules

- Preserve PHP 7.4 syntax in runtime source and generated drop-in.
- Do not hand-edit `stubs/object-cache.php` or `stubs/object-cache.php.sha256`.
- Edit source under `src/`, then regenerate with `php tools/build-dropin.php`.
- Keep WordPress global `wp_cache_*` facade signatures compatible with WordPress.
- Do not add test-name-specific branches to runtime code.
- Do not expose Redis credentials, TLS details, internal hostnames, or socket paths in diagnostics unless explicitly redacted.
- Do not use broad Redis/Valkey keyspace operations such as `KEYS`, `SCAN`, `FLUSHDB`, or `FLUSHALL` in normal runtime behavior.
- Do not store Redis credentials in WordPress options or add an admin credential settings screen.
- Do not introduce runtime dependencies unavailable before normal plugins load.

## Generated Artifacts

Generated release-trust artifacts:

- `stubs/object-cache.php`
- `stubs/object-cache.php.sha256`
- `manifest.json`
- `mincemeat-object-cache.zip`
- `mincemeat-object-cache.zip.sha256`

The drop-in artifacts are tracked and must be regenerated after runtime source changes. Package ZIP, checksum, and manifest files are release build outputs and are intentionally ignored in normal development.

## Validation

Use the smallest useful check first, then broaden before release-impacting work is finished:

```bash
composer validate --strict --no-check-lock
composer lint -- --report=summary
composer stan -- --error-format=raw
composer test
composer test:coverage
composer test:e2e
composer benchmark -- 127.0.0.1 6383 --compare
```

Drop-in parity:

```bash
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
```

Package validation:

```bash
php tools/build-package.php
```

Use CI for full PHP, Redis/Valkey, multisite, TLS, ACL, Unix socket, browser, and WP-CLI confidence.

## Runtime Boundaries

- Redis/Valkey operations belong inside the adapter layer.
- Falsey values are valid cached values.
- `$found` must distinguish misses from cached falsey values.
- Global groups are network-wide; non-global groups are blog-scoped on multisite.
- Non-persistent groups are request-local.
- Group flush invalidates by generation token rotation, not broad key deletion.
