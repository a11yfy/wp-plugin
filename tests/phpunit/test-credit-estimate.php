<?php
/**
 * Fix-all credit estimate (A11yfy_Guardrails::estimate) — must agree with the
 * per-row A11yfy_Admin::credit_estimate() ranges, not with their lower bound
 * (the "up to ~33 while the rows said 18–54 and ~15" bug, 2026-07-30).
 *
 * @package a11yfy
 */

class Test_A11yfy_Credit_Estimate extends WP_UnitTestCase {

	private function make_pdf( $scan = null ) {
		static $n = 0;
		$n++;
		$id = self::factory()->attachment->create_object(
			"2026/07/estimate-{$n}.pdf",
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		if ( null !== $scan ) {
			update_post_meta( $id, '_a11yfy_scan', $scan );
		}
		return $id;
	}

	private function client_scan( $pages, $tagged, $scanned_likely = false ) {
		return array(
			'origin'         => 'client',
			'pages'          => $pages,
			'tagged'         => $tagged,
			'scanned_likely' => $scanned_likely,
		);
	}

	public function test_tagged_born_digital_gets_a_range() {
		$id = $this->make_pdf( $this->client_scan( 18, true ) );

		$this->assertSame(
			array(
				'min' => 18,
				'max' => 54,
			),
			A11yfy_Guardrails::estimate( array( $id ) )
		);
	}

	public function test_untagged_is_flat_three_per_page() {
		$id = $this->make_pdf( $this->client_scan( 5, false ) );

		$this->assertSame(
			array(
				'min' => 15,
				'max' => 15,
			),
			A11yfy_Guardrails::estimate( array( $id ) )
		);
	}

	public function test_tagged_but_scanned_is_flat_three_per_page() {
		// Mirrors credit_estimate(): a scanned PDF needs the full rebuild even
		// when a (probably bogus) tag tree is present.
		$id = $this->make_pdf( $this->client_scan( 4, true, true ) );

		$this->assertSame(
			array(
				'min' => 12,
				'max' => 12,
			),
			A11yfy_Guardrails::estimate( array( $id ) )
		);
	}

	public function test_unscanned_falls_back_to_five_pages_upper_band() {
		$id = $this->make_pdf();

		$this->assertSame(
			array(
				'min' => 15,
				'max' => 15,
			),
			A11yfy_Guardrails::estimate( array( $id ) )
		);
	}

	public function test_totals_sum_per_file_ranges() {
		$ids = array(
			$this->make_pdf( $this->client_scan( 18, true ) ), // 18–54.
			$this->make_pdf( $this->client_scan( 5, false ) ), // 15.
			$this->make_pdf(),                                 // 15 fallback.
		);

		$this->assertSame(
			array(
				'min' => 48,
				'max' => 84,
			),
			A11yfy_Guardrails::estimate( $ids )
		);
	}

	public function test_fix_all_sum_matches_per_row_labels() {
		$id       = $this->make_pdf( $this->client_scan( 18, true ) );
		$row      = A11yfy_Admin::credit_estimate( $id );
		$estimate = A11yfy_Guardrails::estimate( array( $id ) );

		$this->assertSame( $row['min'], $estimate['min'] );
		$this->assertSame( $row['max'], $estimate['max'] );
	}
}
