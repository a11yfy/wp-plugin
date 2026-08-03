<?php
/**
 * Visitor on-demand mode (feature spec 2026-08-03): request repository,
 * URL→attachment resolution, status decision matrix, public REST handlers,
 * notify lifecycle and the pending-credit park/resume loop.
 *
 * @package a11yfy
 */

class Test_A11yfy_Visitor extends WP_UnitTestCase {

	/** @var array Mocked HTTP responses per URL fragment. */
	private $http_mocks = array();

	public function set_up() {
		parent::set_up();

		A11yfy_Settings::set_api_key( 'ak_test_0123456789abcdef' );
		A11yfy_Settings::update( array( 'mode' => 'on_demand' ) );

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_transient( A11yfy_Visitor_Notify::PENDING_NOTIFY_TRANSIENT );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function mock_http( $preempt, $args, $url ) {
		foreach ( $this->http_mocks as $fragment => $mock ) {
			if ( false !== strpos( $url, $fragment ) ) {
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => $mock['code'],
						'message' => 'Mock',
					),
					'body'     => wp_json_encode( $mock['body'] ),
					'cookies'  => array(),
				);
			}
		}
		return $preempt;
	}

	/**
	 * Attachment with a real uploads file + _wp_attached_file meta.
	 *
	 * @return array { id: int, url: string, file: string }
	 */
	private function make_pdf_attachment( $name = 'visitor-doc.pdf' ) {
		$uploads = wp_get_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . $name;
		file_put_contents( $file, "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Visitor Doc',
			)
		);
		update_post_meta( $id, '_wp_attached_file', $name );

		return array(
			'id'   => $id,
			'url'  => trailingslashit( $uploads['baseurl'] ) . $name,
			'file' => $file,
		);
	}

	private function mark_not_accessible( $attachment_id ) {
		update_post_meta(
			$attachment_id,
			'_a11yfy_scan',
			array(
				'compliant' => false,
				'score'     => 40,
				'pages'     => 2,
			)
		);
	}

	private function sent_mail_count() {
		return count( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	private function request_rows( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE attachment_id = %d ORDER BY id ASC', A11yfy_Requests::table(), $attachment_id ),
			ARRAY_A
		);
	}

	// ── Requests repository ────────────────────────────────────────────────

	public function test_add_is_idempotent_per_attachment_and_email() {
		$doc = $this->make_pdf_attachment();

		$this->assertTrue( A11yfy_Requests::add( $doc['id'], 'v@example.com', 'hash', 'hu_HU' ) );
		$this->assertFalse( A11yfy_Requests::add( $doc['id'], 'v@example.com', 'hash', 'hu_HU' ) );
		$this->assertCount( 1, A11yfy_Requests::open_for_attachment( $doc['id'] ) );
	}

	public function test_pending_attachments_ordered_by_oldest_request() {
		global $wpdb;
		$a = $this->make_pdf_attachment( 'a.pdf' );
		$b = $this->make_pdf_attachment( 'b.pdf' );

		A11yfy_Requests::add( $b['id'], 'first@example.com', 'h', 'en_US' );
		A11yfy_Requests::add( $a['id'], 'second@example.com', 'h', 'en_US' );
		// Backdate b's request so it is the oldest.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET created_at = %s WHERE attachment_id = %d',
				A11yfy_Requests::table(),
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				$b['id']
			)
		);
		A11yfy_Requests::set_status_for_attachment( $a['id'], 'pending_credit' );
		A11yfy_Requests::set_status_for_attachment( $b['id'], 'pending_credit' );

		$this->assertSame( array( $b['id'], $a['id'] ), A11yfy_Requests::pending_attachments() );
	}

	// ── URL → attachment resolution ────────────────────────────────────────

	public function test_resolve_url_finds_attachment_and_rejects_foreign() {
		$doc = $this->make_pdf_attachment();

		$this->assertSame( $doc['id'], A11yfy_Visitor::resolve_url( $doc['url'] ) );
		// Query strings are stripped before resolution.
		$this->assertSame( $doc['id'], A11yfy_Visitor::resolve_url( $doc['url'] . '?ver=3' ) );
		$this->assertSame( 0, A11yfy_Visitor::resolve_url( 'https://evil.example/doc.pdf' ) );
		$this->assertSame( 0, A11yfy_Visitor::resolve_url( home_url( '/page/' ) ) );
	}

	public function test_resolve_url_finds_conservative_sibling() {
		$doc     = $this->make_pdf_attachment();
		$uploads = wp_get_upload_dir();
		$sibling = trailingslashit( $uploads['basedir'] ) . 'visitor-doc-accessible.pdf';
		file_put_contents( $sibling, '%PDF-1.7 sibling' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		A11yfy_Map::upsert(
			$doc['id'],
			array(
				'mode'            => 'conservative',
				'remediated_path' => $sibling,
				'original_hash'   => str_repeat( 'a', 64 ),
				'status'          => 'active',
			)
		);

		$sibling_url = trailingslashit( $uploads['baseurl'] ) . 'visitor-doc-accessible.pdf';
		$this->assertSame( $doc['id'], A11yfy_Visitor::resolve_url( $sibling_url ) );
	}

	// ── Status decision matrix ─────────────────────────────────────────────

	public function test_status_matrix() {
		$doc = $this->make_pdf_attachment();

		// No scan data → unknown (fail-open).
		$this->assertSame( 'unknown', A11yfy_Visitor::status_for_attachment( $doc['id'] )['status'] );

		// Non-compliant scan → not_accessible.
		$this->mark_not_accessible( $doc['id'] );
		$this->assertSame( 'not_accessible', A11yfy_Visitor::status_for_attachment( $doc['id'] )['status'] );

		// Blocker → unknown (the pipeline cannot promise a result).
		update_post_meta( $doc['id'], A11yfy_Guardrails::BLOCKED_META, 'signed' );
		$this->assertSame( 'unknown', A11yfy_Visitor::status_for_attachment( $doc['id'] )['status'] );
		delete_post_meta( $doc['id'], A11yfy_Guardrails::BLOCKED_META );

		// In-flight job → processing.
		$row_id = A11yfy_Jobs::create( $doc['id'], str_repeat( 'b', 64 ), 'visitor-doc.pdf', 'wp-test-key', 'visitor' );
		$this->assertSame( 'processing', A11yfy_Visitor::status_for_attachment( $doc['id'] )['status'] );
		A11yfy_Jobs::update( $row_id, array( 'status' => 'failed' ) );

		// Compliant scan → accessible.
		update_post_meta(
			$doc['id'],
			'_a11yfy_scan',
			array(
				'compliant' => true,
				'score'     => 100,
			)
		);
		$this->assertSame( 'accessible', A11yfy_Visitor::status_for_attachment( $doc['id'] )['status'] );
	}

	public function test_status_conservative_map_exposes_accessible_url() {
		$doc     = $this->make_pdf_attachment();
		$uploads = wp_get_upload_dir();
		$sibling = trailingslashit( $uploads['basedir'] ) . 'visitor-doc-accessible.pdf';
		file_put_contents( $sibling, '%PDF-1.7 sibling' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		A11yfy_Map::upsert(
			$doc['id'],
			array(
				'mode'            => 'conservative',
				'remediated_path' => $sibling,
				'original_hash'   => str_repeat( 'a', 64 ),
				'status'          => 'active',
			)
		);

		$status = A11yfy_Visitor::status_for_attachment( $doc['id'] );
		$this->assertSame( 'accessible', $status['status'] );
		$this->assertSame( trailingslashit( $uploads['baseurl'] ) . 'visitor-doc-accessible.pdf', $status['accessible_url'] );
	}

	// ── REST handlers ──────────────────────────────────────────────────────

	private function status_request( array $urls ) {
		$request = new WP_REST_Request( 'POST', '/a11yfy/v1/pdf-status' );
		$request->set_param( 'urls', $urls );
		return A11yfy_Visitor::handle_status( $request );
	}

	private function remediation_request( $url, $email, $hp = '' ) {
		$request = new WP_REST_Request( 'POST', '/a11yfy/v1/request-remediation' );
		$request->set_param( 'url', $url );
		$request->set_param( 'email', $email );
		$request->set_param( 'hp', $hp );
		return A11yfy_Visitor::handle_request( $request );
	}

	public function test_status_endpoint_batch() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );

		$response = $this->status_request( array( $doc['url'], 'https://evil.example/x.pdf' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'not_accessible', $data['statuses'][ $doc['url'] ]['status'] );
		$this->assertSame( 'unknown', $data['statuses']['https://evil.example/x.pdf']['status'] );
	}

	public function test_status_endpoint_disabled_outside_on_demand_mode() {
		A11yfy_Settings::update( array( 'mode' => 'auto' ) );
		$response = $this->status_request( array( home_url( '/x.pdf' ) ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_request_honeypot_pretends_success_without_row() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );

		$response = $this->remediation_request( $doc['url'], 'v@example.com', 'http://spam' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $this->request_rows( $doc['id'] ) );
	}

	public function test_request_rejects_invalid_email_and_unknown_url() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );

		$this->assertSame( 400, $this->remediation_request( $doc['url'], 'not-an-email' )->get_status() );
		$this->assertSame( 404, $this->remediation_request( 'https://evil.example/x.pdf', 'v@example.com' )->get_status() );
	}

	public function test_request_already_accessible_short_circuits() {
		$doc = $this->make_pdf_attachment();
		update_post_meta(
			$doc['id'],
			'_a11yfy_scan',
			array(
				'compliant' => true,
			)
		);

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 'already_accessible', $response->get_data()['state'] );
		$this->assertCount( 0, $this->request_rows( $doc['id'] ) );
	}

	public function test_request_blocked_pdf_returns_409() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		update_post_meta( $doc['id'], A11yfy_Guardrails::BLOCKED_META, 'xfa' );

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'not_possible', $response->get_data()['error'] );
		$this->assertCount( 0, $this->request_rows( $doc['id'] ) );
	}

	public function test_request_with_active_job_rides_along() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Jobs::create( $doc['id'], str_repeat( 'c', 64 ), 'visitor-doc.pdf', 'wp-active-key', 'manual' );

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 'queued', $response->get_data()['state'] );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertCount( 1, $rows );
		// No second job was created — the subscriber rides on the running one.
		$this->assertSame( 'queued', $rows[0]['status'] );
	}

	public function test_request_monthly_cap_parks_immediately() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Settings::update( array( 'monthly_cap' => 1 ) );
		A11yfy_Guardrails::add_spend( 5 );
		set_transient( 'a11yfy_balance', array( 'credits' => 1000 ), 300 );

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 'pending', $response->get_data()['state'] );
		$this->assertSame( 'pending_credit', $this->request_rows( $doc['id'] )[0]['status'] );
	}

	public function test_request_with_sufficient_balance_queues_job() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		set_transient( 'a11yfy_balance', array( 'credits' => 1000 ), 300 );

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 'queued', $response->get_data()['state'] );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'queued', $rows[0]['status'] );
	}

	public function test_request_without_balance_parks_and_warns_admin() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		set_transient( 'a11yfy_balance', array( 'credits' => 1 ), 300 );

		$response = $this->remediation_request( $doc['url'], 'v@example.com' );
		$this->assertSame( 'pending', $response->get_data()['state'] );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'pending_credit', $rows[0]['status'] );
		$this->assertSame( 1, $this->sent_mail_count() );

		// Throttled: a second parked request must not mail again.
		$this->remediation_request( $doc['url'], 'w@example.com' );
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	// ── Notify lifecycle ───────────────────────────────────────────────────

	public function test_job_finished_done_notifies_and_marks_rows() {
		$doc = $this->make_pdf_attachment();
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );
		A11yfy_Requests::add( $doc['id'], 'two@example.com', 'h', 'en_US' );

		do_action( 'a11yfy_job_finished', $doc['id'], 'done', array( 'error_code' => null ) );

		$this->assertSame( 2, $this->sent_mail_count() );
		foreach ( $this->request_rows( $doc['id'] ) as $row ) {
			$this->assertSame( 'done_notified', $row['status'] );
			$this->assertNotEmpty( $row['notified_at'] );
		}
	}

	public function test_notify_uses_custom_template_with_placeholders() {
		$doc = $this->make_pdf_attachment();
		A11yfy_Settings::update(
			array(
				'visitor_email_subject' => 'Ready: {document_title}',
				'visitor_email_body'    => 'Get it at {document_url}',
			)
		);
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'de_DE' );

		do_action( 'a11yfy_job_finished', $doc['id'], 'done', array() );

		$mail = tests_retrieve_phpmailer_instance()->mock_sent[0];
		$this->assertSame( 'Ready: Visitor Doc', $mail['subject'] );
		$this->assertStringContainsString( $doc['url'], $mail['body'] );
	}

	public function test_job_finished_credit_failure_parks_requests() {
		$doc = $this->make_pdf_attachment();
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );
		set_transient( 'a11yfy_balance', array( 'credits' => 0 ), 300 );

		do_action( 'a11yfy_job_finished', $doc['id'], 'failed', array( 'error_code' => 'insufficient_credits_api' ) );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'pending_credit', $rows[0]['status'] );
	}

	public function test_job_finished_hard_failure_fails_requests_without_email() {
		$doc = $this->make_pdf_attachment();
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );

		do_action( 'a11yfy_job_finished', $doc['id'], 'failed', array( 'error_code' => 'content_loss' ) );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'failed', $rows[0]['status'] );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	// ── 402 at submit re-parks (integration through the real pipeline) ─────

	public function test_submit_402_reparks_visitor_requests() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );

		$this->http_mocks['/jobs']    = array(
			'code' => 402,
			'body' => array(
				'code'      => 'insufficient_credits_api',
				'message'   => 'Not enough credits',
				'required'  => 15,
				'available' => 2,
			),
		);
		// The 402 → pending_credit transition triggers admin_pending_notify(),
		// which fetches /balance for the delegated-aware email copy.
		$this->http_mocks['/balance'] = array(
			'code' => 200,
			'body' => array( 'credits' => 2 ),
		);

		A11yfy_RemediateService::submit( $doc['id'], 'visitor' );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'pending_credit', $rows[0]['status'] );
		// The job row itself is failed (money-safe), the request lifecycle lives on.
		$job = A11yfy_Jobs::latest_for_attachment( $doc['id'] );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'insufficient_credits_api', $job['error_code'] );
	}

	// ── Guard-skip on visitor submits ──────────────────────────────────────

	public function test_guard_skip_monthly_cap_parks_requests() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );
		A11yfy_Settings::update( array( 'monthly_cap' => 1 ) );
		A11yfy_Guardrails::add_spend( 5 );
		set_transient( 'a11yfy_balance', array( 'credits' => 100 ), 300 );

		A11yfy_RemediateService::submit( $doc['id'], 'visitor' );

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'pending_credit', $rows[0]['status'] );
	}

	// ── Resume loop ────────────────────────────────────────────────────────

	public function test_credit_check_resumes_pending_when_balance_recovers() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );
		A11yfy_Requests::set_status_for_attachment( $doc['id'], 'pending_credit' );

		// Low-credit warning disabled (threshold 0) — the resume must run anyway (F3).
		A11yfy_Settings::update( array( 'low_credit_threshold' => 0 ) );
		$this->http_mocks['/balance'] = array(
			'code' => 200,
			'body' => array( 'credits' => 1000 ),
		);

		A11yfy_Queue::credit_check();

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'queued', $rows[0]['status'] );
	}

	public function test_credit_check_keeps_parked_when_balance_still_low() {
		$doc = $this->make_pdf_attachment();
		$this->mark_not_accessible( $doc['id'] );
		A11yfy_Requests::add( $doc['id'], 'one@example.com', 'h', 'en_US' );
		A11yfy_Requests::set_status_for_attachment( $doc['id'], 'pending_credit' );

		A11yfy_Settings::update( array( 'low_credit_threshold' => 0 ) );
		$this->http_mocks['/balance'] = array(
			'code' => 200,
			'body' => array( 'credits' => 1 ),
		);

		A11yfy_Queue::credit_check();

		$rows = $this->request_rows( $doc['id'] );
		$this->assertSame( 'pending_credit', $rows[0]['status'] );
	}

	// ── Purge / retention ──────────────────────────────────────────────────

	public function test_purge_removes_old_terminal_rows() {
		global $wpdb;
		$doc = $this->make_pdf_attachment();
		A11yfy_Requests::add( $doc['id'], 'old@example.com', 'h', 'en_US' );
		A11yfy_Requests::set_status_for_attachment( $doc['id'], 'failed' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET updated_at = %s WHERE attachment_id = %d',
				A11yfy_Requests::table(),
				gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
				$doc['id']
			)
		);

		A11yfy_Requests::purge();

		$this->assertCount( 0, $this->request_rows( $doc['id'] ) );
	}

	public function test_mode_whitelist_accepts_on_demand() {
		A11yfy_Settings::update( array( 'mode' => 'on_demand' ) );
		$this->assertSame( 'on_demand', A11yfy_Settings::get( 'mode' ) );
		$this->assertTrue( A11yfy_Visitor::enabled() );

		A11yfy_Settings::update( array( 'mode' => 'manual' ) );
		$this->assertFalse( A11yfy_Visitor::enabled() );
	}
}
