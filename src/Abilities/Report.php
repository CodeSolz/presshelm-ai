<?php
/**
 * Read-only reports for Growth Intelligence (cloud G2): WooCommerce sales aggregated per day and per
 * product (no customer names, emails or addresses ever leave the site), and a map of the site's
 * public URLs to posts and products (with canonical URLs and old slugs) so search and analytics data
 * can be matched to the right content.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reports.
 */
final class Report {

	/** Most orders read in one call (a longer range is split by the cloud). */
	const MAX_ORDERS = 5000;

	/** Items per URL map page. */
	const MAP_PAGE = 500;

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$read = array(
			'readonly'   => true,
			'idempotent' => true,
		);
		return array(
			'woo.report'   => array(
				'risk'        => 'READ',
				'cap'         => 'view_woocommerce_reports',
				'mutates'     => false,
				'run'         => array( self::class, 'woo_report' ),
				'label'       => 'Store sales report',
				'description' => 'Paid orders, revenue, refunds and units per product per day (site time zone), for up to 31 days. Totals only: no customer data.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'from' => $date,
						'to'   => $date,
					),
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'site.url_map' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'url_map' ),
				'label'       => 'Public URLs of the site',
				'description' => 'Published posts, pages, products and other public content with their address, title, last change, canonical URL and old slugs, 500 per page.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 1000,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
		);
	}

	/**
	 * Sales per day and per product.
	 *
	 * @param array $p {from, to}.
	 * @return array|\WP_Error
	 */
	public static function woo_report( array $p ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'presshelm_no_woo', 'WooCommerce is not active on this site.' );
		}
		$tz   = wp_timezone();
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $p['from'], $tz );
		$to   = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $p['to'], $tz );
		if ( ! $from || ! $to || $to < $from ) {
			return new \WP_Error( 'presshelm_payload', 'from and to must be dates (YYYY-MM-DD), from not after to.' );
		}
		if ( (int) $from->diff( $to )->days > 30 ) {
			return new \WP_Error( 'presshelm_payload', 'At most 31 days per report.' );
		}
		$start     = $from->getTimestamp();
		$end       = $to->modify( '+1 day' )->getTimestamp() - 1;
		$statuses  = array_map(
			static function ( $s ) {
				return 'wc-' . $s;
			},
			wc_get_is_paid_statuses()
		);
		$days      = array();
		$products  = array();
		$seen      = 0;
		$truncated = false;
		for ( $page = 1; $page <= 50; $page++ ) {
			$orders = wc_get_orders(
				array(
					'type'      => 'shop_order',
					'status'    => $statuses,
					'date_paid' => $start . '...' . $end,
					'limit'     => 100,
					'paged'     => $page,
					'orderby'   => 'date',
					'order'     => 'ASC',
				)
			);
			if ( ! $orders ) {
				break;
			}
			foreach ( $orders as $order ) {
				if ( ++$seen > self::MAX_ORDERS ) {
					$truncated = true;
					break 2;
				}
				$paid = $order->get_date_paid();
				$day  = $paid ? $paid->date_i18n( 'Y-m-d' ) : $p['from'];
				if ( ! isset( $days[ $day ] ) ) {
					$days[ $day ] = array(
						'date'     => $day,
						'orders'   => 0,
						'revenue'  => 0.0,
						'refunds'  => 0.0,
						'items'    => 0,
						'discount' => 0.0,
					);
				}
				++$days[ $day ]['orders'];
				$days[ $day ]['revenue']  += (float) $order->get_total();
				$days[ $day ]['discount'] += (float) $order->get_discount_total();
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}
					$pid = (int) $item->get_product_id();
					$key = $day . '|' . $pid;
					if ( ! isset( $products[ $key ] ) ) {
						$products[ $key ] = array(
							'date'       => $day,
							'product_id' => $pid,
							'name'       => mb_substr( (string) $item->get_name(), 0, 200 ),
							'orders'     => 0,
							'qty'        => 0,
							'revenue'    => 0.0,
						);
					}
					++$products[ $key ]['orders'];
					$products[ $key ]['qty']     += (int) $item->get_quantity();
					$products[ $key ]['revenue'] += (float) $item->get_total();
					$days[ $day ]['items']       += (int) $item->get_quantity();
				}
			}
			if ( count( $orders ) < 100 ) {
				break;
			}
		}
		// Refunds by the day they were made.
		$refunds     = wc_get_orders(
			array(
				'type'         => 'shop_order_refund',
				'date_created' => $start . '...' . $end,
				'limit'        => 2000,
			)
		);
		$refund_rows = array();
		foreach ( (array) $refunds as $refund ) {
			$created = $refund->get_date_created();
			$day     = $created ? $created->date_i18n( 'Y-m-d' ) : $p['from'];
			if ( isset( $days[ $day ] ) ) {
				$days[ $day ]['refunds'] += (float) $refund->get_amount();
			} else {
				$days[ $day ] = array(
					'date'     => $day,
					'orders'   => 0,
					'revenue'  => 0.0,
					'refunds'  => (float) $refund->get_amount(),
					'items'    => 0,
					'discount' => 0.0,
				);
			}
			foreach ( $refund->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$key = $day . '|' . (int) $item->get_product_id();
					if ( ! isset( $refund_rows[ $key ] ) ) {
						$refund_rows[ $key ] = array(
							'date'       => $day,
							'product_id' => (int) $item->get_product_id(),
							'qty'        => 0,
							'amount'     => 0.0,
						);
					}
					$refund_rows[ $key ]['qty']    += abs( (int) $item->get_quantity() );
					$refund_rows[ $key ]['amount'] += abs( (float) $item->get_total() );
				}
			}
		}
		ksort( $days );
		$round = static function ( array $rows, array $money ) {
			return array_values(
				array_map(
					static function ( $r ) use ( $money ) {
						foreach ( $money as $k ) {
							$r[ $k ] = round( (float) $r[ $k ], 2 );
						}
						return $r;
					},
					$rows
				)
			);
		};
		return array(
			'from'          => (string) $p['from'],
			'to'            => (string) $p['to'],
			'timezone'      => wp_timezone_string(),
			'currency'      => get_woocommerce_currency(),
			'paid_statuses' => wc_get_is_paid_statuses(),
			'revenue_note'  => 'Order totals include tax and shipping; product revenue is the line total after discounts, without tax.',
			'days'          => $round( $days, array( 'revenue', 'refunds', 'discount' ) ),
			'products'      => $round( $products, array( 'revenue' ) ),
			'refunds'       => $round( $refund_rows, array( 'amount' ) ),
			'truncated'     => $truncated,
		);
	}

	/**
	 * Public URLs with their content.
	 *
	 * @param array $p {page?}.
	 * @return array
	 */
	public static function url_map( array $p ) {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$page  = max( 1, (int) ( $p['page'] ?? 1 ) );
		$q     = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::MAP_PAGE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$canonical = (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true );
			$old       = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $post->ID, '_wp_old_slug', false ) ) ) );
			$items[]   = array(
				'id'        => $post->ID,
				'type'      => $post->post_type,
				'url'       => (string) get_permalink( $post ),
				'title'     => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 200 ),
				'modified'  => get_post_modified_time( 'c', true, $post ),
				'canonical' => '' !== $canonical ? $canonical : null,
				'old_slugs' => array_slice( $old, 0, 10 ),
				'noindex'   => '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ),
			);
		}
		return array(
			'home'       => home_url( '/' ),
			'front_page' => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0,
			'posts_page' => (int) get_option( 'page_for_posts' ),
			'timezone'   => wp_timezone_string(),
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'items'      => $items,
			'page'       => $page,
			'pages'      => (int) $q->max_num_pages,
			'total'      => (int) $q->found_posts,
		);
	}
}
