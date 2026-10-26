<?php
/**
 * Reusable Elementor style-control builders.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Controls;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Style-control builders shared by every widget in the addon.
 *
 * The goal is that adding a styleable element to a widget costs one call, not
 * forty lines — which is what keeps the Style tab exhaustive instead of
 * "whatever the developer had patience for". Every dimensional control is
 * responsive, colours and typography subscribe to Elementor's Global
 * Colors/Fonts, and nothing emits `!important` so V4's cascade stays intact.
 */
trait Style_Controls {

	/**
	 * Open a Style-tab section.
	 *
	 * @param string               $id    Section ID.
	 * @param string               $label Section label.
	 * @param array<string, mixed> $args  Extra section arguments (`condition`, `conditions`).
	 */
	protected function open_style_section( string $id, string $label, array $args = array() ): void {
		$this->start_controls_section(
			$id,
			array_merge(
				array(
					'label' => $label,
					'tab'   => Controls_Manager::TAB_STYLE,
				),
				$args
			)
		);
	}

	/**
	 * Open a Content-tab section.
	 *
	 * @param string               $id    Section ID.
	 * @param string               $label Section label.
	 * @param array<string, mixed> $args  Extra section arguments.
	 */
	protected function open_content_section( string $id, string $label, array $args = array() ): void {
		$this->start_controls_section(
			$id,
			array_merge(
				array(
					'label' => $label,
					'tab'   => Controls_Manager::TAB_CONTENT,
				),
				$args
			)
		);
	}

	/* ─────────────────────────── Atoms ─────────────────────────── */

