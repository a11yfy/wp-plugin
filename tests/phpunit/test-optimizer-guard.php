<?php
/**
 * A11yfy_Optimizer_Guard — optimizer conflict protection (§13.6).
 *
 * ShortPixel/EWWW themselves are absent in the test env; we verify our side
 * of the contracts: the prevent-meta lifecycle, the EWWW bypass filter, the
 * tamper detector and the credit-saving reapply flow.
 *
 * @package a11yfy
 */

class Test_A11yfy_Optimizer_Guard extends WP_UnitTestCase {

	private function make_pdf() {
		static $n = 0;
		$n++;
		return self::factory()->attachment->create_object(
			"2026/07/guard-{$n}.pdf",
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
	}

	/** Temp "PDF" file with known bytes. */
	private function make_file( $contents = '%PDF-1.7 original' ) {
		$path = wp_tempnam( 'a11yfy-guard' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	// ── Prevent-meta lifecycle ─────────────────────────────────────────────

	public function test_protect_sets_prefixed_meta_and_unprotect_clears_it() {
		$id = $this->make_pdf();

		A11yfy_Optimizer_Guard::protect( $id );
		$value = get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true );
		$this->assertStringStartsWith( A11yfy_Optimizer_Guard::PREVENT_PREFIX, $value );

		A11yfy_Optimizer_Guard::unprotect( $id );
		$this->assertSame( '', get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true ) );
	}

	public function test_foreign_prevent_reason_is_never_touched() {
		$id = $this->make_pdf();
		update_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, 'Fatal error during optimization' );

		A11yfy_Optimizer_Guard::protect( $id );
		$this->assertSame(
			'Fatal error during optimization',
			get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true ),
			'ShortPixel\'s own reason must not be overwritten.'
		);

		A11yfy_Optimizer_Guard::unprotect( $id );
		$this->assertSame(
			'Fatal error during optimization',
			get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true ),
			'unprotect() only clears values we wrote.'
		);
	}

	// ── EWWW bypass filter ─────────────────────────────────────────────────

	public function test_ewww_bypass_skips_active_remediated_paths_and_backup_dir() {
		$id   = $this->make_pdf();
		$file = $this->make_file();
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => $file,
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);

		$this->assertTrue( A11yfy_Optimizer_Guard::ewww_bypass( false, $file ), 'Active remediated file → bypass.' );
		$this->assertTrue(
			A11yfy_Optimizer_Guard::ewww_bypass( false, A11yfy_Install::backup_dir() . '/1-remediated-x.pdf' ),
			'Anything inside the backup dir → bypass.'
		);
		$this->assertFalse( A11yfy_Optimizer_Guard::ewww_bypass( false, '/tmp/unrelated.pdf' ), 'Unrelated file → untouched.' );
		$this->assertTrue( A11yfy_Optimizer_Guard::ewww_bypass( true, '/tmp/unrelated.pdf' ), 'An existing skip verdict is preserved.' );
	}

	// ── Tamper detection ───────────────────────────────────────────────────

	public function test_is_tampered_detects_rewritten_bytes() {
		$id   = $this->make_pdf();
		$file = $this->make_file( '%PDF-1.7 remediated' );
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => $file,
				'remediated_hash' => hash_file( 'sha256', $file ),
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);
		$row = A11yfy_Map::for_attachment( $id );
		$this->assertFalse( A11yfy_Optimizer_Guard::is_tampered( $row ), 'Pristine bytes → not tampered.' );

		// Simulate an optimizer rewrite. (is_tampered caches per attachment —
		// use a fresh attachment for the positive case.)
		$id2   = $this->make_pdf();
		$file2 = $this->make_file( '%PDF-1.7 remediated' );
		A11yfy_Map::upsert(
			$id2,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => $file2,
				'remediated_hash' => hash_file( 'sha256', $file2 ),
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);
		file_put_contents( $file2, '%PDF-1.7 optimized (tags stripped)' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertTrue( A11yfy_Optimizer_Guard::is_tampered( A11yfy_Map::for_attachment( $id2 ) ) );
	}

	// ── Reapply (credit-saving copy) ───────────────────────────────────────

	public function test_reapply_restores_remediated_bytes_for_free() {
		$id         = $this->make_pdf();
		$remediated = '%PDF-1.7 remediated content';
		$file       = $this->make_file( $remediated );
		$backup     = A11yfy_Replacer::backup_remediated( $id, $file );
		$this->assertNotNull( $backup );
		$this->assertFileExists( $backup );

		A11yfy_Map::upsert(
			$id,
			array(
				'mode'                   => 'conservative',
				'status'                 => 'active',
				'remediated_path'        => $file,
				'remediated_hash'        => hash_file( 'sha256', $file ),
				'remediated_backup_path' => $backup,
				'original_hash'          => str_repeat( 'a', 64 ),
			)
		);

		// Optimizer destroys the remediated file…
		file_put_contents( $file, '%PDF-1.7 optimized junk' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_post_meta( $id, '_a11yfy_scan', array( 'origin' => 'client' ) );

		$this->assertTrue( A11yfy_Replacer::reapply( $id ) );
		$this->assertSame( $remediated, file_get_contents( $file ), 'Bytes restored from the pristine copy.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( '', get_post_meta( $id, '_a11yfy_scan', true ), 'Stale scan verdict cleared.' );
		$this->assertStringStartsWith(
			A11yfy_Optimizer_Guard::PREVENT_PREFIX,
			get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true ),
			'Re-applied file is protected again.'
		);
		$row = A11yfy_Map::for_attachment( $id );
		$this->assertSame( hash_file( 'sha256', $file ), $row['remediated_hash'] );
	}

	public function test_reapply_without_backup_copy_errors() {
		$id   = $this->make_pdf();
		$file = $this->make_file();
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => $file,
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);
		$result = A11yfy_Replacer::reapply( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'a11yfy_no_remediated_backup', $result->get_error_code() );
	}

	// ── Backfill (schema v2 upgrade) ───────────────────────────────────────

	public function test_backfill_flags_active_rows_and_saves_pristine_copy() {
		$id   = $this->make_pdf();
		$file = $this->make_file( '%PDF-1.7 pristine' );
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => $file,
				'remediated_hash' => hash_file( 'sha256', $file ),
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);

		A11yfy_Optimizer_Guard::backfill();

		$this->assertStringStartsWith(
			A11yfy_Optimizer_Guard::PREVENT_PREFIX,
			get_post_meta( $id, A11yfy_Optimizer_Guard::SHORTPIXEL_PREVENT_META, true )
		);
		$row = A11yfy_Map::for_attachment( $id );
		$this->assertNotEmpty( $row['remediated_backup_path'] );
		$this->assertFileExists( $row['remediated_backup_path'] );
	}

	// ── Site Health ────────────────────────────────────────────────────────

	public function test_site_health_good_when_no_optimizer_active() {
		$result = A11yfy_Optimizer_Guard::site_health_result();
		$this->assertSame( 'good', $result['status'] );
	}
}
