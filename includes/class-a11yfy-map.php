<?php
/**
 * Remediation map repository (§5.3): original attachment → remediated file,
 * backup path, hash-gate ("already remediated this exact file → skip").
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Map {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'a11yfy_map';
	}

	public static function for_attachment( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE attachment_id = %d', self::table(), $attachment_id ),
			ARRAY_A
		);
	}

	/**
	 * Upsert the (unique per attachment) map row.
	 */
	public static function upsert( $attachment_id, array $fields ) {
		global $wpdb;
		$existing = self::for_attachment( $attachment_id );
		if ( $existing ) {
			$wpdb->update( self::table(), $fields, array( 'attachment_id' => (int) $attachment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $existing['id'];
		}
		$fields['attachment_id'] = (int) $attachment_id;
		$wpdb->insert( self::table(), $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function delete( $attachment_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'attachment_id' => (int) $attachment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Hash-gate (§14/16): was this exact file content already remediated?
	 * Protects against re-billing after the 24h idempotency TTL expires.
	 */
	public static function already_remediated( $attachment_id, $file_hash ) {
		$row = self::for_attachment( $attachment_id );
		if ( ! $row || 'active' !== $row['status'] ) {
			return false;
		}
		// Matches either the input we remediated or the output we produced.
		return ( $row['original_hash'] === $file_hash || $row['remediated_hash'] === $file_hash );
	}

	/**
	 * Dashboard counters.
	 *
	 * @return array { remediated: int }
	 */
	public static function counts() {
		global $wpdb;
		return array(
			'remediated' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active'", self::table() ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		);
	}

	/**
	 * All rows (uninstall / bulk restore).
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', self::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
	}
}
