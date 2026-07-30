<?php
/**
 * Settings accessor. Single option array + separately stored encrypted API key.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Settings {

	const OPTION     = 'a11yfy_settings';
	const KEY_OPTION = 'a11yfy_api_key';

	/**
	 * @return array Settings merged over defaults.
	 */
	public static function all() {
		$defaults = array(
			'mode'                 => 'manual',      // manual | auto (§6, §13.5)
			'monthly_cap'          => 0,             // credits/month guardrail; 0 = no cap
			'onboarded'            => false,         // §13.5 one-question wizard answered
			'low_credit_threshold' => 100,           // admin notice below this balance; 0 = off
			'save_strategy'        => 'inplace',     // inplace | conservative (§14/1)
			'notify_email'         => '',            // empty → admin_email
			'delete_data'          => 'keep',        // keep | restore — uninstall behavior (§14/17)
			'webhook_mode'         => false,         // feature-flag (§14/3) — a connect-flow élesíti
			'org_id'               => '',            // multi-org: melyik szervezethez kötődik a kulcs (connect-flow tölti)
			'org_name'             => '',
		);
		$stored   = get_option( self::OPTION, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $partial ) {
		// A changed threshold is a new alert contract — re-arm the one-shot
		// low-credit email so the next hourly check decides afresh.
		if ( array_key_exists( 'low_credit_threshold', $partial )
			&& (int) self::get( 'low_credit_threshold' ) !== (int) $partial['low_credit_threshold'] ) {
			delete_option( A11yfy_Queue::LOW_CREDIT_NOTIFIED_OPTION );
		}
		update_option( self::OPTION, array_merge( self::all(), $partial ) );
	}

	// ── API key ────────────────────────────────────────────────────────────

	public static function set_api_key( $plaintext_key ) {
		$enc = A11yfy_Crypto::encrypt( $plaintext_key );
		if ( null === $enc ) {
			return false;
		}
		update_option( self::KEY_OPTION, $enc, false );
		return true;
	}

	/**
	 * @return string|null Decrypted API key.
	 */
	public static function api_key() {
		return A11yfy_Crypto::decrypt( get_option( self::KEY_OPTION, '' ) );
	}

	public static function delete_api_key() {
		delete_option( self::KEY_OPTION );
	}

	public static function is_connected() {
		$key = self::api_key();
		return is_string( $key ) && '' !== $key;
	}

	/**
	 * Masked key for display: ak_live_ab…f3.
	 */
	public static function masked_key() {
		$key = self::api_key();
		if ( ! $key ) {
			return '';
		}
		return substr( $key, 0, 10 ) . '…' . substr( $key, -2 );
	}

	// ── Webhook signing secret (connect-flow-ból, egyszeri átadás §14/4) ────

	const WEBHOOK_SECRET_OPTION = 'a11yfy_webhook_secret';

	public static function set_webhook_secret( $plaintext ) {
		$enc = A11yfy_Crypto::encrypt( $plaintext );
		if ( null === $enc ) {
			return false;
		}
		update_option( self::WEBHOOK_SECRET_OPTION, $enc, false );
		return true;
	}

	/**
	 * @return string|null
	 */
	public static function webhook_secret() {
		return A11yfy_Crypto::decrypt( get_option( self::WEBHOOK_SECRET_OPTION, '' ) );
	}

	public static function notify_email() {
		$email = self::get( 'notify_email' );
		return ( $email && is_email( $email ) ) ? $email : get_option( 'admin_email' );
	}
}
