<?php
/**
 * Visitor remediation-request repository (on-demand mode).
 *
 * One row per (attachment, email) subscriber. Status flow:
 *   queued         — waiting for the remediation job to finish
 *   pending_credit — parked until the balance covers the estimate
 *   done_notified  — the ready-email went out (terminal)
 *   failed         — the job failed / the document is not processable (terminal)
 *
 * The email address is personal data: terminal rows are purged after 30 days,
 * parked rows that never started after 90 (§GDPR in the feature spec).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Requests {

	const TERMINAL_RETENTION_DAYS = 30;
	const PENDING_RETENTION_DAYS  = 90;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'a11yfy_requests';
	}

	/**
	 * Subscribe an email to an attachment's remediation.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $email         Normalized email.
	 * @param string $ip_hash       Salted sha256 of the requester IP.
	 * @param string $locale        Locale of the request (determine_locale()).
	 * @return bool True on insert, false on duplicate (already subscribed).
	 */
	public static function add( $attachment_id, $email, $ip_hash, $locale ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// INSERT IGNORE via suppressed error: the (attachment_id, email) unique
		// key makes a repeat subscription a harmless no-op.
		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			self::table(),
			array(
				'attachment_id' => (int) $attachment_id,
				'email'         => $email,
				'status'        => 'queued',
				'ip_hash'       => $ip_hash,
				'locale'        => $locale,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$wpdb->suppress_errors( $suppress );
		return (bool) $inserted;
	}

	/**
	 * Open (non-terminal) subscriber rows for an attachment.
	 *
	 * @return array[] Rows.
	 */
	public static function open_for_attachment( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE attachment_id = %d AND status IN ('queued','pending_credit') ORDER BY id ASC",
				self::table(),
				$attachment_id
			),
			ARRAY_A
		);
	}

	/**
	 * Move every open row of an attachment to a new status.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $to            Target status.
	 */
	public static function set_status_for_attachment( $attachment_id, $to ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"UPDATE %i SET status = %s, updated_at = %s WHERE attachment_id = %d AND status IN ('queued','pending_credit')",
				self::table(),
				$to,
				current_time( 'mysql', true ),
				$attachment_id
			)
		);
	}

	public static function mark( $row_id, $status ) {
		global $wpdb;
		$fields = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( 'done_notified' === $status ) {
			$fields['notified_at'] = current_time( 'mysql', true );
		}
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function delete_row( $row_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Any parked (pending_credit) request at all? Cheap gate for the resume loop.
	 */
	public static function has_pending() {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( "SELECT id FROM %i WHERE status = 'pending_credit' LIMIT 1", self::table() )
		);
	}

	/**
	 * Attachments with parked requests, oldest request first (§resume, F8:
	 * the source of truth is this table, not the risk meta).
	 *
	 * @return int[] Attachment IDs.
	 */
	public static function pending_attachments( $limit = 20 ) {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT attachment_id FROM %i WHERE status = 'pending_credit' GROUP BY attachment_id ORDER BY MIN(created_at) ASC LIMIT %d",
				self::table(),
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Dashboard counter.
	 *
	 * @return array { requests: int, documents: int } open (queued + parked) totals.
	 */
	public static function counts() {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests, COUNT(DISTINCT attachment_id) AS documents FROM %i WHERE status IN ('queued','pending_credit')",
				self::table()
			),
			ARRAY_A
		);
		return array(
			'requests'  => $row ? (int) $row['requests'] : 0,
			'documents' => $row ? (int) $row['documents'] : 0,
		);
	}

	/**
	 * Daily maintenance (GDPR retention + stuck-row recovery):
	 *  1. terminal rows older than 30 days → deleted (the email served its purpose)
	 *  2. parked rows older than 90 days → deleted (the remediation never started)
	 *  3. queued rows whose document is already remediated → notify retry sweep
	 *     (covers a wp_mail() failure at finalize time — F6)
	 *  4. stale visitor rate-limit counter options → deleted
	 */
	public static function purge() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"DELETE FROM %i WHERE status IN ('done_notified','failed') AND updated_at < %s",
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - self::TERMINAL_RETENTION_DAYS * DAY_IN_SECONDS )
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'pending_credit' AND created_at < %s",
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - self::PENDING_RETENTION_DAYS * DAY_IN_SECONDS )
			)
		);

		// Notify retry: queued subscribers of an already-active map row (missed
		// or failed email at finalize). Up to 5 attachments per run keeps it cheap.
		$stuck = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT DISTINCT r.attachment_id FROM %i r INNER JOIN %i m ON m.attachment_id = r.attachment_id
				 WHERE r.status = 'queued' AND r.updated_at < %s AND m.status = 'active' LIMIT 5",
				self::table(),
				A11yfy_Map::table(),
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);
		foreach ( $stuck as $attachment_id ) {
			A11yfy_Visitor_Notify::notify_attachment( (int) $attachment_id );
		}

		// Rate-limit counters embed their hour bucket in the option name — drop
		// every bucket except the current and previous one.
		$keep_now  = A11yfy_Visitor::rate_limit_bucket( 0 );
		$keep_prev = A11yfy_Visitor::rate_limit_bucket( -1 );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded maintenance delete on our own options.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( 'a11yfy_vrl_' ) . '%',
				'%' . $wpdb->esc_like( '_' . $keep_now ),
				'%' . $wpdb->esc_like( '_' . $keep_prev )
			)
		);
	}

	// ── WP privacy tools (exporter / eraser) ───────────────────────────────

	/**
	 * Rows for an email address (privacy exporter).
	 *
	 * @return array[] Rows.
	 */
	public static function rows_for_email( $email ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC', self::table(), $email ),
			ARRAY_A
		);
	}

	/**
	 * Delete every row of an email address (privacy eraser).
	 *
	 * @return int Deleted row count.
	 */
	public static function erase_email( $email ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'email' => $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
