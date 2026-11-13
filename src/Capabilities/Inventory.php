<?php
/**
 * Honest capability report (M3.1, D14, K10): what this site is and what PressHelm can and cannot
 * do on it, with the reason for every "no". Contains no personal data (no users, emails or keys).
 *
 * @package PressHelm
 */

namespace PressHelm\Capabilities;

use PressHelm\Abilities\Registry;
use PressHelm\Bridge\Poller;
use PressHelm\Recovery\HealthCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Site inventory.
 */
final class Inventory {

	/** Cached loopback result (a real HTTP request; repeated at most hourly). */
	const LOOPBACK = 'presshelm_loopback_check';

	/**
	 * Full report.
	 *
	 * @param bool $check_loopback Run the loopback request now if no recent result is cached.
	 * @return array
	 */
	public static function report( $check_loopback = true ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$theme              = wp_get_theme();
		$fs                 = self::filesystem();
		$report             = array(
			'generated_at'   => gmdate( 'c' ),
			'plugin_version' => PRESSHELM_VERSION,
			'edition'        => defined( 'PRESSHELM_PRO_VERSION' ) ? 'pro' : 'free',
			'site'           => array(
				'home'       => home_url( '/' ),
				'admin_url'  => admin_url( 'admin.php' ),
				'name'       => get_bloginfo( 'name' ),
				'wp'         => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'multisite'  => is_multisite(),
				'https'      => 0 === strpos( home_url( '/' ), 'https://' ),
				'locale'     => get_locale(),
				'timezone'   => wp_timezone_string(),
				'permalinks' => (string) get_option( 'permalink_structure' ),
			),
			'theme'          => array(
				'name'           => $theme->get( 'Name' ),
				'stylesheet'     => $theme->get_stylesheet(),
				'version'        => $theme->get( 'Version' ),
				'type'           => wp_is_block_theme() ? 'block' : 'classic',
				'child_of'       => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'has_theme_json' => wp_theme_has_theme_json(),
			),
			'builders'       => self::builders(),
			'pack_kernel'    => \PressHelm\Packs\Pack::KERNEL_API,
			'woocommerce'    => self::woocommerce(),
			'plugins'        => self::plugins(),
			'filesystem'     => $fs,
			'cron'           => array(
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'presshelm_poll'   => (bool) wp_next_scheduled( Poller::HOOK ),
			),
			'loopback'       => self::loopback( $check_loopback ),
			'caches'         => \PressHelm\Abilities\Cache::summary(),
			'snippets'       => apply_filters( 'presshelm_snippets_summary', null ), // WPCode, when the add-on is there.
			'health'         => \PressHelm\Abilities\Logs::recent_fatals(),
			'updates'        => self::updates(),
			'php'            => array(
				'memory_limit'        => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
				'post_max_size'       => (string) ini_get( 'post_max_size' ),
				'extensions'          => array_values( array_filter( array( 'sodium', 'curl', 'zip', 'gd', 'imagick', 'intl', 'mbstring', 'openssl' ), 'extension_loaded' ) ),
			),
			'counts'         => array(
				'posts' => (int) wp_count_posts( 'post' )->publish,
				'pages' => (int) wp_count_posts( 'page' )->publish,
			),
		);
		$report['supports'] = self::supports( $report );
		$report['policy']   = \PressHelm\Safety\LocalPolicy::report( array_keys( Registry::all() ) );
		return $report;
	}

