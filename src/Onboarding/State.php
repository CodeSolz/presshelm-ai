<?php
/**
 * Onboarding state (D46 state machine), derived from what is actually stored so it can never
 * drift from reality:
 * PLUGIN_ACTIVE → AI_STAGED → TELEGRAM_STAGED → CLOUD_AUTH_PENDING → SITE_PAIRED → SECRETS_IMPORTED
 * (cloud-side states BOT_WEBHOOK_CONFIGURED → TELEGRAM_OWNER_VERIFIED → AGENT_READY follow in M1.12+).
 *
 * @package PressHelm
 */

namespace PressHelm\Onboarding;

use PressHelm\Connect\Connection;
use PressHelm\Connect\Pairing;

defined( 'ABSPATH' ) || exit;

/**
 * Derived onboarding state.
 */
final class State {

	/**
	 * Current state.
	 *
	 * @return string
	 */
	public static function current() {
		$c = Connection::get();
		if ( ! empty( $c['site_id'] ) ) {
			return ( isset( $c['handoff'] ) && 'done' === $c['handoff'] ) ? 'SECRETS_IMPORTED' : 'SITE_PAIRED';
		}
		if ( get_transient( Pairing::TRANSIENT ) ) {
			return 'CLOUD_AUTH_PENDING';
		}
		$p = PendingCredentials::record();
		if ( ! empty( $p['telegram'] ) && ! empty( $p['ai']['model'] ) ) {
			return 'TELEGRAM_STAGED';
		}
		if ( ! empty( $p['ai']['model'] ) ) {
			return 'AI_STAGED';
		}
		return 'PLUGIN_ACTIVE';
	}

	/**
	 * Wizard step to show by default for the current state.
	 *
	 * @return string ai|telegram|connect|ready
	 */
	public static function default_step() {
		switch ( self::current() ) {
			case 'SECRETS_IMPORTED':
			case 'SITE_PAIRED':
				return 'ready';
			case 'TELEGRAM_STAGED':
			case 'CLOUD_AUTH_PENDING':
				return 'connect';
			case 'AI_STAGED':
				return 'telegram';
			default:
				return 'ai';
		}
	}
}
