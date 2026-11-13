<?php
/**
 * Signed, payload-free wake from the cloud (D23): POST ?rest_route=/presshelm/v1/wake.
 * It never carries a command: after answering, the same request polls the cloud for the site's
 * signed commands (and a scheduled poll stays as the fallback).
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

use PressHelm\Connect\Cloud;
use PressHelm\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Wake route.
 */
final class WakeController {

	/**
	 * Registers the route.
	 */
	public static function register() {
		register_rest_route(
			'presshelm/v1',
			'/wake',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'verify' ),
				'callback'            => array( self::class, 'handle' ),
			)
		);
	}

	/**
	 * Signature check: cloud key over POST \n route \n ts \n nonce \n sha256('').
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return bool
	 */
	public static function verify( \WP_REST_Request $req ) {
		$conn  = Connection::get();
		$pub   = isset( $conn['cloud_public_key'] ) ? base64_decode( (string) $conn['cloud_public_key'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key bytes.
		$ts    = (int) $req->get_header( 'x_ph_ts' );
		$nonce = (string) $req->get_header( 'x_ph_nonce' );
		$sig   = base64_decode( (string) $req->get_header( 'x_ph_sig' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature bytes.
		if ( false === $pub || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) || ! $ts || abs( time() - $ts ) > 300 || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $nonce ) || false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return false;
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, Cloud::canonical( 'POST', $req->get_route(), $ts, $nonce, '' ), $pub ) ) {
			return false;
		}
		$seen = 'presshelm_wake_' . md5( $nonce );
		if ( get_transient( $seen ) ) {
			return false; // Replay.
		}
		set_transient( $seen, 1, 600 );
		return true;
	}

	/**
	 * Schedules an immediate poll and nudges WP-Cron.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle() {
		if ( \PressHelm\Recovery\EmergencyStop::active() ) {
			return new \WP_REST_Response( array( 'stopped' => true ), 423 );
		}
		update_option( 'presshelm_last_wake', time(), false );
		// Poll in this request once the answer is sent: no second WordPress start through WP-Cron,
		// which was the slow part of every command (and could wait a minute when cron was busy).
		add_action( 'shutdown', array( Poller::class, 'run_after_response' ), 0 );
		// Fallback when this request can't finish the work (host limits): the usual scheduled poll.
		if ( ! wp_next_scheduled( 'presshelm_poll_now' ) ) {
			wp_schedule_single_event( time() + 30, 'presshelm_poll_now' );
		}
		return new \WP_REST_Response( array( 'scheduled' => true ), 202 );
	}
}
