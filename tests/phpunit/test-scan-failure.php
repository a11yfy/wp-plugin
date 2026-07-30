<?php
/**
 * Two-strike scan-failure parking (A11yfy_Ajax::record_scan_failure).
 *
 * A permanently failing file (CDN serving different bytes than disk, engine
 * crash) must leave the stale window after the second identical failure —
 * otherwise it is re-downloaded and re-scanned on every admin page load.
 *
 * @package a11yfy
 */

class Test_A11yfy_Scan_Failure extends WP_UnitTestCase {

	private function make_pdf() {
		static $n = 0;
		$n++;
		return self::factory()->attachment->create_object(
			"2026/07/failing-{$n}.pdf",
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
	}

	public function test_first_failure_only_records_the_code() {
		$id = $this->make_pdf();

		A11yfy_Ajax::record_scan_failure( $id, 'hash_mismatch' );

		$error = get_post_meta( $id, A11yfy_Ajax::SCAN_ERROR_META, true );
		$this->assertSame( 'hash_mismatch', $error['code'] );
		$this->assertSame( '', get_post_meta( $id, '_a11yfy_scan_ts', true ), 'First strike must not park the file — the next page load retries.' );
	}

	public function test_second_identical_failure_parks_the_file() {
		$id = $this->make_pdf();

		A11yfy_Ajax::record_scan_failure( $id, 'hash_mismatch' );
		A11yfy_Ajax::record_scan_failure( $id, 'hash_mismatch' );

		$this->assertNotSame( '', get_post_meta( $id, '_a11yfy_scan_ts', true ) );
		$this->assertSame( A11yfy_Admin::ENGINE_VERSION, get_post_meta( $id, '_a11yfy_scan_engine', true ) );
	}

	public function test_different_failure_code_restarts_the_strikes() {
		$id = $this->make_pdf();

		A11yfy_Ajax::record_scan_failure( $id, 'hash_mismatch' );
		A11yfy_Ajax::record_scan_failure( $id, 'bad_report' );

		$this->assertSame( '', get_post_meta( $id, '_a11yfy_scan_ts', true ), 'A different failure is a new strike-one.' );
		$error = get_post_meta( $id, A11yfy_Ajax::SCAN_ERROR_META, true );
		$this->assertSame( 'bad_report', $error['code'] );
	}

	public function test_untrusted_code_is_sanitized() {
		$id = $this->make_pdf();

		A11yfy_Ajax::record_scan_failure( $id, "<script>alert('x')</script>" );

		$error = get_post_meta( $id, A11yfy_Ajax::SCAN_ERROR_META, true );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]*$/', $error['code'] );
	}
}
