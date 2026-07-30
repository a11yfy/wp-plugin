<?php
/**
 * Jobs repository — WP-side job state machine rows.
 *
 * Status flow: queued → submitted → processing → finalizing → done | failed | stalled
 * ('finalizing' = short-lived claim while /result is fetched and the file saved;
 * 'stalled' = polling watchdog fired after 2h, §14/18).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Jobs {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'a11yfy_jobs';
	}

	/**
	 * @return int Row ID.
	 */
	public static function create( $attachment_id, $file_hash, $file_name, $idempotency_key, $source ) {
		global $wpdb;

		// Reuse a still-open row for the same content (idempotent re-submit):
		// the API-side idempotency key is identical, a second WP row would only
		// double-poll — and double-account — the same job.
		$open = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE idempotency_key = %s AND status IN ('queued','submitted','processing','finalizing') ORDER BY id DESC LIMIT 1",
				self::table(),
				$idempotency_key
			)
		);
		if ( $open ) {
			return (int) $open;
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'attachment_id'   => (int) $attachment_id,
				'file_hash'       => $file_hash,
				'file_name'       => $file_name,
				'idempotency_key' => $idempotency_key,
				'status'          => 'queued',
				'source'          => $source,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( $row_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $row_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
	}

	public static function update( $row_id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomically claim a row for finalization — only the winner proceeds
	 * (regular poll and the reconcile sweep can race; a double finalize would
	 * double-count the spend). A claim orphaned by a crash becomes
	 * re-claimable after 10 minutes.
	 *
	 * @param int $row_id Jobs table row ID.
	 * @return bool Whether this caller won the claim.
	 */
	public static function claim_finalize( $row_id ) {
		global $wpdb;
		return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the conditional UPDATE is the claim.
			$wpdb->prepare(
				"UPDATE %i SET status = 'finalizing', updated_at = %s WHERE id = %d AND ( status IN ('submitted','processing') OR ( status = 'finalizing' AND updated_at < %s ) )",
				self::table(),
				current_time( 'mysql', true ),
				(int) $row_id,
				gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS )
			)
		);
	}

	/**
	 * Row lookup by the SaaS-side job id (webhook trigger path).
	 */
	public static function find_by_job_id( $job_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s ORDER BY id DESC LIMIT 1', self::table(), $job_id ),
			ARRAY_A
		);
	}

	/**
	 * Most recent job row for an attachment.
	 */
	public static function latest_for_attachment( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE attachment_id = %d ORDER BY id DESC LIMIT 1',
				self::table(),
				$attachment_id
			),
			ARRAY_A
		);
	}

	/**
	 * Is there an in-flight job for this attachment?
	 */
	public static function has_active( $attachment_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE attachment_id = %d AND status IN ('queued','submitted','processing','finalizing') LIMIT 1",
				self::table(),
				$attachment_id
			)
		);
	}

	/**
	 * Non-terminal rows not touched for $minutes — reconciliation targets.
	 *
	 * @return array[] Rows.
	 */
	public static function stale_rows( $minutes, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ('submitted','processing','finalizing') AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Latest rows for the dashboard job list.
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ),
			ARRAY_A
		);
	}

	/**
	 * Count of in-flight jobs (dashboard "in progress" badge).
	 */
	public static function count_active() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','submitted','processing','finalizing')", self::table() )
		);
	}
}
