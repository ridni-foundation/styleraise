<?php
/**
 * Dynamic tag: campaign deadline.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign end date, as a phrase or a formatted date.
 */
class End_Date extends Tag_Base {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-end-date';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Campaign Deadline', 'styleraise' );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls(): void {
		$this->register_source_controls();
		$this->register_live_control();

		$this->add_control(
			'output',
			array(
				'label'     => esc_html__( 'Output', 'styleraise' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'human',
				'separator' => 'before',
				'options'   => array(
					'human'  => esc_html__( 'Time remaining ("3 weeks to go")', 'styleraise' ),
					'diff'   => esc_html__( 'Time remaining, bare ("3 weeks")', 'styleraise' ),
					'date'   => esc_html__( 'Formatted date', 'styleraise' ),
					'days'   => esc_html__( 'Whole days remaining', 'styleraise' ),
				),
			)
		);

		$this->add_control(
			'date_format',
			array(
				'label'       => esc_html__( 'Date Format', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Site default', 'styleraise' ),
				'description' => esc_html__( 'PHP date format, for example j F Y.', 'styleraise' ),
				'condition'   => array( 'output' => 'date' ),
			)
		);

		$this->add_control(
			'ended_text',
			array(
				'label'       => esc_html__( 'Text When Ended', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'placeholder' => esc_html__( 'Campaign has ended', 'styleraise' ),
				'description' => esc_html__( 'Shown instead of a negative countdown. Leave empty to keep WCDP\'s "ended X ago" phrasing.', 'styleraise' ),
				'condition'   => array( 'output' => array( 'human', 'diff', 'days' ) ),
			)
		);
	}

	/**
	 * Output the value.
	 */
	public function render(): void {
		$campaign = $this->get_campaign();

		if ( ! $campaign || ! $campaign->has_end_date() ) {
			return;
		}

		$timestamp = $campaign->get_end_timestamp();

		if ( ! $timestamp ) {
			return;
		}

		$output     = (string) $this->get_settings( 'output' );
		$ended_text = trim( (string) $this->get_settings( 'ended_text' ) );

		if ( $campaign->is_ended() && '' !== $ended_text && 'date' !== $output ) {
			echo esc_html( $ended_text );

			return;
		}

		switch ( $output ) {
			case 'diff':
				echo esc_html( human_time_diff( $timestamp ) );
				break;

			case 'date':
				$format = trim( (string) $this->get_settings( 'date_format' ) );
				echo esc_html( wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), $timestamp ) );
				break;

			case 'days':
				$days = (int) floor( ( $timestamp - time() ) / DAY_IN_SECONDS );
				echo esc_html( (string) max( 0, $days ) );
				break;

			case 'human':
			default:
				// Only this phrasing is what the refresh endpoint reproduces;
				// the other outputs are computed here and would be overwritten.
				$this->print_value( wp_kses_post( $campaign->format_time_diff() ), 'timeleft', $campaign );
				break;
		}
	}
}
