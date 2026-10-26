<?php
/**
 * Freshness patterns: how hard the addon fights page caching.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Cache;

use WCDP_Elementor\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the site's freshness pattern into one concrete configuration array.
 *
 * Campaign figures are volatile while the HTML that carries them is cached for
 * hours, so "how fresh is fresh enough" is a site-wide policy rather than a
 * per-widget style choice. This class is the single place that policy is
 * expressed; everything else — the REST endpoint, the hydration script, the
 * purger — reads its answer and never second-guesses it.
 */
final class Freshness {

	/**
	 * Hydration levels, from cheapest to most aggressive. Each includes the
	 * previous one.
	 */
	const HYDRATE_OFF      = 'off';
	const HYDRATE_LOAD     = 'load';
	const HYDRATE_FOCUS    = 'focus';
	const HYDRATE_INTERVAL = 'interval';

	/**
	 * Purge targeting strategies.
	 */
	const PURGE_OFF   = 'off';
	const PURGE_INDEX = 'index';
	const PURGE_URLS  = 'urls';
	const PURGE_ALL   = 'all';

	/**
	 * Memoized resolved configuration.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $resolved = null;

	/**
	 * The prebuilt patterns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function patterns(): array {
		return array(
			// Ad-driven push: the number visibly moves, which is the social
			// proof a live campaign runs on.
			'live'     => array(
				'hydrate'        => self::HYDRATE_INTERVAL,
				'interval'       => 30,
				'purge'          => self::PURGE_INDEX,
				'purge_throttle' => 15,
				'data_ttl'       => 60,
				'force_recalc'   => true,
			),
			// Everyday default: one request per page view, no polling.
			'balanced' => array(
				'hydrate'        => self::HYDRATE_FOCUS,
				'interval'       => 0,
				'purge'          => self::PURGE_INDEX,
				'purge_throttle' => 60,
				'data_ttl'       => 300,
				'force_recalc'   => false,
			),
			// No JavaScript and no REST traffic at all; correctness rests
			// entirely on purging.
			'fast'     => array(
				'hydrate'        => self::HYDRATE_OFF,
				'interval'       => 0,
				'purge'          => self::PURGE_INDEX,
				'purge_throttle' => 300,
				'data_ttl'       => 0,
				'force_recalc'   => false,
			),
		);
	}

	/**
	 * Human labels for the pattern picker.
	 *
	 * @return array<string, array{title:string,text:string}>
	 */
	public static function pattern_labels(): array {
		return array(
			'live'     => array(
				'title' => esc_html__( 'Live campaign', 'styleraise' ),
				'text'  => esc_html__( 'Figures refresh every 30 seconds while the tab is open, and caches are purged the moment a donation lands. Use it while a fundraiser is under paid traffic.', 'styleraise' ),
			),
			'balanced' => array(
				'title' => esc_html__( 'Balanced', 'styleraise' ),
				'text'  => esc_html__( 'Figures refresh when the page loads and when the visitor returns to the tab. One small request per page view. The right choice for almost every site.', 'styleraise' ),
			),
			'fast'     => array(
				'title' => esc_html__( 'Maximum speed', 'styleraise' ),
				'text'  => esc_html__( 'No JavaScript and no requests. Freshness relies on cache purging alone, so a visitor holding a page open — or one served from their browser cache — keeps the old number.', 'styleraise' ),
			),
			'custom'   => array(
				'title' => esc_html__( 'Custom', 'styleraise' ),
				'text'  => esc_html__( 'Every knob exposed individually.', 'styleraise' ),
			),
		);
	}

	/**
	 * The active pattern key.
	 *
	 * @return string
	 */
	public static function pattern(): string {
		$pattern = (string) Options::get( 'freshness_pattern', 'balanced' );

		if ( 'custom' === $pattern || isset( self::patterns()[ $pattern ] ) ) {
			return $pattern;
		}

		return 'balanced';
	}

