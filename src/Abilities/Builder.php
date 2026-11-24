<?php
/**
 * Page builders (M3.9): one pair of abilities for every builder with an adapter (Elementor now;
 * more adapters plug into Builder::adapters()). The adapter for a page is found automatically.
 *
 * @package PressHelm
 */

namespace PressHelm\Abilities;

use PressHelm\Builders\Adapter;
use PressHelm\Recovery\Journal;

defined( 'ABSPATH' ) || exit;

/**
 * Builder abilities.
 */
final class Builder {

	/**
	 * Known adapters.
	 *
	 * @return Adapter[]
	 */
	public static function adapters() {
		return array( new \PressHelm\Builders\Elementor(), new \PressHelm\Builders\Beaver(), new \PressHelm\Builders\Brizy(), new \PressHelm\Builders\Siteorigin() );
	}

	/**
	 * Engines for the integration packs a command carries (to-do 21); invalid packs are skipped
	 * (the first problem is kept for the error message).
	 *
	 * @param mixed          $packs Packs from the payload.
	 * @param \WP_Error|null $error First validation problem (out).
	 * @return Adapter[]
	 */
	public static function from_packs( $packs, &$error = null ) {
		$out = array();
		foreach ( is_array( $packs ) ? array_slice( $packs, 0, 20 ) : array() as $pack ) {
			$v = \PressHelm\Packs\Pack::validate( $pack );
			if ( is_wp_error( $v ) ) {
				$error = $error ? $error : $v;
				continue;
			}
			$out[] = 'ability' === $v['source']['type'] ? new \PressHelm\Packs\AbilityDocument( $v ) : new \PressHelm\Packs\Document( $v );
		}
		return $out;
	}

	/**
	 * Names of the active builders (for descriptions).
	 *
	 * @return string[]
	 */
	public static function names() {
		$out = array();
		foreach ( self::active() as $a ) {
			$out[] = \PressHelm\Builders\Guard::BUILDERS[ $a->id() ][0] ?? $a->id();
		}
		return $out;
	}

	/**
	 * Active adapters.
	 *
	 * @return Adapter[]
	 */
	public static function active() {
		return array_values(
			array_filter(
				self::adapters(),
				static function ( Adapter $a ) {
					return $a->active();
				}
			)
		);
	}

