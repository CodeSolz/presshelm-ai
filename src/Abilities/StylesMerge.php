<?php
/**
 * Merges a partial change into a theme.json user-styles document (M3.4b). Only `settings` and
 * `styles` can be changed; objects merge key by key, other values replace, and `null` removes a
 * key. Pure PHP.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Global styles merge.
 */
final class StylesMerge {

	const MAX_BYTES = 204800; // 200 KB of JSON.

	/**
	 * New document.
	 *
	 * @param array $current Current user styles (decoded JSON).
	 * @param array $patch   {settings?, styles?}.
	 * @return array
	 * @throws \InvalidArgumentException When the patch touches anything else or gets too big.
	 */
	public static function apply( array $current, array $patch ) {
		$extra = array_diff( array_keys( $patch ), array( 'settings', 'styles' ) );
		if ( $extra ) {
			throw new \InvalidArgumentException( 'Only settings and styles can be changed' );
		}
		if ( ! $patch ) {
			throw new \InvalidArgumentException( 'Nothing to change' );
		}
		$out = $current;
		foreach ( $patch as $key => $value ) {
			if ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException( 'settings and styles must be objects' );
			}
			$out[ $key ] = self::merge( isset( $out[ $key ] ) && is_array( $out[ $key ] ) ? $out[ $key ] : array(), $value );
		}
		$out['isGlobalStylesUserThemeJSON'] = true;
		if ( ! isset( $out['version'] ) ) {
			$out['version'] = 3;
		}
		if ( strlen( (string) json_encode( $out ) ) > self::MAX_BYTES ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- size check only (pure class, no WordPress).
			throw new \InvalidArgumentException( 'The styles would be too large' );
		}
		return $out;
	}

	/**
	 * Recursive merge: objects key by key, lists and scalars replace, null removes.
	 *
	 * @param array $base  Base.
	 * @param array $patch Patch.
	 * @return array
	 */
	private static function merge( array $base, array $patch ) {
		foreach ( $patch as $k => $v ) {
			if ( null === $v ) {
				unset( $base[ $k ] );
			} elseif ( is_array( $v ) && ! self::is_list( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! self::is_list( $base[ $k ] ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * A JSON list (not an object).
	 *
	 * @param array $a Array.
	 * @return bool
	 */
	private static function is_list( array $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}
