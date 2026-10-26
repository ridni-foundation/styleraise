<?php
/**
 * Dynamic tag: fundraising goal.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Tags_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fundraising goal of a campaign.
 */
class Goal extends Tag_Base {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-goal';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Fundraising Goal', 'styleraise' );
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

		if ( ! $campaign || ! $campaign->has_goal() ) {
			return;
		}

		$this->print_value( $this->format_amount( $campaign->get_goal(), $campaign, 'goal' ), 'goal', $campaign );
	}
}
