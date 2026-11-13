<?php
/**
 * The kernel's document engine (to-do 21): a page builder described by an integration pack, whose
 * page is a tree stored in post fields or meta. Everything builder-specific comes from the pack:
 * where the tree is stored and how it is encoded, whether it is nested (children lists per kind)
 * or flat (parent/order fields), how ids look, what may nest in what, which types and settings are
 * code, and, for builders whose editor saves rendered HTML, how to find each element's HTML (id
 * attributes or comment markers). Then:
 *
 * - with an HTML mirror: a setting changes when its current value is a whole text node or attribute
 *   value in the element's HTML (both are changed together); design settings are refused; remove,
 *   move and duplicate act on the tree and the HTML; new elements are refused (the builder's editor
 *   makes their HTML);
 * - without one (the builder renders from its data): settings are set, elements built from specs.
 *
 * Duplicates get fresh ids everywhere, including in sibling data the pack names (e.g. per-element
 * CSS). The pack's effects run after each change and undo. Undo puts every surface back exactly.
 *
 * @package PressHelm
 */

namespace PressHelm\Packs;

use PressHelm\Builders\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * A pack-described page builder.
 */
final class Document implements Adapter {

	/** HTML elements that never nest. */
	const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param' );

	/**
	 * The pack.
	 *
	 * @var array
	 */
	private $p;

	/**
	 * Engine for a validated pack.
	 *
	 * @param array $pack Pack (Pack::validate()).
	 */
	public function __construct( array $pack ) {
		$this->p = $pack;
	}

	/**
	 * The pack (kept in the undo journal, so undo needs nothing from the cloud).
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
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return Pack::detected( (array) $this->p['detect'] );
	}

	/**
	 * Built with this builder?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		return Pack::owns( (array) $this->p['owns'], (int) $post_id ) && null !== $this->model( $post_id );
	}

	// ---------------------------------------------------------------- storage

	/**
	 * Surfaces kept for undo: model, HTML, refs and extra ones the pack names.
	 *
	 * @return array[]
	 */
	private function surfaces() {
		$src  = $this->p['source'];
		$list = array( $src['model']['surface'] );
		if ( isset( $src['html'] ) ) {
			$list[] = $src['html']['surface'];
		}
		foreach ( (array) ( $src['refs'] ?? array() ) as $r ) {
			$list[] = $r['surface'];
		}
		foreach ( (array) ( $src['undo_also'] ?? array() ) as $s ) {
			if ( is_array( $s ) ) {
				$list[] = $s;
			}
		}
		return $list;
	}

	/**
	 * Stored data of every surface (serialized), for the journal and the version hash.
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
	 * The decoded document (the whole stored value), or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	private function model( $post_id ) {
		$m = $this->p['source']['model'];
		$d = Pack::decode( Pack::read( $m['surface'], $post_id ), (string) $m['encoding'] );
		if ( ! is_array( $d ) ) {
			return null;
		}
		$root = Pack::at( $d, (string) ( $m['path'] ?? '' ) );
		return is_array( $root ) ? $d : null;
	}

	/**
	 * The HTML surface's content ('' when the builder renders from its data).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function html( $post_id ) {
		return isset( $this->p['source']['html'] ) ? (string) Pack::read( $this->p['source']['html']['surface'], $post_id ) : '';
	}

	/**
	 * Whether this builder's HTML is made by its editor (then it is changed alongside the data).
	 *
	 * @return bool
	 */
	private function mirrored() {
		return isset( $this->p['source']['html'] );
	}

	// ---------------------------------------------------------------- tree model

	/**
	 * Tree settings with defaults.
	 *
	 * @param string $k Key.
	 * @return mixed
	 */
	private function t( $k ) {
		$t = (array) $this->p['source']['tree'];
		$d = array(
			'id_key'       => 'id',
			'type_key'     => 'type',
			'settings_key' => null,
			'id_format'    => 'alnum6',
		);
		return $t[ $k ] ?? ( $d[ $k ] ?? null );
	}

	/**
	 * The element list at the model path (by reference).
	 *
	 * @param array $d Document.
	 * @return array Reference.
	 */
	private function &root( array &$d ) {
		$ref = &Pack::at( $d, (string) ( $this->p['source']['model']['path'] ?? '' ) );
		return $ref;
	}

	/**
	 * Nested: kind => [children key, child kind].
	 *
	 * @param string $kind Kind.
	 * @return array|null
	 */
	private function nest( $kind ) {
		$n = (array) ( $this->t( 'nested' ) ?? array() );
		if ( ! isset( $n[ $kind ] ) || ! is_array( $n[ $kind ] ) || empty( $n[ $kind ]['children'] ) ) {
			return null;
		}
		return array( (string) $n[ $kind ]['children'], (string) $n[ $kind ]['child'] );
	}

	/**
	 * The kind of an element (nested: by position; flat: from its type).
	 *
	 * @param array       $el   Element.
	 * @param string|null $kind Kind known from position.
	 * @return string
	 */
	private function kind_of( array $el, $kind = null ) {
		if ( null !== $kind ) {
			return $kind;
		}
		$map  = (array) ( $this->t( 'kind_by_type' ) ?? array() );
		$type = (string) ( $el[ $this->t( 'type_key' ) ] ?? '' );
		return (string) ( $map[ $type ] ?? ( $map['*'] ?? 'element' ) );
	}

