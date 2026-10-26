<?php
/**
 * Campaign image widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Css_Filter;
use Elementor\Group_Control_Image_Size;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Featured image of the campaign product.
 */
class Product_Image extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-product-image';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Campaign Image', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-image';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'image', 'photo', 'thumbnail', 'campaign', 'product', 'wcdp' );
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
		$this->open_content_section( 'section_content', esc_html__( 'Campaign Image', 'styleraise' ) );

		$this->register_product_controls( array( 'default' => 'current' ) );

		// The group control offers every registered size plus a custom
		// width/height pair, which the plain SELECT in 1.0.x did not.
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'      => 'image',
				'default'   => 'woocommerce_single',
				'separator' => 'before',
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
			'lazy_load',
			array(
				'label'        => esc_html__( 'Lazy Load', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Turn off for an image above the fold — lazy loading a hero image delays Largest Contentful Paint.', 'styleraise' ),
			)
		);

		$this->end_controls_section();

		$this->register_image_style();
	}

	/**
	 * Style → Image.
	 */
	private function register_image_style(): void {
		$this->open_style_section( 'section_image_style', esc_html__( 'Image', 'styleraise' ) );

		$wrapper = '{{WRAPPER}} .wcdp-el-product-image';
		$image   = $wrapper . ' img';

		$this->add_responsive_control(
			'align',
			array(
				'label'     => esc_html__( 'Alignment', 'styleraise' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => esc_html__( 'Left', 'styleraise' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => esc_html__( 'Center', 'styleraise' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => esc_html__( 'Right', 'styleraise' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array( $wrapper => 'justify-content: {{VALUE}};' ),
			)
		);

		$this->add_slider(
			'image_width',
			esc_html__( 'Width', 'styleraise' ),
			$image,
			'width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( '%', 'px', 'vw' ),
				'range'      => array( 'px' => array( 'max' => 1200 ) ),
			)
		);

		$this->add_slider(
			'image_max_width',
			esc_html__( 'Maximum Width', 'styleraise' ),
			$image,
			'max-width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( '%', 'px', 'vw' ),
				'range'      => array( 'px' => array( 'max' => 1200 ) ),
			)
		);

		$this->add_responsive_control(
			'image_ratio',
			array(
				'label'       => esc_html__( 'Aspect Ratio', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''      => esc_html__( 'Original', 'styleraise' ),
					'1/1'   => '1:1',
					'4/3'   => '4:3',
					'3/2'   => '3:2',
					'16/9'  => '16:9',
					'21/9'  => '21:9',
					'3/4'   => '3:4',
					'2/3'   => '2:3',
					'9/16'  => '9:16',
				),
				'selectors'   => array( $image => 'aspect-ratio: {{VALUE}}; height: auto;' ),
				'description' => esc_html__( 'Crops every campaign image to the same shape — the reliable way to keep archive cards aligned.', 'styleraise' ),
			)
		);

		$this->add_control(
			'image_object_fit',
			array(
				'label'     => esc_html__( 'Fit', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'cover',
				'options'   => array(
					'cover'      => esc_html__( 'Cover', 'styleraise' ),
					'contain'    => esc_html__( 'Contain', 'styleraise' ),
					'fill'       => esc_html__( 'Fill', 'styleraise' ),
					'scale-down' => esc_html__( 'Scale Down', 'styleraise' ),
				),
				'selectors' => array( $image => 'object-fit: {{VALUE}};' ),
				'condition' => array( 'image_ratio!' => '' ),
			)
		);

		$this->add_control(
			'image_object_position',
			array(
				'label'     => esc_html__( 'Focal Point', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'center center',
				'options'   => array(
					'center center' => esc_html__( 'Center', 'styleraise' ),
					'center top'    => esc_html__( 'Top', 'styleraise' ),
					'center bottom' => esc_html__( 'Bottom', 'styleraise' ),
					'left center'   => esc_html__( 'Left', 'styleraise' ),
					'right center'  => esc_html__( 'Right', 'styleraise' ),
				),
				'selectors' => array( $image => 'object-position: {{VALUE}};' ),
				'condition' => array( 'image_ratio!' => '' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'image_border',
				'selector'  => $image,
				'separator' => 'before',
			)
		);

		$this->add_border_radius( 'image_radius', $image );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'image_box_shadow',
				'selector' => $image,
			)
		);

		$this->add_margin( 'image_margin', $wrapper );

		$this->start_controls_tabs( 'image_tabs' );

		$this->start_controls_tab( 'image_tab_normal', array( 'label' => esc_html__( 'Normal', 'styleraise' ) ) );

		$this->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_filters',
				'selector' => $image,
			)
		);

		$this->add_control(
			'image_opacity',
			array(
				'label'      => esc_html__( 'Opacity', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.01,
					),
				),
				'selectors'  => array( $image => 'opacity: {{SIZE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'image_tab_hover', array( 'label' => esc_html__( 'Hover', 'styleraise' ) ) );

		$this->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_filters_hover',
				'selector' => $wrapper . ':hover img',
			)
		);

		$this->add_control(
			'image_opacity_hover',
			array(
				'label'     => esc_html__( 'Opacity', 'styleraise' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.01,
					),
				),
				'selectors' => array( $wrapper . ':hover img' => 'opacity: {{SIZE}};' ),
			)
		);

		$this->add_control(
			'image_zoom_hover',
			array(
				'label'      => esc_html__( 'Zoom', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => array(
					'px' => array(
						'min'  => 1,
						'max'  => 1.5,
						'step' => 0.01,
					),
				),
				'selectors'  => array(
					$wrapper           => 'overflow: hidden;',
					$wrapper . ':hover img' => 'transform: scale({{SIZE}});',
				),
			)
		);

		$this->add_control(
			'image_transition',
			array(
				'label'      => esc_html__( 'Transition Duration', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 's' ),
				'range'      => array(
					's' => array(
						'min'  => 0,
						'max'  => 3,
						'step' => 0.05,
					),
				),
				'default'    => array(
					'unit' => 's',
					'size' => 0.3,
				),
				'selectors'  => array( $image => 'transition: transform {{SIZE}}s ease, opacity {{SIZE}}s ease, filter {{SIZE}}s ease;' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
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

		$image_id = $product->get_image_id();

		// A variation without its own image falls back to the parent's, which
		// is what a visitor expects to see on a campaign card.
		if ( ! $image_id && $product->is_type( array( 'variation', 'subscription_variation' ) ) ) {
			$parent = Product_Resolver::get_product( (int) $product->get_parent_id() );

			if ( $parent ) {
				$image_id = $parent->get_image_id();
			}
		}

		$size = $this->resolve_image_size( $settings );

		if ( ! $image_id ) {
			if ( function_exists( 'wc_placeholder_img' ) ) {
				printf(
					'<div class="wcdp-el-product-image wcdp-el-product-image--placeholder">%s</div>',
					wp_kses( wc_placeholder_img( $size ), Kses::markup() )
				);
			}

			return;
		}

		$attributes = array(
			'class'   => 'wcdp-el-product-img',
			'alt'     => $product->get_name(),
			'loading' => 'yes' === ( $settings['lazy_load'] ?? 'yes' ) ? 'lazy' : 'eager',
		);

		if ( 'eager' === $attributes['loading'] ) {
			$attributes['fetchpriority'] = 'high';
		}

		// Rendered directly rather than through Elementor's image-size helper,
		// which expects the attachment to come from a Media control. Going
		// through WordPress keeps `srcset`, the alt text and the loading hints.
		$image_html = wp_get_attachment_image( $image_id, $size, false, $attributes );

		if ( 'yes' === ( $settings['link_to_product'] ?? '' ) ) {
			$image_html = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $product->get_permalink() ),
				$image_html
			);
		}

		printf(
			'<div class="wcdp-el-product-image">%s</div>',
			wp_kses( $image_html, Kses::markup() )
		);
	}

	/**
	 * Turn the image-size group control's values into a WordPress size.
	 *
	 * With "Custom" chosen, WordPress serves the nearest registered size to the
	 * requested dimensions rather than generating a new crop.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @return string|int[]
	 */
	private function resolve_image_size( array $settings ) {
		$size = (string) ( $settings['image_size'] ?? 'woocommerce_single' );

		if ( 'custom' !== $size ) {
			return '' !== $size ? $size : 'woocommerce_single';
		}

		$dimension = $settings['image_custom_dimension'] ?? array();

		$width  = isset( $dimension['width'] ) ? absint( $dimension['width'] ) : 0;
		$height = isset( $dimension['height'] ) ? absint( $dimension['height'] ) : 0;

		if ( ! $width && ! $height ) {
			return 'woocommerce_single';
		}

		return array( $width, $height );
	}
}
