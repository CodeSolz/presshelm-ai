<?php
/**
 * The kernel's ability-backed document (to-do 21, tier A): a page builder that registers WordPress
 * Abilities for reading and changing its pages. The pack maps PressHelm's builder operations to the
 * builder's own abilities (with input templates such as {post_id}, {element_id}, {settings}) and
 * says where a node keeps its id, type, settings, text and children. Every change then goes through
 * the builder's own ability, with its own permission and checks. Undo puts the stored page back
 * exactly (the surfaces the pack names).
 *
 * @package PressHelm
 */

namespace PressHelm\Packs;

use PressHelm\Builders\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * An ability-backed page builder.
 */
final class AbilityDocument implements Adapter {

	/**
	 * The pack.
	 *
	 * @var array
	 */
	private $p;

	/**
	 * Engine for a validated pack.
	 *
	 * @param array $pack Pack.
	 */
	public function __construct( array $pack ) {
		$this->p = $pack;
	}

	/**
	 * The pack (kept in the undo journal).
	 *
	 * @return array
	 */
	public function pack() {
		return $this->p;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return (string) $this->p['id'];
	}

	/**
	 * Active (with its abilities)?
	 *
	 * @return bool
	 */
	public function active() {
		return Pack::detected( (array) $this->p['detect'] ) && function_exists( 'wp_get_ability' ) && null !== wp_get_ability( (string) $this->p['source']['update']['ability'] );
	}

