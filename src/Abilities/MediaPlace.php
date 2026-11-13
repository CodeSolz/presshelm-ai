<?php
/**
 * Putting images into pages (B3): as the featured image, as a new image block (start, end or after
 * a block), or in place of another image wherever it appears in a post. Afterwards the image must
 * really show: the rendered post contains it and its file loads; otherwise the change is undone.
 * For a published page the live HTML is checked too (reported only: a page cache may hold the
 * old page for a while).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Image placement.
 */
final class MediaPlace {

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return array(
			'media.insert' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'insert' ),
				'label'       => 'Put an image into a post or page',
				'description' => 'mode featured: sets the featured image. mode block: adds an image block at the start, the end, or after a top-level block (after = index from blocks.inspect), with alt text, optional caption, size (large default, full, medium) and alignment. mode replace: swaps replace_id for the image everywhere in this post (image blocks and classic images keep their size). Afterwards the image must render and its file must load, or the change is undone; a published page is checked too. Undo puts the post back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => $id,
						'image_id'   => $id,
						'mode'       => array(
							'type' => 'string',
							'enum' => array( 'featured', 'block', 'replace' ),
						),
						'position'   => array(
							'type' => 'string',
							'enum' => array( 'start', 'end', 'after' ),
						),
						'after'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'replace_id' => $id,
						'alt'        => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'caption'    => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'size'       => array(
							'type' => 'string',
							'enum' => array( 'large', 'full', 'medium' ),
						),
						'align'      => array(
							'type' => 'string',
							'enum' => array( 'none', 'left', 'center', 'right', 'wide', 'full' ),
						),
					),
					'required'             => array( 'id', 'image_id', 'mode' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * Puts an image into a post.
	 *
	 * @param array  $p          Input.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function insert( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No post or page with that id that can be edited.' );
		}
		$image = (int) $p['image_id'];
		if ( 'attachment' !== get_post_type( $image ) || ! wp_attachment_is_image( $image ) ) {
			return new \WP_Error( 'presshelm_not_image', 'Media item ' . $image . ' is not an image (upload or find one first: media.upload, media.search).' );
		}
		$mode = (string) $p['mode'];
		if ( 'featured' === $mode ) {
			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				return new \WP_Error( 'presshelm_no_featured', 'This kind of content (' . $post->post_type . ') has no featured image.' );
			}
			$old     = get_post_meta( $post->ID, '_thumbnail_id', true );
			$journal = Journal::record(
				$command_id,
				'post_meta',
				(string) $post->ID,
				array(
					'_thumbnail_id' => array(
						'exists' => '' !== $old,
						'value'  => '' !== $old ? $old : null,
					),
				)
			);
			set_post_thumbnail( $post->ID, $image );
			if ( '' !== (string) ( $p['alt'] ?? '' ) && '' === (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $image, '_wp_attachment_image_alt', sanitize_text_field( (string) $p['alt'] ) );
			}
		} else {
			$refuse = \PressHelm\Builders\Guard::refuse_content_edit( $post, 'an image change' );
			if ( $refuse ) {
				return $refuse;
			}
			$content = (string) $post->post_content;
			$new     = 'block' === $mode ? self::with_block( $content, $image, $p ) : self::with_replaced( $content, (int) ( $p['replace_id'] ?? 0 ), $image, $p );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$journal = Journal::record(
				$command_id,
				'post',
				(string) $post->ID,
				array(
					'post_title'   => $post->post_title,
					'post_content' => $content,
					'post_status'  => $post->post_status,
					'post_excerpt' => $post->post_excerpt,
				)
			);
			wp_save_post_revision( $post->ID );
			$r = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $new['content'],
					)
				),
				true
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		clean_post_cache( $post->ID );
		$shows = self::shows( $post->ID, $image, $mode );
		if ( ! $shows['ok'] ) {
			Journal::restore( $journal );
			return new \WP_Error( 'presshelm_image_not_shown', 'The image did not show after the change (' . $shows['reason'] . '), so it was undone.', $shows );
		}
		return array(
			'id'         => $post->ID,
			'image_id'   => $image,
			'mode'       => $mode,
			'replaced'   => isset( $new['count'] ) ? $new['count'] : null,
			'shows'      => $shows['checks'],
			'journal_id' => $journal,
		);
	}

	/**
	 * Image block markup (as the block editor saves it).
	 *
	 * @param int   $image Attachment id.
	 * @param array $p     Input (alt, caption, size, align).
	 * @return string
	 */
	private static function block( $image, array $p ) {
		$size  = in_array( $p['size'] ?? '', array( 'large', 'full', 'medium' ), true ) ? $p['size'] : 'large';
		$align = in_array( $p['align'] ?? '', array( 'left', 'center', 'right', 'wide', 'full' ), true ) ? $p['align'] : '';
		$url   = wp_get_attachment_image_url( $image, $size );
		$alt   = isset( $p['alt'] ) ? (string) $p['alt'] : (string) get_post_meta( $image, '_wp_attachment_image_alt', true );
		$attrs = array(
			'id'              => $image,
			'sizeSlug'        => $size,
			'linkDestination' => 'none',
		);
		if ( $align ) {
			$attrs['align'] = $align;
		}
		$class   = 'wp-block-image' . ( $align ? ' align' . $align : '' ) . ' size-' . $size;
		$caption = '' !== (string) ( $p['caption'] ?? '' ) ? '<figcaption class="wp-element-caption">' . wp_kses_post( (string) $p['caption'] ) . '</figcaption>' : '';
		return '<!-- wp:image ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' -->' . "\n"
			. '<figure class="' . esc_attr( $class ) . '"><img src="' . esc_url( (string) $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $image . '"/>' . $caption . '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Content with a new image block.
	 *
	 * @param string $content Content.
	 * @param int    $image   Attachment id.
	 * @param array  $p       Input.
	 * @return array{content: string}|\WP_Error
	 */
	private static function with_block( $content, $image, array $p ) {
		$block    = self::block( $image, $p );
		$position = (string) ( $p['position'] ?? 'end' );
		if ( 'start' === $position ) {
			return array( 'content' => '' === trim( $content ) ? $block : $block . "\n\n" . $content );
		}
		if ( 'end' === $position ) {
			return array( 'content' => '' === trim( $content ) ? $block : rtrim( $content ) . "\n\n" . $block );
		}
		if ( ! isset( $p['after'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'position "after" needs after (a top-level block index from blocks.inspect).' );
		}
		if ( serialize_blocks( parse_blocks( $content ) ) !== $content ) {
			return new \WP_Error( 'presshelm_nonstandard_markup', 'This page\'s block markup is not in standard form, so an image can only be added at the start or the end.' );
		}
		$blocks = parse_blocks( $content );
		$real   = array_keys(
			array_filter(
				$blocks,
				static function ( $b ) {
					return null !== $b['blockName'];
				}
			)
		);
		$after  = (int) $p['after'];
		if ( ! isset( $real[ $after ] ) ) {
			return new \WP_Error( 'presshelm_block_not_found', 'There is no top-level block ' . $after . ' (the page has ' . count( $real ) . ').' );
		}
		$insert = array_merge( array( parse_blocks( "\n\n" )[0] ), parse_blocks( $block ) );
		array_splice( $blocks, $real[ $after ] + 1, 0, $insert );
		return array( 'content' => serialize_blocks( $blocks ) );
	}

	/**
	 * Content with one image swapped for another (image blocks, cover blocks and classic images).
	 *
	 * @param string $content Content.
	 * @param int    $old     Image to replace.
	 * @param int    $image   New image.
	 * @param array  $p       Input (alt).
	 * @return array{content: string, count: int}|\WP_Error
	 */
	private static function with_replaced( $content, $old, $image, array $p ) {
		if ( ! $old ) {
			return new \WP_Error( 'presshelm_payload', 'mode replace needs replace_id (the image to swap out).' );
		}
		if ( $old === $image ) {
			return new \WP_Error( 'presshelm_payload', 'The new image is the same as the old one.' );
		}
		$count = 0;
		$html  = static function ( $s ) use ( $old, $image, $p, &$count ) {
			return preg_replace_callback(
				'/<img\b[^>]*\bwp-image-' . $old . '\b[^>]*>/i',
				static function ( $m ) use ( $old, $image, $p, &$count ) {
					++$count;
					$tag  = $m[0];
					$size = 'full';
					if ( preg_match( '/\bsrc="([^"]*)"/i', $tag, $src ) ) {
						$size = self::size_of( $old, (string) $src[1] );
					}
					$url = wp_get_attachment_image_url( $image, $size );
					$tag = preg_replace( '/\bsrc="[^"]*"/i', 'src="' . esc_url( (string) $url ) . '"', $tag );
					$tag = preg_replace( '/\s(srcset|sizes|width|height)="[^"]*"/i', '', $tag );
					$tag = preg_replace( '/\bwp-image-' . $old . '\b/', 'wp-image-' . $image, $tag );
					if ( isset( $p['alt'] ) ) {
						$tag = preg_match( '/\balt="[^"]*"/i', $tag ) ? preg_replace( '/\balt="[^"]*"/i', 'alt="' . esc_attr( (string) $p['alt'] ) . '"', $tag ) : preg_replace( '/<img\b/i', '<img alt="' . esc_attr( (string) $p['alt'] ) . '"', $tag );
					}
					return $tag;
				},
				(string) $s
			);
		};
		$walk  = static function ( array $blocks ) use ( &$walk, $html, $old, $image ) {
			foreach ( $blocks as &$b ) {
				if ( in_array( $b['blockName'], array( 'core/image', 'core/cover' ), true ) && (int) ( $b['attrs']['id'] ?? 0 ) === $old ) {
					$b['attrs']['id'] = $image;
					if ( 'core/cover' === $b['blockName'] ) {
						$b['attrs']['url'] = (string) wp_get_attachment_image_url( $image, 'full' );
					}
				}
				$b['innerHTML'] = $html( $b['innerHTML'] );
				foreach ( $b['innerContent'] as &$chunk ) {
					if ( is_string( $chunk ) ) {
						$chunk = $html( $chunk );
					}
				}
				unset( $chunk );
				$b['innerBlocks'] = $walk( (array) $b['innerBlocks'] );
			}
			unset( $b );
			return $blocks;
		};
		// Standard block markup is rebuilt from the blocks (their attributes follow); anything else
		// gets only its image tags swapped, so nothing around them changes.
		$new = serialize_blocks( parse_blocks( $content ) ) === $content ? serialize_blocks( $walk( parse_blocks( $content ) ) ) : $html( $content );
		// Count each image once (innerHTML and innerContent hold the same markup).
		$count = (int) preg_match_all( '/\bwp-image-' . $image . '\b/', $new ) - (int) preg_match_all( '/\bwp-image-' . $image . '\b/', $content );
		if ( $count < 1 ) {
			return new \WP_Error( 'presshelm_not_in_post', 'Image ' . $old . ' does not appear in this post\'s content (see media.usage); nothing was changed.' );
		}
		return array(
			'content' => $new,
			'count'   => $count,
		);
	}

	/**
	 * Which size of an image a URL points at (full if unknown).
	 *
	 * @param int    $id  Attachment id.
	 * @param string $url URL.
	 * @return string
	 */
	private static function size_of( $id, $url ) {
		$file = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$meta = wp_get_attachment_metadata( $id );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $s ) {
			if ( ( $s['file'] ?? '' ) === $file ) {
				return (string) $name;
			}
		}
		return 'full';
	}

	/**
	 * Whether the image really shows: rendered post, the file itself, and the live page.
	 *
	 * @param int    $post_id Post id.
	 * @param int    $image   Attachment id.
	 * @param string $mode    Mode.
	 * @return array{ok: bool, checks: array, reason?: string}
	 */
	public static function shows( $post_id, $image, $mode ) {
		$rel    = (string) get_post_meta( $image, '_wp_attached_file', true );
		$stem   = preg_replace( '/\.[a-z0-9]+$/i', '', $rel );
		$stem   = '' !== $stem ? $stem : 'wp-image-' . $image;
		$checks = array();
		$post   = get_post( $post_id );
		$html   = 'featured' === $mode ? get_the_post_thumbnail( $post, 'large' ) : (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, rendering the post as WordPress does.
		if ( false === strpos( (string) $html, $stem ) ) {
			return array(
				'ok'     => false,
				'checks' => $checks,
				'reason' => 'it is not in the rendered ' . ( 'featured' === $mode ? 'featured image' : 'content' ),
			);
		}
		$checks['rendered'] = 'yes';
		$url                = (string) wp_get_attachment_image_url( $image, 'large' );
		$res                = wp_remote_head(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
			)
		);
		if ( is_wp_error( $res ) ) {
			$checks['file'] = 'not checked (the site could not reach itself)';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
			if ( 200 !== $code || 0 !== strpos( $type, 'image/' ) ) {
				return array(
					'ok'     => false,
					'checks' => $checks,
					'reason' => 'its file does not load (HTTP ' . $code . ( $type ? ', ' . $type : '' ) . ')',
				);
			}
			$checks['file'] = 'loads (' . $type . ')';
		}
		if ( 'publish' !== $post->post_status ) {
			$checks['page'] = 'not checked (' . $post->post_status . ')';
		} else {
			$page = wp_remote_get(
				(string) get_permalink( $post ),
				array(
					'timeout'   => 20,
					'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
					'headers'   => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $page ) || 200 !== (int) wp_remote_retrieve_response_code( $page ) ) {
				$checks['page'] = 'not checked (the page did not answer)';
			} elseif ( false !== strpos( (string) wp_remote_retrieve_body( $page ), $stem ) ) {
				$checks['page'] = 'shows the image';
			} elseif ( ! self::page_has_post( (string) wp_remote_retrieve_body( $page ), $post, $mode, (string) $html ) ) {
				$checks['page'] = 'not checked (the page that answered does not contain this post at all: a page cache, a maintenance or coming-soon page, or the theme)';
			} else {
				$checks['page'] = 'the live page does not show it yet' . ( 'featured' === $mode ? ' (the theme may not show featured images here, or a page cache holds the old page)' : ' (a page cache may hold the old page)' );
			}
		}
		return array(
			'ok'     => true,
			'checks' => $checks,
		);
	}

	/**
	 * Whether a fetched page shows this post at all (a piece of its own text, or its title for a
	 * featured image), so a missing image is not blamed on the change when the page shows something else.
	 *
	 * @param string   $body     Page HTML.
	 * @param \WP_Post $post     Post.
	 * @param string   $mode     Mode.
	 * @param string   $rendered Rendered content (block and replace modes).
	 * @return bool
	 */
	private static function page_has_post( $body, \WP_Post $post, $mode, $rendered ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( 'featured' === $mode ? '' : $rendered ) ) );
		if ( '' === $text ) {
			return false !== strpos( $body, esc_html( get_the_title( $post ) ) );
		}
		$probe = esc_html( mb_substr( $text, 0, 30 ) );
		return false !== strpos( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ), html_entity_decode( $probe ) ) || false !== strpos( $body, $probe );
	}
}
