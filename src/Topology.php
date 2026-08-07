<?php
/**
 * Server topology classification for Mincemeat Object Cache.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Classifies Redis / Valkey topology identity.
 */
final class Topology {

	public const COMPATIBLE  = 'compatible';
	public const UNSUPPORTED = 'unsupported';
	public const UNVERIFIED  = 'unverified';

	/**
	 * Classifies mode and role from sanitized server identity.
	 *
	 * @param array<string,string>|null $server_info Sanitized server identity.
	 * @return array{topology_status:string,topology_mode:string,topology_role:string}
	 */
	public static function classify( ?array $server_info ): array {
		$mode = $server_info !== null ? strtolower( trim( (string) ( $server_info['mode'] ?? '' ) ) ) : '';
		$role = $server_info !== null ? strtolower( trim( (string) ( $server_info['role'] ?? '' ) ) ) : '';

		if ( ! in_array( $mode, array( 'standalone', 'cluster', 'sentinel' ), true ) ) {
			$mode = 'unknown';
		}

		if ( in_array( $role, array( 'master', 'primary' ), true ) ) {
			$role = 'primary';
		} elseif ( in_array( $role, array( 'slave', 'replica' ), true ) ) {
			$role = 'replica';
		} elseif ( $role !== 'sentinel' ) {
			$role = 'unknown';
		}

		$status = self::UNVERIFIED;
		if ( in_array( $mode, array( 'cluster', 'sentinel' ), true ) || in_array( $role, array( 'replica', 'sentinel' ), true ) ) {
			$status = self::UNSUPPORTED;
		} elseif ( $mode === 'standalone' && $role === 'primary' ) {
			$status = self::COMPATIBLE;
		}

		return array(
			'topology_status' => $status,
			'topology_mode'   => $mode,
			'topology_role'   => $role,
		);
	}
}
