<?php
/**
 * Service wiring. Each milestone adds its services here; nothing runs before the admin acts
 * (wp.org guideline 7: no outbound calls without consent).
 *
 * @package PressHelm
 */

namespace PressHelm;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin entry point.
 */
final class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Hooked on plugins_loaded.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		Support\Schema::maybe_install();
		add_action( 'rest_api_init', array( Connect\VerifyController::class, 'register' ) );
		add_action( 'rest_api_init', array( Bridge\WakeController::class, 'register' ) );
		add_action( 'presshelm_poll_now', array( Bridge\Poller::class, 'run' ) );
		add_action( 'wp_mail_failed', array( Abilities\Mail::class, 'record_failure' ) );
		// Another plugin switched on or off can add or remove abilities (forms, snippets, shop): the next
		// poll tells the cloud at once.
		// (The option itself: PressHelm switches plugins silently, without the activated_plugin hooks.)
		add_action( 'update_option_active_plugins', array( Bridge\Poller::class, 'report_soon' ) );
		add_action( 'update_site_option_active_sitewide_plugins', array( Bridge\Poller::class, 'report_soon' ) );
		Woo\Catalog::register();
		Abilities\Porting::register();
		Abilities\Stage::register();
		Bridge\Poller::register();
		Bridge\TaskUser::register();
		Abilities\Registry::register_with_wp();
		if ( is_admin() ) {
			add_action( 'admin_init', array( Admin\Activation::class, 'maybe_redirect' ) );
			Admin\WizardPage::register();
		}
	}
}
