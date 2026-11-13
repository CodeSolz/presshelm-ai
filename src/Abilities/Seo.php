<?php
/**
 * SEO fields through Yoast SEO's own API (version-aware), not raw custom fields:
 * - which fields exist comes from Yoast itself (`WPSEO_Meta::get_meta_field_defs()` for the post
 *   type and the site's Yoast settings, e.g. Open Graph only when it is switched on);
 * - every value is validated before writing, written with `WPSEO_Meta::set_value()` and journaled;
 * - Yoast's index for the post is rebuilt with Yoast's own watcher, and the result is verified
 *   through Yoast's Surfaces API (what Yoast prints on the page). A mismatch undoes the change.
 * Fields Yoast does not offer here are reported as unsupported, never guessed.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO.
 */
final class Seo {

	/** Oldest Yoast with the Surfaces API (`YoastSEO()->meta`). */
	const MIN_YOAST = '14.0';

	/**
	 * Fields PressHelm can set: name => [Yoast tab, Yoast key, Surfaces property, kind].
	 */
	const FIELDS = array(
		'title'               => array( 'general', 'title', 'title', 'text' ),
		'description'         => array( 'general', 'metadesc', 'meta_description', 'text' ),
		'canonical'           => array( 'advanced', 'canonical', 'canonical', 'url' ),
		'og_title'            => array( 'social', 'opengraph-title', 'open_graph_title', 'text' ),
		'og_description'      => array( 'social', 'opengraph-description', 'open_graph_description', 'text' ),
		'og_image_id'         => array( 'social', 'opengraph-image-id', 'open_graph_images', 'image' ),
		'schema_page_type'    => array( 'schema', 'schema_page_type', 'schema_page_type', 'page_type' ),
		'schema_article_type' => array( 'schema', 'schema_article_type', null, 'article_type' ),
	);

