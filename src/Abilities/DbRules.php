<?php
/**
 * Database safeguards (M3.11), pure PHP (tested without WordPress). There is never raw SQL: reads
 * and writes are typed (table, columns, conditions) and turned into `$wpdb->prepare()` templates
 * whose only variable parts are placeholders (%i for names, %s/%d/%f for values).
 *
 * Writes are refused for tables and rows whose change is CRITICAL or would bypass another
 * safeguard: users and their meta (logins, roles), PressHelm's own tables and options, and options
 * that switch themes, activate plugins, move the site or change roles. Secrets are redacted from
 * everything read.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules.
 */
final class DbRules {

	const OPS         = array( '=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'NOT IN' );
	const MAX_IN      = 100;
	const REDACTED    = '[redacted]';
	const NAME        = '/^[A-Za-z0-9_$]{1,64}$/';
	const DENY_TABLES = array( 'users', 'usermeta', 'presshelm_ledger', 'presshelm_journal' );

	/** Options whose change is CRITICAL elsewhere (theme switch, plugin activation, site move, roles). */
	const DENY_OPTIONS = array( 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'template', 'stylesheet', 'current_theme', 'template_root', 'stylesheet_root', 'siteurl', 'home', 'user_roles', 'default_role', 'users_can_register', 'admin_email', 'new_admin_email', 'db_version', 'initial_db_version' );

	/** Key columns that name what a row's value is (options, meta tables). */
	const KEY_COLUMNS = array(
		'option_name' => 'option_value',
		'meta_key'    => 'meta_value',
	);

	/**
	 * Whether an option/meta key holds a secret (redacted on read, never written).
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		$key = strtolower( (string) $key );
		return 0 === strpos( $key, 'presshelm_' )
			|| 0 === strpos( $key, '_transient_presshelm' )
			|| in_array( $key, array( 'session_tokens', '_application_passwords', 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt' ), true )
			|| (bool) preg_match( '/(secret|passw(or)?d|api_?key|access_?token|refresh_?token|private_?key|license_?key|_salt$)/', $key );
	}

	/**
	 * Redacts secrets in one row read from the database.
	 *
	 * @param array $row Column → value.
	 * @return array
	 */
	public static function redact( array $row ) {
		foreach ( array( 'user_pass', 'user_activation_key' ) as $col ) {
			if ( array_key_exists( $col, $row ) && null !== $row[ $col ] && '' !== $row[ $col ] ) {
				$row[ $col ] = self::REDACTED;
			}
		}
		foreach ( self::KEY_COLUMNS as $key_col => $value_col ) {
			if ( isset( $row[ $key_col ] ) && array_key_exists( $value_col, $row ) && self::is_secret_key( (string) $row[ $key_col ] ) ) {
				$row[ $value_col ] = self::REDACTED;
			}
		}
		foreach ( $row as $col => $value ) {
			// Password hashes wherever they are (phpass, bcrypt, argon2, WordPress 6.8+ "$wp$").
			if ( is_string( $value ) && preg_match( '/^\$(P|H|wp\$2y|2[aby]|argon2i?d?)\$/', $value ) ) {
				$row[ $col ] = self::REDACTED;
			}
		}
		return $row;
	}

	/**
	 * Why a table can't be written ('' if it can). Table name without the site prefix.
	 *
	 * @param string $table Unprefixed table name.
	 * @return string
	 */
	public static function table_write_blocker( $table ) {
		$t = strtolower( (string) $table );
		if ( in_array( $t, self::DENY_TABLES, true ) || 0 === strpos( $t, 'presshelm_' ) ) {
			return 'users' === $t || 'usermeta' === $t
				? 'User accounts, passwords and roles are never changed through the database (that is a CRITICAL change for the owner)'
				: 'PressHelm\'s own records can\'t be changed';
		}
		return '';
	}

	/**
	 * Why a row can't be written ('' if it can): checks the row as it is and as it would become.
	 *
	 * @param string $table Unprefixed table name.
	 * @param array  $row   Current row.
	 * @param array  $set   New values (update) or empty (delete).
	 * @return string
	 */
	public static function row_write_blocker( $table, array $row, array $set ) {
		$why = self::table_write_blocker( $table );
		if ( '' !== $why ) {
			return $why;
		}
		foreach ( self::KEY_COLUMNS as $key_col => $value_col ) {
			foreach ( array( $row[ $key_col ] ?? null, $set[ $key_col ] ?? null ) as $key ) {
				if ( null === $key ) {
					continue;
				}
				$k = strtolower( (string) $key );
				if ( self::is_secret_key( $k ) ) {
					return 'Secrets and PressHelm\'s own settings can\'t be changed (' . $k . ')';
				}
				if ( 'option_name' === $key_col && ( in_array( $k, self::DENY_OPTIONS, true ) || '_user_roles' === substr( $k, -11 ) ) ) {
					return 'The option "' . $k . '" switches themes, activates plugins, moves the site or changes roles; that is a CRITICAL change for the owner';
				}
				if ( 'meta_key' === $key_col && ( '_capabilities' === substr( $k, -13 ) || '_user_level' === substr( $k, -11 ) ) ) {
					return 'Roles and capabilities can\'t be changed through the database';
				}
			}
		}
		return '';
	}

	/**
	 * Conditions sorted into one group per operator, in the fixed order of OPS. The query templates
	 * (Database::select) are written out per operator, so no SQL text is ever built from input.
	 * Values are compared as text (MySQL converts for numeric columns, and indexes are used).
	 *
	 * @param array    $conditions [{column, op, value?}], combined with AND.
	 * @param string[] $columns    Known columns of the table.
	 * @return array{groups: array<string, array>, args: array} groups: op → list of conditions
	 *         (IN / NOT IN: their value lists); args: prepare() arguments in template order.
	 * @throws \InvalidArgumentException When a condition is invalid.
	 */
	public static function groups( array $conditions, array $columns ) {
		$groups = array_fill_keys( self::OPS, array() );
		$args   = array_fill_keys( self::OPS, array() );
		foreach ( $conditions as $c ) {
			$col = (string) ( $c['column'] ?? '' );
			$op  = strtoupper( trim( (string) ( $c['op'] ?? '=' ) ) );
			if ( ! in_array( $col, $columns, true ) ) {
				throw new \InvalidArgumentException( 'Unknown column: ' . esc_html( self::safe( $col ) ) );
			}
			if ( ! in_array( $op, self::OPS, true ) ) {
				throw new \InvalidArgumentException( 'Unsupported operator: ' . esc_html( self::safe( $op ) . ' (use ' . implode( ', ', self::OPS ) . ')' ) );
			}
			if ( 'IS NULL' === $op || 'IS NOT NULL' === $op ) {
				$groups[ $op ][] = $col;
				$args[ $op ][]   = $col;
				continue;
			}
			if ( ! array_key_exists( 'value', $c ) ) {
				throw new \InvalidArgumentException( 'Missing value for ' . esc_html( self::safe( $col ) ) );
			}
			if ( 'IN' === $op || 'NOT IN' === $op ) {
				if ( $groups[ $op ] ) {
					throw new \InvalidArgumentException( 'Only one ' . esc_html( $op ) . ' condition per query' );
				}
				$values = $c['value'];
				if ( ! is_array( $values ) || ! $values || count( $values ) > self::MAX_IN ) {
					throw new \InvalidArgumentException( esc_html( $op ) . ' needs a list of 1–' . (int) self::MAX_IN . ' values' );
				}
				$values          = array_map( array( self::class, 'text' ), array_values( $values ) );
				$groups[ $op ][] = $values;
				$args[ $op ]     = array_merge( array( $col ), $values );
				continue;
			}
			if ( null === $c['value'] || is_array( $c['value'] ) ) {
				throw new \InvalidArgumentException( 'Use IS NULL / IS NOT NULL for empty values, IN for lists' );
			}
			$groups[ $op ][] = $col;
			$args[ $op ][]   = $col;
			$args[ $op ][]   = self::text( $c['value'] );
		}
		return array(
			'groups' => $groups,
			'args'   => $args ? array_merge( ...array_values( $args ) ) : array(),
		);
	}

	/**
	 * A condition value as text.
	 *
	 * @param mixed $v Value.
	 * @return string
	 * @throws \InvalidArgumentException When not a scalar.
	 */
	public static function text( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? '1' : '0';
		}
		if ( is_int( $v ) || is_float( $v ) || is_string( $v ) ) {
			return (string) $v;
		}
		throw new \InvalidArgumentException( 'Values must be text, numbers or true/false' );
	}

