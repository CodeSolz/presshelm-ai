<?php
/**
 * WooCommerce (M3.10), through WooCommerce's own objects (WC_Product, WC_Order: HPOS and classic
 * storage, WooCommerce hooks and caches). Every change is journaled for undo.
 *
 * - products: list, read, create (draft by default), update; bulk price/sale/stock changes with a
 *   dry run bound to the product count (one undo for all);
 * - orders: list and read (customer email masked, no phone or address), status changes (the
 *   customer may get WooCommerce's email: HIGH), private notes;
 * - store settings: an allowlist (address, currency format, coming-soon). Payments, refunds and
 *   taxes are never touched (CRITICAL).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce abilities.
 */
final class Woo {

	const LIST_MAX = 50;
	const BULK_MAX = 200;

	/** Refusal for product text that holds code. */
	const NO_CODE = 'PressHelm does not add code (scripts, styles, iframes, event handlers) to products. Nothing was changed. Leave that markup out.';

	/** Product fields PressHelm reads and writes (WC_Product getter/setter names). */
	const FIELDS = array( 'name', 'sku', 'status', 'regular_price', 'sale_price', 'description', 'short_description', 'manage_stock', 'stock_quantity', 'stock_status', 'category_ids', 'featured', 'catalog_visibility' );

	/** Order statuses PressHelm may set (no refunds: payments are CRITICAL). */
	const ORDER_STATUSES = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled' );

