# Mincemeat Object Cache - Remediation Plan

Date: 2026-07-13
Recommendation: no public production tag until P0 items are closed.

## Release Readiness Summary

The project is significantly more mature than the earlier review baseline. Most structural concerns have been addressed: runtime source exists, a generated drop-in exists, CI is broader, static analysis is stricter, integration coverage is stronger, and diagnostics are safer.

The remaining blockers are concentrated in release trust and runtime cleanliness rather than broad missing architecture.

## Closed Since Earlier Review

These older blockers appear resolved or materially improved:

- Source classes now exist under `src/`.
- Generated drop-in exists under `stubs/object-cache.php`.
- Drop-in checksum sidecar exists.
- Companion plugin lifecycle and Site Health responsibilities are present.
- PHPStan is configured at a useful level.
- PHPCS passes locally.
- PHPUnit has substantial unit, contract, compatibility, lifecycle, integration, and smoke coverage.
- CI includes PHP 8.5.
- CI includes Redis/Valkey integration coverage.
- Modern cache helper support exists for multiple operations, runtime flush, group flush, and close.
- Site Health redaction has improved.
- Package tooling exists.

## P0 Remediation

### P0-1: Remove test-specific runtime behavior

Owner: runtime maintainer
Files:

- `src/functions.php`
- `stubs/object-cache.php` after regeneration
- related tests under `tests/Contract` or compatibility suites

Problem:

`wp_cache_flush_group()` checks `debug_backtrace()` and special-cases callers named `test_wp_cache_flush_group`.

Required changes:

1. Remove backtrace inspection from `wp_cache_flush_group()`.
2. Always delegate supported group flush calls to `$wp_object_cache->flush_group($group)`.
3. Keep `wp_cache_supports('flush_group')` true only if the feature is truly supported.
4. Adjust tests so WordPress core fallback expectations are not encoded into production runtime.
5. Regenerate the drop-in.

Acceptance criteria:

- No `debug_backtrace()` call exists in `src/functions.php` facade code for test detection.
- No test method names appear in runtime source or generated drop-in.
- Facade contract tests pass.
- A direct smoke check confirms `wp_cache_supports('flush_group') === true` and `wp_cache_flush_group()` invalidates only the requested group.

### P0-2: Make package builds deterministic or stop committing package artifacts

Owner: release engineering
Files:

- `tools/build-package.php`
- `manifest.json`
- `mincemeat-object-cache.zip`
- `mincemeat-object-cache.zip.sha256`
- `.github/workflows/ci.yml`
- `.gitignore` if artifacts become release-only

Problem:

Repeated `php tools/build-package.php` runs produce different ZIP hashes. Current committed package artifacts cannot be trusted as reproducible source outputs.

Decision required:

Choose one policy:

- Policy A: commit package artifacts and prove they are deterministic.
- Policy B: do not commit package artifacts; publish them only from release CI.

Policy A required changes:

1. Normalize ZIP entry timestamps to a fixed release timestamp or `SOURCE_DATE_EPOCH`.
2. Normalize file permissions and external attributes.
3. Keep file ordering stable.
4. Ensure line-ending normalization is intentional and reflected in manifest hashes.
5. Build twice and compare ZIP hashes in CI.
6. Verify `manifest.json` and `.sha256` sidecars are current.

Policy B required changes:

1. Add package files to `.gitignore`.
2. Remove committed ZIP/checksum/manifest artifacts.
3. Publish package artifacts through CI release workflow.
4. Keep a source manifest or build recipe in the repository.

Acceptance criteria:

- Two clean builds of the same source produce identical ZIP hashes if Policy A is chosen.
- CI fails when generated release artifacts are stale.
- Release documentation states the artifact policy clearly.

## P1 Remediation

### P1-1: Expand artifact parity CI

Owner: CI maintainer
Files:

- `.github/workflows/ci.yml`
- `tools/build-dropin.php`
- `tools/build-package.php`

Required changes:

1. After `php tools/build-dropin.php`, verify both `stubs/object-cache.php` and `stubs/object-cache.php.sha256`.
2. After `php tools/build-package.php`, verify package artifacts according to the chosen artifact policy.
3. Add a ZIP file allowlist check.
4. Add a repeated-build determinism check if ZIP artifacts are committed or if releases rely on reproducible builds.

Acceptance criteria:

- CI catches stale generated drop-in checksum.
- CI catches stale package manifest/checksum.
- CI catches unexpected files in the package.

### P1-2: Replace serialized test config injection

Owner: test tooling maintainer
Files:

- `tools/install-wp-tests.sh`
- related test bootstrap files

Problem:

The script decodes `MINCEMEAT_OBJECT_CACHE_CONFIG` through PHP `unserialize()`.

Required changes:

