<?php
/**
 * Pairing with PressHelm Cloud (ADR-001, D40):
 * start() → browser goes to the cloud consent page → handle_callback() checks state, exchanges
 * code + PKCE verifier + site-key proof server-to-server, then hands off the staged credentials
 * once over the signed channel and wipes them locally.
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

use PressHelm\Onboarding\PendingCredentials;
use PressHelm\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Pairing flow.
 */
final class Pairing {

	const TRANSIENT   = 'presshelm_pairing';
	const EXCHANGING  = 'presshelm_pairing_exchanging';
	const TTL_MINUTES = 15;

	/**
	 * The wp-admin URL the cloud sends the browser back to.
	 *
	 * @return string
	 */
	public static function return_url() {
		return admin_url( 'admin.php?page=presshelm&step=connect&presshelm_cb=1' );
	}

	/**
	 * Registers a pairing request; returns the cloud URL to send the browser to.
	 *
	 * @return string|\WP_Error
	 */
	public static function start() {
		$verifier = Crypto::b64u( random_bytes( 48 ) );
		$state    = Crypto::b64u( random_bytes( 24 ) );
		$res      = Cloud::post(
			'/v1/pairing/requests',
			array(
				'site_url'        => home_url( '/' ),
				'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'redirect_uri'    => self::return_url(),
				'code_challenge'  => Crypto::b64u( hash( 'sha256', $verifier, true ) ),
				'site_public_key' => base64_encode( Keys::public_key() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key bytes, not code.
			),
			false
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['request_id'] ) || empty( $res['json']['authorize_url'] ) ) {
			$msg = isset( $res['json']['error']['message'] ) ? (string) $res['json']['error']['message'] : __( 'PressHelm Cloud refused the connection request.', 'presshelm-ai' );
			return new \WP_Error( 'presshelm_pair_start', $msg );
		}
		set_transient(
			self::TRANSIENT,
			array(
				'request_id' => (string) $res['json']['request_id'],
				'verifier'   => $verifier,
				'state_hash' => hash( 'sha256', $state ),
				'user_id'    => get_current_user_id(),
			),
			self::TTL_MINUTES * MINUTE_IN_SECONDS
		);
		$args = array( 'state' => rawurlencode( $state ) );
		// The administrator's email as a prefill on PressHelm's sign-in page (they confirm it with a code).
		$email = (string) wp_get_current_user()->user_email;
		if ( is_email( $email ) ) {
			$args['login_hint'] = rawurlencode( $email );
		}
		return add_query_arg( $args, (string) $res['json']['authorize_url'] );
	}

