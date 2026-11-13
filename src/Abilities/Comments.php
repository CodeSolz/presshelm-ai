<?php
/**
 * Comments (H5.3): list (author emails masked) and moderate: approve, unapprove, spam, trash.
 * Moderation needs approval; undo puts every comment back to its previous status.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Comments.
 */
final class Comments {

	const ACTIONS = array( 'approve', 'unapprove', 'spam', 'trash' );

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'comments.list'     => array(
				'risk'        => 'READ',
				'cap'         => 'moderate_comments',
				'mutates'     => false,
				'run'         => array( self::class, 'list_comments' ),
				'label'       => 'List comments',
				'description' => 'Comments with id, post, author name, masked email, date, status and the start of the text. Filter by status (hold = waiting, approve, spam, trash, all), post or search. Read only.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'hold', 'approve', 'spam', 'trash', 'all' ),
						),
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'search'  => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
			'comments.moderate' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'moderate_comments',
				'mutates'     => true,
				'run'         => array( self::class, 'moderate' ),
				'label'       => 'Moderate comments',
				'description' => 'Approves, unapproves, marks as spam or trashes up to 100 comments. Undo puts each back to its previous status.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'ids'    => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'minItems' => 1,
							'maxItems' => 100,
						),
						'action' => array(
							'type' => 'string',
							'enum' => self::ACTIONS,
						),
					),
					'required'             => array( 'ids', 'action' ),
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
	 * Lists comments.
	 *
	 * @param array $p {status?, post_id?, search?, limit?}.
	 * @return array
	 */
	public static function list_comments( array $p ) {
		$args = array(
			'status' => (string) ( $p['status'] ?? 'all' ),
			'number' => (int) ( $p['limit'] ?? 50 ),
			'type'   => 'comment',
		);
		if ( ! empty( $p['post_id'] ) ) {
			$args['post_id'] = (int) $p['post_id'];
		}
		if ( ! empty( $p['search'] ) ) {
			$args['search'] = sanitize_text_field( (string) $p['search'] );
		}
		$out = array();
		foreach ( get_comments( $args ) as $c ) {
			$out[] = array(
				'id'      => (int) $c->comment_ID,
				'post_id' => (int) $c->comment_post_ID,
				'author'  => $c->comment_author,
				'email'   => Mail::mask( (string) $c->comment_author_email ),
				'date'    => $c->comment_date_gmt,
				'status'  => wp_get_comment_status( $c ),
				'text'    => mb_substr( wp_strip_all_tags( (string) $c->comment_content ), 0, 300 ),
			);
		}
		$counts = wp_count_comments();
		return array(
			'comments' => $out,
			'counts'   => array(
				'waiting'  => (int) $counts->moderated,
				'approved' => (int) $counts->approved,
				'spam'     => (int) $counts->spam,
				'trash'    => (int) $counts->trash,
			),
		);
	}

	/**
	 * Moderates comments.
	 *
	 * @param array  $p          {ids, action}.
	 * @param string $command_id Command id.
	 * @return array|\WP_Error
	 */
	public static function moderate( array $p, $command_id ) {
		$before = array();
		foreach ( array_unique( array_map( 'intval', (array) $p['ids'] ) ) as $id ) {
			$c = get_comment( $id );
			if ( $c ) {
				$before[ $id ] = (string) $c->comment_approved;
			}
		}
		if ( ! $before ) {
			return new \WP_Error( 'presshelm_not_found', 'None of those comments exist.' );
		}
		$journal = Journal::record( $command_id, 'comments', 'bulk', array( 'statuses' => $before ) );
		$map     = array(
			'approve'   => 'approve',
			'unapprove' => 'hold',
			'spam'      => 'spam',
			'trash'     => 'trash',
		);
		$done    = 0;
		foreach ( array_keys( $before ) as $id ) {
			if ( wp_set_comment_status( $id, $map[ (string) $p['action'] ] ) ) {
				++$done;
			}
		}
		return array(
			'action'     => (string) $p['action'],
			'changed'    => $done,
			'missing'    => count( (array) $p['ids'] ) - count( $before ),
			'journal_id' => $journal,
		);
	}

	/**
	 * Undo: every comment back to its stored status (journal kind comments).
	 *
	 * @param array $before {statuses: {id: comment_approved}}.
	 * @return true
	 */
	public static function restore( array $before ) {
		$to = array(
			'1'     => 'approve',
			'0'     => 'hold',
			'spam'  => 'spam',
			'trash' => 'trash',
		);
		foreach ( (array) ( $before['statuses'] ?? array() ) as $id => $status ) {
			if ( isset( $to[ (string) $status ] ) && get_comment( (int) $id ) ) {
				if ( 'trash' !== (string) $status && 'trash' === wp_get_comment_status( (int) $id ) ) {
					wp_untrash_comment( (int) $id );
				}
				wp_set_comment_status( (int) $id, $to[ (string) $status ] );
			}
		}
		return true;
	}
}
