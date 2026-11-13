<?php
/**
 * Compact, builder-neutral description of element settings, read live from each builder's own
 * definitions (Elementor controls, Beaver forms, block attributes). The agent uses it to write the
 * right setting names and value formats instead of guessing.
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Settings schema helpers.
 */
final class Schema {

	const MAX_FIELDS  = 200;
	const MAX_OPTIONS = 40;
	const MAX_TEXT    = 300;

	/**
	 * One setting.
	 *
	 * @param string $name       Setting name.
	 * @param string $kind       Control/field kind.
	 * @param array  $def        Builder definition.
	 * @param string $tab        Panel tab.
	 * @param bool   $responsive Has per-device copies.
	 * @return array
	 */
	public static function field( $name, $kind, array $def, $tab, $responsive ) {
		$out   = array(
			'name' => $name,
			'kind' => $kind,
		);
		$label = isset( $def['label'] ) && is_scalar( $def['label'] ) ? trim( wp_strip_all_tags( (string) $def['label'] ) ) : '';
		if ( '' !== $label ) {
			$out['label'] = $label;
		}
		if ( 'content' !== $tab && 'general' !== $tab ) {
			$out['tab'] = $tab;
		}
		if ( array_key_exists( 'default', $def ) && '' !== $def['default'] && null !== $def['default'] && array() !== $def['default'] ) {
			$out['default'] = self::clip( $def['default'] );
		}
		if ( ! empty( $def['options'] ) && is_array( $def['options'] ) ) {
			$keys           = array_map( 'strval', array_keys( $def['options'] ) );
			$out['options'] = array_slice( $keys, 0, self::MAX_OPTIONS );
			if ( count( $keys ) > self::MAX_OPTIONS ) {
				$out['options'][] = '… (' . count( $keys ) . ' in all)';
			}
		}
		if ( $responsive ) {
			$out['responsive'] = true;
		}
		if ( ! empty( $def['multiple'] ) ) {
			$out['multiple'] = true;
		}
		// Repeater (Elementor) and sub-form (Beaver) rows: the names of their fields.
		if ( ! empty( $def['fields'] ) && is_array( $def['fields'] ) ) {
			$out['row_fields'] = array_values(
				array_filter(
					array_map(
						static function ( $k, $f ) {
							return is_array( $f ) && isset( $f['name'] ) ? (string) $f['name'] : ( is_string( $k ) ? $k : '' );
						},
						array_keys( $def['fields'] ),
						$def['fields']
					)
				)
			);
		}
		if ( ! empty( $def['form'] ) && is_string( $def['form'] ) ) {
			$out['sub_form'] = $def['form'];
		}
		return $out;
	}

	/**
	 * The result of a type lookup.
	 *
	 * @param string $builder Builder id.
	 * @param string $type    Type.
	 * @param string $title   Title.
	 * @param array  $fields  Fields.
	 * @param bool   $all     All tabs were included.
	 * @param array  $formats Value formats.
	 * @return array
	 */
	public static function result( $builder, $type, $title, array $fields, $all, array $formats ) {
		$out = array(
			'builder'       => $builder,
			'type'          => $type,
			'title'         => $title,
			'settings'      => array_slice( $fields, 0, self::MAX_FIELDS ),
			'value_formats' => $formats,
		);
		if ( count( $fields ) > self::MAX_FIELDS ) {
			$out['note'] = count( $fields ) . ' settings in all; the first ' . self::MAX_FIELDS . ' are listed.';
		}
		if ( ! $all ) {
			$out['scope'] = 'Content settings only; ask with all=true for style and advanced settings.';
		}
		return $out;
	}

	/**
	 * Block types (WordPress's own registry): list, or one block's attributes and supports.
	 *
	 * @param string $type Block name (e.g. core/button), or '' for the list.
	 * @return array|\WP_Error
	 */
	public static function blocks( $type ) {
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( '' === $type ) {
			$out = array();
			foreach ( $registry->get_all_registered() as $name => $b ) {
				if ( 'core/html' === $name || 'core/shortcode' === $name ) {
					continue;
				}
				$out[] = array(
					'type'  => (string) $name,
					'title' => (string) $b->title,
				);
			}
			return array(
				'builder' => 'blocks',
				'widgets' => $out,
				'note'    => 'Block pages are changed with blocks_inspect and block_patch; copy markup patterns from blocks already on the site.',
			);
		}
		$b = $registry->get_registered( $type );
		if ( ! $b ) {
			return new \WP_Error( 'presshelm_not_found', 'No block "' . $type . '" is registered here (see the list without type).' );
		}
		$attrs = array();
		foreach ( (array) $b->attributes as $name => $a ) {
			if ( 'lock' === $name || 'metadata' === $name ) {
				continue;
			}
			$row = array(
				'name' => (string) $name,
				'kind' => is_array( $a['type'] ?? null ) ? implode( '|', $a['type'] ) : (string) ( $a['type'] ?? ( isset( $a['source'] ) ? 'string' : 'any' ) ),
			);
			if ( isset( $a['source'] ) ) {
				// Sourced attributes live in the block's HTML, not in the comment delimiter.
				$row['stored_in'] = 'html (' . $a['source'] . ( isset( $a['selector'] ) ? ' ' . $a['selector'] : '' ) . ')';
			}
			if ( array_key_exists( 'default', $a ) ) {
				$row['default'] = self::clip( $a['default'] );
			}
			if ( ! empty( $a['enum'] ) ) {
				$row['options'] = array_map( 'strval', array_slice( (array) $a['enum'], 0, self::MAX_OPTIONS ) );
			}
			$attrs[] = $row;
		}
		$supports = array();
		foreach ( (array) $b->supports as $k => $v ) {
			if ( false !== $v ) {
				$supports[] = (string) $k;
			}
		}
		return array(
			'builder'    => 'blocks',
			'type'       => $type,
			'title'      => (string) $b->title,
			'attributes' => array_slice( $attrs, 0, self::MAX_FIELDS ),
			'supports'   => $supports,
			'parent'     => $b->parent ? array_values( (array) $b->parent ) : null,
			'dynamic'    => $b->is_dynamic(),
			'note'       => ( $b->is_dynamic() ? 'Dynamic block: PHP renders it, so only the comment attributes matter. ' : 'Static block: its saved HTML must match what the block produces, or the editor shows it as invalid; copy an existing instance (blocks_inspect). ' ) . 'Supported design settings (supports) go into the "style" attribute, e.g. {"style":{"color":{"text":"#111111"}}}.',
		);
	}

	/**
	 * A default value, clipped.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	private static function clip( $v ) {
		if ( is_string( $v ) ) {
			return strlen( $v ) > self::MAX_TEXT ? substr( $v, 0, self::MAX_TEXT ) . '…' : $v;
		}
		if ( is_array( $v ) ) {
			$json = (string) wp_json_encode( $v );
			return strlen( $json ) > self::MAX_TEXT ? substr( $json, 0, self::MAX_TEXT ) . '…' : $v;
		}
		return $v;
	}
}
