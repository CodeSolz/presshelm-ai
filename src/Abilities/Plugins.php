<?php
/**
 * Plugins from WordPress.org (M3.5): list, search, install (inactive), activate, deactivate,
 * update, delete. Only WordPress.org slugs are installed (never arbitrary zip URLs). Updates and
 * deletes snapshot the whole plugin folder first, so undo puts the previous version back; a delete
 * does not run the plugin's uninstall routine, so its data stays and undo is complete. A plugin
 * that breaks the site when activated or updated is rolled back by the health gate (or, if PHP
 * dies during activation, by the Executor's shutdown safety net). PressHelm never manages itself.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin management.
 */
final class Plugins {

	/**
	 * Ability definitions (merged into Registry::all()).
	 *
	 * @return array
	 */
	public static function definitions() {
		$slug = array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9][a-z0-9-]{0,99}$',
		);
		$one  = array(
			'type'                 => 'object',
			'properties'           => array( 'slug' => $slug ),
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
		);
		$edit = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		return array(
			'plugins.list'   => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'list_plugins' ),
				'label'       => 'List installed plugins',
				'description' => 'Installed plugins: slug, name, version, active, and the version an update would bring.',
				'input'       => null,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
			'plugins.search' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'search' ),
				'label'       => 'Search WordPress.org plugins',
				'description' => 'Up to 10 plugins from the WordPress.org directory: slug, name, version, rating, active installs, last update, tested up to.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'      => 'string',
							'minLength' => 2,
							'maxLength' => 100,
						),
					),
					'required'             => array( 'search' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
		);
	}

	/**
	 * Loads the WordPress admin code needed for plugin work.
	 */
	public static function load() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * PressHelm's own plugin folders (this plugin and its Power Tools extension), which it never changes.
	 *
	 * @return string[]
	 */
	public static function own_slugs() {
		return array( basename( PRESSHELM_DIR ), 'presshelm-power-tools' );
	}

	/**
	 * Installed plugins.
	 *
	 * @return array
	 */
	public static function list_plugins() {
		self::load();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $p ) {
			$out[] = array(
				'slug'         => dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ),
				'name'         => $p['Name'],
				'version'      => $p['Version'],
				'active'       => is_plugin_active( $file ),
				'update_to'    => isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : null,
				'is_presshelm' => in_array( dirname( $file ), self::own_slugs(), true ),
			);
		}
		return array( 'plugins' => $out );
	}

	/**
	 * Main file of an installed plugin by folder slug, or null.
	 *
	 * @param string $slug Folder slug.
	 * @return string|null
	 */
	public static function file( $slug ) {
		self::load();
		wp_clean_plugins_cache( false );
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * WordPress.org search.
	 *
	 * @param array $p {search}.
	 * @return array|\WP_Error
	 */
	public static function search( array $p ) {
		self::load();
		$r = plugins_api(
			'query_plugins',
			array(
				'search'   => sanitize_text_field( (string) $p['search'] ),
				'per_page' => 10,
				'fields'   => array(
					'short_description' => true,
					'sections'          => false,
					'icons'             => false,
					'banners'           => false,
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new \WP_Error( 'presshelm_wporg', 'WordPress.org could not be reached: ' . $r->get_error_message() );
		}
		$out = array();
		foreach ( (array) $r->plugins as $pl ) {
			$pl    = (array) $pl;
			$out[] = array(
				'slug'            => $pl['slug'],
				'name'            => wp_strip_all_tags( (string) $pl['name'] ),
				'version'         => $pl['version'],
				'rating'          => (int) $pl['rating'],
				'active_installs' => (int) $pl['active_installs'],
				'last_updated'    => $pl['last_updated'],
				'tested'          => $pl['tested'] ?? null,
				'requires_php'    => $pl['requires_php'] ?? null,
				'description'     => wp_strip_all_tags( (string) ( $pl['short_description'] ?? '' ) ),
				'installed'       => null !== self::file( (string) $pl['slug'] ),
			);
		}
		return array( 'plugins' => $out );
	}
}
