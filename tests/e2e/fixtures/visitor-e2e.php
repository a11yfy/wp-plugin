<?php
/**
 * E2E fixture dispatcher a látogatói on-demand flow-hoz.
 *
 * Futtatás a wp-env tests site-on:
 *   wp eval-file wp-content/plugins/wp-plugin/tests/e2e/fixtures/visitor-e2e.php <cmd> [args…]
 *
 * Parancsok:
 *   setup                    — connected állapot (dummy kulcs) + on_demand mód +
 *                              3 PDF attachment (2 nem megfelelő, 1 megfelelő) +
 *                              publikus oldal a linkekkel; JSON-t ír stdout-ra
 *   balance <credits>        — a11yfy_balance transient beállítása (soft-gate vezérlés)
 *   requests <attachment_id> — a requests tábla sorai az attachmentre (JSON)
 *   purge-requests           — a requests tábla ürítése (teszt-izoláció)
 *   mark-remediated <id>     — remediálás szimulálása (map active + compliant scan)
 *   custom-texts <title>     — testreszabott modal-cím beállítása
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$a11yfy_e2e_cmd = isset( $args[0] ) ? $args[0] : 'setup';

/**
 * Minimal, viewer-tolerated egyoldalas PDF az uploads könyvtárba.
 *
 * @param string $name     Fájlnév.
 * @param bool   $compliant Tárolt scan-verdict.
 * @return array { id: int, url: string }
 */
function a11yfy_e2e_make_pdf( $name, $compliant ) {
	$uploads = wp_get_upload_dir();
	$file    = trailingslashit( $uploads['basedir'] ) . $name;

	$pdf = "%PDF-1.4\n"
		. "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
		. "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
		. "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
		. "trailer<</Root 1 0 R/Size 4>>\n%%EOF\n";
	file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	// Idempotens: azonos nevű korábbi fixture-t újrahasznosítjuk.
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'title'       => $name,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		$id = (int) $existing[0];
	} else {
		$id = wp_insert_attachment(
			array(
				'post_title'     => $name,
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			),
			$file
		);
	}
	update_post_meta( $id, '_wp_attached_file', $name );
	update_post_meta(
		$id,
		'_a11yfy_scan',
		array(
			'compliant' => $compliant,
			'score'     => $compliant ? 100 : 35,
			'pages'     => 1,
			'tagged'    => $compliant,
		)
	);
	delete_post_meta( $id, '_a11yfy_blocked' );

	return array(
		'id'  => $id,
		'url' => trailingslashit( $uploads['baseurl'] ) . $name,
	);
}

switch ( $a11yfy_e2e_cmd ) {

	case 'setup':
		// Connected állapot dummy kulccsal — a kulcs sosem megy ki hálózatra
		// (a soft-gate-et a balance transient fedi, a submit-út a tesztben
		// nem futtatott AS-callback), csak az is_connected() gate-hez kell.
		A11yfy_Settings::set_api_key( 'ak_test_' . str_repeat( '0', 16 ) );
		A11yfy_Settings::update(
			array(
				'mode'                 => 'on_demand',
				'monthly_cap'          => 0,
				'low_credit_threshold' => 0,
				'onboarded'            => true,
				'visitor_modal_title'  => '',
			)
		);

		$a11yfy_bad_a = a11yfy_e2e_make_pdf( 'e2e-visitor-bad-a.pdf', false );
		$a11yfy_bad_b = a11yfy_e2e_make_pdf( 'e2e-visitor-bad-b.pdf', false );
		$a11yfy_ok    = a11yfy_e2e_make_pdf( 'e2e-visitor-ok.pdf', true );

		$a11yfy_content = sprintf(
			'<p><a href="%1$s">Bad PDF A</a></p><p><a href="%2$s">Bad PDF B</a></p><p><a href="%3$s">OK PDF</a></p>',
			esc_url( $a11yfy_bad_a['url'] ),
			esc_url( $a11yfy_bad_b['url'] ),
			esc_url( $a11yfy_ok['url'] )
		);

		$a11yfy_page = get_page_by_path( 'a11yfy-e2e-visitor' );
		if ( $a11yfy_page ) {
			wp_update_post(
				array(
					'ID'           => $a11yfy_page->ID,
					'post_content' => $a11yfy_content,
					'post_status'  => 'publish',
				)
			);
			$a11yfy_page_id = $a11yfy_page->ID;
		} else {
			$a11yfy_page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => 'a11yfy-e2e-visitor',
					'post_title'   => 'a11yfy visitor e2e',
					'post_content' => $a11yfy_content,
				)
			);
		}

		// Friss lap: korábbi futások kérései + a map-sorok ne szivárogjanak át.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', A11yfy_Requests::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( $a11yfy_bad_a['id'], $a11yfy_bad_b['id'], $a11yfy_ok['id'] ) as $a11yfy_aid ) {
			A11yfy_Map::delete( $a11yfy_aid );
			// Az URL→ID cache negatív találata ne ragadjon be a korábbi futásból.
			delete_transient( 'a11yfy_url2id_' . hash( 'sha256', untrailingslashit( (string) wp_get_attachment_url( $a11yfy_aid ) ) ) );
		}
		delete_transient( 'a11yfy_pending_notify' );

		echo wp_json_encode(
			array(
				'page_url' => get_permalink( $a11yfy_page_id ),
				'bad_a'    => $a11yfy_bad_a,
				'bad_b'    => $a11yfy_bad_b,
				'ok'       => $a11yfy_ok,
			)
		);
		break;

	case 'balance':
		set_transient( 'a11yfy_balance', array( 'credits' => (int) $args[1] ), 15 * MINUTE_IN_SECONDS );
		echo 'ok';
		break;

	case 'requests':
		global $wpdb;
		echo wp_json_encode(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT email, status FROM %i WHERE attachment_id = %d ORDER BY id ASC',
					A11yfy_Requests::table(),
					(int) $args[1]
				),
				ARRAY_A
			)
		);
		break;

	case 'purge-requests':
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', A11yfy_Requests::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// A per-IP óránkénti rate-limit számlálók is nullázódnak, hogy az
		// egymás utáni suite-futások ne fogyasszák el egymás keretét.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'a11yfy_vrl_' ) . '%'
			)
		);
		delete_transient( 'a11yfy_pending_notify' );
		echo 'ok';
		break;

	case 'mark-remediated':
		$a11yfy_aid  = (int) $args[1];
		$a11yfy_file = get_attached_file( $a11yfy_aid );
		A11yfy_Map::upsert(
			$a11yfy_aid,
			array(
				'mode'          => 'inplace',
				'original_hash' => $a11yfy_file ? hash_file( 'sha256', $a11yfy_file ) : str_repeat( 'e', 64 ),
				'status'        => 'active',
				'treatment'     => 'technical',
			)
		);
		$a11yfy_scan = get_post_meta( $a11yfy_aid, '_a11yfy_scan', true );
		if ( is_array( $a11yfy_scan ) ) {
			$a11yfy_scan['compliant'] = true;
			update_post_meta( $a11yfy_aid, '_a11yfy_scan', $a11yfy_scan );
		}
		echo 'ok';
		break;

	case 'custom-texts':
		A11yfy_Settings::update( array( 'visitor_modal_title' => (string) $args[1] ) );
		echo 'ok';
		break;

	default:
		echo 'unknown command';
}
