<?php
/**
 * Database, read only (M3.11). Typed only, never raw SQL:
 *
 * - db.tables / db.rows: read this site's tables (row cap 200, long values clipped, secrets redacted);
 * - db.preview: a dry run of an update or delete (how many rows, a before/after sample, and whether
 *   any of them is protected). This plugin never changes database rows itself.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Database abilities.
 */
final class Database {

	const READ_MAX  = 200;
	const WRITE_MAX = 500;

	/**
	 * Ability definitions (merged into Registry::all()).
	 *
	 * @return array
	 */
	public static function definitions() {
		$read  = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$write = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		$table = array(
			'type'    => 'string',
			'pattern' => '^[A-Za-z0-9_$]{1,64}$',
		);
		$where = array(
			'type'     => 'array',
			'maxItems' => 10,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'column' => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9_$]{1,64}$',
					),
					'op'     => array(
						'type' => 'string',
						'enum' => DbRules::OPS,
					),
					'value'  => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array' ) ),
				),
				'required'             => array( 'column' ),
				'additionalProperties' => false,
			),
		);
		$set   = array(
			'type'          => 'object',
			'minProperties' => 1,
			'maxProperties' => 20,
		);
		$count = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => self::WRITE_MAX,
		);
		return array(
			'db.tables'  => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'tables' ),
				'label'       => 'List database tables',
				'description' => 'This site\'s tables (without the prefix), with row counts, size, columns and primary key.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'db.rows'    => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'rows' ),
				'label'       => 'Read database rows',
				'description' => 'Rows of one table matching all conditions (max 200; long values clipped; passwords, keys and tokens redacted).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'table'    => $table,
						'columns'  => array(
							'type'     => 'array',
							'maxItems' => 30,
							'items'    => $table,
						),
						'where'    => $where,
						'order_by' => $table,
						'order'    => array(
							'type' => 'string',
							'enum' => array( 'asc', 'desc' ),
						),
						'limit'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::READ_MAX,
						),
						'offset'   => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'table' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'db.preview' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'preview' ),
				'label'       => 'Dry run of a database change',
				'description' => 'How many rows an update or delete would touch, a before/after sample, and whether it is allowed. Pass the returned expected_rows to db.update / db.delete.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'op'    => array(
							'type' => 'string',
							'enum' => array( 'update', 'delete' ),
						),
						'table' => $table,
						'where' => array_merge( $where, array( 'minItems' => 1 ) ),
						'set'   => $set,
					),
					'required'             => array( 'op', 'table', 'where' ),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
		);
	}

	// ---------------------------------------------------------------- schema

	/**
	 * This site's tables: unprefixed name → status row.
	 *
	 * @return array<string, array>
	 */
	private static function site_tables() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema, always fresh.
			$out[ substr( (string) $t['Name'], strlen( $wpdb->prefix ) ) ] = $t;
		}
		return $out;
	}

	/**
	 * Full name, columns and primary key of a table of this site.
	 *
	 * @param string $table Unprefixed name.
	 * @return array{full:string, columns:string[], primary:string[]}|\WP_Error
	 */
	public static function describe( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( ! preg_match( DbRules::NAME, $table ) || ! isset( self::site_tables()[ $table ] ) ) {
			return new \WP_Error( 'presshelm_not_found', 'No such table on this site (see db.tables; names are without the prefix)' );
		}
		$full    = $wpdb->prefix . $table;
		$columns = array();
		$primary = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $full ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema, always fresh.
			$columns[] = (string) $c['Field'];
			if ( 'PRI' === $c['Key'] ) {
				$primary[] = (string) $c['Field'];
			}
		}
		return array(
			'full'    => $full,
			'columns' => $columns,
			'primary' => $primary,
		);
	}

	/**
	 * Tables.
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		$out = array();
		foreach ( self::site_tables() as $name => $t ) {
			$d     = self::describe( $name );
			$out[] = array(
				'table'       => $name,
				'rows'        => (int) $t['Rows'],
				'bytes'       => (int) $t['Data_length'] + (int) $t['Index_length'],
				'engine'      => $t['Engine'],
				'columns'     => is_wp_error( $d ) ? array() : $d['columns'],
				'primary_key' => is_wp_error( $d ) ? array() : $d['primary'],
				'writable'    => '' === DbRules::table_write_blocker( $name ) && ! is_wp_error( $d ) && $d['primary'],
			);
		}
		return array(
			'prefix' => $wpdb->prefix,
			'server' => $wpdb->db_server_info(),
			'tables' => $out,
		);
	}

	// ---------------------------------------------------------------- read

	/**
	 * Rows.
	 *
	 * @param array $p {table, columns?, where?, order_by?, order?, limit?, offset?}.
	 * @return array|\WP_Error
	 */
	public static function rows( array $p ) {
		global $wpdb;
		$d = self::describe( (string) $p['table'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$cols = array_values( array_map( 'strval', (array) ( $p['columns'] ?? array() ) ) );
		foreach ( $cols as $c ) {
			if ( ! in_array( $c, $d['columns'], true ) ) {
				return new \WP_Error( 'presshelm_payload', 'Unknown column: ' . sanitize_key( $c ) );
			}
		}
		try {
			$where = DbRules::groups( (array) ( $p['where'] ?? array() ), $d['columns'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'presshelm_payload', $e->getMessage() );
		}
		$order_by = (string) ( $p['order_by'] ?? ( $d['primary'][0] ?? $d['columns'][0] ) );
		if ( ! in_array( $order_by, $d['columns'], true ) ) {
			return new \WP_Error( 'presshelm_payload', 'Unknown column to order by' );
		}
		$limit = max( 1, min( self::READ_MAX, (int) ( $p['limit'] ?? 50 ) ) );
		$desc  = isset( $p['order'] ) && 'desc' === $p['order'];
		$rows  = self::select( $d['full'], $cols ? $cols : $d['columns'], $where, $order_by, $desc, $limit + 1, max( 0, (int) ( $p['offset'] ?? 0 ) ) );
		if ( null === $rows ) {
			return new \WP_Error( 'presshelm_db_error', 'The query failed' );
		}
		return array(
			'table' => (string) $p['table'],
			'rows'  => array_map(
				static function ( $r ) {
					return DbRules::clip( DbRules::redact( $r ) );
				},
				array_slice( $rows, 0, $limit )
			),
			'more'  => count( $rows ) > $limit,
		);
	}

	/**
	 * The one SELECT. The template is fixed text: one repeated piece per operator (written out
	 * below), so no SQL is ever built from input; every name and value is a prepare() argument.
	 *
	 * @param string $table    Full table name.
	 * @param array  $cols     Columns to return.
	 * @param array  $where    DbRules::groups() result.
	 * @param string $order_by Column.
	 * @param bool   $desc     Descending.
	 * @param int    $limit    Limit.
	 * @param int    $offset   Offset.
	 * @return array|null Rows, or null when the query failed.
	 */
	public static function select( $table, array $cols, array $where, $order_by, $desc, $limit, $offset ) {
		global $wpdb;
		$g    = $where['groups'];
		$in   = $g['IN'] ? $g['IN'][0] : array();
		$nin  = $g['NOT IN'] ? $g['NOT IN'][0] : array();
		$dir  = $desc ? array( 1 ) : array();
		$args = array_merge( $cols, array( $table ), $where['args'], array( $order_by, (int) $limit, (int) $offset ) );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live data for the agent; never cached.
			$wpdb->prepare(
				'SELECT ' . implode( ', ', array_fill( 0, count( $cols ), '%i' ) ) . ' FROM %i WHERE 1=1'
				. implode( '', array_fill( 0, count( $g['='] ), ' AND %i = %s' ) )
				. implode( '', array_fill( 0, count( $g['!='] ), ' AND %i <> %s' ) )
				. implode( '', array_fill( 0, count( $g['<'] ), ' AND %i < %s' ) )
				. implode( '', array_fill( 0, count( $g['>'] ), ' AND %i > %s' ) )
				. implode( '', array_fill( 0, count( $g['<='] ), ' AND %i <= %s' ) )
				. implode( '', array_fill( 0, count( $g['>='] ), ' AND %i >= %s' ) )
				. implode( '', array_fill( 0, count( $g['LIKE'] ), ' AND %i LIKE %s' ) )
				. implode( '', array_fill( 0, count( $g['NOT LIKE'] ), ' AND %i NOT LIKE %s' ) )
				. implode( '', array_fill( 0, count( $g['IS NULL'] ), ' AND %i IS NULL' ) )
				. implode( '', array_fill( 0, count( $g['IS NOT NULL'] ), ' AND %i IS NOT NULL' ) )
				. implode( '', array_fill( 0, count( $g['IN'] ), ' AND %i IN (' . implode( ', ', array_fill( 0, count( $in ), '%s' ) ) . ')' ) )
				. implode( '', array_fill( 0, count( $g['NOT IN'] ), ' AND %i NOT IN (' . implode( ', ', array_fill( 0, count( $nin ), '%s' ) ) . ')' ) )
				. ' ORDER BY %i' . implode( '', array_fill( 0, count( $dir ), ' DESC' ) ) . ' LIMIT %d OFFSET %d',
				$args
			),
			ARRAY_A
		);
	}

	// ---------------------------------------------------------------- write

	/**
	 * The rows a change would touch, checked (shared by preview, update, delete).
	 *
	 * @param array $p   {table, where, set?}.
	 * @param bool  $upd Update (needs set) or delete.
	 * @return array|\WP_Error {d, set, count, rows, blocked}
	 */
	public static function plan( array $p, $upd ) {
		global $wpdb;
		$d = self::describe( (string) $p['table'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$why = DbRules::table_write_blocker( (string) $p['table'] );
		if ( '' !== $why ) {
			return new \WP_Error( 'presshelm_db_protected', $why );
		}
		if ( ! $d['primary'] ) {
			return new \WP_Error( 'presshelm_db_no_key', 'This table has no primary key, so changed rows could not be restored exactly' );
		}
		if ( empty( $p['where'] ) ) {
			return new \WP_Error( 'presshelm_payload', 'At least one condition is required' );
		}
		try {
			$where = DbRules::groups( (array) $p['where'], $d['columns'] );
			$set   = $upd ? DbRules::set( (array) ( $p['set'] ?? array() ), $d['columns'], $d['primary'] ) : array();
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'presshelm_payload', $e->getMessage() );
		}
		$rows = self::select( $d['full'], $d['columns'], $where, $d['primary'][0], false, self::WRITE_MAX + 1, 0 );
		if ( null === $rows ) {
			return new \WP_Error( 'presshelm_db_error', 'The query failed' );
		}
		$blocked = '';
		foreach ( $rows as $r ) {
			$blocked = DbRules::row_write_blocker( (string) $p['table'], $r, $set );
			if ( '' !== $blocked ) {
				break;
			}
		}
		return array(
			'd'       => $d,
			'set'     => $set,
			'rows'    => $rows,
			'count'   => count( $rows ),
			'blocked' => $blocked,
		);
	}

	/**
	 * Dry run.
	 *
	 * @param array $p {op, table, where, set?}.
	 * @return array|\WP_Error
	 */
	public static function preview( array $p ) {
		$upd  = 'update' === ( $p['op'] ?? '' );
		$plan = self::plan( $p, $upd );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$sample = array();
		foreach ( array_slice( $plan['rows'], 0, 5 ) as $r ) {
			$key = array_intersect_key( $r, array_flip( $plan['d']['primary'] ) );
			$row = array( 'key' => $key );
			if ( $upd ) {
				$row['before'] = DbRules::clip( DbRules::redact( array_intersect_key( $r, $plan['set'] ) ), 300 );
				$row['after']  = DbRules::clip( DbRules::redact( $plan['set'] ), 300 );
			} else {
				$row['row'] = DbRules::clip( DbRules::redact( $r ), 300 );
			}
			$sample[] = $row;
		}
		$too_many = $plan['count'] > self::WRITE_MAX;
		return array(
			'op'            => $upd ? 'update' : 'delete',
			'table'         => (string) $p['table'],
			'expected_rows' => $too_many ? null : $plan['count'],
			'allowed'       => ! $too_many && '' === $plan['blocked'] && $plan['count'] > 0,
			'reason'        => $too_many ? 'More than ' . self::WRITE_MAX . ' rows; narrow the conditions' : ( '' !== $plan['blocked'] ? $plan['blocked'] : ( 0 === $plan['count'] ? 'No rows match' : '' ) ),
			'sample'        => $sample,
		);
	}

	/**
	 * Clears WordPress's object caches for changed rows of core tables.
	 *
	 * @param string $table Full table name.
	 * @param array  $rows  Rows (as they were before).
	 */
	public static function flush_caches( $table, array $rows ) {
		global $wpdb;
		$t = substr( (string) $table, strlen( $wpdb->prefix ) );
		foreach ( $rows as $r ) {
			switch ( $t ) {
				case 'options':
					wp_cache_delete( (string) ( $r['option_name'] ?? '' ), 'options' );
					break;
				case 'posts':
					clean_post_cache( (int) ( $r['ID'] ?? 0 ) );
					break;
				case 'postmeta':
					wp_cache_delete( (int) ( $r['post_id'] ?? 0 ), 'post_meta' );
					break;
				case 'termmeta':
					wp_cache_delete( (int) ( $r['term_id'] ?? 0 ), 'term_meta' );
					break;
				case 'terms':
				case 'term_taxonomy':
					clean_term_cache( (int) ( $r['term_id'] ?? 0 ) );
					break;
				case 'comments':
					clean_comment_cache( (int) ( $r['comment_ID'] ?? 0 ) );
					break;
				case 'commentmeta':
					wp_cache_delete( (int) ( $r['comment_id'] ?? 0 ), 'comment_meta' );
					break;
			}
		}
		if ( 'options' === $t ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
