<?php
/**
 * Dependency and version gate.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies that every dependency of the integration is present and recent
 * enough, and surfaces an actionable admin notice for whatever is missing.
 *
 * The addon must stay silent (but never fatal) when a dependency is absent:
 * Elementor renders saved widget data from JSON, so a missing dependency
 * should degrade to "widget not registered", not to a white screen.
 */
final class Requirements {

	/**
	 * Oldest Elementor release the control API used here is stable on.
	 *
	 * 3.20 is the first version where `has_widget_inner_wrapper()`,
	 * `is_dynamic_content()` and nested `conditions` are all available.
	 */
	const MINIMUM_ELEMENTOR_VERSION = '3.20.0';

	/**
	 * Oldest WCDP release exposing every shortcode this addon drives.
	 *
	 * `[wcdp_order_counter]` landed in WCDP 1.3.2.
	 */
	const MINIMUM_WCDP_VERSION = '1.3.2';

	/**
	 * Oldest supported PHP.
	 *
	 * Matches the `Requires PHP` header. The public build rewrites both from
	 * `.wporg/wporg.json`, because the public edition supports an older PHP.
	 */
	const MINIMUM_PHP_VERSION = '7.4';

	/**
	 * Singleton instance.
	 *
	 * @var Requirements|null
	 */
	private static $instance = null;

	/**
	 * Collected failure messages, keyed by requirement slug.
	 *
	 * @var array<string, string>
	 */
	private $failures = array();

	/**
	 * Memoized result of the check.
	 *
	 * @var bool|null
	 */
	private $result = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Requirements
	 */
	public static function instance(): Requirements {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use {@see Requirements::instance()}.
	 */
	private function __construct() {}

	/**
	 * Run every check once and register the admin notice on failure.
	 *
	 * @return bool True when the addon may boot.
	 */
	public function are_met(): bool {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$this->check_php();
		$this->check_woocommerce();
		$this->check_elementor();
		$this->check_wcdp();

		$this->result = empty( $this->failures );

		if ( ! $this->result ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}

		return $this->result;
	}

	/**
	 * PHP version gate.
	 */
	private function check_php(): void {
		if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=' ) ) {
			return;
		}

		$this->failures['php'] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'PHP %1$s or newer is required. This site runs PHP %2$s.', 'styleraise' ),
			self::MINIMUM_PHP_VERSION,
			PHP_VERSION
		);
	}

	/**
	 * WooCommerce presence gate.
	 */
	private function check_woocommerce(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->failures['woocommerce'] = __( 'WooCommerce must be installed and activated.', 'styleraise' );
	}

	/**
	 * Elementor presence and version gate.
	 */
	private function check_elementor(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->failures['elementor'] = __( 'Elementor must be installed and activated.', 'styleraise' );

			return;
		}

		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, self::MINIMUM_ELEMENTOR_VERSION, '>=' ) ) {
			return;
		}

		$this->failures['elementor_version'] = sprintf(
			/* translators: 1: required Elementor version, 2: installed Elementor version. */
			__( 'Elementor %1$s or newer is required. Version %2$s is installed.', 'styleraise' ),
			self::MINIMUM_ELEMENTOR_VERSION,
			defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : __( 'unknown', 'styleraise' )
		);
	}

	/**
	 * WCDP presence and version gate.
	 */
	private function check_wcdp(): void {
		if ( ! class_exists( 'WCDP' ) ) {
			$this->failures['wcdp'] = __( 'Donation Platform for WooCommerce (WCDP) must be installed and activated.', 'styleraise' );

			return;
		}

		if ( defined( 'WCDP_VERSION' ) && version_compare( WCDP_VERSION, self::MINIMUM_WCDP_VERSION, '>=' ) ) {
			return;
		}

		$this->failures['wcdp_version'] = sprintf(
			/* translators: 1: required WCDP version, 2: installed WCDP version. */
			__( 'Donation Platform for WooCommerce %1$s or newer is required. Version %2$s is installed.', 'styleraise' ),
			self::MINIMUM_WCDP_VERSION,
			defined( 'WCDP_VERSION' ) ? WCDP_VERSION : __( 'unknown', 'styleraise' )
		);
	}

	/**
	 * Print a single grouped notice listing every unmet requirement.
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: %s: plugin name. */
				__( '%s is inactive.', 'styleraise' ),
				WCDP_EL_NAME
			)
		);
		echo '</strong></p><ul style="list-style:disc;margin-left:2em">';

		foreach ( $this->failures as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}

		echo '</ul></div>';
	}
}
