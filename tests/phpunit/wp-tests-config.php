<?php
/**
 * WP test-suite config. DB credentials come from the environment: wp-env
 * containers export WORDPRESS_DB_*, CI runners set WP_TESTS_DB_* explicitly.
 *
 * @package a11yfy
 */

// phpcs:disable WordPress.Security -- test-only config, no user input.

$a11yfy_env = static function ( $keys, $fallback ) {
	foreach ( (array) $keys as $key ) {
		$value = getenv( $key );
		if ( false !== $value && '' !== $value ) {
			return $value;
		}
	}
	return $fallback;
};

define( 'DB_NAME', $a11yfy_env( array( 'WP_TESTS_DB_NAME', 'WORDPRESS_DB_NAME' ), 'tests-wordpress' ) );
define( 'DB_USER', $a11yfy_env( array( 'WP_TESTS_DB_USER', 'WORDPRESS_DB_USER' ), 'root' ) );
define( 'DB_PASSWORD', $a11yfy_env( array( 'WP_TESTS_DB_PASSWORD', 'WORDPRESS_DB_PASSWORD' ), 'password' ) );
define( 'DB_HOST', $a11yfy_env( array( 'WP_TESTS_DB_HOST', 'WORDPRESS_DB_HOST' ), 'mysql' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// WP core checkout inside the wp-env container; CI can override.
define( 'ABSPATH', $a11yfy_env( 'WP_TESTS_ABSPATH', '/var/www/html/' ) );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
