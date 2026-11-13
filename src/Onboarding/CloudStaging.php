<?php
/**
 * Staging fallback (M1.19, D46). If this site can't protect a stored secret (wp-admin without
 * HTTPS, or the default security keys, which WordPress then keeps in the database), the wizard's
 * key and token forms post straight to PressHelm Cloud over HTTPS. The cloud checks the
 * credential, keeps it encrypted and bound to this site's public key, and sends the browser back
 * with a reference. This site keeps only that reference and non-secret details.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

use PressHelm\Connect\Cloud;
use PressHelm\Connect\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Browser-to-cloud credential staging.
 */
final class CloudStaging {

	/** Per-user wizard session that the cloud echoes back (1 hour). */
	const STATE = 'presshelm_stg_state_';

	/**
	 * Why the fallback is needed ('' when it isn't).
	 *
	 * @return string no_https|weak_keys|forced|''
	 */
	public static function reason() {
		if ( defined( 'PRESSHELM_CLOUD_STAGING' ) ) {
			return PRESSHELM_CLOUD_STAGING ? 'forced' : '';
		}
		$reason = '';
		if ( ! is_ssl() ) {
			$reason = 'no_https';
		} elseif ( self::weak_keys() ) {
			$reason = 'weak_keys';
		}
		return (string) apply_filters( 'presshelm_cloud_staging_reason', $reason );
	}

