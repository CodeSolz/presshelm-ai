<?php
/**
 * Health gate after a change (ADR-003): uses WordPress core's own scrape mechanism (the same
 * one the theme/plugin editor uses) on loopback requests to the front page and to wp-admin as
 * the acting user (some breakages only show in the admin), and checks twice with a wait because
 * the web server's OPcache can serve an old file for a moment.
 *
 * When something in front of WordPress answers instead (a CDN or host bot challenge, "Checking your
 * browser…"), the request is repeated straight to this server, bypassing the CDN. If it is still
 * challenged, the result says `blocked`: the check could not run, which says nothing about the change.
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback health check.
 */
final class HealthCheck {

	/** Page titles and markers of bot challenges (Cloudflare, Sucuri, Imunify360, SiteGround, ...). */
	const CHALLENGE = '/checking your browser|just a moment|attention required|one more step|security check|verify you are human|ddos protection|cf-chl|challenge-platform|sgcaptcha|imunify|bot protection/i';

	/**
	 * One loopback check of the front page.
	 *
	 * @return array{ok:bool,http?:int,reason?:string}
	 */
	public static function front() {
		return self::probe( home_url( '/' ), array() );
	}

	/**
	 * One loopback check of wp-admin as the current user, with a short-lived login that is
	 * destroyed right after. Skipped (ok) when there is no current user.
	 *
	 * @return array{ok:bool,http?:int,reason?:string,skipped?:string}
	 */
	public static function admin() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return array(
				'ok'      => true,
				'skipped' => 'no_user',
			);
		}
		$expire  = time() + 5 * MINUTE_IN_SECONDS;
		$manager = \WP_Session_Tokens::get_instance( $uid );
		$token   = $manager->create( $expire );
		$secure  = 0 === strpos( admin_url(), 'https://' );
		$cookies = array(
			new \WP_Http_Cookie(
				array(
					'name'  => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE,
					'value' => wp_generate_auth_cookie( $uid, $expire, $secure ? 'secure_auth' : 'auth', $token ),
				)
			),
			new \WP_Http_Cookie(
				array(
					'name'  => LOGGED_IN_COOKIE,
					'value' => wp_generate_auth_cookie( $uid, $expire, 'logged_in', $token ),
				)
			),
		);
		try {
			return self::probe( admin_url( 'index.php' ), $cookies, false ); // A redirect here (e.g. to the login page) is a finding, not a page to follow.
		} finally {
			$manager->destroy( $token );
		}
	}

	/**
	 * One public URL (e.g. the page a change touched), with the same no-fatal marker.
	 *
	 * @param string $url URL on this site.
	 * @return array{ok:bool,http?:int,reason?:string}
	 */
	public static function url( $url ) {
		return self::probe( $url, array() );
	}

	/**
	 * Whether request() keeps the page body (fetch only).
	 *
	 * @var bool
	 */
	private static $keep_body = false;

	/**
	 * A page as a logged-out visitor receives it (or, with $as_user, as the connected admin), fetched
	 * fresh (past page caches where possible), with its body (up to 3 MB).
	 *
	 * @param string $url     URL on this site.
	 * @param bool   $as_user Fetch logged in as the current user (past a coming-soon page).
	 * @return array{ok:bool,http?:int,reason?:string,blocked?:bool,body?:string}
	 */
	public static function fetch( $url, $as_user = false ) {
		self::$keep_body = true;
		$uid             = $as_user ? get_current_user_id() : 0;
		$token           = '';
		$cookies         = array();
		if ( $uid ) {
			$expire  = time() + 5 * MINUTE_IN_SECONDS;
			$token   = \WP_Session_Tokens::get_instance( $uid )->create( $expire );
			$secure  = 0 === strpos( home_url(), 'https://' );
			$cookies = array(
				new \WP_Http_Cookie(
					array(
						'name'  => LOGGED_IN_COOKIE,
						'value' => wp_generate_auth_cookie( $uid, $expire, 'logged_in', $token ),
					)
				),
				new \WP_Http_Cookie(
					array(
						'name'  => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE,
						'value' => wp_generate_auth_cookie( $uid, $expire, $secure ? 'secure_auth' : 'auth', $token ),
					)
				),
			);
		}
		try {
			return self::probe( $url, $cookies );
		} finally {
			self::$keep_body = false;
			if ( $uid && '' !== $token ) {
				\WP_Session_Tokens::get_instance( $uid )->destroy( $token );
			}
		}
	}

	/**
	 * Whether visitors get a coming-soon or maintenance page instead of the site (and from what).
	 *
	 * @return string '' when visitors see the site.
	 */
	public static function visitor_gate() {
		if ( 'yes' === get_option( 'woocommerce_coming_soon' ) && 'yes' !== get_option( 'woocommerce_store_pages_only' ) ) {
			return 'WooCommerce "Coming soon" mode';
		}
		$elementor = (string) get_option( 'elementor_maintenance_mode_mode', '' );
		if ( in_array( $elementor, array( 'coming_soon', 'maintenance' ), true ) ) {
			return 'Elementor ' . str_replace( '_', ' ', $elementor ) . ' mode';
		}
		$seed = json_decode( (string) get_option( 'seedprod_settings', '' ), true );
		if ( is_array( $seed ) && ( ! empty( $seed['enable_coming_soon_mode'] ) || ! empty( $seed['enable_maintenance_mode'] ) ) ) {
			return 'SeedProd ' . ( ! empty( $seed['enable_maintenance_mode'] ) ? 'maintenance' : 'coming soon' ) . ' mode';
		}
		$wpmm = get_option( 'wpmm_settings' );
		if ( is_array( $wpmm ) && ! empty( $wpmm['general']['status'] ) ) {
			return 'WP Maintenance Mode';
		}
		return '';
	}

	/**
	 * Front page and wp-admin.
	 *
	 * @return array{ok:bool,http?:int,reason?:string,where?:string}
	 */
	public static function both() {
		$front = self::front();
		if ( ! $front['ok'] ) {
			return $front + array( 'where' => 'front' );
		}
		$admin = self::admin();
		if ( ! $admin['ok'] ) {
			return $admin + array( 'where' => 'admin' );
		}
		return $front;
	}

	/**
	 * Health gate: check now, then again after the OPcache revalidation window.
	 *
	 * @param int $wait Seconds to wait (default 3).
	 * @return array{ok:bool,http?:int,reason?:string,where?:string}
	 */
	public static function gate( $wait = 3 ) {
		$first = self::both();
		if ( ! $first['ok'] ) {
			return $first;
		}
		sleep( max( 0, (int) $wait ) );
		return self::both();
	}

	/**
	 * Loopback check; when a bot challenge answers, repeated straight to this server.
	 *
	 * @param string            $url     URL.
	 * @param \WP_Http_Cookie[] $cookies Cookies.
	 * @param bool              $follow  Follow redirects.
	 * @return array{ok:bool,http?:int,reason?:string,blocked?:bool,via?:string}
	 */
	private static function probe( $url, array $cookies, $follow = true ) {
		$r = self::probe_once( $url, $cookies, $follow, '' );
		if ( empty( $r['challenge'] ) ) {
			return $r;
		}
		// Challenged: ask this server directly (same host name and TLS, no CDN in between).
		foreach ( self::origin_ips() as $ip ) {
			$direct = self::probe_once( $url, $cookies, $follow, $ip );
			if ( empty( $direct['challenge'] ) && ( $direct['ok'] || isset( $direct['http'] ) ) ) {
				return $direct + array( 'via' => 'origin' );
			}
		}
		unset( $r['challenge'] );
		return $r + array( 'blocked' => true );
	}

	/**
	 * Addresses of this server to try when a CDN challenges the site's own request.
	 *
	 * @return string[]
	 */
	private static function origin_ips() {
		$ips = array();
		if ( isset( $_SERVER['SERVER_ADDR'] ) ) {
			$ips[] = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
		}
		$ips[] = '127.0.0.1';
		$ips   = (array) apply_filters( 'presshelm_loopback_ips', $ips );
		return array_values(
			array_unique(
				array_filter(
					$ips,
					static function ( $ip ) {
						return false !== filter_var( $ip, FILTER_VALIDATE_IP );
					}
				)
			)
		);
	}

	/**
	 * One loopback request, optionally pinned to an address of this server.
	 *
	 * @param string            $url     URL.
	 * @param \WP_Http_Cookie[] $cookies Cookies.
	 * @param bool              $follow  Follow redirects.
	 * @param string            $ip      Pin the host name to this address ('' = normal DNS).
	 * @return array{ok:bool,http?:int,reason?:string,challenge?:bool}
	 */
	private static function probe_once( $url, array $cookies, $follow, $ip ) {
		$pin = null;
		if ( '' !== $ip ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$port = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ? wp_parse_url( $url, PHP_URL_PORT ) : ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 ) );
			$pin  = static function ( $handle ) use ( $host, $port, $ip ) {
				if ( defined( 'CURLOPT_RESOLVE' ) ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the site's own host name to this server for its loopback check; WordPress offers no API for it.
				}
			};
			add_action( 'http_api_curl', $pin, 10, 1 );
		}
		try {
			return self::request( $url, $cookies, $follow );
		} finally {
			if ( $pin ) {
				remove_action( 'http_api_curl', $pin, 10 );
			}
		}
	}

	/**
	 * Loopback request with core's scrape markers.
	 *
	 * @param string            $url     URL.
	 * @param \WP_Http_Cookie[] $cookies Cookies.
	 * @param bool              $follow  Follow redirects.
	 * @return array{ok:bool,http?:int,reason?:string,challenge?:bool}
	 */
	private static function request( $url, array $cookies, $follow ) {
		$key   = md5( (string) wp_rand() );
		$nonce = wp_generate_password( 40, false );
		set_transient( 'scrape_key_' . $key, $nonce, 60 );
		$res = wp_remote_get(
			add_query_arg(
				array(
					'wp_scrape_key'   => $key,
					'wp_scrape_nonce' => $nonce,
				),
				$url
			),
			array(
				'timeout'     => 30,
				'redirection' => $follow ? 5 : 0,
				'cookies'     => $cookies,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, used the same way core does.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		delete_transient( 'scrape_key_' . $key );
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'     => false,
				'reason' => 'loopback_failed',
			);
		}
		$code  = (int) wp_remote_retrieve_response_code( $res );
		$body  = (string) wp_remote_retrieve_body( $res );
		$start = '###### wp_scraping_result_start:' . $key . ' ######';
		$end   = '###### wp_scraping_result_end:' . $key . ' ######';
		$a     = strpos( $body, $start );
		$b     = strpos( $body, $end );
		$out   = ( false !== $a && false !== $b ) ? json_decode( trim( substr( $body, $a + strlen( $start ), $b - $a - strlen( $start ) ) ), true ) : null;
		$kept  = self::$keep_body ? array( 'body' => substr( $body, 0, 3145728 ) ) : array();
		if ( true === $out && $code < 500 ) {
			return array(
				'ok'   => true,
				'http' => $code,
			) + $kept;
		}
		$fail = array(
			'ok'     => false,
			'http'   => $code,
			'reason' => is_array( $out ) && isset( $out['message'] ) ? substr( (string) $out['message'], 0, 300 ) : self::what_came_back( $res, $code, $body ),
		) + $kept;
		if ( null === $out && preg_match( self::CHALLENGE, substr( $body, 0, 20000 ) ) ) {
			$fail['challenge'] = true;
		}
		// An HTTP password in front of the site (host password protection, an .htaccess login or a
		// plugin): the check cannot get in, which says nothing about the change.
		$auth = wp_remote_retrieve_header( $res, 'www-authenticate' );
		$auth = is_array( $auth ) ? implode( ', ', $auth ) : (string) $auth;
		if ( 401 === $code && null === $out && preg_match( '/^\s*basic/i', $auth ) ) {
			$realm             = preg_match( '/realm="([^"]{1,80})"/i', $auth, $m ) ? ' "' . sanitize_text_field( $m[1] ) . '"' : '';
			$fail['reason']    = 'http_password: the site asks for an HTTP password' . $realm . ' (Basic login), usually the host\'s password protection, an .htaccess login or a plugin that protects the whole site; the check could not get in';
			$fail['challenge'] = true;
		}
		return $fail;
	}

	/**
	 * Why no marker came back, in a form the owner (and support) can act on: a redirect and where
	 * to (path only), or the returned page's title. Never the page itself.
	 *
	 * @param array|\WP_Error $res  Response.
	 * @param int             $code HTTP status.
	 * @param string          $body Body.
	 * @return string
	 */
	private static function what_came_back( $res, $code, $body ) {
		if ( $code >= 300 && $code < 400 ) {
			$to = (string) wp_parse_url( (string) wp_remote_retrieve_header( $res, 'location' ), PHP_URL_PATH );
			return 'redirected to ' . ( '' !== $to ? substr( $to, 0, 120 ) : '(unknown)' ) . ( false !== strpos( $to, 'wp-login' ) ? ' (the background login was refused, e.g. by a security plugin)' : '' );
		}
		if ( false !== strpos( $body, 'id="loginform"' ) ) {
			return 'login page instead of the dashboard (the background login was refused)';
		}
		$title = preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ? trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) ) : '';
		return 'no_marker' . ( '' !== $title ? ': page "' . substr( $title, 0, 100 ) . '"' : ( '' === trim( $body ) ? ': empty page (possible fatal error)' : '' ) );
	}
}
