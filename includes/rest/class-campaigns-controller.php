<?php
/**
 * REST endpoint serving fresh campaign figures.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Rest;

use WCDP_Elementor\Cache\Freshness;
use WCDP_Elementor\Data\Campaign;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Data\Value_Formatter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The channel that makes page caching harmless.
 *
 * Cached HTML can be hours old; this route is never page-cached, so the figures
 * it returns are current and the browser patches them into whatever markup it
 * was served. That is why a visitor arriving from an ad on a ten-hour-old
 * cached page still sees the real total.
 *
 * The route is unauthenticated on purpose: cached pages are served to anonymous
 * visitors, and those are exactly the requests it exists for. It answers only
 * for campaigns a visitor could reach anyway — published products without a
 * password, see is_public() — and it is rate-bounded by a short server-side
 * micro-cache, because each uncached item can trigger WCDP's revenue join.
 * The micro-cache is one transient with at most CACHE_MAX_ENTRIES campaigns,
 * keyed by the normalised campaign, so no request can add rows or grow it.
 */
final class Campaigns_Controller {

	/**
	 * Route namespace.
	 */
	const NAMESPACE = 'wcdp-el/v1';

	/**
	 * Route path.
	 */
	const ROUTE = '/campaigns';

	/**
	 * Most campaigns answered in one request.
	 */
	const MAX_ITEMS = 10;

	/**
	 * Most additional product IDs accepted per campaign.
	 */
	const MAX_ADDIDS = 20;

	/**
	 * The single transient holding the micro-cache.
	 */
	const CACHE_TRANSIENT = 'wcdp_el_api_cache';

