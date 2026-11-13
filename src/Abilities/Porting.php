<?php
/**
 * WordPress content export and import (the WXR format of Tools → Export and Tools → Import):
 * - export with WordPress's own export_wp() into the file box (all content, or one post type);
 * - import with the official WordPress Importer plugin (must be active; if it is not, the answer
 *   says so and the assistant can install it). A preview counts what the file holds; the import runs
 *   in the background; undo trashes the posts it created and deletes its attachments. Terms it
 *   created stay (they are shared by other content), which the description says. It never creates
 *   users and refuses files holding code, custom CSS or global styles.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Files\FileBox;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Content export/import.
 */
final class Porting {

	const HOOK       = 'presshelm_content_import';
	const JOB_PREFIX = 'presshelm_content_import_';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( self::class, 'run_job' ) );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$fid = array(
			'type'    => 'string',
			'pattern' => '^f_[a-f0-9]{12}$',
		);
		$def = static function ( $risk, $cap, $mutates, $run, $label, $desc, $props, $required, $undo ) {
			return array(
				'risk'        => $risk,
				'cap'         => $cap,
				'mutates'     => $mutates,
				'run'         => $run,
				'label'       => $label,
				'description' => $desc,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $props,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => ! $mutates,
					'destructive' => false,
					'idempotent'  => ! $mutates,
				),
				'undo'        => $undo,
			);
		};
		return array(
			'content.export'         => $def( 'LOW', 'export', true, array( self::class, 'export' ), 'Export content (WordPress XML)', 'WordPress\'s own export (Tools → Export) of all content or one post type, into the file box; send it with filebox.send.', array( 'post_type' => array( 'type' => 'string', 'maxLength' => 40 ), 'status' => array( 'type' => 'string', 'maxLength' => 20 ) ), array(), 'journal.restore' ) + array( 'skip_health' => true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'content.import_preview' => $def( 'READ', 'import', false, array( self::class, 'preview' ), 'Preview a WordPress XML import', 'What a WordPress export file (WXR) in the file box holds: posts by type, attachments, authors, categories, tags and terms. Needs the WordPress Importer plugin active.', array( 'file_id' => $fid ), array( 'file_id' ), null ),
			'content.import'         => $def( 'HIGH', 'import', true, array( self::class, 'import' ), 'Import a WordPress XML file', 'Imports a previewed WXR file with the WordPress Importer, in the background (attachments downloaded if asked). Refused unless the file still holds expected_items posts. Undo trashes the posts it created and deletes its attachments; categories and tags it created stay. It never creates users (posts by unknown authors go to the admin who connected the site) and refuses files with code, custom CSS or global styles.', array( 'file_id' => $fid, 'expected_items' => array( 'type' => 'integer', 'minimum' => 1 ), 'attachments' => array( 'type' => 'boolean' ) ), array( 'file_id', 'expected_items' ), 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'content.import_status'  => $def( 'READ', 'import', false, array( self::class, 'status' ), 'Progress of a content import', 'Whether a WordPress XML import has finished, and what it created.', array( 'job_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{16}$' ) ), array( 'job_id' ), null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
		);
	}

	/**
	 * Exports content into the box.
	 *
	 * @param array  $p          {post_type?, status?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function export( array $p, $command_id ) {
		require_once ABSPATH . 'wp-admin/includes/export.php';
		$args = array( 'content' => ! empty( $p['post_type'] ) ? sanitize_key( (string) $p['post_type'] ) : 'all' );
		if ( ! empty( $p['status'] ) ) {
			$args['status'] = sanitize_key( (string) $p['status'] );
		}
		ob_start();
		export_wp( $args );
		$xml = (string) ob_get_clean();
		// export_wp() sets download headers for a browser; this request answers PressHelm, not a browser.
		if ( ! headers_sent() ) {
			header_remove( 'Content-Description' );
			header_remove( 'Content-Disposition' );
			header( 'Content-Type: ' . ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'application/json' : 'text/html' ) . '; charset=' . get_option( 'blog_charset' ) );
		}
		if ( '' === $xml ) {
			return new \WP_Error( 'presshelm_export', 'WordPress produced an empty export.' );
		}
		$tmp = wp_tempnam( 'presshelm-wxr' );
		file_put_contents( $tmp, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- moved into the file box.
		$file = FileBox::add( $tmp, sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . $args['content'] . '-' . gmdate( 'Y-m-d' ) . '.xml' ), 'WordPress export' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$file['items']      = substr_count( $xml, '<item>' );
		$file['journal_id'] = Journal::record( $command_id, 'filebox_file', $file['file_id'], array() );
		$file['next']       = 'Send it to the owner with filebox.send.';
		return $file;
	}

	/**
	 * The WordPress Importer, loaded, or why not.
	 *
	 * @return true|\WP_Error
	 */
	private static function importer() {
		if ( ! class_exists( 'WP_Import' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$file = WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php';
			if ( ! is_plugin_active( 'wordpress-importer/wordpress-importer.php' ) || ! is_file( $file ) ) {
				return new \WP_Error( 'presshelm_needs_importer', 'Importing WordPress XML needs the official "WordPress Importer" plugin (slug wordpress-importer) installed and active. Install and activate it first (with the owner\'s approval), then try again.' );
			}
			require_once ABSPATH . 'wp-admin/includes/import.php';
			// The importer's main file only defines its classes on WordPress's own import screen
			// (loading it again would redeclare its init function): its class files are loaded here.
			if ( ! class_exists( 'WP_Importer' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
			}
			$dir = dirname( $file ) . '/';
			foreach ( array( 'compat.php', 'php-toolkit/load.php', 'parsers/class-wxr-parser.php', 'parsers/class-wxr-parser-simplexml.php', 'parsers/class-wxr-parser-xml.php', 'parsers/class-wxr-parser-regex.php', 'parsers/class-wxr-parser-xml-processor.php', 'class-wp-import.php' ) as $part ) {
				if ( is_file( $dir . $part ) ) {
					require_once $dir . $part;
				}
			}
		}
		// The importer calls admin helpers (post_exists, comment_exists, media sideloading) that
		// WordPress only loads in wp-admin; background jobs and polls run outside it.
		foreach ( array( 'post.php', 'comment.php', 'file.php', 'media.php', 'image.php' ) as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc;
		}
		return class_exists( 'WP_Import' ) ? true : new \WP_Error( 'presshelm_needs_importer', 'The WordPress Importer plugin did not load.' );
	}

	/**
	 * What a WXR file holds.
	 *
	 * @param array $p {file_id}.
	 * @return array|\WP_Error
	 */
	public static function preview( array $p ) {
		$f = FileBox::get( (string) $p['file_id'] );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( 'xml' !== $f['record']['kind'] ) {
			return new \WP_Error( 'presshelm_file_type', 'That file is not XML (it is ' . $f['record']['kind'] . ').' );
		}
		$ok = self::importer();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$parser = new \WXR_Parser();
		$data   = $parser->parse( $f['path'] );
		if ( is_wp_error( $data ) ) {
			return new \WP_Error( 'presshelm_wxr', 'This is not a WordPress export file: ' . $data->get_error_message() );
		}
		$types = array();
		foreach ( (array) $data['posts'] as $post ) {
			$t           = (string) ( $post['post_type'] ?? 'post' );
			$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
			// Content only: no custom CSS, global styles or fields that hold code.
			$code_meta = array_filter(
				(array) ( $post['postmeta'] ?? array() ),
				static function ( $m ) {
					return preg_match( Generic::CODE_NAME, (string) ( $m['key'] ?? '' ) );
				}
			);
			if ( in_array( $t, array( 'custom_css', 'wp_global_styles' ), true ) || $code_meta ) {
				return new \WP_Error( 'presshelm_wxr_code', 'This export holds custom CSS, global styles or code fields. PressHelm imports content only; nothing was imported.' );
			}
		}
		// Nor code anywhere in the content (scripts, styles, PHP, iframes, event handlers, builder CSS).
		$xml = (string) file_get_contents( $f['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the file box.
		if ( Generic::has_code( $xml ) || preg_match( '/\\\\?"custom_(?:css|js)\\\\?"\s*:\s*\\\\?"[^"\\\\]/i', $xml ) ) {
			return new \WP_Error( 'presshelm_wxr_code', 'This export holds code (scripts, styles, PHP, iframes, event handlers or custom CSS). PressHelm imports content only; nothing was imported.' );
		}
		return array(
			'items'          => count( (array) $data['posts'] ),
			'expected_items' => count( (array) $data['posts'] ),
			'by_type'        => $types,
			'authors'        => array_values(
				array_map(
					static function ( $a ) {
						return (string) ( $a['author_login'] ?? '' );
					},
					(array) ( $data['authors'] ?? array() )
				)
			),
			'categories'     => count( (array) ( $data['categories'] ?? array() ) ),
			'tags'           => count( (array) ( $data['tags'] ?? array() ) ),
			'terms'          => count( (array) ( $data['terms'] ?? array() ) ),
			'from'           => (string) ( $data['base_url'] ?? '' ),
			'note'           => 'Imported posts by an author whose login does not exist here go to the admin who connected the site (no users are created). Posts that already exist (same title and date) are skipped by the importer.',
		);
	}

	/**
	 * Starts an import in the background.
	 *
	 * @param array  $p          {file_id, expected_items, attachments?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function import( array $p, $command_id ) {
		$preview = self::preview( $p );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( (int) $preview['items'] !== (int) $p['expected_items'] ) {
			return new \WP_Error( 'presshelm_preview_mismatch', 'The file holds ' . (int) $preview['items'] . ' items, not ' . (int) $p['expected_items'] . ': preview it again. Nothing was imported.' );
		}
		$f    = FileBox::get( (string) $p['file_id'] );
		$copy = FileBox::dir() . 'wxr-' . bin2hex( random_bytes( 8 ) ) . '.xml';
		copy( $f['path'], $copy );
		$job = bin2hex( random_bytes( 8 ) );
		$jid = Journal::record( $command_id, 'content_import', $job, array() );
		update_option(
			self::JOB_PREFIX . $job,
			array(
				'file'        => $copy,
				'name'        => $f['record']['name'],
				'attachments' => ! empty( $p['attachments'] ),
				'items'       => (int) $preview['items'],
				'created'     => array(),
				'status'      => 'queued',
				'user'        => get_current_user_id(),
				'journal_id'  => $jid,
				'started'     => time(),
			),
			false
		);
		wp_schedule_single_event( time(), self::HOOK, array( $job ) );
		spawn_cron();
		return array(
			'job_id'     => $job,
			'status'     => 'queued',
			'items'      => (int) $preview['items'],
			'journal_id' => $jid,
			'next'       => 'It runs in the background; ask content.import_status with job_id.',
		);
	}

	/**
	 * Runs an import job (WP-Cron).
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	public static function run_job( $job ) {
		$key   = self::JOB_PREFIX . sanitize_key( (string) $job );
		$state = get_option( $key );
		if ( ! is_array( $state ) || 'queued' !== $state['status'] || \PressHelm\Recovery\EmergencyStop::active() ) {
			return;
		}
		$state['status'] = 'running';
		update_option( $key, $state, false );
		$token   = \PressHelm\Bridge\TaskUser::begin( (int) $state['user'] );
		$created = array();
		$track   = static function ( $post_id ) use ( &$created ) {
			$created[] = (int) $post_id;
		};
		add_action( 'wp_import_insert_post', $track, 10, 1 );
		// Never creates users: posts by unknown authors belong to the admin who connected the site.
		add_filter( 'import_allow_create_users', '__return_false' );
		try {
			$ok = self::importer();
			if ( is_wp_error( $ok ) ) {
				throw new \RuntimeException( $ok->get_error_message() );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a long import in a background request.
			}
			$importer                    = new \WP_Import();
			$importer->fetch_attachments = (bool) $state['attachments'];
			ob_start();
			$importer->import( $state['file'] );
			ob_end_clean();
			$state['status'] = 'finished';
		} catch ( \Throwable $e ) {
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			$state['status'] = 'failed';
			$state['error']  = substr( $e->getMessage(), 0, 300 );
		} finally {
			remove_action( 'wp_import_insert_post', $track, 10 );
			remove_filter( 'import_allow_create_users', '__return_false' );
			\PressHelm\Bridge\TaskUser::end( $token );
		}
		$state['created']  = $created;
		$state['finished'] = time();
		wp_delete_file( $state['file'] );
		update_option( $key, $state, false );
	}

	/**
	 * Progress of an import.
	 *
	 * @param array $p {job_id}.
	 * @return array|\WP_Error
	 */
	public static function status( array $p ) {
		$state = get_option( self::JOB_PREFIX . sanitize_key( (string) $p['job_id'] ) );
		if ( ! is_array( $state ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No import with that job id.' );
		}
		if ( 'queued' === $state['status'] && ! wp_next_scheduled( self::HOOK, array( (string) $p['job_id'] ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( (string) $p['job_id'] ) );
			spawn_cron();
		}
		$types = array();
		foreach ( $state['created'] as $id ) {
			$t           = (string) get_post_type( $id );
			$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
		}
		return array(
			'job_id'     => (string) $p['job_id'],
			'file'       => $state['name'],
			'status'     => $state['status'],
			'items'      => $state['items'],
			'created'    => count( $state['created'] ),
			'by_type'    => $types,
			'error'      => $state['error'] ?? null,
			'journal_id' => $state['journal_id'],
		);
	}

	/**
	 * Undo of an import: created posts to the trash, created attachments deleted.
	 *
	 * @param string $job Job id.
	 * @return true|\WP_Error
	 */
	public static function undo( $job ) {
		$key   = self::JOB_PREFIX . sanitize_key( (string) $job );
		$state = get_option( $key );
		if ( ! is_array( $state ) ) {
			return true;
		}
		if ( 'running' === $state['status'] ) {
			return new \WP_Error( 'presshelm_import_running', 'The import is still running; undo it when it has finished (content.import_status).' );
		}
		if ( 'queued' === $state['status'] ) {
			$state['status'] = 'undone'; // Never starts.
			update_option( $key, $state, false );
			return true;
		}
		foreach ( array_reverse( (array) $state['created'] ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( (int) $id, true );
			} elseif ( get_post( (int) $id ) ) {
				wp_trash_post( (int) $id );
			}
		}
		$state['status'] = 'undone';
		update_option( $key, $state, false );
		return true;
	}
}
