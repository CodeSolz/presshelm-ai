<?php
/**
 * A page of this site as a visitor receives it (H2): fetched fresh through the site's own loopback
 * (past page caches where possible, origin fallback behind a CDN challenge), then summarized:
 * status, time, title, meta, headings, visible text, links, images, forms, CSS/JS files and
 * whether they load, PHP errors printed on the page. Read only; only this site's own addresses.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\HealthCheck;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP's own DOM API property names (textContent, nodeName, …).

/**
 * Page viewer.
 */
final class PageView {

	const MAX_CHARS   = 10000;
	const MAX_LIST    = 40;
	const ASSET_CHECK = 8;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'page.view' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'view' ),
				'label'       => 'View a page as visitors get it',
				'description' => 'A page of this site as a logged-out visitor receives it: status, time, title, meta description, canonical, robots, headings, visible text (start), links, images with alt text, forms (field names only), CSS/JS files and whether they load, PHP errors printed on the page. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'       => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
						'max_chars' => array(
							'type'    => 'integer',
							'minimum' => 200,
							'maximum' => self::MAX_CHARS,
						),
						'as_admin'  => array(
							'type' => 'boolean',
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
		);
	}

	/**
	 * The full address on this site for a path or URL, or '' when it is another site.
	 *
	 * @param string $url Path or URL.
	 * @return string
	 */
	public static function own_url( $url ) {
		$url  = trim( (string) $url );
		$home = home_url( '/' );
		if ( '' === $url ) {
			return $home;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return home_url( '/' . ltrim( $url, '/' ) );
		}
		$host = static function ( $u ) {
			return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		};
		return $host( $url ) === $host( $home ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Runs the ability.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function view( array $input ) {
		$url = self::own_url( (string) ( $input['url'] ?? '' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'not_this_site', 'Only pages of this site can be viewed.' );
		}
		$started = microtime( true );
		$gate    = HealthCheck::visitor_gate();
		$admin   = ! empty( $input['as_admin'] );
		$page    = HealthCheck::fetch( $url, $admin );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( ! empty( $page['blocked'] ) ) {
			return array(
				'url'     => $url,
				'blocked' => true,
				'message' => 'A browser challenge (CDN or security service) answered instead of the page, also when asked directly.',
			);
		}
		$body = (string) ( $page['body'] ?? '' );
		$out  = array(
			'url'    => $url,
			'status' => (int) ( $page['http'] ?? 0 ),
			'ms'     => $ms,
			'bytes'  => strlen( $body ),
		);
		if ( $admin ) {
			$out['viewed_as'] = 'the logged-in admin (admin bar and private content may show)';
		} elseif ( '' !== $gate ) {
			$out['visitors_see'] = 'a coming-soon / maintenance page (' . $gate . '); the page below is what a logged-out visitor gets. Use as_admin: true to see the real page.';
		}
		if ( '' === trim( $body ) ) {
			return $out + array( 'note' => 'The page came back empty' . ( empty( $page['ok'] ) ? ' (' . ( $page['reason'] ?? 'no answer' ) . ')' : '' ) . '.' );
		}
		return $out + self::summarize( $body, $url, (int) ( $input['max_chars'] ?? 3000 ) );
	}

	/**
	 * What a page shows, from its HTML.
	 *
	 * @param string $html      HTML.
	 * @param string $url       Its address.
	 * @param int    $max_chars Visible text to keep.
	 * @return array
	 */
	public static function summarize( $html, $url, $max_chars = 3000 ) {
		$errors = array();
		if ( preg_match_all( '#<b>\s*(Fatal error|Parse error|Warning|Notice|Deprecated)\s*</b>\s*:\s*([^<\n]{0,240})#i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $m, 0, 5 ) as $x ) {
				$errors[] = $x[1] . ': ' . trim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( wp_strip_all_tags( $x[2] ) ) ) );
			}
		}
		// The loopback's own error-scrape markers are not part of the page.
		$html = (string) preg_replace( '/###### wp_scraping_result_start:[a-f0-9]+ ######.*?###### wp_scraping_result_end:[a-f0-9]+ ######/s', '', $html );

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp   = new \DOMXPath( $doc );
		$text = static function ( $node, $max = 200 ) {
			return trim( mb_substr( preg_replace( '/\s+/u', ' ', (string) $node->textContent ), 0, $max ) );
		};
		$attr = static function ( $xpath ) use ( $xp ) {
			$n = $xp->query( $xpath )->item( 0 );
			return $n ? trim( (string) $n->nodeValue ) : '';
		};
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$rel  = static function ( $u ) use ( $host ) {
			$h = strtolower( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) );
			return ( '' === $h || $h === $host ) ? (string) ( wp_parse_url( (string) $u, PHP_URL_PATH ) ?? '' ) . ( wp_parse_url( (string) $u, PHP_URL_QUERY ) ? '?' . wp_parse_url( (string) $u, PHP_URL_QUERY ) : '' ) : (string) $u;
		};

		$headings = array();
		foreach ( $xp->query( '//h1|//h2|//h3' ) as $h ) {
			if ( count( $headings ) >= self::MAX_LIST ) {
				break;
			}
			$t = $text( $h, 140 );
			if ( '' !== $t ) {
				$headings[] = array(
					'level' => (int) substr( $h->nodeName, 1 ),
					'text'  => $t,
				);
			}
		}

		$links    = array();
		$internal = 0;
		$external = 0;
		foreach ( $xp->query( '//body//a[@href]' ) as $a ) {
			$href = (string) $a->getAttribute( 'href' );
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript:' ) ) {
				continue;
			}
			$h = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $h || $h === $host ) {
				++$internal;
			} else {
				++$external;
			}
			if ( count( $links ) < self::MAX_LIST ) {
				$links[] = array(
					'text' => $text( $a, 80 ),
					'href' => $rel( $href ),
				);
			}
		}

		$images  = array();
		$no_alt  = 0;
		$img_all = 0;
		foreach ( $xp->query( '//body//img' ) as $img ) {
			++$img_all;
			$alt = $img->hasAttribute( 'alt' ) ? trim( (string) $img->getAttribute( 'alt' ) ) : null;
			if ( null === $alt || '' === $alt ) {
				++$no_alt;
			}
			if ( count( $images ) < 30 ) {
				$images[] = array(
					'src' => $rel( $img->getAttribute( 'src' ) ? $img->getAttribute( 'src' ) : $img->getAttribute( 'data-src' ) ),
					'alt' => $alt,
				);
			}
		}

		$forms = array();
		foreach ( $xp->query( '//body//form' ) as $f ) {
			if ( count( $forms ) >= 10 ) {
				break;
			}
			$fields = array();
			foreach ( $xp->query( './/input|.//select|.//textarea', $f ) as $in ) {
				$type = 'input' === $in->nodeName ? strtolower( (string) $in->getAttribute( 'type' ) ) : $in->nodeName;
				if ( in_array( $type, array( 'hidden', 'submit' ), true ) ) {
					continue;
				}
				$fields[] = trim( $type . ' ' . (string) $in->getAttribute( 'name' ) );
			}
			$forms[] = array(
				'action' => $rel( $f->getAttribute( 'action' ) ),
				'method' => strtolower( (string) ( $f->getAttribute( 'method' ) ? $f->getAttribute( 'method' ) : 'get' ) ),
				'fields' => array_slice( $fields, 0, 20 ),
			);
		}

		$css = array();
		foreach ( $xp->query( '//link[@rel="stylesheet"][@href]' ) as $l ) {
			$css[] = (string) $l->getAttribute( 'href' );
		}
		$js = array();
		foreach ( $xp->query( '//script[@src]' ) as $s ) {
			$js[] = (string) $s->getAttribute( 'src' );
		}
		$assets = self::asset_status( array_merge( array_slice( $css, 0, 15 ), array_slice( $js, 0, 15 ) ), $host );

		// Visible text: body without scripts, styles and hidden helpers.
		foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//template|//svg' ) ) as $n ) {
			$n->parentNode->removeChild( $n );
		}
		$body    = $xp->query( '//body' )->item( 0 );
		$visible = $body ? trim( preg_replace( '/\s+/u', ' ', (string) $body->textContent ) ) : '';
		$max     = max( 200, min( self::MAX_CHARS, (int) $max_chars ) );

		return array_filter(
			array(
				'title'       => $attr( '//title' ),
				'description' => $attr( '//meta[@name="description"]/@content' ),
				'canonical'   => $attr( '//link[@rel="canonical"]/@href' ),
				'robots'      => $attr( '//meta[@name="robots"]/@content' ),
				'language'    => $attr( '//html/@lang' ),
				'headings'    => $headings,
				'text'        => mb_substr( $visible, 0, $max ) . ( mb_strlen( $visible ) > $max ? ' …' : '' ),
				'text_chars'  => mb_strlen( $visible ),
				'links'       => array(
					'internal' => $internal,
					'external' => $external,
					'first'    => $links,
				),
				'images'      => array(
					'count'       => $img_all,
					'missing_alt' => $no_alt,
					'first'       => $images,
				),
				'forms'       => $forms,
				'assets'      => $assets,
				'php_errors'  => $errors,
				'builder'     => false !== strpos( $html, 'elementor-' ) ? 'elementor' : ( false !== strpos( $html, 'fl-builder' ) ? 'beaver' : null ),
			),
			static function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			}
		);
	}

	/**
	 * CSS/JS files of the page and, for the first few on this site, whether they load.
	 *
	 * @param string[] $urls Asset URLs.
	 * @param string   $host This site's host.
	 * @return array
	 */
	private static function asset_status( array $urls, $host ) {
		$out     = array();
		$checked = 0;
		foreach ( $urls as $u ) {
			$h    = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			$mine = '' === $h || $h === $host;
			$row  = array( 'url' => $mine ? (string) wp_parse_url( $u, PHP_URL_PATH ) : $u );
			if ( $mine && $checked < self::ASSET_CHECK ) {
				++$checked;
				$full        = '' === $h ? home_url( (string) $u ) : $u;
				$r           = wp_remote_head(
					$full,
					array(
						'timeout'   => 5,
						'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, used the same way core does.
					)
				);
				$row['http'] = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			}
			$out[] = $row;
		}
		$broken = array_values(
			array_filter(
				$out,
				static function ( $r ) {
					return isset( $r['http'] ) && ( 0 === $r['http'] || $r['http'] >= 400 );
				}
			)
		);
		return array(
			'files'  => $out,
			'broken' => $broken,
		);
	}
}
