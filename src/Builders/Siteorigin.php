<?php
/**
 * SiteOrigin Page Builder adapter. A layout lives in the meta panels_data: grids (rows), grid_cells
 * (columns, with weights) and widgets (each a WordPress widget's settings plus panels_info: class,
 * grid, cell, position). SiteOrigin renders it with PHP on every request. Changes are saved through
 * SiteOrigin's own layout-update ability (the same sanitizing and structure checks its editor
 * applies); changed and new widgets go through the widget's own update() sanitizer.
 *
 * Elements get short positional ids: rows r0, r1…; cells r0c0, r0c1…; widgets w0, w1… (in page
 * order). Read the page again after each change (builder.read).
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * SiteOrigin Page Builder.
 */
final class Siteorigin implements Adapter {

	/** Widget classes whose content is code. */
	const CODE_CLASSES = '/Custom_?HTML|_Code\b|Code_Widget|Shortcode|Embed_?Code/i';

	/** Setting keys that carry code. */
	const CODE_KEYS = '/^(custom_?(css|html|js)|css|html|code|shortcode|script|attributes|panels_info)$/i';

	const MAX_WIDGETS = 100;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'siteorigin';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( '\\SiteOrigin_Panels_Abilities' ) && class_exists( '\\SiteOrigin_Panels_Admin' );
	}

	/**
	 * Built with SiteOrigin (a classic, meta-stored layout)?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		$d = get_post_meta( (int) $post_id, 'panels_data', true );
		return is_array( $d ) && ( ! empty( $d['grids'] ) || ! empty( $d['widgets'] ) );
	}

	/**
	 * Stored data (layout and the post_content copy), for the journal and the version hash.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id ) {
		return (string) maybe_serialize(
			array(
				'panels'  => get_post_meta( (int) $post_id, 'panels_data', true ),
				'content' => (string) get_post_field( 'post_content', (int) $post_id ),
			)
		);
	}

	/**
	 * The layout, normalized: grids, grid_cells, widgets (arrays, widgets in page order).
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function layout( $post_id ) {
		$d = get_post_meta( (int) $post_id, 'panels_data', true );
		$d = is_array( $d ) ? $d : array();
		foreach ( array( 'grids', 'grid_cells', 'widgets' ) as $k ) {
			$d[ $k ] = isset( $d[ $k ] ) && is_array( $d[ $k ] ) ? array_values( array_map( array( $this, 'to_array' ), $d[ $k ] ) ) : array();
		}
		return $this->order( $d );
	}

	/**
	 * Objects to arrays (one level), as SiteOrigin's own save does.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	private function to_array( $v ) {
		return is_object( $v ) ? (array) $v : ( is_array( $v ) ? $v : array() );
	}

	/**
	 * Widgets sorted by row, then cell, keeping their order inside a cell; ids renumbered.
	 *
	 * @param array $d Layout.
	 * @return array
	 */
	private function order( array $d ) {
		$i = 0;
		foreach ( $d['widgets'] as &$w ) {
			$w['panels_info']        = $this->to_array( $w['panels_info'] ?? array() );
			$w['panels_info']['ord'] = $i++;
		}
		unset( $w );
		usort(
			$d['widgets'],
			static function ( $a, $b ) {
				return array( (int) $a['panels_info']['grid'], (int) $a['panels_info']['cell'], (int) $a['panels_info']['ord'] ) <=> array( (int) $b['panels_info']['grid'], (int) $b['panels_info']['cell'], (int) $b['panels_info']['ord'] );
			}
		);
		foreach ( $d['widgets'] as $n => &$w ) {
			unset( $w['panels_info']['ord'] );
			$w['panels_info']['id'] = $n;
		}
		unset( $w );
		return $d;
	}

	/**
	 * Tree for the agent: rows > cells > widgets.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		$d   = $this->layout( $post_id );
		$out = array();
		foreach ( $d['grids'] as $g => $grid ) {
			$cells = array();
			$c     = 0;
			foreach ( $d['grid_cells'] as $cell ) {
				if ( (int) ( $cell['grid'] ?? -1 ) !== $g ) {
					continue;
				}
				$kids = array();
				foreach ( $d['widgets'] as $n => $w ) {
					if ( (int) $w['panels_info']['grid'] === $g && (int) $w['panels_info']['cell'] === $c ) {
						$kids[] = $this->widget_node( $n, $w, array( $g, $c, count( $kids ) ) );
					}
				}
				$cells[] = array(
					'id'       => 'r' . $g . 'c' . $c,
					'type'     => 'cell',
					'widget'   => null,
					'path'     => array( $g, $c ),
					'settings' => array( 'weight' => round( (float) ( $cell['weight'] ?? 0 ), 4 ) ),
					'children' => $kids,
				);
				++$c;
			}
			$out[] = array(
				'id'       => 'r' . $g,
				'type'     => 'row',
				'widget'   => null,
				'path'     => array( $g ),
				'settings' => array( 'cells' => count( $cells ) ),
				'children' => $cells,
			);
		}
		return $out;
	}

	/**
	 * One widget for the tree.
	 *
	 * @param int   $n    Widget index.
	 * @param array $w    Widget.
	 * @param int[] $path Path.
	 * @return array
	 */
	private function widget_node( $n, array $w, array $path ) {
		$settings = array();
		foreach ( $w as $k => $v ) {
			if ( 'panels_info' === $k || ! is_scalar( $v ) || '' === $v || count( $settings ) >= 40 ) {
				continue;
			}
			$settings[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v;
		}
		return array(
			'id'       => 'w' . $n,
			'type'     => 'widget',
			'widget'   => (string) ( $w['panels_info']['class'] ?? '' ),
			'path'     => $path,
			'settings' => $settings,
			'children' => array(),
		);
	}

	/**
	 * Parses an element id: ['row', g] / ['cell', g, c] / ['widget', n], or null.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	private function parse( $id ) {
		if ( preg_match( '/^r(\d+)c(\d+)$/', $id, $m ) ) {
			return array( 'cell', (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '/^r(\d+)$/', $id, $m ) ) {
			return array( 'row', (int) $m[1] );
		}
		if ( preg_match( '/^w(\d+)$/', $id, $m ) ) {
			return array( 'widget', (int) $m[1] );
		}
		return null;
	}

	/**
	 * Cells of a row (indexes into grid_cells, in order).
	 *
	 * @param array $d Layout.
	 * @param int   $g Row.
	 * @return int[]
	 */
	private function cells_of( array $d, $g ) {
		$out = array();
		foreach ( $d['grid_cells'] as $i => $cell ) {
			if ( (int) ( $cell['grid'] ?? -1 ) === $g ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * Refuses code widgets.
	 *
	 * @param array $w Widget.
	 * @return \WP_Error|null
	 */
	private function guard( array $w ) {
		if ( preg_match( self::CODE_CLASSES, (string) ( $w['panels_info']['class'] ?? '' ) ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Custom HTML, shortcode and code widgets are code; they are not changed through the page builder.' );
		}
		return null;
	}

	/**
	 * Checks settings (no code keys).
	 *
	 * @param array $settings Settings.
	 * @return array|\WP_Error
	 */
	private function clean( array $settings ) {
		$out = array();
		foreach ( $settings as $k => $v ) {
			if ( preg_match( self::CODE_KEYS, (string) $k ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, attributes, code and the layout info are never set through widget settings.' );
			}
			if ( ! is_scalar( $v ) && ! is_array( $v ) && null !== $v ) {
				return new \WP_Error( 'presshelm_payload', 'Setting values must be text, numbers, true/false or lists' );
			}
			$out[ (string) $k ] = $v;
		}
		return $out;
	}

	/**
	 * Changes settings of one widget (the widget's own update() sanitizes them on save).
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Widget id (w…).
	 * @param array  $settings   Settings.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings ) {
		$d = $this->layout( $post_id );
		$p = $this->parse( (string) $element_id );
		if ( ! $p || 'widget' !== $p[0] || ! isset( $d['widgets'][ $p[1] ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No widget with that id on this page (see builder.read); rows and cells have no settings PressHelm changes.' );
		}
		$w     = $d['widgets'][ $p[1] ];
		$guard = $this->guard( $w );
		if ( $guard ) {
			return $guard;
		}
		$clean = $this->clean( $settings );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$info                  = $w['panels_info'];
		$info['raw']           = true; // The widget's own update() runs on save.
		$d['widgets'][ $p[1] ] = array_merge( $w, $clean, array( 'panels_info' => $info ) );
		return $this->save( $post_id, $d );
	}

	/**
	 * A widget class this site can render.
	 *
	 * @param string $cls Class.
	 * @return bool
	 */
	private function known_class( $cls ) {
		global $wp_widget_factory;
		return '' !== $cls && class_exists( $cls ) && is_subclass_of( $cls, 'WP_Widget' ) && ( ! $wp_widget_factory || isset( $wp_widget_factory->widgets[ $cls ] ) || method_exists( $cls, 'widget' ) );
	}

	/**
	 * A new widget from a spec {class (or widget), settings}.
	 *
	 * @param array $spec Spec.
	 * @param int   $g    Row.
	 * @param int   $c    Cell.
	 * @return array|\WP_Error
	 */
	private function new_widget( array $spec, $g, $c ) {
		$class = (string) ( $spec['class'] ?? ( $spec['widget'] ?? '' ) );
		if ( preg_match( self::CODE_CLASSES, $class ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Custom HTML, shortcode and code widgets are not added through the page builder.' );
		}
		if ( ! $this->known_class( $class ) ) {
			return new \WP_Error( 'presshelm_payload', 'Unknown widget "' . $class . '" on this site (see builder.read vocabulary).' );
		}
		$settings = $this->clean( (array) ( $spec['settings'] ?? array() ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		return array_merge(
			$settings,
			array(
				'panels_info' => array(
					'class'     => $class,
					'raw'       => true,
					'grid'      => $g,
					'cell'      => $c,
					'id'        => 0,
					'widget_id' => wp_generate_uuid4(),
					'style'     => array(),
				),
			)
		);
	}

	/**
	 * Inserts a row (with cells and widgets) at a position, shifting later rows.
	 *
	 * @param array $d    Layout.
	 * @param int   $at   Row position.
	 * @param array $spec {type: row, cells?: n, weights?: [], children?: [ {cell: [widgets…]} or widget specs ]}.
	 * @return array|\WP_Error
	 */
	private function insert_row( array $d, $at, array $spec ) {
		$cells = (array) ( $spec['children'] ?? array() );
		$n     = max( 1, min( 12, (int) ( $spec['cells'] ?? ( $cells ? count( $cells ) : 1 ) ) ) );
		$at    = max( 0, min( $at, count( $d['grids'] ) ) );
		foreach ( $d['grid_cells'] as &$cell ) {
			if ( (int) $cell['grid'] >= $at ) {
				++$cell['grid'];
			}
		}
		unset( $cell );
		foreach ( $d['widgets'] as &$w ) {
			if ( (int) $w['panels_info']['grid'] >= $at ) {
				++$w['panels_info']['grid'];
			}
		}
		unset( $w );
		array_splice(
			$d['grids'],
			$at,
			0,
			array(
				array(
					'cells' => $n,
					'style' => array(),
				),
			)
		);
		$weights = isset( $spec['weights'] ) && is_array( $spec['weights'] ) && count( $spec['weights'] ) === $n ? array_map( 'floatval', $spec['weights'] ) : array_fill( 0, $n, 1 / $n );
		$sum     = array_sum( $weights ) > 0 ? array_sum( $weights ) : 1;
		$new     = array();
		for ( $c = 0; $c < $n; $c++ ) {
			$new[] = array(
				'grid'   => $at,
				'index'  => $c,
				'weight' => $weights[ $c ] / $sum,
				'style'  => array(),
			);
		}
		$d['grid_cells'] = array_merge( $d['grid_cells'], $new );
		foreach ( array_values( $cells ) as $c => $cell ) {
			foreach ( (array) ( $cell['children'] ?? array() ) as $ws ) {
				if ( $c >= $n ) {
					break;
				}
				$w = $this->new_widget( (array) $ws, $at, $c );
				if ( is_wp_error( $w ) ) {
					return $w;
				}
				$d['widgets'][] = $w;
			}
		}
		return $d;
	}

	/**
	 * Insert, remove, move or duplicate one element.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      Operation.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op ) {
		$d    = $this->layout( $post_id );
		$kind = (string) ( $op['op'] ?? '' );

		if ( 'insert' === $kind ) {
			$spec   = (array) ( $op['element'] ?? array() );
			$parent = (string) ( $op['parent_id'] ?? '' );
			if ( '' === $parent ) {
				if ( 'row' !== ( $spec['type'] ?? '' ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'At the top level only rows are added ({type: "row", cells: n, children: [{children: [widgets]}]}); widgets go into a cell.' );
				}
				$r = $this->insert_row( $d, isset( $op['position'] ) ? (int) $op['position'] : count( $d['grids'] ), $spec );
				return is_wp_error( $r ) ? $r : $this->save( $post_id, $r );
			}
			$p = $this->parse( $parent );
			if ( ! $p || 'cell' !== $p[0] || ! isset( $this->cells_of( $d, $p[1] )[ $p[2] ] ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'Widgets go into a cell (id r…c…); rows go at the top level.' );
			}
			$w = $this->new_widget( $spec, $p[1], $p[2] );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			return $this->save( $post_id, $this->place( $d, $w, $p[1], $p[2], isset( $op['position'] ) ? (int) $op['position'] : null ) );
		}

		$p = $this->parse( (string) ( $op['element_id'] ?? '' ) );
		if ( ! $p ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}

		if ( 'widget' === $p[0] ) {
			if ( ! isset( $d['widgets'][ $p[1] ] ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No widget with that id on this page (see builder.read).' );
			}
			$w     = $d['widgets'][ $p[1] ];
			$guard = $this->guard( $w );
			if ( $guard ) {
				return $guard;
			}
			if ( 'remove' === $kind ) {
				array_splice( $d['widgets'], $p[1], 1 );
				return $this->save( $post_id, $d );
			}
			if ( 'duplicate' === $kind ) {
				$copy                             = $w;
				$copy['panels_info']['widget_id'] = wp_generate_uuid4();
				array_splice( $d['widgets'], $p[1] + 1, 0, array( $copy ) );
				return $this->save( $post_id, $d );
			}
			if ( 'move' === $kind ) {
				$t = $this->parse( (string) ( $op['parent_id'] ?? '' ) );
				if ( ! $t || 'cell' !== $t[0] || ! isset( $this->cells_of( $d, $t[1] )[ $t[2] ] ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'A widget moves into a cell (id r…c…).' );
				}
				array_splice( $d['widgets'], $p[1], 1 );
				return $this->save( $post_id, $this->place( $d, $w, $t[1], $t[2], isset( $op['position'] ) ? (int) $op['position'] : null ) );
			}
		}

		if ( 'row' === $p[0] ) {
			$g = $p[1];
			if ( ! isset( $d['grids'][ $g ] ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No row with that id on this page (see builder.read).' );
			}
			foreach ( $d['widgets'] as $w ) {
				if ( (int) $w['panels_info']['grid'] === $g && $this->guard( $w ) && 'remove' !== $kind ) {
					return $this->guard( $w );
				}
			}
			if ( 'remove' === $kind ) {
				return $this->save( $post_id, $this->drop_row( $d, $g ) );
			}
			if ( 'duplicate' === $kind || 'move' === $kind ) {
				if ( 'move' === $kind && '' !== (string) ( $op['parent_id'] ?? '' ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'Rows stay at the top level; give position only.' );
				}
				$cells   = array();
				$widgets = array();
				foreach ( $this->cells_of( $d, $g ) as $ci ) {
					$cells[] = $d['grid_cells'][ $ci ];
				}
				foreach ( $d['widgets'] as $w ) {
					if ( (int) $w['panels_info']['grid'] === $g ) {
						$widgets[] = $w;
					}
				}
				$grid = $d['grids'][ $g ];
				$to   = 'duplicate' === $kind ? $g + 1 : max( 0, min( (int) ( $op['position'] ?? count( $d['grids'] ) - 1 ), count( $d['grids'] ) - 1 ) );
				if ( 'move' === $kind ) {
					$d = $this->drop_row( $d, $g );
				}
				$d = $this->insert_row(
					$d,
					$to,
					array(
						'cells'   => count( $cells ),
						'weights' => array_map(
							static function ( $c ) {
								return (float) ( $c['weight'] ?? 0 );
							},
							$cells
						),
					)
				);
				if ( is_wp_error( $d ) ) {
					return $d;
				}
				$d['grids'][ $to ] = $grid;
				foreach ( $d['grid_cells'] as &$cell ) {
					if ( (int) $cell['grid'] === $to && isset( $cells[ (int) $cell['index'] ] ) ) {
						$cell = array_merge( $cells[ (int) $cell['index'] ], array( 'grid' => $to ) );
					}
				}
				unset( $cell );
				foreach ( $widgets as $w ) {
					$w['panels_info']['grid'] = $to;
					if ( 'duplicate' === $kind ) {
						$w['panels_info']['widget_id'] = wp_generate_uuid4();
					}
					$d['widgets'][] = $w;
				}
				return $this->save( $post_id, $d );
			}
		}

		if ( 'cell' === $p[0] ) {
			if ( 'remove' !== $kind ) {
				return new \WP_Error( 'presshelm_payload', 'Cells can be removed (the others share the width again); move or duplicate a row or a widget instead.' );
			}
			$cells = $this->cells_of( $d, $p[1] );
			if ( ! isset( $cells[ $p[2] ] ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No cell with that id on this page (see builder.read).' );
			}
			if ( 1 === count( $cells ) ) {
				return $this->save( $post_id, $this->drop_row( $d, $p[1] ) );
			}
			$d['widgets'] = array_values(
				array_filter(
					$d['widgets'],
					static function ( $w ) use ( $p ) {
						return ! ( (int) $w['panels_info']['grid'] === $p[1] && (int) $w['panels_info']['cell'] === $p[2] );
					}
				)
			);
			foreach ( $d['widgets'] as &$w ) {
				if ( (int) $w['panels_info']['grid'] === $p[1] && (int) $w['panels_info']['cell'] > $p[2] ) {
					--$w['panels_info']['cell'];
				}
			}
			unset( $w );
			array_splice( $d['grid_cells'], $cells[ $p[2] ], 1 );
			$left = $this->cells_of( $d, $p[1] );
			foreach ( $left as $i => $ci ) {
				$d['grid_cells'][ $ci ]['index']  = $i;
				$d['grid_cells'][ $ci ]['weight'] = 1 / count( $left );
			}
			$d['grids'][ $p[1] ]['cells'] = count( $left );
			return $this->save( $post_id, $d );
		}
		return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
	}

	/**
	 * Puts a widget into a cell at a position (widgets of the cell keep their order).
	 *
	 * @param array    $d   Layout.
	 * @param array    $w   Widget.
	 * @param int      $g   Row.
	 * @param int      $c   Cell.
	 * @param int|null $pos Position in the cell (null = last).
	 * @return array
	 */
	private function place( array $d, array $w, $g, $c, $pos ) {
		$w['panels_info']['grid'] = $g;
		$w['panels_info']['cell'] = $c;
		$in                       = array();
		foreach ( $d['widgets'] as $i => $x ) {
			if ( (int) $x['panels_info']['grid'] === $g && (int) $x['panels_info']['cell'] === $c ) {
				$in[] = $i;
			}
		}
		$pos   = null === $pos ? count( $in ) : max( 0, min( $pos, count( $in ) ) );
		$index = $pos < count( $in ) ? $in[ $pos ] : ( $in ? end( $in ) + 1 : count( $d['widgets'] ) );
		array_splice( $d['widgets'], $index, 0, array( $w ) );
		return $this->order( $d );
	}

	/**
	 * Removes a row with its cells and widgets; later rows move up.
	 *
	 * @param array $d Layout.
	 * @param int   $g Row.
	 * @return array
	 */
	private function drop_row( array $d, $g ) {
		array_splice( $d['grids'], $g, 1 );
		$d['grid_cells'] = array_values(
			array_filter(
				$d['grid_cells'],
				static function ( $c ) use ( $g ) {
					return (int) $c['grid'] !== $g;
				}
			)
		);
		foreach ( $d['grid_cells'] as &$c ) {
			if ( (int) $c['grid'] > $g ) {
				--$c['grid'];
			}
		}
		unset( $c );
		$d['widgets'] = array_values(
			array_filter(
				$d['widgets'],
				static function ( $w ) use ( $g ) {
					return (int) $w['panels_info']['grid'] !== $g;
				}
			)
		);
		foreach ( $d['widgets'] as &$w ) {
			if ( (int) $w['panels_info']['grid'] > $g ) {
				--$w['panels_info']['grid'];
			}
		}
		unset( $w );
		return $d;
	}

	/**
	 * Widgets and nesting for building new elements on this site.
	 *
	 * @return array
	 */
	public function vocabulary() {
		return array(
			'nesting' => array(
				'top level' => 'row (cells share the width by weight)',
				'row'       => 'cell',
				'cell'      => 'widget',
			),
			'widgets' => $this->widget_classes(),
			'element' => 'Ids are positions: rows r0, r1…; cells r0c0…; widgets w0, w1… in page order (read again after each change). A new widget: {class: "<widget class>", settings: {…}} into a cell (parent_id r…c…). A new row at the top level: {type: "row", cells: 2, weights?: [0.5, 0.5], children: [{children: [widget…]}, {children: […]}]}. Widget settings are the widget\'s own fields (builder_schema siteorigin <class>); each widget sanitizes them itself on save.',
		);
	}

	/**
	 * Widget classes on this site (no code widgets).
	 *
	 * @return string[]
	 */
	private function widget_classes() {
		global $wp_widget_factory;
		$out = array();
		foreach ( $wp_widget_factory ? array_keys( (array) $wp_widget_factory->widgets ) : array() as $class ) {
			if ( ! preg_match( self::CODE_CLASSES, (string) $class ) ) {
				$out[] = (string) $class;
			}
		}
		return $out;
	}

	/**
	 * Widget list, or one widget's fields (SiteOrigin widgets describe their form; core widgets
	 * use the settings their form shows).
	 *
	 * @param string $type Widget class, or ''.
	 * @param bool   $all  Ignored.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		unset( $all );
		global $wp_widget_factory;
		if ( '' === $type ) {
			$out = array();
			foreach ( $this->widget_classes() as $class ) {
				$w     = $wp_widget_factory->widgets[ $class ] ?? null;
				$out[] = array(
					'type'  => $class,
					'title' => $w ? wp_strip_all_tags( (string) $w->name ) : $class,
				);
			}
			return array(
				'builder'  => 'siteorigin',
				'elements' => array( 'row', 'cell' ),
				'widgets'  => $out,
			);
		}
		$w = $wp_widget_factory->widgets[ $type ] ?? null;
		if ( ! $w || preg_match( self::CODE_CLASSES, $type ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No editable widget "' . $type . '" on this site (see the list without type).' );
		}
		$fields = array();
		if ( method_exists( $w, 'form_options' ) ) {
			foreach ( (array) $w->form_options() as $name => $f ) {
				if ( preg_match( self::CODE_KEYS, (string) $name ) ) {
					continue;
				}
				$fields[] = Schema::field( (string) $name, (string) ( $f['type'] ?? 'text' ), (array) $f, 'content', false );
			}
		} else {
			// Core widgets: the field names their form uses.
			ob_start();
			$w->form( array() );
			$form = (string) ob_get_clean();
			if ( preg_match_all( '/name="widget-[^\[]+\[[^\]]*\]\[([a-z0-9_]+)\]"/i', $form, $m ) ) {
				foreach ( array_unique( $m[1] ) as $name ) {
					$fields[] = Schema::field( (string) $name, 'text', array(), 'content', false );
				}
			}
		}
		return Schema::result( 'siteorigin', $type, wp_strip_all_tags( (string) $w->name ), $fields, false, array( 'widget' => 'settings are the widget\'s own fields; it sanitizes them itself on save' ) );
	}

	/**
	 * Puts the layout and the post_content copy back exactly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Serialized data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw ) {
		$d = maybe_unserialize( (string) $raw );
		if ( ! is_array( $d ) || ! array_key_exists( 'panels', $d ) ) {
			return new \WP_Error( 'presshelm_journal_corrupt', 'The saved SiteOrigin layout is damaged' );
		}
		if ( is_array( $d['panels'] ) ) {
			update_post_meta( (int) $post_id, 'panels_data', map_deep( $d['panels'], array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) );
		} else {
			delete_post_meta( (int) $post_id, 'panels_data' );
		}
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => (string) ( $d['content'] ?? '' ) ), array( 'ID' => (int) $post_id ), array( '%s' ), array( '%d' ) );
		clean_post_cache( (int) $post_id );
		return true;
	}

	/**
	 * Saves through SiteOrigin's own layout-update ability (sanitizing, structure check, copy).
	 *
	 * @param int   $post_id Post id.
	 * @param array $d       Layout.
	 * @return true|\WP_Error
	 */
	private function save( $post_id, array $d ) {
		if ( count( $d['widgets'] ) > self::MAX_WIDGETS ) {
			return new \WP_Error( 'presshelm_payload', 'Too many widgets on one page (at most ' . self::MAX_WIDGETS . ').' );
		}
		$d = $this->order( $d );
		wp_save_post_revision( (int) $post_id );
		$r = \SiteOrigin_Panels_Abilities::single()->layout_update(
			array(
				'post_id'     => (int) $post_id,
				'panels_data' => $d,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['updated'] ) ) {
			return new \WP_Error( 'presshelm_builder_save', 'SiteOrigin did not save the layout: ' . (string) ( $r['message'] ?? 'unknown reason' ) );
		}
		return true;
	}
}
