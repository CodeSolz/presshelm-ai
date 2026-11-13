<?php
/**
 * Folder snapshots (M3.3, ADR-003): a full copy of a plugin or theme folder before a risky change
 * (e.g. an update), restorable exactly (files put back, files added since removed, hashes
 * verified). Stored in uploads/presshelm-snapshots/<64 hex>/ with index.php + .htaccess guards;
 * every copied file gets a `.ph-snap` suffix so a copied PHP file can never run from a URL.
 * Snapshots older than 30 days are pruned.
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

use PressHelm\Files\PathPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots.
 */
final class Snapshots {

	const MAX_BYTES = 104857600; // 100 MB.
	const MAX_FILES = 20000;
	const SUFFIX    = '.ph-snap';
	const KEEP_DAYS = 30;

	// phpcs:disable WordPress.WP.AlternativeFunctions -- local file copies inside uploads/presshelm-snapshots; WP_Filesystem has no equivalent for hashing/streaming copies.

	/**
	 * Base folder (created with guards on first use).
	 *
	 * @return string
	 */
	public static function base() {
		$up   = wp_upload_dir( null, false );
		$base = PathPolicy::norm( (string) $up['basedir'] ) . '/presshelm-snapshots';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $base . '/index.php' ) ) {
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $base;
	}

	/**
	 * Deletes snapshots older than KEEP_DAYS.
	 *
	 * @return int Number removed.
	 */
	public static function prune() {
		$n = 0;
		foreach ( (array) glob( self::base() . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/', basename( (string) $dir ) ) ) {
				continue;
			}
			$m = json_decode( (string) @file_get_contents( $dir . '/manifest.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a half-written snapshot has no manifest.
			$t = is_array( $m ) ? (int) ( $m['created_at'] ?? 0 ) : (int) filemtime( $dir );
			if ( $t < time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				self::delete_dir( $dir );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * All files under a folder (skips VCS folders and links).
	 *
	 * @param string $dir Folder with trailing slash.
	 * @return string[]
	 */
	public static function files( $dir ) {
		$out = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $f ) {
					return ! $f->isLink() && ! ( $f->isDir() && in_array( $f->getFilename(), array( '.git', '.svn' ), true ) );
				}
			)
		);
		foreach ( $it as $f ) {
			if ( $f->isFile() && 0 !== strpos( $f->getFilename(), '.ph-' ) ) {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}

	/**
	 * Removes a snapshot folder.
	 *
	 * @param string $dir Folder.
	 */
	public static function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				rmdir( $f->getPathname() );
			} else {
				unlink( $f->getPathname() );
			}
		}
		rmdir( $dir );
	}
	// phpcs:enable
}
