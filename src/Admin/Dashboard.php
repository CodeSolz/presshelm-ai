<?php
/**
 * Overview → Dashboard: everything PressHelm does on this site at a glance, once it is set up.
 * Connections, the numbers that matter (tasks, changes, AI cost), the last two weeks, recent
 * activity, the safety net, and ideas for what to ask.
 *
 * Reads only: the plugin's own tables, the connection record, and three cached answers from
 * PressHelm Cloud (Telegram status, AI usage, connected apps). Each part still renders when the
 * cloud can't be reached.
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

use PressHelm\Abilities\Registry;
use PressHelm\Bridge\Poller;
use PressHelm\Connect\Apps;
use PressHelm\Connect\Connection;
use PressHelm\Connect\TelegramLink;
use PressHelm\Onboarding\Providers;
use PressHelm\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Overview dashboard.
 */
final class Dashboard {

	/** Days in the activity chart. */
	const DAYS = 14;

	/**
	 * Telegram status from the cloud, once per page.
	 *
	 * @return array|null
	 */
	public static function telegram() {
		static $cache = false;
		if ( false === $cache ) {
			$c     = Connection::get();
			$live  = ! empty( $c['telegram'] ) ? TelegramLink::status() : null;
			$cache = is_array( $live ) ? $live : null;
		}
		return $cache;
	}

	/**
	 * Whether setup is complete (owner linked in Telegram, or unknown while the cloud is away).
	 *
	 * @return bool
	 */
	public static function ready() {
		$c = Connection::get();
		if ( empty( $c['site_id'] ) || empty( $c['ai'] ) || empty( $c['telegram'] ) || 'done' !== ( $c['handoff'] ?? '' ) ) {
			return false;
		}
		$live = self::telegram();
		return null === $live || ! empty( $live['owner'] );
	}

	/**
	 * The dashboard.
	 */
	public static function render() {
		$stats = self::numbers();
		self::connections();
		self::kpis( $stats );
		echo '<div class="ph-dash-grid">';
		self::chart( $stats['days'] );
		self::top( $stats['top'] );
		echo '</div><div class="ph-dash-grid">';
		self::recent();
		self::safety();
		echo '</div>';
		self::ideas();
	}

	/**
	 * Tasks per day (site time) and this month's totals from the task ledger.
	 *
	 * @return array
	 */
	private static function numbers() {
		global $wpdb;
		$table = Schema::table( 'ledger' );
		$tz    = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$today = new \DateTimeImmutable( 'today', $tz );
		$first = $today->modify( '-' . ( self::DAYS - 1 ) . ' days' );
		$month = new \DateTimeImmutable( 'first day of this month 00:00', $tz );
		$since = $first < $month ? $first : $month;
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ability, status, created_at FROM %i WHERE created_at >= %s', $table, $since->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live numbers.
		$risk  = array_map(
			static function ( $a ) {
				return (string) ( $a['risk'] ?? '' );
			},
			Registry::all()
		);
		$days  = array();
		for ( $d = $first; $d <= $today; $d = $d->modify( '+1 day' ) ) {
			$days[ $d->format( 'Y-m-d' ) ] = array(
				'all'    => 0,
				'failed' => 0,
			);
		}
		$out = array(
			'today'     => 0,
			'yesterday' => 0,
			'month'     => 0,
			'changes'   => 0,
			'failed'    => 0,
			'top'       => array(),
		);
		$y   = $today->modify( '-1 day' )->format( 'Y-m-d' );
		foreach ( $rows as $r ) {
			$local = ( new \DateTimeImmutable( (string) $r['created_at'], $utc ) )->setTimezone( $tz );
			$day   = $local->format( 'Y-m-d' );
			$bad   = in_array( $r['status'], array( 'failed', 'rolled_back', 'rejected' ), true );
			if ( isset( $days[ $day ] ) ) {
				++$days[ $day ]['all'];
				$days[ $day ]['failed'] += $bad ? 1 : 0;
			}
			$out['today']     += $day === $today->format( 'Y-m-d' ) ? 1 : 0;
			$out['yesterday'] += $day === $y ? 1 : 0;
			if ( $local >= $month ) {
				++$out['month'];
				$out['failed'] += $bad ? 1 : 0;
				if ( 'succeeded' === $r['status'] && 'READ' !== ( $risk[ $r['ability'] ] ?? 'READ' ) ) {
					++$out['changes'];
				}
				$out['top'][ $r['ability'] ] = ( $out['top'][ $r['ability'] ] ?? 0 ) + 1;
			}
		}
		arsort( $out['top'] );
		$out['top']  = array_slice( $out['top'], 0, 5, true );
		$out['days'] = $days;
		return $out;
	}

