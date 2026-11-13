<?php
/**
 * A page builder PressHelm can read and change (M3.9). Each adapter goes through the builder's
 * own save path (so its caches, CSS and revisions stay right) and can put a page back exactly.
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Builder adapter.
 */
interface Adapter {

	/**
	 * Short id (e.g. "elementor").
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Whether the builder is active on this site.
	 *
	 * @return bool
	 */
	public function active();

	/**
	 * Whether a post is built with this builder.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id );

	/**
	 * The page as stored (exact bytes, for hashing and undo).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id );

	/**
	 * Element tree for the agent: [{id, type, widget, path, settings, children}].
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id );

	/**
	 * Merges settings into one element and saves through the builder.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Element id.
	 * @param array  $settings   Settings to set.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings );

	/**
	 * Puts a page back exactly as `raw()` returned it, through the builder.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Stored data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw );

	/**
	 * A structure change: insert, remove, move or duplicate one element (the builder's own rules
	 * for what may contain what apply), saved through the builder.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      {op, element_id?, parent_id?, position?, element?}.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op );

	/**
	 * What an agent needs to know to build elements for this site (layout kind, element types).
	 *
	 * @return array
	 */
	public function vocabulary();

	/**
	 * The builder's own definition of its element types, read live: without $type the list of
	 * types; with $type that type's settings (name, kind, label, default, options).
	 *
	 * @param string $type Widget/module/element type, or ''.
	 * @param bool   $all  Also style and advanced settings (default: content only).
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all );
}
