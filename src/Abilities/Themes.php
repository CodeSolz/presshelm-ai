<?php
/**
 * Themes (M3.7). PressHelm never switches the active theme: that is CRITICAL and stays with the
 * owner (wp-admin → Appearance → Themes) until the dashboard step-up exists (M5). What it can do:
 *
 * - child themes: created **inactive** from an installed parent (style.css + functions.php that
 *   enqueues both stylesheets; the parent's Customizer settings are copied so activating keeps them);
 * - draft themes: a copy of an installed theme to edit safely (files.patch on `theme:<draft>`), a
 *   preview link, then "publish" copies the draft over the live theme after a snapshot (undo and
 *   automatic rollback put the live theme back exactly). The theme's name, folder and settings stay;
 * - block templates and template parts of the active theme: list, read, change one block.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Theme abilities.
 */
final class Themes {

	const OPTION        = 'presshelm_themes';
	const PREVIEW_PARAM = 'presshelm_preview';
	const PREVIEW_TTL   = HOUR_IN_SECONDS;
	const MAX_FILES     = 5000;
	const MAX_BYTES     = 104857600;
	const SLUG          = '^[a-z0-9][a-z0-9_-]{1,60}$';
	const TEMPLATE_ID   = '^[A-Za-z0-9_.-]+//[A-Za-z0-9_-]+$';