	/**
	 * A small card.
	 *
	 * @param string $icon  Line icon.
	 * @param string $label Card label.
	 * @param string $value Main line.
	 * @param string $meta  Second line.
	 * @param string $link  Where "Manage" goes.
	 * @param string $state ok|warn|idle.
	 * @param string $logo  Provider mark instead of the icon ('' = icon).
	 */
	private static function card( $icon, $label, $value, $meta, $link, $state, $logo = '' ) {
		echo '<a class="ph-conn is-' . esc_attr( $state ) . '" href="' . esc_url( $link ) . '"><span class="ph-conn-icon' . ( $logo ? ' ph-provider-logo ph-logo-' . esc_attr( $logo ) : '' ) . '">';
		Icons::out( $logo ? Icons::provider( $logo ) : Icons::line( $icon, 22 ) );
		echo '</span><span class="ph-conn-body"><span class="ph-conn-label">' . esc_html( $label ) . '<span class="ph-dot" aria-hidden="true"></span></span>';
		echo '<span class="ph-conn-value">' . esc_html( $value ) . '</span><span class="ph-conn-meta">' . esc_html( $meta ) . '</span></span></a>';
	}

	/** AI, Telegram, cloud, apps. */
	private static function connections() {
		$c    = Connection::get();
		$ai   = (array) ( $c['ai'] ?? array() );
		$tg   = (array) ( $c['telegram'] ?? array() );
		$info = isset( $ai['provider'] ) ? Providers::get( (string) $ai['provider'] ) : null;
		$live = self::telegram();
		echo '<div class="ph-conns">';
		if ( $ai ) {
			$meta = array( 'oauth' === ( $ai['method'] ?? '' ) ? __( 'Signed in', 'presshelm-ai' ) : __( 'API key', 'presshelm-ai' ) . ' ' . (string) ( $ai['hint'] ?? '' ) );
			if ( ! empty( $ai['fallback_model'] ) ) {
				/* translators: %s: model id */
				array_unshift( $meta, sprintf( __( 'fallback %s', 'presshelm-ai' ), (string) $ai['fallback_model'] ) );
			}
			self::card( 'sparkles', $info ? (string) ( $info['short'] ?? $info['label'] ) : (string) $ai['provider'], ! empty( $ai['model'] ) ? (string) $ai['model'] : '—', implode( ' · ', $meta ), Tabs::url( 'ai' ), 'ok', (string) $ai['provider'] );
		} else {
			self::card( 'sparkles', __( 'AI', 'presshelm-ai' ), __( 'Not connected', 'presshelm-ai' ), __( 'Choose a provider and model', 'presshelm-ai' ), Tabs::url( 'ai' ), 'warn' );
		}
		$owner = $live && ! empty( $live['owner'] ) ? (array) $live['owner'] : array();
		if ( $tg ) {
			/* translators: %s: Telegram username or id */
			$meta = $owner ? sprintf( __( 'Owner %s linked', 'presshelm-ai' ), ! empty( $owner['username'] ) ? '@' . $owner['username'] : (string) ( $owner['id'] ?? '' ) ) : ( $live ? __( 'Owner not linked yet', 'presshelm-ai' ) : __( 'Status unavailable right now', 'presshelm-ai' ) );
			self::card( 'send', __( 'Telegram', 'presshelm-ai' ), '@' . (string) ( $tg['username'] ?? '' ), $meta, Tabs::url( 'telegram' ), $owner || ! $live ? 'ok' : 'warn' );
		} else {
			self::card( 'send', __( 'Telegram', 'presshelm-ai' ), __( 'No bot yet', 'presshelm-ai' ), __( 'Add your own bot', 'presshelm-ai' ), Tabs::url( 'telegram' ), 'warn' );
		}
		$contact = (int) get_transient( Poller::HEARTBEAT );
		/* translators: %s: time since, e.g. 2 mins */
		self::card( 'cloud', __( 'PressHelm Cloud', 'presshelm-ai' ), __( 'Connected', 'presshelm-ai' ), $contact ? sprintf( __( 'Last check-in %s ago', 'presshelm-ai' ), human_time_diff( $contact ) ) : __( 'Checks in every minute', 'presshelm-ai' ), Tabs::url( 'connection' ), 'ok' );
		$apps = get_transient( 'presshelm_dash_apps' );
		if ( ! is_array( $apps ) ) {
			$list = Apps::all();
			$apps = is_wp_error( $list ) ? array() : array_values( array_unique( array_map( 'strval', array_column( $list, 'app' ) ) ) );
			set_transient( 'presshelm_dash_apps', $apps, 5 * MINUTE_IN_SECONDS );
		}
		/* translators: %d: number of apps */
		self::card( 'link', __( 'Claude & ChatGPT', 'presshelm-ai' ), $apps ? sprintf( _n( '%d app connected', '%d apps connected', count( $apps ), 'presshelm-ai' ), count( $apps ) ) : __( 'Not connected', 'presshelm-ai' ), $apps ? implode( ', ', $apps ) : __( 'Chat from your own app too', 'presshelm-ai' ), Tabs::url( 'apps' ), $apps ? 'ok' : 'idle' );
		echo '</div>';
	}

