<?php
/**
 * Real WCDP donation form inside the Elementor editor.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Editor;

use WCDP_Elementor\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets WCDP build its form for the Elementor editor canvas.
 *
 * Elementor renders a widget's canvas HTML while it bootstraps the editor
 * (`post.php?action=elementor`): the editor config carries every widget's
 * markup, and the preview iframe only inserts it. That request runs in
 * wp-admin, where WooCommerce loads no cart, and WCDP prints "In the current
 * view, the donation form is not available." instead of the form.
 *
 * Three steps make the real form visible, each limited to the editor:
 *
 * 1. a WooCommerce cart for that request only, for a user who may edit the
 *    post (`maybe_load_cart()`). A content control change re-renders the
 *    widget over `elementor_ajax`, where WCDP's shortcode refuses to run;
 *    there the widget builds the form through `build_for_widget_refresh()`,
 *    with the same in-memory cart swapped in for that one render;
 * 2. WCDP's stylesheet in the preview iframe, where WCDP enqueues nothing
 *    because the form never renders there (`enqueue_preview_styles()`);
 * 3. the form root un-hidden in the widget's HTML, since `wcdp.js` — which
 *    normally removes `visibility:hidden` — does not run on that markup
 *    (`reveal()`), and its spinner hidden by `wcdp-el-preview.css`.
 *
 * Nothing is added to the cart, and the request leaves no trace in the
 * user's WooCommerce state: the session is {@see Preview_Session} (memory
 * only), the cart does not load from or save to the session or the saved
 * cart, the "merge the saved cart after login" flag is neither consumed nor
 * deleted, and no WooCommerce cookie is set.
 */
final class Form_Preview {

	/**
	 * User meta WooCommerce sets on every login and the first cart load
	 * afterwards consumes (deletes).
	 */
	const SAVED_CART_FLAG = '_woocommerce_load_saved_cart_after_login';

	/**
	 * Oldest WooCommerce with `woocommerce_cart_session_initialize`.
	 */
	const MIN_WC_VERSION = '6.9';

	/**
	 * WC_Session_Handler methods Preview_Session overrides, each with the
	 * number of parameters its override declares (all optional).
	 */
	const OVERRIDDEN_METHODS = array(
		'init'                        => 0,
		'init_session_cookie'         => 0,
		'save_data'                   => 1,
		'set_customer_session_cookie' => 1,
		'destroy_session'             => 0,
		'forget_session'              => 0,
		'delete_session'              => 1,
		'update_session_timestamp'    => 2,
	);

	/**
	 * Preview_Session's own (private) helper; the parent must not have it.
	 */
	const SESSION_HELPER = 'wcdp_el_reset_data';

	/**
	 * Memoized result of session_swap_supported().
	 *
	 * @var bool|null
	 */
	private static $swap_supported = null;

	/**
	 * The service registered by Plugin; null until then.
	 *
	 * @var Form_Preview|null
	 */
	private static $instance = null;

	/**
	 * User the preview cart was loaded for; 0 when none.
	 *
	 * @var int
	 */
	private $preview_user = 0;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		self::$instance = $this;

