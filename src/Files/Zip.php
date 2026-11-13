<?php
/**
 * ZIP archives with PHP's zip extension when the server has it, and WordPress's own PclZip when it
 * does not (the same fallback WordPress uses to unpack updates), so archives work on every host.
 *
 * @package PressHelm
 */

namespace PressHelm\Files;

defined( 'ABSPATH' ) || exit;

/**
 * ZIP helpers.
 */
final class Zip {

	/**
	 * PclZip, loaded.
	 *
	 * @param string $path Archive path.
	 * @return \PclZip
	 */
	private static function pcl( $path ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		return new \PclZip( $path );
	}

	/**
	 * Entries of an archive.
	 *
	 * @param string $path Archive.
	 * @return array<int,array{name:string,size:int,dir:bool}>|\WP_Error
	 */
	public static function entries( $path ) {
		$out = array();
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new \WP_Error( 'presshelm_zip', 'The ZIP could not be opened (damaged?).' );
			}
			$n = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			for ( $i = 0; $i < $n; $i++ ) {
				$s     = $zip->statIndex( $i );
				$out[] = array(
					'name' => (string) $s['name'],
					'size' => (int) $s['size'],
					'dir'  => '/' === substr( (string) $s['name'], -1 ),
				);
			}
			$zip->close();
			return $out;
		}
		$list = self::pcl( $path )->listContent();
		if ( ! is_array( $list ) ) {
			return new \WP_Error( 'presshelm_zip', 'The ZIP could not be opened (damaged?).' );
		}
		foreach ( $list as $e ) {
			$out[] = array(
				'name' => (string) $e['filename'],
				'size' => (int) $e['size'],
				'dir'  => ! empty( $e['folder'] ),
			);
		}
		return $out;
	}

	/**
	 * One entry's contents, written to a file.
	 *
	 * @param string $path Archive.
	 * @param string $name Entry name.
	 * @param string $dest Destination file.
	 * @return bool
	 */
	public static function extract( $path, $name, $dest ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return false;
			}
			$data = $zip->getFromName( $name );
			$zip->close();
		} else {
			$r    = self::pcl( $path )->extract( PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING );
			$data = is_array( $r ) && isset( $r[0]['content'] ) ? $r[0]['content'] : false;
		}
		if ( false === $data ) {
			return false;
		}
		return false !== file_put_contents( $dest, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file moved into the file box.
	}

	/**
	 * Creates an archive.
	 *
	 * @param string               $dest  Archive to create.
	 * @param array<string,string> $files Local path => name inside the archive.
	 * @return true|\WP_Error
	 */
	public static function create( $dest, array $files ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $dest, \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'presshelm_zip', 'The ZIP could not be created.' );
			}
			foreach ( $files as $local => $inside ) {
				$zip->addFile( $local, $inside );
			}
			return $zip->close() ? true : new \WP_Error( 'presshelm_zip', 'The ZIP could not be written.' );
		}
		wp_delete_file( $dest );
		$pcl = self::pcl( $dest );
		$add = array();
		foreach ( $files as $local => $inside ) {
			$add[] = array(
				PCLZIP_ATT_FILE_NAME          => $local,
				PCLZIP_ATT_FILE_NEW_FULL_NAME => $inside,
			);
		}
		// PclZip takes the files in chunks to keep memory low.
		foreach ( array_chunk( $add, 200 ) as $i => $chunk ) {
			$r = 0 === $i ? $pcl->create( $chunk ) : $pcl->add( $chunk );
			if ( 0 === $r ) {
				return new \WP_Error( 'presshelm_zip', 'The ZIP could not be written: ' . $pcl->errorInfo( true ) );
			}
		}
		return true;
	}
}
