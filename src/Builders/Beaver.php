<?php
/**
 * Beaver Builder adapter. A layout is a flat list of nodes (row > column-group > column > module,
 * a column may hold a nested column-group), each with a parent and a position, stored in
 * `_fl_builder_data` (published) and `_fl_builder_draft`. Changes go through
 * FLBuilderModel::update_layout_data() for both, then Beaver's asset cache is cleared. New nodes get
 * Beaver's own defaults and ids; columns in a group share the width evenly, as Beaver does.
 * Nodes of global rows/modules (saved templates) are never changed: that would change every page
 * using them. HTML and WordPress-widget modules are code and are not added or changed.
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Beaver Builder.
 */
final class Beaver implements Adapter {

	const META  = '_fl_builder_data';
	const DRAFT = '_fl_builder_draft';

	/** Modules whose content is code. */
	const CODE_MODULES = array( 'html', 'widget' );

	/** Setting keys that carry code. */
	const CODE_KEYS = '/(^|_)(css|js|custom_attributes|attributes|html)$/i';

	/** What may contain what ('' = the top level). */
	const CHILDREN = array(
		''             => array( 'row' ),
		'row'          => array( 'column-group' ),
		'column-group' => array( 'column' ),
		'column'       => array( 'module', 'column-group' ),
		'module'       => array(),
	);

