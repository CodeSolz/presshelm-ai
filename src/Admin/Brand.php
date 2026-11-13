<?php
/**
 * The name and logo the plugin's screens show. Always "PressHelm" here; the filter lets an add-on
 * replace them (the Power Tools extension does, for agencies with a white label in PressHelm Cloud).
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Brand.
 */
final class Brand {

	/**
	 * Name and logo, after the `presshelm_brand` filter (cleaned: short plain name, https logo).
	 *
	 * @return array{name:string,logo:string}
	 */
	public static function get() {
		$default = array(
			'name' => __( 'PressHelm', 'presshelm-ai' ),
			'logo' => '',
		);
		$b       = apply_filters( 'presshelm_brand', $default );
		if ( ! is_array( $b ) ) {
			return $default;
		}
		$name = trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
		$logo = (string) ( $b['logo'] ?? '' );
		return array(
			'name' => '' !== $name ? mb_substr( $name, 0, 40 ) : $default['name'],
			'logo' => 0 === strpos( $logo, 'https://' ) ? esc_url_raw( $logo ) : '',
		);
	}
}
