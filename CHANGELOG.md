# Changelog

## [Unreleased]

## [0.1.0-rc4] - 2026-08-08

- Extracted the request-local memory tier into a dedicated `MemoryTier` collaborator owned by `ObjectCache`, delegating falsey-safe reads, object-cloning writes, per-key/group removal, and request-tier clearing. The public `ObjectCache` surface is unchanged and all tests remain green.
- Moved the test-only `ValueCodec::header_inline()` fixture builder into the test support layer (`ValueEnvelopeBuilder`), so the production drop-in exposes only the public value-codec encode/decode surface.
- Added a topology contract test asserting the `Api` diagnostics and Site Health paths share the single `Topology` classifier for standalone, cluster, sentinel, replica, and incomplete identities.
- Documented the intentional `try { adapter } catch { degrade + return-filled }` boilerplate decision and the canonical persistent-connection identity subset used by `PhpRedisAdapter::persistent_id()`.
- Wired the multisite `switch_blog` action so blog scope flips automatically whenever WordPress switches or restores the current blog; global groups survive the switch while non-global groups are scoped to the current blog.
- Added a `wp_cache_supports()` parity regression confirming the six advertised features (`add_multiple`, `set_multiple`, `get_multiple`, `delete_multiple`, `flush_runtime`, `flush_group`) are the only ones reported.
- Added contract coverage for `get_multiple()` `$force` semantics (bypasses request memory, reads the backend, repopulates memory), suspended `add_multiple()` / `wp_cache_add()` behavior, and the `WP_Object_Cache` alias guard so a co-resident cache is never clobbered.
- Broadened third-party interop fixtures with a page-builder admin/post-save path and a caching-adjacent `get_multiple()` / `set_multiple()`-heavy fixture, asserting no PHP diagnostics and populated hit/miss counters.
- Re-verified the numeric contract matrix with no drift after the Phase 1 hot-path changes.
- Memoized `KeySpace::group_digest()` per request so repeated key derivation hashes each group once, and cleaned up `item_key()` string assembly (`implode`) without changing the wire format.
- Replaced the double request-memory array probe (`exists()` + index) on the read path with a single falsey-safe `memory_read()` probe across `get()`, `get_multiple()`, and the persistent read paths, preserving hit/miss accounting and cached `false` / `0` / `''` / `null` semantics.
- Added an opt-out `measure_performance` config key (default `true`) that skips `microtime( true )` capture around backend commands while still counting `backend_calls`.
- Added request-memory-focused before/after benchmark evidence and re-ran the controlled RC3→RC4 comparison (no command-count regression; request-memory hit latency improved).
- Hardened memory safety: the value-envelope decoder now validates the declared payload length against the actual remaining bytes before slicing or allocating, and unit tests prove hostile/over/under-declared length fields are rejected as corrupt and that `unserialize` failures are contained with no warnings.
- Added object-aliasing isolation tests (contract + persistent integration) proving mutating a returned object never changes the cached copy across `set()`+`get()`, `persistent_get`, and `get_multiple()`.
- Documented the request-tier growth policy (request-scoped, unbounded by design, freed at request end, eviction deferred to v1) and surfaced the live request-tier entry count as an on-demand Site Health / diagnostics field.
- Added close-path correctness tests for runtime-only and degraded (circuit-open) states, and archived the RC3→RC4 request-memory before/after evidence (~99 bytes/entry, no regression).
- Registered the immutable `0.1.0-rc3` drop-in checksum in the lifecycle ownership registry and updated the packaged lifecycle upgrade/rollback E2E testing for RC3 → RC4.

## [0.1.0-rc3] - 2026-08-07

- Updated WordPress release matrix to WordPress 6.9.5 and 7.0.3 (security patch for CVE-2026-60137 and CVE-2026-63030), and added scheduled monitoring for WordPress 7.1 RC.
- Updated third-party compatibility smoke fixtures to WooCommerce 11.0.0, Yoast SEO 28.2, Easy Digital Downloads 3.6.9, and added Query Monitor 4.0.7 interoperability verification.
- Updated controlled benchmark backend image to Redis 8.10.0-alpine and validated backend compatibility on Valkey 9.
- Registered the immutable `0.1.0-rc2` drop-in checksum in the lifecycle ownership registry and updated packaged lifecycle upgrade/rollback E2E testing for RC2 → RC3.
- Relaxed `$force` parameter type declaration on `get()` and `get_multiple()` to match WordPress core permissive contract, verified `$found` disambiguation for cached `false` vs miss, and added multisite blog-scoping tests.
- Optimized persistent cache read allocations by returning freshly decoded value objects directly instead of performing a double object clone.
- Unified server topology classification into a single `Topology` helper shared across `Api` and Site Health diagnostics, and centralized `SCHEMA_MARKER` in `KeySpace`.
- Pruned dead test-only helper methods (`del_multiple`, `pttl`, `cached_server_info`, `supports_unlink`, `group_tokens`), collapsed pipeline SET execution, and eliminated reflection-based `new Redis()` calls in diagnostics.
- Updated release documentation, controlled performance evidence job names and artifact labels, and release tooling for 0.1.0-rc3.


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
