<?php
/**
 * Admin UI: menu, dashboard/settings pages, Media Library column,
 * bulk action, notices. All upsell surfaces are dismissible and live on the
 * plugin's own pages (wp.org Guideline 11).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Admin {

	/** Must match the bundled JS engine version — stale scans get re-run. */
	// MUST match js/src/version.js — scan_batch() compares stored verdicts
	// against this constant, so a drift re-scans the whole library on every
	// admin page load (tests/phpunit/test-engine-version.php gates this).
	const ENGINE_VERSION = '0.8.0';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_a11yfy_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_a11yfy_save_onboarding', array( __CLASS__, 'save_onboarding' ) );
		add_action( 'admin_post_a11yfy_dismiss_connect_notice', array( __CLASS__, 'dismiss_connect_notice' ) );
		add_action( 'admin_post_a11yfy_download_certificate', array( __CLASS__, 'download_certificate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( A11YFY_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );

		// Site Health checks (§13.2) — proactive support-load reduction.
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );

		// Media Library integration.
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page(
			__( 'a11yfy — PDF Accessibility', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'a11yfy',
			'edit_posts',
			'a11yfy',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-universal-access-alt',
			81
		);
		// Page title (browser tab) gets the "a11yfy — " prefix; the menu label stays short.
		add_submenu_page( 'a11yfy', 'a11yfy — ' . __( 'Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ), __( 'Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ), 'edit_posts', 'a11yfy', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( 'a11yfy', 'a11yfy — ' . __( 'Settings', 'a11yfy-pdf-accessibility-checker-fixer' ), __( 'Settings', 'a11yfy-pdf-accessibility-checker-fixer' ), 'manage_options', 'a11yfy-settings', array( __CLASS__, 'render_settings' ) );
		// Per-document detail page (§7 extension), reachable only via "Details"
		// links (admin.php?page=a11yfy-document&id=N). The non-menu parent slug
		// keeps it out of every menu; remove_submenu_page() would instead break
		// user_can_access_admin_page() for the page.
		add_submenu_page( 'options.php', 'a11yfy — ' . __( 'Document details', 'a11yfy-pdf-accessibility-checker-fixer' ), __( 'Document details', 'a11yfy-pdf-accessibility-checker-fixer' ), 'edit_posts', 'a11yfy-document', array( __CLASS__, 'render_document' ) );
	}

	public static function assets( $hook ) {
		if ( 'admin_page_a11yfy-document' === $hook ) {
			self::document_assets();
			return;
		}
		if ( ! in_array( $hook, array( 'toplevel_page_a11yfy', 'upload.php', 'media-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'a11yfy-admin', A11YFY_PLUGIN_URL . 'assets/css/admin.css', array(), A11YFY_VERSION );
		wp_enqueue_script( 'a11yfy-hash', A11YFY_PLUGIN_URL . 'assets/js/hash.js', array(), A11YFY_VERSION, true );
		wp_enqueue_script( 'a11yfy-admin', A11YFY_PLUGIN_URL . 'assets/js/admin.js', array( 'a11yfy-hash' ), A11YFY_VERSION, true );
		wp_localize_script(
			'a11yfy-admin',
			'a11yfyAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'a11yfy_ajax' ),
				'workerUrl'     => A11YFY_PLUGIN_URL . 'assets/js/dist/a11yfy-engine.worker.js',
				// Base URL — the client appends '&id=<attachment>'.
				'docUrl'        => admin_url( 'admin.php?page=a11yfy-document' ),
				'engineVersion' => self::ENGINE_VERSION,
				'canRemediate'  => current_user_can( 'manage_options' ) && A11yfy_Settings::is_connected(),
				// Background deep-scan without a button press (dashboard/media
				// visits + right after browser uploads). Default on.
				'autoScan'      => (bool) apply_filters( 'a11yfy_auto_scan', true ),
				'i18n'          => array(
					/* translators: 1: current position, 2: total number of PDFs. */
					'scanning'           => __( 'Scanning… %1$d / %2$d', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: number of PDFs checked. */
					'scanDone'           => __( 'Scan finished: %d PDFs checked.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %s: file name. */
					'scanFailed'         => __( 'Scan failed for %s', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'noPdfs'             => __( 'No PDFs need scanning — everything is up to date.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: number of PDFs queued. */
					'queued'             => __( 'Remediation queued for %d PDFs.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'fix'                => __( 'Fix', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'fixLocked'          => __( 'Connect your a11yfy account to enable PDF remediation.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'signedConfirm'      => __( 'This document is digitally signed. Remediation modifies the file, so the signature will no longer be valid. Do you want to continue?', 'a11yfy-pdf-accessibility-checker-fixer' ),
					// User-facing remediation types — the API exposes two only.
					'treatmentTechnical' => __( 'technical repair', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'treatmentFull'      => __( 'full repair', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'restore'            => __( 'Restore', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'details'            => __( 'Details', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'reapply'            => __( 'Re-apply fix (free)', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'tampered'           => __( 'Modified by another program', 'a11yfy-pdf-accessibility-checker-fixer' ),
					// Best-effort outcome (server compliant=false): free, downloadable.
					'bestEffort'         => __( 'best effort', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'bestEffortNote'     => __( 'The document received the best possible repair but did not fully meet the PDF/UA-1 standard. The output is available for free download.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'spDisabled'         => __( 'ShortPixel PDF optimization is now off.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				),
			)
		);
	}

	/**
	 * Assets for the per-document detail page: the pdf.js viewer bundle (main
	 * thread, canvas preview) + the page orchestrator. The engine still runs
	 * in the dedicated Web Worker — the live re-analysis never blocks the UI.
	 */
	protected static function document_assets() {
		wp_enqueue_style( 'a11yfy-admin', A11YFY_PLUGIN_URL . 'assets/css/admin.css', array(), A11YFY_VERSION );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		// Attachment-level authorization: the page-level capability alone would
		// let any editor load metadata for PDFs they cannot edit.
		if ( ! $id || 'application/pdf' !== get_post_mime_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return; // The render callback shows the error state (styled, no JS).
		}

		wp_enqueue_script( 'a11yfy-viewer', A11YFY_PLUGIN_URL . 'assets/js/dist/a11yfy-viewer.js', array(), A11YFY_VERSION, true );
		wp_enqueue_script( 'a11yfy-hash', A11YFY_PLUGIN_URL . 'assets/js/hash.js', array(), A11YFY_VERSION, true );
		wp_enqueue_script( 'a11yfy-document', A11YFY_PLUGIN_URL . 'assets/js/document.js', array( 'a11yfy-viewer', 'a11yfy-hash' ), A11YFY_VERSION, true );

		$file = get_attached_file( $id );
		$map  = A11yfy_Map::for_attachment( $id );
		wp_localize_script(
			'a11yfy-document',
			'a11yfyDoc',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'a11yfy_ajax' ),
				'workerUrl'       => A11YFY_PLUGIN_URL . 'assets/js/dist/a11yfy-engine.worker.js',
				'hasBackup'       => (bool) ( $map && 'active' === $map['status'] && ! empty( $map['backup_path'] ) && file_exists( $map['backup_path'] ) ),
				'attachment'      => array(
					'id'       => $id,
					'filename' => $file ? wp_basename( $file ) : get_the_title( $id ),
					'url'      => wp_get_attachment_url( $id ),
				),
				'catalog'         => A11yfy_Scan_Report::catalog(),
				'severityLabels'  => A11yfy_Scan_Report::severity_labels(),
				'categories'      => A11yfy_Scan_Report::categories(),
				'categoryMap'     => A11yfy_Scan_Report::category_map(),
				// Live-scan blocker reasons (password/signature/XFA/portfolio).
				'blockedMessages' => A11yfy_Guardrails::blocker_messages(),
				'i18n'            => array(
					'loading'         => __( 'Analyzing the document…', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'analyzeFailed'   => __( 'The document could not be analyzed.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'signedConfirm'   => __( 'This document is digitally signed. Remediation modifies the file, so the signature will no longer be valid. Do you want to continue?', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: 1: number of failed checks, 2: number of passed checks. */
					'summary'         => __( '%1$d issue types found — %2$d checks passed.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'noIssues'        => __( 'No machine-detectable accessibility issues were found.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: score 0-100. */
					'score'           => __( 'Score: %d / 100', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'riskLabels'      => array(
						'low'      => __( 'Low risk', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'medium'   => __( 'Medium risk', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'high'     => __( 'High risk', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'critical' => __( 'Not accessible', 'a11yfy-pdf-accessibility-checker-fixer' ),
					),
					'docLevel'        => __( 'Affects the whole document', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: 1: current page, 2: total pages. */
					'pageInfo'        => __( 'Page %1$d of %2$d', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: total number of pages. */
					'pages'           => __( '%d pages', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'techDetails'     => __( 'Technical details', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: number of findings. */
					'pageFindings'    => __( '%d findings on this page', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'pageFindingsOne' => __( '1 finding on this page', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: page number. */
					'goToPage'        => __( 'Go to page %d', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: page number. */
					'pageChip'        => __( 'Page %d', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'prevPage'        => __( 'Previous page', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'nextPage'        => __( 'Next page', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'zoomIn'          => __( 'Zoom in', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'zoomOut'         => __( 'Zoom out', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: how many times the issue occurs. */
					'issueCount'      => __( '%d×', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'queued'          => __( 'Remediation queued — track it on the Dashboard.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'error'           => __( 'Something went wrong — please try again.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'previewFailed'   => __( 'The preview could not be rendered (the analysis still works).', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: percentage of untagged text. */
					'coverage'        => __( '~%d%% of the text is untagged — screen readers skip it or read it out of order.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					'originalNote'    => __( 'Showing the original file (before remediation) — this analysis is not saved.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: %d: estimated number of credits. */
					'estimateOne'     => __( 'Estimated cost: ~%d credits', 'a11yfy-pdf-accessibility-checker-fixer' ),
					/* translators: 1: minimum credits, 2: maximum credits. */
					'estimateRange'   => __( 'Estimated cost: %1$d–%2$d credits', 'a11yfy-pdf-accessibility-checker-fixer' ),
				),
			)
		);
	}

	/**
	 * Plugins-list row links: Settings always; one-click Connect while the
	 * site has no a11yfy account linked yet.
	 */
	public static function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$extra = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=a11yfy' ) ) . '">' . esc_html__( 'Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</a>',
		);
		if ( ! A11yfy_Settings::is_connected() ) {
			$extra[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=a11yfy_connect_start' ), 'a11yfy_connect' ) ) . '">'
				. esc_html__( 'Connect account', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</a>';
		}
		return array_merge( $extra, $links );
	}

	// ── Pages ──────────────────────────────────────────────────────────────

	/**
	 * Top navigation between the two admin pages (Dashboard ⇄ Settings) so
	 * users don't have to hunt in the sidebar. Hidden for roles that cannot
	 * access Settings — a one-tab strip is noise.
	 *
	 * @param string $current 'dashboard' or 'settings'.
	 */
	public static function page_tabs( $current ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'dashboard' => array( admin_url( 'admin.php?page=a11yfy' ), __( 'Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
			'settings'  => array( admin_url( 'admin.php?page=a11yfy-settings' ), __( 'Settings', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
		);
		echo '<nav class="nav-tab-wrapper a11yfy-tabs" aria-label="' . esc_attr__( 'a11yfy pages', 'a11yfy-pdf-accessibility-checker-fixer' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $tab[0] ),
				$key === $current ? ' nav-tab-active' : '',
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $tab[1] )
			);
		}
		echo '</nav>';
	}

	public static function render_dashboard() {
		require A11YFY_PLUGIN_DIR . 'includes/admin/views/dashboard.php';
	}

	public static function render_settings() {
		require A11YFY_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}

	public static function render_document() {
		require A11YFY_PLUGIN_DIR . 'includes/admin/views/document.php';
	}

	/**
	 * Settings save (admin_post) — manage_options + nonce.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change a11yfy settings.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		check_admin_referer( 'a11yfy_settings' );

		$notice = 'saved';

		// API key: empty field keeps the stored key; "disconnect" clears it.
		if ( ! empty( $_POST['a11yfy_disconnect'] ) ) {
			A11yfy_Settings::delete_api_key();
			delete_option( A11yfy_Settings::WEBHOOK_SECRET_OPTION );
			A11yfy_Settings::update(
				array(
					'webhook_mode' => false,
					'org_id'       => '',
					'org_name'     => '',
				)
			);
			delete_transient( 'a11yfy_balance' );
			A11yfy_Settings::update( array( 'onboarded' => false ) );
			$notice = 'disconnected';
		} elseif ( ! empty( $_POST['a11yfy_api_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['a11yfy_api_key'] ) );
			if ( ! preg_match( '/^ak_(live|test)_[0-9a-f]{16,64}$/', $key ) ) {
				$notice = 'bad_key_format';
			} else {
				$client  = new A11yfy_ApiClient( $key );
				$balance = $client->balance();
				if ( is_wp_error( $balance ) ) {
					$notice = 'key_rejected';
				} elseif ( ! A11yfy_Settings::set_api_key( $key ) ) {
					// Encryption/storage failed (e.g. sodium unavailable) — the
					// key was NOT saved; do not pretend to be connected.
					$notice = 'key_store_failed';
				} else {
					set_transient( 'a11yfy_balance', $balance, 15 * MINUTE_IN_SECONDS );
					$notice = 'connected';
					// The settings form below the key field IS the mode/cap
					// decision — no separate wizard for the manual-key path.
					// A pasted key carries no org identity — clear any stale one.
					A11yfy_Settings::update(
						array(
							'onboarded' => true,
							'org_id'    => '',
							'org_name'  => '',
						)
					);
				}
			}
		}

		$accent = isset( $_POST['a11yfy_visitor_accent_color'] )
			? sanitize_hex_color( wp_unslash( $_POST['a11yfy_visitor_accent_color'] ) )
			: '';

		A11yfy_Settings::update(
			array(
				'mode'                  => self::sanitize_mode( isset( $_POST['a11yfy_mode'] ) ? wp_unslash( $_POST['a11yfy_mode'] ) : '' ),
				'monthly_cap'           => isset( $_POST['a11yfy_monthly_cap'] ) ? max( 0, (int) $_POST['a11yfy_monthly_cap'] ) : 0,
				'low_credit_threshold'  => isset( $_POST['a11yfy_low_credit'] ) ? max( 0, (int) $_POST['a11yfy_low_credit'] ) : 100,
				'save_strategy'         => ( isset( $_POST['a11yfy_save_strategy'] ) && 'conservative' === $_POST['a11yfy_save_strategy'] ) ? 'conservative' : 'inplace',
				'notify_email'          => isset( $_POST['a11yfy_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['a11yfy_notify_email'] ) ) : '',
				'delete_data'           => ( isset( $_POST['a11yfy_delete_data'] ) && 'restore' === $_POST['a11yfy_delete_data'] ) ? 'restore' : 'keep',
				// Visitor on-demand texts: empty = localized default at render time.
				'visitor_modal_title'   => self::visitor_text_field( 'a11yfy_visitor_modal_title' ),
				'visitor_modal_body'    => self::visitor_textarea_field( 'a11yfy_visitor_modal_body' ),
				'visitor_btn_open'      => self::visitor_text_field( 'a11yfy_visitor_btn_open' ),
				'visitor_btn_request'   => self::visitor_text_field( 'a11yfy_visitor_btn_request' ),
				'visitor_request_info'  => self::visitor_text_field( 'a11yfy_visitor_request_info' ),
				'visitor_email_label'   => self::visitor_text_field( 'a11yfy_visitor_email_label' ),
				'visitor_btn_submit'    => self::visitor_text_field( 'a11yfy_visitor_btn_submit' ),
				'visitor_success_msg'   => self::visitor_text_field( 'a11yfy_visitor_success_msg' ),
				'visitor_privacy_note'  => self::visitor_text_field( 'a11yfy_visitor_privacy_note' ),
				'visitor_theme_style'   => ! empty( $_POST['a11yfy_visitor_theme_style'] ),
				'visitor_accent_color'  => $accent ? $accent : '#1d4ed8',
				'visitor_email_subject' => self::visitor_text_field( 'a11yfy_visitor_email_subject' ),
				'visitor_email_body'    => self::visitor_textarea_field( 'a11yfy_visitor_email_body' ),
			)
		);

		wp_safe_redirect( add_query_arg( 'a11yfy_notice', $notice, admin_url( 'admin.php?page=a11yfy-settings' ) ) );
		exit;
	}

	/**
	 * Mode whitelist (K2): a third radio value must not silently degrade to
	 * 'manual' the way the old binary check did.
	 *
	 * @param string $raw Posted mode.
	 * @return string auto|manual|on_demand
	 */
	private static function sanitize_mode( $raw ) {
		$mode = sanitize_key( $raw );
		return in_array( $mode, array( 'auto', 'manual', 'on_demand' ), true ) ? $mode : 'manual';
	}

	private static function visitor_text_field( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- called from save_settings() after check_admin_referer().
	}

	private static function visitor_textarea_field( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- called from save_settings() after check_admin_referer().
	}

	/**
	 * Onboarding wizard save (§13.5) — one decision: mode + monthly cap.
	 */
	public static function save_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change a11yfy settings.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		check_admin_referer( 'a11yfy_onboarding' );

		A11yfy_Settings::update(
			array(
				'mode'        => self::sanitize_mode( isset( $_POST['a11yfy_mode'] ) ? wp_unslash( $_POST['a11yfy_mode'] ) : '' ),
				'monthly_cap' => isset( $_POST['a11yfy_monthly_cap'] ) ? max( 0, (int) $_POST['a11yfy_monthly_cap'] ) : 0,
				'onboarded'   => true,
			)
		);

		wp_safe_redirect( add_query_arg( 'a11yfy_notice', 'onboarded', admin_url( 'admin.php?page=a11yfy' ) ) );
		exit;
	}

	/**
	 * Persistently hide the "connect your account" dashboard notice (per user).
	 */
	public static function dismiss_connect_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change a11yfy settings.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		check_admin_referer( 'a11yfy_dismiss_connect' );
		update_user_meta( get_current_user_id(), 'a11yfy_connect_notice_dismissed', 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=a11yfy' ) );
		exit;
	}

	// ── Notices ────────────────────────────────────────────────────────────

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Settings/dashboard feedback (own pages only).
		$own_pages = array( 'a11yfy_page_a11yfy-settings', 'toplevel_page_a11yfy' );
		if ( in_array( $screen->id, $own_pages, true ) && isset( $_GET['a11yfy_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$map  = array(
				'saved'                  => array( 'success', __( 'Settings saved.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'connected'              => array( 'success', __( 'Connected to a11yfy — the API key works.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'connected_webhook'      => array( 'success', __( 'Connected to a11yfy — instant (webhook) status updates are enabled.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'disconnected'           => array( 'info', __( 'Disconnected. The stored API key was removed.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'bad_key_format'         => array( 'error', __( 'That does not look like an a11yfy API key (expected ak_live_… or ak_test_…). Other settings were saved.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'key_rejected'           => array( 'error', __( 'a11yfy rejected the API key. Check it on a11yfy.com under Account → API keys. Other settings were saved.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'key_store_failed'       => array( 'error', __( 'The API key could not be stored securely on this server (encryption unavailable). The key was NOT saved — ask your host about the PHP sodium extension. Other settings were saved.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'connect_denied'         => array( 'info', __( 'Connection cancelled on a11yfy.com.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'connect_failed'         => array( 'error', __( 'Connecting to a11yfy failed — please try again.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'connect_state_mismatch' => array( 'error', __( 'The connect response could not be verified (expired or tampered request). Please try again.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
				'onboarded'              => array( 'success', __( 'All set — a11yfy is ready to work.', 'a11yfy-pdf-accessibility-checker-fixer' ) ),
			);
			$code = sanitize_key( wp_unslash( $_GET['a11yfy_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $map[ $code ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $code ][0] ), esc_html( $map[ $code ][1] ) );
			}
		}

		// Bulk action feedback on the media list.
		if ( 'upload' === $screen->id && isset( $_GET['a11yfy_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of queued PDFs. */
						__( 'a11yfy: %d PDFs queued for remediation. Track progress on the a11yfy Dashboard.', 'a11yfy-pdf-accessibility-checker-fixer' ),
						(int) $_GET['a11yfy_queued'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				)
			);
		}

		// Not-connected reminder — remediation is off until an account is
		// linked. Shown on every screen where the lock is visible (plugin
		// pages, Media Library, Plugins), persistently dismissible (G11).
		$a11yfy_connect_screens = array(
			'toplevel_page_a11yfy',
			'a11yfy_page_a11yfy-settings',
			'admin_page_a11yfy-document',
			'upload',
			'plugins',
		);
		if ( in_array( $screen->id, $a11yfy_connect_screens, true )
			&& current_user_can( 'manage_options' )
			&& ! A11yfy_Settings::is_connected()
			&& ! get_user_meta( get_current_user_id(), 'a11yfy_connect_notice_dismissed', true ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>a11yfy:</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
				esc_html__( 'PDF remediation is currently unavailable. Connect your a11yfy account to enable it — the free scan keeps working either way.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=a11yfy_connect_start' ), 'a11yfy_connect' ) ),
				esc_html__( 'Connect account', 'a11yfy-pdf-accessibility-checker-fixer' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=a11yfy_dismiss_connect_notice' ), 'a11yfy_dismiss_connect' ) ),
				esc_html__( 'Hide this notice', 'a11yfy-pdf-accessibility-checker-fixer' )
			);
		}

		// Low-credit warning — plugin pages + media library, dismissible (G11).
		$relevant = in_array( $screen->id, array( 'toplevel_page_a11yfy', 'a11yfy_page_a11yfy-settings', 'upload' ), true );
		if ( $relevant && current_user_can( 'manage_options' ) ) {
			$low = get_transient( 'a11yfy_low_credit' );
			if ( is_array( $low ) ) {
				if ( ! empty( $low['delegated'] ) ) {
					// Delegated allowance: no top-up link — the parent org controls it.
					$org = isset( $low['billing_org_name'] ) && '' !== (string) $low['billing_org_name']
						? (string) $low['billing_org_name']
						: __( 'your partner organization', 'a11yfy-pdf-accessibility-checker-fixer' );
					printf(
						'<div class="notice notice-warning is-dismissible"><p><strong>a11yfy:</strong> %s</p></div>',
						esc_html(
							isset( $low['available'] )
								/* translators: 1: remaining delegated credits, 2: parent organization name. */
								? sprintf( __( 'Your delegated credit allowance is low (%1$d credits left). It is provided by %2$s — ask them to raise your limit.', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $low['available'], $org )
								/* translators: %s: parent organization name. */
								: sprintf( __( 'Your last remediation was blocked: the delegated credit limit set by %s is exhausted.', 'a11yfy-pdf-accessibility-checker-fixer' ), $org )
						)
					);
				} else {
					printf(
						'<div class="notice notice-warning is-dismissible"><p><strong>a11yfy:</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
						esc_html(
							isset( $low['available'] )
								/* translators: %d: remaining credits. */
								? sprintf( __( 'Your credit balance is low (%d credits left). Remediation will stop at zero.', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $low['available'] )
								: __( 'Your last remediation was blocked: not enough credits.', 'a11yfy-pdf-accessibility-checker-fixer' )
						),
						'https://a11yfy.com',
						esc_html__( 'Top up on a11yfy.com', 'a11yfy-pdf-accessibility-checker-fixer' )
					);
				}
			}
		}
	}

	// ── Certificate download (SDK-01) ─────────────────────────────────────

	/**
	 * Nonce-protected admin-post URL for the certificate download proxy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function certificate_url( $attachment_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=a11yfy_download_certificate&id=' . (int) $attachment_id ),
			'a11yfy_certificate_' . (int) $attachment_id
		);
	}

	/**
	 * Certificate download proxy — the /v1 download endpoint is API-key
	 * authenticated, so the browser cannot fetch it directly. Streams the
	 * PDF with the server-side stored key.
	 */
	public static function download_certificate() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this certificate.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		$attachment_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'a11yfy_certificate_' . $attachment_id );
		// Attachment-level authorization, not just the generic edit_posts.
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this certificate.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$cert = get_post_meta( $attachment_id, '_a11yfy_certificate', true );
		if ( ! is_array( $cert ) || empty( $cert['id'] ) ) {
			wp_die( esc_html__( 'This document has no a11yfy certificate yet.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$client = new A11yfy_ApiClient();
		$body   = $client->certificate_download( (string) $cert['id'] );
		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="a11yfy-certificate-' . sanitize_file_name( (string) $cert['id'] ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF stream.
		exit;
	}

	// ── Site Health ────────────────────────────────────────────────────────

	public static function site_health_tests( $tests ) {
		$tests['direct']['a11yfy'] = array(
			'label' => __( 'a11yfy PDF accessibility', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	public static function site_health_result() {
		$result = array(
			'label'       => __( 'a11yfy is set up and working', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Accessibility', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Background queue is available and the a11yfy connection works.', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>',
			'test'        => 'a11yfy',
		);

		if ( ! A11yfy_Queue::available() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'a11yfy background processing is unavailable', 'a11yfy-pdf-accessibility-checker-fixer' );
			$result['description'] = '<p>' . esc_html__( 'Action Scheduler could not be loaded — remediation status updates will be slow (visit-triggered WP-cron fallback).', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>';
			return $result;
		}

		if ( ! A11yfy_Settings::is_connected() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'a11yfy is not connected', 'a11yfy-pdf-accessibility-checker-fixer' );
			$result['description'] = '<p>' . esc_html__( 'The free scan works, but PDF remediation needs an a11yfy API key (a11yfy → Settings).', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>';
			return $result;
		}

		$stats = self::stats();
		if ( $stats['non_compliant'] > 0 ) {
			$result['status'] = 'recommended';
			/* translators: %d: number of non-compliant PDFs. */
			$result['label']       = sprintf( __( '%d PDFs on this site are not accessible', 'a11yfy-pdf-accessibility-checker-fixer' ), $stats['non_compliant'] );
			$result['description'] = '<p>' . esc_html__( 'Open the a11yfy Dashboard to review and fix them.', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</p>';
		}

		return $result;
	}

	// ── Media Library ──────────────────────────────────────────────────────

	public static function media_column( $columns ) {
		$columns['a11yfy'] = __( 'Accessibility', 'a11yfy-pdf-accessibility-checker-fixer' );
		return $columns;
	}

	public static function media_column_content( $column, $attachment_id ) {
		if ( 'a11yfy' !== $column || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return;
		}
		echo wp_kses_post( self::badge_html( $attachment_id ) );
		$estimate = self::fixable_estimate_label( $attachment_id );
		if ( $estimate ) {
			echo '<br /><span class="a11yfy-credit-est">' . esc_html( $estimate ) . '</span>';
		}
	}

	/**
	 * File-level credit estimate from the stored client scan.
	 *
	 * Mirrors the web app's estimateCreditRange() (web/src/lib/config.ts):
	 * born-digital + tagged → pages×1 … pages×3, everything else pages×3.
	 * The real quote is locked server-side at diagnostic time (D-07) — this
	 * is a pre-purchase orientation figure only.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null { min: int, max: int }, or null without a usable scan.
	 */
	public static function credit_estimate( $attachment_id ) {
		$scan = get_post_meta( $attachment_id, '_a11yfy_scan', true );
		if ( ! is_array( $scan ) || 'client' !== ( isset( $scan['origin'] ) ? $scan['origin'] : '' ) ) {
			return null;
		}
		$pages = isset( $scan['pages'] ) ? (int) $scan['pages'] : 0;
		if ( $pages < 1 ) {
			return null;
		}
		if ( empty( $scan['scanned_likely'] ) && ! empty( $scan['tagged'] ) ) {
			return array(
				'min' => $pages,
				'max' => $pages * 3,
			);
		}
		return array(
			'min' => $pages * 3,
			'max' => $pages * 3,
		);
	}

	/**
	 * Localized label for credit_estimate(); '' when no estimate is available.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function credit_estimate_label( $attachment_id ) {
		$estimate = self::credit_estimate( $attachment_id );
		if ( ! $estimate ) {
			return '';
		}
		if ( $estimate['min'] === $estimate['max'] ) {
			/* translators: %d: estimated number of credits. */
			return sprintf( __( 'Estimated cost: ~%d credits', 'a11yfy-pdf-accessibility-checker-fixer' ), $estimate['max'] );
		}
		/* translators: 1: minimum credits, 2: maximum credits. */
		return sprintf( __( 'Estimated cost: %1$d–%2$d credits', 'a11yfy-pdf-accessibility-checker-fixer' ), $estimate['min'], $estimate['max'] );
	}

	/**
	 * Estimate label ONLY for files that currently show a Fix affordance —
	 * remediated/compliant/in-progress/unsendable files would find it noise.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function fixable_estimate_label( $attachment_id ) {
		$map = A11yfy_Map::for_attachment( $attachment_id );
		if ( $map && 'active' === $map['status'] ) {
			return '';
		}
		if ( 'compliant' === get_post_meta( $attachment_id, '_a11yfy_risk', true ) ) {
			return '';
		}
		if ( A11yfy_Jobs::has_active( $attachment_id ) ) {
			return '';
		}
		if ( ! in_array( A11yfy_Guardrails::blocked_code( $attachment_id ), array( '', 'signed' ), true ) ) {
			return '';
		}
		return self::credit_estimate_label( $attachment_id );
	}

	/**
	 * Status badge for one attachment (also reused by AJAX refresh).
	 */
	public static function badge_html( $attachment_id ) {
		$map = A11yfy_Map::for_attachment( $attachment_id );
		if ( $map && 'active' === $map['status'] ) {
			$label = 'noop' === $map['treatment']
				? __( 'Compliant', 'a11yfy-pdf-accessibility-checker-fixer' )
				: __( 'Remediated', 'a11yfy-pdf-accessibility-checker-fixer' );
			$extra = '';
			if ( null !== $map['before_issues'] ) {
				/* translators: %d: number of issues fixed. */
				$extra = ' ' . sprintf( __( '(%d issues fixed)', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $map['before_issues'] );
			}
			return '<span class="a11yfy-badge a11yfy-badge--ok">✓ ' . esc_html( $label . $extra ) . '</span>';
		}

		$job = A11yfy_Jobs::latest_for_attachment( $attachment_id );
		if ( $job && in_array( $job['status'], array( 'queued', 'submitted', 'processing', 'finalizing' ), true ) ) {
			return '<span class="a11yfy-badge a11yfy-badge--busy">⏳ ' . esc_html__( 'Remediation in progress…', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
		}
		if ( $job && in_array( $job['status'], array( 'failed', 'stalled' ), true ) ) {
			return '<span class="a11yfy-badge a11yfy-badge--err" title="' . esc_attr( (string) $job['error_message'] ) . '">✗ '
				. esc_html__( 'Remediation failed', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
		}

		// A user-initiated Fix the guardrail skipped (no job row was created —
		// without this badge the click would look like a silent no-op). Message
		// rendered from the code at display time, in the current admin locale.
		$skip = get_post_meta( $attachment_id, '_a11yfy_last_skip', true );
		if ( is_array( $skip ) && ! empty( $skip['code'] ) ) {
			$message = A11yfy_Guardrails::skip_message( $skip['code'], isset( $skip['message'] ) ? $skip['message'] : '' );
			return '<span class="a11yfy-badge a11yfy-badge--err" title="' . esc_attr( $message ) . '">⚠ '
				. esc_html__( 'Not sent', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span> <span class="a11yfy-badge__reason">' . esc_html( $message ) . '</span>';
		}

		$risk = get_post_meta( $attachment_id, '_a11yfy_risk', true );
		switch ( $risk ) {
			case 'compliant':
				return '<span class="a11yfy-badge a11yfy-badge--ok">✓ ' . esc_html__( 'Passed pre-check', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
			case 'low':
				return '<span class="a11yfy-badge a11yfy-badge--low">' . esc_html__( 'Low risk', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
			case 'medium':
				return '<span class="a11yfy-badge a11yfy-badge--med">' . esc_html__( 'Medium risk', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
			case 'high':
				return '<span class="a11yfy-badge a11yfy-badge--high">' . esc_html__( 'High risk', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
			case 'critical':
				return '<span class="a11yfy-badge a11yfy-badge--high">' . esc_html__( 'Not accessible', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
			default:
				return '<span class="a11yfy-badge">' . esc_html__( 'Not scanned yet', 'a11yfy-pdf-accessibility-checker-fixer' ) . '</span>';
		}
	}

	public static function bulk_actions( $actions ) {
		if ( current_user_can( 'manage_options' ) && A11yfy_Settings::is_connected() ) {
			$actions['a11yfy_remediate'] = __( 'Make accessible with a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' );
		}
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'a11yfy_remediate' !== $action || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}
		$queued = 0;
		foreach ( $ids as $id ) {
			if ( 'application/pdf' === get_post_mime_type( $id ) ) {
				A11yfy_Queue::enqueue_remediation( (int) $id, 'bulk' );
				++$queued;
			}
		}
		return add_query_arg( 'a11yfy_queued', $queued, $redirect );
	}

	public static function row_actions( $actions, $post ) {
		if ( 'application/pdf' !== $post->post_mime_type ) {
			return $actions;
		}
		if ( current_user_can( 'manage_options' ) && A11yfy_Settings::is_connected() ) {
			$map = A11yfy_Map::for_attachment( $post->ID );
			if ( $map && 'active' === $map['status'] && 'inplace' === $map['mode'] && $map['backup_path'] ) {
				$actions['a11yfy_restore'] = sprintf(
					'<a href="#" class="a11yfy-restore" data-id="%d" data-nonce="%s">%s</a>',
					(int) $post->ID,
					esc_attr( wp_create_nonce( 'a11yfy_ajax' ) ),
					esc_html__( 'Restore original (a11yfy)', 'a11yfy-pdf-accessibility-checker-fixer' )
				);
			} elseif (
				! A11yfy_Jobs::has_active( $post->ID )
				// FIRST branch is "already accessible": no fix action for it.
				&& ! A11yfy_Guardrails::is_marked_compliant( $post->ID )
				// Signed PDFs get the action too — the click asks for
				// confirmation (remediation invalidates the signature).
				&& in_array( A11yfy_Guardrails::blocked_code( $post->ID ), array( '', 'signed' ), true )
			) {
				$actions['a11yfy_remediate'] = sprintf(
					'<a href="#" class="a11yfy-remediate" data-id="%d" data-nonce="%s"%s>%s</a>',
					(int) $post->ID,
					esc_attr( wp_create_nonce( 'a11yfy_ajax' ) ),
					'signed' === A11yfy_Guardrails::blocked_code( $post->ID ) ? ' data-signed="1"' : '',
					esc_html__( 'Make accessible (a11yfy)', 'a11yfy-pdf-accessibility-checker-fixer' )
				);
			}
		}
		if ( current_user_can( 'edit_posts' ) && A11yfy_Settings::is_connected() ) {
			$cert = get_post_meta( $post->ID, '_a11yfy_certificate', true );
			if ( is_array( $cert ) && ! empty( $cert['id'] ) ) {
				$actions['a11yfy_certificate'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( self::certificate_url( $post->ID ) ),
					esc_html__( 'Certificate (a11yfy)', 'a11yfy-pdf-accessibility-checker-fixer' )
				);
			}
		}
		return $actions;
	}

	// ── Dashboard data helpers (used by the view) ─────────────────────────

	/**
	 * Counts of PDF attachments by stored risk verdict.
	 *
	 * @return array { total, compliant, low, medium, high, critical, remediated, unscanned }
	 */
	public static function stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'" ); // phpcs:ignore WordPress.DB

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT pm.meta_value AS risk, COUNT(*) AS n
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_a11yfy_risk'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf'
			 GROUP BY pm.meta_value",
			ARRAY_A
		);

		$stats = array(
			'total'      => $total,
			'compliant'  => 0,
			'low'        => 0,
			'medium'     => 0,
			'high'       => 0,
			'critical'   => 0,
			'remediated' => A11yfy_Map::counts()['remediated'],
			'unscanned'  => $total,
		);
		foreach ( $rows as $row ) {
			if ( isset( $stats[ $row['risk'] ] ) ) {
				$stats[ $row['risk'] ] = (int) $row['n'];
				$stats['unscanned']   -= (int) $row['n'];
			}
		}
		$stats['unscanned']     = max( 0, $stats['unscanned'] );
		$stats['non_compliant'] = $stats['medium'] + $stats['high'] + $stats['critical'];

		return $stats;
	}

	/**
	 * Filterable, paged PDF list for the dashboard table (§7). The status
	 * buckets mirror the stats() rows; precedence matches badge_html():
	 * an active remediation-map row beats the stored risk verdict.
	 *
	 * @param string[] $statuses Any of: passed|partial|failing|remediated|unscanned.
	 * @param int      $paged    1-based page.
	 * @param int      $per_page Page size.
	 * @return array { total: int, items: array[] }
	 */
	public static function pdf_list( array $statuses, $paged = 1, $per_page = 20 ) {
		global $wpdb;

		// No chip selected = no filter: show every PDF (§7 UX — selecting a
		// chip narrows the list, the default view is the full library).
		if ( ! $statuses ) {
			$statuses = array( 'passed', 'partial', 'failing', 'remediated', 'unscanned' );
		}

		// The bucket selection travels as 1/0 flags rather than concatenated
		// SQL, so both queries below stay literal strings — no interpolation
		// for a reviewer (or a sniff) to have to trust. MySQL folds the
		// `0 = 1` branches away, so the plan matches the omitted-branch one.
		$want = array(
			'remediated' => 0,
			'failing'    => 0,
			'partial'    => 0,
			'passed'     => 0,
			'unscanned'  => 0,
		);
		foreach ( $statuses as $status ) {
			if ( isset( $want[ $status ] ) ) {
				$want[ $status ] = 1;
			}
		}
		if ( ! array_filter( $want ) ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$map = A11yfy_Map::table();
		// Table names go through %i (WP 6.2+). The JOIN and bucket blocks are
		// spelled out in both queries instead of being shared in a variable, so
		// the placeholder count stays statically verifiable — keep them in sync.
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p
				 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_a11yfy_risk'
				 LEFT JOIN %i m ON m.attachment_id = p.ID AND m.status = 'active'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND (
					( %d = 1 AND m.attachment_id IS NOT NULL )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IN ('high','critical') )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value = 'medium' )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IN ('compliant','low') )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IS NULL )
				 )",
				$wpdb->posts,
				$wpdb->postmeta,
				$map,
				$want['remediated'],
				$want['failing'],
				$want['partial'],
				$want['passed'],
				$want['unscanned']
			)
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM %i p
				 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = '_a11yfy_risk'
				 LEFT JOIN %i m ON m.attachment_id = p.ID AND m.status = 'active'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' AND (
					( %d = 1 AND m.attachment_id IS NOT NULL )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IN ('high','critical') )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value = 'medium' )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IN ('compliant','low') )
					OR ( %d = 1 AND m.attachment_id IS NULL AND pm.meta_value IS NULL )
				 )
				 ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d",
				$wpdb->posts,
				$wpdb->postmeta,
				$map,
				$want['remediated'],
				$want['failing'],
				$want['partial'],
				$want['passed'],
				$want['unscanned'],
				max( 1, (int) $per_page ),
				max( 0, ( (int) $paged - 1 ) * (int) $per_page )
			),
			ARRAY_A
		);

		$connected     = A11yfy_Settings::is_connected();
		$can_manage    = current_user_can( 'manage_options' );
		$can_remediate = $can_manage && $connected;
		$items         = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row['ID'];
			$map_row = A11yfy_Map::for_attachment( $id );
			$active  = $map_row && 'active' === $map_row['status'];
			$file    = get_attached_file( $id );
			// External rewrite of the remediated bytes (optimizer/FTP, §13.6)?
			$tampered = $active && A11yfy_Optimizer_Guard::is_tampered( $map_row );
			$scan     = get_post_meta( $id, '_a11yfy_scan', true );
			$risk     = get_post_meta( $id, '_a11yfy_risk', true );
			$blocked  = A11yfy_Guardrails::blocked_code( $id );
			// FIRST branch is always "already accessible": a compliant or
			// remediated file gets NO fix affordance and NO blocker notice.
			$fixable = ! $active && 'compliant' !== $risk && ! A11yfy_Jobs::has_active( $id );
			$items[] = array(
				'id'               => $id,
				'filename'         => $file ? wp_basename( $file ) : get_the_title( $id ),
				'url'              => wp_get_attachment_url( $id ),
				'edit_url'         => get_edit_post_link( $id, 'raw' ),
				'badge'            => self::badge_html( $id ),
				// A compliant file has nothing to fix — no money button for it.
				'remediate'        => $can_remediate && $fixable && '' === $blocked,
				// Digitally signed: the Fix button stays ACTIVE, but the click
				// asks for confirmation (remediation invalidates the signature).
				'remediate_signed' => $can_remediate && $fixable && 'signed' === $blocked,
				// Not connected yet: show the Fix affordance struck-through so
				// admins learn what connecting unlocks (no jobs can exist yet).
				'remediate_locked' => ! $connected && $can_manage && $fixable && in_array( $blocked, array( '', 'signed' ), true ),
				// Unsendable document (password/XFA/portfolio): the Fix
				// affordance renders disabled with the reason as its title.
				// Signed is NOT in this bucket any more (confirm flow above),
				// and a compliant/remediated file shows no blocker at all.
				'blocked'          => ( $fixable && 'signed' !== $blocked ) ? $blocked : '',
				'blocked_msg'      => ( $fixable && $blocked ) ? A11yfy_Guardrails::blocker_messages()[ $blocked ] : '',
				// Pre-formatted, localized credit estimate — rendered next to
				// the Fix affordance only (unsendable/blocked files get none).
				'estimate'         => ( $fixable && in_array( $blocked, array( '', 'signed' ), true ) )
					? self::credit_estimate_label( $id ) : '',
				'restore'          => $can_remediate && $active && 'inplace' === $map_row['mode'] && ! empty( $map_row['backup_path'] ),
				'has_scan'         => ( is_array( $scan ) && 'client' === ( isset( $scan['origin'] ) ? $scan['origin'] : '' ) )
					|| ( $active && is_array( get_post_meta( $id, '_a11yfy_scan_before', true ) ) ),
				'tampered'         => $tampered,
				'reapply'          => $tampered && $can_manage && ! empty( $map_row['remediated_backup_path'] ) && file_exists( $map_row['remediated_backup_path'] ),
			);
		}

		return array(
			'total' => $total,
			'items' => $items,
		);
	}

	/**
	 * IDs of scanned, non-compliant, not-yet-remediated PDFs ("Fix all" target).
	 *
	 * @return int[]
	 */
	public static function non_compliant_ids() {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_a11yfy_risk'
			 LEFT JOIN {$wpdb->postmeta} bl ON bl.post_id = p.ID AND bl.meta_key = '_a11yfy_blocked'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf'
			   AND pm.meta_value IN ('medium','high','critical')
			   AND bl.meta_id IS NULL"
		);
		return array_map( 'intval', $ids );
	}
}
