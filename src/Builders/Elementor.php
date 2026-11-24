<?php
/**
 * Elementor adapter (M3.9). Reads `_elementor_data`; changes go through Elementor's own
 * Document::save() (its sanitizing, CSS regeneration and cache clearing). Code-bearing parts are
 * never changed this way: the HTML widget, custom CSS and custom attributes (that would be live
 * code without the code safeguards).
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor.
 */
final class Elementor implements Adapter {

	const META = '_elementor_data';

	/** Widgets whose content is code. */
	const CODE_WIDGETS = array( 'html', 'shortcode' );

	/** Setting keys that carry code (CSS, attributes, custom JS). */
	const CODE_KEYS = '/(custom_css|_attributes|custom_attributes|_custom_js|^_element_custom)/i';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'elementor';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( '\\Elementor\\Plugin' ) && defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Built with Elementor?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_built( $post_id ) {
		// A page Elementor edits that has no elements yet (a blank page or form) is still built with it.
		return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Stored data.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function raw( $post_id ) {
		return (string) get_post_meta( (int) $post_id, self::META, true );
	}

	/**
	 * Elements as stored.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function elements( $post_id ) {
		$data = json_decode( $this->raw( $post_id ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Tree for the agent.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function tree( $post_id ) {
		return $this->walk( $this->elements( $post_id ), array() );
	}

	/**
	 * Compact tree.
	 *
	 * @param array $elements Elements.
	 * @param int[] $prefix   Path.
	 * @return array
	 */
	private function walk( array $elements, array $prefix ) {
		$out = array();
		foreach ( array_values( $elements ) as $i => $e ) {
			$path     = array_merge( $prefix, array( $i ) );
			$settings = array();
			foreach ( (array) ( $e['settings'] ?? array() ) as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$settings[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v;
				}
			}
			$out[] = array(
				'id'       => (string) ( $e['id'] ?? '' ),
				'type'     => (string) ( $e['elType'] ?? '' ),
				'widget'   => isset( $e['widgetType'] ) ? (string) $e['widgetType'] : null,
				'path'     => $path,
				'settings' => $settings,
				'children' => $this->walk( (array) ( $e['elements'] ?? array() ), $path ),
			);
		}
		return $out;
	}

	/**
	 * Merges settings into one element (found by id anywhere in the tree).
	 *
	 * @param array  $elements Elements (changed in place).
	 * @param string $id       Element id.
	 * @param array  $settings Settings.
	 * @return array|null The element before the change, or null if not found.
	 */
	private function merge( array &$elements, $id, array $settings ) {
		foreach ( $elements as &$e ) {
			if ( (string) ( $e['id'] ?? '' ) === $id ) {
				$before        = $e;
				$e['settings'] = array_merge( (array) ( $e['settings'] ?? array() ), $settings );
				return $before;
			}
			if ( ! empty( $e['elements'] ) ) {
				$found = $this->merge( $e['elements'], $id, $settings );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		unset( $e );
		return null;
	}

	/**
	 * Updates one element through Elementor.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Element id.
	 * @param array  $settings   Settings.
	 * @return true|\WP_Error
	 */
	public function update_element( $post_id, $element_id, array $settings ) {
		foreach ( array_keys( $settings ) as $k ) {
			if ( preg_match( self::CODE_KEYS, (string) $k ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, custom attributes and custom code are never changed through the page builder (they are live code)' );
			}
		}
		foreach ( $settings as $k => $v ) {
			if ( ! is_scalar( $v ) && ! is_array( $v ) && null !== $v ) {
				return new \WP_Error( 'presshelm_payload', 'Setting values must be text, numbers, true/false or lists' );
			}
			// Like block edits: no scripts or event handlers in text, even for admins with
			// unfiltered_html (code goes through the code safeguards, not the page builder).
			$settings[ $k ] = is_string( $v ) ? wp_kses_post( $v ) : ( is_array( $v ) ? wp_kses_post_deep( $v ) : $v );
		}
		$elements = $this->elements( $post_id );
		$before   = $this->merge( $elements, (string) $element_id, $settings );
		if ( null === $before ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read)' );
		}
		if ( in_array( (string) ( $before['widgetType'] ?? '' ), self::CODE_WIDGETS, true ) ) {
			return new \WP_Error( 'presshelm_builder_code', 'HTML and shortcode widgets are code; they are not changed through the page builder' );
		}
		return $this->save( $post_id, $elements );
	}

	/**
	 * Restores the stored data exactly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Stored data.
	 * @return true|\WP_Error
	 */
	public function restore( $post_id, $raw ) {
		// A page Elementor opened but never saved has no data yet: that is an empty page, not damage.
		$elements = '' === (string) $raw ? array() : json_decode( (string) $raw, true );
		if ( ! is_array( $elements ) ) {
			return new \WP_Error( 'presshelm_journal_corrupt', 'The saved Elementor data is damaged' );
		}
		$r = $this->save( $post_id, $elements );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// Byte-exact: Elementor may re-encode; the saved copy is the reference.
		if ( $this->raw( $post_id ) !== (string) $raw ) {
			update_post_meta( (int) $post_id, self::META, wp_slash( (string) $raw ) );
			$this->clear_css( $post_id );
		}
		return true;
	}

	/**
	 * Starts an empty page in Elementor (as "Edit with Elementor" does); the elements follow in change().
	 *
	 * @param int $post_id Post id.
	 * @return true|\WP_Error
	 */
	public function start( $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( (int) $post_id, false );
		if ( ! $document ) {
			return new \WP_Error( 'presshelm_not_found', 'Elementor cannot edit this kind of content' );
		}
		if ( method_exists( $document, 'set_is_built_with_elementor' ) ) {
			$document->set_is_built_with_elementor( true );
		} else {
			update_post_meta( (int) $post_id, '_elementor_edit_mode', 'builder' );
		}
		return true;
	}

	/**
	 * Undoes start(): the page is a plain, empty page again.
	 *
	 * @param int $post_id Post id.
	 * @return true
	 */
	public function unstart( $post_id ) {
		foreach ( array( '_elementor_edit_mode', self::META, '_elementor_version', '_elementor_pro_version', '_elementor_template_type', '_elementor_page_settings', '_elementor_css', '_elementor_page_assets', '_elementor_element_cache' ) as $key ) {
			delete_post_meta( (int) $post_id, $key );
		}
		// Elementor keeps a plain-text copy of the page in post_content; the page was empty before.
		wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => '',
			)
		);
		return true;
	}

	/**
	 * Saves through Elementor's document API.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $elements Elements.
	 * @return true|\WP_Error
	 */
	private function save( $post_id, array $elements ) {
		$document = \Elementor\Plugin::$instance->documents->get( (int) $post_id, false );
		if ( ! $document ) {
			return new \WP_Error( 'presshelm_not_found', 'Elementor could not open this page' );
		}
		wp_save_post_revision( (int) $post_id );
		$ok = $document->save( array( 'elements' => $elements ) );
		if ( ! $ok ) {
			return new \WP_Error( 'presshelm_builder_save', 'Elementor refused to save the page (permissions?)' );
		}
		return true;
	}

	/** What may contain what (Elementor's own nesting rules); null = the top level of the page. */
	const CHILDREN = array(
		''          => array( 'section', 'container' ),
		'section'   => array( 'column' ),
		'column'    => array( 'widget', 'section' ),
		'container' => array( 'container', 'widget' ),
		'widget'    => array(),
	);

	/** Most elements one inserted piece may contain. */
	const MAX_ELEMENTS = 200;

	/**
	 * Layout kind and element types for building new elements on this site.
	 *
	 * @return array
	 */
	public function vocabulary() {
		$e = \Elementor\Plugin::$instance;
		return array(
			'containers' => $this->containers(),
			'nesting'    => array(
				'top level' => $this->containers() ? 'section or container' : 'section',
				'section'   => 'column',
				'column'    => 'widget or inner section',
				'container' => 'container or widget',
			),
			'widgets'    => array_values( array_diff( array_keys( (array) $e->widgets_manager->get_widget_types() ), self::CODE_WIDGETS ) ),
			'element'    => 'Elements are Elementor JSON: {elType, widgetType (widgets only), settings, elements}. Ids are assigned by PressHelm. Copy setting names from existing elements (builder.read).',
		);
	}

	/**
	 * An element's controls as the editor sees them.
	 *
	 * @param \Elementor\Controls_Stack $w Widget or element type.
	 * @return array
	 */
	private function full_controls( $w ) {
		// Outside the editor Elementor registers controls without labels, options, tabs and style
		// settings (a front-end speed-up). For this read, the element's controls are built once as
		// the editor sees them, then the cache is dropped so pages render as before.
		$perf = '\\Elementor\\Core\\Frontend\\Performance';
		$flag = null;
		if ( class_exists( $perf ) && property_exists( $perf, 'is_frontend' ) ) {
			$flag = new \ReflectionProperty( $perf, 'is_frontend' );
			$flag->setAccessible( true );
			$was = $flag->getValue();
			$flag->setValue( null, false );
			\Elementor\Plugin::$instance->controls_manager->delete_stack( $w );
		}
		try {
			return (array) $w->get_controls();
		} finally {
			if ( $flag ) {
				$flag->setValue( null, $was );
				\Elementor\Plugin::$instance->controls_manager->delete_stack( $w );
			}
		}
	}

	/**
	 * Whether this site uses Elementor's flexbox containers.
	 *
	 * @return bool
	 */
	private function containers() {
		$x = \Elementor\Plugin::$instance->experiments ?? null;
		return $x && method_exists( $x, 'is_feature_active' ) ? (bool) $x->is_feature_active( 'container' ) : false;
	}

	/**
	 * Path (indexes) of an element, or null.
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Element id.
	 * @param int[]  $prefix   Path so far.
	 * @return int[]|null
	 */
	private function locate( array $elements, $id, array $prefix = array() ) {
		foreach ( array_values( $elements ) as $i => $e ) {
			if ( (string) ( $e['id'] ?? '' ) === $id ) {
				return array_merge( $prefix, array( $i ) );
			}
			$found = $this->locate( (array) ( $e['elements'] ?? array() ), $id, array_merge( $prefix, array( $i ) ) );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * The children list of the element at $path (the page's top level for an empty path).
	 *
	 * @param array $elements Elements (by reference).
	 * @param int[] $path     Path of the parent.
	 * @return array Reference to the list.
	 */
	private function &children( array &$elements, array $path ) {
		$ref = &$elements;
		foreach ( $path as $n => $i ) {
			if ( $n > 0 ) {
				$ref = &$ref['elements'];
			}
			$ref = &$ref[ $i ];
		}
		if ( $path ) {
			if ( ! isset( $ref['elements'] ) || ! is_array( $ref['elements'] ) ) {
				$ref['elements'] = array();
			}
			$ref = &$ref['elements'];
		}
		return $ref;
	}

	/**
	 * The element at a path.
	 *
	 * @param array $elements Elements.
	 * @param int[] $path     Path.
	 * @return array
	 */
	private function at( array $elements, array $path ) {
		$node = array( 'elements' => $elements );
		foreach ( $path as $i ) {
			$node = $node['elements'][ $i ];
		}
		return $node;
	}

	/**
	 * Validates a new element (and everything inside it), gives it fresh ids and filters its text.
	 *
	 * @param array  $el     Element.
	 * @param string $parent_type Parent type ('' = top level).
	 * @param int    $count  Elements so far (by reference).
	 * @return array|\WP_Error
	 */
	private function prepare( array $el, $parent_type, &$count ) {
		if ( ++$count > self::MAX_ELEMENTS ) {
			return new \WP_Error( 'presshelm_payload', 'Too many elements in one change (at most ' . self::MAX_ELEMENTS . ').' );
		}
		$type = (string) ( $el['elType'] ?? '' );
		// An inner section is a section inside a column (Elementor marks it isInner), not a widget.
		if ( in_array( strtolower( str_replace( '_', '-', ( 'widget' === $type ? (string) ( $el['widgetType'] ?? '' ) : $type ) ) ), array( 'inner-section', 'innersection' ), true ) ) {
			$type = 'section';
			unset( $el['widgetType'] );
		}
		if ( ! isset( self::CHILDREN[ $type ] ) || '' === $type ) {
			return new \WP_Error( 'presshelm_payload', 'elType must be section, column, container or widget.' );
		}
		if ( 'container' === $type && ! $this->containers() ) {
			return new \WP_Error( 'presshelm_payload', 'This site does not use Elementor containers; use sections and columns.' );
		}
		if ( ! in_array( $type, self::CHILDREN[ $parent_type ], true ) ) {
			return new \WP_Error( 'presshelm_builder_nesting', 'Elementor does not allow a ' . $type . ' ' . ( '' === $parent_type ? 'at the top level' : 'inside a ' . $parent_type ) . '.' );
		}
		$out = array(
			'id'       => \Elementor\Utils::generate_random_string(),
			'elType'   => $type,
			'settings' => array(),
			'elements' => array(),
		);
		if ( 'widget' === $type ) {
			$widget = (string) ( $el['widgetType'] ?? '' );
			if ( in_array( $widget, self::CODE_WIDGETS, true ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'HTML and shortcode widgets are code; they are not added through the page builder.' );
			}
			if ( '' === $widget || ! \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget ) ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown widget type "' . $widget . '" on this site (see builder.read vocabulary).' );
			}
			$out['widgetType'] = $widget;
		}
		if ( 'section' === $type && 'column' === $parent_type ) {
			$out['isInner'] = true;
		}
		$settings = $el['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			return new \WP_Error( 'presshelm_payload', 'settings must be an object.' );
		}
		foreach ( $settings as $k => $v ) {
			if ( preg_match( self::CODE_KEYS, (string) $k ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'Custom CSS, custom attributes and custom code are never set through the page builder.' );
			}
			$out['settings'][ $k ] = is_string( $v ) ? wp_kses_post( $v ) : ( is_array( $v ) ? wp_kses_post_deep( $v ) : $v );
		}
		foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
			$c = $this->prepare( (array) $child, $type, $count );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$out['elements'][] = $c;
		}
		return $out;
	}

	/**
	 * A deep copy with fresh ids.
	 *
	 * @param array $el Element.
	 * @return array
	 */
	private function copy( array $el ) {
		$el['id'] = \Elementor\Utils::generate_random_string();
		foreach ( (array) ( $el['elements'] ?? array() ) as $i => $child ) {
			$el['elements'][ $i ] = $this->copy( (array) $child );
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
		$elements = $this->elements( $post_id );
		$kind     = (string) ( $op['op'] ?? '' );
		$parent   = isset( $op['parent_id'] ) && '' !== (string) $op['parent_id'] ? (string) $op['parent_id'] : null;

		// Where an element goes: the parent's path and type.
		$target   = function ( array $elements ) use ( $parent ) {
			if ( null === $parent ) {
				return array( array(), '' );
			}
			$path = $this->locate( $elements, $parent );
			if ( null === $path ) {
				return new \WP_Error( 'presshelm_not_found', 'No parent element with that id on this page (see builder.read).' );
			}
			return array( $path, (string) ( $this->at( $elements, $path )['elType'] ?? '' ) );
		};
		$position = function ( array $siblings ) use ( $op ) {
			$n = isset( $op['position'] ) ? (int) $op['position'] : count( $siblings );
			return max( 0, min( $n, count( $siblings ) ) );
		};

		if ( 'insert' === $kind ) {
			$t = $target( $elements );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$count = 0;
			$el    = $this->prepare( (array) ( $op['element'] ?? array() ), $t[1], $count );
			if ( is_wp_error( $el ) ) {
				return $el;
			}
			$list = &$this->children( $elements, $t[0] );
			array_splice( $list, $position( $list ), 0, array( $el ) );
			unset( $list );
			return $this->save( $post_id, $elements );
		}

		$id   = (string) ( $op['element_id'] ?? '' );
		$path = $this->locate( $elements, $id );
		if ( null === $path ) {
			return new \WP_Error( 'presshelm_not_found', 'No element with that id on this page (see builder.read).' );
		}
		$node      = $this->at( $elements, $path );
		$own_index = (int) end( $path );
		$own_list  = array_slice( $path, 0, -1 );

		if ( 'duplicate' === $kind ) {
			if ( in_array( (string) ( $node['widgetType'] ?? '' ), self::CODE_WIDGETS, true ) ) {
				return new \WP_Error( 'presshelm_builder_code', 'HTML and shortcode widgets are code; they are not copied through the page builder.' );
			}
			$list = &$this->children( $elements, $own_list );
			array_splice( $list, $own_index + 1, 0, array( $this->copy( $node ) ) );
			unset( $list );
			return $this->save( $post_id, $elements );
		}
		if ( 'remove' === $kind || 'move' === $kind ) {
			$list = &$this->children( $elements, $own_list );
			array_splice( $list, $own_index, 1 );
			unset( $list );
			if ( 'move' === $kind ) {
				$t = $target( $elements ); // An element can't move into itself: it is no longer in the tree.
				if ( is_wp_error( $t ) ) {
					return $t;
				}
				$type = (string) ( $node['elType'] ?? '' );
				if ( ! in_array( $type, self::CHILDREN[ $t[1] ] ?? array(), true ) ) {
					return new \WP_Error( 'presshelm_builder_nesting', 'Elementor does not allow a ' . $type . ' ' . ( '' === $t[1] ? 'at the top level' : 'inside a ' . $t[1] ) . '.' );
				}
				if ( 'section' === $type ) {
					$node['isInner'] = 'column' === $t[1];
				}
				$list = &$this->children( $elements, $t[0] );
				array_splice( $list, $position( $list ), 0, array( $node ) );
				unset( $list );
			}
			return $this->save( $post_id, $elements );
		}
		return new \WP_Error( 'presshelm_payload', 'op must be insert, remove, move or duplicate.' );
	}

	/**
	 * Removes the page's generated CSS (Elementor rebuilds it on the next view).
	 *
	 * @param int $post_id Post id.
	 */
	private function clear_css( $post_id ) {
		if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( (int) $post_id )->delete();
		}
	}

	/** Control kinds that only arrange the editor panel (no stored value). */
	const UI_CONTROLS = array( 'section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'notice', 'deprecated_notice', 'alert', 'button', 'popover_toggle', 'hidden' );

	/** How values of common control kinds are stored (Elementor's own formats). */
	const VALUE_FORMATS = array(
		'text/textarea/wysiwyg' => 'string (wysiwyg: HTML)',
		'number'                => 'number',
		'select/choose'         => 'one of the option keys (string)',
		'switcher'              => '"yes" when on, "" when off',
		'color'                 => '"#rrggbb" (or a global color via __globals__)',
		'url'                   => '{"url": "https://...", "is_external": "on" or "", "nofollow": "on" or ""}',
		'media'                 => '{"id": attachment id, "url": "..."} (use a media library image)',
		'slider'                => '{"unit": "px", "%", "em"..., "size": number}',
		'dimensions'            => '{"unit": "px", "top": "10", "right": "10", "bottom": "10", "left": "10", "isLinked": true or false}',
		'icons'                 => '{"value": "fas fa-star", "library": "fa-solid"}',
		'repeater'              => 'array of rows, each an object of its fields (Elementor adds _id)',
		'gallery'               => 'array of {"id", "url"}',
		'responsive'            => 'settings marked responsive also exist as name_tablet and name_mobile',
	);

	/**
	 * Elementor's own element definitions, read live.
	 *
	 * @param string $type Widget type, or section/column/container, or ''.
	 * @param bool   $all  Also style and advanced settings.
	 * @return array|\WP_Error
	 */
	public function schema( $type, $all ) {
		$e = \Elementor\Plugin::$instance;
		if ( '' === $type ) {
			$out = array();
			foreach ( (array) $e->widgets_manager->get_widget_types() as $name => $w ) {
				if ( in_array( $name, self::CODE_WIDGETS, true ) ) {
					continue;
				}
				$out[] = array(
					'type'  => (string) $name,
					'title' => wp_strip_all_tags( (string) $w->get_title() ),
				);
			}
			return array(
				'builder'  => 'elementor',
				'elements' => $this->containers() ? array( 'container' ) : array( 'section', 'column' ),
				'widgets'  => $out,
			);
		}
		$w = in_array( $type, array( 'section', 'column', 'container' ), true ) ? $e->elements_manager->get_element_types( $type ) : $e->widgets_manager->get_widget_types( $type );
		if ( ! $w || in_array( $type, self::CODE_WIDGETS, true ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Elementor has no editable element type "' . $type . '" here (see the list without type).' );
		}
		$out = array();
		foreach ( $this->full_controls( $w ) as $name => $c ) {
			$kind = (string) ( $c['type'] ?? '' );
			if ( in_array( $kind, self::UI_CONTROLS, true ) || preg_match( self::CODE_KEYS, (string) $name ) ) {
				continue;
			}
			// Responsive copies (name_tablet, name_mobile, ...) are described by their base setting.
			// The desktop setting has no parent; each device copy names the previous one.
			if ( isset( $c['responsive'] ) && ! empty( $c['parent'] ) ) {
				continue;
			}
			$tab = (string) ( $c['tab'] ?? 'content' );
			if ( ! $all && 'content' !== $tab ) {
				continue;
			}
			$out[] = Schema::field( (string) $name, $kind, (array) $c, $tab, isset( $c['responsive'] ) || ! empty( $c['is_responsive'] ) );
		}
		return Schema::result( 'elementor', $type, wp_strip_all_tags( (string) $w->get_title() ), $out, $all, self::VALUE_FORMATS );
	}
}
