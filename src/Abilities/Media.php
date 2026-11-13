<?php
/**
 * Media library (A2): search, where an item is used, replace its file (same id and address, so
 * every page shows the new one), and delete safely (refused while in use unless forced; the
 * files and details are backed up so undo brings it back with the same id).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;
use PressHelm\Recovery\MediaBackup;

defined( 'ABSPATH' ) || exit;

/**
 * Media.
 */
final class Media {

	/** Mime groups for search. */
	const TYPES = array( 'image', 'video', 'audio', 'document', 'any' );

	/**
	 * Ability definitions.
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
		$src  = array(
			'inbox_id' => array(
				'type'      => 'string',
				'maxLength' => 40,
			),
			'url'      => array(
				'type'      => 'string',
				'format'    => 'uri',
				'maxLength' => 2000,
			),
			'data'     => array(
				'type'      => 'string',
				'maxLength' => 8000000,
			),
			'filename' => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
		);
		return array(
			'media.search'  => array(
				'risk'        => 'READ',
				'cap'         => 'upload_files',
				'mutates'     => false,
				'run'         => array( self::class, 'search' ),
				'label'       => 'Find media',
				'description' => 'Media library items by text (title, file name, caption, alt), type (image, video, audio, document) and unattached, newest first: id, title, file, type, address, alt text, size, dimensions, attached post. Max 50 per page.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'type'       => array(
							'type' => 'string',
							'enum' => self::TYPES,
						),
						'unattached' => array( 'type' => 'boolean' ),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'media.usage'   => array(
				'risk'        => 'READ',
				'cap'         => 'upload_files',
				'mutates'     => false,
				'run'         => array( self::class, 'usage_ability' ),
				'label'       => 'Where a media item is used',
				'description' => 'Posts, pages and products that show it (in content, as featured image, in a product gallery or in Elementor data), and the site logo or icon.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'media.replace' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'upload_files',
				'mutates'     => true,
				'run'         => array( self::class, 'replace' ),
				'label'       => 'Replace a media file',
				'description' => 'Puts a new file in place of a media item, keeping its id and address, so every page using it shows the new one. The new file must be the same kind with the same extension (e.g. a JPEG for a JPEG). Sizes are regenerated. Undo puts the old file back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array_merge( array( 'id' => $id ), $src ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'media.delete'  => array(
				'risk'        => 'HIGH',
				'cap'         => 'delete_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'delete' ),
				'label'       => 'Delete a media item',
				'description' => 'Deletes a media item and its files. Refused while it is used somewhere (see media.usage) unless force is true. The files and details are backed up first: undo brings it back with the same id and address.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => $id,
						'force' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * A media item the current user may change, or an error.
	 *
	 * @param int $id Attachment id.
	 * @return \WP_Post|\WP_Error
	 */
	private static function attachment( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'presshelm_not_found', 'No media item with that id.' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to change this media item' );
		}
		return $post;
	}

	/**
	 * Search.
	 *
	 * @param array $p Input.
	 * @return array
	 */
	public static function search( array $p ) {
		$type = (string) ( $p['type'] ?? 'any' );
		$mime = array(
			'image'    => 'image',
			'video'    => 'video',
			'audio'    => 'audio',
			'document' => array( 'application', 'text' ),
		);
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => (string) ( $p['search'] ?? '' ),
			'posts_per_page' => min( 50, max( 1, (int) ( $p['limit'] ?? 20 ) ) ),
			'paged'          => max( 1, (int) ( $p['page'] ?? 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( isset( $mime[ $type ] ) ) {
			$args['post_mime_type'] = $mime[ $type ];
		}
		if ( ! empty( $p['unattached'] ) ) {
			$args['post_parent'] = 0;
		}
		$q   = new \WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $a ) {
			$meta  = wp_get_attachment_metadata( $a->ID );
			$file  = get_attached_file( $a->ID );
			$out[] = array(
				'id'       => $a->ID,
				'title'    => $a->post_title,
				'file'     => $file ? basename( $file ) : null,
				'mime'     => $a->post_mime_type,
				'url'      => wp_get_attachment_url( $a->ID ),
				'alt'      => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
				'caption'  => $a->post_excerpt,
				'bytes'    => $file && is_file( $file ) ? (int) filesize( $file ) : null,
				'width'    => is_array( $meta ) ? ( $meta['width'] ?? null ) : null,
				'height'   => is_array( $meta ) ? ( $meta['height'] ?? null ) : null,
				'date'     => $a->post_date_gmt,
				'attached' => $a->post_parent ? (int) $a->post_parent : null,
			);
		}
		return array(
			'items' => $out,
			'total' => (int) $q->found_posts,
			'page'  => (int) $args['paged'],
		);
	}

	/**
	 * Where an item is used (ability wrapper).
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function usage_ability( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'presshelm_not_found', 'No media item with that id.' );
		}
		return self::usage( $post->ID );
	}

	/**
	 * Where an item is used.
	 *
	 * @param int $id Attachment id.
	 * @return array{used: bool, count: int, places: array}
	 */
	public static function usage( $id ) {
		global $wpdb;
		$id     = (int) $id;
		$places = array();
		$add    = static function ( $post_id, $how ) use ( &$places ) {
			$key = $post_id . '|' . $how;
			if ( isset( $places[ $key ] ) || count( $places ) >= 50 ) {
				return;
			}
			$p              = get_post( (int) $post_id );
			$places[ $key ] = array(
				'post_id' => (int) $post_id,
				'type'    => $p ? $p->post_type : null,
				'title'   => $p ? $p->post_title : null,
				'status'  => $p ? $p->post_status : null,
				'how'     => $how,
			);
		};
		// In content: the image class, block attributes, or the file's path (any size).
		$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem = '' !== $rel ? preg_replace( '/\.[a-z0-9]+$/i', '', $rel ) : '';
		$path = '' !== $stem ? '%/' . $wpdb->esc_like( $stem ) . '%' : '%wp-image-' . $id . '"%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- usage lookup across posts.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment', 'customize_changeset') AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
				 AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT 50",
				'%wp-image-' . $id . '"%',
				'%wp-image-' . $id . ' %',
				'%"id":' . $id . ',%',
				'%"id":' . $id . '}%',
				'%"mediaId":' . $id . '%',
				$path
			)
		);
		foreach ( $rows as $pid ) {
			$add( $pid, 'content' );
		}
		$thumb = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 50", (string) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- usage lookup.
		foreach ( $thumb as $pid ) {
			$add( $pid, 'featured image' );
		}
		$gallery = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND CONCAT(',', meta_value, ',') LIKE %s LIMIT 50", '%,' . $id . ',%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- usage lookup.
		foreach ( $gallery as $pid ) {
			$add( $pid, 'product gallery' );
		}
		$el_path = '' !== $stem ? '%' . $wpdb->esc_like( str_replace( '/', '\\/', $stem ) ) . '%' : '%"id":' . $id . ',%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- usage lookup.
		$el = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 50",
				'%"id":' . $id . ',%',
				'%"id":"' . $id . '"%',
				$el_path
			)
		);
		foreach ( $el as $pid ) {
			$add( $pid, 'Elementor content' );
		}
		$out = array_values( $places );
		if ( (int) get_option( 'site_icon' ) === $id ) {
			$out[] = array( 'how' => 'site icon' );
		}
		if ( (int) get_theme_mod( 'custom_logo' ) === $id || (int) get_option( 'site_logo' ) === $id ) {
			$out[] = array( 'how' => 'site logo' );
		}
		return array(
			'used'   => (bool) $out,
			'count'  => count( $out ),
			'places' => $out,
		);
	}

	/**
	 * A file from the owner's Telegram inbox, a public URL or base64 data, in a temp file.
	 *
	 * @param array $p {inbox_id|url|data, filename?}.
	 * @return array{0: string, 1: string}|\WP_Error Temp path and file name.
	 */
	public static function fetch( array $p ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! empty( $p['inbox_id'] ) ) {
			// A file the owner sent in Telegram: fetched from the cloud with this site's signature.
			$res = \PressHelm\Connect\Cloud::post( '/v1/connector/media', array( 'id' => (string) $p['inbox_id'] ), true, 60 );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( 200 !== $res['code'] || empty( $res['json']['data'] ) ) {
				return new \WP_Error( 'presshelm_inbox_missing', (string) ( $res['json']['error']['message'] ?? 'This file is no longer available. Ask the owner to send it again.' ) );
			}
			$raw = base64_decode( (string) $res['json']['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- uploaded file bytes, not code.
			if ( false === $raw || '' === $raw ) {
				return new \WP_Error( 'presshelm_payload', 'The file from PressHelm Cloud could not be read.' );
			}
			$tmp = wp_tempnam( 'presshelm-upload' );
			file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file handed to WordPress's upload handling.
			$name = ! empty( $p['filename'] ) ? (string) $p['filename'] : (string) ( $res['json']['filename'] ?? '' );
		} elseif ( ! empty( $p['url'] ) ) {
			// download_url() uses wp_safe_remote_get(): private and local addresses are refused.
			$tmp  = download_url( esc_url_raw( (string) $p['url'] ), 60 );
			$name = ! empty( $p['filename'] ) ? (string) $p['filename'] : basename( (string) wp_parse_url( (string) $p['url'], PHP_URL_PATH ) );
		} elseif ( ! empty( $p['data'] ) ) {
			$raw = base64_decode( (string) $p['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- uploaded file bytes, not code.
			if ( false === $raw || '' === $raw ) {
				return new \WP_Error( 'presshelm_payload', 'data is not valid base64' );
			}
			$tmp = wp_tempnam( 'presshelm-upload' );
			file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file handed to WordPress's upload handling.
			$name = (string) ( $p['filename'] ?? '' );
		} else {
			return new \WP_Error( 'presshelm_payload', 'Give an inbox_id, a url or data' );
		}
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'presshelm_download', 'The file could not be downloaded: ' . $tmp->get_error_message() );
		}
		return array( $tmp, sanitize_file_name( '' !== $name ? $name : 'upload' ) );
	}

	/**
	 * Replaces the file of a media item (same id and address).
	 *
	 * @param array  $p          {id, inbox_id|url|data, filename?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function replace( array $p, $command_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$post = self::attachment( $p['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$main = get_attached_file( $post->ID, true );
		if ( ! $main || ! is_file( $main ) ) {
			return new \WP_Error( 'presshelm_media_files', 'The file of this media item is missing on the server.' );
		}
		$got = self::fetch( $p );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		list( $tmp, $name ) = $got;
		$old_ext            = strtolower( (string) pathinfo( $main, PATHINFO_EXTENSION ) );
		// Checked under the current file's name: same extension, and the content must really be that type.
		$type  = wp_check_filetype_and_ext( $tmp, basename( $main ) );
		$given = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$new   = wp_check_filetype_and_ext( $tmp, '' === $given ? basename( $main ) : $name );
		if ( empty( $type['type'] ) || ( ! empty( $new['ext'] ) && strtolower( (string) $new['ext'] ) !== $old_ext ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'presshelm_media_type', 'The new file must be a ' . strtoupper( $old_ext ) . ' like the one it replaces (the address keeps its .' . $old_ext . ' ending). Upload it as a new media item instead.' );
		}
		$backup = MediaBackup::take( $post->ID );
		if ( is_wp_error( $backup ) ) {
			wp_delete_file( $tmp );
			return $backup;
		}
		$journal = Journal::record(
			$command_id,
			'media_replaced',
			(string) $post->ID,
			array(
				'backup'   => $backup,
				'metadata' => wp_get_attachment_metadata( $post->ID ),
				'mime'     => $post->post_mime_type,
			)
		);
		// The old sizes go (the backup keeps them); the new ones are made from the new file.
		$old_files = MediaBackup::files_of( $post->ID );
		$main_rel  = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		MediaBackup::remove( array_diff( $old_files, array( $main_rel ) ) );
		if ( ! copy( $tmp, $main ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- writing the media item's own file in uploads.
			wp_delete_file( $tmp );
			MediaBackup::restore( $backup );
			return new \WP_Error( 'presshelm_media_write', 'The new file could not be written; the old one was put back.' );
		}
		wp_delete_file( $tmp );
		$meta = wp_generate_attachment_metadata( $post->ID, $main );
		wp_update_attachment_metadata( $post->ID, $meta );
		if ( ! empty( $type['type'] ) && $type['type'] !== $post->post_mime_type ) {
			wp_update_post(
				array(
					'ID'             => $post->ID,
					'post_mime_type' => $type['type'],
				)
			);
		}
		clean_post_cache( $post->ID );
		return array(
			'id'         => $post->ID,
			'url'        => wp_get_attachment_url( $post->ID ),
			'width'      => is_array( $meta ) ? ( $meta['width'] ?? null ) : null,
			'height'     => is_array( $meta ) ? ( $meta['height'] ?? null ) : null,
			'sizes'      => is_array( $meta ) && ! empty( $meta['sizes'] ) ? count( $meta['sizes'] ) : 0,
			'note'       => 'Visitors and caches may show the old file for a while (browser and page caches).',
			'journal_id' => $journal,
		);
	}

	/**
	 * Deletes a media item after backing it up (refused while in use unless forced).
	 *
	 * @param array  $p          {id, force?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function delete( array $p, $command_id ) {
		$post = self::attachment( $p['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to delete this media item' );
		}
		$usage = self::usage( $post->ID );
		if ( $usage['used'] && empty( $p['force'] ) ) {
			return new \WP_Error( 'presshelm_media_in_use', 'This media item is still used (' . $usage['count'] . ' place(s), see media.usage); nothing was deleted. Remove it there first, or delete with force.', $usage );
		}
		$backup = MediaBackup::take( $post->ID );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}
		$journal = Journal::record(
			$command_id,
			'media_deleted',
			(string) $post->ID,
			array(
				'backup' => $backup,
				'post'   => array(
					'post_title'     => $post->post_title,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_name'      => $post->post_name,
					'post_parent'    => (int) $post->post_parent,
					'post_date'      => $post->post_date,
					'post_date_gmt'  => $post->post_date_gmt,
					'post_mime_type' => $post->post_mime_type,
					'post_author'    => (int) $post->post_author,
					'guid'           => $post->guid,
				),
				'meta'   => $meta,
			)
		);
		if ( ! wp_delete_attachment( $post->ID, true ) ) {
			return new \WP_Error( 'presshelm_media_delete', 'WordPress could not delete this media item.' );
		}
		return array(
			'deleted'    => $post->ID,
			'was_used'   => $usage['count'],
			'journal_id' => $journal,
		);
	}

	/**
	 * Undo of a replacement: the new files go, the backed-up ones come back with the old details.
	 *
	 * @param int   $id     Attachment id.
	 * @param array $before {backup, metadata, mime}.
	 * @return true|\WP_Error
	 */
	public static function undo_replace( $id, array $before ) {
		$keep = MediaBackup::paths( (string) ( $before['backup'] ?? '' ) );
		if ( null === $keep ) {
			return new \WP_Error( 'presshelm_media_backup_missing', 'The backup of this media item is gone (backups are kept 30 days).' );
		}
		MediaBackup::remove( array_diff( MediaBackup::files_of( $id ), $keep ) );
		$r = MediaBackup::restore( (string) $before['backup'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		wp_update_attachment_metadata( $id, (array) ( $before['metadata'] ?? array() ) );
		if ( ! empty( $before['mime'] ) ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => (string) $before['mime'],
				)
			);
		}
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Undo of a deletion: the item comes back with the same id, details and files.
	 *
	 * @param int   $id     Attachment id.
	 * @param array $before {backup, post, meta}.
	 * @return true|\WP_Error
	 */
	public static function undo_delete( $id, array $before ) {
		if ( get_post( $id ) ) {
			return new \WP_Error( 'presshelm_media_exists', 'Something else already uses this id; the media item cannot come back under it.' );
		}
		$r = MediaBackup::restore( (string) ( $before['backup'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$post   = (array) ( $before['post'] ?? array() );
		$new_id = wp_insert_post(
			wp_slash(
				array_merge(
					$post,
					array(
						'import_id'   => $id,
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
					)
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		if ( $id !== (int) $new_id ) {
			wp_delete_post( (int) $new_id, true );
			return new \WP_Error( 'presshelm_media_id', 'The media item could not get its old id back.' );
		}
		foreach ( (array) ( $before['meta'] ?? array() ) as $key => $values ) {
			delete_post_meta( $id, (string) $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $id, (string) $key, wp_slash( $value ) );
			}
		}
		clean_post_cache( $id );
		return true;
	}
}
