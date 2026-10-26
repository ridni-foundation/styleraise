<?php
/**
 * Allowed-HTML lists for the markup the widgets print.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Render;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowlists passed to `wp_kses()` at every echo of assembled markup.
 *
 * Widgets print markup built by WooCommerce (`wc_price()`, attachment images)
 * and by WCDP (the donation form, the leaderboard). Each echo goes through
 * `wp_kses()` with one of these lists, so nothing reaches the page unescaped.
 * The lists are `wp_kses_allowed_html( 'post' )` plus exactly what those
 * producers emit; the form list was taken from the real WCDP 1.5.0 form
 * (`tests/stand/run/kses.php` compares the markup before and after).
 */
final class Kses {

	/**
	 * Attributes allowed on every tag of the lists below.
	 *
	 * `data-*` carries the live-refresh descriptors and WCDP's form state;
	 * wp_kses() honours that one wildcard only, so ARIA is listed by name.
	 */
	private const GLOBAL_ATTRIBUTES = array(
		'class'            => true,
		'id'               => true,
		'style'            => true,
		'title'            => true,
		'role'             => true,
		'dir'              => true,
		'lang'             => true,
		'hidden'           => true,
		'tabindex'         => true,
		'translate'        => true,
		'aria-atomic'      => true,
		'aria-busy'        => true,
		'aria-checked'     => true,
		'aria-controls'    => true,
		'aria-current'     => true,
		'aria-describedby' => true,
		'aria-disabled'    => true,
		'aria-expanded'    => true,
		'aria-haspopup'    => true,
		'aria-hidden'      => true,
		'aria-invalid'     => true,
		'aria-label'       => true,
		'aria-labelledby'  => true,
		'aria-live'        => true,
		'aria-modal'       => true,
		'aria-pressed'     => true,
		'aria-relevant'    => true,
		'aria-required'    => true,
		'aria-selected'    => true,
		'aria-valuemax'    => true,
		'aria-valuemin'    => true,
		'aria-valuenow'    => true,
		'data-*'           => true,
	);

	/**
	 * Cached lists, built once per request.
	 *
	 * @var array<string, array<string, array<string, bool>>>
	 */
	private static $cache = array();

	/**
	 * Markup of values, prices, titles and images.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function markup(): array {
		if ( ! isset( self::$cache['markup'] ) ) {
			self::$cache['markup'] = self::build(
				array(
					// wc_price() wraps the amount in <bdi> and marks the
					// currency symbol translate="no".
					'bdi'  => array(),
					'img'  => array(
						'src'           => true,
						'srcset'        => true,
						'sizes'         => true,
						'alt'           => true,
						'width'         => true,
						'height'        => true,
						'loading'       => true,
						'decoding'      => true,
						'fetchpriority' => true,
					),
					'svg'  => array(
						'xmlns'     => true,
						'viewbox'   => true,
						'width'     => true,
						'height'    => true,
						'fill'      => true,
						'focusable' => true,
					),
					'path' => array(
						'd'    => true,
						'fill' => true,
					),
				)
			);
		}

		return self::$cache['markup'];
	}

	/**
	 * Markup of the WCDP donation form and leaderboard.
	 *
	 * The form embeds WooCommerce checkout fields and one `<style id="wcdp-css">`
	 * block of colour variables that WCDP builds from sanitize_hex_color().
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function form(): array {
		if ( ! isset( self::$cache['form'] ) ) {
			$field = array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'checked'      => true,
				'selected'     => true,
				'disabled'     => true,
				'readonly'     => true,
				'required'     => true,
				'placeholder'  => true,
				'autocomplete' => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'pattern'      => true,
				'inputmode'    => true,
				'maxlength'    => true,
				'size'         => true,
				'multiple'     => true,
				'for'          => true,
				'form'         => true,
				'label'        => true,
				'rows'         => true,
				'cols'         => true,
			);

			self::$cache['form'] = self::build(
				array_merge(
					self::markup(),
					array(
						'form'                        => array(
							'action'         => true,
							'method'         => true,
							'enctype'        => true,
							'name'           => true,
							'target'         => true,
							'novalidate'     => true,
							'autocomplete'   => true,
							'accept-charset' => true,
						),
						'input'                       => $field,
						'select'                      => $field,
						'option'                      => $field,
						'optgroup'                    => $field,
						'textarea'                    => $field,
						'button'                      => $field,
						'label'                       => $field,
						'fieldset'                    => $field,
						'legend'                      => array(),
						'noscript'                    => array(),
						'style'                       => array(),
						// WooCommerce order attribution: a custom element its
						// own script fills with hidden inputs.
						'wc-order-attribution-inputs' => array(),
					)
				)
			);
		}

		return self::$cache['form'];
	}

	/**
	 * Merge extra tags into the post list and add the global attributes.
	 *
	 * @param array<string, array<string, bool>> $extra Tag => attributes.
	 * @return array<string, array<string, bool>>
	 */
	private static function build( array $extra ): array {
		$allowed = wp_kses_allowed_html( 'post' );

		foreach ( $extra as $tag => $attributes ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $attributes );
		}

		foreach ( $allowed as $tag => $attributes ) {
			$allowed[ $tag ] = array_merge( self::GLOBAL_ATTRIBUTES, is_array( $attributes ) ? $attributes : array() );
		}

		return $allowed;
	}
}
