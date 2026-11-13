<?php
/**
 * Every accepted command and its result. The primary key makes acceptance atomic, so a
 * redelivered or duplicated envelope is never executed twice.
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

use PressHelm\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Command ledger.
 */
final class Ledger {

	/**
	 * Row or null.
	 *
	 * @param string $command_id Command id.
	 * @return array|null
	 */
	public static function get( $command_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE command_id = %s', Schema::table( 'ledger' ), $command_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, must be fresh.
		return $row ? $row : null;
	}

	/**
	 * Atomically accepts a command (false if it already exists).
	 *
	 * @param string $command_id Command id.
	 * @param int    $seq        Sequence.
	 * @param string $ability    Ability.
	 * @return bool
	 */
	public static function start( $command_id, $seq, $ability ) {
		global $wpdb;
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; INSERT IGNORE gives atomic acceptance.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (command_id, seq, ability, status, created_at) VALUES (%s, %d, %s, %s, %s)',
				Schema::table( 'ledger' ),
				$command_id,
				$seq,
				$ability,
				'running',
				current_time( 'mysql', true )
			)
		);
		return 1 === (int) $ok;
	}

	/**
	 * Stores the outcome.
	 *
	 * @param string $command_id Command id.
	 * @param string $status     Final status.
	 * @param array  $outcome    {status, result}.
	 */
	public static function finish( $command_id, $status, array $outcome ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			Schema::table( 'ledger' ),
			array(
				'status'      => (string) $status,
				'result'      => (string) wp_json_encode( $outcome ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'command_id' => $command_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}
}
