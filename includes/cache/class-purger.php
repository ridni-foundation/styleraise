<?php
/**
 * Page-cache purging across every common caching plugin.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells whatever page cache is installed that a campaign's pages just changed.
 *
 * A donation does not update any post, so no caching plugin has a reason to
 * rebuild the pages that show the campaign — they keep serving the figure that
 * was current when the file was written. That is the single biggest cause of a
 * live fundraiser looking dead.
 *
 * Every adapter is guarded by a `function_exists` / `class_exists` check, so an
 * API that has moved in a newer release degrades to "this adapter does
 * nothing" rather than to a fatal error.
 */
final class Purger {

	/**
	 * Transient guarding the purge rate.
	 */
	const THROTTLE_KEY = 'wcdp_el_purge_throttle';

	/**
	 * Option holding the last purge report, for the diagnostics panel.
	 */
	const LAST_PURGE_OPTION = 'wcdp_el_last_purge';

	/**
	 * Product IDs queued for purging in this request.
	 *
	 * @var int[]
	 */
	private static $queue = array();

	/**
	 * Whether the shutdown handler is already attached.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Every supported caching layer.
	 *
	 * @return array<string, array{label:string,active:bool,posts:callable|null,urls:callable|null,all:callable|null}>
	 */
	public static function adapters(): array {
		$adapters = array();

		/* ── WP Rocket ── */
		$adapters['wp-rocket'] = array(
			'label'  => 'WP Rocket',
			'active' => function_exists( 'rocket_clean_post' ) || defined( 'WP_ROCKET_VERSION' ),
			'posts'  => static function ( array $ids ): void {
				if ( ! function_exists( 'rocket_clean_post' ) ) {
					return;
				}

				foreach ( $ids as $id ) {
					rocket_clean_post( $id );
				}
			},
			'urls'   => static function ( array $urls ): void {
				if ( function_exists( 'rocket_clean_files' ) ) {
					rocket_clean_files( $urls );
				}
			},
			'all'    => static function (): void {
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
			},
		);

		/* ── LiteSpeed Cache ── */
		$adapters['litespeed'] = array(
			'label'  => 'LiteSpeed Cache',
			'active' => defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' ),
			'posts'  => static function ( array $ids ): void {
				foreach ( $ids as $id ) {
					do_action( 'litespeed_purge_post', $id );
				}
			},
			'urls'   => static function ( array $urls ): void {
				foreach ( $urls as $url ) {
					do_action( 'litespeed_purge_url', $url );
				}
			},
			'all'    => static function (): void {
				do_action( 'litespeed_purge_all', WCDP_EL_NAME );
			},
		);

		/* ── W3 Total Cache ── */
		$adapters['w3-total-cache'] = array(
			'label'  => 'W3 Total Cache',
			'active' => defined( 'W3TC' ) || function_exists( 'w3tc_flush_all' ),
			'posts'  => static function ( array $ids ): void {
				if ( ! function_exists( 'w3tc_flush_post' ) ) {
					return;
				}

				foreach ( $ids as $id ) {
					w3tc_flush_post( $id );
				}
			},
			'urls'   => static function ( array $urls ): void {
				if ( ! function_exists( 'w3tc_flush_url' ) ) {
					return;
				}

				foreach ( $urls as $url ) {
					w3tc_flush_url( $url );
				}
			},
			'all'    => static function (): void {
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
				}
			},
		);

