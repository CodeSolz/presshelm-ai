<?php
/**
 * Collects commands from the cloud (outbound HTTPS, D23). Triggered by WP-Cron every minute, by
 * the signed wake route, and later by an open admin tab. A lock prevents overlapping polls.
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

use PressHelm\Recovery\EmergencyStop;

use PressHelm\Abilities\Registry;
use PressHelm\Connect\Cloud;
use PressHelm\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Poller.
 */
final class Poller {

	const HOOK      = 'presshelm_poll';
	const LOCK      = 'presshelm_poll_lock';
	const HEARTBEAT = 'presshelm_last_heartbeat';

	/** Longest a hot session keeps polling after a wake (seconds; less when PHP's limit is lower). */
	const HOT_SECONDS = 22;

	/** Longest single held poll (ms); the cloud answers at once when a command arrives. */
	const HOT_WAIT_MS = 15000;

	/**
	 * Hooks: cron schedule + handler.
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute is intended: it is how fast commands are picked up without a wake.
		add_action( self::HOOK, array( self::class, 'run' ) );
		if ( Connection::is_connected() && ! EmergencyStop::active() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, 'presshelm_minute', self::HOOK );
		}
	}

	/**
	 * Adds a one-minute schedule.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['presshelm_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (PressHelm)', 'presshelm-ai' ),
		);
		return $s;
	}

	/**
	 * After a wake: the response is sent first, then this request polls right away (no second
	 * WordPress start through WP-Cron) and stays for a short hot session, so the next commands of
	 * the same chat arrive in milliseconds.
	 */
	public static function run_after_response() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		ignore_user_abort( true );
		self::run( true );
	}

	/**
	 * One poll cycle; returns per-envelope statuses (useful for tests and logs). Hot: keep polling
	 * with a held request while commands keep coming, within PHP's time limit.
	 *
	 * @param bool $hot Hot session (after a wake).
	 * @return string[]|\WP_Error
	 */
	public static function run( $hot = false ) {
		if ( ! Connection::is_connected() ) {
			return array();
		}
		if ( EmergencyStop::active() ) {
			return EmergencyStop::error(); // No polling at all while stopped.
		}
		if ( get_transient( self::LOCK ) ) {
			return new \WP_Error( 'presshelm_busy', 'Another poll is running' );
		}
		set_transient( self::LOCK, 1, 120 );
		try {
			self::maybe_heartbeat();
			$deadline = microtime( true ) + self::hot_seconds();
			$out      = array();
			$wait     = 0;
			do {
				$body = array( 'capacity' => 5 );
				if ( $wait > 0 ) {
					$body['wait_ms'] = $wait;
				}
				$res = Cloud::post( '/v1/connector/poll', $body, true, 30 + (int) ceil( $wait / 1000 ) );
				if ( is_wp_error( $res ) ) {
					return $out ? $out : $res;
				}
				if ( 200 !== $res['code'] ) {
					return $out ? $out : new \WP_Error( 'presshelm_poll', isset( $res['json']['error']['code'] ) ? (string) $res['json']['error']['code'] : 'poll_failed' );
				}
				$envelopes = (array) ( $res['json']['envelopes'] ?? array() );
				foreach ( $envelopes as $env ) {
					$out[] = Dispatcher::handle( (array) $env );
				}
				// Go on only in a hot session, while commands keep coming, with a cloud that can hold
				// the poll (an older cloud would answer at once and this would spin).
				$left = $deadline - microtime( true );
				$wait = (int) min( self::HOT_WAIT_MS, max( 0, ( $left - 3 ) * 1000 ) );
			} while ( $hot && $envelopes && ! empty( $res['json']['wait_supported'] ) && $wait >= 1000 && ! EmergencyStop::active() );
			return $out;
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * How long a hot session may last: HOT_SECONDS, less when PHP's own limit is lower.
	 *
	 * @return float
	 */
	private static function hot_seconds() {
		$limit = (int) ini_get( 'max_execution_time' );
		return $limit > 0 ? (float) min( self::HOT_SECONDS, max( 0, $limit - 8 ) ) : (float) self::HOT_SECONDS;
	}

	/**
	 * The next poll sends the capability report (e.g. after a plugin was switched on or off).
	 */
	public static function report_soon() {
		delete_transient( self::HEARTBEAT );
	}

	/**
	 * Sends versions + inventory at most every 5 minutes.
	 */
	private static function maybe_heartbeat() {
		if ( (int) get_transient( self::HEARTBEAT ) > 0 ) {
			return;
		}
		self::heartbeat_now();
	}

	/**
	 * Sends versions, the capability report and the emergency-stop state now (also called when
	 * the stop is switched on or off, so the cloud knows at once).
	 */
	public static function heartbeat_now() {
		if ( ! Connection::is_connected() ) {
			return;
		}
		// Housekeeping at most daily: old snapshots go (M3.3).
		if ( ! get_transient( 'presshelm_snapshot_prune' ) ) {
			set_transient( 'presshelm_snapshot_prune', 1, DAY_IN_SECONDS );
			\PressHelm\Recovery\Snapshots::prune();
		}
		set_transient( self::HEARTBEAT, time(), 5 * MINUTE_IN_SECONDS );
		// No loopback request from the cron path: a cached result is reused (Inventory).
		$inv = \PressHelm\Capabilities\Inventory::report( false );
		$res = Cloud::post(
			'/v1/connector/heartbeat',
			array(
				'plugin_version' => PRESSHELM_VERSION,
				'wp_version'     => $inv['site']['wp'],
				'php_version'    => $inv['site']['php'],
				'inventory'      => $inv,
				'emergency_stop' => EmergencyStop::active(),
			)
		);
		if ( ! is_wp_error( $res ) && is_array( $res['json'] ?? null ) ) {
			/**
			 * The cloud's answer to the heartbeat (add-ons read their settings from it).
			 *
			 * @param array $json Answer.
			 */
			do_action( 'presshelm_heartbeat_response', $res['json'] );
		}
	}

	/**
	 * Unschedules (disconnect / deactivate).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
