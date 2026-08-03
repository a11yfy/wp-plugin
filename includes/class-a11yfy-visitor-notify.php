<?php
/**
 * Visitor notifications for on-demand mode.
 *
 * Listens on the terminal-state action (`a11yfy_job_finished`, fired on every
 * terminal branch — the pre-existing `a11yfy_remediated` skips noop/failed,
 * K3/F4) and:
 *   done   → emails every open subscriber (locale-grouped, F7), marks rows
 *            done_notified only after wp_mail() succeeded (F6)
 *   failed → 402-class errors re-park the requests as pending_credit and
 *            warn the admin; anything else marks them failed (no visitor
 *            email — we never announce a failure we cannot explain)
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Visitor_Notify {

	const PENDING_NOTIFY_TRANSIENT = 'a11yfy_pending_notify';
	const PENDING_NOTIFY_INTERVAL  = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'a11yfy_job_finished', array( __CLASS__, 'on_job_finished' ), 10, 3 );
	}

	/**
	 * Terminal job state → request lifecycle.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $status        done|failed|stalled.
	 * @param array  $row           Jobs table row fields (incl. error_code).
	 */
	public static function on_job_finished( $attachment_id, $status, $row ) {
		$open = A11yfy_Requests::open_for_attachment( $attachment_id );
		if ( empty( $open ) ) {
			return;
		}

		if ( 'done' === $status ) {
			self::notify_attachment( $attachment_id );
			return;
		}

		$error = isset( $row['error_code'] ) ? (string) $row['error_code'] : '';
		if ( in_array( $error, array( 'insufficient_credits_api', 'delegated_limit_reached' ), true ) ) {
			// Money, not failure: park and resume when the balance recovers.
			A11yfy_Requests::set_status_for_attachment( $attachment_id, 'pending_credit' );
			self::admin_pending_notify();
			return;
		}

		A11yfy_Requests::set_status_for_attachment( $attachment_id, 'failed' );
	}

	/**
	 * Guardrail skip on a visitor-sourced submit (the job never started).
	 *
	 * @param int      $attachment_id Attachment.
	 * @param WP_Error $error         Guardrail error.
	 */
	public static function handle_guard_skip( $attachment_id, WP_Error $error ) {
		$code = $error->get_error_code();
		if ( 'a11yfy_in_flight' === $code ) {
			// Another job is already running for this document — the requests
			// stay queued and its terminal action will settle them.
			return;
		}
		if ( 'a11yfy_monthly_cap' === $code ) {
			// The cap resets with the calendar month — the resume loop picks
			// these up once check() passes again.
			A11yfy_Requests::set_status_for_attachment( $attachment_id, 'pending_credit' );
			self::admin_pending_notify();
			return;
		}
		if ( in_array( $code, array( 'a11yfy_compliant', 'a11yfy_already_done' ), true ) ) {
			// The document turned out accessible — that IS the good news.
			self::notify_attachment( $attachment_id );
			return;
		}
		A11yfy_Requests::set_status_for_attachment( $attachment_id, 'failed' );
	}

	/**
	 * Email every open subscriber of an attachment that the accessible
	 * version is ready. Locale-grouped; a row is marked done_notified only
	 * after its wp_mail() returned true — failures stay queued for the
	 * daily retry sweep (A11yfy_Requests::purge()).
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function notify_attachment( $attachment_id ) {
		$rows = A11yfy_Requests::open_for_attachment( $attachment_id );
		if ( empty( $rows ) ) {
			return;
		}

		$custom_subject = trim( (string) A11yfy_Settings::get( 'visitor_email_subject' ) );
		$custom_body    = trim( (string) A11yfy_Settings::get( 'visitor_email_body' ) );
		$customized     = '' !== $custom_subject || '' !== $custom_body;

		$groups = array();
		foreach ( $rows as $row ) {
			// Customized admin copy goes out as-is to everyone (spec §9);
			// the '' locale group means: no switch, current site locale.
			$locale              = $customized ? '' : (string) $row['locale'];
			$groups[ $locale ][] = $row;
		}

		$headers = array( 'Reply-To: ' . get_option( 'admin_email' ) );

		foreach ( $groups as $locale => $group ) {
			$switched = false;
			if ( '' !== $locale && determine_locale() !== $locale ) {
				$switched = switch_to_locale( $locale );
			}

			$subject = '' !== $custom_subject ? $custom_subject : sprintf(
				/* translators: %s: site name. */
				__( '[%s] Your accessible document is ready', 'a11yfy-pdf-accessibility-checker-fixer' ),
				self::site_name()
			);
			$body_template = '' !== $custom_body ? $custom_body : __(
				"Hello,\n\nGood news: the accessible version of \"{document_title}\" that you requested on {site_name} is ready.\n\nYou can download it here:\n{document_url}\n\nThank you for caring about accessibility.\n\n{site_name}",
				'a11yfy-pdf-accessibility-checker-fixer'
			);

			foreach ( $group as $row ) {
				$vars = array(
					'{site_name}'      => self::site_name(),
					'{document_title}' => get_the_title( $attachment_id ),
					'{document_url}'   => self::document_url( $attachment_id ),
					'{request_date}'   => mysql2date( get_option( 'date_format' ), $row['created_at'] ),
				);
				$sent = wp_mail(
					$row['email'],
					strtr( $subject, $vars ),
					strtr( $body_template, $vars ),
					$headers
				);
				if ( $sent ) {
					A11yfy_Requests::mark( (int) $row['id'], 'done_notified' );
				}
			}

			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The URL where the accessible version lives: conservative mode swaps to
	 * the sibling file, inplace (and noop) keep the original URL.
	 */
	public static function document_url( $attachment_id ) {
		$map = A11yfy_Map::for_attachment( $attachment_id );
		if ( $map && 'active' === $map['status'] && 'conservative' === $map['mode']
			&& empty( $map['opt_out'] ) && $map['remediated_path'] ) {
			$uploads = wp_get_upload_dir();
			if ( 0 === strpos( $map['remediated_path'], $uploads['basedir'] ) ) {
				return str_replace( $uploads['basedir'], $uploads['baseurl'], $map['remediated_path'] );
			}
		}
		return (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Admin warning: visitor requests are parked for lack of credits.
	 * Throttled to one email per 6 hours — repeated demand keeps reminding,
	 * but never mail-bombs (spec §5.2). Only set after a successful send (F6).
	 */
	public static function admin_pending_notify() {
		if ( get_transient( self::PENDING_NOTIFY_TRANSIENT ) ) {
			return;
		}

		$counts = A11yfy_Requests::counts();
		if ( $counts['requests'] < 1 ) {
			return;
		}
		$estimate = A11yfy_Guardrails::estimate( A11yfy_Requests::pending_attachments( 50 ) );
		$balance  = A11yfy_Queue::balance();

		$body = sprintf(
			/* translators: 1: number of waiting visitor requests, 2: number of documents, 3: estimated credits needed. */
			__( "Visitors of your site requested accessible PDF versions, but your a11yfy credit balance does not cover the work.\n\nWaiting requests: %1\$d (%2\$d documents)\nEstimated credits needed: up to %3\$d\n\nThe requests are parked and will start automatically once enough credits are available.", 'a11yfy-pdf-accessibility-checker-fixer' ),
			$counts['requests'],
			$counts['documents'],
			(int) $estimate['max']
		);

		if ( is_array( $balance ) && ! empty( $balance['delegated'] ) ) {
			// Delegated allowance: the site owner cannot top up (K5) — the
			// parent organization controls the limit.
			$org   = isset( $balance['billing_org_name'] ) ? (string) $balance['billing_org_name'] : '';
			$org   = '' !== $org ? $org : __( 'your partner organization', 'a11yfy-pdf-accessibility-checker-fixer' );
			$body .= "\n\n" . sprintf(
				/* translators: %s: parent organization name. */
				__( 'Your credits are provided by %s — ask them to raise your limit.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				$org
			);
		} else {
			$body .= "\n\n" . sprintf(
				/* translators: %s: top-up URL. */
				__( 'Top up at: %s', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'https://a11yfy.com'
			);
		}

		$sent = wp_mail(
			A11yfy_Settings::notify_email(),
			__( '[a11yfy] Visitor requests are waiting for credits', 'a11yfy-pdf-accessibility-checker-fixer' ),
			$body
		);
		if ( $sent ) {
			set_transient( self::PENDING_NOTIFY_TRANSIENT, time(), self::PENDING_NOTIFY_INTERVAL );
		}
	}

	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
