<?php
/**
 * Background queue glue — everything runs on Action Scheduler (§13.2):
 * one central queue, rate-limit friendly, retry with visibility.
 * Falls back to WP-cron single events when AS is unavailable.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Queue {

	const GROUP = 'a11yfy';

	public static function init() {
		A11yfy_Replacer::init();

		// AS action callbacks.
		add_action( 'a11yfy_submit_job', array( 'A11yfy_RemediateService', 'submit' ), 10, 2 );
		add_action( 'a11yfy_retry_submit', array( 'A11yfy_RemediateService', 'do_submit' ) );
		add_action( 'a11yfy_poll_job', array( 'A11yfy_RemediateService', 'poll' ) );
		add_action( 'a11yfy_triage', array( 'A11yfy_Triage', 'run' ) );
		add_action( 'a11yfy_reconcile', array( __CLASS__, 'reconcile' ) );
		add_action( 'a11yfy_credit_check', array( __CLASS__, 'credit_check' ) );

		// Recurring maintenance (idempotent scheduling).
		add_action( 'init', array( __CLASS__, 'schedule_recurring' ) );
	}

	public static function available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	public static function schedule_recurring() {
		if ( ! self::available() || ! A11yfy_Settings::is_connected() ) {
			return;
		}
		// Reconciliation sweep — mandatory even in future webhook mode (at-most-once!).
		if ( ! as_next_scheduled_action( 'a11yfy_reconcile', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 15 * MINUTE_IN_SECONDS, 'a11yfy_reconcile', array(), self::GROUP );
		}
		// Hourly low-credit poll (§14/15).
		if ( ! as_next_scheduled_action( 'a11yfy_credit_check', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 600, HOUR_IN_SECONDS, 'a11yfy_credit_check', array(), self::GROUP );
		}
	}

	// ── Enqueue helpers ────────────────────────────────────────────────────

	public static function enqueue_remediation( $attachment_id, $source ) {
		if ( self::available() ) {
			as_enqueue_async_action( 'a11yfy_submit_job', array( (int) $attachment_id, $source ), self::GROUP );
		} else {
			wp_schedule_single_event( time() + 5, 'a11yfy_submit_job', array( (int) $attachment_id, $source ) );
		}
	}

	public static function enqueue_triage( $attachment_id ) {
		if ( self::available() ) {
			as_enqueue_async_action( 'a11yfy_triage', array( (int) $attachment_id ), self::GROUP );
		} else {
			wp_schedule_single_event( time() + 5, 'a11yfy_triage', array( (int) $attachment_id ) );
		}
	}

	public static function schedule_poll( $row_id, $delay_seconds ) {
		if ( self::available() ) {
			as_schedule_single_action( time() + $delay_seconds, 'a11yfy_poll_job', array( (int) $row_id ), self::GROUP );
		} else {
			wp_schedule_single_event( time() + $delay_seconds, 'a11yfy_poll_job', array( (int) $row_id ) );
		}
	}

	public static function schedule_submit_retry( $row_id, $delay_seconds ) {
		if ( self::available() ) {
			as_schedule_single_action( time() + $delay_seconds, 'a11yfy_retry_submit', array( (int) $row_id ), self::GROUP );
		} else {
			wp_schedule_single_event( time() + $delay_seconds, 'a11yfy_retry_submit', array( (int) $row_id ) );
		}
	}

	// ── Recurring jobs ─────────────────────────────────────────────────────

	/**
	 * Reconciliation: any in-flight job with no poll activity for 10+ minutes
	 * gets a fresh poll (covers lost single actions, crashed workers, etc.).
	 */
	public static function reconcile() {
		foreach ( A11yfy_Jobs::stale_rows( 10 ) as $row ) {
			self::schedule_poll( (int) $row['id'], wp_rand( 5, 120 ) );
		}
	}

	/**
	 * Marker option: a low-credit email went out for the CURRENT dip below the
	 * threshold. Edge-triggered (like the web app's maybeNotifyCreditsLow):
	 * one email per crossing — re-armed only when the balance recovers to the
	 * threshold, never by the passage of time. The pre-1.0.0 daily transient
	 * re-sent the same warning every day while the balance stayed low.
	 */
	const LOW_CREDIT_NOTIFIED_OPTION = 'a11yfy_low_credit_notified';

	/**
	 * Hourly low-credit check (§6) — dismissible notice + one email per
	 * threshold crossing.
	 */
	public static function credit_check() {
		$threshold = (int) A11yfy_Settings::get( 'low_credit_threshold' );
		if ( $threshold <= 0 || ! A11yfy_Settings::is_connected() ) {
			return;
		}
		$client  = new A11yfy_ApiClient();
		$balance = $client->balance();
		if ( is_wp_error( $balance ) || ! isset( $balance['credits'] ) ) {
			return;
		}

		set_transient( 'a11yfy_balance', $balance, 15 * MINUTE_IN_SECONDS );

		if ( (int) $balance['credits'] >= $threshold ) {
			delete_transient( 'a11yfy_low_credit' );
			// Recovered above the threshold — re-arm, so the NEXT crossing
			// emails again (top-up → new dip → new email).
			delete_option( self::LOW_CREDIT_NOTIFIED_OPTION );
			return;
		}

		set_transient(
			'a11yfy_low_credit',
			array(
				'available'        => (int) $balance['credits'],
				'delegated'        => ! empty( $balance['delegated'] ),
				'billing_org_name' => isset( $balance['billing_org_name'] ) ? (string) $balance['billing_org_name'] : '',
			),
			DAY_IN_SECONDS
		);

		if ( ! get_option( self::LOW_CREDIT_NOTIFIED_OPTION ) ) {
			if ( ! empty( $balance['delegated'] ) ) {
				// Delegated allowance: the recipient cannot top up — the parent
				// organization controls the limit.
				$org  = isset( $balance['billing_org_name'] ) ? (string) $balance['billing_org_name'] : '';
				$org  = '' !== $org ? $org : __( 'your partner organization', 'a11yfy-pdf-accessibility-checker-fixer' );
				$body = sprintf(
					/* translators: 1: remaining delegated credits, 2: parent organization name. */
					__( "Your a11yfy delegated credit allowance is down to %1\$d credits. PDF remediation will stop when it reaches zero.\n\nYour credits are provided by %2\$s — ask them to raise your limit, or switch to your own credits in your a11yfy organization settings.", 'a11yfy-pdf-accessibility-checker-fixer' ),
					(int) $balance['credits'],
					$org
				);
			} else {
				$body = sprintf(
					/* translators: 1: credit balance, 2: top-up URL. */
					__( "Your a11yfy credit balance is down to %1\$d credits. PDF remediation will stop when it reaches zero.\n\nTop up at: %2\$s", 'a11yfy-pdf-accessibility-checker-fixer' ),
					(int) $balance['credits'],
					'https://a11yfy.com'
				);
			}
			wp_mail(
				A11yfy_Settings::notify_email(),
				__( '[a11yfy] Low credit balance', 'a11yfy-pdf-accessibility-checker-fixer' ),
				$body
			);
			update_option( self::LOW_CREDIT_NOTIFIED_OPTION, time(), false );
		}
	}

	/**
	 * Cached balance for UI (15 min transient, refreshed by the hourly check;
	 * the Dashboard passes $refresh = true so a fresh top-up shows immediately).
	 *
	 * @param bool $refresh Force an API call.
	 * @return array|null
	 */
	public static function balance( $refresh = false ) {
		$cached = get_transient( 'a11yfy_balance' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		if ( ! A11yfy_Settings::is_connected() ) {
			return null;
		}
		$client  = new A11yfy_ApiClient();
		$balance = $client->balance();
		if ( is_wp_error( $balance ) ) {
			return is_array( $cached ) ? $cached : null;
		}
		set_transient( 'a11yfy_balance', $balance, 15 * MINUTE_IN_SECONDS );
		return $balance;
	}
}
