<?php
/**
 * Donor leaderboard widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;
use WCDP_Elementor\Render\Shortcode_Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor wall, rendered by WCDP and styled entirely from the Style tab.
 *
 * This widget deliberately keeps WCDP's markup. Reproducing it would mean
 * duplicating the donation query, the anonymity handling and the donor-line
 * templates that live in WCDP's settings — all of which must stay in one
 * place. What the widget does replace is WCDP's inline `<style>`: it is
 * stripped, and a registered stylesheet plus per-instance Elementor rules take
 * over, so two leaderboards on one page can look different.
 */
class Leaderboard extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-leaderboard';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Leaderboard', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-bullet-list';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'leaderboard', 'donors', 'donor wall', 'supporters', 'recent donations', 'wcdp' );
	}

	/**
	 * Stylesheets.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base', 'wcdp-el-leaderboard' );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_list_style();
		$this->register_marker_style();
		$this->register_text_styles();
		$this->register_button_style();
	}

	/**
	 * Content → Leaderboard.
	 */
	private function register_content_controls(): void {
		$this->open_content_section( 'section_campaign', esc_html__( 'Leaderboard', 'styleraise' ) );

		$this->register_product_controls(
			array(
				'allow_all' => true,
				'default'   => 'all',
			)
		);

		// Legacy control names, unchanged from 1.0.x.
		$this->add_control(
			'style',
			array
			(
				'label'     => esc_html__( 'Layout', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '1',
				'separator' => 'before',
				'options'   => array(
					'1' => esc_html__( '1 — Timeline with dots', 'styleraise' ),
					'2' => esc_html__( '2 — Compact list with icon', 'styleraise' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => esc_html__( 'Number of Donations', 'styleraise' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 1000,
				'dynamic' => array( 'active' => true ),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => esc_html__( 'Order By', 'styleraise' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'  => esc_html__( 'Most recent', 'styleraise' ),
					'total' => esc_html__( 'Largest amount', 'styleraise' ),
				),
			)
		);

		$this->add_control(
			'split',
			array(
				'label'       => esc_html__( 'Show Initially', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => -1,
				'min'         => -1,
				'description' => esc_html__( 'Reveal the rest behind a "Show more" button. Use -1 to show everything at once.', 'styleraise' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => esc_html__( '"Show More" Label', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Show more', 'styleraise' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'conditions'  => array(
					'terms' => array(
						array(
							'name'     => 'split',
							'operator' => '>',
							'value'    => 0,
						),
					),
				),
			)
		);

		$this->add_control(
			'fallback',
			array(
				'label'       => esc_html__( 'Text With No Donations', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'combine_translations',
			array(
				'label'        => esc_html__( 'Combine Translations', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'product_source!' => 'all' ),
				'description'  => esc_html__( 'With Polylang or WPML active, include donations made through every translation of this product.', 'styleraise' ),
			)
		);

		$this->add_control(
			'template_notice',
			array(
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => '<div class="elementor-control-field-description">' . sprintf(
					/* translators: %s: link to the WCDP settings screen. */
					esc_html__( 'The donor line itself ("{firstname} donated {amount}") is a WCDP setting shared site-wide. %s', 'styleraise' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=wc-donation-platform' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit it in WCDP settings', 'styleraise' ) . '</a>'
				) . '</div>',
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → List & Rows.
	 */
	private function register_list_style(): void {
		$this->open_style_section( 'section_list_style', esc_html__( 'List & Rows', 'styleraise' ) );

		$wrapper = '{{WRAPPER}} .wcdp-el-leaderboard';
		$item    = $wrapper . ' .wcdp-leaderboard-li';

		$this->add_box_style( 'list', $wrapper );

		$this->add_control(
			'row_heading',
			array(
				'label'     => esc_html__( 'Rows', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_padding( 'row_padding', $item );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'row_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $item,
			)
		);

		$this->add_border_radius( 'row_radius', $item );

		$this->add_control(
			'row_divider',
			array(
				'label'        => esc_html__( 'Divider Between Rows', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'row_divider_color',
			array(
				'label'     => esc_html__( 'Divider Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#e0e0e0',
				'selectors' => array(
					$item . ':not(:last-child)' => 'border-bottom: var(--wcdp-el-lb-divider-width, 1px) solid {{VALUE}};',
				),
				'condition' => array( 'row_divider' => 'yes' ),
			)
		);

		$this->add_slider(
			'row_divider_width',
			esc_html__( 'Divider Width', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-divider-width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 20 ) ),
				'condition'  => array( 'row_divider' => 'yes' ),
			)
		);

		// Alternating row background is a common donor-wall request and needs
		// no markup change.
		$this->add_control(
			'row_stripe_color',
			array(
				'label'     => esc_html__( 'Alternate Row Background', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					$item . ':nth-child(even)' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Marker.
	 */
	private function register_marker_style(): void {
		$this->open_style_section( 'section_marker_style', esc_html__( 'Marker', 'styleraise' ) );

		$wrapper = '{{WRAPPER}} .wcdp-el-leaderboard';

		/* ── Timeline (layout 1) ── */

		$this->add_control(
			'marker_dot_color',
			array(
				'label'     => esc_html__( 'Dot Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => Global_Colors::COLOR_PRIMARY ),
				'selectors' => array( $wrapper => '--wcdp-el-lb-dot-color: {{VALUE}};' ),
				'condition' => array( 'style' => '1' ),
			)
		);

		$this->add_slider(
			'marker_dot_size',
			esc_html__( 'Dot Size', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-dot-size: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 40 ) ),
				'condition'  => array( 'style' => '1' ),
			)
		);

		$this->add_control(
			'marker_line_color',
			array(
				'label'     => esc_html__( 'Line Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $wrapper => '--wcdp-el-lb-line-color: {{VALUE}};' ),
				'condition' => array( 'style' => '1' ),
			)
		);

		$this->add_slider(
			'marker_line_width',
			esc_html__( 'Line Width', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-line-width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 20 ) ),
				'condition'  => array( 'style' => '1' ),
			)
		);

		$this->add_slider(
			'marker_indent',
			esc_html__( 'Text Indent', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-indent: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 120 ) ),
				'condition'  => array( 'style' => '1' ),
			)
		);

		$this->add_slider(
			'marker_line_offset',
			esc_html__( 'Line Offset', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-line-offset: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 120 ) ),
				'condition'  => array( 'style' => '1' ),
			)
		);

		/* ── Icon (layout 2) ── */

		$this->add_control(
			'marker_icon',
			array(
				'label'       => esc_html__( 'Icon', 'styleraise' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => array( 'image', 'svg' ),
				'selectors'   => array( $wrapper => '--wcdp-el-lb-icon: url({{URL}});' ),
				'condition'   => array( 'style' => '2' ),
				'description' => esc_html__( 'Leave empty to keep the WCDP donation icon.', 'styleraise' ),
			)
		);

		$this->add_slider(
			'marker_icon_width',
			esc_html__( 'Icon Width', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-icon-width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'em' => array( 'max' => 6 ) ),
				'condition'  => array( 'style' => '2' ),
			)
		);

		$this->add_slider(
			'marker_icon_height',
			esc_html__( 'Icon Height', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-icon-height: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'em' => array( 'max' => 6 ) ),
				'condition'  => array( 'style' => '2' ),
			)
		);

		$this->add_slider(
			'marker_icon_gap',
			esc_html__( 'Icon Gap', 'styleraise' ),
			$wrapper,
			'--wcdp-el-lb-icon-gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 60 ) ),
				'condition'  => array( 'style' => '2' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style sections for the donor line and its individual values.
	 */
	private function register_text_styles(): void {
		$wrapper = '{{WRAPPER}} .wcdp-el-leaderboard';

		$this->add_text_style_section(
			'lb_title',
			esc_html__( 'Donor Line', 'styleraise' ),
			$wrapper . ' .wcdp-leaderboard-title',
			array(
				'global_typography' => Global_Typography::TYPOGRAPHY_PRIMARY,
				'extra'             => function () use ( $wrapper ) {
					$this->add_donor_part_controls( 'lb_title', $wrapper );
				},
			)
		);

		$this->add_text_style_section(
			'lb_subtitle',
			esc_html__( 'Secondary Line', 'styleraise' ),
			$wrapper . ' .wcdp-leaderboard-subtitle',
			array(
				'extra' => function () use ( $wrapper ) {
					$this->add_donor_part_controls( 'lb_subtitle', $wrapper );
				},
			)
		);

		$this->add_text_style_section(
			'lb_fallback',
			esc_html__( 'Empty State', 'styleraise' ),
			$wrapper . ' .wcdp-el-leaderboard__fallback',
			array( 'order' => false )
		);
	}

	/**
	 * Overrides for the individual placeholders inside a donor line.
	 *
	 * WCDP wraps every placeholder in its own span, so the amount can be
	 * styled apart from the name without touching the template.
	 *
	 * @param string $prefix  Control name prefix.
	 * @param string $wrapper Widget wrapper selector.
	 */
	private function add_donor_part_controls( string $prefix, string $wrapper ): void {
		$parts = array(
			'amount'          => esc_html__( 'Amount', 'styleraise' ),
			'firstname'       => esc_html__( 'First Name', 'styleraise' ),
			'lastname'        => esc_html__( 'Last Name', 'styleraise' ),
			'company_or_name' => esc_html__( 'Company or Name', 'styleraise' ),
			'timediff'        => esc_html__( 'Time Ago', 'styleraise' ),
			'datetime'        => esc_html__( 'Date & Time', 'styleraise' ),
			'city'            => esc_html__( 'City', 'styleraise' ),
			'comment'         => esc_html__( 'Comment', 'styleraise' ),
			'anonymous'       => esc_html__( 'Anonymous Label', 'styleraise' ),
			'product'         => esc_html__( 'Campaign Name', 'styleraise' ),
		);

		$this->add_control(
			$prefix . '_parts_heading',
			array(
				'label'       => esc_html__( 'Individual Values', 'styleraise' ),
				'type'        => Controls_Manager::HEADING,
				'separator'   => 'before',
				'description' => esc_html__( 'Only the placeholders used by the WCDP donor-line template have any effect.', 'styleraise' ),
			)
		);

		foreach ( $parts as $part => $label ) {
			$selector = $wrapper . ' .wcdp-leaderboard-' . $part;

			$this->add_typography( $prefix . '_part_' . $part . '_typography', $selector, array( 'label' => $label ) );

			$this->add_color(
				$prefix . '_part_' . $part . '_color',
				$selector,
				array(
					/* translators: %s: name of the value, e.g. "Amount". */
					'label'          => sprintf( esc_html__( '%s Color', 'styleraise' ), $label ),
					'global_default' => null,
				)
			);
		}
	}

	/**
	 * Style → Show More Button.
	 */
	private function register_button_style(): void {
		$this->open_style_section(
			'section_button_style',
			esc_html__( 'Show More Button', 'styleraise' ),
			array(
				'conditions' => array(
					'terms' => array(
						array(
							'name'     => 'split',
							'operator' => '>',
							'value'    => 0,
						),
					),
				),
			)
		);

		$button = '{{WRAPPER}} .wcdp-el-leaderboard .wcdp-button';

		$this->add_responsive_control(
			'button_align',
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
					'stretch'    => array(
						'title' => esc_html__( 'Full Width', 'styleraise' ),
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'default'   => 'flex-start',
				'selectors' => array( $button => 'align-self: {{VALUE}};' ),
			)
		);

		$this->add_typography( 'button_typography', $button );

		$this->start_controls_tabs( 'button_tabs' );

		$this->start_controls_tab( 'button_tab_normal', array( 'label' => esc_html__( 'Normal', 'styleraise' ) ) );

		$this->add_color( 'button_color', $button, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $button,
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'button_tab_hover', array( 'label' => esc_html__( 'Hover', 'styleraise' ) ) );

		$this->add_color(
			'button_color_hover',
			$button . ':hover, ' . $button . ':focus-visible',
			array( 'global_default' => null )
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_background_hover',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $button . ':hover, ' . $button . ':focus-visible',
			)
		);

		$this->add_control(
			'button_transition',
			array(
				'label'      => esc_html__( 'Transition Duration', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 's' ),
				'range'      => array(
					's' => array(
						'min'  => 0,
						'max'  => 2,
						'step' => 0.05,
					),
				),
				'selectors'  => array( $button => 'transition: all {{SIZE}}s ease;' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		// Border, radius, shadow and spacing are added individually rather
		// than through add_box_style(), which would register a second
		// `button_background` group control and collide with the one in the
		// Normal tab above.
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'button_border',
				'selector'  => $button,
				'separator' => 'before',
			)
		);

		$this->add_border_radius( 'button_radius', $button );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'button_box_shadow',
				'selector' => $button,
			)
		);

		$this->add_padding( 'button_padding', $button );

		$this->add_margin( 'button_margin', $button );

		$this->end_controls_section();
	}

	/**
	 * Render the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$atts = array(
			'style'   => (string) ( $settings['style'] ?? '1' ),
			'orderby' => 'total' === ( $settings['orderby'] ?? 'date' ) ? 'total' : 'date',
		);

		$source = (string) ( $settings['product_source'] ?? 'all' );

		if ( 'all' === $source ) {
			$atts['id'] = '-1';
		} else {
			$product_id = Product_Resolver::resolve( $settings );

			if ( ! $product_id || ! Product_Resolver::is_donable( $product_id ) ) {
				$this->render_placeholder( $this->get_unresolved_message() );

				return;
			}

			$ids = array( $product_id );

			if ( 'yes' === ( $settings['combine_translations'] ?? 'yes' ) ) {
				foreach ( Product_Resolver::get_translation_ids( $product_id ) as $translation_id ) {
					if ( Product_Resolver::is_donable( $translation_id ) ) {
						$ids[] = $translation_id;
					}
				}
			}

			$atts['id'] = implode( ',', array_unique( $ids ) );
		}

		if ( isset( $settings['limit'] ) && is_numeric( $settings['limit'] ) ) {
			$atts['limit'] = max( 1, min( 1000, (int) $settings['limit'] ) );
		}

		if ( isset( $settings['split'] ) && is_numeric( $settings['split'] ) ) {
			$atts['split'] = (int) $settings['split'];
		}

		if ( ! empty( $settings['button_text'] ) ) {
			$atts['button'] = (string) $settings['button_text'];
		}

		if ( ! empty( $settings['fallback'] ) ) {
			$atts['fallback'] = (string) $settings['fallback'];
		}

		$html = Shortcode_Runner::run( 'wcdp_leaderboard', $atts );

		if ( '' === trim( $html ) ) {
			$this->render_placeholder( esc_html__( 'WCDP returned no output for this leaderboard.', 'styleraise' ) );

			return;
		}

		$has_list = false !== strpos( $html, 'wcdp-leaderboard-li' );

		// WCDP scopes this CSS to a generated id, but still prints it inline on
		// every render and redeclares `:root` variables page-wide. The
		// registered stylesheet covers the same rules per instance.
		$html = Shortcode_Runner::strip_inline_styles( $html );

		if ( ! $has_list ) {
			// The empty state is returned as a bare escaped string; wrap it so
			// it has something to be styled through.
			$html = '<div class="wcdp-el-leaderboard__fallback">' . $html . '</div>';
		}

		$this->add_render_attribute(
			'wrapper',
			array(
				'class' => 'wcdp-el-leaderboard',
				'style' => $this->get_leaderboard_variables(),
			)
		);

		echo '<div ';
		$this->print_render_attribute_string( 'wrapper' );
		echo '>';
		echo wp_kses( $html, Kses::form() );
		echo '</div>';
	}

	/**
	 * Inline custom properties seeding the palette and the default icon.
	 *
	 * The icon URL cannot live in a static stylesheet because it points into
	 * the WCDP plugin directory, which is only known at runtime.
	 *
	 * @return string
	 */
	private function get_leaderboard_variables(): string {
		$declarations = array();

		$accent = sanitize_hex_color( (string) get_option( 'wcdp_main_color', '#30bf76' ) );

		if ( $accent ) {
			$declarations[] = '--wcdp-main-2:' . $accent;
		}

		if ( defined( 'WCDP_DIR_URL' ) ) {
			$declarations[] = '--wcdp-el-lb-icon:url(' . esc_url_raw( WCDP_DIR_URL . 'assets/svg/donation.svg' ) . ')';
		}

		return implode( ';', $declarations );
	}
}