	/**
	 * The element's settings (a copy).
	 *
	 * @param array $el Element.
	 * @return array
	 */
	private function settings_of( array $el ) {
		$sk = $this->t( 'settings_key' );
		if ( $sk ) {
			return (array) ( $el[ $sk ] ?? array() );
		}
		$out = array();
		foreach ( $el as $k => $v ) {
			if ( ! $this->locked( (string) $k ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Keys that are structure, layout or styling internals (never settings).
	 *
	 * @param string $k Key.
	 * @return bool
	 */
	private function locked( $k ) {
		$structural = array( (string) $this->t( 'id_key' ), (string) $this->t( 'type_key' ) );
		$flat       = (array) ( $this->t( 'flat' ) ?? array() );
		foreach ( array( 'parent_key', 'order_key' ) as $fk ) {
			if ( isset( $flat[ $fk ] ) ) {
				$structural[] = (string) $flat[ $fk ];
			}
		}
		foreach ( (array) ( $this->t( 'nested' ) ?? array() ) as $n ) {
			if ( is_array( $n ) && ! empty( $n['children'] ) ) {
				$structural[] = (string) $n['children'];
			}
		}
		return in_array( $k, $structural, true ) || ( isset( $this->p['locked_keys'] ) && preg_match( (string) $this->p['locked_keys'], $k ) );
	}

	/**
	 * Code settings (always refused).
	 *
	 * @param string $k Key.
	 * @return bool
	 */
	private function code_key( $k ) {
		return (bool) preg_match( Pack::ALWAYS_CODE, $k ) || ( isset( $this->p['code_keys'] ) && preg_match( (string) $this->p['code_keys'], $k ) );
	}

	/**
	 * Code elements (by type).
	 *
	 * @param array $el Element.
	 * @return bool
	 */
	private function code_el( array $el ) {
		return isset( $this->p['code_types'] ) && preg_match( (string) $this->p['code_types'], (string) ( $el[ $this->t( 'type_key' ) ] ?? '' ) );
	}

	/**
	 * Flat model: the element map and its keys.
	 *
	 * @return array [parent key, order key, root value]
	 */
	private function flat() {
		$f = (array) $this->t( 'flat' );
		return array( (string) ( $f['parent_key'] ?? 'parent' ), (string) ( $f['order_key'] ?? 'order' ), $f['root'] ?? false );
	}

	/**
	 * Flat: parent id of an element ('' = top level).
	 *
	 * @param array $el Element.
	 * @return string
	 */
	private function parent_of( array $el ) {
		list( $pk ) = $this->flat();
		$v          = $el[ $pk ] ?? null;
		return null === $v || false === $v || '' === $v ? '' : (string) $v;
	}

	/**
	 * Flat: children ids of a parent, by order.
	 *
	 * @param array  $els Elements (id => element).
	 * @param string $pid Parent id or ''.
	 * @return string[]
	 */
	private function kids( array $els, $pid ) {
		list( , $ok ) = $this->flat();
		$ids          = array();
		foreach ( $els as $id => $e ) {
			if ( is_array( $e ) && $this->parent_of( $e ) === $pid ) {
				$ids[] = (string) $id;
			}
		}
		usort(
			$ids,
			static function ( $a, $b ) use ( $els, $ok ) {
				return (int) ( $els[ $a ][ $ok ] ?? 0 ) <=> (int) ( $els[ $b ][ $ok ] ?? 0 );
			}
		);
		return $ids;
	}

	/**
	 * Flat: orders 0..n under a parent.
	 *
	 * @param array    $els   Elements.
	 * @param string   $pid   Parent id or ''.
	 * @param string[] $order Ids.
	 */
	private function renumber( array &$els, $pid, array $order ) {
		list( $pk, $ok, $root ) = $this->flat();
		foreach ( array_values( $order ) as $i => $id ) {
			$els[ $id ][ $pk ] = '' === $pid ? $root : $pid;
			$els[ $id ][ $ok ] = $i;
		}
	}

	/**
	 * Flat: an element and everything under it.
	 *
	 * @param array  $els Elements.
	 * @param string $id  Id.
	 * @return string[]
	 */
	private function subtree( array $els, $id ) {
		$out = array( $id );
		foreach ( $this->kids( $els, $id ) as $k ) {
			$out = array_merge( $out, $this->subtree( $els, $k ) );
		}
		return $out;
	}

	/**
	 * Tree for the agent.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		$d = $this->model( $post_id );
		if ( ! $d ) {
			return array();
		}
		$root = $this->root( $d );
		if ( $this->t( 'flat' ) ) {
			return $this->walk_flat( $root, '', array() );
		}
		return $this->walk_nested( $root, (string) $this->t( 'root_kind' ), array() );
	}

	/**
	 * A tree node for the agent.
	 *
	 * @param array  $el       Element.
	 * @param string $kind     Kind.
	 * @param int[]  $path     Path.
	 * @param array  $children Children (already built).
	 * @return array
	 */
	private function node( array $el, $kind, array $path, array $children ) {
		$settings = array();
		foreach ( $this->settings_of( $el ) as $k => $v ) {
			if ( $this->code_key( (string) $k ) || '' === $v || null === $v || false === $v || array() === $v || count( $settings ) >= 40 ) {
				continue;
			}
			if ( is_scalar( $v ) ) {
				$settings[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v;
			} elseif ( is_array( $v ) && count( $v ) <= 8 && ! array_filter( $v, 'is_array' ) ) {
				$settings[ $k ] = $v;
			}
		}
		$leaf = (string) ( $this->p['widget_kind'] ?? 'element' );
		return array(
			'id'       => (string) ( $el[ $this->t( 'id_key' ) ] ?? '' ),
			'type'     => $kind,
			'widget'   => $kind === $leaf || null === $this->t( 'nested' ) ? (string) ( $el[ $this->t( 'type_key' ) ] ?? '' ) : null,
			'path'     => $path,
			'settings' => $settings,
			'children' => $children,
		);
	}

	/**
	 * Nested tree.
	 *
	 * @param array  $items  Elements.
	 * @param string $kind   Their kind.
	 * @param int[]  $prefix Path.
	 * @return array
	 */
	private function walk_nested( array $items, $kind, array $prefix ) {
		$out = array();
		foreach ( array_values( $items ) as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$path  = array_merge( $prefix, array( $i ) );
			$k     = $this->kind_in( $el, $kind );
			$n     = $this->nest( $k );
			$out[] = $this->node( $el, $k, $path, $n ? $this->walk_nested( (array) ( $el[ $n[0] ] ?? array() ), $n[1], $path ) : array() );
		}
		return $out;
	}

	/**
	 * Nested: an element's kind (from its type when the pack maps types to kinds, e.g. a row inside a
	 * column; else from its place).
	 *
	 * @param array  $el   Element.
	 * @param string $kind Kind from its place.
	 * @return string
	 */
	private function kind_in( array $el, $kind ) {
		$map  = (array) ( $this->t( 'kind_by_type' ) ?? array() );
		$type = (string) ( $el[ $this->t( 'type_key' ) ] ?? '' );
		return $map && isset( $map[ $type ] ) ? (string) $map[ $type ] : (string) $kind;
	}

	/**
	 * Flat tree.
	 *
	 * @param array  $els    Elements.
	 * @param string $pid    Parent.
	 * @param int[]  $prefix Path.
	 * @return array
	 */
	private function walk_flat( array $els, $pid, array $prefix ) {
		$out = array();
		foreach ( $this->kids( $els, $pid ) as $i => $id ) {
			$path  = array_merge( $prefix, array( $i ) );
			$out[] = $this->node( $els[ $id ], $this->kind_of( $els[ $id ] ), $path, $this->walk_flat( $els, $id, $path ) );
		}
		return $out;
	}

	/**
	 * Nested: finds an element (reference to its list, index, kind, ancestor ids).
	 *
	 * @param array  $items List (by reference).
	 * @param string $kind  Kind of the list's elements.
	 * @param string $id    Id.
	 * @param array  $chain Ancestor ids.
	 * @return array|null
	 */
	private function &find( array &$items, $kind, $id, array $chain = array() ) {
		$none = null;
		$ik   = (string) $this->t( 'id_key' );
		foreach ( $items as $i => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$k = $this->kind_in( $el, $kind );
			if ( (string) ( $el[ $ik ] ?? '' ) === $id ) {
				$hit = array( &$items, $i, $k, $chain );
				return $hit;
			}
			$n = $this->nest( $k );
			if ( $n && isset( $el[ $n[0] ] ) && is_array( $el[ $n[0] ] ) ) {
				$found = &$this->find( $el[ $n[0] ], $n[1], $id, array_merge( $chain, array( (string) ( $el[ $ik ] ?? '' ) ) ) );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return $none;
	}

	// ---------------------------------------------------------------- HTML mirror

	/**
	 * [start, end) of the element with this id attribute, or null.
	 *
	 * @param string $html HTML.
	 * @param string $attr Id attribute value, or a whole attribute (name="value").
	 * @return int[]|null
	 */
	private function by_attr( $html, $attr ) {
		$mark = preg_match( '/^[a-z][a-z0-9-]*="[^"<>]*"$/', $attr ) ? preg_quote( $attr, '/' ) : 'id="' . preg_quote( $attr, '/' ) . '"';
		if ( ! preg_match( '/<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\s' . $mark . '[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = (int) $m[0][1];
		$open  = $start + strlen( $m[0][0] );
		if ( in_array( strtolower( $m[1][0] ), self::VOID, true ) || '/>' === substr( $m[0][0], -2 ) ) {
			return array( $start, $open );
		}
		$depth = 1;
		$pos   = $open;
		while ( $depth > 0 && preg_match( '/<!--.*?-->|<(script|style)\b[^>]*>.*?<\/\1\s*>|<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(\/?)>/s', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$pos = (int) $t[0][1] + strlen( $t[0][0] );
			if ( ! isset( $t[4] ) || '' === $t[3][0] ) {
				continue;
			}
			if ( in_array( strtolower( $t[3][0] ), self::VOID, true ) || '/' === $t[4][0] ) {
				continue;
			}
			$depth += '/' === $t[2][0] ? -1 : 1;
		}
		return 0 === $depth ? array( $start, $pos ) : null;
	}

	/**
	 * Fills an {id} template.
	 *
	 * @param string $tpl Template.
	 * @param string $id  Id.
	 * @return string
	 */
	private function fill( $tpl, $id ) {
		return str_replace( '{id}', $id, (string) $tpl );
	}

	/**
	 * The HTML unit of an element: its boundary (id attribute or comment markers), widened to a
	 * wrapper that holds only it when the pack says so.
	 *
	 * @param string $html HTML.
	 * @param string $id   Element id.
	 * @return int[]|null
	 */
	private function unit( $html, $id ) {
		$h = (array) $this->p['source']['html'];
		if ( isset( $h['comments'] ) ) {
			$open  = $this->fill( $h['comments'][0], $id );
			$close = $this->fill( $h['comments'][1], $id );
			$s     = strpos( $html, $open );
			$e     = false === $s ? false : strpos( $html, $close, $s );
			return false === $s || false === $e ? null : array( $s, $e + strlen( $close ) );
		}
		$r = null;
		foreach ( (array) ( $h['element'] ?? array() ) as $tpl ) {
			$r = $this->by_attr( $html, $this->fill( $tpl, $id ) );
			if ( $r ) {
				break;
			}
		}
		$wrap  = (array) ( $h['absorb_wrappers'] ?? array() );
		$marks = array();
		foreach ( (array) ( $h['wrapper_marks'] ?? array() ) as $tpl ) {
			$marks[] = $this->fill( $tpl, $id );
		}
		for ( $i = 0; $r && $wrap && $i < 2; $i++ ) {
			$before = substr( $html, 0, $r[0] );
			if ( ! preg_match( '/<(' . implode( '|', array_map( 'preg_quote', $wrap ) ) . ')\b[^>]*>\s*$/', $before, $o, PREG_OFFSET_CAPTURE ) ) {
				break;
			}
			// With marks, only a wrapper that names this element (e.g. its own outer class) is part of it.
			if ( $marks && ! array_filter(
				$marks,
				static function ( $mk ) use ( $o ) {
					return false !== strpos( $o[0][0], $mk );
				}
			) ) {
				break;
			}
			if ( ! preg_match( '/^\s*<\/' . $o[1][0] . '\s*>/', substr( $html, $r[1] ), $c ) ) {
				break;
			}
			$r = array( (int) $o[0][1], $r[1] + strlen( $c[0] ) );
		}
		return $r;
	}

	/**
	 * Where new children go at the end of a parent ('' = top level), or null.
	 *
	 * @param string   $html  HTML.
	 * @param string   $pid   Parent id or ''.
	 * @param string[] $kids  The parent's children ids (without the moving element).
	 * @return int|null
	 */
	private function end_of( $html, $pid, array $kids ) {
		$h = (array) $this->p['source']['html'];
		if ( $kids ) {
			$r = $this->unit( $html, end( $kids ) );
			return $r ? $r[1] : null;
		}
		if ( '' === $pid ) {
			if ( isset( $h['top']['id_attr'] ) ) {
				$r = $this->by_attr( $html, (string) $h['top']['id_attr'] );
				return $r ? $r[0] + (int) strrpos( substr( $html, $r[0], $r[1] - $r[0] ), '</' ) : null;
			}
			$p = isset( $h['top']['before'] ) ? strrpos( $html, (string) $h['top']['before'] ) : false;
			return false === $p ? strlen( $html ) : $p;
		}
		$r = $this->unit( $html, $pid );
		if ( ! $r ) {
			return null;
		}
		if ( isset( $h['container'] ) ) {
			if ( ! preg_match( '/<[a-z]+\b[^>]*' . preg_quote( (string) $h['container'], '/' ) . '[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $r[0] ) || (int) $m[0][1] > $r[1] ) {
				return null;
			}
			return (int) $m[0][1] + strlen( $m[0][0] );
		}
		$close = strrpos( substr( $html, $r[0], $r[1] - $r[0] ), '</' );
		return false === $close ? null : $r[0] + $close;
	}

	/**
	 * How often a value appears as a whole text node or attribute value.
	 *
	 * @param string $html HTML.
	 * @param string $form Value as it would appear.
	 * @return int
	 */
	private function whole_count( $html, $form ) {
		return (int) preg_match_all( $this->whole( $form ), $html );
	}

	/**
	 * Pattern for a value as a whole text node or attribute value (with the whitespace an editor
	 * puts around text when the pack says so: groups 1 and 3).
	 *
	 * @param string $form Value as it would appear.
	 * @return string
	 */
	private function whole( $form ) {
		$ws = empty( $this->p['source']['html']['trim_text'] ) ? '' : '\s*';
		return '/(?<=[>"])(' . $ws . ')(' . preg_quote( $form, '/' ) . ')(' . $ws . ')(?=[<"])/';
	}

	// ---------------------------------------------------------------- changes

	/**
	 * Refusal for code elements.
	 *
	 * @return \WP_Error
	 */
	private function code_error() {
		return new \WP_Error( 'presshelm_builder_code', 'Code elements (HTML, JavaScript, shortcodes, embeds) and custom CSS or attributes are not changed through the page builder.' );
	}

	/**
	 * Leaves of a value by dot path.
	 *
	 * @param mixed  $v      Value.
	 * @param string $prefix Path.
	 * @return array
	 */
	private function leaves( $v, $prefix = '' ) {
		if ( ! is_array( $v ) ) {
			return array( $prefix => $v );
		}
		$out = array();
		foreach ( $v as $k => $x ) {
			$out = array_merge( $out, $this->leaves( $x, '' === $prefix ? (string) $k : $prefix . '.' . $k ) );
		}
		return $out;
	}

	/**
	 * Puts leaves back into a value's shape.
	 *
	 * @param mixed $shape  Original value.
	 * @param array $leaves Path => value.
	 * @return mixed
	 */
	private function unleaf( $shape, array $leaves ) {
		if ( ! is_array( $shape ) ) {
			return $leaves[''] ?? $shape;
		}
		foreach ( $leaves as $path => $v ) {
			$ref = &$shape;
			foreach ( explode( '.', (string) $path ) as $p ) {
				$ref = &$ref[ $p ];
			}
			$ref = $v;
			unset( $ref );
		}
		return $shape;
	}

	/**
	 * A value cleaned like the builder's editor would (addresses as URLs, text as post HTML).
	 *
	 * @param string $key Setting path.
	 * @param mixed  $v   Value.
	 * @return mixed
	 */
	private function clean( $key, $v ) {
		if ( ! is_string( $v ) ) {
			return $v;
		}
		return isset( $this->p['url_keys'] ) && preg_match( (string) $this->p['url_keys'], $key ) ? esc_url_raw( $v ) : wp_kses_post( $v );
	}

	/**
	 * Changes settings of one element.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Element id.
	 * @param array  $settings   Settings.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings ) {
		$d    = $this->model( $post_id );
		$html = $this->html( $post_id );
		$id   = (string) $element_id;
		if ( ! $d ) {
			return new \WP_Error( 'presshelm_not_builder', 'This page has no builder data' );
		}
		$root = &$this->root( $d );
		if ( $this->t( 'flat' ) ) {
			if ( ! isset( $root[ $id ] ) || ! is_array( $root[ $id ] ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
			}
			$el = &$root[ $id ];
		} else {
			$hit = &$this->find( $root, (string) $this->t( 'root_kind' ), $id );
			if ( null === $hit ) {
				return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
			}
			$el = &$hit[0][ $hit[1] ];
		}
		if ( $this->code_el( $el ) ) {
			return $this->code_error();
		}
		$sk   = $this->t( 'settings_key' );
		$cur  = $this->settings_of( $el );
		$part = null;
		$r    = null;
		if ( $this->mirrored() ) {
			$r = $this->unit( $html, $id );
			if ( ! $r ) {
				return new \WP_Error( 'presshelm_builder_html', 'This element is not in the saved page HTML; open and save the page once in the builder\'s editor.' );
			}
			$part = substr( $html, $r[0], $r[1] - $r[0] );
		}
		foreach ( $settings as $k => $v ) {
			$k = (string) $k;
			if ( $this->code_key( $k ) ) {
				return $this->code_error();
			}
			if ( $this->locked( $k ) ) {
				return new \WP_Error( 'presshelm_builder_setting', '"' . $k . '" is structure, layout or styling data; it is not changed through the page builder.' );
			}
			if ( ! $this->mirrored() ) {
				$cur[ $k ] = is_array( $v ) ? map_deep( $v, array( $this, 'kses_value' ) ) : $this->clean( $k, $v );
				continue;
			}
			if ( ! array_key_exists( $k, $cur ) ) {
				return new \WP_Error( 'presshelm_builder_setting', 'This element has no setting "' . $k . '" (see builder.read).' );
			}
			$old = $this->leaves( $cur[ $k ] );
			$new = $this->leaves( is_array( $cur[ $k ] ) && is_array( $v ) ? array_replace_recursive( $cur[ $k ], $v ) : $v );
			if ( array_keys( $old ) !== array_keys( $new ) ) {
				return new \WP_Error( 'presshelm_builder_setting', 'Keep the shape of "' . $k . '" as builder.read shows it (only change its values).' );
			}
			foreach ( $new as $path => $nv ) {
				$ov = $old[ $path ];
				if ( $ov === $nv || ( is_scalar( $ov ) && is_scalar( $nv ) && (string) $ov === (string) $nv ) ) {
					continue;
				}
				$leaf = '' === $path ? $k : $k . '.' . $path;
				if ( ! is_string( $ov ) || '' === $ov || ! ( is_string( $nv ) || is_numeric( $nv ) ) ) {
					return $this->design_error( $leaf );
				}
				$clean = (string) $this->clean( $leaf, (string) $nv );
				$found = false;
				foreach ( array_unique( array( $ov, esc_html( $ov ), esc_attr( $ov ), esc_url( $ov ) ) ) as $form ) {
					if ( '' !== $form && 1 === $this->whole_count( $part, $form ) ) {
						$repl  = $form === $ov ? $clean : ( esc_url( $ov ) === $form ? esc_url( $clean ) : ( esc_attr( $ov ) === $form ? esc_attr( $clean ) : esc_html( $clean ) ) );
						$part  = preg_replace_callback(
							$this->whole( $form ),
							static function ( $m ) use ( $repl ) {
								return $m[1] . $repl . $m[3];
							},
							$part,
							1
						);
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					return $this->design_error( $leaf );
				}
				$new[ $path ] = $clean;
			}
			$cur[ $k ] = $this->unleaf( $cur[ $k ], $new );
		}
		if ( $sk ) {
			$el[ $sk ] = $cur;
		} else {
			$el = array_merge( $el, $cur );
		}
		unset( $el, $hit );
		return $this->save( $post_id, $d, null === $r ? null : substr( $html, 0, $r[0] ) . $part . substr( $html, $r[1] ) );
	}

	/**
	 * Cleans one string value (map_deep callback).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public function kses_value( $v ) {
		return is_string( $v ) ? wp_kses_post( $v ) : $v;
	}

	/**
	 * Refusal for design settings of an editor-rendered builder.
	 *
	 * @param string $leaf Setting.
	 * @return \WP_Error
	 */
	private function design_error( $leaf ) {
		$name = (string) ( $this->p['name'] ?? $this->p['id'] );
		return new \WP_Error( 'presshelm_builder_setting', 'The setting "' . $leaf . '" is a design setting that ' . $name . '\'s editor turns into page HTML, so it can only be changed there. PressHelm changes texts, links and image addresses.' );
	}

	/**
	 * Insert, remove, move or duplicate one element.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      Operation.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op ) {
		$kind = (string) ( $op['op'] ?? '' );
		$name = (string) ( $this->p['name'] ?? $this->p['id'] );
		if ( ! in_array( $kind, array( 'insert', 'remove', 'move', 'duplicate' ), true ) ) {
			return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
		}
		if ( isset( $this->p['ops'] ) && ! in_array( $kind, (array) $this->p['ops'], true ) ) {
			return new \WP_Error( 'presshelm_builder_op', 'On ' . $name . ' pages PressHelm can ' . implode( ', ', (array) $this->p['ops'] ) . ' elements; to ' . $kind . ' one, the owner uses ' . $name . '\'s editor. Nothing was changed.' );
		}
		if ( 'insert' === $kind && $this->mirrored() ) {
			return new \WP_Error( 'presshelm_builder_insert', $name . ' builds the HTML of new elements in its editor, so PressHelm cannot add a new one. Duplicate a similar element (op duplicate) and change its texts, links or images, or ask the owner to add it in ' . $name . '.' );
		}
		$d    = $this->model( $post_id );
		$html = $this->html( $post_id );
		if ( ! $d ) {
			return new \WP_Error( 'presshelm_not_builder', 'This page has no builder data' );
		}
		return $this->t( 'flat' ) ? $this->change_flat( $post_id, $d, $html, $op ) : $this->change_nested( $post_id, $d, $html, $op );
	}

	/**
	 * Allowed parent kinds of a kind ('' = top level), from the pack's nesting.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	private function parents_of( $kind ) {
		$out = array();
		foreach ( (array) ( $this->p['nesting'] ?? array() ) as $parent => $children ) {
			if ( in_array( $kind, (array) $children, true ) ) {
				$out[] = 'top' === $parent ? '' : (string) $parent;
			}
		}
		return $out;
	}

	/**
	 * Nesting refusal.
	 *
	 * @param string $kind Kind.
	 * @return \WP_Error
	 */
	private function nesting_error( $kind ) {
		$p = $this->parents_of( $kind );
		return new \WP_Error( 'presshelm_builder_nesting', 'A ' . $kind . ' goes ' . ( in_array( '', $p, true ) ? 'at the top level' : 'into a ' . implode( ' or ', $p ) ) . ' (see builder.read).' );
	}

	/**
	 * Flat model changes.
	 *
	 * @param int    $post_id Post id.
	 * @param array  $d       Document.
	 * @param string $html    HTML.
	 * @param array  $op      Operation.
	 * @return true|\WP_Error
	 */
	private function change_flat( $post_id, array $d, $html, array $op ) {
		$kind = (string) $op['op'];
		$els  = &$this->root( $d );
		$ik   = (string) $this->t( 'id_key' );
		$tk   = (string) $this->t( 'type_key' );

		if ( 'insert' === $kind ) {
			$spec   = (array) ( $op['element'] ?? array() );
			$target = (string) ( $op['parent_id'] ?? '' );
			$new    = $this->build_flat( $spec, $els );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			if ( '' !== $target && ! isset( $els[ $target ] ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			$pk = '' === $target ? '' : $this->kind_of( $els[ $target ] );
			if ( ! in_array( $pk, $this->parents_of( $this->kind_of( $new ) ), true ) ) {
				return $this->nesting_error( $this->kind_of( $new ) );
			}
			$nid         = (string) $new[ $ik ];
			$els[ $nid ] = $new;
			$to          = array_values( array_diff( $this->kids( $els, $target ), array( $nid ) ) );
			array_splice( $to, isset( $op['position'] ) ? max( 0, min( (int) $op['position'], count( $to ) ) ) : count( $to ), 0, array( $nid ) );
			$this->renumber( $els, $target, $to );
			unset( $els );
			return $this->save( $post_id, $d, null );
		}

		$id = (string) ( $op['element_id'] ?? '' );
		if ( ! isset( $els[ $id ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$tree = $this->subtree( $els, $id );
		foreach ( $tree as $x ) {
			if ( $this->code_el( (array) $els[ $x ] ) ) {
				return $this->code_error();
			}
		}
		$r = null;
		if ( $this->mirrored() ) {
			$r = $this->unit( $html, $id );
			if ( ! $r ) {
				return new \WP_Error( 'presshelm_builder_html', 'This element is not in the saved page HTML; open and save the page once in the builder\'s editor.' );
			}
		}
		$part = null === $r ? '' : substr( $html, $r[0], $r[1] - $r[0] );
		$pid  = $this->parent_of( $els[ $id ] );
		$sibs = $this->kids( $els, $pid );

		if ( 'remove' === $kind ) {
			foreach ( $tree as $x ) {
				unset( $els[ $x ] );
			}
			$this->renumber( $els, $pid, array_values( array_diff( $sibs, array( $id ) ) ) );
			unset( $els );
			return $this->save( $post_id, $d, null === $r ? null : substr( $html, 0, $r[0] ) . substr( $html, $r[1] ) );
		}

		if ( 'duplicate' === $kind ) {
			$map = array();
			foreach ( $tree as $x ) {
				do {
					$nid = Pack::new_id( (string) $this->t( 'id_format' ) );
				} while ( isset( $els[ $nid ] ) || in_array( $nid, $map, true ) );
				$map[ $x ] = $nid;
			}
			list( $pk ) = $this->flat();
			foreach ( $tree as $x ) {
				$copy        = $els[ $x ];
				$copy[ $ik ] = $map[ $x ];
				if ( $x !== $id ) {
					$copy[ $pk ] = $map[ (string) $copy[ $pk ] ];
				}
				$els[ $map[ $x ] ] = $copy;
			}
			array_splice( $sibs, (int) array_search( $id, $sibs, true ) + 1, 0, array( $map[ $id ] ) );
			$this->renumber( $els, $pid, $sibs );
			unset( $els );
			return $this->save( $post_id, $d, null === $r ? null : substr( $html, 0, $r[1] ) . strtr( $part, $map ) . substr( $html, $r[1] ), $map );
		}

		// move.
		$target = (string) ( $op['parent_id'] ?? '' );
		if ( '' !== $target && ! isset( $els[ $target ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
		}
		$pk = '' === $target ? '' : $this->kind_of( $els[ $target ] );
		if ( ! in_array( $pk, $this->parents_of( $this->kind_of( $els[ $id ] ) ), true ) ) {
			return $this->nesting_error( $this->kind_of( $els[ $id ] ) );
		}
		if ( in_array( $target, $tree, true ) ) {
			return new \WP_Error( 'presshelm_builder_nesting', 'An element cannot be moved into itself.' );
		}
		$this->renumber( $els, $pid, array_values( array_diff( $sibs, array( $id ) ) ) );
		$to = array_values( array_diff( $this->kids( $els, $target ), array( $id ) ) );
		$at = isset( $op['position'] ) ? max( 0, min( (int) $op['position'], count( $to ) ) ) : count( $to );
		if ( null !== $r ) {
			$html = substr( $html, 0, $r[0] ) . substr( $html, $r[1] );
			if ( $at < count( $to ) ) {
				$sib = $this->unit( $html, $to[ $at ] );
				$pos = $sib ? $sib[0] : null;
			} else {
				$pos = $this->end_of( $html, $target, $to );
			}
			if ( null === $pos ) {
				return new \WP_Error( 'presshelm_builder_html', 'The new place is not in the saved page HTML; open and save the page once in the builder\'s editor.' );
			}
			$html = substr( $html, 0, $pos ) . $part . substr( $html, $pos );
		}
		array_splice( $to, $at, 0, array( $id ) );
		$this->renumber( $els, $target, $to );
		unset( $els );
		return $this->save( $post_id, $d, null === $r ? null : $html );
	}

	/**
	 * Flat: a new element from a spec {type, settings} (builders that render from their data).
	 *
	 * @param array $spec Spec.
	 * @param array $els  Elements (ids in use).
	 * @return array|\WP_Error
	 */
	private function build_flat( array $spec, array $els ) {
		$tk   = (string) $this->t( 'type_key' );
		$type = (string) ( $spec['type'] ?? ( $spec[ $tk ] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_\/-]{0,60}$/', $type ) ) {
			return new \WP_Error( 'presshelm_payload', 'The new element needs a type (see builder.read vocabulary).' );
		}
		$el = array( $tk => $type );
		if ( $this->code_el( $el ) ) {
			return $this->code_error();
		}
		foreach ( (array) ( $spec['settings'] ?? array() ) as $k => $v ) {
			if ( $this->code_key( (string) $k ) || $this->locked( (string) $k ) ) {
				return $this->code_error();
			}
			$el[ (string) $k ] = is_array( $v ) ? map_deep( $v, array( $this, 'kses_value' ) ) : $this->clean( (string) $k, $v );
		}
		do {
			$nid = Pack::new_id( (string) $this->t( 'id_format' ) );
		} while ( isset( $els[ $nid ] ) );
		$el[ (string) $this->t( 'id_key' ) ] = $nid;
		return $el;
	}

	/**
	 * Nested model changes.
	 *
	 * @param int    $post_id Post id.
	 * @param array  $d       Document.
	 * @param string $html    HTML.
	 * @param array  $op      Operation.
	 * @return true|\WP_Error
	 */
	private function change_nested( $post_id, array $d, $html, array $op ) {
		$kind  = (string) $op['op'];
		$root  = &$this->root( $d );
		$rk    = (string) $this->t( 'root_kind' );
		$ik    = (string) $this->t( 'id_key' );
		$place = function ( $target ) use ( &$root, $rk ) {
			if ( '' === $target ) {
				return array( &$root, '' );
			}
			$to = &$this->find( $root, $rk, $target );
			if ( null === $to ) {
				return null;
			}
			$n = $this->nest( $to[2] );
			if ( ! $n ) {
				return array( null, $to[2] );
			}
			if ( ! isset( $to[0][ $to[1] ][ $n[0] ] ) || ! is_array( $to[0][ $to[1] ][ $n[0] ] ) ) {
				$to[0][ $to[1] ][ $n[0] ] = array();
			}
			return array( &$to[0][ $to[1] ][ $n[0] ], $to[2] );
		};

		if ( 'insert' === $kind ) {
			$target = (string) ( $op['parent_id'] ?? '' );
			$slot   = $place( $target );
			if ( null === $slot ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			$child = '' === $target ? $rk : ( $this->nest( $slot[1] )[1] ?? null );
			if ( null === $slot[0] || null === $child ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'That element cannot hold other elements.' );
			}
			$new = $this->build_nested( (array) ( $op['element'] ?? array() ), $child );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$list = &$slot[0];
			array_splice( $list, isset( $op['position'] ) ? max( 0, min( (int) $op['position'], count( $list ) ) ) : count( $list ), 0, array( $new ) );
			unset( $list, $root );
			return $this->save( $post_id, $d, null );
		}

		$id  = (string) ( $op['element_id'] ?? '' );
		$hit = &$this->find( $root, $rk, $id );
		if ( null === $hit ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$el = $hit[0][ $hit[1] ];
		if ( $this->code_el( $el ) || $this->has_code( $el, $hit[2] ) ) {
			return $this->code_error();
		}
		$r = null;
		if ( $this->mirrored() ) {
			$r = $this->unit( $html, $id );
			if ( ! $r ) {
				return new \WP_Error( 'presshelm_builder_html', 'This element is not in the saved page HTML; open and save the page once in the builder\'s editor.' );
			}
		}
		$part = null === $r ? '' : substr( $html, $r[0], $r[1] - $r[0] );

		if ( 'remove' === $kind ) {
			array_splice( $hit[0], $hit[1], 1 );
			$this->reindex( $hit[0] );
			unset( $hit, $root );
			return $this->save( $post_id, $d, null === $r ? null : substr( $html, 0, $r[0] ) . substr( $html, $r[1] ) );
		}
		if ( 'duplicate' === $kind ) {
			$map  = array();
			$copy = $this->reid( $el, $hit[2], $map );
			foreach ( $map as $old => $new ) {
				$part = preg_replace( '/(?<![A-Za-z0-9])' . preg_quote( $old, '/' ) . '(?![A-Za-z0-9])/', $new, $part );
			}
			array_splice( $hit[0], $hit[1] + 1, 0, array( $copy ) );
			$this->reindex( $hit[0] );
			unset( $hit, $root );
			return $this->save( $post_id, $d, null === $r ? null : substr( $html, 0, $r[1] ) . $part . substr( $html, $r[1] ), $map );
		}

		// move.
		$target = (string) ( $op['parent_id'] ?? '' );
		$need   = $this->parents_of( $hit[2] );
		if ( '' !== $target && ( $target === $id || in_array( $id, $this->ancestors( $root, $target ), true ) ) ) {
			return new \WP_Error( 'presshelm_builder_nesting', 'An element cannot be moved into itself.' );
		}
		$tkind = '' === $target ? '' : $this->kind_at( $root, $target );
		if ( null === $tkind ) {
			return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
		}
		if ( ! in_array( $tkind, $need, true ) ) {
			return $this->nesting_error( $hit[2] );
		}
		array_splice( $hit[0], $hit[1], 1 );
		$this->reindex( $hit[0] );
		unset( $hit );
		$links = (array) ( $this->t( 'links' ) ?? array() );
		if ( isset( $links['parent_key'] ) && array_key_exists( $links['parent_key'], $el ) ) {
			$el[ $links['parent_key'] ] = '' === $target ? $this->top_parent( $d ) : $target;
		}
		if ( null !== $r ) {
			$html = substr( $html, 0, $r[0] ) . substr( $html, $r[1] );
		}
		$slot = $place( $target );
		$list = &$slot[0];
		$at   = isset( $op['position'] ) ? max( 0, min( (int) $op['position'], count( $list ) ) ) : count( $list );
		if ( null !== $r ) {
			if ( $at < count( $list ) ) {
				$sib = $this->unit( $html, (string) $list[ $at ][ $ik ] );
				$pos = $sib ? $sib[0] : null;
			} else {
				$pos = $this->end_of(
					$html,
					$target,
					array_map(
						static function ( $x ) use ( $ik ) {
							return (string) $x[ $ik ];
						},
						$list
					)
				);
			}
			if ( null === $pos ) {
				return new \WP_Error( 'presshelm_builder_html', 'The new place is not in the saved page HTML; open and save the page once in the builder\'s editor.' );
			}
			$html = substr( $html, 0, $pos ) . $part . substr( $html, $pos );
		}
		array_splice( $list, $at, 0, array( $el ) );
		$this->reindex( $list );
		unset( $list, $root );
		return $this->save( $post_id, $d, null === $r ? null : $html );
	}

	/**
	 * Nested: positions 0..n in a list, for builders that keep each element's index (only where an
	 * element already has that key).
	 *
	 * @param array $items Elements (by reference).
	 */
	private function reindex( array &$items ) {
		$ik = (string) ( ( (array) ( $this->t( 'links' ) ?? array() ) )['index_key'] ?? '' );
		if ( '' === $ik ) {
			return;
		}
		foreach ( array_keys( $items ) as $i => $k ) {
			if ( is_array( $items[ $k ] ) && array_key_exists( $ik, $items[ $k ] ) ) {
				$items[ $k ][ $ik ] = $i;
			}
		}
	}

	/**
	 * Nested: the parent id the builder gives top-level elements (a value in the document), or ''.
	 *
	 * @param array $d Document.
	 * @return string
	 */
	private function top_parent( array $d ) {
		$path = (string) ( ( (array) ( $this->t( 'links' ) ?? array() ) )['top_parent'] ?? '' );
		$v    = $d;
		foreach ( '' === $path ? array() : explode( '.', $path ) as $p ) {
			if ( ! is_array( $v ) || ! isset( $v[ $p ] ) ) {
				return '';
			}
			$v = $v[ $p ];
		}
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * Nested: whether an element holds a code element.
	 *
	 * @param array  $el   Element.
	 * @param string $kind Kind.
	 * @return bool
	 */
	private function has_code( array $el, $kind ) {
		$n = $this->nest( $kind );
		foreach ( $n ? (array) ( $el[ $n[0] ] ?? array() ) : array() as $c ) {
			if ( is_array( $c ) && ( $this->code_el( $c ) || $this->has_code( $c, $this->kind_in( $c, $n[1] ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Nested: ancestor ids of an element (and itself).
	 *
	 * @param array  $root Root list.
	 * @param string $id   Id.
	 * @return string[]
	 */
	private function ancestors( array $root, $id ) {
		$hit = &$this->find( $root, (string) $this->t( 'root_kind' ), $id );
		return null === $hit ? array() : array_merge( $hit[3], array( $id ) );
	}

	/**
	 * Nested: the kind of an element, or null.
	 *
	 * @param array  $root Root list.
	 * @param string $id   Id.
	 * @return string|null
	 */
	private function kind_at( array $root, $id ) {
		$hit = &$this->find( $root, (string) $this->t( 'root_kind' ), $id );
		return null === $hit ? null : (string) $hit[2];
	}

	/**
	 * Nested: a copy with fresh ids (and the old => new map).
	 *
	 * @param array  $el   Element.
	 * @param string $kind Kind.
	 * @param array  $map  Ids (filled).
	 * @return array
	 */
	private function reid( array $el, $kind, array &$map ) {
		$ik                         = (string) $this->t( 'id_key' );
		$new                        = Pack::new_id( (string) $this->t( 'id_format' ) );
		$map[ (string) $el[ $ik ] ] = $new;
		$el[ $ik ]                  = $new;
		$n                          = $this->nest( $kind );
		$pk                         = (string) ( ( (array) ( $this->t( 'links' ) ?? array() ) )['parent_key'] ?? '' );
		if ( $n && isset( $el[ $n[0] ] ) && is_array( $el[ $n[0] ] ) ) {
			foreach ( $el[ $n[0] ] as $i => $c ) {
				$c = $this->reid( (array) $c, $this->kind_in( (array) $c, $n[1] ), $map );
				if ( '' !== $pk && array_key_exists( $pk, $c ) ) {
					$c[ $pk ] = $new;
				}
				$el[ $n[0] ][ $i ] = $c;
			}
		}
		return $el;
	}

	/**
	 * Nested: a new element from a spec {type, settings, children} (builders that render from their data).
	 *
	 * @param array  $spec Spec.
	 * @param string $kind Kind it will have.
	 * @return array|\WP_Error
	 */
	private function build_nested( array $spec, $kind ) {
		$tk = (string) $this->t( 'type_key' );
		$sk = $this->t( 'settings_key' );
		$el = array( (string) $this->t( 'id_key' ) => Pack::new_id( (string) $this->t( 'id_format' ) ) );
		if ( isset( $spec['type'] ) ) {
			$el[ $tk ] = (string) $spec['type'];
		}
		if ( $this->code_el( $el ) ) {
			return $this->code_error();
		}
		$set = array();
		foreach ( (array) ( $spec['settings'] ?? array() ) as $k => $v ) {
			if ( $this->code_key( (string) $k ) || $this->locked( (string) $k ) ) {
				return $this->code_error();
			}
			$set[ (string) $k ] = is_array( $v ) ? map_deep( $v, array( $this, 'kses_value' ) ) : $this->clean( (string) $k, $v );
		}
		if ( $sk ) {
			$el[ $sk ] = $set;
		} else {
			$el = array_merge( $el, $set );
		}
		$n = $this->nest( $kind );
		if ( $n ) {
			$el[ $n[0] ] = array();
			foreach ( (array) ( $spec['children'] ?? array() ) as $c ) {
				$x = $this->build_nested( (array) $c, $n[1] );
				if ( is_wp_error( $x ) ) {
					return $x;
				}
				$el[ $n[0] ][] = $x;
			}
		}
		return $el;
	}

	// ---------------------------------------------------------------- vocabulary, schema

	/**
	 * Nesting and element format for builder.read (from the pack).
	 *
	 * @return array
	 */
	public function vocabulary() {
		return array(
			'nesting' => (array) ( $this->p['nesting'] ?? array() ),
			'widgets' => array_values( array_map( 'strval', (array) ( $this->p['widgets'] ?? array() ) ) ),
			'element' => (string) ( $this->p['element_help'] ?? '' ),
		);
	}

	/**
	 * Element list from the pack (the builder defines its elements in its editor).
	 *
	 * @param string $type Type, or ''.
	 * @param bool   $all  Ignored.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		unset( $all );
		if ( '' === $type ) {
			return array(
				'builder' => $this->id(),
				'widgets' => array_map(
					static function ( $t ) {
						return array(
							'type'  => (string) $t,
							'title' => (string) $t,
						);
					},
					(array) ( $this->p['widgets'] ?? array() )
				),
				'note'    => 'Settings of each element: builder_read shows them on the page.',
			);
		}
		return new \WP_Error( 'presshelm_schema_unavailable', ( $this->p['name'] ?? $this->id() ) . ' defines its elements in its editor, not on the server; builder_read shows the settings of each ' . $type . ' on the page.' );
	}

	// ---------------------------------------------------------------- save, undo

	/**
	 * Puts every surface back exactly, then runs the pack's effects.
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
			$r = Pack::write( $s, $post_id, array_key_exists( $k, $d ) ? $d[ $k ] : null );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return Effects::run( (array) ( $this->p['effects'] ?? array() ), $post_id );
	}

	/**
	 * Saves the model (and HTML), copies sibling data of duplicated elements, runs the effects.
	 *
	 * @param int         $post_id Post id.
	 * @param array       $d       Document.
	 * @param string|null $html    New HTML (null = unchanged).
	 * @param array       $map     Old id => new id (duplicates).
	 * @return true|\WP_Error
	 */
	private function save( $post_id, array $d, $html, array $map = array() ) {
		$m   = $this->p['source']['model'];
		$enc = Pack::encode( $d, (string) $m['encoding'] );
		if ( null === $enc ) {
			return new \WP_Error( 'presshelm_payload', 'The page data could not be encoded' );
		}
		wp_save_post_revision( (int) $post_id );
		$r = Pack::write( $m['surface'], $post_id, $enc );
		if ( true === $r && null !== $html ) {
			$r = Pack::write( $this->p['source']['html']['surface'], $post_id, $html );
		}
		if ( true !== $r ) {
			return $r;
		}
		if ( $map ) {
			foreach ( (array) ( $this->p['source']['refs'] ?? array() ) as $ref ) {
				$cur = Pack::read( $ref['surface'], $post_id );
				if ( 'keyed_by_id' === ( $ref['mode'] ?? '' ) && is_array( $cur ) ) {
					foreach ( $map as $old => $new ) {
						if ( isset( $cur[ $old ] ) ) {
							$cur[ $new ] = map_deep(
								$cur[ $old ],
								static function ( $v ) use ( $map ) {
									return is_string( $v ) ? strtr( $v, $map ) : $v;
								}
							);
						}
					}
					Pack::write( $ref['surface'], $post_id, $cur );
				} elseif ( 'css_rules' === ( $ref['mode'] ?? '' ) && is_string( $cur ) && '' !== $cur ) {
					Pack::write( $ref['surface'], $post_id, $cur . $this->copy_css( $cur, $map ) );
				}
			}
		}
		return Effects::run( (array) ( $this->p['effects'] ?? array() ), $post_id );
	}

	/**
	 * Copies every top-level CSS statement that mentions an old id, with the new ids.
	 *
	 * @param string $css CSS.
	 * @param array  $map Old => new.
	 * @return string
	 */
	private function copy_css( $css, array $map ) {
		$add   = '';
		$depth = 0;
		$start = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] && --$depth <= 0 ) {
				$depth = 0;
				$stmt  = substr( $css, $start, $i + 1 - $start );
				$start = $i + 1;
				foreach ( $map as $old => $new ) {
					if ( false !== strpos( $stmt, (string) $old ) ) {
						$add .= strtr( $stmt, $map );
						break;
					}
				}
			}
		}
		return $add;
	}
}
