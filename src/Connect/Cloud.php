<?php
/**
 * HTTP client for PressHelm Cloud. Requests after pairing are signed with the site key
 * (ADR-001): METHOD \n PATH \n TS \n NONCE \n sha256(body).
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

use PressHelm\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Cloud client.
 */
final class Cloud {

	/**
	 * Base URL of PressHelm Cloud (constant PRESSHELM_CLOUD_URL or filter, for staging/dev).
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = defined( 'PRESSHELM_CLOUD_URL' ) ? (string) PRESSHELM_CLOUD_URL : 'https://app.presshelmai.com';
		return untrailingslashit( (string) apply_filters( 'presshelm_cloud_url', $url ) );
	}

	/**
	 * Canonical string that is signed.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param int    $ts     Unix time.
	 * @param string $nonce  Nonce.
	 * @param string $body   Exact body.
	 * @return string
	 */
	public static function canonical( $method, $path, $ts, $nonce, $body ) {
		return strtoupper( $method ) . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash( 'sha256', $body );
	}

	/**
	 * POST JSON to the cloud.
	 *
	 * @param string $path   Path starting with /.
	 * @param array  $data   Body.
	 * @param bool   $signed  Sign with the site key (requires pairing).
	 * @param int    $timeout Seconds (shorter for calls made while a page renders).
	 * @param bool   $next    Sign with the replacement key (key rotation).
	 * @return array{code:int,json:array}|\WP_Error
	 */
	public static function post( $path, array $data, $signed = true, $timeout = 30, $next = false ) {
		$body    = (string) wp_json_encode( $data );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $signed ) {
			$site_id = Connection::site_id();
			if ( '' === $site_id ) {
				return new \WP_Error( 'presshelm_not_connected', __( 'This site is not connected to PressHelm Cloud.', 'presshelm-ai' ) );
			}
			$ts      = time();
			$nonce   = Crypto::b64u( random_bytes( 16 ) );
			$headers = array_merge(
				$headers,
				array(
					'X-PH-Site'  => $site_id,
					'X-PH-Ts'    => (string) $ts,
					'X-PH-Nonce' => $nonce,
					'X-PH-Sig'   => base64_encode( Keys::sign( self::canonical( 'POST', $path, $ts, $nonce, $body ), $next ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature bytes, not code.
				)
			);
		}
		$res = wp_remote_post(
			self::base_url() . $path,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => (int) $timeout,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'presshelm_cloud_unreachable', __( 'Could not reach PressHelm Cloud. Check that this server can make outgoing HTTPS requests, then try again.', 'presshelm-ai' ) );
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'json' => (array) json_decode( wp_remote_retrieve_body( $res ), true ),
		);
	}
}
