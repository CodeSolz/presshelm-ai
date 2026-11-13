<?php
/**
 * Form plugins (M3.15): use what the site already has. Lists the forms of every active form plugin
 * (Contact Form 7, WPForms, Fluent Forms) with ready-to-use embed markup, and creates simple
 * contact forms with Contact Form 7 or WPForms through their own APIs. The notification always
 * goes to the site's admin email (never an address the agent chooses). Creating does not embed:
 * the agent embeds the returned markup with the content/block tools (which need approval).
 * Fluent Forms: list and embed only (its forms are built in its own editor).
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Form abilities.
 */
final class Forms {

	const FIELD_TYPES = array( 'name', 'email', 'text', 'textarea', 'phone' );
	const MAX_FIELDS  = 20;

	/**
	 * Which form plugins are active.
	 *
	 * @return array<string, bool> plugin → active.
	 */
	public static function plugins() {
		return array(
			'cf7'        => class_exists( 'WPCF7_ContactForm' ),
			'wpforms'    => function_exists( 'wpforms' ),
			'fluentform' => defined( 'FLUENTFORM' ) || class_exists( '\\FluentForm\\App\\Models\\Form' ),
		);
	}

	/** What to do when no form plugin is active. */
	const INSTALL_HINT = 'No form plugin is active yet: install and activate Contact Form 7 (plugin_install slug contact-form-7, then plugin_activate) or WPForms Lite (wpforms-lite), then create the form.';

