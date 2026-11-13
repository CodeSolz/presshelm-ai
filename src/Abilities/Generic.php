<?php
/**
 * Text edits: a search/replace of stored text (a post field or a post's meta), so every page
 * builder's text is reachable without a dedicated adapter. Writes are approved by the owner,
 * journaled for an exact undo, health-checked, and refused where they would add or change code.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Builders\Guard;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Text edits of stored content.
 */
final class Generic {

	const POST_FIELDS = array( 'post_content', 'post_excerpt', 'post_title' );

	/** Meta keys that hold code (custom CSS/JS, header scripts, snippets). */
	const CODE_NAME = '/(^|[_-])(css|js|javascript|scripts?|snippets?|code|php|head(er)?_(code|scripts?)|footer_(code|scripts?)|custom_html|tracking_code)([_-]|$)/i';

	/**
	 * Whether a text contains code: script, style, PHP or iframe tags, javascript: links, event-handler
	 * attributes or CSS expressions (also as escaped in JSON).
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function has_code( $s ) {
		$s = (string) $s;
		// Builder data stores markup JSON-escaped (<, <\/script>): look at it unescaped too.
		$plain = str_replace( array( '\\u003c', '\\u003C', '\\u003e', '\\u003E', '\\/' ), array( '<', '<', '>', '>', '/' ), $s );
		return (bool) preg_match( '/<\s*(script|style|iframe|object|embed|applet|meta|link|base|form)\b|<\?(php|=)?|\bjavascript\s*:|\bvbscript\s*:|\bdata\s*:\s*text\/html|\son[a-z]{3,}\s*=|@import\b|expression\s*\(/i', $plain );
	}

	/**
	 * Whether any text in a value (nested arrays included) contains code (see has_code()).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function code_in( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				if ( self::code_in( $v ) ) {
					return true;
				}
			}
			return false;
		}
		return is_string( $value ) && self::has_code( $value );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$target = array(
			'target_type' => array(
				'type' => 'string',
				'enum' => array( 'post', 'meta' ),
			),
			'id'          => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'field'       => array(
				'type' => 'string',
				'enum' => self::POST_FIELDS,
			),
			'meta_key'    => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
		);
		$read   = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		return array(
			'content.text_search' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'text_search' ),
				'label'       => 'Find text inside a stored field',
				'description' => 'Finds a text in a post field or a post\'s meta (e.g. a page builder\'s data), and returns each match with context exactly as stored (JSON stays escaped), ready to paste as old_content. Meta without meta_key searches every meta field of the post and names the ones that contain the text (how to find where any builder keeps it).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $target + array(
						'pattern' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 500,
						),
					),
					'required'             => array( 'target_type', 'pattern' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'content.text_edit'   => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'text_edit' ),
				'label'       => 'Replace text inside a stored field',
				'description' => 'Replaces old_content with new_content in a post field or a post\'s meta, on the site (the value never travels whole). old_content must match exactly once unless replace_all. JSON stays valid or the edit is refused; serialized PHP data is refused. Journaled: undo puts the value back exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $target + array(
						'old_content' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 20000,
						),
						'new_content' => array(
							'type'      => 'string',
							'maxLength' => 20000,
						),
						'replace_all' => array( 'type' => 'boolean' ),
						'whole_word'  => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'target_type', 'old_content', 'new_content' ),
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

	// ------------------------------------------------------------------ text edits

	/**
	 * Reads the target value.
	 *
	 * @param array $p Target.
	 * @return array{value:string,post:\WP_Post|null,label:string}|\WP_Error
	 */
	private static function target( array $p ) {
		$type = (string) $p['target_type'];
		$post = get_post( (int) ( $p['id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found (give its id).' );
		}
		if ( 'post' === $type ) {
			$field = (string) ( $p['field'] ?? 'post_content' );
			if ( ! in_array( $field, self::POST_FIELDS, true ) ) {
				return new \WP_Error( 'presshelm_payload', 'field must be post_content, post_excerpt or post_title.' );
			}
			$value = (string) $post->{$field};
			$label = $field . ' of #' . $post->ID;
		} else {
			$key = (string) ( $p['meta_key'] ?? '' );
			if ( '' === $key || ! metadata_exists( 'post', $post->ID, $key ) ) {
				return new \WP_Error( 'presshelm_not_found', 'That meta key does not exist on this post.' );
			}
			$value = get_post_meta( $post->ID, $key, true );
			$label = 'meta ' . $key . ' of #' . $post->ID;
		}
		if ( is_array( $value ) || is_object( $value ) || ( is_string( $value ) && is_serialized( $value ) ) ) {
			return new \WP_Error( 'presshelm_serialized', 'This value is stored as serialized PHP data; editing it as text would corrupt it. Use the builder tools or the plugin\'s own settings instead.' );
		}
		return array(
			'value' => (string) $value,
			'post'  => $post,
			'label' => $label,
		);
	}

	/**
	 * Offsets of the matches.
	 *
	 * @param string $hay   Value.
	 * @param string $find  Text.
	 * @param bool   $whole Whole words only.
	 * @return int[]
	 */
	private static function matches( $hay, $find, $whole ) {
		$out = array();
		if ( $whole ) {
			preg_match_all( '/(?<![\p{L}\p{N}_])' . preg_quote( $find, '/' ) . '(?![\p{L}\p{N}_])/u', $hay, $m, PREG_OFFSET_CAPTURE );
			foreach ( $m[0] as $hit ) {
				$out[] = (int) $hit[1];
			}
			return $out;
		}
		for ( $at = strpos( $hay, $find ); false !== $at; $at = strpos( $hay, $find, $at + max( 1, strlen( $find ) ) ) ) {
			$out[] = $at;
		}
		return $out;
	}

	/**
	 * Finds text in a stored value.
	 *
	 * @param array $p Target + pattern.
	 * @return array|\WP_Error
	 */
	public static function text_search( array $p ) {
		if ( 'meta' === $p['target_type'] && '' === (string) ( $p['meta_key'] ?? '' ) ) {
			return self::search_all_meta( $p );
		}
		$t = self::target( $p );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$hits = self::matches( $t['value'], (string) $p['pattern'], false );
		$out  = array();
		foreach ( array_slice( $hits, 0, 20 ) as $at ) {
			$out[] = array(
				'offset'  => $at,
				'match'   => substr( $t['value'], $at, 400 ),
				'context' => substr( $t['value'], max( 0, $at - 200 ), min( 200, $at ) ) . '[…]',
			);
		}
		return array(
			'target'  => $t['label'],
			'bytes'   => strlen( $t['value'] ),
			'json'    => self::is_json( $t['value'] ),
			'count'   => count( $hits ),
			'matches' => $out,
		);
	}

	/**
	 * Searches every meta field of one post: which fields hold the text, how they are stored
	 * (text, JSON or serialized) and the first match in each, so the right field can be edited.
	 *
	 * @param array $p {id, pattern}.
	 * @return array|\WP_Error
	 */
	private static function search_all_meta( array $p ) {
		$post = get_post( (int) ( $p['id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found (give its id).' );
		}
		$find   = (string) $p['pattern'];
		$fields = array();
		$keys   = 0;
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			++$keys;
			foreach ( (array) $values as $raw ) {
				$raw = (string) $raw; // As stored: serialized data stays a string here.
				$at  = strpos( $raw, $find );
				if ( false === $at ) {
					continue;
				}
				$fields[] = array(
					'meta_key' => (string) $key,
					'format'   => is_serialized( $raw ) ? 'serialized (not editable as text; use the plugin\'s own tools)' : ( self::is_json( $raw ) ? 'json' : 'text' ),
					'bytes'    => strlen( $raw ),
					'count'    => substr_count( $raw, $find ),
					'first'    => substr( $raw, max( 0, $at - 150 ), min( 150, $at ) + strlen( $find ) + 150 ),
				);
				break;
			}
			if ( count( $fields ) >= 30 ) {
				break;
			}
		}
		$in_content = strpos( (string) $post->post_content, $find );
		return array(
			'target'          => 'all meta of #' . $post->ID,
			'meta_keys'       => $keys,
			'fields'          => $fields,
			'in_post_content' => false !== $in_content,
			'next'            => $fields ? 'Search the field with meta_key for exact matches, then edit it with content.text_edit.' : 'The text is not in this post\'s meta.',
		);
	}

	/**
	 * Whether a value is a JSON object or list.
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private static function is_json( $v ) {
		$s = ltrim( $v );
		return '' !== $s && ( '{' === $s[0] || '[' === $s[0] ) && null !== json_decode( $v, true );
	}

	/**
	 * Replaces text in a stored value (journaled, checked).
	 *
	 * @param array  $p          Target + old/new.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function text_edit( array $p, $command_id ) {
		$t = self::target( $p );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$type    = (string) $p['target_type'];
		$old     = (string) $p['old_content'];
		$new     = (string) $p['new_content'];
		$post    = $t['post'];
		$snippet = Guard::refuse_snippet( $post );
		if ( $snippet ) {
			return $snippet;
		}
		// Elementor shows its own data, not the post content: an edit there would not show.
		if ( 'post' === $type && 'post_content' === ( $p['field'] ?? 'post_content' ) && $post && 'elementor' === Guard::builder_of( $post ) ) {
			return new \WP_Error( 'presshelm_builder_page', 'This page is built with Elementor: its visible text is in the meta _elementor_data (use target_type meta, meta_key _elementor_data), not the post content.' );
		}
		// Text only: never code, and never a field that holds code.
		$where = 'meta' === $type ? (string) ( $p['meta_key'] ?? '' ) : '';
		if ( '' !== $where && preg_match( self::CODE_NAME, $where ) ) {
			return new \WP_Error( 'presshelm_code_field', '"' . $where . '" holds code (CSS, scripts or snippets); PressHelm does not edit code here. Nothing was changed.' );
		}
		if ( self::has_code( $new ) ) {
			return new \WP_Error( 'presshelm_code', 'new_content contains code (a script, style, PHP, iframe or event handler). Text edits change words, links and images only. Nothing was changed.' );
		}
		$hits = self::matches( $t['value'], $old, ! empty( $p['whole_word'] ) );
		if ( ! $hits ) {
			return new \WP_Error( 'presshelm_no_match', 'old_content was not found in the ' . $t['label'] . '. Search again (content_text_search) and paste a match exactly as stored.' );
		}
		if ( count( $hits ) > 1 && empty( $p['replace_all'] ) ) {
			return new \WP_Error( 'presshelm_multiple_matches', 'old_content occurs ' . count( $hits ) . ' times; add surrounding text to target one, or set replace_all.' );
		}
		$value = $t['value'];
		foreach ( array_reverse( $hits ) as $at ) {
			$value = substr_replace( $value, $new, $at, strlen( $old ) );
		}
		// Code already there may not change either (e.g. the words inside an existing script).
		if ( Registry::new_code( $t['value'], $value ) ) {
			return new \WP_Error( 'presshelm_code', 'This edit would change code that is already there (inside a script, style, iframe, event handler or script address). Text edits change words, links and images only. Nothing was changed.' );
		}
		if ( self::is_json( $t['value'] ) && ! self::is_json( $value ) ) {
			return new \WP_Error( 'presshelm_json_broken', 'This change would break the stored JSON (escape quotes and slashes in new_content as they are stored). Nothing was changed.' );
		}
		if ( 'post' === $type && 'post_content' === ( $p['field'] ?? 'post_content' ) ) {
			$lost = Registry::filter_losses( $value );
			if ( $lost && ! Registry::filter_losses( $t['value'] ) ) {
				return new \WP_Error( 'presshelm_content_filtered', 'This change would make WordPress strip ' . implode( ', ', $lost ) . '. Nothing was changed.' );
			}
		}
		if ( 'post' === $type ) {
			$journal = Journal::record(
				$command_id,
				'post',
				(string) $post->ID,
				array(
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_status'  => $post->post_status,
					'post_excerpt' => $post->post_excerpt,
				)
			);
			wp_save_post_revision( $post->ID );
			$r = wp_update_post(
				wp_slash(
					array(
						'ID' => $post->ID,
						(string) ( $p['field'] ?? 'post_content' ) => $value,
					)
				),
				true
			);
			if ( is_wp_error( $r ) ) {
				Journal::restore( $journal );
				return $r;
			}
		} else {
			$key     = (string) $p['meta_key'];
			$journal = Journal::record(
				$command_id,
				'post_meta',
				(string) $post->ID,
				array(
					$key => array(
						'exists' => true,
						'value'  => $t['value'],
					),
				)
			);
			update_post_meta( $post->ID, $key, wp_slash( $value ) );
			if ( '_elementor_data' === $key && class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post->ID )->delete(); // Elementor rebuilds it on the next view.
			}
			clean_post_cache( $post->ID );
		}
		$after = self::target( $p );
		if ( is_wp_error( $after ) || $after['value'] !== $value ) {
			Journal::restore( $journal );
			return new \WP_Error( 'presshelm_not_saved', 'WordPress did not store the value as written (a filter changed it), so the change was undone.' );
		}
		return array(
			'id'         => $post->ID,
			'target'     => $t['label'],
			'replaced'   => count( $hits ),
			'journal_id' => $journal,
		);
	}
}
