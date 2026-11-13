<?php
/**
 * Site-structure abilities (M3.4b): classic menus (list, create, edit items), global styles
 * (block themes and themes with theme.json), and an allowlist of post fields (featured image,
 * SEO title/description of the active SEO plugin). Every change is journaled for undo.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Site-structure abilities.
 */
final class Site {

	const MAX_OPS = 20;

	/**
	 * Ability definitions (merged into Registry::all()).
	 *
	 * @return array
	 */
	public static function definitions() {
		$id   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$read = array(
			'readonly'   => true,
			'idempotent' => true,
		);
		$edit = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		return array(
			'menus.list'           => array(
				'risk'        => 'READ',
				'cap'         => 'edit_theme_options',
				'mutates'     => false,
				'run'         => array( self::class, 'menus_list' ),
				'label'       => 'List menus',
				'description' => 'Classic menus with their items (id, title, link, parent, order) and the theme\'s menu locations.',
				'input'       => null,
				'annotations' => $read,
				'undo'        => null,
			),
			'menus.create'         => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_theme_options',
				'mutates'     => true,
				'run'         => array( self::class, 'menus_create' ),
				'label'       => 'Create a menu',
				'description' => 'Creates an empty classic menu, optionally shown in one of the theme\'s menu locations. Undo deletes it and restores the location.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'     => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'location' => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'menus.update'         => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_theme_options',
				'mutates'     => true,
				'run'         => array( self::class, 'menus_update' ),
				'label'       => 'Change menu items',
				'description' => 'Up to 20 operations on one menu: add (page/post/custom link), rename, move, remove (children move up). Undo restores the whole menu exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'menu_id' => $id,
						'ops'     => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_OPS,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'op'        => array(
										'type' => 'string',
										'enum' => array( 'add', 'rename', 'move', 'remove' ),
									),
									'item_id'   => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'type'      => array(
										'type' => 'string',
										'enum' => array( 'page', 'post', 'custom' ),
									),
									'object_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'url'       => array(
										'type'      => 'string',
										'maxLength' => 2000,
									),
									'title'     => array(
										'type'      => 'string',
										'maxLength' => 200,
									),
									'parent'    => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'position'  => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
								),
								'required'             => array( 'op' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'menu_id', 'ops' ),
					'additionalProperties' => false,
				),
				'annotations' => $edit,
				'undo'        => 'journal.restore',
			),
			'styles.global.get'    => array(
				'risk'        => 'READ',
				'cap'         => 'edit_theme_options',
				'mutates'     => false,
				'run'         => array( self::class, 'styles_get' ),
				'label'       => 'Read global styles',
				'description' => 'The site\'s own global styles (theme.json user settings and styles) for the active theme, if the theme supports them.',
				'input'       => null,
				'annotations' => $read,
				'undo'        => null,
			),
			'styles.global.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_theme_options',
				'mutates'     => true,
				'run'         => array( self::class, 'styles_update' ),
				'label'       => 'Change global styles',
				'description' => 'Merges a partial change into the global styles (objects key by key, null removes a key). Design values only (no CSS code). Block themes and themes with theme.json only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'settings' => array( 'type' => 'object' ),
						'styles'   => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'annotations' => $edit,
				'undo'        => 'journal.restore',
			),
			'content.meta.get'     => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'meta_get' ),
				'label'       => 'Read SEO fields and featured image',
				'description' => 'Featured image and the SEO title/description used by the active SEO plugin (Yoast, Rank Math or SEOPress).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'content.meta.update'  => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'meta_update' ),
				'label'       => 'Change SEO fields or featured image',
				'description' => 'Sets the featured image (attachment id; 0 removes it) and/or the SEO title/description of the active SEO plugin.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'              => $id,
						'featured_image'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'seo_title'       => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'seo_description' => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $edit,
				'undo'        => 'journal.restore',
			),
		);
	}

	// ---------------------------------------------------------------- menus

	/**
	 * Menus and locations.
	 *
	 * @return array
	 */
	public static function menus_list() {
		$locations = get_nav_menu_locations();
		$menus     = array();
		foreach ( wp_get_nav_menus() as $m ) {
			$menus[] = array(
				'id'        => (int) $m->term_id,
				'name'      => $m->name,
				'locations' => array_keys( array_filter( $locations, static fn( $id ) => (int) $id === (int) $m->term_id ) ),
				'items'     => self::items( (int) $m->term_id ),
			);
		}
		return array(
			'menus'     => $menus,
			'locations' => get_registered_nav_menus(),
		);
	}

	/**
	 * Creates a menu (optionally in a location).
	 *
	 * @param array  $p          {name, location?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function menus_create( array $p, $command_id ) {
		$location = (string) ( $p['location'] ?? '' );
		if ( '' !== $location && ! isset( get_registered_nav_menus()[ $location ] ) ) {
			return new \WP_Error( 'presshelm_location', 'The theme has no menu location named ' . sanitize_key( $location ) );
		}
		$id = wp_create_nav_menu( sanitize_text_field( (string) $p['name'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$journal = Journal::record( $command_id, 'menu_created', (string) $id, array( 'locations' => get_nav_menu_locations() ) );
		if ( '' !== $location ) {
			$locs              = get_nav_menu_locations();
			$locs[ $location ] = (int) $id;
			set_theme_mod( 'nav_menu_locations', $locs );
		}
		return array(
			'menu_id'    => (int) $id,
			'location'   => '' !== $location ? $location : null,
			'journal_id' => $journal,
		);
	}

	/**
	 * Applies item operations to one menu (the whole menu is journaled first).
	 *
	 * @param array  $p          {menu_id, ops}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function menus_update( array $p, $command_id ) {
		$menu = wp_get_nav_menu_object( (int) $p['menu_id'] );
		if ( ! $menu ) {
			return new \WP_Error( 'presshelm_not_found', 'Menu not found' );
		}
		$menu_id = (int) $menu->term_id;
		$ops     = array_slice( (array) $p['ops'], 0, self::MAX_OPS );
		// Validate everything before changing anything.
		$known = wp_list_pluck( self::items( $menu_id ), 'id' );
		foreach ( $ops as $n => $op ) {
			$what = (string) ( $op['op'] ?? '' );
			if ( in_array( $what, array( 'rename', 'move', 'remove' ), true ) && ! in_array( (int) ( $op['item_id'] ?? 0 ), $known, true ) ) {
				return new \WP_Error( 'presshelm_not_found', 'Operation ' . (int) ( $n + 1 ) . ': no such item in this menu' );
			}
			if ( 'add' === $what ) {
				$type = (string) ( $op['type'] ?? '' );
				if ( 'custom' === $type && ! wp_http_validate_url( (string) ( $op['url'] ?? '' ) ) && 0 !== strpos( (string) ( $op['url'] ?? '' ), '/' ) && 0 !== strpos( (string) ( $op['url'] ?? '' ), '#' ) ) {
					return new \WP_Error( 'presshelm_payload', 'Operation ' . (int) ( $n + 1 ) . ': a custom link needs an http(s) URL, a /path or a #anchor' );
				}
				if ( in_array( $type, array( 'page', 'post' ), true ) && get_post_type( (int) ( $op['object_id'] ?? 0 ) ) !== $type ) {
					return new \WP_Error( 'presshelm_payload', 'Operation ' . (int) ( $n + 1 ) . ': object_id must be a ' . $type );
				}
				if ( ! in_array( $type, array( 'page', 'post', 'custom' ), true ) ) {
					return new \WP_Error( 'presshelm_payload', 'Operation ' . (int) ( $n + 1 ) . ': type must be page, post or custom' );
				}
			}
		}
		$journal = Journal::record( $command_id, 'menu', (string) $menu_id, array( 'items' => self::items( $menu_id, true ) ) );
		$added   = array();
		foreach ( $ops as $op ) {
			switch ( $op['op'] ) {
				case 'add':
					$args = array(
						'menu-item-title'     => sanitize_text_field( (string) ( $op['title'] ?? '' ) ),
						'menu-item-parent-id' => (int) ( $op['parent'] ?? 0 ),
						'menu-item-position'  => (int) ( $op['position'] ?? 0 ),
						'menu-item-status'    => 'publish',
					);
					if ( 'custom' === $op['type'] ) {
						$args['menu-item-type'] = 'custom';
						$args['menu-item-url']  = esc_url_raw( (string) $op['url'] );
					} else {
						$args['menu-item-type']      = 'post_type';
						$args['menu-item-object']    = $op['type'];
						$args['menu-item-object-id'] = (int) $op['object_id'];
					}
					$new = wp_update_nav_menu_item( $menu_id, 0, $args );
					if ( is_wp_error( $new ) ) {
						return $new;
					}
					$added[] = (int) $new;
					break;
				case 'rename':
					self::update_item( $menu_id, (int) $op['item_id'], array( 'menu-item-title' => sanitize_text_field( (string) ( $op['title'] ?? '' ) ) ) );
					break;
				case 'move':
					$changes = array( 'menu-item-position' => (int) ( $op['position'] ?? 1 ) );
					if ( isset( $op['parent'] ) ) {
						$changes['menu-item-parent-id'] = (int) $op['parent'];
					}
					self::update_item( $menu_id, (int) $op['item_id'], $changes );
					break;
				case 'remove':
					$gone   = (int) $op['item_id'];
					$parent = (int) get_post_meta( $gone, '_menu_item_menu_item_parent', true );
					foreach ( self::items( $menu_id ) as $item ) {
						if ( $item['parent'] === $gone ) {
							self::update_item( $menu_id, $item['id'], array( 'menu-item-parent-id' => $parent ) );
						}
					}
					wp_delete_post( $gone, true );
					break;
			}
		}
		return array(
			'menu_id'    => $menu_id,
			'added'      => $added,
			'items'      => self::items( $menu_id ),
			'journal_id' => $journal,
		);
	}

	/**
	 * Puts a menu back exactly as journaled (used by Journal::restore for kind `menu`).
	 *
	 * @param int   $menu_id Menu id.
	 * @param array $items   Journaled items (with all fields).
	 */
	public static function restore_menu( $menu_id, array $items ) {
		$keep = array();
		$map  = array();
		// Existing items are updated, missing ones recreated (new ids, mapped).
		foreach ( $items as $it ) {
			$args = self::args_from( $it, 0 );
			$post = get_post( (int) $it['id'] );
			if ( $post && 'nav_menu_item' === $post->post_type ) {
				wp_update_nav_menu_item( $menu_id, (int) $it['id'], $args );
				$map[ (int) $it['id'] ] = (int) $it['id'];
			} else {
				$map[ (int) $it['id'] ] = (int) wp_update_nav_menu_item( $menu_id, 0, $args );
			}
			$keep[] = $map[ (int) $it['id'] ];
		}
		// Parents, with recreated ids mapped.
		foreach ( $items as $it ) {
			$parent = (int) $it['parent'];
			update_post_meta( $map[ (int) $it['id'] ], '_menu_item_menu_item_parent', (string) ( $parent ? ( $map[ $parent ] ?? 0 ) : 0 ) );
		}
		// Items added after the journal entry go away.
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			if ( ! in_array( (int) $item->ID, $keep, true ) ) {
				wp_delete_post( (int) $item->ID, true );
			}
		}
	}

	/**
	 * Items of a menu.
	 *
	 * @param int  $menu_id Menu id.
	 * @param bool $full    Include every field (for the journal).
	 * @return array
	 */
	public static function items( $menu_id, $full = false ) {
		$out = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $i ) {
			$row = array(
				'id'        => (int) $i->ID,
				'title'     => (string) $i->title,
				'type'      => (string) $i->type,
				'object'    => (string) $i->object,
				'object_id' => (int) $i->object_id,
				'url'       => (string) $i->url,
				'parent'    => (int) $i->menu_item_parent,
				'order'     => (int) $i->menu_order,
			);
			if ( $full ) {
				$row += array(
					'target'      => (string) $i->target,
					'attr_title'  => (string) $i->attr_title,
					'description' => (string) $i->description,
					'classes'     => implode( ' ', array_filter( (array) $i->classes ) ),
					'xfn'         => (string) $i->xfn,
				);
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Updates some fields of an item, keeping the others.
	 *
	 * @param int   $menu_id Menu id.
	 * @param int   $item_id Item id.
	 * @param array $changes wp_update_nav_menu_item() args to change.
	 */
	private static function update_item( $menu_id, $item_id, array $changes ) {
		foreach ( self::items( $menu_id, true ) as $it ) {
			if ( $it['id'] === $item_id ) {
				wp_update_nav_menu_item( $menu_id, $item_id, array_merge( self::args_from( $it, $it['parent'] ), $changes ) );
				return;
			}
		}
	}

	/**
	 * wp_update_nav_menu_item() args from a journaled/listed item.
	 *
	 * @param array $it     Item.
	 * @param int   $parent_id Parent id to set.
	 * @return array
	 */
	private static function args_from( array $it, $parent_id ) {
		return array(
			'menu-item-title'       => $it['title'],
			'menu-item-type'        => $it['type'],
			'menu-item-object'      => $it['object'],
			'menu-item-object-id'   => $it['object_id'],
			'menu-item-url'         => $it['url'],
			'menu-item-parent-id'   => (int) $parent_id,
			'menu-item-position'    => (int) $it['order'],
			'menu-item-target'      => $it['target'] ?? '',
			'menu-item-attr-title'  => $it['attr_title'] ?? '',
			'menu-item-description' => $it['description'] ?? '',
			'menu-item-classes'     => $it['classes'] ?? '',
			'menu-item-xfn'         => $it['xfn'] ?? '',
			'menu-item-status'      => 'publish',
		);
	}

	// ---------------------------------------------------------------- global styles

	/**
	 * Whether the active theme has global styles.
	 *
	 * @return bool
	 */
	private static function styles_supported() {
		return wp_is_block_theme() || wp_theme_has_theme_json();
	}

	/**
	 * The user global styles.
	 *
	 * @return array|\WP_Error
	 */
	public static function styles_get() {
		if ( ! self::styles_supported() ) {
			return new \WP_Error( 'presshelm_not_supported', 'The active theme (' . get_stylesheet() . ') is a classic theme without theme.json, so it has no global styles. Use Additional CSS instead.' );
		}
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$data = isset( $post['post_content'] ) ? json_decode( (string) $post['post_content'], true ) : array();
		return array(
			'theme'    => get_stylesheet(),
			'settings' => (object) ( $data['settings'] ?? array() ),
			'styles'   => (object) ( $data['styles'] ?? array() ),
		);
	}

	/**
	 * First place in a global-styles change that holds CSS code instead of a design value: a "css" key
	 * (custom CSS) or a value with braces or tags. Empty when there is none.
	 *
	 * @param mixed  $value Change (part).
	 * @param string $path  Where it is.
	 * @return string
	 */
	private static function styles_code( $value, $path = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$at = '' === $path ? (string) $k : $path . '.' . $k;
				if ( 'css' === strtolower( (string) $k ) ) {
					return $at;
				}
				$bad = self::styles_code( $v, $at );
				if ( '' !== $bad ) {
					return $bad;
				}
			}
			return '';
		}
		return is_string( $value ) && preg_match( '/[<>{}]/', $value ) ? $path : '';
	}

	/**
	 * Merges a change into the user global styles (journaled).
	 *
	 * @param array  $p          {settings?, styles?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function styles_update( array $p, $command_id ) {
		if ( ! self::styles_supported() ) {
			return self::styles_get();
		}
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		if ( empty( $post['ID'] ) ) {
			return new \WP_Error( 'presshelm_styles', 'Could not open the global styles of this theme' );
		}
		$bad = self::styles_code( array_intersect_key( $p, array_flip( array( 'settings', 'styles' ) ) ) );
		if ( '' !== $bad ) {
			return new \WP_Error( 'presshelm_payload', 'Global styles take design values only (colours, fonts, sizes, spacing), not CSS code: ' . $bad );
		}
		$current = json_decode( (string) $post['post_content'], true );
		try {
			$next = StylesMerge::apply( is_array( $current ) ? $current : array(), array_intersect_key( $p, array_flip( array( 'settings', 'styles' ) ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'presshelm_payload', $e->getMessage() );
		}
		$journal = Journal::record(
			$command_id,
			'post',
			(string) $post['ID'],
			array(
				'post_title'   => (string) $post['post_title'],
				'post_content' => (string) $post['post_content'],
				'post_status'  => (string) $post['post_status'],
				'post_excerpt' => '',
			)
		);
		$r       = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post['ID'],
					'post_content' => (string) wp_json_encode( $next ),
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		return array(
			'theme'      => get_stylesheet(),
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- post fields

	/**
	 * Meta keys of the active SEO plugin, or null.
	 *
	 * @return array{plugin:string,title:string,description:string}|null
	 */
	public static function seo_keys() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array(
				'plugin'      => 'yoast',
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
			);
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return array(
				'plugin'      => 'rank-math',
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
			);
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return array(
				'plugin'      => 'seopress',
				'title'       => '_seopress_titles_title',
				'description' => '_seopress_titles_desc',
			);
		}
		return null;
	}

	/**
	 * Featured image and SEO fields.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function meta_get( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$seo   = self::seo_keys();
		$thumb = (int) get_post_thumbnail_id( $post );
		return array(
			'id'              => $post->ID,
			'featured_image'  => $thumb ? $thumb : null,
			'seo_plugin'      => $seo ? $seo['plugin'] : null,
			'seo_title'       => $seo ? (string) get_post_meta( $post->ID, $seo['title'], true ) : null,
			'seo_description' => $seo ? (string) get_post_meta( $post->ID, $seo['description'], true ) : null,
		);
	}

	/**
	 * Sets featured image and/or SEO fields (journaled together).
	 *
	 * @param array  $p          Input.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function meta_update( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$seo     = self::seo_keys();
		$changes = array();
		if ( isset( $p['seo_title'] ) || isset( $p['seo_description'] ) ) {
			if ( ! $seo ) {
				return new \WP_Error( 'presshelm_no_seo_plugin', 'No supported SEO plugin (Yoast, Rank Math, SEOPress) is active, so there is no SEO title/description to set.' );
			}
			if ( isset( $p['seo_title'] ) ) {
				$changes[ $seo['title'] ] = sanitize_text_field( (string) $p['seo_title'] );
			}
			if ( isset( $p['seo_description'] ) ) {
				$changes[ $seo['description'] ] = sanitize_textarea_field( (string) $p['seo_description'] );
			}
		}
		if ( isset( $p['featured_image'] ) ) {
			$img = (int) $p['featured_image'];
			if ( $img && ! wp_attachment_is_image( $img ) ) {
				return new \WP_Error( 'presshelm_payload', 'featured_image must be the id of an image in the media library' );
			}
			$changes['_thumbnail_id'] = $img;
		}
		if ( ! $changes ) {
			return new \WP_Error( 'presshelm_payload', 'Nothing to change' );
		}
		$before = array();
		foreach ( array_keys( $changes ) as $key ) {
			$before[ $key ] = array(
				'exists' => metadata_exists( 'post', $post->ID, $key ),
				'value'  => get_post_meta( $post->ID, $key, true ),
			);
		}
		$journal = Journal::record( $command_id, 'post_meta', (string) $post->ID, $before );
		foreach ( $changes as $key => $value ) {
			if ( '_thumbnail_id' === $key && 0 === $value ) {
				delete_post_thumbnail( $post->ID );
			} elseif ( '_thumbnail_id' === $key ) {
				set_post_thumbnail( $post->ID, $value );
			} elseif ( 'yoast' === ( $seo['plugin'] ?? '' ) && class_exists( '\WPSEO_Meta' ) && 0 === strpos( $key, \WPSEO_Meta::$meta_prefix ) ) {
				\WPSEO_Meta::set_value( substr( $key, strlen( \WPSEO_Meta::$meta_prefix ) ), $value, $post->ID ); // Yoast's own API.
			} else {
				update_post_meta( $post->ID, $key, wp_slash( $value ) );
			}
		}
		return array(
			'id'         => $post->ID,
			'changed'    => array_keys( $changes ),
			'journal_id' => $journal,
		);
	}
}
