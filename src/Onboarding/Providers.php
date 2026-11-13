<?php
/**
 * AI provider registry (D50): the wizard shows only the methods each provider officially
 * supports. Adding a login method later (e.g. "Sign in with ChatGPT", V.19a) is a registry
 * change, not a wizard change.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * Provider registry.
 */
final class Providers {

	/**
	 * All providers, filterable via `presshelm_ai_providers`.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		$providers = array(
			'openrouter'        => array(
				'short'   => 'OpenRouter',
				'label'   => 'OpenRouter',
				'blurb'   => __( 'One account for Claude, GPT, Gemini and many more. Log in or use a key.', 'presshelm-ai' ),
				'methods' => array( 'oauth', 'api_key' ),
				'key_url' => 'https://openrouter.ai/settings/keys',
				'prefer'  => array( 'anthropic/claude-sonnet-5-5', 'openai/gpt-6.1-sol', 'google/gemini-3.8-flash' ),
				'cheap'   => array( 'anthropic/claude-haiku-5-5', 'openai/gpt-6-luna', 'google/gemini-3.5-flash-lite' ),
			),
			'anthropic'         => array(
				'short'   => 'Anthropic',
				'label'   => 'Anthropic (Claude)',
				'blurb'   => __( 'Use an API key from the Claude Console.', 'presshelm-ai' ),
				'methods' => array( 'api_key' ),
				'key_url' => 'https://platform.claude.com/settings/keys',
				'prefer'  => array( 'claude-sonnet-5-5', 'claude-opus-5-5' ),
				'cheap'   => array( 'claude-haiku-5-5' ),
			),
			'openai'            => array(
				'short'   => 'OpenAI',
				'label'   => 'OpenAI',
				'blurb'   => __( 'Use an API key from the OpenAI platform.', 'presshelm-ai' ),
				'methods' => array( 'api_key' ),
				'key_url' => 'https://platform.openai.com/api-keys',
				'prefer'  => array( 'gpt-6.1-sol', 'gpt-6-astra' ),
				'cheap'   => array( 'gpt-6-luna' ),
			),
			'gemini'            => array(
				'short'   => 'Google Gemini',
				'label'   => 'Google Gemini',
				'blurb'   => __( 'Use an API key from Google AI Studio.', 'presshelm-ai' ),
				'methods' => array( 'api_key' ),
				'key_url' => 'https://aistudio.google.com/apikey',
				'prefer'  => array( 'gemini-3.8-flash', 'gemini-3.6-flash' ),
				'cheap'   => array( 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite' ),
			),
			'openai_compatible' => array(
				'short'   => __( 'Custom', 'presshelm-ai' ),
				'label'   => __( 'Other (OpenAI-compatible)', 'presshelm-ai' ),
				'blurb'   => __( 'Any provider with an OpenAI-compatible API (base URL + key).', 'presshelm-ai' ),
				'methods' => array( 'api_key' ),
				'key_url' => '',
			),
		);
		return (array) apply_filters( 'presshelm_ai_providers', $providers );
	}

	/**
	 * One provider or null.
	 *
	 * @param string $id Provider id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Whether the provider supports a method.
	 *
	 * @param string $id     Provider id.
	 * @param string $method api_key|oauth.
	 * @return bool
	 */
	public static function supports( $id, $method ) {
		$p = self::get( $id );
		return null !== $p && in_array( $method, (array) $p['methods'], true );
	}

	/**
	 * Suggested default among the models the key can use (presets from research V.9; never
	 * hard-required: when none is available the first model is used).
	 *
	 * @param string   $id     Provider id.
	 * @param string[] $models Available model ids.
	 * @param string   $kind   prefer|cheap.
	 * @return string
	 */
	public static function suggested_model( $id, array $models, $kind = 'prefer' ) {
		$p = self::get( $id );
		foreach ( (array) ( isset( $p[ $kind ] ) ? $p[ $kind ] : array() ) as $candidate ) {
			if ( in_array( $candidate, $models, true ) ) {
				return $candidate;
			}
		}
		return 'prefer' === $kind && $models ? (string) reset( $models ) : '';
	}
}
