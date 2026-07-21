# Mincemeat Object Cache - Release Guide

Audience: maintainers, release engineers, and AI coding agents.

## Versioning

Mincemeat Object Cache uses ZeroVer while the public API and operational behavior settle. Public testing releases use WordPress-compatible version strings such as `0.1.0-rc1`.

For a release candidate:

1. Update the plugin header in `mincemeat-object-cache.php`.
2. Update `readme.txt` stable tag and changelog section.
3. Update `README.md` status text.
4. Update `src/Api.php` implementation version.
5. Before regeneration changes the drop-in, record the previous immutable
   release's tracked `stubs/object-cache.php.sha256` in
   `Lifecycle::RELEASE_DROPIN_HASHES`. Never add development builds or test
   fixtures to this ownership registry.
6. Regenerate the drop-in with `php tools/build-dropin.php`.

## Public Changelog Policy

Keep public release notes short and user-centered. The first public baseline is:

```text
Initial release.
```

Do not include private remediation history, local credential details, internal hostnames, or implementation incident notes in public changelog entries.

## Required Checks

Run local checks before publishing:

```bash
composer validate --strict --no-check-lock
composer lint -- --report=summary
composer stan -- --error-format=raw
composer test
composer test:smoke
composer test:coverage
composer test:e2e
composer test:e2e:lifecycle
composer benchmark -- 127.0.0.1 6383 --compare
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
php tools/build-package.php
```

The release matrix must include the maintained minimum WordPress patch and the
current stable WordPress patch in single-site and multisite modes. A scheduled
trunk job is an allowed-failure early warning and does not replace those gates.
The packaged lifecycle gate requires the immutable previous release tag and
must pass upgrade, recovery, rollback, deactivation, and foreign-drop-in
preservation before publishing a candidate.

Full release confidence comes from CI across supported PHP versions, Redis 8, Valkey 9, single site, multisite, TCP, TLS, ACL, Unix socket, backend outages, browser flows, and WP-CLI lifecycle checks.

## Package Artifacts

`php tools/build-package.php` produces:

- `mincemeat-object-cache.zip`
- `mincemeat-object-cache.zip.sha256`
- `manifest.json`

The package build is deterministic. Rebuilding from the same source should produce the same ZIP checksum and manifest content.

## GitHub Release

After all checks pass and the release commit is pushed:

```bash
git tag 0.1.0-rc1
git push origin 0.1.0-rc1
gh release create 0.1.0-rc1 \
  mincemeat-object-cache.zip \
  mincemeat-object-cache.zip.sha256 \
  manifest.json \
  --title "Mincemeat Object Cache 0.1.0-rc1" \
  --notes "Initial release." \
  --prerelease
```

Repository release immutability should remain enabled so published artifacts cannot be replaced.
