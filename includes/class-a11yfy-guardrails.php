<?php
/**
 * Credit-protection guardrails (§6, North Star: unit cost per document).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Guardrails {

	const SPEND_OPTION = 'a11yfy_monthly_spend';
	const MAX_BYTES    = 52428800; // 50 MB — NVP client-scan AND remediate cap (§14/7).
	const BLOCKED_META = '_a11yfy_blocked';
	// Explicit user confirmation to remediate a digitally signed PDF, scoped to
	// the exact content (sha256) it was confirmed for — a replaced/re-signed
	// file needs a fresh confirmation, and a successful remediation (new hash)
	// neutralizes it automatically.
	const SIGNED_OK_META = '_a11yfy_signed_ok';

	/**
	 * Document-property blockers the client-side engine detects (report booleans,
	 * engine >= 0.5.0), in precedence order. Stored as a bare code in the
	 * BLOCKED_META post meta; the message is rendered at display time so it
	 * always matches the current admin locale (0.2.2 lesson: never bake a
	 * locale into stored values).
	 *
	 * @return array<string,string> code => user-facing message.
	 */
	public static function blocker_messages() {
		return array(
			'encrypted' => __( 'This PDF is password-protected or encrypted. Remove the protection, then run remediation again.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'signed'    => __( 'This PDF is digitally signed. Remediation rewrites the file, so the signature will no longer be valid — the fix only starts after you confirm.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'xfa'       => __( 'This PDF contains an XFA form, which cannot be preserved during remediation. Convert it to a regular PDF form first.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'portfolio' => __( 'This PDF is a portfolio (a container of embedded files). Remediate the individual documents instead.', 'a11yfy-pdf-accessibility-checker-fixer' ),
		);
	}

	/**
	 * Blocker code recorded by the last client scan ('' when remediable).
	 *
	 * @param int $attachment_id Attachment.
	 * @return string One of the blocker_messages() keys, or ''.
	 */
	public static function blocked_code( $attachment_id ) {
		$code = get_post_meta( $attachment_id, self::BLOCKED_META, true );
		return ( is_string( $code ) && isset( self::blocker_messages()[ $code ] ) ) ? $code : '';
	}

	/**
	 * Display-time message for a stored skip/blocker code. Falls back to the
	 * message that was stored alongside the code (older rows, non-catalog codes).
	 *
	 * @param string $code   Skip code (with or without the a11yfy_ prefix).
	 * @param string $stored Message stored at skip time.
	 * @return string
	 */
	public static function skip_message( $code, $stored ) {
		$messages = self::blocker_messages();
		$bare     = preg_replace( '/^a11yfy_/', '', (string) $code );
		return isset( $messages[ $bare ] ) ? $messages[ $bare ] : (string) $stored;
	}

	/**
	 * Can this attachment be submitted for remediation right now?
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $source        auto|manual|bulk.
	 * @return true|WP_Error
	 */
	public static function check( $attachment_id, $source ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'a11yfy_file_missing', __( 'The attachment file is not available on the local filesystem.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// 50 MB NVP cap (§14/7).
		$size = filesize( $file );
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'a11yfy_too_large', __( 'This PDF is larger than 50 MB — above the current processing limit.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// Content hash: hash-gate + the signed-PDF confirmation is scoped to it.
		$hash = hash_file( 'sha256', $file );

		// Encrypted / password-protected PDF (audit 7-P0.3): the pipeline cannot
		// remediate it and would waste an API call. Detection: the trailer's
		// `/Encrypt N M R` entry in the file tail — plaintext by spec even in
		// encrypted PDFs, so a tail scan is reliable and costs one 32 KB read.
		if ( self::is_encrypted_pdf( $file, $size ) ) {
			return new WP_Error( 'a11yfy_encrypted', self::blocker_messages()['encrypted'] );
		}

		// Blocker recorded by the client-side scan (signed / XFA / portfolio —
		// engine 0.5.0): the pipeline either cannot represent the content or
		// would destroy something the owner cares about (signature validity).
		// A signed PDF passes when the user explicitly confirmed the signature
		// loss for THIS exact content (hash-scoped confirm meta).
		$blocked = self::blocked_code( $attachment_id );
		if ( $blocked && ! ( 'signed' === $blocked && self::signed_allowed( $attachment_id, $hash ) ) ) {
			return new WP_Error( 'a11yfy_' . $blocked, self::blocker_messages()[ $blocked ] );
		}

		// Signature backstop for files no browser scan has seen yet (auto mode
		// on FTP/REST uploads). /ByteRange is plaintext by spec; signatures are
		// appended as (or covered by) the last incremental update, so a tail
		// window is a reliable, cheap detector. Once a client scan exists its
		// object-level verdict wins — the byte heuristic stays out of the way.
		if (
			! is_array( get_post_meta( $attachment_id, '_a11yfy_scan', true ) )
			&& ! self::signed_allowed( $attachment_id, $hash )
			&& self::is_signed_pdf( $file, $size )
		) {
			return new WP_Error( 'a11yfy_signed', self::blocker_messages()['signed'] );
		}

		// Memory pre-check (§14/7): multipart body is built in memory (~2× file size + headroom).
		$needed = $size * 2.5 + 33554432;
		$limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $limit > 0 && memory_get_usage() + $needed > $limit ) {
			return new WP_Error( 'a11yfy_memory', __( 'Not enough PHP memory to upload this PDF safely. Raise memory_limit or process a smaller file.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// Monthly credit cap.
		$cap = (int) A11yfy_Settings::get( 'monthly_cap' );
		if ( $cap > 0 && self::month_spend() >= $cap ) {
			return new WP_Error( 'a11yfy_monthly_cap', __( 'The monthly credit cap set for this site has been reached.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// Only process non-compliant files (skip = saves the API call itself, §13.5);
		// the server-side `noop` treatment (0 credits) is the backstop.
		// Manual single-file action overrides the scan verdict (the user insisted).
		if ( 'manual' !== $source && self::is_marked_compliant( $attachment_id ) ) {
			return new WP_Error( 'a11yfy_compliant', __( 'This PDF already passed the accessibility pre-check — skipping to avoid unnecessary credit use.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// Hash-gate (§14/16): this exact content was already remediated.
		if ( A11yfy_Map::already_remediated( $attachment_id, $hash ) ) {
			return new WP_Error( 'a11yfy_already_done', __( 'This exact file was already remediated.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		// Duplicate in-flight job.
		if ( A11yfy_Jobs::has_active( $attachment_id ) ) {
			return new WP_Error( 'a11yfy_in_flight', __( 'A remediation job is already running for this PDF.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		return true;
	}

	/**
	 * Trailer-tail heuristic for encrypted PDFs.
	 *
	 * Every encrypted PDF references its encryption dictionary as an indirect
	 * `/Encrypt N M R` entry in the (plaintext) trailer, which lives in the last
	 * kilobytes of the file — including xref-stream and incremental-update
	 * layouts. The specific `N M R` pattern avoids matching stray `/Encrypt`
	 * name tokens in page content.
	 *
	 * @param string $file Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	public static function is_encrypted_pdf( $file, $size ) {
		$window = 32768;
		$offset = ( $size > $window ) ? $size - $window : 0;
		// Offset-windowed local read; WP_Filesystem cannot seek, so it is the
		// wrong tool for a tail window on a potentially huge file.
		$tail = @file_get_contents( $file, false, null, $offset, $window ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $tail ) {
			return false; // Unreadable → let the later stages surface the real error.
		}
		return 1 === preg_match( '/\/Encrypt\s+\d+\s+\d+\s+R\b/', $tail );
	}

	/**
	 * Tail heuristic for digitally signed PDFs: a signature value dictionary
	 * must carry a plaintext `/ByteRange [...]` entry (ISO 32000-1 §12.8.1),
	 * and signing appends it in the final incremental update — the file tail.
	 * Backstop only; the authoritative signal is the client-scan blocker meta.
	 *
	 * @param string $file Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	public static function is_signed_pdf( $file, $size ) {
		$window = 131072; // 128 KB: /Contents padding pushes /ByteRange further up than /Encrypt.
		$offset = ( $size > $window ) ? $size - $window : 0;
		$tail   = @file_get_contents( $file, false, null, $offset, $window ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $tail ) {
			return false;
		}
		return 1 === preg_match( '/\/ByteRange\s*\[/', $tail );
	}

	/**
	 * Did the user explicitly confirm remediating this signed PDF (this exact
	 * content)? Set by the manual Fix confirm (AJAX); a changed file (new hash)
	 * or a successful remediation invalidates it automatically.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $hash          sha256 of the current file content.
	 * @return bool
	 */
	public static function signed_allowed( $attachment_id, $hash ) {
		return '' !== (string) $hash
			&& get_post_meta( $attachment_id, self::SIGNED_OK_META, true ) === $hash;
	}

	/**
	 * Client-scan verdict says compliant? Conservative: only an explicit green counts.
	 */
	public static function is_marked_compliant( $attachment_id ) {
		$scan = get_post_meta( $attachment_id, '_a11yfy_scan', true );
		return is_array( $scan ) && ! empty( $scan['compliant'] );
	}

	// ── Monthly spend ledger (option-based, per calendar month) ────────────
	//
	// One plain-integer option per period (a11yfy_monthly_spend_YYYY-MM) so the
	// increment can be a single SQL UPDATE — the old get_option→update_option
	// read-modify-write lost concurrent settles (two finalize workers racing).

	private static function spend_option_name( $ym ) {
		return self::SPEND_OPTION . '_' . $ym;
	}

	public static function month_spend() {
		$ym  = gmdate( 'Y-m' );
		$val = get_option( self::spend_option_name( $ym ), null );
		if ( null !== $val ) {
			return (int) $val;
		}
		// Legacy (≤0.2.4) single-option array format — migrated on next add_spend().
		$row = get_option( self::SPEND_OPTION, array() );
		return ( is_array( $row ) && isset( $row['period'] ) && $row['period'] === $ym ) ? (int) $row['credits'] : 0;
	}

	public static function add_spend( $credits ) {
		global $wpdb;

		$credits = max( 0, (int) $credits );
		if ( ! $credits ) {
			return;
		}

		$ym   = gmdate( 'Y-m' );
		$name = self::spend_option_name( $ym );

		// Seed the period row once (add_option = INSERT, the option_name unique
		// key makes a concurrent seeder lose harmlessly). Carries over the
		// legacy single-option value, then retires it and last month's row.
		if ( false === get_option( $name, false ) ) {
			$legacy = get_option( self::SPEND_OPTION, array() );
			$seed   = ( is_array( $legacy ) && isset( $legacy['period'] ) && $legacy['period'] === $ym ) ? (int) $legacy['credits'] : 0;
			add_option( $name, $seed, '', false );
			delete_option( self::SPEND_OPTION );
			delete_option( self::spend_option_name( gmdate( 'Y-m', strtotime( $ym . '-01 UTC' ) - DAY_IN_SECONDS ) ) );
		}

		// Atomic increment — never read-modify-write here (cap-race, §6).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomicity is the point; update_option() would race.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + %d WHERE option_name = %s",
				$credits,
				$name
			)
		);
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Credit estimate for a set of attachments (§14/8): the per-file
	 * A11yfy_Admin::credit_estimate() ranges summed up, so the Fix all label
	 * always agrees with the per-row labels. No estimator endpoint.
	 *
	 * @param int[] $attachment_ids IDs.
	 * @return array { min: int, max: int } Estimated credits.
	 */
	public static function estimate( array $attachment_ids ) {
		$min = 0;
		$max = 0;
		foreach ( $attachment_ids as $id ) {
			$range = A11yfy_Admin::credit_estimate( $id );
			if ( ! $range ) {
				// No usable client scan yet — assume the 3 cr/page treatment
				// on the stored page count (5 pages when even that is unknown).
				$scan  = get_post_meta( $id, '_a11yfy_scan', true );
				$pages = ( is_array( $scan ) && ! empty( $scan['pages'] ) ) ? (int) $scan['pages'] : 5;
				$range = array(
					'min' => $pages * 3,
					'max' => $pages * 3,
				);
			}
			$min += $range['min'];
			$max += $range['max'];
		}
		return array(
			'min' => $min,
			'max' => $max,
		);
	}
}
