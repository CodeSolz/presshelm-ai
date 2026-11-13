<?php
/**
 * Pre-connection staging (D40): the AI credential and the bot token wait here, encrypted, for at
 * most 24 hours until the site is paired; then they are sent once to the cloud vault and wiped.
 * Only masked hints and non-secret metadata are ever readable without decryption.
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

use PressHelm\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted staging record.
 */
final class PendingCredentials {

	const OPTION = 'presshelm_pending';
	const TTL    = DAY_IN_SECONDS;

	/**
	 * Raw record (no secrets in clear), or an empty array when missing/expired.
	 *
	 * @return array
	 */
	public static function record() {
		$r = get_option( self::OPTION );
		if ( ! is_array( $r ) ) {
			return array();
		}
		if ( (int) ( $r['expires'] ?? 0 ) < time() ) {
			self::wipe();
			return array();
		}
		return $r;
	}

	/**
	 * Saves the record (autoload off) and refreshes the 24-hour expiry.
	 *
	 * @param array $r Record.
	 */
	private static function save( array $r ) {
		$r['expires'] = time() + self::TTL;
		update_option( self::OPTION, $r, false );
	}

	/**
	 * Stages the AI credential.
	 *
	 * @param string $provider Provider id.
	 * @param string $method   api_key|oauth.
	 * @param string $secret   Key or token.
	 * @param array  $meta     model, fallback_model, base_url, models (non-secret).
	 */
	public static function set_ai( $provider, $method, $secret, array $meta = array() ) {
		$r       = self::record();
		$r['ai'] = array(
			'provider'       => (string) $provider,
			'method'         => (string) $method,
			'sealed'         => Crypto::seal( $secret ),
			'hint'           => Crypto::mask( $secret ),
			'model'          => (string) ( $meta['model'] ?? '' ),
			'fallback_model' => (string) ( $meta['fallback_model'] ?? '' ),
			'base_url'       => (string) ( $meta['base_url'] ?? '' ),
			'models'         => array_slice( array_map( 'strval', (array) ( $meta['models'] ?? array() ) ), 0, 500 ),
		);
		self::save( $r );
	}

	/**
	 * Updates non-secret AI choices (model / fallback).
	 *
	 * @param string $model    Model id.
	 * @param string $fallback Fallback model id.
	 */
	public static function set_models( $model, $fallback ) {
		$r = self::record();
		if ( empty( $r['ai'] ) ) {
			return;
		}
		$r['ai']['model']          = (string) $model;
		$r['ai']['fallback_model'] = (string) $fallback;
		self::save( $r );
	}

	/**
	 * Stages the Telegram bot token + its public details.
	 *
	 * @param string $token Bot token.
	 * @param array  $bot   id, username, name from getMe.
	 */
	public static function set_telegram( $token, array $bot ) {
		$r             = self::record();
		$r['telegram'] = array(
			'sealed'   => Crypto::seal( $token ),
			'hint'     => '…' . substr( (string) $token, -4 ),
			'bot_id'   => (int) ( $bot['id'] ?? 0 ),
			'username' => (string) ( $bot['username'] ?? '' ),
			'name'     => (string) ( $bot['name'] ?? '' ),
		);
		self::save( $r );
	}

	/**
	 * This site uses the shared bot of the owner's PressHelm account (Business and Agency): no token.
	 */
	public static function set_telegram_shared() {
		$r             = self::record();
		$r['telegram'] = array(
			'shared'   => true,
			'hint'     => '',
			'bot_id'   => 0,
			'username' => '',
			'name'     => '',
		);
		self::save( $r );
	}

	/**
	 * AI credential staged in PressHelm Cloud (M1.19): only the reference and details are kept.
	 *
	 * @param string $ref  Staging reference.
	 * @param array  $meta provider, method, base_url, hint, models.
	 */
	public static function set_ai_staged( $ref, array $meta ) {
		$r       = self::record();
		$r['ai'] = array(
			'provider'       => (string) ( $meta['provider'] ?? '' ),
			'method'         => (string) ( $meta['method'] ?? 'api_key' ),
			'staged_ref'     => (string) $ref,
			'hint'           => (string) ( $meta['hint'] ?? '' ),
			'model'          => '',
			'fallback_model' => '',
			'base_url'       => (string) ( $meta['base_url'] ?? '' ),
			'models'         => array_slice( array_map( 'strval', (array) ( $meta['models'] ?? array() ) ), 0, 500 ),
		);
		self::save( $r );
	}

	/**
	 * Bot token staged in PressHelm Cloud (M1.19).
	 *
	 * @param string $ref  Staging reference.
	 * @param array  $meta hint, bot {id, username, name}.
	 */
	public static function set_telegram_staged( $ref, array $meta ) {
		$bot           = (array) ( $meta['bot'] ?? array() );
		$r             = self::record();
		$r['telegram'] = array(
			'staged_ref' => (string) $ref,
			'hint'       => (string) ( $meta['hint'] ?? '' ),
			'bot_id'     => (int) ( $bot['id'] ?? 0 ),
			'username'   => (string) ( $bot['username'] ?? '' ),
			'name'       => (string) ( $bot['name'] ?? '' ),
		);
		self::save( $r );
	}

	/**
	 * Body for the one-time hand-off (decrypted only here, in memory).
	 *
	 * @return array|\WP_Error
	 */
	public static function handoff_body() {
		$r    = self::record();
		$body = array();
		if ( ! empty( $r['ai']['staged_ref'] ) ) {
			// Staged in the cloud: the reference is enough (only this site's key can redeem it).
			$body['ai'] = array_filter(
				array(
					'staged_ref'     => $r['ai']['staged_ref'],
					'model'          => $r['ai']['model'],
					'fallback_model' => $r['ai']['fallback_model'],
				),
				'strlen'
			);
		} elseif ( ! empty( $r['ai'] ) ) {
			$secret = Crypto::open( $r['ai']['sealed'] );
			if ( false === $secret ) {
				return new \WP_Error( 'presshelm_staging_unreadable', __( 'The saved AI credential could not be read (the site\'s security keys may have changed). Enter it again.', 'presshelm-ai' ) );
			}
			$body['ai'] = array_filter(
				array(
					'provider'       => $r['ai']['provider'],
					'method'         => $r['ai']['method'],
					'secret'         => $secret,
					'model'          => $r['ai']['model'],
					'fallback_model' => $r['ai']['fallback_model'],
					'base_url'       => $r['ai']['base_url'],
				),
				'strlen'
			);
		}
		if ( ! empty( $r['telegram']['shared'] ) ) {
			$body['telegram'] = array( 'shared' => true );
		} elseif ( ! empty( $r['telegram']['staged_ref'] ) ) {
			$body['telegram'] = array(
				'staged_ref'   => $r['telegram']['staged_ref'],
				'username'     => $r['telegram']['username'],
				'display_name' => $r['telegram']['name'],
			);
		} elseif ( ! empty( $r['telegram'] ) ) {
			$token = Crypto::open( $r['telegram']['sealed'] );
			if ( false === $token ) {
				return new \WP_Error( 'presshelm_staging_unreadable', __( 'The saved bot token could not be read. Enter it again.', 'presshelm-ai' ) );
			}
			$body['telegram'] = array(
				'token'        => $token,
				'username'     => $r['telegram']['username'],
				'display_name' => $r['telegram']['name'],
			);
		}
		return $body;
	}

	/**
	 * Deletes everything staged.
	 */
	public static function wipe() {
		delete_option( self::OPTION );
	}
}
