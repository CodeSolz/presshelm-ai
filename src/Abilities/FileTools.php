<?php
/**
 * Files in and out of the site, for any import, export or archive job: fetch a file (from a file
 * the owner sent in Telegram, or a link) into PressHelm's private file box, look inside it, zip
 * and unzip, send a file to the owner in Telegram, and install a plugin or theme from a ZIP.
 * Importers (WooCommerce products, WordPress content, any plugin's REST upload) take box files.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Files\FileBox;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * File tools.
 */
final class FileTools {

	const SEND_MAX  = 20971520; // 20 MB: what Telegram takes from a bot without hassle.
	const URL_MAX   = 209715200;
	const ZIP_FILES = 20000;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$fid   = array(
			'type'    => 'string',
			'pattern' => '^f_[a-f0-9]{12}$',
		);
		$read  = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$write = array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
		$def   = static function ( $risk, $cap, $mutates, $run, $label, $desc, $props, $required, $ann, $undo ) {
			return array(
				'risk'        => $risk,
				'cap'         => $cap,
				'mutates'     => $mutates,
				'run'         => $run,
				'label'       => $label,
				'description' => $desc,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $props,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'annotations' => $ann,
				'undo'        => $undo,
			) + ( $mutates && 'LOW' === $risk ? array( 'skip_health' => true ) : array() );
		};
		return array(
			'filebox.fetch'  => $def( 'LOW', 'upload_files', true, array( self::class, 'fetch' ), 'Fetch a file into PressHelm\'s file box', 'From a file the owner sent in Telegram (inbox_id) or a public https link. Stored privately on the site (closed to the web, kept 7 days) for an import or another step. Scripts and programs are refused.', array( 'inbox_id' => array( 'type' => 'string', 'pattern' => '^inb_[A-Za-z0-9_-]{8,40}$' ), 'url' => array( 'type' => 'string', 'maxLength' => 2000, 'pattern' => '^https://' ), 'name' => array( 'type' => 'string', 'maxLength' => 200 ) ), array(), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'filebox.list'   => $def( 'READ', 'upload_files', false, array( self::class, 'list_files' ), 'List the file box', 'Files waiting in PressHelm\'s private file box: id, name, type, size, where from, when.', array(), array(), $read, null ),
			'filebox.peek'   => $def( 'READ', 'upload_files', false, array( self::class, 'peek' ), 'Look inside a file', 'A CSV\'s columns, row count and first rows; the start of a JSON, XML or text file; the entries of a ZIP.', array( 'file_id' => $fid, 'rows' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ) ), array( 'file_id' ), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'filebox.zip'    => $def( 'LOW', 'manage_options', true, array( self::class, 'zip' ), 'Make a ZIP', 'Packs a folder of the uploads, or files from the box, into a ZIP in the box (wp-config.php, .htaccess and similar files are never included). Send it with filebox.send.', array( 'sources' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object' ) ), 'name' => array( 'type' => 'string', 'maxLength' => 100 ) ), array( 'sources' ), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'filebox.unzip'  => $def( 'LOW', 'upload_files', true, array( self::class, 'unzip' ), 'Unpack a ZIP into the box', 'Each file of a ZIP becomes a box file (scripts and programs inside are skipped and listed). Nothing is unpacked into the site itself.', array( 'file_id' => $fid ), array( 'file_id' ), $write, 'journal.restore' ),
			'filebox.send'   => $def( 'LOW', 'upload_files', false, array( self::class, 'send' ), 'Send a file to the owner', 'Sends a box file, or a file from the uploads folder, to the owner in Telegram (max 20 MB).', array( 'file_id' => $fid, 'upload_path' => array( 'type' => 'string', 'maxLength' => 500 ), 'caption' => array( 'type' => 'string', 'maxLength' => 500 ) ), array(), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'filebox.delete' => $def( 'LOW', 'upload_files', true, array( self::class, 'delete' ), 'Delete a box file', 'Removes a file from the file box.', array( 'file_id' => $fid ), array( 'file_id' ), $write, null ),
		);
	}

	// ---------------------------------------------------------------- in

	/**
	 * Fetches a file into the box.
	 *
	 * @param array  $p          {inbox_id|url, name?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function fetch( array $p, $command_id ) {
		if ( ! empty( $p['inbox_id'] ) ) {
			$got = self::from_inbox( (string) $p['inbox_id'] );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $tmp, $name ) = $got;
			$origin             = 'sent by the owner in Telegram';
		} elseif ( ! empty( $p['url'] ) ) {
			$url = self::direct_url( (string) $p['url'] );
			$tmp = wp_tempnam( 'presshelm-fetch' );
			// wp_safe_remote_get(): private and local addresses are refused.
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 300,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => self::URL_MAX,
				)
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'presshelm_download', 'The file could not be downloaded' . ( is_wp_error( $res ) ? ': ' . $res->get_error_message() : ' (HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . ')' ) . '.' );
			}
			$disp   = (string) wp_remote_retrieve_header( $res, 'content-disposition' );
			$name   = preg_match( '/filename\*?=(?:UTF-8\'\')?"?([^";]+)/i', $disp, $m ) ? rawurldecode( $m[1] ) : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$origin = 'downloaded from ' . (string) wp_parse_url( $url, PHP_URL_HOST );
		} else {
			return new \WP_Error( 'presshelm_payload', 'Give an inbox_id (a file the owner sent) or a url.' );
		}
		if ( ! empty( $p['name'] ) ) {
			$name = (string) $p['name'];
		}
		$file = FileBox::add( $tmp, $name, $origin );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$file['journal_id'] = Journal::record( $command_id, 'filebox_file', $file['file_id'], array() );
		return $file + array( 'next' => 'Look inside with filebox.peek, or use file_id with an importer.' );
	}

	/**
	 * A share link as a direct download link (GitHub "blob" pages, Dropbox share links).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function direct_url( $url ) {
		$url = (string) $url;
		if ( preg_match( '#^https://github\.com/[^/]+/[^/]+/blob/#', $url ) ) {
			// GitHub redirects ?raw=1 to the file itself.
			return add_query_arg( 'raw', '1', $url );
		}
		if ( preg_match( '#^https://(www\.)?dropbox\.com/#', $url ) ) {
			return add_query_arg( 'dl', '1', remove_query_arg( 'dl', $url ) );
		}
		return $url;
	}

	/**
	 * A file the owner sent in Telegram, fetched from the cloud with this site's signature.
	 *
	 * @param string $inbox_id Inbox id.
	 * @return array{0:string,1:string}|\WP_Error Temp path and name.
	 */
	public static function from_inbox( $inbox_id ) {
		$res = \PressHelm\Connect\Cloud::post( '/v1/connector/media', array( 'id' => $inbox_id ), true, 120 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['data'] ) ) {
			return new \WP_Error( 'presshelm_inbox_missing', (string) ( $res['json']['error']['message'] ?? 'This file is no longer available. Ask the owner to send it again.' ) );
		}
		$raw = base64_decode( (string) $res['json']['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- file bytes, not code.
		if ( false === $raw ) {
			return new \WP_Error( 'presshelm_payload', 'The file from PressHelm Cloud could not be read.' );
		}
		$tmp = wp_tempnam( 'presshelm-inbox' );
		file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file moved into the file box.
		return array( $tmp, (string) ( $res['json']['filename'] ?? 'file' ) );
	}

	/**
	 * The box.
	 *
	 * @return array
	 */
	public static function list_files() {
		return array( 'files' => FileBox::all() );
	}

	/**
	 * Looks inside a file.
	 *
	 * @param array $p {file_id, rows?}.
	 * @return array|\WP_Error
	 */
	public static function peek( array $p ) {
		$f = FileBox::get( (string) $p['file_id'] );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$path = $f['path'];
		$out  = $f['record'];
		$kind = $out['kind'];
		if ( 'csv' === $kind ) {
			$fh  = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading a box file row by row.
			$bom = fread( $fh, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			if ( "\xEF\xBB\xBF" !== $bom ) {
				rewind( $fh );
			}
			$first = (string) fgets( $fh );
			$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );
			$head  = str_getcsv( trim( $first ), $delim );
			$rows  = array();
			$count = 0;
			$want  = (int) ( $p['rows'] ?? 5 );
			while ( false !== ( $r = fgetcsv( $fh, 0, $delim ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- reading rows.
				if ( array( null ) === $r ) {
					continue;
				}
				++$count;
				if ( count( $rows ) < $want ) {
					$rows[] = array_map(
						static function ( $v ) {
							return strlen( (string) $v ) > 200 ? substr( (string) $v, 0, 200 ) . '…' : (string) $v;
						},
						array_slice( $r, 0, 60 )
					);
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			return $out + array(
				'delimiter' => "\t" === $delim ? 'tab' : $delim,
				'columns'   => array_slice( $head, 0, 120 ),
				'rows'      => $count,
				'first'     => $rows,
			);
		}
		if ( 'zip' === $kind ) {
			$all = \PressHelm\Files\Zip::entries( $path );
			if ( is_wp_error( $all ) ) {
				return $all;
			}
			$entries = array();
			foreach ( array_slice( $all, 0, 200 ) as $e ) {
				$entries[] = $e['name'] . ( $e['dir'] ? '' : ' (' . $e['size'] . ' bytes)' );
			}
			return $out + array(
				'entries'       => $entries,
				'entries_total' => count( $all ),
			);
		}
		if ( in_array( $kind, array( 'json', 'xml', 'text' ), true ) ) {
			$start = (string) file_get_contents( $path, false, null, 0, 4000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- box file.
			return $out + array( 'start' => \PressHelm\Abilities\Logs::clean( $start ) );
		}
		return $out;
	}

	// ---------------------------------------------------------------- archives

	/**
	 * Makes a ZIP in the box.
	 *
	 * @param array  $p          {sources: [{root: uploads|box, path?, file_id?}], name?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function zip( array $p, $command_id ) {
		$up    = trailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
		$pairs = array();
		foreach ( (array) $p['sources'] as $src ) {
			$root = (string) ( $src['root'] ?? '' );
			if ( 'box' === $root ) {
				$f = FileBox::get( (string) ( $src['file_id'] ?? '' ) );
				if ( is_wp_error( $f ) ) {
					return $f;
				}
				$pairs[ $f['path'] ] = $f['record']['name'];
				continue;
			}
			if ( 'uploads' !== $root ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown source "' . $root . '": use uploads (with a path) or box (with a file_id). Packing a theme or a plugin (its code) is part of the PressHelm Power Tools extension.' );
			}
			$base = $up;
			$sub  = trim( \PressHelm\Files\PathPolicy::norm( (string) ( $src['path'] ?? '' ) ), '/' );
			if ( false !== strpos( $sub, '..' ) ) {
				return new \WP_Error( 'presshelm_payload', 'Paths may not go up a folder.' );
			}
			$start  = '' === $sub ? $base : $base . $sub . '/';
			$prefix = 'uploads' . ( '' !== $sub ? '/' . $sub : '' );
			if ( is_file( rtrim( $start, '/' ) ) ) {
				$pairs[ rtrim( $start, '/' ) ] = $prefix;
				continue;
			}
			if ( ! is_dir( $start ) ) {
				return new \WP_Error( 'presshelm_not_found', 'Folder not found: ' . $prefix );
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $start, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$path = wp_normalize_path( $file->getPathname() );
				$rel  = substr( $path, strlen( $start ) );
				if ( in_array( strtolower( basename( $path ) ), \PressHelm\Files\PathPolicy::PROTECTED, true ) || false !== strpos( $path, '/presshelm-files-' ) || preg_match( '#(^|/)(\.git|\.svn|node_modules)/#', $rel ) || '.env' === basename( $path ) ) {
					continue;
				}
				// Data only: code files some plugins leave in uploads are not packed.
				if ( FileBox::executable( basename( $path ) ) || in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), FileBox::ZIP_CODE_EXT, true ) ) {
					continue;
				}
				if ( count( $pairs ) >= self::ZIP_FILES ) {
					return new \WP_Error( 'presshelm_zip', 'More than ' . self::ZIP_FILES . ' files: choose a smaller folder.' );
				}
				$pairs[ $path ] = $prefix . '/' . $rel;
			}
		}
		if ( ! $pairs ) {
			return new \WP_Error( 'presshelm_zip', 'Nothing to pack.' );
		}
		$tmp = wp_tempnam( 'presshelm-zip' );
		$r   = \PressHelm\Files\Zip::create( $tmp, $pairs );
		if ( is_wp_error( $r ) ) {
			wp_delete_file( $tmp );
			return $r;
		}
		$name = sanitize_file_name( (string) ( $p['name'] ?? 'archive' ) );
		$file = FileBox::add( $tmp, preg_match( '/\.zip$/i', $name ) ? $name : $name . '.zip', 'packed by PressHelm' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$file['files_packed'] = count( $pairs );
		$file['journal_id']   = Journal::record( $command_id, 'filebox_file', $file['file_id'], array() );
		return $file;
	}

	/**
	 * Unpacks a ZIP into the box (never into the site).
	 *
	 * @param array  $p          {file_id}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function unzip( array $p, $command_id ) {
		$f = FileBox::get( (string) $p['file_id'] );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( 'zip' !== $f['record']['kind'] ) {
			return new \WP_Error( 'presshelm_zip', 'That file is not a ZIP.' );
		}
		$all = \PressHelm\Files\Zip::entries( $f['path'] );
		if ( is_wp_error( $all ) ) {
			return $all;
		}
		$files   = array();
		$skipped = array();
		$ids     = array();
		foreach ( $all as $e ) {
			if ( count( $files ) >= 500 ) {
				$skipped[] = 'more than 500 files: the rest was not unpacked';
				break;
			}
			$name = $e['name'];
			if ( $e['dir'] ) {
				continue;
			}
			if ( FileBox::executable( $name ) || $e['size'] > FileBox::MAX_SIZE ) {
				$skipped[] = $name;
				continue;
			}
			$tmp = wp_tempnam( 'presshelm-unzip' );
			if ( ! \PressHelm\Files\Zip::extract( $f['path'], $name, $tmp ) ) {
				wp_delete_file( $tmp );
				$skipped[] = $name;
				continue;
			}
			// The path inside the ZIP becomes part of the name (never a folder on the site).
			$added = FileBox::add( $tmp, str_replace( '/', '--', $name ), 'from ' . $f['record']['name'] );
			if ( is_wp_error( $added ) ) {
				$skipped[] = $name;
				continue;
			}
			$ids[]   = $added['file_id'];
			$files[] = array(
				'file_id' => $added['file_id'],
				'name'    => $name,
				'kind'    => $added['kind'],
				'size'    => $added['size'],
			);
		}
		$journal = Journal::record( $command_id, 'filebox_files', implode( ',', $ids ), array() );
		return array(
			'files'      => $files,
			'skipped'    => $skipped,
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- out

	/**
	 * Sends a file to the owner in Telegram (through PressHelm Cloud).
	 *
	 * @param array $p {file_id|upload_path, caption?}.
	 * @return array|\WP_Error
	 */
	public static function send( array $p ) {
		if ( ! empty( $p['file_id'] ) ) {
			$f = FileBox::get( (string) $p['file_id'] );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			$path = $f['path'];
			$name = $f['record']['name'];
		} elseif ( ! empty( $p['upload_path'] ) ) {
			$base = trailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
			$rel  = ltrim( \PressHelm\Files\PathPolicy::norm( (string) $p['upload_path'] ), '/' );
			$path = $base . $rel;
			$real = wp_normalize_path( (string) realpath( $path ) );
			if ( false !== strpos( $rel, '..' ) || '' === $real || 0 !== strpos( $real, $base ) || ! is_file( $real ) || FileBox::executable( $rel ) ) {
				return new \WP_Error( 'presshelm_not_found', 'No such file in the uploads folder.' );
			}
			$name = basename( $real );
		} else {
			return new \WP_Error( 'presshelm_payload', 'Give a file_id or an upload_path.' );
		}
		$size = (int) filesize( $path );
		if ( $size > self::SEND_MAX ) {
			return new \WP_Error( 'presshelm_file_size', 'The file is larger than 20 MB, too big to send in Telegram. Zip a smaller part, or the owner downloads it from WordPress.' );
		}
		$res = \PressHelm\Connect\Cloud::post(
			'/v1/connector/files',
			array(
				'name'    => $name,
				'caption' => (string) ( $p['caption'] ?? '' ),
				'data'    => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file bytes for the owner.
			),
			true,
			180
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] ) {
			return new \WP_Error( 'presshelm_send', (string) ( $res['json']['error']['message'] ?? 'The file could not be sent.' ) );
		}
		return array(
			'sent' => $name,
			'size' => $size,
		);
	}

	/**
	 * Deletes a box file.
	 *
	 * @param array $p {file_id}.
	 * @return array
	 */
	public static function delete( array $p ) {
		FileBox::remove( (string) $p['file_id'] );
		return array( 'deleted' => (string) $p['file_id'] );
	}

	// ---------------------------------------------------------------- installs
}
