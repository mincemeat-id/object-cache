<?php
/**
 * WP-CLI commands for Mincemeat Object Cache.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use WP_CLI;

/**
 * Manage the Mincemeat Object Cache drop-in lifecycle and check status.
 */
final class CliCommand {

	/**
	 * Displays the object cache status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mincemeat-cache status
	 *
	 * @param array<int,string>     $args       Command arguments.
	 * @param array<string,string>  $assoc_args Command associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$state       = Lifecycle::get_dropin_state();
		$diagnostics = Api::diagnostics();

		WP_CLI::line( 'Drop-in Status: ' . $state );
		WP_CLI::line( 'Cache Status:   ' . $diagnostics['state'] );
		WP_CLI::line( 'Reason:         ' . $diagnostics['reason'] );
		WP_CLI::line( 'Topology:       ' . $diagnostics['topology_status'] . ' (' . $diagnostics['topology_mode'] . '/' . $diagnostics['topology_role'] . ')' );
		WP_CLI::line( 'Connection Reuse: ' . $diagnostics['connection_reuse'] );

		if ( isset( $diagnostics['scheme'] ) ) {
			WP_CLI::line( 'Scheme:         ' . $diagnostics['scheme'] );
			WP_CLI::line( 'Host:           ' . ( $diagnostics['host'] ?? '' ) );
			WP_CLI::line( 'Port:           ' . ( $diagnostics['port'] ?? '' ) );
			WP_CLI::line( 'Database:       ' . ( $diagnostics['database'] ?? '' ) );
			WP_CLI::line( 'Namespace:      ' . ( $diagnostics['namespace_digest'] ?? '' ) );
		}
	}

	/**
	 * Installs the Mincemeat Object Cache drop-in.
	 *
	 * Refuses to overwrite a foreign drop-in.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mincemeat-cache install-dropin
	 *
	 * @subcommand install-dropin
	 *
	 * @param array<int,string>     $args       Command arguments.
	 * @param array<string,string>  $assoc_args Command associative arguments.
	 */
	public function install_dropin( array $args, array $assoc_args ): void {
		$state = Lifecycle::get_dropin_state();

		if ( $state === Lifecycle::STATE_OWNED_CURRENT ) {
			WP_CLI::success( 'Drop-in is already installed and up to date.' );
			return;
		}

		if ( $state === Lifecycle::STATE_FOREIGN ) {
			WP_CLI::error( 'A foreign object-cache.php drop-in is present. Overwriting foreign drop-ins is refused.' );
			return;
		}

		$success = Lifecycle::install_dropin();
		if ( $success ) {
			WP_CLI::success( 'Mincemeat Object Cache drop-in installed successfully.' );
		} else {
			WP_CLI::error( 'Failed to install Mincemeat Object Cache drop-in. Please check filesystem permissions.' );
		}
	}

	/**
	 * Updates the Mincemeat Object Cache drop-in.
	 *
	 * Refuses to overwrite a foreign drop-in.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mincemeat-cache update-dropin
	 *
	 * @subcommand update-dropin
	 *
	 * @param array<int,string>     $args       Command arguments.
	 * @param array<string,string>  $assoc_args Command associative arguments.
	 */
	public function update_dropin( array $args, array $assoc_args ): void {
		$state = Lifecycle::get_dropin_state();

		if ( $state === Lifecycle::STATE_OWNED_CURRENT ) {
			WP_CLI::success( 'Drop-in is already up to date.' );
			return;
		}

		if ( $state === Lifecycle::STATE_FOREIGN ) {
			WP_CLI::error( 'A foreign object-cache.php drop-in is present. Updating foreign drop-ins is refused.' );
			return;
		}

		if ( $state === Lifecycle::STATE_ABSENT ) {
			WP_CLI::error( 'Drop-in is not installed. Use "install-dropin" instead.' );
			return;
		}

		$success = Lifecycle::install_dropin();
		if ( $success ) {
			WP_CLI::success( 'Mincemeat Object Cache drop-in updated successfully.' );
		} else {
			WP_CLI::error( 'Failed to update Mincemeat Object Cache drop-in.' );
		}
	}

	/**
	 * Removes the Mincemeat Object Cache drop-in.
	 *
	 * Refuses to remove a foreign drop-in.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mincemeat-cache remove-dropin
	 *
	 * @subcommand remove-dropin
	 *
	 * @param array<int,string>     $args       Command arguments.
	 * @param array<string,string>  $assoc_args Command associative arguments.
	 */
	public function remove_dropin( array $args, array $assoc_args ): void {
		$state = Lifecycle::get_dropin_state();

		if ( $state === Lifecycle::STATE_ABSENT ) {
			WP_CLI::success( 'No drop-in found to remove.' );
			return;
		}

		if ( $state === Lifecycle::STATE_FOREIGN ) {
			WP_CLI::error( 'A foreign object-cache.php drop-in is present. Removing foreign drop-ins is refused.' );
			return;
		}

		$success = Lifecycle::remove_dropin();
		if ( $success ) {
			WP_CLI::success( 'Mincemeat Object Cache drop-in removed successfully.' );
		} else {
			WP_CLI::error( 'Failed to remove Mincemeat Object Cache drop-in.' );
		}
	}
}
