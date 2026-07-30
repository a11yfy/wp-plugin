<?php
/**
 * PHPUnit bootstrap: wp-phpunit (WP core test suite from Composer) + the plugin
 * loaded as an mu-plugin. Paths are plugin-root-relative so the same config
 * runs inside wp-env containers and bare CI runners alike.
 *
 * @package a11yfy
 */

$a11yfy_root = dirname( __DIR__, 2 );

require_once $a11yfy_root . '/vendor-dev/autoload.php';

$a11yfy_wp_phpunit = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $a11yfy_wp_phpunit ) {
	$a11yfy_wp_phpunit = $a11yfy_root . '/vendor-dev/wp-phpunit/wp-phpunit';
	putenv( 'WP_PHPUNIT__DIR=' . $a11yfy_wp_phpunit );
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

require_once $a11yfy_wp_phpunit . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $a11yfy_root ) {
		require $a11yfy_root . '/a11yfy.php';
		// is_admin() is false in the CLI test runner — load the admin layer too.
		require_once $a11yfy_root . '/includes/admin/class-a11yfy-admin.php';
		require_once $a11yfy_root . '/includes/admin/class-a11yfy-ajax.php';
		require_once $a11yfy_root . '/includes/admin/class-a11yfy-scan-report.php';
		require_once $a11yfy_root . '/includes/class-a11yfy-connect.php';
	}
);

require $a11yfy_wp_phpunit . '/includes/bootstrap.php';
