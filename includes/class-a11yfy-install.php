<?php
/**
 * Activation / upgrade: custom tables, backup dir, schema versioning.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Install {

	// v3: reruns the guard backfill — rewrites our prevent-meta reasons to the
	// stable English form (they were locale-baked before 0.2.2).
	const SCHEMA_VERSION = '4';

	public static function activate() {
		self::create_tables();
		self::ensure_backup_dir();
		// v2: optimizer protection backfill — flag remediated attachments and
		// save the credit-protecting copy for still-pristine files (§13.6).
		A11yfy_Optimizer_Guard::backfill();
		update_option( 'a11yfy_schema_version', self::SCHEMA_VERSION );
	}

	public static function deactivate() {
		// Stop background work; keep all state (§14/17).
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'a11yfy-pdf-accessibility-checker-fixer' );
		}
		// WP-cron fallback events (scheduled when Action Scheduler is
		// unavailable) — clear regardless of args.
		foreach ( array( 'a11yfy_submit_job', 'a11yfy_retry_submit', 'a11yfy_poll_job', 'a11yfy_triage' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'a11yfy_schema_version' ) !== self::SCHEMA_VERSION ) {
			self::activate();
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// Remediation jobs — the WP-side mirror of /v1 jobs (polling state machine).
		$jobs = "CREATE TABLE {$wpdb->prefix}a11yfy_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			job_id VARCHAR(64) NULL,
			idempotency_key VARCHAR(128) NOT NULL,
			file_hash CHAR(64) NOT NULL,
			file_name VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			credits_used INT UNSIGNED NULL,
			treatment VARCHAR(20) NULL,
			compliant TINYINT(1) NULL,
			before_issues INT UNSIGNED NULL,
			before_pages INT UNSIGNED NULL,
			error_code VARCHAR(64) NULL,
			error_message TEXT NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'manual',
			poll_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			submitted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY job_id (job_id)
		) $charset;";

		// original attachment → remediated file mapping (report, restore, hash-gate).
		$map = "CREATE TABLE {$wpdb->prefix}a11yfy_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			job_row_id BIGINT UNSIGNED NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'inplace',
			backup_path VARCHAR(1024) NULL,
			remediated_path VARCHAR(1024) NULL,
			remediated_backup_path VARCHAR(1024) NULL,
			remediated_hash CHAR(64) NULL,
			original_hash CHAR(64) NOT NULL,
			treatment VARCHAR(20) NULL,
			compliant TINYINT(1) NULL,
			before_issues INT UNSIGNED NULL,
			credits_used INT UNSIGNED NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'manual',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			opt_out TINYINT(1) NOT NULL DEFAULT 0,
			remediated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id)
		) $charset;";

		dbDelta( $jobs );
		dbDelta( $map );
	}

	/**
	 * uploads/a11yfy-backups with directory-listing + direct-access protection.
	 */
	public static function ensure_backup_dir() {
		$dir = self::backup_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file, WP_Filesystem is overkill here.
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file, WP_Filesystem is overkill here.
		}
		return $dir;
	}

	public static function backup_dir() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'a11yfy-backups';
	}
}
