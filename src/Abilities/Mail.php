<?php
/**
 * Email (C1): how the site sends mail and whether its domain is set up for it, a test email to an
 * administrator, and recent sends from the SMTP plugin's log plus failures PressHelm noticed.
 * SMTP passwords and API keys are never read out; recipient addresses are shown masked.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Mail.
 */
final class Mail {

	/** Option with the last sending failures (masked). */
	const FAILURES = 'presshelm_mail_failures';

	/** SMTP / mail plugins people use: plugin file => name. */
	const PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'post-smtp/postman-smtp.php'        => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'smtp-mailer/main.php'              => 'SMTP Mailer',
		'gmail-smtp/main.php'               => 'Gmail SMTP',
		'mailin/sendinblue.php'             => 'Brevo',
		'wp-ses/wp-ses.php'                 => 'WP Offload SES',
		'suremails/suremails.php'           => 'SureMail',
	);

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$read = array(
			'readonly'   => true,
			'idempotent' => true,
		);
		return array(
			'mail.inspect' => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'inspect' ),
				'label'       => 'How this site sends email',
				'description' => 'The sending setup (SMTP plugin, mailer, host, sender address; never passwords or keys), whether the sender domain is the site\'s, the domain\'s SPF, DMARC and MX records, recent failures, and what to fix.',
				'input'       => null,
				'annotations' => $read,
				'undo'        => null,
			),
			'mail.log'     => array(
				'risk'        => 'READ',
				'cap'         => 'manage_options',
				'mutates'     => false,
				'run'         => array( self::class, 'log' ),
				'label'       => 'Recent emails',
				'description' => 'Recent emails from the SMTP plugin\'s log (FluentSMTP, WP Mail SMTP Pro, Post SMTP) with status and error, and sending failures PressHelm noticed. Recipients are masked. Max 50.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
						'failed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'annotations' => $read,
				'undo'        => null,
			),
			'mail.test'    => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'manage_options',
				'mutates'     => true,
				'skip_health' => true,
				'run'         => array( self::class, 'test' ),
				'label'       => 'Send a test email',
				'description' => 'Sends a short test email through the site\'s normal mail setup to the site\'s admin email, or to another administrator\'s address. Reports whether WordPress handed it over, through which mailer, and the exact error if not. An email cannot be unsent.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'to' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => null,
			),
		);
	}

	/**
	 * An address with the local part hidden: j***@example.com.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function mask( $email ) {
		$email = trim( (string) $email );
		if ( preg_match( '/<([^>]+)>/', $email, $m ) ) {
			$email = $m[1];
		}
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return '' === $email ? '' : '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Every address in a string masked.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function mask_all( $text ) {
		return (string) preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			static function ( $m ) {
				return self::mask( $m[0] );
			},
			(string) $text
		);
	}

	/**
	 * Remembers a failed send (wp_mail_failed), masked, last 20.
	 *
	 * @param \WP_Error $error The failure.
	 */
	public static function record_failure( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}
		$data = (array) $error->get_error_data();
		$list = (array) get_option( self::FAILURES, array() );
		array_unshift(
			$list,
			array(
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'to'      => implode( ', ', array_map( array( self::class, 'mask' ), (array) ( $data['to'] ?? array() ) ) ),
				'subject' => mb_substr( (string) ( $data['subject'] ?? '' ), 0, 120 ),
				'error'   => mb_substr( self::mask_all( $error->get_error_message() ), 0, 300 ),
			)
		);
		update_option( self::FAILURES, array_slice( $list, 0, 20 ), false );
	}

	/**
	 * The site's own domain (as WordPress's default sender uses it).
	 *
	 * @return string
	 */
	private static function site_domain() {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * TXT records of a name containing a marker, or null when DNS can't be asked.
	 *
	 * @param string $name   DNS name.
	 * @param string $marker e.g. v=spf1.
	 * @return string[]|null
	 */
	private static function txt( $name, $marker ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		$recs = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing record raises a warning; that is an answer here, not an error.
		if ( false === $recs ) {
			return null;
		}
		$out = array();
		foreach ( (array) $recs as $r ) {
			$txt = isset( $r['entries'] ) ? implode( '', (array) $r['entries'] ) : (string) ( $r['txt'] ?? '' );
			if ( 0 === stripos( $txt, $marker ) ) {
				$out[] = $txt;
			}
		}
		return $out;
	}

	/**
	 * The active SMTP plugin's setup, without secrets.
	 *
	 * @return array
	 */
	private static function smtp_plugins() {
		$out = array();
		foreach ( self::PLUGINS as $file => $name ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}
			$row = array( 'plugin' => $name );
			if ( false !== strpos( $file, 'wp-mail-smtp' ) ) {
				$o   = (array) get_option( 'wp_mail_smtp', array() );
				$row = array_merge(
					$row,
					array(
						'mailer'       => (string) ( $o['mail']['mailer'] ?? '' ),
						'from'         => (string) ( $o['mail']['from_email'] ?? '' ),
						'force_from'   => ! empty( $o['mail']['from_email_force'] ),
						'host'         => (string) ( $o['smtp']['host'] ?? '' ),
						'port'         => (string) ( $o['smtp']['port'] ?? '' ),
						'encryption'   => (string) ( $o['smtp']['encryption'] ?? '' ),
						'logs_enabled' => ! empty( $o['logs']['enabled'] ),
					)
				);
			} elseif ( 'fluent-smtp/fluent-smtp.php' === $file ) {
				$o     = (array) get_option( 'fluentmail-settings', array() );
				$conns = array();
				foreach ( (array) ( $o['connections'] ?? array() ) as $key => $c ) {
					$ps      = (array) ( $c['provider_settings'] ?? array() );
					$conns[] = array(
						'provider' => (string) ( $ps['provider'] ?? '' ),
						'from'     => (string) ( $ps['sender_email'] ?? '' ),
						'host'     => (string) ( $ps['host'] ?? '' ),
						'port'     => (string) ( $ps['port'] ?? '' ),
						'default'  => (string) ( $o['misc']['default_connection'] ?? '' ) === (string) $key,
					);
				}
				$row['connections']  = $conns;
				$row['logs_enabled'] = 'yes' === ( $o['misc']['log_emails'] ?? '' );
			} elseif ( 'post-smtp/postman-smtp.php' === $file ) {
				$o   = (array) get_option( 'postman_options', array() );
				$row = array_merge(
					$row,
					array(
						'mailer'     => (string) ( $o['transport_type'] ?? '' ),
						'from'       => (string) ( $o['sender_email'] ?? '' ),
						'host'       => (string) ( $o['hostname'] ?? '' ),
						'port'       => (string) ( $o['port'] ?? '' ),
						'encryption' => (string) ( $o['enc_type'] ?? '' ),
					)
				);
			}
			$out[] = array_filter(
				$row,
				static function ( $v ) {
					return '' !== $v && array() !== $v;
				}
			);
		}
		return $out;
	}

	/**
	 * How the site sends mail.
	 *
	 * @return array
	 */
	public static function inspect() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$domain  = self::site_domain();
		$default = 'wordpress@' . $domain;
		$from    = (string) apply_filters( 'wp_mail_from', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter: the sender WordPress will really use.
		$plugins = self::smtp_plugins();
		$owner   = ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
		$owner   = wp_normalize_path( (string) $owner );
		$replace = false === strpos( $owner, '/wp-includes/' ) ? str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', $owner ) : null;
		$from_d  = strtolower( substr( (string) strrchr( $from, '@' ), 1 ) );
		$spf     = self::txt( $from_d, 'v=spf1' );
		$dmarc   = self::txt( '_dmarc.' . $from_d, 'v=DMARC1' );
		$mx      = function_exists( 'dns_get_record' ) ? @dns_get_record( $from_d, DNS_MX ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see txt().
		$fails   = (array) get_option( self::FAILURES, array() );
		$todo    = array();
		if ( ! $plugins && ! $replace ) {
			$todo[] = 'Mail goes out through the server\'s own mail() function, which often lands in spam or is not delivered at all. Install an SMTP plugin (e.g. FluentSMTP or WP Mail SMTP) and connect a mail service; the owner enters the password or API key there themselves.';
		}
		if ( $from_d && $from_d !== $domain && substr( $from_d, -strlen( '.' . $domain ) ) !== '.' . $domain ) {
			$todo[] = 'The sender (' . $from . ') is not on the site\'s domain (' . $domain . '); mail services and receivers trust it less unless that domain is set up for this mail service.';
		}
		if ( is_array( $spf ) && ! $spf ) {
			$todo[] = 'The sender domain has no SPF record, so receivers cannot tell which servers may send for it. Add the TXT record the mail service gives.';
		} elseif ( is_array( $spf ) && count( $spf ) > 1 ) {
			$todo[] = 'The sender domain has ' . count( $spf ) . ' SPF records; there must be exactly one (merge them).';
		}
		if ( is_array( $dmarc ) && ! $dmarc ) {
			$todo[] = 'The sender domain has no DMARC record; large mail providers (Gmail, Yahoo, Outlook) increasingly send such mail to spam. A start: TXT at _dmarc.' . $from_d . ' with "v=DMARC1; p=none".';
		}
		if ( $fails ) {
			$todo[] = count( $fails ) . ' sending failure(s) were noticed, the last at ' . $fails[0]['at'] . ' UTC: ' . $fails[0]['error'];
		}
		return array(
			'site_domain'      => $domain,
			'from'             => $from,
			'from_name'        => (string) apply_filters( 'wp_mail_from_name', 'WordPress' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'admin_email'      => self::mask( (string) get_option( 'admin_email' ) ),
			'smtp_plugins'     => $plugins,
			'wp_mail_replaced' => $replace,
			'dns'              => array(
				'domain' => $from_d,
				'spf'    => null === $spf ? 'not checked (DNS lookups are not available here)' : $spf,
				'dmarc'  => null === $dmarc ? 'not checked' : $dmarc,
				'mx'     => false === $mx ? 'not checked' : array_values( array_map( static fn( $r ) => (string) ( $r['target'] ?? '' ), (array) $mx ) ),
			),
			'recent_failures'  => array_slice( $fails, 0, 5 ),
			'to_fix'           => $todo,
		);
	}

	/**
	 * Allowed test recipients: the admin email and administrators' addresses.
	 *
	 * @return string[]
	 */
	private static function recipients() {
		$out = array( strtolower( (string) get_option( 'admin_email' ) ) );
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'user_email' ),
				'number' => 50,
			)
		) as $u ) {
			$out[] = strtolower( (string) $u->user_email );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Sends a test email to an administrator.
	 *
	 * @param array $p {to?}.
	 * @return array|\WP_Error
	 */
	public static function test( array $p ) {
		$allowed = self::recipients();
		$to      = strtolower( trim( (string) ( $p['to'] ?? '' ) ) );
		$to      = '' === $to || 'admin' === $to ? strtolower( (string) get_option( 'admin_email' ) ) : $to;
		if ( ! in_array( $to, $allowed, true ) ) {
			return new \WP_Error( 'presshelm_mail_recipient', 'A test email can only go to the site\'s admin email or an administrator\'s address. Nothing was sent.' );
		}
		$used  = array();
		$error = null;
		$init  = static function ( $phpmailer ) use ( &$used ) {
			$used = array(
				'mailer'     => (string) $phpmailer->Mailer, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
				'host'       => 'smtp' === $phpmailer->Mailer ? (string) $phpmailer->Host : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
				'port'       => 'smtp' === $phpmailer->Mailer ? (int) $phpmailer->Port : 0, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
				'encryption' => (string) $phpmailer->SMTPSecure, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
				'from'       => (string) $phpmailer->From, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
			);
		};
		$fail  = static function ( $e ) use ( &$error ) {
			$error = $e instanceof \WP_Error ? $e->get_error_message() : 'unknown error';
		};
		add_action( 'phpmailer_init', $init, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $fail );
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$sent = wp_mail(
			$to,
			'PressHelm test email from ' . $site,
			'This is a test email sent by PressHelm at your request, to check that ' . $site . " can send email.\n\nSent: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\nSite: " . home_url( '/' ) . "\n\nIf this arrived in your spam folder, the site's email setup needs attention (ask PressHelm to inspect it).\n"
		);
		remove_action( 'phpmailer_init', $init, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $fail );
		$out = array(
			'sent' => (bool) $sent,
			'to'   => self::mask( $to ),
			'via'  => $used ? array_filter( $used ) : 'not PHPMailer (a mail plugin sends through its own API)',
		);
		if ( ! $sent ) {
			$out['error'] = mb_substr( self::mask_all( (string) ( $error ?? 'WordPress could not send it.' ) ), 0, 500 );
		} else {
			$out['note'] = 'WordPress handed the email over. Ask the owner to check the inbox and the spam folder (it can take a minute); "sent" does not prove delivery.';
		}
		return $out;
	}

	/**
	 * Recent emails from the SMTP plugin's log, and noticed failures.
	 *
	 * @param array $p {limit?, failed?}.
	 * @return array
	 */
	public static function log( array $p ) {
		global $wpdb;
		$limit  = min( 50, max( 1, (int) ( $p['limit'] ?? 20 ) ) );
		$failed = ! empty( $p['failed'] );
		$items  = array();
		$source = null;
		$tables = array(
			'FluentSMTP'       => $wpdb->prefix . 'fsmpt_email_logs',
			'WP Mail SMTP Pro' => $wpdb->prefix . 'wpmailsmtp_emails_log',
			'Post SMTP'        => $wpdb->prefix . 'post_smtp_logs',
		);
		foreach ( $tables as $name => $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- is the plugin's log table there.
				continue;
			}
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $failed ? 500 : $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the SMTP plugin's own log, read only.
			$source = $name;
			foreach ( $rows as $r ) {
				$item = self::log_row( $name, $r );
				if ( $failed && 'failed' !== $item['status'] ) {
					continue;
				}
				$items[] = $item;
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
			break;
		}
		return array(
			'source'           => $source ? $source : 'no SMTP plugin log found (FluentSMTP, WP Mail SMTP Pro or Post SMTP with logging on)',
			'emails'           => $items,
			'noticed_failures' => array_slice( (array) get_option( self::FAILURES, array() ), 0, $limit ),
		);
	}

	/**
	 * One log row in a common shape (columns differ per plugin and version; unknown ones are left out).
	 *
	 * @param string $name Plugin.
	 * @param array  $r    Row.
	 * @return array
	 */
	private static function log_row( $name, array $r ) {
		$pick   = static function ( array $keys ) use ( $r ) {
			foreach ( $keys as $k ) {
				if ( isset( $r[ $k ] ) && '' !== (string) $r[ $k ] ) {
					return (string) $r[ $k ];
				}
			}
			return '';
		};
		$to     = $pick( array( 'to', 'to_header', 'people', 'original_to' ) );
		$status = strtolower( $pick( array( 'status', 'success' ) ) );
		if ( 'WP Mail SMTP Pro' === $name ) {
			$status = '0' === $status ? 'failed' : ( in_array( $status, array( '1', '3' ), true ) ? 'sent' : ( '2' === $status ? 'waiting' : $status ) );
		} elseif ( 'Post SMTP' === $name ) {
			$status = in_array( $status, array( '1', 'true', 'sent' ), true ) ? 'sent' : ( '' === $status ? '' : 'failed' );
		}
		$error = $pick( array( 'error_text', 'solution', 'response' ) );
		return array(
			'id'      => (int) ( $r['id'] ?? 0 ),
			'date'    => $pick( array( 'created_at', 'date_sent', 'time', 'updated_at' ) ),
			'to'      => mb_substr( self::mask_all( wp_strip_all_tags( $to ) ), 0, 200 ),
			'subject' => mb_substr( $pick( array( 'subject', 'original_subject' ) ), 0, 120 ),
			'status'  => $status,
			'error'   => 'sent' === $status ? '' : mb_substr( self::mask_all( wp_strip_all_tags( (string) maybe_unserialize( $error ) === $error ? $error : wp_json_encode( maybe_unserialize( $error ) ) ) ), 0, 300 ),
		);
	}
}
