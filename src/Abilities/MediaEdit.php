<?php
/**
 * Image processing on the site (B2): what an image weighs, resize / crop / recompress in place
 * (same id and address), and conversion to WebP (links in content and Elementor follow; the old
 * files stay so outside links keep working). WordPress's own image editor (GD or Imagick) does the
 * work. Everything is backed up first, so undo puts the exact files and content back.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;
use PressHelm\Recovery\MediaBackup;

defined( 'ABSPATH' ) || exit;

/**
 * Image editing.
 */
final class MediaEdit {

	/** Formats that can be edited and converted. */
	const EDITABLE = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Ratios offered for cropping. */
	const RATIOS = array( '1:1', '4:3', '3:2', '16:9', '21:9', '3:4', '2:3', '9:16', '4:5' );

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
		$px   = array(
			'type'    => 'integer',
			'minimum' => 16,
			'maximum' => 10000,
		);
		$qual = array(
			'type'    => 'integer',
			'minimum' => 30,
			'maximum' => 100,
		);
		$edit = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		return array(
			'media.image_info' => array(
				'risk'        => 'READ',
				'cap'         => 'upload_files',
				'mutates'     => false,
				'run'         => array( self::class, 'info' ),
				'label'       => 'Image details and savings',
				'description' => 'For up to 50 images: dimensions, file size, the generated sizes and their total, and what would help (resize, recompress, WebP), plus whether this server can edit images and write WebP.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids' => array(
							'type'     => 'array',
							'items'    => $id,
							'minItems' => 1,
							'maxItems' => 50,
						),
					),
					'required'             => array( 'ids' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
			'media.image_edit' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'upload_files',
				'mutates'     => true,
				'run'         => array( self::class, 'edit' ),
				'label'       => 'Resize, crop or recompress an image',
				'description' => 'Edits an image in place (same id and address, every page shows the result): crop to a ratio (centered) or an exact box, shrink to fit a maximum width/height (never enlarges), and/or save at a JPEG/WebP quality (30-100). Sizes are regenerated. Undo puts the original back exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => $id,
						'max_width'  => $px,
						'max_height' => $px,
						'crop_ratio' => array(
							'type' => 'string',
							'enum' => self::RATIOS,
						),
						'crop_box'   => array(
							'type'                 => 'object',
							'properties'           => array(
								'x'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'y'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'width'  => $px,
								'height' => $px,
							),
							'required'             => array( 'x', 'y', 'width', 'height' ),
							'additionalProperties' => false,
						),
						'quality'    => $qual,
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $edit,
				'undo'        => 'journal.restore',
			),
			'media.convert'    => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'upload_files',
				'mutates'     => true,
				'run'         => array( self::class, 'convert' ),
				'label'       => 'Convert images to WebP',
				'description' => 'Converts up to 20 JPEG/PNG images to WebP (usually much smaller). Each keeps its id; its sizes are regenerated as WebP and links to it in post content and Elementor are updated. The old files stay on the server so outside links keep working. One undo puts every image, link and page back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'     => array(
							'type'     => 'array',
							'items'    => $id,
							'minItems' => 1,
							'maxItems' => 20,
						),
						'quality' => $qual,
					),
					'required'             => array( 'ids' ),
					'additionalProperties' => false,
				),
				'annotations' => $edit,
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * An image the current user may change, or an error.
	 *
	 * @param int $id Attachment id.
	 * @return \WP_Post|\WP_Error
	 */
	private static function image( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'presshelm_not_found', 'No media item with id ' . (int) $id . '.' );
		}
		if ( ! in_array( $post->post_mime_type, self::EDITABLE, true ) ) {
			return new \WP_Error( 'presshelm_media_type', 'Media item ' . $post->ID . ' is not a JPEG, PNG or WebP image (' . $post->post_mime_type . ').' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to change media item ' . $post->ID );
		}
		$file = get_attached_file( $post->ID, true );
		if ( ! $file || ! is_file( $file ) ) {
			return new \WP_Error( 'presshelm_media_files', 'The file of media item ' . $post->ID . ' is missing on the server.' );
		}
		return $post;
	}

	/**
	 * Bytes of an image's main file and of all its generated sizes.
	 *
	 * @param int $id Attachment id.
	 * @return array{main: int, all: int}
	 */
	private static function weight( $id ) {
		$base = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
		$all  = 0;
		foreach ( MediaBackup::files_of( $id ) as $rel ) {
			$all += (int) filesize( $base . $rel );
		}
		$main = get_attached_file( $id, true );
		return array(
			'main' => $main && is_file( $main ) ? (int) filesize( $main ) : 0,
			'all'  => $all,
		);
	}

	/**
	 * Image details and what would help.
	 *
	 * @param array $p {ids}.
	 * @return array
	 */
	public static function info( array $p ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$webp  = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$items = array();
		foreach ( array_unique( array_map( 'intval', (array) $p['ids'] ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$items[] = array(
					'id'    => $id,
					'error' => 'not found',
				);
				continue;
			}
			$meta  = wp_get_attachment_metadata( $id );
			$w     = (int) ( $meta['width'] ?? 0 );
			$h     = (int) ( $meta['height'] ?? 0 );
			$size  = self::weight( $id );
			$hints = array();
			if ( ! in_array( $post->post_mime_type, self::EDITABLE, true ) ) {
				$hints[] = 'not an editable image type';
			} else {
				if ( $w > 2560 || $h > 2560 ) {
					$hints[] = 'very large: resizing to 1920-2560 px wide is plenty for web pages';
				}
				if ( $size['main'] > 500 * 1024 ) {
					$hints[] = 'heavy file: recompressing (quality 75-82) or WebP would help';
				}
				if ( $webp && 'image/webp' !== $post->post_mime_type ) {
					$hints[] = 'WebP is usually 25-35% smaller at the same look';
				}
			}
			$items[] = array(
				'id'          => $id,
				'title'       => $post->post_title,
				'mime'        => $post->post_mime_type,
				'url'         => wp_get_attachment_url( $id ),
				'width'       => $w,
				'height'      => $h,
				'bytes'       => $size['main'],
				'bytes_total' => $size['all'],
				'sizes'       => is_array( $meta ) && ! empty( $meta['sizes'] ) ? count( $meta['sizes'] ) : 0,
				'suggestions' => $hints,
			);
		}
		return array(
			'items'  => $items,
			'server' => array(
				'editor'        => (string) _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) ),
				'webp_possible' => $webp,
			),
		);
	}

	/**
	 * Resize, crop and/or recompress in place.
	 *
	 * @param array  $p          {id, max_width?, max_height?, crop_ratio?, crop_box?, quality?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function edit( array $p, $command_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$post = self::image( $p['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( empty( $p['max_width'] ) && empty( $p['max_height'] ) && empty( $p['crop_ratio'] ) && empty( $p['crop_box'] ) && empty( $p['quality'] ) ) {
			return new \WP_Error( 'presshelm_nothing', 'Say what to do: max_width / max_height, crop_ratio or crop_box, and/or quality.' );
		}
		if ( ! empty( $p['crop_ratio'] ) && ! empty( $p['crop_box'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Give crop_ratio or crop_box, not both.' );
		}
		$main = get_attached_file( $post->ID, true );
		$ed   = wp_get_image_editor( $main );
		if ( is_wp_error( $ed ) ) {
			return new \WP_Error( 'presshelm_image_editor', 'This server cannot edit images (' . $ed->get_error_message() . ').' );
		}
		$size = $ed->get_size();
		$w    = (int) $size['width'];
		$h    = (int) $size['height'];
		if ( ! empty( $p['crop_box'] ) ) {
			$b = (array) $p['crop_box'];
			if ( (int) $b['x'] + (int) $b['width'] > $w || (int) $b['y'] + (int) $b['height'] > $h ) {
				return new \WP_Error( 'presshelm_payload', 'The crop box goes outside the image (' . $w . 'x' . $h . ').' );
			}
			$r = $ed->crop( (int) $b['x'], (int) $b['y'], (int) $b['width'], (int) $b['height'] );
		} elseif ( ! empty( $p['crop_ratio'] ) ) {
			list( $rw, $rh ) = array_map( 'intval', explode( ':', (string) $p['crop_ratio'] ) );
			$cw              = $w;
			$ch              = (int) round( $w * $rh / $rw );
			if ( $ch > $h ) {
				$ch = $h;
				$cw = (int) round( $h * $rw / $rh );
			}
			$r = $ed->crop( (int) floor( ( $w - $cw ) / 2 ), (int) floor( ( $h - $ch ) / 2 ), $cw, $ch );
		}
		if ( isset( $r ) && is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! empty( $p['max_width'] ) || ! empty( $p['max_height'] ) ) {
			$r = $ed->resize( empty( $p['max_width'] ) ? null : (int) $p['max_width'], empty( $p['max_height'] ) ? null : (int) $p['max_height'], false );
			// Already within the limits: WordPress refuses to enlarge, which is what we want.
			if ( is_wp_error( $r ) && 'error_getting_dimensions' !== $r->get_error_code() && 'image_resize_error' !== $r->get_error_code() ) {
				return $r;
			}
		}
		if ( ! empty( $p['quality'] ) ) {
			$ed->set_quality( (int) $p['quality'] );
		}
		$before = self::weight( $post->ID );
		$backup = MediaBackup::take( $post->ID );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$old_meta = wp_get_attachment_metadata( $post->ID );
		$tmp      = trailingslashit( get_temp_dir() ) . 'presshelm-edit-' . wp_generate_password( 12, false ) . '.' . pathinfo( $main, PATHINFO_EXTENSION );
		$saved    = $ed->save( $tmp, $post->post_mime_type );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$journal = Journal::record(
			$command_id,
			'media_replaced',
			(string) $post->ID,
			array(
				'backup'   => $backup,
				'metadata' => $old_meta,
				'mime'     => $post->post_mime_type,
			)
		);
		// The old sizes go (the backup keeps them); new ones are made from the edited file.
		$main_rel = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		MediaBackup::remove( array_diff( MediaBackup::files_of( $post->ID ), array( $main_rel ) ) );
		if ( ! copy( $saved['path'], $main ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- writing the media item's own file in uploads.
			wp_delete_file( $saved['path'] );
			MediaBackup::restore( $backup );
			wp_update_attachment_metadata( $post->ID, $old_meta );
			return new \WP_Error( 'presshelm_media_write', 'The edited image could not be written; the original was put back.' );
		}
		wp_delete_file( $saved['path'] );
		$meta = wp_generate_attachment_metadata( $post->ID, $main );
		wp_update_attachment_metadata( $post->ID, $meta );
		clean_post_cache( $post->ID );
		$after = self::weight( $post->ID );
		return array(
			'id'         => $post->ID,
			'url'        => wp_get_attachment_url( $post->ID ),
			'before'     => array(
				'width'  => $w,
				'height' => $h,
				'bytes'  => $before['main'],
			),
			'after'      => array(
				'width'  => (int) ( $meta['width'] ?? 0 ),
				'height' => (int) ( $meta['height'] ?? 0 ),
				'bytes'  => $after['main'],
			),
			'sizes'      => is_array( $meta ) && ! empty( $meta['sizes'] ) ? count( $meta['sizes'] ) : 0,
			'note'       => 'Browser and page caches may show the old image for a while.',
			'journal_id' => $journal,
		);
	}

	/**
	 * Converts images to WebP; links in content and Elementor follow.
	 *
	 * @param array  $p          {ids, quality?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function convert( array $p, $command_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return new \WP_Error( 'presshelm_no_webp', 'This server\'s image library cannot write WebP (ask the host to enable WebP in GD or Imagick). Nothing was changed.' );
		}
		$quality = (int) ( $p['quality'] ?? 82 );
		$ids     = array_values( array_unique( array_map( 'intval', (array) $p['ids'] ) ) );
		// Checked first: one bad id changes nothing.
		$posts = array();
		foreach ( $ids as $id ) {
			$post = self::image( $id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$posts[] = $post;
		}
		$done    = array();
		$skipped = array();
		$items   = array();
		$journal = Journal::record( $command_id, 'media_converted', 'bulk', array( 'items' => array() ) );
		foreach ( $posts as $post ) {
			if ( 'image/webp' === $post->post_mime_type ) {
				$skipped[] = array(
					'id'     => $post->ID,
					'reason' => 'already WebP',
				);
				continue;
			}
			$r = self::convert_one( $post, $quality );
			if ( is_wp_error( $r ) ) {
				$skipped[] = array(
					'id'     => $post->ID,
					'reason' => $r->get_error_message(),
				);
				continue;
			}
			$items[] = $r['undo'];
			$done[]  = $r['result'];
			// Saved after each image, so a failure part-way still leaves a complete undo record.
			self::update_journal( $journal, $items );
		}
		return array(
			'converted'   => $done,
			'skipped'     => $skipped,
			'bytes_saved' => array_sum( array_column( $done, 'saved' ) ),
			'note'        => 'The old files stay on the server, so links from outside the site keep working. Page caches may show the old images for a while.',
			'journal_id'  => $journal,
		);
	}

	/**
	 * Converts one image.
	 *
	 * @param \WP_Post $post    Attachment.
	 * @param int      $quality WebP quality.
	 * @return array{undo: array, result: array}|\WP_Error
	 */
	private static function convert_one( \WP_Post $post, $quality ) {
		$id     = $post->ID;
		$main   = get_attached_file( $id, true );
		$before = self::weight( $id );
		$ed     = wp_get_image_editor( $main );
		if ( is_wp_error( $ed ) ) {
			return $ed;
		}
		$ed->set_quality( $quality );
		$dir    = trailingslashit( dirname( $main ) );
		$name   = wp_unique_filename( $dir, pathinfo( $main, PATHINFO_FILENAME ) . '.webp' );
		$backup = MediaBackup::take( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$old_meta = wp_get_attachment_metadata( $id );
		$old_rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$saved    = $ed->save( $dir . $name, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// What links to it now (content and Elementor), saved before anything changes.
		$places = array();
		foreach ( Media::usage( $id )['places'] as $pl ) {
			if ( isset( $pl['post_id'] ) && in_array( $pl['how'], array( 'content', 'Elementor content' ), true ) ) {
				$places[ (int) $pl['post_id'] ] = true;
			}
		}
		$content = array();
		$el      = array();
		foreach ( array_keys( $places ) as $pid ) {
			$content[ $pid ] = (string) get_post_field( 'post_content', $pid, 'raw' );
			$raw             = get_post_meta( $pid, '_elementor_data', true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$el[ $pid ] = $raw;
			}
		}
		update_attached_file( $id, $saved['path'] );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/webp',
			)
		);
		$meta = wp_generate_attachment_metadata( $id, $saved['path'] );
		wp_update_attachment_metadata( $id, $meta );
		// Old file names → new ones (same size names; a size that no longer exists → the full image).
		$sub   = trailingslashit( dirname( $old_rel ) );
		$sub   = './' === $sub ? '' : $sub;
		$map   = array( $sub . basename( $old_rel ) => $sub . basename( $saved['path'] ) );
		$new_s = is_array( $meta ) && ! empty( $meta['sizes'] ) ? (array) $meta['sizes'] : array();
		foreach ( (array) ( $old_meta['sizes'] ?? array() ) as $size => $s ) {
			$map[ $sub . $s['file'] ] = $sub . ( isset( $new_s[ $size ]['file'] ) ? $new_s[ $size ]['file'] : basename( $saved['path'] ) );
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$escaped = array();
		foreach ( $map as $from => $to ) {
			$escaped[ str_replace( '/', '\/', $from ) ] = str_replace( '/', '\/', $to );
		}
		$updated = 0;
		foreach ( $content as $pid => $old ) {
			$new = strtr( $old, $map );
			if ( $new !== $old ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $pid,
							'post_content' => $new,
						)
					)
				);
				++$updated;
			}
		}
		foreach ( $el as $pid => $old ) {
			$new = strtr( strtr( $old, $escaped ), $map );
			if ( $new !== $old ) {
				update_post_meta( $pid, '_elementor_data', wp_slash( $new ) );
				delete_post_meta( $pid, '_elementor_css' );
			}
		}
		clean_post_cache( $id );
		$after = self::weight( $id );
		return array(
			'undo'   => array(
				'id'        => $id,
				'backup'    => $backup,
				'metadata'  => $old_meta,
				'mime'      => $post->post_mime_type,
				'file'      => $old_rel,
				'content'   => $content,
				'elementor' => $el,
			),
			'result' => array(
				'id'            => $id,
				'url'           => wp_get_attachment_url( $id ),
				'bytes_before'  => $before['main'],
				'bytes_after'   => $after['main'],
				'saved'         => max( 0, $before['main'] - $after['main'] ),
				'pages_updated' => $updated,
			),
		);
	}

	/**
	 * Writes the undo items into the journal entry made at the start.
	 *
	 * @param int   $journal Journal id.
	 * @param array $items   Undo items.
	 */
	private static function update_journal( $journal, array $items ) {
		global $wpdb;
		$wpdb->update( \PressHelm\Support\Schema::table( 'journal' ), array( 'before_state' => (string) wp_json_encode( array( 'items' => $items ) ) ), array( 'id' => (int) $journal ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/**
	 * Undo of a conversion: the WebP files go, the old files, details, content and Elementor data come back.
	 *
	 * @param array $items Undo items.
	 * @return true|\WP_Error
	 */
	public static function undo_convert( array $items ) {
		foreach ( array_reverse( $items ) as $it ) {
			$id   = (int) $it['id'];
			$keep = MediaBackup::paths( (string) ( $it['backup'] ?? '' ) );
			if ( null === $keep ) {
				return new \WP_Error( 'presshelm_media_backup_missing', 'The backup of media item ' . $id . ' is gone (backups are kept 30 days).' );
			}
			if ( get_post( $id ) ) {
				MediaBackup::remove( array_diff( MediaBackup::files_of( $id ), $keep ) );
			}
			$r = MediaBackup::restore( (string) $it['backup'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			update_post_meta( $id, '_wp_attached_file', (string) $it['file'] );
			wp_update_attachment_metadata( $id, (array) $it['metadata'] );
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => (string) $it['mime'],
				)
			);
			foreach ( (array) $it['content'] as $pid => $old ) {
				if ( get_post( (int) $pid ) ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => (int) $pid,
								'post_content' => (string) $old,
							)
						)
					);
				}
			}
			foreach ( (array) $it['elementor'] as $pid => $old ) {
				update_post_meta( (int) $pid, '_elementor_data', wp_slash( (string) $old ) );
				delete_post_meta( (int) $pid, '_elementor_css' );
			}
			clean_post_cache( $id );
		}
		return true;
	}
}
