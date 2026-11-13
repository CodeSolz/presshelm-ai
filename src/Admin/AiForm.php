<?php
/**
 * "Connect AI": one form for the AI provider, the way to connect (API key or sign-in), the
 * credential and the model, saved with one button. Used by the setup wizard and by the
 * AI & Model settings of a connected site.
 *
 * Secrets: a key typed here goes to the site only when the site can keep it safely; otherwise
 * (CloudStaging) the browser posts it straight to PressHelm Cloud. A connected site never keeps
 * the key: it is handed to the vault and only a masked hint stays here. Changing just the model
 * keeps the saved key (it never leaves the vault).
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

use PressHelm\Connect\Cloud;
use PressHelm\Connect\Connection;
use PressHelm\Connect\Pairing;
use PressHelm\Onboarding\CloudStaging;
use PressHelm\Onboarding\OpenRouterAuth;
use PressHelm\Onboarding\PendingCredentials;
use PressHelm\Onboarding\ProviderCheck;
use PressHelm\Onboarding\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Unified AI settings form.
 */
final class AiForm {

	/** Models the saved key can use (from PressHelm Cloud), per site. */
	const MODELS_CACHE = 'presshelm_ai_models';

	/** The form as last submitted (no secrets), to show it again after an error. */
	const REMEMBER = 'presshelm_ai_form_';

	/** Model choice kept across the OpenRouter sign-in. */
	const CHOICE = 'presshelm_ai_choice_';

