<?php
/**
 * Donation form widget.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use WCDP_Elementor\Cache\Script_Guard;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Editor\Form_Preview;
use WCDP_Elementor\Render\Kses;
use WCDP_Elementor\Render\Shortcode_Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCDP donation form with a full Style tab.
 *
 * The form is rendered by WCDP — it is a real WooCommerce checkout, and
 * reimplementing it would mean reimplementing payment. What this widget adds
 * is control: WCDP drives almost its entire appearance through custom
 * properties declared on `:root`, so redeclaring those on the widget turns a
 * site-wide palette into a per-instance one, and the descendant selectors
 * cover everything the variables do not reach.
 *
 * Two upstream behaviours shape the implementation:
 *
 * - In popup mode WCDP prints the form on `wp_footer`, outside the widget
 *   wrapper, so style selectors have to name `#wcdp-form` as well as
 *   `{{WRAPPER}}`. Only one form per page is permitted, so that id is unique.
 * - WCDP's shortcode refuses to render on `elementor_ajax`, the request that
 *   re-renders the widget after a content change. With the editor preview on,
 *   Editor\Form_Preview builds the form there through WCDP's own method;
 *   otherwise the widget shows a hint. Style controls are unaffected: they are
 *   pure CSS and need no re-render.
 */
class Donation_Form extends Widget_Base {

	/**
	 * Oldest WCDP release that renders each newer layout.
	 *
	 * An older WCDP does not reject an unknown layout: it silently renders
	 * layout 1. Layouts missing here exist in every supported release.
	 */
	private const STYLE_MIN_WCDP = array(
		'6' => '1.5.0',
		'7' => '1.5.1',
		'8' => '1.5.1',
	);

	/**
	 * Form layouts the given WCDP release can render, key => label.
	 *
	 * @param string $wcdp_version Installed WCDP version, '0' when unknown.
	 * @return array<string, string>
	 */
	public static function available_styles( string $wcdp_version ): array {
		$styles = array(
			'1' => esc_html__( '1 — Three steps with progress header', 'styleraise' ),
			'2' => esc_html__( '2 — Everything on one page', 'styleraise' ),
			'3' => esc_html__( '3 — Three steps, no header', 'styleraise' ),
			'4' => esc_html__( '4 — Amount only, redirect to checkout', 'styleraise' ),
			'5' => esc_html__( '5 — Three steps with banner header', 'styleraise' ),
			'6' => esc_html__( '6 — Steps, no header, back arrow on top', 'styleraise' ),
			'7' => esc_html__( '7 — Two steps, no header', 'styleraise' ),
			'8' => esc_html__( '8 — Two steps with banner header', 'styleraise' ),
		);

		foreach ( self::STYLE_MIN_WCDP as $style => $minimum ) {
			if ( version_compare( $wcdp_version, $minimum, '<' ) ) {
				unset( $styles[ $style ] );
			}
		}

		return $styles;
	}

