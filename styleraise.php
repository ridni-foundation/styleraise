<?php
/**
 * Plugin Name: StyleRaise – Connector for Elementor and Donation Platform for WooCommerce
 * Plugin URI: https://github.com/ridni-foundation/styleraise
 * Description: Donation progress, donor walls and campaign dynamic tags for Donation Platform for WooCommerce — fully styleable inside Elementor.
 * Version: 3.1.7
 * Author: Ridni Foundation
 * Author URI: https://ridni.org.ua/en/
 * Requires at least: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, elementor, wc-donation-platform
 * Text Domain: styleraise
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Elementor tested up to: 4.3.5
 * Elementor Pro tested up to: 4.3.2
 * WC requires at least: 4.0.0
 * WC tested up to: 11.2
 *
 * @package WCDP_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCDP_EL_VERSION', '3.1.7' );

/**
 * Product name shown in the admin screen, notices and cache-purge logs.
 *
 * A product name, not a phrase, so it is not translated. The public build
 * rewrites it from `.wporg/wporg.json` (`short_name`), which is why every
 * visible occurrence goes through this constant.
 */
define( 'WCDP_EL_NAME', 'StyleRaise' );
define( 'WCDP_EL_FILE', __FILE__ );
define( 'WCDP_EL_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCDP_EL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare WooCommerce feature compatibility.
 *
 * Must be registered at file-load time: `before_woocommerce_init` fires before
 * `plugins_loaded`, so this cannot live inside the bootstrap callback below,
 * and it deliberately runs even when the dependency gate later fails —
 * WooCommerce reads these declarations regardless of whether the addon boots.
 *
 * - `custom_order_tables` (HPOS): compatible. The addon never reads or writes
 *   order data; campaign totals come from WCDP, which owns those queries. Its
 *   single direct query targets `_elementor_data` in `wp_postmeta`, which HPOS
 *   does not move.
 * - `cart_checkout_blocks`: incompatible. The donation form widget renders
 *   WCDP's form, and WCDP requires the classic `[woocommerce_checkout]`.
 * - `product_block_editor`: compatible. The addon only reads product data on
 *   the frontend and registers nothing in the product editor.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
	}
);

require_once WCDP_EL_DIR . 'includes/class-requirements.php';
require_once WCDP_EL_DIR . 'includes/class-installer.php';

register_activation_hook( __FILE__, array( '\WCDP_Elementor\Installer', 'activate' ) );

/**
 * Boot the integration once every dependency is loaded.
 *
 * Priority 20 guarantees that WooCommerce, Elementor and WCDP (all of which
 * bootstrap on `plugins_loaded` at the default priority) have already
 * registered their classes, shortcodes and hooks.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! \WCDP_Elementor\Requirements::instance()->are_met() ) {
			return;
		}

		require_once WCDP_EL_DIR . 'includes/class-plugin.php';

		\WCDP_Elementor\Plugin::instance();
	},
	20
);
