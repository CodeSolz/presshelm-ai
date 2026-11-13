<?php
/**
 * Exact before-state of a WooCommerce product and its variations, for undo: the post fields, every
 * meta value as stored, every product taxonomy term (categories, tags, type, visibility,
 * attributes). Restoring writes all of it back, recreates variations that were deleted (same ids
 * when free), removes variations that did not exist, then lets WooCommerce resync its lookups.
 *
 * @package PressHelm
 */

namespace PressHelm\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Product snapshot.
 */
final class Snapshot {

	const POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'menu_order', 'post_parent', 'comment_status', 'post_password', 'post_type', 'post_date', 'post_date_gmt' );

	/**
	 * Snapshot of a product (with its variations).
	 *
	 * @param int $id Product id.
	 * @return array|null
	 */
	public static function capture( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return null;
		}
		$out = self::one( $post );
		if ( 'product' === $post->post_type ) {
			$out['variations'] = array();
			foreach ( get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => 'product_variation',
					'post_status' => 'any',
				)
			) as $child ) {
				$out['variations'][] = self::one( $child );
			}
		}
		return $out;
	}

	/**
	 * One post's state.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function one( \WP_Post $post ) {
		$fields = array();
		foreach ( self::POST_FIELDS as $f ) {
			$fields[ $f ] = $post->{$f};
		}
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$t = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $t ) ) {
				$terms[ $tax ] = array_map( 'intval', $t );
			}
		}
		return array(
			'id'     => (int) $post->ID,
			'fields' => $fields,
			'meta'   => array_map( 'array_values', (array) get_post_meta( $post->ID ) ), // As stored (serialized values stay strings).
			'terms'  => $terms,
		);
	}

	/**
	 * Writes a snapshot back.
	 *
	 * @param array $snap Snapshot.
	 * @return true|\WP_Error
	 */
	public static function restore( array $snap ) {
		$r = self::put( $snap );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( isset( $snap['variations'] ) ) {
			$keep = array();
			foreach ( (array) $snap['variations'] as $v ) {
				$put = self::put( $v );
				if ( is_wp_error( $put ) ) {
					return $put;
				}
				$keep[] = (int) $put;
			}
			// Variations made after the snapshot go (variations have no trash in WooCommerce).
			foreach ( get_children(
				array(
					'post_parent' => (int) $snap['id'],
					'post_type'   => 'product_variation',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			) as $child_id ) {
				if ( ! in_array( (int) $child_id, $keep, true ) ) {
					wp_delete_post( (int) $child_id, true );
				}
			}
		}
		clean_post_cache( (int) $snap['id'] );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $snap['id'] );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $snap['id'] ) : null;
		if ( $product ) {
			$product->save(); // WooCommerce rebuilds its lookup tables from the restored data.
			// Save hooks (WooCommerce's own syncing, other plugins) may add or change values: the
			// snapshot is written once more so the product is exactly as it was.
			self::put( $snap );
			foreach ( (array) ( $snap['variations'] ?? array() ) as $v ) {
				self::put( $v );
			}
			clean_post_cache( (int) $snap['id'] );
			wc_delete_product_transients( (int) $snap['id'] );
		}
		return true;
	}

	/**
	 * One post back (recreated with the same id when it was deleted).
	 *
	 * @param array $s State.
	 * @return int|\WP_Error Post id.
	 */
	private static function put( array $s ) {
		$id     = (int) $s['id'];
		$fields = (array) $s['fields'];
		if ( get_post( $id ) ) {
			$r = wp_update_post( wp_slash( array_merge( $fields, array( 'ID' => $id ) ) ), true );
		} else {
			$r = wp_insert_post( wp_slash( array_merge( $fields, array( 'import_id' => $id ) ) ), true );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$id = (int) $r;
		foreach ( array_keys( (array) get_post_meta( $id ) ) as $key ) {
			delete_post_meta( $id, (string) $key );
		}
		foreach ( (array) $s['meta'] as $key => $values ) {
			foreach ( (array) $values as $raw ) {
				add_post_meta( $id, (string) $key, wp_slash( maybe_unserialize( $raw ) ) );
			}
		}
		foreach ( (array) $s['terms'] as $tax => $ids ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $tax );
		}
		clean_post_cache( $id );
		return $id;
	}
}
