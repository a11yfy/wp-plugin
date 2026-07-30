<?php
/**
 * Image/PDF-optimizer conflict protection (§13.6 addendum, 2026-07-07 field
 * finding): ShortPixel's PDF optimization (ON by default) rewrites PDFs and
 * strips the entire accessibility layer (StructTreeRoot, MarkInfo, /Lang,
 * XMP pdfuaid) — destroying paid remediation output after the fact.
 *
 * Four layers:
 *  1. Per-file prevention — ShortPixel honors the `_shortpixel_prevent_optimize`
 *     post meta (isOptimizePrevented(), reason shown in its UI); EWWW exposes
 *     the `ewww_image_optimizer_bypass` filter (called on every code path).
 *  2. Visibility — Site Health test + dashboard warning when an active
 *     optimizer has PDF optimization enabled (Imagify always optimizes PDFs
 *     and has no reliable per-file exclusion → warning only).
 *  3. Tamper detection — remediated_hash vs. the bytes on disk (dashboard).
 *  4. Credit-saving copy — the remediated PDF is also backed up at apply time,
 *     so a destroyed file is re-appliable for 0 credits (A11yfy_Replacer).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Optimizer_Guard {

	/** Post meta ShortPixel checks in isOptimizePrevented() — any non-empty value blocks processing. */
	const SHORTPIXEL_PREVENT_META = '_shortpixel_prevent_optimize';

	/** Marks the prevent-meta values we own (never clear someone else's reason). */
	const PREVENT_PREFIX = 'a11yfy:';

	public static function init() {
		// Per-file exclusion for EWWW — fires on all its paths (upload, bulk, background, folder scan).
		add_filter( 'ewww_image_optimizer_bypass', array( __CLASS__, 'ewww_bypass' ), 10, 2 );

		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
	}

	// ── Layer 1: per-file prevention ───────────────────────────────────────

	/**
	 * Mark a remediated attachment as off-limits for optimizers.
	 * Harmless no-op meta when ShortPixel is absent; cheap and idempotent.
	 * The reason is deliberately NOT translated: it is baked into the database
	 * at write time (it would freeze in whatever locale the admin ran under),
	 * so a stable English string is stored instead.
	 */
	public static function protect( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, self::SHORTPIXEL_PREVENT_META, true );
		if ( '' !== $existing && 0 !== strpos( (string) $existing, self::PREVENT_PREFIX ) ) {
			return; // ShortPixel's own fatal-error reason — leave it alone.
		}
		update_post_meta(
			$attachment_id,
			self::SHORTPIXEL_PREVENT_META,
			self::PREVENT_PREFIX . ' Accessible PDF (a11yfy) — optimization would strip the accessibility tags.'
		);
	}

	/**
	 * Remove our prevent flag (restore flow). Only clears values we wrote.
	 */
	public static function unprotect( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, self::SHORTPIXEL_PREVENT_META, true );
		if ( '' !== $existing && 0 === strpos( (string) $existing, self::PREVENT_PREFIX ) ) {
			delete_post_meta( $attachment_id, self::SHORTPIXEL_PREVENT_META );
		}
	}

	/**
	 * EWWW per-file bypass: skip active remediated files and everything in
	 * our backup directory (EWWW folder-scanning could reach it).
	 *
	 * @param bool   $skip Current verdict.
	 * @param string $path Absolute file path EWWW is about to optimize.
	 * @return bool
	 */
	public static function ewww_bypass( $skip, $path ) {
		if ( $skip || ! is_string( $path ) || '' === $path ) {
			return $skip;
		}
		$normalized = wp_normalize_path( $path );
		if ( 0 === strpos( $normalized, wp_normalize_path( A11yfy_Install::backup_dir() ) ) ) {
			return true;
		}
		return isset( self::protected_paths()[ $normalized ] ) ? true : $skip;
	}

	/**
	 * Active remediated file paths, keyed by normalized path (request-cached).
	 * Covers both modes: inplace (= the attachment file) and conservative
	 * (= the -accessible.pdf sibling).
	 *
	 * @return array<string,true>
	 */
	protected static function protected_paths() {
		static $paths = null;
		if ( null !== $paths ) {
			return $paths;
		}
		global $wpdb;
		$paths = array();
		$rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
			$wpdb->prepare(
				"SELECT remediated_path FROM %i WHERE status = 'active' AND remediated_path IS NOT NULL",
				A11yfy_Map::table()
			)
		);
		foreach ( $rows as $row_path ) {
			$paths[ wp_normalize_path( $row_path ) ] = true;
		}
		return $paths;
	}

	/**
	 * One-time backfill (schema upgrade): flag every already-remediated
	 * attachment, and — when the on-disk bytes are still pristine — save the
	 * credit-protecting copy of the remediated PDF that older versions of the
	 * plugin did not keep.
	 */
	public static function backfill() {
		foreach ( A11yfy_Map::all() as $row ) {
			if ( 'active' !== $row['status'] ) {
				continue;
			}
			self::protect( (int) $row['attachment_id'] );

			if ( empty( $row['remediated_backup_path'] ) && ! empty( $row['remediated_path'] )
				&& ! empty( $row['remediated_hash'] ) && file_exists( $row['remediated_path'] )
				&& hash_file( 'sha256', $row['remediated_path'] ) === $row['remediated_hash'] ) {
				$backup = A11yfy_Replacer::backup_remediated( (int) $row['attachment_id'], $row['remediated_path'] );
				if ( $backup ) {
					A11yfy_Map::upsert( (int) $row['attachment_id'], array( 'remediated_backup_path' => $backup ) );
				}
			}
		}
	}

	// ── Layer 2: detection + visibility ────────────────────────────────────

	/**
	 * Active optimizer plugins that will rewrite (and de-tag) PDFs.
	 *
	 * @return array[] { id, name, pdf_enabled, can_disable, per_file_guard }
	 */
	public static function optimizer_warnings() {
		$warnings = array();

		if ( function_exists( 'wpSPIO' ) ) {
			$pdf_on = true; // ShortPixel default is ON.
			try {
				$pdf_on = (bool) \wpSPIO()->settings()->optimizePdfs;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- foreign plugin internals; keep the safe default.
			}
			if ( $pdf_on ) {
				$warnings[] = array(
					'id'             => 'shortpixel',
					'name'           => 'ShortPixel Image Optimizer',
					'can_disable'    => true,
					'per_file_guard' => true, // prevent-meta honored.
				);
			}
		}

		if ( defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) && function_exists( 'ewww_image_optimizer_get_option' )
			&& (int) ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) > 0 ) {
			$warnings[] = array(
				'id'             => 'ewww',
				'name'           => 'EWWW Image Optimizer',
				'can_disable'    => false,
				'per_file_guard' => true, // bypass filter honored.
			);
		}

		if ( defined( 'IMAGIFY_VERSION' ) ) {
			$warnings[] = array(
				'id'             => 'imagify',
				'name'           => 'Imagify',
				'can_disable'    => false,
				'per_file_guard' => false, // no reliable per-file exclusion (bulk ignores the helper).
			);
		}

		return $warnings;
	}

	/**
	 * Turn off ShortPixel's PDF optimization (explicit user action from the
	 * dashboard banner — never silent).
	 *
	 * @return bool Success.
	 */
	public static function disable_shortpixel_pdf() {
		// Only through ShortPixel's own settings API — we never write another
		// plugin's raw option directly (and there is nothing to disable when
		// ShortPixel is not active anyway).
		if ( function_exists( 'wpSPIO' ) ) {
			try {
				// The settings model persists itself on shutdown.
				\wpSPIO()->settings()->optimizePdfs = false;
				return true;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- no fallback: never touch another plugin's raw option.
			}
		}
		return false;
	}

	public static function site_health_tests( $tests ) {
		$tests['direct']['a11yfy_pdf_optimizers'] = array(
			'label' => __( 'PDF optimization conflicts (a11yfy)', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	public static function site_health_result() {
		$result = array(
			'label'       => __( 'No image optimizer is rewriting PDF files', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Accessibility', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No active plugin was found that compresses PDF files. PDF compression rewrites the file and strips its accessibility tags.', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>',
			'test'        => 'a11yfy_pdf_optimizers',
		);

		$warnings = self::optimizer_warnings();
		if ( ! $warnings ) {
			return $result;
		}

		$names   = wp_list_pluck( $warnings, 'name' );
		$at_risk = A11yfy_Map::counts()['remediated'] > 0;

		$result['status'] = $at_risk ? 'critical' : 'recommended';
		$result['label']  = sprintf(
			/* translators: %s: comma-separated plugin names. */
			__( 'PDF optimization is enabled in %s — it strips PDF accessibility tags', 'a11yfy-pdf-accessibility-checker-fixer' ),
			implode( ', ', $names )
		);
		$result['description'] = '<p>' . esc_html__( 'Compressing a PDF rewrites the file and removes its accessibility structure (tags, language, PDF/UA metadata) — including PDFs already remediated with a11yfy credits. Disable PDF optimization in the plugin settings; a11yfy also protects remediated files individually where the optimizer supports it.', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>';
		$result['actions']     = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=a11yfy' ) ),
			esc_html__( 'Review on the a11yfy Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' )
		);

		return $result;
	}

	// ── Layer 3: tamper detection ──────────────────────────────────────────

	/**
	 * Have the remediated bytes been rewritten by something else (optimizer,
	 * FTP, …)? Request-cached per attachment — hash_file is not free.
	 *
	 * @param array $map_row Active map row.
	 * @return bool
	 */
	public static function is_tampered( array $map_row ) {
		if ( 'active' !== $map_row['status'] || empty( $map_row['remediated_hash'] ) || empty( $map_row['remediated_path'] ) ) {
			return false;
		}
		static $cache = array();
		$id           = (int) $map_row['attachment_id'];
		if ( ! array_key_exists( $id, $cache ) ) {
			$cache[ $id ] = file_exists( $map_row['remediated_path'] )
				&& hash_file( 'sha256', $map_row['remediated_path'] ) !== $map_row['remediated_hash'];
		}
		return $cache[ $id ];
	}
}