1. Replace serialized input with JSON.
2. Decode with `json_decode(..., true)`.
3. Validate that decoded config is an array.
4. Validate allowed keys and scalar/array types.
5. Update CI env construction and docs.

Acceptance criteria:

- No test setup script calls `unserialize()` on environment input.
- CI and local test setup still work.

### P1-3: Clean local service credential and certificate handling

Owner: test tooling maintainer
Files:

- `tools/setup-test-services.sh`
- `tests/certs/`
- root-level stray files
- `.gitignore`

Problem:

Local service setup writes test certs into repository paths and a root-level `mypassword` file exists.

Required changes:

1. Move generated TLS material to an ignored build or temp directory.
2. Use restrictive permissions compatible with the container setup.
3. Remove stray root credential files.
4. Document local-only credentials.
5. Add a simple secret-scan check for known accidental files.

Acceptance criteria:

- Running local service setup does not create tracked or suspicious root-level files.
- Test certs are clearly local-only or regenerated as needed.
- CI does not depend on committed private keys unless they are documented as test fixtures.

### P1-4: Align public release metadata

Owner: release manager
Files:

- `mincemeat-object-cache.php`
- `readme.txt`
- `README.md`
- `CHANGELOG.md`
- package manifest

Required changes:

1. Replace `1.0.0-dev` with the release candidate or final release version.
2. Align stable tag and plugin header version.
3. Confirm WordPress 6.9+ requirement.
4. Confirm PHP support language: PHP 7.4 through 8.5 tested.
5. Mention that PHP 8.5 support also depends on the wider WordPress installation stack.
6. Replace any absolute local file links with relative links.

Acceptance criteria:

- Public metadata contains no development-only labels for a stable tag.
- README links are portable.
- Release notes state tested versions accurately.

### P1-5: Prove full integration matrix before release

Owner: CI maintainer
Files:

- `.github/workflows/ci.yml`
- Docker/test helper files

Required changes:

1. Run PHP 7.4 through 8.5.
2. Run Redis 8 and Valkey 9.
3. Run single site and multisite.
4. Run TCP, Unix socket, ACL, and TLS scenarios.
5. Persist clear CI artifacts for failed connection scenarios.

Acceptance criteria:

- Release candidate branch has a green full matrix.
- Failed matrix cells expose enough diagnostics without secrets.

## P2 Remediation

### P2-1: Clarify future PHP support policy

Owner: maintainer
Files:

- `composer.json`
- `README.md`
- `readme.txt`
- docs

Options:

- Keep `php >=7.4` and document that official support is limited to CI-tested PHP versions.
- Add a Composer conflict for future PHP majors/minors until tested.

Acceptance criteria:

- Users can tell which PHP versions are tested and supported.
- Composer policy does not imply untested future compatibility without explanation.

### P2-2: Add hot-path performance guardrails

Owner: runtime maintainer
Files:

- tests or benchmarks to be added

Recommended checks:

- request-memory hit path
- backend hit path
- backend miss path
- multiple-get path
- group flush token rotation
- failed backend connection path

Acceptance criteria:

- A repeatable local benchmark exists.
- The benchmark can compare changes before/after hot-path edits.

### P2-3: Add stronger fault-injection tests

Owner: test maintainer
Files:

- integration tests
- service helpers

Scenarios:

- Redis disconnect during `get`
- Redis disconnect during `set`
- Redis disconnect during numeric Lua operation
- Redis disconnect during group generation token rotation
- TLS certificate mismatch
- ACL authentication failure

Acceptance criteria:

- WordPress request flow remains available.
- Site Health reports degraded state without secrets.
- Metrics capture meaningful failure counts.

### P2-4: Runtime cleanup

Owner: runtime maintainer
Files:

- `src/ObjectCache.php`
- related tests

Recommended cleanup:

- Remove no-op assignments.
- Review state transitions for clarity.
- Tighten PHPDoc where PHPStan can infer more precisely.
- Keep any cleanup separate from release-blocking behavior fixes.

Acceptance criteria:

- No behavior change unless tests explicitly cover it.
- Static analysis and tests remain green.

## Release Gate Checklist

A public production release can be considered when all of these are true:

- P0-1 closed.
- P0-2 closed.
- Artifact parity CI verifies the selected artifact policy.
- Full CI matrix is green.
- README/readme/plugin headers are release-ready.
- Site Health redaction tests pass.
- Generated drop-in checksum is current.
- Package checksum and manifest are current if committed.
- No root-level secret-like files remain.
- Docs are committed intentionally, either by unignoring `/docs/` or force-adding them.

## Suggested Execution Order

1. Fix `wp_cache_flush_group()` runtime branch and tests.
2. Fix or redesign package artifact policy.
3. Expand CI artifact parity.
4. Clean test tooling config and certificate handling.
5. Align public release metadata.
6. Run full CI.
7. Cut a release candidate.
