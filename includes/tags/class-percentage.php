<?php
/**
 * Dynamic tag: percentage raised.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Modules\DynamicTags\Module as Tags_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Percentage of the goal raised.
 */
class Percentage extends Tag_Base {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-percentage';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Percentage Raised', 'styleraise' );
	}

	/**
	 * Usable in text and numeric controls.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( Tags_Module::TEXT_CATEGORY, Tags_Module::NUMBER_CATEGORY );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->register_source_controls();
		$this->register_live_control();

		$this->add_control(
			'decimals',
			array(
				'label'     => esc_html__( 'Decimals', 'styleraise' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'max'       => 4,
				'default'   => 0,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_symbol',
			array(
				'label'        => esc_html__( 'Include % Symbol', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Turn off when the tag feeds a numeric control.', 'styleraise' ),
			)
		);

		$this->add_control(
			'cap_at_100',
			array(
				'label'        => esc_html__( 'Cap at 100%', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Overfunded campaigns otherwise report their real figure, such as 137%.', 'styleraise' ),
			)
		);
	}

	/**
	 * Output the value.
	 */
	public function render(): void {
		$campaign = $this->get_campaign();

		if ( ! $campaign || ! $campaign->has_goal() ) {
			return;
		}

		$decimals = is_numeric( $this->get_settings( 'decimals' ) ) ? max( 0, (int) $this->get_settings( 'decimals' ) ) : 0;

		if ( 'yes' === $this->get_settings( 'show_symbol' ) ) {
			$capped = 'yes' === $this->get_settings( 'cap_at_100' );

			$value = $capped
				? number_format_i18n( $campaign->get_fill_percentage(), $decimals ) . '%'
				: $campaign->format_percentage( $decimals );

			// Only the uncapped figure is what the refresh endpoint returns;
			// capping happens here and would be undone by a refresh.
			$this->print_value( esc_html( $value ), 'percentage', $campaign, ! $capped );

			return;
		}

		$percentage = 'yes' === $this->get_settings( 'cap_at_100' )
			? $campaign->get_fill_percentage()
			: $campaign->get_percentage();

		echo esc_html( number_format( $percentage, $decimals, '.', '' ) );
	}
}