	const MAX_NODES = 200;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'beaver';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( '\\FLBuilderModel' );
	}

	/**
	 * Built with Beaver Builder?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		return (bool) get_post_meta( (int) $post_id, '_fl_builder_enabled', true ) && is_array( get_post_meta( (int) $post_id, self::META, true ) );
	}

	/**
	 * Stored data (serialized, for the journal and the version hash).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id ) {
		return (string) maybe_serialize( get_post_meta( (int) $post_id, self::META, true ) );
	}

	/**
	 * Nodes as stored (node id => object).
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function nodes( $post_id ) {
		$data = get_post_meta( (int) $post_id, self::META, true );
		$out  = array();
		foreach ( is_array( $data ) ? $data : array() as $id => $node ) {
			if ( is_object( $node ) ) {
				$out[ (string) $id ] = clone $node;
				if ( is_object( $node->settings ?? null ) ) {
					$out[ (string) $id ]->settings = clone $node->settings;
				}
			}
		}
		return $out;
	}

	/**
	 * Children of a parent, by position.
	 *
	 * @param array       $nodes  Nodes.
	 * @param string|null $parent_id Parent node id (null = top level).
	 * @return string[] Node ids.
	 */
	private function kids( array $nodes, $parent_id ) {
		$ids = array();
		foreach ( $nodes as $id => $n ) {
			$p = isset( $n->parent ) && '' !== (string) $n->parent ? (string) $n->parent : null;
			if ( $p === $parent_id ) {
				$ids[] = (string) $id;
			}
		}
		usort(
			$ids,
			function ( $a, $b ) use ( $nodes ) {
				return (int) ( $nodes[ $a ]->position ?? 0 ) <=> (int) ( $nodes[ $b ]->position ?? 0 );
			}
		);
		return $ids;
	}

	/**
	 * Tree for the agent.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		return $this->walk( $this->nodes( $post_id ), null, array() );
	}

	/**
	 * Compact tree.
	 *
	 * @param array       $nodes  Nodes.
	 * @param string|null $parent_id Parent.
	 * @param int[]       $prefix Path.
	 * @return array
	 */
	private function walk( array $nodes, $parent_id, array $prefix ) {
		$out = array();
		foreach ( $this->kids( $nodes, $parent_id ) as $i => $id ) {
			$n        = $nodes[ $id ];
			$path     = array_merge( $prefix, array( $i ) );
			$settings = array();
			foreach ( (array) ( $n->settings ?? array() ) as $k => $v ) {
				if ( is_scalar( $v ) && '' !== $v && count( $settings ) < 40 ) {
					$settings[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v;
				}
			}
			$out[] = array(
				'id'       => $id,
				'type'     => (string) ( $n->type ?? '' ),
				'widget'   => 'module' === ( $n->type ?? '' ) ? (string) ( $n->settings->type ?? '' ) : null,
				'global'   => $this->is_global( $n ),
				'path'     => $path,
				'settings' => $settings,
				'children' => $this->walk( $nodes, $id, $path ),
			);
		}
		return $out;
	}

	/**
	 * Part of a global (saved) row or module?
	 *
	 * @param object $n Node.
	 * @return bool
	 */
	private function is_global( $n ) {
		return ! empty( $n->template_id ) || ! empty( $n->global );
	}

	/**
	 * Filters and checks settings (no code keys; text like the editor).
	 *
	 * @param array $settings Settings.
	 * @return array|\WP_Error
	 */
	private function clean( array $settings ) {
		$out = array();
		foreach ( $settings as $k => $v ) {
			if ( preg_match( self::CODE_KEYS, (string) $k ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, attributes and code are never set through the page builder.' );
			}
			if ( ! is_scalar( $v ) && ! is_array( $v ) && null !== $v ) {
				return new \WP_Error( 'presshelm_payload', 'Setting values must be text, numbers, true/false or lists' );
			}
			$out[ $k ] = is_string( $v ) ? wp_kses_post( $v ) : ( is_array( $v ) ? wp_kses_post_deep( $v ) : $v );
		}
		return $out;
	}

	/**
	 * Changes settings of one node.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Node id.
	 * @param array  $settings   Settings.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings ) {
		$nodes = $this->nodes( $post_id );
		$n     = $nodes[ (string) $element_id ] ?? null;
		if ( ! $n ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
		}
		$guard = $this->guard_node( $nodes, (string) $element_id );
		if ( $guard ) {
			return $guard;
		}
		$clean = $this->clean( $settings );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		unset( $clean['type'] ); // A module's type is not a setting to change.
		$n->settings = (object) array_merge( (array) ( is_object( $n->settings ?? null ) ? $n->settings : array() ), $clean );
		return $this->save( $post_id, $nodes );
	}

	/**
	 * Refuses nodes that are code or part of a global template (the node or any ancestor).
	 *
	 * @param array  $nodes Nodes.
	 * @param string $id    Node id.
	 * @return \WP_Error|null
	 */
	private function guard_node( array $nodes, $id ) {
		$n = $nodes[ $id ];
		if ( 'module' === ( $n->type ?? '' ) && in_array( (string) ( $n->settings->type ?? '' ), self::CODE_MODULES, true ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'HTML and widget modules are code; they are not changed through the page builder.' );
		}
		for ( $cur = $n, $hops = 0; $cur && $hops < 50; $hops++ ) {
			if ( $this->is_global( $cur ) ) {
				return new \WP_Error( 'presshelm_builder_global', 'This element belongs to a global (saved) row or module; changing it would change every page that uses it. Edit it in Beaver Builder\'s saved templates.' );
			}
			$cur = isset( $cur->parent ) && '' !== (string) $cur->parent ? ( $nodes[ (string) $cur->parent ] ?? null ) : null;
		}
		return null;
	}

	/**
	 * New nodes from a spec, with Beaver's defaults and ids.
	 *
	 * @param array       $spec   {type, module? (module slug), settings?, children?}.
	 * @param string|null $parent_id Parent id.
	 * @param string      $ptype  Parent type ('' = top level).
	 * @param array       $nodes  Nodes (new ones are added).
	 * @param int         $count  Nodes so far.
	 * @return string|\WP_Error New node id.
	 */
	private function build( array $spec, $parent_id, $ptype, array &$nodes, &$count ) {
		if ( ++$count > self::MAX_NODES ) {
			return new \WP_Error( 'presshelm_payload', 'Too many elements in one change (at most ' . self::MAX_NODES . ').' );
		}
		$type = (string) ( $spec['type'] ?? '' );
		if ( ! isset( self::CHILDREN[ $type ] ) || '' === $type ) {
			return new \WP_Error( 'presshelm_payload', 'type must be row, column-group, column or module.' );
		}
		if ( ! in_array( $type, self::CHILDREN[ $ptype ], true ) ) {
			return new \WP_Error( 'presshelm_builder_nesting', 'Beaver Builder does not allow a ' . $type . ' ' . ( '' === $ptype ? 'at the top level' : 'inside a ' . $ptype ) . '.' );
		}
		$settings = $this->clean( (array) ( $spec['settings'] ?? array() ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( 'module' === $type ) {
			$slug = (string) ( $spec['module'] ?? ( $settings['type'] ?? '' ) );
			if ( in_array( $slug, self::CODE_MODULES, true ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'HTML and widget modules are code; they are not added through the page builder.' );
			}
			if ( '' === $slug || ! isset( \FLBuilderModel::$modules[ $slug ] ) ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown module "' . $slug . '" on this site (see builder.read vocabulary).' );
			}
			$base = (array) \FLBuilderModel::get_module_defaults( $slug );
			$set  = (object) array_merge( $base, $settings, array( 'type' => $slug ) );
		} elseif ( 'row' === $type ) {
			$set = (object) array_merge( (array) \FLBuilderModel::get_row_defaults(), $settings );
		} elseif ( 'column' === $type ) {
			$set = (object) array_merge( (array) \FLBuilderModel::get_col_defaults(), $settings );
		} else {
			$set = '';
		}
		$id           = (string) \FLBuilderModel::generate_node_id();
		$nodes[ $id ] = (object) array(
			'node'     => $id,
			'type'     => $type,
			'parent'   => $parent_id,
			'position' => 0,
			'settings' => $set,
		);
		$children     = (array) ( $spec['children'] ?? array() );
		// Rows and column groups always get somewhere to put content, as Beaver's own "add row" does.
		if ( ! $children && 'row' === $type ) {
			$children = array( array( 'type' => 'column-group' ) );
		}
		if ( ! $children && 'column-group' === $type ) {
			$children = array( array( 'type' => 'column' ) );
		}
		foreach ( array_values( $children ) as $i => $child ) {
			$c = $this->build( (array) $child, $id, $type, $nodes, $count );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$nodes[ $c ]->position = $i;
		}
		$this->even_widths( $nodes, $id );
		return $id;
	}

	/**
	 * Columns of a column group share the width evenly (as Beaver does when columns change).
	 *
	 * @param array  $nodes Nodes.
	 * @param string $group Column-group id (anything else is ignored).
	 */
	private function even_widths( array &$nodes, $group ) {
		if ( 'column-group' !== ( $nodes[ $group ]->type ?? '' ) ) {
			return;
		}
		$cols = $this->kids( $nodes, $group );
		foreach ( $cols as $col ) {
			if ( ! is_object( $nodes[ $col ]->settings ?? null ) ) {
				$nodes[ $col ]->settings = new \stdClass();
			}
			$nodes[ $col ]->settings->size = round( 100 / max( 1, count( $cols ) ), 2 );
		}
	}

	/**
	 * Positions 0..n under a parent in the given order.
	 *
	 * @param array       $nodes Nodes.
	 * @param string|null $parent_id Parent.
	 * @param string[]    $order Node ids.
	 */
	private function renumber( array &$nodes, $parent_id, array $order ) {
		foreach ( array_values( $order ) as $i => $id ) {
			$nodes[ $id ]->parent   = $parent_id;
			$nodes[ $id ]->position = $i;
		}
	}

	/**
	 * A node and all nodes under it.
	 *
	 * @param array  $nodes Nodes.
	 * @param string $id    Node id.
	 * @return string[]
	 */
	private function subtree( array $nodes, $id ) {
		$out = array( $id );
		foreach ( $this->kids( $nodes, $id ) as $k ) {
			$out = array_merge( $out, $this->subtree( $nodes, $k ) );
		}
		return $out;
	}

	/**
	 * Parent id (null = top level) and parent type ('' = top level) of a would-be parent.
	 *
	 * @param array  $nodes  Nodes.
	 * @param string $parent_id Parent id or ''.
	 * @return array|\WP_Error
	 */
	private function parent_of( array $nodes, $parent_id ) {
		if ( '' === $parent_id ) {
			return array( null, '' );
		}
		if ( ! isset( $nodes[ $parent_id ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
		}
		$g = $this->guard_node( $nodes, $parent_id );
		if ( $g ) {
			return $g;
		}
		return array( $parent_id, (string) $nodes[ $parent_id ]->type );
	}

	/**
	 * Insert, remove, move or duplicate one element.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      Operation.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op ) {
		$nodes = $this->nodes( $post_id );
		$kind  = (string) ( $op['op'] ?? '' );
		$pos   = function ( array $siblings ) use ( $op ) {
			$n = isset( $op['position'] ) ? (int) $op['position'] : count( $siblings );
			return max( 0, min( $n, count( $siblings ) ) );
		};

		if ( 'insert' === $kind ) {
			$p = $this->parent_of( $nodes, (string) ( $op['parent_id'] ?? '' ) );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$siblings = $this->kids( $nodes, $p[0] );
			$count    = 0;
			$id       = $this->build( (array) ( $op['element'] ?? array() ), $p[0], $p[1], $nodes, $count );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			array_splice( $siblings, $pos( $siblings ), 0, array( $id ) );
			$this->renumber( $nodes, $p[0], $siblings );
			$this->even_widths( $nodes, (string) $p[0] );
			return $this->save( $post_id, $nodes );
		}

		$id = (string) ( $op['element_id'] ?? '' );
		if ( ! isset( $nodes[ $id ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$guard = $this->guard_node( $nodes, $id );
		if ( $guard ) {
			return $guard;
		}
		$old_parent = isset( $nodes[ $id ]->parent ) && '' !== (string) $nodes[ $id ]->parent ? (string) $nodes[ $id ]->parent : null;
		$siblings   = $this->kids( $nodes, $old_parent );

		if ( 'duplicate' === $kind ) {
			$map = array();
			foreach ( $this->subtree( $nodes, $id ) as $old ) {
				$map[ $old ] = (string) \FLBuilderModel::generate_node_id();
			}
			foreach ( $map as $old => $new ) {
				$copy           = clone $nodes[ $old ];
				$copy->settings = is_object( $copy->settings ?? null ) ? clone $copy->settings : ( $copy->settings ?? '' );
				$copy->node     = $new;
				$copy->parent   = $old === $id ? $old_parent : $map[ (string) $copy->parent ];
				$nodes[ $new ]  = $copy;
			}
			array_splice( $siblings, array_search( $id, $siblings, true ) + 1, 0, array( $map[ $id ] ) );
			$this->renumber( $nodes, $old_parent, $siblings );
			$this->even_widths( $nodes, (string) $old_parent );
			return $this->save( $post_id, $nodes );
		}

		if ( 'remove' === $kind ) {
			foreach ( $this->subtree( $nodes, $id ) as $gone ) {
				unset( $nodes[ $gone ] );
			}
			$rest = array_values( array_diff( $siblings, array( $id ) ) );
			$this->renumber( $nodes, $old_parent, $rest );
			// A column group without columns goes too; the others share the width again.
			if ( null !== $old_parent && 'column-group' === ( $nodes[ $old_parent ]->type ?? '' ) ) {
				if ( ! $rest ) {
					$grand = isset( $nodes[ $old_parent ]->parent ) && '' !== (string) $nodes[ $old_parent ]->parent ? (string) $nodes[ $old_parent ]->parent : null;
					unset( $nodes[ $old_parent ] );
					$this->renumber( $nodes, $grand, $this->kids( $nodes, $grand ) );
				} else {
					$this->even_widths( $nodes, $old_parent );
				}
			}
			return $this->save( $post_id, $nodes );
		}

		if ( 'move' === $kind ) {
			$target = (string) ( $op['parent_id'] ?? '' );
			if ( '' !== $target && in_array( $target, $this->subtree( $nodes, $id ), true ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'An element cannot be moved into itself.' );
			}
			$p = $this->parent_of( $nodes, $target );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$type = (string) $nodes[ $id ]->type;
			if ( ! in_array( $type, self::CHILDREN[ $p[1] ], true ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'Beaver Builder does not allow a ' . $type . ' ' . ( '' === $p[1] ? 'at the top level' : 'inside a ' . $p[1] ) . '.' );
			}
			$this->renumber( $nodes, $old_parent, array_values( array_diff( $siblings, array( $id ) ) ) );
			$to = array_values( array_diff( $this->kids( $nodes, $p[0] ), array( $id ) ) );
			array_splice( $to, $pos( $to ), 0, array( $id ) );
			$this->renumber( $nodes, $p[0], $to );
			$this->even_widths( $nodes, (string) $old_parent );
			$this->even_widths( $nodes, (string) $p[0] );
			return $this->save( $post_id, $nodes );
		}
		return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
	}

	/**
	 * Modules and nesting for building new elements on this site.
	 *
	 * @return array
	 */
	public function vocabulary() {
		$modules = array();
		foreach ( (array) \FLBuilderModel::$modules as $slug => $m ) {
			if ( ! in_array( $slug, self::CODE_MODULES, true ) && ( ! isset( $m->enabled ) || $m->enabled ) ) {
				$modules[] = $slug;
			}
		}
		return array(
			'nesting' => array(
				'top level'    => 'row',
				'row'          => 'column-group',
				'column-group' => 'column (widths are shared evenly)',
				'column'       => 'module or column-group',
			),
			'widgets' => $modules,
			'element' => 'Elements: {type: row|column-group|column|module, module (module slug, for modules), settings, children}. A row without children gets one column. Ids and Beaver\'s defaults are added by PressHelm; copy setting names from existing elements (builder.read).',
		);
	}

	/**
	 * Restores the stored data exactly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Serialized data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw ) {
		$data = maybe_unserialize( (string) $raw );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'presshelm_journal_corrupt', 'The saved Beaver Builder data is damaged' );
		}
		update_post_meta( (int) $post_id, self::META, $data );
		update_post_meta( (int) $post_id, self::DRAFT, $data );
		\FLBuilderModel::delete_all_asset_cache( (int) $post_id );
		return true;
	}

	/**
	 * Saves through Beaver Builder (published and draft), then clears its asset cache.
	 *
	 * @param int   $post_id Post id.
	 * @param array $nodes   Nodes.
	 * @return true
	 */
	private function save( $post_id, array $nodes ) {
		wp_save_post_revision( (int) $post_id );
		\FLBuilderModel::update_layout_data( $nodes, 'published', (int) $post_id );
		\FLBuilderModel::update_layout_data( $nodes, 'draft', (int) $post_id );
		\FLBuilderModel::delete_all_asset_cache( (int) $post_id );
		return true;
	}

	/** How values of common field kinds are stored (Beaver's own formats). */
	const VALUE_FORMATS = array(
		'text/textarea/editor' => 'string (editor: HTML)',
		'select'               => 'one of the option keys (string)',
		'color'                => 'hex without "#" (e.g. "1e73be")',
		'link'                 => 'URL string; target and nofollow are separate settings (name_target, name_nofollow)',
		'photo'                => 'attachment id (the setting name_src holds its URL)',
		'unit'                 => 'number as string; the unit is in name_unit',
		'dimension'            => 'separate settings name_top, name_right, name_bottom, name_left',
		'form'                 => 'array of objects (each one a sub-form of settings, e.g. list items)',
		'responsive'           => 'responsive settings also exist as name_medium and name_responsive',
	);

	/**
	 * Beaver's own module and node definitions, read live.
	 *
	 * @param string $type Module slug, or row/column, or ''.
	 * @param bool   $all  Also style and advanced settings.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		if ( '' === $type ) {
			$out = array();
			foreach ( (array) \FLBuilderModel::$modules as $slug => $m ) {
				if ( in_array( $slug, self::CODE_MODULES, true ) || ( isset( $m->enabled ) && ! $m->enabled ) ) {
					continue;
				}
				$out[] = array(
					'type'  => (string) $slug,
					'title' => wp_strip_all_tags( (string) ( $m->name ?? $slug ) ),
				);
			}
			return array(
				'builder'  => 'beaver',
				'elements' => array( 'row', 'column' ),
				'widgets'  => $out,
			);
		}
		if ( in_array( $type, array( 'row', 'col', 'column' ), true ) ) {
			$form  = \FLBuilderModel::get_settings_form( 'row' === $type ? 'row' : 'col' );
			$tabs  = is_array( $form ) ? (array) ( $form['tabs'] ?? array() ) : array();
			$title = 'row' === $type ? 'Row' : 'Column';
		} elseif ( isset( \FLBuilderModel::$modules[ $type ] ) && ! in_array( $type, self::CODE_MODULES, true ) ) {
			$m     = \FLBuilderModel::$modules[ $type ];
			$tabs  = (array) $m->form;
			$title = (string) ( $m->name ?? $type );
		} else {
			return new \WP_Error( 'presshelm_not_found', 'Beaver Builder has no editable module "' . $type . '" here (see the list without type).' );
		}
		if ( ! $tabs ) {
			return new \WP_Error( 'presshelm_schema_unavailable', 'Beaver Builder did not load this form outside its editor; copy setting names from an existing element (builder_read).' );
		}
		$out = array();
		foreach ( $tabs as $tab_id => $tab ) {
			$tab_id = (string) $tab_id;
			if ( ! $all && in_array( $tab_id, array( 'style', 'advanced' ), true ) ) {
				continue;
			}
			foreach ( (array) ( $tab['sections'] ?? array() ) as $section ) {
				foreach ( (array) ( $section['fields'] ?? array() ) as $name => $f ) {
					if ( ! isset( $f['type'] ) || 'code' === $f['type'] || preg_match( self::CODE_KEYS, (string) $name ) ) {
						continue;
					}
					$out[] = Schema::field( (string) $name, (string) $f['type'], (array) $f, $tab_id, ! empty( $f['responsive'] ) );
				}
			}
		}
		return Schema::result( 'beaver', $type, wp_strip_all_tags( $title ), $out, $all, self::VALUE_FORMATS );
	}
}
