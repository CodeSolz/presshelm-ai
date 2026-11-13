<?php
/**
 * Local encryption for secrets that must briefly live in WordPress (sodium secretbox; WordPress
 * ships sodium_compat). The key is derived from this site's salts, so a database dump alone
 * does not reveal the staged credentials.
 *
 * @package PressHelm
 */

namespace PressHelm\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Secretbox helpers.
 */
final class Crypto {

	/**
	 * Key derived from the site's salts.
	 *
	 * @param string $context Separates keys for different purposes.
	 * @return string 32-byte key.
	 */
	public static function key( $context = 'presshelm' ) {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|' . $context . '|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string      $plain Plaintext.
	 * @param string|null $key   Optional key (tests); defaults to the site key.
	 * @return string Base64 nonce|ciphertext.
	 */
	public static function seal( $plain, $key = null ) {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) $plain, $nonce, null === $key ? self::key() : $key );
		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encrypted data, not code.
	}

	/**
	 * Decrypts a string.
	 *
	 * @param string      $sealed Output of seal().
	 * @param string|null $key    Optional key (tests).
	 * @return string|false Plaintext, or false when tampered/undecryptable.
	 */
	public static function open( $sealed, $key = null ) {
		$raw = base64_decode( (string) $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted data, not code.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return false;
		}
		$out = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			null === $key ? self::key() : $key
		);
		return false === $out ? false : $out;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bin Bytes.
	 * @return string
	 */
	public static function b64u( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
	}

	/**
	 * Display hint: a recognisable prefix + the last 4 characters.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = trim( (string) $secret );
		if ( strlen( $secret ) <= 8 ) {
			return '••••';
		}
		$prefix = '';
		if ( preg_match( '/^(sk-[a-z]{0,8}-?|sk-|or-|AIza)/', $secret, $m ) ) {
			$prefix = $m[1];
		}
		return $prefix . '…' . substr( $secret, -4 );
	}
}
