<?php
/**
 * REST route the cloud calls during pairing to prove the plugin is really installed on this
 * site: POST /wp-json/presshelm/v1/verify (also reachable as ?rest_route=).
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership verification route.
 */
final class VerifyController {

	/**
	 * Registers the route.
	 */
	public static function register() {
		register_rest_route(
			'presshelm/v1',
			'/verify',
			array(
				'methods'             => 'POST',
				// Public by design: it only signs a challenge for a pairing this site started itself (see Pairing::sign_ownership()).
				'permission_callback' => '__return_true',
				'args'                => array(
					'request_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'challenge'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => array( self::class, 'handle' ),
			)
		);
	}

	/**
	 * Signs the challenge when a matching pairing is in progress.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $req ) {
		$sig = Pairing::sign_ownership( (string) $req->get_param( 'request_id' ), (string) $req->get_param( 'challenge' ) );
		if ( null === $sig ) {
			return new \WP_REST_Response( array( 'error' => 'no_pairing_in_progress' ), 404 );
		}
		return new \WP_REST_Response( array( 'signature' => $sig ), 200 );
	}
}