	/** Model ids as providers write them. */
	const MODEL_ID = '/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,199}$/';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'admin_post_presshelm_ai_save', array( self::class, 'save' ) );
		add_action( 'wp_ajax_presshelm_ai_models', array( self::class, 'ajax_models' ) );
	}

	// ------------------------------------------------------------------ state

	/**
	 * Where the form returns to.
	 *
	 * @param string $mode wizard|settings.
	 * @return string
	 */
	public static function back_url( $mode ) {
		return 'settings' === $mode ? Tabs::url( 'ai' ) : WizardPage::url( 'ai' );
	}

	/**
	 * What is saved now.
	 *
	 * @param string $mode wizard|settings.
	 * @return array{provider:string,method:string,model:string,fallback:string,hint:string,models:string[],saved:bool,models_error:string}
	 */
	public static function current( $mode ) {
		$out = array(
			'provider'     => '',
			'method'       => 'api_key',
			'model'        => '',
			'fallback'     => '',
			'hint'         => '',
			'models'       => array(),
			'saved'        => false,
			'models_error' => '',
		);
		if ( 'settings' === $mode ) {
			$ai = (array) ( Connection::get()['ai'] ?? array() );
			if ( ! empty( $ai['provider'] ) ) {
				$out['provider'] = (string) $ai['provider'];
				$out['method']   = 'oauth' === ( $ai['method'] ?? '' ) ? 'oauth' : 'api_key';
				$out['model']    = (string) ( $ai['model'] ?? '' );
				$out['fallback'] = (string) ( $ai['fallback_model'] ?? '' );
				$out['hint']     = (string) ( $ai['hint'] ?? '' );
				$out['saved']    = true;
				$list            = self::cloud_models();
				if ( is_wp_error( $list ) ) {
					$out['models_error'] = $list->get_error_message();
				} else {
					$out['models'] = (array) $list['models'];
					if ( '' === $out['fallback'] && ! empty( $list['fallback_model'] ) ) {
						$out['fallback'] = (string) $list['fallback_model'];
					}
				}
			}
			return $out;
		}
		$p = PendingCredentials::record();
		if ( ! empty( $p['ai']['provider'] ) ) {
			$out['provider'] = (string) $p['ai']['provider'];
			$out['method']   = 'oauth' === ( $p['ai']['method'] ?? '' ) ? 'oauth' : 'api_key';
			$out['model']    = (string) ( $p['ai']['model'] ?? '' );
			$out['fallback'] = (string) ( $p['ai']['fallback_model'] ?? '' );
			$out['hint']     = (string) ( $p['ai']['hint'] ?? '' );
			$out['models']   = (array) ( $p['ai']['models'] ?? array() );
			$out['saved']    = true;
		}
		return $out;
	}

	/**
	 * A key verified earlier (e.g. the model chosen then was not available) for this provider.
	 *
	 * @param string $provider Provider id.
	 * @param string $method   api_key|oauth.
	 * @return array|null
	 */
	private static function pending_for( $provider, $method ) {
		$p = PendingCredentials::record();
		$a = isset( $p['ai'] ) ? (array) $p['ai'] : array();
		return ! empty( $a['provider'] ) && $a['provider'] === $provider && ( $a['method'] ?? '' ) === $method && ! empty( $a['models'] ) ? $a : null;
	}

	/**
	 * The models the vault key can use (cached for 10 minutes).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|\WP_Error
	 */
	public static function cloud_models( $fresh = false ) {
		$c = $fresh ? false : get_transient( self::MODELS_CACHE );
		if ( is_array( $c ) ) {
			return $c;
		}
		$res = Cloud::post( '/v1/connector/ai/models', array(), true, 20 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['json'] ) ) {
			return new \WP_Error( 'presshelm_ai_models', __( 'The model list could not be loaded from PressHelm Cloud just now.', 'presshelm-ai' ) );
		}
		$out = array(
			'models'         => array_values( array_map( 'strval', (array) ( $res['json']['models'] ?? array() ) ) ),
			'fallback_model' => (string) ( $res['json']['fallback_model'] ?? '' ),
		);
		if ( ! $out['models'] ) {
			return new \WP_Error( 'presshelm_ai_models', __( 'Your provider did not return a model list just now. You can keep the current model or try again later.', 'presshelm-ai' ) );
		}
		set_transient( self::MODELS_CACHE, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Suggested model ids shown before a key is checked.
	 *
	 * @param string $provider Provider id.
	 * @return string[]
	 */
	private static function presets( $provider ) {
		$p = Providers::get( $provider );
		return array_values( array_unique( array_merge( (array) ( $p['prefer'] ?? array() ), (array) ( $p['cheap'] ?? array() ) ) ) );
	}

	// ------------------------------------------------------------------ rendering

	/**
	 * Short mark for a provider tile.
	 *
	 * @param string $id    Provider id.
	 * @param string $label Provider name.
	 * @return string
	 */
	private static function initials( $id, $label ) {
		$marks = array(
			'openrouter'        => 'OR',
			'anthropic'         => 'AN',
			'openai'            => 'OA',
			'gemini'            => 'GE',
			'openai_compatible' => 'API',
		);
		if ( isset( $marks[ $id ] ) ) {
			return $marks[ $id ];
		}
		$letters = preg_replace( '/[^A-Za-z]/', '', (string) $label );
		return strtoupper( substr( (string) $letters, 0, 2 ) );
	}

	/**
	 * The form.
	 *
	 * @param string $mode wizard|settings.
	 */
	public static function render( $mode ) {
		$mode     = 'settings' === $mode ? 'settings' : 'wizard';
		$cur      = self::current( $mode );
		$uid      = get_current_user_id();
		$form     = get_transient( self::REMEMBER . $uid );
		$form     = is_array( $form ) ? $form : array();
		$all      = Providers::all();
		$provider = (string) ( $form['provider'] ?? $cur['provider'] );
		if ( ! isset( $all[ $provider ] ) ) {
			$provider = isset( $all['openai'] ) ? 'openai' : (string) key( $all );
		}
		$methods = (array) $all[ $provider ]['methods'];
		$method  = (string) ( $form['method'] ?? ( $provider === $cur['provider'] ? $cur['method'] : $methods[0] ) );
		if ( ! in_array( $method, $methods, true ) ) {
			$method = (string) $methods[0];
		}
		$staging = CloudStaging::enabled();

		// Model lists per provider: what the saved (or just verified) key can use, else suggestions.
		$data = array();
		foreach ( $all as $id => $p ) {
			$known = array();
			$saved = '';
			if ( $id === $cur['provider'] ) {
				$known = $cur['models'];
				$saved = $cur['hint'];
			}
			$pending = self::pending_for( $id, 'api_key' ) ?? self::pending_for( $id, 'oauth' );
			if ( $pending ) {
				$known = (array) $pending['models'];
				$saved = (string) ( $pending['hint'] ?? '' );
			}
			$data[ $id ] = array(
				'label'    => (string) $p['label'],
				'methods'  => array_values( (array) $p['methods'] ),
				'key_url'  => (string) ( $p['key_url'] ?? '' ),
				'models'   => array_values( $known ),
				'presets'  => self::presets( $id ),
				'verified' => (bool) $known,
				'saved'    => $saved,
				'model'    => Providers::suggested_model( $id, $known ? $known : self::presets( $id ) ),
				'fallback' => Providers::suggested_model( $id, $known ? $known : self::presets( $id ), 'cheap' ),
			);
		}
		$sel        = $data[ $provider ];
		$list       = $sel['models'] ? $sel['models'] : $sel['presets'];
		$model      = (string) ( $form['model'] ?? ( $provider === $cur['provider'] && '' !== $cur['model'] ? $cur['model'] : $sel['model'] ) );
		$fallback   = (string) ( $form['fallback'] ?? ( $provider === $cur['provider'] ? $cur['fallback'] : $sel['fallback'] ) );
		$base_url   = (string) ( $form['base_url'] ?? '' );
		$site_post  = admin_url( 'admin-post.php' );
		$cloud_post = CloudStaging::form_action();
		$return_to  = add_query_arg( 'ph_mode', $mode, WizardPage::url( 'ai' ) );

		$texts = array(
			'data-checking'     => __( 'Checking with your provider…', 'presshelm-ai' ),
			'data-failed'       => __( 'The model list could not be loaded. Try again.', 'presshelm-ai' ),
			'data-err-provider' => __( 'Choose an AI provider.', 'presshelm-ai' ),
			'data-err-key'      => __( 'Paste your API key.', 'presshelm-ai' ),
			'data-err-base'     => __( 'Enter the provider\'s base URL, starting with https://', 'presshelm-ai' ),
			'data-err-model'    => __( 'Choose a model.', 'presshelm-ai' ),
			'data-ok'           => __( 'Connection healthy', 'presshelm-ai' ),
			'data-idle'         => __( 'Not checked yet. Test the connection or save.', 'presshelm-ai' ),
		);
		printf(
			'<form id="presshelm-ai-form" class="ph-ai-form" method="post" action="%1$s" novalidate data-mode="%2$s" data-staging="%3$s" data-site-action="%4$s" data-cloud-action="%5$s" data-ajax="%6$s" data-nonce="%7$s" data-current="%8$s" data-current-method="%9$s"',
			esc_url( $staging ? $cloud_post : $site_post ),
			esc_attr( $mode ),
			$staging ? '1' : '0',
			esc_url( $site_post ),
			esc_url( $cloud_post ),
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'presshelm_ai_models' ) ),
			esc_attr( $cur['provider'] ),
			esc_attr( $cur['method'] )
		);
		// Texts the script shows (validation, status).
		foreach ( $texts as $k => $v ) {
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		echo '>';
		// Fields for WordPress (switched off while the form posts a new key to PressHelm Cloud).
		$off = $staging ? ' disabled' : '';
		echo '<input type="hidden" name="action" value="presshelm_ai_save" data-site-field' . esc_attr( $off ) . '>';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'presshelm_ai_save' ) ) . '" data-site-field' . esc_attr( $off ) . '>';
		echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '" data-site-field' . esc_attr( $off ) . '>';
		if ( $staging ) {
			CloudStaging::hidden_fields( 'ai', $return_to );
		}
		echo '<script type="application/json" id="presshelm-ai-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';

		// 1. Provider (tiles).
		$order = array_unique( array_merge( array( 'openai', 'anthropic', 'gemini', 'openrouter', 'openai_compatible' ), array_keys( $all ) ) );
		echo '<fieldset class="ph-field ph-providers"><legend class="ph-section-title">' . esc_html__( 'Choose your AI provider', 'presshelm-ai' ) . '</legend>';
		echo '<div class="ph-provider-grid">';
		foreach ( $order as $id ) {
			if ( ! isset( $all[ $id ] ) ) {
				continue;
			}
			$p = $all[ $id ];
			printf(
				'<label class="ph-provider" title="%1$s"><input type="radio" name="provider" value="%2$s"%3$s aria-describedby="ph-blurb-%2$s">',
				esc_attr( (string) $p['blurb'] ),
				esc_attr( $id ),
				checked( $id, $provider, false )
			);
			echo '<span class="ph-check">';
			Icons::out( Icons::line( 'check', 14 ) );
			echo '</span><span class="ph-provider-logo ph-logo-' . esc_attr( $id ) . '">';
			Icons::out( Icons::provider( $id ) );
			echo '</span><span class="ph-provider-name">' . esc_html( (string) ( $p['short'] ?? $p['label'] ) ) . '</span>';
			if ( $id === $cur['provider'] && $cur['saved'] ) {
				echo '<span class="ph-provider-current">' . esc_html__( 'In use', 'presshelm-ai' ) . '</span>';
			}
			echo '<span class="screen-reader-text" id="ph-blurb-' . esc_attr( $id ) . '">' . esc_html( (string) $p['blurb'] ) . '</span></label>';
		}
		echo '</div></fieldset>';

		echo '<div class="ph-conn-card"><h3 class="ph-card-title">' . esc_html__( 'Connection & models', 'presshelm-ai' ) . '</h3>';

		// 2. How to connect (chips; only the provider's methods are shown).
		echo '<fieldset class="ph-field ph-methods-field"><legend class="screen-reader-text">' . esc_html__( 'How to connect', 'presshelm-ai' ) . '</legend><div class="ph-chips">';
		foreach (
			array(
				'api_key' => array( __( 'API key', 'presshelm-ai' ), 'key' ),
				'oauth'   => array( __( 'Sign in', 'presshelm-ai' ), 'log-in' ),
			) as $m => $spec
		) {
			printf(
				'<label class="ph-segment ph-chip" data-method="%1$s"%2$s><input type="radio" name="method" value="%1$s"%3$s> <span>',
				esc_attr( $m ),
				in_array( $m, $methods, true ) ? '' : ' hidden',
				checked( $m, $method, false )
			);
			Icons::out( Icons::line( $spec[1], 16 ) );
			echo esc_html( $spec[0] ) . '</span></label>';
		}
		echo '</div></fieldset>';

		// 3a. API key.
		$saved_hint = $sel['saved'];
		$has_saved  = '' !== $saved_hint && 'api_key' === $cur['method'] && $provider === $cur['provider'];
		echo '<div class="ph-auth ph-auth-key"' . ( 'api_key' === $method ? '' : ' hidden' ) . '>';
		echo '<div class="ph-field ph-base-url"' . ( 'openai_compatible' === $provider ? '' : ' hidden' ) . '><label class="ph-label" for="ph-base">' . esc_html__( 'Base URL', 'presshelm-ai' ) . '</label>';
		echo '<input id="ph-base" type="url" name="base_url" class="ph-input" placeholder="https://api.example.com/v1" value="' . esc_attr( $base_url ) . '" autocomplete="off">';
		echo '<p class="ph-help">' . esc_html__( 'The provider\'s OpenAI-compatible API address, starting with https://', 'presshelm-ai' ) . '</p></div>';
		echo '<div class="ph-field"><label class="ph-label" for="ph-key">' . esc_html__( 'API key', 'presshelm-ai' ) . '</label>';
		$ph_saved = __( 'Saved — leave blank to keep it', 'presshelm-ai' );
		$ph_paste = __( 'Paste your API key', 'presshelm-ai' );
		echo '<div class="ph-key-row"><div class="ph-input-wrap"><input id="ph-key" type="password" name="' . esc_attr( $staging ? 'secret' : 'api_key' ) . '" class="ph-input" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-form-type="other" spellcheck="false" aria-describedby="ph-key-help" placeholder="' . esc_attr( $has_saved ? $ph_saved : $ph_paste ) . '" data-saved-placeholder="' . esc_attr( $ph_saved ) . '" data-placeholder="' . esc_attr( $ph_paste ) . '">';
		echo '<button type="button" class="ph-reveal" aria-controls="ph-key" aria-pressed="false" aria-label="' . esc_attr__( 'Show key', 'presshelm-ai' ) . '" data-hide-label="' . esc_attr__( 'Hide key', 'presshelm-ai' ) . '">';
		Icons::out( Icons::line( 'eye', 20 ) );
		Icons::out( Icons::line( 'eye-off', 20 ) );
		echo '</button></div>';
		echo '<a class="ph-link ph-get-key" href="' . esc_url( $sel['key_url'] ? $sel['key_url'] : '#' ) . '" target="_blank" rel="noopener noreferrer"' . ( $sel['key_url'] ? '' : ' hidden' ) . '>';
		Icons::out( Icons::line( 'external', 18 ) );
		echo esc_html__( 'Get API key', 'presshelm-ai' ) . '</a></div>';
		echo '<p class="ph-lock" id="ph-key-help">';
		Icons::out( Icons::line( 'lock', 15 ) );
		echo '<span>' . esc_html__( 'Encrypted and stored securely in your PressHelm vault, never on this site.', 'presshelm-ai' ) . '</span>';
		/* translators: %s: masked key, e.g. sk-ant-…1111 */
		echo ' <span class="ph-key-saved"' . ( $has_saved ? '' : ' hidden' ) . '>' . esc_html( sprintf( __( 'Saved key %s: leave the field blank to keep it.', 'presshelm-ai' ), $saved_hint ) ) . '</span></p>';
		if ( $staging ) {
			echo '<p class="ph-note ph-note-info">' . esc_html( CloudStaging::notice() ) . '</p>';
		}
		echo '</div></div>';

		// 3b. Sign in.
		$signed_in = 'oauth' === $cur['method'] && $provider === $cur['provider'] && $cur['saved'];
		echo '<div class="ph-auth ph-auth-oauth"' . ( 'oauth' === $method ? '' : ' hidden' ) . '><div class="ph-signin">';
		if ( $signed_in ) {
			/* translators: 1: provider name, 2: masked key */
			echo '<p class="ph-signin-state"><span class="ph-pill ph-pill-ok">' . esc_html__( 'Signed in', 'presshelm-ai' ) . '</span> ' . esc_html( sprintf( __( '%1$s key %2$s', 'presshelm-ai' ), $sel['label'], $cur['hint'] ) ) . '</p>';
			echo '<p class="ph-help">' . esc_html__( 'Save to keep this sign-in with the models below, or sign in again to use another account.', 'presshelm-ai' ) . '</p>';
			echo '<button type="submit" name="intent" value="reauth" class="ph-btn ph-btn-outline ph-reauth">' . esc_html__( 'Sign in again', 'presshelm-ai' ) . '</button>';
		} else {
			echo '<p class="ph-help">' . esc_html__( 'When you save, you sign in on the provider\'s own site and come straight back here. You never share a password with PressHelm.', 'presshelm-ai' ) . '</p>';
		}
		echo '</div></div>';

		// 4. Models.
		echo '<div class="ph-field-grid">';
		echo '<div class="ph-field"><label class="ph-label" for="ph-model">' . esc_html__( 'Primary model', 'presshelm-ai' ) . '</label>';
		self::select( 'model', 'ph-model', $list, $model, false );
		echo '</div><div class="ph-field"><label class="ph-label" for="ph-fallback">' . esc_html__( 'Fallback model', 'presshelm-ai' ) . ' <span class="ph-optional">' . esc_html__( '(Optional)', 'presshelm-ai' ) . '</span></label>';
		self::select( 'fallback_model', 'ph-fallback', $list, $fallback, true );
		echo '</div></div>';

		// Connection status, with "Refresh models".
		if ( $cur['saved'] && '' === $cur['models_error'] && $cur['models'] ) {
			$state = array( 'ok', __( 'Connection healthy', 'presshelm-ai' ) );
		} elseif ( '' !== $cur['models_error'] ) {
			$state = array( 'warn', $cur['models_error'] );
		} else {
			$state = array( 'idle', __( 'Not connected yet. Choose a model and save.', 'presshelm-ai' ) );
		}
		printf( '<div class="ph-health is-%1$s" data-initial="%1$s" role="status" aria-live="polite"><span class="ph-dot" aria-hidden="true"></span><span class="ph-health-text" data-initial-text="%2$s">%2$s</span>', esc_attr( $state[0] ), esc_html( $state[1] ) );
		echo '<button type="button" class="ph-link ph-load-models">';
		Icons::out( Icons::line( 'refresh', 18 ) );
		echo esc_html__( 'Refresh models', 'presshelm-ai' ) . '</button></div>';

		echo '<div class="ph-form-status" role="alert"></div>';
		echo '<div class="ph-actions"><button type="submit" class="ph-btn ph-btn-primary ph-save" data-saving="' . esc_attr__( 'Saving…', 'presshelm-ai' ) . '"><span>' . esc_html__( 'Save changes', 'presshelm-ai' ) . '</span>';
		Icons::out( Icons::line( 'arrow-right', 18 ) );
		echo '</button><button type="button" class="ph-btn ph-btn-outline ph-test">' . esc_html__( 'Test connection', 'presshelm-ai' ) . '</button>';
		echo '<span class="ph-applies">' . esc_html( 'wizard' === $mode ? __( 'Next: your Telegram bot.', 'presshelm-ai' ) : __( 'Applies to this site.', 'presshelm-ai' ) ) . '</span></div>';
		echo '</div>';
		echo '<p class="ph-billing">';
		Icons::out( Icons::line( 'shield-check', 18 ) );
		echo esc_html__( 'Your AI usage is billed directly by your provider.', 'presshelm-ai' ) . '</p>';
		echo '</form>';
		delete_transient( self::REMEMBER . $uid );
	}

	/**
	 * A model <select>.
	 *
	 * @param string   $name     Field name.
	 * @param string   $id       Element id.
	 * @param string[] $models   Options.
	 * @param string   $selected Selected value.
	 * @param bool     $optional Adds "None".
	 */
	private static function select( $name, $id, array $models, $selected, $optional ) {
		if ( '' !== $selected && ! in_array( $selected, $models, true ) ) {
			array_unshift( $models, $selected ); // A saved model stays selectable.
		}
		printf( '<select name="%1$s" id="%2$s" class="ph-input ph-select">', esc_attr( $name ), esc_attr( $id ) );
		if ( $optional ) {
			echo '<option value="">' . esc_html__( 'None', 'presshelm-ai' ) . '</option>';
		}
		foreach ( $models as $m ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( (string) $m ), selected( (string) $m, $selected, false ) );
		}
		echo '</select>';
	}

	// ------------------------------------------------------------------ saving

	/**
	 * Sanitized POST string.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() checks the nonce first.
	}

	/**
	 * Back to the form with an error (the form is shown again as it was, without the key).
	 *
	 * @param string $mode    wizard|settings.
	 * @param array  $form    provider, method, model, fallback, base_url.
	 * @param string $message Error.
	 */
	private static function fail( $mode, array $form, $message ) {
		set_transient( self::REMEMBER . get_current_user_id(), $form, 5 * MINUTE_IN_SECONDS );
		set_transient( 'presshelm_notice_' . get_current_user_id(), array( 'error', $message ), 120 );
		Redirect::to( self::back_url( $mode ) );
	}

	/**
	 * Saved: wizard → next step; settings → into the vault.
	 *
	 * @param string $mode  wizard|settings.
	 * @param string $label Provider name.
	 * @param string $model Model.
	 * @param array  $form  The form (for an error).
	 */
	private static function done( $mode, $label, $model, array $form ) {
		delete_transient( self::MODELS_CACHE );
		if ( 'settings' === $mode ) {
			$r = Pairing::handoff();
			if ( is_wp_error( $r ) ) {
				self::fail( $mode, $form, $r->get_error_message() );
			}
			/* translators: 1: provider name, 2: model id */
			set_transient( 'presshelm_notice_' . get_current_user_id(), array( 'success', sprintf( __( 'Settings saved. PressHelm now uses %1$s with %2$s.', 'presshelm-ai' ), $label, $model ) ), 120 );
			Redirect::to( self::back_url( $mode ) );
		}
		/* translators: 1: provider name, 2: model id */
		set_transient( 'presshelm_notice_' . get_current_user_id(), array( 'success', sprintf( __( 'Saved: %1$s with %2$s. Now your Telegram bot.', 'presshelm-ai' ), $label, $model ) ), 120 );
		Redirect::to( WizardPage::url( 'telegram' ) );
	}

	/**
	 * The chosen models, checked against a list.
	 *
	 * @param string[] $models   Available models ([] = unknown: format check only).
	 * @param string   $model    Main model.
	 * @param string   $fallback Fallback model.
	 * @return string '' when fine, else the message.
	 */
	private static function check_models( array $models, $model, $fallback ) {
		if ( '' === $model ) {
			return __( 'Choose a model.', 'presshelm-ai' );
		}
		foreach ( array_filter( array( $model, $fallback ), 'strlen' ) as $m ) {
			if ( ( $models && ! in_array( $m, $models, true ) ) || ! preg_match( self::MODEL_ID, $m ) ) {
				/* translators: %s: model id */
				return sprintf( __( '"%s" is not available with this key. Choose a model from the updated list and save again.', 'presshelm-ai' ), $m );
			}
		}
		return '';
	}

	/** One button: validate, then store (wizard) or hand to the vault (settings). */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'presshelm-ai' ), 403 );
		}
		check_admin_referer( 'presshelm_ai_save' );
		$mode     = 'settings' === self::post( 'mode' ) && Connection::is_connected() ? 'settings' : 'wizard';
		$provider = self::post( 'provider' );
		$info     = Providers::get( $provider );
		$method   = self::post( 'method' );
		$model    = self::post( 'model' );
		$fallback = self::post( 'fallback_model' );
		$fallback = $fallback === $model ? '' : $fallback;
		$base_url = esc_url_raw( self::post( 'base_url' ) );
		$key      = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a key must not be altered by sanitizers; the nonce was checked above.
		$form     = array(
			'provider' => $provider,
			'method'   => $method,
			'model'    => $model,
			'fallback' => $fallback,
			'base_url' => $base_url,
		);
		if ( ! $info ) {
			self::fail( $mode, $form, __( 'Choose an AI provider.', 'presshelm-ai' ) );
		}
		if ( ! Providers::supports( $provider, $method ) ) {
			$method         = (string) $info['methods'][0];
			$form['method'] = $method;
		}
		$label = (string) $info['label'];
		$cur   = self::current( $mode );
		$same  = $cur['saved'] && $provider === $cur['provider'] && $method === $cur['method'];

		// Sign in (OpenRouter): keep the current sign-in, or go and sign in.
		if ( 'oauth' === $method ) {
			if ( $same && 'reauth' !== self::post( 'intent' ) ) {
				self::save_model_only( $mode, $label, $cur, $form );
			}
			set_transient(
				self::CHOICE . get_current_user_id(),
				array(
					'model'    => $model,
					'fallback' => $fallback,
				),
				15 * MINUTE_IN_SECONDS
			);
			wp_redirect( OpenRouterAuth::start( $mode ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed external host (openrouter.ai).
			exit;
		}

		// API key left blank: keep the saved key (or one verified a moment ago).
		if ( '' === $key ) {
			if ( 'openai_compatible' === $provider && '' === $base_url && ! $same ) {
				self::fail( $mode, $form, __( 'Enter the provider\'s base URL and API key.', 'presshelm-ai' ) );
			}
			$pending = self::pending_for( $provider, 'api_key' );
			if ( $pending ) {
				$bad = self::check_models( (array) $pending['models'], $model, $fallback );
				if ( '' !== $bad ) {
					self::fail( $mode, $form, $bad );
				}
				PendingCredentials::set_models( $model, $fallback );
				self::done( $mode, $label, $model, $form );
			}
			if ( $same ) {
				self::save_model_only( $mode, $label, $cur, $form );
			}
			/* translators: %s: provider name */
			self::fail( $mode, $form, sprintf( __( 'Paste your %s API key.', 'presshelm-ai' ), $label ) );
		}

		// A new key: never kept on a site that cannot keep it safely.
		if ( CloudStaging::enabled() ) {
			self::fail( $mode, $form, CloudStaging::notice() );
		}
		if ( 'openai_compatible' === $provider && 0 !== strpos( $base_url, 'https://' ) ) {
			self::fail( $mode, $form, __( 'Enter the provider\'s base URL, starting with https://', 'presshelm-ai' ) );
		}
		$models = ProviderCheck::validate( $provider, $key, $base_url );
		if ( is_wp_error( $models ) ) {
			self::fail( $mode, $form, $models->get_error_message() );
		}
		PendingCredentials::set_ai(
			$provider,
			'api_key',
			$key,
			array(
				'base_url' => $base_url,
				'models'   => $models,
			)
		);
		if ( ! in_array( $model, $models, true ) ) {
			// A suggestion shown before the key was checked may not be available to it: use the
			// best available one. A model the owner picked from a real list must exist, though.
			if ( '' !== $model && ! in_array( $model, self::presets( $provider ), true ) ) {
				self::fail( $mode, $form, self::check_models( $models, $model, '' ) );
			}
			$model = Providers::suggested_model( $provider, $models );
		}
		if ( '' !== $fallback && ! in_array( $fallback, $models, true ) ) {
			$fallback = Providers::suggested_model( $provider, $models, 'cheap' );
		}
		PendingCredentials::set_models( $model, $fallback === $model ? '' : $fallback );
		self::done( $mode, $label, $model, $form );
	}

	/**
	 * Only the model changed: the saved key stays where it is.
	 *
	 * @param string $mode  wizard|settings.
	 * @param string $label Provider name.
	 * @param array  $cur   Current settings.
	 * @param array  $form  The form.
	 */
	private static function save_model_only( $mode, $label, array $cur, array $form ) {
		$bad = self::check_models( $cur['models'], $form['model'], $form['fallback'] );
		if ( '' !== $bad ) {
			self::fail( $mode, $form, $bad );
		}
		if ( 'wizard' === $mode ) {
			PendingCredentials::set_models( $form['model'], $form['fallback'] );
			self::done( $mode, $label, $form['model'], $form );
		}
		$res = Cloud::post(
			'/v1/connector/ai/model',
			array(
				'model'          => $form['model'],
				'fallback_model' => $form['fallback'],
			),
			true,
			15
		);
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			self::fail( $mode, $form, is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? __( 'The model could not be saved. Try again.', 'presshelm-ai' ) ) );
		}
		$ai = (array) ( Connection::get()['ai'] ?? array() );
		Connection::update(
			array(
				'ai' => array_merge(
					$ai,
					array(
						'model'          => $form['model'],
						'fallback_model' => $form['fallback'],
					)
				),
			)
		);
		delete_transient( self::MODELS_CACHE );
		/* translators: 1: provider name, 2: model id */
		set_transient( 'presshelm_notice_' . get_current_user_id(), array( 'success', sprintf( __( 'Settings saved. PressHelm now uses %1$s with %2$s.', 'presshelm-ai' ), $label, $form['model'] ) ), 120 );
		Redirect::to( self::back_url( $mode ) );
	}

	/**
	 * After a key was staged in PressHelm Cloud or a sign-in finished: apply the model chosen in
	 * the form (or the suggestion), then save like the button does.
	 *
	 * @param string $mode   wizard|settings.
	 * @param array  $choice model, fallback (from the form).
	 */
	public static function finish_return( $mode, array $choice ) {
		$p        = PendingCredentials::record();
		$ai       = isset( $p['ai'] ) ? (array) $p['ai'] : array();
		$provider = (string) ( $ai['provider'] ?? '' );
		$models   = (array) ( $ai['models'] ?? array() );
		$info     = Providers::get( $provider );
		$model    = (string) ( $choice['model'] ?? '' );
		$fallback = (string) ( $choice['fallback'] ?? '' );
		if ( ! in_array( $model, $models, true ) ) {
			$model = Providers::suggested_model( $provider, $models );
		}
		if ( '' !== $fallback && ! in_array( $fallback, $models, true ) ) {
			$fallback = Providers::suggested_model( $provider, $models, 'cheap' );
		}
		PendingCredentials::set_models( $model, $fallback === $model ? '' : $fallback );
		self::done(
			'settings' === $mode && Connection::is_connected() ? 'settings' : 'wizard',
			$info ? (string) $info['label'] : $provider,
			$model,
			array(
				'provider' => $provider,
				'method'   => (string) ( $ai['method'] ?? 'api_key' ),
			)
		);
	}

	/**
	 * OpenRouter's public model list (no key needed); the suggestions when it can't be loaded.
	 *
	 * @return string[]
	 */
	public static function openrouter_models() {
		$ids = ProviderCheck::openrouter_models();
		return $ids ? $ids : self::presets( 'openrouter' );
	}

	/**
	 * The model choice kept across the sign-in.
	 *
	 * @return array
	 */
	public static function take_choice() {
		$key = self::CHOICE . get_current_user_id();
		$c   = get_transient( $key );
		delete_transient( $key );
		return is_array( $c ) ? $c : array();
	}

	// ------------------------------------------------------------------ "Refresh the list"

	/** The models a key (or the saved key) can use, for the model lists. */
	public static function ajax_models() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'presshelm_ai_models', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'presshelm-ai' ) ), 403 );
		}
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$mode     = isset( $_POST['mode'] ) && 'settings' === $_POST['mode'] ? 'settings' : 'wizard';
		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$key      = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a key must not be altered by sanitizers.
		if ( ! Providers::get( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose an AI provider.', 'presshelm-ai' ) ), 400 );
		}
		$models = null;
		if ( '' !== $key ) {
			if ( CloudStaging::enabled() ) {
				wp_send_json_error( array( 'message' => __( 'The models load when you save: your key goes straight to PressHelm Cloud.', 'presshelm-ai' ) ), 400 );
			}
			$models = ProviderCheck::validate( $provider, $key, $base_url );
		} else {
			$cur = self::current( $mode );
			if ( 'settings' === $mode && $cur['saved'] && $provider === $cur['provider'] ) {
				$list   = self::cloud_models( true );
				$models = is_wp_error( $list ) ? $list : (array) $list['models'];
			} elseif ( self::pending_for( $provider, 'api_key' ) || self::pending_for( $provider, 'oauth' ) ) {
				$pending = self::pending_for( $provider, 'api_key' ) ?? self::pending_for( $provider, 'oauth' );
				$models  = (array) $pending['models'];
			} else {
				wp_send_json_error( array( 'message' => __( 'Paste your API key first, then refresh the list.', 'presshelm-ai' ) ), 400 );
			}
		}
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'models'   => array_values( $models ),
				'model'    => Providers::suggested_model( $provider, $models ),
				'fallback' => Providers::suggested_model( $provider, $models, 'cheap' ),
				/* translators: %d: number of models */
				'message'  => sprintf( _n( 'Key works. %d model is available.', 'Key works. %d models are available.', count( $models ), 'presshelm-ai' ), count( $models ) ),
			)
		);
	}
}
