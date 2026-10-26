<?php
/**
 * Dynamic tag: product ID from post meta.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as Tags_Module;
use WCDP_Elementor\Data\Product_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a WooCommerce product ID out of a custom field on the current post.
 *
 * Kept from 1.0.x with its original tag name and control name so existing
 * pages keep resolving. It extends Elementor's `Tag` directly rather than
 * `Tag_Base`, because it resolves an ID rather than campaign figures.
 */
class Product_Id extends Tag {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wcdp-product-id';
	}

	/**
	 * Tag title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Product ID (post meta)', 'styleraise' );
	}

	/**
	 * Dynamic tag group.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'wcdp';
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
		$this->add_control(
			'meta_key',
			array(
				'label'       => esc_html__( 'Meta Key', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'placeholder' => 'product_id',
				'description' => esc_html__( 'Custom field on the current post that stores the WooCommerce product ID.', 'styleraise' ),
			)
		);

		$this->add_control(
			'resolve_parent',
			array(
				'label'        => esc_html__( 'Resolve Variations', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'When the field holds a variation ID, return its parent product — where WCDP keeps the campaign settings.', 'styleraise' ),
			)
		);
	}

	/**
	 * Output the value.
	 */
	public function render(): void {
		$meta_key = trim( (string) $this->get_settings( 'meta_key' ) );

		if ( '' === $meta_key ) {
			return;
		}

		$post_id = Product_Resolver::context_post_id();

		if ( ! $post_id ) {
			return;
		}

		$product_id = absint( get_post_meta( $post_id, $meta_key, true ) );

		if ( ! $product_id ) {
			return;
		}

		if ( 'yes' === $this->get_settings( 'resolve_parent' ) ) {
			$product_id = Product_Resolver::to_parent( $product_id );
		}

		echo esc_html( (string) $product_id );
	}
}
