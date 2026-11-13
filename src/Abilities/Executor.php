<?php
/**
 * Runs one ability with PressHelm's safeguards, whoever asked (the cloud via the Dispatcher, or a
 * local caller through the WordPress Abilities API): capability check for the current user, the
 * ability itself (which journals what it changes), then for changes a health gate with automatic
 * rollback of everything this run journaled (D35).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\HealthCheck;
use PressHelm\Recovery\Journal;
use PressHelm\Recovery\Verify;
use PressHelm\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Safe ability execution.
 */
final class Executor {

	/**
	 * Runs an ability as the current user.
	 *
	 * @param string $ability_id Ability id (e.g. content.update).
	 * @param array  $payload    Input.
	 * @param string $run_id     Command id (cloud) or a local run id; journal entries are tagged with it.
	 * @return array{status:string,result:mixed}
	 */
	public static function run( $ability_id, array $payload, $run_id ) {
		$mutates = ! empty( Registry::all()[ $ability_id ]['mutates'] );
		if ( $mutates ) {
			self::begin( (string) $run_id );
		}
		try {
			return self::run_unguarded( $ability_id, $payload, $run_id );
		} finally {
			if ( $mutates ) {
				self::end();
			}
		}
	}

	/**
	 * The run whose changes the shutdown safety net would undo (null when none is in progress).
	 *
	 * @var string|null
	 */
	private static $active = null;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Marks a changing run as in progress. If PHP stops before `end()` (fatal error, timeout,
	 * exit), the shutdown handler undoes everything this run journaled (ADR-003 safety net).
	 *
	 * @param string $run_id Run id.
	 */
	public static function begin( $run_id ) {
		self::$active = (string) $run_id;
		if ( ! self::$registered ) {
			register_shutdown_function( array( self::class, 'on_shutdown' ) );
			self::$registered = true;
		}
	}

	/** The run finished (whatever its outcome): nothing for the safety net to do. */
	public static function end() {
		self::$active = null;
	}

