<?php
/**
 * Caches, kept apart by layer:
 * - WordPress object cache (in memory, or persistent such as Redis/Memcached via a drop-in);
 * - page caches of plugins, cleared only through each plugin's own documented purge API;
 * - the hosting cache, detected from response headers and the host's own must-use plugin, cleared
 *   only where the host offers a PHP API;
 * - a CDN, detected from response headers; never cleared from here (that needs the CDN's API).
 * This plugin only inspects caches; it never clears them.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Cache layers.
 */
final class Cache {

	/**
	 * Page-cache plugins this plugin can detect: id => [name, detection callable].
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public static function page_plugins() {
		return array(
			'litespeed'        => array(
				'LiteSpeed Cache',
				function () {
					return defined( 'LSCWP_V' );
				},
			),
			'wp-rocket'        => array(
				'WP Rocket',
				function () {
					return function_exists( 'rocket_clean_domain' );
				},
			),
			'w3-total-cache'   => array(
				'W3 Total Cache',
				function () {
					return function_exists( 'w3tc_flush_all' );
				},
			),
			'wp-super-cache'   => array(
				'WP Super Cache',
				function () {
					return function_exists( 'wp_cache_clear_cache' );
				},
			),
			'wp-fastest-cache' => array(
				'WP Fastest Cache',
				function () {
					return class_exists( 'WpFastestCache' );
				},
			),
			'cache-enabler'    => array(
				'Cache Enabler',
				function () {
					return class_exists( 'Cache_Enabler' );
				},
			),
			'siteground'       => array(
				'SiteGround Speed Optimizer',
				function () {
					return function_exists( 'sg_cachepress_purge_everything' );
				},
			),
			'breeze'           => array(
				'Breeze',
				function () {
					return defined( 'BREEZE_VERSION' );
				},
			),
		);
	}

	/**
	 * Hosting and CDN layers from response headers (pure: unit-tested).
	 *
	 * @param array<string,string|string[]> $headers Response headers (any case).
	 * @return array{hosting:string[],cdn:string[]}
	 */
	public static function classify_headers( array $headers ) {
		$h = array();
		foreach ( $headers as $k => $v ) {
			$h[ strtolower( (string) $k ) ] = strtolower( is_array( $v ) ? implode( ',', $v ) : (string) $v );
		}
		$hosting = array();
		$cdn     = array();
		if ( isset( $h['x-litespeed-cache'] ) || false !== strpos( $h['server'] ?? '', 'litespeed' ) ) {
			$hosting[] = 'LiteSpeed server cache';
		}
		if ( isset( $h['x-varnish'] ) || false !== strpos( $h['via'] ?? '', 'varnish' ) ) {
			$hosting[] = 'Varnish';
		}
		if ( isset( $h['x-kinsta-cache'] ) ) {
			$hosting[] = 'Kinsta cache';
		}
		if ( isset( $h['x-proxy-cache'] ) && false !== strpos( $h['server'] ?? '', 'nginx' ) ) {
			$hosting[] = 'Nginx proxy cache (e.g. SiteGround)';
		}
		if ( isset( $h['wpe-backend'] ) || isset( $h['x-wpe-request-id'] ) ) {
			$hosting[] = 'WP Engine cache';
		}
		if ( isset( $h['x-nginx-cache'] ) || isset( $h['x-fastcgi-cache'] ) ) {
			$hosting[] = 'Nginx FastCGI cache';
		}
		if ( isset( $h['cf-cache-status'] ) || isset( $h['cf-ray'] ) || 'cloudflare' === ( $h['server'] ?? '' ) ) {
			$cdn[] = 'Cloudflare';
		}
		if ( isset( $h['x-amz-cf-id'] ) ) {
			$cdn[] = 'Amazon CloudFront';
		}
		if ( isset( $h['x-fastly-request-id'] ) || false !== strpos( $h['x-served-by'] ?? '', 'cache-' ) ) {
			$cdn[] = 'Fastly';
		}
		if ( false !== strpos( $h['server'] ?? '', 'bunnycdn' ) ) {
			$cdn[] = 'Bunny CDN';
		}
		if ( isset( $h['x-sucuri-id'] ) || isset( $h['x-sucuri-cache'] ) ) {
			$cdn[] = 'Sucuri firewall/CDN';
		}
		return array(
			'hosting' => array_values( array_unique( $hosting ) ),
			'cdn'     => array_values( array_unique( $cdn ) ),
		);
	}

