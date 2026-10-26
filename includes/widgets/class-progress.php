<?php
/**
 * Fundraising progress widget.
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
use WCDP_Elementor\Cache\Freshness;
use WCDP_Elementor\Cache\Live_Target;
use WCDP_Elementor\Cache\Location_Index;
use WCDP_Elementor\Data\Campaign;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Data\Value_Formatter;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders fundraising progress.
 *
 * The widget builds its own flex markup, with every visual aspect driven by
 * responsive Elementor controls scoped to the widget instance. Figures come
 * from WCDP through {@see \WCDP_Elementor\Data\Campaign}; only the markup is
 * ours, so the numbers can never drift from what the shortcode would print.
 *
 * The Elementor widget name and the control names inherited from 1.0.x are
 * unchanged, so no saved page needs rewriting.
 */
class Progress extends Widget_Base {

	/**
	 * Elements that can appear beside the bar.
	 */
	const ELEMENTS = array( 'amount', 'goal', 'percentage', 'remaining', 'deadline', 'count' );

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-progress-bar';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Progress', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-skill-bar';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'progress', 'bar', 'donation', 'fundraising', 'goal', 'raised', 'thermometer', 'wcdp' );
	}

	/**
	 * Stylesheets.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base', 'wcdp-el-progress' );
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

	/* ─────────────────────────── Presets ─────────────────────────── */

	/**
	 * The ten WCDP shortcode styles, expressed as element sets.
	 *
	 * A value of `above` or `below` places the element on that side of the
	 * bar; an absent key hides it. These double as the starting points for the
	 * custom builder.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function presets(): array {
		return array(
			'1'  => array(
				'bar'       => 'yes',
				'bar_label' => 'raised_of_goal',
			),
			'2'  => array(
				'bar'      => 'yes',
				'amount'   => 'above',
				'template' => 'raised_of_goal',
				'deadline' => 'above',
			),
			'3'  => array(
				'bar'      => 'yes',
				'amount'   => 'above',
				'template' => 'raised_of_goal',
				'deadline' => 'below',
			),
			'4'  => array(
				'bar'        => 'yes',
				'percentage' => 'above',
				'deadline'   => 'above',
			),
			'5'  => array( 'deadline' => 'above' ),
			'6'  => array(
				'amount'   => 'above',
				'template' => 'goal',
			),
			'7'  => array( 'remaining' => 'above' ),
			'8'  => array(
				'amount'   => 'above',
				'template' => 'raised_of_goal',
			),
			'9'  => array(
				'amount'   => 'above',
				'template' => 'raised',
			),
			'10' => array( 'percentage' => 'above' ),
		);
	}

	/**
	 * Labels for the preset picker.
	 *
	 * Style 7 is labelled "Amount still needed" because that is what WCDP's
	 * template actually prints (`goal - raised`); 1.0.x mislabelled it.
	 *
	 * @return array<string, string>
	 */
	private static function preset_labels(): array {
		return array(
			'1'  => esc_html__( '1 — Bar with amount inside', 'styleraise' ),
			'2'  => esc_html__( '2 — Amounts and deadline above bar', 'styleraise' ),
			'3'  => esc_html__( '3 — Amounts above bar, deadline below', 'styleraise' ),
			'4'  => esc_html__( '4 — Percentage and deadline above bar', 'styleraise' ),
			'5'  => esc_html__( '5 — Deadline only', 'styleraise' ),
			'6'  => esc_html__( '6 — Goal only', 'styleraise' ),
			'7'  => esc_html__( '7 — Amount still needed', 'styleraise' ),
			'8'  => esc_html__( '8 — Raised of goal', 'styleraise' ),
			'9'  => esc_html__( '9 — Raised only', 'styleraise' ),
			'10' => esc_html__( '10 — Percentage only', 'styleraise' ),
		);
	}

	/**
	 * Presets that include a given element.
	 *
	 * Feeds the static display conditions on the Style tab: Elementor cannot
	 * evaluate "does the chosen preset contain this element?" at runtime, so
	 * the answer is precomputed into a list of preset values.
	 *
	 * @param string $element Element key, or `bar` / `bar_label`.
	 * @return string[]
	 */
	private static function presets_with( string $element ): array {
		$matches = array();

		foreach ( self::presets() as $preset => $config ) {
			if ( isset( $config[ $element ] ) ) {
				$matches[] = (string) $preset;
			}
		}

		return $matches;
	}

	/**
	 * A named default text template.
	 *
	 * Kept in one place so the connector word in "{raised} of {goal}" is
	 * translatable — a template built inline would ship the English "of" to
	 * every locale.
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	private static function template( string $key ): string {
		switch ( $key ) {
			case 'raised_of_goal':
				/* translators: {raised} and {goal} are replaced with formatted amounts. Keep both placeholders. */
				return esc_html__( '{raised} of {goal}', 'styleraise' );

			case 'count':
				/* translators: {count} is replaced with the number of donations. Keep the placeholder. */
				return esc_html__( '{count} donations', 'styleraise' );

			case 'raised':
			case 'goal':
			case 'remaining':
			case 'percentage':
			case 'timeleft':
				return '{' . $key . '}';
		}

		return '';
	}

	/* ─────────────────────────── Controls ─────────────────────────── */

	/**
	 * Register every control.
	 */
	protected function register_controls(): void {
		$this->register_campaign_section();
		$this->register_layout_section();
		$this->register_format_section();

		$this->register_bar_style();
		$this->register_bar_label_style();
		$this->register_element_styles();
		$this->register_layout_style();
	}

	/**
	 * Content → Campaign.
	 */
	private function register_campaign_section(): void {
		$this->open_content_section( 'section_campaign', esc_html__( 'Campaign', 'styleraise' ) );

		$this->register_product_controls();
		$this->register_campaign_data_controls();
		$this->register_live_control();

		$this->end_controls_section();
	}

	/**
	 * Content → Layout & Elements.
	 */
	private function register_layout_section(): void {
		$this->open_content_section( 'section_layout', esc_html__( 'Layout & Elements', 'styleraise' ) );


		$this->add_control(
			'content_mode',
			array(
				'label'        => esc_html__( 'Composition', 'styleraise' ),
				'type'         => Controls_Manager::SELECT,
				'default'      => 'preset',
				'options'      => array(
					'preset' => esc_html__( 'Use a preset', 'styleraise' ),
					'custom' => esc_html__( 'Build it myself', 'styleraise' ),
				),
			)
		);

		$this->add_control(
			'style',
			array(
				'label'      => esc_html__( 'Preset', 'styleraise' ),
				'type'       => Controls_Manager::SELECT,
				'default'    => '1',
				'options'    => self::preset_labels(),
				'condition' => array( 'content_mode' => 'preset' ),
			)
		);

		$custom_only = array( 'content_mode' => 'custom' );

		$this->add_control(
			'show_bar',
			array(
				'label'        => esc_html__( 'Progress Bar', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
				'condition'    => $custom_only,
			)
		);

		$this->add_control(
			'bar_label',
			array(
				'label'       => esc_html__( 'Text Inside Bar', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'placeholder' => self::template( 'raised_of_goal' ),
				'description' => $this->get_placeholder_help(),
				'condition'   => $custom_only + array( 'show_bar' => 'yes' ),
			)
		);

		$this->register_element_content_controls( 'amount', esc_html__( 'Amount', 'styleraise' ), self::template( 'raised_of_goal' ), $custom_only, 'yes' );
		$this->register_element_content_controls( 'goal', esc_html__( 'Goal', 'styleraise' ), self::template( 'goal' ), $custom_only, '' );
		$this->register_element_content_controls( 'percentage', esc_html__( 'Percentage', 'styleraise' ), self::template( 'percentage' ), $custom_only, '' );
		$this->register_element_content_controls( 'remaining', esc_html__( 'Still Needed', 'styleraise' ), self::template( 'remaining' ), $custom_only, '' );
		$this->register_element_content_controls( 'deadline', esc_html__( 'Deadline', 'styleraise' ), self::template( 'timeleft' ), $custom_only, '' );
		$this->register_element_content_controls( 'count', esc_html__( 'Donation Count', 'styleraise' ), self::template( 'count' ), $custom_only, '' );

		$this->end_controls_section();
	}

	/**
	 * Switcher, template and position for one element.
	 *
	 * @param string               $element  Element key.
	 * @param string               $label    Human label.
	 * @param string               $template Default text template.
	 * @param array<string, mixed> $base     Shared condition.
	 * @param string               $default  Default switcher value.
	 */
	private function register_element_content_controls( string $element, string $label, string $template, array $base, string $default ): void {
		$this->add_control(
			'show_' . $element,
			array(
				'label'        => $label,
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => $default,
				'separator'    => 'before',
				'condition'    => $base,
			)
		);

		$this->add_control(
			$element . '_template',
			array(
				'label'       => esc_html__( 'Text', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => $template,
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'description' => $this->get_placeholder_help(),
				'condition'   => $base + array( 'show_' . $element => 'yes' ),
			)
		);

		$this->add_control(
			$element . '_position',
			array(
				'label'     => esc_html__( 'Position', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'above',
				'options'   => array(
					'above' => esc_html__( 'Above the bar', 'styleraise' ),
					'below' => esc_html__( 'Below the bar', 'styleraise' ),
				),
				'condition' => $base + array( 'show_' . $element => 'yes' ),
			)
		);
	}

	/**
	 * Content → Number Format.
	 */
	private function register_format_section(): void {
		$this->open_content_section(
			'section_format',
			esc_html__( 'Number Format', 'styleraise' )
		);

		$this->add_control(
			'amount_source',
			array(
				'label'       => esc_html__( 'Amount Formatting', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'site',
				'options'     => array(
					'site'   => esc_html__( 'Match the rest of the site', 'styleraise' ),
					'custom' => esc_html__( 'Format here', 'styleraise' ),
				),
				'description' => esc_html__( '"Match the rest of the site" prints the amounts exactly as WCDP does, so a multi-currency switcher keeps working. Choose "Format here" only if you need the decimal and compact options below — they format the base currency and will not follow a currency switcher.', 'styleraise' ),
			)
		);

		$this->add_control(
			'currency_position',
			array(
				'label'       => esc_html__( 'Currency Symbol Position', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''            => esc_html__( 'Follow WooCommerce setting', 'styleraise' ),
					'auto'        => esc_html__( 'Automatic — let a currency switcher decide', 'styleraise' ),
					'left'        => esc_html__( 'Left — {symbol}100', 'styleraise' ),
					'right'       => esc_html__( 'Right — 100{symbol}', 'styleraise' ),
					'left_space'  => esc_html__( 'Left with space — {symbol} 100', 'styleraise' ),
					'right_space' => esc_html__( 'Right with space — 100 {symbol}', 'styleraise' ),
				),
				'description' => esc_html__( 'Applies in both formatting modes. The default reads WooCommerce → Settings → General → Currency position and enforces it, so a currency plugin cannot quietly flip the symbol. Choose "Automatic" if you want a multi-currency switcher to set the position per currency.', 'styleraise' ),
			)
		);

		$this->add_control(
			'amount_decimals',
			array(
				'label'       => esc_html__( 'Amount Decimals', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 4,
				'default'     => '',
				'placeholder' => esc_html__( 'Store default', 'styleraise' ),
				'condition'   => array( 'amount_source' => 'custom' ),
			)
		);

		$this->add_control(
			'trim_zero_decimals',
			array(
				'label'        => esc_html__( 'Hide .00 Decimals', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show whole amounts without decimal places.', 'styleraise' ),
				'condition'   => array( 'amount_source' => 'custom' ),
			)
		);

		$this->add_control(
			'compact_amounts',
			array(
				'label'        => esc_html__( 'Compact Notation', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Render large amounts as 1.2K / 3.4M. The currency symbol is dropped in this mode.', 'styleraise' ),
				'condition'   => array( 'amount_source' => 'custom' ),
			)
		);

		// Legacy control name, kept so 1.0.x values survive.
		$this->add_control(
			'percentage_decimals',
			array(
				'label'   => esc_html__( 'Percentage Decimals', 'styleraise' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 4,
				'default' => 0,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Description listing the available text placeholders.
	 *
	 * @return string
	 */
	private function get_placeholder_help(): string {
		return esc_html__( 'Placeholders: {raised} {goal} {remaining} {percentage} {count} {timeleft}', 'styleraise' );
	}

	/* ─────────────────────────── Style: bar ─────────────────────────── */

	/**
	 * Condition showing a control when the bar is rendered.
	 *
	 * @return array<string, mixed>
	 */
	private function bar_conditions(): array {
		return $this->build_element_conditions( 'content_mode', 'style', self::presets_with( 'bar' ), 'show_bar' );
	}

	/**
	 * Condition showing a control when a given element is rendered.
	 *
	 * @param string $element Element key.
	 * @return array<string, mixed>
	 */
	private function element_conditions( string $element ): array {
		return $this->build_element_conditions( 'content_mode', 'style', self::presets_with( $element ), 'show_' . $element );
	}

	/**
	 * Style → Progress Bar.
	 */
	private function register_bar_style(): void {
		$this->open_style_section(
			'section_bar_style',
			esc_html__( 'Progress Bar', 'styleraise' ),
			array( 'conditions' => $this->bar_conditions() )
		);

		$bar   = '{{WRAPPER}} .wcdp-el-progress__bar';
		$track = '{{WRAPPER}} .wcdp-el-progress__track';
		$fill  = '{{WRAPPER}} .wcdp-el-progress__fill';

		$this->add_slider(
			'bar_height',
			esc_html__( 'Height', 'styleraise' ),
			$bar,
			'min-height: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em', 'rem' ),
				'default'    => array(
					'unit' => 'px',
					'size' => 16,
				),
			)
		);

		$this->add_border_radius( 'bar_radius', $bar . ', ' . $track . ', ' . $fill );

		$this->add_control(
			'bar_track_heading',
			array(
				'label'     => esc_html__( 'Track', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'bar_track_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $track,
			)
		);

		$this->add_control(
			'bar_fill_heading',
			array(
				'label'     => esc_html__( 'Fill', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'bar_fill_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $fill,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'bar_border',
				'selector'  => $bar,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'bar_box_shadow',
				'selector' => $bar,
			)
		);

		$this->add_control(
			'bar_animate',
			array(
				'label'        => esc_html__( 'Animate on Load', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'bar_animation_duration',
			array(
				'label'      => esc_html__( 'Animation Duration', 'styleraise' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 's' ),
				'range'      => array(
					's' => array(
						'min'  => 0,
						'max'  => 5,
						'step' => 0.1,
					),
				),
				'default'    => array(
					'unit' => 's',
					'size' => 1.5,
				),
				'selectors'  => array(
					'{{WRAPPER}} .wcdp-el-progress__fill' => '--wcdp-el-bar-duration: {{SIZE}}s;',
				),
				'condition'  => array( 'bar_animate' => 'yes' ),
			)
		);

		$this->add_margin( 'bar_margin', $bar, array( 'separator' => 'before' ) );

		$this->add_order( 'bar_order', $bar );

		$this->end_controls_section();
	}

	/**
	 * Style → Text Inside Bar.
	 */
	private function register_bar_label_style(): void {
		$conditions = array(
			'relation' => 'or',
			'terms'    => array(
				array(
					'relation' => 'and',
					'terms'    => array(
						array(
							'name'     => 'content_mode',
							'operator' => '===',
							'value'    => 'custom',
						),
						array(
							'name'     => 'bar_label',
							'operator' => '!==',
							'value'    => '',
						),
					),
				),
				array(
					'relation' => 'and',
					'terms'    => array(
						array(
							'name'     => 'content_mode',
							'operator' => '===',
							'value'    => 'preset',
						),
						array(
							'name'     => 'style',
							'operator' => 'in',
							'value'    => self::presets_with( 'bar_label' ),
						),
					),
				),
			),
		);

		$selector = '{{WRAPPER}} .wcdp-el-progress__bar-label';

		$this->add_text_style_section(
			'bar_label',
			esc_html__( 'Text Inside Bar', 'styleraise' ),
			$selector,
			array(
				'conditions'   => $conditions,
				'align'        => false,
				'spacing'      => false,
				'currency'     => true,
				'global_color' => null,
				'extra'        => function () use ( $selector ) {
					$this->add_control(
						'bar_label_placement',
						array(
							'label'     => esc_html__( 'Placement', 'styleraise' ),
							'type'      => Controls_Manager::SELECT,
							'default'   => 'fill',
							'options'   => array(
								'fill' => esc_html__( 'Follow the fill', 'styleraise' ),
								'bar'  => esc_html__( 'Over the whole bar', 'styleraise' ),
							),
							'separator' => 'before',
						)
					);

					// One control drives both placements: the in-flow label
					// aligns inside the fill, the overlay label inside the bar.
					$this->add_flex_align(
						'bar_label_align',
						'{{WRAPPER}} .wcdp-el-progress__bar--label-fill .wcdp-el-progress__fill,'
						. ' {{WRAPPER}} .wcdp-el-progress__bar--label-bar .wcdp-el-progress__bar-wrap',
						array( 'default' => 'flex-end' )
					);

					$this->add_padding( 'bar_label_padding', $selector );

					$this->add_value_part_controls( 'bar_label', $selector );
				},
			)
		);
	}

	/* ─────────────────────────── Style: elements ─────────────────────────── */

	/**
	 * Style sections for every text element.
	 */
	private function register_element_styles(): void {
		$labels = array(
			'amount'     => esc_html__( 'Amount', 'styleraise' ),
			'goal'       => esc_html__( 'Goal', 'styleraise' ),
			'percentage' => esc_html__( 'Percentage', 'styleraise' ),
			'remaining'  => esc_html__( 'Still Needed', 'styleraise' ),
			'deadline'   => esc_html__( 'Deadline', 'styleraise' ),
			'count'      => esc_html__( 'Donation Count', 'styleraise' ),
		);

		foreach ( $labels as $element => $label ) {
			$selector = '{{WRAPPER}} .wcdp-el-progress__' . $element;

			$this->add_text_style_section(
				$element,
				$label,
				$selector,
				array(
					'conditions'        => $this->element_conditions( $element ),
					'order'             => true,
					'currency'          => in_array( $element, array( 'amount', 'goal', 'remaining' ), true ),
					'global_typography' => 'amount' === $element ? Global_Typography::TYPOGRAPHY_PRIMARY : Global_Typography::TYPOGRAPHY_TEXT,
					'global_color'      => 'amount' === $element ? Global_Colors::COLOR_PRIMARY : Global_Colors::COLOR_TEXT,
					'extra'             => function () use ( $element, $selector ) {
						$this->add_value_part_controls( $element, $selector );
					},
				)
			);
		}
	}

	/**
	 * Per-value overrides inside a text element.
	 *
	 * Every placeholder renders into its own span, so "big bold raised amount,
	 * small grey goal" needs no custom CSS.
	 *
	 * @param string $prefix   Control name prefix.
	 * @param string $selector Selector of the containing element.
	 */
	private function add_value_part_controls( string $prefix, string $selector ): void {
		$parts = array(
			'raised'     => esc_html__( 'Raised Value', 'styleraise' ),
			'goal'       => esc_html__( 'Goal Value', 'styleraise' ),
			'remaining'  => esc_html__( 'Remaining Value', 'styleraise' ),
			'percentage' => esc_html__( 'Percentage Value', 'styleraise' ),
			'count'      => esc_html__( 'Count Value', 'styleraise' ),
			'timeleft'   => esc_html__( 'Time Left Value', 'styleraise' ),
		);

		$this->add_control(
			$prefix . '_parts_heading',
			array(
				'label'       => esc_html__( 'Individual Values', 'styleraise' ),
				'type'        => Controls_Manager::HEADING,
				'separator'   => 'before',
				'description' => esc_html__( 'Overrides for a single placeholder inside the text above. Only the placeholders you actually use have any effect.', 'styleraise' ),
			)
		);

		foreach ( $parts as $part => $label ) {
			$part_selector = $selector . ' .wcdp-el-value--' . $part;

			$this->add_typography(
				$prefix . '_part_' . $part . '_typography',
				$part_selector,
				array( 'label' => $label )
			);

			$this->add_color(
				$prefix . '_part_' . $part . '_color',
				$part_selector,
				array(
					/* translators: %s: name of the value, e.g. "Raised Value". */
					'label'          => sprintf( esc_html__( '%s Color', 'styleraise' ), $label ),
					'global_default' => null,
				)
			);
		}
	}

	/* ─────────────────────────── Style: layout ─────────────────────────── */

	/**
	 * Style → Layout.
	 */
	private function register_layout_style(): void {
		$this->open_style_section(
			'section_layout_style',
			esc_html__( 'Layout', 'styleraise' )
		);

		$this->add_responsive_control(
			'row_direction',
			array(
				'label'     => esc_html__( 'Rows Direction', 'styleraise' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'row'    => array(
						'title' => esc_html__( 'Side by side', 'styleraise' ),
						'icon'  => 'eicon-arrow-right',
					),
					'column' => array(
						'title' => esc_html__( 'Stacked', 'styleraise' ),
						'icon'  => 'eicon-arrow-down',
					),
				),
				'default'   => 'row',
				'selectors' => array(
					'{{WRAPPER}} .wcdp-el-progress__row' => 'flex-direction: {{VALUE}};',
				),
				'description' => esc_html__( 'Set this per device — stacking the amounts on mobile is usually what you want.', 'styleraise' ),
			)
		);

		// Registered first so the two per-row controls below, which Elementor
		// prints later at equal specificity, can override it.
		$this->add_flex_align(
			'row_align',
			'{{WRAPPER}} .wcdp-el-progress__row',
			array(
				'label'   => esc_html__( 'Alignment — All Rows', 'styleraise' ),
				'default' => 'space-between',
			)
		);

		$this->add_control(
			'row_align_heading',
			array(
				'label'       => esc_html__( 'Per-Row Override', 'styleraise' ),
				'type'        => Controls_Manager::HEADING,
				'separator'   => 'before',
				'description' => esc_html__( 'Rows above and below the bar usually hold a different number of items — two amounts spread apart above, a single value aligned right below. Leave a field empty to keep the shared alignment.', 'styleraise' ),
			)
		);

		$this->add_flex_align(
			'row_align_above',
			'{{WRAPPER}} .wcdp-el-progress__row--above',
			array( 'label' => esc_html__( 'Above the Bar', 'styleraise' ) )
		);

		$this->add_flex_align(
			'row_align_below',
			'{{WRAPPER}} .wcdp-el-progress__row--below',
			array( 'label' => esc_html__( 'Below the Bar', 'styleraise' ) )
		);

		$this->add_slider(
			'row_gap',
			esc_html__( 'Gap Inside Rows', 'styleraise' ),
			'{{WRAPPER}} .wcdp-el-progress__row',
			'gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'max' => 80 ) ),
			)
		);

		$this->add_slider(
			'stack_gap',
			esc_html__( 'Gap Between Rows and Bar', 'styleraise' ),
			'{{WRAPPER}} .wcdp-el-progress',
			'gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'max' => 80 ) ),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
			)
		);

		$this->add_box_style(
			'wrapper',
			'{{WRAPPER}} .wcdp-el-progress',
			array()
		);

		$this->end_controls_section();
	}


	/* ─────────────────────────── Render ─────────────────────────── */

	/**
	 * Render the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$campaign = $this->get_campaign();

		if ( ! $campaign ) {
			$this->render_placeholder( $this->get_unresolved_message() );

			return;
		}

		$this->render_native( $campaign, $settings );
	}

	/**
	 * Native markup.
	 *
	 * @param Campaign             $campaign Campaign snapshot.
	 * @param array<string, mixed> $settings Widget settings.
	 */
	private function render_native( Campaign $campaign, array $settings ): void {
		$composition = $this->resolve_composition( $settings, $campaign );

		$rows = array(
			'above' => array(),
			'below' => array(),
		);

		foreach ( self::ELEMENTS as $element ) {
			if ( empty( $composition['elements'][ $element ] ) ) {
				continue;
			}

			$html = $this->render_value_template( $composition['templates'][ $element ], $campaign, $settings );

			if ( '' === $html ) {
				continue;
			}

			$position = $composition['elements'][ $element ];

			$rows[ $position ][] = sprintf(
				'<span class="wcdp-el-progress__item wcdp-el-progress__%1$s">%2$s</span>',
				esc_attr( $element ),
				$html
			);
		}

		if ( empty( $rows['above'] ) && empty( $rows['below'] ) && ! $composition['bar'] ) {
			$this->render_placeholder( esc_html__( 'Nothing to show — enable the bar or at least one element.', 'styleraise' ) );

			return;
		}

		$classes = array( 'wcdp-el-progress' );

		if ( 'preset' === ( $settings['content_mode'] ?? 'preset' ) ) {
			$classes[] = 'wcdp-el-progress--preset-' . sanitize_html_class( (string) ( $settings['style'] ?? '1' ) );
		}

		if ( 'yes' === ( $settings['bar_animate'] ?? 'yes' ) ) {
			$classes[] = 'wcdp-el-progress--animated';
		}

		$style = array( '--wcdp-el-fill:' . round( $campaign->get_fill_percentage(), 2 ) . '%' );

		// Seed the palette from WCDP's own colour settings so a fresh widget
		// matches the rest of the site before anyone opens the Style tab.
		foreach ( $this->get_palette_variables() as $declaration ) {
			$style[] = $declaration;
		}

		$attributes = array(
			'class' => $classes,
			'style' => implode( ';', $style ),
		);

		// A page cache stores this markup with whatever total was current when
		// it was written. The descriptor lets the browser fetch the real one
		// afterwards, which is what keeps an hours-old cached page honest.
		$live = Live_Target::attribute(
			$campaign,
			Value_Formatter::fmt_from_settings( $settings ),
			$this->build_campaign_args( $campaign->get_product_id(), $settings ),
			(string) ( $settings['live_updates'] ?? 'inherit' )
		);

		if ( '' !== $live ) {
			$attributes[ Live_Target::ATTRIBUTE ] = $live;

			Live_Target::mark_needed();
		}

		// Remember that this page shows this campaign, so a donation can purge
		// exactly the pages that went stale and nothing else.
		Location_Index::record( $campaign->get_product_id(), $campaign->get_addids() );

		$this->add_render_attribute( 'wrapper', $attributes );

		echo '<div ';
		$this->print_render_attribute_string( 'wrapper' );
		echo '>';

		$this->render_row( 'above', $rows['above'] );

		if ( $composition['bar'] ) {
			$this->render_bar( $campaign, $composition, $settings );
		}

		$this->render_row( 'below', $rows['below'] );

		echo '</div>';

		$this->render_editor_hints( $campaign );
	}

	/**
	 * Print one meta row.
	 *
	 * @param string   $position `above` or `below`.
	 * @param string[] $items    Pre-rendered item markup.
	 */
	private function render_row( string $position, array $items ): void {
		if ( empty( $items ) ) {
			return;
		}

		printf(
			'<div class="wcdp-el-progress__row wcdp-el-progress__row--%1$s">%2$s</div>',
			esc_attr( $position ),
			wp_kses( implode( '', $items ), Kses::markup() )
		);
	}

	/**
	 * Print the bar.
	 *
	 * @param Campaign             $campaign    Campaign snapshot.
	 * @param array<string, mixed> $composition Resolved composition.
	 * @param array<string, mixed> $settings    Widget settings.
	 */
	private function render_bar( Campaign $campaign, array $composition, array $settings ): void {
		$label = '';

		if ( '' !== $composition['bar_label'] ) {
			$label = $this->render_value_template( $composition['bar_label'], $campaign, $settings );
		}

		$placement = 'bar' === ( $settings['bar_label_placement'] ?? 'fill' ) ? 'bar' : 'fill';

		$this->add_render_attribute(
			'bar',
			array(
				'class'         => array( 'wcdp-el-progress__bar', 'wcdp-el-progress__bar--label-' . $placement ),
				'role'          => 'progressbar',
				'aria-valuenow' => (string) round( $campaign->get_raised(), 2 ),
				'aria-valuemin' => '0',
				'aria-valuemax' => (string) round( $campaign->get_goal(), 2 ),
				'aria-label'    => $campaign->get_aria_label(),
			)
		);

		echo '<div ';
		$this->print_render_attribute_string( 'bar' );
		echo '>';
		echo '<div class="wcdp-el-progress__track"></div>';

		if ( '' !== $label && 'fill' === $placement ) {
			// In flow inside the fill, so a label taller than the bar grows it
			// instead of being clipped.
			printf(
				'<div class="wcdp-el-progress__fill"><span class="wcdp-el-progress__bar-label">%s</span></div>',
				wp_kses( $label, Kses::markup() )
			);
		} else {
			echo '<div class="wcdp-el-progress__fill"></div>';

			if ( '' !== $label ) {
				printf(
					'<div class="wcdp-el-progress__bar-wrap"><span class="wcdp-el-progress__bar-label">%s</span></div>',
					wp_kses( $label, Kses::markup() )
				);
			}
		}

		echo '</div>';
	}

	/**
	 * Inline custom properties seeding the bar palette from WCDP's settings.
	 *
	 * @return string[] CSS declarations.
	 */
	private function get_palette_variables(): array {
		$track = sanitize_hex_color( (string) get_option( 'wcdp_secondary_color', '#30bf76' ) );
		$fill  = sanitize_hex_color( (string) get_option( 'wcdp_main_color', '#006633' ) );

		$declarations = array();

		if ( $track ) {
			$declarations[] = '--wcdp-el-track-color:' . $track;
		}

		if ( $fill ) {
			$declarations[] = '--wcdp-el-fill-color:' . $fill;
		}

		return $declarations;
	}

	/**
	 * Work out which elements render, where, and with what text.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @param Campaign             $campaign Campaign snapshot.
	 * @return array{bar:bool,bar_label:string,elements:array<string,string>,templates:array<string,string>}
	 */
	private function resolve_composition( array $settings, Campaign $campaign ): array {
		$mode = (string) ( $settings['content_mode'] ?? 'preset' );

		if ( 'custom' === $mode ) {
			$composition = $this->resolve_custom_composition( $settings );
		} else {
			$composition = $this->resolve_preset_composition( (string) ( $settings['style'] ?? '1' ) );
		}

		// A campaign without a goal cannot express progress; showing a bar or a
		// percentage against WCDP's silent fallback of 100 would be fiction.
		if ( ! $campaign->has_goal() ) {
			$composition['bar'] = false;
			$composition['bar_label'] = '';

			unset(
				$composition['elements']['goal'],
				$composition['elements']['percentage'],
				$composition['elements']['remaining']
			);

			foreach ( $composition['templates'] as $key => $template ) {
				$composition['templates'][ $key ] = $this->strip_goal_placeholders( $template );
			}

			// Presets built entirely from goal-dependent parts would now render
			// nothing at all. Falling back to the amount raised keeps the
			// widget informative instead of blank.
			if ( empty( $composition['elements'] ) ) {
				$composition['elements']['amount']  = 'above';
				$composition['templates']['amount'] = self::template( 'raised' );
			}
		}

		/**
		 * Filter the resolved composition before rendering.
		 *
		 * @param array    $composition Resolved composition.
		 * @param array    $settings    Widget settings.
		 * @param Campaign $campaign    Campaign snapshot.
		 */
		return (array) apply_filters( 'wcdp_el_progress_composition', $composition, $settings, $campaign );
	}

	/**
	 * Composition from a preset.
	 *
	 * @param string $preset Preset key.
	 * @return array{bar:bool,bar_label:string,elements:array<string,string>,templates:array<string,string>}
	 */
	private function resolve_preset_composition( string $preset ): array {
		$presets = self::presets();
		$config  = $presets[ $preset ] ?? $presets['1'];

		$elements  = array();
		$templates = array();

		foreach ( self::ELEMENTS as $element ) {
			if ( ! isset( $config[ $element ] ) ) {
				continue;
			}

			$elements[ $element ]  = $config[ $element ];
			$templates[ $element ] = $this->get_preset_template( $element, $config );
		}

		return array(
			'bar'       => ! empty( $config['bar'] ),
			'bar_label' => self::template( (string) ( $config['bar_label'] ?? '' ) ),
			'elements'  => $elements,
			'templates' => $templates,
		);
	}

	/**
	 * Default text for an element inside a preset.
	 *
	 * @param string                $element Element key.
	 * @param array<string, string> $config  Preset configuration.
	 * @return string
	 */
	private function get_preset_template( string $element, array $config ): string {
		if ( 'amount' === $element ) {
			return self::template( (string) ( $config['template'] ?? 'raised_of_goal' ) );
		}

		$keys = array(
			'percentage' => 'percentage',
			'remaining'  => 'remaining',
			'deadline'   => 'timeleft',
			'count'      => 'count',
		);

		return isset( $keys[ $element ] ) ? self::template( $keys[ $element ] ) : '';
	}

	/**
	 * Composition from the element switchers.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array{bar:bool,bar_label:string,elements:array<string,string>,templates:array<string,string>}
	 */
	private function resolve_custom_composition( array $settings ): array {
		$elements  = array();
		$templates = array();

		foreach ( self::ELEMENTS as $element ) {
			if ( 'yes' !== ( $settings[ 'show_' . $element ] ?? '' ) ) {
				continue;
			}

			$position = 'below' === ( $settings[ $element . '_position' ] ?? 'above' ) ? 'below' : 'above';

			$elements[ $element ]  = $position;
			$templates[ $element ] = (string) ( $settings[ $element . '_template' ] ?? '' );
		}

		return array(
			'bar'       => 'yes' === ( $settings['show_bar'] ?? 'yes' ),
			'bar_label' => (string) ( $settings['bar_label'] ?? '' ),
			'elements'  => $elements,
			'templates' => $templates,
		);
	}

	/**
	 * Remove goal-dependent placeholders from a template.
	 *
	 * @param string $template Text template.
	 * @return string
	 */
	private function strip_goal_placeholders( string $template ): string {
		if ( false === strpos( $template, '{goal}' ) ) {
			return $template;
		}

		// "{raised} of {goal}" degrades to "{raised}" rather than to a
		// dangling connector.
		if ( false !== strpos( $template, '{raised}' ) ) {
			return '{raised}';
		}

		return str_replace( '{goal}', '', $template );
	}

	/* ─────────────────────────── Value rendering ─────────────────────────── */

	/**
	 * Replace placeholders in a text template with styleable spans.
	 *
	 * @param string               $template Text template.
	 * @param Campaign             $campaign Campaign snapshot.
	 * @param array<string, mixed> $settings Widget settings.
	 * @return string Safe HTML.
	 */
	private function render_value_template( string $template, Campaign $campaign, array $settings ): string {
		$template = trim( $template );

		if ( '' === $template ) {
			return '';
		}

		$values = $this->get_placeholder_values( $campaign, $settings );

		// Escape the literal text first, then substitute markup, so author
		// text can never inject HTML while the value spans stay intact.
		$output = esc_html( $template );

		foreach ( $values as $placeholder => $markup ) {
			$output = str_replace( esc_html( $placeholder ), $markup, $output );
		}

		return $output;
	}

	/**
	 * Placeholder → markup map.
	 *
	 * @param Campaign             $campaign Campaign snapshot.
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array<string, string>
	 */
	private function get_placeholder_values( Campaign $campaign, array $settings ): array {
		// Formatting lives in Value_Formatter because the REST endpoint that
		// refreshes these figures has to reproduce them character for character.
		// Two implementations would drift, and every refresh would visibly
		// rewrite the page for no reason.
		$values = Value_Formatter::values( $campaign, Value_Formatter::fmt_from_settings( $settings ) );

		$map = array();

		foreach ( $values as $name => $html ) {
			$map[ '{' . $name . '}' ] = $this->wrap_value( $name, $html );
		}

		return $map;
	}

	/**
	 * Wrap one value in its styleable span.
	 *
	 * @param string $name Value name.
	 * @param string $html Already-escaped value markup.
	 * @return string
	 */
	private function wrap_value( string $name, string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			'<span class="wcdp-el-value wcdp-el-value--%1$s" %2$s="%1$s">%3$s</span>',
			esc_attr( $name ),
			esc_attr( Live_Target::VALUE_ATTRIBUTE ),
			$html
		);
	}



	/* ─────────────────────────── Editor hints ─────────────────────────── */

	/**
	 * Surface configuration problems in the editor only.
	 *
	 * @param Campaign $campaign Campaign snapshot.
	 */
	private function render_editor_hints( Campaign $campaign ): void {
		if ( ! $this->is_editor() || $campaign->has_goal() ) {
			return;
		}

		printf(
			'<div class="wcdp-el-placeholder wcdp-el-placeholder--hint"><span class="wcdp-el-placeholder__text">%s</span></div>',
			esc_html__( 'No fundraising goal is set on this product, so the bar, percentage and remaining amount are hidden. Set a goal in the product\'s WCDP settings, or enter a Goal Override.', 'styleraise' )
		);
	}
}