	/**
	 * The four numbers.
	 *
	 * @param array $s Numbers.
	 */
	private static function kpis( array $s ) {
		$usage = Tabs::usage_data();
		$diff  = $s['today'] - $s['yesterday'];
		echo '<div class="ph-kpis">';
		echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html__( 'Tasks today', 'presshelm-ai' ) . '</span><span class="ph-stat-value">' . esc_html( number_format_i18n( $s['today'] ) ) . '</span><span class="ph-stat-meta">';
		if ( 0 === $s['yesterday'] && 0 === $s['today'] ) {
			esc_html_e( 'Quiet so far', 'presshelm-ai' );
		} else {
			/* translators: 1: + or − difference, 2: tasks yesterday */
			echo '<span class="ph-trend ' . ( $diff >= 0 ? 'is-up' : 'is-down' ) . '">' . esc_html( ( $diff >= 0 ? '+' : '−' ) . number_format_i18n( abs( $diff ) ) ) . '</span> ' . esc_html( sprintf( __( 'vs %s yesterday', 'presshelm-ai' ), number_format_i18n( $s['yesterday'] ) ) );
		}
		echo '</span></div>';
		echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html__( 'Tasks this month', 'presshelm-ai' ) . '</span><span class="ph-stat-value">' . esc_html( number_format_i18n( $s['month'] ) ) . '</span><span class="ph-stat-meta">';
		/* translators: %s: number of tasks that failed or were undone */
		echo esc_html( $s['failed'] ? sprintf( _n( '%s needed a second look', '%s needed a second look', $s['failed'], 'presshelm-ai' ), number_format_i18n( $s['failed'] ) ) : __( 'None failed', 'presshelm-ai' ) ) . '</span></div>';
		echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html__( 'Changes this month', 'presshelm-ai' ) . '</span><span class="ph-stat-value">' . esc_html( number_format_i18n( $s['changes'] ) ) . '</span><span class="ph-stat-meta">' . esc_html__( 'Each one saved first, so it can be put back', 'presshelm-ai' ) . '</span></div>';
		echo '<div class="ph-stat"><span class="ph-stat-label">' . esc_html__( 'AI cost this month', 'presshelm-ai' ) . '</span>';
		if ( $usage ) {
			$m     = (array) $usage['this_month'];
			$limit = $usage['guard']['monthly_usd'] ?? null;
			echo '<span class="ph-stat-value">' . esc_html( (string) $m['estimated_cost'] ) . '</span><span class="ph-stat-meta">';
			if ( null !== $limit && (float) $limit > 0 ) {
				$pct = min( 100, (int) round( (float) $m['estimated_cost_usd'] / (float) $limit * 100 ) );
				/* translators: %s: monthly limit in dollars */
				echo esc_html( sprintf( __( 'of $%s limit', 'presshelm-ai' ), number_format_i18n( (float) $limit, 2 ) ) ) . '</span>';
				printf( '<span class="ph-bar%3$s" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%2$d"><span style="width:%2$d%%"></span></span>', esc_attr__( 'Monthly limit used', 'presshelm-ai' ), (int) $pct, $pct >= 80 ? ' is-high' : '' );
			} else {
				/* translators: %s: number of tokens */
				echo esc_html( sprintf( __( '%s tokens · no monthly limit', 'presshelm-ai' ), number_format_i18n( (int) $m['tokens'] ) ) ) . '</span>';
			}
		} else {
			echo '<span class="ph-stat-value">—</span><span class="ph-stat-meta">' . esc_html__( 'Could not load right now', 'presshelm-ai' ) . '</span>';
		}
		echo '</div></div>';
	}

