<?php
/**
 * Error logs, read only: WordPress's debug.log (WP_DEBUG_LOG) and PHP's error_log when it is a
 * file this site can read. Only the end of the file is read; repeated lines are grouped; secrets
 * (this site's database password and keys, tokens, passwords, cookies, long random strings) are
 * masked before anything leaves the site. Paths are shown relative to the WordPress folder.
 * Nothing is changed: turning logging on is a wp-config.php change the owner makes.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Logs.
 */
final class Logs {

	const READ_BYTES = 524288;
	const MAX_LINES  = 300;
	const MAX_GROUPS = 40;
	const LINE_CHARS = 600;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'logs.read' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'read' ),
				'label'       => 'Read the error log',
				'description' => 'The end of WordPress\'s debug.log (or PHP\'s error log): errors grouped by message with counts and last time, newest first, secrets masked. Also whether logging is on, and when WordPress last emailed the admin about a fatal error. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'source' => array(
							'type' => 'string',
							'enum' => array( 'wordpress', 'php' ),
						),
						'search' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'level'  => array(
							'type' => 'string',
							'enum' => array( 'all', 'fatal', 'warning', 'notice', 'deprecated' ),
						),
						'lines'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LINES,
						),
					),
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
	 * Where each log is, and whether it can be read.
	 *
	 * @return array<string,array{path:string,readable:bool,why?:string}>
	 */
	public static function sources() {
		$out = array();
		$wp  = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		if ( $wp ) {
			$path             = is_string( $wp ) && '' !== $wp && ! in_array( strtolower( $wp ), array( '1', 'true' ), true ) ? $wp : WP_CONTENT_DIR . '/debug.log';
			$out['wordpress'] = self::source( $path );
		} else {
			$out['wordpress'] = array(
				'path'     => '',
				'readable' => false,
				'why'      => 'WordPress logging is off (WP_DEBUG_LOG is not set in wp-config.php).',
			);
		}
		$php = (string) ini_get( 'error_log' );
		if ( '' !== $php && 'syslog' !== $php && ( empty( $out['wordpress']['path'] ) || wp_normalize_path( $php ) !== wp_normalize_path( $out['wordpress']['path'] ) ) ) {
			$out['php'] = self::source( $php );
		}
		return $out;
	}

	/**
	 * One log file's state.
	 *
	 * @param string $path Path.
	 * @return array{path:string,readable:bool,why?:string}
	 */
	private static function source( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( ! is_file( $path ) ) {
			return array(
				'path'     => $path,
				'readable' => false,
				'why'      => 'The log file does not exist yet (nothing was logged, or logging was switched on recently).',
			);
		}
		if ( ! is_readable( $path ) ) {
			return array(
				'path'     => $path,
				'readable' => false,
				'why'      => 'The log file exists, but this site cannot read it (file permissions).',
			);
		}
		return array(
			'path'     => $path,
			'readable' => true,
		);
	}

	/**
	 * Reads the end of a log.
	 *
	 * @param array $p {source?, search?, level?, lines?}.
	 * @return array|\WP_Error
	 */
	public static function read( array $p ) {
		$sources = self::sources();
		// The WordPress log is always the first source (a key, not a name: kept lowercase).
		$which  = (string) ( $p['source'] ?? ( ! empty( $sources['wordpress']['readable'] ) || empty( $sources['php']['readable'] ) ? array_key_first( $sources ) : 'php' ) );
		$status = array(
			'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true,
		);
		$fatal  = self::last_fatal_email();
		$src    = $sources[ $which ] ?? null;
		if ( ! $src || ! $src['readable'] ) {
			return array(
				'source'                 => $which,
				'logging'                => $status,
				'readable'               => false,
				'why'                    => $src['why'] ?? 'There is no such log on this site.',
				'fatal_error_email_sent' => $fatal,
				'how_to'                 => 'To log errors, the owner (or host) adds to wp-config.php: define( \'WP_DEBUG\', true ); define( \'WP_DEBUG_LOG\', true ); define( \'WP_DEBUG_DISPLAY\', false ); (visitors then see nothing; errors go to wp-content/debug.log). PressHelm never edits wp-config.php.',
			);
		}

		$size  = (int) filesize( $src['path'] );
		$chunk = self::tail( $src['path'], $size );
		$lines = preg_split( '/\r\n|\n|\r/', $chunk );
		if ( $size > self::READ_BYTES ) {
			array_shift( $lines ); // The first line is cut.
		}
		$search = (string) ( $p['search'] ?? '' );
		$level  = (string) ( $p['level'] ?? 'all' );
		$max    = (int) ( $p['lines'] ?? 200 );

		// Group by message (without the date), newest first.
		$groups = array();
		$total  = 0;
		foreach ( array_reverse( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) as $line ) {
			if ( $total >= $max ) {
				break;
			}
			if ( '' !== $search && false === stripos( $line, $search ) ) {
				continue;
			}
			$kind = self::kind( $line );
			if ( 'all' !== $level && $kind !== $level ) {
				continue;
			}
			++$total;
			$when = preg_match( '/^\[([^\]]{8,40})\]\s*/', $line, $m ) ? $m[1] : '';
			$msg  = self::clean( '' !== $when ? substr( $line, strlen( $m[0] ) ) : $line );
			$key  = md5( preg_replace( '/\d+/', '#', $msg ) );
			if ( ! isset( $groups[ $key ] ) ) {
				if ( count( $groups ) >= self::MAX_GROUPS ) {
					continue;
				}
				$groups[ $key ] = array(
					'kind'    => $kind,
					'message' => $msg,
					'count'   => 0,
					'last'    => $when,
				);
			}
			++$groups[ $key ]['count'];
		}
		$counts = array();
		foreach ( $groups as $g ) {
			$counts[ $g['kind'] ] = ( $counts[ $g['kind'] ] ?? 0 ) + $g['count'];
		}
		return array(
			'source'                 => $which,
			'file'                   => self::rel( $src['path'] ),
			'bytes'                  => $size,
			'modified_gmt'           => gmdate( 'Y-m-d H:i', (int) filemtime( $src['path'] ) ),
			'logging'                => $status,
			'read'                   => $size > self::READ_BYTES ? 'the last ' . (int) ( self::READ_BYTES / 1024 ) . ' KB' : 'the whole file',
			'lines_matched'          => $total,
			'by_kind'                => $counts,
			'groups'                 => array_values( $groups ),
			'fatal_error_email_sent' => $fatal,
			'other_sources'          => array_values( array_diff( array_keys( $sources ), array( $which ) ) ),
			'note'                   => 'Secrets are masked. Errors from before a fix stay in the log: compare the "last" times with when things changed.',
		);
	}

	/**
	 * The end of a file.
	 *
	 * @param string $path Path.
	 * @param int    $size Size.
	 * @return string
	 */
	private static function tail( $path, $size ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the end of a large log without loading it whole.
		if ( ! $fh ) {
			return '';
		}
		if ( $size > self::READ_BYTES ) {
			fseek( $fh, -self::READ_BYTES, SEEK_END );
		}
		$data = (string) fread( $fh, self::READ_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $data;
	}

	/**
	 * Error kind of a log line.
	 *
	 * @param string $line Line.
	 * @return string fatal|warning|notice|deprecated|other
	 */
	private static function kind( $line ) {
		if ( preg_match( '/PHP (Fatal error|Parse error)|Uncaught |Allowed memory size|Maximum execution time/i', $line ) ) {
			return 'fatal';
		}
		if ( preg_match( '/PHP Warning/i', $line ) ) {
			return 'warning';
		}
		if ( preg_match( '/PHP Notice/i', $line ) ) {
			return 'notice';
		}
		if ( preg_match( '/PHP Deprecated|is deprecated/i', $line ) ) {
			return 'deprecated';
		}
		return 'other';
	}

	/**
	 * A log message that is safe to send: paths shortened, secrets masked, length capped.
	 *
	 * @param string $msg Message.
	 * @return string
	 */
	public static function clean( $msg ) {
		$msg = str_replace( array( wp_normalize_path( ABSPATH ), untrailingslashit( ABSPATH ) . DIRECTORY_SEPARATOR, ABSPATH ), '', (string) $msg );
		// This site's own secrets, wherever they appear.
		$secrets = array();
		foreach ( array( 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $c ) {
			if ( defined( $c ) && strlen( (string) constant( $c ) ) >= 4 ) {
				$secrets[] = (string) constant( $c );
			}
		}
		if ( $secrets ) {
			$msg = str_replace( $secrets, '[secret]', $msg );
		}
		// Order matters: whole tokens first, then "name = value" pairs.
		$msg = preg_replace(
			array(
				'/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
				'/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]+/',
				'/\b(sk|pk|rk)[-_](?:live|test|ant|proj)?[-_]?[A-Za-z0-9_-]{16,}/',
				'/\bAIza[0-9A-Za-z_-]{30,}/',
				'/\b\d{6,}:[A-Za-z0-9_-]{30,}\b/',
				'/(wordpress_(?:logged_in|sec)?_?[0-9a-f]{32})=[^;\s]+/i',
				'/((?:pass(?:word)?|pwd|secret|token|api[_-]?key|authorization|cookie|session|nonce)["\']?\s*[:=>]+\s*["\']?)(?!\[masked|Bearer \[)[^\s"\',&;)]+/i',
				'/\b(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{32,}={0,2}/', // Long random-looking strings (keys, hashes), not paths.
			),
			array( 'Bearer [masked]', '[masked token]', '[masked key]', '[masked key]', '[masked token]', '$1=[masked]', '$1[masked]', '[masked]' ),
			$msg
		);
		return strlen( $msg ) > self::LINE_CHARS ? substr( $msg, 0, self::LINE_CHARS ) . '…' : $msg;
	}

	/**
	 * A path relative to the WordPress folder (or just its name when outside it).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function rel( $path ) {
		$root = wp_normalize_path( ABSPATH );
		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : '(outside WordPress) ' . basename( $path );
	}

	/**
	 * Recent fatal errors, for the capability report (monitoring, M4.9): when WordPress last emailed
	 * a fatal-error notice, and the fatal lines of the last hour in debug.log (count, newest, masked).
	 *
	 * @param int $seconds Window.
	 * @return array{fatal_email_at:int|null, fatals:int, last_fatal_at:string|null, last_fatal:string|null}
	 */
	public static function recent_fatals( $seconds = 3600 ) {
		$t   = (int) get_option( 'recovery_mode_email_last_sent', 0 );
		$out = array(
			'fatal_email_at' => $t > 0 ? $t : null,
			'fatals'         => 0,
			'last_fatal_at'  => null,
			'last_fatal'     => null,
		);
		$src = self::sources()['wordpress'] ?? null;
		if ( ! $src || empty( $src['readable'] ) || filemtime( $src['path'] ) < time() - $seconds ) {
			return $out;
		}
		$size  = (int) filesize( $src['path'] );
		$lines = preg_split( '/\r\n|\n|\r/', self::tail( $src['path'], $size ) );
		$since = time() - $seconds;
		foreach ( array_reverse( $lines ) as $line ) {
			if ( ! preg_match( '/^\[([^\]]{8,40})\]\s*(.*)$/', trim( (string) $line ), $m ) ) {
				continue;
			}
			$when = strtotime( $m[1] );
			if ( false === $when || $when < $since ) {
				break; // Older lines follow.
			}
			if ( 'fatal' === self::kind( $m[2] ) ) {
				++$out['fatals'];
				if ( null === $out['last_fatal'] ) {
					$out['last_fatal_at'] = gmdate( 'c', $when );
					$out['last_fatal']    = substr( self::clean( $m[2] ), 0, 300 );
				}
			}
		}
		return $out;
	}

	/**
	 * When WordPress last emailed the admin about a fatal error (its fatal error protection).
	 *
	 * @return string|null UTC time, or null.
	 */
	private static function last_fatal_email() {
		$t = (int) get_option( 'recovery_mode_email_last_sent', 0 );
		return $t > 0 ? gmdate( 'Y-m-d H:i', $t ) . ' UTC' : null;
	}
}
