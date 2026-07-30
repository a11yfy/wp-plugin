<?php
/**
 * Admin-AJAX endpoints. Every handler: nonce + capability + payload validation.
 * The scan write-back is deliberately hardened (§14 blind-spot list): a forged
 * "compliant" verdict would make auto-remediate skip the file.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Ajax {

	const NONCE = 'a11yfy_ajax';

	/**
	 * Periodic re-validation interval: a fresh client scan is re-checked
	 * (cheap fingerprint compare) after this long, so externally changed
	 * files are re-scanned eventually.
	 */
	const RESCAN_SECONDS = WEEK_IN_SECONDS;

	/**
	 * Last scan failure per attachment: array{ code: string, ts: int }.
	 * Two consecutive identical failures park the file in the weekly re-scan
	 * window — otherwise a permanently failing file (CDN serving different
	 * bytes than disk, engine crash on a malformed PDF) would be re-downloaded
	 * and re-scanned on every single admin page load.
	 */
	const SCAN_ERROR_META = '_a11yfy_scan_error';

	public static function init() {
		add_action( 'wp_ajax_a11yfy_scan_batch', array( __CLASS__, 'scan_batch' ) );
		add_action( 'wp_ajax_a11yfy_save_scan', array( __CLASS__, 'save_scan' ) );
		add_action( 'wp_ajax_a11yfy_scan_failed', array( __CLASS__, 'scan_failed' ) );
		add_action( 'wp_ajax_a11yfy_fetch_pdf', array( __CLASS__, 'fetch_pdf' ) );
		add_action( 'wp_ajax_a11yfy_remediate', array( __CLASS__, 'remediate' ) );
		add_action( 'wp_ajax_a11yfy_fix_all', array( __CLASS__, 'fix_all' ) );
		add_action( 'wp_ajax_a11yfy_restore', array( __CLASS__, 'restore' ) );
		add_action( 'wp_ajax_a11yfy_status', array( __CLASS__, 'status' ) );
		add_action( 'wp_ajax_a11yfy_list_pdfs', array( __CLASS__, 'list_pdfs' ) );
		add_action( 'wp_ajax_a11yfy_fetch_backup', array( __CLASS__, 'fetch_backup' ) );
		add_action( 'wp_ajax_a11yfy_reapply', array( __CLASS__, 'reapply' ) );
		add_action( 'wp_ajax_a11yfy_disable_pdf_optimization', array( __CLASS__, 'disable_pdf_optimization' ) );
	}

	private static function guard( $capability ) {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired — reload the page.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}
	}

	/**
	 * Next chunk of PDFs the browser engine should scan.
	 * Staleness: no verdict, PHP-triage verdict, engine upgrade, or file changed
	 * (cheap mtime-size fingerprint; the sha256 is checked at save time).
	 *
	 * The stale set is queried directly (never scanned, scanned by an older
	 * engine, or due for the periodic fingerprint re-check), oldest scan first —
	 * so every PDF in the library is reached, not just the most recent ones.
	 */
	public static function scan_batch() {
		self::guard( 'edit_posts' );

		$limit = 10;
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				// Headroom over the batch size: candidates skipped below are
				// "touched" and leave the stale window for the next call.
				'posts_per_page' => $limit * 4,
				'orderby'        => 'a11yfy_scan_ts',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the stale lookup is the feature; admin-only, keys are indexed postmeta.
					'relation'       => 'OR',
					'a11yfy_scan_ts' => array(
						'key'     => '_a11yfy_scan_ts',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_a11yfy_scan_ts',
						'value'   => time() - self::RESCAN_SECONDS,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_a11yfy_scan_engine',
						'value'   => A11yfy_Admin::ENGINE_VERSION,
						'compare' => '!=',
					),
				),
			)
		);

		$batch = array();
		foreach ( $query->posts as $id ) {
			if ( count( $batch ) >= $limit ) {
				break;
			}
			// Per-object capability: only hand out PDFs this user may store a verdict for.
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) || filesize( $file ) > A11yfy_Guardrails::MAX_BYTES ) {
				// Not client-scannable (missing file, or above the §13.4 cap —
				// PHP triage owns those). Touch the timestamp so the entry
				// leaves the stale window instead of starving it forever.
				update_post_meta( $id, '_a11yfy_scan_ts', time() );
				continue;
			}
			$fingerprint = filemtime( $file ) . '-' . filesize( $file );
			$scan        = get_post_meta( $id, '_a11yfy_scan', true );

			$fresh = is_array( $scan )
				&& 'client' === ( isset( $scan['origin'] ) ? $scan['origin'] : '' )
				&& ( isset( $scan['engine_version'] ) ? $scan['engine_version'] : '' ) === A11yfy_Admin::ENGINE_VERSION
				&& ( isset( $scan['fingerprint'] ) ? $scan['fingerprint'] : '' ) === $fingerprint;

			if ( $fresh ) {
				// Re-validated: unchanged file, current engine — next check in RESCAN_SECONDS.
				update_post_meta( $id, '_a11yfy_scan_ts', time() );
				update_post_meta( $id, '_a11yfy_scan_engine', A11yfy_Admin::ENGINE_VERSION );
				continue;
			}

			$batch[] = array(
				'id'       => (int) $id,
				'url'      => wp_get_attachment_url( $id ),
				'filename' => wp_basename( $file ),
			);
		}

		wp_send_json_success( array( 'items' => $batch ) );
	}

	/**
	 * Record a scan failure with two-strike parking (see SCAN_ERROR_META).
	 *
	 * First failure only records the code — the next page load retries
	 * immediately, so a transient race (file replaced mid-scan) heals itself.
	 * A second consecutive identical failure touches the staleness metas: the
	 * file leaves the stale window and is retried on the weekly cycle instead
	 * of on every page load. A successful save_scan() clears the meta.
	 *
	 * @internal Public for tests.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $code          Short machine failure code.
	 */
	public static function record_scan_failure( $attachment_id, $code ) {
		$code = sanitize_key( substr( (string) $code, 0, 32 ) );
		$prev = get_post_meta( $attachment_id, self::SCAN_ERROR_META, true );

		update_post_meta(
			$attachment_id,
			self::SCAN_ERROR_META,
			array(
				'code' => $code,
				'ts'   => time(),
			)
		);

		$prev_code = ( is_array( $prev ) && isset( $prev['code'] ) ) ? $prev['code'] : null;
		if ( $code === $prev_code ) {
			update_post_meta( $attachment_id, '_a11yfy_scan_ts', time() );
			update_post_meta( $attachment_id, '_a11yfy_scan_engine', A11yfy_Admin::ENGINE_VERSION );
		}
	}

	/**
	 * Client-side scan failure report (POST: id, code) — fetch or engine error
	 * the browser could not turn into a save_scan() call.
	 */
	public static function scan_failed() {
		self::guard( 'edit_posts' );

		$attachment_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_ajax_referer().
		if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'bad attachment' ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}

		$code = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized in record_scan_failure(); guard() runs check_ajax_referer().
		self::record_scan_failure( $attachment_id, $code ? $code : 'client' );

		wp_send_json_success();
	}

	/**
	 * Store one client-scan verdict (POST: id, report JSON).
	 */
	public static function save_scan() {
		self::guard( 'edit_posts' );

		$attachment_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_ajax_referer().
		if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'bad attachment' ), 400 );
		}
		// Per-object capability (§14 blind-spot list): a blanket edit_posts
		// would let a Contributor forge a verdict on any attachment.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}

		$raw    = isset( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON validated below; guard() runs check_ajax_referer().
		$report = json_decode( $raw, true );
		if ( ! is_array( $report ) ) {
			self::record_scan_failure( $attachment_id, 'bad_report' );
			wp_send_json_error( array( 'message' => 'bad report' ), 400 );
		}

		// ── Strict payload validation ──
		$score = isset( $report['score'] ) ? (int) $report['score'] : null;
		$risk  = isset( $report['risk'] ) ? (string) $report['risk'] : '';
		$hash  = isset( $report['fileHash'] ) ? strtolower( (string) $report['fileHash'] ) : '';
		if ( null === $score || $score < 0 || $score > 100
			|| ! in_array( $risk, array( 'low', 'medium', 'high', 'critical' ), true )
			|| ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			self::record_scan_failure( $attachment_id, 'invalid_verdict' );
			wp_send_json_error( array( 'message' => 'invalid verdict' ), 400 );
		}

		// The scanned bytes must be the file that is on disk right now.
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) || hash_file( 'sha256', $file ) !== $hash ) {
			self::record_scan_failure( $attachment_id, 'hash_mismatch' );
			wp_send_json_error( array( 'message' => 'file changed since scan' ), 409 );
		}

		// Cap and sanitize the stored check list (no HTML, bounded size).
		$checks = array();
		if ( isset( $report['checks'] ) && is_array( $report['checks'] ) ) {
			foreach ( array_slice( $report['checks'], 0, 80 ) as $check ) {
				if ( ! is_array( $check ) || empty( $check['id'] ) ) {
					continue;
				}
				$items = array();
				if ( ! empty( $check['items'] ) && is_array( $check['items'] ) ) {
					foreach ( array_slice( $check['items'], 0, 20 ) as $item ) {
						$items[] = array(
							'page'   => isset( $item['page'] ) ? (int) $item['page'] : null,
							'detail' => sanitize_text_field( substr( isset( $item['detail'] ) ? (string) $item['detail'] : '', 0, 160 ) ),
						);
					}
				}
				$checks[] = array(
					'id'     => sanitize_text_field( substr( (string) $check['id'], 0, 32 ) ),
					'group'  => sanitize_text_field( substr( isset( $check['group'] ) ? (string) $check['group'] : '', 0, 24 ) ),
					'status' => in_array( isset( $check['status'] ) ? $check['status'] : '', array( 'pass', 'fail', 'inapplicable', 'error' ), true ) ? $check['status'] : 'error',
					'count'  => isset( $check['count'] ) ? min( 99999, max( 0, (int) $check['count'] ) ) : 0,
					'items'  => $items,
				);
			}
		}

		// Conservative green (§13.4/§14/12): every applicable check passed AND
		// tagged AND no check crashed — errored checks did not actually run, so
		// a verdict built on them must not turn the file green.
		$has_error = false;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$has_error = true;
				break;
			}
		}
		$compliant = ( 100 === $score ) && ! empty( $report['tagged'] ) && ! $has_error;

		// Remediation blockers (engine 0.5.0): first match in precedence order
		// lands in a dedicated queryable meta — non_compliant_ids() and the
		// guardrail read it, the row UI renders the localized reason from it.
		$blocked = '';
		foreach ( array( 'encrypted', 'signed', 'xfa', 'portfolio' ) as $blocker ) {
			if ( ! empty( $report[ $blocker ] ) ) {
				$blocked = $blocker;
				break;
			}
		}
		if ( $blocked ) {
			update_post_meta( $attachment_id, A11yfy_Guardrails::BLOCKED_META, $blocked );
		} else {
			delete_post_meta( $attachment_id, A11yfy_Guardrails::BLOCKED_META );
		}

		update_post_meta(
			$attachment_id,
			'_a11yfy_scan',
			array(
				'origin'         => 'client',
				'engine_version' => sanitize_text_field( substr( isset( $report['engineVersion'] ) ? (string) $report['engineVersion'] : '', 0, 16 ) ),
				'file_hash'      => $hash,
				'fingerprint'    => filemtime( $file ) . '-' . filesize( $file ),
				'score'          => $score,
				'risk'           => $risk,
				'pages'          => isset( $report['pages'] ) ? min( 99999, max( 0, (int) $report['pages'] ) ) : 0,
				'tagged'         => ! empty( $report['tagged'] ),
				'scanned_likely' => ! empty( $report['scannedLikely'] ),
				'encrypted'      => ! empty( $report['encrypted'] ),
				'signed'         => ! empty( $report['signed'] ),
				'xfa'            => ! empty( $report['xfa'] ),
				'portfolio'      => ! empty( $report['portfolio'] ),
				'compliant'      => $compliant,
				'checks'         => $checks,
				'scanned_at'     => time(),
			)
		);
		update_post_meta( $attachment_id, '_a11yfy_risk', $compliant ? 'compliant' : $risk );
		// Queryable staleness metas — scan_batch() selects on these directly.
		update_post_meta( $attachment_id, '_a11yfy_scan_ts', time() );
		update_post_meta( $attachment_id, '_a11yfy_scan_engine', sanitize_text_field( substr( isset( $report['engineVersion'] ) ? (string) $report['engineVersion'] : '', 0, 16 ) ) );
		delete_post_meta( $attachment_id, self::SCAN_ERROR_META );

		wp_send_json_success( array( 'badge' => A11yfy_Admin::badge_html( $attachment_id ) ) );
	}

	/**
	 * CORS fallback (§13.4): stream the attachment bytes same-origin when the
	 * media library is served from a CDN domain the browser can't fetch.
	 */
	public static function fetch_pdf() {
		self::guard( 'edit_posts' );

		// Nonce is verified above by guard() → check_ajax_referer(); the sniff
		// cannot follow the check across the helper method.
		$attachment_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file          = $attachment_id ? get_attached_file( $attachment_id ) : false;
		if ( ! $file || ! file_exists( $file ) || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		// Per-object capability — mirror of save_scan(): stream only what the
		// user could store a verdict for.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}
		if ( filesize( $file ) > A11yfy_Guardrails::MAX_BYTES ) {
			wp_send_json_error( array( 'message' => 'too large' ), 413 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $file ) );
		// Consumed by fetch()+arrayBuffer() in admin.js — attachment disposition
		// only affects navigation, so it hardens without breaking the scanner.
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', wp_basename( $file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Stream the pre-remediation backup of a remediated attachment (GET: id) —
	 * the document detail page's "original" view analyzes and previews it
	 * client-side. Path comes from the map row (DB), never from user input.
	 */
	public static function fetch_backup() {
		self::guard( 'edit_posts' );

		$attachment_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() runs check_ajax_referer().
		if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 403 );
		}
		$map  = A11yfy_Map::for_attachment( $attachment_id );
		$file = $map && 'active' === $map['status'] && ! empty( $map['backup_path'] ) ? $map['backup_path'] : '';
		// Defense-in-depth (mirror of A11yfy_Replacer): the path comes from our
		// own DB row, but stream only from inside the plugin's backup dir.
		if ( ! $file || 0 !== strpos( $file, trailingslashit( A11yfy_Install::backup_dir() ) ) || ! file_exists( $file ) ) {
			wp_send_json_error( array( 'message' => 'no backup' ), 404 );
		}
		if ( filesize( $file ) > A11yfy_Guardrails::MAX_BYTES ) {
			wp_send_json_error( array( 'message' => 'too large' ), 413 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', wp_basename( $file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Filterable PDF list for the dashboard table (GET: statuses csv, paged).
	 */
	public static function list_pdfs() {
		self::guard( 'edit_posts' );

		$allowed  = array( 'passed', 'partial', 'failing', 'remediated', 'unscanned' );
		$raw      = isset( $_GET['statuses'] ) ? sanitize_text_field( wp_unslash( $_GET['statuses'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() runs check_ajax_referer().
		$statuses = array_values( array_intersect( $allowed, array_filter( explode( ',', $raw ) ) ) );
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() runs check_ajax_referer().

		wp_send_json_success( A11yfy_Admin::pdf_list( $statuses, $paged, 20 ) );
	}

	/**
	 * Re-apply the saved remediated copy after an external rewrite (§13.6).
	 * Free (0 credits) but file-changing → manage_options.
	 */
	public static function reapply() {
		self::guard( 'manage_options' );

		$attachment_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_ajax_referer().
		$result        = $attachment_id ? A11yfy_Replacer::reapply( $attachment_id ) : new WP_Error( 'bad', 'bad attachment' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'badge' => A11yfy_Admin::badge_html( $attachment_id ) ) );
	}

	/**
	 * Turn off ShortPixel's PDF optimization — explicit button on the
	 * dashboard warning banner (§13.6), never silent.
	 */
	public static function disable_pdf_optimization() {
		self::guard( 'manage_options' );

		if ( ! A11yfy_Optimizer_Guard::disable_shortpixel_pdf() ) {
			wp_send_json_error( array( 'message' => __( 'Could not change the ShortPixel settings — disable PDF optimization in Settings → ShortPixel.', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 500 );
		}
		wp_send_json_success();
	}

	/**
	 * Queue one attachment for remediation (money action → manage_options, §8).
	 */
	public static function remediate() {
		self::guard( 'manage_options' );

		$attachment_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_ajax_referer().
		if ( ! $attachment_id || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'bad attachment' ), 400 );
		}
		if ( ! A11yfy_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your a11yfy account first (Settings).', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 400 );
		}

		// Signed-PDF confirm: the user explicitly accepted that remediation
		// invalidates the digital signature — record it hash-scoped, so it only
		// covers this exact content (and never a later re-signed upload).
		if ( ! empty( $_POST['confirm_signed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- guard() runs check_ajax_referer().
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) ) {
				update_post_meta( $attachment_id, A11yfy_Guardrails::SIGNED_OK_META, hash_file( 'sha256', $file ) );
			}
		}

		A11yfy_Queue::enqueue_remediation( $attachment_id, 'manual' );
		wp_send_json_success( array( 'badge' => A11yfy_Admin::badge_html( $attachment_id ) ) );
	}

	/**
	 * Dashboard "Fix all non-compliant" — server-side target list, queued in bulk.
	 * No confirm dialog by design; the button itself shows estimate + balance.
	 */
	public static function fix_all() {
		self::guard( 'manage_options' );

		if ( ! A11yfy_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your a11yfy account first (Settings).', 'a11yfy-pdf-accessibility-checker-fixer' ) ), 400 );
		}

		$ids = A11yfy_Admin::non_compliant_ids();
		foreach ( $ids as $id ) {
			A11yfy_Queue::enqueue_remediation( $id, 'bulk' );
		}
		wp_send_json_success( array( 'queued' => count( $ids ) ) );
	}

	/**
	 * Restore the original PDF from backup.
	 */
	public static function restore() {
		self::guard( 'manage_options' );

		$attachment_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_ajax_referer().
		$result        = $attachment_id ? A11yfy_Replacer::restore( $attachment_id ) : new WP_Error( 'bad', 'bad attachment' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'badge' => A11yfy_Admin::badge_html( $attachment_id ) ) );
	}

	/**
	 * Dashboard heartbeat: active job list snapshot (drives the progress panel;
	 * also serves as admin-AJAX polling while the page is open, §4).
	 */
	public static function status() {
		self::guard( 'edit_posts' );

		$jobs = array();
		foreach ( A11yfy_Jobs::recent( 20 ) as $row ) {
			// Per-object capability: hide job rows for attachments the user
			// could not edit (Contributor sees only their own). Rows whose
			// attachment was deleted stay visible (history/debugging).
			if ( get_post( (int) $row['attachment_id'] ) && ! current_user_can( 'edit_post', (int) $row['attachment_id'] ) ) {
				continue;
			}
			$jobs[] = array(
				'id'            => (int) $row['id'],
				'attachment_id' => (int) $row['attachment_id'],
				'file_name'     => $row['file_name'],
				'status'        => $row['status'],
				'treatment'     => $row['treatment'],
				'credits_used'  => null !== $row['credits_used'] ? (int) $row['credits_used'] : null,
				// Best-effort jelzés (0 = nem teljes PDF/UA-1, ingyenes); NULL =
				// ismeretlen (régi sor / régi szerver-válasz).
				'compliant'     => isset( $row['compliant'] ) && null !== $row['compliant'] ? (int) $row['compliant'] : null,
				'before_issues' => null !== $row['before_issues'] ? (int) $row['before_issues'] : null,
				'error_message' => $row['error_message'],
			);
		}
		wp_send_json_success(
			array(
				'jobs'   => $jobs,
				'active' => A11yfy_Jobs::count_active(),
			)
		);
	}
}
