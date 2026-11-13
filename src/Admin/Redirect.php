<?php
/**
 * Redirects in wp-admin that never end on a blank page.
 *
 * A redirect needs the response headers to still be open. If something printed output first
 * (another plugin, a PHP notice), WordPress can't redirect and the browser would be left on an empty
 * page. Then a small page with a link (and an automatic refresh) is shown instead. While PressHelm
 * finishes a sign-in, a fatal PHP error is also shown as a short message instead of a blank page.
 *
 * @package PressHelm
 */

namespace PressHelm\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Redirect helper.
 */
final class Redirect {

	/**
	 * Goes to an admin URL and stops.
	 *
	 * @param string $url Address inside this site's admin.
	 */
	public static function to( $url ) {
		$file = '';
		$line = 0;
		// The command line has no headers: there, redirect as usual (tests watch wp_redirect).
		if ( ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! headers_sent( $file, $line ) ) && wp_safe_redirect( $url ) ) {
			exit;
		}
		$url = wp_validate_redirect( $url, admin_url() );
		echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . esc_url( $url ) . '"><title>' . esc_html__( 'PressHelm', 'presshelm-ai' ) . '</title>';
		echo '<div style="font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 16px">';
		echo '<p>' . esc_html__( 'Almost done. If this page does not move on by itself, continue here:', 'presshelm-ai' ) . '</p>';
		echo '<p><a href="' . esc_url( $url ) . '" style="font-weight:600">' . esc_html__( 'Continue to PressHelm', 'presshelm-ai' ) . '</a></p>';
		if ( '' !== $file ) {
			/* translators: 1: file, 2: line */
			echo '<p style="color:#666;font-size:13px">' . esc_html( sprintf( __( 'Another part of this site printed output first (%1$s, line %2$d), so the page could not move on automatically.', 'presshelm-ai' ), wp_basename( $file ), $line ) ) . '</p>';
		}
		echo '</div>';
		exit;
	}

	/**
	 * While a sign-in is finished: a fatal PHP error shows a message and a way back, not a blank page.
	 *
	 * @param string $back Where to go back to.
	 */
	public static function guard_fatal( $back ) {
		register_shutdown_function(
			static function () use ( $back ) {
				$e = error_get_last();
				if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				echo '<div style="font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:0 16px">';
				echo '<h1 style="font-size:22px">' . esc_html__( 'PressHelm could not finish this step', 'presshelm-ai' ) . '</h1>';
				/* translators: 1: error message, 2: file, 3: line */
				echo '<p>' . esc_html( sprintf( __( 'PHP stopped with an error: %1$s (%2$s, line %3$d).', 'presshelm-ai' ), strtok( (string) $e['message'], "\n" ), wp_basename( (string) $e['file'] ), (int) $e['line'] ) ) . '</p>';
				echo '<p><a href="' . esc_url( $back ) . '" style="font-weight:600">' . esc_html__( 'Back to PressHelm', 'presshelm-ai' ) . '</a></p></div>';
			}
		);
	}
}
