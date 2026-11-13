<?php
/**
 * Plugin tables (versioned; created/updated with dbDelta):
 *   presshelm_ledger  — every accepted command and its result (idempotency, redelivery).
 *   presshelm_journal — before-state of every change (undo / automatic rollback).
 *
 * @package PressHelm
 */

namespace PressHelm\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Schema installer.
 */
final class Schema {

	const VERSION = '1';
	const OPTION  = 'presshelm_db_version';

	/**
	 * Table name with prefix.
	 *
	 * @param string $name ledger|journal.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'presshelm_' . $name;
	}

	/**
	 * Creates/updates the tables when the stored version differs.
	 */
	public static function maybe_install() {
		if ( self::VERSION === get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$ledger  = self::table( 'ledger' );
		$journal = self::table( 'journal' );
		dbDelta(
			"CREATE TABLE {$ledger} (
				command_id varchar(64) NOT NULL,
				seq bigint(20) unsigned NOT NULL,
				ability varchar(100) NOT NULL,
				status varchar(20) NOT NULL,
				result longtext NULL,
				created_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (command_id),
				KEY seq (seq)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$journal} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				command_id varchar(64) NOT NULL DEFAULT '',
				kind varchar(40) NOT NULL,
				target varchar(191) NOT NULL DEFAULT '',
				before_state longtext NOT NULL,
				created_at datetime NOT NULL,
				restored_at datetime NULL,
				PRIMARY KEY  (id),
				KEY command_id (command_id)
			) {$charset};"
		);
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drops the tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( array( 'ledger', 'journal' ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes our own tables.
		}
		delete_option( self::OPTION );
	}
}
