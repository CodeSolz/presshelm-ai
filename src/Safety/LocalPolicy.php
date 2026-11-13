<?php
/**
 * The site owner's own limits (D4, M3.13): per area of work, "as usual", "always ask me" or
 * "never". It can only tighten: "never" refuses every request in that area (reads included),
 * "always ask me" refuses changes that were not approved by the owner in Telegram. Enforced here
 * on every signed command, whatever the cloud sends; the cloud also reads it (capability report)
 * to ask for approval up front. Undo is always allowed: it is a safety tool.
 *
 * @package PressHelm
 */

namespace PressHelm\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Local policy.
 */
final class LocalPolicy {

	const OPTION = 'presshelm_local_policy';
	const MODES  = array( 'default', 'ask', 'off' );

	/**
	 * Areas: id → ability id prefixes (first match wins; checked longest first).
	 *
	 * @return array<string, string[]>
	 */
	public static function areas() {
		return array(
			'content'  => array( 'content.', 'blocks.', 'media.', 'menus.', 'builder.', 'forms.', 'seo.' ),
			'design'   => array( 'styles.', 'templates.', 'template.' ),
			'settings' => array( 'settings.' ),
			'plugins'  => array( 'plugins.' ),
			'code'     => array( 'files.', 'custom_plugin.', 'theme.', 'themes.', 'snippets.' ),
			'database' => array( 'db.', 'ops.search_replace' ),
			'upkeep'   => array( 'ops.', 'cache.', 'logs.', 'filebox.' ),
			'store'    => array( 'woo.' ),
			'others'   => array( 'abilities.', 'rest.' ),
		);
	}

	/**
	 * Area labels for wp-admin.
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		$labels = array(
			'content'  => __( 'Posts, pages (incl. page-builder pages), forms, media and menus', 'presshelm-ai' ),
			'design'   => __( 'Design: global styles, block templates', 'presshelm-ai' ),
			'settings' => __( 'Site settings', 'presshelm-ai' ),
			'upkeep'   => __( 'Maintenance: caches, permalinks', 'presshelm-ai' ),
			'store'    => __( 'WooCommerce: products, orders, store settings', 'presshelm-ai' ),
			'others'   => __( 'Abilities of other plugins (Abilities API)', 'presshelm-ai' ),
		);
		/**
		 * Areas an add-on brings (it adds the label of each area its abilities belong to).
		 *
		 * @param array<string,string> $labels Area → label.
		 */
		return (array) apply_filters( 'presshelm_policy_labels', $labels );
	}

	/**
	 * Area of an ability ('' = not covered, e.g. site.inspect, journal.restore).
	 *
	 * @param string $ability Ability id.
	 * @return string
	 */
	public static function area( $ability ) {
		foreach ( self::areas() as $area => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( (string) $ability, $prefix ) ) {
					return $area;
				}
			}
		}
		return '';
	}

	/**
	 * Current modes (area → default|ask|off).
	 *
	 * @return array<string, string>
	 */
	public static function modes() {
		$saved = get_option( self::OPTION );
		$out   = array();
		foreach ( array_keys( self::areas() ) as $area ) {
			$m            = is_array( $saved ) ? (string) ( $saved[ $area ] ?? 'default' ) : 'default';
			$out[ $area ] = in_array( $m, self::MODES, true ) ? $m : 'default';
		}
		return $out;
	}

	/**
	 * Saves modes (unknown areas/modes ignored).
	 *
	 * @param array $modes Area → mode.
	 */
	public static function save( array $modes ) {
		$clean = array();
		foreach ( array_keys( self::areas() ) as $area ) {
			$m              = (string) ( $modes[ $area ] ?? 'default' );
			$clean[ $area ] = in_array( $m, self::MODES, true ) ? $m : 'default';
		}
		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Mode for one ability.
	 *
	 * @param string $ability Ability id.
	 * @return string default|ask|off
	 */
	public static function mode( $ability ) {
		$area = self::area( $ability );
		return '' === $area ? 'default' : self::modes()[ $area ];
	}

	/**
	 * Why a signed command may not run here ('' if it may).
	 *
	 * @param string $ability     Ability id.
	 * @param bool   $mutates     Whether it changes the site.
	 * @param bool   $approved    Whether the command carries an owner approval.
	 * @return string Error code ('' = allowed).
	 */
	public static function refusal( $ability, $mutates, $approved ) {
		$mode = self::mode( $ability );
		if ( 'off' === $mode ) {
			return 'local_policy_off';
		}
		if ( 'ask' === $mode && $mutates && ! $approved ) {
			return 'local_policy_needs_approval';
		}
		return '';
	}

	/**
	 * Per-ability modes that differ from "as usual" (sent to the cloud in the capability report).
	 *
	 * @param string[] $abilities Ability ids.
	 * @return array<string, string>
	 */
	public static function report( array $abilities ) {
		$out = array();
		foreach ( $abilities as $id ) {
			$m = self::mode( (string) $id );
			if ( 'default' !== $m ) {
				$out[ (string) $id ] = $m;
			}
		}
		return $out;
	}
}
