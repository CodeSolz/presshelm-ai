<?php
/**
 * Validates a Telegram bot token with getMe. The token is never logged or echoed.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * Telegram token check.
 */
final class TelegramCheck {

	const TOKEN_RE = '/^(\d{5,15}):[A-Za-z0-9_-]{30,64}$/';

	/**
	 * Format check only.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function looks_valid( $token ) {
		return (bool) preg_match( self::TOKEN_RE, (string) $token );
	}

	/**
	 * Calls getMe.
	 *
	 * @param string $token Bot token.
	 * @return array{id:int,username:string,name:string}|\WP_Error
	 */
	public static function get_me( $token ) {
		$token = trim( (string) $token );
		if ( ! self::looks_valid( $token ) ) {
			return new \WP_Error( 'presshelm_bot_format', __( 'That does not look like a bot token. It looks like 123456789:AA… and comes from @BotFather.', 'presshelm-ai' ) );
		}
		$res = wp_remote_get( 'https://api.telegram.org/bot' . $token . '/getMe', array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'presshelm_bot_network', __( 'Could not reach Telegram from this server. Check that outgoing connections are allowed, then try again.', 'presshelm-ai' ) );
		}
		$json = (array) json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $json['ok'] ) || empty( $json['result']['is_bot'] ) ) {
			return new \WP_Error( 'presshelm_bot_invalid', __( 'Telegram did not accept this token. Copy it again from @BotFather (or use /token there to get a new one).', 'presshelm-ai' ) );
		}
		return array(
			'id'       => (int) $json['result']['id'],
			'username' => (string) ( $json['result']['username'] ?? '' ),
			'name'     => (string) ( $json['result']['first_name'] ?? '' ),
		);
	}
}