	/**
	 * Handles the browser coming back from the cloud.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state State.
	 * @param string $error Error from the cloud (access_denied).
	 * @return true|\WP_Error
	 */
	public static function handle_callback( $code, $state, $error = '' ) {
		$p = get_transient( self::TRANSIENT );
		if ( ! is_array( $p ) || ! hash_equals( (string) $p['state_hash'], hash( 'sha256', (string) $state ) ) || get_current_user_id() !== (int) $p['user_id'] ) {
			return new \WP_Error( 'presshelm_pair_state', __( 'This connection attempt expired or does not match. Click Connect again.', 'presshelm-ai' ) );
		}
		delete_transient( self::TRANSIENT ); // Single use.
		if ( '' !== (string) $error ) {
			return new \WP_Error( 'presshelm_pair_denied', __( 'The connection was cancelled on PressHelm Cloud. You can try again any time.', 'presshelm-ai' ) );
		}

		// Let the verify route answer the cloud's ownership check during the exchange.
		set_transient( self::EXCHANGING, (string) $p['request_id'], 2 * MINUTE_IN_SECONDS );
		$res = Cloud::post(
			'/oauth/token',
			array(
				'request_id'    => $p['request_id'],
				'code'          => (string) $code,
				'code_verifier' => $p['verifier'],
				'proof'         => base64_encode( Keys::sign( $code . '.' . $p['verifier'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature bytes, not code.
			),
			false
		);
		delete_transient( self::EXCHANGING );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['site_id'] ) || empty( $res['json']['cloud_public_key'] ) ) {
			$why = isset( $res['json']['error_description'] ) ? (string) $res['json']['error_description'] : __( 'The connection could not be completed.', 'presshelm-ai' );
			return new \WP_Error( 'presshelm_pair_exchange', $why );
		}
		$cloud_pub = base64_decode( (string) $res['json']['cloud_public_key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key bytes.
		if ( false === $cloud_pub || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $cloud_pub ) ) {
			return new \WP_Error( 'presshelm_pair_exchange', __( 'PressHelm Cloud sent an invalid key.', 'presshelm-ai' ) );
		}
		Connection::update(
			array(
				'site_id'          => (string) $res['json']['site_id'],
				'cloud_public_key' => base64_encode( $cloud_pub ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key bytes, not code.
				'cloud_url'        => Cloud::base_url(),
				'connected_at'     => time(),
				'connected_by'     => get_current_user_id(),
				'last_seq'         => 0,
			)
		);
		// The user tasks run as (content permissions only), created by this admin's own request.
		\PressHelm\Bridge\TaskUser::ensure();
		// On reconnect the cloud says what it kept; forget what it did not (shown as missing).
		$carried = isset( $res['json']['carried'] ) ? (array) $res['json']['carried'] : array();
		$drop    = array();
		foreach ( array( 'ai', 'telegram' ) as $k ) {
			if ( empty( $carried[ $k ] ) ) {
				$drop[ $k ] = array();
			}
		}
		if ( $drop ) {
			Connection::update( $drop );
		}
		return self::handoff();
	}

	/**
	 * Sends the staged credentials once; wipes them only after the cloud confirms (D46).
	 *
	 * @return true|\WP_Error
	 */
	public static function handoff() {
		$body = PendingCredentials::handoff_body();
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! $body ) {
			return true; // Nothing staged (e.g. reconnect).
		}
		$res = Cloud::post( '/v1/connector/credentials', $body );
		if ( is_wp_error( $res ) ) {
			Connection::update( array( 'handoff' => 'retry' ) );
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['stored'] ) ) {
			Connection::update( array( 'handoff' => 'retry' ) );
			$msg = isset( $res['json']['error']['message'] ) ? (string) $res['json']['error']['message'] : __( 'PressHelm Cloud could not store your credentials. Try again.', 'presshelm-ai' );
			return new \WP_Error( 'presshelm_handoff', $msg );
		}
		// Only what was sent changes (a connected site may change just the AI or just the bot).
		$update = array( 'handoff' => 'done' );
		foreach ( array( 'ai', 'telegram' ) as $k ) {
			if ( isset( $res['json'][ $k ] ) ) {
				$update[ $k ] = (array) $res['json'][ $k ];
			}
		}
		Connection::update( $update );
		PendingCredentials::wipe();
		if ( ! empty( $res['json']['telegram_error']['message'] ) ) {
			// Everything else was stored; only the shared bot was not possible (plan, or none chosen yet).
			return new \WP_Error( 'presshelm_tg_shared', (string) $res['json']['telegram_error']['message'] );
		}
		return true;
	}

	/**
	 * Answers the cloud's ownership challenge (REST, see VerifyController). Only signs while a
	 * pairing started by this site is in progress, so the route is not a general signing oracle.
	 *
	 * @param string $request_id Pairing request id.
	 * @param string $challenge  Random challenge from the cloud.
	 * @return string|null Base64 signature, or null when no matching pairing is in progress.
	 */
	public static function sign_ownership( $request_id, $challenge ) {
		$exchanging = (string) get_transient( self::EXCHANGING );
		if ( '' === $exchanging || ! hash_equals( $exchanging, (string) $request_id ) || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', (string) $challenge ) ) {
			return null;
		}
		return base64_encode( Keys::sign( "presshelm-verify\n" . $request_id . "\n" . $challenge ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature bytes, not code.
	}
}
