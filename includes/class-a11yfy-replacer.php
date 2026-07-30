<?php
/**
 * Saving the remediated PDF (§13.3, §14/1).
 *
 * Default: in-place swap — same path/URL, original backed up to
 * uploads/a11yfy-backups, thumbnail metadata regenerated, one-click restore.
 * Conservative mode: remediated copy stored next to the original, links swapped
 * at render time (wp_get_attachment_url + the_content filters), original untouched.
 * Offloaded (non-local) files automatically fall back to conservative mode.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Replacer {

	public static function init() {
		// Conservative-mode render-time link swap (§5.1) — global, per-attachment opt-out (§14/2).
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		// Attachment deletion → map row + our file copies must go too, otherwise
		// the "Remediated" counter keeps counting ghosts and backups pile up.
		add_action( 'delete_attachment', array( __CLASS__, 'on_attachment_deleted' ) );
	}

	/**
	 * Cleanup when a PDF is deleted from the Media Library: remove the map row
	 * and every file copy the plugin created for it (original backup, pristine
	 * remediated backup, conservative-mode remediated sibling). Path prefixes
	 * are validated so only files under uploads/ that we wrote get deleted.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 */
	public static function on_attachment_deleted( $attachment_id ) {
		$row = A11yfy_Map::for_attachment( $attachment_id );
		if ( ! $row ) {
			return;
		}

		$backup_dir = trailingslashit( A11yfy_Install::backup_dir() );
		foreach ( array( $row['backup_path'], $row['remediated_backup_path'] ) as $path ) {
			if ( $path && 0 === strpos( $path, $backup_dir ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// Conservative mode writes the remediated PDF next to the original —
		// the attachment delete only removes the original.
		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );
		if ( 'conservative' === $row['mode'] && $row['remediated_path']
			&& 0 === strpos( $row['remediated_path'], $basedir ) && file_exists( $row['remediated_path'] ) ) {
			wp_delete_file( $row['remediated_path'] );
		}

		A11yfy_Map::delete( $attachment_id );
	}

	/**
	 * Download the job output and apply the configured save strategy.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $output_url    Presigned URL (1h expiry — always fresh from /result).
	 * @param array  $job_row       Jobs table row.
	 * @return array|WP_Error Map row fields on success.
	 */
	public static function apply( $attachment_id, $output_url, array $job_row ) {
		$original = get_attached_file( $attachment_id );
		$is_local = $original && file_exists( $original );

		// Offload/CDN detection (§13.3): both strategies need the local file —
		// non-local originals are "partially supported" in the NVP (v1.1: S3).
		if ( ! $is_local ) {
			return new WP_Error( 'a11yfy_offloaded', __( 'The original file is not stored locally (offload plugin?) — not supported yet.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$strategy = A11yfy_Settings::get( 'save_strategy' );

		$tmp = self::download( $output_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$result = ( 'inplace' === $strategy )
			? self::apply_inplace( $attachment_id, $original, $tmp )
			: self::apply_conservative( $attachment_id, $original, $tmp );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $tmp );
			return $result;
		}

		// Credit protection (§13.6): keep a pristine copy of the remediated PDF
		// so an optimizer/FTP rewrite is re-appliable for 0 credits, and flag
		// the attachment so ShortPixel & co. leave it alone.
		$fields = array_merge(
			$result,
			array(
				'job_row_id'             => (int) $job_row['id'],
				'treatment'              => $job_row['treatment'],
				'compliant'              => isset( $job_row['compliant'] ) ? $job_row['compliant'] : null,
				'before_issues'          => $job_row['before_issues'],
				'credits_used'           => $job_row['credits_used'],
				'source'                 => $job_row['source'],
				'status'                 => 'active',
				'remediated_at'          => current_time( 'mysql', true ),
				'remediated_backup_path' => self::backup_remediated( $attachment_id, $result['remediated_path'] ),
			)
		);
		A11yfy_Map::upsert( $attachment_id, $fields );
		A11yfy_Optimizer_Guard::protect( $attachment_id );

		// The stored file changed → stale scan verdict must not linger. The
		// pre-remediation verdict is kept as a snapshot: the dashboard details
		// panel shows "these issues existed → now fixed" from it (§7).
		$before = get_post_meta( $attachment_id, '_a11yfy_scan', true );
		if ( is_array( $before ) && 'client' === ( isset( $before['origin'] ) ? $before['origin'] : '' ) ) {
			update_post_meta( $attachment_id, '_a11yfy_scan_before', $before );
		}
		delete_post_meta( $attachment_id, '_a11yfy_scan' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_ts' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_engine' );

		// Conservative mode changes the rendered link — cached pages would keep
		// serving the old, inaccessible PDF until purged (§13.6). After the map
		// upsert, so a re-cache already renders the swapped URL.
		if ( 'conservative' === $fields['mode'] ) {
			A11yfy_Cache::purge_for_attachment( $attachment_id );
		}

		return $fields;
	}

	/**
	 * Download the presigned output to a temp file and verify it is a PDF.
	 *
	 * @param string $url Presigned output URL.
	 * @return string|WP_Error Temp file path.
	 */
	private static function download( $url ) {
		// SSRF-hardening: only https URLs that pass WP's own reachability
		// validation (wp_http_validate_url rejects localhost / private IP
		// ranges) may be fetched — the presigned output URL comes from the
		// a11yfy API response, treat it as untrusted wire data.
		if ( 0 !== strpos( (string) $url, 'https://' ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'a11yfy_bad_output_url', __( 'The remediated PDF URL is not a valid public https URL.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'a11yfy_download_failed', __( 'Could not download the remediated PDF from a11yfy.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		$fh    = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$magic = $fh ? fread( $fh, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( '%PDF-' !== $magic ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'a11yfy_bad_output', __( 'The downloaded file is not a valid PDF.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		return $tmp;
	}

	/**
	 * In-place swap with backup + thumbnail regen (§14/1 mandatory companions).
	 */
	private static function apply_inplace( $attachment_id, $original, $tmp ) {
		$backup_dir = A11yfy_Install::ensure_backup_dir();
		// Random token in the name: the .htaccess deny only works on Apache —
		// on nginx the only protection is that the URL cannot be guessed
		// (the path is stored in our job row, never exposed).
		$backup = trailingslashit( $backup_dir ) . $attachment_id . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 16, false, false ) . '-' . wp_basename( $original );

		if ( ! @copy( $original, $backup ) ) {
			return new WP_Error( 'a11yfy_backup_failed', __( 'Could not back up the original PDF — aborting the swap.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$original_hash = hash_file( 'sha256', $original );

		// Atomic-ish replace: rename over the original.
		if ( ! @rename( $tmp, $original ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-fs swap; WP_Filesystem has no atomic move.
			if ( ! @copy( $tmp, $original ) ) {
				@copy( $backup, $original ); // Roll back.
				return new WP_Error( 'a11yfy_swap_failed', __( 'Could not write the remediated PDF over the original.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
			}
			wp_delete_file( $tmp );
		}

		self::regen_metadata( $attachment_id, $original );

		return array(
			'mode'            => 'inplace',
			'backup_path'     => $backup,
			'remediated_path' => $original,
			'remediated_hash' => hash_file( 'sha256', $original ),
			'original_hash'   => $original_hash,
		);
	}

	/**
	 * Conservative mode: separate file, links swapped at render time.
	 */
	private static function apply_conservative( $attachment_id, $original, $tmp ) {
		$dir    = dirname( $original );
		$target = trailingslashit( $dir ) . preg_replace( '/\.pdf$/i', '', wp_basename( $original ) ) . '-accessible.pdf';

		if ( ! @rename( $tmp, $target ) && ! @copy( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-fs move with copy fallback.
			return new WP_Error( 'a11yfy_swap_failed', __( 'Could not store the remediated PDF next to the original.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		return array(
			'mode'            => 'conservative',
			'backup_path'     => null,
			'remediated_path' => $target,
			'remediated_hash' => hash_file( 'sha256', $target ),
			'original_hash'   => hash_file( 'sha256', $original ),
		);
	}

	/**
	 * Copy the remediated PDF into the protected backup dir (§13.6 layer 4).
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $source        Path of the freshly written remediated PDF.
	 * @return string|null Backup path, or null when the copy failed (non-fatal).
	 */
	public static function backup_remediated( $attachment_id, $source ) {
		$backup_dir = A11yfy_Install::ensure_backup_dir();
		// Unguessable name — see apply_inplace() for the nginx rationale.
		$backup = trailingslashit( $backup_dir ) . $attachment_id . '-remediated-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 16, false, false ) . '-' . wp_basename( $source );
		return @copy( $source, $backup ) ? $backup : null;
	}

	/**
	 * Re-apply the remediated PDF from our pristine copy after an external
	 * rewrite (optimizer, FTP). Costs 0 credits (§13.6 layer 4).
	 *
	 * @return true|WP_Error
	 */
	public static function reapply( $attachment_id ) {
		$row = A11yfy_Map::for_attachment( $attachment_id );
		if ( ! $row || 'active' !== $row['status'] || empty( $row['remediated_path'] ) ) {
			return new WP_Error( 'a11yfy_no_map', __( 'No remediated version is recorded for this PDF.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		if ( empty( $row['remediated_backup_path'] ) || ! file_exists( $row['remediated_backup_path'] ) ) {
			return new WP_Error( 'a11yfy_no_remediated_backup', __( 'No saved copy of the remediated PDF exists — run remediation again.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		if ( ! @copy( $row['remediated_backup_path'], $row['remediated_path'] ) ) {
			return new WP_Error( 'a11yfy_reapply_failed', __( 'Could not write the remediated PDF back over the file.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		if ( 'inplace' === $row['mode'] ) {
			self::regen_metadata( $attachment_id, $row['remediated_path'] );
		}
		A11yfy_Map::upsert( $attachment_id, array( 'remediated_hash' => hash_file( 'sha256', $row['remediated_path'] ) ) );
		delete_post_meta( $attachment_id, '_a11yfy_scan' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_ts' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_engine' );
		A11yfy_Optimizer_Guard::protect( $attachment_id );

		return true;
	}

	/**
	 * One-click restore (§13.3): put the backed-up original back.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( $attachment_id ) {
		$row = A11yfy_Map::for_attachment( $attachment_id );
		if ( ! $row || 'active' !== $row['status'] ) {
			return new WP_Error( 'a11yfy_no_map', __( 'No remediated version is recorded for this PDF.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		if ( 'inplace' === $row['mode'] ) {
			if ( ! $row['backup_path'] || ! file_exists( $row['backup_path'] ) ) {
				return new WP_Error( 'a11yfy_no_backup', __( 'The backup of the original PDF is missing.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
			}
			$original = get_attached_file( $attachment_id );
			if ( ! @copy( $row['backup_path'], $original ) ) {
				return new WP_Error( 'a11yfy_restore_failed', __( 'Could not restore the original PDF.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
			}
			self::regen_metadata( $attachment_id, $original );
		} elseif ( $row['remediated_path'] && file_exists( $row['remediated_path'] ) ) {
			wp_delete_file( $row['remediated_path'] );
		}

		A11yfy_Map::upsert( $attachment_id, array( 'status' => 'restored' ) );
		delete_post_meta( $attachment_id, '_a11yfy_scan' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_ts' );
		delete_post_meta( $attachment_id, '_a11yfy_scan_engine' );
		// The original is back — the "before" snapshot IS the current state again.
		delete_post_meta( $attachment_id, '_a11yfy_scan_before' );
		A11yfy_Optimizer_Guard::unprotect( $attachment_id );

		// Restore flips the rendered link back in conservative mode (§13.6).
		if ( 'conservative' === $row['mode'] ) {
			A11yfy_Cache::purge_for_attachment( $attachment_id );
		}

		return true;
	}

	/**
	 * Rebuild attachment metadata so the Media Library preview thumbnail
	 * reflects the new first page (§14/1).
	 */
	private static function regen_metadata( $attachment_id, $file ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
		clean_attachment_cache( $attachment_id );
	}

	// ── Conservative-mode render-time swap ─────────────────────────────────

	private static function swap_url_for( $attachment_id ) {
		static $cache = array();
		if ( array_key_exists( $attachment_id, $cache ) ) {
			return $cache[ $attachment_id ];
		}
		$row = A11yfy_Map::for_attachment( $attachment_id );
		$url = null;
		if ( $row && 'active' === $row['status'] && 'conservative' === $row['mode'] && empty( $row['opt_out'] )
			&& $row['remediated_path'] && file_exists( $row['remediated_path'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( 0 === strpos( $row['remediated_path'], $uploads['basedir'] ) ) {
				$url = str_replace( $uploads['basedir'], $uploads['baseurl'], $row['remediated_path'] );
			}
		}
		$cache[ $attachment_id ] = $url;
		return $url;
	}

	public static function filter_attachment_url( $url, $attachment_id ) {
		if ( is_admin() ) {
			return $url;
		}
		$swapped = self::swap_url_for( $attachment_id );
		return $swapped ? $swapped : $url;
	}

	public static function filter_content( $content ) {
		if ( is_admin() || false === stripos( $content, '.pdf' ) ) {
			return $content;
		}
		static $rows = null;
		if ( null === $rows ) {
			global $wpdb;
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
				$wpdb->prepare(
					"SELECT attachment_id FROM %i WHERE status = 'active' AND mode = 'conservative' AND opt_out = 0",
					A11yfy_Map::table()
				),
				ARRAY_A
			);
		}
		foreach ( $rows as $row ) {
			$id       = (int) $row['attachment_id'];
			$swapped  = self::swap_url_for( $id );
			$original = wp_get_original_image_url( $id );
			if ( ! $original ) {
				$file     = get_post_meta( $id, '_wp_attached_file', true );
				$uploads  = wp_get_upload_dir();
				$original = $file ? trailingslashit( $uploads['baseurl'] ) . $file : null;
			}
			if ( $swapped && $original && false !== strpos( $content, $original ) ) {
				$content = str_replace( $original, esc_url( $swapped ), $content );
			}
		}
		return $content;
	}
}
