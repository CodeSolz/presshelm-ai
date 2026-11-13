<?php
/**
 * WordPress core (H5.3): version and available updates (read), and an update to the exact version
 * WordPress.org offers (HIGH, approval). Core updates cannot be undone by PressHelm; the site's
 * health check runs right after, and the approval card says so. Post meta (read): every meta key
 * of a post, secret-looking values redacted.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Core and post meta.
 */
final class Core {

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$ro = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		return array(
			'core.version'   => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'version' ),
				'label'       => 'WordPress version and updates',
				'description' => 'The installed WordPress version, the updates WordPress.org offers (checked now), PHP and database versions, and the automatic update settings. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
			'post.meta_list' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'meta_list' ),
				'label'       => 'List a post\'s meta',
				'description' => 'Every custom field (meta key) of a post or page, or one key: values unserialized, long values shortened, secret-looking values redacted. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'key' => array(
							'type'      => 'string',
							'maxLength' => 255,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
		);
	}

	/**
	 * Offers from WordPress.org (fresh check).
	 *
	 * @return array
	 */
	public static function offers() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check( array(), true );
		$out = array();
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response, $u->version ) && in_array( $u->response, array( 'upgrade', 'autoupdate', 'latest' ), true ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/**
	 * Version and updates.
	 *
	 * @return array
	 */
	public static function version() {
		global $wpdb;
		$current = get_bloginfo( 'version' );
		$offers  = array();
		foreach ( self::offers() as $u ) {
			if ( version_compare( (string) $u->version, $current, '>' ) ) {
				$offers[] = array(
					'version' => (string) $u->version,
					'locale'  => (string) ( $u->locale ?? '' ),
					'php_min' => (string) ( $u->php_version ?? '' ),
				);
			}
		}
		return array(
			'version'      => $current,
			'updates'      => $offers,
			'up_to_date'   => ! $offers,
			'php'          => PHP_VERSION,
			'database'     => (string) $wpdb->db_server_info(),
			'auto_updates' => defined( 'WP_AUTO_UPDATE_CORE' ) ? ( is_bool( WP_AUTO_UPDATE_CORE ) ? ( WP_AUTO_UPDATE_CORE ? 'all' : 'off' ) : (string) WP_AUTO_UPDATE_CORE ) : 'minor (default)',
		);
	}

	/**
	 * A post's meta.
	 *
	 * @param array $p {id, key?}.
	 * @return array|\WP_Error
	 */
	public static function meta_list( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No post you can edit with that id.' );
		}
		$out = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( isset( $p['key'] ) && (string) $p['key'] !== (string) $key ) {
				continue;
			}
			$vals = array();
			foreach ( (array) $values as $v ) {
				$v      = maybe_unserialize( $v );
				$vals[] = DbRules::is_secret_key( (string) $key ) ? DbRules::REDACTED : self::short( $v );
			}
			$out[ (string) $key ] = 1 === count( $vals ) ? $vals[0] : $vals;
		}
		return array(
			'id'   => (int) $post->ID,
			'meta' => $out,
		);
	}

	/**
	 * A meta value, shortened for reading.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	private static function short( $v ) {
		if ( is_string( $v ) ) {
			return mb_strlen( $v ) > 2000 ? mb_substr( $v, 0, 2000 ) . ' … (' . mb_strlen( $v ) . ' characters)' : $v;
		}
		$json = (string) wp_json_encode( $v );
		return strlen( $json ) > 2000 ? substr( $json, 0, 2000 ) . ' … (' . strlen( $json ) . ' bytes)' : $v;
	}
}
