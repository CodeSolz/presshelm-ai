<?php
/**
 * Which files PressHelm may read or write (M3.2, ADR-003). Files are addressed as a named root
 * (e.g. `plugin:my-plugin`, `theme:my-theme`) plus a relative path. Refused: empty paths, NUL
 * bytes, absolute paths, `..`, dotfiles and dot-directories (.git, .env), protected names
 * (wp-config.php, .htaccess, .user.ini, php.ini), extensions outside the allowlist, symlinks, and
 * anything whose real location is outside the root. Pure PHP (tested without WordPress).
 *
 * @package PressHelm
 */

namespace PressHelm\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Path policy.
 */
final class PathPolicy {

	const WRITE_EXT = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'svg' );
	const READ_EXT  = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'svg', 'md', 'scss', 'twig', 'xml', 'pot' );
	const PROTECTED = array( 'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config' );

	/**
	 * Root name → absolute directory (normalized, with trailing slash).
	 *
	 * @var array<string,string>
	 */
	private $roots = array();

	/**
	 * Roots that may only be read (e.g. PressHelm's own folder).
	 *
	 * @var array<string,bool>
	 */
	private $read_only = array();

	/**
	 * Added to the "unknown root" error (e.g. how to work on a theme PressHelm may not change).
	 *
	 * @var string
	 */
	private $unknown_hint = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $roots        Name → directory. Directories that don't exist are skipped.
	 * @param string[]             $read_only    Root names that may never be written.
	 * @param string               $unknown_hint Added to the "unknown root" error.
	 */
	public function __construct( array $roots, array $read_only = array(), $unknown_hint = '' ) {
		$this->unknown_hint = (string) $unknown_hint;
		foreach ( $roots as $name => $dir ) {
			$real = realpath( (string) $dir );
			if ( false !== $real && is_dir( $real ) ) {
				$this->roots[ (string) $name ] = self::norm( $real ) . '/';
			}
		}
		foreach ( $read_only as $name ) {
			$this->read_only[ (string) $name ] = true;
		}
	}

	/**
	 * Known root names.
	 *
	 * @return string[]
	 */
	public function roots() {
		return array_keys( $this->roots );
	}

	/**
	 * Absolute directory of a root.
	 *
	 * @param string $root Root name.
	 * @return string
	 * @throws FileError When unknown.
	 */
	public function root_dir( $root ) {
		if ( ! isset( $this->roots[ $root ] ) ) {
			throw new FileError( 'unknown_root', 'Unknown root: ' . esc_html( $root ) . ( '' !== $this->unknown_hint && preg_match( '/^(theme|plugin):/', (string) $root ) ? '. ' . esc_html( $this->unknown_hint ) : '' ) );
		}
		return $this->roots[ $root ];
	}

	/**
	 * Resolves a file inside a root.
	 *
	 * @param string $root     Root name.
	 * @param string $relative Path relative to the root.
	 * @param bool   $write    For writing (stricter extension list; read-only roots refused).
	 * @return string Absolute normalized path (the file may not exist yet when writing).
	 * @throws FileError When refused.
	 */
	public function resolve( $root, $relative, $write ) {
		$base = $this->root_dir( $root );
		if ( $write && isset( $this->read_only[ $root ] ) ) {
			throw new FileError( 'read_only_root', 'This folder can never be changed by PressHelm' );
		}
		$rel = self::norm( (string) $relative );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) ) {
			throw new FileError( 'path_denied', 'Empty path or NUL byte' );
		}
		// No percent-encoding or control characters: harmless to the filesystem, but a layer that
		// decodes them later could turn %2e%2e into "..".
		if ( false !== strpos( $rel, '%' ) || preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
			throw new FileError( 'path_denied', 'Encoded or control characters are not allowed in paths' );
		}
		if ( preg_match( '#^([a-z]:|/|\\\\)#i', $rel ) || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $rel ) ) {
			throw new FileError( 'path_denied', 'Absolute paths and URLs are not allowed' );
		}
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				throw new FileError( 'path_denied', 'Path segments ".", ".." and empty segments are not allowed' );
			}
			if ( '.' === $part[0] ) {
				throw new FileError( 'path_denied', 'Hidden files and folders are not allowed' );
			}
		}
		$name = strtolower( (string) end( $parts ) );
		if ( in_array( $name, self::PROTECTED, true ) ) {
			throw new FileError( 'path_denied', 'Protected file: ' . esc_html( $name ) );
		}
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		if ( ! in_array( $ext, $write ? self::WRITE_EXT : self::READ_EXT, true ) ) {
			throw new FileError( 'path_denied', 'File type not allowed: .' . esc_html( $ext ) );
		}
		// Real location: the deepest existing ancestor must be inside the root (no symlink escape).
		$full     = $base . $rel;
		$dir      = dirname( $full );
		$base_len = strlen( $base );
		while ( ! file_exists( $dir ) && strlen( $dir ) > $base_len ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- $dir shrinks every iteration; its length must be re-read.
			$dir = dirname( $dir );
		}
		$real_dir = realpath( $dir );
		if ( false === $real_dir || 0 !== strpos( self::norm( $real_dir ) . '/', $base ) ) {
			throw new FileError( 'path_denied', 'Outside the allowed folder' );
		}
		if ( is_link( $full ) ) {
			throw new FileError( 'path_denied', 'Symbolic links are not followed' );
		}
		if ( file_exists( $full ) ) {
			$real = realpath( $full );
			if ( false === $real || 0 !== strpos( self::norm( $real ), $base ) || ! is_file( $real ) ) {
				throw new FileError( 'path_denied', 'Outside the allowed folder' );
			}
		}
		return $full;
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $p Path.
	 * @return string
	 */
	public static function norm( $p ) {
		return rtrim( str_replace( '\\', '/', $p ), '/' );
	}
}
