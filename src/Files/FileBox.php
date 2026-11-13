<?php
/**
 * PressHelm's private file box on the site: where files the owner sends (or that PressHelm fetches
 * or produces: imports, exports, archives) wait for the next step. A folder under uploads with a
 * random name, closed to the web (.htaccess, web.config, index.php), never listed by WordPress.
 * Files are stored under a random id; names are sanitized. The box keeps data only: files that could
 * run on the server (PHP and other scripts or programs), and ZIP archives containing any such file or
 * web code (JavaScript, HTML), are refused before they are stored. Files expire after 7 days.
 *
 * @package PressHelm
 */

namespace PressHelm\Files;

defined( 'ABSPATH' ) || exit;

/**
 * File box.
 */
final class FileBox {

	const OPTION   = 'presshelm_filebox';
	const TTL_DAYS = 7;
	const MAX_SIZE = 209715200; // 200 MB.

	/** Extensions that could be executed by a web server: refused, or neutralized inside archives. */
	/** Inside a ZIP, web code is refused too (the box keeps data, not plugins, themes or sites). */
	const ZIP_CODE_EXT = array( 'js', 'mjs', 'cjs', 'html', 'htm', 'xhtml', 'svgz', 'jar', 'wasm' );

	const EXEC_EXT = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'shtml', 'htaccess', 'htpasswd', 'ini', 'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'vbs', 'ps1' );

