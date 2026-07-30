<?php
/**
 * Connect-flow (§2.1): "Csatlakozás az a11yfy-hoz" —
 * browser consent on a11yfy.com → one-time code → server-to-server exchange.
 * The raw API key never travels in a URL and the user never sees it.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Connect {

	const STATE_TRANSIENT = 'a11yfy_connect_state';

	public static function init() {
		add_action( 'admin_post_a11yfy_connect_start', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_a11yfy_connect_cb', array( __CLASS__, 'callback' ) );
	}

	/**
	 * Web app base URL (the /v1 API base without the /v1 suffix).
	 */
	public static function web_base() {
		return preg_replace( '#/v1/?$#', '', A11yfy_ApiClient::base_url() );
	}

	private static function connect_lang() {
		return 0 === strpos( determine_locale(), 'hu' ) ? 'hu' : 'en';
	}

	/**
	 * Step 1 — redirect the admin to the a11yfy consent screen.
	 */
	public static function start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		check_admin_referer( 'a11yfy_connect' );

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$url = self::web_base() . '/' . self::connect_lang() . '/connect?' . http_build_query(
			array(
				'site'     => home_url(),
				'callback' => admin_url( 'admin-post.php' ) . '?action=a11yfy_connect_cb',
				'webhook'  => rest_url( 'a11yfy/v1/webhook' ),
				'state'    => $state,
			)
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external by design
		exit;
	}

	/**
	 * Step 2 — consent callback: state-check, then server-to-server exchange.
	 */
	public static function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$settings_url = admin_url( 'admin.php?page=a11yfy-settings' );

		// CSRF: the state must match what we minted at start (one-time). A WP
		// nonce cannot round-trip through the external consent redirect — the
		// one-time transient + hash_equals below plays the same role.
		$state    = isset( $_GET['a11yfy_state'] ) ? sanitize_text_field( wp_unslash( $_GET['a11yfy_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CSRF = one-time state token, verified with hash_equals() below.
		$expected = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );
		if ( ! $expected || ! $state || ! hash_equals( $expected, $state ) ) {
			wp_safe_redirect( add_query_arg( 'a11yfy_notice', 'connect_state_mismatch', $settings_url ) );
			exit;
		}

		if ( isset( $_GET['a11yfy_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- runs after the hash_equals() state check above.
			wp_safe_redirect( add_query_arg( 'a11yfy_notice', 'connect_denied', $settings_url ) );
			exit;
		}

		$code = isset( $_GET['a11yfy_code'] ) ? sanitize_text_field( wp_unslash( $_GET['a11yfy_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- runs after the hash_equals() state check above.
		if ( ! $code ) {
			wp_safe_redirect( add_query_arg( 'a11yfy_notice', 'connect_failed', $settings_url ) );
			exit;
		}

		$result = self::exchange( $code );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'a11yfy_notice', 'connect_failed', $settings_url ) );
			exit;
		}

		// Success lands on the Dashboard, where the §13.5 one-question
		// onboarding wizard takes over (failures stay on Settings above).
		wp_safe_redirect(
			add_query_arg(
				'a11yfy_notice',
				! empty( $result['webhook_enabled'] ) ? 'connected_webhook' : 'connected',
				admin_url( 'admin.php?page=a11yfy' )
			)
		);
		exit;
	}

	/**
	 * Server-to-server one-time-code exchange; stores key + webhook secret.
	 *
	 * @param string $code One-time code from the consent redirect.
	 * @return array|WP_Error { webhook_enabled: bool }
	 */
	private static function exchange( $code ) {
		$response = wp_remote_post(
			self::web_base() . '/api/connect/exchange',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'code' => $code,
						'site' => home_url(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $json['api_key'] ) ) {
			return new WP_Error( isset( $json['code'] ) ? $json['code'] : 'exchange_failed', 'Connect exchange failed.' );
		}

		if ( ! A11yfy_Settings::set_api_key( (string) $json['api_key'] ) ) {
			return new WP_Error( 'a11yfy_crypto', 'Could not store the API key.' );
		}

		// Webhook-mód feature-flag (§14/3): csak akkor élesedik, ha a SaaS
		// reachability-pingje átment ÉS megkaptuk az egyszeri signing secretet.
		$webhook_enabled = false;
		if ( ! empty( $json['webhook']['reachable'] ) && ! empty( $json['webhook']['signing_secret'] ) ) {
			$webhook_enabled = A11yfy_Settings::set_webhook_secret( (string) $json['webhook']['signing_secret'] );
		}
		A11yfy_Settings::update(
			array(
				'webhook_mode' => $webhook_enabled,
				// Multi-org: which organization this site's key belongs to — an
				// agency running many client sites needs this for reconciliation.
				'org_id'       => isset( $json['org_id'] ) ? (string) $json['org_id'] : '',
				'org_name'     => isset( $json['org_name'] ) ? (string) $json['org_name'] : '',
			)
		);

		delete_transient( 'a11yfy_balance' );
		delete_transient( 'a11yfy_low_credit' );
		delete_option( A11yfy_Queue::LOW_CREDIT_NOTIFIED_OPTION );

		return array( 'webhook_enabled' => $webhook_enabled );
	}
}
