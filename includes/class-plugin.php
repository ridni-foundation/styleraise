<?php
/**
 * Integration core: autoloading, registration, asset wiring.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Responsibilities are deliberately narrow: it wires WordPress and Elementor
 * hooks and owns the widget/tag registry. All presentation lives in the widget
 * classes, all campaign data access in {@see \WCDP_Elementor\Data\Campaign},
 * so the registration layer stays replaceable — this is what keeps a future
 * Elementor V4 atomic adapter additive rather than a rewrite.
 */
final class Plugin {

	/**
	 * Elementor widget category slug.
	 *
	 * Kept stable since 1.0.0 so existing pages keep resolving.
	 */
	const CATEGORY = 'wcdp-elementor';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		spl_autoload_register( array( $this, 'autoload' ) );

		Installer::maybe_upgrade();

		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_styles' ) );

		// Dynamic tags render inside other people's widgets and cannot declare
		// a script dependency, so a page that only refreshes through a tag
		// enqueues the script here instead.
		add_action( 'wp_footer', array( $this, 'maybe_enqueue_live_script' ), 5 );
		add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'enqueue_editor_styles' ) );

		// Campaign figures are volatile while the HTML carrying them is cached
		// for hours. These two hooks are the whole answer: the REST route feeds
		// the browser fresh numbers, the purger tells the page cache to rebuild.
		new Rest\Campaigns_Controller();

		Cache\Freshness::apply_data_ttl();

		// WCDP hides the donation form until its own script reveals it, and
		// every "delay JavaScript until interaction" feature holds that script
		// back — leaving a spinner where the form already is.
		new Cache\Script_Guard();

		$this->register_purge_triggers();

		// Enabling donations on a product changes what the campaign picker
		// should offer, so the cached list has to go.
		add_action( 'updated_post_meta', array( $this, 'maybe_flush_picker_cache' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'maybe_flush_picker_cache' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_flush_picker_cache' ), 10, 3 );

		if ( is_admin() ) {
			new Admin\Settings();
		}
	}

	/**
	 * Hook the events that make a campaign's figures change.
	 *
	 * A donation updates no post, so no caching plugin has any reason to
	 * rebuild the pages showing the campaign — they keep serving the total that
	 * was current when the file was written. These triggers supply the missing
	 * signal.
	 */
	private function register_purge_triggers(): void {
		if ( ! Cache\Freshness::purge_enabled() ) {
			return;
		}

		// The most precise signal available: WooCommerce Analytics writes the
		// order-product lookup row, which is the same source WCDP reads its
		// revenue from.
		add_action( 'woocommerce_analytics_update_product', array( $this, 'purge_for_order_item' ), 20, 2 );

		// Belt to that braces, for sites where Analytics is disabled.
		add_action( 'woocommerce_order_status_completed', array( $this, 'purge_for_order' ), 20 );

		// Editing the goal or the deadline changes what every widget prints.
		add_action( 'updated_post_meta', array( $this, 'purge_for_campaign_meta' ), 20, 3 );
		add_action( 'added_post_meta', array( $this, 'purge_for_campaign_meta' ), 20, 3 );
	}

	/**
	 * Queue a purge for the campaign behind one order item.
	 *
	 * @param int $order_item_id Order item ID.
	 * @param int $order_id      Order ID.
	 */
	public function purge_for_order_item( $order_item_id, $order_id ): void {
		unset( $order_id );

		if ( ! class_exists( 'WC_Order_Factory' ) ) {
			return;
		}

		$item = \WC_Order_Factory::get_order_item( $order_item_id );

		if ( ! $item || ! method_exists( $item, 'get_product_id' ) ) {
			return;
		}

		$product_id = (int) $item->get_product_id();

		if ( $product_id > 0 ) {
			Cache\Purger::queue( Data\Product_Resolver::to_parent( $product_id ) );
		}
	}

	/**
	 * Queue a purge for every campaign in a completed order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function purge_for_order( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			if ( $product_id > 0 ) {
				Cache\Purger::queue( Data\Product_Resolver::to_parent( $product_id ) );
			}
		}
	}

	/**
	 * Queue a purge when a campaign's own settings change.
	 *
	 * @param int|array $meta_id  Meta ID.
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public function purge_for_campaign_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );

		$watched = array(
			Data\Campaign::META_GOAL,
			Data\Campaign::META_END_DATE,
		);

		if ( in_array( $meta_key, $watched, true ) ) {
			Cache\Purger::queue( (int) $post_id );
		}
	}

	/**
	 * Invalidate the campaign picker cache when a donation flag changes.
	 *
	 * @param int|array $meta_id  Meta ID (array for the deleted_post_meta hook).
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public function maybe_flush_picker_cache( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id, $post_id );

		if ( '_donable' === $meta_key ) {
			Data\Product_Resolver::flush_options_cache();
		}
	}

	/* ─────────────────────────── Autoloading ─────────────────────────── */

	/**
	 * Map a namespaced class name to a WordPress-style file name.
	 *
	 * `WCDP_Elementor\Widgets\Progress_Bar` → `includes/widgets/class-progress-bar.php`
	 * Traits and interfaces use the `trait-` / `interface-` prefixes.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$parts    = explode( '\\', $relative );
		$base     = array_pop( $parts );

		$directory = '';
		if ( ! empty( $parts ) ) {
			$directory = strtolower( implode( '/', $parts ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $base ) );

		foreach ( array( 'class', 'trait', 'interface' ) as $prefix ) {
			$path = WCDP_EL_DIR . 'includes/' . $directory . $prefix . '-' . $slug . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;

				return;
			}
		}
	}

	/* ─────────────────────────── Registry ─────────────────────────── */

	/**
	 * Widget registry: Elementor widget name → widget class (short name).
	 *
	 * The Elementor widget names are frozen from 1.0.x so that pages built
	 * with the previous release keep rendering after the 2.0 upgrade.
	 *
	 * @return array<string, string>
	 */
	public function get_widget_registry(): array {
		$widgets = array(
			'wcdp-progress-bar'   => 'Progress',
			'wcdp-donation-form'  => 'Donation_Form',
			'wcdp-leaderboard'    => 'Leaderboard',
			'wcdp-order-counter'  => 'Order_Counter',
			'wcdp-product-title'  => 'Product_Title',
			'wcdp-product-price'  => 'Product_Price',
			'wcdp-product-image'  => 'Product_Image',
		);

		/**
		 * Filter the registered widgets.
		 *
		 * Unset a key to remove a widget from the editor entirely.
		 *
		 * @param array<string, string> $widgets Widget name → class short name.
		 */
		return (array) apply_filters( 'wcdp_el_widget_registry', $widgets );
	}

	/**
	 * Dynamic tag registry: tag name → tag class (short name).
	 *
	 * @return array<string, string>
	 */
	public function get_tag_registry(): array {
		$tags = array(
			'wcdp-product-id'  => 'Product_Id',
			'wcdp-raised'      => 'Raised',
			'wcdp-goal'        => 'Goal',
			'wcdp-remaining'   => 'Remaining',
			'wcdp-percentage'  => 'Percentage',
			'wcdp-order-count' => 'Order_Count',
			'wcdp-end-date'    => 'End_Date',
		);

		/**
		 * Filter the registered dynamic tags.
		 *
		 * @param array<string, string> $tags Tag name → class short name.
		 */
		return (array) apply_filters( 'wcdp_el_tag_registry', $tags );
	}

	/* ─────────────────────────── Registration ─────────────────────────── */

	/**
	 * Add the WCDP widget category to the editor panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => esc_html__( 'WCDP Donation', 'styleraise' ),
				'icon'  => 'eicon-heart',
			)
		);
	}

	/**
	 * Register every enabled widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		foreach ( $this->get_widget_registry() as $widget_name => $class_name ) {
			if ( Options::is_widget_disabled( $widget_name ) ) {
				continue;
			}

			$fqcn = __NAMESPACE__ . '\\Widgets\\' . $class_name;

			if ( ! class_exists( $fqcn ) ) {
				continue;
			}

			$widgets_manager->register( new $fqcn() );
		}
	}

	/**
	 * Register the WCDP dynamic tag group and every enabled tag.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $tags_manager Elementor dynamic tags manager.
	 */
	public function register_dynamic_tags( $tags_manager ): void {
		$tags_manager->register_group(
			'wcdp',
			array(
				'title' => esc_html__( 'WCDP Donation', 'styleraise' ),
			)
		);

		foreach ( $this->get_tag_registry() as $class_name ) {
			$fqcn = __NAMESPACE__ . '\\Tags\\' . $class_name;

			if ( ! class_exists( $fqcn ) ) {
				continue;
			}

			$tags_manager->register( new $fqcn() );
		}
	}

	/* ─────────────────────────── Assets ─────────────────────────── */

	/**
	 * Register (never enqueue) the frontend stylesheets.
	 *
	 * Widgets declare these handles through `get_style_depends()`, so Elementor
	 * loads each file only on pages that actually contain the widget. Enqueuing
	 * them here would defeat that.
	 */
	public function register_frontend_styles(): void {
		$styles = array(
			'wcdp-el-base'        => 'wcdp-el-base.css',
			'wcdp-el-progress'    => 'wcdp-el-progress.css',
			'wcdp-el-leaderboard' => 'wcdp-el-leaderboard.css',
			'wcdp-el-form'        => 'wcdp-el-form.css',
			'wcdp-el-product'     => 'wcdp-el-product.css',
		);

		foreach ( $styles as $handle => $file ) {
			$deps = ( 'wcdp-el-base' === $handle ) ? array() : array( 'wcdp-el-base' );

			wp_register_style(
				$handle,
				WCDP_EL_URL . 'assets/css/' . $file,
				$deps,
				WCDP_EL_VERSION
			);
		}

		$this->register_live_script();
	}

	/**
	 * Register (never enqueue) the live-figures script.
	 *
	 * Widgets declare it through `get_script_depends()`, so Elementor loads it
	 * only on pages that actually contain one. A site with hydration switched
	 * off ships no JavaScript at all.
	 */
	private function register_live_script(): void {
		if ( ! Cache\Freshness::hydration_enabled() ) {
			return;
		}

		wp_register_script(
			'wcdp-el-live',
			WCDP_EL_URL . 'assets/js/wcdp-el-live.js',
			array(),
			WCDP_EL_VERSION,
			true
		);

		$config = Cache\Freshness::config();

		wp_localize_script(
			'wcdp-el-live',
			'wcdpElLive',
			array(
				'endpoint' => Rest\Campaigns_Controller::url(),
				'mode'     => $config['hydrate'],
				'interval' => $config['interval'],
			)
		);
	}

	/**
	 * Enqueue the live-figures script for pages that only need it via a tag.
	 *
	 * A no-op when a widget already pulled it in through `get_script_depends()`.
	 */
	public function maybe_enqueue_live_script(): void {
		if ( ! Cache\Live_Target::is_needed() ) {
			return;
		}

		if ( wp_script_is( 'wcdp-el-live', 'registered' ) ) {
			wp_enqueue_script( 'wcdp-el-live' );
		}
	}

	/**
	 * Editor-only styling for widget placeholders and panel notices.
	 */
	public function enqueue_editor_styles(): void {
		wp_enqueue_style(
			'wcdp-el-editor',
			WCDP_EL_URL . 'assets/css/wcdp-el-editor.css',
			array(),
			WCDP_EL_VERSION
		);
	}
}
