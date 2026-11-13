<?php
/**
 * Connection state after pairing: site id, pinned cloud public key, masked credential hints.
 * Never holds AI keys or bot tokens (those live in the cloud vault).
 *
 * @package PressHelm
 */

namespace PressHelm\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Connection record.
 */
final class Connection {

	const OPTION = 'presshelm_connection';

	/**
	 * Record or empty array.
	 *
	 * @return array
	 */
	public static function get() {
		$c = get_option( self::OPTION );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Whether paired.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::site_id();
	}

	/**
	 * Site id or ''.
	 *
	 * @return string
	 */
	public static function site_id() {
		$c = self::get();
		return isset( $c['site_id'] ) ? (string) $c['site_id'] : '';
	}

	/**
	 * Merges fields into the record.
	 *
	 * @param array $fields Fields.
	 */
	public static function update( array $fields ) {
		update_option( self::OPTION, array_merge( self::get(), $fields ), false );
	}

	/**
	 * Removes the connection locally.
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}
}
