# Changelog

## [Unreleased]

- Added isolated WordPress browser/WP-CLI E2E coverage for lifecycle, Site Health, outage recovery, ownership safety, and multisite.
- Registered the documented hyphenated WP-CLI lifecycle subcommands explicitly.

## [1.0.0-rc1] - 2026-07-13

- Replaced serialized test configuration injection with validated JSON decoding.
- Cleaned up local credential handling, certificate storage, and hardened cert permissions.
- Expanded CI coverage and verification matrix to PHP 7.4 through 8.5.
- Fixed stray root credential file (`mypassword`) and moved TLS material to ignore paths.
- Updated version metadata to `1.0.0-rc1`.

All notable changes are documented here. The project follows Semantic Versioning.
