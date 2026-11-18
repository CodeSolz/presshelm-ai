<?php
/**
 * "Sign in with your website" (2026-11-18): someone on PressHelm Cloud's sign-in page typed this
 * site's address; the cloud sent their browser here. An administrator confirms, and the site signs
 * the one-time nonce with its key so the cloud knows this site's admin asked. That alone never
 * signs anyone in: the cloud also needs a browser the account owner signed in on before, or a code
 * from the owner's email. Nothing is stored here and nothing about the site leaves it except the
 * administrator's username, which the cloud records in the account's security log.
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Site sign-in.
 */
final class SiteLogin {

	/** Shape of the cloud's one-time nonce. */
	const NONCE = '/^[A-Za-z0-9_-]{24,64}$/';

	/**
	 * Where to send the browser back: the cloud's return address with the signed nonce.
	 *
	 * @param string $nonce One-time nonce from the cloud.
	 * @return string|\WP_Error
	 */
	public static function return_url( $nonce ) {
		if ( ! preg_match( self::NONCE, (string) $nonce ) ) {
			return new \WP_Error( 'presshelm_site_login', __( 'This sign-in link is not valid. Start again on PressHelm.', 'presshelm-ai' ) );
		}
		$site_id = Connection::site_id();
		if ( '' === $site_id ) {
			return new \WP_Error( 'presshelm_site_login', __( 'This site is not connected to PressHelm yet.', 'presshelm-ai' ) );
		}
		$user    = wp_get_current_user();
		$wp_user = substr( (string) $user->user_login, 0, 100 );
		$cloud   = Cloud::base_url();
		$message = 'presshelm-login.v1|' . $nonce . '|' . $cloud . '|' . $site_id . '|' . $wp_user;
		$sig     = base64_encode( Keys::sign( $message ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a signature, not code.
		return $cloud . '/login/site/return?' . http_build_query(
			array(
				'nonce'   => $nonce,
				'sig'     => $sig,
				'wp_user' => $wp_user,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
}
