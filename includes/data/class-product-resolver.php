<?php
/**
 * Resolves which WooCommerce product a widget refers to.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single place where "which campaign is this widget about?" is answered.
 *
 * Every widget and dynamic tag routes through here so that loop items,
 * archive templates, theme-builder previews and legacy meta-key setups all
 * behave identically.
 */
final class Product_Resolver {

	/**
	 * Transient key for the editor's donable-product picker.
	 */
	const OPTIONS_TRANSIENT = 'wcdp_el_donable_options';

	/**
	 * Memoized product options for the current request.
	 *
	 * @var array<int|string, string>|null
	 */
	private static $options_cache = null;

	/**
	 * Resolve the product ID from widget settings.
	 *
	 * Supported sources:
	 *  - `manual`  — a campaign chosen in the picker (`product_id_manual`)
	 *  - `current` — the post being displayed, or the current loop item
	 *  - `tag`     — any dynamic tag or literal value (`product_id_tag`)
	 *  - `dynamic` — a post meta field holding the ID (`product_meta_key`)
	 *
	 * The stored source keys and control names are deliberately unchanged from
	 * 1.0.x. Elementor never persists a control left at its default, so an
	 * existing widget that used the old default has no `product_source` value
	 * at all — renaming the keys would silently repoint every one of those
	 * widgets at the wrong product.
	 *
	 * @param array<string, mixed> $settings Widget settings for display.
	 * @return int Product ID, or 0 when unresolvable.
	 */
	public static function resolve( array $settings ): int {
		$source = isset( $settings['product_source'] ) ? (string) $settings['product_source'] : 'manual';

		switch ( $source ) {
			case 'current':
				$id = self::context_post_id();
				break;

			case 'tag':
				$id = absint( $settings['product_id_tag'] ?? 0 );
				break;

			case 'dynamic':
				$id = self::from_meta( (string) ( $settings['product_meta_key'] ?? '' ) );
				break;

			case 'all':
				// Handled by the calling widget; there is no single product.
				return 0;

			case 'manual':
			default:
				$id = absint( $settings['product_id_manual'] ?? 0 );
				break;
		}

		return self::to_parent( $id );
	}

	/**
	 * Read a product ID out of a post meta field on the current post.
	 *
	 * @param string $meta_key Meta field name.
	 * @return int
	 */
	private static function from_meta( string $meta_key ): int {
		$meta_key = trim( $meta_key );

		if ( '' === $meta_key ) {
			return 0;
		}

		$post_id = self::context_post_id();

		if ( ! $post_id ) {
			return 0;
		}

		return absint( get_post_meta( $post_id, $meta_key, true ) );
	}

	/**
	 * The post that supplies context for the current render.
	 *
	 * Resolution order matters: a loop item beats the queried object (so a
	 * Loop Grid or archive template renders per-item data), an Elementor
	 * template preview beats the template's own ID (which would otherwise
	 * resolve to the template post), and the queried object is the fallback.
	 *
	 * @return int
	 */
	public static function context_post_id(): int {
		if ( in_the_loop() ) {
			$loop_id = get_the_ID();

			if ( $loop_id ) {
				return (int) $loop_id;
			}
		}

		$preview_id = self::elementor_preview_post_id();

		if ( $preview_id ) {
			return $preview_id;
		}

		$queried_id = get_queried_object_id();

		if ( $queried_id ) {
			return (int) $queried_id;
		}

		$current_id = get_the_ID();

		return $current_id ? (int) $current_id : 0;
	}

	/**
	 * The stand-in post configured in an Elementor template's preview settings.
	 *
	 * Only ever non-zero while editing or previewing a document that defines
	 * one, so it is inert on the frontend and for ordinary pages.
	 *
	 * @return int
	 */
	private static function elementor_preview_post_id(): int {
		if ( ! self::is_elementor_render_context() ) {
			return 0;
		}

		$elementor = \Elementor\Plugin::$instance;
		$document  = $elementor->documents ? $elementor->documents->get_current() : null;

		if ( ! $document ) {
			$post_id  = get_the_ID();
			$document = ( $post_id && $elementor->documents ) ? $elementor->documents->get( $post_id ) : null;
		}

		if ( ! $document ) {
			return 0;
		}

		$preview_id = $document->get_settings( 'preview_id' );

		return $preview_id ? absint( $preview_id ) : 0;
	}

	/**
	 * True while Elementor is rendering in the editor or the preview iframe.
	 *
	 * @return bool
	 */
	public static function is_elementor_render_context(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( $elementor->editor && $elementor->editor->is_edit_mode() ) {
			return true;
		}

		if ( $elementor->preview && $elementor->preview->is_preview_mode() ) {
			return true;
		}

		return false;
	}

