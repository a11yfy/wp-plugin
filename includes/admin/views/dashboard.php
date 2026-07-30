<?php
/**
 * Dashboard view (§7). Variables are computed here on purpose — thin view,
 * data helpers live in A11yfy_Admin.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$a11yfy_stats     = A11yfy_Admin::stats();
$a11yfy_connected = A11yfy_Settings::is_connected();
// Force-refresh: the admin lands here right after topping up on a11yfy.com —
// a 15-minute-stale cached balance reads as "my purchase didn't arrive".
$a11yfy_balance  = $a11yfy_connected ? A11yfy_Queue::balance( true ) : null;
$a11yfy_estimate = A11yfy_Guardrails::estimate( A11yfy_Admin::non_compliant_ids() );
$a11yfy_spend    = A11yfy_Guardrails::month_spend();
$a11yfy_cap      = (int) A11yfy_Settings::get( 'monthly_cap' );
$a11yfy_can_pay  = current_user_can( 'manage_options' );
$a11yfy_wizard   = $a11yfy_connected && $a11yfy_can_pay && ! A11yfy_Settings::get( 'onboarded' );
?>
<div class="wrap a11yfy-wrap">
	<h1><?php esc_html_e( 'a11yfy — PDF Accessibility', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h1>

	<?php A11yfy_Admin::page_tabs( 'dashboard' ); ?>

	<div class="a11yfy-topbar">
		<?php if ( $a11yfy_connected ) : ?>
			<span class="a11yfy-conn a11yfy-conn--ok">✓ <?php esc_html_e( 'Connected to a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></span>
			<?php if ( is_array( $a11yfy_balance ) && isset( $a11yfy_balance['credits'] ) ) : ?>
				<span class="a11yfy-credits">
					<?php if ( ! empty( $a11yfy_balance['delegated'] ) ) : ?>
						<?php
						// Delegated key: credits = remaining allowance funded by the
						// parent organization — the site owner cannot top it up here.
						$a11yfy_billing_org = isset( $a11yfy_balance['billing_org_name'] ) ? (string) $a11yfy_balance['billing_org_name'] : '';
						if ( isset( $a11yfy_balance['limit'] ) && null !== $a11yfy_balance['limit'] && '' !== $a11yfy_billing_org ) {
							/* translators: 1: remaining delegated credits, 2: credit limit, 3: parent organization name. */
							echo esc_html( sprintf( __( 'Delegated credits: %1$d of %2$d left (provided by %3$s)', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $a11yfy_balance['credits'], (int) $a11yfy_balance['limit'], $a11yfy_billing_org ) );
						} elseif ( '' !== $a11yfy_billing_org ) {
							/* translators: 1: remaining delegated credits, 2: parent organization name. */
							echo esc_html( sprintf( __( 'Delegated credits: %1$d (provided by %2$s)', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $a11yfy_balance['credits'], $a11yfy_billing_org ) );
						} else {
							/* translators: %d: remaining delegated credits. */
							echo esc_html( sprintf( __( 'Delegated credits: %d', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $a11yfy_balance['credits'] ) );
						}
						?>
					<?php else : ?>
						<?php
						/* translators: %d: credit balance. */
						echo esc_html( sprintf( __( 'Credits: %d', 'a11yfy-pdf-accessibility-checker-fixer' ), (int) $a11yfy_balance['credits'] ) );
						?>
						<a href="https://a11yfy.com" target="_blank" rel="noopener" class="button button-small"><?php esc_html_e( 'Top up', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<?php if ( $a11yfy_cap > 0 ) : ?>
				<span class="a11yfy-cap">
					<?php
					/* translators: 1: credits spent this month, 2: monthly cap. */
					echo esc_html( sprintf( __( 'This month: %1$d / %2$d credits', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_spend, $a11yfy_cap ) );
					?>
				</span>
			<?php endif; ?>
		<?php else : ?>
			<span class="a11yfy-conn a11yfy-conn--off"><?php esc_html_e( 'Not connected — the free scan works without an account.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></span>
			<?php if ( $a11yfy_can_pay ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=a11yfy-settings' ) ); ?>">
					<?php esc_html_e( 'Connect a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php
	// Optimizer conflict warning (§13.6): PDF compression strips a11y tags.
	$a11yfy_optimizers = A11yfy_Optimizer_Guard::optimizer_warnings();
	if ( $a11yfy_optimizers ) :
		?>
		<div class="a11yfy-card a11yfy-optimizer-warning">
			<h2>⚠️ <?php esc_html_e( 'PDF optimization conflict detected', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
			<p>
				<?php esc_html_e( 'The plugins below compress PDF files. Compression rewrites the PDF and strips its accessibility structure (tags, language, PDF/UA metadata) — it can also destroy PDFs already remediated with your a11yfy credits.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
			</p>
			<ul>
				<?php foreach ( $a11yfy_optimizers as $a11yfy_opt ) : ?>
					<li>
						<strong><?php echo esc_html( $a11yfy_opt['name'] ); ?></strong>
						—
						<?php
						if ( $a11yfy_opt['per_file_guard'] ) {
							esc_html_e( 'files remediated by a11yfy are excluded automatically, but every other PDF is still at risk.', 'a11yfy-pdf-accessibility-checker-fixer' );
						} else {
							esc_html_e( 'this plugin offers no reliable per-file exclusion — a11yfy cannot protect individual PDFs from it.', 'a11yfy-pdf-accessibility-checker-fixer' );
						}
						if ( 'shortpixel' === $a11yfy_opt['id'] && $a11yfy_opt['can_disable'] && $a11yfy_can_pay ) {
							printf(
								' <button type="button" class="button" id="a11yfy-disable-sp-pdf" data-nonce="%s">%s</button>',
								esc_attr( wp_create_nonce( 'a11yfy_ajax' ) ),
								esc_html__( 'Turn off ShortPixel PDF optimization', 'a11yfy-pdf-accessibility-checker-fixer' )
							);
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $a11yfy_wizard ) : ?>
		<div class="a11yfy-card a11yfy-wizard">
			<h2>✓ <?php esc_html_e( 'Connected. How should we work?', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>

			<?php if ( $a11yfy_stats['non_compliant'] > 0 ) : ?>
				<p class="a11yfy-wizard__pain">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of non-compliant PDFs found by the free scan. */
							_n(
								'The free scan found %d PDF on this site that does not meet the accessibility requirements (EAA/ADA risk).',
								'The free scan found %d PDFs on this site that do not meet the accessibility requirements (EAA/ADA risk).',
								$a11yfy_stats['non_compliant'],
								'a11yfy-pdf-accessibility-checker-fixer'
							),
							$a11yfy_stats['non_compliant']
						)
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'a11yfy_onboarding' ); ?>
				<input type="hidden" name="action" value="a11yfy_save_onboarding" />

				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'How should we work?', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></legend>

					<label class="a11yfy-wizard__option">
						<input type="radio" name="a11yfy_mode" value="auto" checked="checked" />
						<span class="a11yfy-wizard__body">
							<strong><?php esc_html_e( 'Automatic (recommended)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							<span class="a11yfy-wizard__desc">
								<?php esc_html_e( 'Nothing for you to do: whenever a PDF is uploaded, it is made accessible automatically.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
							</span>
							<span class="a11yfy-wizard__cap">
								<?php
								printf(
									/* translators: %s: number input for the monthly credit cap. */
									esc_html__( 'Monthly cap: %s credits', 'a11yfy-pdf-accessibility-checker-fixer' ),
									'<input type="number" min="0" step="1" name="a11yfy_monthly_cap" value="'
										. esc_attr( $a11yfy_cap )
										. '" class="small-text" aria-label="'
										. esc_attr__( 'Monthly credit cap', 'a11yfy-pdf-accessibility-checker-fixer' ) . '" />'
								);
								?>
								<span class="description"><?php esc_html_e( '0 = no monthly limit.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></span>
							</span>
						</span>
					</label>

					<label class="a11yfy-wizard__option">
						<input type="radio" name="a11yfy_mode" value="manual" />
						<span class="a11yfy-wizard__body">
							<strong><?php esc_html_e( 'Manual', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							<span class="a11yfy-wizard__desc">
								<?php esc_html_e( 'You start remediation yourself — in the Media Library or on this Dashboard. Nothing runs without your action.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
							</span>
						</span>
					</label>
				</fieldset>

				<?php if ( 0 === $a11yfy_stats['total'] - $a11yfy_stats['unscanned'] && $a11yfy_stats['total'] > 0 ) : ?>
					<p class="description">
						<?php esc_html_e( 'Tip: run the free scan below first to see where this site stands — your choice above works either way.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</p>
				<?php endif; ?>

				<p>
					<button type="submit" class="button button-primary button-hero">
						<?php esc_html_e( 'Start working', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</button>
				</p>
			</form>
		</div>
	<?php endif; ?>

	<div class="a11yfy-card">
		<h2><?php esc_html_e( 'PDF status', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Free technical pre-check against the machine-verifiable PDF/UA-1 checkpoints — runs in your browser, no file leaves your site.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
		</p>

		<?php
		// Status buckets double as table filters (§7): toggling a chip narrows
		// the PDF list below to the selected buckets.
		$a11yfy_chips = array(
			'passed'     => array( '✅', __( 'Passed pre-check', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['compliant'] + $a11yfy_stats['low'] ),
			'partial'    => array( '⚠️', __( 'Partially accessible', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['medium'] ),
			'failing'    => array( '❌', __( 'Not accessible', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['high'] + $a11yfy_stats['critical'] ),
			'remediated' => array( '🛠', __( 'Remediated by a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['remediated'] ),
			'unscanned'  => array( '❔', __( 'Not scanned yet', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['unscanned'] ),
		);
		?>
		<div class="a11yfy-filters" id="a11yfy-filters" role="group" aria-label="<?php esc_attr_e( 'Filter the PDF list by status', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
			<?php // No chip selected by default: the unfiltered view is the full library, a click narrows it (§7 UX). ?>
			<?php foreach ( $a11yfy_chips as $a11yfy_key => $a11yfy_chip ) : ?>
				<button type="button" class="a11yfy-chip" data-status="<?php echo esc_attr( $a11yfy_key ); ?>" aria-pressed="false">
					<?php echo esc_html( $a11yfy_chip[0] . ' ' . $a11yfy_chip[1] ); ?>
					<span class="a11yfy-chip__count"><?php echo esc_html( $a11yfy_chip[2] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<table class="widefat striped" id="a11yfy-pdfs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
		<p id="a11yfy-pdfs-empty" hidden><?php esc_html_e( 'No PDFs match the selected filters.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
		<p>
			<button type="button" class="button" id="a11yfy-pdfs-more" hidden>
				<?php esc_html_e( 'Load more', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
			</button>
		</p>

		<p>
			<button type="button" class="button button-primary" id="a11yfy-scan-btn">
				<?php esc_html_e( 'Scan PDF library', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
			</button>
			<span id="a11yfy-scan-progress" class="a11yfy-progress" role="status" aria-live="polite"></span>
		</p>

		<?php if ( $a11yfy_stats['non_compliant'] > 0 && $a11yfy_connected && $a11yfy_can_pay ) : ?>
			<hr />
			<p>
				<?php
				/* translators: %d: number of non-compliant PDFs. */
				echo esc_html( sprintf( __( '%d documents do not meet the accessibility requirements (EAA/ADA risk).', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_stats['non_compliant'] ) );
				?>
			</p>
			<p>
				<button type="button" class="button button-hero button-primary" id="a11yfy-fix-all">
					<?php esc_html_e( 'Fix all', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</button>
				<span class="a11yfy-estimate">
					<?php
					$a11yfy_balance_credits = is_array( $a11yfy_balance ) && isset( $a11yfy_balance['credits'] ) ? (int) $a11yfy_balance['credits'] : 0;
					if ( $a11yfy_estimate['min'] === $a11yfy_estimate['max'] ) {
						echo esc_html(
							sprintf(
								/* translators: 1: estimated credits, 2: current balance. */
								__( 'estimated: up to ~%1$d credits — your balance: %2$d', 'a11yfy-pdf-accessibility-checker-fixer' ),
								$a11yfy_estimate['max'],
								$a11yfy_balance_credits
							)
						);
					} else {
						echo esc_html(
							sprintf(
								/* translators: 1: minimum credits, 2: maximum credits, 3: current balance. */
								__( 'estimated: %1$d–%2$d credits — your balance: %3$d', 'a11yfy-pdf-accessibility-checker-fixer' ),
								$a11yfy_estimate['min'],
								$a11yfy_estimate['max'],
								$a11yfy_balance_credits
							)
						);
					}
					?>
				</span>
			</p>
		<?php endif; ?>
	</div>

	<div class="a11yfy-card">
		<h2><?php esc_html_e( 'Remediation jobs', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
		<div id="a11yfy-jobs" data-empty="<?php esc_attr_e( 'No remediation has been run yet.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>">
			<table class="widefat striped" id="a11yfy-jobs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
						<th><?php esc_html_e( 'Result', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
						<th><?php esc_html_e( 'Credits', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</div>
</div>