		/* ── WP Super Cache ── */
		$adapters['wp-super-cache'] = array(
			'label'  => 'WP Super Cache',
			'active' => function_exists( 'wp_cache_post_change' ),
			'posts'  => static function ( array $ids ): void {
				if ( ! function_exists( 'wp_cache_post_change' ) ) {
					return;
				}

				foreach ( $ids as $id ) {
					wp_cache_post_change( $id );
				}
			},
			'urls'   => static function ( array $urls ): void {
				if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
					return;
				}

				foreach ( $urls as $url ) {
					wpsc_delete_url_cache( $url );
				}
			},
			'all'    => static function (): void {
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
				}
			},
		);

		/* ── Cache Enabler ── */
		$adapters['cache-enabler'] = array(
			'label'  => 'Cache Enabler',
			'active' => class_exists( '\Cache_Enabler' ),
			'posts'  => static function ( array $ids ): void {
				if ( ! method_exists( '\Cache_Enabler', 'clear_page_cache_by_post_id' ) ) {
					return;
				}

				foreach ( $ids as $id ) {
					\Cache_Enabler::clear_page_cache_by_post_id( $id );
				}
			},
			'urls'   => static function ( array $urls ): void {
				if ( ! method_exists( '\Cache_Enabler', 'clear_page_cache_by_url' ) ) {
					return;
				}

				foreach ( $urls as $url ) {
					\Cache_Enabler::clear_page_cache_by_url( $url );
				}
			},
			'all'    => static function (): void {
				if ( method_exists( '\Cache_Enabler', 'clear_complete_cache' ) ) {
					\Cache_Enabler::clear_complete_cache();
				}
			},
		);

		/* ── SiteGround Optimizer ── */
		$adapters['sg-optimizer'] = array(
			'label'  => 'SiteGround Optimizer',
			'active' => defined( 'SiteGround_Optimizer\VERSION' ) || function_exists( 'sg_cachepress_purge_cache' ),
			'posts'  => null,
			'urls'   => static function ( array $urls ): void {
				foreach ( $urls as $url ) {
					do_action( 'siteground_optimizer_flush_cache', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: SiteGround Optimizer.
				}
			},
			'all'    => static function (): void {
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
				}
			},
		);

		/* ── Nginx Helper ── */
		$adapters['nginx-helper'] = array(
			'label'  => 'Nginx Helper',
			'active' => defined( 'NGINX_HELPER_BASENAME' ) || class_exists( '\Nginx_Helper' ),
			'posts'  => null,
			'urls'   => static function ( array $urls ): void {
				foreach ( $urls as $url ) {
					do_action( 'rt_nginx_helper_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Nginx Helper.
				}
			},
			'all'    => static function (): void {
				do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Nginx Helper.
			},
		);

		/* ── Hummingbird ── */
		$adapters['hummingbird'] = array(
			'label'  => 'Hummingbird',
			'active' => class_exists( '\Hummingbird\WP_Hummingbird' ) || defined( 'WPHB_VERSION' ),
			'posts'  => static function ( array $ids ): void {
				foreach ( $ids as $id ) {
					do_action( 'wphb_clear_page_cache', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Hummingbird.
				}
			},
			'urls'   => null,
			'all'    => static function (): void {
				do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Hummingbird.
			},
		);

		/* ── Breeze ── */
		$adapters['breeze'] = array(
			'label'  => 'Breeze',
			'active' => class_exists( '\Breeze_PurgeCache' ) || defined( 'BREEZE_VERSION' ),
			'posts'  => null,
			'urls'   => null,
			'all'    => static function (): void {
				do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Breeze.
			},
		);

		/* ── WP Engine ── */
		$adapters['wp-engine'] = array(
			'label'  => 'WP Engine',
			'active' => class_exists( '\WpeCommon' ),
			'posts'  => static function ( array $ids ): void {
				if ( ! method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
					return;
				}

				foreach ( $ids as $id ) {
					\WpeCommon::purge_varnish_cache( $id );
				}
			},
			'urls'   => null,
			'all'    => static function (): void {
				if ( method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
			},
		);

		/* ── Kinsta ── */
		$adapters['kinsta'] = array(
			'label'  => 'Kinsta',
			'active' => class_exists( '\Kinsta\Cache' ) || defined( 'KINSTAMU_VERSION' ),
			'posts'  => static function ( array $ids ): void {
				foreach ( $ids as $id ) {
					do_action( 'kinsta_cache_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Kinsta MU plugin.
				}
			},
			'urls'   => null,
			'all'    => static function (): void {
				do_action( 'kinsta_cache_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: Kinsta MU plugin.
			},
		);

		/* ── Cloudflare and anything else listening to core signals ── */
		$adapters['generic'] = array(
			'label'  => esc_html__( 'Core purge signal', 'styleraise' ),
			'active' => true,
			'posts'  => static function ( array $ids ): void {
				// The official Cloudflare plugin, most host-level caches and a
				// long tail of smaller plugins listen for this rather than
				// exposing an API of their own.
				foreach ( $ids as $id ) {
					clean_post_cache( $id );
				}
			},
			'urls'   => null,
			'all'    => null,
		);

		/**
		 * Filter the caching adapters.
		 *
		 * Add an entry to support a cache this plugin does not know about; the
		 * shape is documented on this method.
		 *
		 * @param array $adapters Adapter definitions keyed by slug.
		 */
		return (array) apply_filters( 'wcdp_el_cache_adapters', $adapters );
	}

	/**
	 * Adapters that are actually installed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function active_adapters(): array {
		return array_filter(
			self::adapters(),
			static function ( $adapter ) {
				return ! empty( $adapter['active'] );
			}
		);
	}

	/**
	 * Queue a campaign for purging at the end of the request.
	 *
	 * Deferring to `shutdown` collapses a burst of hooks fired by one order
	 * into a single purge.
	 *
	 * @param int $product_id Campaign product ID.
	 */
	public static function queue( int $product_id ): void {
		if ( $product_id > 0 ) {
			self::$queue[ $product_id ] = $product_id;
		}

		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'shutdown', array( __CLASS__, 'run_queue' ), 100 );
	}

	/**
	 * Purge everything queued in this request.
	 */
	public static function run_queue(): void {
		if ( empty( self::$queue ) ) {
			return;
		}

		$product_ids = array_values( self::$queue );

		self::$queue = array();

		self::purge_campaigns( $product_ids );
	}

	/**
	 * Purge the pages showing the given campaigns.
	 *
	 * @param int[] $product_ids Campaign product IDs.
	 * @param bool  $force       Ignore the throttle.
	 * @return array{purged:bool,reason:string,posts:int,urls:int,adapters:string[]}
	 */
	public static function purge_campaigns( array $product_ids, bool $force = false ): array {
		$config = Freshness::config();

		$report = array(
			'purged'   => false,
			'reason'   => '',
			'posts'    => 0,
			'urls'     => 0,
			'adapters' => array(),
		);

		if ( Freshness::PURGE_OFF === $config['purge'] ) {
			$report['reason'] = 'disabled';

			return $report;
		}

		if ( ! $force && ! self::claim_throttle( $config['purge_throttle'] ) ) {
			$report['reason'] = 'throttled';

			return $report;
		}

		$product_ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );

		if ( $config['force_recalc'] ) {
			self::force_recalculation( $product_ids );
		}

		if ( Freshness::PURGE_ALL === $config['purge'] ) {
			$report['adapters'] = self::purge_all();
			$report['purged']   = true;
			$report['reason']   = 'all';

			self::record( $report );

			return $report;
		}

		$post_ids = array();
		$urls     = array();

		if ( Freshness::PURGE_INDEX === $config['purge'] ) {
			$post_ids = Location_Index::posts_for( $product_ids );

			// The campaign's own product page always shows its figures, whether
			// or not a widget ever recorded it.
			foreach ( $product_ids as $product_id ) {
				$post_ids[] = $product_id;
			}
		}

		$urls = array_merge( $urls, $config['purge_urls'] );

		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
		$urls     = array_values( array_unique( array_filter( $urls ) ) );

		if ( empty( $post_ids ) && empty( $urls ) ) {
			$report['reason'] = 'nothing-to-purge';

			return $report;
		}

		$report['adapters'] = self::dispatch( $post_ids, $urls );
		$report['posts']    = count( $post_ids );
		$report['urls']     = count( $urls );
		$report['purged']   = true;
		$report['reason']   = $config['purge'];

		self::record( $report );

		/**
		 * Fires after campaign pages have been purged.
		 *
		 * @param int[] $product_ids Campaigns that triggered the purge.
		 * @param int[] $post_ids    Posts whose cache was cleared.
		 * @param array $report      Purge report.
		 */
		do_action( 'wcdp_el_cache_purged', $product_ids, $post_ids, $report );

		return $report;
	}

	/**
	 * Send the purge to every active adapter.
	 *
	 * @param int[]    $post_ids Post IDs to clear.
	 * @param string[] $urls     URLs to clear.
	 * @return string[] Slugs of adapters that did something.
	 */
	private static function dispatch( array $post_ids, array $urls ): array {
		$used = array();

		foreach ( self::active_adapters() as $slug => $adapter ) {
			$did = false;

			if ( ! empty( $post_ids ) && isset( $adapter['posts'] ) && is_callable( $adapter['posts'] ) ) {
				call_user_func( $adapter['posts'], $post_ids );
				$did = true;
			}

			if ( ! empty( $urls ) && isset( $adapter['urls'] ) && is_callable( $adapter['urls'] ) ) {
				call_user_func( $adapter['urls'], $urls );
				$did = true;
			}

			if ( $did ) {
				$used[] = (string) $slug;
			}
		}

		return $used;
	}

	/**
	 * Flush every active page cache completely.
	 *
	 * @return string[] Slugs of adapters that ran.
	 */
	public static function purge_all(): array {
		$used = array();

		foreach ( self::active_adapters() as $slug => $adapter ) {
			if ( isset( $adapter['all'] ) && is_callable( $adapter['all'] ) ) {
				call_user_func( $adapter['all'] );
				$used[] = (string) $slug;
			}
		}

		return $used;
	}

	/**
	 * Drop WCDP's cached revenue so the next render recalculates it.
	 *
	 * WCDP normally clears this itself within ~30 seconds of a donation, via
	 * WooCommerce Analytics. This is the belt to that braces, for sites where
	 * Analytics is disabled or lagging — at the cost of one heavy join on the
	 * next uncached render, which is why it is off outside the live pattern.
	 *
	 * @param int[] $product_ids Campaign product IDs.
	 */
	private static function force_recalculation( array $product_ids ): void {
		foreach ( $product_ids as $product_id ) {
			delete_post_meta( $product_id, 'wcdp_total_revenue' );
			delete_transient( 'wcdp_order_counter_' . $product_id );
		}
	}

	/**
	 * Claim the throttle slot.
	 *
	 * @param int $throttle Seconds between purges.
	 * @return bool True when this call may purge.
	 */
	private static function claim_throttle( int $throttle ): bool {
		if ( $throttle <= 0 ) {
			return true;
		}

		if ( get_transient( self::THROTTLE_KEY ) ) {
			return false;
		}

		set_transient( self::THROTTLE_KEY, time(), $throttle );

		return true;
	}

	/**
	 * Store the last purge for the diagnostics panel.
	 *
	 * @param array<string, mixed> $report Purge report.
	 */
	private static function record( array $report ): void {
		$report['time'] = time();

		update_option( self::LAST_PURGE_OPTION, $report, false );
	}

	/**
	 * The last recorded purge.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_purge() {
		$report = get_option( self::LAST_PURGE_OPTION, null );

		return is_array( $report ) ? $report : null;
	}
}
