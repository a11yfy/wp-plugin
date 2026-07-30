<?php
/**
 * A11yfy_Cache purge-routing tests (§13.6). The cache plugins themselves are
 * absent in the test env — we observe the hook-based backends (LiteSpeed
 * per-post / purge-all actions), which A11yfy_Cache always fires.
 *
 * @package a11yfy
 */

class Test_A11yfy_Cache extends WP_UnitTestCase {

	/** @var int[] Post IDs collected from the litespeed_purge_post hook. */
	private $purged_posts = array();

	/** @var int How many times a full purge was requested. */
	private $purged_all = 0;

	public function set_up() {
		parent::set_up();
		$this->purged_posts = array();
		$this->purged_all   = 0;
		add_action( 'litespeed_purge_post', array( $this, 'collect_post' ) );
		add_action( 'litespeed_purge_all', array( $this, 'collect_all' ) );
	}

	public function collect_post( $post_id ) {
		$this->purged_posts[] = (int) $post_id;
	}

	public function collect_all() {
		$this->purged_all++;
	}

	private function make_pdf_attachment() {
		$attachment_id = self::factory()->attachment->create_object(
			'2026/07/report.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/07/report.pdf' );
		return $attachment_id;
	}

	public function test_purges_posts_that_reference_the_pdf() {
		$attachment_id = $this->make_pdf_attachment();
		$post_id       = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="https://example.org/wp-content/uploads/2026/07/report.pdf">PDF</a>',
			)
		);

		A11yfy_Cache::purge_for_attachment( $attachment_id );

		$this->assertContains( $post_id, $this->purged_posts );
		$this->assertSame( 0, $this->purged_all, 'Targeted purge must not trigger a full purge.' );
	}

	public function test_falls_back_to_full_purge_when_nothing_references_the_pdf() {
		$attachment_id = $this->make_pdf_attachment();

		A11yfy_Cache::purge_for_attachment( $attachment_id );

		$this->assertSame( array(), $this->purged_posts );
		$this->assertSame( 1, $this->purged_all );
	}

	public function test_filter_can_disable_purging() {
		$attachment_id = $this->make_pdf_attachment();
		add_filter( 'a11yfy_cache_purge_enabled', '__return_false' );

		A11yfy_Cache::purge_for_attachment( $attachment_id );

		remove_filter( 'a11yfy_cache_purge_enabled', '__return_false' );
		$this->assertSame( array(), $this->purged_posts );
		$this->assertSame( 0, $this->purged_all );
	}

	public function test_attachment_parent_post_is_purged() {
		$parent_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachment_id = self::factory()->attachment->create_object(
			'2026/07/attached.pdf',
			$parent_id,
			array( 'post_mime_type' => 'application/pdf' )
		);
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/07/attached.pdf' );

		A11yfy_Cache::purge_for_attachment( $attachment_id );

		$this->assertContains( $parent_id, $this->purged_posts );
	}
}
