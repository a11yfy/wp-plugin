<?php
/**
 * Certificate persistence (SDK-01): the /result payload's certificate block
 * is stored on the attachment for the download proxy and the admin UI; a
 * payload without a certificate clears stale meta (a newer output supersedes
 * the previous certificate).
 *
 * @package a11yfy
 */

class Test_Certificate extends WP_UnitTestCase {

	private function make_pdf_attachment() {
		return self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
	}

	public function test_store_certificate_persists_id_and_verify_url() {
		$id = $this->make_pdf_attachment();

		A11yfy_RemediateService::store_certificate(
			$id,
			array(
				'certificate' => array(
					'certificate_id' => 'cert_abc123',
					'download_url'   => 'https://a11yfy.com/v1/certificates/cert_abc123/download',
					'verify_url'     => 'https://a11yfy.com/en/verify/cert_abc123',
				),
			)
		);

		$meta = get_post_meta( $id, '_a11yfy_certificate', true );
		$this->assertSame( 'cert_abc123', $meta['id'] );
		$this->assertSame( 'https://a11yfy.com/en/verify/cert_abc123', $meta['verify_url'] );
	}

	public function test_store_certificate_without_block_clears_stale_meta() {
		$id = $this->make_pdf_attachment();
		update_post_meta( $id, '_a11yfy_certificate', array( 'id' => 'cert_old' ) );

		A11yfy_RemediateService::store_certificate( $id, array( 'treatment' => 'technical' ) );

		$this->assertSame( '', get_post_meta( $id, '_a11yfy_certificate', true ) );
	}

	public function test_store_certificate_re_remediation_overwrites() {
		$id = $this->make_pdf_attachment();
		A11yfy_RemediateService::store_certificate(
			$id,
			array( 'certificate' => array( 'certificate_id' => 'cert_v1' ) )
		);
		A11yfy_RemediateService::store_certificate(
			$id,
			array(
				'certificate' => array(
					'certificate_id' => 'cert_v2',
					'verify_url'     => 'https://a11yfy.com/en/verify/cert_v2',
				),
			)
		);

		$meta = get_post_meta( $id, '_a11yfy_certificate', true );
		$this->assertSame( 'cert_v2', $meta['id'] );
	}
}
