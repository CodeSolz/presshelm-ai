<?php
/**
 * The PressHelm page once the site is connected (D43): Setup · AI & Model · Telegram Bot ·
 * Cloud Connection · Agent Status · Permissions & Security · Task History · Backup & Rollback ·
 * Disconnect / Reconnect. Read-mostly; every action posts to admin-post.php with a nonce.
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

use PressHelm\Abilities\Registry;
use PressHelm\Bridge\Poller;
use PressHelm\Connect\Cloud;
use PressHelm\Connect\Connection;
use PressHelm\Connect\TelegramLink;
use PressHelm\Onboarding\Providers;
use PressHelm\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Admin tabs.
 */
final class Tabs {

	/**
	 * What each ability does, in plain words.
	 *
	 * @return array<string,string>
	 */
	public static function ability_labels() {
		$out = array();
		foreach ( Registry::all() as $id => $a ) {
			$out[ $id ] = (string) ( $a['label'] ?? $id );
		}
		return array_merge(
			$out,
			array(
				'site.inspect'              => __( 'Check the site (capability report)', 'presshelm-ai' ),
				'content.read'              => __( 'Read a post or page', 'presshelm-ai' ),
				'content.update'            => __( 'Edit a post or page', 'presshelm-ai' ),
				'styles.additional_css.set' => __( 'Change Additional CSS', 'presshelm-ai' ),
				'journal.restore'           => __( 'Undo an earlier change', 'presshelm-ai' ),
			)
		);
	}

	/**
	 * The page's areas (top tabs), each with its sections (left menu).
	 *
	 * @return array<string,array{0:string,1:array<string,array{0:string,1:string}>}> area → label, sections (label, icon).
	 */
	public static function groups() {
		return array(
			'overview'    => array(
				__( 'Overview', 'presshelm-ai' ),
				array(
					'home'  => array( __( 'Dashboard', 'presshelm-ai' ), 'dashboard' ),
					'setup' => array( __( 'Setup', 'presshelm-ai' ), 'list-check' ),
					'agent' => array( __( 'Agent status', 'presshelm-ai' ), 'bot' ),
				),
			),
			'connections' => array(
				__( 'Connections', 'presshelm-ai' ),
				array(
					'ai'         => array( __( 'AI & model', 'presshelm-ai' ), 'settings' ),
					'telegram'   => array( __( 'Telegram bot', 'presshelm-ai' ), 'send' ),
					'connection' => array( __( 'Cloud connection', 'presshelm-ai' ), 'cloud' ),
					'apps'       => array( __( 'Claude & ChatGPT apps', 'presshelm-ai' ), 'link' ),
				),
			),
			'activity'    => array(
				__( 'Activity', 'presshelm-ai' ),
				array(
					'history' => array( __( 'Task history', 'presshelm-ai' ), 'history' ),
					'usage'   => array( __( 'Usage and cost', 'presshelm-ai' ), 'chart' ),
					'backup'  => array( __( 'Backup & rollback', 'presshelm-ai' ), 'restore' ),
				),
			),
			'settings'    => array(
				__( 'Settings', 'presshelm-ai' ),
				array(
					'permissions' => array( __( 'Permissions & security', 'presshelm-ai' ), 'shield' ),
					'limit'       => array( __( 'Spend limit', 'presshelm-ai' ), 'sliders' ),
					'disconnect'  => array( __( 'Stop & disconnect', 'presshelm-ai' ), 'power' ),
				),
			),
		);
	}

	/**
	 * Section header texts: headline and one line under it.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function heads() {
		return array(
			'home'        => array( __( 'Your developer is on duty.', 'presshelm-ai' ), __( 'Everything PressHelm does on this site, at a glance.', 'presshelm-ai' ) ),
			'setup'       => array( __( 'Ready when you are.', 'presshelm-ai' ), __( 'Link Telegram, check the connection, and start chatting with your developer.', 'presshelm-ai' ) ),
			'agent'       => array( __( 'Your developer, at a glance.', 'presshelm-ai' ), __( 'What PressHelm is doing on this site, and what it may do.', 'presshelm-ai' ) ),
			'ai'          => array( __( 'Intelligence, configured.', 'presshelm-ai' ), __( 'Your provider. Your models. Your WordPress developer.', 'presshelm-ai' ) ),
			'telegram'    => array( __( 'Your bot, your chat.', 'presshelm-ai' ), __( 'Talk to PressHelm through your own Telegram bot.', 'presshelm-ai' ) ),
			'connection'  => array( __( 'Securely connected.', 'presshelm-ai' ), __( 'How this site and PressHelm Cloud talk to each other.', 'presshelm-ai' ) ),
			'apps'        => array( __( 'Bring your own chat app.', 'presshelm-ai' ), __( 'Use PressHelm from Claude or ChatGPT, on your own plan. Changes asked there are approved here.', 'presshelm-ai' ) ),
			'history'     => array( __( 'Everything, on the record.', 'presshelm-ai' ), __( 'Every task PressHelm ran on this site, with what it changed.', 'presshelm-ai' ) ),
			'usage'       => array( __( 'Usage, in plain numbers.', 'presshelm-ai' ), __( 'Every AI request PressHelm makes for this account, counted by PressHelm Cloud.', 'presshelm-ai' ) ),
			'backup'      => array( __( 'Every change, reversible.', 'presshelm-ai' ), __( 'Put back anything PressHelm changed.', 'presshelm-ai' ) ),
			'permissions' => array( __( 'You set the rules.', 'presshelm-ai' ), __( 'What PressHelm may do here, and what needs your approval first.', 'presshelm-ai' ) ),
			'limit'       => array( __( 'Spending, capped.', 'presshelm-ai' ), __( 'When a limit is reached, PressHelm stops using your AI until the next day or month (UTC) and tells you in Telegram.', 'presshelm-ai' ) ),
			'disconnect'  => array( __( 'Stay in control.', 'presshelm-ai' ), __( 'Stop everything at once, reconnect, or disconnect this site.', 'presshelm-ai' ) ),
		);
	}

	/**
	 * Area and section for an id (an area, a section, or an old tab name).
	 *
	 * @param string $id      Area or section id.
	 * @param string $section Section asked for within the area.
	 * @return array{0:string,1:string}
	 */
	public static function locate( $id, $section = '' ) {
		$groups = self::groups();
		if ( isset( $groups[ $id ] ) ) {
			$sections = $groups[ $id ][1];
			return array( $id, isset( $sections[ $section ] ) ? $section : (string) key( $sections ) );
		}
		foreach ( $groups as $g => $def ) {
			if ( isset( $def[1][ $id ] ) ) {
				return array( $g, $id );
			}
		}
		return array( 'overview', 'setup' );
	}

	/**
	 * URL of a section (or an area).
	 *
	 * @param string $tab Section or area id.
	 * @return string
	 */
	public static function url( $tab ) {
		list( $group, $section ) = self::locate( $tab );
		return WizardPage::url(
			'',
			array(
				'tab'     => $group,
				'section' => $section,
			)
		);
	}

