<?php
/**
 * What may happen after a pack's change: a closed list, chosen by name. Most are generic (clear a
 * plugin's transients or meta cache); a few are a plugin's own public PHP that has no Ability or
 * REST route (tier D in to-do 21). A pack can only pick from this list; nothing here calls a
 * function whose name comes from the pack.
 *
 * @package PressHelm
 */

namespace PressHelm\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * Effects.
 */
final class Effects {

	/**
	 * Effect names this kernel knows.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array( 'meta.delete', 'transient.delete_prefix', 'cache.clean_post', 'vc.rebuild_css', 'pagelayer.load_renderers' );
	}

	/**
	 * Whether a name is a known effect.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function known( $name ) {
		return in_array( $name, self::names(), true );
	}

	/**
	 * Runs a pack's effects after a change (or an undo).
	 *
	 * @param array $effects Effects ({name, …} or names).
	 * @param int   $post_id Post id.
	 * @return true|\WP_Error
	 */
	public static function run( array $effects, $post_id ) {
		foreach ( $effects as $fx ) {
			$fx   = is_array( $fx ) ? $fx : array( 'name' => (string) $fx );
			$name = (string) ( $fx['name'] ?? '' );
			if ( 'meta.delete' === $name ) {
				$key = (string) ( $fx['key'] ?? '' );
				if ( preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $key ) && 0 !== strpos( $key, '_wp_' ) && false === strpos( $key, 'presshelm' ) ) {
					delete_post_meta( (int) $post_id, $key );
				}
			} elseif ( 'transient.delete_prefix' === $name ) {
				$prefix = (string) ( $fx['prefix'] ?? '' );
				if ( preg_match( '/^[A-Za-z0-9_-]{4,60}$/', $prefix ) && false === strpos( $prefix, 'presshelm' ) ) {
					global $wpdb;
					$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
					$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding a plugin's transients by prefix.
					foreach ( $keys as $k ) {
						delete_transient( substr( (string) $k, strlen( '_transient_' ) ) );
					}
				}
			} elseif ( 'cache.clean_post' === $name ) {
				clean_post_cache( (int) $post_id );
			} elseif ( 'pagelayer.load_renderers' === $name ) {
				// PageLayer loads its widget renderers only on the front end; this request renders the page too.
				if ( function_exists( 'pagelayer_load_shortcodes' ) ) {
					pagelayer_load_shortcodes();
				}
			} elseif ( 'vc.rebuild_css' === $name ) {
				// Visual Composer bundles a page's CSS from its stored element CSS with its own PHP.
				if ( function_exists( 'vchelper' ) ) {
					try {
						vchelper( 'Assets' )->generateSourceCssFile( array(), array( 'sourceId' => (int) $post_id ) );
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'presshelm_builder_css', 'Visual Composer could not rebuild the page\'s CSS: ' . $e->getMessage() );
					}
				}
			}
		}
		return true;
	}
}
