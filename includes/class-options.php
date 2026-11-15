<?php
/**
 * Plugin-wide options.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin accessor over the single option row the addon owns.
 *
 * Only settings that must influence *control defaults* live here — anything a
 * user tunes per widget belongs in Elementor, not in a settings page.
 */
final class Options {

	/**
	 * Option name.
	 */
	const OPTION = 'wcdp_el_settings';

	/**
	 * Memoized option value.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Widget names the user has switched off in the editor panel.
			'disabled_widgets'         => array(),

			// How hard the addon works to keep figures fresh behind a page
			// cache. See \WCDP_Elementor\Cache\Freshness for what each
			// pattern resolves to.
			'freshness_pattern'        => 'balanced',

			// Only consulted when the pattern is `custom`.
			'freshness_hydrate'        => 'focus',
			'freshness_interval'       => 30,
			'freshness_purge'          => 'index',
			'freshness_purge_throttle' => 60,
			'freshness_purge_urls'     => '',
			'freshness_data_ttl'       => 0,
			'freshness_force_recalc'   => false,

			// Keeps the donation form's script out of "delay JavaScript until
			// interaction": `auto`, `attributes` or `off`.
			'script_guard'             => 'auto',

			// Show WCDP's real form on the Elementor editor canvas: the editor
			// bootstrap gets a WooCommerce cart for that request only.
			'editor_form_preview'      => true,
		);
	}

	/**
	 * Read the full option array.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;

		update_option( self::OPTION, $all );

		self::$cache = $all;
	}

	/**
	 * Whether a widget is switched off.
	 *
	 * @param string $widget_name Elementor widget name.
	 * @return bool
	 */
	public static function is_widget_disabled( string $widget_name ): bool {
		$disabled = self::get( 'disabled_widgets', array() );

		return is_array( $disabled ) && in_array( $widget_name, $disabled, true );
	}

	/**
	 * Drop the memoized value.
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