	/**
	 * The area shown now.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function current() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable
		// Overview opens on the dashboard once setup is complete, on Setup until then.
		if ( 'overview' === $tab && '' === $section ) {
			$section = Dashboard::ready() ? 'home' : 'setup';
		}
		return self::locate( $tab, $section );
	}

	/**
	 * Page header: brand, cloud status and (when connected) the areas.
	 *
	 * @param bool $connected Whether the site is connected.
	 * @param bool $tabs      Show the areas.
	 */
	public static function header( $connected, $tabs = true ) {
		$brand = Brand::get();
		echo '<div class="ph-hero"><div class="ph-top"><h1 class="ph-brand">';
		if ( '' !== $brand['logo'] ) {
			echo '<img src="' . esc_url( $brand['logo'] ) . '" alt="" width="40" height="40" style="object-fit:contain">';
		} elseif ( __( 'PressHelm', 'presshelm-ai' ) === $brand['name'] ) {
			Icons::out( Icons::logo( 40 ) );
		}
		echo '<span>' . esc_html( $brand['name'] ) . '</span></h1><div class="ph-top-right">';
		echo $connected ? '<span class="ph-pill ph-pill-ok">' . esc_html__( 'Cloud connected', 'presshelm-ai' ) . '</span>' : '<span class="ph-pill ph-pill-warn">' . esc_html__( 'Not connected yet', 'presshelm-ai' ) . '</span>';
		echo '</div></div>';
		if ( $connected && $tabs ) {
			list( $group ) = self::current();
			echo '<nav class="ph-maintabs" aria-label="' . esc_attr( Brand::get()['name'] ) . '">';
			foreach ( self::groups() as $id => $def ) {
				printf(
					'<a href="%1$s" class="ph-maintab%2$s"%3$s>%4$s</a>',
					esc_url( self::url( $id ) ),
					$id === $group ? ' nav-tab-active is-active' : '',
					$id === $group ? ' aria-current="page"' : '',
					esc_html( $def[0] )
				);
			}
			echo '</nav>';
		}
		echo '</div>';
	}

	/**
	 * A status pill for a section header ('' when none).
	 *
	 * @param string $section Section id.
	 * @return string
	 */
	private static function head_pill( $section ) {
		$c = Connection::get();
		if ( 'ai' === $section ) {
			$ai   = (array) ( $c['ai'] ?? array() );
			$info = isset( $ai['provider'] ) ? Providers::get( (string) $ai['provider'] ) : null;
			if ( ! $ai ) {
				return '<span class="ph-pill ph-pill-warn">' . esc_html__( 'No AI connected', 'presshelm-ai' ) . '</span>';
			}
			$name = $info ? (string) ( $info['short'] ?? $info['label'] ) : (string) $ai['provider'];
			/* translators: %s: provider name */
			return '<span class="ph-pill ph-pill-ok">' . esc_html( sprintf( __( '%s connected', 'presshelm-ai' ), $name ) ) . '</span>';
		}
		if ( 'telegram' === $section && ! empty( $c['telegram']['username'] ) ) {
			return '<span class="ph-pill ph-pill-ok">@' . esc_html( (string) $c['telegram']['username'] ) . '</span>';
		}
		if ( 'connection' === $section ) {
			return '<span class="ph-pill ph-pill-ok">' . esc_html__( 'Cloud connected', 'presshelm-ai' ) . '</span>';
		}
		if ( 'home' === $section ) {
			if ( \PressHelm\Recovery\EmergencyStop::active() ) {
				return '<span class="ph-pill ph-pill-warn">' . esc_html__( 'Emergency stop on', 'presshelm-ai' ) . '</span>';
			}
			return Dashboard::ready() ? '<span class="ph-pill ph-pill-ok">' . esc_html__( 'All systems ready', 'presshelm-ai' ) . '</span>' : '<span class="ph-pill ph-pill-warn">' . esc_html__( 'Setup not finished', 'presshelm-ai' ) . '</span>';
		}
		return '';
	}

