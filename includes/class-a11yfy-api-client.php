<?php
/**
 * /v1 API client — the ONLY place that knows the a11yfy REST contract (§13.9).
 *
 * Contract: docs/API-CONTRACT.md (v1-STABLE). Error envelope:
 * { code, message (HU), message_en } — surfaced as WP_Error( code, message ).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_ApiClient {

	/** @var string */
	private $api_key;

	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? (string) A11yfy_Settings::api_key() : $api_key;
	}

	public static function base_url() {
		return untrailingslashit( apply_filters( 'a11yfy_api_base', A11YFY_API_BASE ) );
	}

	// ── Endpoints ──────────────────────────────────────────────────────────

	/**
	 * GET /v1/balance.
	 *
	 * @return array|WP_Error { credits, subscription, one_time }
	 */
	public function balance() {
		return $this->request( 'GET', '/balance' );
	}

	/**
	 * GET /v1/jobs/:id.
	 *
	 * @return array|WP_Error { job_id, status, credits_used, error }
	 */
	public function job_status( $job_id ) {
		return $this->request( 'GET', '/jobs/' . rawurlencode( $job_id ) );
	}

	/**
	 * GET /v1/jobs/:id/result.
	 *
	 * @return array|WP_Error { status, credits_used, treatment, output_url, before, after }
	 */
	public function job_result( $job_id ) {
		return $this->request( 'GET', '/jobs/' . rawurlencode( $job_id ) . '/result' );
	}

	/**
	 * GET /v1/certificates/:id/download — raw certificate PDF bytes.
	 * Separate from request(): the response body is a PDF, not JSON.
	 *
	 * @param string $cert_id Certificate ID.
	 * @return string|WP_Error PDF bytes.
	 */
	public function certificate_download( $cert_id ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'a11yfy_not_connected', __( 'No a11yfy API key configured.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		$response = wp_remote_get(
			self::base_url() . '/certificates/' . rawurlencode( $cert_id ) . '/download',
			array(
				'timeout'    => 30,
				'user-agent' => 'a11yfy-wp/' . A11YFY_VERSION . '; ' . home_url(),
				'headers'    => array( 'Authorization' => 'Bearer ' . $this->api_key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'service_unavailable', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( 200 !== $status || '' === $body ) {
			return new WP_Error( 'a11yfy_cert_download_failed', __( 'Downloading the certificate failed — please try again.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}
		return $body;
	}

	/**
	 * POST /v1/jobs — multipart upload.
	 *
	 * Retry safety (§14/16): the caller passes a fixed idempotency key and a fixed
	 * file name; retries MUST be byte-identical or the server answers 409.
	 * The endpoint runs an inline diagnosis before the 202 → generous timeout (§14/7).
	 *
	 * @param string $file_path       Absolute path of the PDF.
	 * @param string $file_name       Fixed multipart filename.
	 * @param string $idempotency_key Fixed idempotency key.
	 * @return array|WP_Error { job_id, status, created_at }
	 */
	public function create_job( $file_path, $file_name, $idempotency_key, $allow_signed = false ) {
		$body = $this->build_multipart( $file_path, $file_name, $boundary, $allow_signed );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return $this->request(
			'POST',
			'/jobs',
			array(
				'headers' => array(
					'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
					'Idempotency-Key' => $idempotency_key,
				),
				'body'    => $body,
				'timeout' => 120,
			)
		);
	}

	// ── Internals ──────────────────────────────────────────────────────────

	/**
	 * @param string $file_path    Source file.
	 * @param string $file_name    Multipart filename (byte-identical across retries).
	 * @param string $boundary     Out param.
	 * @param bool   $allow_signed Signed-PDF opt-in (user-confirmed signature loss).
	 * @return string|WP_Error Raw multipart body.
	 */
	private function build_multipart( $file_path, $file_name, &$boundary, $allow_signed = false ) {
		// Deterministic boundary: identical retry bytes (server signs the payload).
		$boundary = 'a11yfyb' . substr( hash_file( 'sha256', $file_path ), 0, 24 );

		$contents = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new WP_Error( 'a11yfy_file_unreadable', __( 'The PDF file could not be read from disk.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$eol  = "\r\n";
		$body = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', $file_name ) . '"' . $eol
			. 'Content-Type: application/pdf' . $eol . $eol
			. $contents . $eol;
		if ( $allow_signed ) {
			// User-confirmed signed-PDF submit: without this field the API
			// rejects digitally signed documents with a typed `signed_pdf` error.
			$body .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="allow_signed"' . $eol . $eol
				. 'true' . $eol;
		}
		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}

	/**
	 * @param string $method GET|POST.
	 * @param string $path   Path under /v1.
	 * @param array  $args   Extra wp_remote_request args (headers/body/timeout).
	 * @return array|WP_Error Decoded JSON. WP_Error code = API error `code`;
	 *                        `a11yfy_http` data key carries the HTTP status,
	 *                        `retry_after` the Retry-After header when present.
	 */
	private function request( $method, $path, array $args = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'a11yfy_not_connected', __( 'No a11yfy API key configured.', 'a11yfy-pdf-accessibility-checker-fixer' ) );
		}

		$defaults = array(
			'method'     => $method,
			'timeout'    => 30,
			'user-agent' => 'a11yfy-wp/' . A11YFY_VERSION . '; ' . home_url(),
			'headers'    => array(),
		);
		$args     = array_replace_recursive( $defaults, $args );

		$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		$args['headers']['Accept']        = 'application/json';

		$response = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			// Network-level failure → treat as retryable service issue.
			return new WP_Error( 'service_unavailable', $response->get_error_message(), array( 'a11yfy_http' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $json ) ? $json : array();
		}

		$code    = isset( $json['code'] ) ? (string) $json['code'] : 'a11yfy_http_' . $status;
		$message = self::error_message( $json, $status );
		$data    = array( 'a11yfy_http' => $status );

		if ( isset( $json['required'], $json['available'] ) ) {
			$data['required']  = (int) $json['required'];
			$data['available'] = (int) $json['available'];
		}
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $retry_after ) {
			$data['retry_after'] = (int) $retry_after;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Pick the localized error message from the envelope (HU native, EN fallback).
	 */
	private static function error_message( $json, $status ) {
		$is_hu = 0 === strpos( determine_locale(), 'hu' );
		if ( is_array( $json ) ) {
			if ( $is_hu && ! empty( $json['message'] ) ) {
				return (string) $json['message'];
			}
			if ( ! empty( $json['message_en'] ) ) {
				return (string) $json['message_en'];
			}
			if ( ! empty( $json['message'] ) ) {
				return (string) $json['message'];
			}
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'a11yfy API error (HTTP %d).', 'a11yfy-pdf-accessibility-checker-fixer' ), $status );
	}

	/**
	 * Error-code → retry class (§13.2 retry matrix).
	 *
	 * @param string $code API error code.
	 * @return string 'retry' | 'fatal'
	 */
	public static function retry_class( $code ) {
		$retryable = array( 'provider_unavailable', 'service_unavailable', 'rate_limited', 'container_error' );
		return in_array( $code, $retryable, true ) ? 'retry' : 'fatal';
	}
}
