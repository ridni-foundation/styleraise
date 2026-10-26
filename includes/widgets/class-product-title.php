<?php
/**
 * Campaign title widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Title of the campaign product.
 *
 * Exists because the campaign is often not the queried post — on a CPT page or
 * a custom archive card the product is reached through a meta field or a
 * dynamic tag, which the core WooCommerce title widget cannot do.
 */
class Product_Title extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-product-title';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Campaign Title', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-t-letter';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'title', 'name', 'campaign', 'product', 'heading', 'wcdp' );
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
		$this->open_content_section( 'section_content', esc_html__( 'Campaign Title', 'styleraise' ) );

		$this->register_product_controls( array( 'default' => 'current' ) );

		$this->add_control(
			'html_tag',
			array(
				'label'     => esc_html__( 'HTML Tag', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'h2',
				'separator' => 'before',
				'options'   => array(
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
					'div'  => 'div',
					'span' => 'span',
					'p'    => 'p',
				),
			)
		);

		$this->add_control(
			'link_to_product',
			array(
				'label'        => esc_html__( 'Link to Campaign', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'max_lines',
			array(
				'label'       => esc_html__( 'Limit to Lines', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 10,
				'default'     => '',
				'placeholder' => esc_html__( 'No limit', 'styleraise' ),
				'selectors'   => array(
					'{{WRAPPER}} .wcdp-el-product-title' => 'display: -webkit-box; -webkit-line-clamp: {{VALUE}}; -webkit-box-orient: vertical; overflow: hidden;',
				),
				'description' => esc_html__( 'Truncates long campaign names so archive cards stay the same height.', 'styleraise' ),
			)
		);

		$this->end_controls_section();

		$selector = '{{WRAPPER}} .wcdp-el-product-title';

		$this->add_text_style_section(
			'title',
			esc_html__( 'Title', 'styleraise' ),
			$selector,
			array(
				'global_typography' => Global_Typography::TYPOGRAPHY_PRIMARY,
				'global_color'      => Global_Colors::COLOR_PRIMARY,
				'extra'             => function () use ( $selector ) {
					$this->add_color(
						'title_link_color',
						$selector . ' a',
						array(
							'label'          => esc_html__( 'Link Color', 'styleraise' ),
							'global_default' => null,
							'condition'      => array( 'link_to_product' => 'yes' ),
						)
					);

					$this->add_color(
						'title_link_hover_color',
						$selector . ' a:hover, ' . $selector . ' a:focus-visible',
						array(
							'label'          => esc_html__( 'Link Hover Color', 'styleraise' ),
							'global_default' => null,
							'condition'      => array( 'link_to_product' => 'yes' ),
						)
					);
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

		$tag     = tag_escape( (string) ( $settings['html_tag'] ?? 'h2' ) );
		$content = esc_html( $product->get_name() );

		if ( 'yes' === ( $settings['link_to_product'] ?? '' ) ) {
			$content = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $product->get_permalink() ),
				$content
			);
		}

		echo wp_kses(
			sprintf( '<%1$s class="wcdp-el-product-title">%2$s</%1$s>', $tag, $content ),
			Kses::markup()
		);
	}
}
