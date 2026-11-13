<?php
/**
 * Linking the owner's Telegram account (M1.6, M1.17, D46): one-time deep link, live bot status,
 * and the owner's approve/deny decision. All calls are signed with the site key.
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Telegram linking client.
 */
final class TelegramLink {

	/** Set while the owner is expected to press Start in Telegram (drives the waiting view). */
	const WAITING = 'presshelm_tg_waiting';

	/**
	 * Bot, webhook, owner and pending requests from the cloud.
	 *
	 * @return array|\WP_Error
	 */
	public static function status() {
		$res = Cloud::post( '/v1/connector/telegram/status', array(), true, 8 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] ) {
			return new \WP_Error( 'presshelm_tg_status', __( 'PressHelm Cloud did not return the Telegram status.', 'presshelm-ai' ) );
		}
		return $res['json'];
	}

	/**
	 * A fresh one-time t.me link (or the plain bot link once the owner is linked).
	 *
	 * @return string|\WP_Error
	 */
	public static function deep_link() {
		$res = Cloud::post( '/v1/connector/telegram/link', array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$link = isset( $res['json']['deep_link'] ) ? (string) $res['json']['deep_link'] : '';
		if ( 200 !== $res['code'] || 0 !== strpos( $link, 'https://t.me/' ) ) {
			return new \WP_Error( 'presshelm_tg_link', __( 'Could not create a Telegram link. Try again.', 'presshelm-ai' ) );
		}
		if ( empty( $res['json']['linked'] ) ) {
			set_transient( self::WAITING, 1, 10 * MINUTE_IN_SECONDS );
		}
		return $link;
	}

	/**
	 * Approves or denies a pending link request.
	 *
	 * @param string $request_id Request id.
	 * @param bool   $approve    Approve.
	 * @return true|\WP_Error
	 */
	public static function decide( $request_id, $approve ) {
		$res = Cloud::post(
			'/v1/connector/telegram/decide',
			array(
				'request_id' => (string) $request_id,
				'decision'   => $approve ? 'approve' : 'deny',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] ) {
			return new \WP_Error( 'presshelm_tg_decide', __( 'This request is no longer valid. Open Telegram again to send a new one.', 'presshelm-ai' ) );
		}
		delete_transient( self::WAITING );
		return true;
	}
}
