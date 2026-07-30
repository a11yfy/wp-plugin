<?php
/**
 * A11yfy_Settings unit tests.
 *
 * @package a11yfy
 */

class Test_A11yfy_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( A11yfy_Settings::OPTION );
		delete_option( A11yfy_Settings::KEY_OPTION );
		parent::tear_down();
	}

	public function test_defaults() {
		$all = A11yfy_Settings::all();

		$this->assertSame( 'manual', $all['mode'] );
		$this->assertSame( 0, $all['monthly_cap'], 'Default monthly cap is 0 = no limit (§13.5).' );
		$this->assertFalse( $all['onboarded'] );
		$this->assertSame( 'inplace', $all['save_strategy'] );
		$this->assertFalse( $all['webhook_mode'] );
	}

	public function test_partial_update_preserves_other_keys() {
		A11yfy_Settings::update( array( 'mode' => 'auto' ) );
		A11yfy_Settings::update( array( 'monthly_cap' => 250 ) );

		$all = A11yfy_Settings::all();
		$this->assertSame( 'auto', $all['mode'] );
		$this->assertSame( 250, $all['monthly_cap'] );
	}

	public function test_unknown_stored_value_falls_back_to_defaults_merge() {
		update_option( A11yfy_Settings::OPTION, 'corrupted-scalar' );
		$this->assertSame( 0, A11yfy_Settings::all()['monthly_cap'] );
	}

	public function test_notify_email_falls_back_to_admin_email() {
		update_option( 'admin_email', 'owner@example.org' );
		$this->assertSame( 'owner@example.org', A11yfy_Settings::notify_email() );

		A11yfy_Settings::update( array( 'notify_email' => 'custom@example.org' ) );
		$this->assertSame( 'custom@example.org', A11yfy_Settings::notify_email() );

		A11yfy_Settings::update( array( 'notify_email' => 'not-an-email' ) );
		$this->assertSame( 'owner@example.org', A11yfy_Settings::notify_email() );
	}

	public function test_api_key_roundtrip_when_sodium_available() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium unavailable' );
		}
		$this->assertTrue( A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' ) );
		$this->assertSame( 'ak_test_0123456789abcdef', A11yfy_Settings::api_key() );
		$this->assertTrue( A11yfy_Settings::is_connected() );
		$this->assertSame( 'ak_test_01…ef', A11yfy_Settings::masked_key() );

		A11yfy_Settings::delete_api_key();
		$this->assertFalse( A11yfy_Settings::is_connected() );
	}
}
