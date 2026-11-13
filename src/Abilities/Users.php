<?php
/**
 * Users (H5.3): list and read (emails masked), create, change role / name / email, delete with
 * the content handed to another user. Passwords are never set, read or returned: a new user gets
 * WordPress's own "set your password" email. The connected admin and the last administrator are
 * never demoted or deleted. Changes need the owner's approval (HIGH).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Users.
 */
final class Users {

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$id   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$role = array(
			'type'      => 'string',
			'maxLength' => 60,
			'pattern'   => '^[a-z0-9_-]+$',
		);
		$ro   = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		return array(
			'users.list' => array(
				'risk'        => 'READ',
				'cap'         => 'list_users',
				'mutates'     => false,
				'run'         => array( self::class, 'list_users' ),
				'label'       => 'List users',
				'description' => 'Users with id, login, display name, masked email, roles, registration date and post count. Filter by role or search. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'role'   => $role,
						'search' => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
			'users.get'  => array(
				'risk'        => 'READ',
				'cap'         => 'list_users',
				'mutates'     => false,
				'run'         => array( self::class, 'get_user' ),
				'label'       => 'Read a user',
				'description' => 'One user: login, display name, masked email, roles, registration date, post count. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'annotations' => $ro,
				'undo'        => null,
			),
		);
	}

	/**
	 * A user as the agent sees it (email masked, no password data).
	 *
	 * @param \WP_User $u User.
	 * @return array
	 */
	public static function out( $u ) {
		return array(
			'id'           => (int) $u->ID,
			'login'        => $u->user_login,
			'display_name' => $u->display_name,
			'email'        => Mail::mask( (string) $u->user_email ),
			'roles'        => array_values( (array) $u->roles ),
			'registered'   => (string) $u->user_registered,
			'posts'        => (int) count_user_posts( $u->ID, array( 'post', 'page' ), true ),
		);
	}

	/**
	 * Lists users.
	 *
	 * @param array $p {role?, search?, limit?, page?}.
	 * @return array
	 */
	public static function list_users( array $p ) {
		$limit = (int) ( $p['limit'] ?? 50 );
		$args  = array(
			'number'  => $limit,
			'paged'   => (int) ( $p['page'] ?? 1 ),
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( ! empty( $p['role'] ) ) {
			$args['role'] = (string) $p['role'];
		}
		if ( ! empty( $p['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( (string) $p['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
		}
		$q = new \WP_User_Query( $args + array( 'count_total' => true ) );
		return array(
			'users' => array_map( array( self::class, 'out' ), (array) $q->get_results() ),
			'total' => (int) $q->get_total(),
		);
	}

	/**
	 * One user.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function get_user( array $p ) {
		$u = get_userdata( (int) $p['id'] );
		return $u ? self::out( $u ) : new \WP_Error( 'presshelm_not_found', 'No user with that id.' );
	}
}
