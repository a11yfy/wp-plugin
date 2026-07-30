<?php
/**
 * Per-document detail page (§7 extension). Live client-side re-analysis with
 * a pdf.js canvas preview: the findings panel is the accessible source of
 * truth, the preview is a visual aid. Thin view — data helpers live in
 * A11yfy_Admin / A11yfy_Scan_Report; the dynamic parts are rendered by
 * assets/js/document.js.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$a11yfy_doc_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.

// Attachment-level authorization (not just the page capability): the detail
// page exposes per-document status/report data, so the current user must be
// allowed to edit THIS attachment. Same error card — no information leak.
if ( ! $a11yfy_doc_id || 'application/pdf' !== get_post_mime_type( $a11yfy_doc_id ) || ! current_user_can( 'edit_post', $a11yfy_doc_id ) ) {
	?>
	<div class="wrap a11yfy-wrap">
		<p class="a11yfy-doc__back">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=a11yfy' ) ); ?>">← <?php esc_html_e( 'Back to the a11yfy Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></a>
		</p>
		<div class="a11yfy-card">
			<h1><?php esc_html_e( 'Document not found', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h1>
			<p><?php esc_html_e( 'This link does not point to a PDF in the Media Library.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
		</div>
	</div>
	<?php
	return;
}

$a11yfy_file          = get_attached_file( $a11yfy_doc_id );
	$a11yfy_filename  = $a11yfy_file ? wp_basename( $a11yfy_file ) : get_the_title( $a11yfy_doc_id );
	$a11yfy_filesize  = $a11yfy_file && file_exists( $a11yfy_file ) ? size_format( (int) filesize( $a11yfy_file ) ) : '';
	$a11yfy_details   = A11yfy_Scan_Report::details( $a11yfy_doc_id );
	$a11yfy_map_row   = A11yfy_Map::for_attachment( $a11yfy_doc_id );
	$a11yfy_active    = $a11yfy_map_row && 'active' === $a11yfy_map_row['status'];
	$a11yfy_risk      = get_post_meta( $a11yfy_doc_id, '_a11yfy_risk', true );
	$a11yfy_connected = A11yfy_Settings::is_connected();
	$a11yfy_can_pay   = current_user_can( 'manage_options' );
	// ELSŐ elágazás mindig a "már akadálymentes": compliant/remediált doksinál
	// se javítás gomb, se blocker-tájékoztatás nem jelenik meg.
	$a11yfy_compliant   = $a11yfy_active || 'compliant' === $a11yfy_risk;
	$a11yfy_blocked     = $a11yfy_compliant ? '' : A11yfy_Guardrails::blocked_code( $a11yfy_doc_id );
	$a11yfy_blocked_msg = $a11yfy_blocked ? A11yfy_Guardrails::blocker_messages()[ $a11yfy_blocked ] : '';
	// Aláírt PDF: a javítás indítható, de a kattintás megerősítést kér
	// (a javítás érvényteleníti az aláírást) — lásd document.js.
	$a11yfy_signed   = 'signed' === $a11yfy_blocked;
	$a11yfy_fixable  = ! $a11yfy_compliant && ( '' === $a11yfy_blocked || $a11yfy_signed ) && ! A11yfy_Jobs::has_active( $a11yfy_doc_id );
	$a11yfy_can_fix  = $a11yfy_can_pay && $a11yfy_connected && $a11yfy_fixable;
	$a11yfy_locked   = $a11yfy_can_pay && ! $a11yfy_connected && $a11yfy_fixable;
	$a11yfy_edit_url = get_edit_post_link( $a11yfy_doc_id, 'raw' );
	$a11yfy_cert     = get_post_meta( $a11yfy_doc_id, '_a11yfy_certificate', true );
	$a11yfy_has_cert = $a11yfy_active && $a11yfy_connected && is_array( $a11yfy_cert ) && ! empty( $a11yfy_cert['id'] );
?>
<div class="wrap a11yfy-wrap">
	<p class="a11yfy-doc__back">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=a11yfy' ) ); ?>">← <?php esc_html_e( 'Back to the a11yfy Dashboard', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></a>
	</p>

	<div class="a11yfy-card a11yfy-doc__header">
		<h1 class="a11yfy-doc__title"><?php echo esc_html( $a11yfy_filename ); ?></h1>
		<p class="a11yfy-doc__meta">
			<span id="a11yfy-doc-badge"><?php echo wp_kses_post( A11yfy_Admin::badge_html( $a11yfy_doc_id ) ); ?></span>
			<?php if ( $a11yfy_filesize ) : ?>
				<span><?php echo esc_html( $a11yfy_filesize ); ?></span>
			<?php endif; ?>
			<span id="a11yfy-doc-pagecount"></span>
			<?php if ( $a11yfy_details && $a11yfy_details['scanned_at'] ) : ?>
				<span>
					<?php
					/* translators: %s: human-readable time difference. */
					echo esc_html( sprintf( __( 'last scanned %s ago', 'a11yfy-pdf-accessibility-checker-fixer' ), human_time_diff( $a11yfy_details['scanned_at'] ) ) );
					?>
				</span>
			<?php endif; ?>
		</p>
		<p class="a11yfy-doc__actions">
			<?php if ( $a11yfy_can_fix ) : ?>
				<button type="button" class="button button-primary" id="a11yfy-doc-fix" data-id="<?php echo esc_attr( $a11yfy_doc_id ); ?>"<?php echo $a11yfy_signed ? ' data-signed="1"' : ''; ?>>
					<?php esc_html_e( 'Fix with a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</button>
			<?php elseif ( $a11yfy_can_pay && $a11yfy_blocked && ! $a11yfy_signed ) : ?>
				<?php // Az ok CSAK a title-ben (description) él — aria-label-be téve a képernyőolvasók kétszer mondanák be. ?>
				<button type="button" class="button a11yfy-remediate--locked" disabled title="<?php echo esc_attr( $a11yfy_blocked_msg ); ?>" aria-label="<?php esc_attr_e( 'Fix with a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
					🔒 <?php esc_html_e( 'Fix with a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</button>
			<?php elseif ( $a11yfy_locked ) : ?>
				<button type="button" class="button a11yfy-remediate--locked" disabled title="<?php esc_attr_e( 'Connect your a11yfy account to enable PDF remediation.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
					<?php esc_html_e( 'Fix with a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $a11yfy_can_fix || $a11yfy_locked ) : ?>
				<?php // Szerver-oldali becslés a tárolt scanből; a friss élő elemzés után a document.js frissíti. ?>
				<?php $a11yfy_estimate = A11yfy_Admin::credit_estimate_label( $a11yfy_doc_id ); ?>
				<span id="a11yfy-doc-estimate" class="a11yfy-credit-est" <?php echo $a11yfy_estimate ? '' : 'hidden'; ?>><?php echo esc_html( $a11yfy_estimate ); ?></span>
			<?php endif; ?>
			<button type="button" class="button" id="a11yfy-doc-rescan"><?php esc_html_e( 'Re-run the check', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></button>
			<button type="button" class="button" id="a11yfy-doc-print"><?php esc_html_e( 'Print report', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></button>
			<?php if ( $a11yfy_active && $a11yfy_map_row && ! empty( $a11yfy_map_row['backup_path'] ) && file_exists( $a11yfy_map_row['backup_path'] ) ) : ?>
				<span class="a11yfy-doc__source" role="group" aria-label="<?php esc_attr_e( 'Which version to inspect', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
					<button type="button" class="button a11yfy-doc__source-btn is-active" id="a11yfy-doc-src-current" aria-pressed="true">
						<?php esc_html_e( 'Remediated (current)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</button>
					<button type="button" class="button a11yfy-doc__source-btn" id="a11yfy-doc-src-original" aria-pressed="false">
						<?php esc_html_e( 'Original (before)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</button>
				</span>
			<?php endif; ?>
			<?php if ( $a11yfy_has_cert ) : ?>
				<a class="button" href="<?php echo esc_url( A11yfy_Admin::certificate_url( $a11yfy_doc_id ) ); ?>">
					<?php esc_html_e( 'Download certificate', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $a11yfy_edit_url ) : ?>
				<a class="button" href="<?php echo esc_url( $a11yfy_edit_url ); ?>"><?php esc_html_e( 'Open in Media Library', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></a>
			<?php endif; ?>
			<?php if ( $a11yfy_has_cert && ! empty( $a11yfy_cert['verify_url'] ) ) : ?>
				<a class="a11yfy-doc__verify" href="<?php echo esc_url( $a11yfy_cert['verify_url'] ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Verify certificate online', 'a11yfy-pdf-accessibility-checker-fixer' ); ?> ↗
				</a>
			<?php endif; ?>
		</p>
		<p id="a11yfy-doc-blocked" class="a11yfy-doc__blocked" <?php echo $a11yfy_blocked ? '' : 'hidden'; ?>>
			<?php echo $a11yfy_blocked ? esc_html( '⚠ ' . $a11yfy_blocked_msg ) : ''; ?>
		</p>
	</div>

	<div class="a11yfy-doc">
		<aside class="a11yfy-doc__panel" aria-label="<?php esc_attr_e( 'Accessibility findings', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
			<div class="a11yfy-card">
				<p id="a11yfy-doc-progress" class="a11yfy-progress" role="status" aria-live="polite"></p>
				<?php // Best-effort remediáció (szerver compliant=false): egy mondatos, nem túlmagyarázott jelzés — a webes job-detail szövegével azonos. ?>
				<?php if ( $a11yfy_active && isset( $a11yfy_map_row['compliant'] ) && null !== $a11yfy_map_row['compliant'] && 0 === (int) $a11yfy_map_row['compliant'] ) : ?>
					<div class="a11yfy-doc__besteffort" role="note">
						<strong><?php esc_html_e( 'Best-effort repair applied', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
						<p><?php esc_html_e( 'The document received the best possible repair but did not fully meet the PDF/UA-1 standard. The output is available for free download.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
					</div>
				<?php endif; ?>
				<div id="a11yfy-doc-summary" class="a11yfy-doc__summary"></div>
				<div id="a11yfy-doc-doclevel"></div>
				<div id="a11yfy-doc-findings"></div>

				<?php if ( $a11yfy_active && $a11yfy_details && $a11yfy_details['before'] && $a11yfy_details['before']['issues'] ) : ?>
					<div class="a11yfy-doc__before">
						<h2 class="a11yfy-details__summary"><?php esc_html_e( 'Issues found before remediation', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
						<?php foreach ( $a11yfy_details['before']['issues'] as $a11yfy_issue ) : ?>
							<?php $a11yfy_fixed = ! empty( $a11yfy_issue['fixed'] ); ?>
							<div class="<?php echo esc_attr( 'a11yfy-issue a11yfy-issue--' . $a11yfy_issue['severity'] . ( $a11yfy_fixed ? ' is-fixed' : '' ) ); ?>">
								<p class="a11yfy-issue__head">
									<span class="a11yfy-issue__sev"><?php echo esc_html( $a11yfy_issue['severity_label'] ); ?></span>
									<strong><?php echo esc_html( $a11yfy_issue['title'] ); ?></strong>
									<span class="a11yfy-issue__count"><?php echo esc_html( $a11yfy_issue['count'] ); ?>×</span>
									<span class="<?php echo esc_attr( 'a11yfy-issue__outcome ' . ( $a11yfy_fixed ? 'is-ok' : 'is-bad' ) ); ?>">
										<?php echo $a11yfy_fixed ? '✓ ' . esc_html__( 'Fixed', 'a11yfy-pdf-accessibility-checker-fixer' ) : esc_html__( 'Still present', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
									</span>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="a11yfy-card a11yfy-doc__checklist">
				<h2><?php esc_html_e( 'Manual review checklist', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These aspects cannot be decided by a machine — a quick human review completes the picture.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</p>
				<ul>
					<?php foreach ( A11yfy_Scan_Report::manual_checklist() as $a11yfy_i => $a11yfy_item ) : ?>
						<li>
							<label>
								<input type="checkbox" id="a11yfy-check-<?php echo esc_attr( $a11yfy_i ); ?>" />
								<?php echo esc_html( $a11yfy_item ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</aside>

		<div class="a11yfy-doc__viewer a11yfy-card">
			<div class="a11yfy-doc__toolbar">
				<button type="button" class="button" id="a11yfy-doc-prev" aria-label="<?php esc_attr_e( 'Previous page', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">‹</button>
				<span id="a11yfy-doc-pageinfo" aria-live="polite"></span>
				<button type="button" class="button" id="a11yfy-doc-next" aria-label="<?php esc_attr_e( 'Next page', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">›</button>
				<span class="a11yfy-doc__toolbar-spacer"></span>
				<span id="a11yfy-doc-pagefindings" class="a11yfy-doc__pagefindings"></span>
				<button type="button" class="button" id="a11yfy-doc-zoomout" aria-label="<?php esc_attr_e( 'Zoom out', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">−</button>
				<button type="button" class="button" id="a11yfy-doc-zoomin" aria-label="<?php esc_attr_e( 'Zoom in', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">+</button>
			</div>
			<div class="a11yfy-doc__canvas-wrap" id="a11yfy-doc-canvas-wrap" tabindex="0">
				<div class="a11yfy-doc__canvas-holder">
					<canvas id="a11yfy-doc-canvas" aria-hidden="true"></canvas>
					<?php // Overlay boxes are visual aids only — the findings list is the accessible report. ?>
					<div class="a11yfy-doc__overlay" id="a11yfy-doc-overlay" aria-hidden="true"></div>
				</div>
			</div>
			<p class="description a11yfy-doc__viewer-note">
				<?php esc_html_e( 'The preview is a visual aid — the findings list on the left is the authoritative, screen-reader-friendly report.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
			</p>
		</div>
	</div>
</div>
