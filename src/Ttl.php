<?php
/**
 * TTL normalization and capping.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Pure TTL resolution per DESIGN 3.4.
 */
final class Ttl {

	/** The Redis no-expiry sentinel (PTTL = -1). */
	public const NO_EXPIRY_MS = -1;

	/** The Redis missing-key sentinel (PTTL = -2). */
	public const MISSING_MS = -2;

	private function __construct() {
	}

	/**
	 * Resolves the effective TTL in seconds for a cache write.
	 *
	 * Semantics:
	 * - Caller expiry <= 0 means "no caller-requested expiry"; the configured
	 *   max_ttl still applies when nonzero.
	 * - A positive caller expiry is capped by max_ttl when max_ttl is nonzero.
	 * - A return value of 0 means "no expiry" (store without TTL).
	 *
	 * @param int $caller_expire Caller-supplied expiry in seconds.
	 * @param int $max_ttl        Configured maximum TTL in seconds (0 = unbounded).
	 * @return int Effective TTL seconds; 0 means "no expiry".
	 */
	public static function resolve( int $caller_expire, int $max_ttl): int {
		$caller = $caller_expire < 0 ? 0 : $caller_expire;

		if ($caller <= 0) {
			return $max_ttl > 0 ? $max_ttl : 0;
		}

		if ($max_ttl > 0 && $caller > $max_ttl) {
			return $max_ttl;
		}

		return $caller;
	}

	/**
	 * Normalizes a PTTL response from the backend to a remaining TTL value.
	 *
	 * @param int $pttl_ms PTTL in milliseconds, or NO_EXPIRY_MS / MISSING_MS.
	 * @return int|null TTL in milliseconds; null for no-expiry; the MISSING
	 *                  sentinel is normalized to null (caller treats as absent).
	 */
	public static function remaining_from_pttl( int $pttl_ms): ?int {
		if ($pttl_ms === self::NO_EXPIRY_MS || $pttl_ms === self::MISSING_MS) {
			return null;
		}

		return $pttl_ms < 0 ? null : $pttl_ms;
	}

	/**
	 * Converts the resolved TTL seconds to milliseconds for backend commands.
	 *
	 * @param int $ttl_seconds Resolved TTL; 0 means no expiry.
	 * @return int|null TTL in ms, or null for no expiry.
	 */
	public static function to_ms( int $ttl_seconds): ?int {
		return $ttl_seconds > 0 ? $ttl_seconds * 1000 : null;
	}
}