	/**
	 * Tasks per day, last two weeks (bars; hover or focus shows the numbers; a table for screen readers).
	 *
	 * @param array $days Day → counts.
	 */
	private static function chart( array $days ) {
		$max   = max( 1, max( array_column( $days, 'all' ) ) );
		$total = array_sum( array_column( $days, 'all' ) );
		echo '<section class="ph-box ph-chart-box" aria-labelledby="ph-chart-title"><div class="ph-box-head"><h3 id="ph-chart-title">' . esc_html__( 'Last 14 days', 'presshelm-ai' ) . '</h3>';
		/* translators: %s: number of tasks */
		echo '<span class="ph-box-meta">' . esc_html( sprintf( _n( '%s task', '%s tasks', $total, 'presshelm-ai' ), number_format_i18n( $total ) ) ) . '</span></div>';
		echo '<div class="ph-chart" aria-hidden="true"><div class="ph-chart-grid"><span>' . esc_html( number_format_i18n( $max ) ) . '</span><span>0</span></div><div class="ph-bars">';
		$i = 0;
		foreach ( $days as $day => $n ) {
			$d     = new \DateTimeImmutable( $day, wp_timezone() );
			$label = wp_date( 'M j', $d->getTimestamp() );
			$h     = $n['all'] ? max( 4, (int) round( $n['all'] / $max * 100 ) ) : 0;
			/* translators: 1: date, 2: tasks, 3: failed or undone */
			$tip = sprintf( __( '%1$s: %2$d tasks, %3$d failed or undone', 'presshelm-ai' ), $label, $n['all'], $n['failed'] );
			echo '<span class="ph-bar-col" tabindex="0" data-tip="' . esc_attr( $tip ) . '"><span class="ph-bar-v" style="height:' . (int) $h . '%"></span>';
			echo '<span class="ph-bar-x">' . esc_html( 0 === $i % 2 || count( $days ) - 1 === $i ? wp_date( 'j', $d->getTimestamp() ) : '' ) . '</span></span>';
			++$i;
		}
		echo '</div></div><table class="screen-reader-text"><caption>' . esc_html__( 'Tasks per day, last 14 days', 'presshelm-ai' ) . '</caption><tbody>';
		foreach ( $days as $day => $n ) {
			echo '<tr><th scope="row">' . esc_html( wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp() ) ) . '</th><td>' . (int) $n['all'] . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * What PressHelm did most this month.
	 *
	 * @param array $top Ability → count.
	 */
	private static function top( array $top ) {
		$labels = Tabs::ability_labels();
		echo '<section class="ph-box" aria-labelledby="ph-top-title"><div class="ph-box-head"><h3 id="ph-top-title">' . esc_html__( 'Most done this month', 'presshelm-ai' ) . '</h3></div>';
		if ( ! $top ) {
			echo '<p class="ph-help">' . esc_html__( 'Nothing yet this month.', 'presshelm-ai' ) . '</p></section>';
			return;
		}
		$max = max( $top );
		echo '<ul class="ph-most">';
		foreach ( $top as $ability => $n ) {
			printf(
				'<li><span class="ph-most-label">%1$s</span><span class="ph-most-n">%2$s</span><span class="ph-most-bar" aria-hidden="true"><span style="width:%3$d%%"></span></span></li>',
				esc_html( $labels[ $ability ] ?? $ability ),
				esc_html( number_format_i18n( $n ) ),
				(int) max( 4, round( $n / $max * 100 ) )
			);
		}
		echo '</ul></section>';
	}

	/** The last tasks. */
	private static function recent() {
		global $wpdb;
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ability, status, created_at FROM %i ORDER BY created_at DESC LIMIT 6', Schema::table( 'ledger' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live list.
		$labels = Tabs::ability_labels();
		echo '<section class="ph-box" aria-labelledby="ph-recent-title"><div class="ph-box-head"><h3 id="ph-recent-title">' . esc_html__( 'Recent activity', 'presshelm-ai' ) . '</h3><a class="ph-link ph-small-link" href="' . esc_url( Tabs::url( 'history' ) ) . '">' . esc_html__( 'See all', 'presshelm-ai' ) . ' &rarr;</a></div>';
		if ( ! $rows ) {
			echo '<p class="ph-help">' . esc_html__( 'No tasks yet. Message your bot to start.', 'presshelm-ai' ) . '</p></section>';
			return;
		}
		$state = array(
			'succeeded'   => array( 'ok', __( 'done', 'presshelm-ai' ) ),
			'rolled_back' => array( 'warn', __( 'undone', 'presshelm-ai' ) ),
			'failed'      => array( 'bad', __( 'failed', 'presshelm-ai' ) ),
			'rejected'    => array( 'muted', __( 'refused', 'presshelm-ai' ) ),
		);
		echo '<ul class="ph-feed">';
		foreach ( $rows as $r ) {
			$s    = $state[ $r['status'] ] ?? array( 'muted', (string) $r['status'] );
			$time = (int) strtotime( $r['created_at'] . ' UTC' );
			/* translators: %s: time since */
			printf( '<li><span class="ph-feed-dot is-%1$s" aria-hidden="true"></span><span class="ph-feed-text">%2$s <span class="ph-feed-state">%3$s</span></span><time datetime="%4$s">%5$s</time></li>', esc_attr( $s[0] ), esc_html( $labels[ $r['ability'] ] ?? $r['ability'] ), esc_html( $s[1] ), esc_attr( gmdate( 'c', $time ) ), esc_html( sprintf( __( '%s ago', 'presshelm-ai' ), human_time_diff( $time ) ) ) );
		}
		echo '</ul></section>';
	}

	/** Undo, saved copies, limits, emergency stop. */
	private static function safety() {
		global $wpdb;
		$undo   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE restored_at IS NULL', Schema::table( 'journal' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, live number.
		$store  = Tabs::backup_storage();
		$limits = array_filter(
			\PressHelm\Safety\LocalPolicy::modes(),
			static function ( $m ) {
				return 'default' !== $m;
			}
		);
		$stop   = \PressHelm\Recovery\EmergencyStop::active();
		echo '<section class="ph-box" aria-labelledby="ph-safety-title"><div class="ph-box-head"><h3 id="ph-safety-title">' . esc_html__( 'Safety net', 'presshelm-ai' ) . '</h3>';
		echo $stop ? '<span class="ph-pill ph-pill-warn">' . esc_html__( 'Emergency stop on', 'presshelm-ai' ) . '</span>' : '<span class="ph-pill ph-pill-ok">' . esc_html__( 'Protected', 'presshelm-ai' ) . '</span>';
		echo '</div><ul class="ph-safety">';
		/* translators: %s: number of changes */
		echo '<li>' . wp_kses( Icons::line( 'restore', 18 ), Icons::kses() ) . '<span><strong>' . esc_html( sprintf( _n( '%s change you can put back', '%s changes you can put back', $undo, 'presshelm-ai' ), number_format_i18n( $undo ) ) ) . '</strong> <a class="ph-link ph-small-link" href="' . esc_url( Tabs::url( 'backup' ) ) . '">' . esc_html__( 'Backup & rollback', 'presshelm-ai' ) . '</a></span></li>';
		/* translators: 1: number of copies, 2: size */
		echo '<li>' . wp_kses( Icons::line( 'lock', 18 ), Icons::kses() ) . '<span>' . esc_html( sprintf( __( '%1$d saved copies (%2$s), kept 30 days, in a folder the web cannot open', 'presshelm-ai' ), $store['count'], size_format( $store['bytes'] ) ) ) . '</span></li>';
		echo '<li>' . wp_kses( Icons::line( 'shield-check', 18 ), Icons::kses() ) . '<span>' . esc_html__( 'Every change is checked on the live site afterwards and undone automatically if something breaks', 'presshelm-ai' ) . '</span></li>';
		echo '<li>' . wp_kses( Icons::line( 'sliders', 18 ), Icons::kses() ) . '<span>' . esc_html( $limits ? __( 'Your limits:', 'presshelm-ai' ) . ' ' . implode( ', ', array_map( array( Tabs::class, 'limit_text' ), array_keys( $limits ), $limits ) ) : __( 'Standard rules: risky changes ask you first', 'presshelm-ai' ) ) . ' </span></li>';
		echo '</ul><div class="ph-safety-actions">';
		if ( $stop ) {
			Tabs::button( 'emergency_resume', __( 'Resume PressHelm', 'presshelm-ai' ), array(), 'button button-primary' );
		} else {
			Tabs::button( 'emergency_stop', __( 'Emergency stop', 'presshelm-ai' ), array(), 'button presshelm-stop' );
		}
		echo '<a class="ph-link ph-small-link" href="' . esc_url( Tabs::url( 'permissions' ) ) . '">' . esc_html__( 'Permissions', 'presshelm-ai' ) . ' &rarr;</a></div></section>';
	}

	/** Ideas for what to ask, to copy into Telegram (or Claude / ChatGPT). */
	private static function ideas() {
		$ideas = array(
			__( 'Check my site and tell me its status', 'presshelm-ai' ),
			__( 'Run a speed test on my home page and fix the biggest problem', 'presshelm-ai' ),
			__( 'Show me today\'s orders', 'presshelm-ai' ),
			__( 'Find spelling mistakes on my About page', 'presshelm-ai' ),
			__( 'Add a contact form to my Contact page', 'presshelm-ai' ),
			__( 'What did you change on my site this week?', 'presshelm-ai' ),
		);
		$c     = Connection::get();
		echo '<section class="ph-box ph-ideas" aria-labelledby="ph-ideas-title"><div class="ph-box-head"><h3 id="ph-ideas-title">' . esc_html__( 'Try asking', 'presshelm-ai' ) . '</h3><span class="ph-box-meta">' . esc_html__( 'Tap to copy, then paste it to your bot', 'presshelm-ai' ) . '</span></div><div class="ph-idea-list">';
		foreach ( $ideas as $idea ) {
			echo '<button type="button" class="ph-idea" data-copy-text="' . esc_attr( $idea ) . '" data-done="' . esc_attr__( 'Copied', 'presshelm-ai' ) . '">' . wp_kses( Icons::line( 'message', 16 ), Icons::kses() ) . '<span>' . esc_html( $idea ) . '</span></button>';
		}
		echo '</div>';
		if ( ! empty( $c['telegram']['username'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank" class="ph-ideas-cta"><input type="hidden" name="action" value="presshelm_tg_open">';
			wp_nonce_field( 'presshelm_tg_open' );
			echo '<button type="submit" class="ph-btn ph-btn-primary">' . wp_kses( Icons::line( 'send', 18 ), Icons::kses() ) . '<span>' . esc_html__( 'Open Telegram', 'presshelm-ai' ) . '</span></button></form>';
		}
		echo '</section>';
	}
}