	/**
	 * Most campaigns the micro-cache keeps; the oldest are dropped first.
	 */
	const CACHE_MAX_ENTRIES = 20;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * The public URL of the route.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaigns' ),
					// Intentionally public: the callers are anonymous visitors
					// of cached pages. Private, draft and password-protected
					// campaigns are refused per item in build_item().
					'permission_callback' => '__return_true',
					'args'                => array(
						'q' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'JSON-encoded list of campaigns to refresh.', 'styleraise' ),
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && strlen( $value ) <= 8192;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Answer a refresh request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_campaigns( WP_REST_Request $request ): WP_REST_Response {
		$requested = json_decode( (string) $request->get_param( 'q' ), true );

		if ( ! is_array( $requested ) ) {
			return $this->respond( array( 'items' => new \stdClass() ) );
		}

		$requested = array_slice( $requested, 0, self::MAX_ITEMS );

		/**
		 * Filter how long a campaign's figures are reused server-side.
		 *
		 * This bounds the database work an unauthenticated endpoint can cause,
		 * while still being orders of magnitude fresher than any page cache.
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl   = (int) apply_filters( 'wcdp_el_api_cache_ttl', 15 );
		$now   = time();
		$store = $ttl > 0 ? get_transient( self::CACHE_TRANSIENT ) : array();
		$store = is_array( $store ) ? $store : array();

		// Entries carry their own time: the transient's expiry restarts on
		// every write, so it cannot age individual campaigns.
		$store = array_filter(
			$store,
			static function ( $entry ) use ( $now, $ttl ) {
				return is_array( $entry ) && isset( $entry['t'], $entry['p'] ) && $entry['t'] > $now - $ttl;
			}
		);

		$items  = array();
		$missed = false;

		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$key = isset( $item['k'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $item['k'] ), 0, 64 ) : '';

			if ( '' === $key ) {
				continue;
			}

			$campaign = $this->normalize_item( $item );

			if ( null === $campaign ) {
				continue;
			}

			$entry_key = $this->entry_key( $campaign );

			if ( isset( $store[ $entry_key ] ) ) {
				$items[ $key ] = $store[ $entry_key ]['p'];

				continue;
			}

			$missed  = true;
			$payload = $this->build_item( $campaign );

			if ( null !== $payload ) {
				$items[ $key ]       = $payload;
				$store[ $entry_key ] = array(
					't' => $now,
					'p' => $payload,
				);
			}
		}

		if ( $missed && $ttl > 0 ) {
			// New entries are appended, so the last ones are the newest. Keep
			// those: the cache never holds more than CACHE_MAX_ENTRIES
			// campaigns whatever the requests ask for. (Sorting by `t` would
			// not do — entries written within one second tie.)
			set_transient( self::CACHE_TRANSIENT, array_slice( $store, -self::CACHE_MAX_ENTRIES, null, true ), $ttl );
		}

		$body = array(
			'items' => empty( $items ) ? new \stdClass() : $items,
			'ts'    => $now,
		);

		return $this->respond( $body, ! $missed && ! empty( $items ) );
	}

	/**
	 * Validate one requested campaign and reduce it to a canonical form.
	 *
	 * Everything the cache key is built from passes through here: unknown
	 * fields are dropped, numbers are cast and rounded, additional IDs are
	 * sorted and deduplicated, so equivalent requests share one entry.
	 *
	 * @param array<string, mixed> $item Requested item.
	 * @return array<string, mixed>|null Null when the campaign may not be served.
	 */
	private function normalize_item( array $item ) {
		$product_id = absint( $item['id'] ?? 0 );

		if ( ! $product_id || ! self::is_public( $product_id ) || ! Product_Resolver::is_donable( $product_id ) ) {
			return null;
		}

		$addids = array();

		if ( ! empty( $item['add'] ) && is_array( $item['add'] ) ) {
			$addids = array_unique( array_filter( array_slice( array_map( 'absint', $item['add'] ), 0, self::MAX_ADDIDS ) ) );
			$addids = array_values( array_diff( $addids, array( $product_id ) ) );
			sort( $addids );
		}

		// One non-public product refuses the whole campaign rather than being
		// dropped from it: otherwise the difference between two answers would
		// reveal the total of the product that was left out. The page keeps
		// the figures it was rendered with.
		foreach ( $addids as $addid ) {
			if ( ! self::is_public( $addid ) ) {
				return null;
			}
		}

		$goal  = $item['goal'] ?? '';
		$cheat = $item['cheat'] ?? 0;

		return array(
			'id'    => $product_id,
			'add'   => $addids,
			'goal'  => is_numeric( $goal ) ? round( (float) $goal, 2 ) : '',
			'cheat' => is_numeric( $cheat ) ? round( (float) $cheat, 2 ) : 0.0,
			'fmt'   => Value_Formatter::sanitize_fmt( is_array( $item['fmt'] ?? null ) ? $item['fmt'] : array() ),
		);
	}

	/**
	 * Build the payload for one normalised campaign.
	 *
	 * @param array<string, mixed> $campaign Output of normalize_item().
	 * @return array<string, mixed>|null
	 */
	private function build_item( array $campaign ) {
		$fmt = $campaign['fmt'];

		$campaign = Campaign::get(
			$campaign['id'],
			array(
				'goal'              => $campaign['goal'],
				'cheat'             => $campaign['cheat'],
				'addids'            => $campaign['add'],
				'currency_position' => $fmt['cp'],
			)
		);

		if ( ! $campaign ) {
			return null;
		}

		return array(
			'values' => Value_Formatter::values( $campaign, $fmt ),
			'state'  => Value_Formatter::state( $campaign ),
		);
	}

	/**
	 * Whether anyone may see a product's figures.
	 *
	 * True for a published product without a password — what an anonymous
	 * visitor can open anyway. A variation must be enabled itself and belong
	 * to such a product. Drafts, private, pending and trashed products, and
	 * IDs that are not products at all, are refused.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	private static function is_public( int $product_id ): bool {
		$post = get_post( $product_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'product_variation' === $post->post_type ) {
			if ( 'publish' !== $post->post_status ) {
				return false;
			}

			$post = get_post( (int) $post->post_parent );

			if ( ! $post instanceof \WP_Post ) {
				return false;
			}
		}

		return 'product' === $post->post_type
			&& 'publish' === $post->post_status
			&& '' === $post->post_password;
	}

	/**
	 * Micro-cache key of one normalised campaign.
	 *
	 * The currency and locale are part of the key: with a multi-currency or
	 * multilingual plugin active, two visitors asking for the same campaign
	 * must not be served each other's formatted strings.
	 *
	 * @param array<string, mixed> $campaign Output of normalize_item().
	 * @return string
	 */
	private function entry_key( array $campaign ): string {
		$context = array(
			$campaign,
			function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			determine_locale(),
		);

		return md5( (string) wp_json_encode( $context ) );
	}

	/**
	 * Wrap a body in a response that nothing is allowed to cache.
	 *
	 * @param array<string, mixed> $body   Response body.
	 * @param bool                 $cached Whether it came from the micro-cache.
	 * @return WP_REST_Response
	 */
	private function respond( array $body, bool $cached = false ): WP_REST_Response {
		$response = new WP_REST_Response( $body, 200 );

		// Browsers, proxies and CDNs each need telling separately. `Vary:
		// Cookie` matters most: a currency switcher keys off a cookie, so a
		// shared cache must never reuse one visitor's amounts for another.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'CDN-Cache-Control', 'no-store' );
		$response->header( 'Cloudflare-CDN-Cache-Control', 'no-store' );
		$response->header( 'Vary', 'Cookie, Accept-Language' );
		$response->header( 'X-WCDP-EL-Cached', $cached ? '1' : '0' );

		// LiteSpeed caches REST responses when asked to; opt this route out.
		do_action( 'litespeed_control_set_nocache', 'WCDP Elementor live figures' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook: LiteSpeed Cache.

		return $response;
	}
}