	/** Store settings PressHelm may change. */
	const SETTINGS = array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country', 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep', 'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_coming_soon', 'woocommerce_store_pages_only' );

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Ability definitions (only when WooCommerce is active).
	 *
	 * @return array
	 */
	public static function definitions() {
		if ( ! self::active() ) {
			return array();
		}
		$read    = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$write   = array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$price   = array(
			'type'    => 'string',
			'pattern' => '^(|[0-9]+(\.[0-9]{1,6})?)$',
		);
		$product = array(
			'name'               => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'sku'                => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'status'             => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'private', 'publish' ),
			),
			'regular_price'      => $price,
			'sale_price'         => $price,
			'description'        => array(
				'type'      => 'string',
				'maxLength' => 100000,
			),
			'short_description'  => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
			'manage_stock'       => array( 'type' => 'boolean' ),
			'stock_quantity'     => array(
				'type'    => 'integer',
				'minimum' => -1000000,
			),
			'stock_status'       => array(
				'type' => 'string',
				'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
			),
			'category_ids'       => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => $id,
			),
			'featured'           => array( 'type' => 'boolean' ),
			'catalog_visibility' => array(
				'type' => 'string',
				'enum' => array( 'visible', 'catalog', 'search', 'hidden' ),
			),
		);
		$filter  = array(
			'ids'      => array(
				'type'     => 'array',
				'maxItems' => self::BULK_MAX,
				'items'    => $id,
			),
			'category' => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'search'   => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
		);
		$change  = array(
			'type'                 => 'object',
			'properties'           => array(
				'price_percent'    => array(
					'type'    => 'number',
					'minimum' => -90,
					'maximum' => 500,
				),
				'price_set'        => $price,
				'sale_percent_off' => array(
					'type'    => 'number',
					'minimum' => 1,
					'maximum' => 95,
				),
				'sale_clear'       => array( 'type' => 'boolean' ),
				'stock_status'     => $product['stock_status'],
			),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
		$obj     = static function ( array $props, array $required = array() ) {
			$o = array(
				'type'                 => 'object',
				'properties'           => $props,
				'additionalProperties' => false,
			);
			if ( $required ) {
				$o['required'] = $required;
			}
			return $o;
		};
		$def     = static function ( $risk, $cap, $mutates, $run, $label, $description, $input, $annotations, $undo ) {
			return array(
				'risk'        => $risk,
				'cap'         => $cap,
				'mutates'     => $mutates,
				'run'         => $run,
				'label'       => $label,
				'description' => $description,
				'input'       => $input,
				'annotations' => $annotations,
				'undo'        => $undo,
			);
		};
		return array(
			'woo.products.list'         => $def( 'READ', 'edit_products', false, array( self::class, 'products_list' ), 'List products', 'Products with price, sale price, stock and status (max 50 per page). Filter by search text, status or category slug.', $obj( array_merge( array_intersect_key( $filter, array_flip( array( 'search', 'category' ) ) ), array( 'status' => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'pending', 'private', 'publish' ) ), 'page' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) ), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.product.get'           => $def( 'READ', 'edit_products', false, array( self::class, 'product_get' ), 'Read a product', 'One product with descriptions, categories, images and (for variable products) its variations.', $obj( array( 'id' => $id ), array( 'id' ) ), $read, null ),
			'woo.product.create'        => $def( 'MEDIUM', 'edit_products', true, array( self::class, 'product_create' ), 'Create a product', 'A simple product (draft unless status is given). Undo moves it to the trash.', $obj( $product, array( 'name' ) ), $write, 'journal.restore' ),
			'woo.product.update'        => $def( 'MEDIUM', 'edit_products', true, array( self::class, 'product_update' ), 'Change a product', 'Changes the given fields of a product (or a variation). Undo puts the previous values back.', $obj( array_merge( array( 'id' => $id ), $product ), array( 'id' ) ), $write, 'journal.restore' ),
			'woo.products.bulk_preview' => $def( 'READ', 'edit_products', false, array( self::class, 'bulk_preview' ), 'Dry run of a bulk product change', 'Which simple products a price/sale/stock change would touch (by ids, category or search; max 200), with before/after for each. Returns expected_count.', $obj( array_merge( $filter, array( 'change' => $change ) ), array( 'change' ) ), $read, null ),
			'woo.products.bulk_update'  => $def( 'HIGH', 'edit_products', true, array( self::class, 'bulk_update' ), 'Bulk product change', 'Applies a previewed price/sale/stock change. Refused unless exactly expected_count products match. One undo puts every product back.', $obj( array_merge( $filter, array( 'change' => $change, 'expected_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::BULK_MAX ) ) ), array( 'change', 'expected_count' ) ), array_merge( $write, array( 'destructive' => true ) ), 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.orders.list'           => $def( 'READ', 'edit_shop_orders', false, array( self::class, 'orders_list' ), 'List orders', 'Recent orders: number, status, total, date, customer first name and masked email (no phone or address). Max 50 per page.', $obj( array( 'status' => array( 'type' => 'string', 'enum' => array_merge( array( 'any' ), self::ORDER_STATUSES, array( 'refunded', 'failed' ) ) ), 'page' => array( 'type' => 'integer', 'minimum' => 1 ) ) ), $read, null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.order.get'             => $def( 'READ', 'edit_shop_orders', false, array( self::class, 'order_get' ), 'Read an order', 'Items, totals, status, payment and shipping method names, and notes (customer email masked, no phone or address).', $obj( array( 'id' => $id ), array( 'id' ) ), $read, null ),
			'woo.order.status'          => $def( 'HIGH', 'edit_shop_orders', true, array( self::class, 'order_status' ), 'Change an order status', 'Sets pending, processing, on-hold, completed or cancelled (never refunded: refunds are payments). WooCommerce may email the customer about it, which can\'t be undone; undo sets the previous status.', $obj( array( 'id' => $id, 'status' => array( 'type' => 'string', 'enum' => self::ORDER_STATUSES ) ), array( 'id', 'status' ) ), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.order.note'            => $def( 'MEDIUM', 'edit_shop_orders', true, array( self::class, 'order_note' ), 'Add a private order note', 'A private note on an order (only shop staff see it; the customer is not emailed). Undo deletes it.', $obj( array( 'id' => $id, 'note' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ) ), array( 'id', 'note' ) ), $write, 'journal.restore' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short inline schema.
			'woo.settings.get'          => $def( 'READ', 'manage_woocommerce', false, array( self::class, 'settings_get' ), 'Read store settings', 'Store address, currency and price format, coming-soon mode, plus read-only facts (taxes on, HPOS, WooCommerce version).', $obj( array() ), $read, null ),
		);
	}

	// ---------------------------------------------------------------- products

	/**
	 * Fields of a product (edit context).
	 *
	 * @param \WC_Product $p Product.
	 * @return array
	 */
	public static function fields( $p ) {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			$getter = 'get_' . $f;
			if ( is_callable( array( $p, $getter ) ) ) {
				$out[ $f ] = $p->{$getter}( 'edit' );
			}
		}
		return $out;
	}

	/**
	 * Sets fields on a product (WooCommerce setters validate them).
	 *
	 * @param \WC_Product $p      Product.
	 * @param array       $fields Field → value.
	 */
	public static function apply( $p, array $fields ) {
		foreach ( $fields as $f => $v ) {
			if ( in_array( $f, self::FIELDS, true ) && is_callable( array( $p, 'set_' . $f ) ) ) {
				$p->{'set_' . $f}( $v );
			}
		}
	}

	/**
	 * A product for the agent.
	 *
	 * @param \WC_Product $p    Product.
	 * @param bool        $full With descriptions etc.
	 * @return array
	 */
	private static function product_out( $p, $full = false ) {
		$out = array(
			'id'             => $p->get_id(),
			'type'           => $p->get_type(),
			'parent_id'      => $p->get_parent_id(),
			'name'           => $p->get_name(),
			'sku'            => $p->get_sku(),
			'status'         => $p->get_status(),
			'price'          => $p->get_price(),
			'regular_price'  => $p->get_regular_price(),
			'sale_price'     => $p->get_sale_price(),
			'manage_stock'   => $p->get_manage_stock(),
			'stock_quantity' => $p->get_stock_quantity(),
			'stock_status'   => $p->get_stock_status(),
			'categories'     => wp_list_pluck( wc_get_product_terms( $p->get_id(), 'product_cat' ), 'slug' ),
			'link'           => get_permalink( $p->get_id() ),
		);
		if ( $full ) {
			$out['description']        = $p->get_description();
			$out['short_description']  = $p->get_short_description();
			$out['category_ids']       = $p->get_category_ids();
			$out['image_id']           = $p->get_image_id();
			$out['gallery_image_ids']  = $p->get_gallery_image_ids();
			$out['featured']           = $p->get_featured();
			$out['catalog_visibility'] = $p->get_catalog_visibility();
			$out['variations']         = $p->is_type( 'variable' ) ? $p->get_children() : array();
		}
		return $out;
	}

	/**
	 * Products.
	 *
	 * @param array $p {search?, status?, category?, page?}.
	 * @return array
	 */
	public static function products_list( array $p ) {
		$args = array(
			'limit'    => self::LIST_MAX,
			'page'     => max( 1, (int) ( $p['page'] ?? 1 ) ),
			'status'   => isset( $p['status'] ) && 'any' !== $p['status'] ? (string) $p['status'] : array( 'draft', 'pending', 'private', 'publish' ),
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);
		if ( ! empty( $p['search'] ) ) {
			$args['s'] = (string) $p['search'];
		}
		if ( ! empty( $p['category'] ) ) {
			$args['category'] = array( (string) $p['category'] );
		}
		$r = wc_get_products( $args );
		return array(
			'total'    => (int) $r->total,
			'pages'    => (int) $r->max_num_pages,
			'products' => array_map( array( self::class, 'product_out' ), $r->products ),
		);
	}

	/**
	 * One product.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function product_get( array $p ) {
		$product = wc_get_product( (int) $p['id'] );
		if ( ! $product ) {
			return new \WP_Error( 'presshelm_not_found', 'No product with that id' );
		}
		return self::product_out( $product, true );
	}

	/**
	 * Creates a simple product.
	 *
	 * @param array  $p          Fields.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function product_create( array $p, $command_id ) {
		if ( Generic::code_in( array_intersect_key( $p, array_flip( self::FIELDS ) ) ) ) {
			return new \WP_Error( 'presshelm_code', self::NO_CODE );
		}
		$product = new \WC_Product_Simple();
		try {
			self::apply( $product, array_merge( array( 'status' => 'draft' ), array_intersect_key( $p, array_flip( self::FIELDS ) ) ) );
			$id = $product->save();
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error( 'presshelm_payload', $e->getMessage() );
		}
		if ( ! $id ) {
			return new \WP_Error( 'presshelm_write_failed', 'The product could not be saved' );
		}
		$journal = Journal::record( $command_id, 'post_created', (string) $id, array() );
		return array_merge( self::product_out( wc_get_product( $id ) ), array( 'journal_id' => $journal ) );
	}

	/**
	 * Changes fields of a product or variation.
	 *
	 * @param array  $p          {id, …fields}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function product_update( array $p, $command_id ) {
		$product = wc_get_product( (int) $p['id'] );
		if ( ! $product ) {
			return new \WP_Error( 'presshelm_not_found', 'No product with that id' );
		}
		$set = array_intersect_key( $p, array_flip( self::FIELDS ) );
		if ( ! $set ) {
			return new \WP_Error( 'presshelm_payload', 'Nothing to change' );
		}
		if ( Generic::code_in( $set ) ) {
			return new \WP_Error( 'presshelm_code', self::NO_CODE );
		}
		$before  = array_intersect_key( self::fields( $product ), $set );
		$journal = Journal::record(
			$command_id,
			'woo_products',
			(string) $product->get_id(),
			array(
				'items' => array(
					array(
						'id'     => $product->get_id(),
						'fields' => $before,
					),
				),
			)
		);
		try {
			self::apply( $product, $set );
			$product->save();
		} catch ( \WC_Data_Exception $e ) {
			Journal::restore( $journal );
			return new \WP_Error( 'presshelm_payload', $e->getMessage() );
		}
		return array_merge( self::product_out( wc_get_product( $product->get_id() ) ), array( 'journal_id' => $journal ) );
	}

	/**
	 * Puts saved fields back (journal undo).
	 *
	 * @param array $items [{id, fields}].
	 * @return true|\WP_Error
	 */
	public static function restore_products( array $items ) {
		foreach ( $items as $item ) {
			$product = wc_get_product( (int) ( $item['id'] ?? 0 ) );
			if ( ! $product ) {
				continue;
			}
			try {
				self::apply( $product, (array) ( $item['fields'] ?? array() ) );
				$product->save();
			} catch ( \WC_Data_Exception $e ) {
				return new \WP_Error( 'presshelm_restore_failed', $e->getMessage() );
			}
		}
		return true;
	}

	// ---------------------------------------------------------------- bulk

	/**
	 * The bulk plan: matching simple products and their new values.
	 *
	 * @param array $p {ids?|category?|search?, change}.
	 * @return array|\WP_Error {items, skipped}
	 */
	private static function bulk_plan( array $p ) {
		if ( empty( $p['ids'] ) && empty( $p['category'] ) && empty( $p['search'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'Choose the products: ids, category or search' );
		}
		$change = (array) ( $p['change'] ?? array() );
		if ( ! array_intersect_key( $change, array_flip( array( 'price_percent', 'price_set', 'sale_percent_off', 'sale_clear', 'stock_status' ) ) ) ) {
			return new \WP_Error( 'presshelm_payload', 'Nothing to change' );
		}
		if ( isset( $change['price_percent'], $change['price_set'] ) || ( isset( $change['sale_percent_off'] ) && ! empty( $change['sale_clear'] ) ) ) {
			return new \WP_Error( 'presshelm_payload', 'Conflicting changes: choose one price change and one sale change' );
		}
		$args = array(
			'limit'  => self::BULK_MAX + 1,
			'status' => array( 'draft', 'pending', 'private', 'publish' ),
			'return' => 'objects',
		);
		if ( ! empty( $p['ids'] ) ) {
			$args['include'] = array_map( 'intval', (array) $p['ids'] );
		}
		if ( ! empty( $p['category'] ) ) {
			$args['category'] = array( (string) $p['category'] );
		}
		if ( ! empty( $p['search'] ) ) {
			$args['s'] = (string) $p['search'];
		}
		$decimals = wc_get_price_decimals();
		$items    = array();
		$skipped  = array();
		foreach ( wc_get_products( $args ) as $product ) {
			if ( ! $product->is_type( 'simple' ) ) {
				$skipped[] = array(
					'id'     => $product->get_id(),
					'name'   => $product->get_name(),
					'reason' => $product->get_type() . ' product: change its variations one by one',
				);
				continue;
			}
			$old     = self::fields( $product );
			$new     = array();
			$regular = (string) $old['regular_price'];
			if ( isset( $change['price_set'] ) ) {
				$new['regular_price'] = (string) $change['price_set'];
			} elseif ( isset( $change['price_percent'] ) && '' !== $regular ) {
				$new['regular_price'] = (string) round( (float) $regular * ( 1 + (float) $change['price_percent'] / 100 ), $decimals );
			}
			$base = $new['regular_price'] ?? $regular;
			if ( isset( $change['sale_percent_off'] ) && '' !== $base ) {
				$new['sale_price'] = (string) round( (float) $base * ( 1 - (float) $change['sale_percent_off'] / 100 ), $decimals );
			} elseif ( ! empty( $change['sale_clear'] ) ) {
				$new['sale_price'] = '';
			}
			if ( isset( $change['stock_status'] ) ) {
				$new['stock_status'] = (string) $change['stock_status'];
			}
			$diff = array();
			foreach ( $new as $f => $v ) {
				if ( (string) $old[ $f ] !== (string) $v ) {
					$diff[ $f ] = $v;
				}
			}
			if ( ! $diff ) {
				continue;
			}
			$items[] = array(
				'product' => $product,
				'before'  => array_intersect_key( $old, $diff ),
				'after'   => $diff,
			);
		}
		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Bulk dry run.
	 *
	 * @param array $p {ids?|category?|search?, change}.
	 * @return array|\WP_Error
	 */
	public static function bulk_preview( array $p ) {
		$plan = self::bulk_plan( $p );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$n = count( $plan['items'] );
		return array(
			'expected_count' => $n > self::BULK_MAX ? null : $n,
			'allowed'        => $n > 0 && $n <= self::BULK_MAX,
			'reason'         => 0 === $n ? 'No product would change' : ( $n > self::BULK_MAX ? 'More than ' . self::BULK_MAX . ' products; narrow the selection' : '' ),
			'products'       => array_map(
				static function ( $i ) {
					return array(
						'id'     => $i['product']->get_id(),
						'name'   => $i['product']->get_name(),
						'before' => $i['before'],
						'after'  => $i['after'],
					);
				},
				array_slice( $plan['items'], 0, 50 )
			),
			'skipped'        => $plan['skipped'],
		);
	}

	/**
	 * Bulk change (bound to the dry run's count; one journal entry for all).
	 *
	 * @param array  $p          {ids?|category?|search?, change, expected_count}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function bulk_update( array $p, $command_id ) {
		$plan = self::bulk_plan( $p );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$n = count( $plan['items'] );
		if ( $n !== (int) $p['expected_count'] || $n > self::BULK_MAX ) {
			return new \WP_Error( 'presshelm_conflict', sprintf( '%d products would change now, not %d as previewed; run the dry run again', $n, (int) $p['expected_count'] ) );
		}
		$journal = Journal::record(
			$command_id,
			'woo_products',
			'bulk',
			array(
				'items' => array_map(
					static function ( $i ) {
						return array(
							'id'     => $i['product']->get_id(),
							'fields' => $i['before'],
						);
					},
					$plan['items']
				),
			)
		);
		foreach ( $plan['items'] as $i ) {
			try {
				self::apply( $i['product'], $i['after'] );
				$i['product']->save();
			} catch ( \WC_Data_Exception $e ) {
				Journal::restore( $journal );
				return new \WP_Error( 'presshelm_payload', 'Product #' . (int) $i['product']->get_id() . ': ' . $e->getMessage() . ' (everything already changed was put back)' );
			}
		}
		return array(
			'changed'    => $n,
			'journal_id' => $journal,
		);
	}

	// ---------------------------------------------------------------- orders

	/**
	 * A masked email (j***@example.com).
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '' === $email ? '' : '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * An order for the agent (no phone or address; email masked).
	 *
	 * @param \WC_Order $o    Order.
	 * @param bool      $full With items and notes.
	 * @return array
	 */
	private static function order_out( $o, $full = false ) {
		$out = array(
			'id'          => $o->get_id(),
			'number'      => $o->get_order_number(),
			'status'      => $o->get_status(),
			'total'       => $o->get_total(),
			'currency'    => $o->get_currency(),
			'date'        => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
			'customer'    => trim( $o->get_billing_first_name() . ' ' . mb_substr( (string) $o->get_billing_last_name(), 0, 1 ) . ( '' !== (string) $o->get_billing_last_name() ? '.' : '' ) ),
			'email'       => self::mask_email( $o->get_billing_email() ),
			'items_count' => $o->get_item_count(),
		);
		if ( $full ) {
			$out['items']    = array_values(
				array_map(
					static function ( $item ) {
						return array(
							'name'       => $item->get_name(),
							'product_id' => $item->get_product_id(),
							'quantity'   => $item->get_quantity(),
							'total'      => $item->get_total(),
						);
					},
					$o->get_items()
				)
			);
			$out['payment']  = $o->get_payment_method_title();
			$out['shipping'] = $o->get_shipping_method();
			$out['subtotal'] = $o->get_subtotal();
			$out['tax']      = $o->get_total_tax();
			$out['notes']    = array_map(
				static function ( $n ) {
					return array(
						'id'       => (int) $n->id,
						'date'     => $n->date_created ? $n->date_created->date( 'c' ) : null,
						'customer' => (bool) $n->customer_note,
						'text'     => wp_strip_all_tags( (string) $n->content ),
					);
				},
				array_slice( (array) wc_get_order_notes( array( 'order_id' => $o->get_id() ) ), 0, 20 )
			);
		}
		return $out;
	}

	/**
	 * Orders.
	 *
	 * @param array $p {status?, page?}.
	 * @return array
	 */
	public static function orders_list( array $p ) {
		$r = wc_get_orders(
			array(
				'limit'    => self::LIST_MAX,
				'paged'    => max( 1, (int) ( $p['page'] ?? 1 ) ),
				'status'   => isset( $p['status'] ) && 'any' !== $p['status'] ? array( 'wc-' . $p['status'] ) : array_keys( wc_get_order_statuses() ),
				'orderby'  => 'date',
				'order'    => 'DESC',
				'type'     => 'shop_order',
				'paginate' => true,
			)
		);
		return array(
			'total'  => (int) $r->total,
			'pages'  => (int) $r->max_num_pages,
			'orders' => array_map( array( self::class, 'order_out' ), $r->orders ),
		);
	}

	/**
	 * One order.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function order_get( array $p ) {
		$o = wc_get_order( (int) $p['id'] );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			return new \WP_Error( 'presshelm_not_found', 'No order with that id' );
		}
		return self::order_out( $o, true );
	}

	/**
	 * Order status (journaled).
	 *
	 * @param array  $p          {id, status}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function order_status( array $p, $command_id ) {
		$o      = wc_get_order( (int) $p['id'] );
		$status = (string) $p['status'];
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			return new \WP_Error( 'presshelm_not_found', 'No order with that id' );
		}
		if ( ! in_array( $status, self::ORDER_STATUSES, true ) ) {
			return new \WP_Error( 'presshelm_payload', 'That status can\'t be set by PressHelm' );
		}
		if ( $o->get_status() === $status ) {
			return new \WP_Error( 'presshelm_no_change', 'The order already has that status' );
		}
		$journal = Journal::record( $command_id, 'woo_order_status', (string) $o->get_id(), array( 'status' => $o->get_status() ) );
		$o->update_status( $status, __( 'Status changed by PressHelm (approved by the site owner).', 'presshelm-ai' ) );
		return array(
			'id'         => $o->get_id(),
			'status'     => $status,
			'journal_id' => $journal,
		);
	}

	/**
	 * Private order note (journaled).
	 *
	 * @param array  $p          {id, note}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function order_note( array $p, $command_id ) {
		$o = wc_get_order( (int) $p['id'] );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			return new \WP_Error( 'presshelm_not_found', 'No order with that id' );
		}
		$note_id = $o->add_order_note( sanitize_textarea_field( (string) $p['note'] ), 0, false );
		if ( ! $note_id ) {
			return new \WP_Error( 'presshelm_write_failed', 'The note could not be added' );
		}
		return array(
			'note_id'    => (int) $note_id,
			'journal_id' => Journal::record( $command_id, 'woo_order_note', (string) $note_id, array( 'order_id' => $o->get_id() ) ),
		);
	}

	// ---------------------------------------------------------------- settings

	/**
	 * Store settings.
	 *
	 * @return array
	 */
	public static function settings_get() {
		$out = array();
		foreach ( self::SETTINGS as $name ) {
			$out[ $name ] = get_option( $name );
		}
		return array(
			'settings'    => $out,
			'read_only'   => array(
				'woocommerce_version' => WC()->version,
				'taxes_enabled'       => wc_tax_enabled(),
				'hpos'                => class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
				'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ),
			),
			'not_changed' => 'Payments, refunds, taxes and shipping are never changed by PressHelm.',
		);
	}
}
