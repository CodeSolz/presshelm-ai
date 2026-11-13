<?php
/**
 * Media backups (A2): the files of one attachment (main file, every generated size and the
 * original upload) copied before PressHelm replaces or deletes it, so undo can put them back
 * exactly. Stored next to the folder snapshots (uploads/presshelm-snapshots/<64 hex>/, guarded,
 * `.ph-snap` suffix, pruned after 30 days with them).
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

use PressHelm\Files\PathPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Media backups.
 */
final class MediaBackup {

	const MAX_BYTES = 209715200; // 200 MB.

	// phpcs:disable WordPress.WP.AlternativeFunctions -- copies of one attachment's files inside uploads/presshelm-snapshots; WP_Filesystem has no hashing/streaming copy.

	/**
	 * The uploads folder (normalized, trailing slash).
	 *
	 * @return string
	 */
	private static function uploads() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( PathPolicy::norm( (string) $up['basedir'] ) );
	}

	/**
	 * Every file of an attachment that exists, relative to the uploads folder.
	 *
	 * @param int $id Attachment id.
	 * @return string[]
	 */
	public static function files_of( $id ) {
		$base = self::uploads();
		$main = get_attached_file( $id, true );
		if ( ! $main ) {
			return array();
		}
		$main  = PathPolicy::norm( (string) $main );
		$dir   = trailingslashit( dirname( $main ) );
		$all   = array( $main );
		$meta  = wp_get_attachment_metadata( $id );
		$sizes = is_array( $meta ) && ! empty( $meta['sizes'] ) ? (array) $meta['sizes'] : array();
		foreach ( $sizes as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$all[] = $dir . basename( (string) $size['file'] );
			}
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$all[] = $dir . basename( (string) $meta['original_image'] );
		}
		$out = array();
		foreach ( array_unique( $all ) as $abs ) {
			if ( 0 === strpos( $abs, $base ) && is_file( $abs ) && ! is_link( $abs ) ) {
				$out[] = substr( $abs, strlen( $base ) );
			}
		}
		return $out;
	}

	/**
	 * Copies an attachment's files.
	 *
	 * @param int $id Attachment id.
	 * @return string|\WP_Error Backup id.
	 */
	public static function take( $id ) {
		$base  = self::uploads();
		$files = self::files_of( $id );
		if ( ! $files ) {
			return new \WP_Error( 'presshelm_media_files', 'The file of this media item is missing on the server.' );
		}
		$total = 0;
		foreach ( $files as $rel ) {
			$total += (int) filesize( $base . $rel );
		}
		if ( $total > self::MAX_BYTES ) {
			return new \WP_Error( 'presshelm_media_large', 'This media item is too large to back up (over 200 MB); nothing was changed.' );
		}
		$bid = bin2hex( random_bytes( 32 ) );
		$dir = Snapshots::base() . '/' . $bid;
		wp_mkdir_p( $dir . '/f' );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		$manifest = array(
			'kind'       => 'media',
			'attachment' => (int) $id,
			'created_at' => time(),
			'files'      => array(),
		);
		foreach ( array_values( $files ) as $i => $rel ) {
			$copy = $dir . '/f/' . $i . Snapshots::SUFFIX;
			if ( ! copy( $base . $rel, $copy ) ) {
				Snapshots::delete_dir( $dir );
				return new \WP_Error( 'presshelm_media_backup', 'The media file could not be backed up; nothing was changed.' );
			}
			$manifest['files'][] = array(
				'rel'    => $rel,
				'copy'   => $i . Snapshots::SUFFIX,
				'sha256' => hash_file( 'sha256', $copy ),
			);
		}
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) );
		return $bid;
	}

	/**
	 * The relative paths a backup holds.
	 *
	 * @param string $bid Backup id.
	 * @return string[]|null
	 */
	public static function paths( $bid ) {
		$m = self::manifest( $bid );
		return $m ? array_column( (array) $m['files'], 'rel' ) : null;
	}

	/**
	 * Puts the backed-up files back (hashes checked first; nothing is written if one is damaged).
	 *
	 * @param string $bid Backup id.
	 * @return int|\WP_Error Files restored.
	 */
	public static function restore( $bid ) {
		$m = self::manifest( $bid );
		if ( ! $m ) {
			return new \WP_Error( 'presshelm_media_backup_missing', 'The backup of this media item is gone (backups are kept 30 days).' );
		}
		$dir  = Snapshots::base() . '/' . $bid . '/f/';
		$base = self::uploads();
		foreach ( (array) $m['files'] as $f ) {
			if ( ! is_file( $dir . $f['copy'] ) || hash_file( 'sha256', $dir . $f['copy'] ) !== $f['sha256'] || ! self::safe_rel( (string) $f['rel'] ) ) {
				return new \WP_Error( 'presshelm_media_backup_corrupt', 'The backup of this media item is damaged; nothing was changed.' );
			}
		}
		$n = 0;
		foreach ( (array) $m['files'] as $f ) {
			$target = $base . $f['rel'];
			wp_mkdir_p( dirname( $target ) );
			if ( copy( $dir . $f['copy'], $target ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Deletes files of the uploads folder (relative paths), e.g. sizes made by a replacement.
	 *
	 * @param string[] $rels Relative paths.
	 */
	public static function remove( array $rels ) {
		$base = self::uploads();
		foreach ( $rels as $rel ) {
			if ( self::safe_rel( (string) $rel ) && is_file( $base . $rel ) && ! is_link( $base . $rel ) ) {
				wp_delete_file( $base . $rel );
			}
		}
	}

	/**
	 * A backup's manifest, or null.
	 *
	 * @param string $bid Backup id.
	 * @return array|null
	 */
	private static function manifest( $bid ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $bid ) ) {
			return null;
		}
		$raw = @file_get_contents( Snapshots::base() . '/' . $bid . '/manifest.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pruned backup has no manifest.
		$m   = json_decode( (string) $raw, true );
		return is_array( $m ) && 'media' === ( $m['kind'] ?? '' ) ? $m : null;
	}

	/**
	 * A relative path inside uploads (no traversal, no PressHelm folders, no executable files).
	 *
	 * @param string $rel Path.
	 * @return bool
	 */
	private static function safe_rel( $rel ) {
		return '' !== $rel && false === strpos( $rel, '..' ) && false === strpos( $rel, "\0" ) && '/' !== $rel[0] && 0 !== strpos( $rel, 'presshelm-' ) && ! preg_match( '/\.(php\d?|phtml|phar|htaccess)$/i', $rel );
	}
}
