<?php
/**
 * API-key encryption at rest (§14/13).
 *
 * Key derivation: HKDF-SHA256 over wp_salt('auth') — zero wp-config setup.
 * Optional override: define A11YFY_ENCRYPTION_KEY (>=32 chars) in wp-config.php.
 * Salt rotation invalidates the stored key → the UI asks to reconnect (documented).
 *
 * @package a11yfy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class A11yfy_Crypto {

	/**
	 * @return string 32-byte binary key.
	 */
	private static function key() {
		$material = defined( 'A11YFY_ENCRYPTION_KEY' ) ? A11YFY_ENCRYPTION_KEY : wp_salt( 'auth' );
		return hash_hkdf( 'sha256', $material, 32, 'a11yfy-api-key-v1' );
	}

	/**
	 * @param string $plaintext Secret to encrypt.
	 * @return string|null base64(nonce . ciphertext) or null on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * @param string $stored base64(nonce . ciphertext).
	 * @return string|null Plaintext or null (wrong key / corrupt value).
	 */
	public static function decrypt( $stored ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( Exception $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
