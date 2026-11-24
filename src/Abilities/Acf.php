<?php
/**
 * Advanced Custom Fields (competitor parity 2026-11-24): the fields ACF shows on a post or term, read and changed through ACF's own API (get_field / update_field), never as raw meta:
 * - which fields exist comes from ACF's field groups for that object (its location rules);
 * - each value is checked against its field (choices, numbers, e-mail, links, attachments, ACF's
 *   own validation) and written with update_field(), journaled; undo puts every value back;
 * - text that WordPress would filter (scripts, event handlers) is refused, not silently changed;
 * - taxonomy fields that save the post's terms stay with wp-admin.
 * Options pages are site settings: reading them is here (secrets masked), changing them is the
 * PressHelm Power Tools extension's acf.options_update.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * ACF fields.
 */
final class Acf {

	/** Where a value lives: post:<id>, term:<id>, option or option:<options page slug>. */
	const TARGET = '^(post|term):[1-9][0-9]{0,18}$|^option(:[A-Za-z0-9_-]{1,64})?$';

	/** Field types without a value of their own. */
	const LAYOUT_TYPES = array( 'message', 'tab', 'accordion' );

	/** Field types whose value is text that ends up in the page. */
	const TEXT_TYPES = array( 'text', 'textarea', 'wysiwyg', 'url', 'email', 'oembed' );

	/** Most fields one change may set. */
	const MAX_VALUES = 30;

