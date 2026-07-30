<?php
/**
 * Page-cache purge integration (§13.6).
 *
 * Conservative mode swaps PDF links at render time (the_content /
 * wp_get_attachment_url filters), so any page-cache plugin keeps serving the
 * pre-remediation HTML with the old link until its cache is purged. After a
 * conservative-mode save (and after a restore) we purge the posts that
 * reference the PDF on every detected cache plugin; when no referencing post
 * can be found the whole cache is purged — a stale inaccessible link is worse
 * than one cache rebuild.
 *
 * In-place mode keeps the same URL, so no purge is needed there.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Cache {

	/** LIKE-scan safety cap — beyond this we purge everything anyway. */
	const MAX_REFERENCING_POSTS = 50;

	/**
	 * Purge cached pages that reference the attachment.
	 *
	 * @param int $attachment_id Attachment whose rendered URL changed.
	 */
	public static function purge_for_attachment( $attachment_id ) {
		/**
		 * Filters whether a11yfy may purge page caches after a link swap.
		 *
		 * @param bool $enabled       Default true.
		 * @param int  $attachment_id Attachment being purged.
		 */
		if ( ! apply_filters( 'a11yfy_cache_purge_enabled', true, (int) $attachment_id ) ) {
			return;
		}

		$post_ids = self::referencing_posts( (int) $attachment_id );

		if ( empty( $post_ids ) || count( $post_ids ) >= self::MAX_REFERENCING_POSTS ) {
			self::purge_all();
			return;
		}

		foreach ( $post_ids as $post_id ) {
			self::purge_post( (int) $post_id );
		}
	}

	/**
	 * Published posts whose content references the attachment file, plus the
	 * attachment's parent post. Widget/template references cannot be found
	 * this way — the caller falls back to purge_all() on an empty result.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int[] Post IDs.
	 */
	private static function referencing_posts( $attachment_id ) {
		global $wpdb;

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off LIKE scan at remediation time.
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type NOT IN ( 'attachment', 'revision', 'nav_menu_item' )
				   AND post_content LIKE %s
				 LIMIT %d",
				'%' . $wpdb->esc_like( $file ) . '%',
				self::MAX_REFERENCING_POSTS
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		$parent = (int) get_post_field( 'post_parent', $attachment_id );
		if ( $parent && ! in_array( $parent, $ids, true ) ) {
			$ids[] = $parent;
		}

		return $ids;
	}

	/**
	 * Per-post purge on every detected cache plugin. All calls are guarded
	 * (function_exists) or hook-based (no-op when the plugin is absent).
	 *
	 * @param int $post_id Post to purge.
	 */
	public static function purge_post( $post_id ) {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
		// LiteSpeed Cache (v3+ hook API).
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party cache plugin's own hook, not ours to prefix.
		// WP Super Cache.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		// WP Fastest Cache.
		do_action( 'wpfc_clear_post_cache_by_id', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party cache plugin's own hook, not ours to prefix.
		// WordPress core: post + associated caches (harmless without a page cache).
		clean_post_cache( $post_id );
	}

	/**
	 * Full-cache purge fallback on every detected cache plugin.
	 */
	public static function purge_all() {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party cache plugin's own hook, not ours to prefix.
		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		// WP Fastest Cache.
		do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party cache plugin's own hook, not ours to prefix.
		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party cache plugin's own hook, not ours to prefix.
	}
}
