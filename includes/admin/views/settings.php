<?php
/**
 * Settings view. Manual API-key flow (NVP-α); the connect-flow lands with M3.0.
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$a11yfy_settings  = A11yfy_Settings::all();
$a11yfy_connected = A11yfy_Settings::is_connected();
// Multi-org (W4): delegált kulcsnál a szerveroldali (szülői) limit is fékez —
// a plafon-mező mellett jelezni kell, hogy két független plafon él.
$a11yfy_balance   = $a11yfy_connected ? A11yfy_Queue::balance() : null;
$a11yfy_delegated = is_array( $a11yfy_balance ) && ! empty( $a11yfy_balance['delegated'] );
?>
<div class="wrap a11yfy-wrap">
	<h1><?php esc_html_e( 'a11yfy Settings', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h1>

	<?php A11yfy_Admin::page_tabs( 'settings' ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'a11yfy_settings' ); ?>
		<input type="hidden" name="action" value="a11yfy_save_settings" />

		<h2><?php esc_html_e( 'Connection', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>

		<?php if ( ! $a11yfy_connected ) : ?>
			<p>
				<a class="button button-primary button-hero"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=a11yfy_connect_start' ), 'a11yfy_connect' ) ); ?>">
					<?php esc_html_e( 'Connect to a11yfy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'One click: log in or register on a11yfy.com, approve the access, done. Alternatively, paste an API key below.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
			</p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="a11yfy_api_key"><?php esc_html_e( 'API key', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<?php if ( $a11yfy_connected ) : ?>
						<p>
							<code><?php echo esc_html( A11yfy_Settings::masked_key() ); ?></code>
							— <?php esc_html_e( 'connected', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
							<?php if ( ! empty( $a11yfy_settings['org_name'] ) ) : ?>
								<br />
								<?php
								/* translators: %s: organization name the key belongs to. */
								echo esc_html( sprintf( __( 'Connected to: %s', 'a11yfy-pdf-accessibility-checker-fixer' ), (string) $a11yfy_settings['org_name'] ) );
								?>
							<?php endif; ?>
						</p>
						<label>
							<input type="checkbox" name="a11yfy_disconnect" value="1" />
							<?php esc_html_e( 'Disconnect (remove the stored key)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'To replace the key, paste a new one below.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
					<?php endif; ?>
					<input type="password" class="regular-text" id="a11yfy_api_key" name="a11yfy_api_key" value=""
						autocomplete="off" placeholder="ak_live_…" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the a11yfy account page. */
							esc_html__( 'Create a key on %s under Account → API keys. The key is stored encrypted and is never sent to the browser.', 'a11yfy-pdf-accessibility-checker-fixer' ),
							'<a href="https://a11yfy.com" target="_blank" rel="noopener">a11yfy.com</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<?php if ( $a11yfy_connected ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status updates', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
					<td>
						<?php if ( ! empty( $a11yfy_settings['webhook_mode'] ) ) : ?>
							<p>✓ <?php esc_html_e( 'Instant (webhook) — a11yfy notifies this site the moment a job finishes; polling remains as a safety net.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Polling — the site checks job status in the background. Reconnecting via the button flow enables instant webhook updates when your site is publicly reachable.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php esc_html_e( 'How should we work?', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="a11yfy_mode" value="auto" <?php checked( $a11yfy_settings['mode'], 'auto' ); ?> />
							<strong><?php esc_html_e( 'Automatic (recommended)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							— <?php esc_html_e( 'new PDF uploads are made accessible automatically.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label><br />
						<label>
							<input type="radio" name="a11yfy_mode" value="on_demand" <?php checked( $a11yfy_settings['mode'], 'on_demand' ); ?> />
							<strong><?php esc_html_e( 'On demand (visitor requests)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							— <?php esc_html_e( 'when a visitor clicks a non-accessible PDF, they can request an accessible version — remediation runs only on real demand.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label><br />
						<label>
							<input type="radio" name="a11yfy_mode" value="manual" <?php checked( $a11yfy_settings['mode'], 'manual' ); ?> />
							<strong><?php esc_html_e( 'Manual', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							— <?php esc_html_e( 'you pick what to fix in the Media Library or on the Dashboard.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_monthly_cap"><?php esc_html_e( 'Monthly credit cap', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" id="a11yfy_monthly_cap" name="a11yfy_monthly_cap"
						value="<?php echo esc_attr( $a11yfy_settings['monthly_cap'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Remediation pauses when this many credits were spent in a calendar month. 0 = no cap.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
					<?php if ( $a11yfy_delegated ) : ?>
						<p class="description">
							<?php
							$a11yfy_billing_org = isset( $a11yfy_balance['billing_org_name'] ) ? (string) $a11yfy_balance['billing_org_name'] : '';
							if ( '' === $a11yfy_billing_org ) {
								$a11yfy_billing_org = __( 'your partner organization', 'a11yfy-pdf-accessibility-checker-fixer' );
							}
							if ( isset( $a11yfy_balance['limit'] ) && null !== $a11yfy_balance['limit'] ) {
								/* translators: 1: parent organization name, 2: delegated credit limit. */
								echo esc_html( sprintf( __( 'Note: your credits are provided by %1$s with a separate limit of %2$d credits per period. Whichever cap is reached first stops remediation.', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_billing_org, (int) $a11yfy_balance['limit'] ) );
							} else {
								/* translators: %s: parent organization name. */
								echo esc_html( sprintf( __( 'Note: your credits are provided by %s with its own separate limit. Whichever cap is reached first stops remediation.', 'a11yfy-pdf-accessibility-checker-fixer' ), $a11yfy_billing_org ) );
							}
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_low_credit"><?php esc_html_e( 'Low-credit warning below', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" id="a11yfy_low_credit" name="a11yfy_low_credit"
						value="<?php echo esc_attr( $a11yfy_settings['low_credit_threshold'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Admin notice + email when the balance drops below this. 0 = off.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Saving strategy', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="a11yfy_save_strategy" value="inplace" <?php checked( $a11yfy_settings['save_strategy'], 'inplace' ); ?> />
							<strong><?php esc_html_e( 'Replace in place (recommended)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							— <?php esc_html_e( 'same URL, original kept as a backup with one-click restore.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label><br />
						<label>
							<input type="radio" name="a11yfy_save_strategy" value="conservative" <?php checked( $a11yfy_settings['save_strategy'], 'conservative' ); ?> />
							<strong><?php esc_html_e( 'Conservative', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></strong>
							— <?php esc_html_e( 'the original file is never touched; links are swapped when pages render.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_notify_email"><?php esc_html_e( 'Notification email', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<input type="email" id="a11yfy_notify_email" name="a11yfy_notify_email"
						value="<?php echo esc_attr( $a11yfy_settings['notify_email'] ); ?>" class="regular-text"
						placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Low-credit and failure notifications. Empty = site admin email.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On plugin deletion', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="a11yfy_delete_data" value="keep" <?php checked( $a11yfy_settings['delete_data'], 'keep' ); ?> />
							<?php esc_html_e( 'Keep the remediated PDFs (recommended — they are your content).', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label><br />
						<label>
							<input type="radio" name="a11yfy_delete_data" value="restore" <?php checked( $a11yfy_settings['delete_data'], 'restore' ); ?> />
							<?php esc_html_e( 'Restore the original files from backup.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Visitor requests (on-demand mode)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These texts appear in the dialog visitors see when they click a PDF that is not accessible yet, and in the email they receive when the accessible version is ready. Leave a field empty to use the built-in text in the site language.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
		</p>
		<?php
		$a11yfy_visitor_defaults = A11yfy_Visitor::default_texts();
		$a11yfy_visitor_fields   = array(
			'visitor_modal_title'  => array( __( 'Dialog title', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_modal_body'   => array( __( 'Dialog message', 'a11yfy-pdf-accessibility-checker-fixer' ), 'textarea' ),
			'visitor_btn_open'     => array( __( '"Open document" button', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_btn_request'  => array( __( '"Request accessible version" button', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_request_info' => array( __( 'Request explanation', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_email_label'  => array( __( 'Email field label', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_btn_submit'   => array( __( '"Request document" button', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_success_msg'  => array( __( 'Confirmation message', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
			'visitor_privacy_note' => array( __( 'Privacy note', 'a11yfy-pdf-accessibility-checker-fixer' ), 'text' ),
		);
		?>
		<table class="form-table" role="presentation">
			<?php foreach ( $a11yfy_visitor_fields as $a11yfy_key => $a11yfy_field ) : ?>
				<tr>
					<th scope="row">
						<label for="a11yfy_<?php echo esc_attr( $a11yfy_key ); ?>"><?php echo esc_html( $a11yfy_field[0] ); ?></label>
					</th>
					<td>
						<?php $a11yfy_default = isset( $a11yfy_visitor_defaults[ str_replace( 'visitor_', '', $a11yfy_key ) ] ) ? $a11yfy_visitor_defaults[ str_replace( 'visitor_', '', $a11yfy_key ) ] : ''; ?>
						<?php if ( 'textarea' === $a11yfy_field[1] ) : ?>
							<textarea class="large-text" rows="3" id="a11yfy_<?php echo esc_attr( $a11yfy_key ); ?>"
								name="a11yfy_<?php echo esc_attr( $a11yfy_key ); ?>"
								placeholder="<?php echo esc_attr( $a11yfy_default ); ?>"><?php echo esc_textarea( $a11yfy_settings[ $a11yfy_key ] ); ?></textarea>
						<?php else : ?>
							<input type="text" class="large-text" id="a11yfy_<?php echo esc_attr( $a11yfy_key ); ?>"
								name="a11yfy_<?php echo esc_attr( $a11yfy_key ); ?>"
								value="<?php echo esc_attr( $a11yfy_settings[ $a11yfy_key ] ); ?>"
								placeholder="<?php echo esc_attr( $a11yfy_default ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dialog button style', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="a11yfy_visitor_theme_style" value="1" <?php checked( ! empty( $a11yfy_settings['visitor_theme_style'] ) ); ?> />
						<?php esc_html_e( 'Inherit the button style from the theme (recommended)', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Block themes style the dialog buttons exactly like the theme buttons. Classic themes without a machine-readable button style fall back to the accent color below.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_visitor_accent_color"><?php esc_html_e( 'Dialog accent color', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<input type="color" id="a11yfy_visitor_accent_color" name="a11yfy_visitor_accent_color"
						value="<?php echo esc_attr( $a11yfy_settings['visitor_accent_color'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Used for the dialog buttons when theme inheritance is off (and as fallback). Pick a dark enough color: the button label is white and needs at least 4.5:1 contrast.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_visitor_email_subject"><?php esc_html_e( 'Ready-email subject', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="a11yfy_visitor_email_subject" name="a11yfy_visitor_email_subject"
						value="<?php echo esc_attr( $a11yfy_settings['visitor_email_subject'] ); ?>"
						placeholder="
						<?php
						/* translators: %s: site name. */
						echo esc_attr( sprintf( __( '[%s] Your accessible document is ready', 'a11yfy-pdf-accessibility-checker-fixer' ), get_bloginfo( 'name' ) ) );
						?>
						" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="a11yfy_visitor_email_body"><?php esc_html_e( 'Ready-email body', 'a11yfy-pdf-accessibility-checker-fixer' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="6" id="a11yfy_visitor_email_body" name="a11yfy_visitor_email_body"><?php echo esc_textarea( $a11yfy_settings['visitor_email_body'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Placeholders: {site_name}, {document_title}, {document_url}, {request_date}. Empty = built-in text in the language each visitor used.', 'a11yfy-pdf-accessibility-checker-fixer' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'a11yfy-pdf-accessibility-checker-fixer' ) ); ?>
	</form>
</div>