	const MAX_TEXT = 600;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$fields = array();
		foreach ( array_keys( self::FIELDS ) as $name ) {
			$fields[ $name ] = 'og_image_id' === $name ? array(
				'type'    => 'integer',
				'minimum' => 0,
			) : array(
				'type'      => 'string',
				'maxLength' => self::MAX_TEXT,
			);
		}
		return array(
			'seo.yoast.inspect' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'inspect' ),
				'label'       => 'Read Yoast SEO fields',
				'description' => 'Which Yoast SEO fields this post can have (from Yoast itself), their stored values and what Yoast actually outputs for the page.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
			'seo.yoast.update'  => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'update' ),
				'label'       => 'Change Yoast SEO fields',
				'description' => 'Sets Yoast SEO fields of one post (empty string = back to Yoast\'s default), through Yoast\'s API, verified against what Yoast outputs.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => $id,
						'fields' => array(
							'type'                 => 'object',
							'properties'           => $fields,
							'additionalProperties' => false,
							'minProperties'        => 1,
						),
					),
					'required'             => array( 'id', 'fields' ),
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
	 * Whether Yoast is usable here, and why not.
	 *
	 * @return true|\WP_Error
	 */
	public static function available() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return new \WP_Error( 'presshelm_no_yoast', 'Yoast SEO is not active on this site.' );
		}
		if ( version_compare( (string) WPSEO_VERSION, self::MIN_YOAST, '<' ) ) {
			return new \WP_Error( 'presshelm_yoast_version', 'Yoast SEO ' . WPSEO_VERSION . ' is too old: PressHelm needs ' . self::MIN_YOAST . ' or newer (for verified changes). Update Yoast first.' );
		}
		if ( ! class_exists( '\WPSEO_Meta' ) || ! method_exists( '\WPSEO_Meta', 'set_value' ) || ! method_exists( '\WPSEO_Meta', 'get_meta_field_defs' ) || ! function_exists( 'YoastSEO' ) ) {
			return new \WP_Error( 'presshelm_yoast_api', 'This Yoast SEO version does not offer the API PressHelm uses (WPSEO_Meta / YoastSEO()).' );
		}
		return true;
	}

	/**
	 * Fields Yoast offers for this post: name => Yoast key; and the rest with the reason.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{fields:array<string,string>,unsupported:array<string,string>}
	 */
	public static function fields_for( $target ) {
		$fields      = array();
		$unsupported = array();
		$current     = $GLOBALS['post'] ?? null;
		// Yoast's field definitions read the global post, as on the editor screen.
		$GLOBALS['post'] = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below; this is the context Yoast's own editor sets.
		$type            = $target->post_type;
		$advanced_ok     = ! ( class_exists( '\WPSEO_Capability_Utils' ) && class_exists( '\WPSEO_Options' ) && ! \WPSEO_Capability_Utils::current_user_can( 'wpseo_edit_advanced_metadata' ) && \WPSEO_Options::get( 'disableadvanced_meta' ) );
		try {
			foreach ( self::FIELDS as $name => $def ) {
				list( $tab, $key ) = $def;
				$lookup            = 'og_image_id' === $name ? 'opengraph-image' : $key;
				$defs              = (array) \WPSEO_Meta::get_meta_field_defs( $tab, $type );
				if ( isset( $defs[ $lookup ] ) ) {
					$fields[ $name ] = $key;
				} elseif ( 'social' === $tab ) {
					$unsupported[ $name ] = 'Open Graph is switched off in Yoast SEO (Settings → Site features → Open Graph data).';
				} elseif ( ! $advanced_ok && ( 'advanced' === $tab || 'schema' === $tab ) ) {
					$unsupported[ $name ] = 'Yoast SEO only lets users with its "advanced SEO data" permission change this (Yoast SEO → Settings → Advanced).';
				} else {
					$unsupported[ $name ] = 'Yoast SEO does not offer this field for ' . $type . ' posts.';
				}
			}
		} finally {
			$GLOBALS['post'] = $current; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the previous global.
		}
		return array(
			'fields'      => $fields,
			'unsupported' => $unsupported,
		);
	}

	/**
	 * The values Yoast outputs for the post (Surfaces API), after rebuilding its index.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	public static function effective( $post_id ) {
		self::sync( $post_id );
		$meta = \YoastSEO()->meta->for_post( (int) $post_id );
		$out  = array();
		if ( ! $meta ) {
			return $out;
		}
		foreach ( self::FIELDS as $name => $def ) {
			$prop = $def[2];
			if ( null === $prop ) {
				// Not a page output value: what Yoast stored on its index entry for the post.
				// Yoast's models read columns through __get (isset() says false), so read directly.
				$out[ $name ] = is_object( $meta->indexable ) ? $meta->indexable->{$name} : null;
				continue;
			}
			$value = $meta->{$prop};
			if ( 'og_image_id' === $name ) {
				$first = is_array( $value ) ? reset( $value ) : null;
				$value = is_array( $first ) ? (int) ( $first['id'] ?? 0 ) : 0;
			} elseif ( 'schema_page_type' === $name ) {
				$value = array_values( array_filter( array_map( 'strval', (array) $value ) ) ); // Yoast lists WebPage plus the chosen type.
			}
			$out[ $name ] = is_scalar( $value ) || null === $value || is_array( $value ) ? $value : null;
		}
		return $out;
	}

	/**
	 * Rebuilds Yoast's index for one post with Yoast's own watcher (it otherwise waits for the end
	 * of the request).
	 *
	 * @param int $post_id Post id.
	 */
	private static function sync( $post_id ) {
		$watcher = 'Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher'; // Service ids are written without a leading namespace separator.
		if ( ! class_exists( $watcher ) || ! isset( \YoastSEO()->classes ) ) {
			return;
		}
		try {
			$w = \YoastSEO()->classes->get( $watcher );
			if ( $w && method_exists( $w, 'build_indexable' ) ) {
				$w->build_indexable( (int) $post_id );
			}
			// Yoast keeps a page's output data for the rest of the request; read the rebuilt one.
			$memo = 'Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer';
			if ( class_exists( $memo ) ) {
				$m = \YoastSEO()->classes->get( $memo );
				if ( $m && method_exists( $m, 'clear' ) ) {
					$m->clear();
				}
			}
		} catch ( \Throwable $e ) {
			// A Yoast version without this service: verification then compares with the stale index
			// and reports a mismatch instead of claiming success.
			unset( $e );
		}
	}

	/**
	 * Fields, stored values and Yoast's output for one post.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function inspect( array $p ) {
		$ok = self::available();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$f      = self::fields_for( $post );
		$stored = array();
		foreach ( $f['fields'] as $name => $key ) {
			$stored[ $name ] = (string) \WPSEO_Meta::get_value( $key, $post->ID );
		}
		return array(
			'id'            => $post->ID,
			'yoast_version' => WPSEO_VERSION,
			'fields'        => array_keys( $f['fields'] ),
			'unsupported'   => $f['unsupported'],
			'stored'        => $stored,
			'output'        => self::effective( $post->ID ),
			'options'       => self::type_options( $f['fields'] ),
		);
	}

	/**
	 * Allowed schema types (from Yoast).
	 *
	 * @param array $fields Available fields.
	 * @return array
	 */
	private static function type_options( array $fields ) {
		$cls = '\Yoast\WP\SEO\Config\Schema_Types';
		if ( ! class_exists( $cls ) ) {
			return array();
		}
		$out = array();
		if ( isset( $fields['schema_page_type'] ) && defined( $cls . '::PAGE_TYPES' ) ) {
			$out['schema_page_type'] = array_keys( $cls::PAGE_TYPES );
		}
		if ( isset( $fields['schema_article_type'] ) && defined( $cls . '::ARTICLE_TYPES' ) ) {
			$out['schema_article_type'] = array_keys( $cls::ARTICLE_TYPES );
		}
		return $out;
	}

	/**
	 * Validates and normalises one value (empty string = clear).
	 *
	 * @param string $name    Field.
	 * @param mixed  $value   Input.
	 * @param array  $options Allowed schema types.
	 * @return string|\WP_Error Value to store.
	 */
	public static function validate( $name, $value, array $options ) {
		$kind = self::FIELDS[ $name ][3];
		if ( 'image' === $kind ) {
			$img = (int) $value;
			if ( 0 === $img ) {
				return '';
			}
			if ( ! wp_attachment_is_image( $img ) ) {
				return new \WP_Error( 'presshelm_payload', 'og_image_id must be an image in the media library (or 0 to clear).' );
			}
			return (string) $img;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 'text' === $kind ) {
			$clean = sanitize_text_field( $value );
			if ( $clean !== $value ) {
				return new \WP_Error( 'presshelm_payload', $name . ' must be plain text (no HTML, line breaks or extra spaces).' );
			}
			return $clean;
		}
		if ( 'url' === $kind ) {
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			if ( '' === $url || $url !== $value || ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'presshelm_payload', 'canonical must be a full http(s) URL.' );
			}
			return $url;
		}
		$allowed = $options[ $name ] ?? array();
		if ( ! in_array( $value, $allowed, true ) ) {
			return new \WP_Error( 'presshelm_payload', $name . ' must be one of Yoast\'s types: ' . implode( ', ', $allowed ) . '.' );
		}
		return $value;
	}

	/**
	 * Sets Yoast fields of one post, journaled and verified.
	 *
	 * @param array  $p          {id, fields}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function update( array $p, $command_id ) {
		$ok = self::available();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$f       = self::fields_for( $post );
		$options = self::type_options( $f['fields'] );
		$writes  = array();
		foreach ( (array) $p['fields'] as $name => $value ) {
			if ( ! isset( self::FIELDS[ $name ] ) ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown field ' . $name );
			}
			if ( ! isset( $f['fields'][ $name ] ) ) {
				return new \WP_Error( 'presshelm_seo_unsupported', $name . ': ' . $f['unsupported'][ $name ] . ' Nothing was changed.' );
			}
			$v = self::validate( $name, $value, $options );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$writes[ $f['fields'][ $name ] ] = $v;
			if ( 'og_image_id' === $name ) {
				$writes['opengraph-image'] = '' === $v ? '' : (string) wp_get_attachment_url( (int) $v );
			}
		}

		$before = array();
		foreach ( array_keys( $writes ) as $key ) {
			$meta_key            = \WPSEO_Meta::$meta_prefix . $key;
			$before[ $meta_key ] = array(
				'exists' => metadata_exists( 'post', $post->ID, $meta_key ),
				'value'  => get_post_meta( $post->ID, $meta_key, true ),
			);
		}
		$journal = Journal::record( $command_id, 'post_meta', (string) $post->ID, $before );
		foreach ( $writes as $key => $value ) {
			if ( '' === $value ) {
				\WPSEO_Meta::delete( $key, $post->ID ); // Back to Yoast's default.
			} else {
				\WPSEO_Meta::set_value( $key, $value, $post->ID );
			}
		}

		// Verify against what Yoast outputs; anything that did not take effect undoes the change.
		$output   = self::effective( $post->ID );
		$mismatch = array();
		foreach ( (array) $p['fields'] as $name => $value ) {
			$want = $writes[ $f['fields'][ $name ] ];
			if ( '' === $want || false !== strpos( $want, '%%' ) ) {
				continue; // A reset to Yoast's default, or Yoast variables to be filled in.
			}
			$got = $output[ $name ] ?? null;
			$hit = is_array( $got ) ? in_array( $want, $got, true ) : (string) $got === $want; // Page type: Yoast adds "WebPage".
			if ( ! $hit ) {
				$mismatch[ $name ] = array(
					'set'    => $want,
					'output' => $output[ $name ] ?? null,
				);
			}
		}
		if ( $mismatch ) {
			Journal::restore( $journal );
			// Check the undo too: something that rewrites these values may rewrite the old ones as well.
			$not_back = array();
			foreach ( $before as $meta_key => $state ) {
				$now = get_post_meta( $post->ID, $meta_key, true );
				if ( ( $state['exists'] ? (string) $state['value'] : '' ) !== (string) $now ) {
					$not_back[ substr( $meta_key, strlen( \WPSEO_Meta::$meta_prefix ) ) ] = $now;
				}
			}
			$msg  = 'Yoast SEO does not output what was set (' . wp_json_encode( $mismatch ) . '). ';
			$msg .= $not_back
				? 'PressHelm tried to put the previous values back, but the site now has ' . wp_json_encode( $not_back ) . ': something else on the site (likely another plugin) rewrites these fields. Tell the owner; do not retry.'
				: 'The change was undone; the previous values are back.';
			return new \WP_Error( 'presshelm_seo_not_applied', $msg );
		}
		return array(
			'id'         => $post->ID,
			'changed'    => array_keys( (array) $p['fields'] ),
			'output'     => $output,
			'journal_id' => $journal,
		);
	}
}