	/**
	 * Ability definitions (merged into Registry::all()).
	 *
	 * @return array
	 */
	public static function definitions() {
		$read  = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$write = array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
		$slug  = array(
			'type'    => 'string',
			'pattern' => self::SLUG,
		);
		$tid   = array(
			'type'    => 'string',
			'pattern' => self::TEMPLATE_ID,
		);
		$ttype = array(
			'type' => 'string',
			'enum' => array( 'wp_template', 'wp_template_part' ),
		);
		return array(
			'themes.list'     => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'list_themes' ),
				'label'       => 'List installed themes',
				'description' => 'Installed themes: name, folder, version, parent, block theme or not, which one is active, and which ones PressHelm made (child themes, drafts). Only the owner switches themes.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'templates.list'  => array(
				'risk'        => 'READ',
				'cap'         => 'edit_theme_options',
				'mutates'     => false,
				'run'         => array( self::class, 'templates_list' ),
				'label'       => 'List block templates and template parts',
				'description' => 'Templates (wp_template) and template parts (wp_template_part, e.g. header, footer) of the active block theme, and whether each was customized.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'type' => $ttype ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'template.read'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_theme_options',
				'mutates'     => false,
				'run'         => array( self::class, 'template_read' ),
				'label'       => 'Read a block template',
				'description' => 'Markup, content_sha256 and the block tree (with paths) of one template or template part.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => $tid,
						'type' => $ttype,
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'template.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_theme_options',
				'mutates'     => true,
				'run'         => array( self::class, 'template_update' ),
				'label'       => 'Change one block of a template',
				'description' => 'Replaces or removes the block at `path` of a template or template part (saved as a customization, like the Site Editor does; the theme file is not touched). Bound to content_sha256 from template.read. Undo restores the previous version.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'             => $tid,
						'type'           => $ttype,
						'path'           => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'minItems' => 1,
							'maxItems' => 20,
						),
						'markup'         => array(
							'type'      => 'string',
							'maxLength' => 100000,
						),
						'remove'         => array( 'type' => 'boolean' ),
						'content_sha256' => array(
							'type'    => 'string',
							'pattern' => '^[a-fA-F0-9]{64}$',
						),
					),
					'required'             => array( 'id', 'path', 'content_sha256' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	// ---------------------------------------------------------------- registry

	/**
	 * Themes PressHelm made: slug → {kind: child|draft, of, name, created_at, states?}.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		$o = get_option( self::OPTION );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * Forgets a theme (after undo).
	 *
	 * @param string $slug Theme folder.
	 */
	public static function forget( $slug ) {
		$all = self::all();
		unset( $all[ (string) $slug ] );
		update_option( self::OPTION, $all, false );
	}

	// ---------------------------------------------------------------- list

	/**
	 * Installed themes.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$mine   = self::all();
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes( array( 'errors' => null ) ) as $slug => $t ) {
			$out[] = array(
				'slug'        => $slug,
				'name'        => $t->get( 'Name' ),
				'version'     => $t->get( 'Version' ),
				'parent'      => $t->parent() ? $t->get_template() : null,
				'block_theme' => $t->is_block_theme(),
				'active'      => $slug === $active,
				'broken'      => $t->errors() ? $t->errors()->get_error_message() : null,
				'made_by'     => isset( $mine[ $slug ] ) ? array(
					'kind' => $mine[ $slug ]['kind'],
					'of'   => $mine[ $slug ]['of'],
				) : null,
			);
		}
		return array(
			'active'     => $active,
			'themes'     => $out,
			'activation' => 'Only the site owner switches themes (WordPress → Appearance → Themes).',
		);
	}

	// ---------------------------------------------------------------- child themes

	// ---------------------------------------------------------------- drafts

	// ---------------------------------------------------------------- theme files

	// ---------------------------------------------------------------- preview

	// ---------------------------------------------------------------- templates

	/**
	 * Why block templates can't be used here ('' if they can).
	 *
	 * @return string
	 */
	private static function templates_blocker() {
		return wp_is_block_theme() || current_theme_supports( 'block-templates' ) ? '' : 'The active theme is not a block theme, so it has no block templates (use theme files or a child theme instead)';
	}

	/**
	 * Templates and parts of the active theme.
	 *
	 * @param array $p {type?}.
	 * @return array|\WP_Error
	 */
	public static function templates_list( array $p ) {
		$why = self::templates_blocker();
		if ( '' !== $why ) {
			return new \WP_Error( 'presshelm_unsupported', $why );
		}
		$types = isset( $p['type'] ) ? array( (string) $p['type'] ) : array( 'wp_template', 'wp_template_part' );
		$out   = array();
		foreach ( $types as $type ) {
			foreach ( get_block_templates( array(), $type ) as $t ) {
				$out[] = array(
					'id'         => $t->id,
					'type'       => $type,
					'slug'       => $t->slug,
					'title'      => $t->title,
					'area'       => 'wp_template_part' === $type ? $t->area : null,
					'customized' => 'custom' === $t->source,
				);
			}
		}
		return array(
			'theme'     => get_stylesheet(),
			'templates' => $out,
		);
	}

	/**
	 * One template.
	 *
	 * @param array $p {id, type?}.
	 * @return array|\WP_Error
	 */
	public static function template_read( array $p ) {
		$t = self::template( $p );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$content = (string) $t->content;
		return array(
			'id'             => $t->id,
			'type'           => $t->type,
			'title'          => $t->title,
			'customized'     => 'custom' === $t->source,
			'content'        => $content,
			'content_sha256' => hash( 'sha256', $content ),
			'blocks'         => Content::tree( parse_blocks( $content ), array() ),
		);
	}

	/**
	 * Changes one block of a template; saved as a customization (theme files untouched).
	 *
	 * @param array  $p          {id, type?, path, markup?|remove?, content_sha256}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function template_update( array $p, $command_id ) {
		$t = self::template( $p );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$content = (string) $t->content;
		if ( ! hash_equals( hash( 'sha256', $content ), strtolower( (string) $p['content_sha256'] ) ) ) {
			return new \WP_Error( 'presshelm_conflict', 'The template changed since it was read; read it again' );
		}
		$remove = ! empty( $p['remove'] );
		if ( ! $remove && ! isset( $p['markup'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Give the new block markup, or remove: true' );
		}
		$new = $remove ? array() : array_values(
			array_filter(
				parse_blocks( wp_kses_post( (string) $p['markup'] ) ),
				static function ( $b ) {
					return null !== $b['blockName'] || '' !== trim( (string) $b['innerHTML'] );
				}
			)
		);
		if ( ! $remove && ! $new ) {
			return new \WP_Error( 'presshelm_payload', 'The new markup contains no blocks' );
		}
		$changed = Content::splice( parse_blocks( $content ), array_map( 'intval', (array) $p['path'] ), $new );
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}
		// Saved the way the Site Editor saves (serialized blocks).
		$updated = serialize_blocks( $changed );
		if ( $t->wp_id ) {
			$post    = get_post( (int) $t->wp_id );
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
			$r       = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $updated,
					)
				),
				true
			);
		} else {
			// First customization: a wp_template(_part) post for this theme. Undo moves it to the
			// trash, and WordPress falls back to the theme's file.
			$r = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $t->type,
						'post_status'  => 'publish',
						'post_name'    => $t->slug,
						'post_title'   => $t->title,
						'post_excerpt' => (string) $t->description,
						'post_content' => $updated,
					)
				),
				true
			);
			if ( ! is_wp_error( $r ) ) {
				wp_set_post_terms( $r, array( $t->theme ), 'wp_theme' );
				if ( 'wp_template_part' === $t->type && $t->area ) {
					wp_set_post_terms( $r, array( $t->area ), 'wp_template_part_area' );
				}
				$journal = Journal::record( $command_id, 'post_created', (string) $r, array() );
			}
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$after = get_block_template( $t->id, $t->type );
		return array(
			'id'             => $t->id,
			'content_sha256' => hash( 'sha256', (string) ( $after ? $after->content : $updated ) ),
			'journal_id'     => $journal,
		);
	}

	/**
	 * Resolves a template of the active theme.
	 *
	 * @param array $p {id, type?}.
	 * @return \WP_Block_Template|\WP_Error
	 */
	private static function template( array $p ) {
		$why = self::templates_blocker();
		if ( '' !== $why ) {
			return new \WP_Error( 'presshelm_unsupported', $why );
		}
		$type = (string) ( $p['type'] ?? 'wp_template' );
		$t    = get_block_template( (string) $p['id'], $type );
		if ( ! $t ) {
			return new \WP_Error( 'presshelm_not_found', 'No such ' . ( 'wp_template_part' === $type ? 'template part' : 'template' ) . ' in the active theme (see templates.list)' );
		}
		return $t;
	}

	// ---------------------------------------------------------------- helpers
}