	/**
	 * Ability definitions (always offered: without a form plugin they say which one to install).
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'forms.list'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_pages',
				'mutates'     => false,
				'run'         => array( self::class, 'list_forms' ),
				'label'       => 'List forms',
				'description' => 'Active form plugins (Contact Form 7, WPForms, Fluent Forms) and their forms, each with embed markup. Use a form plugin the site already has before suggesting a new one.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
			'forms.create' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_pages',
				'mutates'     => true,
				'run'         => array( self::class, 'create' ),
				'label'       => 'Create a form',
				'description' => 'A simple form (fields: name, email, text, textarea, phone) with Contact Form 7 or WPForms (default: the one the site uses most). Submissions are emailed to the site admin. Returns embed markup; embedding it in a page is a separate change. Undo deletes the form.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type' => 'string',
							'enum' => array( 'cf7', 'wpforms' ),
						),
						'title'  => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'fields' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_FIELDS,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'type'     => array(
										'type' => 'string',
										'enum' => self::FIELD_TYPES,
									),
									'label'    => array(
										'type'      => 'string',
										'minLength' => 1,
										'maxLength' => 100,
									),
									'required' => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'type', 'label' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'forms.get'    => array(
				'risk'        => 'READ',
				'cap'         => 'edit_pages',
				'mutates'     => false,
				'run'         => array( self::class, 'get_form' ),
				'label'       => 'Read a Contact Form 7 form',
				'description' => 'One Contact Form 7 form: title, its form template (CF7 tags like [email* your-email] inside labels) and its mail settings (recipient, subject, body, additional headers).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
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
			'forms.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_pages',
				'mutates'     => true,
				'run'         => array( self::class, 'update' ),
				'label'       => 'Change a Contact Form 7 form',
				'description' => 'Changes a Contact Form 7 form: title, form (the whole template: labels and CF7 tags, e.g. add "<label> Phone [tel your-phone] </label>" before the submit tag) and mail (only the keys given change: recipient, subject, body, additional_headers with Reply-To/Cc/Bcc lines). Text and CF7 tags only, no code. Undo puts it back exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'form'  => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 20000,
						),
						'mail'  => array(
							'type'                 => 'object',
							'properties'           => array(
								'recipient'          => array(
									'type'      => 'string',
									'maxLength' => 500,
								),
								'subject'            => array(
									'type'      => 'string',
									'maxLength' => 500,
								),
								'body'               => array(
									'type'      => 'string',
									'maxLength' => 20000,
								),
								'additional_headers' => array(
									'type'      => 'string',
									'maxLength' => 1000,
								),
							),
							'additionalProperties' => false,
						),
					),
					'required'             => array( 'id' ),
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

	// ---------------------------------------------------------------- read and change (Contact Form 7)

	/**
	 * A Contact Form 7 form by id.
	 *
	 * @param int $id Form id.
	 * @return \WPCF7_ContactForm|\WP_Error
	 */
	private static function cf7_form( $id ) {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return new \WP_Error( 'presshelm_needs_form_plugin', 'Contact Form 7 is not active on this site.' );
		}
		$cf = \WPCF7_ContactForm::get_instance( (int) $id );
		if ( ! $cf ) {
			return new \WP_Error( 'presshelm_not_found', 'No Contact Form 7 form with that id (see forms.list).' );
		}
		return $cf;
	}

	/**
	 * Reads a Contact Form 7 form.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function get_form( array $p ) {
		$cf = self::cf7_form( (int) $p['id'] );
		if ( is_wp_error( $cf ) ) {
			return $cf;
		}
		$mail = (array) $cf->prop( 'mail' );
		return array(
			'plugin' => 'cf7',
			'id'     => (int) $cf->id(),
			'title'  => $cf->title(),
			'form'   => (string) $cf->prop( 'form' ),
			'mail'   => array(
				'recipient'          => (string) ( $mail['recipient'] ?? '' ),
				'subject'            => (string) ( $mail['subject'] ?? '' ),
				'body'               => (string) ( $mail['body'] ?? '' ),
				'additional_headers' => (string) ( $mail['additional_headers'] ?? '' ),
			),
			'embed'  => self::block( $cf->shortcode() ),
		);
	}

	/**
	 * Changes a Contact Form 7 form (journaled: post and its form/mail meta come back exactly on undo).
	 *
	 * @param array  $p          {id, title?, form?, mail?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function update( array $p, $command_id ) {
		$cf = self::cf7_form( (int) $p['id'] );
		if ( is_wp_error( $cf ) ) {
			return $cf;
		}
		$mail_in = (array) ( $p['mail'] ?? array() );
		foreach ( array_merge( array( (string) ( $p['title'] ?? '' ), (string) ( $p['form'] ?? '' ) ), array_map( 'strval', $mail_in ) ) as $text ) {
			if ( Generic::has_code( $text ) ) {
				return new \WP_Error( 'presshelm_code', 'Forms take text, labels and CF7 tags only, no code (scripts, styles, iframes, event handlers). Nothing was changed.' );
			}
		}
		if ( isset( $mail_in['recipient'] ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $mail_in['recipient'] ) ) ) as $to ) {
				if ( ! is_email( preg_replace( '/^.*<([^>]+)>\s*$/', '$1', $to ) ) && ! preg_match( '/^\[[a-z0-9_-]+\]$/i', $to ) ) {
					return new \WP_Error( 'presshelm_payload', 'The recipient must be email addresses or CF7 mail tags like [_site_admin_email]. Nothing was changed.' );
				}
			}
		}
		if ( isset( $mail_in['additional_headers'] ) ) {
			foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $mail_in['additional_headers'] ) ) ) as $line ) {
				if ( ! preg_match( '/^(Reply-To|Cc|Bcc)\s*:\s*\S/i', $line ) ) {
					return new \WP_Error( 'presshelm_payload', 'additional_headers may only hold Reply-To, Cc and Bcc lines. Nothing was changed.' );
				}
			}
		}
		$id     = (int) $cf->id();
		$post   = get_post( $id );
		$before = array(
			'post' => array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
			),
			'meta' => array(),
		);
		foreach ( array( '_form', '_mail' ) as $key ) {
			$before['meta'][ $key ] = metadata_exists( 'post', $id, $key ) ? base64_encode( maybe_serialize( get_post_meta( $id, $key, true ) ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- exact bytes for undo.
		}
		// One journal entry for the post and its form and mail settings: undo puts all of it back.
		$journal = Journal::record( $command_id, 'record', (string) $id, $before );
		$props   = array();
		if ( isset( $p['form'] ) ) {
			$props['form'] = (string) $p['form'];
		}
		if ( $mail_in ) {
			$props['mail'] = array_merge( (array) $cf->prop( 'mail' ), array_map( 'strval', $mail_in ) );
		}
		if ( $props ) {
			$cf->set_properties( $props );
		}
		if ( isset( $p['title'] ) ) {
			$cf->set_title( sanitize_text_field( (string) $p['title'] ) );
		}
		if ( ! $cf->save() ) {
			Journal::restore( $journal );
			return new \WP_Error( 'presshelm_write_failed', 'Contact Form 7 could not save the form.' );
		}
		return self::get_form( array( 'id' => $id ) ) + array( 'journal_id' => $journal );
	}

	// ---------------------------------------------------------------- list

	/**
	 * Forms of every active form plugin.
	 *
	 * @return array
	 */
	public static function list_forms() {
		$out    = array();
		$active = self::plugins();
		if ( $active['cf7'] ) {
			$out['cf7'] = array(
				'name'       => 'Contact Form 7',
				'can_create' => true,
				'forms'      => array_map(
					static function ( $f ) {
						return array(
							'id'    => $f->id(),
							'title' => $f->title(),
							'embed' => self::block( $f->shortcode() ),
						);
					},
					\WPCF7_ContactForm::find( array( 'posts_per_page' => 50 ) )
				),
			);
		}
		if ( $active['wpforms'] ) {
			$forms          = get_posts(
				array(
					'post_type'   => 'wpforms',
					'post_status' => 'publish',
					'numberposts' => 50,
				)
			);
			$out['wpforms'] = array(
				'name'       => 'WPForms',
				'can_create' => true,
				'forms'      => array_map(
					static function ( $p ) {
						return array(
							'id'    => $p->ID,
							'title' => $p->post_title,
							'embed' => self::block( '[wpforms id="' . (int) $p->ID . '"]' ),
						);
					},
					$forms
				),
			);
		}
		if ( $active['fluentform'] ) {
			global $wpdb;
			$rows              = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, title FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', $wpdb->prefix . 'fluentform_forms', 'published', 50 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fluent Forms' own table, read only, always fresh.
			$out['fluentform'] = array(
				'name'       => 'Fluent Forms',
				'can_create' => false,
				'note'       => 'New Fluent Forms forms are built in its own editor; existing ones can be embedded.',
				'forms'      => array_map(
					static function ( $r ) {
						return array(
							'id'    => (int) $r['id'],
							'title' => (string) $r['title'],
							'embed' => self::block( '[fluentform id="' . (int) $r['id'] . '"]' ),
						);
					},
					$rows
				),
			);
		}
		return array(
			'plugins'   => $out,
			'suggested' => self::default_plugin(),
		) + ( $out ? array() : array( 'next_step' => self::INSTALL_HINT ) );
	}

	/**
	 * A shortcode as block markup (for block_patch / content tools).
	 *
	 * @param string $shortcode Shortcode.
	 * @return string
	 */
	private static function block( $shortcode ) {
		return "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
	}

	/**
	 * The creatable plugin the site uses most ('' if none).
	 *
	 * @return string
	 */
	public static function default_plugin() {
		$active = self::plugins();
		$counts = array();
		if ( $active['wpforms'] ) {
			$counts['wpforms'] = (int) ( wp_count_posts( 'wpforms' )->publish ?? 0 );
		}
		if ( $active['cf7'] ) {
			$counts['cf7'] = (int) ( wp_count_posts( 'wpcf7_contact_form' )->publish ?? 0 );
		}
		if ( ! $counts ) {
			return '';
		}
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	// ---------------------------------------------------------------- create

	/**
	 * Fields with defaults and unique machine names.
	 *
	 * @param array $fields Input fields.
	 * @return array [{type, label, required, name}]
	 */
	private static function fields( array $fields ) {
		if ( ! $fields ) {
			$fields = array(
				array(
					'type'     => 'name',
					'label'    => __( 'Your name', 'presshelm-ai' ),
					'required' => true,
				),
				array(
					'type'     => 'email',
					'label'    => __( 'Your email', 'presshelm-ai' ),
					'required' => true,
				),
				array(
					'type'     => 'textarea',
					'label'    => __( 'Your message', 'presshelm-ai' ),
					'required' => true,
				),
			);
		}
		$out  = array();
		$seen = array();
		foreach ( array_slice( $fields, 0, self::MAX_FIELDS ) as $f ) {
			$type = in_array( $f['type'] ?? '', self::FIELD_TYPES, true ) ? $f['type'] : 'text';
			$base = 'your-' . ( sanitize_key( str_replace( ' ', '-', (string) ( $f['label'] ?? $type ) ) ) ?: $type ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- fallback name.
			$name = $base;
			for ( $i = 2; isset( $seen[ $name ] ); $i++ ) {
				$name = $base . '-' . $i;
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'type'     => $type,
				'label'    => sanitize_text_field( (string) ( $f['label'] ?? $type ) ),
				'required' => ! empty( $f['required'] ),
				'name'     => $name,
			);
		}
		return $out;
	}

	/**
	 * Creates a form.
	 *
	 * @param array  $p          {plugin?, title, fields?}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function create( array $p, $command_id ) {
		$plugin = (string) ( $p['plugin'] ?? self::default_plugin() );
		$active = self::plugins();
		if ( ! $active['cf7'] && ! $active['wpforms'] ) {
			return new \WP_Error( 'presshelm_needs_form_plugin', self::INSTALL_HINT . ' Nothing was changed.' );
		}
		if ( ! in_array( $plugin, array( 'cf7', 'wpforms' ), true ) || empty( $active[ $plugin ] ) ) {
			return new \WP_Error( 'presshelm_unsupported', 'That form plugin is not active here (see forms.list). Forms can be created with Contact Form 7 or WPForms.' );
		}
		$title  = sanitize_text_field( (string) $p['title'] );
		$fields = self::fields( (array) ( $p['fields'] ?? array() ) );
		$r      = 'cf7' === $plugin ? self::create_cf7( $title, $fields ) : self::create_wpforms( $title, $fields );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['journal_id'] = Journal::record(
			$command_id,
			'form_created',
			(string) $r['id'],
			array( 'plugin' => $plugin )
		);
		$r['next_step']  = 'Embed it with the content or block tools (the owner approves that page change).';
		return $r;
	}

	/**
	 * Contact Form 7.
	 *
	 * @param string $title  Title.
	 * @param array  $fields Fields.
	 * @return array|\WP_Error
	 */
	private static function create_cf7( $title, array $fields ) {
		$tags  = array(
			'name'     => 'text',
			'email'    => 'email',
			'text'     => 'text',
			'textarea' => 'textarea',
			'phone'    => 'tel',
		);
		$form  = '';
		$body  = '';
		$email = '';
		foreach ( $fields as $f ) {
			$tag   = $tags[ $f['type'] ] . ( $f['required'] ? '*' : '' ) . ' ' . $f['name'] . ( 'name' === $f['type'] ? ' autocomplete:name' : '' );
			$form .= '<label> ' . $f['label'] . "\n    [" . $tag . "] </label>\n\n";
			$body .= $f['label'] . ': [' . $f['name'] . "]\n";
			if ( 'email' === $f['type'] && '' === $email ) {
				$email = $f['name'];
			}
		}
		$form .= '[submit "' . esc_attr__( 'Send', 'presshelm-ai' ) . '"]';
		$cf    = \WPCF7_ContactForm::get_template( array( 'title' => $title ) );
		$mail  = (array) $cf->prop( 'mail' );
		// The site admin receives submissions (never an address chosen by the agent).
		$mail['recipient']          = '[_site_admin_email]';
		$mail['subject']            = '[_site_title] "' . $title . '"';
		$mail['body']               = $body . "\n--\n" . __( 'Sent from a form on [_site_title] ([_site_url])', 'presshelm-ai' );
		$mail['additional_headers'] = '' !== $email ? 'Reply-To: [' . $email . ']' : '';
		$cf->set_properties(
			array(
				'form' => $form,
				'mail' => $mail,
			)
		);
		$id = $cf->save();
		if ( ! $id ) {
			return new \WP_Error( 'presshelm_write_failed', 'Contact Form 7 could not save the form' );
		}
		$saved = \WPCF7_ContactForm::get_instance( $id );
		return array(
			'plugin' => 'cf7',
			'id'     => (int) $id,
			'title'  => $title,
			'fields' => $fields,
			'embed'  => self::block( $saved ? $saved->shortcode() : '[contact-form-7 id="' . (int) $id . '"]' ),
		);
	}

	/**
	 * WPForms (created like WPForms does outside its builder, then given the fields).
	 *
	 * @param string $title  Title.
	 * @param array  $fields Fields.
	 * @return array|\WP_Error
	 */
	private static function create_wpforms( $title, array $fields ) {
		$id = wpforms()->obj( 'form' )->add( $title, array(), array( 'builder' => false ) );
		if ( ! $id ) {
			return new \WP_Error( 'presshelm_write_failed', 'WPForms could not create the form' );
		}
		$data   = wpforms()->obj( 'form' )->get( $id, array( 'content_only' => true ) );
		$data   = is_array( $data ) ? $data : array();
		$types  = array(
			'name'     => 'name',
			'email'    => 'email',
			'text'     => 'text',
			'textarea' => 'textarea',
			'phone'    => 'text', // Phone fields need WPForms Pro.
		);
		$list   = array();
		$fid    = 0;
		$emails = array();
		foreach ( $fields as $f ) {
			$field = array(
				'id'       => (string) $fid,
				'type'     => $types[ $f['type'] ],
				'label'    => $f['label'],
				'required' => $f['required'] ? '1' : '',
				'size'     => 'medium',
			);
			if ( 'name' === $f['type'] ) {
				$field['format'] = 'simple';
			}
			if ( 'email' === $f['type'] ) {
				$emails[] = $fid;
			}
			$list[ (string) $fid ] = $field;
			++$fid;
		}
		$data['id']                     = $id;
		$data['fields']                 = $list;
		$data['field_id']               = (string) $fid;
		$data['settings']               = (array) ( $data['settings'] ?? array() );
		$data['settings']['form_title'] = $title;
		if ( $emails && isset( $data['settings']['notifications']['1'] ) ) {
			// Recipient stays {admin_email} (WPForms' default); replies go to the visitor.
			$data['settings']['notifications']['1']['replyto'] = '{field_id="' . $emails[0] . '"}';
		}
		if ( ! wpforms()->obj( 'form' )->update( $id, wp_slash( $data ) ) ) {
			wp_delete_post( $id, true );
			return new \WP_Error( 'presshelm_write_failed', 'WPForms could not save the form fields' );
		}
		return array(
			'plugin' => 'wpforms',
			'id'     => (int) $id,
			'title'  => $title,
			'fields' => $fields,
			'embed'  => self::block( '[wpforms id="' . (int) $id . '"]' ),
		);
	}

	/**
	 * Journal undo: deletes a form PressHelm created.
	 *
	 * @param int    $id     Form id.
	 * @param string $plugin Plugin.
	 * @return true|\WP_Error
	 */
	public static function delete( $id, $plugin ) {
		if ( 'cf7' === $plugin && class_exists( 'WPCF7_ContactForm' ) ) {
			$cf = \WPCF7_ContactForm::get_instance( (int) $id );
			if ( $cf ) {
				$cf->delete();
			}
			return true;
		}
		if ( 'wpforms' === $plugin && 'wpforms' === get_post_type( (int) $id ) ) {
			wp_delete_post( (int) $id, true );
			return true;
		}
		return new \WP_Error( 'presshelm_journal_kind', 'That form plugin is not active; delete the form there' );
	}
}