	/** Shutdown safety net. */
	public static function on_shutdown() {
		if ( null === self::$active ) {
			return;
		}
		$run          = self::$active;
		self::$active = null;
		foreach ( self::journal_ids( $run ) as $jid ) {
			Journal::restore( $jid );
		}
		// A cloud command: say what happened now, instead of leaving it "interrupted".
		if ( 0 === strpos( $run, 'local_' ) ) {
			return;
		}
		$last    = error_get_last();
		$message = is_array( $last ) && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true )
			? str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $last['message'] ) )
			: 'PHP stopped before the change finished';
		$outcome = array(
			'status' => 'rolled_back',
			'result' => array(
				'error'   => 'fatal_during_change',
				'message' => substr( $message, 0, 500 ),
			),
		);
		\PressHelm\Bridge\Ledger::finish( $run, 'rolled_back', $outcome );
		\PressHelm\Connect\Cloud::post(
			'/v1/connector/results',
			array(
				'command_id' => $run,
				'status'     => 'rolled_back',
				'result'     => $outcome['result'],
			),
			true,
			15
		);
	}

	/**
	 * The run itself (see `run`).
	 *
	 * @param string $ability_id Ability id.
	 * @param array  $payload    Input.
	 * @param string $run_id     Run id.
	 * @return array{status:string,result:mixed}
	 */
	private static function run_unguarded( $ability_id, array $payload, $run_id ) {
		if ( \PressHelm\Recovery\EmergencyStop::active() ) {
			return array(
				'status' => 'rejected',
				'result' => array( 'error' => 'emergency_stop' ),
			);
		}
		$abilities = Registry::all();
		$ability   = isset( $abilities[ $ability_id ] ) ? $abilities[ $ability_id ] : null;
		if ( ! $ability ) {
			return array(
				'status' => 'rejected',
				'result' => array( 'error' => 'unknown_ability' ),
			);
		}
		if ( ! current_user_can( \PressHelm\Bridge\TaskUser::gate( (string) $ability['cap'] ) ) ) {
			return array(
				'status' => 'rejected',
				'result' => array( 'error' => 'capability_denied' ),
			);
		}
		// The payload must match the ability's declared input (types, required fields, allowed
		// values), whatever sent it: nothing runs on input the ability did not declare.
		if ( ! empty( $ability['input'] ) && is_array( $ability['input'] ) ) {
			$valid = rest_validate_value_from_schema( $payload, $ability['input'], 'input' );
			if ( is_wp_error( $valid ) ) {
				return array(
					'status' => 'rejected',
					'result' => array(
						'error'   => 'invalid_input',
						'message' => $valid->get_error_message(),
					),
				);
			}
		}
		// Content changes are verified on the post afterwards; remember whether its blocks were valid.
		$verify_id = 'payload' === ( Verify::POST_ABILITIES[ $ability_id ] ?? null ) ? (int) ( $payload['id'] ?? 0 ) : 0;
		$was_valid = $verify_id && get_post( $verify_id ) ? Verify::blocks_valid( (string) get_post( $verify_id )->post_content ) : false;
		$before    = $verify_id ? Verify::snapshot( $verify_id ) : null;
		try {
			$out = call_user_func( $ability['run'], $payload, (string) $run_id );
		} catch ( \Throwable $e ) {
			// PHP 7+ throws for most fatal mistakes (undefined function, type errors, parse errors in
			// included code, e.g. a plugin being activated). Undo what this run journaled and say so.
			foreach ( self::journal_ids( (string) $run_id ) as $jid ) {
				Journal::restore( $jid );
			}
			return array(
				'status' => $ability['mutates'] ? 'rolled_back' : 'failed',
				'result' => array(
					'error'   => 'fatal_during_change',
					'message' => substr( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $e->getMessage() ) ), 0, 500 ),
				),
			);
		}
		if ( is_wp_error( $out ) ) {
			return array(
				'status' => 'failed',
				'result' => array(
					'error'   => $out->get_error_code(),
					'message' => $out->get_error_message(),
				),
			);
		}
		if ( $ability['mutates'] ) {
			// Some changes cannot affect how pages load (e.g. a new file in the media library).
			$health = empty( $ability['skip_health'] ) ? HealthCheck::gate() : array(
				'ok'      => true,
				'skipped' => 'this change cannot affect how pages load',
			);
			// A bot challenge in front of the site (CDN or host) blocked the check: that says nothing
			// about the change. Content and settings changes are kept; code, plugin and database
			// changes are only kept when a check really ran.
			if ( ! $health['ok'] && ! empty( $health['blocked'] ) && ! in_array( \PressHelm\Safety\LocalPolicy::area( (string) $ability_id ), array( 'code', 'plugins', 'database' ), true ) ) {
				$health = array(
					'ok'         => true,
					'unverified' => 'challenge',
					'note'       => 'The site check was blocked by a browser challenge (CDN or host), so it could not run.',
				);
			}
			if ( ! $health['ok'] ) {
				// Automatic rollback (D35): undo every journal entry this run created.
				foreach ( self::journal_ids( (string) $run_id ) as $jid ) {
					Journal::restore( $jid );
				}
				return array(
					'status' => 'rolled_back',
					'result' => array(
						'error'  => 'health_check_failed',
						'health' => $health,
					),
				);
			}
			$out['health'] = $health;
			$post_id       = Verify::post_of( (string) $ability_id, $payload, (array) $out );
			if ( $post_id ) {
				$check = Verify::post( $post_id, $verify_id ? $was_valid : false, $verify_id ? $before : null );
				if ( ! $check['ok'] ) {
					foreach ( self::journal_ids( (string) $run_id ) as $jid ) {
						Journal::restore( $jid );
					}
					return array(
						'status' => 'rolled_back',
						'result' => array(
							'error'  => 'verification_failed',
							'verify' => $check,
							'health' => $health,
						),
					);
				}
				$out['verified'] = $check['checks'];
			} else {
				/**
				 * Live check for an add-on's own ability (null when it has none).
				 *
				 * @param array|null $verified   Checks to report.
				 * @param string     $ability_id Ability.
				 * @param array      $payload    Its input.
				 */
				$verified = apply_filters( 'presshelm_verify_live', null, $ability_id, $payload );
				if ( is_array( $verified ) ) {
					$out['verified'] = $verified;
				}
			}
		}
		return array(
			'status' => 'succeeded',
			'result' => $out,
		);
	}

	/**
	 * Journal ids created by a run.
	 *
	 * @param string $run_id Run id.
	 * @return int[]
	 */
	private static function journal_ids( $run_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE command_id = %s AND restored_at IS NULL ORDER BY id DESC', Schema::table( 'journal' ), $run_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}
}
