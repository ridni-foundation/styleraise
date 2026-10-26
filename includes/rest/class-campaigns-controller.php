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
 * Everything it returns is already public on the page that asks for it, so the
 * route is unauthenticated — but it is rate-bounded by a short server-side
 * micro-cache, because each uncached item can trigger WCDP's revenue join.
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
					// Every figure returned here is already rendered on the
					// public page making the request.
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

		$cache_key = $this->cache_key( $requested );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $this->respond( $cached, true );
		}

		$items = array();

		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$key = isset( $item['k'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $item['k'] ), 0, 64 ) : '';

			if ( '' === $key ) {
				continue;
			}

			$payload = $this->build_item( $item );

			if ( null !== $payload ) {
				$items[ $key ] = $payload;
			}
		}

		$body = array(
			'items' => empty( $items ) ? new \stdClass() : $items,
			'ts'    => time(),
		);

		/**
		 * Filter how long a hydration response is reused server-side.
		 *
		 * This bounds the database work an unauthenticated endpoint can cause,
		 * while still being orders of magnitude fresher than any page cache.
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl = (int) apply_filters( 'wcdp_el_api_cache_ttl', 15 );

		if ( $ttl > 0 ) {
			set_transient( $cache_key, $body, $ttl );
		}

		return $this->respond( $body );
	}

	/**
	 * Build the payload for one requested campaign.
	 *
	 * @param array<string, mixed> $item Requested item.
	 * @return array<string, mixed>|null
	 */
	private function build_item( array $item ) {
		$product_id = absint( $item['id'] ?? 0 );

		if ( ! $product_id || ! Product_Resolver::is_donable( $product_id ) ) {
			return null;
		}

		$addids = array();

		if ( ! empty( $item['add'] ) && is_array( $item['add'] ) ) {
			$addids = array_slice( array_map( 'absint', $item['add'] ), 0, self::MAX_ADDIDS );
		}

		$fmt = Value_Formatter::sanitize_fmt( is_array( $item['fmt'] ?? null ) ? $item['fmt'] : array() );

		$goal  = $item['goal'] ?? '';
		$cheat = $item['cheat'] ?? 0;

		$campaign = Campaign::get(
			$product_id,
			array(
				'goal'              => is_numeric( $goal ) ? (float) $goal : '',
				'cheat'             => is_numeric( $cheat ) ? (float) $cheat : 0,
				'addids'            => $addids,
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
	 * Micro-cache key for a request.
	 *
	 * The currency and locale are part of the key: with a multi-currency or
	 * multilingual plugin active, two visitors asking for the same campaign
	 * must not be served each other's formatted strings.
	 *
	 * @param array<int, mixed> $requested Requested items.
	 * @return string
	 */
	private function cache_key( array $requested ): string {
		$context = array(
			$requested,
			function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			determine_locale(),
		);

		return 'wcdp_el_api_' . md5( (string) wp_json_encode( $context ) );
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
		do_action( 'litespeed_control_set_nocache', 'WCDP Elementor live figures' );

		return $response;
	}
}
