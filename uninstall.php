<?php
/**
 * Removes everything PressHelm stored in WordPress. (Cloud-side unregistering arrives with M2.6.)
 *
 * @package PressHelm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'presshelm_poll' );
global $wpdb;
// The task user goes too; anything it wrote (revisions, media) passes to the admin who connected the site.
$presshelm_task_user = (int) get_option( 'presshelm_task_user' );
if ( $presshelm_task_user && get_userdata( $presshelm_task_user ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$presshelm_connection = (array) get_option( 'presshelm_connection', array() );
	$presshelm_owner      = (int) ( $presshelm_connection['connected_by'] ?? 0 );
	if ( ! $presshelm_owner || ! get_userdata( $presshelm_owner ) ) {
		$presshelm_admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);
		$presshelm_owner  = (int) ( $presshelm_admins[0] ?? 0 );
	}
	if ( $presshelm_owner ) {
		wp_delete_user( $presshelm_task_user, $presshelm_owner );
	}
}
remove_role( 'presshelm_assistant' );
delete_option( 'presshelm_task_user' );
foreach ( array( 'ledger', 'journal' ) as $presshelm_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'presshelm_' . $presshelm_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes our own tables.
}
foreach ( array( 'presshelm_pending', 'presshelm_connection', 'presshelm_site_keys', 'presshelm_site_keys_next', 'presshelm_db_version', 'presshelm_last_wake', 'presshelm_emergency_stop', 'presshelm_custom_plugins', 'presshelm_themes', 'presshelm_local_policy', 'presshelm_mcp_expose', 'presshelm_mail_failures' ) as $presshelm_option ) {
	delete_option( $presshelm_option );
}
foreach ( array( 'presshelm_pairing', 'presshelm_pairing_exchanging', 'presshelm_activation_redirect', 'presshelm_tg_waiting' ) as $presshelm_transient ) {
	delete_transient( $presshelm_transient );
}
// Per-user transients (notices, change mode, staging session): presshelm_* by prefix.
foreach ( array( '_transient_presshelm_', '_transient_timeout_presshelm_' ) as $presshelm_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $presshelm_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own transients.
}
