<?php
/**
 * "Log in with OpenRouter" (official OAuth PKCE for third-party apps, D48): the user approves on
 * openrouter.ai and we receive a user-controlled API key, billed to the user's OpenRouter account.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

use PressHelm\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * OpenRouter OAuth PKCE.
 */
final class OpenRouterAuth {

	const TRANSIENT = 'presshelm_or_oauth_';
	const AUTH_URL  = 'https://openrouter.ai/auth';

	/**
	 * Starts the flow; returns the URL to send the browser to.
	 *
	 * OpenRouter sends the browser back to the callback's path only (it drops the query) with
	 * `?code=…`, so the callback is plain wp-admin/admin.php. That return is recognised by the sign-in
	 * waiting for this administrator (see pending()); the code only works with this attempt's PKCE
	 * verifier, which only PressHelm Cloud sees when it completes the exchange (nothing is stored there).
	 *
	 * @param string $mode wizard|settings (where the owner started).
	 * @return string
	 */
	public static function start( $mode = 'wizard' ) {
		$verifier = Crypto::b64u( random_bytes( 48 ) );
		$nonce    = Crypto::b64u( random_bytes( 18 ) ); // Binds the callback to this admin + this attempt.
		set_transient(
			self::TRANSIENT . get_current_user_id(),
			array(
				'verifier' => $verifier,
				'nonce'    => $nonce,
				'mode'     => 'settings' === $mode ? 'settings' : 'wizard',
			),
			15 * MINUTE_IN_SECONDS
		);
		$callback = admin_url( 'admin.php' );
		return add_query_arg(
			array(
				'callback_url'          => rawurlencode( $callback ),
				'code_challenge'        => Crypto::b64u( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			self::AUTH_URL
		);
	}

	/**
	 * The sign-in this administrator started in the last 15 minutes, or null.
	 *
	 * @return array{verifier:string,nonce:string,mode:string}|null
	 */
	public static function pending() {
		$t = get_transient( self::TRANSIENT . get_current_user_id() );
		return is_array( $t ) && ! empty( $t['nonce'] ) ? $t : null;
	}

	/**
	 * Finishes the flow: returns the user's OpenRouter API key.
	 *
	 * @param string $code  Authorization code from OpenRouter.
	 * @param string $nonce Our nonce from the callback URL.
	 * @return string|\WP_Error
	 */
	public static function finish( $code, $nonce ) {
		$key = self::TRANSIENT . get_current_user_id();
		$t   = get_transient( $key );
		delete_transient( $key ); // Single use.
		if ( ! is_array( $t ) || ! hash_equals( (string) $t['nonce'], (string) $nonce ) || '' === (string) $code ) {
			return new \WP_Error( 'presshelm_or_state', __( 'The OpenRouter login could not be verified. Please try again.', 'presshelm-ai' ) );
		}
		// The code is exchanged by PressHelm Cloud (the plugin never calls the AI provider itself).
		$res = \PressHelm\Connect\Cloud::post(
			'/v1/onboarding/openrouter-exchange',
			array(
				'code'          => (string) $code,
				'code_verifier' => (string) $t['verifier'],
			),
			false,
			30
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['key'] ) ) {
			return new \WP_Error( 'presshelm_or_exchange', __( 'OpenRouter did not complete the login. Please try again.', 'presshelm-ai' ) );
		}
		return (string) $res['json']['key'];
	}
}
