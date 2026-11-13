<?php
/**
 * Site key rotation (M2.6). A replacement keypair is created and kept until the cloud has
 * switched: the request is signed with the current key and proven with the new one. If the answer
 * is lost on the way back, a signed ping with the new key tells which key the cloud now holds, so
 * the site can't lock itself out.
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Key rotation.
 */
final class Rotation {

	/**
	 * Rotates the site key.
	 *
	 * @return true|\WP_Error
	 */
	public static function run() {
		$site = Connection::site_id();
		if ( '' === $site ) {
			return new \WP_Error( 'presshelm_not_connected', __( 'This site is not connected to PressHelm Cloud.', 'presshelm-ai' ) );
		}
		$pub = base64_encode( Keys::next_public_key() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key bytes, not code.
		$res = Cloud::post(
			'/v1/connector/rotate-key',
			array(
				'public_key' => $pub,
				'proof'      => base64_encode( Keys::sign( "presshelm-rotate-key\n" . $site . "\n" . $pub, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature bytes, not code.
			)
		);
		if ( ! is_wp_error( $res ) && 200 === $res['code'] ) {
			Keys::promote();
			return true;
		}
		// Unknown outcome (e.g. timeout): ask the cloud which key it accepts now.
		$probe = Cloud::post( '/v1/connector/ping', array(), true, 15, true );
		if ( ! is_wp_error( $probe ) && 200 === $probe['code'] ) {
			Keys::promote();
			return true;
		}
		Keys::discard_next();
		return new \WP_Error( 'presshelm_rotate', __( 'The keys could not be replaced. The current keys still work; try again later.', 'presshelm-ai' ) );
	}
}