	/**
	 * Built with this builder?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		$built = Pack::owns( (array) $this->p['owns'], (int) $post_id );
		if ( $built ) {
			Effects::run( (array) ( $this->p['prepare'] ?? array() ), (int) $post_id );
		}
		return $built;
	}

	/**
	 * Surfaces kept for undo.
	 *
	 * @return array[]
	 */
	private function surfaces() {
		$out = array();
		foreach ( (array) ( $this->p['source']['undo'] ?? array( array( 'post' => 'post_content' ) ) ) as $s ) {
			if ( is_array( $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * Stored data (serialized).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id ) {
		$out = array();
		foreach ( $this->surfaces() as $s ) {
			$out[ wp_json_encode( $s ) ] = Pack::read( $s, $post_id );
		}
		return (string) maybe_serialize( $out );
	}

	/**
	 * Fills an ability input template: "{name}" values are replaced by the typed value (keys whose
	 * value is missing are left out); other values are kept as written.
	 *
	 * @param array $tpl  Template.
	 * @param array $vars Values.
	 * @return array
	 */
	private function input( array $tpl, array $vars ) {
		$out = array();
		foreach ( $tpl as $k => $v ) {
			if ( is_string( $v ) && preg_match( '/^\{([a-z_]+)\}$/', $v, $m ) ) {
				if ( array_key_exists( $m[1], $vars ) && null !== $vars[ $m[1] ] ) {
					$out[ $k ] = $vars[ $m[1] ];
				}
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = $this->input( $v, $vars );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Runs one of the builder's abilities (by the pack's mapping).
	 *
	 * @param string $op   Operation key in the pack (read, update, insert…).
	 * @param array  $vars Values.
	 * @return array|\WP_Error
	 */
	private function call( $op, array $vars ) {
		$map = $this->p['source'][ $op ] ?? null;
		if ( ! is_array( $map ) || empty( $map['ability'] ) ) {
			return new \WP_Error( 'presshelm_builder_unsupported', ( $this->p['name'] ?? $this->id() ) . ' offers no way to ' . $op . ' this.' );
		}
		$a = wp_get_ability( (string) $map['ability'] );
		if ( ! $a ) {
			return new \WP_Error( 'presshelm_builder_unavailable', 'The ability ' . $map['ability'] . ' is not available on this site (update the plugin).' );
		}
		$r = $a->execute( $this->input( (array) ( $map['input'] ?? array() ), $vars ) );
		if ( is_wp_error( $r ) ) {
			return new \WP_Error( 'presshelm_builder_refused', ( $this->p['name'] ?? $this->id() ) . ' refused: ' . $r->get_error_message() );
		}
		return is_array( $r ) ? $r : array();
	}

	/**
	 * A value at a dot path.
	 *
	 * @param mixed  $v    Value.
	 * @param string $path Path.
	 * @return mixed
	 */
	private function get( $v, $path ) {
		foreach ( '' === (string) $path ? array() : explode( '.', (string) $path ) as $p ) {
			if ( ! is_array( $v ) || ! array_key_exists( $p, $v ) ) {
				return null;
			}
			$v = $v[ $p ];
		}
		return $v;
	}

	/**
	 * Node mapping key (id, type, settings, text, children).
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private function n( $k ) {
		return (string) ( $this->p['source']['node'][ $k ] ?? '' );
	}

	/**
	 * The builder's node tree of a page.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function nodes( $post_id ) {
		$r = $this->call( 'read', array( 'post_id' => (int) $post_id ) );
		return is_wp_error( $r ) ? array() : (array) $this->get( $r, (string) ( $this->p['source']['read']['tree'] ?? '' ) );
	}

	/**
	 * Code types and keys.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private function code_type( $type ) {
		return isset( $this->p['code_types'] ) && preg_match( (string) $this->p['code_types'], $type );
	}

	/**
	 * Code setting?
	 *
	 * @param string $k Key.
	 * @return bool
	 */
	private function code_key( $k ) {
		return (bool) preg_match( Pack::ALWAYS_CODE, $k ) || ( isset( $this->p['code_keys'] ) && preg_match( (string) $this->p['code_keys'], $k ) );
	}

	/**
	 * Kind of a node from its type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private function kind( $type ) {
		$map = (array) ( $this->p['kind_by_type'] ?? array() );
		return (string) ( $map[ $type ] ?? ( $map['*'] ?? 'widget' ) );
	}

	/**
	 * Tree for the agent.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		return $this->walk( $this->nodes( $post_id ), array() );
	}

	/**
	 * Compact tree.
	 *
	 * @param array $items  Nodes.
	 * @param int[] $prefix Path.
	 * @return array
	 */
	private function walk( array $items, array $prefix ) {
		$out = array();
		foreach ( array_values( $items ) as $i => $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$type     = (string) $this->get( $n, $this->n( 'type' ) );
			$path     = array_merge( $prefix, array( $i ) );
			$settings = array();
			foreach ( (array) $this->get( $n, $this->n( 'settings' ) ) as $k => $v ) {
				if ( $this->code_key( (string) $k ) || ( isset( $this->p['locked_keys'] ) && preg_match( (string) $this->p['locked_keys'], (string) $k ) ) || ! is_scalar( $v ) || '' === $v || count( $settings ) >= 40 ) {
					continue;
				}
				$settings[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v;
			}
			$text = $this->get( $n, $this->n( 'text' ) );
			if ( is_string( $text ) && '' !== $text ) {
				$settings['content'] = mb_substr( $text, 0, 1000 );
			}
			$kids  = $this->get( $n, $this->n( 'children' ) );
			$out[] = array(
				'id'       => (string) $this->get( $n, $this->n( 'id' ) ),
				'type'     => $this->kind( $type ),
				'widget'   => $type,
				'path'     => $path,
				'settings' => $settings,
				'children' => is_array( $kids ) ? $this->walk( $kids, $path ) : array(),
			);
		}
		return $out;
	}

	/**
	 * A node by id.
	 *
	 * @param array  $items Nodes.
	 * @param string $id    Id.
	 * @return array|null
	 */
	private function find( array $items, $id ) {
		foreach ( $items as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			if ( (string) $this->get( $n, $this->n( 'id' ) ) === $id ) {
				return $n;
			}
			$kids = $this->get( $n, $this->n( 'children' ) );
			if ( is_array( $kids ) ) {
				$f = $this->find( $kids, $id );
				if ( $f ) {
					return $f;
				}
			}
		}
		return null;
	}

	/**
	 * Refuses code nodes (and code inside a node).
	 *
	 * @param array $n Node.
	 * @return \WP_Error|null
	 */
	private function guard( array $n ) {
		if ( $this->code_type( (string) $this->get( $n, $this->n( 'type' ) ) ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Code elements (HTML, embeds, shortcodes) are not changed through the page builder.' );
		}
		$kids = $this->get( $n, $this->n( 'children' ) );
		foreach ( is_array( $kids ) ? $kids : array() as $c ) {
			$g = is_array( $c ) ? $this->guard( $c ) : null;
			if ( $g ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * Splits settings into the builder's settings and text; refuses code.
	 *
	 * @param array $settings Settings.
	 * @return array|\WP_Error [settings, text|null]
	 */
	private function split( array $settings ) {
		$attrs = array();
		$text  = null;
		foreach ( $settings as $k => $v ) {
			$k = (string) $k;
			if ( 'content' === $k && '' !== $this->n( 'text' ) ) {
				if ( ! is_string( $v ) ) {
					return new \WP_Error( 'presshelm_payload', 'content is text (HTML for text elements).' );
				}
				$text = wp_kses_post( $v );
				continue;
			}
			if ( $this->code_key( $k ) || ( isset( $this->p['locked_keys'] ) && preg_match( (string) $this->p['locked_keys'], $k ) ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, attributes, code and element ids are never set through the page builder.' );
			}
			if ( ! is_scalar( $v ) && null !== $v ) {
				return new \WP_Error( 'presshelm_payload', 'Settings are text, numbers or true/false (see builder_schema).' );
			}
			$attrs[ $k ] = is_string( $v ) ? wp_kses_post( $v ) : $v;
		}
		return array( $attrs, $text );
	}

	/**
	 * Changes settings (and text) of one element through the builder's ability.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Element id.
	 * @param array  $settings   Settings.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings ) {
		$n = $this->find( $this->nodes( $post_id ), (string) $element_id );
		if ( ! $n ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
		}
		$g = $this->guard( $n );
		if ( $g ) {
			return $g;
		}
		$s = $this->split( $settings );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		wp_save_post_revision( (int) $post_id );
		$r = $this->call(
			'update',
			array(
				'post_id'    => (int) $post_id,
				'element_id' => (string) $element_id,
				'settings'   => $s[0] ? $s[0] : null,
				'text'       => $s[1],
			)
		);
		return is_wp_error( $r ) ? $r : $this->after( $post_id );
	}

	/**
	 * A new node in the builder's format from the agent's spec {type, settings, children}.
	 *
	 * @param array $spec Spec.
	 * @return array|\WP_Error
	 */
	private function build( array $spec ) {
		$type = (string) ( $spec['type'] ?? ( $spec['tag'] ?? ( $spec['widget'] ?? '' ) ) );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_\/-]{0,60}$/', $type ) ) {
			return new \WP_Error( 'presshelm_payload', 'The new element needs a type (see builder.read vocabulary).' );
		}
		if ( $this->code_type( $type ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Code elements (HTML, embeds, shortcodes) are not added through the page builder.' );
		}
		$s = $this->split( (array) ( $spec['settings'] ?? array() ) );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$out = array();
		$set = function ( array &$a, $path, $v ) {
			$ref = &$a;
			foreach ( explode( '.', $path ) as $p ) {
				$ref = &$ref[ $p ];
			}
			$ref = $v;
		};
		$set( $out, $this->n( 'type' ), $type );
		$set( $out, $this->n( 'settings' ), $s[0] );
		if ( null !== $s[1] ) {
			$set( $out, $this->n( 'text' ), $s[1] );
		} elseif ( ! empty( $spec['children'] ) && is_array( $spec['children'] ) ) {
			$kids = array();
			foreach ( $spec['children'] as $c ) {
				$x = $this->build( (array) $c );
				if ( is_wp_error( $x ) ) {
					return $x;
				}
				$kids[] = $x;
			}
			$set( $out, $this->n( 'children' ), $kids );
		}
		return $out;
	}

	/**
	 * Insert, remove, move or duplicate one element through the builder's abilities.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      Operation.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op ) {
		$kind  = (string) ( $op['op'] ?? '' );
		$nodes = $this->nodes( $post_id );
		$top   = (array) ( $this->p['top_types'] ?? array() );
		$vars  = array(
			'post_id'  => (int) $post_id,
			'position' => isset( $op['position'] ) ? (int) $op['position'] : null,
		);
		if ( 'insert' === $kind ) {
			$el = $this->build( (array) ( $op['element'] ?? array() ) );
			if ( is_wp_error( $el ) ) {
				return $el;
			}
			$target = (string) ( $op['parent_id'] ?? '' );
			if ( '' !== $target && ! $this->find( $nodes, $target ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			if ( '' === $target && $top && ! in_array( (string) $this->get( $el, $this->n( 'type' ) ), $top, true ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'At the top level only ' . implode( ', ', $top ) . ' are added.' );
			}
			$vars['element']   = $el;
			$vars['parent_id'] = '' === $target ? null : $target;
			wp_save_post_revision( (int) $post_id );
			$r = $this->call( 'insert', $vars );
			return is_wp_error( $r ) ? $r : $this->after( $post_id );
		}
		if ( ! in_array( $kind, array( 'remove', 'move', 'duplicate' ), true ) ) {
			return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
		}
		$id = (string) ( $op['element_id'] ?? '' );
		$n  = $this->find( $nodes, $id );
		if ( ! $n ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$g = $this->guard( $n );
		if ( $g ) {
			return $g;
		}
		$vars['element_id'] = $id;
		if ( 'move' === $kind ) {
			$target = (string) ( $op['parent_id'] ?? '' );
			if ( '' !== $target ) {
				$kids = $this->get( $n, $this->n( 'children' ) );
				if ( $target === $id || ( is_array( $kids ) && $this->find( $kids, $target ) ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'An element cannot be moved into itself.' );
				}
				$vars['parent_id'] = $target;
			} elseif ( $top && ! in_array( (string) $this->get( $n, $this->n( 'type' ) ), $top, true ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'Only ' . implode( ', ', $top ) . ' go at the top level.' );
			}
		}
		wp_save_post_revision( (int) $post_id );
		$r = $this->call( $kind, $vars );
		return is_wp_error( $r ) ? $r : $this->after( $post_id );
	}

	/**
	 * Effects after a change.
	 *
	 * @param int $post_id Post id.
	 * @return true|\WP_Error
	 */
	private function after( $post_id ) {
		return Effects::run( (array) ( $this->p['effects'] ?? array() ), (int) $post_id );
	}

	/**
	 * Element types for builder.read (from the builder's own list ability, or the pack).
	 *
	 * @return array
	 */
	public function vocabulary() {
		$types = array_map( 'strval', (array) ( $this->p['widgets'] ?? array() ) );
		$w     = $this->p['source']['widgets'] ?? null;
		if ( is_array( $w ) && ! empty( $w['ability'] ) ) {
			$r = $this->call( 'widgets', array() );
			if ( ! is_wp_error( $r ) ) {
				$list  = $this->get( $r, (string) ( $w['path'] ?? '' ) );
				$types = array();
				foreach ( (array) $list as $k => $v ) {
					$t = ! empty( $w['keys'] ) ? (string) $k : ( is_array( $v ) ? (string) ( $v['id'] ?? '' ) : (string) $v );
					if ( '' !== $t && ! $this->code_type( $t ) ) {
						$types[] = $t;
					}
				}
			}
		}
		return array(
			'nesting' => (array) ( $this->p['nesting'] ?? array() ),
			'widgets' => $types,
			'element' => (string) ( $this->p['element_help'] ?? '' ),
		);
	}

	/**
	 * Element list, or one type's settings from the builder's own schema ability.
	 *
	 * @param string $type Type, or ''.
	 * @param bool   $all  Also shared style settings.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		if ( '' === $type ) {
			return array(
				'builder' => $this->id(),
				'widgets' => array_map(
					static function ( $t ) {
						return array(
							'type'  => $t,
							'title' => $t,
						);
					},
					$this->vocabulary()['widgets']
				),
			);
		}
		if ( $this->code_type( $type ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Code elements are not changed through the page builder.' );
		}
		$r = $this->call(
			'schema',
			array(
				'type' => $type,
				'mode' => $all ? 'all' : 'own',
			)
		);
		return is_wp_error( $r ) ? $r : array(
			'builder' => $this->id(),
			'type'    => $type,
			'schema'  => $r,
		);
	}

	/**
	 * Puts the stored page back exactly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Serialized data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw ) {
		$d = maybe_unserialize( (string) $raw );
		if ( ! is_array( $d ) ) {
			return new \WP_Error( 'presshelm_journal_corrupt', 'The saved builder data is damaged' );
		}
		foreach ( $this->surfaces() as $s ) {
			$k = wp_json_encode( $s );
			if ( array_key_exists( $k, $d ) ) {
				$r = Pack::write( $s, $post_id, $d[ $k ] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		return $this->after( $post_id );
	}
}
