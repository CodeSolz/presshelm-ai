<?php
/**
 * Brizy adapter. A Brizy page keeps its layout as editor data (JSON: a tree of elements, each
 * {type, value: {_id, _styles, items?, …settings}}) in Brizy's post storage, and shows HTML that
 * Brizy's compiler made from it. A change edits the editor data through Brizy's own post model,
 * then asks Brizy to compile the page again with its own compiler (the path Brizy itself uses to
 * recompile pages after an update). If the compiler cannot be reached, nothing is kept.
 * Code elements (embed code, HTML) and custom CSS / attributes are never changed.
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Brizy.
 */
final class Brizy implements Adapter {

	/** Element types whose content is code. */
	const CODE_TYPES = array( 'EmbedCode', 'Html', 'WPShortcode', 'WPCustomShortcode', 'Embed' );

	/** Setting keys that carry code or raw attributes. */
	const CODE_KEYS = '/^(custom(CSS|Attributes|Html|JS)|customClassName|cssClass|code|html)$/i';

	/** Keys that are structure, not settings. */
	const STRUCTURE = array( '_id', '_styles', 'items', '_version', 'globalBlockId' );

	const MAX_NODES = 200;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'brizy';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( '\\Brizy_Editor_Post' ) && class_exists( '\\Brizy_Editor_Entity' );
	}

	/**
	 * Built with Brizy?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		return (bool) get_post_meta( (int) $post_id, 'brizy_enabled', true ) && '' !== $this->json( $post_id );
	}

	/** Brizy's stored keys for a page (all of them are put back on undo). */
	const META = array( 'brizy', 'brizy-compiled-sections', 'brizy-need-compile', 'brizy-post-compiler-version', 'brizy-post-plugin-version', 'brizy-post-editor-version', 'brizy_enabled' );

	/**
	 * Stored data (serialized, for the journal and the version hash).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id ) {
		$out = array();
		foreach ( self::META as $k ) {
			$v = get_post_meta( (int) $post_id, $k, false );
			if ( $v ) {
				$out[ $k ] = $v[0];
			}
		}
		return (string) maybe_serialize( $out );
	}

	/**
	 * The editor data (JSON), '' when missing.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function json( $post_id ) {
		try {
			$post = \Brizy_Editor_Post::get( (int) $post_id );
			return (string) $post->getEditorData( true );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * The editor data as an array ({items: [...]}).
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function data( $post_id ) {
		$d = json_decode( $this->json( $post_id ), true );
		if ( ! is_array( $d ) ) {
			return array( 'items' => array() );
		}
		if ( ! isset( $d['items'] ) || ! is_array( $d['items'] ) ) {
			$d['items'] = array();
		}
		return $d;
	}

	/**
	 * Tree for the agent.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		return $this->walk( $this->data( $post_id )['items'], array() );
	}

	/**
	 * Compact tree.
	 *
	 * @param array $items  Elements.
	 * @param int[] $prefix Path.
	 * @return array
	 */
	private function walk( array $items, array $prefix ) {
		$out = array();
		foreach ( array_values( $items ) as $i => $el ) {
			if ( ! is_array( $el ) || ! isset( $el['type'] ) ) {
				continue;
			}
			$v        = (array) ( $el['value'] ?? array() );
			$path     = array_merge( $prefix, array( $i ) );
			$settings = array();
			foreach ( $v as $k => $val ) {
				if ( in_array( $k, self::STRUCTURE, true ) || ! is_scalar( $val ) || '' === $val || count( $settings ) >= 40 ) {
					continue;
				}
				$settings[ $k ] = is_string( $val ) ? mb_substr( $val, 0, 300 ) : $val;
			}
			$out[] = array(
				'id'       => (string) ( $v['_id'] ?? '' ),
				'type'     => (string) $el['type'],
				'widget'   => (string) $el['type'],
				'global'   => ! empty( $v['globalBlockId'] ) || 'GlobalBlock' === $el['type'],
				'path'     => $path,
				'settings' => $settings,
				'children' => $this->walk( (array) ( $v['items'] ?? array() ), $path ),
			);
		}
		return $out;
	}

	/**
	 * Finds an element by id: a reference to its parent list and its index there.
	 *
	 * @param array  $items List (by reference).
	 * @param string $id    Element id.
	 * @param array  $chain Ancestors so far (types).
	 * @return array|null [&list, index, ancestors].
	 */
	private function &find( array &$items, $id, array $chain = array() ) {
		$none = null;
		foreach ( $items as $i => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['value']['_id'] ?? '' ) === $id ) {
				$hit = array( &$items, $i, $chain );
				return $hit;
			}
			if ( isset( $el['value']['items'] ) && is_array( $el['value']['items'] ) ) {
				$found = &$this->find( $el['value']['items'], $id, array_merge( $chain, array( $el ) ) );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return $none;
	}

	/**
	 * Refuses code elements and parts of global blocks (the element or an ancestor).
	 *
	 * @param array $el        Element.
	 * @param array $ancestors Ancestors.
	 * @return \WP_Error|null
	 */
	private function guard( array $el, array $ancestors ) {
		if ( in_array( (string) ( $el['type'] ?? '' ), self::CODE_TYPES, true ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'Embed code and HTML elements are code; they are not changed through the page builder.' );
		}
		foreach ( array_merge( $ancestors, array( $el ) ) as $a ) {
			if ( 'GlobalBlock' === ( $a['type'] ?? '' ) || ! empty( $a['value']['globalBlockId'] ) ) {
				return new \WP_Error( 'presshelm_builder_global', 'This element belongs to a global block; changing it would change every page that uses it. Edit it in Brizy\'s global blocks.' );
			}
		}
		return null;
	}

	/**
	 * Filters and checks settings (no structure or code keys; text like the editor).
	 *
	 * @param array $settings Settings.
	 * @return array|\WP_Error
	 */
	private function clean( array $settings ) {
		$out = array();
		foreach ( $settings as $k => $v ) {
			$k = (string) $k;
			if ( preg_match( self::CODE_KEYS, $k ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, attributes and code are never set through the page builder.' );
			}
			if ( in_array( $k, self::STRUCTURE, true ) ) {
				return new \WP_Error( 'presshelm_payload', '"' . $k . '" is part of the page structure, not a setting (use builder.change for structure).' );
			}
			if ( ! is_scalar( $v ) && ! is_array( $v ) && null !== $v ) {
				return new \WP_Error( 'presshelm_payload', 'Setting values must be text, numbers, true/false or lists' );
			}
			$out[ $k ] = is_string( $v ) ? wp_kses_post( $v ) : ( is_array( $v ) ? wp_kses_post_deep( $v ) : $v );
		}
		return $out;
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
		$data = $this->data( $post_id );
		$hit  = &$this->find( $data['items'], (string) $element_id );
		if ( null === $hit ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
		}
		$el    = &$hit[0][ $hit[1] ];
		$guard = $this->guard( $el, $hit[2] );
		if ( $guard ) {
			return $guard;
		}
		$clean = $this->clean( $settings );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$el['value'] = array_merge( (array) $el['value'], $clean );
		unset( $el );
		return $this->save( $post_id, $data );
	}

	/**
	 * A new element subtree from a spec: fresh ids for every element; code is refused.
	 *
	 * @param array $spec  {type, value: {...settings, items?}} (as builder.read shows elements).
	 * @param int   $count Elements so far.
	 * @return array|\WP_Error
	 */
	private function build( array $spec, &$count ) {
		if ( ++$count > self::MAX_NODES ) {
			return new \WP_Error( 'presshelm_payload', 'Too many elements in one change (at most ' . self::MAX_NODES . ').' );
		}
		$type = (string) ( $spec['type'] ?? '' );
		if ( ! preg_match( '/^[A-Z][A-Za-z0-9]{1,40}$/', $type ) ) {
			return new \WP_Error( 'presshelm_payload', 'Each element needs a Brizy type, e.g. Section, SectionItem, Wrapper, RichText, Button (see builder.read).' );
		}
		if ( in_array( $type, self::CODE_TYPES, true ) || 'GlobalBlock' === $type ) {
			return new \WP_Error( 'presshelm_builder_code', 'Embed code, HTML and global blocks are not added through the page builder.' );
		}
		$value    = (array) ( $spec['value'] ?? array() );
		$children = isset( $value['items'] ) && is_array( $value['items'] ) ? $value['items'] : array();
		unset( $value['items'], $value['_id'], $value['globalBlockId'] );
		$styles = isset( $value['_styles'] ) && is_array( $value['_styles'] ) ? array_values( array_map( 'strval', $value['_styles'] ) ) : null;
		unset( $value['_styles'] );
		$clean = $this->clean( $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$el = array(
			'type'  => $type,
			'value' => array_merge(
				array( '_styles' => $styles ? $styles : array( lcfirst( $type ) ) ),
				$clean,
				array( '_id' => $this->new_id() )
			),
		);
		if ( $children || isset( $spec['value']['items'] ) ) {
			$el['value']['items'] = array();
			foreach ( array_values( $children ) as $child ) {
				$c = $this->build( (array) $child, $count );
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				$el['value']['items'][] = $c;
			}
		}
		return $el;
	}

	/**
	 * A fresh element id in Brizy's format (12 lowercase letters).
	 *
	 * @return string
	 */
	private function new_id() {
		$s = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$s .= chr( 97 + random_int( 0, 25 ) );
		}
		return $s;
	}

	/**
	 * New ids for a copied subtree.
	 *
	 * @param array $el Element.
	 * @return array
	 */
	private function reid( array $el ) {
		if ( isset( $el['value'] ) && is_array( $el['value'] ) ) {
			$el['value']['_id'] = $this->new_id();
			if ( isset( $el['value']['items'] ) && is_array( $el['value']['items'] ) ) {
				$el['value']['items'] = array_map( array( $this, 'reid' ), $el['value']['items'] );
			}
		}
		return $el;
	}

	/**
	 * Insert, remove, move or duplicate one element.
	 *
	 * @param int   $post_id Post id.
	 * @param array $op      Operation.
	 * @return true|\WP_Error
	 */
	public function change( $post_id, array $op ) {
		$data = $this->data( $post_id );
		$kind = (string) ( $op['op'] ?? '' );
		$at   = function ( array $siblings ) use ( $op ) {
			$n = isset( $op['position'] ) ? (int) $op['position'] : count( $siblings );
			return max( 0, min( $n, count( $siblings ) ) );
		};

		if ( 'insert' === $kind ) {
			$count = 0;
			$el    = $this->build( (array) ( $op['element'] ?? array() ), $count );
			if ( is_wp_error( $el ) ) {
				return $el;
			}
			$parent_id = (string) ( $op['parent_id'] ?? '' );
			if ( '' === $parent_id ) {
				if ( 0 !== strpos( $el['type'], 'Section' ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'At the top level of a Brizy page only sections are allowed (Section > SectionItem > …).' );
				}
				array_splice( $data['items'], $at( $data['items'] ), 0, array( $el ) );
				return $this->save( $post_id, $data );
			}
			$hit = &$this->find( $data['items'], $parent_id );
			if ( null === $hit ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			$parent = &$hit[0][ $hit[1] ];
			$guard  = $this->guard( $parent, $hit[2] );
			if ( $guard ) {
				return $guard;
			}
			if ( ! isset( $parent['value']['items'] ) || ! is_array( $parent['value']['items'] ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'A ' . $parent['type'] . ' cannot hold other elements.' );
			}
			if ( 0 === strpos( $el['type'], 'Section' ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'Sections only go at the top level of a page.' );
			}
			array_splice( $parent['value']['items'], $at( $parent['value']['items'] ), 0, array( $el ) );
			unset( $parent );
			return $this->save( $post_id, $data );
		}

		$id  = (string) ( $op['element_id'] ?? '' );
		$hit = &$this->find( $data['items'], $id );
		if ( null === $hit ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$el    = $hit[0][ $hit[1] ];
		$guard = $this->guard( $el, $hit[2] );
		if ( $guard ) {
			return $guard;
		}

		if ( 'duplicate' === $kind ) {
			array_splice( $hit[0], $hit[1] + 1, 0, array( $this->reid( $el ) ) );
			return $this->save( $post_id, $data );
		}
		if ( 'remove' === $kind ) {
			array_splice( $hit[0], $hit[1], 1 );
			return $this->save( $post_id, $data );
		}
		if ( 'move' === $kind ) {
			$target = (string) ( $op['parent_id'] ?? '' );
			if ( '' !== $target && ( $target === $id || null !== $this->find_in( $el, $target ) ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'An element cannot be moved into itself.' );
			}
			$is_section = 0 === strpos( (string) $el['type'], 'Section' );
			if ( ( '' === $target ) !== $is_section ) {
				return new \WP_Error( 'presshelm_builder_nesting', $is_section ? 'Sections stay at the top level of a page.' : 'Only sections go at the top level of a page.' );
			}
			array_splice( $hit[0], $hit[1], 1 );
			unset( $hit );
			if ( '' === $target ) {
				array_splice( $data['items'], $at( $data['items'] ), 0, array( $el ) );
				return $this->save( $post_id, $data );
			}
			$to = &$this->find( $data['items'], $target );
			if ( null === $to ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			$parent = &$to[0][ $to[1] ];
			$guard  = $this->guard( $parent, $to[2] );
			if ( $guard ) {
				return $guard;
			}
			if ( ! isset( $parent['value']['items'] ) || ! is_array( $parent['value']['items'] ) ) {
				return new \WP_Error( 'presshelm_builder_nesting', 'A ' . $parent['type'] . ' cannot hold other elements.' );
			}
			array_splice( $parent['value']['items'], $at( $parent['value']['items'] ), 0, array( $el ) );
			unset( $parent );
			return $this->save( $post_id, $data );
		}
		return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
	}

	/**
	 * Whether an element contains (at any depth) an element with this id.
	 *
	 * @param array  $el Element.
	 * @param string $id Id.
	 * @return array|null
	 */
	private function find_in( array $el, $id ) {
		$items = (array) ( $el['value']['items'] ?? array() );
		return $this->find( $items, $id );
	}

	/**
	 * Element types and nesting for building new elements on this site.
	 *
	 * @return array
	 */
	public function vocabulary() {
		return array(
			'nesting' => array(
				'top level'   => 'Section (also SectionHeader, SectionFooter)',
				'Section'     => 'SectionItem (one per slide; a normal section has one)',
				'SectionItem' => 'Wrapper (one element each), Row, Cloneable (buttons, icons)',
				'Row'         => 'Column',
				'Column'      => 'Wrapper, Row, Cloneable',
				'Wrapper'     => 'one content element: RichText, Image, Video, Spacer, Line, Form2, Map, …',
				'Cloneable'   => 'Button, Icon',
			),
			'widgets' => array( 'RichText', 'Button', 'Image', 'Icon', 'Spacer', 'Line', 'Video', 'Map', 'Form2', 'Counter', 'Countdown2', 'Tabs', 'Accordion', 'ImageGallery', 'IconText', 'Carousel' ),
			'element' => 'Elements: {type, value: {settings…, _styles, items}} exactly as builder.read shows them. The easiest safe way to add something is to duplicate a similar element and change its settings; PressHelm gives every new element a fresh id. Text lives in RichText "text" (HTML with Brizy\'s classes, e.g. <p class="brz-tp-paragraph">); a Button\'s label is "text" and its link "linkExternal" with "linkType":"external". After each change Brizy compiles the page again with its own compiler.',
		);
	}

	/**
	 * Brizy defines its elements in its editor (JavaScript), not on the server.
	 *
	 * @param string $type Type, or ''.
	 * @param bool   $all  Ignored.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		unset( $all );
		if ( '' === $type ) {
			return array(
				'builder'  => 'brizy',
				'elements' => array( 'Section', 'SectionItem', 'Row', 'Column', 'Wrapper', 'Cloneable' ),
				'widgets'  => array_map(
					static function ( $t ) {
						return array(
							'type'  => $t,
							'title' => $t,
						);
					},
					$this->vocabulary()['widgets']
				),
				'note'     => 'Brizy defines its elements in its editor (JavaScript), so their full settings cannot be read on the server. Copy setting names and values from existing elements (builder_read).',
			);
		}
		return new \WP_Error( 'presshelm_schema_unavailable', 'Brizy defines its elements in its editor, not on the server; copy setting names and values from an existing ' . $type . ' element (builder_read).' );
	}

	/**
	 * Puts Brizy's stored data back exactly (no compile needed: the compiled page is restored too).
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Serialized data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw ) {
		$data = maybe_unserialize( (string) $raw );
		if ( ! is_array( $data ) || ! isset( $data['brizy'] ) ) {
			return new \WP_Error( 'presshelm_journal_corrupt', 'The saved Brizy data is damaged' );
		}
		foreach ( self::META as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				update_post_meta( (int) $post_id, $k, wp_slash( $data[ $k ] ) );
			} else {
				delete_post_meta( (int) $post_id, $k );
			}
		}
		clean_post_cache( (int) $post_id );
		return true;
	}

	/**
	 * Saves the editor data through Brizy's post model, then compiles the page with Brizy's own
	 * compiler. If it does not compile, the previous data is put back.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Editor data.
	 * @return true|\WP_Error
	 */
	private function save( $post_id, array $data ) {
		$before = $this->raw( $post_id );
		$json   = wp_json_encode( $data );
		if ( ! $json ) {
			return new \WP_Error( 'presshelm_payload', 'The page data could not be encoded' );
		}
		try {
			wp_save_post_revision( (int) $post_id );
			$post = \Brizy_Editor_Post::get( (int) $post_id );
			$post->setEditorData( $json );
			$post->set_needs_compile( true );
			$post->set_compiler_version( '0.0.0' ); // Brizy compiles pages whose compiler version is older than its own.
			$post->saveStorage();
			$post = \Brizy_Editor_Post::get( (int) $post_id );
			$post->compile_page();
			$post = \Brizy_Editor_Post::get( (int) $post_id );
		} catch ( \Throwable $e ) {
			$this->restore( $post_id, $before );
			return new \WP_Error( 'presshelm_builder_save', 'Brizy could not save the page: ' . $e->getMessage() );
		}
		if ( $post->get_needs_compile() || '0.0.0' === (string) $post->get_compiler_version() ) {
			$this->restore( $post_id, $before );
			return new \WP_Error( 'presshelm_builder_compile', 'Brizy\'s compiler could not be reached, so the page was not changed. Try again later, or make the change in Brizy\'s editor.' );
		}
		return true;
	}
}