	/**
	 * Whether credentials go to the cloud instead of being stored here.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== self::reason();
	}

	/**
	 * The keys the local encryption is derived from are missing or default: WordPress then
	 * generates salts and stores them in the database next to the encrypted data.
	 *
	 * @return bool
	 */
	public static function weak_keys() {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $name ) {
			$value = defined( $name ) ? (string) constant( $name ) : '';
			if ( strlen( $value ) < 32 || false !== strpos( $value, 'put your unique phrase here' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Explanation shown above the forms.
	 *
	 * @return string
	 */
	public static function notice() {
		$why = 'no_https' === self::reason()
			? __( 'This admin page is not using HTTPS,', 'presshelm-ai' )
			: __( 'This site\'s security keys (wp-config.php) are not set,', 'presshelm-ai' );
		if ( 'forced' === self::reason() ) {
			$why = __( 'This site is set up to keep no secrets,', 'presshelm-ai' );
		}
		/* translators: %s: reason, e.g. "This admin page is not using HTTPS," */
		return sprintf( __( '%s so your key is sent directly from your browser to PressHelm Cloud over HTTPS and is not stored on this site.', 'presshelm-ai' ), $why );
	}

	/**
	 * This user's wizard session token (created on demand).
	 *
	 * @return string
	 */
	public static function state() {
		$key   = self::STATE . get_current_user_id();
		$state = get_transient( $key );
		if ( ! is_string( $state ) || '' === $state ) {
			$state = \PressHelm\Support\Crypto::b64u( random_bytes( 32 ) );
		}
		set_transient( $key, $state, HOUR_IN_SECONDS );
		return $state;
	}

	/**
	 * Where the browser form posts.
	 *
	 * @return string
	 */
	public static function form_action() {
		return Cloud::base_url() . '/v1/staging/form';
	}

	/**
	 * Hidden fields of the browser form.
	 *
	 * @param string $kind      ai|telegram.
	 * @param string $return_to wp-admin URL to come back to.
	 */
	public static function hidden_fields( $kind, $return_to ) {
		$fields = array(
			'kind'      => $kind,
			'site_key'  => base64_encode( Keys::public_key() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key bytes, not code.
			'state'     => self::state(),
			'return_to' => $return_to,
		);
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
	}

	/**
	 * Handles the browser coming back from the cloud form.
	 *
	 * @param string $state Echoed session token.
	 * @param string $kind  ai|telegram.
	 * @param string $ref   Staging reference (on success).
	 * @param string $error Error code (on failure).
	 * @return array|\WP_Error Non-secret details that were staged.
	 */
	public static function complete( $state, $kind, $ref, $error ) {
		$expected = get_transient( self::STATE . get_current_user_id() );
		if ( ! is_string( $expected ) || '' === $expected || ! hash_equals( $expected, (string) $state ) ) {
			return new \WP_Error( 'presshelm_stg_state', __( 'That page has expired. Enter the key again.', 'presshelm-ai' ) );
		}
		if ( '' !== $error ) {
			return new \WP_Error( 'presshelm_stg_' . $error, self::message( $error ) );
		}
		if ( ! in_array( $kind, array( 'ai', 'telegram' ), true ) || ! preg_match( '/^stgref_[A-Za-z0-9_-]{43}$/', $ref ) ) {
			return new \WP_Error( 'presshelm_stg_ref', __( 'PressHelm Cloud sent back an invalid answer. Try again.', 'presshelm-ai' ) );
		}
		$res = Cloud::post(
			'/v1/staging/info',
			array(
				'ref'   => $ref,
				'state' => $state,
			),
			false,
			15
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ( $res['json']['kind'] ?? '' ) !== $kind ) {
			return new \WP_Error( 'presshelm_stg_info', __( 'The saved key has expired. Enter it again.', 'presshelm-ai' ) );
		}
		return self::store( $kind, $ref, (array) ( $res['json']['meta'] ?? array() ) );
	}

	/**
	 * Login keys reach this server anyway (OAuth return): pass them on without storing them.
	 *
	 * @param string $provider Provider id.
	 * @param string $method   oauth|api_key.
	 * @param string $secret   Key.
	 * @return array|\WP_Error
	 */
	public static function relay_ai( $provider, $method, $secret ) {
		$res = Cloud::post(
			'/v1/staging/records',
			array(
				'kind'     => 'ai',
				'provider' => $provider,
				'method'   => $method,
				'secret'   => $secret,
				'site_key' => base64_encode( Keys::public_key() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key bytes, not code.
				'state'    => self::state(),
			),
			false
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['ref'] ) ) {
			return new \WP_Error( 'presshelm_stg_relay', self::message( (string) ( $res['json']['error']['code'] ?? '' ) ) );
		}
		return self::store( 'ai', (string) $res['json']['ref'], (array) ( $res['json']['meta'] ?? array() ) );
	}

	/**
	 * Keeps the reference + details in the pending record.
	 *
	 * @param string $kind ai|telegram.
	 * @param string $ref  Reference.
	 * @param array  $meta Details from the cloud.
	 * @return array
	 */
	private static function store( $kind, $ref, array $meta ) {
		if ( 'ai' === $kind ) {
			PendingCredentials::set_ai_staged( $ref, $meta );
		} else {
			PendingCredentials::set_telegram_staged( $ref, $meta );
		}
		return $meta;
	}

	/**
	 * Friendly text for a cloud error code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function message( $code ) {
		$map = array(
			'rejected'             => __( 'The provider rejected this key. Check that you copied it completely and that it is active.', 'presshelm-ai' ),
			'invalid_key'          => __( 'Paste the full API key.', 'presshelm-ai' ),
			'invalid_base_url'     => __( 'Enter the provider\'s public base URL, starting with https://', 'presshelm-ai' ),
			'rate_limited'         => __( 'Too many attempts. Wait a minute and try again.', 'presshelm-ai' ),
			'no_models'            => __( 'The key works but no models are available to it. Check your provider account.', 'presshelm-ai' ),
			'unreachable'          => __( 'PressHelm Cloud could not reach the provider. Try again in a moment.', 'presshelm-ai' ),
			'provider_error'       => __( 'The provider answered with an error. Try again later.', 'presshelm-ai' ),
			'invalid_token'        => __( 'That does not look like a bot token. Copy the whole token from @BotFather.', 'presshelm-ai' ),
			'bot_rejected'         => __( 'Telegram did not accept this token. Copy it again from @BotFather (or use /revoke there for a new one).', 'presshelm-ai' ),
			'telegram_unreachable' => __( 'PressHelm Cloud could not reach Telegram. Try again in a moment.', 'presshelm-ai' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : __( 'Something went wrong. Try again.', 'presshelm-ai' );
	}
}