	/**
	 * The resolved configuration for this site.
	 *
	 * @return array{hydrate:string,interval:int,purge:string,purge_throttle:int,purge_urls:string[],data_ttl:int,force_recalc:bool}
	 */
	public static function config(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$pattern  = self::pattern();
		$patterns = self::patterns();

		if ( 'custom' === $pattern ) {
			$config = array(
				'hydrate'        => (string) Options::get( 'freshness_hydrate', self::HYDRATE_FOCUS ),
				'interval'       => (int) Options::get( 'freshness_interval', 0 ),
				'purge'          => (string) Options::get( 'freshness_purge', self::PURGE_INDEX ),
				'purge_throttle' => (int) Options::get( 'freshness_purge_throttle', 60 ),
				'data_ttl'       => (int) Options::get( 'freshness_data_ttl', 0 ),
				'force_recalc'   => (bool) Options::get( 'freshness_force_recalc', false ),
			);
		} else {
			$config = $patterns[ $pattern ];
		}

		$config['purge_urls'] = self::purge_urls();

		$config = self::sanitize_config( $config );

		/**
		 * Filter the resolved freshness configuration.
		 *
		 * Lets a site override the policy in code, for example to switch to the
		 * live pattern only on specific campaign templates.
		 *
		 * @param array  $config  Resolved configuration.
		 * @param string $pattern Active pattern key.
		 */
		self::$resolved = (array) apply_filters( 'wcdp_el_freshness_config', $config, $pattern );

		return self::$resolved;
	}

	/**
	 * Clamp every value into a sane range.
	 *
	 * @param array<string, mixed> $config Raw configuration.
	 * @return array<string, mixed>
	 */
	private static function sanitize_config( array $config ): array {
		$hydrate = (string) ( $config['hydrate'] ?? self::HYDRATE_FOCUS );

		if ( ! in_array( $hydrate, array( self::HYDRATE_OFF, self::HYDRATE_LOAD, self::HYDRATE_FOCUS, self::HYDRATE_INTERVAL ), true ) ) {
			$hydrate = self::HYDRATE_FOCUS;
		}

		$purge = (string) ( $config['purge'] ?? self::PURGE_INDEX );

		if ( ! in_array( $purge, array( self::PURGE_OFF, self::PURGE_INDEX, self::PURGE_URLS, self::PURGE_ALL ), true ) ) {
			$purge = self::PURGE_INDEX;
		}

		$interval = (int) ( $config['interval'] ?? 0 );

		// A floor of 10 seconds: anything shorter turns every open tab into a
		// load generator without telling the visitor anything new.
		if ( self::HYDRATE_INTERVAL === $hydrate ) {
			$interval = max( 10, min( 3600, $interval ?: 30 ) );
		} else {
			$interval = 0;
		}

		return array(
			'hydrate'        => $hydrate,
			'interval'       => $interval,
			'purge'          => $purge,
			'purge_throttle' => max( 0, min( 3600, (int) ( $config['purge_throttle'] ?? 60 ) ) ),
			'purge_urls'     => array_values( (array) ( $config['purge_urls'] ?? array() ) ),
			'data_ttl'       => max( 0, min( 6 * HOUR_IN_SECONDS, (int) ( $config['data_ttl'] ?? 0 ) ) ),
			'force_recalc'   => (bool) ( $config['force_recalc'] ?? false ),
		);
	}

	/**
	 * Manually configured purge URLs.
	 *
	 * @return string[]
	 */
	private static function purge_urls(): array {
		$raw = Options::get( 'freshness_purge_urls', array() );

		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n]+/', $raw ) ?: array();
		}

		$urls = array();

		foreach ( (array) $raw as $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			// Relative paths are accepted so a site can be moved between
			// domains without rewriting the list.
			$urls[] = ( 0 === strpos( $url, 'http' ) ) ? esc_url_raw( $url ) : home_url( $url );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Whether the hydration script should be loaded at all.
	 *
	 * @return bool
	 */
	public static function hydration_enabled(): bool {
		return self::HYDRATE_OFF !== self::config()['hydrate'];
	}

	/**
	 * Whether purging is enabled.
	 *
	 * @return bool
	 */
	public static function purge_enabled(): bool {
		return self::PURGE_OFF !== self::config()['purge'];
	}

	/**
	 * Apply the configured TTL to WCDP's own transient caches.
	 *
	 * WCDP caches the donation counter and the leaderboard for six hours behind
	 * `wcdp_cache_expiration`. The revenue total is a separate mechanism: WCDP
	 * drops it within ~30 seconds of a donation through WooCommerce Analytics,
	 * so this filter deliberately does not try to shorten that.
	 */
	public static function apply_data_ttl(): void {
		$ttl = self::config()['data_ttl'];

		if ( $ttl <= 0 ) {
			return;
		}

		add_filter(
			'wcdp_cache_expiration',
			static function () use ( $ttl ) {
				return $ttl;
			},
			20
		);
	}

	/**
	 * Drop the memoized configuration.
	 */
	public static function flush(): void {
		self::$resolved = null;
	}
}
