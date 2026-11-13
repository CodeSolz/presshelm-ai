<?php
/**
 * Checks an AI API key before the site is connected. The plugin never contacts AI providers itself:
 * PressHelm Cloud lists the models the key can access (a free call, no tokens used) and answers with
 * them; nothing is stored there. Never logs or echoes the key.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

use PressHelm\Connect\Cloud;

defined( 'ABSPATH' ) || exit;

/**
 * Key checks.
 */
final class ProviderCheck {

	/**
	 * Validates a key; returns the accessible model ids.
	 *
	 * @param string $provider Provider id.
	 * @param string $key      API key.
	 * @param string $base_url Base URL (OpenAI-compatible only).
	 * @return string[]|\WP_Error
	 */
	public static function validate( $provider, $key, $base_url = '' ) {
		$key = trim( (string) $key );
		if ( strlen( $key ) < 8 ) {
			return new \WP_Error( 'presshelm_key', __( 'Paste the full API key.', 'presshelm-ai' ) );
		}
		$res = Cloud::post(
			'/v1/onboarding/check-key',
			array(
				'provider' => (string) $provider,
				'key'      => $key,
				'base_url' => (string) $base_url,
			),
			false,
			45
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['models'] ) ) {
			$msg = (string) ( $res['json']['error']['message'] ?? __( 'The key could not be checked. Try again in a moment.', 'presshelm-ai' ) );
			return new \WP_Error( 'presshelm_' . sanitize_key( (string) ( $res['json']['error']['code'] ?? 'key' ) ), $msg );
		}
		return array_values( array_filter( array_map( 'strval', (array) $res['json']['models'] ) ) );
	}

	/**
	 * OpenRouter's public model list (through PressHelm Cloud).
	 *
	 * @return string[] Empty when it cannot be loaded.
	 */
	public static function openrouter_models() {
		$res = Cloud::post( '/v1/onboarding/openrouter-models', array(), false, 20 );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) ( $res['json']['models'] ?? array() ) ) ) );
	}
}
