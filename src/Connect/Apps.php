<?php
/**
 * Claude / ChatGPT apps (M4.18): the owner adds PressHelm to their own Claude or ChatGPT app.
 * Signing in, and approving changes asked from such an app, are confirmed here by an
 * administrator; this class carries those decisions to PressHelm Cloud, signed with the site key.
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Connected apps client.
 */
final class Apps {

	/** Sign-in request ids (from the cloud's sign-in page). */
	const REQUEST_ID = '/^asr_[A-Za-z0-9_-]{16}$/';

	/** Approval ids. */
	const APPROVAL_ID = '/^apr_[A-Za-z0-9_-]{16}$/';

	/**
	 * The address to paste into Claude or ChatGPT.
	 *
	 * @return string
	 */
	public static function mcp_url() {
		return Cloud::base_url() . '/mcp/app';
	}

	/**
	 * The current administrator's login (recorded with each decision).
	 *
	 * @return string
	 */
	private static function who() {
		return (string) wp_get_current_user()->user_login;
	}

	/**
	 * Signed call; the JSON answer on 200, else an error with the cloud's message.
	 *
	 * @param string $path     Path.
	 * @param array  $data     Body.
	 * @param string $fallback Message when the cloud gives none.
	 * @return array|\WP_Error
	 */
	private static function call( $path, array $data, $fallback ) {
		$res = Cloud::post( $path, $data, true, 15 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['json'] ) ) {
			$msg = isset( $res['json']['error']['message'] ) ? (string) $res['json']['error']['message'] : $fallback;
			return new \WP_Error( 'presshelm_apps', $msg );
		}
		return $res['json'];
	}

	/**
	 * Which app asks to connect.
	 *
	 * @param string $id Request id.
	 * @return array{app:string,return_host:string}|\WP_Error
	 */
	public static function request( $id ) {
		return self::call( '/v1/connector/app/request', array( 'request_id' => (string) $id ), __( 'This sign-in expired. Start again from your app.', 'presshelm-ai' ) );
	}

	/**
	 * Allow or deny a sign-in; returns where to send the browser back to.
	 *
	 * @param string $id      Request id.
	 * @param bool   $approve Allow.
	 * @return string|\WP_Error
	 */
	public static function decide( $id, $approve ) {
		$r = self::call(
			'/v1/connector/app/decide',
			array(
				'request_id' => (string) $id,
				'decision'   => $approve ? 'approve' : 'deny',
				'wp_user'    => self::who(),
			),
			__( 'This sign-in expired. Start again from your app.', 'presshelm-ai' )
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$url = isset( $r['return_url'] ) ? (string) $r['return_url'] : '';
		if ( 0 !== strpos( $url, Cloud::base_url() . '/app/oauth/return?' ) ) {
			return new \WP_Error( 'presshelm_apps', __( 'PressHelm Cloud sent an unexpected address.', 'presshelm-ai' ) );
		}
		return $url;
	}

	/**
	 * Apps connected to this site.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function all() {
		$r = self::call( '/v1/connector/app/list', array(), __( 'Could not load the connected apps.', 'presshelm-ai' ) );
		return is_wp_error( $r ) ? $r : (array) ( $r['apps'] ?? array() );
	}

	/**
	 * Disconnects an app.
	 *
	 * @param string $grant_id Connection id.
	 * @return true|\WP_Error
	 */
	public static function revoke( $grant_id ) {
		$r = self::call( '/v1/connector/app/revoke', array( 'grant_id' => (string) $grant_id ), __( 'This app is not connected.', 'presshelm-ai' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * What an approval asks.
	 *
	 * @param string $id Approval id.
	 * @return array{text:string,status:string,expires_at:string,app:?string}|\WP_Error
	 */
	public static function approval( $id ) {
		return self::call( '/v1/connector/approval/info', array( 'approval_id' => (string) $id ), __( 'No such approval for this site.', 'presshelm-ai' ) );
	}

	/**
	 * Approve or deny a change.
	 *
	 * @param string $id      Approval id.
	 * @param bool   $approve Approve.
	 * @return array|\WP_Error
	 */
	public static function approval_decide( $id, $approve ) {
		return self::call(
			'/v1/connector/approval/decide',
			array(
				'approval_id' => (string) $id,
				'decision'    => $approve ? 'approve' : 'deny',
				'wp_user'     => self::who(),
			),
			__( 'This request expired or was already answered.', 'presshelm-ai' )
		);
	}
}
