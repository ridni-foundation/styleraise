<?php
/**
 * Dynamic tag: donation count.
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
 * Number of completed donations for a campaign.
 */
class Order_Count extends Tag_Base {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-order-count';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Donation Count', 'styleraise' );
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
			'cheat',
			array(
				'label'       => esc_html__( 'Count Correction', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'separator'   => 'before',
				'description' => esc_html__( 'Added to the real count, for example to include offline donations. Can be negative.', 'styleraise' ),
			)
		);

		$this->add_control(
			'localize',
			array(
				'label'        => esc_html__( 'Thousands Separator', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Turn off when the tag feeds a numeric control.', 'styleraise' ),
			)
		);
	}

	/**
	 * Output the value.
	 */
	public function render(): void {
		$campaign = $this->get_campaign();

		if ( ! $campaign ) {
			return;
		}

		$cheat = is_numeric( $this->get_settings( 'cheat' ) ) ? (int) $this->get_settings( 'cheat' ) : 0;
		$count = max( 0, $campaign->get_order_count() + $cheat );

		$localized = 'yes' === $this->get_settings( 'localize' );

		// The un-localised form exists to feed numeric controls, where a span
		// would break the value.
		$this->print_value(
			esc_html( $localized ? number_format_i18n( $count ) : (string) $count ),
			'count',
			$campaign,
			$localized
		);
	}
}
