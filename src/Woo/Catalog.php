<?php
/**
 * WooCommerce catalogue work without limits on what can be set, through WooCommerce's own code:
 * - products: any field WooCommerce's REST API knows (type, prices, descriptions, images and
 *   gallery, categories, tags, attributes, stock, shipping, linked products, meta) and variations
 *   (create, update, delete), with an exact snapshot first so one undo puts everything back;
 * - CSV import with WooCommerce's own importer and column mapping: a preview, then a background
 *   job in batches (its status can be asked for), undone in one step (new products to the trash,
 *   updated ones back from snapshots);
 * - CSV export with WooCommerce's own exporter, into the file box (send it with filebox.send).
 *
 * @package PressHelm
 */

namespace PressHelm\Woo;

use PressHelm\Files\FileBox;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue.
 */
final class Catalog {

	const HOOK       = 'presshelm_woo_import_batch';
	const JOB_PREFIX = 'presshelm_woo_import_';
	const BATCH      = 20;
	const MAX_SNAPS  = 3000;

	/**
	 * Hooks (background import batches).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( self::class, 'continue_job' ) );
	}

	/**
	 * Ability definitions (while WooCommerce is active).
	 *
	 * @return array
	 */
	public static function definitions() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$fid   = array(
			'type'    => 'string',
			'pattern' => '^f_[a-f0-9]{12}$',
		);
		$id    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$read  = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$write = array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
		$def   = static function ( $risk, $mutates, $run, $label, $desc, $props, $required, $ann, $undo ) {
			return array(
				'risk'        => $risk,
				'cap'         => 'edit_products',
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
				'annotations' => $ann,
				'undo'        => $undo,
			);
		};
		return array(
			'woo.product.save'            => $def( 'MEDIUM', true, array( self::class, 'save' ), 'Create or change a product (any field)', 'Creates a product, or changes one (id), with any field of WooCommerce\'s product API (type, prices, descriptions, images and gallery, categories, tags, attributes, stock, shipping, linked products, meta), plus variations to create, update or delete. Images can also be files the owner sent (inbox_id). Undo puts the whole product back exactly (or trashes a new one).', array( 'id' => $id, 'product' => array( 'type' => 'object' ), 'variations' => array( 'type' => 'object' ) ), array(), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.product.trash'           => $def( 'MEDIUM', true, array( self::class, 'trash' ), 'Move a product to the trash', 'Moves a product to the trash (not deleted). Undo puts it back as it was.', array( 'id' => $id ), array( 'id' ), $write, 'journal.restore' ),
			'woo.products.import_preview' => $def( 'READ', false, array( self::class, 'import_preview' ), 'Preview a product CSV import', 'Reads a CSV from the file box with WooCommerce\'s own column mapping: which columns are used or ignored, row count, how many rows would create or update products, and the first rows as WooCommerce reads them. Returns expected_rows for the import.', array( 'file_id' => $fid, 'update_existing' => array( 'type' => 'boolean' ), 'keep_ids' => array( 'type' => 'boolean' ) ), array( 'file_id' ), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.products.import'         => $def( 'HIGH', true, array( self::class, 'import_start' ), 'Import products from a CSV', 'Imports a previewed CSV with WooCommerce\'s own importer, in batches in the background (images in the CSV are downloaded). Refused unless the CSV still has expected_rows rows. Ask for progress with woo.products.import_status. One undo trashes the new products and puts updated ones back.', array( 'file_id' => $fid, 'update_existing' => array( 'type' => 'boolean' ), 'keep_ids' => array( 'type' => 'boolean' ), 'expected_rows' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'file_id', 'expected_rows' ), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.products.import_status'  => $def( 'READ', false, array( self::class, 'import_status' ), 'Progress of a product import', 'How far a product import is: rows done, created, updated, skipped, failed (with reasons), and whether it has finished.', array( 'job_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{16}$' ) ), array( 'job_id' ), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.products.export'         => $def( 'LOW', true, array( self::class, 'export' ), 'Export products to a CSV', 'Exports products (all, or by category) with WooCommerce\'s own exporter into the file box; send it to the owner with filebox.send.', array( 'category' => array( 'type' => 'string', 'maxLength' => 200 ), 'types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 6 ) ), array(), $write, 'journal.restore' ) + array( 'skip_health' => true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
		);
	}

	// ---------------------------------------------------------------- products

	/**
	 * One REST request inside WordPress (WooCommerce's own validation and saving).
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $body   Body.
	 * @return array|\WP_Error Response data.
	 */
	private static function rest( $method, $route, array $body = array() ) {
		$req = new \WP_REST_Request( $method, $route );
		foreach ( $body as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$e = $res->as_error();
			return new \WP_Error( 'presshelm_woo', 'WooCommerce refused it: ' . $e->get_error_message() );
		}
		return (array) $res->get_data();
	}

	/**
	 * Images given as files the owner sent: added to the media library first.
	 *
	 * @param array  $images     Images [{id|src|inbox_id, alt?, name?}].
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	private static function images( array $images, $command_id ) {
		foreach ( $images as $i => $img ) {
			if ( is_array( $img ) && ! empty( $img['inbox_id'] ) ) {
				$up = \PressHelm\Abilities\Content::media_upload(
					array(
						'inbox_id' => (string) $img['inbox_id'],
						'alt'      => (string) ( $img['alt'] ?? '' ),
					),
					$command_id
				);
				if ( is_wp_error( $up ) ) {
					return $up;
				}
				$images[ $i ] = array_filter(
					array(
						'id'  => (int) $up['id'],
						'alt' => $img['alt'] ?? null,
					)
				);
			}
		}
		return $images;
	}

	/**
	 * Creates or changes a product (and its variations).
	 *
	 * @param array  $p          {id?, product?, variations?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function save( array $p, $command_id ) {
		$product = (array) ( $p['product'] ?? array() );
		$vars    = (array) ( $p['variations'] ?? array() );
		$id      = (int) ( $p['id'] ?? 0 );
		if ( ! $id && ! $product ) {
			return new \WP_Error( 'presshelm_payload', 'Give product fields (and an id to change an existing product).' );
		}
		if ( $id && ! wc_get_product( $id ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No product with that id.' );
		}
		unset( $product['id'] );
		if ( \PressHelm\Abilities\Generic::code_in( array( $product, $vars ) ) ) {
			return new \WP_Error( 'presshelm_code', 'PressHelm does not add code (scripts, styles, iframes, event handlers) to products. Nothing was changed. Leave that markup out.' );
		}
		if ( isset( $product['images'] ) ) {
			$product['images'] = self::images( (array) $product['images'], $command_id );
			if ( is_wp_error( $product['images'] ) ) {
				return $product['images'];
			}
		}
		$journal = $id ? Journal::record( $command_id, 'woo_snapshot', (string) $id, array( 'snap' => Snapshot::capture( $id ) ) ) : null;
		if ( $product ) {
			$data = self::rest( $id ? 'PUT' : 'POST', '/wc/v3/products' . ( $id ? '/' . $id : '' ), $product );
			if ( is_wp_error( $data ) ) {
				if ( $journal ) {
					Journal::restore( $journal );
				}
				return $data;
			}
			$id = (int) $data['id'];
		}
		if ( ! $journal ) {
			$journal = Journal::record( $command_id, 'post_created', (string) $id, array() );
		}
		$batch = array();
		if ( $vars ) {
			foreach ( array( 'create', 'update' ) as $op ) {
				foreach ( (array) ( $vars[ $op ] ?? array() ) as $k => $v ) {
					if ( isset( $v['image'] ) && is_array( $v['image'] ) && ! empty( $v['image']['inbox_id'] ) ) {
						$img = self::images( array( $v['image'] ), $command_id );
						if ( is_wp_error( $img ) ) {
							Journal::restore( $journal );
							return $img;
						}
						$vars[ $op ][ $k ]['image'] = $img[0];
					}
				}
			}
			$res = self::rest( 'POST', '/wc/v3/products/' . $id . '/variations/batch', array_intersect_key( $vars, array_flip( array( 'create', 'update', 'delete' ) ) ) );
			if ( is_wp_error( $res ) ) {
				Journal::restore( $journal );
				return $res;
			}
			foreach ( array( 'create', 'update', 'delete' ) as $op ) {
				foreach ( (array) ( $res[ $op ] ?? array() ) as $item ) {
					$batch[ $op ][] = isset( $item['error'] ) ? array( 'error' => (string) ( $item['error']['message'] ?? 'refused' ) ) : (int) ( $item['id'] ?? 0 );
				}
			}
		}
		// Let WooCommerce settle what it derives (variable price ranges, stock status) now, not at the end of the request.
		$now = wc_get_product( $id );
		if ( $now && $now->is_type( 'variable' ) ) {
			\WC_Product_Variable::sync( $id );
			wc_delete_product_transients( $id );
			$now = wc_get_product( $id );
		}
		return array(
			'id'         => $id,
			'name'       => $now ? $now->get_name() : '',
			'type'       => $now ? $now->get_type() : '',
			'status'     => $now ? $now->get_status() : '',
			'price'      => $now ? $now->get_price() : '',
			'images'     => $now ? count( array_filter( array_merge( array( $now->get_image_id() ), $now->get_gallery_image_ids() ) ) ) : 0,
			'variations' => $now && $now->is_type( 'variable' ) ? count( $now->get_children() ) : null,
			'batch'      => $batch ? $batch : null,
			'link'       => $now ? get_permalink( $id ) : '',
			'journal_id' => $journal,
		);
	}

	/**
	 * Moves a product to the trash.
	 *
	 * @param array  $p          {id}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function trash( array $p, $command_id ) {
		$id = (int) $p['id'];
		if ( ! wc_get_product( $id ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No product with that id.' );
		}
		$journal = Journal::record( $command_id, 'woo_snapshot', (string) $id, array( 'snap' => Snapshot::capture( $id ) ) );
		$r       = self::rest( 'DELETE', '/wc/v3/products/' . $id, array( 'force' => false ) );
		if ( is_wp_error( $r ) ) {
			Journal::restore( $journal );
			return $r;
		}
		return array(
			'id'         => $id,
			'status'     => 'trash',
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- import

	/**
	 * WooCommerce's own column mapping for these headers (header => field, '' = ignored).
	 *
	 * @param string[] $headers Headers.
	 * @return array
	 */
	private static function mapping( array $headers ) {
		require_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
		$mapper = new class() extends \WC_Product_CSV_Importer_Controller {
			/**
			 * Public access to WooCommerce's protected auto mapping.
			 *
			 * @param string[] $h Headers.
			 * @return array
			 */
			public function map( array $h ) {
				return $this->auto_map_columns( $h, false );
			}
		};
		return (array) $mapper->map( $headers );
	}

	/**
	 * What to do with the CSV's ID column. Product IDs from another site usually clash with posts
	 * here (WooCommerce then skips those rows). When the file does not refer to products by ID
	 * ("id:123" in parent, grouped, upsell or cross-sell columns), the ID column is ignored and
	 * WooCommerce assigns new IDs; links by SKU keep working.
	 *
	 * @param string    $path            CSV.
	 * @param string[]  $headers         Headers.
	 * @param bool      $update_existing Update existing products.
	 * @param bool|null $keep_ids        Owner's choice, or null for automatic.
	 * @return array{mapping:array,keep_ids:bool,note:string}
	 */
	private static function id_plan( $path, array $headers, $update_existing, $keep_ids ) {
		$map    = self::mapping( $headers );
		$id_col = array_search( 'id', $map, true );
		if ( false === $id_col ) {
			return array(
				'mapping'  => $map,
				'keep_ids' => false,
				'note'     => 'The CSV has no ID column: WooCommerce assigns new IDs.',
			);
		}
		$raw   = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- box file.
		$by_id = (bool) preg_match( '/(^|[,"\s])id:\d+/m', $raw );
		$rows  = ( new \WC_Product_CSV_Importer( $path, array( 'mapping' => $map ) ) )->get_raw_data();
		$index = array_search( $id_col, $headers, true );
		$clash = 0;
		foreach ( $rows as $r ) {
			$id   = (int) ( $r[ $index ] ?? 0 );
			$post = $id ? get_post( $id ) : null;
			if ( $post && ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				++$clash;
			} elseif ( $post && ! $update_existing ) {
				++$clash;
			}
		}
		$keep = null === $keep_ids ? ( 0 === $clash || $by_id || $update_existing ) : (bool) $keep_ids;
		if ( ! $keep ) {
			$map[ $id_col ] = '';
		}
		if ( $keep ) {
			$note = $clash ? $clash . ' rows have IDs that are taken on this site; WooCommerce will skip them' . ( $by_id ? ' (the file links products by ID, so the IDs are kept)' : '' ) . '. keep_ids=false lets WooCommerce assign new IDs.' : 'The CSV\'s product IDs are used.';
		} else {
			$note = 'The CSV\'s ID column is ignored' . ( $clash ? ' (' . $clash . ' of its IDs are taken on this site)' : '' ) . ': WooCommerce assigns new IDs; products are linked by SKU.';
		}
		return array(
			'mapping'  => $map,
			'keep_ids' => $keep,
			'note'     => $note,
		);
	}

	/**
	 * A CSV file from the box, checked.
	 *
	 * @param string $file_id Box file id.
	 * @return string|\WP_Error Path.
	 */
	private static function csv( $file_id ) {
		$f = FileBox::get( $file_id );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( 'csv' !== $f['record']['kind'] ) {
			return new \WP_Error( 'presshelm_file_type', 'That file is not a CSV (it is ' . $f['record']['kind'] . ').' );
		}
		require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		return $f['path'];
	}

	/**
	 * Previews an import.
	 *
	 * @param array $p {file_id, update_existing?}.
	 * @return array|\WP_Error
	 */
	public static function import_preview( array $p ) {
		$path = self::csv( (string) $p['file_id'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$head    = new \WC_Product_CSV_Importer( $path, array( 'lines' => 1 ) );
		$headers = $head->get_raw_keys();
		$plan    = self::id_plan( $path, $headers, ! empty( $p['update_existing'] ), $p['keep_ids'] ?? null );
		$map     = $plan['mapping'];
		// Raw rows only: WooCommerce's parser creates placeholder products for SKUs it does not
		// know yet, and a preview must not write anything.
		$rows   = ( new \WC_Product_CSV_Importer( $path, array() ) )->get_raw_data();
		$col    = array_flip( array_keys( $map ) );
		$field  = static function ( array $r, $wanted ) use ( $map, $col ) {
			$h = array_search( $wanted, $map, true );
			return false === $h ? '' : trim( (string) ( $r[ $col[ $h ] ] ?? '' ) );
		};
		$new    = 0;
		$update = 0;
		foreach ( $rows as $r ) {
			$id     = (int) $field( $r, 'id' );
			$sku    = $field( $r, 'sku' );
			$exists = ( $id && wc_get_product( $id ) ) || ( '' !== $sku && wc_get_product_id_by_sku( $sku ) );
			if ( $exists ) {
				++$update;
			} else {
				++$new;
			}
		}
		$sample = array();
		foreach ( array_slice( $rows, 0, 3 ) as $r ) {
			$images   = array_filter( array_map( 'trim', explode( ',', $field( $r, 'images' ) ) ) );
			$sample[] = array_filter(
				array(
					'type'       => $field( $r, 'type' ),
					'name'       => $field( $r, 'name' ),
					'sku'        => $field( $r, 'sku' ),
					'price'      => $field( $r, 'regular_price' ),
					'categories' => $field( $r, 'category_ids' ),
					'images'     => $images ? count( $images ) . ' image(s), downloaded from their links' : '',
				)
			);
		}
		$used    = array();
		$ignored = array();
		foreach ( $map as $h => $field ) {
			if ( '' === (string) $field ) {
				$ignored[] = $h;
			} else {
				$used[ $h ] = $field;
			}
		}
		$update_existing = ! empty( $p['update_existing'] );
		return array(
			'ids'           => $plan['note'],
			'keep_ids'      => $plan['keep_ids'],
			'rows'          => count( $rows ),
			'expected_rows' => count( $rows ),
			'would_create'  => $new,
			'would_update'  => $update_existing ? $update : 0,
			'existing_rows' => $update_existing ? 0 : $update,
			'note'          => $update && ! $update_existing ? $update . ' rows match existing products (by ID or SKU): WooCommerce skips them unless update_existing is true.' : null,
			'columns_used'  => $used,
			'ignored'       => $ignored,
			'first_rows'    => $sample,
		);
	}

	/**
	 * Starts an import (first batch now, the rest in the background).
	 *
	 * @param array  $p          {file_id, update_existing?, expected_rows}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function import_start( array $p, $command_id ) {
		$preview = self::import_preview( $p );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( (int) $preview['rows'] !== (int) $p['expected_rows'] ) {
			return new \WP_Error( 'presshelm_preview_mismatch', 'The CSV has ' . (int) $preview['rows'] . ' rows, not ' . (int) $p['expected_rows'] . ': preview it again. Nothing was imported.' );
		}
		$f = FileBox::get( (string) $p['file_id'] );
		// The job keeps its own copy: the box file may expire or be deleted meanwhile.
		$copy = FileBox::dir() . 'import-' . bin2hex( random_bytes( 8 ) ) . '.csv';
		copy( $f['path'], $copy );
		$job = bin2hex( random_bytes( 8 ) );
		$jid = Journal::record( $command_id, 'woo_import', $job, array() );
		update_option(
			self::JOB_PREFIX . $job,
			array(
				'file'            => $copy,
				'name'            => $f['record']['name'],
				'mapping'         => self::id_plan( $copy, ( new \WC_Product_CSV_Importer( $copy, array( 'lines' => 1 ) ) )->get_raw_keys(), ! empty( $p['update_existing'] ), $p['keep_ids'] ?? null )['mapping'],
				'update_existing' => ! empty( $p['update_existing'] ),
				'position'        => 0,
				'rows'            => (int) $preview['rows'],
				'done_rows'       => 0,
				'created'         => array(),
				'updated'         => array(),
				'variations'      => array(),
				'skipped'         => array(),
				'failed'          => array(),
				'snapshots'       => array(),
				'status'          => 'running',
				// Posts with a higher id did not exist before the import: never snapshotted as "updated".
				'max_id_before'   => (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name, no input.
				'user'            => get_current_user_id(),
				'started'         => time(),
				'journal_id'      => $jid,
			),
			false
		);
		self::batch( $job );
		$state = self::import_status( array( 'job_id' => $job ) );
		return $state + array(
			'journal_id' => $jid,
			'next'       => 'running' === $state['status'] ? 'It continues in the background; ask woo.products.import_status with job_id for progress.' : null,
		);
	}

	/**
	 * Background continuation (WP-Cron / Action Scheduler).
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	public static function continue_job( $job ) {
		$state = get_option( self::JOB_PREFIX . sanitize_key( (string) $job ) );
		if ( ! is_array( $state ) || 'running' !== $state['status'] ) {
			return;
		}
		if ( \PressHelm\Recovery\EmergencyStop::active() ) {
			$state['status'] = 'stopped (emergency stop)';
			update_option( self::JOB_PREFIX . $job, $state, false );
			return;
		}
		$token = \PressHelm\Bridge\TaskUser::begin( (int) $state['user'] );
		try {
			self::batch( (string) $job );
		} finally {
			\PressHelm\Bridge\TaskUser::end( $token );
		}
	}

	/**
	 * Imports the next batch of rows.
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	private static function batch( $job ) {
		$key   = self::JOB_PREFIX . $job;
		$state = get_option( $key );
		require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		// Snapshot every existing product the importer is about to change (for undo).
		$snap = static function ( $item ) use ( &$state ) {
			if ( $item instanceof \WC_Product && $item->get_id() && $item->get_id() <= (int) $state['max_id_before'] && ! isset( $state['snapshots'][ $item->get_id() ] ) && count( $state['snapshots'] ) < self::MAX_SNAPS ) {
				$s = Snapshot::capture( $item->get_id() );
				if ( $s && 'importing' !== ( $s['fields']['post_status'] ?? '' ) ) {
					$state['snapshots'][ $item->get_id() ] = $s;
				}
			}
			return $item;
		};
		add_filter( 'woocommerce_product_import_pre_insert_product_object', $snap, 1 );
		try {
			$importer = new \WC_Product_CSV_Importer(
				$state['file'],
				array(
					'start_pos'       => (int) $state['position'],
					'lines'           => self::BATCH,
					'mapping'         => $state['mapping'],
					'update_existing' => (bool) $state['update_existing'],
					'parse'           => true,
				)
			);
			$r        = $importer->import();
		} catch ( \Throwable $e ) {
			$state['status']   = 'failed';
			$state['failed'][] = 'The importer stopped: ' . substr( $e->getMessage(), 0, 300 );
			update_option( $key, $state, false );
			return;
		} finally {
			remove_filter( 'woocommerce_product_import_pre_insert_product_object', $snap, 1 );
		}
		$state['created']    = array_values( array_unique( array_merge( $state['created'], array_map( 'intval', (array) $r['imported'] ) ) ) );
		$state['variations'] = array_values( array_unique( array_merge( $state['variations'], array_map( 'intval', (array) $r['imported_variations'] ) ) ) );
		$state['updated']    = array_values( array_unique( array_merge( $state['updated'], array_map( 'intval', (array) $r['updated'] ) ) ) );
		foreach ( array( 'skipped', 'failed' ) as $k ) {
			foreach ( (array) $r[ $k ] as $err ) {
				if ( count( $state[ $k ] ) < 50 ) {
					$state[ $k ][] = is_wp_error( $err ) ? $err->get_error_message() . ( $err->get_error_data() && isset( $err->get_error_data()['row'] ) ? ' (row ' . $err->get_error_data()['row'] . ')' : '' ) : (string) $err;
				}
			}
		}
		$state['done_rows'] += count( (array) $r['imported'] ) + count( (array) $r['imported_variations'] ) + count( (array) $r['updated'] ) + count( (array) $r['skipped'] ) + count( (array) $r['failed'] );
		$state['position']   = (int) $importer->get_file_position();
		if ( 100 <= (int) $importer->get_percent_complete() || $state['done_rows'] >= $state['rows'] ) {
			$state['status']   = 'finished';
			$state['finished'] = time();
			self::cleanup_placeholders();
			wp_delete_file( $state['file'] );
		}
		update_option( $key, $state, false );
		if ( 'running' === $state['status'] ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, array( $job ), 'presshelm' );
			} else {
				wp_schedule_single_event( time(), self::HOOK, array( $job ) );
				spawn_cron();
			}
		}
	}

	/**
	 * Placeholder products WooCommerce makes for SKU references, as its own importer cleans them.
	 *
	 * @return void
	 */
	private static function cleanup_placeholders() {
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_original_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_query_meta_key -- the same clean-up WooCommerce's importer does at the end.
		foreach ( array( 'product', 'product_variation' ) as $type ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
				$wpdb->posts,
				array(
					'post_type'   => $type,
					'post_status' => 'importing',
				)
			);
		}
	}

	/**
	 * Progress of an import.
	 *
	 * @param array $p {job_id}.
	 * @return array|\WP_Error
	 */
	public static function import_status( array $p ) {
		$state = get_option( self::JOB_PREFIX . sanitize_key( (string) $p['job_id'] ) );
		if ( ! is_array( $state ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No import with that job id.' );
		}
		// WP-Cron only runs on visits: nudge a stalled job.
		if ( 'running' === $state['status'] && ! wp_next_scheduled( self::HOOK, array( (string) $p['job_id'] ) ) && ! function_exists( 'as_enqueue_async_action' ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( (string) $p['job_id'] ) );
			spawn_cron();
		}
		return array(
			'job_id'     => (string) $p['job_id'],
			'file'       => $state['name'],
			'status'     => $state['status'],
			'rows'       => $state['rows'],
			'done_rows'  => min( $state['done_rows'], $state['rows'] ),
			'created'    => count( $state['created'] ),
			'variations' => count( $state['variations'] ),
			'updated'    => count( $state['updated'] ),
			'skipped'    => $state['skipped'],
			'failed'     => $state['failed'],
			'journal_id' => $state['journal_id'],
		);
	}

	/**
	 * Undo of an import: new products to the trash, updated ones back from their snapshots.
	 *
	 * @param string $job Job id.
	 * @return true|\WP_Error
	 */
	public static function undo_import( $job ) {
		$key   = self::JOB_PREFIX . sanitize_key( (string) $job );
		$state = get_option( $key );
		if ( ! is_array( $state ) ) {
			return true;
		}
		$state['status'] = 'undone';
		update_option( $key, $state, false ); // Stops further batches first.
		// Products the importer first made as placeholders (SKU references) come back as "updated"
		// but had no before-state: they are new too.
		$made = array_merge(
			$state['created'],
			array_filter(
				$state['updated'],
				static function ( $id ) use ( $state ) {
					return (int) $id > (int) $state['max_id_before'];
				}
			)
		);
		foreach ( $made as $pid ) {
			if ( get_post( (int) $pid ) ) {
				wp_trash_post( (int) $pid );
			}
		}
		foreach ( $state['snapshots'] as $snap ) {
			$r = Snapshot::restore( (array) $snap );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------- export

	/**
	 * Exports products to a CSV in the box.
	 *
	 * @param array  $p          {category?, types?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function export( array $p, $command_id ) {
		require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
		$exporter = new \WC_Product_CSV_Exporter();
		$name     = 'presshelm-export-' . bin2hex( random_bytes( 6 ) ) . '.csv';
		$exporter->set_filename( $name );
		$exporter->set_limit( 100000 );
		$exporter->set_page( 1 );
		$exporter->enable_meta_export( true );
		if ( ! empty( $p['category'] ) ) {
			$exporter->set_product_category_to_export( array( sanitize_title( (string) $p['category'] ) ) );
		}
		if ( ! empty( $p['types'] ) ) {
			$exporter->set_product_types_to_export( array_map( 'sanitize_key', (array) $p['types'] ) );
		}
		$exporter->generate_file();
		$tmp = wp_tempnam( 'presshelm-export' );
		file_put_contents( $tmp, $exporter->get_headers_row_file() . $exporter->get_file() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- moved into the file box.
		// WooCommerce's own temporary export files (in the uploads folder).
		$wc_file = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . $name;
		foreach ( array( $wc_file, $wc_file . '.headers' ) as $leftover ) {
			if ( is_file( $leftover ) ) {
				wp_delete_file( $leftover );
			}
		}
		$added = FileBox::add( $tmp, 'products-' . gmdate( 'Y-m-d' ) . '.csv', 'WooCommerce product export' );
		if ( is_wp_error( $added ) ) {
			return $added;
		}
		return $added + array(
			'journal_id' => Journal::record( $command_id, 'filebox_file', $added['file_id'], array() ),
			'products'   => (int) $exporter->get_total_exported(),
			'next'       => 'Send it to the owner with filebox.send.',
		);
	}
}
