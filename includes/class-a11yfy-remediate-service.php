<?php
/**
 * Remediation orchestration: submit → poll → download → save (§4.1 flow).
 * All entry points are Action Scheduler callbacks (see A11yfy_Queue).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_RemediateService {

	const WATCHDOG_SECONDS = 7200; // §14/18: after 2h of polling → stalled.

	/**
	 * Step 1 — submit the PDF to POST /v1/jobs.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $source        auto|manual|bulk.
	 */
	public static function submit( $attachment_id, $source ) {
		$check = A11yfy_Guardrails::check( $attachment_id, $source );
		if ( is_wp_error( $check ) ) {
			// Guardrail skips are expected outcomes, not failures — record only
			// for user-initiated actions so the Media UI can show why.
			if ( 'auto' !== $source ) {
				update_post_meta(
					$attachment_id,
					'_a11yfy_last_skip',
					array(
						'code'    => $check->get_error_code(),
						'message' => $check->get_error_message(),
						'at'      => time(),
					)
				);
			}
			return;
		}
		delete_post_meta( $attachment_id, '_a11yfy_last_skip' );

		$file = get_attached_file( $attachment_id );
		$hash = hash_file( 'sha256', $file );

		// Reuse the still-open row for the same content (idempotent re-submit);
		// otherwise create a fresh one. Key = content hash + attachment (§4.1).
		$row_id = A11yfy_Jobs::create(
			$attachment_id,
			$hash,
			wp_basename( $file ),
			'wp-' . $hash . '-' . $attachment_id,
			$source
		);

		self::do_submit( $row_id );
	}

	/**
	 * Actual API submit for a job row (also the retry entry point — byte-identical
	 * request per §14/16: same file name, same idempotency key, no webhook_url).
	 *
	 * @param int $row_id Jobs table row ID.
	 */
	public static function do_submit( $row_id ) {
		$row = A11yfy_Jobs::get( $row_id );
		if ( ! $row || ! in_array( $row['status'], array( 'queued' ), true ) ) {
			return;
		}

		$file = get_attached_file( (int) $row['attachment_id'] );
		if ( ! $file || ! file_exists( $file ) || hash_file( 'sha256', $file ) !== $row['file_hash'] ) {
			A11yfy_Jobs::update(
				$row_id,
				array(
					'status'     => 'failed',
					'error_code' => 'a11yfy_file_changed',
				)
			);
			return;
		}

		$client = new A11yfy_ApiClient();
		// Hash-scoped user-confirm: only ever true after the explicit signed-PDF
		// confirmation for this exact content (see A11yfy_Ajax::remediate()).
		$allow_signed = A11yfy_Guardrails::signed_allowed( (int) $row['attachment_id'], $row['file_hash'] );
		$response     = $client->create_job( $file, $row['file_name'], $row['idempotency_key'], $allow_signed );

		if ( is_wp_error( $response ) ) {
			self::handle_submit_error( $row_id, $response );
			return;
		}

		A11yfy_Jobs::update(
			$row_id,
			array(
				'job_id'       => isset( $response['job_id'] ) ? (string) $response['job_id'] : null,
				'status'       => 'submitted',
				'submitted_at' => current_time( 'mysql', true ),
			)
		);

		A11yfy_Queue::schedule_poll( $row_id, 15 );
	}

	private static function handle_submit_error( $row_id, WP_Error $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();

		if ( 'retry' === A11yfy_ApiClient::retry_class( $code ) ) {
			$row      = A11yfy_Jobs::get( $row_id );
			$attempts = (int) $row['poll_attempts'];
			if ( $attempts < 3 ) {
				A11yfy_Jobs::update( $row_id, array( 'poll_attempts' => $attempts + 1 ) );
				$delay = isset( $data['retry_after'] ) ? max( 30, (int) $data['retry_after'] ) : 60 * pow( 2, $attempts );
				A11yfy_Queue::schedule_submit_retry( $row_id, $delay );
				return;
			}
		}

		// Fatal (content_loss, convert_timeout, validation_failed, 402, 409…) — no
		// retry, money-safe (§13.2). insufficient_credits also raises the notice.
		A11yfy_Jobs::update(
			$row_id,
			array(
				'status'        => 'failed',
				'error_code'    => $code,
				'error_message' => $error->get_error_message(),
			)
		);
		if ( 'insufficient_credits_api' === $code || 'delegated_limit_reached' === $code ) {
			// delegated_limit_reached: the parent-assigned allowance is exhausted —
			// the admin notice must not suggest a top-up (the site owner cannot).
			$notice = is_array( $data ) ? $data : array();
			if ( 'delegated_limit_reached' === $code ) {
				$notice['delegated'] = true;
			}
			set_transient( 'a11yfy_low_credit', $notice, DAY_IN_SECONDS );
		}
	}

	/**
	 * Step 2 — poll GET /v1/jobs/:id (webhooks are a feature-flag later; §14/3).
	 *
	 * @param int $row_id Jobs table row ID.
	 */
	public static function poll( $row_id ) {
		$row = A11yfy_Jobs::get( $row_id );
		if ( ! $row || ! $row['job_id'] ) {
			return;
		}
		// A 'finalizing' row reaching poll() came from the reconcile sweep — the
		// claim in finalize() decides whether it is a re-claimable orphan.
		if ( 'finalizing' === $row['status'] ) {
			self::finalize( $row_id );
			return;
		}
		if ( ! in_array( $row['status'], array( 'submitted', 'processing' ), true ) ) {
			return;
		}

		// Watchdog (§14/18).
		if ( $row['submitted_at'] && ( time() - strtotime( $row['submitted_at'] . ' UTC' ) ) > self::WATCHDOG_SECONDS ) {
			A11yfy_Jobs::update(
				$row_id,
				array(
					'status'        => 'stalled',
					'error_code'    => 'a11yfy_watchdog',
					'error_message' => __( 'The job did not finish within 2 hours. Please contact a11yfy support.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				)
			);
			return;
		}

		$client = new A11yfy_ApiClient();
		$status = $client->job_status( $row['job_id'] );

		if ( is_wp_error( $status ) ) {
			// Transient API trouble → keep polling on the normal schedule.
			A11yfy_Jobs::update( $row_id, array( 'poll_attempts' => (int) $row['poll_attempts'] + 1 ) );
			A11yfy_Queue::schedule_poll( $row_id, self::poll_delay( (int) $row['poll_attempts'] + 1 ) );
			return;
		}

		$state = isset( $status['status'] ) ? $status['status'] : 'processing';

		// 'partial' is terminális (contract 2026-07-06 additív bővítés) — a
		// /result dönti el, van-e letölthető kimenet.
		if ( in_array( $state, array( 'done', 'failed', 'partial' ), true ) ) {
			self::finalize( $row_id );
			return;
		}

		$attempts = (int) $row['poll_attempts'] + 1;
		A11yfy_Jobs::update(
			$row_id,
			array(
				'status'        => 'processing',
				'poll_attempts' => $attempts,
			)
		);
		A11yfy_Queue::schedule_poll( $row_id, self::poll_delay( $attempts ) );
	}

	/**
	 * Backoff: 15s → 30s → 60s → 120s → capped at 300s.
	 */
	private static function poll_delay( $attempts ) {
		return (int) min( 300, 15 * pow( 2, min( $attempts, 5 ) ) );
	}

	/**
	 * Step 3 — terminal state: fetch /result, download output, apply save strategy.
	 *
	 * @param int $row_id Jobs table row ID.
	 */
	public static function finalize( $row_id ) {
		$row = A11yfy_Jobs::get( $row_id );
		if ( ! $row || in_array( $row['status'], array( 'done', 'failed', 'stalled' ), true ) ) {
			return;
		}

		// Claim guard: poll and the reconcile sweep can call finalize()
		// concurrently — only the claim winner settles spend and saves the file
		// (a lost claim is simply the other worker doing the same work).
		if ( ! A11yfy_Jobs::claim_finalize( $row_id ) ) {
			return;
		}

		$client = new A11yfy_ApiClient();
		$result = $client->job_result( $row['job_id'] );

		if ( is_wp_error( $result ) ) {
			// Release the claim so the next poll can retry.
			A11yfy_Jobs::update( $row_id, array( 'status' => 'processing' ) );
			A11yfy_Queue::schedule_poll( $row_id, 60 );
			return;
		}

		$credits = isset( $result['credits_used'] ) ? (int) $result['credits_used'] : 0;
		$fields  = array(
			'credits_used'  => $credits,
			'treatment'     => isset( $result['treatment'] ) ? (string) $result['treatment'] : null,
			// Best-effort jelzés a szervertől: false = a kimenet nem érte el a
			// teljes PDF/UA-1 megfelelőséget (ingyenes). Régi szerver-válaszban
			// a mező hiányzik → NULL (ismeretlen), nem hamis "megfelelő".
			'compliant'     => array_key_exists( 'compliant', $result ) ? ( $result['compliant'] ? 1 : 0 ) : null,
			'before_issues' => isset( $result['before']['issues'] ) ? (int) $result['before']['issues'] : null,
			'before_pages'  => isset( $result['before']['pages'] ) ? (int) $result['before']['pages'] : null,
		);

		if ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
			$fields['status']     = 'failed';
			$fields['error_code'] = 'remediation_failed';
			A11yfy_Jobs::update( $row_id, $fields );
			return;
		}

		if ( $credits > 0 ) {
			A11yfy_Guardrails::add_spend( $credits );
		}

		// `noop` = already compliant, no output to download (0 credits, §13.8/#4).
		if ( isset( $result['treatment'] ) && 'noop' === $result['treatment'] ) {
			$fields['status'] = 'done';
			A11yfy_Jobs::update( $row_id, $fields );
			$scan = get_post_meta( (int) $row['attachment_id'], '_a11yfy_scan', true );
			if ( is_array( $scan ) ) {
				$scan['compliant'] = true;
				update_post_meta( (int) $row['attachment_id'], '_a11yfy_scan', $scan );
			}
			return;
		}

		if ( empty( $result['output_url'] ) ) {
			A11yfy_Jobs::update(
				$row_id,
				array_merge(
					$fields,
					array(
						'status'        => 'failed',
						'error_code'    => 'a11yfy_no_output',
						'error_message' => __( 'The job finished but no output file was provided.', 'a11yfy-pdf-accessibility-checker-fixer' ),
					)
				)
			);
			return;
		}

		A11yfy_Jobs::update( $row_id, $fields );
		$row = A11yfy_Jobs::get( $row_id );

		$applied = A11yfy_Replacer::apply( (int) $row['attachment_id'], $result['output_url'], $row );
		if ( is_wp_error( $applied ) && 'a11yfy_download_failed' === $applied->get_error_code() ) {
			// The presigned output URL may have expired (1h) between /result and
			// the download — fetch a fresh /result ONCE and retry before failing
			// the job (the credits are already spent, §13.2).
			$fresh = $client->job_result( $row['job_id'] );
			if ( ! is_wp_error( $fresh ) && ! empty( $fresh['output_url'] ) ) {
				$applied = A11yfy_Replacer::apply( (int) $row['attachment_id'], $fresh['output_url'], $row );
			}
		}
		if ( is_wp_error( $applied ) ) {
			A11yfy_Jobs::update(
				$row_id,
				array(
					'status'        => 'failed',
					'error_code'    => $applied->get_error_code(),
					'error_message' => $applied->get_error_message(),
				)
			);
			return;
		}

		self::store_certificate( (int) $row['attachment_id'], $result );

		A11yfy_Jobs::update( $row_id, array( 'status' => 'done' ) );

		/**
		 * Fires after a PDF was remediated and saved.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $applied       Map row fields.
		 */
		do_action( 'a11yfy_remediated', (int) $row['attachment_id'], $applied );
	}

	/**
	 * Persist the certificate block of a /result payload on the attachment.
	 * Overwrites on re-remediation (the newest certificate supersedes the
	 * previous one); clears the meta when the payload carries no certificate,
	 * so a stale certificate is never shown for a newer output file.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Decoded /result payload.
	 */
	public static function store_certificate( $attachment_id, $result ) {
		if ( empty( $result['certificate']['certificate_id'] ) ) {
			delete_post_meta( $attachment_id, '_a11yfy_certificate' );
			return;
		}
		$cert = $result['certificate'];
		update_post_meta(
			$attachment_id,
			'_a11yfy_certificate',
			array(
				'id'         => sanitize_text_field( (string) $cert['certificate_id'] ),
				'verify_url' => isset( $cert['verify_url'] ) ? esc_url_raw( (string) $cert['verify_url'] ) : '',
			)
		);
	}
}