	/**
	 * The box folder (created and closed on first use).
	 *
	 * @return string Absolute path with a trailing slash.
	 */
	public static function dir() {
		$suffix = (string) get_option( self::OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 12 ) );
			update_option( self::OPTION, $suffix, false );
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( wp_normalize_path( $up['basedir'] ) ) . 'presshelm-files-' . $suffix . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$closed = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $closed as $name => $body ) {
			if ( ! is_file( $dir . $name ) ) {
				file_put_contents( $dir . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- PressHelm's own private folder.
			}
		}
		return $dir;
	}

	/**
	 * Whether a name has an extension that could run on the server.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function executable( $name ) {
		$name = strtolower( (string) $name );
		foreach ( explode( '.', $name ) as $i => $part ) {
			if ( $i > 0 && in_array( $part, self::EXEC_EXT, true ) ) {
				return true; // Also catches "x.php.csv" style double extensions.
			}
		}
		return '.' === substr( basename( $name ), 0, 1 ) || '.user.ini' === basename( $name );
	}

	/**
	 * Type of a file, from its bytes first (never only from the name).
	 *
	 * @param string $path File.
	 * @param string $name Name.
	 * @return string zip|image|pdf|csv|json|xml|text|binary
	 */
	public static function kind( $path, $name ) {
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file in the box.
		$ext  = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( 0 === strpos( $head, "PK\x03\x04" ) || 0 === strpos( $head, "PK\x05\x06" ) ) {
			return 'zip';
		}
		if ( 0 === strpos( $head, '%PDF-' ) ) {
			return 'pdf';
		}
		if ( preg_match( '/^(\xFF\xD8\xFF|\x89PNG|GIF8|RIFF....WEBP)/s', $head ) ) {
			return 'image';
		}
		if ( preg_match( '/^(MZ|\x7FELF|#!)/', $head ) || false !== stripos( $head, '<?php' ) ) {
			return 'binary'; // Programs and scripts are never treated as data.
		}
		if ( false !== strpos( $head, "\0" ) ) {
			return 'binary';
		}
		if ( in_array( $ext, array( 'csv', 'tsv' ), true ) ) {
			return 'csv';
		}
		if ( 'json' === $ext || preg_match( '/^\s*[\[{]/', $head ) ) {
			return 'json';
		}
		if ( 'xml' === $ext || 0 === strpos( ltrim( $head ), '<?xml' ) ) {
			return 'xml';
		}
		return 'text';
	}

	/**
	 * Why a ZIP may not be stored (it contains code, or cannot be read), or '' when it holds data only.
	 *
	 * @param string $path ZIP file.
	 * @return string
	 */
	public static function zip_code( $path ) {
		$entries = Zip::entries( $path );
		if ( is_wp_error( $entries ) ) {
			return 'This ZIP could not be read, so its contents could not be checked. Nothing was saved.';
		}
		foreach ( $entries as $e ) {
			if ( $e['dir'] ) {
				continue;
			}
			$ext = strtolower( pathinfo( (string) $e['name'], PATHINFO_EXTENSION ) );
			if ( self::executable( basename( (string) $e['name'] ) ) || in_array( $ext, self::ZIP_CODE_EXT, true ) ) {
				return 'This ZIP contains program code (' . sanitize_file_name( basename( (string) $e['name'] ) ) . '), like a plugin, a theme or a website. PressHelm\'s file box keeps data only (images, documents, spreadsheets, exports), so it was not stored. Installing plugins and themes from ZIP files is part of the separate PressHelm Power Tools extension.';
			}
		}
		return '';
	}

	/**
	 * Stores a local file (moved) in the box.
	 *
	 * @param string $tmp    Temporary file (moved into the box).
	 * @param string $name   Wanted name.
	 * @param string $origin Where it came from (for the listing).
	 * @return array|\WP_Error File record.
	 */
	public static function add( $tmp, $name, $origin ) {
		self::cleanup();
		$name = sanitize_file_name( '' !== (string) $name ? (string) $name : 'file' );
		if ( self::executable( $name ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'presshelm_file_type', 'Files that could run on the server (PHP and other scripts or programs) are not stored. Nothing was saved.' );
		}
		$size = (int) filesize( $tmp );
		if ( $size > self::MAX_SIZE ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'presshelm_file_size', 'The file is larger than 200 MB.' );
		}
		$kind = self::kind( $tmp, $name );
		if ( 'zip' === $kind ) {
			$code = self::zip_code( $tmp );
			if ( '' !== $code ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'presshelm_file_type', $code );
			}
		}
		if ( 'binary' === $kind && ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'xlsx', 'xls', 'docx', 'doc', 'odt', 'ods', 'mp4', 'mp3', 'woff', 'woff2', 'ttf', 'otf', 'ico' ), true ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'presshelm_file_type', 'This looks like a program or script, not a data file, so it was not stored.' );
		}
		$id   = 'f_' . bin2hex( random_bytes( 6 ) );
		$dest = self::dir() . $id . '__' . $name;
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- falls back to copy below.
			if ( ! copy( $tmp, $dest ) ) {
				return new \WP_Error( 'presshelm_file_store', 'The file could not be stored on the site.' );
			}
			wp_delete_file( $tmp );
		}
		$meta        = (array) get_option( self::OPTION . '_index', array() );
		$meta[ $id ] = array(
			'name'   => $name,
			'kind'   => $kind,
			'size'   => $size,
			'sha256' => hash_file( 'sha256', $dest ),
			'origin' => substr( (string) $origin, 0, 200 ),
			'at'     => time(),
		);
		update_option( self::OPTION . '_index', $meta, false );
		return array( 'file_id' => $id ) + $meta[ $id ];
	}

	/**
	 * Path of a stored file.
	 *
	 * @param string $id File id.
	 * @return array{path:string,record:array}|\WP_Error
	 */
	public static function get( $id ) {
		$meta = (array) get_option( self::OPTION . '_index', array() );
		if ( ! preg_match( '/^f_[a-f0-9]{12}$/', (string) $id ) || empty( $meta[ $id ] ) ) {
			return new \WP_Error( 'presshelm_file_missing', 'No such file in PressHelm\'s file box (files are kept 7 days). See file_list.' );
		}
		$path = self::dir() . $id . '__' . $meta[ $id ]['name'];
		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'presshelm_file_missing', 'The file is no longer on the site.' );
		}
		return array(
			'path'   => $path,
			'record' => array( 'file_id' => $id ) + $meta[ $id ],
		);
	}

	/**
	 * Stored files, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		self::cleanup();
		$out = array();
		foreach ( (array) get_option( self::OPTION . '_index', array() ) as $id => $r ) {
			$out[] = array( 'file_id' => $id ) + $r;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['at'] <=> $a['at'];
			}
		);
		return $out;
	}

	/**
	 * Removes a stored file.
	 *
	 * @param string $id File id.
	 * @return bool
	 */
	public static function remove( $id ) {
		$f = self::get( $id );
		if ( ! is_wp_error( $f ) ) {
			wp_delete_file( $f['path'] );
		}
		$meta = (array) get_option( self::OPTION . '_index', array() );
		unset( $meta[ $id ] );
		update_option( self::OPTION . '_index', $meta, false );
		return true;
	}

	/**
	 * Deletes expired files.
	 *
	 * @return void
	 */
	public static function cleanup() {
		$meta    = (array) get_option( self::OPTION . '_index', array() );
		$changed = false;
		foreach ( $meta as $id => $r ) {
			if ( (int) ( $r['at'] ?? 0 ) < time() - self::TTL_DAYS * DAY_IN_SECONDS ) {
				$path = self::dir() . $id . '__' . ( $r['name'] ?? '' );
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
				unset( $meta[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION . '_index', $meta, false );
		}
	}
}