	/**
	 * Whether ACF (free or PRO) is active with the API PressHelm uses.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'update_field' ) && function_exists( 'get_field' );
	}

	/**
	 * Ability definitions (only while ACF is active).
	 *
	 * @return array
	 */
	public static function definitions() {
		if ( ! self::active() ) {
			return array();
		}
		$target = array(
			'type'    => 'string',
			'pattern' => self::TARGET,
		);
		return array(
			'acf.read'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'read' ),
				'label'       => 'Read Advanced Custom Fields',
				'description' => 'The ACF fields of a post, term or options page (target post:<id>, term:<id>, option or option:<page>): each field group with its fields (name, key, label, type, choices, sub fields) and the stored value. Without a page, option lists the options pages. Secrets are masked.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'target' => $target ),
					'required'             => array( 'target' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
			'acf.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'update' ),
				'label'       => 'Change Advanced Custom Fields',
				'description' => 'Sets ACF field values of one post or term (by field name or key, as acf.read lists them) through ACF\'s own save, checked against each field. Repeaters, groups and flexible content take ACF\'s array format. Undo puts every value back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'target' => array(
							'type'    => 'string',
							'pattern' => '^(post|term):[1-9][0-9]{0,18}$',
						),
						'values' => array(
							'type'          => 'object',
							'minProperties' => 1,
							'maxProperties' => self::MAX_VALUES,
						),
					),
					'required'             => array( 'target', 'values' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * Resolves a target to ACF's id and checks the current user may see it.
	 *
	 * @param string $target Target.
	 * @return array{acf_id:string|int,type:string,id:int,page:string,label:string,edit:string}|\WP_Error
	 */
	public static function resolve( $target ) {
		$target = (string) $target;
		if ( ! preg_match( '/' . self::TARGET . '/', $target ) ) {
			return new \WP_Error( 'presshelm_payload', 'target must be post:<id>, term:<id>, option or option:<page>.' );
		}
		if ( 0 === strpos( $target, 'option' ) ) {
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( \PressHelm\Bridge\TaskUser::SITE_CAP ) ) {
				return new \WP_Error( 'presshelm_capability', 'Options pages are site settings: only administrators can read them.' );
			}
			$slug = (string) substr( $target, 7 );
			if ( '' === $slug ) {
				return array(
					'acf_id' => 'options',
					'type'   => 'option',
					'id'     => 0,
					'page'   => '',
					'label'  => 'options pages',
					'edit'   => admin_url(),
				);
			}
			$page = self::options_page( $slug );
			if ( ! $page ) {
				return new \WP_Error( 'presshelm_not_found', 'No ACF options page "' . $slug . '" (acf.read with target "option" lists them).' );
			}
			return array(
				'acf_id' => (string) ( $page['post_id'] ?? 'options' ),
				'type'   => 'option',
				'id'     => 0,
				'page'   => $slug,
				'label'  => (string) ( $page['page_title'] ?? $slug ),
				'edit'   => admin_url( 'admin.php?page=' . rawurlencode( $slug ) ),
			);
		}
		list( $type, $id ) = explode( ':', $target );
		$id                = (int) $id;
		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'presshelm_not_found', 'Post not found' );
			}
			return array(
				'acf_id' => $id,
				'type'   => 'post',
				'id'     => $id,
				'page'   => '',
				'label'  => (string) $post->post_title,
				'edit'   => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) || ! current_user_can( 'edit_term', $id ) ) {
				return new \WP_Error( 'presshelm_not_found', 'Term not found' );
			}
			return array(
				'acf_id' => 'term_' . $id,
				'type'   => 'term',
				'id'     => $id,
				'page'   => '',
				'label'  => (string) $term->name,
				'edit'   => (string) get_edit_term_link( $id, $term->taxonomy ),
			);
		}
		return new \WP_Error( 'presshelm_payload', 'target must be post:<id>, term:<id>, option or option:<page>.' );
	}

	/**
	 * One options page by its menu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return array|null
	 */
	public static function options_page( $slug ) {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return null;
		}
		foreach ( (array) acf_get_options_pages() as $page ) {
			if ( (string) ( $page['menu_slug'] ?? '' ) === (string) $slug ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * The field groups ACF shows for a target, with their fields.
	 *
	 * @param array $t Resolved target.
	 * @return array<int,array{title:string,key:string,fields:array}>
	 */
	public static function groups( array $t ) {
		$filter = array();
		if ( 'post' === $t['type'] ) {
			$filter = array( 'post_id' => $t['id'] );
		} elseif ( 'term' === $t['type'] ) {
			$term   = get_term( $t['id'] );
			$filter = array( 'taxonomy' => $term && ! is_wp_error( $term ) ? $term->taxonomy : '' );
		} else {
			$filter = array( 'options_page' => $t['page'] );
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( $filter ) as $group ) {
			$out[] = array(
				'title'  => (string) ( $group['title'] ?? '' ),
				'key'    => (string) ( $group['key'] ?? '' ),
				'fields' => (array) acf_get_fields( $group ),
			);
		}
		return $out;
	}

	/**
	 * Fields of a target by name and by key.
	 *
	 * @param array $groups Groups.
	 * @return array<string,array>
	 */
	public static function field_map( array $groups ) {
		$map = array();
		foreach ( $groups as $g ) {
			foreach ( $g['fields'] as $f ) {
				if ( '' !== (string) ( $f['name'] ?? '' ) ) {
					$map[ (string) $f['name'] ] = $f;
				}
				$map[ (string) $f['key'] ] = $f;
			}
		}
		return $map;
	}

	/**
	 * A field as the agent sees it (no value).
	 *
	 * @param array $f Field.
	 * @return array
	 */
	private static function describe( array $f ) {
		$out = array(
			'name'  => (string) ( $f['name'] ?? '' ),
			'key'   => (string) ( $f['key'] ?? '' ),
			'label' => (string) ( $f['label'] ?? '' ),
			'type'  => (string) ( $f['type'] ?? '' ),
		);
		if ( ! empty( $f['required'] ) ) {
			$out['required'] = true;
		}
		if ( ! empty( $f['instructions'] ) ) {
			$out['instructions'] = mb_substr( wp_strip_all_tags( (string) $f['instructions'] ), 0, 200 );
		}
		if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
			$out['choices'] = array_slice( $f['choices'], 0, 50, true );
		}
		if ( ! empty( $f['multiple'] ) ) {
			$out['multiple'] = true;
		}
		if ( 'taxonomy' === $out['type'] && ! empty( $f['save_terms'] ) ) {
			$out['read_only'] = 'saves the post\'s terms: change it in wp-admin';
		}
		foreach ( array( 'sub_fields', 'layouts' ) as $k ) {
			if ( ! empty( $f[ $k ] ) && is_array( $f[ $k ] ) ) {
				$out[ $k ] = array();
				foreach ( $f[ $k ] as $sub ) {
					$out[ $k ][] = 'layouts' === $k
						? array(
							'name'       => (string) ( $sub['name'] ?? '' ),
							'label'      => (string) ( $sub['label'] ?? '' ),
							'sub_fields' => array_map( array( self::class, 'describe' ), (array) ( $sub['sub_fields'] ?? array() ) ),
						)
						: self::describe( (array) $sub );
				}
			}
		}
		return $out;
	}

	/**
	 * Reads the fields of a target.
	 *
	 * @param array $p {target}.
	 * @return array|\WP_Error
	 */
	public static function read( array $p ) {
		$t = self::resolve( (string) $p['target'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( 'option' === $t['type'] && '' === $t['page'] ) {
			$pages = array();
			foreach ( function_exists( 'acf_get_options_pages' ) ? (array) acf_get_options_pages() : array() as $page ) {
				$pages[] = array(
					'target' => 'option:' . (string) ( $page['menu_slug'] ?? '' ),
					'title'  => (string) ( $page['page_title'] ?? '' ),
					'parent' => (string) ( $page['parent_slug'] ?? '' ),
				);
			}
			return array(
				'options_pages' => $pages,
				'note'          => $pages ? 'Read one with target option:<slug>.' : 'This site has no ACF options pages.',
			);
		}
		$groups = array();
		foreach ( self::groups( $t ) as $g ) {
			$fields = array();
			foreach ( $g['fields'] as $f ) {
				if ( in_array( (string) ( $f['type'] ?? '' ), self::LAYOUT_TYPES, true ) ) {
					continue;
				}
				$fields[] = self::describe( $f ) + array( 'value' => self::shown( $f, get_field( (string) $f['key'], $t['acf_id'], false ) ) );
			}
			$groups[] = array(
				'title'  => $g['title'],
				'key'    => $g['key'],
				'fields' => $fields,
			);
		}
		$out = array(
			'target'  => (string) $p['target'],
			'label'   => $t['label'],
			'groups'  => $groups,
			'edit_at' => $t['edit'],
		);
		if ( ! $groups ) {
			$out['note'] = 'ACF shows no field groups here (its location rules do not match this ' . $t['type'] . ').';
		}
		return $out;
	}

	/**
	 * A stored value as the agent may see it: secrets masked (password fields, secret-like names,
	 * secrets inside arrays, serialized data or JSON), long text cut.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function shown( array $f, $value ) {
		$name = (string) ( $f['name'] ?? '' );
		if ( 'password' === (string) ( $f['type'] ?? '' ) && '' !== (string) ( is_scalar( $value ) ? $value : 'x' ) ) {
			return DbRules::REDACTED;
		}
		$masked = DbRules::redact_deep( array( '' !== $name ? $name : 'value' => $value ) );
		return self::cut( reset( $masked ) );
	}

	/**
	 * Long text cut everywhere in a value.
	 *
	 * @param mixed $v     Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function cut( $v, $depth = 0 ) {
		if ( is_string( $v ) && strlen( $v ) > 2000 ) {
			return substr( $v, 0, 2000 ) . '… [' . strlen( $v ) . ' bytes]';
		}
		if ( is_array( $v ) && $depth < 10 ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::cut( $x, $depth + 1 );
			}
		}
		return $v;
	}

	/**
	 * Checks one value against its field.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return mixed|\WP_Error The value to write.
	 */
	public static function check( array $f, $value ) {
		$type = (string) ( $f['type'] ?? '' );
		$name = (string) ( $f['name'] ?? $f['key'] );
		if ( in_array( $type, self::LAYOUT_TYPES, true ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' is a layout element without a value.' );
		}
		if ( 'taxonomy' === $type && ! empty( $f['save_terms'] ) ) {
			return new \WP_Error( 'presshelm_acf_terms', $name . ' saves the post\'s terms: change it in wp-admin (or set the terms with the terms tools).' );
		}
		if ( ! empty( $f['required'] ) && ( null === $value || '' === $value || array() === $value ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' is required and cannot be emptied.' );
		}
		if ( null === $value || '' === $value ) {
			return $value;
		}
		// A masked secret copied back from acf.read would overwrite the real one.
		if ( false !== strpos( (string) wp_json_encode( $value ), DbRules::REDACTED ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' contains "' . DbRules::REDACTED . '" (a masked secret from acf.read); leave secret fields out of the change.' );
		}
		if ( in_array( $type, self::TEXT_TYPES, true ) && is_string( $value ) && wp_kses_post( $value ) !== $value ) {
			return new \WP_Error( 'presshelm_kses', $name . ': the text contains scripts or code WordPress would remove; it is not set through PressHelm.' );
		}
		if ( 'email' === $type && ! is_email( (string) $value ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' needs an e-mail address.' );
		}
		if ( 'url' === $type && ! wp_http_validate_url( (string) $value ) && 0 !== strpos( (string) $value, '/' ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' needs a link (https://… or a /path).' );
		}
		if ( in_array( $type, array( 'number', 'range' ), true ) && ! is_numeric( $value ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' needs a number.' );
		}
		if ( in_array( $type, array( 'select', 'radio', 'checkbox', 'button_group' ), true ) && ! empty( $f['choices'] ) && empty( $f['allow_custom'] ) && empty( $f['other_choice'] ) ) {
			foreach ( (array) $value as $v ) {
				if ( ! array_key_exists( (string) $v, (array) $f['choices'] ) ) {
					return new \WP_Error( 'presshelm_payload', $name . ': "' . sanitize_text_field( (string) $v ) . '" is not one of its choices (' . implode( ', ', array_slice( array_map( 'strval', array_keys( (array) $f['choices'] ) ), 0, 20 ) ) . ').' );
				}
			}
		}
		if ( in_array( $type, array( 'image', 'file', 'gallery' ), true ) ) {
			foreach ( (array) $value as $id ) {
				if ( ! is_numeric( $id ) || 'attachment' !== get_post_type( (int) $id ) ) {
					return new \WP_Error( 'presshelm_payload', $name . ' takes media library ids (' . sanitize_text_field( (string) $id ) . ' is not one).' );
				}
			}
		}
		if ( in_array( $type, array( 'post_object', 'relationship', 'page_link' ), true ) ) {
			foreach ( (array) $value as $id ) {
				if ( is_numeric( $id ) && ! get_post( (int) $id ) ) {
					return new \WP_Error( 'presshelm_payload', $name . ': post ' . (int) $id . ' does not exist.' );
				}
			}
		}
		// ACF's own validation (required sub fields, min/max, character limits, custom rules).
		if ( function_exists( 'acf_validate_value' ) && function_exists( 'acf_reset_validation_errors' ) && function_exists( 'acf_get_validation_errors' ) ) {
			acf_reset_validation_errors();
			acf_validate_value( $value, $f, 'acf[' . (string) $f['key'] . ']' );
			$errors = (array) acf_get_validation_errors();
			acf_reset_validation_errors();
			if ( $errors ) {
				$first = (array) reset( $errors );
				return new \WP_Error( 'presshelm_acf_invalid', $name . ': ' . wp_strip_all_tags( (string) ( $first['message'] ?? 'ACF refused this value.' ) ) );
			}
		}
		return $value;
	}

	/**
	 * Whether a value is stored for a field on a post or term.
	 *
	 * @param array  $t    Resolved target.
	 * @param string $name Field name.
	 * @return bool
	 */
	private static function stored( array $t, $name ) {
		return metadata_exists( $t['type'], $t['id'], $name );
	}

	/**
	 * Changes field values of a post or term.
	 *
	 * @param array  $p          {target, values}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function update( array $p, $command_id ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'presshelm_no_acf', 'Advanced Custom Fields is not active on this site.' );
		}
		$t = self::resolve( (string) $p['target'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( 'option' === $t['type'] ) {
			return new \WP_Error( 'presshelm_payload', 'Options pages are changed with acf.options_update (PressHelm Power Tools).' );
		}
		$map    = self::field_map( self::groups( $t ) );
		$writes = array();
		foreach ( (array) $p['values'] as $name => $value ) {
			if ( ! isset( $map[ (string) $name ] ) ) {
				$names = array_values(
					array_filter(
						array_map(
							static function ( $f ) {
								return (string) ( $f['name'] ?? '' );
							},
							$map
						)
					)
				);
				return new \WP_Error( 'presshelm_acf_unknown', 'No ACF field "' . sanitize_text_field( (string) $name ) . '" on this ' . $t['type'] . ( $names ? ' (fields: ' . implode( ', ', array_slice( array_unique( $names ), 0, 40 ) ) . ')' : ' (ACF shows no fields here)' ) . '. Nothing was changed.' );
			}
			$f = $map[ (string) $name ];
			$v = self::check( $f, $value );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( $v->get_error_code(), $v->get_error_message() . ' Nothing was changed.' );
			}
			$writes[ (string) $f['key'] ] = array(
				'field' => $f,
				'value' => $v,
			);
		}

		$before = array(
			'acf_id' => $t['acf_id'],
			'type'   => $t['type'],
			'id'     => $t['id'],
			'fields' => array(),
		);
		foreach ( $writes as $key => $w ) {
			$before['fields'][] = array(
				'key'    => $key,
				'name'   => (string) $w['field']['name'],
				'stored' => self::stored( $t, (string) $w['field']['name'] ),
				'value'  => get_field( $key, $t['acf_id'], false ),
			);
		}
		$journal = Journal::record( $command_id, 'acf', (string) $p['target'], $before );
		foreach ( $writes as $key => $w ) {
			if ( null === $w['value'] || '' === $w['value'] ) {
				delete_field( $key, $t['acf_id'] );
			} elseif ( ! update_field( $key, $w['value'], $t['acf_id'] ) && ! self::same( get_field( $key, $t['acf_id'], false ), $w['value'] ) ) {
				// update_field() is also false when the value was already the same.
				self::restore( $before );
				return new \WP_Error( 'presshelm_acf_save', 'ACF did not save ' . (string) $w['field']['name'] . '; nothing was changed.' );
			}
		}
		$after = array();
		foreach ( $writes as $key => $w ) {
			$after[ (string) $w['field']['name'] ] = self::shown( $w['field'], get_field( $key, $t['acf_id'], false ) );
		}
		return array(
			'target'     => (string) $p['target'],
			'changed'    => array_keys( $after ),
			'values'     => $after,
			'edit_at'    => $t['edit'],
			'journal_id' => $journal,
		);
	}

	/**
	 * Same stored value (ACF keeps numbers as strings).
	 *
	 * @param mixed $a Stored.
	 * @param mixed $b Wanted.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Journal undo: every value back as it was (fields that had none are emptied again).
	 *
	 * @param array $before {acf_id, type, id, fields: [{key, name, stored, value}]}.
	 * @return true|\WP_Error
	 */
	public static function restore( array $before ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'presshelm_no_acf', 'Advanced Custom Fields is not active; activate it, then undo again.' );
		}
		foreach ( array_reverse( (array) ( $before['fields'] ?? array() ) ) as $f ) {
			if ( empty( $f['stored'] ) ) {
				delete_field( (string) $f['key'], $before['acf_id'] );
				// ACF also keeps "_name" => field key next to the value.
				if ( in_array( (string) ( $before['type'] ?? '' ), array( 'post', 'term' ), true ) ) {
					delete_metadata( (string) $before['type'], (int) $before['id'], (string) $f['name'] );
					delete_metadata( (string) $before['type'], (int) $before['id'], '_' . (string) $f['name'] );
				}
			} else {
				update_field( (string) $f['key'], $f['value'], $before['acf_id'] );
			}
		}
		return true;
	}
}
