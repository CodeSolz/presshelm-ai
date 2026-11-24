<?php
/**
 * Abilities PressHelm can run on this site. Each one declares its risk, the WordPress capability
 * the executing user must have, whether it changes the site (→ journal + health gate), its input
 * schema and how it is undone. They are also registered with the WordPress Abilities API (M3.1),
 * not public: anything run through it goes through the same safeguards (Executor).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Capabilities\Inventory;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Ability registry.
 */
final class Registry {

	/** Abilities API category. */
	const CATEGORY = 'presshelm';

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array{risk:string,cap:string,mutates:bool,run:callable,label:string,description:string,input:array|null,annotations:array,undo:string|null}>
	 */
	public static function all() {
		/**
		 * Abilities added by an installed extension (same shape as PressHelm's own).
		 *
		 * @param array $all Ability id → definition.
		 */
		return (array) apply_filters( 'presshelm_abilities', self::core() );
	}

	/**
	 * PressHelm's own abilities.
	 *
	 * @return array
	 */
	public static function core() {
		$id  = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$own = array(
			'site.inspect'    => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'site_inspect' ),
				'label'       => 'Inspect the site',
				'description' => 'Honest capability report: versions, theme, builders, WooCommerce, filesystem, cron, loopback, PHP limits, and what PressHelm can and cannot do here (with reasons).',
				'input'       => null,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
			'content.read'    => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'content_read' ),
				'label'       => 'Read a post or page',
				'description' => 'Title, status and content of one post or page.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'undo'        => null,
			),
			'content.update'  => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'content_update' ),
				'label'       => 'Edit a post or page',
				'description' => 'Changes the title and/or content (filtered like the editor). The previous version is journaled and a revision is saved first.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => $id,
						'title'   => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'content' => array(
							'type'      => 'string',
							'maxLength' => 200000,
						),
						'excerpt' => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'status'  => array(
							'type' => 'string',
							'enum' => Content::STATUSES,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
			'journal.restore' => array(
				'risk'        => 'HIGH',
				'cap'         => 'manage_options',
				'mutates'     => true,
				'run'         => array( self::class, 'journal_restore' ),
				'label'       => 'Undo an earlier change',
				'description' => 'Puts back the state recorded before a change (each record can be used once).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'journal_id' => $id ),
					'required'             => array( 'journal_id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => null,
			),
		);
		$all = array_merge( $own, Content::definitions(), Media::definitions(), MediaEdit::definitions(), MediaPlace::definitions(), Mail::definitions(), Report::definitions(), Terms::definitions(), Site::definitions(), Plugins::definitions(), Themes::definitions(), Database::definitions(), Ops::definitions(), Cache::definitions(), Revisions::definitions(), Generic::definitions(), Seo::definitions(), Woo::definitions(), Builder::definitions(), PluginRecords::definitions(), Forms::definitions(), Logs::definitions(), PageView::definitions(), Stage::definitions(), Users::definitions(), Comments::definitions(), Core::definitions(), FileTools::definitions(), \PressHelm\Woo\Catalog::definitions(), Porting::definitions(), ThirdParty::definitions(), Acf::definitions() );
		return $all;
	}

	/**
	 * Abilities API name for an ability id (content.update → presshelm/content-update).
	 *
	 * @param string $id Ability id.
	 * @return string
	 */
	public static function wp_name( $id ) {
		return 'presshelm/' . str_replace( array( '.', '_' ), '-', $id );
	}

	/**
	 * Registers the category and abilities with the WordPress Abilities API (if present).
	 */
	public static function register_with_wp() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/** Abilities API category. */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'PressHelm', 'presshelm-ai' ),
				'description' => __( 'Site changes made by your PressHelm AI developer, with journal, health check and undo.', 'presshelm-ai' ),
			)
		);
	}

	/** Abilities API abilities (not public: no REST exposure; see M3.14 for the MCP Adapter). */
	public static function register_abilities() {
		$mcp = ThirdParty::mcp_exposed();
		foreach ( self::all() as $id => $a ) {
			$args = array(
				'label'               => $a['label'],
				'description'         => $a['description'],
				'category'            => self::CATEGORY,
				'execute_callback'    => static function ( $input = null ) use ( $id ) {
					$o = Executor::run( $id, is_array( $input ) ? $input : array(), 'local_' . wp_generate_uuid4() );
					return 'succeeded' === $o['status'] ? $o['result'] : new \WP_Error( 'presshelm_' . $o['status'], (string) ( $o['result']['message'] ?? $o['result']['error'] ?? $o['status'] ), $o['result'] );
				},
				'permission_callback' => static function () use ( $a ) {
					return current_user_can( $a['cap'] );
				},
				'meta'                => array(
					'annotations'  => array_map( 'boolval', (array) $a['annotations'] ),
					'show_in_rest' => $mcp && 'READ' === $a['risk'],
					'mcp'          => array(
						// Only read-only abilities, and only if the owner opted in (Permissions tab):
						// changes stay behind PressHelm's approvals.
						'public' => $mcp && 'READ' === $a['risk'],
						'type'   => 'tool',
					),
					'presshelm'    => array(
						'id'   => $id,
						'risk' => $a['risk'],
						'undo' => $a['undo'] ? self::wp_name( $a['undo'] ) : null,
					),
				),
			);
			if ( $a['input'] ) {
				$args['input_schema'] = $a['input'];
			}
			wp_register_ability( self::wp_name( $id ), $args );
		}
	}

	/**
	 * Honest capability report (M3.1).
	 *
	 * @return array
	 */
	public static function site_inspect() {
		return Inventory::report( true );
	}

	/**
	 * Reads one post/page.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function content_read( array $p ) {
		$post = get_post( isset( $p['id'] ) ? (int) $p['id'] : 0 );
		if ( ! $post ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to read this post' );
		}
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'content'  => mb_substr( (string) $post->post_content, 0, 100000 ),
			'builder'  => \PressHelm\Builders\Guard::builder_of( $post ),
			'modified' => $post->post_modified_gmt,
			'link'     => get_permalink( $post ),
		);
	}

	/**
	 * Updates title and/or content (journal + revision first).
	 *
	 * @param array  $p          {id, title?, content?}.
	 * @param string $command_id Command id.
	 * @return array|\WP_Error
	 */
	public static function content_update( array $p, $command_id ) {
		$post = get_post( isset( $p['id'] ) ? (int) $p['id'] : 0 );
		if ( ! $post ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_capability', 'Not allowed to edit this post' );
		}
		$snippet = \PressHelm\Builders\Guard::refuse_snippet( $post );
		if ( $snippet ) {
			return $snippet;
		}
		if ( ! isset( $p['title'] ) && ! isset( $p['content'] ) && ! isset( $p['excerpt'] ) && ! isset( $p['status'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Nothing to change' );
		}
		if ( isset( $p['content'] ) ) {
			$builder = \PressHelm\Builders\Guard::refuse_content_edit( $post, 'this content edit' );
			if ( $builder ) {
				return $builder;
			}
			$lost = self::filter_losses( (string) $p['content'] );
			if ( $lost ) {
				return new \WP_Error(
					'presshelm_content_filtered',
					'Saving this full content would silently remove ' . implode( ', ', $lost ) . ' (WordPress filters them out of edits). Nothing was changed. Change only the block concerned with block_patch, or put a saved version back with revision_restore.'
				);
			}
		}
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
		$update = array( 'ID' => $post->ID );
		if ( isset( $p['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $p['title'] );
		}
		if ( isset( $p['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $p['content'] ); // Same filtering an editor gets.
		}
		if ( isset( $p['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $p['excerpt'] );
		}
		if ( isset( $p['status'] ) && in_array( $p['status'], Content::STATUSES, true ) ) {
			if ( 'publish' === $p['status'] && ! current_user_can( 'publish_post', $post->ID ) ) {
				return new \WP_Error( 'presshelm_capability', 'Not allowed to publish this post' );
			}
			$update['post_status'] = $p['status'];
		}
		$r = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'id'         => $post->ID,
			'journal_id' => $journal,
			'link'       => get_permalink( $post->ID ),
		);
	}

	/**
	 * What WordPress's edit filter (wp_kses_post) would remove from content: elements and
	 * attributes that are in the input but fewer afterwards. Mere normalisation (e.g. & → &amp;)
	 * is not a loss.
	 *
	 * @param string $html Content.
	 * @return string[] e.g. ['<form> ×1', '<input> ×3', 'attribute style ×2'].
	 */
	public static function filter_losses( $html ) {
		$after = wp_kses_post( $html );
		if ( $after === $html ) {
			return array();
		}
		$count = function ( $s, $re ) {
			preg_match_all( $re, $s, $m );
			return array_count_values( array_map( 'strtolower', $m[1] ) );
		};
		$lost  = array();
		$tags  = '#<([a-zA-Z][a-zA-Z0-9-]*)\b#';
		$attr  = '#\s([a-zA-Z][a-zA-Z0-9_:-]*)\s*=\s*["\']#';
		foreach ( array(
			$tags => '<%s>',
			$attr => 'attribute %s',
		) as $re => $label ) {
			$a = $count( $html, $re );
			$b = $count( $after, $re );
			foreach ( $a as $name => $n ) {
				$diff = $n - ( $b[ $name ] ?? 0 );
				if ( $diff > 0 ) {
					$lost[] = sprintf( $label, $name ) . ' ×' . $diff;
				}
			}
		}
		return $lost;
	}

	/**
	 * Code in a changed text that is not, byte for byte, code the text already had: new code, or
	 * existing code with anything inside it changed (a script's or style's contents, an event
	 * handler, a javascript: address). Code the owner already has may stay or go, never change.
	 *
	 * @param string $before The stored text.
	 * @param string $after  The text as it would be saved.
	 * @return string[] The new or changed code (empty when there is none).
	 */
	public static function new_code( $before, $after ) {
		list( $had )        = self::code_pieces( $before );
		list( $now, $rest ) = self::code_pieces( $after );
		$left               = array_count_values( $had );
		$new                = array();
		foreach ( $now as $piece ) {
			if ( ! empty( $left[ $piece ] ) ) {
				--$left[ $piece ];
			} else {
				$new[] = $piece;
			}
		}
		// Anything that still looks like code was not recognised as a whole piece: refused too.
		if ( Generic::has_code( $rest ) ) {
			$new[] = $rest;
		}
		return $new;
	}

	/**
	 * The code in a text, each piece whole and exactly as stored: PHP, script/style/iframe/object
	 * elements with their contents, tags that load or embed code (embed, meta, link, base, form),
	 * tags with event handlers, script addresses or CSS expressions, and script addresses outside
	 * tags (e.g. in block settings). JSON escapes are read as the characters they stand for.
	 *
	 * @param string $s Text.
	 * @return array{0:string[],1:string} The pieces, and the text without them.
	 */
	private static function code_pieces( $s ) {
		$s      = (string) preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( $m ) {
				return html_entity_decode( '&#' . hexdec( $m[1] ) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			str_replace( '\\/', '/', (string) $s )
		);
		$marker = '/\son[a-z]{3,}\s*=|\b(?:javascript|vbscript)\s*:|\bdata\s*:\s*text\/html|@import\b|expression\s*\(/i';
		$pieces = array();
		$take   = static function ( $m ) use ( &$pieces ) {
			$pieces[] = $m[0];
			return "\x00";
		};
		$s      = (string) preg_replace_callback( '/<\?.*?(?:\?>|$)/s', $take, $s );
		$s      = (string) preg_replace_callback( '/<\s*(script|style|iframe|object|applet|noscript|template)\b.*?(?:<\s*\/\s*\1\s*>|$)/is', $take, $s );
		$s      = (string) preg_replace_callback( '/<\s*(?:embed|meta|link|base|form)\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+(?:>|$)/i', $take, $s );
		$s      = (string) preg_replace_callback(
			'/<[a-zA-Z][a-zA-Z0-9:-]*(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+(?:>|$)/',
			static function ( $m ) use ( $marker, &$pieces ) {
				if ( ! preg_match( $marker, $m[0] ) ) {
					return $m[0];
				}
				$pieces[] = $m[0];
				return "\x00";
			},
			$s
		);
		// Outside tags (block settings, text): up to the end of the JSON string or the line.
		$s = (string) preg_replace_callback( '/(?:\b(?:javascript|vbscript)\s*:|\bdata\s*:\s*text\/html|@import\b|expression\s*\()(?:[^"<>\\\\\n]|\\\\.)*/i', $take, $s );
		return array( $pieces, $s );
	}

	/**
	 * Undo by journal id.
	 *
	 * @param array $p {journal_id}.
	 * @return array|\WP_Error
	 */
	public static function journal_restore( array $p ) {
		$r = Journal::restore( isset( $p['journal_id'] ) ? (int) $p['journal_id'] : 0 );
		return is_wp_error( $r ) ? $r : array( 'restored' => (int) $p['journal_id'] );
	}
}