	/**
	 * True for requests that build the editor panel and therefore need the
	 * product picker populated.
	 *
	 * Populating a SELECT2 with every product is the single most expensive
	 * thing the 1.0.x widgets did, and it ran on the frontend too, once per
	 * widget instance. The stored value is all the frontend ever needs.
	 *
	 * @return bool
	 */
	public static function is_panel_request(): bool {
		if ( is_admin() ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return true;
		}

		if ( wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context probe, no state change.

			return 'elementor_ajax' === $action;
		}

		return false;
	}

	/**
	 * Options for the donable-product picker.
	 *
	 * Returns an empty array outside panel requests so the frontend never runs
	 * the query.
	 *
	 * @return array<int|string, string>
	 */
	public static function get_options(): array {
		if ( ! self::is_panel_request() ) {
			return array();
		}

		if ( null !== self::$options_cache ) {
			return self::$options_cache;
		}

		$cached = get_transient( self::OPTIONS_TRANSIENT );

		if ( is_array( $cached ) ) {
			self::$options_cache = $cached;

			return $cached;
		}

		$options = array( '' => esc_html__( '— Select a campaign —', 'styleraise' ) );

		/**
		 * Filter the maximum number of products offered in the picker.
		 *
		 * @param int $limit Maximum number of options.
		 */
		$limit = (int) apply_filters( 'wcdp_el_product_picker_limit', 300 );

		// The picker lists campaigns in every language, each labelled with
		// its own. Polylang reads `'lang' => ''`; WPML has no query argument
		// for it, so its current language is switched to "all" around the
		// query and restored right after. Null means WPML is not active.
		$wpml_language = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.

		if ( null !== $wpml_language ) {
			do_action( 'wpml_switch_language', 'all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => $limit,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'lang'                   => '',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta key, editor-only, transient-cached.
					array(
						'key'   => '_donable',
						'value' => 'yes',
					),
				),
			)
		);

		if ( null !== $wpml_language ) {
			do_action( 'wpml_switch_language', $wpml_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.
		}

		foreach ( $query->posts as $product_id ) {
			$product_id = (int) $product_id;
			$options[ $product_id ] = self::format_option_label( $product_id );
		}

		set_transient( self::OPTIONS_TRANSIENT, $options, 5 * MINUTE_IN_SECONDS );

		self::$options_cache = $options;

		return $options;
	}

	/**
	 * Human-readable label for one product in the picker.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function format_option_label( int $product_id ): string {
		$title    = get_the_title( $product_id );
		$language = self::get_post_language( $product_id );

		if ( '' === $title ) {
			$title = esc_html__( '(no title)', 'styleraise' );
		}

		if ( '' !== $language ) {
			return sprintf( '%s — #%d [%s]', $title, $product_id, $language );
		}

		return sprintf( '%s — #%d', $title, $product_id );
	}

	/**
	 * Uppercased language code for a post, when a multilingual plugin is active.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty string when no multilingual plugin is present.
	 */
	public static function get_post_language( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$language = pll_get_post_language( $post_id );

			return $language ? strtoupper( (string) $language ) : '';
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.

			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return strtoupper( (string) $details['language_code'] );
			}
		}

		return '';
	}

	/**
	 * Translated siblings of a product, used to combine progress across
	 * languages.
	 *
	 * @param int $product_id Product ID.
	 * @return int[] Sibling IDs, excluding the product itself.
	 */
	public static function get_translation_ids( int $product_id ): array {
		$ids = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $product_id );

			if ( is_array( $translations ) ) {
				$ids = array_map( 'absint', array_values( $translations ) );
			}
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$trid = apply_filters( 'wpml_element_trid', null, $product_id, 'post_product' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.

			if ( $trid ) {
				$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: WPML API.

				if ( is_array( $translations ) ) {
					foreach ( $translations as $translation ) {
						if ( isset( $translation->element_id ) ) {
							$ids[] = absint( $translation->element_id );
						}
					}
				}
			}
		}

		$ids = array_filter(
			$ids,
			static function ( $id ) use ( $product_id ) {
				return $id && $id !== $product_id;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve a variation to its parent product.
	 *
	 * WCDP stores campaign settings (goal, end date, donable flag) on the
	 * parent, so a variation ID has to be lifted before anything else.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int
	 */
	public static function to_parent( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		$parent_id = wp_get_post_parent_id( $product_id );

		return $parent_id ? (int) $parent_id : $product_id;
	}

	/**
	 * Whether donations are enabled for a product, per WCDP.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_donable( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( class_exists( 'WCDP_Form' ) ) {
			return (bool) \WCDP_Form::is_donable( $product_id );
		}

		return 'yes' === get_post_meta( $product_id, '_donable', true );
	}

	/**
	 * Load a WC_Product instance safely.
	 *
	 * @param int $product_id Product ID.
	 * @return \WC_Product|null
	 */
	public static function get_product( int $product_id ) {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return ( $product instanceof \WC_Product ) ? $product : null;
	}

	/**
	 * Invalidate the picker cache.
	 */
	public static function flush_options_cache(): void {
		delete_transient( self::OPTIONS_TRANSIENT );
		self::$options_cache = null;
	}
}
