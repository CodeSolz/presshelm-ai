<?php
/**
 * The user a task runs as: PressHelm's own "PressHelm" user with the PressHelm Assistant role, never
 * the administrator. It has a fixed list of content permissions (an editor's posts, pages, media,
 * comments and categories, a shop manager's products and orders, menus and templates) and nothing
 * else: WordPress refuses everything outside that list for this user, whatever its role says, so no
 * plugin, theme, user, update, file, option-screen or network administration is possible. It cannot
 * log in (no password login, no application passwords, no password reset). The site owner's admin
 * creates it when connecting the site; tasks never create or change users.
 *
 * @package PressHelm
 */

namespace PressHelm\Bridge;

use PressHelm\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Task user.
 */
final class TaskUser {

	/** The role. */
	const ROLE = 'presshelm_assistant';

	/** Option: the user's id. */
	const OPTION = 'presshelm_task_user';

	/**
	 * PressHelm's own capability for its read-only site information (versions, logs, caches, plugin,
	 * theme and user lists), its content import and export and the listed settings it may change.
	 * PressHelm's abilities that a person runs locally ask for the administrator capability instead.
	 */
	const SITE_CAP = 'presshelm_site_tasks';

	/** Administrator capabilities PressHelm abilities are gated on; the task user is checked against SITE_CAP for them. */
	const GATED = array( 'manage_options', 'list_users', 'import', 'export' );

	/** Everything the task user may do: an editor's and a shop manager's content permissions. */
	const CAPS = array(
		'read',
		'level_0',
		'level_1',
		'level_2',
		'level_3',
		'level_4',
		'level_5',
		'level_6',
		'level_7',
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'edit_private_posts',
		'publish_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
		'read_private_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'edit_private_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'delete_private_pages',
		'read_private_pages',
		'upload_files',
		'unfiltered_html',
		'moderate_comments',
		'manage_categories',
		'manage_links',
		'edit_theme_options',
		self::SITE_CAP,
		// WooCommerce: a shop manager's product, order and coupon work.
		'manage_woocommerce',
		'view_woocommerce_reports',
		'edit_product',
		'read_product',
		'delete_product',
		'edit_products',
		'edit_others_products',
		'publish_products',
		'read_private_products',
		'delete_products',
		'delete_private_products',
		'delete_published_products',
		'delete_others_products',
		'edit_private_products',
		'edit_published_products',
		'manage_product_terms',
		'edit_product_terms',
		'delete_product_terms',
		'assign_product_terms',
		'edit_shop_order',
		'read_shop_order',
		'delete_shop_order',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'publish_shop_orders',
		'read_private_shop_orders',
		'delete_shop_orders',
		'delete_private_shop_orders',
		'delete_published_shop_orders',
		'delete_others_shop_orders',
		'edit_private_shop_orders',
		'edit_published_shop_orders',
		'manage_shop_order_terms',
		'edit_shop_order_terms',
		'delete_shop_order_terms',
		'assign_shop_order_terms',
		'edit_shop_coupon',
		'read_shop_coupon',
		'delete_shop_coupon',
		'edit_shop_coupons',
		'edit_others_shop_coupons',
		'publish_shop_coupons',
		'read_private_shop_coupons',
		'delete_shop_coupons',
		'delete_private_shop_coupons',
		'delete_published_shop_coupons',
		'delete_others_shop_coupons',
		'edit_private_shop_coupons',
		'edit_published_shop_coupons',
		'manage_shop_coupon_terms',
		'edit_shop_coupon_terms',
		'delete_shop_coupon_terms',
		'assign_shop_coupon_terms',
		// Yoast SEO: an editor's SEO fields.
		'wpseo_bulk_edit',
		'wpseo_edit_advanced_metadata',
	);

	/** Capabilities the task user never has, also where WordPress would map them to one it has (e.g. Additional CSS to unfiltered_html). */
	const NEVER = array(
		'install_plugins',
		'activate_plugins',
		'activate_plugin',
		'deactivate_plugin',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'resume_plugins',
		'resume_plugin',
		'install_themes',
		'switch_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'resume_themes',
		'resume_theme',
		'customize',
		'edit_css',
		'create_users',
		'add_users',
		'list_users',
		'edit_user',
		'edit_users',
		'delete_user',
		'delete_users',
		'remove_user',
		'remove_users',
		'promote_user',
		'promote_users',
		'update_core',
		'update_php',
		'update_https',
		'install_languages',
		'update_languages',
		'edit_files',
		'unfiltered_upload',
		'manage_options',
		'manage_privacy_options',
		'export_others_personal_data',
		'erase_others_personal_data',
		'import',
		'export',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'create_sites',
		'delete_sites',
		'delete_site',
	);