	/**
	 * Hosting caches whose host offers a PHP API to clear them (names only; detection).
	 *
	 * @return string[]
	 */
	public static function hosting_apis() {
		$out = array();
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			$out[] = 'WP Engine cache';
		}
		return $out;
	}

	/**
	 * Layers without any HTTP request (for the capability report).
	 *
	 * @return array
	 */
	public static function summary() {
		$page = array();
		foreach ( self::page_plugins() as $id => $def ) {
			if ( call_user_func( $def[1] ) ) {
				$page[] = $def[0];
			}
		}
		$dropin = WP_CONTENT_DIR . '/object-cache.php';
		return array(
			'object'      => array(
				'persistent' => (bool) wp_using_ext_object_cache(),
				'dropin'     => is_readable( $dropin ) ? (string) ( get_file_data( $dropin, array( 'name' => 'Plugin Name' ) )['name'] ?? '' ) : '',
			),
			'page_plugin' => $page,
		);
	}

	/**
	 * Every layer, incl. hosting and CDN seen in the home page's response headers.
	 *
	 * @return array
	 */
	public static function inspect() {
		$s       = self::summary();
		$headers = array();
		$res     = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's loopback filter.
			)
		);
		if ( ! is_wp_error( $res ) ) {
			$headers = wp_remote_retrieve_headers( $res );
			$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		}
		$seen   = self::classify_headers( $headers );
		$apis   = self::hosting_apis();
		$multi  = is_multisite();
		$layers = array(
			'object'  => array(
				'what'      => $s['object']['persistent'] ? 'Persistent object cache' . ( $s['object']['dropin'] ? ' (' . $s['object']['dropin'] . ')' : '' ) : 'In-memory object cache (cleared on every page load anyway)',
				'can_clear' => true,
			),
			'page'    => array(),
			'hosting' => array(),
			'cdn'     => array(),
		);
		foreach ( self::page_plugins() as $id => $def ) {
			if ( in_array( $def[0], $s['page_plugin'], true ) ) {
				$layers['page'][] = array(
					'id'        => $id,
					'what'      => $def[0],
					'can_clear' => ! $multi,
					'why_not'   => $multi ? 'multisite: clearing would affect the other sites' : null,
				);
			}
		}
		foreach ( $seen['hosting'] as $name ) {
			$layers['hosting'][] = array(
				'what'      => $name,
				'can_clear' => in_array( $name, $apis, true ) && ! $multi,
				'why_not'   => in_array( $name, $apis, true ) ? ( $multi ? 'multisite' : null ) : 'the host offers no API PressHelm can use: clear it in the hosting panel, or through its WordPress plugin if one is listed under page caches',
			);
		}
		foreach ( $seen['cdn'] as $name ) {
			$layers['cdn'][] = array(
				'what'      => $name,
				'can_clear' => false,
				'why_not'   => 'needs a ' . $name . ' integration (API access) that PressHelm does not have: clear it in the ' . $name . ' dashboard',
			);
		}
		return array(
			'layers'        => $layers,
			'headers_check' => is_wp_error( $res ) ? 'the home page could not be loaded: ' . $res->get_error_message() : 'ok',
		);
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'cache.inspect' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'inspect' ),
				'label'       => 'Inspect caches',
				'description' => 'Object cache, page-cache plugins, hosting cache and CDN (read only), each with whether it has an API that could clear it and why not.',
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
}
