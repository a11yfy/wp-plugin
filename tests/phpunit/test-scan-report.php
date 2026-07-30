<?php
/**
 * A11yfy_Scan_Report — plain-language issue list from the stored scan verdict
 * resolved against the bundled Matterhorn catalog (§7 extension).
 *
 * @package a11yfy
 */

class Test_A11yfy_Scan_Report extends WP_UnitTestCase {

	private function make_pdf_with_scan( array $checks ) {
		$id = self::factory()->attachment->create_object(
			'2026/07/report.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		update_post_meta(
			$id,
			'_a11yfy_scan',
			array(
				'origin'     => 'client',
				'score'      => 40,
				'risk'       => 'high',
				'pages'      => 3,
				'tagged'     => false,
				'compliant'  => false,
				'checks'     => $checks,
				'scanned_at' => time(),
			)
		);
		return $id;
	}

	public function test_catalog_bundles_matterhorn_and_extra_entries() {
		$catalog = A11yfy_Scan_Report::catalog();
		$this->assertArrayHasKey( '06-001', $catalog, 'Matterhorn checkpoint catalog is bundled.' );
		$this->assertArrayHasKey( 'struct-tree-root', $catalog, 'Engine-specific extra checks are merged in.' );
		$this->assertArrayHasKey( 'markinfo-marked', $catalog );
		$this->assertNotEmpty( $catalog['06-001']['title'] );
	}

	public function test_details_resolves_failures_and_sorts_by_severity_then_count() {
		$id = $this->make_pdf_with_scan(
			array(
				array(
					'id'     => '06-003', // minor-ish metadata issue in the catalog.
					'group'  => 'metadata',
					'status' => 'fail',
					'count'  => 1,
					'items'  => array(),
				),
				array(
					'id'     => 'struct-tree-root', // critical (extra entry).
					'group'  => 'structural',
					'status' => 'fail',
					'count'  => 1,
					'items'  => array(),
				),
				array(
					'id'     => '01-005',
					'group'  => 'structural',
					'status' => 'fail',
					'count'  => 12,
					'items'  => array(
						array( 'page' => 2, 'detail' => 'x' ),
						array( 'page' => 1, 'detail' => 'y' ),
						array( 'page' => 2, 'detail' => 'z' ),
					),
				),
				array(
					'id'     => '06-001',
					'group'  => 'metadata',
					'status' => 'pass',
					'count'  => 0,
					'items'  => array(),
				),
			)
		);

		$details = A11yfy_Scan_Report::details( $id );

		$this->assertSame( 40, $details['score'] );
		$this->assertSame( 1, $details['passed'] );
		$this->assertCount( 3, $details['issues'], 'Only failed checks become issues.' );

		// Severity ordering: criticals first; equal severity → higher count first.
		$severities = wp_list_pluck( $details['issues'], 'severity' );
		$sorted     = $severities;
		$rank       = array( 'critical' => 0, 'major' => 1, 'minor' => 2 );
		usort( $sorted, static function ( $a, $b ) use ( $rank ) {
			return $rank[ $a ] - $rank[ $b ];
		} );
		$this->assertSame( $sorted, $severities );

		// The struct-tree-root issue carries the localized catalog text.
		$by_id = array_column( $details['issues'], null, 'id' );
		$this->assertNotSame( 'struct-tree-root', $by_id['struct-tree-root']['title'], 'Raw id replaced by plain-language title.' );
		$this->assertSame( 'critical', $by_id['struct-tree-root']['severity'] );

		// Page aggregation: unique + sorted.
		$this->assertSame( array( 1, 2 ), $by_id['01-005']['pages'] );
		$this->assertSame( 12, $by_id['01-005']['count'] );
	}

	public function test_unknown_check_id_falls_back_to_id_and_major() {
		$id = $this->make_pdf_with_scan(
			array(
				array(
					'id'     => '99-999',
					'group'  => 'misc',
					'status' => 'fail',
					'count'  => 2,
					'items'  => array(),
				),
			)
		);
		$issue = A11yfy_Scan_Report::details( $id )['issues'][0];
		$this->assertSame( '99-999', $issue['title'] );
		$this->assertSame( 'major', $issue['severity'] );
	}

	// ── Before/after view for remediated files ─────────────────────────────

	private function make_remediated_with_snapshot( array $before_checks, $with_current_scan = false, array $current_checks = array() ) {
		$id = self::factory()->attachment->create_object(
			'2026/07/remediated.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'remediated_path' => '/tmp/x.pdf',
				'original_hash'   => str_repeat( 'a', 64 ),
			)
		);
		update_post_meta(
			$id,
			'_a11yfy_scan_before',
			array(
				'origin'     => 'client',
				'checks'     => $before_checks,
				'scanned_at' => 1751000000,
			)
		);
		if ( $with_current_scan ) {
			update_post_meta(
				$id,
				'_a11yfy_scan',
				array(
					'origin' => 'client',
					'score'  => 90,
					'checks' => $current_checks,
				)
			);
		}
		return $id;
	}

	public function test_remediated_snapshot_renders_without_current_scan_all_fixed() {
		$id = $this->make_remediated_with_snapshot(
			array(
				array( 'id' => 'struct-tree-root', 'group' => 'structural', 'status' => 'fail', 'count' => 1, 'items' => array() ),
				array( 'id' => '06-001', 'group' => 'metadata', 'status' => 'fail', 'count' => 1, 'items' => array() ),
			)
		);

		$details = A11yfy_Scan_Report::details( $id );

		$this->assertNotNull( $details, 'Remediated file with snapshot is renderable even without a fresh scan.' );
		$this->assertFalse( $details['has_current'] );
		$this->assertTrue( $details['remediated'] );
		$this->assertSame( array(), $details['issues'] );
		$this->assertCount( 2, $details['before']['issues'] );
		foreach ( $details['before']['issues'] as $issue ) {
			$this->assertTrue( $issue['fixed'], 'Without a fresh scan the remediation output is trusted.' );
		}
	}

	public function test_remediated_snapshot_crosses_against_fresh_scan() {
		$id = $this->make_remediated_with_snapshot(
			array(
				array( 'id' => 'struct-tree-root', 'group' => 'structural', 'status' => 'fail', 'count' => 1, 'items' => array() ),
				array( 'id' => '06-001', 'group' => 'metadata', 'status' => 'fail', 'count' => 1, 'items' => array() ),
			),
			true,
			array(
				// 06-001 still fails after remediation; struct-tree-root is gone.
				array( 'id' => '06-001', 'group' => 'metadata', 'status' => 'fail', 'count' => 1, 'items' => array() ),
				array( 'id' => 'struct-tree-root', 'group' => 'structural', 'status' => 'pass', 'count' => 0, 'items' => array() ),
			)
		);

		$details = A11yfy_Scan_Report::details( $id );
		$this->assertTrue( $details['has_current'] );
		$by_id = array_column( $details['before']['issues'], null, 'id' );
		$this->assertTrue( $by_id['struct-tree-root']['fixed'] );
		$this->assertFalse( $by_id['06-001']['fixed'], 'An issue still failing in the fresh scan is not "fixed".' );
	}

	public function test_details_null_without_client_scan() {
		$id = self::factory()->attachment->create_object(
			'2026/07/noscan.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		$this->assertNull( A11yfy_Scan_Report::details( $id ) );

		update_post_meta( $id, '_a11yfy_scan', array( 'origin' => 'php-triage' ) );
		$this->assertNull( A11yfy_Scan_Report::details( $id ), 'PHP triage has no check list — not renderable.' );
	}
}
