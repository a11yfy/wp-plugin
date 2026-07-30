<?php
/**
 * Hourly low-credit check (A11yfy_Queue::credit_check) — edge-triggered email.
 *
 * One email per threshold crossing: while the balance stays low NO repeat is
 * sent (the pre-1.0.0 daily transient re-mailed every day); recovering above
 * the threshold re-arms, so the next dip emails again.
 *
 * @package a11yfy
 */

class Test_A11yfy_Credit_Check extends WP_UnitTestCase {

	/** @var int Balance the mocked GET /v1/balance returns. */
	private $balance = 0;

	public function set_up() {
		parent::set_up();

		A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' );
		A11yfy_Settings::update( array( 'low_credit_threshold' => 50 ) );

		add_filter( 'pre_http_request', array( $this, 'mock_balance_request' ), 10, 3 );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_balance_request' ), 10 );
		delete_option( A11yfy_Queue::LOW_CREDIT_NOTIFIED_OPTION );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function mock_balance_request( $preempt, $args, $url ) {
		if ( false === strpos( $url, '/balance' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array(),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( array( 'credits' => $this->balance ) ),
			'cookies'  => array(),
		);
	}

	private function sent_mail_count() {
		$mailer = tests_retrieve_phpmailer_instance();
		return count( $mailer->mock_sent );
	}

	public function test_below_threshold_sends_exactly_one_email() {
		$this->balance = 30;

		A11yfy_Queue::credit_check();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertNotFalse( get_option( A11yfy_Queue::LOW_CREDIT_NOTIFIED_OPTION ) );

		// Still low on the next hourly runs — no repeat (the old daily
		// transient would re-send after 24h; the option never expires).
		A11yfy_Queue::credit_check();
		A11yfy_Queue::credit_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	public function test_recovery_re_arms_the_next_crossing() {
		$this->balance = 30;
		A11yfy_Queue::credit_check();
		$this->assertSame( 1, $this->sent_mail_count() );

		// Top-up above the threshold: notice + one-shot flag cleared.
		$this->balance = 200;
		A11yfy_Queue::credit_check();
		$this->assertFalse( get_option( A11yfy_Queue::LOW_CREDIT_NOTIFIED_OPTION ) );
		$this->assertFalse( get_transient( 'a11yfy_low_credit' ) );

		// New dip → new email.
		$this->balance = 40;
		A11yfy_Queue::credit_check();
		$this->assertSame( 2, $this->sent_mail_count() );
	}

	public function test_threshold_change_re_arms() {
		$this->balance = 30;
		A11yfy_Queue::credit_check();
		$this->assertSame( 1, $this->sent_mail_count() );

		// A changed threshold is a new alert contract.
		A11yfy_Settings::update( array( 'low_credit_threshold' => 40 ) );
		A11yfy_Queue::credit_check();
		$this->assertSame( 2, $this->sent_mail_count() );
	}

	public function test_above_threshold_sends_nothing() {
		$this->balance = 200;
		A11yfy_Queue::credit_check();
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	public function test_disabled_threshold_sends_nothing() {
		A11yfy_Settings::update( array( 'low_credit_threshold' => 0 ) );
		$this->balance = 1;
		A11yfy_Queue::credit_check();
		$this->assertSame( 0, $this->sent_mail_count() );
	}
}
