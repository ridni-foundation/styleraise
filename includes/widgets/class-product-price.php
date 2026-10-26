<?php
/**
 * Campaign price widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Price of the campaign product, with the sale and regular prices separately
 * styleable — WooCommerce renders them as `<del>` and `<ins>`, so they need
 * their own controls rather than inheriting one colour.
 */
class Product_Price extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-product-price';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Campaign Price', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-price-list';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'price', 'amount', 'campaign', 'product', 'wcdp' );
	}

	/**
	 * Stylesheets.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base', 'wcdp-el-product' );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->open_content_section( 'section_content', esc_html__( 'Campaign Price', 'styleraise' ) );

		$this->register_product_controls( array( 'default' => 'current' ) );

		$this->add_control(
			'html_tag',
			array(
				'label'     => esc_html__( 'HTML Tag', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'div',
				'separator' => 'before',
				'options'   => array(
					'div'  => 'div',
					'span' => 'span',
					'p'    => 'p',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
				),
			)
		);

		$this->end_controls_section();

		$selector = '{{WRAPPER}} .wcdp-el-product-price';

		$this->add_text_style_section(
			'price',
			esc_html__( 'Price', 'styleraise' ),
			$selector,
			array(
				'currency'     => true,
				'global_color' => Global_Colors::COLOR_PRIMARY,
				'extra'        => function () use ( $selector ) {
					$this->add_control(
						'price_sale_heading',
						array(
							'label'       => esc_html__( 'Sale Prices', 'styleraise' ),
							'type'        => Controls_Manager::HEADING,
							'separator'   => 'before',
							'description' => esc_html__( 'WooCommerce shows a struck-through original price next to the current one.', 'styleraise' ),
						)
					);

					$this->add_typography( 'price_del_typography', $selector . ' del', array( 'label' => esc_html__( 'Original Price', 'styleraise' ) ) );

					$this->add_color(
						'price_del_color',
						$selector . ' del',
						array(
							'label'          => esc_html__( 'Original Price Color', 'styleraise' ),
							'global_default' => null,
						)
					);

					$this->add_typography( 'price_ins_typography', $selector . ' ins', array( 'label' => esc_html__( 'Current Price', 'styleraise' ) ) );

					$this->add_color(
						'price_ins_color',
						$selector . ' ins',
						array(
							'label'          => esc_html__( 'Current Price Color', 'styleraise' ),
							'global_default' => null,
						)
					);

					$this->add_control(
						'price_hide_del',
						array(
							'label'        => esc_html__( 'Hide Original Price', 'styleraise' ),
							'type'         => Controls_Manager::SWITCHER,
							'return_value' => 'yes',
							'selectors'    => array( $selector . ' del' => 'display: none;' ),
						)
					);

					$this->add_typography( 'price_suffix_typography', $selector . ' .woocommerce-price-suffix', array( 'label' => esc_html__( 'Price Suffix', 'styleraise' ) ) );
				},
			)
		);
	}

	/**
	 * Render the widget.
	 */
	protected function render(): void {
		$settings   = $this->get_settings_for_display();
		$product_id = Product_Resolver::resolve( $settings );
		$product    = Product_Resolver::get_product( $product_id );

		if ( ! $product ) {
			$this->render_placeholder( $this->get_unresolved_message() );

			return;
		}

		$price_html = $product->get_price_html();

		if ( '' === trim( (string) $price_html ) ) {
			$this->render_placeholder( esc_html__( 'This product has no price to display.', 'styleraise' ) );

			return;
		}

		$tag = tag_escape( (string) ( $settings['html_tag'] ?? 'div' ) );

		echo wp_kses(
			sprintf( '<%1$s class="wcdp-el-product-price">%2$s</%1$s>', $tag, $price_html ),
			Kses::markup()
		);
	}
}
