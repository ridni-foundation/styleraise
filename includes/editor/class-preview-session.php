<?php
/**
 * WooCommerce session that never persists, for the Elementor editor preview.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The session behind the editor's preview cart.
 *
 * WooCommerce writes a session row for a logged-in user as soon as anything in
 * the session changes, and the customer object changes it on every request.
 * In the editor that would leave a `woocommerce_sessions` row, and possibly a
 * session cookie, behind a request that only looked at a form. This session
 * works in memory: it starts empty (with an empty cart), registers no hooks (no save on shutdown,
 * no cookie), does not read or touch the user's stored session, and turns
 * every write into a no-op. It is only ever created by
 * {@see Form_Preview::load_preview_cart()}, from both preview paths: the
 * editor bootstrap (`maybe_load_cart()`) and a widget refresh over
 * `elementor_ajax` (`build_for_widget_refresh()` → `with_preview_cart()`).
 * WooCommerce instantiates it — and the autoloader loads it — only after
 * {@see Form_Preview::session_swap_supported()} has checked the parent class.
 *
 * Every override keeps the parent's parameters optional so the signatures
 * stay compatible across WooCommerce releases.
 */
class Preview_Session extends \WC_Session_Handler {

	/**
	 * Start an empty in-memory session; no hooks, no cookie, no storage read.
	 */
	public function init() {
		$this->_customer_id = (string) $this->generate_customer_id();
		$this->wcdp_el_reset_data();
	}

	/**
	 * Empty data with an empty cart already present.
	 *
	 * WC_Cart::get_cart() reads the session once even with the cart-session
	 * hooks off; a missing `cart` there makes WooCommerce merge the saved cart
	 * and delete the user's "merge after login" flag. An empty cart skips that.
	 *
	 * Prefixed: a private method whose name WooCommerce later gives a public
	 * method of WC_Session_Handler would be a fatal error when this class
	 * loads. Form_Preview::session_swap_supported() refuses a parent that
	 * already has this name.
	 */
	private function wcdp_el_reset_data() {
		$this->_data  = array( 'cart' => maybe_serialize( array() ) );
		$this->_dirty = false;
	}

	/**
	 * Same empty start: the stored session and its cookie are never read.
	 */
	public function init_session_cookie() {
		$this->init();
	}

	/**
	 * Never stored.
	 *
	 * @param string|mixed $old_session_key Unused.
	 */
	public function save_data( $old_session_key = '' ) {
		unset( $old_session_key );
		$this->_dirty = false;
	}

	/**
	 * Never sent.
	 *
	 * @param bool $set Unused.
	 */
	public function set_customer_session_cookie( $set = false ) {
		unset( $set );
	}

	/**
	 * Forget the in-memory data only; the stored session is not ours.
	 */
	public function destroy_session() {
		$this->wcdp_el_reset_data();
	}

	/**
	 * Forget the in-memory data only; no cookie is expired.
	 */
	public function forget_session() {
		$this->wcdp_el_reset_data();
	}

	/**
	 * Stored sessions are left alone.
	 *
	 * @param mixed $customer_id Unused.
	 */
	public function delete_session( $customer_id = '' ) {
		unset( $customer_id );
	}

	/**
	 * Stored sessions are left alone.
	 *
	 * @param mixed $customer_id Unused.
	 * @param mixed $timestamp   Unused.
	 */
	public function update_session_timestamp( $customer_id = '', $timestamp = 0 ) {
		unset( $customer_id, $timestamp );
	}
}
