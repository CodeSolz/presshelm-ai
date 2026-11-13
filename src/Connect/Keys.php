<?php
/**
 * The site's Ed25519 identity, stored encrypted (secretbox with a key derived from the salts).
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

use PressHelm\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Site keypair.
 */
final class Keys {

	const OPTION = 'presshelm_site_keys';

	/** The replacement keypair while a rotation is in progress (M2.6). */
	const NEXT = 'presshelm_site_keys_next';

	/**
	 * Returns the keypair, creating it on first use.
	 *
	 * @return string libsodium keypair (64-byte secret + 32-byte public).
	 */
	public static function keypair() {
		$sealed = get_option( self::OPTION );
		if ( $sealed ) {
			$kp = Crypto::open( $sealed, Crypto::key( 'presshelm-site-keys' ) );
			if ( false !== $kp && SODIUM_CRYPTO_SIGN_KEYPAIRBYTES === strlen( $kp ) ) {
				return $kp;
			}
		}
		$kp = sodium_crypto_sign_keypair();
		update_option( self::OPTION, Crypto::seal( $kp, Crypto::key( 'presshelm-site-keys' ) ), false );
		return $kp;
	}

	/**
	 * Raw 32-byte public key.
	 *
	 * @return string
	 */
	public static function public_key() {
		return sodium_crypto_sign_publickey( self::keypair() );
	}

	/**
	 * Detached signature.
	 *
	 * @param string $message Message.
	 * @param bool   $next    Sign with the replacement key (during rotation).
	 * @return string 64 bytes.
	 */
	public static function sign( $message, $next = false ) {
		return sodium_crypto_sign_detached( (string) $message, sodium_crypto_sign_secretkey( $next ? self::next_keypair() : self::keypair() ) );
	}

	/**
	 * The replacement keypair, created on first use.
	 *
	 * @return string
	 */
	public static function next_keypair() {
		$sealed = get_option( self::NEXT );
		if ( $sealed ) {
			$kp = Crypto::open( $sealed, Crypto::key( 'presshelm-site-keys' ) );
			if ( false !== $kp && SODIUM_CRYPTO_SIGN_KEYPAIRBYTES === strlen( $kp ) ) {
				return $kp;
			}
		}
		$kp = sodium_crypto_sign_keypair();
		update_option( self::NEXT, Crypto::seal( $kp, Crypto::key( 'presshelm-site-keys' ) ), false );
		return $kp;
	}

	/**
	 * Raw public key of the replacement keypair.
	 *
	 * @return string
	 */
	public static function next_public_key() {
		return sodium_crypto_sign_publickey( self::next_keypair() );
	}

	/**
	 * The cloud accepted the replacement: it becomes the key.
	 */
	public static function promote() {
		$next = get_option( self::NEXT );
		if ( $next ) {
			update_option( self::OPTION, $next, false );
			delete_option( self::NEXT );
		}
	}

	/**
	 * The rotation did not happen: drop the replacement.
	 */
	public static function discard_next() {
		delete_option( self::NEXT );
	}

	/**
	 * Forgets the keypair (disconnect / uninstall). A new one is created on the next pairing.
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_option( self::NEXT );
	}
}
