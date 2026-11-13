<?php
/**
 * Records of another plugin (to-do 21, kernel 3): forms, surveys and similar things a plugin keeps
 * as posts of its own post type with its own meta, when the plugin offers no API for them. A
 * "records" pack (data from PressHelm Cloud) names the post type and the fields (post columns and
 * meta keys); this fixed engine lists, reads, creates, changes and trashes such records. It never
 * touches WordPress's own post types or meta, or post types other plugins use for configuration, code
 * or orders (Pack::CONFIG_TYPES), never runs code named by a pack, refuses code keys and
 * filters every text like other post edits; every change can be undone exactly.
 *
 * @package PressHelm
 */

namespace PressHelm\Packs;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Records engine.
 */
final class Records {

	/** Post columns a record field may be. */
	const COLUMNS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' );

	/** Statuses a record may get. */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/** Meta encodings. */
	const ENCODINGS = array( 'array', 'json', 'raw' );

	/**
	 * Validates the records part of a pack (called by Pack::validate for kind "records").
	 *
	 * @param array $pack Pack.
	 * @return true|string Problem.
	 */
	public static function problems( array $pack ) {
		if ( empty( $pack['records'] ) || ! is_array( $pack['records'] ) ) {
			return 'records';
		}
		foreach ( $pack['records'] as $type => $def ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,30}$/', (string) $type ) || ! is_array( $def ) ) {
				return 'records type';
			}
			$pt = (string) ( $def['post_type'] ?? '' );
			if ( ! Pack::type_ok( $pt ) ) {
				return 'records post_type';
			}
			if ( empty( $def['fields'] ) || ! is_array( $def['fields'] ) ) {
				return 'records fields';
			}
			foreach ( $def['fields'] as $name => $f ) {
				if ( ! preg_match( '/^[a-z][a-z0-9_]{0,40}$/', (string) $name ) || ! is_array( $f ) ) {
					return 'records field name';
				}
				if ( isset( $f['post'] ) ) {
					if ( ! in_array( $f['post'], self::COLUMNS, true ) ) {
						return 'records field column';
					}
				} elseif ( ! Pack::meta_key_ok( $f['meta'] ?? null ) || ! in_array( $f['encoding'] ?? 'raw', self::ENCODINGS, true ) ) {
					return 'records field meta';
				}
			}
		}
		return true;
	}

	/**
	 * The definition of a record type, checked against the site (plugin active, post type registered).
	 *
	 * @param array  $pack Validated pack.
	 * @param string $type Record type.
	 * @return array|\WP_Error
	 */
	private static function def( array $pack, $type ) {
		$def = $pack['records'][ (string) $type ] ?? null;
		if ( ! is_array( $def ) ) {
			return new \WP_Error( 'presshelm_payload', 'Unknown record type for ' . $pack['name'] . ' (types: ' . implode( ', ', array_keys( $pack['records'] ) ) . ').' );
		}
		if ( ! Pack::detected( (array) $pack['detect'] ) || ! post_type_exists( (string) $def['post_type'] ) ) {
			return new \WP_Error( 'presshelm_not_active', $pack['name'] . ' is not active on this site.' );
		}
		return $def;
	}

	/**
	 * A record of that type, or an error.
	 *
	 * @param array $def Type definition.
	 * @param int   $id  Post id.
	 * @return \WP_Post|\WP_Error
	 */
	private static function post( array $def, $id ) {
		$p = get_post( (int) $id );
		if ( ! $p || $p->post_type !== $def['post_type'] ) {
			return new \WP_Error( 'presshelm_not_found', 'No such record (see the list).' );
		}
		return $p;
	}

	/**
	 * Lists records.
	 *
	 * @param array  $pack   Validated pack.
	 * @param string $type   Type.
	 * @param string $search Title search.
	 * @param int    $limit  Max.
	 * @return array|\WP_Error
	 */
	public static function list_records( array $pack, $type, $search = '', $limit = 50 ) {
		$def = self::def( $pack, $type );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$posts = get_posts(
			array(
				'post_type'      => $def['post_type'],
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => max( 1, min( 200, (int) $limit ) ),
				's'              => (string) $search,
				'orderby'        => 'modified',
			)
		);
		return array(
			'type'    => $type,
			'records' => array_map(
				static function ( $p ) use ( $def ) {
					return array(
						'id'       => (int) $p->ID,
						'title'    => $p->post_title,
						'status'   => $p->post_status,
						'modified' => $p->post_modified_gmt,
						'embed'    => isset( $def['embed'] ) ? str_replace( '{id}', (string) $p->ID, (string) $def['embed'] ) : null,
					);
				},
				$posts
			),
		);
	}

	/**
	 * Reads one record (every declared field, decoded).
	 *
	 * @param array  $pack Validated pack.
	 * @param string $type Type.
	 * @param int    $id   Id.
	 * @return array|\WP_Error
	 */
	public static function get( array $pack, $type, $id ) {
		$def = self::def( $pack, $type );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$p = self::post( $def, $id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$fields = array();
		foreach ( $def['fields'] as $name => $f ) {
			$fields[ $name ] = self::read( $f, $p );
		}
		return array(
			'type'   => $type,
			'id'     => (int) $p->ID,
			'fields' => $fields,
			'embed'  => isset( $def['embed'] ) ? str_replace( '{id}', (string) $p->ID, (string) $def['embed'] ) : null,
		);
	}

	/**
	 * A field's value.
	 *
	 * @param array    $f Field.
	 * @param \WP_Post $p Post.
	 * @return mixed
	 */
	private static function read( array $f, \WP_Post $p ) {
		if ( isset( $f['post'] ) ) {
			return $p->{$f['post']};
		}
		$v = get_post_meta( $p->ID, (string) $f['meta'], true );
		if ( 'json' === ( $f['encoding'] ?? 'raw' ) ) {
			$d = is_string( $v ) ? json_decode( $v, true ) : null;
			return null === $d ? $v : $d;
		}
		return $v;
	}

	/**
	 * Creates or changes a record: values replace whole fields, patch changes keys inside an array
	 * or JSON field (dot paths). Journaled for exact undo.
	 *
	 * @param array    $pack       Validated pack.
	 * @param string   $type       Type.
	 * @param int|null $id         Id (null creates).
	 * @param array    $values     Field => new value.
	 * @param array    $patch      Field => {dot.path => value}.
	 * @param string   $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function save( array $pack, $type, $id, array $values, array $patch, $command_id ) {
		$def = self::def( $pack, $type );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$code = isset( $pack['code_keys'] ) ? (string) $pack['code_keys'] : '';
		foreach ( array_merge( array_keys( $values ), array_keys( $patch ) ) as $name ) {
			if ( ! isset( $def['fields'][ $name ] ) ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown field "' . $name . '" (fields: ' . implode( ', ', array_keys( $def['fields'] ) ) . ').' );
			}
		}
		$p = null;
		if ( $id ) {
			$p = self::post( $def, $id );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
		}
		// New values for every touched field.
		$new = array();
		foreach ( $values as $name => $v ) {
			$new[ $name ] = $v;
		}
		foreach ( $patch as $name => $changes ) {
			$f = $def['fields'][ $name ];
			if ( isset( $f['post'] ) || 'raw' === ( $f['encoding'] ?? 'raw' ) || ! is_array( $changes ) ) {
				return new \WP_Error( 'presshelm_payload', 'patch works on array or JSON fields; give "' . $name . '" in values instead.' );
			}
			$cur = array_key_exists( $name, $new ) ? $new[ $name ] : ( $p ? self::read( $f, $p ) : array() );
			$cur = is_array( $cur ) ? $cur : array();
			foreach ( $changes as $path => $v ) {
				$ref = &Pack::at( $cur, (string) $path );
				$ref = $v;
				unset( $ref );
			}
			$new[ $name ] = $cur;
		}
		// Safety, on what changes only (what the plugin itself stored stays byte for byte): no code
		// keys, texts filtered like post edits, only known statuses.
		foreach ( $new as $name => $v ) {
			$f = $def['fields'][ $name ];
			if ( isset( $f['post'] ) && 'post_status' === $f['post'] && ! in_array( (string) $v, self::STATUSES, true ) ) {
				return new \WP_Error( 'presshelm_payload', 'Status must be one of ' . implode( ', ', self::STATUSES ) . '.' );
			}
			$bad          = '';
			$is_form      = isset( $f['html'] ) && 'form' === $f['html'];
			$current      = $p ? self::read( $f, $p ) : null;
			$new[ $name ] = self::guard( $v, $current, $code, '', $bad, $is_form );
			if ( '' !== $bad ) {
				return new \WP_Error( 'presshelm_builder_code', '"' . $bad . '" is code (scripts, custom CSS or HTML attributes); it is not changed through PressHelm. Nothing was changed.' );
			}
		}

		$cols = array();
		$meta = array();
		foreach ( $new as $name => $v ) {
			$f = $def['fields'][ $name ];
			if ( isset( $f['post'] ) ) {
				$cols[ $f['post'] ] = is_scalar( $v ) ? (string) $v : '';
			} else {
				$meta[ (string) $f['meta'] ] = 'json' === ( $f['encoding'] ?? 'raw' ) ? (string) wp_json_encode( $v ) : ( 'array' === $f['encoding'] ? ( is_array( $v ) ? $v : array() ) : ( is_scalar( $v ) ? (string) $v : '' ) );
			}
		}

		if ( ! $p ) {
			$pid = wp_insert_post(
				wp_slash(
					array_merge(
						array(
							'post_type'   => $def['post_type'],
							'post_status' => 'publish',
						),
						$cols
					)
				),
				true
			);
			if ( is_wp_error( $pid ) ) {
				return $pid;
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( (int) $pid, $k, wp_slash( $v ) );
			}
			$journal = Journal::record( $command_id, 'post_created', (string) $pid, array() );
			return self::done( $pack, $type, (int) $pid, $journal, true );
		}

		$before = array(
			'post' => array(),
			'meta' => array(),
		);
		foreach ( array_keys( $cols ) as $c ) {
			$before['post'][ $c ] = $p->{$c};
		}
		foreach ( array_keys( $meta ) as $k ) {
			$before['meta'][ $k ] = metadata_exists( 'post', $p->ID, $k ) ? base64_encode( maybe_serialize( get_post_meta( $p->ID, $k, true ) ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- exact bytes for undo.
		}
		$journal = Journal::record( $command_id, 'record', (string) $p->ID, $before );
		if ( $cols ) {
			$r = wp_update_post( wp_slash( array_merge( array( 'ID' => $p->ID ), $cols ) ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		foreach ( $meta as $k => $v ) {
			update_post_meta( $p->ID, $k, wp_slash( $v ) );
		}
		clean_post_cache( $p->ID );
		return self::done( $pack, $type, (int) $p->ID, $journal, false );
	}

	/**
	 * Result of a save.
	 *
	 * @param array  $pack    Pack.
	 * @param string $type    Type.
	 * @param int    $id      Id.
	 * @param int    $journal Journal id.
	 * @param bool   $created Created.
	 * @return array
	 */
	private static function done( array $pack, $type, $id, $journal, $created ) {
		$def = $pack['records'][ $type ];
		return array(
			'type'       => $type,
			'id'         => $id,
			'created'    => $created,
			'embed'      => isset( $def['embed'] ) ? str_replace( '{id}', (string) $id, (string) $def['embed'] ) : null,
			'journal_id' => $journal,
		);
	}

	/**
	 * Moves a record to the trash (undo restores it).
	 *
	 * @param array  $pack       Validated pack.
	 * @param string $type       Type.
	 * @param int    $id         Id.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function trash( array $pack, $type, $id, $command_id ) {
		$def = self::def( $pack, $type );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$p = self::post( $def, $id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$journal = Journal::record( $command_id, 'record_trashed', (string) $p->ID, array( 'status' => $p->post_status ) );
		if ( ! wp_trash_post( $p->ID ) ) {
			return new \WP_Error( 'presshelm_write_failed', 'The record could not be moved to the trash.' );
		}
		return array(
			'id'         => (int) $p->ID,
			'trashed'    => true,
			'journal_id' => $journal,
		);
	}

	/**
	 * Undo of a change (journal kind "record").
	 *
	 * @param int   $id     Post id.
	 * @param array $before {post, meta}.
	 * @return true|\WP_Error
	 */
	public static function restore( $id, array $before ) {
		if ( ! get_post( (int) $id ) ) {
			return new \WP_Error( 'presshelm_journal_missing', 'That record no longer exists.' );
		}
		if ( ! empty( $before['post'] ) ) {
			$r = wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $id ), (array) $before['post'] ) ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		foreach ( (array) ( $before['meta'] ?? array() ) as $k => $b64 ) {
			if ( null === $b64 ) {
				delete_post_meta( (int) $id, (string) $k );
				continue;
			}
			$raw = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- exact bytes saved for undo.
			$v   = is_serialized( (string) $raw ) ? unserialize( (string) $raw, array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- PressHelm's own saved value, no objects.
			update_post_meta( (int) $id, (string) $k, wp_slash( $v ) );
		}
		clean_post_cache( (int) $id );
		return true;
	}

	/**
	 * A new value with every changed part checked: a changed (non-empty) value under a code key is
	 * refused ($bad names it), changed texts are filtered; unchanged parts are kept as stored.
	 *
	 * @param mixed  $v    New value.
	 * @param mixed  $cur  Current value (null when there is none).
	 * @param string $code Pack's code-key pattern.
	 * @param string $key  Key of this value ('' at the top).
	 * @param string $bad  First refused code key (out).
	 * @param bool   $form Texts are form HTML (inputs allowed).
	 * @return mixed
	 */
	private static function guard( $v, $cur, $code, $key, &$bad, $form = false ) {
		if ( $v === $cur ) {
			return $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::guard( $x, is_array( $cur ) && array_key_exists( $k, $cur ) ? $cur[ $k ] : null, $code, (string) $k, $bad, $form );
				if ( '' !== $bad ) {
					return $v;
				}
			}
			return $v;
		}
		$is_code = '' !== $key && ! is_numeric( $key ) && ( preg_match( Pack::ALWAYS_CODE, $key ) || ( '' !== $code && preg_match( $code, $key ) ) );
		if ( $is_code && '' !== $v && null !== $v ) {
			$bad = $key;
			return $v;
		}
		return $form && is_string( $v ) ? wp_kses( $v, self::form_html() ) : self::clean( $v );
	}

	/**
	 * Post HTML plus form elements with harmless attributes (never event handlers).
	 *
	 * @return array
	 */
	private static function form_html() {
		$allowed             = wp_kses_allowed_html( 'post' );
		$common              = array(
			'id'           => true,
			'class'        => true,
			'name'         => true,
			'value'        => true,
			'placeholder'  => true,
			'required'     => true,
			'disabled'     => true,
			'readonly'     => true,
			'autocomplete' => true,
			'aria-label'   => true,
			'title'        => true,
		);
		$allowed['form']     = array_merge( $allowed['form'] ?? array(), $common, array( 'method' => true ) );
		$allowed['input']    = array_merge(
			$common,
			array(
				'type'      => true,
				'checked'   => true,
				'min'       => true,
				'max'       => true,
				'step'      => true,
				'pattern'   => true,
				'maxlength' => true,
				'minlength' => true,
				'size'      => true,
				'multiple'  => true,
			)
		);
		$allowed['select']   = array_merge( $common, array( 'multiple' => true ) );
		$allowed['option']   = array(
			'value'    => true,
			'selected' => true,
			'disabled' => true,
		);
		$allowed['optgroup'] = array( 'label' => true );
		$allowed['textarea'] = array_merge(
			$common,
			array(
				'rows'      => true,
				'cols'      => true,
				'maxlength' => true,
			)
		);
		$allowed['label']    = array_merge( $allowed['label'] ?? array(), array( 'for' => true ) );
		$allowed['button']   = array_merge( $allowed['button'] ?? array(), $common, array( 'type' => true ) );
		$allowed['fieldset'] = array_merge( $allowed['fieldset'] ?? array(), array( 'class' => true ) );
		$allowed['legend']   = array_merge( $allowed['legend'] ?? array(), array( 'class' => true ) );
		return $allowed;
	}

	/**
	 * Texts filtered like post edits (map_deep callback).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function clean( $v ) {
		return is_string( $v ) ? wp_kses_post( $v ) : $v;
	}
}
