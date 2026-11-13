<?php
/**
 * Pages built with a page builder keep their layout in the builder's own data (post meta), or as
 * the builder's shortcodes in the content. Editing them as normal content either changes nothing
 * visible (Elementor, Beaver Builder, Bricks, Oxygen, Breakdance) or can break the layout (Divi,
 * WPBakery). This tells which builder owns a page, from each builder's own markers, so content
 * edits can be refused with a clear reason instead.
 *
 * @package PressHelm
 */

namespace PressHelm\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Builder guard.
 */
final class Guard {

	/**
	 * Builders: id => [name, what PressHelm can do with their pages].
	 */
	const BUILDERS = array(
		'elementor'      => array( 'Elementor', 'Read and change it with builder_read, builder_update (settings) and builder_change (add, remove, move, duplicate elements).' ),
		'divi'           => array( 'Divi', 'PressHelm cannot edit Divi layouts yet.' ),
		'wpbakery'       => array( 'WPBakery', 'PressHelm cannot edit WPBakery layouts yet.' ),
		'beaver'         => array( 'Beaver Builder', 'Read and change it with builder_read, builder_update (settings) and builder_change (add, remove, move, duplicate elements).' ),
		'bricks'         => array( 'Bricks', 'PressHelm cannot edit Bricks layouts yet.' ),
		'oxygen'         => array( 'Oxygen', 'PressHelm cannot edit Oxygen layouts yet.' ),
		'breakdance'     => array( 'Breakdance', 'PressHelm cannot edit Breakdance layouts yet.' ),
		'pagelayer'      => array( 'PageLayer', 'Read and change it with builder_read, builder_update (settings and text) and builder_change (add, remove, move, duplicate elements); PageLayer checks every change itself.' ),
		'visualcomposer' => array( 'Visual Composer', 'Change texts, links and images with builder_read and builder_update, and remove, move or duplicate elements with builder_change; new elements and design settings are added in Visual Composer.' ),
		'siteorigin'     => array( 'SiteOrigin Page Builder', 'Read and change it with builder_read, builder_update (widget settings) and builder_change (add, remove, move, duplicate rows and widgets).' ),
		'seedprod'       => array( 'SeedProd', 'Change texts, links and images with builder_read and builder_update, and remove, move or duplicate elements with builder_change; new elements and design settings are added in SeedProd\'s editor.' ),
		'brizy'          => array( 'Brizy', 'Read and change it with builder_read, builder_update (settings) and builder_change (add, remove, move, duplicate elements); Brizy compiles the page again after each change.' ),
		'colibri'        => array( 'Colibri', 'Change texts, links and images with builder_read and builder_update, and remove or move elements with builder_change; new elements, copies and design settings are made in Colibri\'s editor (Appearance → Customize).' ),
	);

