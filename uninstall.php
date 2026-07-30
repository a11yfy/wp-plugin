<?php
/**
 * Uninstall (§14/17): remediated files stay by default (they are the user's
 * content); the admin can opt into restoring originals in Settings.
 * All plugin state (tables, options, post meta) is removed either way.
 * On multisite the cleanup runs for every site — the tables, options and
 * backup directories are per-site.
 *
 * @package a11yfy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin state for the current site.
 */
function a11yfy_uninstall_current_site() {
	global $wpdb;

	$a11yfy_settings = get_option( 'a11yfy_settings', array() );
	$a11yfy_restore  = is_array( $a11yfy_settings ) && isset( $a11yfy_settings['delete_data'] ) && 'restore' === $a11yfy_settings['delete_data'];

	$a11yfy_map_table = $wpdb->prefix . 'a11yfy_map';

	// Optional: restore originals from backup before dropping state.
	if ( $a11yfy_restore ) {
		$a11yfy_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s', $a11yfy_map_table, 'active' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( is_array( $a11yfy_rows ) ) {
			foreach ( $a11yfy_rows as $a11yfy_row ) {
				if ( 'inplace' === $a11yfy_row['mode'] && ! empty( $a11yfy_row['backup_path'] ) && file_exists( $a11yfy_row['backup_path'] ) ) {
					$a11yfy_target = get_attached_file( (int) $a11yfy_row['attachment_id'] );
					if ( $a11yfy_target ) {
						@copy( $a11yfy_row['backup_path'], $a11yfy_target );
					}
				} elseif ( 'conservative' === $a11yfy_row['mode'] && ! empty( $a11yfy_row['remediated_path'] ) && file_exists( $a11yfy_row['remediated_path'] ) ) {
					wp_delete_file( $a11yfy_row['remediated_path'] );
				}
			}
		}
	}

	// Drop tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}a11yfy_jobs" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}a11yfy_map" );  // phpcs:ignore WordPress.DB

	// Options + transients.
	delete_option( 'a11yfy_settings' );
	delete_option( 'a11yfy_api_key' );
	delete_option( 'a11yfy_webhook_secret' );
	delete_option( 'a11yfy_schema_version' );
	delete_option( 'a11yfy_monthly_spend' );
	delete_option( 'a11yfy_low_credit_notified' );
	// Per-period spend counters (a11yfy_monthly_spend_YYYY-MM).
	$wpdb->query( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'a11yfy_monthly_spend_' ) . '%'
		)
	);
	delete_transient( 'a11yfy_balance' );
	delete_transient( 'a11yfy_low_credit' );
	delete_transient( 'a11yfy_low_credit_mailed' );
	delete_transient( 'a11yfy_connect_state' );

	// User meta.
	delete_metadata( 'user', 0, 'a11yfy_connect_notice_dismissed', '', true );

	// Post meta.
	delete_post_meta_by_key( '_a11yfy_scan' );
	delete_post_meta_by_key( '_a11yfy_scan_before' );
	delete_post_meta_by_key( '_a11yfy_scan_ts' );
	delete_post_meta_by_key( '_a11yfy_scan_engine' );
	delete_post_meta_by_key( '_a11yfy_risk' );
	delete_post_meta_by_key( '_a11yfy_last_skip' );

	// Optimizer-guard flags: only the values we wrote (ShortPixel uses the same
	// key for its own fatal-error reasons — those must survive).
	$wpdb->query( // phpcs:ignore WordPress.DB
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_shortpixel_prevent_optimize' AND meta_value LIKE 'a11yfy:%'"
	);

	// Backups directory (originals were restored above if requested).
	$a11yfy_uploads = wp_get_upload_dir();
	$a11yfy_backups = trailingslashit( $a11yfy_uploads['basedir'] ) . 'a11yfy-backups';
	if ( is_dir( $a11yfy_backups ) ) {
		foreach ( (array) glob( $a11yfy_backups . '/*' ) as $a11yfy_file ) {
			wp_delete_file( $a11yfy_file );
		}
		@rmdir( $a11yfy_backups ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of our own empty backup dir.
	}
}

if ( is_multisite() ) {
	$a11yfy_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0, // All sites.
		)
	);
	foreach ( $a11yfy_site_ids as $a11yfy_site_id ) {
		switch_to_blog( (int) $a11yfy_site_id );
		a11yfy_uninstall_current_site();
		restore_current_blog();
	}
} else {
	a11yfy_uninstall_current_site();
}
