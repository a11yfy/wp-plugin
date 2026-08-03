<?php
/**
 * Visitor-facing on-demand mode: front-end assets + public REST endpoints.
 *
 * Flow (feature spec 2026-08-03): the public JS batch-queries the status of
 * every same-origin PDF link on the page; clicking a not-accessible one opens
 * an accessible modal where the visitor can request an accessible version by
 * email. The decision data is local (scan meta + map table) — the click path
 * never calls the SaaS synchronously.
 *
 * Security model: the endpoints are public by design (cached pages make WP
 * nonces unreliable for logged-out visitors) — protection is honeypot +
 * rate limiting + server-side re-validation, and a job can only ever start
 * for a local, scanned, non-compliant attachment (credit-drain cap).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Visitor {

	const STATUS_RATE_LIMIT  = 30; // pdf-status calls / minute / IP.
	const REQUEST_RATE_LIMIT = 5;  // remediation requests / hour / IP.
	const MAX_BATCH_URLS     = 100;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Is the visitor flow active? on_demand mode + connected API key.
	 * The filter lets an admin kill the whole front-end surface (F2).
	 */
	public static function enabled() {
		$enabled = 'on_demand' === A11yfy_Settings::get( 'mode' ) && A11yfy_Settings::is_connected();
		return (bool) apply_filters( 'a11yfy_visitor_enabled', $enabled );
	}

	// ── Front-end assets ───────────────────────────────────────────────────

	public static function enqueue() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_style(
			'a11yfy-visitor',
			A11YFY_PLUGIN_URL . 'assets/css/visitor.css',
			array(),
			A11YFY_VERSION
		);
		wp_enqueue_script(
			'a11yfy-visitor',
			A11YFY_PLUGIN_URL . 'assets/js/visitor.js',
			array(),
			A11YFY_VERSION,
			true
		);

		// Theme-inherit mode (default): no accent is emitted — the buttons carry
		// the wp-element-button class, so block themes style them from
		// theme.json; classic themes fall back to the stylesheet default.
		// Explicit color mode: the accent is emitted and wins over the theme.
		$accent = '';
		if ( ! A11yfy_Settings::get( 'visitor_theme_style' ) ) {
			$accent = (string) sanitize_hex_color( (string) A11yfy_Settings::get( 'visitor_accent_color' ) );
		}
		wp_localize_script(
			'a11yfy-visitor',
			'a11yfyVisitor',
			array(
				'statusUrl'  => esc_url_raw( rest_url( 'a11yfy/v1/pdf-status' ) ),
				'requestUrl' => esc_url_raw( rest_url( 'a11yfy/v1/request-remediation' ) ),
				'accent'     => $accent,
				'privacyUrl' => esc_url_raw( (string) get_privacy_policy_url() ),
				'texts'      => self::texts(),
			)
		);
	}

	/**
	 * Customizable text catalog: stored setting when non-empty, localized
	 * default otherwise (site locale — no hardcoded language).
	 *
	 * @return array<string,string>
	 */
	public static function texts() {
		$out = array();
		foreach ( self::default_texts() as $key => $default ) {
			$custom      = (string) A11yfy_Settings::get( 'visitor_' . $key );
			$out[ $key ] = '' !== trim( $custom ) ? $custom : $default;
		}
		// System messages are not customizable (consistent error language).
		$out['processing_info'] = __( 'An accessible version of this document is already being prepared.', 'a11yfy-pdf-accessibility-checker-fixer' );
		$out['err_email']       = __( 'Please enter a valid email address.', 'a11yfy-pdf-accessibility-checker-fixer' );
		$out['err_rate']        = __( 'Too many requests — please try again later.', 'a11yfy-pdf-accessibility-checker-fixer' );
		$out['err_generic']     = __( 'The request could not be submitted. Please try again later.', 'a11yfy-pdf-accessibility-checker-fixer' );
		$out['close']           = __( 'Close', 'a11yfy-pdf-accessibility-checker-fixer' );
		return $out;
	}

	/**
	 * The admin-customizable keys and their localized defaults. Also the
	 * source of the settings-screen placeholders.
	 *
	 * @return array<string,string>
	 */
	public static function default_texts() {
		return array(
			'modal_title'  => __( 'This document is not yet accessible', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'modal_body'   => __( 'The PDF you are about to open does not currently meet accessibility requirements. You can open it as it is, or request an accessible version.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'btn_open'     => __( 'Open document', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'btn_request'  => __( 'Request accessible version', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'request_info' => __( 'We will email you as soon as the accessible version is ready.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'email_label'  => __( 'Email address', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'btn_submit'   => __( 'Request document', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'success_msg'  => __( 'Thank you! We will notify you by email once the accessible version is ready.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'privacy_note' => __( 'We only use your email address to send this one notification.', 'a11yfy-pdf-accessibility-checker-fixer' ),
		);
	}

	// ── REST endpoints ─────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route(
			'a11yfy/v1',
			'/pdf-status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_status' ),
				// Public by design; see the security model in the class docblock.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'a11yfy/v1',
			'/request-remediation',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /pdf-status — batch status lookup for same-origin PDF URLs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		if ( ! self::enabled() ) {
			return new WP_REST_Response( array( 'error' => 'disabled' ), 404 );
		}
		if ( ! self::status_rate_limit_ok() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$urls = $request->get_param( 'urls' );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_request' ), 400 );
		}
		$urls = array_slice( array_values( array_unique( array_filter( $urls, 'is_string' ) ) ), 0, self::MAX_BATCH_URLS );

		$statuses = array();
		foreach ( $urls as $url ) {
			if ( strlen( $url ) > 2000 ) {
				continue;
			}
			$attachment_id = self::resolve_url( $url );
			if ( ! $attachment_id ) {
				$statuses[ $url ] = array( 'status' => 'unknown' );
				continue;
			}
			$statuses[ $url ] = self::status_for_attachment( $attachment_id, $url );
		}

		return new WP_REST_Response( array( 'statuses' => $statuses ), 200 );
	}

	/**
	 * POST /request-remediation — a visitor subscribes to an accessible copy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		if ( ! self::enabled() ) {
			return new WP_REST_Response( array( 'error' => 'disabled' ), 404 );
		}

		// Honeypot: bots fill every field; humans never see this one.
		if ( '' !== (string) $request->get_param( 'hp' ) ) {
			// Pretend success — no signal for the bot to adapt to.
			return new WP_REST_Response(
				array(
					'ok'    => true,
					'state' => 'queued',
				),
				200
			);
		}

		if ( ! self::request_rate_limit_ok() ) {
			$response = new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
			$response->header( 'Retry-After', (string) HOUR_IN_SECONDS );
			return $response;
		}

		$email = strtolower( trim( (string) $request->get_param( 'email' ) ) );
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_email' ), 400 );
		}

		$url           = (string) $request->get_param( 'url' );
		$attachment_id = self::resolve_url( $url );
		if ( ! $attachment_id ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		// Never trust the client-claimed status — recompute server-side.
		$status = self::status_for_attachment( $attachment_id, $url );
		if ( 'accessible' === $status['status'] ) {
			return new WP_REST_Response(
				array(
					'ok'    => true,
					'state' => 'already_accessible',
				),
				200
			);
		}
		if ( 'unknown' === $status['status'] ) {
			// Unscanned or blocked (signed/encrypted/XFA/portfolio) — the
			// pipeline cannot promise a result here.
			return new WP_REST_Response( array( 'error' => 'not_possible' ), 409 );
		}

		A11yfy_Requests::add(
			$attachment_id,
			$email,
			self::ip_hash(),
			determine_locale()
		);

		// Job already in flight (or another visitor got here first): the new
		// subscriber simply rides along — one job per document, ever.
		if ( A11yfy_Jobs::has_active( $attachment_id ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => true,
					'state' => 'queued',
				),
				200
			);
		}

		$state = self::start_or_park( $attachment_id );
		if ( 'failed' === $state ) {
			return new WP_REST_Response( array( 'error' => 'not_possible' ), 409 );
		}
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'state' => $state,
			),
			200
		);
	}

	/**
	 * Credit soft-gate: enqueue the job when the cached balance covers the
	 * estimate, park the request otherwise. The hard decision is the server's
	 * atomic reserve at submit time — a 402 there re-parks (see
	 * A11yfy_RemediateService::handle_submit_error()).
	 *
	 * @param int $attachment_id Attachment.
	 * @return string queued|pending|failed
	 */
	public static function start_or_park( $attachment_id ) {
		// Early guardrail pass: deciding monthly-cap/blocker outcomes here
		// avoids a misleading interim 'queued' + double admin email when the
		// deferred submit-time check would park the request anyway. submit()
		// re-checks — this is UX, that stays the safety gate.
		$check = A11yfy_Guardrails::check( $attachment_id, 'visitor' );
		if ( is_wp_error( $check ) ) {
			if ( 'a11yfy_in_flight' === $check->get_error_code() ) {
				return 'queued'; // Subscriber rides along on the running job.
			}
			A11yfy_Visitor_Notify::handle_guard_skip( $attachment_id, $check );
			switch ( $check->get_error_code() ) {
				case 'a11yfy_monthly_cap':
					return 'pending';
				case 'a11yfy_compliant':
				case 'a11yfy_already_done':
					return 'queued'; // Already accessible — the notify just went out.
				default:
					return 'failed';
			}
		}

		$estimate = A11yfy_Guardrails::estimate( array( $attachment_id ) );
		$balance  = A11yfy_Queue::balance();

		if ( is_array( $balance ) && isset( $balance['credits'] ) && (int) $balance['credits'] < (int) $estimate['max'] ) {
			A11yfy_Requests::set_status_for_attachment( $attachment_id, 'pending_credit' );
			A11yfy_Visitor_Notify::admin_pending_notify();
			return 'pending';
		}

		// Balance unknown (API hiccup) → optimistic enqueue; the server-side
		// reserve is the real gate and a 402 lands back in pending_credit.
		A11yfy_Queue::enqueue_remediation( $attachment_id, 'visitor' );
		return 'queued';
	}

	// ── URL → attachment resolution (F1) ───────────────────────────────────

	/**
	 * Resolve a same-origin PDF URL to an attachment ID.
	 *
	 * Steps: normalize (strip query/fragment) → attachment_url_to_postid()
	 * → _wp_attached_file lookup (guid drift) → map.remediated_path lookup
	 * (conservative "-accessible.pdf" sibling). Positive hits cached 12h,
	 * misses 15min (a fresh sibling file must not stick as a stale negative).
	 *
	 * @param string $url Absolute URL.
	 * @return int Attachment ID, 0 when unresolvable.
	 */
	public static function resolve_url( $url ) {
		$home = wp_parse_url( home_url() );
		$link = wp_parse_url( $url );
		if ( ! is_array( $link ) || empty( $link['host'] ) || empty( $home['host'] )
			|| strtolower( $link['host'] ) !== strtolower( $home['host'] ) ) {
			return 0;
		}
		$path = isset( $link['path'] ) ? $link['path'] : '';
		if ( ! preg_match( '/\.pdf$/i', $path ) ) {
			return 0;
		}
		$normalized = untrailingslashit( home_url( $path ) );

		$cache_key = 'a11yfy_url2id_' . hash( 'sha256', $normalized );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$id = (int) attachment_url_to_postid( $normalized );

		$uploads = wp_get_upload_dir();
		if ( ! $id && 0 === strpos( $normalized, $uploads['baseurl'] . '/' ) ) {
			$relative = ltrim( substr( $normalized, strlen( $uploads['baseurl'] ) ), '/' );

			// guid drift fallback: the meta is authoritative for the current path.
			global $wpdb;
			$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyed meta lookup, cached below.
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$relative
				)
			);

			// Conservative sibling: the "-accessible.pdf" copy is not an
			// attachment — map it back through the map table.
			if ( ! $id ) {
				$abs = trailingslashit( $uploads['basedir'] ) . $relative;
				$id  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table, indexed column.
					$wpdb->prepare(
						'SELECT attachment_id FROM %i WHERE remediated_path = %s LIMIT 1',
						A11yfy_Map::table(),
						$abs
					)
				);
			}
		}

		set_transient( $cache_key, $id, $id ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $id;
	}

	/**
	 * Status decision from local data only (badge_html() precedence).
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $url           The URL the visitor sees (for filters).
	 * @return array { status: accessible|processing|not_accessible|unknown, accessible_url?: string }
	 */
	public static function status_for_attachment( $attachment_id, $url = '' ) {
		$result = array( 'status' => 'unknown' );

		$map = A11yfy_Map::for_attachment( $attachment_id );
		if ( $map && 'active' === $map['status'] ) {
			$result = array( 'status' => 'accessible' );
			// Conservative mode: links outside the_content (menus, widgets)
			// are not swapped server-side — hand the JS the accessible URL.
			if ( 'conservative' === $map['mode'] && empty( $map['opt_out'] ) && $map['remediated_path'] ) {
				$uploads = wp_get_upload_dir();
				if ( 0 === strpos( $map['remediated_path'], $uploads['basedir'] ) ) {
					$result['accessible_url'] = str_replace( $uploads['basedir'], $uploads['baseurl'], $map['remediated_path'] );
				}
			}
		} elseif ( A11yfy_Guardrails::is_marked_compliant( $attachment_id ) ) {
			$result = array( 'status' => 'accessible' );
		} elseif ( A11yfy_Jobs::has_active( $attachment_id ) ) {
			$result = array( 'status' => 'processing' );
		} else {
			$scan = get_post_meta( $attachment_id, '_a11yfy_scan', true );
			if ( is_array( $scan ) && empty( $scan['compliant'] ) && '' === A11yfy_Guardrails::blocked_code( $attachment_id ) ) {
				$result = array( 'status' => 'not_accessible' );
			}
			// No scan yet, or a blocker (signed/encrypted/XFA/portfolio):
			// fail-open 'unknown' — we never promise what the pipeline
			// cannot deliver (spec §10.1, §10.6).
		}

		/**
		 * Filters the visitor-facing status of a PDF (e.g. to force 'unknown'
		 * and effectively disable the modal for selected documents).
		 *
		 * @param array  $result        { status, accessible_url? }
		 * @param int    $attachment_id Attachment ID.
		 * @param string $url           The URL as seen on the page.
		 */
		return apply_filters( 'a11yfy_visitor_status', $result, $attachment_id, $url );
	}

	// ── Rate limiting ──────────────────────────────────────────────────────

	/**
	 * Hour-bucket suffix for the atomic request rate-limit option name.
	 *
	 * @param int $offset_hours 0 = current bucket, -1 = previous.
	 * @return string e.g. "2026080316"
	 */
	public static function rate_limit_bucket( $offset_hours = 0 ) {
		return gmdate( 'YmdH', time() + $offset_hours * HOUR_IN_SECONDS );
	}

	/**
	 * Atomic per-IP request counter (F5): INSERT … ON DUPLICATE KEY UPDATE on
	 * the options table — two concurrent requests can never both read-modify-
	 * write the same transient value.
	 *
	 * @return bool Whether this request is within the limit.
	 */
	private static function request_rate_limit_ok() {
		global $wpdb;
		$name = 'a11yfy_vrl_' . substr( self::ip_hash(), 0, 32 ) . '_' . self::rate_limit_bucket();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomicity is the point (F5).
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
				 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$name
			)
		);
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bypass the options cache on purpose.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		return $count <= self::REQUEST_RATE_LIMIT;
	}

	/**
	 * Cheap per-IP minute window for the read-only status endpoint — the
	 * transient race here is an accepted low-risk (F5).
	 *
	 * @return bool Whether this call is within the limit.
	 */
	private static function status_rate_limit_ok() {
		$key   = 'a11yfy_vrs_' . substr( self::ip_hash(), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::STATUS_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Salted IP hash — raw addresses are never stored (GDPR).
	 */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	// ── WP privacy tools ───────────────────────────────────────────────────

	public static function register_exporter( $exporters ) {
		$exporters['a11yfy-visitor-requests'] = array(
			'exporter_friendly_name' => __( 'a11yfy accessible-document requests', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['a11yfy-visitor-requests'] = array(
			'eraser_friendly_name' => __( 'a11yfy accessible-document requests', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public static function export_personal_data( $email, $page = 1 ) {
		$items = array();
		foreach ( A11yfy_Requests::rows_for_email( $email ) as $row ) {
			$items[] = array(
				'group_id'    => 'a11yfy_requests',
				'group_label' => __( 'Accessible-document requests', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'item_id'     => 'a11yfy-request-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Document', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'value' => get_the_title( (int) $row['attachment_id'] ),
					),
					array(
						'name'  => __( 'Email', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Requested at', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Status', 'a11yfy-pdf-accessibility-checker-fixer' ),
						'value' => $row['status'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase_personal_data( $email, $page = 1 ) {
		$removed = A11yfy_Requests::erase_email( $email );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
