<?php
/**
 * Content abilities (M3.4a): list/create posts and pages, inspect and patch one Gutenberg block,
 * upload media, read an allowlist of site settings (read only: this plugin never changes site
 * settings). Every change is journaled for undo; risks are declared here and enforced by the
 * cloud's policy.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Content abilities.
 */
final class Content {

	const TYPES    = array( 'post', 'page' );
	const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * Site settings settings.get reports (name → schema). This plugin only reads them.
	 *
	 * @return array<string, array>
	 */
	public static function settings_allowlist() {
		return array(
			'blogname'                     => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'blogdescription'              => array(
				'type'      => 'string',
				'maxLength' => 300,
			),
			'timezone_string'              => array(
				'type'      => 'string',
				'maxLength' => 64,
			),
			'date_format'                  => array(
				'type'      => 'string',
				'maxLength' => 40,
			),
			'time_format'                  => array(
				'type'      => 'string',
				'maxLength' => 40,
			),
			'start_of_week'                => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 6,
			),
			'posts_per_page'               => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'permalink_structure'          => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'show_on_front'                => array(
				'type' => 'string',
				'enum' => array( 'posts', 'page' ),
			),
			'page_on_front'                => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'page_for_posts'               => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'default_comment_status'       => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'blog_public'                  => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			// Discussion (Settings → Discussion).
			'default_ping_status'          => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'require_name_email'           => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'comment_registration'         => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'close_comments_for_old_posts' => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'close_comments_days_old'      => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 3650,
			),
			'thread_comments'              => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'thread_comments_depth'        => array(
				'type'    => 'integer',
				'minimum' => 2,
				'maximum' => 10,
			),
			'page_comments'                => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'comments_per_page'            => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 200,
			),
			'default_comments_page'        => array(
				'type' => 'string',
				'enum' => array( 'newest', 'oldest' ),
			),
			'comment_order'                => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
			'comments_notify'              => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'moderation_notify'            => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'comment_moderation'           => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'comment_previously_approved'  => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'comment_max_links'            => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 100,
			),
			'moderation_keys'              => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
			'disallowed_keys'              => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
			'show_avatars'                 => array(
				'type' => 'integer',
				'enum' => array( 0, 1 ),
			),
			'avatar_rating'                => array(
				'type' => 'string',
				'enum' => array( 'G', 'PG', 'R', 'X' ),
			),
			'avatar_default'               => array(
				'type' => 'string',
				'enum' => array( 'mystery', 'blank', 'gravatar_default', 'identicon', 'wavatar', 'monsterid', 'retro', 'robohash' ),
			),
		);
	}

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
		$path = array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 10,
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
		$read = array(
			'readonly'   => true,
			'idempotent' => true,
		);
		return array(
			'content.list'        => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'list_items' ),
				'label'       => 'Find posts and pages',
				'description' => 'Posts or pages by type, status and search text (id, title, status, modified, link). Max 50.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'   => array(
							'type' => 'string',
							'enum' => self::TYPES,
						),
						'status' => array(
							'type' => 'string',
							'enum' => array_merge( self::STATUSES, array( 'any' ) ),
						),
						'search' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'content.create'      => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'create' ),
				'label'       => 'Create a post or page',
				'description' => 'Creates a post or page (draft unless a status is given; content filtered like the editor). Undo moves it to the trash.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'    => array(
							'type' => 'string',
							'enum' => self::TYPES,
						),
						'title'   => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 300,
						),
						'content' => array(
							'type'      => 'string',
							'maxLength' => 200000,
						),
						'excerpt' => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'status'  => array(
							'type' => 'string',
							'enum' => self::STATUSES,
						),
						'parent'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'type', 'title' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'content.duplicate'   => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'duplicate' ),
				'label'       => 'Duplicate a post or page',
				'description' => 'Copies a post or page as a new draft: content, excerpt, parent, order, template, featured image, categories, tags and other terms, and its meta (page-builder data included). Undo moves the copy to the trash.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => $id,
						'title'  => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 300,
						),
						'status' => array(
							'type' => 'string',
							'enum' => self::STATUSES,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'content.bulk_update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'bulk_update' ),
				'label'       => 'Change many posts or pages at once',
				'description' => 'Up to 100 posts/pages: status (draft, pending, private, publish, trash), comments open/closed, page template, parent, sticky, and terms to add or remove per taxonomy. Items that cannot be changed are listed, not touched. One undo puts every item back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'            => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => 100,
							'uniqueItems' => true,
							'items'       => $id,
						),
						'status'         => array(
							'type' => 'string',
							'enum' => array_merge( self::STATUSES, array( 'trash' ) ),
						),
						'comment_status' => array(
							'type' => 'string',
							'enum' => array( 'open', 'closed' ),
						),
						'template'       => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'parent'         => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'sticky'         => array( 'type' => 'boolean' ),
						'add_terms'      => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'     => 'array',
								'maxItems' => 50,
								'items'    => $id,
							),
						),
						'remove_terms'   => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'     => 'array',
								'maxItems' => 50,
								'items'    => $id,
							),
						),
					),
					'required'             => array( 'ids' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
			'blocks.inspect'      => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'blocks_inspect' ),
				'label'       => 'Inspect the blocks of a post or page',
				'description' => 'The Gutenberg block tree with paths (e.g. [2,0] = first inner block of the third block), names, attributes, a short text preview and each block\'s exact stored markup (copy it into blocks.patch and change only what is needed).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'blocks.patch'        => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'blocks_patch' ),
				'label'       => 'Change one block',
				'description' => 'Replaces the block at `path` with new block markup (one or more blocks), or removes it. Everything else in the post stays byte-identical, also for builders whose saved markup WordPress would write differently. Bound to the content version read (content_sha256).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'             => $id,
						'path'           => $path,
						'markup'         => array(
							'type'      => 'string',
							'maxLength' => 100000,
						),
						'remove'         => array( 'type' => 'boolean' ),
						'content_sha256' => array(
							'type'    => 'string',
							'pattern' => '^[a-fA-F0-9]{64}$',
						),
					),
					'required'             => array( 'id', 'path', 'content_sha256' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'media.upload'        => array(
				'risk'        => 'LOW',
				'cap'         => 'upload_files',
				'mutates'     => true,
				'run'         => array( self::class, 'media_upload' ),
				'skip_health' => true,
				'label'       => 'Add an image or file to the media library',
				'description' => 'From a file the owner sent in Telegram (inbox_id, fetched from PressHelm Cloud over the signed connection), a public https URL or base64 data, with alt text and title. Only file types WordPress allows. Undo deletes it.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'inbox_id' => array(
							'type'    => 'string',
							'pattern' => '^inb_[A-Za-z0-9_-]{8,40}$',
						),
						'url'      => array(
							'type'      => 'string',
							'maxLength' => 2000,
							'pattern'   => '^https://',
						),
						'data'     => array(
							'type'      => 'string',
							'maxLength' => 14000000,
						),
						'filename' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'alt'      => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'title'    => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'settings.get'        => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'settings_get' ),
				'label'       => 'Read site settings',
				'description' => 'Title, tagline, timezone, date/time formats, permalinks, home page, reading defaults, search engine visibility, discussion settings (comments, moderation, avatars), and the site logo and icon.',
				'input'       => null,
				'annotations' => $read,
				'undo'        => null,
			),
		);
	}

	// ---------------------------------------------------------------- posts

	/**
	 * Finds posts/pages.
	 *
	 * @param array $p Input.
	 * @return array
	 */
	public static function list_items( array $p ) {
		$q   = new \WP_Query(
			array(
				'post_type'      => in_array( $p['type'] ?? '', self::TYPES, true ) ? $p['type'] : self::TYPES,
				'post_status'    => ( $p['status'] ?? 'any' ),
				's'              => (string) ( $p['search'] ?? '' ),
				'posts_per_page' => min( 50, max( 1, (int) ( $p['limit'] ?? 20 ) ) ),
				'orderby'        => 'modified',
				'no_found_rows'  => true,
				'perm'           => 'editable',
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			$out[] = array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'modified' => $post->post_modified_gmt,
				'link'     => get_permalink( $post ),
			);
		}
		return array( 'items' => $out );
	}

	/**
	 * Creates a post/page.
	 *
	 * @param array  $p          Input.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function create( array $p, $command_id ) {
		$type = in_array( $p['type'] ?? '', self::TYPES, true ) ? $p['type'] : 'post';
		$cap  = 'page' === $type ? 'edit_pages' : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to create this kind of content' );
		}
		$status = in_array( $p['status'] ?? '', self::STATUSES, true ) ? $p['status'] : 'draft';
		if ( 'publish' === $status && ! current_user_can( 'page' === $type ? 'publish_pages' : 'publish_posts' ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to publish' );
		}
		$lost = Registry::filter_losses( (string) ( $p['content'] ?? '' ) );
		if ( $lost ) {
			return new \WP_Error( 'presshelm_content_filtered', 'This content would lose ' . implode( ', ', $lost ) . ' (WordPress filters them out). Nothing was created. Leave those elements out.' );
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_title'   => sanitize_text_field( (string) $p['title'] ),
					'post_content' => wp_kses_post( (string) ( $p['content'] ?? '' ) ),
					'post_excerpt' => sanitize_textarea_field( (string) ( $p['excerpt'] ?? '' ) ),
					'post_status'  => $status,
					'post_parent'  => 'page' === $type ? (int) ( $p['parent'] ?? 0 ) : 0,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$journal = Journal::record( $command_id, 'post_created', (string) $id, array() );
		return array(
			'id'         => (int) $id,
			'status'     => $status,
			'link'       => get_permalink( $id ),
			'journal_id' => $journal,
		);
	}

	/** Meta never copied to a duplicate (editing locks, old slugs, generated CSS). */
	const NO_COPY_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_elementor_css', '_encloseme', '_pingme' );

	/**
	 * Duplicates a post/page as a new draft (or the given status).
	 *
	 * @param array  $p          {id, title?, status?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function duplicate( array $p, $command_id ) {
		$src = get_post( (int) $p['id'] );
		if ( ! $src || ! in_array( $src->post_type, self::TYPES, true ) || 'trash' === $src->post_status ) {
			return new \WP_Error( 'presshelm_not_found', 'No post or page with that id.' );
		}
		if ( ! current_user_can( 'edit_post', $src->ID ) || ! current_user_can( 'page' === $src->post_type ? 'edit_pages' : 'edit_posts' ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to copy this item' );
		}
		$status = in_array( $p['status'] ?? '', self::STATUSES, true ) ? $p['status'] : 'draft';
		if ( 'publish' === $status && ! current_user_can( 'page' === $src->post_type ? 'publish_pages' : 'publish_posts' ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to publish' );
		}
		/* translators: %s: the original title. */
		$title = isset( $p['title'] ) ? sanitize_text_field( (string) $p['title'] ) : sprintf( __( '%s (copy)', 'presshelm-ai' ), $src->post_title );
		$id    = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $src->post_type,
					'post_title'     => $title,
					'post_content'   => $src->post_content,
					'post_excerpt'   => $src->post_excerpt,
					'post_status'    => $status,
					'post_parent'    => (int) $src->post_parent,
					'menu_order'     => (int) $src->menu_order,
					'comment_status' => $src->comment_status,
					'ping_status'    => $src->ping_status,
					'post_password'  => $src->post_password,
					'post_author'    => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$journal = Journal::record( $command_id, 'post_created', (string) $id, array() );
		$copied  = 0;
		foreach ( (array) get_post_meta( $src->ID ) as $key => $values ) {
			if ( in_array( $key, self::NO_COPY_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) );
				++$copied;
			}
		}
		$terms = array();
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			$ids = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && $ids ) {
				wp_set_object_terms( $id, array_map( 'intval', $ids ), $tax );
				$terms[ $tax ] = count( $ids );
			}
		}
		return array(
			'id'          => (int) $id,
			'source_id'   => (int) $src->ID,
			'title'       => $title,
			'status'      => $status,
			'meta_copied' => $copied,
			'terms'       => $terms,
			'edit_link'   => get_edit_post_link( $id, 'raw' ),
			'link'        => get_permalink( $id ),
			'journal_id'  => $journal,
		);
	}

	/**
	 * Changes many posts/pages at once; one journal entry holds every item's previous state.
	 *
	 * @param array  $p          {ids, status?, comment_status?, template?, parent?, sticky?, add_terms?, remove_terms?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function bulk_update( array $p, $command_id ) {
		$changes = array_intersect_key( $p, array_flip( array( 'status', 'comment_status', 'template', 'parent', 'sticky', 'add_terms', 'remove_terms' ) ) );
		if ( ! $changes ) {
			return new \WP_Error( 'presshelm_nothing', 'Say what to change (status, comment_status, template, parent, sticky, add_terms or remove_terms).' );
		}
		$taxes = array_unique( array_merge( array_keys( (array) ( $p['add_terms'] ?? array() ) ), array_keys( (array) ( $p['remove_terms'] ?? array() ) ) ) );
		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) || ! current_user_can( get_taxonomy( $tax )->cap->assign_terms ) ) {
				return new \WP_Error( 'presshelm_taxonomy', 'Unknown taxonomy or not allowed to assign its terms: ' . $tax );
			}
			foreach ( array_merge( (array) ( $p['add_terms'][ $tax ] ?? array() ), (array) ( $p['remove_terms'][ $tax ] ?? array() ) ) as $term_id ) {
				if ( ! term_exists( (int) $term_id, $tax ) ) {
					return new \WP_Error( 'presshelm_term', 'No term ' . (int) $term_id . ' in ' . $tax . '.' );
				}
			}
		}
		$refused = array();
		$posts   = array();
		foreach ( array_unique( array_map( 'intval', (array) $p['ids'] ) ) as $pid ) {
			$post = get_post( $pid );
			$why  = null;
			if ( ! $post || ! in_array( $post->post_type, self::TYPES, true ) ) {
				$why = 'not a post or page';
			} elseif ( ! current_user_can( 'edit_post', $pid ) ) {
				$why = 'not allowed to edit it';
			} elseif ( isset( $p['status'] ) && 'publish' === $p['status'] && ! current_user_can( 'page' === $post->post_type ? 'publish_pages' : 'publish_posts' ) ) {
				$why = 'not allowed to publish it';
			} elseif ( isset( $p['status'] ) && 'trash' === $p['status'] && ! current_user_can( 'delete_post', $pid ) ) {
				$why = 'not allowed to delete it';
			} elseif ( isset( $p['parent'] ) && 'page' !== $post->post_type ) {
				$why = 'only pages have a parent';
			} elseif ( isset( $p['parent'] ) && (int) $p['parent'] === $pid ) {
				$why = 'a page cannot be its own parent';
			} elseif ( isset( $p['sticky'] ) && 'post' !== $post->post_type ) {
				$why = 'only posts can be sticky';
			} elseif ( isset( $p['template'] ) && 'default' !== $p['template'] && ! isset( wp_get_theme()->get_page_templates( null, $post->post_type )[ $p['template'] ] ) ) {
				$why = 'the theme has no template "' . $p['template'] . '" for this type';
			} elseif ( array_diff( $taxes, get_object_taxonomies( $post->post_type ) ) ) {
				$why = 'that taxonomy does not apply to this type';
			}
			if ( $why ) {
				$refused[] = array(
					'id'  => $pid,
					'why' => $why,
				);
				continue;
			}
			$posts[ $pid ] = $post;
		}
		if ( ! $posts ) {
			return new \WP_Error( 'presshelm_nothing_changed', 'None of these items can be changed: ' . wp_json_encode( $refused ) );
		}
		$before = array();
		foreach ( $posts as $pid => $post ) {
			$state = array(
				'status'         => $post->post_status,
				'comment_status' => $post->comment_status,
				'parent'         => (int) $post->post_parent,
				'template'       => (string) get_post_meta( $pid, '_wp_page_template', true ),
				'sticky'         => is_sticky( $pid ),
				'terms'          => array(),
			);
			foreach ( $taxes as $tax ) {
				$ids                    = wp_get_object_terms( $pid, $tax, array( 'fields' => 'ids' ) );
				$state['terms'][ $tax ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			}
			$before[ $pid ] = $state;
		}
		$journal = Journal::record( $command_id, 'posts_bulk', 'bulk', array( 'posts' => $before ) );
		$changed = 0;
		foreach ( $posts as $pid => $post ) {
			$update = array( 'ID' => $pid );
			if ( isset( $p['status'] ) && 'trash' !== $p['status'] ) {
				if ( 'trash' === $post->post_status ) {
					wp_untrash_post( $pid );
				}
				$update['post_status'] = $p['status'];
			}
			if ( isset( $p['comment_status'] ) ) {
				$update['comment_status'] = $p['comment_status'];
			}
			if ( isset( $p['parent'] ) ) {
				$update['post_parent'] = (int) $p['parent'];
			}
			if ( count( $update ) > 1 ) {
				$r = wp_update_post( wp_slash( $update ), true );
				if ( is_wp_error( $r ) ) {
					$refused[] = array(
						'id'  => $pid,
						'why' => $r->get_error_message(),
					);
					continue;
				}
			}
			if ( isset( $p['template'] ) ) {
				update_post_meta( $pid, '_wp_page_template', sanitize_text_field( (string) $p['template'] ) );
			}
			if ( isset( $p['sticky'] ) ) {
				$p['sticky'] ? stick_post( $pid ) : unstick_post( $pid );
			}
			foreach ( (array) ( $p['add_terms'] ?? array() ) as $tax => $ids ) {
				wp_set_object_terms( $pid, array_map( 'intval', (array) $ids ), $tax, true );
			}
			foreach ( (array) ( $p['remove_terms'] ?? array() ) as $tax => $ids ) {
				wp_remove_object_terms( $pid, array_map( 'intval', (array) $ids ), $tax );
			}
			if ( isset( $p['status'] ) && 'trash' === $p['status'] && 'trash' !== $post->post_status ) {
				wp_trash_post( $pid );
			}
			++$changed;
		}
		return array(
			'changed'    => $changed,
			'refused'    => $refused,
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- blocks

	/**
	 * Block tree.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function blocks_inspect( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$content = (string) $post->post_content;
		return array(
			'id'             => $post->ID,
			'content_sha256' => hash( 'sha256', $content ),
			'standard_form'  => self::round_trips( $content ),
			'builder'        => \PressHelm\Builders\Guard::builder_of( $post ),
			'blocks'         => self::with_markup( self::tree( parse_blocks( $content ), array() ), self::ranges( $content ), $content ),
		);
	}

	/**
	 * Replaces or removes one block; everything else stays byte-identical.
	 *
	 * @param array  $p          {id, path, markup?|remove?, content_sha256}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function blocks_patch( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$builder = \PressHelm\Builders\Guard::refuse_snippet( $post ) ?? \PressHelm\Builders\Guard::refuse_content_edit( $post, 'a block change' );
		if ( $builder ) {
			return $builder;
		}
		$content = (string) $post->post_content;
		if ( ! hash_equals( hash( 'sha256', $content ), strtolower( (string) $p['content_sha256'] ) ) ) {
			return new \WP_Error( 'presshelm_conflict', 'The post changed since it was read; inspect it again' );
		}
		$remove = ! empty( $p['remove'] );
		if ( ! $remove && ! isset( $p['markup'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Give the new block markup, or remove: true' );
		}
		$path = array_map( 'intval', (array) $p['path'] );
		if ( ! $remove ) {
			// Checked, not rewritten: the new markup may hold nothing WordPress filters out of edits
			// (tags, attributes, script addresses) beyond what this block already had (what its builder
			// wrote, e.g. GutenKit's identifier or an SVG's viewBox), and code the block already had
			// only byte for byte (never changed). It is then saved byte for byte.
			$bad = self::unsafe_markup( (string) $p['markup'], self::block_bytes( $content, $path ) );
			if ( $bad ) {
				return $bad;
			}
		}
		$updated = self::splice_bytes( $content, $path, $remove ? '' : (string) $p['markup'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return self::save_patched( $post, $content, $updated, $command_id );
	}

	/**
	 * Refusal when new block markup holds something WordPress filters out of edits that the block
	 * did not already have, or null.
	 *
	 * @param string $markup New markup.
	 * @param string $old    The block's stored markup.
	 * @return \WP_Error|null
	 */
	private static function unsafe_markup( $markup, $old ) {
		if ( Registry::new_code( $old, $markup ) ) {
			return new \WP_Error( 'presshelm_code', 'This block markup adds code or changes code the block already has (a script, style, PHP, iframe, event handler or script address). Nothing was changed. Keep existing code exactly as it is, or remove it.' );
		}
		$now = self::filtered_counts( $markup );
		if ( null === $now ) {
			return new \WP_Error( 'presshelm_payload', 'A block\'s settings are not valid JSON.' );
		}
		$had  = (array) self::filtered_counts( $old );
		$lost = array();
		foreach ( $now as $what => $n ) {
			$more = $n - (int) ( $had[ $what ] ?? 0 );
			if ( $more > 0 ) {
				$lost[] = $what . ' ×' . $more;
			}
		}
		return $lost ? new \WP_Error( 'presshelm_content_filtered', 'This block markup would lose ' . implode( ', ', $lost ) . ' (WordPress filters them out of edits). Nothing was changed. Leave those elements out, or keep the existing block.' ) : null;
	}

	/**
	 * What WordPress's edit filter would take out of block markup, counted: tags, attributes and
	 * script addresses, in the HTML and in the HTML held by block settings. Null for invalid settings.
	 *
	 * @param string $markup Block markup.
	 * @return array<string,int>|null
	 */
	private static function filtered_counts( $markup ) {
		$re     = '/<!--\s+\/?wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?\s+(?:{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?\/?-->/s';
		$pieces = array( (string) preg_replace( $re, ' ', (string) $markup ) );
		preg_match_all( $re, (string) $markup, $tokens );
		foreach ( $tokens[0] as $token ) {
			if ( ! preg_match( '/\s({.*})\s+\/?-->$/s', $token, $m ) ) {
				continue;
			}
			$attrs = json_decode( $m[1], true );
			if ( ! is_array( $attrs ) ) {
				return null;
			}
			array_walk_recursive(
				$attrs,
				static function ( $v ) use ( &$pieces ) {
					if ( is_string( $v ) && preg_match( '/[<:]/', $v ) ) {
						$pieces[] = $v;
					}
				}
			);
		}
		$out = array();
		foreach ( $pieces as $piece ) {
			foreach ( Registry::filter_losses( $piece ) as $l ) {
				if ( preg_match( '/^(.*) ×(\d+)$/', $l, $x ) ) {
					$out[ $x[1] ] = ( $out[ $x[1] ] ?? 0 ) + (int) $x[2];
				}
			}
			// Addresses with a protocol WordPress does not allow (javascript:, data:text/html …).
			preg_match_all( '/\s(?:href|src|action|formaction|xlink:href|data|poster|background|cite)\s*=\s*(["\'])(.*?)\1/is', $piece, $urls );
			foreach ( $urls[2] as $u ) {
				$u = html_entity_decode( (string) $u, ENT_QUOTES | ENT_HTML5 );
				if ( wp_kses_bad_protocol( $u, wp_allowed_protocols() ) !== $u ) {
					$out['script address'] = ( $out['script address'] ?? 0 ) + 1;
				}
			}
		}
		return $out;
	}

	/**
	 * The stored bytes of the block at a path, or ''.
	 *
	 * @param string $content Post content.
	 * @param int[]  $path    Block path.
	 * @return string
	 */
	private static function block_bytes( $content, array $path ) {
		$nodes = self::ranges( $content );
		$node  = null;
		foreach ( $path as $i ) {
			$node = $nodes[ $i ] ?? null;
			if ( ! $node || null === $node['end'] ) {
				return '';
			}
			$nodes = $node['children'];
		}
		return $node ? substr( $content, $node['start'], $node['end'] - $node['start'] ) : '';
	}

	/**
	 * Saves a block change (journaled, with a revision first).
	 *
	 * @param \WP_Post $post       Post.
	 * @param string   $content    Content before.
	 * @param string   $updated    Content after.
	 * @param string   $command_id Run id.
	 * @return array|\WP_Error
	 */
	private static function save_patched( $post, $content, $updated, $command_id ) {
		$journal = Journal::record(
			$command_id,
			'post',
			(string) $post->ID,
			array(
				'post_title'   => $post->post_title,
				'post_content' => $content,
				'post_status'  => $post->post_status,
				'post_excerpt' => $post->post_excerpt,
			)
		);
		wp_save_post_revision( $post->ID );
		$r = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $updated,
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'id'             => $post->ID,
			'content_sha256' => hash( 'sha256', $updated ),
			'journal_id'     => $journal,
		);
	}

	/**
	 * Byte ranges of the real blocks (nested), found with WordPress's own block-comment tokenizer.
	 *
	 * @param string $content Post content.
	 * @return array List of {start, end, children}.
	 */
	public static function ranges( $content ) {
		$re    = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
		$roots = array();
		$stack = array();
		$pos   = 0;
		while ( preg_match( $re, $content, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = (int) $m[0][1];
			$end   = $start + strlen( $m[0][0] );
			$pos   = $end;
			if ( ! empty( $m['void'][0] ) && '' !== $m['void'][0] ) {
				$node = array(
					'start'    => $start,
					'end'      => $end,
					'children' => array(),
				);
				if ( $stack ) {
					$stack[ count( $stack ) - 1 ]['children'][] = $node;
				} else {
					$roots[] = $node;
				}
				continue;
			}
			if ( empty( $m['closer'][0] ) || '' === $m['closer'][0] ) {
				$stack[] = array(
					'start'    => $start,
					'end'      => null,
					'children' => array(),
				);
				continue;
			}
			if ( ! $stack ) {
				continue; // A closer without an opener: WordPress ignores it too.
			}
			$node        = array_pop( $stack );
			$node['end'] = $end;
			if ( $stack ) {
				$stack[ count( $stack ) - 1 ]['children'][] = $node;
			} else {
				$roots[] = $node;
			}
		}
		return $roots;
	}

	/**
	 * Replaces (or removes) the bytes of the block at a path; checks the result still parses.
	 *
	 * @param string $content Post content.
	 * @param int[]  $path    Path (real blocks only).
	 * @param string $markup  New markup ('' removes the block).
	 * @return string|\WP_Error
	 */
	private static function splice_bytes( $content, array $path, $markup ) {
		$nodes = self::ranges( $content );
		$node  = null;
		foreach ( $path as $i ) {
			$node = $nodes[ $i ] ?? null;
			if ( ! $node || null === $node['end'] ) {
				return new \WP_Error( 'presshelm_block_not_found', 'No block at that path' );
			}
			$nodes = $node['children'];
		}
		if ( ! $node ) {
			return new \WP_Error( 'presshelm_block_not_found', 'No block at that path' );
		}
		if ( '' !== $markup ) {
			$new = array_filter(
				parse_blocks( $markup ),
				static function ( $b ) {
					return null !== $b['blockName'];
				}
			);
			if ( ! $new || count( self::ranges( $markup ) ) !== count( $new ) ) {
				return new \WP_Error( 'presshelm_payload', 'The new markup must be whole blocks (copy the block\'s markup from blocks.inspect and change it).' );
			}
		}
		$updated = substr( $content, 0, $node['start'] ) . $markup . substr( $content, $node['end'] );
		if ( count( self::ranges( $updated ) ) < 1 && '' !== trim( $updated ) && false !== strpos( $content, '<!-- wp:' ) && '' !== $markup ) {
			return new \WP_Error( 'presshelm_payload', 'The change would break the page\'s block structure.' );
		}
		return $updated;
	}

	/**
	 * Adds each block's exact stored markup to the inspect tree (by path).
	 *
	 * @param array  $tree    Tree from tree().
	 * @param array  $ranges  Ranges from ranges().
	 * @param string $content Post content.
	 * @return array
	 */
	private static function with_markup( array $tree, array $ranges, $content ) {
		foreach ( $tree as $i => $b ) {
			$r = $ranges[ $i ] ?? null;
			if ( $r && null !== $r['end'] ) {
				$raw                   = substr( $content, $r['start'], $r['end'] - $r['start'] );
				$tree[ $i ]['markup']  = strlen( $raw ) > 6000 ? null : $raw;
				$tree[ $i ]['content'] = self::with_markup( (array) $b['content'], $r['children'], $content );
			}
		}
		return $tree;
	}

	/**
	 * Whether serialize_blocks( parse_blocks( x ) ) === x (then untouched blocks stay byte-identical).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function round_trips( $content ) {
		return serialize_blocks( parse_blocks( $content ) ) === $content;
	}

	/**
	 * Replaces the block at a path with zero or more blocks.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $path   Path.
	 * @param array $replacement Replacement blocks.
	 * @return array|\WP_Error
	 */
	public static function splice( array $blocks, array $path, array $replacement ) {
		// Only real blocks are addressable; freeform whitespace between them is skipped.
		$real = array_keys(
			array_filter(
				$blocks,
				static function ( $b ) {
					return null !== $b['blockName'];
				}
			)
		);
		$i    = array_shift( $path );
		if ( ! isset( $real[ $i ] ) ) {
			return new \WP_Error( 'presshelm_block_not_found', 'No block at that path' );
		}
		$k = $real[ $i ];
		if ( ! $path ) {
			array_splice( $blocks, $k, 1, $replacement );
			return $blocks;
		}
		$inner = self::splice( (array) $blocks[ $k ]['innerBlocks'], $path, $replacement );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}
		if ( count( $inner ) !== count( $blocks[ $k ]['innerBlocks'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Inner blocks can only be replaced one for one' );
		}
		$blocks[ $k ]['innerBlocks'] = $inner;
		return $blocks;
	}

	/**
	 * Compact tree for the agent.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $prefix Path so far.
	 * @return array
	 */
	public static function tree( array $blocks, array $prefix ) {
		$out = array();
		$i   = 0;
		foreach ( $blocks as $b ) {
			if ( null === $b['blockName'] ) {
				continue;
			}
			$path  = array_merge( $prefix, array( $i ) );
			$out[] = array(
				'path'    => $path,
				'name'    => $b['blockName'],
				'attrs'   => $b['attrs'],
				'text'    => mb_substr( trim( wp_strip_all_tags( (string) $b['innerHTML'] ) ), 0, 200 ),
				'html'    => mb_substr( (string) $b['innerHTML'], 0, 2000 ),
				'content' => self::tree( (array) $b['innerBlocks'], $path ),
			);
			++$i;
		}
		return $out;
	}

	// ---------------------------------------------------------------- media

	/**
	 * Uploads a file into the media library.
	 *
	 * @param array  $p          {url?|data?, filename?, alt?, title?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function media_upload( array $p, $command_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$got = Media::fetch( $p );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		list( $tmp, $name ) = $got;
		$type               = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $type['type'] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'presshelm_filetype', 'WordPress does not allow this file type' );
		}
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			isset( $p['title'] ) ? sanitize_text_field( (string) $p['title'] ) : null
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( isset( $p['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $p['alt'] ) );
		}
		$journal = Journal::record( $command_id, 'attachment_created', (string) $id, array() );
		return array(
			'id'         => (int) $id,
			'url'        => wp_get_attachment_url( $id ),
			'mime'       => get_post_mime_type( $id ),
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- settings

	/**
	 * Allowlisted settings.
	 *
	 * @return array
	 */
	public static function settings_get() {
		$out = array();
		foreach ( array_keys( self::settings_allowlist() ) as $name ) {
			$out[ $name ] = get_option( $name );
		}
		return array(
			'settings' => $out,
			'identity' => self::identity(),
		);
	}

	/**
	 * The site logo and icon now.
	 *
	 * @return array
	 */
	public static function identity() {
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo ) {
			$logo = (int) get_option( 'site_logo' );
		}
		$icon = (int) get_option( 'site_icon' );
		return array(
			'logo'             => $logo ? array(
				'id'  => $logo,
				'url' => wp_get_attachment_url( $logo ),
			) : null,
			'icon'             => $icon ? array(
				'id'  => $icon,
				'url' => wp_get_attachment_url( $icon ),
			) : null,
			'theme_shows_logo' => current_theme_supports( 'custom-logo' ) || wp_is_block_theme(),
		);
	}
}
