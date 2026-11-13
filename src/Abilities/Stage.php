<?php
/**
 * Staged edits for published pages (H3): draft → preview → publish → recovery.
 *
 * A published post or page is copied to a hidden draft (meta-linked to it). Changes are made on
 * the copy with the normal tools; nothing visitors see changes, so those edits need no approval.
 * The owner looks at a private preview link (signed, expires); publishing copies the draft's
 * title, content, excerpt and meta (page-builder data included) into the original, which keeps
 * its ID and address, with a revision and one undo for the whole swap. The copy then goes to the
 * trash. If the live page changed after staging, publishing stops and says so.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Staged edits.
 */
final class Stage {

	/** Meta on a draft copy: the post it stages. */
	const META_OF = '_presshelm_stage_of';
	/** Meta on a draft copy: the original's modified time (GMT) when staged. */
	const META_BASE = '_presshelm_stage_base';
	/** Preview links stay valid this long. */
	const PREVIEW_TTL = 2 * DAY_IN_SECONDS;
	/** Meta never copied back into the original. */
	const NO_PUBLISH_META = array( self::META_OF, self::META_BASE, '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_elementor_css', '_encloseme', '_pingme' );

	/**
	 * Front-end hooks for preview links.
	 */
	public static function register() {
		add_filter( 'posts_results', array( self::class, 'preview_results' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'preview_headers' ), 0 );
		add_filter( 'woocommerce_coming_soon_exclude', array( self::class, 'coming_soon_exclude' ) );
	}

