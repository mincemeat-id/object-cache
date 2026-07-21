# Changelog

## [Unreleased]

- No changes yet.

## [0.1.0-rc2] - 2026-07-22

- Replaced lifecycle test-fixture hashes with the immutable `0.1.0-rc1`
  drop-in checksum, and require an exact release checksum before replacing or
  removing an older drop-in.
- Replaced silent WordPress cache-test rewriting with a reviewed,
  checksum-gated patch and a separate provenance verification command.
- Expanded release validation to WordPress 6.9.5 and 7.0.2 across core/query
  contract and packaged browser/WP-CLI E2E coverage, with a scheduled trunk
  compatibility signal.
- Unified numeric coercion and saturation across request memory, Redis, and
  Valkey; boolean `true` now follows WordPress normalization instead of being
  counted as one before incrementing.
- Added a packaged RC1-to-current lifecycle gate covering atomic upgrade,
  failed-update recovery, deliberate rollback, deactivation, and foreign
  drop-in preservation, including warning-free companion compatibility with
  the older drop-in's diagnostics schema.
- Reduced cold persistent-cache work by lazily loading diagnostics and numeric
  scripts and coalescing namespace/group generation-token resolution, with
  deterministic command and round-trip guardrails for cold requests.
- Made compatibility smoke tests fail on missing or mismatched plugins,
  unexpected PHP diagnostics, and post-install database errors; updated the exact
  WooCommerce, Yoast SEO, and Easy Digital Downloads fixtures and report them
  in bounded machine-readable output.
- Hardened configuration and failure diagnostics around stable reason codes,
  flat scalar TLS options, endpoint classification, partial-client cleanup,
  adversarial secret/key/value inputs, and bounded opt-in error logging.
- Defined the v1 standalone-primary and best-effort consistency boundary, with
  Site Health/WP-CLI topology classification and requested-versus-effective
  PhpRedis persistent connection reuse diagnostics.
- Replaced the ignored local performance baseline with controlled immutable-RC1
  and two-run candidate evidence, including reproducibility metadata, raw
  samples, deterministic network-work gates, dual-threshold runtime latency
  checks, and non-gating raw-backend controls for interpreting runner noise.

## [0.1.0-rc1] - 2026-07-13

- Initial release.

This project uses ZeroVer while the public API and operational behavior settle.