	/**
	 * What PressHelm can do here, each with a reason when it can't (shown to the owner and the agent).
	 *
	 * @param array $r Report.
	 * @return array<string, array{ok:bool, reason?:string}>
	 */
	public static function supports( array $r ) {
		$fs        = $r['filesystem'];
		$file_mods = $fs['file_mods_allowed'];
		$direct    = 'direct' === $fs['method'];
		$no        = static function ( $reason ) {
			return array(
				'ok'     => false,
				'reason' => $reason,
			);
		};
		$yes       = array( 'ok' => true );
		$abilities = Registry::all();

		$out = array(
			'content_edit'     => $yes,
			'additional_css'   => $yes,
			'undo'             => $yes,
			'plugin_install'   => ! $file_mods ? $no( __( 'DISALLOW_FILE_MODS is set in wp-config.php', 'presshelm-ai' ) ) : ( $direct ? $yes : $no( __( 'WordPress needs FTP/SSH credentials to write files on this server', 'presshelm-ai' ) ) ),
			'theme_file_edit'  => ! $file_mods ? $no( __( 'DISALLOW_FILE_MODS is set in wp-config.php', 'presshelm-ai' ) ) : ( $fs['themes_writable'] && $direct ? $yes : $no( __( 'The themes folder is not writable by WordPress', 'presshelm-ai' ) ) ),
			'plugin_file_edit' => ! $file_mods ? $no( __( 'DISALLOW_FILE_MODS is set in wp-config.php', 'presshelm-ai' ) ) : ( $fs['plugins_writable'] && $direct ? $yes : $no( __( 'The plugins folder is not writable by WordPress', 'presshelm-ai' ) ) ),
			'health_check'     => true === $r['loopback']['ok'] ? $yes : ( null === $r['loopback']['ok'] ? $no( __( 'Not checked yet', 'presshelm-ai' ) ) : $no( __( 'This site cannot reach itself over HTTP (loopback), so changes can\'t be checked automatically', 'presshelm-ai' ) ) ),
			'scheduled_work'   => $r['cron']['wp_cron_disabled'] ? $no( __( 'WP-Cron is disabled; PressHelm relies on a server cron or the wake signal', 'presshelm-ai' ) ) : $yes,
			'woocommerce'      => $r['woocommerce']['active'] ? $yes : $no( __( 'WooCommerce is not active', 'presshelm-ai' ) ),
			'elementor'        => ! empty( $r['builders']['elementor'] ) ? $yes : $no( __( 'Elementor is not active', 'presshelm-ai' ) ),
			'wp_cli'           => $no( __( 'Real WP-CLI and shell access are never used by the plugin; safe PHP equivalents are used instead', 'presshelm-ai' ) ),
			'abilities_api'    => function_exists( 'wp_register_ability' ) ? $yes : $no( __( 'This WordPress version has no Abilities API', 'presshelm-ai' ) ),
			'abilities_now'    => array(
				'ok'   => true,
				'list' => array_keys( $abilities ),
			),
		);
		// Every change needs a health check: without loopback, file changes are not offered.
		if ( true !== $r['loopback']['ok'] ) {
			foreach ( array( 'plugin_install', 'theme_file_edit', 'plugin_file_edit' ) as $k ) {
				if ( $out[ $k ]['ok'] ) {
					$out[ $k ] = $no( __( 'Not offered without a working health check (loopback)', 'presshelm-ai' ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Filesystem facts.
	 *
	 * @return array
	 */
	private static function filesystem() {
		$method = get_filesystem_method();
		return array(
			'method'            => $method,
			'file_mods_allowed' => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'file_edit_allowed' => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			'themes_writable'   => wp_is_writable( get_theme_root() ),
			'plugins_writable'  => wp_is_writable( WP_PLUGIN_DIR ),
			'uploads_writable'  => wp_is_writable( (string) wp_upload_dir( null, false )['basedir'] ),
		);
	}

	/**
	 * Page builders.
	 *
	 * @return array<string, string|bool>
	 */
	private static function builders() {
		return array(
			'gutenberg' => true,
			'elementor' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : false,
			'divi'      => function_exists( 'et_setup_theme' ) || defined( 'ET_BUILDER_VERSION' ),
			'beaver'    => class_exists( 'FLBuilder' ),
			'bricks'    => defined( 'BRICKS_VERSION' ),
		) + \PressHelm\Builders\Guard::present();
	}

	/**
	 * WooCommerce facts.
	 *
	 * @return array
	 */
	private static function woocommerce() {
		$active = class_exists( 'WooCommerce' );
		$hpos   = false;
		if ( $active && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'active'  => $active,
			'version' => $active && defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
			'hpos'    => $hpos,
		);
	}

	/**
	 * Active plugins (name + version) and update count.
	 *
	 * @return array
	 */
	private static function plugins() {
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$list    = array();
		$updates = get_site_transient( 'update_plugins' );
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$list[] = array(
					'file'    => $file,
					'name'    => $all[ $file ]['Name'],
					'version' => $all[ $file ]['Version'],
				);
			}
		}
		return array(
			'total'             => count( $all ),
			'active'            => array_slice( $list, 0, 200 ),
			'updates_available' => is_object( $updates ) && isset( $updates->response ) ? count( (array) $updates->response ) : null,
		);
	}

	/**
	 * Updates WordPress already found (its own update checks; nothing is fetched here): names and
	 * versions only, for the owner's update alert.
	 *
	 * @return array{core:array|null, plugins:array, themes:array}
	 */
	private static function updates() {
		$out     = array(
			'core'    => null,
			'plugins' => array(),
			'themes'  => array(),
		);
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$all = get_plugins();
			foreach ( (array) $plugins->response as $file => $u ) {
				if ( ! isset( $all[ $file ] ) || ! is_object( $u ) || empty( $u->new_version ) ) {
					continue;
				}
				$out['plugins'][] = array(
					'name' => self::plain( $all[ $file ]['Name'] ),
					'from' => (string) $all[ $file ]['Version'],
					'to'   => (string) $u->new_version,
				);
			}
		}
		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( (array) $themes->response as $slug => $u ) {
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() || ! is_array( $u ) || empty( $u['new_version'] ) ) {
					continue;
				}
				$out['themes'][] = array(
					'name' => self::plain( $theme->get( 'Name' ) ),
					'from' => (string) $theme->get( 'Version' ),
					'to'   => (string) $u['new_version'],
				);
			}
		}
		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( (array) $core->updates as $u ) {
				if ( is_object( $u ) && isset( $u->response, $u->current ) && 'upgrade' === $u->response ) {
					$out['core'] = array(
						'from' => (string) get_bloginfo( 'version' ),
						'to'   => (string) $u->current,
					);
					break;
				}
			}
		}
		$out['plugins'] = array_slice( $out['plugins'], 0, 50 );
		$out['themes']  = array_slice( $out['themes'], 0, 20 );
		return $out;
	}

	/**
	 * A name as plain text (headers may hold tags or entities such as &nbsp;).
	 *
	 * @param mixed $name Name.
	 * @return string
	 */
	private static function plain( $name ) {
		return trim( str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Loopback (the health check depends on it). Real request at most hourly.
	 *
	 * @param bool $check Run it if nothing is cached.
	 * @return array{ok:bool|null, checked_at?:int, http?:int, reason?:string}
	 */
	private static function loopback( $check ) {
		$cached = get_transient( self::LOOPBACK );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $check ) {
			return array(
				'ok'     => null,
				'reason' => 'not_checked_yet',
			);
		}
		$r      = HealthCheck::front();
		$result = array(
			'ok'         => (bool) $r['ok'],
			'checked_at' => time(),
			'http'       => isset( $r['http'] ) ? (int) $r['http'] : 0,
		);
		if ( ! $r['ok'] && isset( $r['reason'] ) ) {
			$result['reason'] = (string) $r['reason'];
		}
		set_transient( self::LOOPBACK, $result, HOUR_IN_SECONDS );
		return $result;
	}
}
