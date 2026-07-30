<?php
/**
 * Unsendable-document blockers (password/signature/XFA/portfolio, §6):
 * guardrail skip, Fix-affordance gating, Fix-all exclusion, skip badge.
 *
 * @package a11yfy
 */

class Test_A11yfy_Guardrails_Blockers extends WP_UnitTestCase {

	private function make_pdf_with_file( $contents, $risk = 'high' ) {
		static $n = 0;
		$n++;
		$path = wp_tempnam( "a11yfy-blocker-{$n}.pdf" );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$id = self::factory()->attachment->create_object(
			"2026/07/blocker-{$n}.pdf",
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		update_attached_file( $id, $path );
		update_post_meta( $id, '_a11yfy_risk', $risk );
		return $id;
	}

	public function test_is_signed_pdf_tail_heuristic() {
		$plain  = wp_tempnam( 'plain.pdf' );
		$signed = wp_tempnam( 'signed.pdf' );
		file_put_contents( $plain, "%PDF-1.7\nplain body\n%%EOF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $signed, "%PDF-1.7\nbody\n/Type /Sig /ByteRange [0 100 200 100] /Contents <00>\n%%EOF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertFalse( A11yfy_Guardrails::is_signed_pdf( $plain, filesize( $plain ) ) );
		$this->assertTrue( A11yfy_Guardrails::is_signed_pdf( $signed, filesize( $signed ) ) );
	}

	public function test_check_skips_on_stored_blocker_code() {
		$id = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		update_post_meta( $id, '_a11yfy_scan', array( 'origin' => 'client' ) ); // A scan ran…
		update_post_meta( $id, A11yfy_Guardrails::BLOCKED_META, 'xfa' );        // …and found XFA.

		$result = A11yfy_Guardrails::check( $id, 'manual' );

		$this->assertWPError( $result );
		$this->assertSame( 'a11yfy_xfa', $result->get_error_code() );
	}

	public function test_check_signature_backstop_without_scan() {
		$id = $this->make_pdf_with_file( "%PDF-1.7\nbody\n/ByteRange [0 100 200 100]\n%%EOF" );

		$result = A11yfy_Guardrails::check( $id, 'auto' );

		$this->assertWPError( $result );
		$this->assertSame( 'a11yfy_signed', $result->get_error_code() );
	}

	public function test_scan_verdict_overrides_signature_backstop() {
		// The tail contains /ByteRange, but the object-level client scan said
		// "not signed" (e.g. stray token in content) — the scan verdict wins.
		$id = $this->make_pdf_with_file( "%PDF-1.7\nbody\n/ByteRange [0 100 200 100]\n%%EOF" );
		update_post_meta( $id, '_a11yfy_scan', array( 'origin' => 'client', 'signed' => false ) );

		$this->assertTrue( A11yfy_Guardrails::check( $id, 'manual' ) );
	}

	public function test_blocked_file_loses_fix_affordance_and_fix_all() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' );

		$blocked = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		$signed  = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		$normal  = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		update_post_meta( $blocked, A11yfy_Guardrails::BLOCKED_META, 'xfa' );
		update_post_meta( $signed, A11yfy_Guardrails::BLOCKED_META, 'signed' );

		$by_id = array_column( A11yfy_Admin::pdf_list( array() )['items'], null, 'id' );
		$this->assertFalse( $by_id[ $blocked ]['remediate'], 'No money button on an unsendable file.' );
		$this->assertSame( 'xfa', $by_id[ $blocked ]['blocked'] );
		$this->assertNotSame( '', $by_id[ $blocked ]['blocked_msg'] );
		// Signed: ACTIVE fix button with a confirm step — not the inert blocked one.
		$this->assertFalse( $by_id[ $signed ]['remediate'] );
		$this->assertTrue( $by_id[ $signed ]['remediate_signed'] );
		$this->assertSame( '', $by_id[ $signed ]['blocked'] );
		$this->assertNotSame( '', $by_id[ $signed ]['blocked_msg'] );
		$this->assertTrue( $by_id[ $normal ]['remediate'] );

		$targets = A11yfy_Admin::non_compliant_ids();
		$this->assertNotContains( $blocked, $targets, 'Fix all must skip unsendable files.' );
		$this->assertNotContains( $signed, $targets, 'Fix all must skip signed files (no per-doc confirm in bulk).' );
		$this->assertContains( $normal, $targets );
	}

	public function test_compliant_file_shows_no_fix_affordance_even_when_signed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' );

		// Már akadálymentes ÉS aláírt: az "already accessible" az ELSŐ elágazás —
		// se javítás gomb, se aláírt-tájékoztatás.
		$id = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF", 'compliant' );
		update_post_meta( $id, A11yfy_Guardrails::BLOCKED_META, 'signed' );

		$by_id = array_column( A11yfy_Admin::pdf_list( array() )['items'], null, 'id' );
		$this->assertFalse( $by_id[ $id ]['remediate'] );
		$this->assertFalse( $by_id[ $id ]['remediate_signed'] );
		$this->assertSame( '', $by_id[ $id ]['blocked'] );
		$this->assertSame( '', $by_id[ $id ]['blocked_msg'] );
	}

	public function test_signed_confirm_meta_lets_check_pass_hash_scoped() {
		$id   = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		$file = get_attached_file( $id );
		update_post_meta( $id, '_a11yfy_scan', array( 'origin' => 'client' ) );
		update_post_meta( $id, A11yfy_Guardrails::BLOCKED_META, 'signed' );

		// Megerősítés nélkül: skip.
		$result = A11yfy_Guardrails::check( $id, 'manual' );
		$this->assertWPError( $result );
		$this->assertSame( 'a11yfy_signed', $result->get_error_code() );

		// A pontos tartalomra szóló megerősítéssel: átmegy.
		update_post_meta( $id, A11yfy_Guardrails::SIGNED_OK_META, hash_file( 'sha256', $file ) );
		$this->assertTrue( A11yfy_Guardrails::check( $id, 'manual' ) );

		// Más tartalom (kicserélt fájl) → a régi megerősítés nem él.
		file_put_contents( $file, "%PDF-1.7\nreplaced body\n%%EOF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = A11yfy_Guardrails::check( $id, 'manual' );
		$this->assertWPError( $result );
		$this->assertSame( 'a11yfy_signed', $result->get_error_code() );
	}

	public function test_badge_surfaces_last_skip() {
		$id = $this->make_pdf_with_file( "%PDF-1.7\nbody\n%%EOF" );
		update_post_meta(
			$id,
			'_a11yfy_last_skip',
			array(
				'code'    => 'a11yfy_encrypted',
				'message' => 'stored-at-skip-time message',
				'at'      => time(),
			)
		);

		$badge = A11yfy_Admin::badge_html( $id );

		$this->assertStringContainsString( 'a11yfy-badge--err', $badge );
		// Rendered from the code at display time (current locale), not the stored string.
		$this->assertStringContainsString( 'password-protected', $badge );
	}
}