	/**
	 * The builder that owns a post's layout, or null for a normal (block editor / classic) post.
	 *
	 * @param \WP_Post|int $post Post.
	 * @return string|null Builder id.
	 */
	public static function builder_of( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		$id      = (int) $post->ID;
		$meta    = function ( $key ) use ( $id ) {
			return get_post_meta( $id, $key, true );
		};
		$content = (string) $post->post_content;
		// Elementor renders from its own data only while it is active (else WordPress shows the content).
		if ( 'builder' === $meta( '_elementor_edit_mode' ) && defined( 'ELEMENTOR_VERSION' ) ) {
			return 'elementor';
		}
		// Divi and WPBakery keep the layout as shortcodes in the content: guarded even if inactive.
		if ( 'on' === $meta( '_et_pb_use_builder' ) || false !== strpos( $content, '[et_pb_section' ) ) {
			return 'divi';
		}
		if ( 'true' === $meta( '_wpb_vc_js_status' ) || false !== strpos( $content, '[vc_row' ) ) {
			return 'wpbakery';
		}
		if ( $meta( '_fl_builder_enabled' ) && class_exists( 'FLBuilder' ) ) {
			return 'beaver';
		}
		if ( ( 'bricks' === $meta( '_bricks_editor_mode' ) || ! empty( $meta( '_bricks_page_content_2' ) ) ) && defined( 'BRICKS_VERSION' ) ) {
			return 'bricks';
		}
		if ( ( $meta( 'ct_builder_shortcodes' ) || $meta( '_ct_builder_json' ) ) && defined( 'CT_VERSION' ) ) {
			return 'oxygen';
		}
		if ( $meta( '_breakdance_data' ) && defined( '__BREAKDANCE_VERSION' ) ) {
			return 'breakdance';
		}
		// PageLayer pages are pagelayer/* blocks rendered by PageLayer: changed through its own abilities.
		if ( defined( 'PAGELAYER_VERSION' ) && false !== strpos( $content, '<!-- wp:pagelayer/' ) ) {
			return 'pagelayer';
		}
		// Visual Composer (visualcomposer.com) keeps its elements in vcv-pageContent and the HTML its editor made in post_content.
		if ( $meta( 'vcv-pageContent' ) && defined( 'VCV_VERSION' ) ) {
			return 'visualcomposer';
		}
		// SiteOrigin renders the page from its layout (meta panels_data); post_content is only a copy.
		$panels = $meta( 'panels_data' );
		if ( is_array( $panels ) && ( ! empty( $panels['grids'] ) || ! empty( $panels['widgets'] ) ) && class_exists( '\\SiteOrigin_Panels' ) ) {
			return 'siteorigin';
		}
		// SeedProd keeps its editor state in post_content_filtered and the HTML its editor made in post_content.
		if ( ( $meta( '_seedprod_page' ) || $meta( '_seedprod_edited_with_seedprod' ) ) && ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) ) {
			return 'seedprod';
		}
		// Brizy shows the page from its own compiled data while it is active.
		if ( $meta( 'brizy_enabled' ) && class_exists( '\\Brizy_Editor_Post' ) ) {
			return 'brizy';
		}
		// Colibri keeps its editor state in a post of its own (meta extend_builder) and the HTML its editor made in post_content.
		$colibri = $meta( 'extend_builder' );
		if ( is_array( $colibri ) && ! empty( $colibri['json'] ) && function_exists( '\\ExtendBuilder\\save_post_data' ) ) {
			return 'colibri';
		}
		return null;
	}

	/**
	 * Refusal for a content edit of a builder page, or null when the edit is fine.
	 *
	 * @param \WP_Post|int $post Post.
	 * @param string       $what What would be edited (e.g. "this content edit").
	 * @return \WP_Error|null
	 */
	public static function refuse_content_edit( $post, $what ) {
		$b = self::builder_of( $post );
		if ( null === $b ) {
			return null;
		}
		list( $name, $can ) = self::BUILDERS[ $b ];
		$why                = in_array( $b, array( 'divi', 'wpbakery' ), true )
			? 'its layout is stored as ' . $name . ' shortcodes, so ' . $what . ' could break it'
			: 'its layout lives in ' . $name . '\'s own data, so ' . $what . ' would not show on the page';
		return new \WP_Error( 'presshelm_builder_page', 'This page is built with ' . $name . ': ' . $why . '. Nothing was changed. ' . $can . ' The owner can edit it in ' . $name . '. Title, status, excerpt and SEO fields can still be changed.' );
	}

	/**
	 * Refuses edits of a WPCode snippet through the content tools: its post content is code and its
	 * status decides whether it runs, so it only changes through snippets.save (PHP stays disabled).
	 *
	 * @param \WP_Post|null $post Post.
	 * @return \WP_Error|null
	 */
	public static function refuse_snippet( $post ) {
		if ( $post && 'wpcode' === $post->post_type ) {
			return new \WP_Error( 'presshelm_snippet_route', 'This is a WPCode code snippet: it is only changed with the snippet tool (snippets.save), which keeps PHP disabled for the owner to enable. Nothing was changed.' );
		}
		return null;
	}

	/**
	 * Block-based builders (their pages are blocks: blocks.inspect / blocks.patch) => detection.
	 */
	const BLOCK_BUILDERS = array(
		'kubio' => 'KUBIO_VERSION',
	);

	/**
	 * Builders present on the site (for the capability report).
	 *
	 * @return array<string,bool>
	 */
	public static function present() {
		return array(
			'wpbakery'       => defined( 'WPB_VC_VERSION' ),
			'oxygen'         => defined( 'CT_VERSION' ),
			'breakdance'     => defined( '__BREAKDANCE_VERSION' ),
			'brizy'          => defined( 'BRIZY_VERSION' ),
			'seedprod'       => defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ),
			'siteorigin'     => defined( 'SITEORIGIN_PANELS_VERSION' ),
			'visualcomposer' => defined( 'VCV_VERSION' ),
			'pagelayer'      => defined( 'PAGELAYER_VERSION' ),
			'colibri'        => function_exists( '\\ExtendBuilder\\save_post_data' ),
		) + array_map( 'defined', self::BLOCK_BUILDERS );
	}
}
