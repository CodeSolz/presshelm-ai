<?php
/**
 * Categories, tags and other taxonomies (A3): list, create, rename/move, delete and merge.
 * Every change journals the terms, their meta, the term lists of the posts involved and the
 * menus linking to them, so undo puts it all back (deleted terms with the same ids).
 * Assigning terms to posts is content.bulk_update (add_terms / remove_terms).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Terms.
 */
final class Terms {

	/** Internal taxonomies that are not the owner's to edit here. */
	const HIDDEN = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' );

	/** Posts (or other objects) one change may touch, so the undo record stays small. */
	const MAX_OBJECTS = 5000;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$tax  = array(
			'type'      => 'string',
			'maxLength' => 32,
		);
		$id   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$name = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$slug = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		$desc = array(
			'type'      => 'string',
			'maxLength' => 5000,
		);
		$par  = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$undo = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		return array(
			'terms.list'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'list_terms' ),
				'label'       => 'List categories, tags and other terms',
				'description' => 'Without taxonomy: the taxonomies that can be edited (category, post_tag, product_cat, ...). With taxonomy: its terms (id, name, slug, parent, post count, description, address), by name, optionally filtered by search text or parent. Max 200 per page.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => $tax,
						'search'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'parent'   => $par,
						'limit'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
			'terms.create' => array(
				'risk'        => 'LOW',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'create' ),
				'label'       => 'Add a category, tag or term',
				'description' => 'Adds a term to a taxonomy (name, optional slug, description and parent for hierarchical ones like categories). Undo removes it again.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy'    => $tax,
						'name'        => $name,
						'slug'        => $slug,
						'description' => $desc,
						'parent'      => $par,
					),
					'required'             => array( 'taxonomy', 'name' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'terms.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'update' ),
				'label'       => 'Rename or move a term',
				'description' => 'Changes a term\'s name, slug (its address changes; old links are not redirected), description or parent. Undo puts it back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy'    => $tax,
						'id'          => $id,
						'name'        => $name,
						'slug'        => $slug,
						'description' => $desc,
						'parent'      => $par,
					),
					'required'             => array( 'taxonomy', 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $undo,
				'undo'        => 'journal.restore',
			),
			'terms.delete' => array(
				'risk'        => 'HIGH',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'delete' ),
				'label'       => 'Delete a term',
				'description' => 'Deletes a term. Its posts lose it (posts left without a category go to the default category); child terms move up to its parent; menu links to it are removed. The default category cannot be deleted. Undo brings it back with the same id, its posts, children and menu links.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => $tax,
						'id'       => $id,
					),
					'required'             => array( 'taxonomy', 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $undo,
				'undo'        => 'journal.restore',
			),
			'terms.merge'  => array(
				'risk'        => 'HIGH',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'merge' ),
				'label'       => 'Merge terms',
				'description' => 'Moves every post of the "from" terms into the "into" term, moves their child terms and menu links to it, then deletes the "from" terms. Undo puts everything back (same ids).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => $tax,
						'from'     => array(
							'type'     => 'array',
							'items'    => $id,
							'minItems' => 1,
							'maxItems' => 20,
						),
						'into'     => $id,
					),
					'required'             => array( 'taxonomy', 'from', 'into' ),
					'additionalProperties' => false,
				),
				'annotations' => $undo,
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * A taxonomy the owner may edit here, or an error.
	 *
	 * @param string $name Taxonomy name.
	 * @return \WP_Taxonomy|\WP_Error
	 */
	private static function taxonomy( $name ) {
		$tax = get_taxonomy( (string) $name );
		if ( ! $tax || ! $tax->show_ui || in_array( $tax->name, self::HIDDEN, true ) ) {
			return new \WP_Error( 'presshelm_taxonomy', 'Unknown taxonomy "' . sanitize_key( (string) $name ) . '". List them with terms.list (no taxonomy).' );
		}
		return $tax;
	}

	/**
	 * A term of a taxonomy, or an error.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return \WP_Term|\WP_Error
	 */
	private static function term( $id, $tax ) {
		$term = get_term( (int) $id, $tax, OBJECT, 'raw' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'presshelm_not_found', 'No ' . $tax . ' term with id ' . (int) $id . '.' );
		}
		return $term;
	}

	/**
	 * The default term of a taxonomy (e.g. Uncategorized), or 0.
	 *
	 * @param string $tax Taxonomy.
	 * @return int
	 */
	private static function default_id( $tax ) {
		return 'post_tag' === $tax ? 0 : (int) get_option( 'category' === $tax ? 'default_category' : 'default_' . $tax, 0 );
	}

	/**
	 * Term output.
	 *
	 * @param \WP_Term $t Term.
	 * @return array
	 */
	private static function out( \WP_Term $t ) {
		$link = get_term_link( $t );
		return array(
			'id'          => (int) $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'parent'      => (int) $t->parent,
			'count'       => (int) $t->count,
			'description' => wp_html_excerpt( (string) $t->description, 300, '…' ),
			'url'         => is_wp_error( $link ) ? null : $link,
		);
	}

	/**
	 * Lists taxonomies, or the terms of one.
	 *
	 * @param array $p Input.
	 * @return array|\WP_Error
	 */
	public static function list_terms( array $p ) {
		if ( empty( $p['taxonomy'] ) ) {
			$out = array();
			foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
				if ( in_array( $tax->name, self::HIDDEN, true ) ) {
					continue;
				}
				$out[] = array(
					'taxonomy'     => $tax->name,
					'label'        => $tax->label,
					'hierarchical' => (bool) $tax->hierarchical,
					'used_by'      => array_values( (array) $tax->object_type ),
					'terms'        => (int) wp_count_terms( array( 'taxonomy' => $tax->name ) ),
					'default_term' => self::default_id( $tax->name ) ? self::default_id( $tax->name ) : null,
				);
			}
			return array( 'taxonomies' => $out );
		}
		$tax = self::taxonomy( $p['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$limit = min( 200, max( 1, (int) ( $p['limit'] ?? 100 ) ) );
		$page  = max( 1, (int) ( $p['page'] ?? 1 ) );
		$args  = array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
			'orderby'    => 'name',
			'search'     => (string) ( $p['search'] ?? '' ),
		);
		if ( isset( $p['parent'] ) ) {
			$args['parent'] = (int) $p['parent'];
		}
		$total = (int) wp_count_terms( $args );
		$terms = get_terms(
			$args + array(
				'number' => $limit,
				'offset' => ( $page - 1 ) * $limit,
			)
		);
		return array(
			'taxonomy'     => $tax->name,
			'hierarchical' => (bool) $tax->hierarchical,
			'default_term' => self::default_id( $tax->name ) ? self::default_id( $tax->name ) : null,
			'terms'        => is_wp_error( $terms ) ? array() : array_map( array( self::class, 'out' ), $terms ),
			'total'        => $total,
			'page'         => $page,
		);
	}

	/**
	 * Checks a parent for a hierarchical taxonomy.
	 *
	 * @param \WP_Taxonomy $tax    Taxonomy.
	 * @param int          $parent_id Parent id (0 = top level).
	 * @param int          $term_id   The term being changed (0 for a new one).
	 * @return true|\WP_Error
	 */
	private static function check_parent( \WP_Taxonomy $tax, $parent_id, $term_id = 0 ) {
		if ( 0 === $parent_id ) {
			return true;
		}
		if ( ! $tax->hierarchical ) {
			return new \WP_Error( 'presshelm_parent', $tax->name . ' terms have no parents.' );
		}
		$t = self::term( $parent_id, $tax->name );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( $term_id && ( $parent_id === $term_id || in_array( $parent_id, get_term_children( $term_id, $tax->name ), true ) ) ) {
			return new \WP_Error( 'presshelm_parent', 'A term cannot be placed under itself or one of its children.' );
		}
		return true;
	}

	/**
	 * Creates a term.
	 *
	 * @param array  $p          {taxonomy, name, slug?, description?, parent?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function create( array $p, $command_id ) {
		$tax = self::taxonomy( $p['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to add ' . $tax->label );
		}
		$parent = (int) ( $p['parent'] ?? 0 );
		$ok     = self::check_parent( $tax, $parent );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$args = array( 'parent' => $parent );
		foreach ( array( 'slug', 'description' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$args[ $k ] = (string) $p[ $k ];
			}
		}
		if ( isset( $args['description'] ) ) {
			$args['description'] = wp_kses_post( $args['description'] ); // Text and simple markup, never code.
		}
		$r = wp_insert_term( (string) $p['name'], $tax->name, $args );
		if ( is_wp_error( $r ) ) {
			$dup = $r->get_error_data( 'term_exists' );
			return $dup ? new \WP_Error( 'presshelm_term_exists', 'That term already exists (id ' . (int) $dup . ').' ) : $r;
		}
		$id      = (int) $r['term_id'];
		$journal = Journal::record(
			$command_id,
			'terms',
			$tax->name,
			array(
				'taxonomy' => $tax->name,
				'created'  => array( $id ),
			)
		);
		return array(
			'term'       => self::out( self::term( $id, $tax->name ) ),
			'journal_id' => $journal,
		);
	}

	/**
	 * Renames or moves a term.
	 *
	 * @param array  $p          {taxonomy, id, name?, slug?, description?, parent?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function update( array $p, $command_id ) {
		$tax = self::taxonomy( $p['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$term = self::term( $p['id'], $tax->name );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to change this term' );
		}
		$args = array_intersect_key( $p, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) );
		if ( ! $args ) {
			return new \WP_Error( 'presshelm_nothing', 'Say what to change (name, slug, description or parent).' );
		}
		if ( isset( $args['description'] ) ) {
			$args['description'] = wp_kses_post( (string) $args['description'] );
		}
		if ( isset( $args['parent'] ) ) {
			$ok = self::check_parent( $tax, (int) $args['parent'], (int) $term->term_id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$journal = Journal::record( $command_id, 'terms', $tax->name, self::snapshot( $tax->name, array( (int) $term->term_id ), false ) );
		$r       = wp_update_term( (int) $term->term_id, $tax->name, wp_slash( $args ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'term'       => self::out( self::term( $term->term_id, $tax->name ) ),
			'journal_id' => $journal,
		);
	}

	/**
	 * Deletes a term.
	 *
	 * @param array  $p          {taxonomy, id}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function delete( array $p, $command_id ) {
		$tax = self::taxonomy( $p['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$term = self::term( $p['id'], $tax->name );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		// Before the capability check: WordPress denies delete_term for the default term, which
		// would read as a permissions problem.
		if ( self::default_id( $tax->name ) === (int) $term->term_id ) {
			return new \WP_Error( 'presshelm_default_term', '"' . $term->name . '" is the default ' . $tax->labels->singular_name . ' and cannot be deleted. Rename it, or choose another default first.' );
		}
		if ( ! current_user_can( 'delete_term', $term->term_id ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to delete this term' );
		}
		$snap = self::snapshot( $tax->name, array( (int) $term->term_id ), true );
		if ( is_wp_error( $snap ) ) {
			return $snap;
		}
		$journal = Journal::record( $command_id, 'terms', $tax->name, $snap );
		$r       = wp_delete_term( (int) $term->term_id, $tax->name );
		if ( true !== $r ) {
			return is_wp_error( $r ) ? $r : new \WP_Error( 'presshelm_term_delete', 'WordPress did not delete the term.' );
		}
		return array(
			'deleted'    => self::out( $term ),
			'posts'      => count( $snap['objects'] ),
			'children'   => count( $snap['terms'] ) - 1,
			'menu_links' => (int) $snap['menu_links'],
			'journal_id' => $journal,
		);
	}

	/**
	 * Merges terms into one.
	 *
	 * @param array  $p          {taxonomy, from, into}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function merge( array $p, $command_id ) {
		$tax = self::taxonomy( $p['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$into = self::term( $p['into'], $tax->name );
		if ( is_wp_error( $into ) ) {
			return $into;
		}
		$into_id = (int) $into->term_id;
		$from    = array_values( array_unique( array_map( 'intval', (array) $p['from'] ) ) );
		if ( in_array( $into_id, $from, true ) ) {
			return new \WP_Error( 'presshelm_merge', 'The "into" term cannot also be in "from".' );
		}
		foreach ( $from as $fid ) {
			$t = self::term( $fid, $tax->name );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			if ( self::default_id( $tax->name ) === $fid ) {
				return new \WP_Error( 'presshelm_default_term', '"' . $t->name . '" is the default ' . $tax->labels->singular_name . ' and cannot be merged away. Merge the others into it instead.' );
			}
			if ( ! current_user_can( 'delete_term', $fid ) ) {
				return new \WP_Error( 'presshelm_capability', 'Not allowed to delete "' . $t->name . '"' );
			}
		}
		if ( ! current_user_can( 'edit_term', $into_id ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to change "' . $into->name . '"' );
		}
		$snap = self::snapshot( $tax->name, array_merge( $from, array( $into_id ) ), true );
		if ( is_wp_error( $snap ) ) {
			return $snap;
		}
		$journal = Journal::record( $command_id, 'terms', $tax->name, $snap );
		$objects = get_objects_in_term( $from, $tax->name );
		foreach ( is_wp_error( $objects ) ? array() : array_unique( array_map( 'intval', $objects ) ) as $oid ) {
			wp_set_object_terms( $oid, array( $into_id ), $tax->name, true );
		}
		$moved = 0;
		foreach ( $from as $fid ) {
			if ( $tax->hierarchical ) {
				foreach ( get_terms(
					array(
						'taxonomy'   => $tax->name,
						'parent'     => $fid,
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				) as $child ) {
					if ( (int) $child !== $into_id ) {
						wp_update_term( (int) $child, $tax->name, array( 'parent' => $into_id ) );
					}
				}
			}
			// Menu links to a merged term point to the "into" term instead of being removed.
			foreach ( self::menu_items( $tax->name, array( $fid ) ) as $item_id ) {
				update_post_meta( $item_id, '_menu_item_object_id', (string) $into_id );
				++$moved;
			}
			wp_delete_term( $fid, $tax->name );
		}
		return array(
			'into'       => self::out( self::term( $into_id, $tax->name ) ),
			'deleted'    => $from,
			'posts'      => is_wp_error( $objects ) ? 0 : count( array_unique( $objects ) ),
			'menu_links' => $moved,
			'journal_id' => $journal,
		);
	}

	/**
	 * Menu items linking to some terms.
	 *
	 * @param string $tax Taxonomy.
	 * @param int[]  $ids Term ids.
	 * @return int[]
	 */
	private static function menu_items( $tax, array $ids ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'nav_menu_item',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- menu items have no other link to their term.
					'meta_query'     => array(
						array(
							'key'   => '_menu_item_type',
							'value' => 'taxonomy',
						),
						array(
							'key'   => '_menu_item_object',
							'value' => $tax,
						),
						array(
							'key'     => '_menu_item_object_id',
							'value'   => array_map( 'strval', $ids ),
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	/**
	 * A term's row and raw meta, for the journal.
	 *
	 * @param \WP_Term $t Term.
	 * @return array
	 */
	private static function row( \WP_Term $t ) {
		global $wpdb;
		$meta = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE term_id = %d ORDER BY meta_id', $wpdb->termmeta, (int) $t->term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw meta (exact serialization) for undo.
		return array(
			'term_id'          => (int) $t->term_id,
			'term_taxonomy_id' => (int) $t->term_taxonomy_id,
			'name'             => $t->name,
			'slug'             => $t->slug,
			'term_group'       => (int) $t->term_group,
			'description'      => $t->description,
			'parent'           => (int) $t->parent,
			'meta'             => $meta ? $meta : array(),
		);
	}

	/**
	 * What undo needs: the terms (and, when deleting, their children, their posts' term lists and
	 * the menus linking to them).
	 *
	 * @param string $tax  Taxonomy.
	 * @param int[]  $ids  Terms that change.
	 * @param bool   $deep Include children, posts and menus (delete / merge).
	 * @return array|\WP_Error
	 */
	private static function snapshot( $tax, array $ids, $deep ) {
		$rows = array();
		$all  = $ids;
		if ( $deep && is_taxonomy_hierarchical( $tax ) ) {
			foreach ( $ids as $id ) {
				$kids = get_terms(
					array(
						'taxonomy'   => $tax,
						'parent'     => $id,
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				);
				$all  = array_merge( $all, is_wp_error( $kids ) ? array() : array_map( 'intval', $kids ) );
			}
		}
		foreach ( array_unique( $all ) as $id ) {
			$t = get_term( (int) $id, $tax, OBJECT, 'raw' );
			if ( $t instanceof \WP_Term ) {
				$rows[] = self::row( $t );
			}
		}
		$snap = array(
			'taxonomy' => $tax,
			'terms'    => $rows,
		);
		if ( ! $deep ) {
			return $snap;
		}
		$objects = get_objects_in_term( $ids, $tax );
		$objects = is_wp_error( $objects ) ? array() : array_unique( array_map( 'intval', $objects ) );
		if ( count( $objects ) > self::MAX_OBJECTS ) {
			return new \WP_Error( 'presshelm_too_many', 'These terms are used by ' . count( $objects ) . ' items; at most ' . self::MAX_OBJECTS . ' can be changed in one step with undo.' );
		}
		$snap['objects'] = array();
		foreach ( $objects as $oid ) {
			$snap['objects'][ $oid ] = array_map( 'intval', (array) wp_get_object_terms( $oid, $tax, array( 'fields' => 'ids' ) ) );
		}
		$items              = self::menu_items( $tax, $ids );
		$snap['menu_links'] = count( $items );
		$snap['menus']      = array();
		foreach ( $items as $item_id ) {
			foreach ( (array) wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) ) as $menu_id ) {
				if ( ! isset( $snap['menus'][ (int) $menu_id ] ) ) {
					$snap['menus'][ (int) $menu_id ] = Site::items( (int) $menu_id, true );
				}
			}
		}
		return $snap;
	}

	/**
	 * Puts terms back as journaled (Journal::restore, kind `terms`).
	 *
	 * @param array $before Snapshot.
	 * @return true|\WP_Error
	 */
	public static function restore( array $before ) {
		global $wpdb;
		$tax = (string) ( $before['taxonomy'] ?? '' );
		if ( ! taxonomy_exists( $tax ) ) {
			return new \WP_Error( 'presshelm_taxonomy', 'The taxonomy "' . sanitize_key( $tax ) . '" is not registered now (is its plugin active?).' );
		}
		$rows    = (array) ( $before['terms'] ?? array() );
		$missing = array();
		// Checked first, so a conflict changes nothing.
		foreach ( $rows as $r ) {
			if ( get_term( (int) $r['term_id'], $tax ) instanceof \WP_Term ) {
				continue;
			}
			$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE term_id = %d', $wpdb->terms, (int) $r['term_id'] ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- id check before re-inserting a deleted term.
				+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE term_taxonomy_id = %d', $wpdb->term_taxonomy, (int) $r['term_taxonomy_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- same.
			if ( $taken ) {
				return new \WP_Error( 'presshelm_term_id', 'Term id ' . (int) $r['term_id'] . ' is used by something else now; nothing was changed.' );
			}
			$same = get_term_by( 'slug', (string) $r['slug'], $tax );
			if ( $same ) {
				return new \WP_Error( 'presshelm_term_slug', 'A term with the slug "' . $r['slug'] . '" exists now (id ' . (int) $same->term_id . '). Rename or delete it, then undo again; nothing was changed.' );
			}
			$missing[] = $r;
		}
		foreach ( (array) ( $before['created'] ?? array() ) as $id ) {
			if ( get_term( (int) $id, $tax ) instanceof \WP_Term ) {
				wp_delete_term( (int) $id, $tax );
			}
		}
		// Deleted terms come back with their own ids.
		foreach ( $missing as $r ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- re-inserting a deleted term with its id.
				$wpdb->terms,
				array(
					'term_id'    => (int) $r['term_id'],
					'name'       => (string) $r['name'],
					'slug'       => (string) $r['slug'],
					'term_group' => (int) $r['term_group'],
				),
				array( '%d', '%s', '%s', '%d' )
			);
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- same.
				$wpdb->term_taxonomy,
				array(
					'term_taxonomy_id' => (int) $r['term_taxonomy_id'],
					'term_id'          => (int) $r['term_id'],
					'taxonomy'         => $tax,
					'description'      => (string) $r['description'],
					'parent'           => (int) $r['parent'],
					'count'            => 0,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d' )
			);
		}
		clean_term_cache( array_map( 'intval', array_column( $rows, 'term_id' ) ), $tax );
		foreach ( $rows as $r ) {
			$id = (int) $r['term_id'];
			wp_update_term(
				$id,
				$tax,
				wp_slash(
					array(
						'name'        => (string) $r['name'],
						'slug'        => (string) $r['slug'],
						'description' => (string) $r['description'],
						'parent'      => (int) $r['parent'],
					)
				)
			);
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- meta written back exactly.
			foreach ( (array) $r['meta'] as $m ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- same.
					$wpdb->termmeta,
					array(
						'term_id'    => $id,
						'meta_key'   => (string) $m['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- insert, not a query.
						'meta_value' => (string) $m['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- insert, not a query.
					),
					array( '%d', '%s', '%s' )
				);
			}
			wp_cache_delete( $id, 'term_meta' );
		}
		foreach ( (array) ( $before['objects'] ?? array() ) as $oid => $ids ) {
			wp_set_object_terms( (int) $oid, array_map( 'intval', (array) $ids ), $tax );
		}
		foreach ( (array) ( $before['menus'] ?? array() ) as $menu_id => $items ) {
			if ( is_nav_menu( (int) $menu_id ) ) {
				Site::restore_menu( (int) $menu_id, (array) $items );
			}
		}
		$tt = array_map( 'intval', array_column( $rows, 'term_taxonomy_id' ) );
		if ( $tt ) {
			wp_update_term_count_now( $tt, $tax );
		}
		clean_term_cache( array_map( 'intval', array_column( $rows, 'term_id' ) ), $tax );
		return true;
	}
}