	/**
	 * Typography group control.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments; `global_default` picks the
	 *                                       Global Fonts entry the control follows.
	 */
	protected function add_typography( string $name, string $selector, array $args = array() ): void {
		$global_default = $args['global_default'] ?? Global_Typography::TYPOGRAPHY_TEXT;
		unset( $args['global_default'] );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array_merge(
				array(
					'name'     => $name,
					'selector' => $selector,
					'global'   => array( 'default' => $global_default ),
				),
				$args
			)
		);
	}

	/**
	 * Colour control writing a single CSS property.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     `label`, `property`, `global_default`, `condition`, `default`.
	 */
	protected function add_color( string $name, string $selector, array $args = array() ): void {
		$property       = $args['property'] ?? 'color';
		$global_default = array_key_exists( 'global_default', $args ) ? $args['global_default'] : Global_Colors::COLOR_TEXT;

		unset( $args['property'], $args['global_default'] );

		$control = array(
			'label'     => esc_html__( 'Color', 'styleraise' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => array( $selector => $property . ': {{VALUE}};' ),
		);

		if ( null !== $global_default ) {
			$control['global'] = array( 'default' => $global_default );
		}

		$this->add_control( $name, array_merge( $control, $args ) );
	}

	/**
	 * Responsive text alignment.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_text_align( string $name, string $selector, array $args = array() ): void {
		$this->add_responsive_control(
			$name,
			array_merge(
				array(
					'label'     => esc_html__( 'Alignment', 'styleraise' ),
					'type'      => Controls_Manager::CHOOSE,
					'options'   => array(
						'left'    => array(
							'title' => esc_html__( 'Left', 'styleraise' ),
							'icon'  => 'eicon-text-align-left',
						),
						'center'  => array(
							'title' => esc_html__( 'Center', 'styleraise' ),
							'icon'  => 'eicon-text-align-center',
						),
						'right'   => array(
							'title' => esc_html__( 'Right', 'styleraise' ),
							'icon'  => 'eicon-text-align-right',
						),
						'justify' => array(
							'title' => esc_html__( 'Justified', 'styleraise' ),
							'icon'  => 'eicon-text-align-justify',
						),
					),
					'selectors' => array( $selector => 'text-align: {{VALUE}};' ),
				),
				$args
			)
		);
	}

	/**
	 * Responsive flex alignment along the main axis.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_flex_align( string $name, string $selector, array $args = array() ): void {
		$this->add_responsive_control(
			$name,
			array_merge(
				array(
					'label'       => esc_html__( 'Alignment', 'styleraise' ),
					'type'        => Controls_Manager::CHOOSE,
					// Six choices do not fit beside a label at the panel's minimum
					// width, so the label takes its own line and the row gets the
					// full width — the same thing Elementor's own container does.
					'label_block' => true,
					// The same six values an Elementor container offers, with the
					// same icons, so the control reads as familiar rather than
					// as a reduced imitation.
					'options'   => array(
						'flex-start'    => array(
							'title' => esc_html__( 'Start', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-start-h',
						),
						'center'        => array(
							'title' => esc_html__( 'Center', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-center-h',
						),
						'flex-end'      => array(
							'title' => esc_html__( 'End', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-end-h',
						),
						'space-between' => array(
							'title' => esc_html__( 'Space Between', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-space-between-h',
						),
						'space-around'  => array(
							'title' => esc_html__( 'Space Around', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-space-around-h',
						),
						'space-evenly'  => array(
							'title' => esc_html__( 'Space Evenly', 'styleraise' ),
							'icon'  => 'eicon-flex eicon-justify-space-evenly-h',
						),
					),
					'selectors'   => array( $selector => 'justify-content: {{VALUE}};' ),
				),
				$args
			)
		);
	}

	/**
	 * Responsive slider control.
	 *
	 * @param string               $name     Control name.
	 * @param string               $label    Control label.
	 * @param string               $selector CSS selector.
	 * @param string               $template CSS template, e.g. `height: {{SIZE}}{{UNIT}};`.
	 * @param array<string, mixed> $args     Extra arguments (`size_units`, `range`, `default`).
	 */
	protected function add_slider( string $name, string $label, string $selector, string $template, array $args = array() ): void {
		$this->add_responsive_control(
			$name,
			array_merge(
				array(
					'label'      => $label,
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px', 'em', 'rem', '%' ),
					'range'      => array(
						'px'  => array(
							'min' => 0,
							'max' => 200,
						),
						'em'  => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 0.1,
						),
						'rem' => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 0.1,
						),
						'%'   => array(
							'min' => 0,
							'max' => 100,
						),
					),
					'selectors'  => array( $selector => $template ),
				),
				$args
			)
		);
	}

	/**
	 * Responsive four-side dimensions control.
	 *
	 * @param string               $name     Control name.
	 * @param string               $label    Control label.
	 * @param string               $selector CSS selector.
	 * @param string               $property CSS property, e.g. `padding` or `margin`.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_dimensions( string $name, string $label, string $selector, string $property, array $args = array() ): void {
		$this->add_responsive_control(
			$name,
			array_merge(
				array(
					'label'      => $label,
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'em', 'rem', '%' ),
					'selectors'  => array(
						$selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					),
				),
				$args
			)
		);
	}

	/**
	 * Padding shorthand.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_padding( string $name, string $selector, array $args = array() ): void {
		$this->add_dimensions( $name, esc_html__( 'Padding', 'styleraise' ), $selector, 'padding', $args );
	}

	/**
	 * Margin shorthand.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_margin( string $name, string $selector, array $args = array() ): void {
		$this->add_dimensions( $name, esc_html__( 'Margin', 'styleraise' ), $selector, 'margin', $args );
	}

	/**
	 * Border radius shorthand.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_border_radius( string $name, string $selector, array $args = array() ): void {
		$this->add_dimensions( $name, esc_html__( 'Border Radius', 'styleraise' ), $selector, 'border-radius', $args );
	}

	/**
	 * Flexbox `order`, so elements can be rearranged without touching markup.
	 *
	 * @param string               $name     Control name.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     Extra arguments.
	 */
	protected function add_order( string $name, string $selector, array $args = array() ): void {
		$this->add_responsive_control(
			$name,
			array_merge(
				array(
					'label'       => esc_html__( 'Order', 'styleraise' ),
					'type'        => Controls_Manager::NUMBER,
					'min'         => -20,
					'max'         => 20,
					'step'        => 1,
					'description' => esc_html__( 'Lower values appear first. Leave empty to keep the natural order.', 'styleraise' ),
					'selectors'   => array( $selector => 'order: {{VALUE}};' ),
				),
				$args
			)
		);
	}

	/* ─────────────────────────── Composites ─────────────────────────── */

	/**
	 * Background, border, radius, shadow and padding for a box-like element.
	 *
	 * @param string               $prefix   Control name prefix.
	 * @param string               $selector CSS selector.
	 * @param array<string, mixed> $args     `condition`/`conditions` applied to every control,
	 *                                       `padding` (bool) to include padding.
	 */
	protected function add_box_style( string $prefix, string $selector, array $args = array() ): void {
		$shared      = $this->extract_conditions( $args );
		$has_padding = $args['padding'] ?? true;

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array_merge(
				array(
					'name'     => $prefix . '_background',
					'types'    => array( 'classic', 'gradient' ),
					'selector' => $selector,
				),
				$shared
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array_merge(
				array(
					'name'     => $prefix . '_border',
					'selector' => $selector,
				),
				$shared
			)
		);

		$this->add_border_radius( $prefix . '_radius', $selector, $shared );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array_merge(
				array(
					'name'     => $prefix . '_box_shadow',
					'selector' => $selector,
				),
				$shared
			)
		);

		if ( $has_padding ) {
			$this->add_padding( $prefix . '_padding', $selector, $shared );
		}
	}

	/**
	 * A complete Style section for one text element.
	 *
	 * Covers typography, colour, text shadow, alignment, spacing and flex
	 * order — the full set, once, so no widget ends up with a half-styleable
	 * label.
	 *
	 * @param string               $id       Section and control-name prefix.
	 * @param string               $label    Section label.
	 * @param string               $selector CSS selector for the element.
	 * @param array<string, mixed> $args     Section arguments plus:
	 *                                       `global_color`, `global_typography`,
	 *                                       `align` (bool), `order` (bool),
	 *                                       `spacing` (bool), `currency` (bool),
	 *                                       `extra` (callable receiving the shared conditions).
	 */
	protected function add_text_style_section( string $id, string $label, string $selector, array $args = array() ): void {
		$section_args = $this->extract_conditions( $args );

		$with_align    = $args['align'] ?? true;
		$with_order    = $args['order'] ?? false;
		$with_spacing  = $args['spacing'] ?? true;
		$with_currency = $args['currency'] ?? false;
		$extra         = $args['extra'] ?? null;

		$this->open_style_section( 'section_' . $id, $label, $section_args );

		$this->add_typography(
			$id . '_typography',
			$selector,
			isset( $args['global_typography'] ) ? array( 'global_default' => $args['global_typography'] ) : array()
		);

		$this->add_color(
			$id . '_color',
			$selector,
			array_key_exists( 'global_color', $args ) ? array( 'global_default' => $args['global_color'] ) : array()
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => $id . '_text_shadow',
				'selector' => $selector,
			)
		);

		if ( $with_currency ) {
			$this->add_currency_controls( $id, $selector );
		}

		if ( $with_align ) {
			$this->add_text_align( $id . '_align', $selector, array( 'separator' => 'before' ) );
		}

		if ( $with_spacing ) {
			$this->add_margin( $id . '_margin', $selector );
		}

		if ( $with_order ) {
			$this->add_order( $id . '_order', $selector );
		}

		if ( is_callable( $extra ) ) {
			$extra();
		}

		$this->end_controls_section();
	}

	/**
	 * Controls for the WooCommerce currency symbol inside a price element.
	 *
	 * The symbol lives in its own span in `wc_price()` output, so it can be
	 * resized, recoloured or hidden without reformatting the number — which is
	 * why prices are never flattened to plain strings anywhere in this addon.
	 *
	 * @param string $id       Control name prefix.
	 * @param string $selector Selector of the price element.
	 */
	protected function add_currency_controls( string $id, string $selector ): void {
		$symbol = $selector . ' .woocommerce-Price-currencySymbol';

		$this->add_control(
			$id . '_currency_heading',
			array(
				'label'     => esc_html__( 'Currency Symbol', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			$id . '_currency_hide',
			array(
				'label'        => esc_html__( 'Hide Symbol', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( $symbol => 'display: none;' ),
			)
		);

		$this->add_color(
			$id . '_currency_color',
			$symbol,
			array(
				'label'         => esc_html__( 'Symbol Color', 'styleraise' ),
				'global_default' => null,
				'condition'     => array( $id . '_currency_hide!' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			$id . '_currency_size',
			array(
				'label'      => esc_html__( 'Symbol Size', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%', 'px', 'em' ),
				'range'      => array(
					'%'  => array(
						'min' => 20,
						'max' => 200,
					),
					'px' => array(
						'min' => 5,
						'max' => 120,
					),
					'em' => array(
						'min'  => 0.2,
						'max'  => 4,
						'step' => 0.05,
					),
				),
				'selectors'  => array( $symbol => 'font-size: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( $id . '_currency_hide!' => 'yes' ),
			)
		);
	}

	/* ─────────────────────────── Helpers ─────────────────────────── */

	/**
	 * Pull the condition keys out of a mixed argument array.
	 *
	 * Lets composite builders forward display conditions to every control they
	 * create without also forwarding their own configuration flags.
	 *
	 * @param array<string, mixed> $args Argument array.
	 * @return array<string, mixed> Only `condition` and `conditions`, when present.
	 */
	protected function extract_conditions( array $args ): array {
		$conditions = array();

		if ( isset( $args['condition'] ) ) {
			$conditions['condition'] = $args['condition'];
		}

		if ( isset( $args['conditions'] ) ) {
			$conditions['conditions'] = $args['conditions'];
		}

		return $conditions;
	}

	/**
	 * Build a nested `conditions` array that shows a control when either a
	 * preset includes an element or the element's own switcher is on.
	 *
	 * Elementor evaluates conditions declaratively, so "the selected preset
	 * contains this element" has to be expressed as a static list of presets
	 * rather than computed at runtime. This assembles that expression.
	 *
	 * Shared terms are folded into each branch rather than wrapped around the
	 * result, which keeps the expression two levels deep. Elementor's
	 * documented examples never nest further than that, so the flatter shape
	 * is the safer one to rely on.
	 *
	 * @param string                     $mode_control   Name of the preset/custom mode control.
	 * @param string                     $preset_control Name of the preset control.
	 * @param string[]                   $presets        Presets that include the element.
	 * @param string                     $toggle_control Name of the element's switcher.
	 * @param array<int, array<string, mixed>> $shared_terms Terms that must hold in every branch.
	 * @return array<string, mixed>
	 */
	protected function build_element_conditions( string $mode_control, string $preset_control, array $presets, string $toggle_control, array $shared_terms = array() ): array {
		$terms = array(
			array(
				'relation' => 'and',
				'terms'    => array_merge(
					$shared_terms,
					array(
						array(
							'name'     => $mode_control,
							'operator' => '===',
							'value'    => 'custom',
						),
						array(
							'name'     => $toggle_control,
							'operator' => '===',
							'value'    => 'yes',
						),
					)
				),
			),
		);

		if ( ! empty( $presets ) ) {
			$terms[] = array(
				'relation' => 'and',
				'terms'    => array_merge(
					$shared_terms,
					array(
						array(
							'name'     => $mode_control,
							'operator' => '===',
							'value'    => 'preset',
						),
						array(
							'name'     => $preset_control,
							'operator' => 'in',
							'value'    => $presets,
						),
					)
				),
			);
		}

		return array(
			'relation' => 'or',
			'terms'    => $terms,
		);
	}
}
