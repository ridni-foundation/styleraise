<?php
/**
 * Donation counter widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use WCDP_Elementor\Cache\Freshness;
use WCDP_Elementor\Cache\Live_Target;
use WCDP_Elementor\Cache\Location_Index;
use WCDP_Elementor\Data\Campaign;
use WCDP_Elementor\Data\Value_Formatter;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows how many donations a campaign has received.
 *
 * The number renders into its own span so it can be styled as a badge — a
 * different size, colour and background from the sentence around it — without
 * any custom CSS.
 */
class Order_Counter extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-order-counter';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Donation Counter', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-counter';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'counter', 'donations', 'donors', 'supporters', 'orders', 'wcdp' );
	}

	/**
	 * Stylesheets.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base' );
	}

	/**
	 * Scripts.
	 *
	 * Elementor loads this only on pages carrying the widget. The script itself
	 * no-ops when it finds no refreshable figures, so a per-instance "never
	 * refresh" costs nothing beyond the cached file.
	 *
	 * @return string[]
	 */
	public function get_script_depends(): array {
		return Freshness::hydration_enabled() ? array( 'wcdp-el-live' ) : array();
	}

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->open_content_section( 'section_campaign', esc_html__( 'Campaign', 'styleraise' ) );

		$this->register_product_controls();
		$this->register_live_control();

		// Legacy control names — 1.0.x values carry over untouched.
		$this->add_control(
			'label',
			array(
				'label'       => esc_html__( 'Text', 'styleraise' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'default'     => esc_html__( '{ORDER_COUNT} people have already contributed to this project', 'styleraise' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Use {ORDER_COUNT} or {count} where the number should appear.', 'styleraise' ),
			)
		);

		$this->add_control(
			'fallback',
			array(
				'label'       => esc_html__( 'Text With No Donations Yet', 'styleraise' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Shown when the count is zero. Leave empty to keep the text above.', 'styleraise' ),
			)
		);

		$this->add_control(
			'cheat',
			array(
				'label'       => esc_html__( 'Count Correction', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'dynamic'     => array( 'active' => true ),
				'description' => esc_html__( 'Added to the real count. Can be negative.', 'styleraise' ),
			)
		);

		$this->add_control(
			'hide_when_empty',
			array(
				'label'        => esc_html__( 'Hide When Zero', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Render nothing at all until the first donation arrives.', 'styleraise' ),
			)
		);

		$this->add_control(
			'combine_translations',
			array(
				'label'        => esc_html__( 'Combine Translations', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'With Polylang or WPML active, count donations from every translation of this product.', 'styleraise' ),
			)
		);

		$this->end_controls_section();

		$this->add_text_style_section(
			'counter',
			esc_html__( 'Text', 'styleraise' ),
			'{{WRAPPER}} .wcdp-el-counter'
		);

		$this->register_number_style();
	}

	/**
	 * Style → Number.
	 */
	private function register_number_style(): void {
		$this->open_style_section(
			'section_number_style',
			esc_html__( 'Number', 'styleraise' )
		);

		$selector = '{{WRAPPER}} .wcdp-el-counter .wcdp-el-value--count';

		$this->add_typography( 'number_typography', $selector );

		$this->add_color(
			'number_color',
			$selector,
			array( 'global_default' => Global_Colors::COLOR_PRIMARY )
		);

		$this->add_control(
			'number_badge_heading',
			array(
				'label'       => esc_html__( 'Badge', 'styleraise' ),
				'type'        => Controls_Manager::HEADING,
				'separator'   => 'before',
				'description' => esc_html__( 'Give the number a background to turn it into a badge.', 'styleraise' ),
			)
		);

		$this->add_box_style( 'number', $selector );

		$this->add_slider(
			'number_min_width',
			esc_html__( 'Minimum Width', 'styleraise' ),
			$selector,
			'min-width: {{SIZE}}{{UNIT}}; display: inline-block; text-align: center;',
			array( 'size_units' => array( 'px', 'em', 'ch' ) )
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$product_id = Product_Resolver::resolve( $settings );

		if ( ! $product_id || ! Product_Resolver::is_donable( $product_id ) ) {
			$this->render_placeholder( $this->get_unresolved_message() );

			return;
		}

		$campaign = Campaign::get( $product_id, $this->build_campaign_args( $product_id, $settings ) );

		if ( ! $campaign ) {
			$this->render_placeholder( $this->get_unresolved_message() );

			return;
		}

		$cheat = isset( $settings['cheat'] ) && is_numeric( $settings['cheat'] ) ? (int) $settings['cheat'] : 0;
		$count = max( 0, $campaign->get_order_count() + $cheat );

		if ( 0 === $count && 'yes' === ( $settings['hide_when_empty'] ?? '' ) ) {
			return;
		}

		$template = (string) ( $settings['label'] ?? '' );

		if ( 0 === $count && ! empty( $settings['fallback'] ) ) {
			$template = (string) $settings['fallback'];
		}

		$template = trim( $template );

		if ( '' === $template ) {
			return;
		}

		$number = sprintf(
			'<span class="wcdp-el-value wcdp-el-value--count" %1$s="count">%2$s</span>',
			esc_attr( Live_Target::VALUE_ATTRIBUTE ),
			esc_html( number_format_i18n( $count ) )
		);

		$output = esc_html( $template );
		$output = str_replace( array( '{ORDER_COUNT}', '{count}' ), $number, $output );

		$attributes = array( 'class' => 'wcdp-el-counter' );

		// The count is as cache-frozen as the amounts are, and it carries the
		// same social proof, so it refreshes through the same channel. The
		// cheat travels in the descriptor, otherwise a refresh would silently
		// drop the offline donations it accounts for.
		$fmt        = Value_Formatter::fmt_from_settings( $settings + array( 'cheat_count' => $cheat ) );
		$live       = Live_Target::attribute(
			$campaign,
			$fmt,
			$this->build_campaign_args( $product_id, $settings ),
			(string) ( $settings['live_updates'] ?? 'inherit' )
		);

		if ( '' !== $live ) {
			$attributes[ Live_Target::ATTRIBUTE ] = $live;

			Live_Target::mark_needed();
		}

		Location_Index::record( $campaign->get_product_id(), $campaign->get_addids() );

		$this->add_render_attribute( 'counter', $attributes );

		echo '<div ';
		$this->print_render_attribute_string( 'counter' );
		echo '>' . wp_kses( $output, Kses::markup() ) . '</div>';
	}

}
