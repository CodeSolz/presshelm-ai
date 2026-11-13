<?php
/**
 * Emergency stop (M3.3, threat T8): the site owner's own off switch, which PressHelm Cloud cannot
 * override. While it is on, the plugin does not poll, ignores wake signals and refuses every
 * ability (cloud commands and local Abilities API calls alike). The cloud is told through the
 * heartbeat so the agent can explain it. Turned on by an administrator in wp-admin, or by
 * `define( 'PRESSHELM_EMERGENCY_STOP', true );` in wp-config.php; only an administrator (or
 * removing the constant) turns it off.
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Emergency stop.
 */
final class EmergencyStop {

	const OPTION = 'presshelm_emergency_stop';

	/**
	 * Whether PressHelm is stopped on this site.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::by_constant() || is_array( get_option( self::OPTION ) );
	}

	/**
	 * Stopped by the wp-config constant (can't be lifted from wp-admin).
	 *
	 * @return bool
	 */
	public static function by_constant() {
		return defined( 'PRESSHELM_EMERGENCY_STOP' ) && PRESSHELM_EMERGENCY_STOP;
	}

	/**
	 * Details for the admin screen.
	 *
	 * @return array{active:bool, by_constant:bool, at?:int, by?:int}
	 */
	public static function state() {
		$o = get_option( self::OPTION );
		return array_merge(
			array(
				'active'      => self::active(),
				'by_constant' => self::by_constant(),
			),
			is_array( $o ) ? array(
				'at' => (int) ( $o['at'] ?? 0 ),
				'by' => (int) ( $o['by'] ?? 0 ),
			) : array()
		);
	}

	/**
	 * Stops PressHelm now.
	 *
	 * @param int $user_id Who stopped it.
	 */
	public static function engage( $user_id ) {
		update_option(
			self::OPTION,
			array(
				'at' => time(),
				'by' => (int) $user_id,
			),
			true
		);
		// Nothing should be picked up again until it is lifted.
		\PressHelm\Bridge\Poller::unschedule();
	}

	/**
	 * Lifts the stop (the constant, if set, still wins).
	 */
	public static function release() {
		delete_option( self::OPTION );
	}

	/**
	 * The reason a request is refused while stopped.
	 *
	 * @return \WP_Error
	 */
	public static function error() {
		return new \WP_Error( 'presshelm_emergency_stop', __( 'PressHelm is stopped on this site (emergency stop). An administrator can resume it in WordPress → PressHelm.', 'presshelm-ai' ) );
	}
}
