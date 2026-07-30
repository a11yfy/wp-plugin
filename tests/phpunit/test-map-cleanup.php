<?php
/**
 * Attachment deletion cleanup (§5.3): the map row and the plugin-created file
 * copies must not survive the attachment — orphan rows inflate the
 * "Remediated" counter, orphan backups pile up in uploads/a11yfy-backups.
 *
 * @package a11yfy
 */

class Test_Map_Cleanup extends WP_UnitTestCase {

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . A11yfy_Map::table() ); // phpcs:ignore WordPress.DB
		parent::tear_down();
	}

	private function make_pdf_attachment() {
		return self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
	}

	private function make_backup_file( $name ) {
		$path = trailingslashit( A11yfy_Install::ensure_backup_dir() ) . $name;
		file_put_contents( $path, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	public function test_delete_attachment_purges_map_row_and_backups() {
		$id         = $this->make_pdf_attachment();
		$backup     = $this->make_backup_file( $id . '-orig-test.pdf' );
		$rem_backup = $this->make_backup_file( $id . '-remediated-test.pdf' );

		A11yfy_Map::upsert(
			$id,
			array(
				'status'                 => 'active',
				'mode'                   => 'inplace',
				'original_hash'          => str_repeat( 'a', 64 ),
				'backup_path'            => $backup,
				'remediated_backup_path' => $rem_backup,
			)
		);

		wp_delete_attachment( $id, true );

		$this->assertNull( A11yfy_Map::for_attachment( $id ), 'Map row must not survive the attachment.' );
		$this->assertSame( 0, A11yfy_Map::counts()['remediated'], 'Remediated counter must not count ghosts.' );
		$this->assertFileDoesNotExist( $backup );
		$this->assertFileDoesNotExist( $rem_backup );
	}

	public function test_conservative_remediated_sibling_is_removed() {
		$id      = $this->make_pdf_attachment();
		$uploads = wp_get_upload_dir();
		$sibling = trailingslashit( $uploads['basedir'] ) . 'sibling-remediated-test.pdf';
		file_put_contents( $sibling, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		A11yfy_Map::upsert(
			$id,
			array(
				'status'          => 'active',
				'mode'            => 'conservative',
				'original_hash'   => str_repeat( 'b', 64 ),
				'remediated_path' => $sibling,
			)
		);

		wp_delete_attachment( $id, true );

		$this->assertNull( A11yfy_Map::for_attachment( $id ) );
		$this->assertFileDoesNotExist( $sibling );
	}

	public function test_paths_outside_uploads_are_left_alone() {
		$id      = $this->make_pdf_attachment();
		$outside = wp_tempnam( 'a11yfy-outside' );
		file_put_contents( $outside, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		A11yfy_Map::upsert(
			$id,
			array(
				'status'        => 'active',
				'mode'          => 'inplace',
				'original_hash' => str_repeat( 'c', 64 ),
				// Hostile/corrupt row: paths pointing outside our dirs.
				'backup_path'   => $outside,
			)
		);

		wp_delete_attachment( $id, true );

		$this->assertNull( A11yfy_Map::for_attachment( $id ), 'Row still cleaned up.' );
		$this->assertFileExists( $outside, 'Files outside uploads/a11yfy-backups must not be touched.' );
		wp_delete_file( $outside );
	}

	public function test_non_remediated_attachment_delete_is_noop() {
		$id = $this->make_pdf_attachment();
		wp_delete_attachment( $id, true );
		$this->assertNull( A11yfy_Map::for_attachment( $id ) );
	}
}
