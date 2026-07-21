# Changelog

## [Unreleased]

- Replaced lifecycle test-fixture hashes with the immutable `0.1.0-rc1`
  drop-in checksum, and require an exact release checksum before replacing or
  removing an older drop-in.
- Replaced silent WordPress cache-test rewriting with a reviewed,
  checksum-gated patch and a separate provenance verification command.
- Expanded release validation to WordPress 6.9.5 and 7.0.2 across core/query
  contract and packaged browser/WP-CLI E2E coverage, with a scheduled trunk
  compatibility signal.

## [0.1.0-rc1] - 2026-07-13

- Initial release.

This project uses ZeroVer while the public API and operational behavior settle.