	/**
	 * The adapter a post is built with.
	 *
	 * @param int $post_id Post id.
	 * @return Adapter|null
	 */
	public static function for_post( $post_id, $packs = array() ) {
		foreach ( self::active() as $a ) {
			if ( $a->is_built( (int) $post_id ) ) {
				return $a;
			}
		}
		foreach ( self::from_packs( $packs ) as $a ) {
			if ( $a->active() && $a->is_built( (int) $post_id ) ) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Ability definitions (only when a builder is active).
	 *
	 * @return array
	 */
	public static function definitions() {
		$schema = array(
			'builder.schema' => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'schema' ),
				'label'       => 'Look up a builder element\'s settings',
				'description' => 'Read live from the builder itself: without type, the element types this site offers (widgets, modules or blocks); with type, that type\'s settings (name, kind, label, default, options) and how values are stored. Content settings by default; all=true adds style and advanced.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'builder' => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9][a-z0-9_-]{1,60}$',
						),
						'packs'   => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array( 'type' => 'object' ),
						),
						'type'    => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'all'     => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'builder' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'undo'        => null,
			),
		);
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return $schema + array(
			'builder.read'   => array(
				'risk'        => 'READ',
				'cap'         => 'edit_posts',
				'mutates'     => false,
				'run'         => array( self::class, 'read' ),
				'label'       => 'Read a page-builder page',
				'description' => 'For pages built with a supported page builder (' . implode( ', ', self::names() ) . '): the element tree (id, type, widget, settings) and data_sha256. Normal pages use blocks.inspect instead.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => $id,
						'packs' => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array( 'type' => 'object' ),
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
			'builder.update' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'update' ),
				'label'       => 'Change a page-builder element',
				'description' => 'Sets settings of one element (e.g. a heading\'s title, a button\'s text or link) through the builder\'s own save, with a revision first and the page CSS rebuilt. Bound to data_sha256 from builder.read. HTML/shortcode widgets, custom CSS and attributes are never changed. Undo puts the page back exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => $id,
						'element_id'  => array(
							'type'    => 'string',
							'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$',
						),
						'settings'    => array(
							'type'          => 'object',
							'minProperties' => 1,
							'maxProperties' => 30,
						),
						'data_sha256' => array(
							'type'    => 'string',
							'pattern' => '^[a-fA-F0-9]{64}$',
						),
						'packs'       => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array( 'type' => 'object' ),
						),
					),
					'required'             => array( 'id', 'element_id', 'settings', 'data_sha256' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
			'builder.change' => array(
				'risk'        => 'MEDIUM',
				'cap'         => 'edit_posts',
				'mutates'     => true,
				'run'         => array( self::class, 'change' ),
				'label'       => 'Add, remove, move or duplicate a page-builder element',
				'description' => 'Structure changes through the builder\'s own save: insert an element (widget, section, column, container) at a position, remove one, move one, or duplicate one (fresh ids, placed after it). An empty page (builder.read can_start) is started with a builder by an insert with builder set. The builder\'s nesting rules and registered widgets apply; HTML/shortcode widgets and custom code are never added. Bound to data_sha256 from builder.read. Undo puts the page back exactly.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => $id,
						'op'          => array(
							'type' => 'string',
							'enum' => array( 'insert', 'remove', 'move', 'duplicate' ),
						),
						'element_id'  => array(
							'type'    => 'string',
							'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$',
						),
						'parent_id'   => array(
							'type'    => 'string',
							'pattern' => '^([A-Za-z0-9][A-Za-z0-9_-]{0,39})?$',
						),
						'position'    => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'element'     => array( 'type' => 'object' ),
						'builder'     => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9][a-z0-9_-]{1,60}$',
						),
						'data_sha256' => array(
							'type'    => 'string',
							'pattern' => '^[a-fA-F0-9]{64}$',
						),
						'packs'       => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array( 'type' => 'object' ),
						),
					),
					'required'             => array( 'id', 'op', 'data_sha256' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'undo'        => 'journal.restore',
			),
		);
	}

	/**
	 * A builder's element types, or one type's settings (read live from the builder).
	 *
	 * @param array $p {builder, type?, all?}.
	 * @return array|\WP_Error
	 */
	public static function schema( array $p ) {
		$type = trim( (string) ( $p['type'] ?? '' ) );
		if ( 'blocks' === $p['builder'] ) {
			return \PressHelm\Builders\Schema::blocks( $type );
		}
		foreach ( array_merge( self::active(), self::from_packs( $p['packs'] ?? array() ) ) as $a ) {
			if ( $a->id() === $p['builder'] && $a->active() ) {
				return $a->schema( $type, ! empty( $p['all'] ) );
			}
		}
		return new \WP_Error( 'presshelm_not_builder', 'That builder is not active on this site.' );
	}

	/**
	 * Reads a builder page.
	 *
	 * @param array $p {id}.
	 * @return array|\WP_Error
	 */
	public static function read( array $p ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$a = self::for_post( $post->ID, $p['packs'] ?? array() );
		if ( ! $a ) {
			$can = self::empty_page( $post ) ? self::startable() : array();
			if ( $can ) {
				// A new, empty page: the agent can build it with a builder from scratch.
				return array(
					'id'          => $post->ID,
					'builder'     => null,
					'empty'       => true,
					'can_start'   => array_map(
						static function ( Adapter $x ) {
							return $x->id();
						},
						$can
					),
					'data_sha256' => hash( 'sha256', '' ),
					'elements'    => array(),
					'vocabulary'  => $can[0]->vocabulary(),
					'how'         => 'This page is empty and not built with a builder yet. To build it with ' . $can[0]->id() . ': builder.change op "insert" with builder "' . $can[0]->id() . '", this data_sha256 and the first top-level element. Undo makes it a plain empty page again.',
				);
			}
			return self::not_builder( $p );
		}
		return array(
			'id'          => $post->ID,
			'builder'     => $a->id(),
			'data_sha256' => hash( 'sha256', $a->raw( $post->ID ) ),
			'elements'    => $a->tree( $post->ID ),
			'vocabulary'  => $a->vocabulary(),
		);
	}

	/**
	 * Changes one element (journaled; health-checked by the Executor).
	 *
	 * @param array  $p          {id, element_id, settings, data_sha256}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function update( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$a = self::for_post( $post->ID, $p['packs'] ?? array() );
		if ( ! $a ) {
			return self::not_builder( $p );
		}
		$raw = $a->raw( $post->ID );
		if ( ! hash_equals( hash( 'sha256', $raw ), strtolower( (string) $p['data_sha256'] ) ) ) {
			return new \WP_Error( 'presshelm_conflict', 'The page changed since it was read; read it again' );
		}
		$journal = Journal::record(
			$command_id,
			'builder',
			(string) $post->ID,
			self::journal_entry( $a, $raw )
		);
		$r       = $a->update_element( $post->ID, (string) $p['element_id'], (array) $p['settings'] );
		if ( is_wp_error( $r ) ) {
			Journal::restore( $journal );
			return $r;
		}
		return array(
			'id'          => $post->ID,
			'builder'     => $a->id(),
			'data_sha256' => hash( 'sha256', $a->raw( $post->ID ) ),
			'journal_id'  => $journal,
		);
	}

	/**
	 * Inserts, removes, moves or duplicates one element (journaled; checked by the Executor).
	 *
	 * @param array  $p          {id, op, element_id?, parent_id?, position?, element?, data_sha256}.
	 * @param string $command_id Run id.
	 * @return array|\WP_Error
	 */
	public static function change( array $p, $command_id ) {
		$post = get_post( (int) $p['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'presshelm_not_found', 'Post not found' );
		}
		$a       = self::for_post( $post->ID, $p['packs'] ?? array() );
		$started = false;
		if ( ! $a && ! empty( $p['builder'] ) && 'insert' === (string) $p['op'] ) {
			// Build an empty page with a builder from scratch (builder.read said can_start).
			foreach ( self::startable() as $x ) {
				if ( $x->id() === (string) $p['builder'] ) {
					$a = $x;
				}
			}
			if ( ! $a ) {
				return new \WP_Error( 'presshelm_not_builder', 'That builder is not active on this site or cannot start a page.' );
			}
			if ( ! self::empty_page( $post ) ) {
				return new \WP_Error( 'presshelm_not_empty', 'Only an empty page can be started with a builder; this one already has content (use blocks.inspect / content.read).' );
			}
			$started = true;
		}
		if ( ! $a ) {
			return self::not_builder( $p );
		}
		$raw = $started ? '' : $a->raw( $post->ID );
		if ( ! hash_equals( hash( 'sha256', $raw ), strtolower( (string) $p['data_sha256'] ) ) ) {
			return new \WP_Error( 'presshelm_conflict', 'The page changed since it was read; read it again' );
		}
		$journal = Journal::record(
			$command_id,
			'builder',
			(string) $post->ID,
			self::journal_entry( $a, $raw ) + ( $started ? array( 'started' => true ) : array() )
		);
		if ( $started ) {
			$s = $a->start( $post->ID );
			if ( is_wp_error( $s ) ) {
				Journal::restore( $journal );
				return $s;
			}
		}
		$r = $a->change( $post->ID, $p );
		if ( is_wp_error( $r ) ) {
			Journal::restore( $journal );
			return $r;
		}
		return array(
			'id'          => $post->ID,
			'builder'     => $a->id(),
			'op'          => (string) $p['op'],
			'data_sha256' => hash( 'sha256', $a->raw( $post->ID ) ),
			'elements'    => $a->tree( $post->ID ),
			'journal_id'  => $journal,
		);
	}

	/**
	 * What the journal keeps for undo: the adapter, the stored data and, for a pack, the pack itself
	 * (so undo works on the site alone).
	 *
	 * @param Adapter $a   Adapter.
	 * @param string  $raw Stored data.
	 * @return array
	 */
	private static function journal_entry( Adapter $a, $raw ) {
		$out = array(
			'adapter' => $a->id(),
			'raw'     => $raw,
		);
		if ( method_exists( $a, 'pack' ) ) {
			$out['pack'] = $a->pack();
		}
		return $out;
	}

	/**
	 * Active adapters that can start an empty page from scratch.
	 *
	 * @return Adapter[]
	 */
	private static function startable() {
		return array_values(
			array_filter(
				self::active(),
				static function ( Adapter $a ) {
					return method_exists( $a, 'start' ) && method_exists( $a, 'unstart' );
				}
			)
		);
	}

	/**
	 * A page with nothing in it yet (a new draft): no content and no builder data.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function empty_page( $post ) {
		return '' === trim( (string) $post->post_content ) && '' === (string) get_post_meta( $post->ID, '_elementor_data', true );
	}

	/**
	 * Not a builder page PressHelm can work with (with a pack problem, if there was one).
	 *
	 * @param array $p Payload.
	 * @return \WP_Error
	 */
	private static function not_builder( array $p ) {
		$error = null;
		self::from_packs( $p['packs'] ?? array(), $error );
		return $error ? $error : new \WP_Error( 'presshelm_not_builder', 'This page is not built with a page builder PressHelm supports; use blocks.inspect / content.read' );
	}

	/**
	 * Journal undo.
	 *
	 * @param int   $post_id Post id.
	 * @param array $before  {adapter, raw}.
	 * @return true|\WP_Error
	 */
	public static function restore( $post_id, array $before ) {
		if ( isset( $before['pack'] ) ) {
			$engines = self::from_packs( array( $before['pack'] ) );
			if ( ! $engines ) {
				return new \WP_Error( 'presshelm_journal_corrupt', 'The saved builder description is damaged' );
			}
			if ( ! $engines[0]->active() ) {
				return new \WP_Error( 'presshelm_builder_inactive', 'The page builder is not active; activate it, then undo again' );
			}
			return $engines[0]->restore( (int) $post_id, (string) ( $before['raw'] ?? '' ) );
		}
		foreach ( self::adapters() as $a ) {
			if ( $a->id() === ( $before['adapter'] ?? '' ) ) {
				if ( ! $a->active() ) {
					return new \WP_Error( 'presshelm_builder_inactive', 'The page builder is not active; activate it, then undo again' );
				}
				// The change started the page with this builder: undo makes it a plain empty page again.
				if ( ! empty( $before['started'] ) && method_exists( $a, 'unstart' ) ) {
					return $a->unstart( (int) $post_id );
				}
				return $a->restore( (int) $post_id, (string) ( $before['raw'] ?? '' ) );
			}
		}
		return new \WP_Error( 'presshelm_journal_kind', 'Unknown page builder' );
	}
}
