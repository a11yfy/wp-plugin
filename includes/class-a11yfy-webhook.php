<?php
/**
 * Webhook receiver (§13.1, feature-flag §14/3).
 *
 * POST /wp-json/a11yfy/v1/webhook
 *  - connect-time reachability ping: {type:'a11yfy.ping', challenge} → echo
 *    (no auth — runs before any secret exists, leaks nothing)
 *  - terminal job webhook: HMAC-verified (timing-safe, ±5 min replay window);
 *    the payload is a TRIGGER only — we take job_id and confirm the state via
 *    GET /v1/jobs/:id, a forged payload can never write status.
 *
 * Polling + reconciliation keep running in webhook mode too: delivery is
 * at-most-once (contract §5.4), a missed webhook must not strand a job.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Webhook {

	const REPLAY_WINDOW = 300; // ±5 perc (contract §5.3)

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'a11yfy/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: the ping is pre-secret, the job webhook is
				// HMAC-authenticated in the handler.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$raw  = $request->get_body();
		$json = json_decode( $raw, true );

		// ── Reachability ping (connect-time challenge echo) ────────────────
		if ( is_array( $json ) && isset( $json['type'] ) && 'a11yfy.ping' === $json['type'] ) {
			$challenge = isset( $json['challenge'] ) ? (string) $json['challenge'] : '';
			if ( '' === $challenge || strlen( $challenge ) > 128 ) {
				return new WP_REST_Response( array( 'error' => 'bad_challenge' ), 400 );
			}
			return new WP_REST_Response( array( 'challenge' => $challenge ), 200 );
		}

		// ── Terminal job webhook ────────────────────────────────────────────
		if ( ! A11yfy_Settings::get( 'webhook_mode' ) ) {
			return new WP_REST_Response( array( 'error' => 'webhook_disabled' ), 403 );
		}
		$secret = A11yfy_Settings::webhook_secret();
		if ( ! $secret ) {
			return new WP_REST_Response( array( 'error' => 'no_secret' ), 403 );
		}

		$verified = self::verify_signature( (string) $request->get_header( 'x-a11yfy-signature' ), $raw, $secret );
		if ( true !== $verified ) {
			return new WP_REST_Response( array( 'error' => $verified ), 401 );
		}

		$job_id = ( is_array( $json ) && isset( $json['job_id'] ) ) ? (string) $json['job_id'] : '';
		if ( ! $job_id || ! preg_match( '/^[0-9a-f-]{16,64}$/', $job_id ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}

		// Trigger, not source: schedule an immediate poll that confirms the
		// status straight from GET /v1/jobs/:id.
		$row = A11yfy_Jobs::find_by_job_id( $job_id );
		if ( $row && in_array( $row['status'], array( 'submitted', 'processing' ), true ) ) {
			A11yfy_Queue::schedule_poll( (int) $row['id'], 1 );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * X-A11yfy-Signature verification: "ts=<unix>;h1=<hex>" over "{ts}:{rawBody}".
	 *
	 * @return true|string true or machine-readable error code.
	 */
	public static function verify_signature( $header, $raw_body, $secret ) {
		if ( ! $header || ! preg_match( '/^ts=(\d+);h1=([0-9a-f]{64})$/', $header, $m ) ) {
			return 'missing_signature';
		}
		$ts = (int) $m[1];
		if ( abs( time() - $ts ) > self::REPLAY_WINDOW ) {
			return 'stale_timestamp';
		}
		$expected = hash_hmac( 'sha256', $ts . ':' . $raw_body, $secret );
		if ( ! hash_equals( $expected, $m[2] ) ) {
			return 'bad_signature';
		}
		return true;
	}
}
