<?php
/**
 * Shared base class for every WCDP Elementor widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base as Elementor_Widget_Base;
use WCDP_Elementor\Controls\Style_Controls;
use WCDP_Elementor\Data\Campaign;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class carrying the campaign-source controls, the render guards and the
 * Elementor performance opt-ins that every widget in the addon shares.
 */
abstract class Widget_Base extends Elementor_Widget_Base {

	use Style_Controls;

	/**
	 * Widget category.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( Plugin::CATEGORY );
	}

	/**
	 * Render with a single wrapper element.
	 *
	 * Nothing in this addon styles through `.elementor-widget-container`, so
	 * the inner wrapper is pure DOM weight.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	/**
	 * Campaign figures change with every donation, so the rendered output must
	 * never be served from Elementor's element cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Stylesheets this widget needs.
	 *
	 * Elementor enqueues these only on pages where the widget is present.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base' );
	}

	/* ─────────────────────────── Shared controls ─────────────────────────── */

	/**
	 * Register the campaign-source controls.
	 *
	 * @param array<string, mixed> $args `default` source, `allow_all` to offer
	 *                                   the "every campaign" option.
	 */
	protected function register_product_controls( array $args = array() ): void {
		$allow_all = ! empty( $args['allow_all'] );
		$default   = (string) ( $args['default'] ?? 'manual' );

		$sources = array();

		if ( $allow_all ) {
			$sources['all'] = esc_html__( 'All campaigns', 'styleraise' );
		}

		$sources['manual']  = esc_html__( 'Pick a campaign', 'styleraise' );
		$sources['current'] = esc_html__( 'Current post / loop item', 'styleraise' );
		$sources['tag']     = esc_html__( 'Dynamic tag or custom value', 'styleraise' );
		$sources['dynamic'] = esc_html__( 'Post meta field', 'styleraise' );

		$this->add_control(
			'product_source',
			array(
				'label'   => esc_html__( 'Campaign Source', 'styleraise' ),
				'type'    => Controls_Manager::SELECT,
				'default' => $default,
				'options' => $sources,
			)
		);

		$this->add_control(
			'product_id_manual',
			array(
				'label'       => esc_html__( 'Campaign', 'styleraise' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => Product_Resolver::get_options(),
				'default'     => '',
				'label_block' => true,
				'condition'   => array( 'product_source' => 'manual' ),
				'description' => esc_html__( 'Only products with donations enabled are listed.', 'styleraise' ),
			)
		);

		$this->add_control(
			'product_id_tag',
			array(
				'label'       => esc_html__( 'Product ID', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'condition'   => array( 'product_source' => 'tag' ),
				'description' => esc_html__( 'Accepts a literal ID or any dynamic tag that resolves to one — an ACF field, a JetEngine value, the WCDP Product ID tag.', 'styleraise' ),
			)
		);

		$this->add_control(
			'product_meta_key',
			array(
				'label'       => esc_html__( 'Meta Key', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'placeholder' => 'collection_select',
				'condition'   => array( 'product_source' => 'dynamic' ),
				'description' => esc_html__( 'Custom field on the current post that stores the product ID.', 'styleraise' ),
			)
		);

		if ( 'all' !== $default ) {
			$this->add_control(
				'product_source_notice',
				array(
					'type'      => Controls_Manager::RAW_HTML,
					'raw'       => '<div class="elementor-control-field-description">' . esc_html__( 'In an archive or Loop Grid template, choose "Current post / loop item" so every card shows its own campaign.', 'styleraise' ) . '</div>',
					'condition' => array( 'product_source' => 'manual' ),
				)
			);
		}
	}

	/**
	 * Register the controls that adjust the campaign totals.
	 *
	 * @param array<string, mixed> $args Reserved for future use.
	 */
	protected function register_campaign_data_controls( array $args = array() ): void {
		unset( $args );

		$this->add_control(
			'goal',
			array(
				'label'       => esc_html__( 'Goal Override', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'min'         => 0,
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Leave empty to use the goal set on the product.', 'styleraise' ),
			)
		);

		$this->add_control(
			'cheat',
			array(
				'label'       => esc_html__( 'Amount Correction', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Added to the raised total, for example to include offline donations. Can be negative.', 'styleraise' ),
			)
		);

		$this->add_control(
			'addids',
			array(
				'label'       => esc_html__( 'Additional Product IDs', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Comma-separated IDs whose donations are added to this campaign.', 'styleraise' ),
			)
		);

		$this->add_control(
			'combine_translations',
			array(
				'label'        => esc_html__( 'Combine Translations', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'With Polylang or WPML active, add the donations of every translation of this product. Turn off if each language is a separate campaign.', 'styleraise' ),
			)
		);
	}

	/**
	 * Register the per-widget override for live figure refreshing.
	 *
	 * Site policy lives in the settings screen; this exists because one page
	 * rarely wants one answer. A hero progress bar under paid traffic should
	 * refresh, while a grid of fifty archive cards showing the same figures
	 * should not pay for it.
	 */
	protected function register_live_control(): void {
		$this->add_control(
			'live_updates',
			array(
				'label'       => esc_html__( 'Live Updates', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'inherit',
				'separator'   => 'before',
				'options'     => array(
					'inherit' => esc_html__( 'Follow site setting', 'styleraise' ),
					'on'      => esc_html__( 'Always refresh', 'styleraise' ),
					'off'     => esc_html__( 'Never refresh', 'styleraise' ),
				),
				'description' => esc_html__( 'Page caches serve the figures that were current when the page was cached. Refreshing fetches the real ones after load, so a cached page still shows the truth.', 'styleraise' ),
			)
		);
	}

	/* ─────────────────────────── Data helpers ─────────────────────────── */

	/**
	 * Product ID this widget instance refers to.
	 *
	 * @return int
	 */
	protected function get_product_id(): int {
		return Product_Resolver::resolve( $this->get_settings_for_display() );
	}

	/**
	 * Campaign snapshot for this widget instance.
	 *
	 * @return Campaign|null
	 */
	protected function get_campaign() {
		$settings   = $this->get_settings_for_display();
		$product_id = Product_Resolver::resolve( $settings );

		if ( ! $product_id ) {
			return null;
		}

		return Campaign::get( $product_id, $this->build_campaign_args( $product_id, $settings ) );
	}

	/**
	 * Assemble the campaign arguments from widget settings.
	 *
	 * @param int                  $product_id Resolved product ID.
	 * @param array<string, mixed> $settings   Widget settings.
	 * @return array<string, mixed>
	 */
	protected function build_campaign_args( int $product_id, array $settings ): array {
		$addids = array();

		if ( ! empty( $settings['addids'] ) ) {
			$addids = array_map( 'absint', array_map( 'trim', explode( ',', (string) $settings['addids'] ) ) );
		}

		if ( 'yes' === ( $settings['combine_translations'] ?? 'yes' ) ) {
			$addids = array_merge( $addids, Product_Resolver::get_translation_ids( $product_id ) );
		}

		return array(
			'goal'   => isset( $settings['goal'] ) && is_numeric( $settings['goal'] ) ? (float) $settings['goal'] : '',
			'cheat'  => isset( $settings['cheat'] ) && is_numeric( $settings['cheat'] ) ? (float) $settings['cheat'] : 0,
			'addids' => array_values( array_filter( $addids ) ),
			// Reaches the probe, so the chosen symbol position also applies to
			// the amounts WCDP formats in "match the site" mode.
			'currency_position' => (string) ( $settings['currency_position'] ?? '' ),
		);
	}

	/* ─────────────────────────── Render helpers ─────────────────────────── */

	/**
	 * Whether the current render happens inside the Elementor editor.
	 *
	 * @return bool
	 */
	protected function is_editor(): bool {
		return Product_Resolver::is_elementor_render_context();
	}

	/**
	 * Print an editor-only placeholder explaining why nothing rendered.
	 *
	 * Stays silent on the frontend: a visitor should see an empty space, never
	 * a configuration hint.
	 *
	 * @param string $message Explanation shown to the editor.
	 */
	protected function render_placeholder( string $message ): void {
		if ( ! $this->is_editor() ) {
			return;
		}

		printf(
			'<div class="wcdp-el-placeholder"><strong class="wcdp-el-placeholder__title">%1$s</strong><span class="wcdp-el-placeholder__text">%2$s</span></div>',
			esc_html( $this->get_title() ),
			esc_html( $message )
		);
	}

	/**
	 * Placeholder text tailored to the configured campaign source.
	 *
	 * @return string
	 */
	protected function get_unresolved_message(): string {
		$settings = $this->get_settings_for_display();
		$source   = (string) ( $settings['product_source'] ?? 'manual' );

		switch ( $source ) {
			case 'current':
				return esc_html__( 'No donation product in the current context. Set a Preview Post in the Elementor settings, or place this widget in a product template or loop.', 'styleraise' );

			case 'tag':
				return esc_html__( 'The dynamic tag did not resolve to a product with donations enabled.', 'styleraise' );

			case 'dynamic':
				return empty( $settings['product_meta_key'] )
					? esc_html__( 'Enter the meta key that stores the product ID.', 'styleraise' )
					: esc_html__( 'The meta field is empty on this post, or it points to a product without donations enabled. A Preview Post may be required.', 'styleraise' );

			case 'manual':
			default:
				return esc_html__( 'Pick a campaign, or switch the source to "Current post / loop item".', 'styleraise' );
		}
	}

	/**
	 * Documentation link shown in the panel header.
	 *
	 * @return string
	 */
	public function get_custom_help_url(): string {
		return 'https://www.wc-donation.com/documentation/';
	}
}
