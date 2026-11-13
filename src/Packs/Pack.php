<?php
/**
 * Integration packs (to-do 21): what PressHelm knows about another plugin arrives from PressHelm
 * Cloud as data in a signed command, and this fixed kernel carries it out. A pack is never code:
 * nothing here includes, evaluates or calls a PHP function or method named by a pack. Detection
 * only checks whether a constant, class or function exists. Safety (approvals, undo, the code
 * deny-list, the write scope) is decided here; a pack can only narrow it.
 *
 * @package PressHelm
 */

namespace PressHelm\Packs;

defined( 'ABSPATH' ) || exit;

/**
 * Pack validation and shared helpers.
 */
final class Pack {

	/**
	 * The pack format this kernel understands (reported in the heartbeat). 2: linked-post surfaces,
	 * attribute boundaries, marked wrappers, whitespace around text, parent links, allowed ops.
	 * 3: records packs (another plugin's own post types and meta: Packs\Records).
	 */
	const KERNEL_API = 3;

	/** Post types a linked surface may never point at (WordPress's own). */
	const CORE_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' );

	/**
	 * Post types other plugins use for configuration, code or orders: never a pack's records or linked
	 * surface (custom fields setup, code snippets, custom code and fonts, shop orders and coupons,
	 * scheduled actions). Those stay with the owner in wp-admin.
	 */
	const CONFIG_TYPES = array( 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcode', 'custom-css-js', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon', 'shop_subscription', 'scheduled-action', 'wpforms-log', 'flamingo_inbound', 'flamingo_contact' );

	/**
	 * Whether a pack may name this post type (never WordPress's own, never configuration or code).
	 *
	 * @param mixed $t Post type.
	 * @return bool
	 */
	public static function type_ok( $t ) {
		return is_string( $t ) && preg_match( '/^[a-z0-9_-]{1,20}$/', $t ) && ! in_array( $t, self::CORE_TYPES, true ) && ! in_array( $t, self::CONFIG_TYPES, true ) && 0 !== strpos( $t, 'wp_' );
	}

	/** Post columns a pack may read and write. */
	const POST_COLUMNS = array( 'post_content', 'post_content_filtered', 'post_excerpt' );

	/** Encodings of a stored value. */
	const ENCODINGS = array( 'raw', 'json', 'urlencoded_json', 'base64_json', 'php_serialized' );

	/** Keys that are always code, whatever a pack says. */
	const ALWAYS_CODE = '/(^|_)(custom_?css|custom_?js|custom_?html|script|javascript|onclick|on[a-z]+event)$/i';

