<?php
/**
 * Plugin Name:       PressHelm AI Site Developer
 * Plugin URI:        https://presshelmai.com/
 * Description:       An AI developer for your content: ask in Telegram, Claude or ChatGPT, approve each change before it runs, and undo any change.
 * Version:           1.0.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            CodeSolz
 * Author URI:        https://codesolz.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       presshelm-ai
 *
 * @package PressHelm
 */

defined( 'ABSPATH' ) || exit;

// Another copy of PressHelm is already active (e.g. the older "presshelm" folder): step aside.
if ( defined( 'PRESSHELM_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Two copies of PressHelm are active. Deactivate the older one in Plugins; your settings and history are kept.', 'presshelm-ai' ) . '</p></div>';
		}
	);
	return;
}

define( 'PRESSHELM_VERSION', '1.0.1' );
define( 'PRESSHELM_FILE', __FILE__ );
define( 'PRESSHELM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSHELM_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSHELM_MIN_PHP', '7.4' );

if ( version_compare( PHP_VERSION, PRESSHELM_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'PressHelm requires PHP %1$s or higher. Your site is running PHP %2$s.', 'presshelm-ai' ),
						PRESSHELM_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

$presshelm_autoload = PRESSHELM_DIR . 'vendor/autoload.php';
if ( is_readable( $presshelm_autoload ) ) {
	require_once $presshelm_autoload;
} else {
	spl_autoload_register(
		static function ( $class_name ) {
			if ( 0 !== strpos( $class_name, 'PressHelm\\' ) ) {
				return;
			}
			$file = PRESSHELM_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PressHelm\\' ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \PressHelm\Admin\Activation::class, 'on_activate' ) );
register_deactivation_hook( __FILE__, array( \PressHelm\Bridge\Poller::class, 'unschedule' ) );
add_action( 'plugins_loaded', array( \PressHelm\Plugin::class, 'boot' ) );
