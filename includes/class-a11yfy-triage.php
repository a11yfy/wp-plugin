<?php
/**
 * Shallow PHP triage (§14/9): 4-5 cheap checks with the bundled smalot/pdfparser,
 * for uploads that happen with no browser open. The client-side deep scan
 * overwrites this verdict on the next dashboard visit.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Triage {

	/**
	 * Runs the shallow triage and stores the result as `_a11yfy_scan` post meta
	 * (origin=php). Never throws; silently skips when the parser can't run.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function run( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) || filesize( $file ) > A11yfy_Guardrails::MAX_BYTES ) {
			return;
		}

		// An authoritative client scan already exists for this exact file → keep it.
		$existing = get_post_meta( $attachment_id, '_a11yfy_scan', true );
		$hash     = hash_file( 'sha256', $file );
		if ( is_array( $existing ) && 'client' === ( isset( $existing['origin'] ) ? $existing['origin'] : '' )
			&& isset( $existing['file_hash'] ) && $existing['file_hash'] === $hash ) {
			return;
		}

		$verdict = self::analyze( $file );
		if ( null === $verdict ) {
			return;
		}

		update_post_meta(
			$attachment_id,
			'_a11yfy_scan',
			array_merge(
				$verdict,
				array(
					'origin'         => 'php',
					'file_hash'      => $hash,
					'engine_version' => 'php-triage-1',
					'scanned_at'     => time(),
				)
			)
		);
		// Indexed verdict meta — drives dashboard stats and the "fix all" target list.
		update_post_meta( $attachment_id, '_a11yfy_risk', $verdict['risk'] );
	}

	/**
	 * @param string $file Absolute path.
	 * @return array|null { tagged, has_lang, marked, scanned_likely, pages, risk, compliant }
	 */
	public static function analyze( $file ) {
		if ( ! self::parser_available() ) {
			return null;
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseFile( $file );

			$details = $pdf->getDetails();
			$pages   = isset( $details['Pages'] ) ? (int) $details['Pages'] : count( $pdf->getPages() );

			// Raw object scan: smalot exposes the object graph, not the struct tree (§1.2).
			$has_struct = false;
			$marked     = false;
			$has_lang   = false;
			foreach ( $pdf->getObjects() as $object ) {
				$header = $object->getHeader();
				if ( ! $header ) {
					continue;
				}
				if ( $header->has( 'StructTreeRoot' ) ) {
					$has_struct = true;
				}
				if ( $header->has( 'MarkInfo' ) ) {
					$mark_info = $header->get( 'MarkInfo' );
					if ( is_object( $mark_info ) && method_exists( $mark_info, 'has' ) && $mark_info->has( 'Marked' )
						&& 'true' === strtolower( (string) $mark_info->get( 'Marked' ) ) ) {
						$marked = true;
					}
				}
				if ( $header->has( 'Lang' ) && '' !== trim( (string) $header->get( 'Lang' ) ) ) {
					$has_lang = true;
				}
			}

			// Scanned heuristic: most pages carry no extractable text.
			$empty_pages = 0;
			$page_list   = $pdf->getPages();
			foreach ( $page_list as $page ) {
				$text = '';
				try {
					$text = $page->getText();
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// Extraction failure counts as empty.
				}
				if ( strlen( trim( $text ) ) < 10 ) {
					++$empty_pages;
				}
			}
			$page_count     = max( 1, count( $page_list ) );
			$scanned_likely = ( $empty_pages / $page_count ) >= 0.6;

			$tagged = $has_struct && $marked;
			if ( $tagged && $has_lang ) {
				$risk = 'medium'; // Shallow pass ≠ compliant — the deep scan decides.
			} elseif ( $tagged ) {
				$risk = 'high';
			} else {
				$risk = 'critical';
			}

			return array(
				'tagged'         => $tagged,
				'has_lang'       => $has_lang,
				'marked'         => $marked,
				'scanned_likely' => $scanned_likely,
				'pages'          => $pages > 0 ? $pages : $page_count,
				'risk'           => $risk,
				'score'          => null,   // Shallow triage never produces a score.
				'compliant'      => false,  // Only the deep client scan may mark green (§13.4).
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Bundled parser + required extensions present?
	 */
	public static function parser_available() {
		if ( ! extension_loaded( 'zlib' ) || ! function_exists( 'mb_strlen' ) || ! function_exists( 'iconv' ) ) {
			return false;
		}
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return true;
		}
		$src = A11YFY_PLUGIN_DIR . 'vendor/pdfparser/src/';
		if ( ! file_exists( $src . 'Smalot/PdfParser/Parser.php' ) ) {
			return false;
		}
		// Minimal PSR-4 autoloader — the bundled alt_autoload declares an
		// unprefixed global function, which could collide with other plugins.
		spl_autoload_register(
			function ( $class ) use ( $src ) {
				if ( 0 === strpos( $class, 'Smalot\\PdfParser\\' ) ) {
					$path = $src . str_replace( '\\', '/', $class ) . '.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		return class_exists( '\Smalot\PdfParser\Parser' );
	}
}