	/**
	 * Validates a pack; returns the pack (normalized) or an error naming the problem.
	 *
	 * @param mixed $pack Pack from the command.
	 * @return array|\WP_Error
	 */
	public static function validate( $pack ) {
		if ( ! is_array( $pack ) ) {
			return self::bad( 'not an object' );
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{1,60}$/', (string) ( $pack['id'] ?? '' ) ) ) {
			return self::bad( 'id' );
		}
		if ( (int) ( $pack['kernel_api'] ?? 0 ) > self::KERNEL_API ) {
			return new \WP_Error( 'presshelm_pack_newer', 'This needs a newer PressHelm plugin on the site (pack format ' . (int) $pack['kernel_api'] . '). Update PressHelm in WordPress → Plugins.' );
		}
		if ( 'records' === ( $pack['kind'] ?? '' ) ) {
			if ( ! isset( $pack['detect'] ) || ! is_array( $pack['detect'] ) ) {
				return self::bad( 'detect' );
			}
			$e = Records::problems( $pack );
			if ( true !== $e ) {
				return self::bad( $e );
			}
			if ( isset( $pack['code_keys'] ) && ( ! is_string( $pack['code_keys'] ) || ! self::valid_regex( $pack['code_keys'] ) ) ) {
				return self::bad( 'code_keys (pattern)' );
			}
			if ( ! isset( $pack['name'] ) || ! is_string( $pack['name'] ) ) {
				return self::bad( 'name' );
			}
			return $pack;
		}
		if ( 'document' !== ( $pack['kind'] ?? '' ) ) {
			return self::bad( 'kind' );
		}
		foreach ( array( 'detect', 'owns' ) as $k ) {
			if ( ! isset( $pack[ $k ] ) || ! is_array( $pack[ $k ] ) ) {
				return self::bad( $k );
			}
		}
		$src = $pack['source'] ?? null;
		if ( ! is_array( $src ) || ! in_array( $src['type'] ?? '', array( 'surface', 'ability' ), true ) ) {
			return self::bad( 'source.type' );
		}
		if ( 'surface' === $src['type'] ) {
			foreach ( array_merge( array( $src['model'] ?? null ), isset( $src['html'] ) ? array( $src['html'] ) : array() ) as $s ) {
				$e = self::check_surface( is_array( $s ) ? ( $s['surface'] ?? null ) : null );
				if ( $e ) {
					return $e;
				}
			}
			if ( ! in_array( $src['model']['encoding'] ?? '', self::ENCODINGS, true ) ) {
				return self::bad( 'source.model.encoding' );
			}
			foreach ( (array) ( $src['refs'] ?? array() ) as $ref ) {
				$e = self::check_surface( is_array( $ref ) ? ( $ref['surface'] ?? null ) : null );
				if ( $e ) {
					return $e;
				}
			}
			$tree = $src['tree'] ?? null;
			if ( ! is_array( $tree ) || ( ! isset( $tree['nested'] ) && ! isset( $tree['flat'] ) ) ) {
				return self::bad( 'source.tree' );
			}
			foreach ( (array) ( $tree['links'] ?? array() ) as $k => $v ) {
				if ( ! in_array( $k, array( 'parent_key', 'index_key', 'top_parent' ), true ) || ! is_string( $v ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,60}$/', $v ) ) {
					return self::bad( 'source.tree.links' );
				}
			}
			foreach ( array_merge( (array) ( $src['html']['element'] ?? array() ), (array) ( $src['html']['wrapper_marks'] ?? array() ) ) as $tpl ) {
				if ( ! is_string( $tpl ) || false === strpos( $tpl, '{id}' ) || ( false !== strpos( $tpl, '=' ) && ! preg_match( '/^[a-z][a-z0-9-]*="[^"<>]*\{id\}[^"<>]*"$/', $tpl ) ) ) {
					return self::bad( 'source.html (element or wrapper mark)' );
				}
			}
		} else {
			foreach ( (array) ( $src['undo'] ?? array() ) as $u ) {
				$e = self::check_surface( $u );
				if ( $e ) {
					return $e;
				}
			}
			foreach ( (array) ( $pack['prepare'] ?? array() ) as $fx ) {
				if ( ! Effects::known( is_array( $fx ) ? (string) ( $fx['name'] ?? '' ) : (string) $fx ) ) {
					return self::bad( 'prepare effect' );
				}
			}
			foreach ( array( 'read', 'update' ) as $k ) {
				if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', (string) ( $src[ $k ]['ability'] ?? '' ) ) ) {
					return self::bad( 'source.' . $k . '.ability' );
				}
			}
		}
		foreach ( array( 'code_keys', 'locked_keys', 'url_keys', 'code_types' ) as $k ) {
			if ( isset( $pack[ $k ] ) && ( ! is_string( $pack[ $k ] ) || ! self::valid_regex( $pack[ $k ] ) ) ) {
				return self::bad( $k . ' (pattern)' );
			}
		}
		if ( isset( $pack['ops'] ) && ( ! is_array( $pack['ops'] ) || array_diff( $pack['ops'], array( 'insert', 'remove', 'move', 'duplicate' ) ) ) ) {
			return self::bad( 'ops' );
		}
		foreach ( (array) ( $pack['effects'] ?? array() ) as $fx ) {
			if ( ! Effects::known( is_array( $fx ) ? (string) ( $fx['name'] ?? '' ) : (string) $fx ) ) {
				return self::bad( 'effect ' . ( is_string( $fx ) ? $fx : '' ) );
			}
		}
		return $pack;
	}

	/**
	 * Whether a pattern from a pack compiles (checked, never trusted).
	 *
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function valid_regex( $pattern ) {
		set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- only while a pack's pattern is compiled.
		$ok = false !== preg_match( $pattern, '' );
		restore_error_handler();
		return $ok;
	}

	/**
	 * A surface reference: {post: column}, {meta: key}, or {linked: {meta, key, post_types}, post: column}
	 * (a column of the builder's own post whose id the page keeps in a meta array).
	 *
	 * @param mixed $s Surface.
	 * @return \WP_Error|null
	 */
	private static function check_surface( $s ) {
		if ( ! is_array( $s ) ) {
			return self::bad( 'surface' );
		}
		if ( isset( $s['linked'] ) ) {
			$l = $s['linked'];
			if ( ! is_array( $l ) || ! self::meta_ok( $l['meta'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,60}$/', (string) ( $l['key'] ?? '' ) ) || empty( $l['post_types'] ) || ! is_array( $l['post_types'] ) ) {
				return self::bad( 'surface linked' );
			}
			foreach ( $l['post_types'] as $t ) {
				if ( ! self::type_ok( $t ) ) {
					return self::bad( 'surface linked post type' );
				}
			}
			return in_array( $s['post'] ?? '', self::POST_COLUMNS, true ) ? null : self::bad( 'surface post column' );
		}
		if ( isset( $s['post'] ) ) {
			return in_array( $s['post'], self::POST_COLUMNS, true ) ? null : self::bad( 'surface post column' );
		}
		return self::meta_ok( $s['meta'] ?? null ) ? null : self::bad( 'surface meta key' );
	}

	/**
	 * Whether a meta key may be a surface (never WordPress's or PressHelm's own).
	 *
	 * @param mixed $k Key.
	 * @return bool
	 */
	public static function meta_key_ok( $k ) {
		return self::meta_ok( $k );
	}

	/**
	 * Whether a meta key may be a surface (never WordPress's or PressHelm's own).
	 *
	 * @param mixed $k Key.
	 * @return bool
	 */
	private static function meta_ok( $k ) {
		return is_string( $k ) && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $k ) && 0 !== strpos( $k, '_wp_' ) && 0 !== strpos( $k, 'presshelm' ) && 0 !== strpos( $k, '_presshelm' ) && '_edit_lock' !== $k;
	}

	/**
	 * The builder's own post a linked surface points at (only of the pack's post types), or 0.
	 *
	 * @param array $s       Surface.
	 * @param int   $post_id Page id.
	 * @return int
	 */
	public static function linked_id( array $s, $post_id ) {
		$m  = get_post_meta( (int) $post_id, (string) $s['linked']['meta'], true );
		$id = is_array( $m ) ? (int) ( $m[ (string) $s['linked']['key'] ] ?? 0 ) : 0;
		$p  = $id > 0 ? get_post( $id ) : null;
		return $p && in_array( $p->post_type, (array) $s['linked']['post_types'], true ) ? $id : 0;
	}

	/**
	 * Validation error.
	 *
	 * @param string $what What is wrong.
	 * @return \WP_Error
	 */
	private static function bad( $what ) {
		return new \WP_Error( 'presshelm_pack_invalid', 'The integration pack is not valid (' . $what . '). Nothing was changed.' );
	}

	/**
	 * Whether the plugin a pack is for is active (constants, classes, functions: existence only).
	 *
	 * @param array $rule {constant|class|function|ability} or {any: [...]} / {all: [...]}.
	 * @return bool
	 */
	public static function detected( array $rule ) {
		if ( isset( $rule['any'] ) ) {
			foreach ( (array) $rule['any'] as $r ) {
				if ( is_array( $r ) && self::detected( $r ) ) {
					return true;
				}
			}
			return false;
		}
		if ( isset( $rule['all'] ) ) {
			foreach ( (array) $rule['all'] as $r ) {
				if ( ! is_array( $r ) || ! self::detected( $r ) ) {
					return false;
				}
			}
			return true;
		}
		if ( isset( $rule['constant'] ) ) {
			return defined( (string) $rule['constant'] );
		}
		if ( isset( $rule['class'] ) ) {
			return class_exists( (string) $rule['class'], false ) || class_exists( (string) $rule['class'] );
		}
		if ( isset( $rule['function'] ) ) {
			return function_exists( (string) $rule['function'] );
		}
		if ( isset( $rule['ability'] ) ) {
			return function_exists( 'wp_get_ability' ) && null !== wp_get_ability( (string) $rule['ability'] );
		}
		return false;
	}

	/**
	 * Whether a post belongs to the plugin (its own markers).
	 *
	 * @param array $rule {meta: key} | {meta_value: [key, value]} | {content_contains: text} | {any|all: [...]}.
	 * @param int   $post_id Post id.
	 * @return bool
	 */
	public static function owns( array $rule, $post_id ) {
		if ( isset( $rule['any'] ) || isset( $rule['all'] ) ) {
			$all  = isset( $rule['all'] );
			$list = (array) ( $all ? $rule['all'] : $rule['any'] );
			foreach ( $list as $r ) {
				$hit = is_array( $r ) && self::owns( $r, $post_id );
				if ( $all && ! $hit ) {
					return false;
				}
				if ( ! $all && $hit ) {
					return true;
				}
			}
			return $all && $list;
		}
		if ( isset( $rule['meta'] ) ) {
			$v = get_post_meta( (int) $post_id, (string) $rule['meta'], true );
			return '' !== $v && false !== $v && null !== $v && array() !== $v;
		}
		if ( isset( $rule['content_contains'] ) ) {
			return false !== strpos( (string) get_post_field( 'post_content', (int) $post_id ), (string) $rule['content_contains'] );
		}
		return false;
	}

	/**
	 * Reads a surface's stored value (exactly as stored).
	 *
	 * @param array $s       Surface.
	 * @param int   $post_id Post id.
	 * @return mixed
	 */
	public static function read( array $s, $post_id ) {
		if ( isset( $s['linked'] ) ) {
			$id = self::linked_id( $s, $post_id );
			return $id ? (string) get_post_field( (string) $s['post'], $id, 'raw' ) : '';
		}
		if ( isset( $s['post'] ) ) {
			return (string) get_post_field( (string) $s['post'], (int) $post_id, 'raw' );
		}
		return get_post_meta( (int) $post_id, (string) $s['meta'], true );
	}

	/**
	 * Writes a surface's value exactly (post columns without filters, as builders' own saves do).
	 *
	 * @param array $s       Surface.
	 * @param int   $post_id Post id.
	 * @param mixed $value   Value ('' or null deletes a meta value).
	 * @return true|\WP_Error
	 */
	public static function write( array $s, $post_id, $value ) {
		if ( isset( $s['linked'] ) ) {
			$id = self::linked_id( $s, $post_id );
			if ( ! $id ) {
				return new \WP_Error( 'presshelm_builder_html', 'The builder\'s data for this page is missing; open and save the page once in the builder\'s editor.' );
			}
			$post_id = $id;
		}
		if ( isset( $s['post'] ) ) {
			global $wpdb;
			if ( false === $wpdb->update( $wpdb->posts, array( (string) $s['post'] => (string) $value ), array( 'ID' => (int) $post_id ), array( '%s' ), array( '%d' ) ) ) {
				return new \WP_Error( 'presshelm_db', 'The page could not be saved' );
			}
			clean_post_cache( (int) $post_id );
			return true;
		}
		if ( null === $value || '' === $value ) {
			delete_post_meta( (int) $post_id, (string) $s['meta'] );
		} else {
			update_post_meta( (int) $post_id, (string) $s['meta'], wp_slash( $value ) );
		}
		return true;
	}

	/**
	 * Decodes a stored value.
	 *
	 * @param mixed  $v        Stored value.
	 * @param string $encoding Encoding.
	 * @return mixed|null Null when it cannot be decoded.
	 */
	public static function decode( $v, $encoding ) {
		switch ( $encoding ) {
			case 'json':
				return is_string( $v ) ? json_decode( $v, true ) : null;
			case 'urlencoded_json':
				return is_string( $v ) ? json_decode( rawurldecode( $v ), true ) : null;
			case 'base64_json':
				return is_string( $v ) ? json_decode( (string) base64_decode( $v, true ), true ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a builder's stored data format.
			case 'php_serialized':
				return is_array( $v ) ? $v : ( is_string( $v ) && is_serialized( $v ) ? unserialize( $v, array( 'allowed_classes' => array( 'stdClass' ) ) ) : null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- stdClass only, as the builder stores it.
			default:
				return $v;
		}
	}

	/**
	 * Encodes a value the way it is stored.
	 *
	 * @param mixed  $v        Value.
	 * @param string $encoding Encoding.
	 * @return mixed|null Null when it cannot be encoded.
	 */
	public static function encode( $v, $encoding ) {
		switch ( $encoding ) {
			case 'json':
				$j = wp_json_encode( $v );
				return false === $j ? null : $j;
			case 'urlencoded_json':
				$j = wp_json_encode( $v );
				return false === $j ? null : rawurlencode( $j );
			case 'base64_json':
				$j = wp_json_encode( $v );
				return false === $j ? null : base64_encode( $j ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a builder's stored data format.
			default:
				return $v;
		}
	}

	/**
	 * A value at a dot path of an array, by reference (created when missing).
	 *
	 * @param array  $data Data.
	 * @param string $path Dot path ('' = the data itself).
	 * @return mixed Reference.
	 */
	public static function &at( array &$data, $path ) {
		$ref = &$data;
		foreach ( '' === (string) $path ? array() : explode( '.', (string) $path ) as $p ) {
			if ( ! is_array( $ref ) ) {
				$ref = array();
			}
			if ( ! isset( $ref[ $p ] ) ) {
				$ref[ $p ] = array();
			}
			$ref = &$ref[ $p ];
		}
		return $ref;
	}

	/**
	 * A fresh element id in a builder's format.
	 *
	 * @param string $format alnum6 | alpha12 | hex8 | uuid | numeric.
	 * @return string
	 */
	public static function new_id( $format ) {
		switch ( $format ) {
			case 'hex8':
				return bin2hex( random_bytes( 4 ) );
			case 'uuid':
				return wp_generate_uuid4();
			case 'alpha12':
				$s = '';
				for ( $i = 0; $i < 12; $i++ ) {
					$s .= chr( 97 + random_int( 0, 25 ) );
				}
				return $s;
			default:
				$c = 'abcdefghijklmnopqrstuvwxyz0123456789';
				$s = $c[ random_int( 0, 25 ) ];
				for ( $i = 0; $i < 6; $i++ ) {
					$s .= $c[ random_int( 0, 35 ) ];
				}
				return $s;
		}
	}
}