		// wp_loaded, not woocommerce_init: the edit_post check needs the post
		// type of the edited record, and most post types (Elementor templates,
		// products) register on `init` after WooCommerce has initialised. The
		// cart is first needed when the editor renders, after admin_init.
		add_action( 'wp_loaded', array( $this, 'maybe_load_cart' ), 20 );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'enqueue_preview_styles' ) );
	}

	/**
	 * Whether the preview is switched on in the settings.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) Options::get( 'editor_form_preview', true );
	}

	/**
	 * Whether this is the Elementor editor bootstrap for a post the current
	 * user may edit.
	 *
	 * That request (`wp-admin/post.php?post=<id>&action=elementor`) is the one
	 * that renders widget HTML for the canvas. It is read before `admin_init`,
	 * so Elementor's own editor state is not available yet; the query and the
	 * capability are.
	 *
	 * @return bool
	 */
	public static function is_editor_request(): bool {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( 'post.php' !== ( isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only context probe; the request changes no state.
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'elementor' !== $action || $post_id <= 0 ) {
			return false;
		}

		// map_meta_cap() cannot answer edit_post for an unregistered post type
		// (it raises _doing_it_wrong and falls back to edit_others_posts).
		$post_type = get_post_type( $post_id );

		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether the in-memory session can safely stand in for WooCommerce's.
	 *
	 * Needs `woocommerce_cart_session_initialize` (WooCommerce 6.9+) and a
	 * WC_Session_Handler that Preview_Session can extend as declared
	 * (session_handler_compatible()) — otherwise loading Preview_Session
	 * would be a fatal error. When this is false the editor keeps the 3.1.x
	 * behaviour: no cart, WCDP's message and the widget's hint.
	 *
	 * @return bool
	 */
	public static function session_swap_supported(): bool {
		if ( null !== self::$swap_supported ) {
			return self::$swap_supported;
		}

		self::$swap_supported = false;

		if ( ! defined( 'WC_VERSION' ) || version_compare( (string) WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
			return false;
		}

		self::$swap_supported = self::session_handler_compatible( 'WC_Session_Handler' );

		return self::$swap_supported;
	}

	/**
	 * Whether Preview_Session's declaration is compatible with this parent.
	 *
	 * Checked before Preview_Session is ever loaded, because each mismatch is
	 * a fatal error at class load, not something to catch: a final class; an
	 * overridden method that is missing, not public, final, static, declares
	 * a return type, takes a parameter by reference or a variadic one, or
	 * takes more parameters than the override; or a parent method named like
	 * Preview_Session's private helper. A variadic parameter counts as one, so
	 * the count alone would let `save_data( ...$args )` through, yet the
	 * override cannot replace it. Parameters the parent requires are fine —
	 * the override accepts them as optional.
	 *
	 * @param string $class_name Parent class (WC_Session_Handler).
	 * @return bool
	 */
	private static function session_handler_compatible( string $class_name ): bool {
		if ( ! class_exists( $class_name ) || method_exists( $class_name, self::SESSION_HELPER ) ) {
			return false;
		}

		try {
			$parent = new \ReflectionClass( $class_name );

			if ( $parent->isFinal() ) {
				return false;
			}

			foreach ( self::OVERRIDDEN_METHODS as $method => $declared ) {
				if ( ! $parent->hasMethod( $method ) ) {
					return false;
				}

				$reflection = $parent->getMethod( $method );

				if ( $reflection->hasReturnType() || ! $reflection->isPublic() || $reflection->isFinal() || $reflection->isStatic() || $reflection->returnsReference() ) {
					return false;
				}

				if ( $reflection->getNumberOfParameters() > $declared ) {
					return false;
				}

				foreach ( $reflection->getParameters() as $parameter ) {
					if ( $parameter->isPassedByReference() || $parameter->isVariadic() ) {
						return false;
					}
				}
			}
		} catch ( \ReflectionException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Give the editor bootstrap a WooCommerce cart so WCDP can build the form.
	 */
	public function maybe_load_cart(): void {
		if ( ! self::is_enabled() || ! self::is_editor_request() ) {
			return;
		}

		if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) || isset( WC()->cart ) ) {
			return;
		}

		if ( ! self::session_swap_supported() ) {
			return;
		}

		$this->load_preview_cart();
	}

	/**
	 * Whether Elementor is running its `render_widget` AJAX action right now.
	 *
	 * That action renders one widget for the editor canvas and returns it to
	 * the browser; nothing it prints is stored. Every other `elementor_ajax`
	 * action that renders widgets does store or reuse the output: `save_builder`
	 * writes each widget's render into `post_content` (DB::save_plain_text()),
	 * whence revisions and the REST API copy it. Only true inside Elementor's
	 * `wp_ajax_elementor_ajax` handler after it verified its nonce
	 * (`elementor/ajax/register_actions` fires only then), with the action
	 * being `render_widget` and the editor in edit mode (set by that action).
	 *
	 * @return bool
	 */
	public static function is_widget_render_action(): bool {
		if ( ! wp_doing_ajax() || ! doing_action( 'wp_ajax_elementor_ajax' ) || ! did_action( 'elementor/ajax/register_actions' ) ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor verified its own nonce; see did_action() above.

		if ( 'elementor_ajax' !== $action || ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! $elementor->editor || ! $elementor->editor->is_edit_mode() || ! $elementor->common ) {
			return false;
		}

		$ajax = $elementor->common->get_component( 'ajax' );

		if ( ! is_object( $ajax ) || ! method_exists( $ajax, 'get_current_action_data' ) ) {
			return false;
		}

		$current = $ajax->get_current_action_data();

		return is_array( $current ) && isset( $current['action'] ) && 'render_widget' === $current['action'];
	}

	/**
	 * Whether this request is Elementor re-rendering a widget for a post the
	 * current user may edit, with the preview switched on.
	 *
	 * Only Elementor's `render_widget` action (is_widget_render_action()):
	 * never `save_builder` or any other request whose output can be stored.
	 *
	 * @param int $post_id Post the editor is open on (`editor_post_id`).
	 * @return bool
	 */
	public static function is_ajax_render_request( int $post_id ): bool {
		if ( ! self::is_enabled() || ! self::is_widget_render_action() ) {
			return false;
		}

		if ( $post_id <= 0 ) {
			return false;
		}

		// As on the bootstrap: edit_post needs a registered post type.
		$post_type = get_post_type( $post_id );

		if ( ! $post_type || ! post_type_exists( $post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return self::session_swap_supported();
	}

	/**
	 * The post an `elementor_ajax` request is for; 0 when absent.
	 *
	 * @return int
	 */
	public static function ajax_editor_post_id(): int {
		return isset( $_REQUEST['editor_post_id'] ) ? absint( wp_unslash( $_REQUEST['editor_post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor verified its own nonce before any widget renders.
	}

	/**
	 * Build the WCDP form for a widget Elementor re-renders over `elementor_ajax`.
	 *
	 * WCDP's shortcode refuses that request, so the form comes from WCDP's
	 * public `WCDP_Form::wcdp_donation_form()`, the method the shortcode
	 * itself returns. On admin-ajax WooCommerce has already loaded the user's
	 * real cart and session; for this one call they are swapped for the same
	 * in-memory ones the bootstrap uses, then put back, so the render leaves
	 * no trace and the rest of the request sees WooCommerce as it was.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string|null Form HTML; null when this request may not or cannot build it.
	 */
	public static function build_for_widget_refresh( array $atts ): ?string {
		if ( null === self::$instance || ! self::is_ajax_render_request( self::ajax_editor_post_id() ) ) {
			return null;
		}

		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) || ! self::form_builder_supported( 'WCDP_Form' ) ) {
			return null;
		}

		// The widget checks the campaign first (is_preview_render()); this
		// keeps the builder safe for any other caller.
		if ( ! self::campaign_previewable( isset( $atts['id'] ) ? absint( $atts['id'] ) : 0 ) ) {
			return null;
		}

		return self::$instance->with_preview_cart(
			static function () use ( $atts ) {
				return \WCDP_Form::wcdp_donation_form( $atts );
			}
		);
	}

	/**
	 * Whether this render builds WCDP's form for the editor canvas.
	 *
	 * Elementor's `render_widget` the preview serves (is_ajax_render_request(),
	 * preview switched on), or the editor bootstrap with a cart in place —
	 * the preview cart, or a cart another plugin (such as Elementor Pro's
	 * WooCommerce module) loaded before `maybe_load_cart()`, which then
	 * stepped aside. WCDP builds the form with either cart, and the other
	 * plugin's cart is there whether the preview is on or off, so the
	 * bootstrap check does not depend on the setting. Without a cart WCDP
	 * builds no form and the widget keeps its 3.1.x hint.
	 *
	 * @return bool
	 */
	public static function is_preview_render(): bool {
		if ( wp_doing_ajax() ) {
			return self::is_ajax_render_request( self::ajax_editor_post_id() );
		}

		return self::is_editor_shell_render()
			&& function_exists( 'WC' )
			&& isset( WC()->cart );
	}

	/**
	 * Whether the editor preview may build this campaign's form.
	 *
	 * The campaign is the editor's choice, but its content is only theirs to
	 * see if it is published or they may read it; a password-protected one
	 * stays out even for an administrator, as on the REST route
	 * (Campaigns_Controller::is_public()). The same rule holds on the editor
	 * bootstrap with any cart (with the preview off too, when another plugin
	 * loaded the cart) and on a widget refresh; otherwise the widget shows a
	 * hint.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	public static function campaign_previewable( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( 'publish' !== get_post_status( $product_id ) && ! current_user_can( 'read_post', $product_id ) ) {
			return false;
		}

		return ! self::is_password_protected( $product_id );
	}

	/**
	 * Whether a campaign (or a variation's parent product) has a password.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	private static function is_password_protected( int $product_id ): bool {
		$post = get_post( $product_id );

		if ( $post instanceof \WP_Post && 'product_variation' === $post->post_type ) {
			$post = get_post( (int) $post->post_parent );
		}

		return ! $post instanceof \WP_Post || '' !== (string) $post->post_password;
	}

	/**
	 * Whether `$class_name::wcdp_donation_form()` can be called with the
	 * attributes alone.
	 *
	 * WCDP 1.5.x declares `( array $value, string $context = 'shortcode' )`;
	 * WCDP 1.3.2 (the plugin's minimum) has a required second argument, and
	 * calling it with one would be an ArgumentCountError — an Error, which
	 * Elementor's AJAX handler does not catch.
	 *
	 * @param string $class_name WCDP's form class.
	 * @return bool
	 */
	private static function form_builder_supported( string $class_name ): bool {
		if ( ! class_exists( $class_name ) || ! method_exists( $class_name, 'wcdp_donation_form' ) ) {
			return false;
		}

		try {
			$method = new \ReflectionMethod( $class_name, 'wcdp_donation_form' );
		} catch ( \ReflectionException $e ) {
			return false;
		}

		return $method->isPublic() && $method->isStatic() && $method->getNumberOfRequiredParameters() <= 1;
	}

	/**
	 * Run a render with the preview cart in place of WooCommerce's own.
	 *
	 * @param callable $build Returns the HTML.
	 * @return string|null
	 */
	private function with_preview_cart( callable $build ): ?string {
		$wc   = WC();
		$real = array( $wc->session, $wc->cart, $wc->customer );

		// WCDP sets the global product for its template.
		$had_product = array_key_exists( 'product', $GLOBALS );
		$product     = $had_product ? $GLOBALS['product'] : null;

		$wc->session  = null;
		$wc->cart     = null;
		$wc->customer = null;

		$html = null;

		try {
			if ( $this->load_preview_cart() ) {
				$html = $build();
			}
		} finally {
			$this->unload_preview_cart();

			list( $wc->session, $wc->cart, $wc->customer ) = $real;

			if ( $had_product ) {
				$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restores WooCommerce's global that WCDP replaced.
			} else {
				unset( $GLOBALS['product'] );
			}
		}

		return is_string( $html ) ? $html : null;
	}

	/**
	 * Load the in-memory cart and switch on the safeguards for it.
	 *
	 * Expects WooCommerce to have no session, cart or customer yet.
	 *
	 * @return bool Whether the preview cart is in place.
	 */
	private function load_preview_cart(): bool {
		// Read-only for the user's WooCommerce state. A plain wc_load_cart()
		// here wrote a `woocommerce_sessions` row for the editor, rewrote the
		// admin's row, created a persistent cart and tried to set the session
		// cookie (stand, 3.2.0). So the session lives in memory only and the
		// cart neither loads from nor writes to the session or the saved cart.
		add_filter( 'woocommerce_session_handler', array( $this, 'session_handler' ), PHP_INT_MAX );
		add_filter( 'woocommerce_cart_session_initialize', array( $this, 'skip_cart_session' ), PHP_INT_MAX );

		try {
			wc_load_cart();
		} finally {
			remove_filter( 'woocommerce_session_handler', array( $this, 'session_handler' ), PHP_INT_MAX );
			remove_filter( 'woocommerce_cart_session_initialize', array( $this, 'skip_cart_session' ), PHP_INT_MAX );
		}

		$this->preview_user = get_current_user_id();

		// WC_Cart::get_cart() still reads the session once. Preview_Session
		// starts with an empty cart, so the saved cart is not merged in; the
		// login flag is hidden so the merge-and-delete branch is never reached.
		add_filter( 'get_user_metadata', array( $this, 'hide_saved_cart_flag' ), PHP_INT_MAX, 4 );

		// Belts while the preview cart is in place: no saved-cart reads or
		// writes, no customer save into the session, no session or cart cookie.
		add_filter( 'woocommerce_persistent_cart_enabled', array( $this, 'disable_persistent_cart' ), PHP_INT_MAX );
		add_filter( 'woocommerce_set_cookie_enabled', array( $this, 'block_wc_cookies' ), PHP_INT_MAX, 2 );

		// No user data in preview markup: checkout fields start empty instead
		// of being filled from the editor's account (name, e-mail, address).
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'empty_checkout_value' ), PHP_INT_MAX );

		if ( isset( WC()->customer ) ) {
			remove_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
		}

		return isset( WC()->cart ) && WC()->session instanceof Preview_Session;
	}

	/**
	 * Undo load_preview_cart() once the render is done.
	 *
	 * The preview cart also registered its own handlers on WooCommerce's cart
	 * actions (totals, coupon checks); they go with it.
	 */
	private function unload_preview_cart(): void {
		remove_filter( 'get_user_metadata', array( $this, 'hide_saved_cart_flag' ), PHP_INT_MAX );
		remove_filter( 'woocommerce_persistent_cart_enabled', array( $this, 'disable_persistent_cart' ), PHP_INT_MAX );
		remove_filter( 'woocommerce_set_cookie_enabled', array( $this, 'block_wc_cookies' ), PHP_INT_MAX );
		remove_filter( 'woocommerce_checkout_get_value', array( $this, 'empty_checkout_value' ), PHP_INT_MAX );

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( is_object( $cart ) ) {
			self::remove_object_callbacks( $cart );
		}

		$this->preview_user = 0;
	}

	/**
	 * Remove every hook callback that is a method of the given object.
	 *
	 * @param object $instance Object whose handlers go.
	 */
	private static function remove_object_callbacks( $instance ): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook => $wp_hook ) {
			if ( ! $wp_hook instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $instance === $callback['function'][0] ) {
						remove_filter( (string) $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Empty value for every checkout field of the preview form.
	 *
	 * WC_Checkout::get_value() returns a non-null filter result as is, so the
	 * customer data (billing_email falls back to the account e-mail) is never
	 * read into the markup.
	 *
	 * @param mixed $value Short-circuit value (null to continue).
	 * @return string
	 */
	public function empty_checkout_value( $value = null ): string {
		unset( $value );

		return '';
	}

	/**
	 * No persistent (saved) cart while the preview cart is in place.
	 *
	 * Its own callback rather than `__return_false`, so removing it cannot
	 * remove another plugin's identical callback.
	 *
	 * @param bool $enabled Whether WooCommerce would use the saved cart.
	 * @return bool
	 */
	public function disable_persistent_cart( $enabled = true ): bool {
		unset( $enabled );

		return false;
	}

	/**
	 * Session class for the editor's preview cart.
	 *
	 * @param string $class_name Session handler class WooCommerce would use.
	 * @return string
	 */
	public function session_handler( $class_name ): string {
		unset( $class_name );

		return Preview_Session::class;
	}

	/**
	 * The preview cart registers no session hooks: it neither loads the saved
	 * cart nor writes the session, and sets no cart cookie.
	 *
	 * Its own callback rather than `__return_false`, so removing it cannot
	 * remove another plugin's identical callback.
	 *
	 * @param bool $initialize Whether WooCommerce would initialise the cart session.
	 * @return bool
	 */
	public function skip_cart_session( $initialize = true ): bool {
		unset( $initialize );

		return false;
	}

	/**
	 * Read the "merge saved cart after login" flag as unset for the preview user.
	 *
	 * The flag stays stored; the user's first frontend request after login
	 * consumes it as WooCommerce intends.
	 *
	 * @param mixed  $value     Short-circuit value (null to continue).
	 * @param int    $object_id User ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function hide_saved_cart_flag( $value, $object_id = 0, $meta_key = '', $single = false ) {
		if ( self::SAVED_CART_FLAG !== $meta_key || $this->preview_user <= 0 || (int) $object_id !== $this->preview_user ) {
			return $value;
		}

		return $single ? '' : array();
	}

	/**
	 * Keep the editor request from setting WooCommerce's session or cart cookies.
	 *
	 * @param bool   $enabled Whether wc_setcookie() may set the cookie.
	 * @param string $name    Cookie name.
	 * @return bool
	 */
	public function block_wc_cookies( $enabled, $name = '' ): bool {
		$name = (string) $name;

		if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) || 0 === strpos( $name, 'woocommerce_' ) ) {
			return false;
		}

		return (bool) $enabled;
	}

	/**
	 * WCDP's stylesheet and the preview helper in the editor's preview iframe.
	 */
	public function enqueue_preview_styles(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}

		wp_enqueue_style( 'wc-donation-platform' );
		wp_enqueue_style( 'wcdp-el-preview' );
	}

	/**
	 * Remove `visibility:hidden` from the style attribute of the form root.
	 *
	 * WCDP prints the root (`id="wcdp"`, class `wcdp-form`) hidden and lets
	 * `wcdp.js` reveal it. Only that declaration on the first such element
	 * changes; the rest of the markup is returned as it came. Attribute and
	 * class names are matched whole, so `data-id="wcdp"` or `wcdp-form-x` do
	 * not count.
	 *
	 * @param string $html Form HTML from WCDP.
	 * @return string
	 */
	public static function reveal( string $html ): string {
		$root = '/<[a-z][a-z0-9]*\b'
			. '(?=[^>]*(?<![\w-])style\s*=)'
			. '(?=[^>]*(?:(?<![\w-])id\s*=\s*["\']wcdp["\']|(?<![\w-])class\s*=\s*["\'][^"\']*(?<![\w-])wcdp-form(?![\w-])))'
			. '[^>]*>/i';

		return (string) preg_replace_callback(
			$root,
			static function ( array $tag ): string {
				return (string) preg_replace_callback(
					'/\s+style\s*=\s*(["\'])(.*?)\1/is',
					static function ( array $attr ): string {
						$style = trim( (string) preg_replace( '/(^|;)\s*visibility\s*:\s*hidden\s*(;|$)/i', '$1', $attr[2] ) );

						return '' === $style ? '' : ' style=' . $attr[1] . $style . $attr[1];
					},
					$tag[0],
					1
				);
			},
			$html,
			1
		);
	}

	/**
	 * Whether this render feeds the editor canvas from the editor bootstrap.
	 *
	 * There `wp_footer` prints into the editor shell around the canvas, not
	 * into the page being edited.
	 *
	 * @return bool
	 */
	public static function is_editor_shell_render(): bool {
		return is_admin()
			&& ! wp_doing_ajax()
			&& class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance->editor
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Identify every callback currently on `wp_footer`.
	 *
	 * @return array<string, true> "priority|callback id" set.
	 */
	public static function footer_snapshot(): array {
		global $wp_filter;

		$ids = array();

		if ( isset( $wp_filter['wp_footer'] ) && $wp_filter['wp_footer'] instanceof \WP_Hook ) {
			foreach ( $wp_filter['wp_footer']->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( $callbacks ) as $id ) {
					$ids[ $priority . '|' . $id ] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * Remove the `wp_footer` callbacks WCDP added since a snapshot.
	 *
	 * WCDP queues a popup form for `wp_footer` with a closure. On the editor
	 * bootstrap that would print a whole checkout form into the editor shell,
	 * where nobody sees it but its fields and ids still sit in the document.
	 * Only callbacks whose code lives in WCDP's directory go; anything else
	 * added meanwhile (WooCommerce's inline JS printer, other plugins) stays.
	 *
	 * @param array<string, true> $before Result of footer_snapshot().
	 */
	public static function drop_footer_added_since( array $before ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_footer'] ) || ! $wp_filter['wp_footer'] instanceof \WP_Hook ) {
			return;
		}

		$wcdp_dir = self::wcdp_dir();

		if ( '' === $wcdp_dir ) {
			return;
		}

		foreach ( $wp_filter['wp_footer']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( isset( $before[ $priority . '|' . $id ] ) ) {
					continue;
				}

				$file = self::callback_file( $callback['function'] );

				if ( '' !== $file && 0 === strpos( $file, $wcdp_dir ) ) {
					remove_action( 'wp_footer', $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * WCDP's plugin directory, normalised with a trailing slash; '' if unknown.
	 *
	 * @return string
	 */
	private static function wcdp_dir(): string {
		if ( defined( 'WCDP_DIR' ) ) {
			$dir = (string) WCDP_DIR;
		} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
			$dir = WP_PLUGIN_DIR . '/wc-donation-platform/';
		} else {
			return '';
		}

		$real = realpath( $dir );

		return trailingslashit( wp_normalize_path( false !== $real ? $real : $dir ) );
	}

	/**
	 * File that defines a hook callback, normalised; '' when unknown.
	 *
	 * @param mixed $callback Hook callback.
	 * @return string
	 */
	private static function callback_file( $callback ): string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && false === strpos( $callback, '::' ) && function_exists( $callback ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				// Two arguments: the one-string form is deprecated since PHP 8.4.
				list( $class_name, $method ) = explode( '::', $callback, 2 );
				$reflection                  = new \ReflectionMethod( $class_name, $method );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}

		$file = $reflection->getFileName();

		if ( ! is_string( $file ) || '' === $file ) {
			return '';
		}

		$real = realpath( $file );

		return wp_normalize_path( false !== $real ? $real : $file );
	}
}
