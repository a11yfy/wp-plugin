<?php
/**
 * Human-readable scan details (§7 extension): the stored client-scan verdict
 * (_a11yfy_scan.checks) resolved against the curated Matterhorn catalog — the
 * same plain-language texts the a11yfy web app uses (matterhorn.{lang}.json,
 * synced from src/a11y_pdf/i18n/). Users see WHAT is wrong and HOW OFTEN,
 * not just a pass/fail badge.
 *
 * N-locale ready: a new language is DATA — drop matterhorn.{lang}.json into
 * languages/ and it is picked up; no code change. Fallback: en.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Scan_Report {

	/**
	 * Locale tag of the bundled catalog matching the current user locale.
	 */
	public static function catalog_locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$tag    = strtolower( substr( (string) $locale, 0, 2 ) );
		if ( $tag && file_exists( A11YFY_PLUGIN_DIR . 'languages/matterhorn.' . $tag . '.json' ) ) {
			return $tag;
		}
		return 'en';
	}

	/**
	 * Checkpoint id → { title, description, severity_label, suggested_fix }.
	 * Catalog + the plugin's own extra checks, request-cached.
	 *
	 * @return array<string,array>
	 */
	public static function catalog() {
		static $catalog = null;
		if ( null !== $catalog ) {
			return $catalog;
		}
		$file    = A11YFY_PLUGIN_DIR . 'languages/matterhorn.' . self::catalog_locale() . '.json';
		$decoded = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset.
		$catalog = array_merge( is_array( $decoded ) ? $decoded : array(), self::extra_entries() );
		return $catalog;
	}

	/**
	 * Engine checks that have no Matterhorn checkpoint id — translated via the
	 * regular .po pipeline (30 languages), so they are data-driven too.
	 */
	protected static function extra_entries() {
		return array(
			'struct-tree-root' => array(
				'title'          => __( 'The PDF has no tag structure at all', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The document contains no structure tree (tags). Screen readers get no headings, paragraphs, lists or tables — the content is read as an unstructured stream, or not at all.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'critical',
				'suggested_fix'  => __( 'Remediation rebuilds the full tag structure automatically.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'markinfo-marked'  => array(
				'title'          => __( 'The PDF does not declare itself as tagged', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The MarkInfo entry is missing or not set to Marked. Assistive technology cannot trust the tag structure even if parts of it exist.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'major',
				'suggested_fix'  => __( 'Remediation sets the tagged-PDF flag together with a valid structure tree.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'26-001'           => array(
				'title'          => __( 'The encrypted file is missing its permissions entry', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The file is encrypted but its encryption settings have no permissions entry, so there is no guarantee that screen readers may access the content.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'critical',
				'suggested_fix'  => __( 'Re-save the file with encryption settings that explicitly allow assistive-technology access.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'26-002'           => array(
				'title'          => __( 'The encryption blocks screen reader access', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The file\'s encryption settings do not allow content extraction by assistive technology, so a screen reader cannot read the document at all.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'critical',
				'suggested_fix'  => __( 'Re-save the file with encryption settings that allow assistive-technology access.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'figure-untagged'  => array(
				'title'          => __( 'An image is invisible to screen readers', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The image is neither tagged as a Figure nor marked as decorative (artifact). A screen reader user never learns it exists — even a text description cannot be attached.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'critical',
				'suggested_fix'  => __( 'Remediation tags real images as Figures with a description and marks decorative ones as artifacts.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'graphics-untagged' => array(
				'title'          => __( 'Unmarked graphic elements in the document', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The document contains lines, filled shapes or gradients that are neither tagged as content nor marked as decorative. PDF/UA validators flag these as untagged content.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'major',
				'suggested_fix'  => __( 'Remediation marks decorative graphics (borders, separators, backgrounds) as artifacts and tags the ones that carry content.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'lang-mismatch'    => array(
				'title'          => __( 'The declared document language does not match the text', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The PDF declares one language, but the page text appears to be written in another. Screen readers pick their voice and pronunciation from the declared language, so the content would be read with the wrong voice.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'minor',
				'suggested_fix'  => __( 'Remediation sets the document language to the language the text is actually written in.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'quality-no-headings' => array(
				'title'          => __( 'The document has no headings at all', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The document contains substantial text but not a single heading element. Screen reader users navigate primarily by headings — without them the document must be read linearly from start to finish.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'minor',
				'suggested_fix'  => __( 'Tag the section titles as headings (H1–H6) so the document structure becomes navigable.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'quality-no-outline'  => array(
				'title'          => __( 'A long document has no bookmarks', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'The document is ten pages or longer but has no bookmark outline. Bookmarks let every reader — not just assistive technology users — jump straight to a section.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'minor',
				'suggested_fix'  => __( 'Add a bookmark outline that mirrors the document’s section structure.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'quality-table-no-th' => array(
				'title'          => __( 'A table has no header cells', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'A data table contains no TH header cells. Screen readers announce the matching header before each cell — without headers the user hears bare numbers with no context.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'minor',
				'suggested_fix'  => __( 'Tag the first row and/or column as TH header cells with an appropriate Scope.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
			'quality-suspect-alt' => array(
				'title'          => __( 'An image description looks like a placeholder', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'description'    => __( 'An image has an alternative text, but it looks like a file name or a generic placeholder (e.g. “image3.png”). A screen reader user learns nothing about what the image actually shows.', 'a11yfy-pdf-accessibility-checker-fixer' ),
				'severity_label' => 'minor',
				'suggested_fix'  => __( 'Replace the placeholder with a description that conveys the meaning of the image.', 'a11yfy-pdf-accessibility-checker-fixer' ),
			),
		);
	}

	/**
	 * Severity label → localized UI text (catalog labels are canonical slugs).
	 */
	public static function severity_labels() {
		return array(
			'critical' => __( 'Critical', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'major'    => __( 'Serious', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'minor'    => __( 'Minor', 'a11yfy-pdf-accessibility-checker-fixer' ),
		);
	}

	/**
	 * User-facing finding categories for the document detail page. Slug order
	 * is the display order.
	 *
	 * @return array<string,string> slug → localized label.
	 */
	public static function categories() {
		return array(
			'tagging'  => __( 'Tagging & structure', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'headings' => __( 'Headings', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'figures'  => __( 'Images & formulas', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'tables'   => __( 'Tables', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'lists'    => __( 'Lists & table of contents', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'forms'    => __( 'Forms, links & annotations', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'language' => __( 'Language', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'metadata' => __( 'Metadata & display', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'security' => __( 'Encryption & permissions', 'a11yfy-pdf-accessibility-checker-fixer' ),
			'other'    => __( 'Other technical', 'a11yfy-pdf-accessibility-checker-fixer' ),
		);
	}

	/**
	 * Engine check → category. Default comes from the check's engine group;
	 * per-id overrides split the mixed groups (syntax, attributes, misc).
	 *
	 * @return array { groups: array<string,string>, ids: array<string,string> }
	 */
	public static function category_map() {
		return array(
			'groups' => array(
				'structural'  => 'tagging',
				'rolemap'     => 'tagging',
				'headings'    => 'headings',
				'attributes'  => 'tagging',
				'syntax'      => 'tagging',
				'annotations' => 'forms',
				'content'     => 'figures',
				'fonts'       => 'other',
				'quality'     => 'other',
				'metadata'    => 'metadata',
				'encryption'  => 'security',
				'misc'        => 'other',
			),
			'ids'    => array(
				'09-004' => 'tables',
				'09-005' => 'lists',
				'09-006' => 'lists',
				'13-004' => 'figures',
				'17-002' => 'figures',
				'15-003' => 'tables',
				'11-006' => 'language',
				'11-001' => 'language',
				'11-003' => 'language',
				'lang-mismatch' => 'language',
				'graphics-untagged' => 'tagging',
				'quality-no-headings' => 'headings',
				'quality-no-outline' => 'lists',
				'quality-table-no-th' => 'tables',
				'quality-suspect-alt' => 'figures',
			),
		);
	}

	/**
	 * Manual review checklist (§ detail page): the machine cannot decide these
	 * Matterhorn conditions — honest guidance instead of silence.
	 *
	 * @return string[] Localized checklist items.
	 */
	public static function manual_checklist() {
		return array(
			__( 'Is the reading order logical when the content is read from start to finish?', 'a11yfy-pdf-accessibility-checker-fixer' ),
			__( 'Do image descriptions (alt texts) convey the meaning — not just a file name?', 'a11yfy-pdf-accessibility-checker-fixer' ),
			__( 'Is the text contrast sufficient (at least 4.5:1 against the background)?', 'a11yfy-pdf-accessibility-checker-fixer' ),
			__( 'Are link texts meaningful on their own (“click here” is not)?', 'a11yfy-pdf-accessibility-checker-fixer' ),
			__( 'Do tables have real header cells and a simple, regular structure?', 'a11yfy-pdf-accessibility-checker-fixer' ),
			__( 'Is the document title informative when shown in a reader’s title bar?', 'a11yfy-pdf-accessibility-checker-fixer' ),
		);
	}

	/**
	 * Resolve one stored check list into a sorted plain-language issue list.
	 *
	 * @param array $checks _a11yfy_scan['checks'].
	 * @return array { issues: array[], passed: int }
	 */
	protected static function issues_from_checks( array $checks ) {
		$catalog  = self::catalog();
		$sev_rank = array(
			'critical' => 0,
			'major'    => 1,
			'minor'    => 2,
		);
		$labels   = self::severity_labels();

		$issues = array();
		$passed = 0;
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['id'], $check['status'] ) ) {
				continue;
			}
			if ( 'pass' === $check['status'] ) {
				++$passed;
			}
			if ( 'fail' !== $check['status'] ) {
				continue;
			}
			$entry    = isset( $catalog[ $check['id'] ] ) ? $catalog[ $check['id'] ] : array();
			$severity = isset( $entry['severity_label'] ) && isset( $sev_rank[ $entry['severity_label'] ] ) ? $entry['severity_label'] : 'major';

			$pages = array();
			foreach ( isset( $check['items'] ) && is_array( $check['items'] ) ? $check['items'] : array() as $item ) {
				if ( ! empty( $item['page'] ) ) {
					$pages[ (int) $item['page'] ] = true;
				}
			}
			$pages = array_keys( $pages );
			sort( $pages );

			$issues[] = array(
				'id'             => $check['id'],
				'count'          => max( 1, isset( $check['count'] ) ? (int) $check['count'] : 0 ),
				'severity'       => $severity,
				'severity_label' => $labels[ $severity ],
				'title'          => isset( $entry['title'] ) ? $entry['title'] : $check['id'],
				'description'    => isset( $entry['description'] ) ? $entry['description'] : '',
				'fix'            => isset( $entry['suggested_fix'] ) ? $entry['suggested_fix'] : '',
				'pages'          => $pages,
			);
		}

		usort(
			$issues,
			static function ( $a, $b ) use ( $sev_rank ) {
				if ( $sev_rank[ $a['severity'] ] !== $sev_rank[ $b['severity'] ] ) {
					return $sev_rank[ $a['severity'] ] - $sev_rank[ $b['severity'] ];
				}
				return $b['count'] - $a['count'];
			}
		);

		return array(
			'issues' => $issues,
			'passed' => $passed,
		);
	}

	/** Valid client-scan meta or null. */
	protected static function client_scan( $attachment_id, $meta_key ) {
		$scan = get_post_meta( $attachment_id, $meta_key, true );
		if ( ! is_array( $scan ) || 'client' !== ( isset( $scan['origin'] ) ? $scan['origin'] : '' ) ) {
			return null;
		}
		return $scan;
	}

	/**
	 * Details payload for the dashboard panel: the current verdict and — for
	 * remediated files — the pre-remediation snapshot so the panel can show
	 * "these issues existed, they are fixed now" (§7).
	 *
	 * @param int $attachment_id Attachment.
	 * @return array|null Null when there is nothing renderable.
	 */
	public static function details( $attachment_id ) {
		$scan = self::client_scan( $attachment_id, '_a11yfy_scan' );

		$map_row    = A11yfy_Map::for_attachment( $attachment_id );
		$remediated = $map_row && 'active' === $map_row['status'];
		$snapshot   = $remediated ? self::client_scan( $attachment_id, '_a11yfy_scan_before' ) : null;

		if ( null === $scan && null === $snapshot ) {
			return null;
		}

		$current = $scan
			? self::issues_from_checks( isset( $scan['checks'] ) && is_array( $scan['checks'] ) ? $scan['checks'] : array() )
			: array(
				'issues' => array(),
				'passed' => 0,
			);

		$before = null;
		if ( $snapshot ) {
			$resolved = self::issues_from_checks( isset( $snapshot['checks'] ) && is_array( $snapshot['checks'] ) ? $snapshot['checks'] : array() );
			// Fixed = gone from the current verdict; without a fresh post-
			// remediation scan the remediation output is trusted (re-scan
			// confirms it on the next dashboard scan run).
			$current_fail_ids = $scan ? wp_list_pluck( $current['issues'], 'id' ) : null;
			foreach ( $resolved['issues'] as &$issue ) {
				$issue['fixed'] = null === $current_fail_ids || ! in_array( $issue['id'], $current_fail_ids, true );
			}
			unset( $issue );
			$before = array(
				'issues'     => $resolved['issues'],
				'scanned_at' => isset( $snapshot['scanned_at'] ) ? (int) $snapshot['scanned_at'] : 0,
			);
		}

		return array(
			'has_current' => (bool) $scan,
			'score'       => $scan && isset( $scan['score'] ) ? (int) $scan['score'] : null,
			'risk'        => $scan && isset( $scan['risk'] ) ? $scan['risk'] : '',
			'pages'       => $scan && isset( $scan['pages'] ) ? (int) $scan['pages'] : 0,
			'tagged'      => $scan && ! empty( $scan['tagged'] ),
			'compliant'   => $scan && ! empty( $scan['compliant'] ),
			'scanned_at'  => $scan && isset( $scan['scanned_at'] ) ? (int) $scan['scanned_at'] : 0,
			'passed'      => $current['passed'],
			'issues'      => $current['issues'],
			'remediated'  => $remediated,
			'before'      => $before,
		);
	}
}