	/**
	 * New values for an update, checked.
	 *
	 * @param array    $set     Column → value.
	 * @param string[] $columns Known columns.
	 * @param string[] $primary Primary-key columns (never changed).
	 * @return array
	 * @throws \InvalidArgumentException When invalid.
	 */
	public static function set( array $set, array $columns, array $primary ) {
		if ( ! $set ) {
			throw new \InvalidArgumentException( 'Nothing to change' );
		}
		foreach ( $set as $col => $v ) {
			if ( ! in_array( (string) $col, $columns, true ) ) {
				throw new \InvalidArgumentException( 'Unknown column: ' . esc_html( self::safe( (string) $col ) ) );
			}
			if ( in_array( (string) $col, $primary, true ) ) {
				throw new \InvalidArgumentException( 'The primary key can\'t be changed' );
			}
			if ( ! is_scalar( $v ) && null !== $v ) {
				throw new \InvalidArgumentException( 'Values must be text, numbers, true/false or null' );
			}
		}
		return $set;
	}

	/**
	 * Truncates long cell values for the agent.
	 *
	 * @param array $row Row.
	 * @param int   $max Max characters per cell.
	 * @return array
	 */
	public static function clip( array $row, $max = 2000 ) {
		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) && strlen( $v ) > $max ) {
				$row[ $k ] = substr( $v, 0, $max ) . '… [' . strlen( $v ) . ' bytes]';
			}
		}
		return $row;
	}

	/**
	 * A name safe to echo back in an error.
	 *
	 * @param string $s Name.
	 * @return string
	 */
	private static function safe( $s ) {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_ !=<>$-]/', '?', (string) $s ), 0, 64 );
	}
}
