<?php
/**
 * The PressHelm admin page: the setup wizard (AI → Telegram → Connect → Ready, D40) until the
 * site is connected, then the connection status. Every action: nonce + manage_options.
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

use PressHelm\Connect\Apps;
use PressHelm\Connect\Connection;
use PressHelm\Connect\Keys;
use PressHelm\Connect\Pairing;
use PressHelm\Connect\TelegramLink;
use PressHelm\Onboarding\CloudStaging;
use PressHelm\Onboarding\OpenRouterAuth;
use PressHelm\Onboarding\PendingCredentials;
use PressHelm\Onboarding\ProviderCheck;
use PressHelm\Onboarding\Providers;
use PressHelm\Onboarding\State;
use PressHelm\Onboarding\TelegramCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Wizard + status page.
 */
final class WizardPage {

	const SLUG  = 'presshelm';
	const STEPS = array( 'ai', 'telegram', 'connect', 'ready' );

	/** Per-user transient: 'ai' or 'telegram' while a connected site changes that setting. */
	const CHANGING = 'presshelm_changing_';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_init', array( self::class, 'handle_returns' ), 1 );
		add_action( 'admin_notices', array( self::class, 'stopped_notice' ) );
		AiForm::register();
		foreach ( array( 'telegram', 'telegram_shared', 'connect', 'retry_handoff', 'tg_open', 'tg_decide', 'disconnect', 'reset', 'change_start', 'change_cancel', 'reconnect', 'journal_restore', 'rotate_keys', 'emergency_stop', 'emergency_resume', 'save_policy', 'save_mcp', 'save_ai_guard', 'app_decide', 'app_revoke', 'approval_decide' ) as $action ) {
			add_action( 'admin_post_presshelm_' . $action, array( self::class, 'action_' . $action ) );
		}
	}

	/**
	 * Admin menu entry.
	 */
	public static function menu() {
		$name = Brand::get()['name'];
		add_menu_page( $name, $name, 'manage_options', self::SLUG, array( self::class, 'render' ), 'dashicons-format-chat', 58 );
	}

	/**
	 * Styles only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'presshelm-admin', PRESSHELM_URL . 'assets/admin/admin.css', array(), PRESSHELM_VERSION );
		wp_enqueue_script( 'presshelm-admin', PRESSHELM_URL . 'assets/admin/admin.js', array(), PRESSHELM_VERSION, true );
		wp_enqueue_style( 'presshelm-settings', PRESSHELM_URL . 'assets/admin/settings.css', array( 'presshelm-admin' ), PRESSHELM_VERSION );
		wp_enqueue_script( 'presshelm-settings', PRESSHELM_URL . 'assets/admin/settings.js', array(), PRESSHELM_VERSION, true );
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * URL of a wizard step.
	 *
	 * @param string $step Step.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $step = '', array $args = array() ) {
		$args = array_merge( array( 'page' => self::SLUG ), $step ? array( 'step' => $step ) : array(), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect inside wp-admin and stop.
	 *
	 * @param string $url URL.
	 */
	private static function go( $url ) {
		Redirect::to( $url );
	}

	/**
	 * One-shot notice for the current user.
	 *
	 * @param string $type    error|success.
	 * @param string $message Text.
	 */
	private static function notice( $type, $message ) {
		set_transient( 'presshelm_notice_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	/**
	 * Common guard for form handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'presshelm-ai' ), 403 );
		}
		check_admin_referer( 'presshelm_' . $action );
	}

	/**
	 * Sanitized POST string.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers run guard() first.
	}

	// ------------------------------------------------------------------ handlers

	/** Step 3: bot token → getMe → stage. */
	public static function action_telegram() {
		self::guard( 'telegram' );
		$token = isset( $_POST['bot_token'] ) ? trim( (string) wp_unslash( $_POST['bot_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran; token format is validated strictly.
		$bot   = TelegramCheck::get_me( $token );
		if ( is_wp_error( $bot ) ) {
			self::notice( 'error', $bot->get_error_message() );
			self::go( self::url( 'telegram' ) );
		}
		PendingCredentials::set_telegram( $token, $bot );
		/* translators: %s: bot username */
		self::notice( 'success', sprintf( __( 'Bot @%s verified.', 'presshelm-ai' ), $bot['username'] ) );
		if ( 'telegram' === self::changing() ) {
			self::finish_change( 'telegram' );
		}
		self::go( self::url( 'connect' ) );
	}

	/** Step 3, other way: use the shared bot of the PressHelm account (Business and Agency). */
	public static function action_telegram_shared() {
		self::guard( 'telegram_shared' );
		PendingCredentials::set_telegram_shared();
		if ( 'telegram' === self::changing() ) {
			self::finish_change( 'telegram' );
		}
		self::notice( 'success', __( 'This site will use the shared bot of your PressHelm account.', 'presshelm-ai' ) );
		self::go( self::url( 'connect' ) );
	}

	/** Step 4: Connect to PressHelm Cloud. */
	public static function action_connect() {
		self::guard( 'connect' );
		$url = Pairing::start();
		if ( is_wp_error( $url ) ) {
			self::notice( 'error', $url->get_error_message() );
			self::go( self::url( 'connect' ) );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- PressHelm Cloud (configured base URL).
		exit;
	}

	/** Retry the credential hand-off after a temporary failure. */
	public static function action_retry_handoff() {
		self::guard( 'retry_handoff' );
		$r = Pairing::handoff();
		self::notice( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Credentials stored in your PressHelm vault.', 'presshelm-ai' ) );
		self::go( self::url( 'ready' ) );
	}

	/** "Open Telegram": a fresh one-time link, then off to t.me (D46). */
	public static function action_tg_open() {
		self::guard( 'tg_open' );
		$link = TelegramLink::deep_link();
		if ( is_wp_error( $link ) ) {
			self::notice( 'error', $link->get_error_message() );
			self::go( self::url( 'ready' ) );
		}
		wp_redirect( $link ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checked to start with https://t.me/ in TelegramLink::deep_link().
		exit;
	}

	/** Owner confirmation: approve or deny the Telegram account that pressed Start (M1.17). */
	public static function action_tg_decide() {
		self::guard( 'tg_decide' );
		$approve = 'approve' === self::post( 'decision' );
		$r       = TelegramLink::decide( self::post( 'request_id' ), $approve );
		if ( is_wp_error( $r ) ) {
			self::notice( 'error', $r->get_error_message() );
		} else {
			self::notice( 'success', $approve ? __( 'Telegram account linked. Your bot now answers only you.', 'presshelm-ai' ) : __( 'Request declined.', 'presshelm-ai' ) );
		}
		self::go( self::url( 'ready' ) );
	}

	/** Claude / ChatGPT sign-in: an administrator allows or denies it, then the browser goes back. */
	public static function action_app_decide() {
		self::guard( 'app_decide' );
		$id = self::post( 'request_id' );
		if ( ! preg_match( Apps::REQUEST_ID, $id ) ) {
			self::go( self::url() );
		}
		$approve = 'approve' === self::post( 'decision' );
		$url     = Apps::decide( $id, $approve );
		if ( is_wp_error( $url ) ) {
			self::notice( 'error', $url->get_error_message() );
			self::go( Tabs::url( 'ai' ) );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checked to be PressHelm Cloud's sign-in return address in Apps::decide().
		exit;
	}

	/** Disconnects a Claude / ChatGPT app. */
	public static function action_app_revoke() {
		self::guard( 'app_revoke' );
		$r = Apps::revoke( self::post( 'grant_id' ) );
		self::notice( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Disconnected. That app can no longer reach this site.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'apps' ) );
	}

	/** Approve or deny a change asked from a Claude / ChatGPT app. */
	public static function action_approval_decide() {
		self::guard( 'approval_decide' );
		$id = self::post( 'approval_id' );
		if ( ! preg_match( Apps::APPROVAL_ID, $id ) ) {
			self::go( self::url() );
		}
		$approve = 'approve' === self::post( 'decision' );
		$r       = Apps::approval_decide( $id, $approve );
		if ( is_wp_error( $r ) ) {
			self::notice( 'error', $r->get_error_message() );
		} else {
			self::notice( 'success', $approve ? __( 'Approved. It runs now; go back to your chat to see the result.', 'presshelm-ai' ) : __( 'Denied. Nothing was changed.', 'presshelm-ai' ) );
		}
		self::go( Tabs::url( 'history' ) );
	}

	/**
	 * Which setting a connected site is changing ('' if none).
	 *
	 * @return string
	 */
	private static function changing() {
		if ( ! Connection::is_connected() ) {
			return '';
		}
		$c = get_transient( self::CHANGING . get_current_user_id() );
		return in_array( $c, array( 'ai', 'telegram' ), true ) ? $c : '';
	}

	/**
	 * Sends the newly staged credential to the vault and returns to its tab.
	 *
	 * @param string $what ai|telegram.
	 */
	private static function finish_change( $what ) {
		$r = Pairing::handoff();
		if ( is_wp_error( $r ) ) {
			self::notice( 'error', $r->get_error_message() );
			self::go( self::url( $what ) );
		}
		delete_transient( self::CHANGING . get_current_user_id() );
		self::notice( 'success', 'ai' === $what ? __( 'AI provider updated.', 'presshelm-ai' ) : __( 'Telegram bot updated.', 'presshelm-ai' ) );
		self::go( Tabs::url( $what ) );
	}

	/** Connected site: change the AI provider/model or the bot (reuses the wizard step). */
	public static function action_change_start() {
		self::guard( 'change_start' );
		$what = self::post( 'what' );
		if ( ! in_array( $what, array( 'ai', 'telegram' ), true ) || ! Connection::is_connected() ) {
			self::go( self::url() );
		}
		PendingCredentials::wipe();
		set_transient( self::CHANGING . get_current_user_id(), $what, HOUR_IN_SECONDS );
		self::go( self::url( $what ) );
	}

	/** Leave a change without saving. */
	public static function action_change_cancel() {
		self::guard( 'change_cancel' );
		$what = self::changing();
		PendingCredentials::wipe();
		delete_transient( self::CHANGING . get_current_user_id() );
		self::go( Tabs::url( $what ? $what : 'setup' ) );
	}

	/** New keys + pairing for an already set-up site; the cloud keeps its AI and bot (same account). */
	public static function action_reconnect() {
		self::guard( 'reconnect' );
		// Same keys (new ones only if they were lost): abandoning the cloud page changes nothing.
		$url = Pairing::start();
		if ( is_wp_error( $url ) ) {
			self::notice( 'error', $url->get_error_message() );
			self::go( Tabs::url( 'disconnect' ) );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- PressHelm Cloud (configured base URL).
		exit;
	}

	/** Backup & Rollback: put one recorded change back. */
	public static function action_journal_restore() {
		self::guard( 'journal_restore' );
		$r = \PressHelm\Recovery\Journal::restore( (int) self::post( 'journal_id' ) );
		self::notice( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'The earlier version is back.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'backup' ) );
	}

	/** Emergency stop (M3.3): nothing PressHelm does runs until an administrator resumes it. */
	public static function action_emergency_stop() {
		self::guard( 'emergency_stop' );
		\PressHelm\Recovery\EmergencyStop::engage( get_current_user_id() );
		\PressHelm\Bridge\Poller::heartbeat_now();
		self::notice( 'success', __( 'PressHelm is stopped on this site. Nothing will run until you resume it.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'disconnect' ) );
	}

	/** Permissions tab: the owner's own limits per area (can only tighten). */
	public static function action_save_policy() {
		self::guard( 'save_policy' );
		$modes = array();
		foreach ( array_keys( \PressHelm\Safety\LocalPolicy::areas() ) as $area ) {
			$modes[ $area ] = self::post( 'policy_' . $area );
		}
		\PressHelm\Safety\LocalPolicy::save( $modes );
		\PressHelm\Bridge\Poller::heartbeat_now(); // The cloud learns the new limits at once.
		self::notice( 'success', __( 'Saved. PressHelm follows these limits from now on.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'permissions' ) );
	}

	/** AI & Model tab: the owner's AI spend limits, kept by PressHelm Cloud (it sees every AI request). */
	public static function action_save_ai_guard() {
		self::guard( 'save_ai_guard' );
		$val = static function ( $v ) {
			return '' === $v ? null : (float) $v;
		};
		$res = \PressHelm\Connect\Cloud::post(
			'/v1/connector/ai-guard',
			array(
				'daily_usd'    => $val( self::post( 'daily_usd' ) ),
				'monthly_usd'  => $val( self::post( 'monthly_usd' ) ),
				'daily_tokens' => $val( self::post( 'daily_tokens' ) ),
			),
			true,
			15
		);
		delete_transient( 'presshelm_ai_usage' );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			self::notice( 'error', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? __( 'The limit could not be saved.', 'presshelm-ai' ) ) );
		} else {
			self::notice( 'success', __( 'Saved. PressHelm Cloud applies the limit from the next AI request.', 'presshelm-ai' ) );
		}
		self::go( Tabs::url( 'limit' ) );
	}

	/** Permissions tab: opt in/out of the WordPress MCP Adapter (read-only abilities only). */
	public static function action_save_mcp() {
		self::guard( 'save_mcp' );
		update_option( \PressHelm\Abilities\ThirdParty::MCP_OPTION, '1' === self::post( 'mcp_expose' ), false );
		self::notice( 'success', __( 'Saved.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'permissions' ) );
	}

	/** Lift the emergency stop. */
	public static function action_emergency_resume() {
		self::guard( 'emergency_resume' );
		\PressHelm\Recovery\EmergencyStop::release();
		if ( \PressHelm\Recovery\EmergencyStop::by_constant() ) {
			self::notice( 'error', __( 'PressHelm stays stopped: PRESSHELM_EMERGENCY_STOP is set in wp-config.php. Remove it there to resume.', 'presshelm-ai' ) );
		} else {
			\PressHelm\Bridge\Poller::heartbeat_now();
			\PressHelm\Bridge\Poller::register(); // Polling resumes.
			self::notice( 'success', __( 'PressHelm is running again.', 'presshelm-ai' ) );
		}
		self::go( Tabs::url( 'disconnect' ) );
	}

	/** On every wp-admin page while stopped: say so, with a way back. */
	public static function stopped_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! \PressHelm\Recovery\EmergencyStop::active() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'PressHelm is stopped (emergency stop).', 'presshelm-ai' ),
			esc_html__( 'Nothing it would do runs on this site.', 'presshelm-ai' ),
			esc_url( Tabs::url( 'disconnect' ) ),
			esc_html__( 'Manage', 'presshelm-ai' )
		);
	}

	/** Connection tab: replace this site's signing key (M2.6). */
	public static function action_rotate_keys() {
		self::guard( 'rotate_keys' );
		$r = \PressHelm\Connect\Rotation::run();
		self::notice( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Connection keys replaced. The old key no longer works.', 'presshelm-ai' ) );
		self::go( Tabs::url( 'connection' ) );
	}

	/**
	 * Disconnect (M2.6): PressHelm Cloud revokes the site and deletes its AI key and bot token,
	 * then this site forgets the connection. `local_only` is for when the cloud can't be reached.
	 */
	public static function action_disconnect() {
		self::guard( 'disconnect' );
		if ( '1' !== self::post( 'local_only' ) ) {
			$res = \PressHelm\Connect\Cloud::post( '/v1/connector/disconnect', array(), true, 20 );
			// 401: the cloud already considers this site disconnected.
			if ( is_wp_error( $res ) || ( 200 !== $res['code'] && 401 !== $res['code'] ) ) {
				self::notice( 'error', __( 'PressHelm Cloud could not be reached, so nothing was disconnected. Try again, or use "Remove only from this site" below.', 'presshelm-ai' ) );
				self::go( Tabs::url( 'disconnect' ) );
			}
		}
		Connection::forget();
		Keys::forget();
		\PressHelm\Bridge\Poller::unschedule();
		delete_transient( TelegramLink::WAITING );
		self::notice( 'success', __( 'Disconnected. Run the setup again to reconnect.', 'presshelm-ai' ) );
		self::go( self::url( 'ai' ) );
	}

	/** Clear staged credentials and start over. */
	public static function action_reset() {
		self::guard( 'reset' );
		PendingCredentials::wipe();
		delete_transient( Pairing::TRANSIENT );
		self::go( self::url( 'ai' ) );
	}

	/**
	 * Browser returns from OpenRouter or PressHelm Cloud (GET on our page).
	 */
	public static function handle_returns() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth returns are verified by state/nonce + PKCE, not WP nonces.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// OpenRouter comes back to plain wp-admin/admin.php?code=… (it drops our query): that is
		// ours only while this administrator has an OpenRouter sign-in waiting.
		if ( isset( $_GET['code'] ) && ! isset( $_GET['page'] ) ) {
			$waiting = OpenRouterAuth::pending();
			if ( $waiting ) {
				self::finish_openrouter( sanitize_text_field( wp_unslash( $_GET['code'] ) ), (string) $waiting['nonce'], (string) ( $waiting['mode'] ?? 'wizard' ) );
			}
			return;
		}
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['presshelm_stg'] ) ) {
			$kind = isset( $_GET['stg_kind'] ) ? sanitize_key( wp_unslash( $_GET['stg_kind'] ) ) : '';
			$meta = CloudStaging::complete(
				sanitize_text_field( wp_unslash( $_GET['presshelm_stg'] ) ),
				$kind,
				isset( $_GET['stg_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['stg_ref'] ) ) : '',
				isset( $_GET['stg_error'] ) ? sanitize_key( wp_unslash( $_GET['stg_error'] ) ) : ''
			);
			$mode = isset( $_GET['ph_mode'] ) && 'settings' === $_GET['ph_mode'] ? 'settings' : 'wizard';
			if ( is_wp_error( $meta ) ) {
				self::notice( 'error', $meta->get_error_message() );
				self::go( 'telegram' === $kind ? self::url( 'telegram' ) : AiForm::back_url( $mode ) );
			}
			if ( 'telegram' === $kind ) {
				/* translators: %s: bot username */
				self::notice( 'success', sprintf( __( 'Bot @%s verified.', 'presshelm-ai' ), (string) ( $meta['bot']['username'] ?? '' ) ) );
				if ( 'telegram' === self::changing() ) {
					self::finish_change( 'telegram' );
				}
				self::go( self::url( 'connect' ) );
			}
			// The model chosen in the same form travels in the return address.
			AiForm::finish_return(
				$mode,
				array(
					'model'    => isset( $_GET['ph_model'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_model'] ) ) : '',
					'fallback' => isset( $_GET['ph_fallback'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_fallback'] ) ) : '',
				)
			);
		}
		if ( isset( $_GET['presshelm_or'] ) ) {
			self::finish_openrouter(
				isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
				sanitize_text_field( wp_unslash( $_GET['presshelm_or'] ) ),
				isset( $_GET['ph_mode'] ) && 'settings' === $_GET['ph_mode'] ? 'settings' : 'wizard'
			);
		}
		if ( isset( $_GET['presshelm_cb'] ) ) {
			$r = Pairing::handle_callback(
				isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
				isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '',
				isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''
			);
			if ( is_wp_error( $r ) ) {
				self::notice( 'error', $r->get_error_message() );
				self::go( self::url( Connection::is_connected() ? 'ready' : 'connect' ) );
			}
			self::notice( 'success', __( 'Connected successfully.', 'presshelm-ai' ) );
			self::go( self::url( 'ready' ) );
		}
		// phpcs:enable
	}

	/**
	 * The browser is back from OpenRouter: store the key (or hand it to the cloud) and save.
	 *
	 * @param string $code  Code from OpenRouter.
	 * @param string $nonce This attempt's nonce.
	 * @param string $mode  wizard|settings.
	 */
	private static function finish_openrouter( $code, $nonce, $mode ) {
		$mode = 'settings' === $mode && Connection::is_connected() ? 'settings' : 'wizard';
		$back = AiForm::back_url( $mode );
		Redirect::guard_fatal( $back );
		try {
			$key = OpenRouterAuth::finish( $code, $nonce );
			if ( is_wp_error( $key ) ) {
				self::notice( 'error', $key->get_error_message() );
				self::go( $back );
			}
			if ( CloudStaging::enabled() ) {
				// This site can't keep it safely: hand it straight to the cloud (checked there).
				$staged = CloudStaging::relay_ai( 'openrouter', 'oauth', $key );
				if ( is_wp_error( $staged ) ) {
					self::notice( 'error', $staged->get_error_message() );
					self::go( $back );
				}
			} else {
				// OpenRouter has just issued this key: only the model list is needed.
				PendingCredentials::set_ai( 'openrouter', 'oauth', $key, array( 'models' => AiForm::openrouter_models() ) );
			}
			AiForm::finish_return( $mode, AiForm::take_choice() );
		} catch ( \Throwable $e ) {
			/* translators: %s: error message */
			self::notice( 'error', sprintf( __( 'The OpenRouter sign-in could not be finished: %s', 'presshelm-ai' ), $e->getMessage() ) );
			self::go( $back );
		}
	}

	// ------------------------------------------------------------------ rendering

	/**
	 * Page output.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : State::default_step(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( ! in_array( $step, self::STEPS, true ) ) {
			$step = State::default_step();
		}
		// Links from a Claude / ChatGPT app: confirm a sign-in or a change (M4.18).
		$app_req   = isset( $_GET['ph_app'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_app'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a confirmation form; the decision itself is a nonce-checked POST.
		$approval  = isset( $_GET['ph_approve'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_approve'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$connected = Connection::is_connected();
		$confirm   = $connected && ( preg_match( Apps::REQUEST_ID, $app_req ) || preg_match( Apps::APPROVAL_ID, $approval ) );
		$changing  = $connected ? self::changing() : '';
		echo '<div class="wrap presshelm ph-app">';
		Tabs::header( $connected, $connected && ! $confirm && ( '' === $changing || $step !== $changing ) );
		// WordPress puts admin notices after this line, below the PressHelm header.
		echo '<hr class="wp-header-end">';
		self::render_notice();
		if ( $confirm ) {
			echo '<div class="presshelm-card">';
			if ( preg_match( Apps::REQUEST_ID, $app_req ) ) {
				self::app_confirm( $app_req );
			} else {
				self::approval_confirm( $approval );
			}
			echo '</div></div>';
			return;
		}
		if ( $connected ) {
			if ( '' === $changing || $step !== $changing ) {
				Tabs::render();
				echo '</div>';
				return;
			}
			echo '<div class="presshelm-card">';
			call_user_func( array( self::class, 'step_' . $step ) );
			Tabs::button( 'change_cancel', __( 'Cancel and keep the current setting', 'presshelm-ai' ), array(), 'button-link' );
			echo '</div></div>';
			return;
		}
		self::render_progress( $step );
		echo '<div class="presshelm-card">';
		call_user_func( array( self::class, 'step_' . $step ) );
		echo '</div></div>';
	}

	/**
	 * "Allow Claude to manage this site?"
	 *
	 * @param string $id Sign-in request id.
	 */
	private static function app_confirm( $id ) {
		$r = Apps::request( $id );
		if ( is_wp_error( $r ) ) {
			echo '<h2>' . esc_html__( 'Connect an AI app', 'presshelm-ai' ) . '</h2><p>' . esc_html( $r->get_error_message() ) . '</p>';
			return;
		}
		$app = (string) $r['app'];
		/* translators: %s: app name, e.g. Claude. */
		echo '<h2>' . esc_html( sprintf( __( 'Allow %s to manage this site?', 'presshelm-ai' ), $app ) ) . '</h2>';
		/* translators: 1: app name, 2: address it returns to, 3: this site. */
		echo '<p>' . esc_html( sprintf( __( '%1$s (%2$s) asks to connect to %3$s through PressHelm.', 'presshelm-ai' ), $app, (string) $r['return_host'], home_url( '/' ) ) ) . '</p>';
		echo '<ul class="presshelm-list"><li>' . esc_html__( 'It can read this site and change it within your PressHelm rules (Permissions & Security).', 'presshelm-ai' ) . '</li>';
		$others = isset( $r['shared_sites'] ) && is_array( $r['shared_sites'] ) ? array_map( 'strval', $r['shared_sites'] ) : array();
		if ( $others ) {
			/* translators: %s: comma-separated list of the owner's other sites. */
			echo '<li><strong>' . esc_html( sprintf( __( 'It also covers your other sites that share your PressHelm bot: %s. It asks you which site you mean before it works on one.', 'presshelm-ai' ), implode( ', ', array_slice( $others, 0, 50 ) ) ) ) . '</strong></li>';
		}
		echo '<li>' . esc_html__( 'Changes that need approval come back here, to you, before they run. Every change can be undone.', 'presshelm-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'You can disconnect it any time in PressHelm → AI & Model.', 'presshelm-ai' ) . '</li></ul>';
		/* translators: %s: app name. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Only allow this if you just started it from your own %s.', 'presshelm-ai' ), $app ) ) . '</strong></p>';
		self::form_open( 'app_decide' );
		echo '<input type="hidden" name="request_id" value="' . esc_attr( $id ) . '">';
		echo '<p><button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Allow', 'presshelm-ai' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="button">' . esc_html__( 'Deny', 'presshelm-ai' ) . '</button></p></form>';
	}

	/**
	 * "Approve this change?" for a change asked from a Claude / ChatGPT app.
	 *
	 * @param string $id Approval id.
	 */
	private static function approval_confirm( $id ) {
		$r = Apps::approval( $id );
		echo '<h2>' . esc_html__( 'Approve this change?', 'presshelm-ai' ) . '</h2>';
		if ( is_wp_error( $r ) ) {
			echo '<p>' . esc_html( $r->get_error_message() ) . '</p>';
			return;
		}
		$status = (string) $r['status'];
		if ( 'pending' !== $status ) {
			$done = array(
				'approved' => __( 'This was already approved.', 'presshelm-ai' ),
				'denied'   => __( 'This was denied.', 'presshelm-ai' ),
			);
			echo '<p>' . esc_html( $done[ $status ] ?? __( 'This request expired. Ask again in your chat if you still want it.', 'presshelm-ai' ) ) . '</p>';
			return;
		}
		if ( ! empty( $r['app'] ) ) {
			/* translators: %s: app name. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Asked from %s.', 'presshelm-ai' ), (string) $r['app'] ) ) . '</p>';
		}
		echo '<pre class="presshelm-approval">' . esc_html( (string) $r['text'] ) . '</pre>';
		self::form_open( 'approval_decide' );
		echo '<input type="hidden" name="approval_id" value="' . esc_attr( $id ) . '">';
		echo '<p><button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'presshelm-ai' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="button">' . esc_html__( 'Deny', 'presshelm-ai' ) . '</button></p></form>';
	}

	/** Shows and clears the one-shot notice. */
	private static function render_notice() {
		$key = 'presshelm_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( is_array( $n ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s presshelm-notice"><p>%2$s</p></div>', 'error' === $n[0] ? 'error' : 'success', esc_html( $n[1] ) );
		}
	}

	/**
	 * Progress bar.
	 *
	 * @param string $current Step.
	 */
	private static function render_progress( $current ) {
		$labels = array(
			'ai'       => __( 'AI provider', 'presshelm-ai' ),
			'telegram' => __( 'Telegram bot', 'presshelm-ai' ),
			'connect'  => __( 'PressHelm Cloud', 'presshelm-ai' ),
			'ready'    => __( 'Finish', 'presshelm-ai' ),
		);
		$index  = array_search( $current, self::STEPS, true );
		echo '<ol class="presshelm-steps">';
		foreach ( self::STEPS as $i => $s ) {
			$cls = $i < $index ? 'done' : ( $i === $index ? 'current' : '' );
			printf( '<li class="%1$s"><span>%2$d</span> %3$s</li>', esc_attr( $cls ), (int) $i + 1, esc_html( $labels[ $s ] ) );
		}
		echo '</ol>';
	}

	/**
	 * Opens a form posting to admin-post.php.
	 *
	 * @param string $action Action suffix.
	 */
	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'presshelm_' . $action ) . '">';
		wp_nonce_field( 'presshelm_' . $action );
	}

	/** Step: Connect AI (provider, how to connect, key or sign-in, model: one Save). */
	private static function step_ai() {
		echo '<div class="ph-head"><div class="ph-head-text"><p class="ph-eyebrow">' . esc_html__( 'Step 1 · AI & model', 'presshelm-ai' ) . '</p>';
		echo '<h2 class="ph-headline">' . esc_html__( 'Connect your AI.', 'presshelm-ai' ) . '</h2>';
		echo '<p class="ph-sub">' . esc_html__( 'PressHelm uses your own AI provider account. Usage is billed by your provider, not by PressHelm.', 'presshelm-ai' ) . '</p></div></div>';
		AiForm::render( 'wizard' );
	}

	/** Step: Telegram bot. */
	private static function step_telegram() {
		$p = PendingCredentials::record();
		if ( empty( $p['ai']['model'] ) ) {
			echo '<p>' . esc_html__( 'First connect your AI provider.', 'presshelm-ai' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'ai' ) ) . '">' . esc_html__( 'Go to step 1', 'presshelm-ai' ) . '</a></p>';
			return;
		}
		echo '<h2>' . esc_html__( 'Your own Telegram bot', 'presshelm-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'You chat with your developer through your own bot, with your own name and picture. It takes a minute:', 'presshelm-ai' ) . '</p>';
		echo '<ol class="presshelm-guide">';
		echo '<li>' . wp_kses(
			__( 'Open <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a> in Telegram.', 'presshelm-ai' ),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</li>';
		echo '<li>' . wp_kses( __( 'Send <code>/newbot</code>, choose a name (e.g. "Joly") and a username that ends in <code>bot</code>.', 'presshelm-ai' ), array( 'code' => array() ) ) . '</li>';
		echo '<li>' . esc_html__( 'BotFather replies with a token like 123456789:AA… Copy it and paste it below.', 'presshelm-ai' ) . '</li>';
		echo '</ol>';
		if ( ! empty( $p['telegram']['shared'] ) ) {
			echo '<p class="presshelm-ok">' . esc_html__( 'This site will use the shared bot of your PressHelm account.', 'presshelm-ai' ) . ' <a href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Continue', 'presshelm-ai' ) . '</a></p>';
		} elseif ( ! empty( $p['telegram'] ) ) {
			/* translators: 1: bot username, 2: bot name */
			echo '<p class="presshelm-ok">' . esc_html( sprintf( __( 'Bot verified: @%1$s (%2$s).', 'presshelm-ai' ), $p['telegram']['username'], $p['telegram']['name'] ) ) . ' <a href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Continue', 'presshelm-ai' ) . '</a></p>';
		}
		$cloud = CloudStaging::enabled();
		if ( $cloud ) {
			echo '<p class="presshelm-staging-note">' . esc_html( CloudStaging::notice() ) . '</p>';
			echo '<form method="post" action="' . esc_url( CloudStaging::form_action() ) . '">';
			CloudStaging::hidden_fields( 'telegram', self::url( 'telegram' ) );
		} else {
			self::form_open( 'telegram' );
		}
		echo '<p><label for="ph-bot"><strong>' . esc_html__( 'Bot token', 'presshelm-ai' ) . '</strong></label><br><input id="ph-bot" type="password" name="' . esc_attr( $cloud ? 'token' : 'bot_token' ) . '" class="regular-text" autocomplete="off" spellcheck="false" required></p>';
		submit_button( __( 'Verify bot', 'presshelm-ai' ) );
		echo '</form>';
		// Several sites, one bot (Business and Agency): no new bot needed for this site.
		echo '<div class="presshelm-shared-bot"><h3>' . esc_html__( 'Already chat with PressHelm about another site?', 'presshelm-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'On the Business and Agency plans, this site can use the bot you already set up for your other sites. In Telegram it asks which site you want to work on and stays on it until you switch.', 'presshelm-ai' ) . '</p>';
		self::form_open( 'telegram_shared' );
		submit_button( __( 'Use my account\'s shared bot', 'presshelm-ai' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/** Step: Connect to PressHelm Cloud. */
	private static function step_connect() {
		$p = PendingCredentials::record();
		if ( empty( $p['ai']['model'] ) || empty( $p['telegram'] ) ) {
			echo '<p>' . esc_html__( 'Finish the AI provider and Telegram steps first.', 'presshelm-ai' ) . '</p><p><a class="button" href="' . esc_url( self::url( empty( $p['ai']['model'] ) ? 'ai' : 'telegram' ) ) . '">' . esc_html__( 'Back', 'presshelm-ai' ) . '</a></p>';
			return;
		}
		$prov = Providers::get( $p['ai']['provider'] );
		echo '<h2>' . esc_html__( 'Connect to PressHelm Cloud', 'presshelm-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'You will sign up or log in on PressHelm, approve this site, and come straight back here. Your AI credential and bot token are then moved into your encrypted PressHelm vault and removed from WordPress.', 'presshelm-ai' ) . '</p>';
		echo '<ul class="presshelm-summary">';
		/* translators: 1: provider, 2: model, 3: masked key */
		echo '<li>' . esc_html( sprintf( __( 'AI: %1$s · %2$s · %3$s', 'presshelm-ai' ), $prov ? $prov['label'] : $p['ai']['provider'], $p['ai']['model'], $p['ai']['hint'] ) ) . '</li>';
		if ( ! empty( $p['telegram']['shared'] ) ) {
			echo '<li>' . esc_html__( 'Telegram: the shared bot of your PressHelm account', 'presshelm-ai' ) . '</li>';
		} else {
			/* translators: %s: bot username */
			echo '<li>' . esc_html( sprintf( __( 'Telegram: @%s', 'presshelm-ai' ), $p['telegram']['username'] ) ) . '</li>';
		}
		/* translators: %s: site URL */
		echo '<li>' . esc_html( sprintf( __( 'Site: %s', 'presshelm-ai' ), home_url( '/' ) ) ) . '</li>';
		echo '</ul>';
		self::form_open( 'connect' );
		submit_button( __( 'Connect to PressHelm Cloud', 'presshelm-ai' ), 'primary hero', 'submit', false );
		echo '</form>';
		self::form_open( 'reset' );
		submit_button( __( 'Start over', 'presshelm-ai' ), 'link', 'submit', false );
		echo '</form>';
	}

	/** Step: Ready / connection status (also the Setup tab). */
	public static function step_ready() {
		$c = Connection::get();
		if ( empty( $c['site_id'] ) ) {
			echo '<p>' . esc_html__( 'Not connected yet.', 'presshelm-ai' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Connect', 'presshelm-ai' ) . '</a></p>';
			return;
		}
		$done     = isset( $c['handoff'] ) && 'done' === $c['handoff'];
		$tg       = isset( $c['telegram'] ) ? (array) $c['telegram'] : array();
		$ai       = isset( $c['ai'] ) ? (array) $c['ai'] : array();
		$username = isset( $tg['username'] ) ? (string) $tg['username'] : '';

		// Live status from the cloud (webhook, owner, pending requests); the page still works without it.
		$live    = $done && $tg ? TelegramLink::status() : null;
		$live_ok = is_array( $live ) && ! empty( $live['bot'] );
		$webhook = $live_ok && isset( $live['webhook'] ) && 'set' === $live['webhook'];
		$owner   = $live_ok && ! empty( $live['owner'] ) ? (array) $live['owner'] : array();
		$pending = $live_ok && ! empty( $live['pending_requests'] ) ? (array) $live['pending_requests'] : array();

		echo '<h2>' . esc_html__( 'Connected successfully', 'presshelm-ai' ) . '</h2>';
		if ( $owner ) {
			$owner_label = ! empty( $owner['username'] ) ? '@' . $owner['username'] : (string) $owner['id'];
			/* translators: %s: Telegram username or id */
			$owner_text = sprintf( __( 'Your Telegram account (%s) linked as owner', 'presshelm-ai' ), $owner_label );
		} else {
			$owner_text = __( 'Your Telegram account linked as owner', 'presshelm-ai' );
		}
		if ( is_wp_error( $live ) ) {
			$webhook_text = __( 'Bot activated by PressHelm Cloud (could not check right now)', 'presshelm-ai' );
		} elseif ( $live_ok && ! $webhook ) {
			$webhook_text = __( 'Bot activated by PressHelm Cloud: Telegram has not accepted it yet. PressHelm will retry.', 'presshelm-ai' );
		} else {
			$webhook_text = __( 'Bot activated by PressHelm Cloud', 'presshelm-ai' );
		}
		$checks = array(
			array( true, __( 'Site connected to PressHelm Cloud', 'presshelm-ai' ) ),
			/* translators: %s: masked AI credential */
			array( $done && $ai, $ai ? sprintf( __( 'AI provider stored in your vault (%s)', 'presshelm-ai' ), isset( $ai['hint'] ) ? $ai['hint'] : '' ) : __( 'AI provider stored in your vault', 'presshelm-ai' ) ),
			/* translators: %s: bot username */
			array( $done && $tg, ! empty( $tg['shared'] ) ? sprintf( __( 'Uses the shared bot of your PressHelm account (@%s)', 'presshelm-ai' ), $username ) : ( $username ? sprintf( __( 'Telegram bot @%s stored in your vault', 'presshelm-ai' ), $username ) : __( 'Telegram bot stored in your vault', 'presshelm-ai' ) ) ),
			array( $live_ok ? $webhook : ( is_wp_error( $live ) ? false : null ), $webhook_text ),
			array( $owner ? true : null, $owner_text ),
			array( $owner ? true : null, $owner ? __( 'Your AI developer is ready: message your bot in Telegram', 'presshelm-ai' ) : __( 'Your AI developer starts once your Telegram account is linked', 'presshelm-ai' ) ),
		);
		echo '<ul class="presshelm-checklist">';
		foreach ( $checks as $chk ) {
			$cls = null === $chk[0] ? 'pending' : ( $chk[0] ? 'ok' : 'bad' );
			printf( '<li class="%1$s">%2$s</li>', esc_attr( $cls ), esc_html( $chk[1] ) );
		}
		echo '</ul>';
		if ( ! $done ) {
			self::form_open( 'retry_handoff' );
			submit_button( __( 'Retry storing credentials', 'presshelm-ai' ), 'primary', 'submit', false );
			echo '</form>';
		} elseif ( ! $tg ) {
			// The AI is stored but no bot (e.g. the shared bot was not possible): choose one now.
			Tabs::button( 'change_start', __( 'Choose a Telegram bot', 'presshelm-ai' ), array( 'what' => 'telegram' ), 'button button-primary' );
		}

		// Owner confirmation (D46): someone pressed Start with this site's one-time link.
		foreach ( $pending as $req ) {
			$req  = (array) $req;
			$who  = ! empty( $req['username'] ) ? '@' . $req['username'] : ( ! empty( $req['first_name'] ) ? $req['first_name'] : '' );
			$name = ! empty( $req['first_name'] ) && ! empty( $req['username'] ) ? ' (' . $req['first_name'] . ')' : '';
			echo '<div class="presshelm-request"><p><strong>';
			/* translators: 1: Telegram username/name, 2: first name in brackets, 3: numeric Telegram id */
			echo esc_html( sprintf( __( 'Telegram user %1$s%2$s, id %3$s, wants to control this site.', 'presshelm-ai' ), $who, $name, isset( $req['telegram_user_id'] ) ? (string) $req['telegram_user_id'] : '' ) );
			echo '</strong></p><p class="description">' . esc_html__( 'Approve only if this is you. Only the approved account can give instructions to your bot.', 'presshelm-ai' ) . '</p>';
			self::form_open( 'tg_decide' );
			echo '<input type="hidden" name="request_id" value="' . esc_attr( isset( $req['id'] ) ? (string) $req['id'] : '' ) . '">';
			echo '<button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'presshelm-ai' ) . '</button> ';
			echo '<button type="submit" name="decision" value="deny" class="button">' . esc_html__( 'Deny', 'presshelm-ai' ) . '</button>';
			echo '</form></div>';
		}

		if ( $username ) {
			if ( ! $owner && ! $pending && get_transient( TelegramLink::WAITING ) ) {
				echo '<p class="presshelm-waiting" data-presshelm-reload="5">' . esc_html__( 'Waiting for you to press Start in Telegram… this page refreshes by itself.', 'presshelm-ai' ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank">';
			echo '<input type="hidden" name="action" value="presshelm_tg_open">';
			wp_nonce_field( 'presshelm_tg_open' );
			echo '<p><button type="submit" class="' . esc_attr( $pending ? 'button' : 'button button-primary hero' ) . '">' . esc_html__( 'Open Telegram', 'presshelm-ai' ) . '</button></p></form>';
			if ( ! $owner && ! $pending ) {
				echo '<p class="description">' . esc_html__( 'Telegram opens your bot. Press Start, then come back here and approve your account.', 'presshelm-ai' ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Try first: "Check my WordPress website and tell me its status."', 'presshelm-ai' ) . '</p>';
	}
}
