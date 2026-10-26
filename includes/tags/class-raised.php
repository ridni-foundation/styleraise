<?php
/**
 * Dynamic tag: amount raised.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Tags_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Total raised for a campaign.
 */
class Raised extends Tag_Base {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-raised';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Amount Raised', 'styleraise' );
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
		$this->register_amount_controls();
		$this->register_live_control();
	}

	/**
	 * Output the value.
	 */
	public function render(): void {
		$campaign = $this->get_campaign();

		if ( ! $campaign ) {
			return;
		}

		$this->print_value( $this->format_amount( $campaign->get_raised(), $campaign, 'raised' ), 'raised', $campaign );
	}
}