	/**
	 * Installed WCDP version, '0' when WCDP does not declare one.
	 *
	 * Filterable so a site — or a test — can describe a different release
	 * than the constant reports.
	 *
	 * @return string
	 */
	private static function wcdp_version(): string {
		$version = defined( 'WCDP_VERSION' ) ? (string) WCDP_VERSION : '0';

		/**
		 * Filters the WCDP version the donation form widget compares layouts against.
		 *
		 * @param string $version WCDP_VERSION, or '0' when it is not defined.
		 */
		return (string) apply_filters( 'wcdp_el_wcdp_version', $version );
	}

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-donation-form';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'WCDP Donation Form', 'styleraise' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'donation', 'donate', 'form', 'checkout', 'fundraising', 'wcdp' );
	}

	/**
	 * Stylesheets.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'wcdp-el-base', 'wcdp-el-form' );
	}

	/* ─────────────────────────── Selector helpers ─────────────────────────── */

	/**
	 * Both roots the form can live under.
	 *
	 * Custom properties set here cascade into the whole form, inline or popup.
	 *
	 * @return string
	 */
	private function root(): string {
		return '{{WRAPPER}} .wcdp-form, body #wcdp-form';
	}

	/**
	 * A selector inside the form, valid in both inline and popup mode.
	 *
	 * @param string $suffix Descendant selector.
	 * @return string
	 */
	private function sel( string $suffix ): string {
		return '{{WRAPPER}} .wcdp-form ' . $suffix . ', body #wcdp-form ' . $suffix;
	}

	/**
	 * A selector on the popup chrome, which never exists inside the wrapper.
	 *
	 * @param string $suffix Selector, relative to the overlay.
	 * @return string
	 */
	private function popup_sel( string $suffix = '' ): string {
		return 'body #wcdp-form' . ( '' !== $suffix ? ' ' . $suffix : '' );
	}

	/* ─────────────────────────── Controls ─────────────────────────── */

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_palette_style();
		$this->register_box_style();
		$this->register_header_style();
		$this->register_amount_options_style();
		$this->register_amount_input_style();
		$this->register_range_style();
		$this->register_labels_style();
		$this->register_buttons_style();
		$this->register_fields_style();
		$this->register_fee_recovery_style();
		$this->register_popup_style();
		$this->register_media_style();
		$this->register_misc_style();
	}

	/**
	 * Content → Donation Form.
	 */
	private function register_content_controls(): void {
		$this->open_content_section( 'section_campaign', esc_html__( 'Donation Form', 'styleraise' ) );

		$this->register_product_controls();

		// Legacy control names, unchanged from 1.0.x. Only the layouts the
		// installed WCDP can render are offered; a saved newer one is kept.
		$styles = self::available_styles( self::wcdp_version() );
		$layout = array(
			'label'     => esc_html__( 'Form Layout', 'styleraise' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => '1',
			'separator' => 'before',
			'options'   => $styles,
		);

		if ( ! isset( $styles['6'] ) ) {
			$layout['description'] = esc_html__( 'Styles 6–8 need Donation Platform for WooCommerce 1.5.0 (style 6) or 1.5.1 (styles 7–8) or newer.', 'styleraise' );
		} elseif ( ! isset( $styles['7'] ) ) {
			$layout['description'] = esc_html__( 'Styles 7–8 need Donation Platform for WooCommerce 1.5.1 or newer.', 'styleraise' );
		}

		$this->add_control( 'style', $layout );

		$this->add_control(
			'popup',
			array(
				'label'        => esc_html__( 'Open in a Popup', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
			)
		);

		$this->add_control(
			'button',
			array(
				'label'        => esc_html__( 'Show Trigger Button', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '1',
				'condition'    => array( 'popup' => '1' ),
				'description'  => esc_html__( 'Turn off to open the popup from your own link pointing at #wcdp-form.', 'styleraise' ),
			)
		);

		$this->add_control(
			'label',
			array(
				'label'       => esc_html__( 'Trigger Button Label', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Donate now!', 'styleraise' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
				'condition'   => array(
					'popup'  => '1',
					'button' => '1',
				),
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'        => esc_html__( 'Show Product Image', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => esc_html__( 'Show Product Title', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
			)
		);

		$this->add_control(
			'show_short_description',
			array(
				'label'        => esc_html__( 'Show Short Description', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => esc_html__( 'Show Full Description', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
			)
		);

		$this->add_control(
			'modern_header',
			array(
				'label'        => esc_html__( 'Modern Step Header', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'condition'    => array( 'style' => array( '1' ) ),
				'description'  => esc_html__( 'Rebuilds the step header with CSS grid instead of floats and a fixed height. Same design, but it no longer collapses inside flex containers. Enable this if the header looks misaligned.', 'styleraise' ),
			)
		);

		$this->add_control(
			'concurrency_notice',
			array(
				'type'      => Controls_Manager::RAW_HTML,
				'raw'       => '<div class="elementor-control-field-description">' . esc_html__( 'WCDP allows one donation form per page, and requires the classic [woocommerce_checkout] — the WooCommerce checkout block is unsupported upstream.', 'styleraise' ) . '</div>',
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Colors.
	 *
	 * The highest-leverage section in the widget: WCDP drives nearly its whole
	 * appearance from these custom properties, and redeclaring them on the
	 * widget root replaces the site-wide palette for this instance only.
	 */
	private function register_palette_style(): void {
		$this->open_style_section( 'section_palette', esc_html__( 'Colors', 'styleraise' ) );

		$root = $this->root();

		// Grouped under headings so every label stays short: a two-line label
		// pushes the colour picker out of the panel at its minimum width.
		$variables = array(
			array(
				'heading' => esc_html__( 'Brand', 'styleraise' ),
			),
			array(
				'key'      => 'primary',
				'label'    => esc_html__( 'Primary', 'styleraise' ),
				'variable' => '--wcdp-main-2',
				'global'   => Global_Colors::COLOR_PRIMARY,
				'note'     => esc_html__( 'Active step, buttons and the progress fill.', 'styleraise' ),
			),
			array(
				'key'      => 'secondary',
				'label'    => esc_html__( 'Secondary', 'styleraise' ),
				'variable' => '--wcdp-main',
				'global'   => Global_Colors::COLOR_SECONDARY,
				'note'     => esc_html__( 'Inactive step and the progress track.', 'styleraise' ),
			),
			array(
				'key'      => 'error',
				'label'    => esc_html__( 'Error', 'styleraise' ),
				'variable' => '--wcdp-main-3',
			),
			array(
				'heading' => esc_html__( 'Step Markers', 'styleraise' ),
			),
			array(
				'key'      => 'step_2',
				'label'    => esc_html__( 'Step 2', 'styleraise' ),
				'variable' => '--wcdp-step-2',
			),
			array(
				'key'      => 'step_3',
				'label'    => esc_html__( 'Step 3', 'styleraise' ),
				'variable' => '--wcdp-step-3',
			),
			array(
				'heading' => esc_html__( 'Amount Buttons', 'styleraise' ),
			),
			array(
				'key'      => 'label_inactive',
				'label'    => esc_html__( 'Background', 'styleraise' ),
				'variable' => '--wcdp-label-inactive',
			),
			array(
				'key'      => 'label_hover',
				'label'    => esc_html__( 'Hover', 'styleraise' ),
				'variable' => '--wcdp-label-inactive-hover',
			),
			array(
				'key'      => 'label_text',
				'label'    => esc_html__( 'Text', 'styleraise' ),
				'variable' => '--wcdp-label-text',
			),
			array(
				'key'      => 'label_text_on',
				'label'    => esc_html__( 'Selected Text', 'styleraise' ),
				'variable' => '--wcdp-label-text-checked',
				'note'     => esc_html__( 'Also used for any text sitting on the primary colour.', 'styleraise' ),
			),
			array(
				'heading' => esc_html__( 'Fields & Popup', 'styleraise' ),
			),
			array(
				'key'      => 'controls',
				'label'    => esc_html__( 'Input Text', 'styleraise' ),
				'variable' => '--wcdp-controls',
			),
			array(
				'key'      => 'popup_bg',
				'label'    => esc_html__( 'Popup Background', 'styleraise' ),
				'variable' => '--wcdp-background-color',
			),
			array(
				'key'      => 'overlay',
				'label'    => esc_html__( 'Overlay', 'styleraise' ),
				'variable' => '--wcdp-overlay-color',
				'alpha'    => true,
			),
		);

		foreach ( $variables as $index => $config ) {
			if ( isset( $config['heading'] ) ) {
				$this->add_control(
					'color_heading_' . $index,
					array(
						'label'     => $config['heading'],
						'type'      => Controls_Manager::HEADING,
						'separator' => 'before',
					)
				);

				continue;
			}

			$control = array(
				'label'     => $config['label'],
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $root => $config['variable'] . ': {{VALUE}};' ),
			);

			if ( isset( $config['global'] ) ) {
				$control['global'] = array( 'default' => $config['global'] );
			}

			if ( ! empty( $config['alpha'] ) ) {
				$control['alpha'] = true;
			}

			if ( isset( $config['note'] ) ) {
				$control['description'] = $config['note'];
			}

			$this->add_control( 'color_' . $config['key'], $control );
		}

		$this->end_controls_section();
	}

	/**
	 * Style → Form Box.
	 */
	private function register_box_style(): void {
		$this->open_style_section( 'section_box', esc_html__( 'Form Box', 'styleraise' ) );

		$form = '{{WRAPPER}} .wcdp-form, body #wcdp-form .wcdp-form';

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'form_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $form,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_border',
				'selector' => $form,
			)
		);

		$this->add_border_radius( 'form_radius', $form );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_box_shadow',
				'selector' => $form,
			)
		);

		$this->add_padding( 'form_padding', $form );

		$this->add_slider(
			'form_max_width',
			esc_html__( 'Maximum Width', 'styleraise' ),
			$form,
			'max-width: {{SIZE}}{{UNIT}}; margin-inline: auto;',
			array(
				'size_units' => array( 'px', '%', 'em' ),
				'range'      => array( 'px' => array( 'max' => 1200 ) ),
			)
		);

		$this->add_slider(
			'row_spacing',
			esc_html__( 'Spacing Between Rows', 'styleraise' ),
			$this->sel( '.wcdp-row' ),
			'margin-bottom: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'max' => 80 ) ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Step Header.
	 */
	private function register_header_style(): void {
		$this->open_style_section(
			'section_header',
			esc_html__( 'Step Header', 'styleraise' ),
			array( 'condition' => array( 'style' => array( '1', '5', '8' ) ) )
		);

		$root = $this->root();

		/* ── Layout 1: circles and progress line ── */

		$this->add_typography( 'header_typography', $this->sel( '.wcdp-header > .wcdp-step' ), array( 'condition' => array( 'style' => '1' ) ) );

		$this->add_slider(
			'header_circle_size',
			esc_html__( 'Step Circle Size', 'styleraise' ),
			$root,
			'--wcdp-el-step-circle-size: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'em', 'px', 'rem' ),
				'range'      => array( 'em' => array( 'max' => 6 ) ),
				'condition'  => array( 'style' => '1' ),
			)
		);

		$this->add_slider(
			'header_line_height',
			esc_html__( 'Progress Line Thickness', 'styleraise' ),
			$root,
			'--wcdp-el-step-line-height: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 30 ) ),
				'condition'  => array(
					'style'         => '1',
					'modern_header' => 'yes',
				),
			)
		);

		$this->add_margin( 'header_margin', $this->sel( '.wcdp-header' ), array( 'condition' => array( 'style' => '1' ) ) );

		/* ── Layouts 5 and 8: chevron bar (8 reuses 5's markup, two steps) ── */

		$banner = array( 'style' => array( '5', '8' ) );

		$this->add_control(
			'header5_heading',
			array(
				'label'     => esc_html__( 'Banner Header', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $banner,
			)
		);

		$this->add_typography( 'header5_typography', $this->sel( '.wcdp-style5' ), array( 'condition' => $banner ) );

		// Layout 8 lets its two labels wrap (WCDP: `height: auto; min-height: 2em`),
		// so there the same value is a minimum. The second, more specific rule
		// matches only layout 8's wrapper and leaves layout 5's CSS unchanged.
		// add_slider() merges `$args` last, so `$args['selectors']` replaces the
		// positional selector and template below (kept for the helper signature).
		$this->add_slider(
			'header5_height',
			esc_html__( 'Height', 'styleraise' ),
			$this->sel( '.wcdp-style5' ),
			'height: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'em', 'px', 'rem' ),
				'range'      => array( 'em' => array( 'max' => 8 ) ),
				'condition'  => $banner,
				'selectors'  => array(
					$this->sel( '.wcdp-style5' )                       => 'height: {{SIZE}}{{UNIT}};',
					$this->sel( '.wcdp-steps-wrapper-2 .wcdp-style5' ) => 'height: auto; min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_margin( 'header5_margin', $this->sel( '.wcdp-steps-wrapper' ), array( 'condition' => $banner ) );

		$this->end_controls_section();
	}

	/**
	 * Style → Amount Buttons.
	 */
	private function register_amount_options_style(): void {
		$this->open_style_section( 'section_amount_options', esc_html__( 'Amount Buttons', 'styleraise' ) );

		$list  = $this->sel( 'ul.wcdp_options' );
		$label = $this->sel( 'ul.wcdp_options > li > label' );

		$this->add_responsive_control(
			'options_columns',
			array(
				'label'       => esc_html__( 'Columns', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 6,
				'default'     => '',
				'placeholder' => esc_html__( 'Auto', 'styleraise' ),
				'selectors'   => array(
					$list => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				),
				'description' => esc_html__( 'Set this per device — three amount buttons rarely fit on a phone.', 'styleraise' ),
			)
		);

		$this->add_slider(
			'options_gap',
			esc_html__( 'Gap', 'styleraise' ),
			$list,
			'gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'max' => 60 ) ),
			)
		);

		$this->add_typography( 'options_typography', $label );

		$this->add_padding( 'options_padding', $label );

		$this->add_border_radius( 'options_radius', $label );

		$this->start_controls_tabs( 'options_tabs' );

		$this->start_controls_tab( 'options_tab_normal', array( 'label' => esc_html__( 'Normal', 'styleraise' ) ) );

		$this->add_color( 'options_color', $label, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'options_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $label,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'options_border',
				'selector' => $label,
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'options_tab_hover', array( 'label' => esc_html__( 'Hover', 'styleraise' ) ) );

		$hover = $this->sel( 'ul.wcdp_options > li > input:hover:not(:disabled):not(:checked) + label' );

		$this->add_color( 'options_color_hover', $hover, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'options_background_hover',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $hover,
			)
		);

		$this->add_control(
			'options_border_color_hover',
			array(
				'label'     => esc_html__( 'Border Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $hover => 'border-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'options_tab_selected', array( 'label' => esc_html__( 'Selected', 'styleraise' ) ) );

		// WCDP paints both the label and any span inside it when checked, so
		// the override has to reach both or the amount text keeps the old colour.
		$checked = $this->sel( 'ul.wcdp_options > li > input:checked + label' )
			. ', ' . $this->sel( 'ul.wcdp_options > li > input:checked + label span' );

		$this->add_color( 'options_color_selected', $checked, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'options_background_selected',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $this->sel( 'ul.wcdp_options > li > input:checked + label' ),
			)
		);

		$this->add_control(
			'options_border_color_selected',
			array(
				'label'     => esc_html__( 'Border Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $this->sel( 'ul.wcdp_options > li > input:checked + label' ) => 'border-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'options_hide_checkmark',
			array(
				'label'        => esc_html__( 'Hide Checkmark', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array(
					$this->sel( '.wcdp_options > li > input:checked + label:after' ) => 'content: none;',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'      => 'options_box_shadow',
				'selector'  => $label,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'options_focus_color',
			array(
				'label'       => esc_html__( 'Keyboard Focus Ring', 'styleraise' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					$this->sel( 'ul.wcdp_options > li > input:focus-visible + label' ) => 'box-shadow: 0 0 0 2px #fff, 0 0 0 4px {{VALUE}};',
				),
				'description' => esc_html__( 'Keep this visible — it is how keyboard users see which amount is focused.', 'styleraise' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Amount Input.
	 */
	private function register_amount_input_style(): void {
		$this->open_style_section( 'section_amount_input', esc_html__( 'Amount Input', 'styleraise' ) );

		$input = $this->sel( 'input.wcdp-donation-amount' );

		$this->add_typography( 'amount_input_typography', $input );

		$this->add_color( 'amount_input_color', $input, array( 'global_default' => null ) );

		$this->add_control(
			'amount_input_background',
			array(
				'label'     => esc_html__( 'Background', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $input => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'amount_input_border',
				'selector' => $input,
			)
		);

		$this->add_border_radius( 'amount_input_radius', $input );

		$this->add_padding( 'amount_input_padding', $input );

		$this->add_slider(
			'amount_input_width',
			esc_html__( 'Width', 'styleraise' ),
			$this->sel( '.wcdp-amount > input.wcdp-donation-amount' ),
			'width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'ch', 'px', '%' ),
				'range'      => array( 'ch' => array( 'max' => 30 ) ),
			)
		);

		$this->add_control(
			'amount_input_focus_color',
			array(
				'label'     => esc_html__( 'Focus Border Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array( $this->sel( 'input.wcdp-donation-amount:focus' ) => 'border-color: {{VALUE}}; outline-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'currency_symbol_heading',
			array(
				'label'     => esc_html__( 'Currency Symbol', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$symbol = $this->sel( '.wcdp-amount .woocommerce-Price-currencySymbol' );

		$this->add_typography( 'currency_symbol_typography', $symbol );

		$this->add_color( 'currency_symbol_color', $symbol, array( 'global_default' => null ) );

		$this->end_controls_section();
	}

	/**
	 * Style → Amount Slider.
	 */
	private function register_range_style(): void {
		$this->open_style_section( 'section_range', esc_html__( 'Amount Slider', 'styleraise' ) );

		$root = $this->root();

		$this->add_control(
			'range_notice',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => '<div class="elementor-control-field-description">' . esc_html__( 'Applies when the product\'s amount layout is set to "Input box with range slider" in WCDP.', 'styleraise' ) . '</div>',
			)
		);

		$this->add_control(
			'range_thumb_color',
			array(
				'label'     => esc_html__( 'Handle Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => Global_Colors::COLOR_PRIMARY ),
				'selectors' => array( $root => '--wcdp-el-range-thumb-color: {{VALUE}};' ),
			)
		);

		$this->add_slider(
			'range_thumb_size',
			esc_html__( 'Handle Size', 'styleraise' ),
			$root,
			'--wcdp-el-range-thumb-size: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 48 ) ),
			)
		);

		$this->add_control(
			'range_track_color',
			array(
				'label'     => esc_html__( 'Track Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $root => '--wcdp-el-range-track-color: {{VALUE}};' ),
			)
		);

		$this->add_slider(
			'range_track_height',
			esc_html__( 'Track Thickness', 'styleraise' ),
			$root,
			'--wcdp-el-range-track-height: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 30 ) ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Labels & Headings.
	 */
	private function register_labels_style(): void {
		$this->open_style_section( 'section_labels', esc_html__( 'Labels & Headings', 'styleraise' ) );

		$heading = $this->sel( '.wcdp-variation-heading' ) . ', ' . $this->sel( '.wcdp-row > label' );

		$this->add_typography( 'labels_typography', $heading );

		$this->add_color( 'labels_color', $heading );

		$this->add_margin( 'labels_margin', $heading );

		$this->add_control(
			'required_heading',
			array(
				'label'     => esc_html__( 'Required Marker', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_color(
			'required_color',
			$this->sel( 'abbr.required' ),
			array(
				'label'          => esc_html__( 'Color', 'styleraise' ),
				'global_default' => null,
			)
		);

		$this->add_control(
			'required_hide',
			array(
				'label'        => esc_html__( 'Hide Asterisk', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( $this->sel( 'abbr.required' ) => 'display: none;' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Buttons.
	 */
	private function register_buttons_style(): void {
		$this->open_style_section( 'section_buttons', esc_html__( 'Buttons', 'styleraise' ) );

		// Inside the form and, separately, the popup trigger which lives in the
		// widget wrapper even when the form itself is in the footer. Layout 6's
		// back arrow is a <button> too, but WCDP draws it as a bare 36×36 icon:
		// it is left out, and `:where()` keeps the selector's specificity exactly
		// as it was before the exclusion, so saved sites cascade the same.
		$plain   = 'button:where(:not(.wcdp-style-6-back))';
		$button  = $this->sel( $plain ) . ', ' . $this->sel( '.wcdp-button' );
		$button .= ', {{WRAPPER}} .wcdp-modal-open';

		$this->add_typography( 'buttons_typography', $button );

		$this->start_controls_tabs( 'buttons_tabs' );

		$this->start_controls_tab( 'buttons_tab_normal', array( 'label' => esc_html__( 'Normal', 'styleraise' ) ) );

		$this->add_color( 'buttons_color', $button, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'buttons_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $button,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'buttons_border',
				'selector' => $button,
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'buttons_tab_hover', array( 'label' => esc_html__( 'Hover', 'styleraise' ) ) );

		$button_hover  = $this->sel( $plain . ':hover' ) . ', ' . $this->sel( '.wcdp-button:hover' );
		$button_hover .= ', {{WRAPPER}} .wcdp-modal-open:hover';
		$button_hover .= ', ' . $this->sel( $plain . ':focus-visible' ) . ', {{WRAPPER}} .wcdp-modal-open:focus-visible';

		$this->add_color( 'buttons_color_hover', $button_hover, array( 'global_default' => null ) );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'buttons_background_hover',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $button_hover,
			)
		);

		$this->add_control(
			'buttons_transition',
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

		$this->add_padding( 'buttons_padding', $button, array( 'separator' => 'before' ) );

		$this->add_border_radius( 'buttons_radius', $button );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'buttons_box_shadow',
				'selector' => $button,
			)
		);

		$this->add_slider(
			'buttons_row_gap',
			esc_html__( 'Gap in Button Row', 'styleraise' ),
			$this->root(),
			'--wcdp-el-button-row-gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 60 ) ),
			)
		);

		$this->add_control(
			'buttons_hide_arrows',
			array(
				'label'        => esc_html__( 'Hide Arrows', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( $this->sel( '.wcdp-arrow' ) => 'display: none;' ),
			)
		);

		$this->add_responsive_control(
			'trigger_align',
			array(
				'label'     => esc_html__( 'Trigger Button Alignment', 'styleraise' ),
				'type'      => Controls_Manager::CHOOSE,
				'separator' => 'before',
				'options'   => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'styleraise' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'styleraise' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'styleraise' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .wcdp-el-form-trigger' => 'text-align: {{VALUE}};',
				),
				'condition' => array(
					'popup'  => '1',
					'button' => '1',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Checkout Fields.
	 */
	private function register_fields_style(): void {
		$this->open_style_section( 'section_fields', esc_html__( 'Checkout Fields', 'styleraise' ) );

		$field  = $this->sel( '.woocommerce-input-wrapper input' );
		$field .= ', ' . $this->sel( '.woocommerce-input-wrapper textarea' );
		$field .= ', ' . $this->sel( '.woocommerce-input-wrapper select' );
		$field .= ', ' . $this->sel( '.select2-selection' );

		$label = $this->sel( '.form-row > label' );

		$this->add_typography( 'fields_label_typography', $label );

		$this->add_color( 'fields_label_color', $label );

		$this->add_control(
			'fields_input_heading',
			array(
				'label'     => esc_html__( 'Inputs', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_typography( 'fields_typography', $field );

		$this->add_color( 'fields_color', $field, array( 'global_default' => null ) );

		$this->add_control(
			'fields_background',
			array(
				'label'     => esc_html__( 'Background', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $field => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'fields_border',
				'selector' => $field,
			)
		);

		$this->add_border_radius( 'fields_radius', $field );

		$this->add_padding( 'fields_padding', $field );

		$this->add_control(
			'fields_focus_color',
			array(
				'label'     => esc_html__( 'Focus Border Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					$this->sel( '.woocommerce-input-wrapper input:focus' )      => 'border-color: {{VALUE}};',
					$this->sel( '.woocommerce-input-wrapper textarea:focus' )   => 'border-color: {{VALUE}};',
					$this->sel( '.woocommerce-input-wrapper select:focus' )     => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'fields_columns',
			array(
				'label'       => esc_html__( 'Field Columns', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 3,
				'default'     => '',
				'separator'   => 'before',
				'placeholder' => esc_html__( 'Theme default', 'styleraise' ),
				'selectors'   => array(
					$this->sel( '#wcdp-step-2 .woocommerce-billing-fields__field-wrapper' ) => 'display: grid; grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr)); gap: var(--wcdp-el-field-gap, 12px);',
				),
				'description' => esc_html__( 'Lay the billing fields out in a grid. Set 1 on mobile.', 'styleraise' ),
			)
		);

		$this->add_slider(
			'fields_gap',
			esc_html__( 'Column Gap', 'styleraise' ),
			$this->root(),
			'--wcdp-el-field-gap: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 60 ) ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Fee Recovery.
	 */
	private function register_fee_recovery_style(): void {
		$this->open_style_section( 'section_fee_recovery', esc_html__( 'Fee Recovery Box', 'styleraise' ) );

		$body     = $this->sel( '.wcdp-fee-recovery__body' );
		$checkbox = $this->sel( '.wcdp-fee-recovery__body-cover-checkbox' );

		$this->add_control(
			'fee_notice',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => '<div class="elementor-control-field-description">' . esc_html__( 'Applies when WCDP fee recovery is enabled.', 'styleraise' ) . '</div>',
			)
		);

		$this->add_typography( 'fee_typography', $body );

		$this->add_color( 'fee_color', $body );

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'fee_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => $body,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'fee_border',
				'selector' => $body,
			)
		);

		$this->add_border_radius( 'fee_radius', $body );

		$this->add_control(
			'fee_checkbox_color',
			array(
				'label'     => esc_html__( 'Checkbox Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					$checkbox                                                       => 'border-color: {{VALUE}};',
					$this->sel( '.wcdp-fee-recovery__body-cover-checkbox--svg' )     => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Popup.
	 */
	private function register_popup_style(): void {
		$this->open_style_section(
			'section_popup',
			esc_html__( 'Popup', 'styleraise' ),
			array( 'condition' => array( 'popup' => '1' ) )
		);

		$popup = $this->popup_sel( '#wcdp-popup' );

		$this->add_control(
			'popup_notice',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => '<div class="elementor-control-field-description">' . esc_html__( 'WCDP prints the popup in the page footer, outside this widget. These rules therefore target the popup by id — safe, because WCDP allows one form per page.', 'styleraise' ) . '</div>',
			)
		);

		$this->add_slider(
			'popup_width',
			esc_html__( 'Width', 'styleraise' ),
			$popup,
			'max-width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', '%', 'vw' ),
				'range'      => array( 'px' => array( 'max' => 1400 ) ),
			)
		);

		$this->add_slider(
			'popup_offset',
			esc_html__( 'Top Offset', 'styleraise' ),
			$popup,
			'margin-top: {{SIZE}}{{UNIT}}; margin-bottom: {{SIZE}}{{UNIT}};',
			array( 'size_units' => array( 'vh', 'px', 'em' ) )
		);

		$this->add_padding( 'popup_padding', $popup );

		$this->add_border_radius( 'popup_radius', $popup );

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'popup_box_shadow',
				'selector' => $popup,
			)
		);

		$this->add_slider(
			'popup_overlay_blur',
			esc_html__( 'Overlay Blur', 'styleraise' ),
			$this->popup_sel(),
			'backdrop-filter: blur({{SIZE}}{{UNIT}});',
			array(
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 30 ) ),
				'separator'  => 'before',
			)
		);

		$this->add_control(
			'popup_close_heading',
			array(
				'label'     => esc_html__( 'Close Button', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'popup_close_color',
			array(
				'label'     => esc_html__( 'Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $this->popup_sel( '.wcdp-close' ) => 'stroke: {{VALUE}};' ),
			)
		);

		$this->add_slider(
			'popup_close_size',
			esc_html__( 'Size', 'styleraise' ),
			$this->popup_sel( '.wcdp-close' ),
			'width: {{SIZE}}{{UNIT}};',
			array(
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'max' => 80 ) ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Product Media & Text.
	 */
	private function register_media_style(): void {
		$this->open_style_section(
			'section_media',
			esc_html__( 'Product Media & Text', 'styleraise' ),
			array(
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => 'show_image',
							'operator' => '===',
							'value'    => '1',
						),
						array(
							'name'     => 'show_title',
							'operator' => '===',
							'value'    => '1',
						),
						array(
							'name'     => 'show_description',
							'operator' => '===',
							'value'    => '1',
						),
						array(
							'name'     => 'show_short_description',
							'operator' => '===',
							'value'    => '1',
						),
					),
				),
			)
		);

		$image = $this->sel( '.wcdp-product-image img' );

		$this->add_slider(
			'media_image_width',
			esc_html__( 'Image Width', 'styleraise' ),
			$image,
			'width: {{SIZE}}{{UNIT}}; height: auto;',
			array(
				'size_units' => array( '%', 'px' ),
				'condition'  => array( 'show_image' => '1' ),
			)
		);

		$this->add_border_radius( 'media_image_radius', $image, array( 'condition' => array( 'show_image' => '1' ) ) );

		$this->add_margin( 'media_image_margin', $this->sel( '.wcdp-product-image' ), array( 'condition' => array( 'show_image' => '1' ) ) );

		$this->add_control(
			'media_title_heading',
			array(
				'label'     => esc_html__( 'Title', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'show_title' => '1' ),
			)
		);

		$this->add_typography( 'media_title_typography', $this->sel( '.wcdp-title' ), array( 'condition' => array( 'show_title' => '1' ) ) );

		$this->add_color( 'media_title_color', $this->sel( '.wcdp-title' ), array( 'condition' => array( 'show_title' => '1' ) ) );

		$this->add_control(
			'media_description_heading',
			array(
				'label'      => esc_html__( 'Description', 'styleraise' ),
				'type'       => Controls_Manager::HEADING,
				'separator'  => 'before',
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => 'show_description',
							'operator' => '===',
							'value'    => '1',
						),
						array(
							'name'     => 'show_short_description',
							'operator' => '===',
							'value'    => '1',
						),
					),
				),
			)
		);

		$description = $this->sel( '.wcdp-description' ) . ', ' . $this->sel( '.wcdp-short-description' );

		$this->add_typography( 'media_description_typography', $description );

		$this->add_color( 'media_description_color', $description );

		$this->end_controls_section();
	}

	/**
	 * Style → Notices, Loader & Branding.
	 */
	private function register_misc_style(): void {
		$this->open_style_section( 'section_misc', esc_html__( 'Notices, Loader & Branding', 'styleraise' ) );

		$notice = $this->sel( '.woocommerce-error' ) . ', ' . $this->sel( '.woocommerce-info' ) . ', ' . $this->sel( '.wcdp-error-message' );

		$this->add_typography( 'notice_typography', $notice );

		$this->add_color( 'notice_color', $notice, array( 'global_default' => null ) );

		$this->add_control(
			'notice_background',
			array(
				'label'     => esc_html__( 'Notice Background', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $notice => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_border_radius( 'notice_radius', $notice );

		$this->add_padding( 'notice_padding', $notice );

		// WCDP's inline field messages (red text under a field, WCDP 1.5.1).
		// Kept apart from the notice box above so saved widgets keep their CSS,
		// and without global defaults: nothing is emitted until a value is set.
		$this->add_control(
			'field_messages_heading',
			array(
				'label'     => esc_html__( 'Field Messages', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$field_message = '';

		foreach ( array( '.wcdp-required-field-notice', '.wcdp-amount-notice', '.wcdp-variation-error', '.wcdp-invalid-field-notice' ) as $index => $class ) {
			$field_message .= ( $index ? ', ' : '' ) . $this->sel( $class );
		}

		$this->add_color( 'field_messages_color', $field_message, array( 'global_default' => null ) );

		// `global` replaces the helper's Global Fonts default, which would emit CSS.
		$this->add_typography( 'field_messages_typography', $field_message, array( 'global' => array() ) );

		$this->add_control(
			'loader_heading',
			array(
				'label'     => esc_html__( 'Loader', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'loader_color',
			array(
				'label'     => esc_html__( 'Dots Color', 'styleraise' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $this->sel( '.lds-ellipsis div' ) => 'background: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'branding_heading',
			array(
				'label'     => esc_html__( 'WCDP Branding', 'styleraise' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_typography( 'branding_typography', $this->sel( '.wcdp-branding' ) );

		$this->add_color( 'branding_color', $this->sel( '.wcdp-branding' ) );

		$this->add_control(
			'branding_hide',
			array(
				'label'        => esc_html__( 'Hide Branding', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( $this->sel( '.wcdp-branding' ) => 'display: none;' ),
				'description'  => esc_html__( 'Whether WCDP shows this at all is a WCDP setting; this only hides it for this instance.', 'styleraise' ),
			)
		);

		$this->end_controls_section();
	}

	/* ─────────────────────────── Render ─────────────────────────── */

	/**
	 * Render the widget.
	 */
	protected function render(): void {
		$settings   = $this->get_settings_for_display();
		$product_id = Product_Resolver::resolve( $settings );

		if ( ! $product_id || ! Product_Resolver::is_donable( $product_id ) ) {
			$this->render_placeholder( $this->get_unresolved_message() );

			return;
		}

		// Any elementor_ajax render other than Elementor's own render_widget —
		// above all save_builder, which stores every widget's output in
		// post_content — prints exactly what 3.1.x printed: WCDP builds no form
		// there, and no form with the editor's data may ever be stored.
		if ( $this->is_widget_ajax_render() && ! Form_Preview::is_widget_render_action() ) {
			$this->render_placeholder(
				esc_html__( 'WCDP cannot build a checkout form during an editor refresh. Reload the preview to see the form; Style tab changes apply live and need no reload.', 'styleraise' )
			);

			return;
		}

		// Wherever the form is built for the editor canvas — the editor
		// bootstrap with a cart (ours, or another plugin's even with the
		// preview off), or a widget refresh — it is built only for a campaign
		// the user may see there: published or readable, and without a
		// password (Form_Preview::campaign_previewable()).
		if ( Form_Preview::is_preview_render() && ! Form_Preview::campaign_previewable( $product_id ) ) {
			$this->render_placeholder(
				esc_html__( 'The editor does not build the donation form for a password-protected campaign or one you cannot view. Open the page or Preview to see what visitors get; Style tab changes apply live.', 'styleraise' )
			);

			return;
		}

		$style = (string) ( $settings['style'] ?? '1' );

		$atts = array(
			'id'    => $product_id,
			'style' => $style,
		);

		if ( ! empty( $settings['popup'] ) ) {
			$atts['popup']  = '1';
			$atts['button'] = ! empty( $settings['button'] ) ? '1' : '0';
		}

		if ( ! empty( $settings['label'] ) ) {
			$atts['label'] = (string) $settings['label'];
		}

		foreach ( array(
			'show_image'             => 'image',
			'show_title'             => 'title',
			'show_description'       => 'description',
			'show_short_description' => 'short_description',
		) as $control => $attribute ) {
			if ( ! empty( $settings[ $control ] ) ) {
				$atts[ $attribute ] = '1';
			}
		}

		// WCDP echoes `className` onto the form root, which is the only
		// supported way to tag markup it moves to the footer.
		if ( 'yes' === ( $settings['modern_header'] ?? '' ) ) {
			$atts['className'] = 'wcdp-el-form--modern-header';
		}

		// A layout newer than the installed WCDP is still passed on as saved:
		// WCDP renders layout 1 in its place, and the saved choice applies as
		// soon as WCDP is updated. Only the editor is told why.
		$this->render_style_hint( $style );

		// Before the shortcode runs: a popup form without a trigger button
		// returns nothing here and still prints in the footer.
		Script_Guard::mark_form_rendered();

		if ( $this->is_widget_ajax_render() ) {
			// Elementor's render_widget (the only elementor_ajax action left
			// here) re-renders a widget on every content control change, where
			// WCDP's shortcode refuses to run.
			// The editor preview builds the form there itself (the campaign was
			// checked above); without it the widget explains instead of echoing
			// WCDP's stand-in sentence.
			$html = Form_Preview::build_for_widget_refresh( $atts );

			if ( null === $html ) {
				$this->render_placeholder( $this->refresh_hint() );

				return;
			}
		} else {
			// A popup is queued for wp_footer, which on the editor bootstrap is
			// the editor shell, not the canvas: keep the form out of it.
			$editor_shell = ! empty( $atts['popup'] ) && Form_Preview::is_editor_shell_render();
			$footer       = $editor_shell ? Form_Preview::footer_snapshot() : array();

			$html = Shortcode_Runner::run( 'wcdp_donation_form', $atts );

			if ( $editor_shell ) {
				Form_Preview::drop_footer_added_since( $footer );
			}
		}

		if ( '' === trim( $html ) ) {
			// Expected when the popup renders with no trigger button: the form
			// itself was queued for the footer, which the editor canvas never
			// shows — so the editor gets a word of explanation instead of a gap.
			$this->render_placeholder(
				esc_html__( 'This popup has no button of its own: it opens from your link pointing at #wcdp-form, so there is nothing to show here. Use Preview to try it.', 'styleraise' )
			);

			return;
		}

		$is_error = false !== strpos( $html, 'wcdp-error-message' );

		$classes = array( 'wcdp-el-form-host' );

		if ( ! empty( $settings['popup'] ) && ! empty( $settings['button'] ) ) {
			$classes[] = 'wcdp-el-form-trigger';
		}

		// In the editor wcdp.js never runs on this markup, so the form would
		// stay hidden behind its spinner. Only the root's visibility changes,
		// and only before kses sees the markup.
		if ( $this->is_editor() && Form_Preview::is_enabled() ) {
			$html = Form_Preview::reveal( $html );
		}

		printf( '<div class="%s">', esc_attr( implode( ' ', $classes ) ) );
		echo wp_kses( $html, Kses::form() );
		echo '</div>';

		// WCDP's own messages already name their cause (one form per page,
		// donations not enabled, product unavailable). The one that needs a
		// word of explanation is "not available in the current view": WCDP
		// prints it when WooCommerce has no cart, which is the normal state of
		// the Elementor editor — not a fault of the page.
		if ( $is_error && $this->is_editor() && function_exists( 'WC' ) && ! isset( WC()->cart ) ) {
			printf(
				'<div class="wcdp-el-placeholder wcdp-el-placeholder--hint"><span class="wcdp-el-placeholder__text">%s</span></div>',
				esc_html__( 'The Elementor editor has no WooCommerce cart, so WCDP cannot build the form here. Open the page or Preview to see it with the styles set in this widget.', 'styleraise' )
			);
		}
	}

	/**
	 * Editor-only hint for a saved layout the installed WCDP cannot render.
	 *
	 * @param string $style Saved layout key.
	 */
	private function render_style_hint( string $style ): void {
		if ( ! isset( self::STYLE_MIN_WCDP[ $style ] ) || ! $this->is_editor() ) {
			return;
		}

		$version = self::wcdp_version();

		if ( isset( self::available_styles( $version )[ $style ] ) ) {
			return;
		}

		printf(
			'<div class="wcdp-el-placeholder wcdp-el-placeholder--hint"><span class="wcdp-el-placeholder__text">%s</span></div>',
			esc_html(
				sprintf(
					/* translators: 1: form layout number, 2: minimum Donation Platform for WooCommerce version, 3: installed version. */
					__( 'Layout %1$s needs Donation Platform for WooCommerce %2$s or newer (installed: %3$s), so WCDP shows layout 1 instead. Update WCDP to use it.', 'styleraise' ),
					$style,
					self::STYLE_MIN_WCDP[ $style ],
					$version
				)
			)
		);
	}

	/**
	 * Hint for a widget refresh that could not build the form.
	 *
	 * With the editor preview on and supported, reopening the editor shows the
	 * form (its bootstrap builds it); otherwise the editor never shows it, and
	 * the page or Preview does.
	 *
	 * @return string Escaped text.
	 */
	private function refresh_hint(): string {
		if ( Form_Preview::is_enabled() && Form_Preview::session_swap_supported() ) {
			return esc_html__( 'WCDP cannot build a checkout form during this editor refresh. Close and reopen the editor to see the form; Style tab changes apply live.', 'styleraise' );
		}

		return esc_html__( 'WCDP cannot build a checkout form in the Elementor editor. Open the page or Preview to see it with the styles set in this widget; Style tab changes apply live.', 'styleraise' );
	}

	/**
	 * Whether this render is Elementor refreshing a single widget over AJAX.
	 *
	 * @return bool
	 */
	private function is_widget_ajax_render(): bool {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context probe; Elementor verifies its own nonce.

		return 'elementor_ajax' === $action;
	}
}