	/**
	 * A valid preview link shows the staged page even while the store is in "Coming soon" mode
	 * (that page only; the owner previewing on a phone is usually not logged in).
	 *
	 * @param bool $excluded Already excluded.
	 * @return bool
	 */
	public static function coming_soon_exclude( $excluded ) {
		return $excluded || ( is_singular() && self::previewing( (int) get_queried_object_id() ) > 0 );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$stage_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return array(
			'content.stage'         => array(
				'risk'        => 'LOW',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'stage' ),
				'label'       => 'Stage changes to a published page',
				'description' => 'Copies a published post or page to a hidden draft for changes, with a private preview link. Visitors see nothing until it is published. If one is already staged for that page, that one is returned.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $stage_id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
			'content.stage_publish' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'publish' ),
				'label'       => 'Publish staged changes',
				'description' => 'Puts a staged draft live: its title, content, excerpt and meta replace the original page\'s (same ID and address, revision kept), then the draft goes to the trash. Refused if the live page changed after staging unless force is set. Undo puts the page back as it was.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'stage_id' => $stage_id,
						'force'    => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'stage_id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'content.stage_discard' => array(
				'risk'        => 'LOW',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'discard' ),
				'label'       => 'Discard staged changes',
				'description' => 'Moves a staged draft to the trash; the live page is untouched.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'stage_id' => $stage_id ),
					'required'             => array( 'stage_id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
			'content.stage_list'    => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'list_open' ),
				'label'       => 'List staged changes',
				'description' => 'Staged drafts waiting to be published: the page they stage, what differs, a fresh preview link.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
		);
	}

	/**
	 * The original a draft copy stages (0 when it is not a staged copy).
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function original_of( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'trash' === $post->post_status || 'publish' === $post->post_status ) {
			return 0;
		}
		return (int) get_post_meta( $post->ID, self::META_OF, true );
	}

	/**
	 * Signed preview link for a staged copy (works without logging in, until it expires).
	 *
	 * @param int $stage_id Copy id.
	 * @return string
	 */
	public static function preview_url( $stage_id ) {
		$exp = time() + self::PREVIEW_TTL;
		return add_query_arg(
			array(
				'presshelm_preview' => self::sign( (int) $stage_id, $exp ),
				'presshelm_exp'     => $exp,
			),
			get_permalink( (int) $stage_id )
		);
	}

	/**
	 * Signature of a preview link.
	 *
	 * @param int $stage_id Copy id.
	 * @param int $exp      Expiry (unix time).
	 * @return string
	 */
	private static function sign( $stage_id, $exp ) {
		return substr( hash_hmac( 'sha256', 'stage-preview|' . (int) $stage_id . '|' . (int) $exp, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * The staged copy a request may preview (0 when the link is missing, wrong or expired).
	 *
	 * @param int $post_id Post the request shows.
	 * @return int
	 */
	private static function previewing( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a signed, expiring preview link replaces the nonce (it must work logged out).
		if ( empty( $_GET['presshelm_preview'] ) || empty( $_GET['presshelm_exp'] ) ) {
			return 0;
		}
		$sig = sanitize_text_field( wp_unslash( $_GET['presshelm_preview'] ) );
		$exp = absint( wp_unslash( $_GET['presshelm_exp'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $exp < time() || ! hash_equals( self::sign( (int) $post_id, $exp ), $sig ) || ! self::original_of( (int) $post_id ) ) {
			return 0;
		}
		return (int) $post_id;
	}

	/**
	 * Lets a valid preview link show the staged draft (in this request only, read only).
	 *
	 * @param \WP_Post[] $posts Posts found.
	 * @param \WP_Query  $query Query.
	 * @return \WP_Post[]
	 */
	public static function preview_results( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() || 1 !== count( (array) $posts ) ) {
			return $posts;
		}
		$post = $posts[0];
		if ( ! ( $post instanceof \WP_Post ) || 'draft' !== $post->post_status || ! self::previewing( $post->ID ) ) {
			return $posts;
		}
		$shown              = clone $post;
		$shown->post_status = 'publish';
		return array( $shown );
	}

	/**
	 * Preview pages are never indexed or cached.
	 */
	public static function preview_headers() {
		if ( ! is_singular() || ! self::previewing( (int) get_queried_object_id() ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/**
	 * Stages a published post or page.
	 *
	 * @param array  $p          {id}.
	 * @param string $command_id Command id.
	 * @return array|\WP_Error
	 */
	public static function stage( array $p, $command_id ) {
		$src = get_post( (int) $p['id'] );
		if ( ! $src || ! in_array( $src->post_type, Content::TYPES, true ) || 'trash' === $src->post_status ) {
			return new \WP_Error( 'presshelm_not_found', 'No post or page with that id.' );
		}
		if ( self::original_of( $src->ID ) ) {
			return new \WP_Error( 'presshelm_stage_copy', 'That is already a staged copy; make the changes on it.' );
		}
		if ( 'publish' !== $src->post_status ) {
			return new \WP_Error( 'presshelm_not_published', 'Only published pages are staged; a ' . $src->post_status . ' page can be changed directly (visitors do not see it).' );
		}
		if ( ! current_user_can( 'edit_post', $src->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to edit this item.' );
		}
		$open = self::open_for( $src->ID );
		if ( $open ) {
			return self::describe( $open, true );
		}
		$copy = Content::duplicate(
			array(
				'id'     => $src->ID,
				'title'  => $src->post_title,
				'status' => 'draft',
			),
			$command_id
		);
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		update_post_meta( (int) $copy['id'], self::META_OF, (int) $src->ID );
		update_post_meta( (int) $copy['id'], self::META_BASE, (string) $src->post_modified_gmt );
		return self::describe( get_post( (int) $copy['id'] ), false ) + array( 'journal_id' => $copy['journal_id'] ?? null );
	}

	/**
	 * The open staged copy of a post, if any.
	 *
	 * @param int $original_id Original id.
	 * @return \WP_Post|null
	 */
	private static function open_for( $original_id ) {
		$found = get_posts(
			array(
				'post_type'   => Content::TYPES,
				'post_status' => array( 'draft', 'pending' ),
				'meta_key'    => self::META_OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, own meta; only on staging.
				'meta_value'  => (int) $original_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'numberposts' => 1,
			)
		);
		return $found ? $found[0] : null;
	}

	/**
	 * What the agent and owner need to know about a staged copy.
	 *
	 * @param \WP_Post $copy     Copy.
	 * @param bool     $existing Whether it was staged before.
	 * @return array
	 */
	private static function describe( $copy, $existing ) {
		$orig = get_post( (int) get_post_meta( $copy->ID, self::META_OF, true ) );
		$base = (string) get_post_meta( $copy->ID, self::META_BASE, true );
		$diff = array();
		if ( $orig ) {
			if ( $orig->post_title !== $copy->post_title ) {
				$diff[] = 'title';
			}
			if ( $orig->post_content !== $copy->post_content ) {
				$diff[] = 'content (' . strlen( $orig->post_content ) . ' → ' . strlen( $copy->post_content ) . ' bytes)';
			}
			if ( $orig->post_excerpt !== $copy->post_excerpt ) {
				$diff[] = 'excerpt';
			}
			if ( (string) get_post_meta( $orig->ID, '_elementor_data', true ) !== (string) get_post_meta( $copy->ID, '_elementor_data', true ) ) {
				$diff[] = 'Elementor layout';
			}
		}
		return array(
			'stage_id'        => (int) $copy->ID,
			'original_id'     => $orig ? (int) $orig->ID : 0,
			'original_title'  => $orig ? $orig->post_title : '',
			'original_url'    => $orig ? (string) get_permalink( $orig ) : '',
			'preview_url'     => self::preview_url( $copy->ID ),
			'preview_expires' => gmdate( 'Y-m-d H:i', time() + self::PREVIEW_TTL ) . ' UTC',
			'already_staged'  => $existing,
			'changed'         => $diff ? $diff : array( 'nothing yet' ),
			'live_changed'    => $orig && '' !== $base && $orig->post_modified_gmt !== $base,
			'next'            => 'Make the changes on stage_id with the normal tools (they do not touch the live page and need no approval), share preview_url with the owner, then publish with content_stage_publish (one approval).',
		);
	}

	/**
	 * Publishes a staged copy into its original.
	 *
	 * @param array  $p          {stage_id, force?}.
	 * @param string $command_id Command id.
	 * @return array|\WP_Error
	 */
	public static function publish( array $p, $command_id ) {
		$copy = get_post( (int) $p['stage_id'] );
		$oid  = $copy ? self::original_of( $copy->ID ) : 0;
		$orig = $oid ? get_post( $oid ) : null;
		if ( ! $copy || ! $orig || 'trash' === $orig->post_status ) {
			return new \WP_Error( 'presshelm_stage_missing', 'No staged draft with that id (or its page is gone).' );
		}
		if ( ! current_user_can( 'edit_post', $orig->ID ) || ! current_user_can( 'page' === $orig->post_type ? 'publish_pages' : 'publish_posts' ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to publish this item.' );
		}
		$base = (string) get_post_meta( $copy->ID, self::META_BASE, true );
		if ( '' !== $base && $orig->post_modified_gmt !== $base && empty( $p['force'] ) ) {
			return new \WP_Error( 'presshelm_stage_conflict', 'The live page was changed after it was staged (' . $orig->post_modified_gmt . ' UTC). Publishing would overwrite that change. Check the live page; publish with force only if the owner agrees.' );
		}
		// One undo for the whole swap: the original's fields and every meta key that will change.
		$meta_before = array();
		$copy_meta   = (array) get_post_meta( $copy->ID );
		foreach ( $copy_meta as $key => $values ) {
			if ( in_array( $key, self::NO_PUBLISH_META, true ) ) {
				continue;
			}
			$exists              = metadata_exists( 'post', $orig->ID, $key );
			$meta_before[ $key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $orig->ID, $key, true ) : null,
			);
		}
		$journal = Journal::record(
			$command_id,
			'stage_publish',
			(string) $orig->ID,
			array(
				'post' => array(
					'post_title'   => $orig->post_title,
					'post_content' => $orig->post_content,
					'post_status'  => $orig->post_status,
					'post_excerpt' => $orig->post_excerpt,
				),
				'meta' => $meta_before,
			)
		);
		wp_save_post_revision( $orig->ID );
		$r = wp_update_post(
			wp_slash(
				array(
					'ID'           => $orig->ID,
					'post_title'   => $copy->post_title,
					'post_content' => $copy->post_content,
					'post_excerpt' => $copy->post_excerpt,
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( $copy_meta as $key => $values ) {
			if ( in_array( $key, self::NO_PUBLISH_META, true ) ) {
				continue;
			}
			update_post_meta( $orig->ID, $key, wp_slash( maybe_unserialize( $values[0] ?? '' ) ) );
		}
		self::rebuild_builder_css( $orig->ID );
		wp_trash_post( $copy->ID );
		return array(
			'id'         => (int) $orig->ID,
			'url'        => (string) get_permalink( $orig->ID ),
			'published'  => 'staged draft #' . (int) $copy->ID . ' is live (the draft went to the trash)',
			'journal_id' => $journal,
		);
	}

	/**
	 * After builder data changed outside the builder: drop its cached CSS so it is rebuilt.
	 *
	 * @param int $post_id Post id.
	 */
	public static function rebuild_builder_css( $post_id ) {
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) && get_post_meta( (int) $post_id, '_elementor_data', true ) ) {
			\Elementor\Core\Files\CSS\Post::create( (int) $post_id )->delete();
		}
		if ( class_exists( '\FLBuilderModel' ) && method_exists( '\FLBuilderModel', 'delete_asset_cache_for_all_posts' ) ) {
			\FLBuilderModel::delete_asset_cache_for_all_posts();
		}
	}

	/**
	 * Discards a staged copy (to the trash).
	 *
	 * @param array  $p          {stage_id}.
	 * @param string $command_id Command id.
	 * @return array|\WP_Error
	 */
	public static function discard( array $p, $command_id ) {
		$copy = get_post( (int) $p['stage_id'] );
		if ( ! $copy || ! self::original_of( $copy->ID ) ) {
			return new \WP_Error( 'presshelm_stage_missing', 'No staged draft with that id.' );
		}
		$journal = Journal::record(
			$command_id,
			'post',
			(string) $copy->ID,
			array(
				'post_title'   => $copy->post_title,
				'post_content' => $copy->post_content,
				'post_status'  => $copy->post_status,
				'post_excerpt' => $copy->post_excerpt,
			)
		);
		wp_trash_post( $copy->ID );
		return array(
			'discarded'  => (int) $copy->ID,
			'journal_id' => $journal,
		);
	}

	/**
	 * Open staged copies.
	 *
	 * @return array
	 */
	public static function list_open() {
		$found = get_posts(
			array(
				'post_type'   => Content::TYPES,
				'post_status' => array( 'draft', 'pending' ),
				'meta_key'    => self::META_OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, own meta.
				'numberposts' => 50,
			)
		);
		return array(
			'staged' => array_map(
				static function ( $c ) {
					return self::describe( $c, true );
				},
				$found
			),
		);
	}

	/**
	 * Undo of a publish: the original's fields and meta as they were (journal kind stage_publish).
	 *
	 * @param int   $post_id Original id.
	 * @param array $before  {post, meta}.
	 * @return true|\WP_Error
	 */
	public static function restore( $post_id, array $before ) {
		$post = (array) ( $before['post'] ?? array() );
		$r    = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post_id,
					'post_title'   => (string) ( $post['post_title'] ?? '' ),
					'post_content' => (string) ( $post['post_content'] ?? '' ),
					'post_status'  => (string) ( $post['post_status'] ?? 'publish' ),
					'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
				)
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( (array) ( $before['meta'] ?? array() ) as $key => $state ) {
			if ( empty( $state['exists'] ) ) {
				delete_post_meta( (int) $post_id, (string) $key );
			} else {
				update_post_meta( (int) $post_id, (string) $key, wp_slash( $state['value'] ) );
			}
		}
		self::rebuild_builder_css( (int) $post_id );
		return true;
	}
}
