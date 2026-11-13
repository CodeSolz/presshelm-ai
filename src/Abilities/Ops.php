<?php
/**
 * WP-CLI-like read-only diagnostics in PHP (M3.12). The plugin never runs a shell or real WP-CLI,
 * and nothing here changes the site:
 *
 * - ops.cron_list: the scheduled events;
 * - ops.option_get: read options (secrets redacted, like db.rows);
 * - ops.search_replace_preview: where a text appears in chosen tables and what a replacement would
 *   change (a dry run; this plugin does not replace across the database).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance operations.
 */
final class Ops {

	const SR_MAX_ROWS   = 2000;
	const SR_TABLES     = array( 'posts', 'postmeta', 'options', 'termmeta', 'comments', 'commentmeta' );
	const TEXT_TYPES    = '/^(char|varchar|tinytext|text|mediumtext|longtext)\b/i';
	const SR_SAMPLE     = 5;
	const SR_MAX_TABLES = 10;
	const SR_SEARCH_MIN = 3;
	const SR_SEARCH_MAX = 500;

	/**
	 * Ability definitions (merged into Registry::all()).
	 *
	 * @return array
	 */
	public static function definitions() {
		$read = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
		$sr   = array(
			'search'  => array(
				'type'      => 'string',
				'minLength' => self::SR_SEARCH_MIN,
				'maxLength' => self::SR_SEARCH_MAX,
			),
			'replace' => array(
				'type'      => 'string',
				'maxLength' => self::SR_SEARCH_MAX,
			),
			'tables'  => array(
				'type'     => 'array',
				'maxItems' => self::SR_MAX_TABLES,
				'items'    => array(
					'type'    => 'string',
					'pattern' => '^[A-Za-z0-9_$]{1,64}$',
				),
			),
		);
		return array(
			'ops.cron_list'              => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'cron_list' ),
				'label'       => 'List scheduled tasks (cron)',
				'description' => 'Like `wp cron event list`: every scheduled event with its next run, schedule and whether it is overdue; plus whether WP-Cron is disabled.',
				'input'       => $none,
				'annotations' => $read,
				'undo'        => null,
			),
			'ops.option_get'             => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'option_get' ),
				'label'       => 'Read options',
				'description' => 'Like `wp option get`: values of up to 20 options by name (passwords, keys and tokens are redacted).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'names' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 20,
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 191,
							),
						),
					),
					'required'             => array( 'names' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'ops.search_replace_preview' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'search_replace_preview' ),
				'label'       => 'Dry run of a search-and-replace',
				'description' => 'Like `wp search-replace --dry-run`: how many rows per table and column contain the text, a before/after sample, and rows that would be skipped (protected, or data that can\'t be rewritten safely). Default tables: posts, postmeta, options, termmeta, comments, commentmeta. Returns expected_rows.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $sr,
					'required'             => array( 'search', 'replace' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
		);
	}

	// ---------------------------------------------------------------- simple operations

	/**
	 * Scheduled events.
	 *
	 * @return array
	 */
	public static function cron_list() {
		$out = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $e ) {
					$out[] = array(
						'hook'     => (string) $hook,
						'next_run' => gmdate( 'c', (int) $ts ),
						'overdue'  => (int) $ts < time() - 60,
						'schedule' => ! empty( $e['schedule'] ) ? (string) $e['schedule'] : 'once',
						'args'     => count( (array) ( $e['args'] ?? array() ) ),
					);
				}
			}
		}
		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'events'           => $out,
		);
	}

	/**
	 * Option values (secrets redacted).
	 *
	 * @param array $p {names}.
	 * @return array
	 */
	public static function option_get( array $p ) {
		$out      = array();
		$sentinel = new \stdClass();
		foreach ( array_slice( (array) $p['names'], 0, 20 ) as $name ) {
			$name = (string) $name;
			if ( DbRules::is_secret_key( $name ) ) {
				$out[ $name ] = DbRules::REDACTED;
				continue;
			}
			$v            = get_option( $name, $sentinel );
			$out[ $name ] = $v === $sentinel ? null : self::clip_value( $v );
		}
		return array( 'options' => $out );
	}

	/**
	 * Long values clipped for the agent (arrays kept, strings cut).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	private static function clip_value( $v ) {
		if ( is_string( $v ) ) {
			return strlen( $v ) > 4000 ? substr( $v, 0, 4000 ) . '… [' . strlen( $v ) . ' bytes]' : $v;
		}
		if ( is_array( $v ) ) {
			$json = (string) wp_json_encode( $v );
			return strlen( $json ) > 8000 ? '[array, ' . count( $v ) . ' items, ' . strlen( $json ) . ' bytes as JSON]' : $v;
		}
		return is_object( $v ) ? '[object]' : $v;
	}

	// ---------------------------------------------------------------- search-replace

	/**
	 * Replaces text in a value; serialized PHP data is unserialized, changed and re-serialized
	 * so string lengths stay right. Objects are never instantiated.
	 *
	 * @param string $value   Stored value.
	 * @param string $search  Text to find.
	 * @param string $replace Replacement.
	 * @return string|null New value, or null when it can't be rewritten safely (objects).
	 */
	public static function replace_value( $value, $search, $replace ) {
		$value = (string) $value;
		if ( false === strpos( $value, $search ) ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			// Stored WordPress data; allowed_classes false never instantiates objects (unlike
			// maybe_unserialize), and broken data is reported as "can't rewrite", not as a warning.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $data && 'b:0;' !== $value ) {
				return null;
			}
			$ok   = true;
			$data = self::replace_deep( $data, $search, $replace, $ok );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- writing the same format back.
			return $ok ? serialize( $data ) : null;
		}
		return str_replace( $search, $replace, $value );
	}

	/**
	 * Recursive replace in unserialized data.
	 *
	 * @param mixed  $data    Data.
	 * @param string $search  Find.
	 * @param string $replace Replace.
	 * @param bool   $ok      Set to false when an object is met.
	 * @return mixed
	 */
	private static function replace_deep( $data, $search, $replace, &$ok ) {
		if ( is_string( $data ) ) {
			$new = self::replace_value( $data, $search, $replace );
			if ( null === $new ) {
				$ok = false;
				return $data;
			}
			return $new;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::replace_deep( $v, $search, $replace, $ok );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			$ok = false; // Objects (incl. __PHP_Incomplete_Class) can't be rewritten without loading their class.
		}
		return $data;
	}

	/**
	 * The plan: every row with the text, its new values, and what is skipped.
	 *
	 * @param array $p {search, replace, tables?}.
	 * @return array|\WP_Error
	 */
	public static function plan( array $p ) {
		$search  = (string) $p['search'];
		$replace = (string) $p['replace'];
		if ( strlen( $search ) < self::SR_SEARCH_MIN || $search === $replace ) {
			return new \WP_Error( 'presshelm_payload', 'Give a search text of at least 3 characters and a different replacement' );
		}
		$tables = array_values( array_unique( array_map( 'strval', (array) ( $p['tables'] ?? self::SR_TABLES ) ) ) );
		$like   = '%' . $GLOBALS['wpdb']->esc_like( $search ) . '%';
		$rows   = array();
		$skip   = array();
		$counts = array();
		foreach ( $tables as $table ) {
			$d = Database::describe( $table );
			if ( is_wp_error( $d ) ) {
				return $d;
			}
			$blocked = DbRules::table_write_blocker( $table );
			if ( '' !== $blocked ) {
				$skip[] = array(
					'table'  => $table,
					'reason' => $blocked,
				);
				continue;
			}
			if ( ! $d['primary'] ) {
				$skip[] = array(
					'table'  => $table,
					'reason' => 'no primary key',
				);
				continue;
			}
			foreach ( self::text_columns( $d['full'] ) as $col ) {
				if ( in_array( $col, $d['primary'], true ) ) {
					continue;
				}
				$where = DbRules::groups(
					array(
						array(
							'column' => $col,
							'op'     => 'LIKE',
							'value'  => $like,
						),
					),
					$d['columns']
				);
				$found = Database::select( $d['full'], $d['columns'], $where, $d['primary'][0], false, self::SR_MAX_ROWS + 1, 0 );
				if ( null === $found ) {
					return new \WP_Error( 'presshelm_db_error', 'The search failed' );
				}
				foreach ( $found as $r ) {
					$key = $table . '|' . implode( ',', array_intersect_key( $r, array_flip( $d['primary'] ) ) );
					$new = self::replace_value( $r[ $col ], $search, $replace );
					if ( null === $new ) {
						$skip[] = array(
							'table'  => $table,
							'column' => $col,
							'key'    => array_intersect_key( $r, array_flip( $d['primary'] ) ),
							'reason' => 'contains PHP objects or damaged serialized data; can\'t be rewritten safely',
						);
						continue;
					}
					if ( $new === (string) $r[ $col ] ) {
						continue;
					}
					$why = DbRules::row_write_blocker( $table, $r, array( $col => $new ) );
					if ( '' !== $why ) {
						$skip[] = array(
							'table'  => $table,
							'column' => $col,
							'key'    => array_intersect_key( $r, array_flip( $d['primary'] ) ),
							'reason' => $why,
						);
						continue;
					}
					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ] = array(
							'table'   => $table,
							'full'    => $d['full'],
							'primary' => $d['primary'],
							'row'     => $r,
							'set'     => array(),
						);
					}
					$rows[ $key ]['set'][ $col ]   = $new;
					$counts[ $table . '.' . $col ] = ( $counts[ $table . '.' . $col ] ?? 0 ) + 1;
				}
			}
		}
		return array(
			'rows'   => $rows,
			'skip'   => $skip,
			'counts' => $counts,
		);
	}

	/**
	 * Text columns of a table.
	 *
	 * @param string $full Full table name.
	 * @return string[]
	 */
	private static function text_columns( $full ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $full ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema, always fresh.
			if ( preg_match( self::TEXT_TYPES, (string) $c['Type'] ) ) {
				$out[] = (string) $c['Field'];
			}
		}
		return $out;
	}

	/**
	 * Dry run.
	 *
	 * @param array $p {search, replace, tables?}.
	 * @return array|\WP_Error
	 */
	public static function search_replace_preview( array $p ) {
		$plan = self::plan( $p );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$n      = count( $plan['rows'] );
		$sample = array();
		foreach ( array_slice( $plan['rows'], 0, self::SR_SAMPLE ) as $r ) {
			foreach ( $r['set'] as $col => $new ) {
				$sample[] = array(
					'table'  => $r['table'],
					'key'    => array_intersect_key( $r['row'], array_flip( $r['primary'] ) ),
					'column' => $col,
					'before' => self::around( (string) $r['row'][ $col ], (string) $p['search'] ),
					'after'  => self::around( $new, (string) $p['replace'] ),
				);
			}
		}
		return array(
			'expected_rows' => $n > self::SR_MAX_ROWS ? null : $n,
			'allowed'       => $n > 0 && $n <= self::SR_MAX_ROWS,
			'reason'        => 0 === $n ? 'Nothing to change' : ( $n > self::SR_MAX_ROWS ? 'More than ' . self::SR_MAX_ROWS . ' rows; choose fewer tables' : '' ),
			'by_column'     => $plan['counts'],
			'sample'        => $sample,
			'skipped'       => array_slice( $plan['skip'], 0, 20 ),
			'skipped_count' => count( $plan['skip'] ),
		);
	}

	/**
	 * A short excerpt around the first match.
	 *
	 * @param string $text   Text.
	 * @param string $needle Match.
	 * @return string
	 */
	private static function around( $text, $needle ) {
		$i = '' === $needle ? false : strpos( $text, $needle );
		if ( false === $i ) {
			return substr( $text, 0, 160 );
		}
		$start = max( 0, $i - 60 );
		return ( $start > 0 ? '…' : '' ) . substr( $text, $start, 60 + strlen( $needle ) + 60 ) . ( $start + 120 + strlen( $needle ) < strlen( $text ) ? '…' : '' );
	}
}
