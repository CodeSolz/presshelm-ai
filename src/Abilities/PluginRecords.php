<?php
/**
 * Abilities for another plugin's records (forms and the like kept as its own post type), carried
 * out by the fixed records engine (Packs\Records) with the records pack the signed command carries.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Packs\Pack;
use PressHelm\Packs\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin records abilities.
 */
final class PluginRecords {

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$pack = array( 'type' => 'object' );
		$type = array(
			'type'    => 'string',
			'pattern' => '^[a-z][a-z0-9_-]{0,30}$',
		);
		$id   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$ro   = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		return array(
			'records.list'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_pages',
				'mutates'     => false,
				'run'         => array( self::class, 'list_records' ),
				'label'       => 'List another plugin\'s records',
				'description' => 'Forms and similar records another plugin keeps as its own post type (PressHelm Cloud says which, with the records pack): id, title, status, embed.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'pack'   => $pack,
						'type'   => $type,
						'search' => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
						),
					),
					'required'             => array( 'pack', 'type' ),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
			'records.get'    => array(
				'risk'        => 'READ',
				'cap'         => 'edit_pages',
				'mutates'     => false,
				'run'         => array( self::class, 'get' ),
				'label'       => 'Read another plugin\'s record',
				'description' => 'Every field of one record (decoded).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'pack' => $pack,
						'type' => $type,
						'id'   => $id,
					),
					'required'             => array( 'pack', 'type', 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
			'records.save'   => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_pages',
				'mutates'     => true,
				'run'         => array( self::class, 'save' ),
				'label'       => 'Create or change another plugin\'s record',
				'description' => 'Creates a record (no id) or changes one: values replace whole fields, patch changes keys inside an array or JSON field (dot paths). Code keys are refused; texts are filtered like post edits. Undo puts it back exactly (a created record goes to the trash).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'pack'   => $pack,
						'type'   => $type,
						'id'     => $id,
						'values' => array( 'type' => 'object' ),
						'patch'  => array( 'type' => 'object' ),
					),
					'required'             => array( 'pack', 'type' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'records.delete' => array(
				'risk'        => 'HIGH',
				'cap'         => 'edit_pages',
				'mutates'     => true,
				'run'         => array( self::class, 'delete' ),
				'label'       => 'Trash another plugin\'s record',
				'description' => 'Moves a record to the trash; undo restores it.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'pack' => $pack,
						'type' => $type,
						'id'   => $id,
					),
					'required'             => array( 'pack', 'type', 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * The validated records pack of a payload.
	 *
	 * @param array $p Payload.
	 * @return array|\WP_Error
	 */
	private static function pack( array $p ) {
		$v = Pack::validate( $p['pack'] ?? null );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		return 'records' === ( $v['kind'] ?? '' ) ? $v : new \WP_Error( 'presshelm_pack_invalid', 'That pack does not describe records.' );
	}

	/**
	 * List.
	 *
	 * @param array $p Payload.
	 * @return array|\WP_Error
	 */
	public static function list_records( array $p ) {
		$pack = self::pack( $p );
		return is_wp_error( $pack ) ? $pack : Records::list_records( $pack, (string) $p['type'], (string) ( $p['search'] ?? '' ), (int) ( $p['limit'] ?? 50 ) );
	}

	/**
	 * Get.
	 *
	 * @param array $p Payload.
	 * @return array|\WP_Error
	 */
	public static function get( array $p ) {
		$pack = self::pack( $p );
		return is_wp_error( $pack ) ? $pack : Records::get( $pack, (string) $p['type'], (int) $p['id'] );
	}

	/**
	 * Save.
	 *
	 * @param array  $p          Payload.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function save( array $p, $command_id ) {
		$pack = self::pack( $p );
		if ( is_wp_error( $pack ) ) {
			return $pack;
		}
		$values = (array) ( $p['values'] ?? array() );
		$patch  = (array) ( $p['patch'] ?? array() );
		if ( ! $values && ! $patch ) {
			return new \WP_Error( 'presshelm_payload', 'Give values or patch.' );
		}
		return Records::save( $pack, (string) $p['type'], isset( $p['id'] ) ? (int) $p['id'] : null, $values, $patch, $command_id );
	}

	/**
	 * Delete (trash).
	 *
	 * @param array  $p          Payload.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function delete( array $p, $command_id ) {
		$pack = self::pack( $p );
		return is_wp_error( $pack ) ? $pack : Records::trash( $pack, (string) $p['type'], (int) $p['id'], $command_id );
	}
}
