<?php
/**
 * WordPress revisions: list a post's saved versions and restore one exactly, with WordPress's own
 * wp_restore_post_revision() (no re-filtering of the saved markup, unlike an edit). The restore is
 * journaled (undo puts the version before it back) and checked like every content change.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Revisions.
 */
final class Revisions {

	const MAX_LIST = 30;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return array(
			'content.revisions'        => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'list_revisions' ),
				'label'       => 'List saved versions of a post or page',
				'description' => 'WordPress revisions of a post or page, newest first: when, by whom, size, and how they differ in size from the current version.',
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
			'content.revision_restore' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'restore' ),
				'label'       => 'Restore a saved version of a post or page',
				'description' => 'Puts a post or page back exactly as a WordPress revision saved it (title, content, excerpt). Journaled: undo returns to the version before the restore.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => $id,
						'revision_id' => $id,
					),
					'required'             => array( 'id', 'revision_id' ),
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
	 * Revisions of one post, newest first.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function list_revisions( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		if ( ! wp_revisions_enabled( $post ) ) {
			return new \WP_Error( 'presshelm_no_revisions', 'Revisions are switched off for this post type on this site (WP_POST_REVISIONS), so there are no saved versions to go back to.' );
		}
		$current = strlen( (string) $post->post_content );
		$out     = array();
		foreach ( array_slice( wp_get_post_revisions( $post->ID, array( 'order' => 'DESC' ) ), 0, self::MAX_LIST ) as $rev ) {
			$author = get_userdata( (int) $rev->post_author );
			$size   = strlen( (string) $rev->post_content );
			$out[]  = array(
				'revision_id'     => (int) $rev->ID,
				'saved_gmt'       => (string) $rev->post_modified_gmt,
				'author'          => $author ? $author->display_name : '',
				'autosave'        => (bool) wp_is_post_autosave( $rev ),
				'title'           => (string) $rev->post_title,
				'bytes'           => $size,
				'bytes_vs_now'    => $size - $current,
				'same_as_current' => (string) $rev->post_content === (string) $post->post_content && (string) $rev->post_title === (string) $post->post_title,
			);
		}
		return array(
			'id'            => $post->ID,
			'current_bytes' => $current,
			'modified_gmt'  => (string) $post->post_modified_gmt,
			'revisions'     => $out,
		);
	}

	/**
	 * Restores one revision exactly.
	 *
	 * @param array  $p          {id, revision_id}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function restore( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$rev_id = (int) $p['revision_id']; // wp_get_post_revision() takes its argument by reference.
		$rev    = wp_get_post_revision( $rev_id );
		if ( ! $rev || (int) $rev->post_parent !== (int) $post->ID ) {
			return new \WP_Error( 'presshelm_not_found', 'That revision does not belong to this post (see content.revisions).' );
		}
		$snippet = \PressHelm\Builders\Guard::refuse_snippet( $post );
		if ( $snippet ) {
			return $snippet;
		}
		if ( 'elementor' !== \PressHelm\Builders\Guard::builder_of( $post ) ) {
			$builder = \PressHelm\Builders\Guard::refuse_content_edit( $post, 'restoring an older version of its content' );
			if ( $builder ) {
				return $builder;
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
		$r       = wp_restore_post_revision( $rev->ID );
		if ( ! $r || is_wp_error( $r ) ) {
			return new \WP_Error( 'presshelm_restore_failed', 'WordPress could not restore that revision.' );
		}
		$now = get_post( $post->ID );
		if ( (string) $now->post_content !== (string) $rev->post_content ) {
			// Something filtered the content on the way in: say so instead of claiming an exact restore.
			Journal::restore( $journal );
			return new \WP_Error( 'presshelm_restore_inexact', 'WordPress changed the content while restoring (likely a content filter on this site), so the restore was undone. Nothing was changed.' );
		}
		return array(
			'id'             => $post->ID,
			'restored_from'  => array(
				'revision_id' => (int) $rev->ID,
				'saved_gmt'   => (string) $rev->post_modified_gmt,
			),
			'content_sha256' => hash( 'sha256', (string) $now->post_content ),
			'journal_id'     => $journal,
			'link'           => get_permalink( $post->ID ),
		);
	}
}
