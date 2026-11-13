<?php
/**
 * After a change to a post's content (on top of the site-wide health gate): the saved blocks are
 * still valid, the content renders without a PHP error, and a published page still loads. Any
 * failure makes the executor roll the change back, like a failed health check.
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Post verification.
 */
final class Verify {

	/** Abilities that change a post's content, with where the post id comes from. */
	const POST_ABILITIES = array(
		'content.update'           => 'payload',
		'blocks.patch'             => 'payload',
		'content.create'           => 'result',
		'content.revision_restore' => 'payload',
		'builder.update'           => 'payload',
		'builder.change'           => 'payload',
		'content.text_edit'        => 'payload',
		'media.insert'             => 'payload',
		'content.stage_publish'    => 'result',
	);

	/**
	 * The post a run changed, or 0.
	 *
	 * @param string $ability Ability id.
	 * @param array  $payload Input.
	 * @param array  $out     Result.
	 * @return int
	 */
	public static function post_of( $ability, array $payload, array $out ) {
		$from = self::POST_ABILITIES[ $ability ] ?? null;
		if ( 'payload' === $from ) {
			return (int) ( $payload['id'] ?? 0 );
		}
		if ( 'result' === $from ) {
			return (int) ( $out['id'] ?? 0 );
		}
		return 0;
	}

	/**
	 * Whether content round-trips through the block parser unchanged.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function blocks_valid( $content ) {
		return serialize_blocks( parse_blocks( (string) $content ) ) === (string) $content;
	}

	/**
	 * What a post shows before a change (title and rendered text), to know what to look for after.
	 *
	 * @param int $post_id Post id.
	 * @return array{title:string,text:string}|null
	 */
	public static function snapshot( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return null;
		}
		try {
			$html = (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, rendering the post as WordPress does.
		} catch ( \Throwable $e ) {
			$html = '';
		}
		return array(
			'title' => (string) get_the_title( $post ),
			'text'  => self::plain( $html ),
		);
	}