	/**
	 * The area: left menu of sections, the open section on the right.
	 */
	public static function render() {
		list( $group, $open ) = self::current();
		$def                  = self::groups()[ $group ];
		$heads                = self::heads();
		echo '<div class="ph-shell"><aside class="ph-side"><p class="ph-side-title" id="ph-side-title">' . esc_html( $def[0] ) . '</p>';
		echo '<div role="tablist" aria-orientation="vertical" aria-labelledby="ph-side-title" class="ph-vnav-list">';
		foreach ( $def[1] as $id => $sec ) {
			$on = $id === $open;
			printf(
				'<a role="tab" id="ph-tab-%1$s" href="%2$s" aria-controls="ph-panel-%1$s" aria-selected="%3$s" tabindex="%4$s" class="ph-vnav-item">',
				esc_attr( $id ),
				esc_url( self::url( $id ) ),
				$on ? 'true' : 'false',
				$on ? '0' : '-1'
			);
			Icons::out( Icons::line( $sec[1], 20 ) );
			echo '<span class="ph-vnav-label">' . esc_html( $sec[0] ) . '</span></a>';
		}
		echo '</div><div class="ph-side-help">';
		Icons::out( Icons::line( 'help', 22 ) );
		echo '<span>' . esc_html__( 'Need help with PressHelm?', 'presshelm-ai' ) . '<br><a href="https://presshelmai.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Visit presshelmai.com', 'presshelm-ai' ) . ' &rarr;</a></span></div></aside>';
		echo '<div class="ph-main">';
		foreach ( $def[1] as $id => $sec ) {
			printf( '<section class="ph-panel" role="tabpanel" id="ph-panel-%1$s" aria-labelledby="ph-tab-%1$s" tabindex="0"%2$s>', esc_attr( $id ), $id === $open ? '' : ' hidden' );
			echo '<div class="ph-head"><div class="ph-head-text"><p class="ph-eyebrow">' . esc_html( $sec[0] ) . '</p>';
			echo '<h2 class="ph-headline">' . esc_html( $heads[ $id ][0] ) . '</h2><p class="ph-sub">' . esc_html( $heads[ $id ][1] ) . '</p></div>';
			echo wp_kses( self::head_pill( $id ), array( 'span' => array( 'class' => true ) ) );
			echo '</div><div class="ph-body">';
			call_user_func( array( self::class, 'tab_' . $id ) );
			echo '</div></section>';
		}
		echo '</div></div>';
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Local time from a UTC MySQL datetime.
	 *
	 * @param string|null $gmt UTC datetime.
	 * @return string
	 */
	private static function when( $gmt ) {
		if ( empty( $gmt ) ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Two-column facts table.
	 *
	 * @param array<int,array{0:string,1:string}> $rows Label, value.
	 */
	private static function facts( array $rows ) {
		echo '<table class="presshelm-facts"><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $r[0] ), esc_html( $r[1] ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Small POST form with one button.
	 *
	 * @param string $action Action suffix (presshelm_*).
	 * @param string $label  Button text.
	 * @param array  $fields Hidden fields.
	 * @param string $classes Button classes.
	 */
	public static function button( $action, $label, array $fields = array(), $classes = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="presshelm-inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'presshelm_' . $action ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		wp_nonce_field( 'presshelm_' . $action );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	// ------------------------------------------------------------------ tabs

	/** Dashboard: everything at a glance. */
	private static function tab_home() {
		Dashboard::render();
	}

	/** Setup: the connection checklist, Telegram linking and approvals. */
	private static function tab_setup() {
		WizardPage::step_ready();
	}

	/** AI & model: the one form for provider, connection and models. */
	private static function tab_ai() {
		AiForm::render( 'settings' );
	}

	/** Usage and cost. */
	private static function tab_usage() {
		self::ai_usage();
	}

	/** Spend limit. */
	private static function tab_limit() {
		self::spend_limit();
	}

	/** Claude & ChatGPT apps. */
	private static function tab_apps() {
		self::apps_section();
	}

	/**
	 * AI usage and limits from PressHelm Cloud (which counts every AI request), cached a minute.
	 *
	 * @return array|null
	 */
	public static function usage_data() {
		$u = get_transient( 'presshelm_ai_usage' );
		if ( ! is_array( $u ) ) {
			$res = \PressHelm\Connect\Cloud::post( '/v1/connector/ai-usage', array(), true, 10 );
			$u   = ! is_wp_error( $res ) && 200 === $res['code'] ? $res['json'] : null;
			if ( $u ) {
				set_transient( 'presshelm_ai_usage', $u, MINUTE_IN_SECONDS );
			}
		}
		return is_array( $u ) ? $u : null;
	}

	/**
	 * Usage and cost.
	 *
	 * @param array|null $u Usage from the cloud (loaded when not given).
	 */
	private static function ai_usage( $u = null ) {
		$u = is_array( $u ) ? $u : self::usage_data();
		if ( ! $u ) {
			echo '<div class="ph-empty"><p>' . esc_html__( 'Usage could not be loaded from PressHelm Cloud just now.', 'presshelm-ai' ) . '</p><a class="button" href="' . esc_url( self::url( 'usage' ) ) . '">' . esc_html__( 'Try again', 'presshelm-ai' ) . '</a></div>';
			return;
		}
		echo '<div class="ph-stats">';
		foreach (
			array(
				__( 'Today', 'presshelm-ai' )      => (array) $u['today'],
				__( 'This month', 'presshelm-ai' ) => (array) $u['this_month'],
			) as $label => $t
		) {
			echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html( $label ) . '</span><span class="ph-stat-value">' . esc_html( (string) $t['estimated_cost'] ) . '</span>';
			/* translators: 1: number of tokens, 2: number of requests */
			echo '<span class="ph-stat-meta">' . esc_html( sprintf( __( '%1$s tokens · %2$s requests', 'presshelm-ai' ), number_format_i18n( (int) $t['tokens'] ), number_format_i18n( (int) $t['requests'] ) ) ) . '</span></div>';
		}
		echo '</div>';
		echo '<h3 class="ph-subhead">' . esc_html__( 'Last 30 days by model', 'presshelm-ai' ) . '</h3>';
		$models = array_slice( (array) ( $u['last_30_days_by_model'] ?? array() ), 0, 10 );
		if ( ! $models ) {
			echo '<div class="ph-empty"><p>' . esc_html__( 'No AI requests in the last 30 days.', 'presshelm-ai' ) . '</p></div>';
		} else {
			echo '<div class="ph-table-wrap"><table class="ph-table"><thead><tr><th scope="col">' . esc_html__( 'Model', 'presshelm-ai' ) . '</th><th scope="col" class="num">' . esc_html__( 'Requests', 'presshelm-ai' ) . '</th><th scope="col" class="num">' . esc_html__( 'Tokens', 'presshelm-ai' ) . '</th><th scope="col" class="num">' . esc_html__( 'Est. cost', 'presshelm-ai' ) . '</th></tr></thead><tbody>';
			foreach ( $models as $m ) {
				printf(
					'<tr><td><code>%1$s</code></td><td class="num">%2$s</td><td class="num">%3$s</td><td class="num">%4$s</td></tr>',
					esc_html( (string) $m['model'] ),
					esc_html( number_format_i18n( (int) $m['requests'] ) ),
					esc_html( number_format_i18n( (int) $m['tokens'] ) ),
					esc_html( (string) $m['estimated_cost'] )
				);
			}
			echo '</tbody></table></div>';
		}
		echo '<p class="ph-help">' . esc_html( (string) ( $u['note'] ?? '' ) ) . '</p>';
	}

	/**
	 * Spend limit: how much of each limit is used, and the form.
	 *
	 * @param array|null $u Usage from the cloud (loaded when not given).
	 */
	private static function spend_limit( $u = null ) {
		$u      = is_array( $u ) ? $u : self::usage_data();
		$g      = (array) ( $u['guard'] ?? array() );
		$today  = (array) ( $u['today'] ?? array() );
		$month  = (array) ( $u['this_month'] ?? array() );
		$meters = array(
			array( __( 'Today', 'presshelm-ai' ), (float) ( $today['estimated_cost_usd'] ?? 0 ), $g['daily_usd'] ?? null, 'usd' ),
			array( __( 'This month', 'presshelm-ai' ), (float) ( $month['estimated_cost_usd'] ?? 0 ), $g['monthly_usd'] ?? null, 'usd' ),
			array( __( 'Tokens today', 'presshelm-ai' ), (float) ( $today['tokens'] ?? 0 ), $g['daily_tokens'] ?? null, 'tokens' ),
		);
		if ( $u ) {
			echo '<div class="ph-meters">';
			foreach ( $meters as $m ) {
				$fmt   = static function ( $v, $kind ) {
					return 'usd' === $kind ? '$' . number_format_i18n( (float) $v, 2 ) : number_format_i18n( (float) $v );
				};
				$limit = null === $m[2] ? null : (float) $m[2];
				$pct   = $limit && $limit > 0 ? min( 100, (int) round( $m[1] / $limit * 100 ) ) : 0;
				echo '<div class="ph-meter' . ( $limit && $pct >= 80 ? ' is-high' : '' ) . '"><div class="ph-meter-top"><span>' . esc_html( $m[0] ) . '</span><span>';
				/* translators: 1: amount used, 2: the limit */
				echo esc_html( null === $limit ? sprintf( __( '%s · no limit', 'presshelm-ai' ), $fmt( $m[1], $m[3] ) ) : sprintf( __( '%1$s of %2$s', 'presshelm-ai' ), $fmt( $m[1], $m[3] ), $fmt( $limit, $m[3] ) ) );
				echo '</span></div>';
				if ( null !== $limit ) {
					printf( '<div class="ph-bar" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%2$d"><span style="width:%2$d%%"></span></div>', esc_attr( $m[0] ), (int) $pct );
				}
				echo '</div>';
			}
			echo '</div>';
		} else {
			echo '<p class="ph-note ph-note-warn">' . esc_html__( 'Usage could not be loaded from PressHelm Cloud just now. You can still change the limits.', 'presshelm-ai' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ph-form">';
		echo '<input type="hidden" name="action" value="presshelm_save_ai_guard">';
		wp_nonce_field( 'presshelm_save_ai_guard' );
		echo '<div class="ph-field-grid three">';
		foreach (
			array(
				'daily_usd'    => array( __( 'Per day', 'presshelm-ai' ), '0.01', '$', '' ),
				'monthly_usd'  => array( __( 'Per month', 'presshelm-ai' ), '0.01', '$', '' ),
				'daily_tokens' => array( __( 'Tokens per day', 'presshelm-ai' ), '1', '', __( 'tokens', 'presshelm-ai' ) ),
			) as $field => $spec
		) {
			echo '<div class="ph-field"><label class="ph-label" for="' . esc_attr( 'presshelm_' . $field ) . '">' . esc_html( $spec[0] ) . '</label><div class="ph-input-group">';
			if ( '' !== $spec[2] ) {
				echo '<span class="ph-affix" aria-hidden="true">' . esc_html( $spec[2] ) . '</span>';
			}
			printf(
				'<input type="number" min="0" step="%1$s" id="%2$s" name="%3$s" value="%4$s" class="ph-input" placeholder="%5$s">',
				esc_attr( $spec[1] ),
				esc_attr( 'presshelm_' . $field ),
				esc_attr( $field ), // The field name the save handler reads.
				esc_attr( isset( $g[ $field ] ) && null !== $g[ $field ] ? (string) $g[ $field ] : '' ),
				esc_attr__( 'No limit', 'presshelm-ai' )
			);
			if ( '' !== $spec[3] ) {
				echo '<span class="ph-affix" aria-hidden="true">' . esc_html( $spec[3] ) . '</span>';
			}
			echo '</div></div>';
		}
		echo '</div><p class="ph-help">' . esc_html__( 'Leave a field empty for no limit. The token limit also works for models without a known price.', 'presshelm-ai' ) . '</p>';
		echo '<div class="ph-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save Settings', 'presshelm-ai' ) . '</button></div></form>';
	}

	/** Claude and ChatGPT apps (M4.18): the connector address and the apps connected now. */
	private static function apps_section() {
		echo '<div class="ph-box"><label class="ph-label" for="presshelm-mcp-url">' . esc_html__( 'Connector address', 'presshelm-ai' ) . '</label>';
		echo '<div class="ph-key-row"><input id="presshelm-mcp-url" type="text" class="ph-input code" readonly value="' . esc_attr( \PressHelm\Connect\Apps::mcp_url() ) . '">';
		echo '<button type="button" class="button ph-copy" data-copy="presshelm-mcp-url" data-done="' . esc_attr__( 'Copied', 'presshelm-ai' ) . '">' . esc_html__( 'Copy', 'presshelm-ai' ) . '</button></div>';
		echo '<p class="ph-help">' . esc_html__( 'Paste it into your app, then sign in with this site\'s address and allow it here in WordPress.', 'presshelm-ai' ) . '</p></div>';
		echo '<div class="ph-cards two">';
		echo '<div class="ph-box"><h3>' . esc_html__( 'Claude', 'presshelm-ai' ) . '</h3><ol class="ph-steps">';
		echo '<li>' . esc_html__( 'Settings → Connectors → Add custom connector.', 'presshelm-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the connector address and add it.', 'presshelm-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Connect, enter this site\'s address, allow it in WordPress.', 'presshelm-ai' ) . '</li></ol></div>';
		echo '<div class="ph-box"><h3>' . esc_html__( 'ChatGPT', 'presshelm-ai' ) . '</h3><ol class="ph-steps">';
		echo '<li>' . esc_html__( 'Settings → Apps → Advanced settings: turn on Developer mode.', 'presshelm-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Create app and paste the connector address.', 'presshelm-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Sign in with this site\'s address, allow it in WordPress.', 'presshelm-ai' ) . '</li></ol></div>';
		echo '</div>';
		echo '<h3 class="ph-subhead">' . esc_html__( 'Connected apps', 'presshelm-ai' ) . '</h3>';
		$apps = \PressHelm\Connect\Apps::all();
		if ( is_wp_error( $apps ) ) {
			echo '<p class="ph-note ph-note-warn">' . esc_html( $apps->get_error_message() ) . '</p>';
			return;
		}
		if ( ! $apps ) {
			echo '<div class="ph-empty"><p>' . esc_html__( 'No apps are connected.', 'presshelm-ai' ) . '</p></div>';
			return;
		}
		$iso = static function ( $v ) {
			return $v ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $v ) ) : null;
		};
		echo '<div class="ph-table-wrap"><table class="ph-table"><thead><tr><th scope="col">' . esc_html__( 'App', 'presshelm-ai' ) . '</th><th scope="col">' . esc_html__( 'Allowed by', 'presshelm-ai' ) . '</th><th scope="col">' . esc_html__( 'Connected', 'presshelm-ai' ) . '</th><th scope="col">' . esc_html__( 'Last used', 'presshelm-ai' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'presshelm-ai' ) . '</span></th></tr></thead><tbody>';
		foreach ( $apps as $a ) {
			echo '<tr><td><span class="ph-pill ph-pill-ok">' . esc_html__( 'Active', 'presshelm-ai' ) . '</span> <strong>' . esc_html( (string) ( $a['app'] ?? '' ) ) . '</strong></td><td>' . esc_html( (string) ( $a['approved_by'] ?? '—' ) ) . '</td><td>' . esc_html( self::when( $iso( $a['created_at'] ?? null ) ) ) . '</td><td>' . esc_html( self::when( $iso( $a['last_used_at'] ?? null ) ) ) . '</td><td class="num">';
			self::button( 'app_revoke', __( 'Disconnect', 'presshelm-ai' ), array( 'grant_id' => (string) ( $a['id'] ?? '' ) ), 'button ph-danger' );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** The customer's bot. */
	private static function tab_telegram() {
		$tg     = (array) ( Connection::get()['telegram'] ?? array() );
		$live   = $tg ? TelegramLink::status() : null;
		$ok     = is_array( $live ) && ! empty( $live['bot'] );
		$owner  = $ok && ! empty( $live['owner'] ) ? (array) $live['owner'] : array();
		$status = __( 'unknown', 'presshelm-ai' );
		if ( is_wp_error( $live ) ) {
			$status = __( 'could not check right now', 'presshelm-ai' );
		} elseif ( $ok ) {
			$status = 'set' === ( $live['webhook'] ?? '' ) ? __( 'active', 'presshelm-ai' ) : __( 'not active yet (PressHelm will retry)', 'presshelm-ai' );
		}
		if ( ! $tg ) {
			echo '<p>' . esc_html__( 'No bot is stored for this site yet.', 'presshelm-ai' ) . '</p>';
		} else {
			self::facts(
				array(
					array( __( 'Bot', 'presshelm-ai' ), ! empty( $tg['username'] ) ? '@' . $tg['username'] : '—' ),
					array( __( 'Status', 'presshelm-ai' ), $status ),
					array( __( 'Owner', 'presshelm-ai' ), $owner ? ( ! empty( $owner['username'] ) ? '@' . $owner['username'] : (string) $owner['id'] ) : __( 'not linked yet (see Setup)', 'presshelm-ai' ) ),
					array( __( 'Token', 'presshelm-ai' ), ! empty( $tg['shared'] ) ? __( 'kept with the shared bot', 'presshelm-ai' ) : ( ! empty( $tg['hint'] ) ? (string) $tg['hint'] : '—' ) ),
				)
			);
			if ( ! empty( $tg['shared'] ) ) {
				echo '<p class="description">' . esc_html__( 'This site uses the shared bot of your PressHelm account: in Telegram, the bot asks which site you want to work on (/site switches). Which sites share the bot is set in PressHelm Cloud → Sites.', 'presshelm-ai' ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Only the linked owner can give instructions; everyone else is ignored. Changing the token of the same bot keeps the owner; a different bot needs linking again.', 'presshelm-ai' ) . '</p>';
		self::button( 'change_start', __( 'Use a different bot or a new token', 'presshelm-ai' ), array( 'what' => 'telegram' ), 'button button-primary' );
	}

	/** Connection facts. */
	private static function tab_connection() {
		$c    = Connection::get();
		$by   = ! empty( $c['connected_by'] ) ? get_userdata( (int) $c['connected_by'] ) : false;
		$beat = (int) get_transient( Poller::HEARTBEAT );
		$next = wp_next_scheduled( Poller::HOOK );
		self::facts(
			array(
				array( __( 'Cloud', 'presshelm-ai' ), Cloud::base_url() ),
				array( __( 'Site id', 'presshelm-ai' ), (string) ( $c['site_id'] ?? '—' ) ),
				array( __( 'Connected', 'presshelm-ai' ), ! empty( $c['connected_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $c['connected_at'] ) : '—' ),
				array( __( 'Connected by', 'presshelm-ai' ), $by ? $by->display_name : '—' ),
				array( __( 'Last check-in', 'presshelm-ai' ), $beat ? wp_date( get_option( 'time_format' ), $beat ) : __( 'more than 5 minutes ago', 'presshelm-ai' ) ),
				array( __( 'Next check for work', 'presshelm-ai' ), $next ? wp_date( get_option( 'time_format' ), (int) $next ) : __( 'not scheduled', 'presshelm-ai' ) ),
			)
		);
		echo '<p class="description">' . esc_html__( 'This site asks PressHelm Cloud for work about once a minute (and immediately when woken). Every message in both directions is signed; the cloud cannot run anything that is not in the Permissions list.', 'presshelm-ai' ) . '</p>';
		self::button( 'rotate_keys', __( 'Replace connection keys', 'presshelm-ai' ) );
		echo '<p class="description">' . esc_html__( 'Creates a new signing key for this site; the old one stops working at once. Use it if you think the site\'s database was exposed.', 'presshelm-ai' ) . '</p>';
	}

	/** Agent status: connection, last contact, limits, recent work. */
	private static function tab_agent() {
		global $wpdb;
		$table  = Schema::table( 'ledger' );
		$last   = $wpdb->get_row( $wpdb->prepare( 'SELECT ability, status, created_at FROM %i ORDER BY created_at DESC LIMIT 1', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live status.
		$counts = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY status', $table, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live status.
		$by     = array();
		foreach ( $counts as $c ) {
			$by[ (string) $c['status'] ] = (int) $c['n'];
		}
		$labels  = self::ability_labels();
		$contact = (int) get_transient( Poller::HEARTBEAT );
		$stop    = \PressHelm\Recovery\EmergencyStop::active();
		$limits  = array_filter(
			\PressHelm\Safety\LocalPolicy::modes(),
			static function ( $m ) {
				return 'default' !== $m;
			}
		);
		if ( $stop ) {
			echo '<p class="presshelm-stopped">' . esc_html__( 'Emergency stop is on: nothing runs on this site.', 'presshelm-ai' ) . ' <a href="' . esc_url( self::url( 'disconnect' ) ) . '">' . esc_html__( 'Resume', 'presshelm-ai' ) . '</a></p>';
		}
		self::facts(
			array(
				array( __( 'Connection', 'presshelm-ai' ), Connection::is_connected() ? __( 'Connected to PressHelm Cloud', 'presshelm-ai' ) : __( 'Not connected', 'presshelm-ai' ) ),
				/* translators: %s: human time difference */
				array( __( 'Last contact with the cloud', 'presshelm-ai' ), $contact ? sprintf( __( '%s ago', 'presshelm-ai' ), human_time_diff( $contact ) ) : __( 'not in the last few minutes', 'presshelm-ai' ) ),
				array( __( 'Your limits', 'presshelm-ai' ), $limits ? implode( ', ', array_map( array( self::class, 'limit_text' ), array_keys( $limits ), $limits ) ) : __( 'none (standard rules)', 'presshelm-ai' ) ),
				array( __( 'Last task on this site', 'presshelm-ai' ), $last ? sprintf( '%1$s — %2$s (%3$s)', $labels[ $last['ability'] ] ?? $last['ability'], self::status_text( (string) $last['status'] ), self::when( $last['created_at'] ) ) : __( 'none yet', 'presshelm-ai' ) ),
				/* translators: 1: tasks done, 2: tasks undone automatically, 3: refused or failed */
				array( __( 'Last 24 hours', 'presshelm-ai' ), sprintf( __( '%1$d done, %2$d undone automatically, %3$d refused or failed', 'presshelm-ai' ), $by['succeeded'] ?? 0, $by['rolled_back'] ?? 0, ( $by['failed'] ?? 0 ) + ( $by['rejected'] ?? 0 ) ) ),
			)
		);
		echo '<p class="description">' . esc_html__( 'The AI developer itself runs in PressHelm Cloud and talks to you in Telegram; this page shows what reaches this site.', 'presshelm-ai' ) . '</p>';
	}

	/**
	 * "Area: mode" for a limit.
	 *
	 * @param string $area Area id.
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function limit_text( $area, $mode ) {
		$labels = \PressHelm\Safety\LocalPolicy::labels();
		return ( $labels[ $area ] ?? $area ) . ': ' . ( 'off' === $mode ? __( 'never', 'presshelm-ai' ) : __( 'always ask me', 'presshelm-ai' ) );
	}

	/**
	 * Plain words for a task status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_text( $status ) {
		$map = array(
			'succeeded'   => __( 'done', 'presshelm-ai' ),
			'rolled_back' => __( 'undone automatically (the site check failed)', 'presshelm-ai' ),
			'failed'      => __( 'failed', 'presshelm-ai' ),
			'rejected'    => __( 'refused', 'presshelm-ai' ),
			'running'     => __( 'running', 'presshelm-ai' ),
		);
		return $map[ $status ] ?? $status;
	}

	/** What the agent may do, and what needs approval. */
	private static function tab_permissions() {
		echo '<p>' . esc_html__( 'Tasks run as the "PressHelm" user, never as an administrator. It can only change content (posts, pages, media, menus, products and orders) and cannot log in.', 'presshelm-ai' ) . '</p>';
		self::policy_form();
		$rules = array(
			'READ'   => __( 'Runs at once (reads only)', 'presshelm-ai' ),
			'LOW'    => __( 'Runs at once; can be undone', 'presshelm-ai' ),
			'MEDIUM' => __( 'Needs your approval in Telegram; can be undone', 'presshelm-ai' ),
			'HIGH'   => __( 'Needs your approval in Telegram', 'presshelm-ai' ),
		);
		echo '<h3>' . esc_html__( 'What the agent can do here', 'presshelm-ai' ) . '</h3>';
		echo '<table class="widefat striped presshelm-table"><thead><tr><th>' . esc_html__( 'The agent can', 'presshelm-ai' ) . '</th><th>' . esc_html__( 'Rule', 'presshelm-ai' ) . '</th></tr></thead><tbody>';
		$labels = self::ability_labels();
		foreach ( Registry::all() as $id => $a ) {
			$mode = \PressHelm\Safety\LocalPolicy::mode( $id );
			$rule = $rules[ $a['risk'] ] ?? __( 'Needs approval', 'presshelm-ai' );
			if ( 'off' === $mode ) {
				$rule = __( 'Never (your setting)', 'presshelm-ai' );
			} elseif ( 'ask' === $mode && ! empty( $a['mutates'] ) ) {
				$rule = __( 'Needs your approval in Telegram (your setting)', 'presshelm-ai' );
			}
			printf( '<tr class="%3$s"><td>%1$s</td><td>%2$s</td></tr>', esc_html( $labels[ $id ] ?? $id ), esc_html( $rule ), esc_attr( 'presshelm-mode-' . $mode ) );
		}
		echo '</tbody></table>';
		self::site_allows();
		self::mcp_form();
		echo '<ul class="presshelm-bullets">';
		foreach (
			array(
				__( 'Nothing else can run: unknown requests are rejected by this plugin.', 'presshelm-ai' ),
				__( 'Every change is recorded before it happens (Backup & Rollback) and the site is checked afterwards; if it breaks, the change is undone automatically.', 'presshelm-ai' ),
				__( 'Requests must be signed by PressHelm Cloud for this site; old or copied requests are refused.', 'presshelm-ai' ),
				__( 'Your AI key and bot token are not stored on this site.', 'presshelm-ai' ),
			) as $line
		) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}

	/** The owner's own limits (LocalPolicy): per area, as usual / always ask me / never. */
	private static function policy_form() {
		$modes   = \PressHelm\Safety\LocalPolicy::modes();
		$choices = array(
			'default' => __( 'As usual', 'presshelm-ai' ),
			'ask'     => __( 'Always ask me', 'presshelm-ai' ),
			'off'     => __( 'Never', 'presshelm-ai' ),
		);
		echo '<h3>' . esc_html__( 'Your limits', 'presshelm-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( '"Always ask me" makes every change in that area wait for your approval in Telegram; "Never" refuses everything in that area, even reading. This site enforces these itself, whatever PressHelm Cloud asks. Undo always stays available.', 'presshelm-ai' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="presshelm_save_policy">';
		wp_nonce_field( 'presshelm_save_policy' );
		echo '<table class="widefat striped presshelm-table presshelm-policy"><tbody>';
		foreach ( \PressHelm\Safety\LocalPolicy::labels() as $area => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			foreach ( $choices as $value => $text ) {
				printf(
					'<label class="presshelm-choice"><input type="radio" name="%1$s" value="%2$s"%3$s> %4$s</label> ',
					esc_attr( 'policy_' . $area ),
					esc_attr( $value ),
					checked( $modes[ $area ], $value, false ),
					esc_html( $text )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save limits', 'presshelm-ai' ) . '</button></p></form>';
	}

	/** Opt-in to the official WordPress MCP Adapter (M3.14): read-only abilities only. */
	private static function mcp_form() {
		echo '<h3>' . esc_html__( 'Other AI tools (WordPress MCP Adapter)', 'presshelm-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'If you use the WordPress MCP Adapter to connect other AI tools to this site, you can let them use PressHelm\'s read-only abilities. They run as the WordPress user who connects them. Abilities that change the site are never offered there: those stay behind your approvals in Telegram.', 'presshelm-ai' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="presshelm_save_mcp">';
		wp_nonce_field( 'presshelm_save_mcp' );
		printf(
			'<label class="presshelm-choice"><input type="checkbox" name="mcp_expose" value="1"%1$s> %2$s</label> ',
			checked( \PressHelm\Abilities\ThirdParty::mcp_exposed(), true, false ),
			esc_html__( 'Offer PressHelm\'s read-only abilities to the MCP Adapter', 'presshelm-ai' )
		);
		echo '<button type="submit" class="button">' . esc_html__( 'Save', 'presshelm-ai' ) . '</button></form>';
	}

	/** Honest report of what this site allows (M3.1): each "no" with its reason. */
	private static function site_allows() {
		$labels = array(
			'content_edit'   => __( 'Edit posts and pages', 'presshelm-ai' ),
			'undo'           => __( 'Undo changes', 'presshelm-ai' ),
			'health_check'   => __( 'Check the site after each change', 'presshelm-ai' ),
			'scheduled_work' => __( 'Background work (WP-Cron)', 'presshelm-ai' ),
			'woocommerce'    => __( 'WooCommerce', 'presshelm-ai' ),
			'elementor'      => __( 'Elementor pages', 'presshelm-ai' ),
		);
		/**
		 * Checks an add-on adds to "What this site allows" (key in the site report → label).
		 *
		 * @param array<string,string> $labels Labels.
		 */
		$labels   = (array) apply_filters( 'presshelm_site_allows_labels', $labels );
		$supports = \PressHelm\Capabilities\Inventory::report( false )['supports'];
		echo '<h3>' . esc_html__( 'What this site allows', 'presshelm-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Whether the setup of this site permits each kind of work. The agent can only do what is listed in the table above; more abilities arrive in updates.', 'presshelm-ai' ) . '</p>';
		echo '<ul class="presshelm-checklist">';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $supports[ $key ] ) ) {
				continue;
			}
			$s = $supports[ $key ];
			printf( '<li class="%1$s">%2$s</li>', esc_attr( $s['ok'] ? 'ok' : 'pending' ), esc_html( $s['ok'] ? $label : $label . ' — ' . ( $s['reason'] ?? '' ) ) );
		}
		echo '</ul>';
	}

	/** Themes PressHelm made (M3.7): only the owner switches to them, in Appearance → Themes. */
	private static function custom_themes() {
		$mine = \PressHelm\Abilities\Themes::all();
		if ( ! $mine ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Themes made by PressHelm', 'presshelm-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'PressHelm never switches your theme. Drafts are published by PressHelm with a backup; child themes are activated by you.', 'presshelm-ai' ) . '</p>';
		echo '<table class="widefat striped presshelm-table"><tbody>';
		foreach ( $mine as $slug => $info ) {
			$theme = wp_get_theme( (string) $slug );
			if ( ! $theme->exists() ) {
				continue;
			}
			/* translators: %s: theme name */
			$kind = 'draft' === $info['kind'] ? sprintf( __( 'Draft of %s', 'presshelm-ai' ), (string) $info['of'] ) : sprintf( __( 'Child theme of %s', 'presshelm-ai' ), (string) $info['of'] );
			if ( 'new' === $info['kind'] ) {
				$kind = __( 'New theme', 'presshelm-ai' );
			}
			echo '<tr><td><strong>' . esc_html( $theme->get( 'Name' ) ) . '</strong><br><code>' . esc_html( (string) $slug ) . '</code></td><td>' . esc_html( $kind ) . '</td><td>';
			if ( get_stylesheet() === $slug ) {
				echo '<span class="presshelm-status succeeded">' . esc_html__( 'Active', 'presshelm-ai' ) . '</span>';
			} elseif ( in_array( $info['kind'], array( 'child', 'new' ), true ) && current_user_can( 'switch_themes' ) ) {
				// WordPress's own theme details, with Live Preview and Activate.
				echo '<a class="button button-small" href="' . esc_url( admin_url( 'themes.php?theme=' . rawurlencode( (string) $slug ) ) ) . '">' . esc_html__( 'Review and activate', 'presshelm-ai' ) . '</a>';
			} elseif ( ! empty( $info['published_at'] ) ) {
				/* translators: %s: human time difference */
				echo esc_html( sprintf( __( 'Published %s ago', 'presshelm-ai' ), human_time_diff( (int) $info['published_at'] ) ) );
			} else {
				echo esc_html__( 'Not published yet', 'presshelm-ai' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Tasks this site ran, each with its receipt. */
	private static function tab_history() {
		do_action( 'presshelm_owner_items' ); // The add-on lists the plugins it wrote.
		self::custom_themes();
		global $wpdb;
		$table = Schema::table( 'ledger' );
		self::task_stats( $table );
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live list.
		$page   = self::page_number( 'hp', $total );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT command_id, ability, status, result, created_at, finished_at FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live list.
		$labels = self::ability_labels();
		$all    = Registry::all();
		if ( ! $rows ) {
			echo '<div class="ph-empty"><p>' . esc_html__( 'No tasks yet. Send your bot a message to start.', 'presshelm-ai' ) . '</p></div>';
			return;
		}
		echo '<h3 class="ph-subhead">' . esc_html__( 'All tasks', 'presshelm-ai' ) . '</h3>';
		echo '<table class="widefat striped presshelm-table"><thead><tr><th>' . esc_html__( 'When', 'presshelm-ai' ) . '</th><th>' . esc_html__( 'Task', 'presshelm-ai' ) . '</th><th>' . esc_html__( 'Approval', 'presshelm-ai' ) . '</th><th>' . esc_html__( 'Result', 'presshelm-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$out    = json_decode( (string) $r['result'], true );
			$out    = is_array( $out ) ? $out : array();
			$result = is_array( $out['result'] ?? null ) ? $out['result'] : array();
			$risk   = (string) ( $all[ $r['ability'] ]['risk'] ?? '' );
			if ( ! empty( $out['approved'] ) ) {
				$approval = __( 'Approved by you in Telegram', 'presshelm-ai' );
			} elseif ( 'READ' === $risk ) {
				$approval = __( 'Not needed (read only)', 'presshelm-ai' );
			} elseif ( 'LOW' === $risk ) {
				$approval = __( 'Not needed (low risk)', 'presshelm-ai' );
			} else {
				$approval = __( 'No approval attached', 'presshelm-ai' );
			}
			echo '<tr><td>' . esc_html( self::when( $r['created_at'] ) ) . '</td><td>' . esc_html( $labels[ $r['ability'] ] ?? $r['ability'] ) . '</td><td>' . esc_html( $approval ) . '</td><td>';
			printf( '<span class="presshelm-status %1$s">%2$s</span>', esc_attr( sanitize_html_class( $r['status'] ) ), esc_html( self::status_text( (string) $r['status'] ) ) );
			$receipt = array();
			if ( isset( $result['error'] ) ) {
				$receipt[] = __( 'Reason:', 'presshelm-ai' ) . ' ' . (string) ( $result['message'] ?? $result['error'] );
			}
			if ( isset( $result['health']['ok'] ) ) {
				$receipt[] = $result['health']['ok'] ? __( 'Site checked afterwards: OK', 'presshelm-ai' ) : __( 'Site check failed, so the change was undone', 'presshelm-ai' );
			}
			if ( isset( $result['journal_id'] ) ) {
				/* translators: %d: backup record number */
				$receipt[] = sprintf( __( 'Backup record #%d (Backup & Rollback)', 'presshelm-ai' ), (int) $result['journal_id'] );
			}
			/* translators: %s: task id */
			$receipt[] = sprintf( __( 'Task id: %s', 'presshelm-ai' ), (string) $r['command_id'] );
			echo '<details class="presshelm-receipt"><summary>' . esc_html__( 'Receipt', 'presshelm-ai' ) . '</summary><ul>';
			foreach ( $receipt as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></details></td></tr>';
		}
		echo '</tbody></table>';
		self::pager( 'hp', 'history', $page, $total );
	}

	/** Rows per page in Task history and Backup & rollback. */
	const PER_PAGE = 20;

	/**
	 * The page asked for (1 … last).
	 *
	 * @param string $param Query parameter.
	 * @param int    $total Rows.
	 * @return int
	 */
	private static function page_number( $param, $total ) {
		$page = isset( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return max( 1, min( $page, max( 1, (int) ceil( $total / self::PER_PAGE ) ) ) );
	}

	/**
	 * "Showing 21–40 of 134" and page links.
	 *
	 * @param string $param   Query parameter.
	 * @param string $section Section id.
	 * @param int    $page    Current page.
	 * @param int    $total   Rows.
	 */
	private static function pager( $param, $section, $page, $total ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$link  = static function ( $n ) use ( $param, $section ) {
			return add_query_arg( $param, $n, self::url( $section ) );
		};
		$first = ( $page - 1 ) * self::PER_PAGE + 1;
		echo '<nav class="ph-pager" aria-label="' . esc_attr__( 'Pages', 'presshelm-ai' ) . '"><span class="ph-pager-info">';
		/* translators: 1: first row, 2: last row, 3: all rows */
		echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s', 'presshelm-ai' ), number_format_i18n( $first ), number_format_i18n( min( $total, $first + self::PER_PAGE - 1 ) ), number_format_i18n( $total ) ) );
		echo '</span><span class="ph-pager-links">';
		if ( $page > 1 ) {
			echo '<a class="ph-page" href="' . esc_url( $link( $page - 1 ) ) . '" rel="prev">&larr; ' . esc_html__( 'Previous', 'presshelm-ai' ) . '</a>';
		}
		$shown = array_unique(
			array_filter(
				array( 1, $page - 1, $page, $page + 1, $pages ),
				static function ( $n ) use ( $pages ) {
					return $n >= 1 && $n <= $pages;
				}
			)
		); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
		sort( $shown );
		$prev = 0;
		foreach ( $shown as $n ) {
			if ( $prev && $n > $prev + 1 ) {
				echo '<span class="ph-page-gap" aria-hidden="true">…</span>';
			}
			if ( $n === $page ) {
				echo '<span class="ph-page is-current" aria-current="page">' . esc_html( number_format_i18n( $n ) ) . '</span>';
			} else {
				/* translators: %s: page number */
				echo '<a class="ph-page" href="' . esc_url( $link( $n ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Page %s', 'presshelm-ai' ), number_format_i18n( $n ) ) ) . '">' . esc_html( number_format_i18n( $n ) ) . '</a>';
			}
			$prev = $n;
		}
		if ( $page < $pages ) {
			echo '<a class="ph-page" href="' . esc_url( $link( $page + 1 ) ) . '" rel="next">' . esc_html__( 'Next', 'presshelm-ai' ) . ' &rarr;</a>';
		}
		echo '</span></nav>';
	}

	/**
	 * Today and this month (site time): tasks, done, approved by the owner, failed, refused.
	 *
	 * @param string $table Ledger table.
	 */
	private static function task_stats( $table ) {
		global $wpdb;
		$utc     = new \DateTimeZone( 'UTC' );
		$periods = array(
			__( 'Today', 'presshelm-ai' )      => ( new \DateTimeImmutable( 'today', wp_timezone() ) )->setTimezone( $utc ),
			__( 'This month', 'presshelm-ai' ) => ( new \DateTimeImmutable( 'first day of this month 00:00', wp_timezone() ) )->setTimezone( $utc ),
		);
		echo '<div class="ph-stats">';
		foreach ( $periods as $label => $since ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, SUM(result LIKE %s) AS approved FROM %i WHERE created_at >= %s GROUP BY status', '%' . $wpdb->esc_like( '"approved":true' ) . '%', $table, $since->format( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live numbers.
			$c    = array(
				'all'      => 0,
				'done'     => 0,
				'approved' => 0,
				'failed'   => 0,
				'refused'  => 0,
			);
			foreach ( $rows as $r ) {
				$n              = (int) $r['n'];
				$c['all']      += $n;
				$c['approved'] += (int) $r['approved'];
				if ( 'succeeded' === $r['status'] ) {
					$c['done'] += $n;
				} elseif ( 'rejected' === $r['status'] ) {
					$c['refused'] += $n;
				} elseif ( in_array( $r['status'], array( 'failed', 'rolled_back' ), true ) ) {
					$c['failed'] += $n;
				}
			}
			echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html( $label ) . '</span>';
			/* translators: %s: number of tasks */
			echo '<span class="ph-stat-value">' . esc_html( sprintf( _n( '%s task', '%s tasks', $c['all'], 'presshelm-ai' ), number_format_i18n( $c['all'] ) ) ) . '</span><span class="ph-stat-chips">';
			foreach (
				array(
					'done'     => array( __( 'done', 'presshelm-ai' ), 'ok' ),
					'approved' => array( __( 'approved by you', 'presshelm-ai' ), 'info' ),
					'failed'   => array( __( 'failed or undone', 'presshelm-ai' ), 'bad' ),
					'refused'  => array( __( 'refused', 'presshelm-ai' ), 'muted' ),
				) as $k => $spec
			) {
				printf( '<span class="ph-chip-stat is-%1$s"><strong>%2$s</strong> %3$s</span>', esc_attr( $spec[1] ), esc_html( number_format_i18n( $c[ $k ] ) ), esc_html( $spec[0] ) );
			}
			echo '</span></div>';
		}
		echo '</div>';
	}

	/** Recorded changes (files, folders, database rows, content), with local undo. */
	private static function tab_backup() {
		global $wpdb;
		$table = Schema::table( 'journal' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live list.
		$page  = self::page_number( 'bp', $total );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, target, before_state, created_at, restored_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live list.
		echo '<p class="description">' . esc_html__( 'Before each change, PressHelm saves what it is about to change: the old content, file or folder, or the database rows. You can put any of them back here. Saved copies of files, folders and database rows are kept for 30 days.', 'presshelm-ai' ) . '</p>';
		$store = self::backup_storage();
		/* translators: 1: number of saved copies, 2: size */
		echo '<p>' . esc_html( sprintf( __( 'Saved copies on this site: %1$d (%2$s), in a folder the web cannot open.', 'presshelm-ai' ), $store['count'], size_format( $store['bytes'] ) ) ) . '</p>';
		if ( ! $rows ) {
			echo '<div class="ph-empty"><p>' . esc_html__( 'No changes recorded yet.', 'presshelm-ai' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped presshelm-table"><thead><tr><th>#</th><th>' . esc_html__( 'When', 'presshelm-ai' ) . '</th><th>' . esc_html__( 'What was saved', 'presshelm-ai' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . (int) $r['id'] . '</td><td>' . esc_html( self::when( $r['created_at'] ) ) . '</td><td>' . esc_html( self::journal_text( $r ) ) . '</td><td>';
			if ( $r['restored_at'] ) {
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'Put back %s', 'presshelm-ai' ), self::when( $r['restored_at'] ) ) );
			} else {
				self::button( 'journal_restore', __( 'Put this back', 'presshelm-ai' ), array( 'journal_id' => (string) $r['id'] ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::pager( 'bp', 'backup', $page, $total );
	}

	/**
	 * A journal entry in plain words.
	 *
	 * @param array $r Row.
	 * @return string
	 */
	private static function journal_text( array $r ) {
		$t      = (string) $r['target'];
		$before = json_decode( (string) $r['before_state'], true );
		$before = is_array( $before ) ? $before : array();
		switch ( $r['kind'] ) {
			case 'post':
				/* translators: 1: post id, 2: post title */
				return sprintf( __( 'Post/page #%1$d "%2$s" (earlier version)', 'presshelm-ai' ), (int) $t, get_the_title( (int) $t ) );
			case 'post_created':
				/* translators: %d: post id */
				return sprintf( __( 'New post/page #%d (putting back moves it to the trash)', 'presshelm-ai' ), (int) $t );
			case 'attachment_created':
				/* translators: %d: attachment id */
				return sprintf( __( 'Uploaded file #%d (putting back deletes it)', 'presshelm-ai' ), (int) $t );
			case 'custom_css':
				/* translators: %s: theme */
				return sprintf( __( 'Additional CSS of %s', 'presshelm-ai' ), $t );
			case 'option':
			case 'options':
				return __( 'Site settings', 'presshelm-ai' ) . ( 'option' === $r['kind'] ? ': ' . $t : '' );
			case 'menu':
			case 'menu_created':
				/* translators: %d: menu id */
				return sprintf( __( 'Menu #%d', 'presshelm-ai' ), (int) $t );
			case 'post_meta':
				/* translators: %d: post id */
				return sprintf( __( 'Featured image / SEO fields of #%d', 'presshelm-ai' ), (int) $t );
			case 'file':
				/* translators: %s: root|path */
				return sprintf( __( 'File %s', 'presshelm-ai' ), str_replace( '|', ' → ', $t ) );
			case 'dir':
				/* translators: %s: root (e.g. theme:astra) */
				return sprintf( __( 'Whole folder %s (full copy)', 'presshelm-ai' ), $t );
			case 'db_rows':
				/* translators: %s: table */
				return sprintf( __( 'Database rows of %s', 'presshelm-ai' ), $t );
			case 'plugin_installed':
				/* translators: %s: plugin */
				return sprintf( __( 'Plugin %s was added (putting back removes it)', 'presshelm-ai' ), $t );
			case 'plugin_activated':
			case 'plugin_deactivated':
				/* translators: %s: plugin */
				return sprintf( __( 'Plugin %s on/off', 'presshelm-ai' ), $t );
			case 'plugin_deleted':
				/* translators: %s: plugin */
				return sprintf( __( 'Plugin %s (full copy before deleting)', 'presshelm-ai' ), $t );
			case 'theme_created':
				/* translators: %s: theme */
				return sprintf( __( 'Theme %s was added (putting back removes it)', 'presshelm-ai' ), $t );
			case 'woo_snapshot':
				/* translators: %d: product id */
				return sprintf( __( 'Product #%d (earlier version)', 'presshelm-ai' ), (int) $t );
			case 'woo_import':
				return __( 'Product import from a CSV file (putting back removes new products and restores changed ones)', 'presshelm-ai' );
			case 'content_import':
				return __( 'Content import (putting back removes what was imported)', 'presshelm-ai' );
			case 'filebox_file':
			case 'filebox_files':
				return __( 'Files in PressHelm\'s file box (putting back removes them)', 'presshelm-ai' );
			case 'snippet':
				/* translators: %s: snippet id */
				return sprintf( __( 'Code snippet #%s (earlier version)', 'presshelm-ai' ), $t );
			case 'snippet_created':
				/* translators: %s: snippet id */
				return sprintf( __( 'New code snippet #%s (putting back removes it)', 'presshelm-ai' ), $t );
		}
		return $r['kind'] . ' ' . $t;
	}

	/**
	 * Saved copies on disk (file/folder snapshots and database backups).
	 *
	 * @return array{count:int, bytes:int}
	 */
	public static function backup_storage() {
		$up   = wp_upload_dir( null, false );
		$base = trailingslashit( (string) $up['basedir'] ) . 'presshelm-snapshots';
		$out  = array(
			'count' => 0,
			'bytes' => 0,
		);
		if ( ! is_dir( $base ) ) {
			return $out;
		}
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/', basename( (string) $dir ) ) ) {
				continue;
			}
			++$out['count'];
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( (string) $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$out['bytes'] += (int) $f->getSize();
			}
		}
		return $out;
	}

	/** Reconnect or disconnect. */
	private static function tab_disconnect() {
		$stop = \PressHelm\Recovery\EmergencyStop::state();
		echo '<h3>' . esc_html__( 'Emergency stop', 'presshelm-ai' ) . '</h3>';
		if ( $stop['active'] ) {
			echo '<p class="presshelm-stopped">' . esc_html__( 'PressHelm is stopped on this site: it does not check for work, ignores wake-ups and runs nothing. PressHelm Cloud cannot undo this.', 'presshelm-ai' ) . '</p>';
			if ( $stop['by_constant'] ) {
				echo '<p class="description">' . esc_html__( 'Set by PRESSHELM_EMERGENCY_STOP in wp-config.php: remove it there to resume.', 'presshelm-ai' ) . '</p>';
			} else {
				self::button( 'emergency_resume', __( 'Resume PressHelm', 'presshelm-ai' ), array(), 'button button-primary' );
			}
		} else {
			echo '<p>' . esc_html__( 'Stops everything PressHelm does on this site at once, whatever the cloud asks. Use it if something looks wrong; you can resume any time.', 'presshelm-ai' ) . '</p>';
			self::button( 'emergency_stop', __( 'Stop PressHelm now', 'presshelm-ai' ), array(), 'button button-secondary presshelm-stop' );
		}
		echo '<h3>' . esc_html__( 'Reconnect', 'presshelm-ai' ) . '</h3>';
		echo '<p>' . esc_html__( 'Use this if PressHelm says the site is not connected or the connection keys were lost. Your AI provider and bot stay as they are.', 'presshelm-ai' ) . '</p>';
		self::button( 'reconnect', __( 'Reconnect to PressHelm Cloud', 'presshelm-ai' ) );
		echo '<h3>' . esc_html__( 'Disconnect', 'presshelm-ai' ) . '</h3>';
		echo '<p>' . esc_html__( 'Stops all PressHelm work on this site: PressHelm Cloud cancels waiting tasks and deletes your AI key and bot token, and this site removes its connection keys. Your recorded changes stay in Backup & Rollback.', 'presshelm-ai' ) . '</p>';
		self::button( 'disconnect', __( 'Disconnect this site', 'presshelm-ai' ), array(), 'button button-link-delete' );
		echo '<p class="description">' . esc_html__( 'Only if PressHelm Cloud cannot be reached: remove the connection from this site alone. Your credentials then stay in the cloud until you remove the site there.', 'presshelm-ai' ) . '</p>';
		self::button( 'disconnect', __( 'Remove only from this site', 'presshelm-ai' ), array( 'local_only' => '1' ), 'button-link' );
	}
}
