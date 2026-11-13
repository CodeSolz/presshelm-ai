<?php
/**
 * One-time redirect to the setup wizard after a single, interactive activation (D40). Never on
 * bulk activation, network activation, WP-CLI, AJAX or REST.
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Activation redirect.
 */
final class Activation {

	const FLAG = 'presshelm_activation_redirect';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function on_activate( $network_wide = false ) {
		if ( $network_wide || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// Bulk activation from the Plugins screen passes checked[] (activate-multi on the redirect).
		if ( isset( $_REQUEST['checked'] ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only check; core verified the bulk action nonce.
			return;
		}
		set_transient( self::FLAG, get_current_user_id(), 60 );
	}

	/**
	 * admin_init: redirect once, for the user who activated.
	 */
	public static function maybe_redirect() {
		$user = get_transient( self::FLAG );
		if ( false === $user ) {
			return;
		}
		delete_transient( self::FLAG );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || get_current_user_id() !== (int) $user || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only check.
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=presshelm' ) );
		exit;
	}
}