	/**
	 * Hooks (plugins_loaded).
	 */
	public static function register() {
		add_filter( 'user_has_cap', array( self::class, 'only_listed' ), PHP_INT_MAX, 4 );
		add_filter( 'map_meta_cap', array( self::class, 'deny' ), PHP_INT_MAX, 4 );
		add_filter( 'authenticate', array( self::class, 'no_login' ), PHP_INT_MAX );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'no_app_passwords' ), 10, 2 );
		add_filter( 'allow_password_reset', array( self::class, 'no_reset' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'owner_as_author' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'ensure_for_admin' ) );
	}

	/**
	 * The task user's id (0 = not created yet).
	 *
	 * @return int
	 */
	public static function id() {
		$id = (int) get_option( self::OPTION, 0 );
		return $id && get_userdata( $id ) ? $id : 0;
	}

	/**
	 * Whether a user is the task user.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is( $user_id ) {
		$id = (int) get_option( self::OPTION, 0 );
		return $id > 0 && (int) $user_id === $id;
	}

	/**
	 * Creates the role and the user if they are missing. Only an administrator's own request does
	 * this (connecting the site, or opening WordPress admin while connected), never a task.
	 *
	 * @return int User id, or 0.
	 */
	public static function ensure() {
		if ( ! current_user_can( 'create_users' ) || self::is( get_current_user_id() ) ) {
			return self::id();
		}
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'PressHelm Assistant', array_fill_keys( self::CAPS, true ) );
		} elseif ( array_keys( array_filter( $role->capabilities ) ) !== self::CAPS ) {
			remove_role( self::ROLE );
			add_role( self::ROLE, 'PressHelm Assistant', array_fill_keys( self::CAPS, true ) );
		}
		$id = self::id();
		if ( $id ) {
			$user = get_userdata( $id );
			if ( is_multisite() && ! is_user_member_of_blog( $id ) ) {
				add_user_to_blog( get_current_blog_id(), $id, self::ROLE );
			} elseif ( $user && array( self::ROLE ) !== array_values( (array) $user->roles ) ) {
				$user->set_role( self::ROLE );
			}
			return $id;
		}
		$login = 'presshelm-assistant';
		if ( username_exists( $login ) ) {
			$login .= '-' . strtolower( wp_generate_password( 6, false ) );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => 'PressHelm',
				'nickname'     => 'PressHelm',
				'description'  => __( 'PressHelm runs your tasks as this user. It cannot log in and can only change content (posts, pages, media, menus, products and orders).', 'presshelm-ai' ),
				'role'         => self::ROLE,
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_option( self::OPTION, (int) $id, false );
		return (int) $id;
	}

	/**
	 * Creates the user for a site connected before it existed (admin_init, administrators only).
	 */
	public static function ensure_for_admin() {
		if ( ! self::id() && ! empty( Connection::get()['site_id'] ) ) {
			self::ensure();
		}
	}

	/**
	 * Starts running as a user.
	 *
	 * @param int $user_id User.
	 * @return array Token for end().
	 */
	public static function begin( $user_id ) {
		$token = array( 'prev' => get_current_user_id() );
		wp_set_current_user( (int) $user_id );
		// WordPress's own HTML filtering follows the current user's capabilities.
		kses_init();
		return $token;
	}

	/**
	 * Back to the previous user.
	 *
	 * @param array $token From begin().
	 */
	public static function end( array $token ) {
		wp_set_current_user( (int) $token['prev'] );
		kses_init();
	}

	/**
	 * The capability an ability's declared capability is checked as for the current user.
	 *
	 * @param string $cap Declared capability.
	 * @return string
	 */
	public static function gate( $cap ) {
		return self::is( get_current_user_id() ) && in_array( $cap, self::GATED, true ) ? self::SITE_CAP : $cap;
	}

	/**
	 * The task user has exactly the listed capabilities (filter `user_has_cap`).
	 *
	 * @param bool[]   $allcaps Capabilities.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User.
	 * @return bool[]
	 */
	public static function only_listed( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );
		if ( ! $user instanceof \WP_User || ! self::is( $user->ID ) ) {
			return $allcaps;
		}
		return array_fill_keys( self::CAPS, true );
	}

	/**
	 * Refuses the capabilities in NEVER to the task user (filter `map_meta_cap`).
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability asked for.
	 * @param int      $user_id User.
	 * @param array    $args    Arguments.
	 * @return string[]
	 */
	public static function deny( $caps, $cap, $user_id, $args ) {
		unset( $args );
		if ( self::is( $user_id ) && ( in_array( $cap, self::NEVER, true ) || array_intersect( (array) $caps, self::NEVER ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * The task user cannot log in (filter `authenticate`).
	 *
	 * @param \WP_User|\WP_Error|null $user Result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function no_login( $user ) {
		if ( $user instanceof \WP_User && self::is( $user->ID ) ) {
			return new \WP_Error( 'presshelm_task_user', __( 'This user only runs PressHelm tasks and cannot log in.', 'presshelm-ai' ) );
		}
		return $user;
	}

	/**
	 * No application passwords for the task user.
	 *
	 * @param bool     $available Available.
	 * @param \WP_User $user      User.
	 * @return bool
	 */
	public static function no_app_passwords( $available, $user ) {
		return $user instanceof \WP_User && self::is( $user->ID ) ? false : $available;
	}

	/**
	 * No password reset for the task user.
	 *
	 * @param bool $allow   Allowed.
	 * @param int  $user_id User.
	 * @return bool
	 */
	public static function no_reset( $allow, $user_id ) {
		return self::is( $user_id ) ? false : $allow;
	}

	/**
	 * Content a task creates belongs to the admin who connected the site (bylines stay theirs);
	 * revisions keep the task user, so history shows what PressHelm changed (filter `wp_insert_post_data`).
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Input.
	 * @return array
	 */
	public static function owner_as_author( $data, $postarr ) {
		unset( $postarr );
		if ( 'revision' === ( $data['post_type'] ?? '' ) || ! self::is( (int) ( $data['post_author'] ?? 0 ) ) ) {
			return $data;
		}
		$owner = (int) ( Connection::get()['connected_by'] ?? 0 );
		if ( $owner && get_userdata( $owner ) ) {
			$data['post_author'] = $owner;
		}
		return $data;
	}
}
