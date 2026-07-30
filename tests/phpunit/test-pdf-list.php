<?php
/**
 * A11yfy_Admin::pdf_list() bucket filtering (dashboard table, §7).
 *
 * @package a11yfy
 */

class Test_A11yfy_Pdf_List extends WP_UnitTestCase {

	private function make_pdf( $risk = null ) {
		static $n = 0;
		$n++;
		$id = self::factory()->attachment->create_object(
			"2026/07/doc-{$n}.pdf",
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		if ( $risk ) {
			update_post_meta( $id, '_a11yfy_risk', $risk );
		}
		return $id;
	}

	public function test_buckets_filter_by_risk_meta() {
		$passed    = $this->make_pdf( 'compliant' );
		$low       = $this->make_pdf( 'low' );
		$partial   = $this->make_pdf( 'medium' );
		$failing   = $this->make_pdf( 'critical' );
		$unscanned = $this->make_pdf();

		$ids = function ( $statuses ) {
			return wp_list_pluck( A11yfy_Admin::pdf_list( $statuses )['items'], 'id' );
		};

		$this->assertEqualSets( array( $passed, $low ), $ids( array( 'passed' ) ) );
		$this->assertEqualSets( array( $partial ), $ids( array( 'partial' ) ) );
		$this->assertEqualSets( array( $failing ), $ids( array( 'failing' ) ) );
		$this->assertEqualSets( array( $unscanned ), $ids( array( 'unscanned' ) ) );
		$this->assertEqualSets(
			array( $passed, $low, $partial, $failing, $unscanned ),
			$ids( array( 'passed', 'partial', 'failing', 'remediated', 'unscanned' ) )
		);
		$this->assertEqualSets(
			array( $passed, $low, $partial, $failing, $unscanned ),
			$ids( array() ),
			'No selected chip = no filter → the full library.'
		);
	}

	public function test_compliant_file_gets_no_fix_button() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' );

		$compliant = $this->make_pdf( 'compliant' );
		$failing   = $this->make_pdf( 'high' );

		$by_id = array_column( A11yfy_Admin::pdf_list( array() )['items'], null, 'id' );

		$this->assertFalse( $by_id[ $compliant ]['remediate'], 'Nothing to fix on a compliant file.' );
		$this->assertTrue( $by_id[ $failing ]['remediate'] );
	}

	public function test_active_map_row_wins_over_stale_risk_meta() {
		$id = $this->make_pdf( 'high' );
		A11yfy_Map::upsert(
			$id,
			array(
				'mode'            => 'inplace',
				'status'          => 'active',
				'treatment'       => 'remediate',
				'remediated_path' => '/tmp/x.pdf',
				'backup_path'     => '/tmp/x.bak.pdf',
			)
		);

		$remediated = wp_list_pluck( A11yfy_Admin::pdf_list( array( 'remediated' ) )['items'], 'id' );
		$failing    = wp_list_pluck( A11yfy_Admin::pdf_list( array( 'failing' ) )['items'], 'id' );

		$this->assertContains( $id, $remediated, 'Active map row puts the doc in the remediated bucket…' );
		$this->assertNotContains( $id, $failing, '…and removes it from the risk-meta bucket (badge precedence).' );
	}

	public function test_pagination_counts_total() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->make_pdf( 'medium' );
		}
		$page1 = A11yfy_Admin::pdf_list( array( 'partial' ), 1, 20 );
		$page2 = A11yfy_Admin::pdf_list( array( 'partial' ), 2, 20 );

		$this->assertSame( 25, $page1['total'] );
		$this->assertCount( 20, $page1['items'] );
		$this->assertCount( 5, $page2['items'] );
	}
}
