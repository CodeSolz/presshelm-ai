<?php
/**
 * Before-state of every change, so it can be undone on request or rolled back automatically
 * when the health check fails (D35).
 *
 * @package PressHelm
 */

namespace PressHelm\Recovery;

use PressHelm\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Undo journal.
 */
final class Journal {

	/**
	 * Records the state before a change.
	 *
	 * @param string $command_id Command id.
	 * @param string $kind       post|custom_css.
	 * @param string $target     Object id (post id, stylesheet).
	 * @param array  $before     Data needed to restore.
	 * @return int Journal id.
	 */
	public static function record( $command_id, $kind, $target, array $before ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
			Schema::table( 'journal' ),
			array(
				'command_id'   => (string) $command_id,
				'kind'         => (string) $kind,
				'target'       => (string) $target,
				'before_state' => (string) wp_json_encode( $before ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * One entry.
	 *
	 * @param int $id Journal id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'journal' ), (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, always fresh.
		return $row ? $row : null;
	}

	/**
	 * Restores a journal entry.
	 *
	 * @param int $id Journal id.
	 * @return true|\WP_Error
	 */
	public static function restore( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'presshelm_journal_missing', __( 'Nothing to undo with that id.', 'presshelm-ai' ) );
		}
		if ( null !== $row['restored_at'] ) {
			return new \WP_Error( 'presshelm_journal_used', __( 'That change was already undone.', 'presshelm-ai' ) );
		}
		$before = (array) json_decode( (string) $row['before_state'], true );
		switch ( $row['kind'] ) {
			case 'post':
				// wp_update_post() unslashes its input: without wp_slash() the backslashes in block
				// attributes (e.g. "-" for "--") were stripped and the restored page was damaged.
				$r = wp_update_post(
					wp_slash(
						array(
							'ID'           => (int) $row['target'],
							'post_title'   => (string) ( $before['post_title'] ?? '' ),
							'post_content' => (string) ( $before['post_content'] ?? '' ),
							'post_status'  => (string) ( $before['post_status'] ?? 'draft' ),
							'post_excerpt' => (string) ( $before['post_excerpt'] ?? '' ),
						)
					),
					true
				);
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'post_created':
				// Undo of a create: to the trash (recoverable), never deleted outright.
				if ( get_post( (int) $row['target'] ) && ! wp_trash_post( (int) $row['target'] ) ) {
					return new \WP_Error( 'presshelm_journal_trash', __( 'The created post could not be moved to the trash.', 'presshelm-ai' ) );
				}
				break;
			case 'attachment_created':
				if ( get_post( (int) $row['target'] ) && ! wp_delete_attachment( (int) $row['target'], true ) ) {
					return new \WP_Error( 'presshelm_journal_media', __( 'The uploaded file could not be removed.', 'presshelm-ai' ) );
				}
				break;
			case 'form_created':
				// Target: form id; before: {plugin}.
				$r = \PressHelm\Abilities\Forms::delete( (int) $row['target'], (string) ( $before['plugin'] ?? '' ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'woo_snapshot':
				// Target: product id; before: {snap} (the whole product and its variations).
				$r = \PressHelm\Woo\Snapshot::restore( (array) ( $before['snap'] ?? array() ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'woo_import':
				// Target: import job id (its state lists what was created and the snapshots).
				$r = \PressHelm\Woo\Catalog::undo_import( (string) $row['target'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'content_import':
				$r = \PressHelm\Abilities\Porting::undo( (string) $row['target'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'filebox_file':
			case 'filebox_files':
				// Target: box file id(s), comma separated (files PressHelm fetched or made).
				foreach ( array_filter( explode( ',', (string) $row['target'] ) ) as $fid ) {
					\PressHelm\Files\FileBox::remove( $fid );
				}
				break;
			case 'builder':
				// Target: post id; before: {adapter, raw} (put back through the builder, byte-exact).
				$r = \PressHelm\Abilities\Builder::restore( (int) $row['target'], $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'record':
				// Target: post id of another plugin's record; before: {post: columns, meta: exact values}.
				$r = \PressHelm\Packs\Records::restore( (int) $row['target'], $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'record_trashed':
				if ( get_post( (int) $row['target'] ) && ! wp_untrash_post( (int) $row['target'] ) ) {
					return new \WP_Error( 'presshelm_journal_trash', __( 'The record could not be restored from the trash.', 'presshelm-ai' ) );
				}
				break;
			case 'woo_products':
				// Target: product id or "bulk"; before: {items: [{id, fields}]} (WooCommerce setters).
				$r = \PressHelm\Abilities\Woo::restore_products( (array) ( $before['items'] ?? array() ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'woo_order_status':
				// Target: order id; before: {status}. WooCommerce may email the customer again.
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['target'] ) : false;
				if ( $order ) {
					$order->update_status( (string) ( $before['status'] ?? 'pending' ), __( 'Status put back by PressHelm (undo).', 'presshelm-ai' ) );
				}
				break;
			case 'woo_order_note':
				if ( function_exists( 'wc_delete_order_note' ) ) {
					wc_delete_order_note( (int) $row['target'] );
				}
				break;
			case 'menu':
				// Target: menu id; before: {items} with every field (restored exactly).
				\PressHelm\Abilities\Site::restore_menu( (int) $row['target'], (array) ( $before['items'] ?? array() ) );
				break;
			case 'menu_created':
				wp_delete_nav_menu( (int) $row['target'] );
				set_theme_mod( 'nav_menu_locations', (array) ( $before['locations'] ?? array() ) );
				break;
			case 'media_replaced':
				$r = \PressHelm\Abilities\Media::undo_replace( (int) $row['target'], $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'media_deleted':
				$r = \PressHelm\Abilities\Media::undo_delete( (int) $row['target'], $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'media_converted':
				// Target 'bulk'; before: {items} (files, details, content and Elementor data per image).
				$r = \PressHelm\Abilities\MediaEdit::undo_convert( (array) ( $before['items'] ?? array() ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'terms':
				// Target: taxonomy; before: {terms, created?, objects?, menus?} (deleted terms return with their ids).
				$r = \PressHelm\Abilities\Terms::restore( $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'posts_bulk':
				// Target is 'bulk'; the before state lists each post's status, comment status, parent, template, sticky flag and terms.
				foreach ( (array) ( $before['posts'] ?? array() ) as $pid => $state ) {
					$pid = (int) $pid;
					if ( ! get_post( $pid ) ) {
						continue;
					}
					if ( 'trash' === get_post_status( $pid ) && 'trash' !== ( $state['status'] ?? '' ) ) {
						wp_untrash_post( $pid );
					}
					if ( 'trash' === ( $state['status'] ?? '' ) ) {
						wp_trash_post( $pid );
					} else {
						$r = wp_update_post(
							wp_slash(
								array(
									'ID'             => $pid,
									'post_status'    => (string) ( $state['status'] ?? 'draft' ),
									'comment_status' => (string) ( $state['comment_status'] ?? 'closed' ),
									'post_parent'    => (int) ( $state['parent'] ?? 0 ),
								)
							),
							true
						);
						if ( is_wp_error( $r ) ) {
							return $r;
						}
					}
					if ( '' === (string) ( $state['template'] ?? '' ) ) {
						delete_post_meta( $pid, '_wp_page_template' );
					} else {
						update_post_meta( $pid, '_wp_page_template', (string) $state['template'] );
					}
					if ( 'post' === get_post_type( $pid ) ) {
						! empty( $state['sticky'] ) ? stick_post( $pid ) : unstick_post( $pid );
					}
					foreach ( (array) ( $state['terms'] ?? array() ) as $tax => $ids ) {
						if ( taxonomy_exists( (string) $tax ) ) {
							wp_set_object_terms( $pid, array_map( 'intval', (array) $ids ), (string) $tax );
						}
					}
				}
				break;
			case 'comments':
				// Target: "bulk"; before: {statuses: {id: comment_approved}}.
				\PressHelm\Abilities\Comments::restore( $before );
				break;
			case 'stage_publish':
				// Target: the original post; before: {post: fields, meta: {key: {exists, value}}}.
				$r = \PressHelm\Abilities\Stage::restore( (int) $row['target'], $before );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'post_meta':
				// Target: post id; before: {meta_key: {exists, value}}.
				foreach ( $before as $key => $state ) {
					if ( empty( $state['exists'] ) ) {
						delete_post_meta( (int) $row['target'], (string) $key );
					} else {
						update_post_meta( (int) $row['target'], (string) $key, wp_slash( $state['value'] ) );
					}
				}
				break;
			default:
				/**
				 * Undo of a change made by an add-on ability (null: nobody knows this kind).
				 *
				 * @param true|\WP_Error|null $result Result.
				 * @param array               $row    Journal row.
				 * @param array               $before Saved state.
				 */
				$r = apply_filters( 'presshelm_journal_restore', null, $row, $before );
				if ( null === $r ) {
					return new \WP_Error( 'presshelm_journal_kind', __( 'This kind of change cannot be undone automatically.', 'presshelm-ai' ) );
				}
				if ( is_wp_error( $r ) ) {
					return $r;
				}
		}
		global $wpdb;
		$wpdb->update( Schema::table( 'journal' ), array( 'restored_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return true;
	}
}
