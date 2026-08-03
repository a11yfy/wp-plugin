<?php
/**
 * Plugin Name:       a11yfy – PDF Accessibility Checker & Fixer
 * Plugin URI:        https://a11yfy.com/wordpress
 * Description:       Free in-browser PDF accessibility scan (Matterhorn Protocol machine checks) and one-click PDF/UA-1 remediation via the a11yfy service.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            a11yfy
 * Author URI:        https://a11yfy.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       a11yfy-pdf-accessibility-checker-fixer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'A11YFY_VERSION', '1.1.0' );
define( 'A11YFY_PLUGIN_FILE', __FILE__ );
define( 'A11YFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'A11YFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// API base — override with the A11YFY_API_BASE constant (e.g. dev sandbox) or the
// `a11yfy_api_base` filter. Never configurable from the UI: the key implies the env.
if ( ! defined( 'A11YFY_API_BASE' ) ) {
	define( 'A11YFY_API_BASE', 'https://a11yfy.com/v1' );
}

// Bundled Action Scheduler (background queue). A host plugin (e.g. WooCommerce)
// may already ship it — the library self-resolves to the newest registered copy.
if ( file_exists( A11YFY_PLUGIN_DIR . 'vendor/action-scheduler/action-scheduler.php' ) ) {
	require_once A11YFY_PLUGIN_DIR . 'vendor/action-scheduler/action-scheduler.php';
}

require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-install.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-crypto.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-settings.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-api-client.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-jobs.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-map.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-guardrails.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-optimizer-guard.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-cache.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-replacer.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-triage.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-remediate-service.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-queue.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-webhook.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-requests.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-visitor.php';
require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-visitor-notify.php';

if ( is_admin() ) {
	require_once A11YFY_PLUGIN_DIR . 'includes/admin/class-a11yfy-admin.php';
	require_once A11YFY_PLUGIN_DIR . 'includes/admin/class-a11yfy-ajax.php';
	require_once A11YFY_PLUGIN_DIR . 'includes/admin/class-a11yfy-scan-report.php';
	require_once A11YFY_PLUGIN_DIR . 'includes/class-a11yfy-connect.php';
}

register_activation_hook( __FILE__, array( 'A11yfy_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'A11yfy_Install', 'deactivate' ) );

/**
 * Bootstrap.
 */
function a11yfy_init() {
	A11yfy_Install::maybe_upgrade();
	A11yfy_Queue::init();
	A11yfy_Webhook::init();
	A11yfy_Optimizer_Guard::init();
	A11yfy_Visitor::init();
	A11yfy_Visitor_Notify::init();

	if ( is_admin() ) {
		A11yfy_Admin::init();
		A11yfy_Ajax::init();
		A11yfy_Connect::init();
	}

	// Auto-scan (PHP triage) on every new PDF upload; auto-remediate only in auto mode.
	add_action( 'add_attachment', 'a11yfy_on_add_attachment' );
}
add_action( 'plugins_loaded', 'a11yfy_init' );

/**
 * New attachment hook: queue triage for PDFs (and remediation in auto mode).
 *
 * @param int $attachment_id Attachment ID.
 */
function a11yfy_on_add_attachment( $attachment_id ) {
	if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
		return;
	}
	A11yfy_Queue::enqueue_triage( $attachment_id );

	if ( 'auto' === A11yfy_Settings::get( 'mode' ) && A11yfy_Settings::is_connected() ) {
		A11yfy_Queue::enqueue_remediation( $attachment_id, 'auto' );
	}
}