	/**
	 * Visible text of HTML: no scripts or styles, entities decoded, whitespace collapsed, lower case.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{00A0}" ), array( "'", "'", '"', '"', ' ' ), $text );
		return trim( mb_strtolower( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * Phrases the change added (new sentences, a new title), the longest first; when it only
	 * removed text, phrases that should be gone.
	 *
	 * @param array|null $before Snapshot before (null for a new post).
	 * @param string     $title  Title now.
	 * @param string     $text   Plain text now.
	 * @return array{added:string[],removed:string[]}
	 */
	public static function expected( $before, $title, $text ) {
		$split = static function ( $t ) {
			$parts = preg_split( '/(?<=[.!?])\s+|\s*\|\s*/u', (string) $t );
			return array_values(
				array_unique(
					array_filter(
						array_map( 'trim', (array) $parts ),
						static function ( $p ) {
							return mb_strlen( $p ) >= 15 && mb_strlen( $p ) <= 200;
						}
					)
				)
			);
		};
		$old   = $before ? (string) $before['text'] : '';
		$added = array_values(
			array_filter(
				$split( $text ),
				static function ( $p ) use ( $old ) {
					return '' === $old || false === strpos( $old, $p );
				}
			)
		);
		usort(
			$added,
			static function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		$added = array_slice( $added, 0, 3 );
		$t     = self::plain( $title );
		if ( $before && '' !== $t && self::plain( (string) $before['title'] ) !== $t ) {
			array_unshift( $added, $t );
		}
		$removed = array();
		if ( ! $added && $before ) {
			$removed = array_slice(
				array_values(
					array_filter(
						$split( $old ),
						static function ( $p ) use ( $text ) {
							return false === strpos( $text, $p );
						}
					)
				),
				0,
				2
			);
		}
		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Whether the change shows on the live page (fetched fresh as a visitor). Never undoes: a page
	 * cache or CDN can show the old version for a while, and that is reported, not "fixed".
	 *
	 * @param \WP_Post   $post   Post.
	 * @param array|null $before Snapshot before.
	 * @param string     $html   Rendered content now.
	 * @return array{live:string,css?:string}
	 */
	public static function live( $post, $before, $html ) {
		$want = self::expected( $before, (string) get_the_title( $post ), self::plain( $html ) );
		if ( ! $want['added'] && ! $want['removed'] ) {
			return array( 'live' => 'not checked (no visible text changed)' );
		}
		// Visitors see a coming-soon page: check the page as the logged-in admin instead, and say so.
		$gate = HealthCheck::visitor_gate();
		$page = HealthCheck::fetch( (string) get_permalink( $post ), '' !== $gate );
		$body = (string) ( $page['body'] ?? '' );
		if ( ! empty( $page['blocked'] ) ) {
			return array( 'live' => 'not checked (a browser challenge answered)' );
		}
		$seen = self::plain( $body );
		if ( '' === $seen ) {
			return array( 'live' => 'not checked (the page came back empty)' );
		}
		$missing = array();
		foreach ( $want['added'] as $p ) {
			if ( false === strpos( $seen, $p ) ) {
				$missing[] = $p;
			}
		}
		foreach ( $want['removed'] as $p ) {
			if ( false !== strpos( $seen, $p ) ) {
				$missing[] = $p;
			}
		}
		$n   = count( $want['added'] ) + count( $want['removed'] );
		$out = array(
			'live' => $missing
				? 'not visible yet: ' . ( count( $missing ) === $n ? 'the live page still shows the old version' : ( $n - count( $missing ) ) . ' of ' . $n . ' changes show' ) . ' (a page cache or CDN may still serve it; it usually refreshes on its own, or the cache can be cleared)'
				: 'visible (' . $n . ' of ' . $n . ' checked on the live page' . ( '' !== $gate ? ' as a logged-in admin; visitors currently see a coming-soon page (' . $gate . ')' : '' ) . ')',
		);
		// Elementor pages: their CSS file is linked and loads.
		if ( false !== strpos( $body, 'elementor/css/post-' . (int) $post->ID . '.css' ) && preg_match( '#https?://[^"\']+elementor/css/post-' . (int) $post->ID . '\.css[^"\']*#', $body, $m ) ) {
			$r          = wp_remote_head(
				$m[0],
				array(
					'timeout'   => 5,
					'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, used the same way core does.
				)
			);
			$code       = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$out['css'] = 200 === $code ? 'page CSS loads' : 'page CSS did not load (HTTP ' . $code . ')';
		}
		return $out;
	}

	/**
	 * Checks one post after a change.
	 *
	 * @param int        $post_id   Post id.
	 * @param bool       $was_valid Whether its blocks round-tripped before the change.
	 * @param array|null $before    Snapshot before the change (null: a new post).
	 * @return array{ok:bool,checks:array<string,string>,reason?:string}
	 */
	public static function post( $post_id, $was_valid, $before = null ) {
		$post   = get_post( (int) $post_id );
		$checks = array();
		if ( ! $post ) {
			return array(
				'ok'     => false,
				'checks' => array(),
				'reason' => 'the post is gone',
			);
		}
		$content = (string) $post->post_content;

		// 1. Block validity (only demanded if the page was valid before: classic pages stay as they were).
		if ( $was_valid ) {
			if ( ! self::blocks_valid( $content ) ) {
				return array(
					'ok'     => false,
					'checks' => $checks + array( 'blocks' => 'invalid' ),
					'reason' => 'the saved blocks no longer parse back to the same markup',
				);
			}
			$checks['blocks'] = 'valid';
		} else {
			$checks['blocks'] = 'not checked (the page was not standard block markup before)';
		}

		// 2. It renders without a PHP error (in this request).
		try {
			$html             = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, rendering the post as WordPress does.
			$checks['render'] = strlen( (string) $html ) . ' bytes';
		} catch ( \Throwable $e ) {
			return array(
				'ok'     => false,
				'checks' => $checks + array( 'render' => 'error' ),
				'reason' => 'rendering failed: ' . substr( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $e->getMessage() ) ), 0, 300 ),
			);
		}

		// 3. A published page still loads (same no-fatal marker as the health check).
		if ( 'publish' === $post->post_status ) {
			$page = HealthCheck::url( (string) get_permalink( $post ) );
			if ( ! $page['ok'] && ! empty( $page['blocked'] ) ) {
				$checks['page'] = 'not checked (a browser challenge in front of the site answered)';
				return array(
					'ok'     => true,
					'checks' => $checks,
				);
			}
			if ( ! $page['ok'] ) {
				return array(
					'ok'     => false,
					'checks' => $checks + array( 'page' => 'failed' ),
					'reason' => 'the page itself did not load: ' . ( $page['reason'] ?? 'unknown' ),
				);
			}
			$checks['page'] = 'loads (HTTP ' . (int) ( $page['http'] ?? 0 ) . ')';
			$checks        += self::live( $post, $before, (string) $html );
		} else {
			$checks['page'] = 'not checked (' . $post->post_status . ')';
		}
		return array(
			'ok'     => true,
			'checks' => $checks,
		);
	}
}
