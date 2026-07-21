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
composer benchmark:controlled -- 127.0.0.1 6383
php tools/build-dropin.php
git diff --exit-code stubs/object-cache.php stubs/object-cache.php.sha256
php tools/build-package.php
```

The release matrix must include the maintained minimum WordPress patch and the
current stable WordPress patch in single-site and multisite modes. A scheduled
trunk job is an allowed-failure early warning and does not replace those gates.
The packaged lifecycle gate requires the immutable previous release tag and
must pass upgrade, recovery, rollback, deactivation, and foreign-drop-in
preservation before publishing a candidate. Its mixed-version phase must remain
free of PHP diagnostics while the new companion plugin manages the older
drop-in.

The controlled performance job must upload the immutable-RC1 report, two clean
candidate reports, a passing repeatability comparison, and a passing RC1-to-RC2
comparison. Review the recorded commit, runner/CPU, PHP INI/extensions, backend
image digest, warmup/sample policy, raw samples, and deterministic command counts
before quoting a performance result or approving a candidate.

Full release confidence comes from CI across supported PHP versions, Redis 8, Valkey 9, single site, multisite, TCP, TLS, ACL, Unix socket, backend outages, browser flows, and WP-CLI lifecycle checks.

RC2 and v1 release notes must describe the supported topology as one direct
standalone writable primary. Do not imply support for Cluster, Sentinel,
replica reads, direct replicas, multi-primary routing, or managed proxies until
those modes have dedicated designs and release matrices. Release validation
must confirm that Site Health reports a standalone primary as compatible,
Cluster/Sentinel/replica identities as unsupported, and incomplete identities
as unverified. It must also record requested versus effective PhpRedis
persistent reuse and retain the documented ambiguous-write caveat.

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
