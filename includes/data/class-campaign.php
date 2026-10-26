<?php
/**
 * Campaign data value object.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Data;

use WCDP_Elementor\Render\Shortcode_Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything a widget or dynamic tag needs to know about one fundraising
 * campaign: raised, goal, percentage, remaining, deadline, donation count.
 *
 * The numbers come from WCDP itself rather than from a re-implementation of
 * its SQL. Raised is captured off WCDP's own `wcdp_progress_revenue` filter
 * during a throwaway shortcode run, so third-party filters that adjust the
 * total (offline donations, matched giving) are honoured and the figure can
 * never drift from what `[wcdp_progress]` would print.
 */
final class Campaign {

	/**
	 * WCDP meta key holding the fundraising goal.
	 *
	 * Stored under a literal key containing brackets — the array-style name is
	 * part of the key itself, not a serialized array.
	 */
	const META_GOAL = 'wcdp-settings[wcdp_fundraising_goal]';

	/**
	 * WCDP meta key holding the fundraising end date.
	 */
	const META_END_DATE = 'wcdp-settings[wcdp_fundraising_end_date]';

	/**
	 * Per-request memoization, keyed by product + arguments.
	 *
	 * @var array<string, Campaign|null>
	 */
	private static $cache = array();

	/**
	 * Primary product ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Additional product IDs folded into the total.
	 *
	 * @var int[]
	 */
	private $addids;

	/**
	 * Total raised, including additional products and the manual correction.
	 *
	 * @var float
	 */
	private $raised;

	/**
	 * Fundraising goal. Zero means "no goal set".
	 *
	 * @var float
	 */
	private $goal;

	/**
	 * Raw end-date meta value.
	 *
	 * @var string
	 */
	private $end_date;

	/**
	 * Lazily resolved donation count.
	 *
	 * @var int|null
	 */
	private $order_count = null;

	/**
	 * Amount strings exactly as WCDP would print them.
	 *
	 * Captured from `wcdp_progress_revenue`, `wcdp_progress_goal` and
	 * `wcdp_progress_remaining`, so any site-level formatting survives —
	 * most importantly a multi-currency plugin, which converts the figures
	 * on precisely those filters. Re-formatting the raw float here would
	 * print the base-currency number under the visitor's currency symbol.
	 *
	 * @var array<string, string>
	 */
	private $formatted = array();

	/**
	 * Build a campaign snapshot.
	 *
	 * @param int   $product_id Primary product ID.
	 * @param int[] $addids     Additional product IDs.
	 * @param float  $raised    Total raised.
	 * @param float  $goal      Fundraising goal.
	 * @param string $end_date  Raw end-date meta value.
	 * @param array<string, string> $formatted Amount strings as WCDP printed them.
	 */
	private function __construct( int $product_id, array $addids, float $raised, float $goal, string $end_date, array $formatted = array() ) {
		$this->product_id = $product_id;
		$this->addids     = $addids;
		$this->raised     = $raised;
		$this->goal       = $goal;
		$this->end_date   = $end_date;
		$this->formatted  = $formatted;
	}

	/**
	 * Resolve a campaign snapshot.
	 *
	 * @param int                  $product_id Product or variation ID.
	 * @param array<string, mixed> $args       Optional overrides:
	 *                                         `goal` (numeric or '' for the product setting),
	 *                                         `cheat` (amount added to the total),
	 *                                         `addids` (int[] of extra products).
	 * @return Campaign|null Null when the product is missing or not donable.
	 */
	public static function get( int $product_id, array $args = array() ) {
		$product_id = Product_Resolver::to_parent( $product_id );

		if ( ! $product_id || ! Product_Resolver::is_donable( $product_id ) ) {
			return null;
		}

		$args = wp_parse_args(
			$args,
			array(
				'goal'             => '',
				'cheat'            => 0,
				'addids'           => array(),
				'currency_position' => '',
			)
		);

		$addids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $args['addids'] ),
					static function ( $id ) use ( $product_id ) {
						return $id && $id !== $product_id;
					}
				)
			)
		);

		// The symbol position is part of the key: it changes the strings the
		// probe captures, so two widgets asking for different positions must
		// not share a snapshot.
		$cache_key = $product_id . '|' . implode( ',', $addids ) . '|' . (string) $args['goal']
			. '|' . (string) $args['cheat'] . '|' . (string) $args['currency_position'];

		if ( array_key_exists( $cache_key, self::$cache ) ) {
			return self::$cache[ $cache_key ];
		}

		$goal = is_numeric( $args['goal'] ) ? (float) $args['goal'] : self::read_goal( $product_id );

		$probe = self::probe( $product_id, $addids, (float) $args['cheat'], $goal, (string) $args['currency_position'] );

		if ( null === $probe['raised'] ) {
			self::$cache[ $cache_key ] = null;

			return null;
		}

		$campaign = new self(
			$product_id,
			$addids,
			$probe['raised'],
			$goal,
			(string) get_post_meta( $product_id, self::META_END_DATE, true ),
			$probe['formatted']
		);

		self::$cache[ $cache_key ] = $campaign;

		return $campaign;
	}

	/**
	 * Translate a symbol-position key into WooCommerce's price format.
	 *
	 * Mirrors `get_woocommerce_price_format()`: `%1$s` is the currency symbol
	 * and `%2$s` the number.
	 *
	 * Two keys are special:
	 *
	 * - `''` — the default — resolves the store's own
	 *   `woocommerce_currency_pos` option and returns a concrete format. That
	 *   is deliberately stronger than returning nothing: currency plugins
	 *   filter `woocommerce_price_format` to apply a per-currency position, so
	 *   "no override" can silently disagree with the WooCommerce setting the
	 *   control names. Reading the option makes the label literally true.
	 * - `auto` returns an empty string, meaning "do not override" — that is
	 *   the mode for sites that *want* a currency switcher to decide.
	 *
	 * @param string $position `auto`, `left`, `right`, `left_space`, `right_space` or ''.
	 * @return string Empty string only for `auto`.
	 */
	public static function price_format( string $position ): string {
		if ( 'auto' === $position ) {
			return '';
		}

		if ( '' === $position ) {
			$position = (string) get_option( 'woocommerce_currency_pos', 'left' );
		}

		switch ( $position ) {
			case 'left':
				return '%1$s%2$s';

			case 'right':
				return '%2$s%1$s';

			case 'left_space':
				return '%1$s&nbsp;%2$s';

			case 'right_space':
				return '%2$s&nbsp;%1$s';
		}

		return '';
	}

	/**
	 * Read the goal from the product's WCDP settings.
	 *
	 * @param int $product_id Product ID.
	 * @return float Zero when no numeric goal is configured.
	 */
	private static function read_goal( int $product_id ): float {
		$raw = get_post_meta( $product_id, self::META_GOAL, true );

		return is_numeric( $raw ) ? (float) $raw : 0.0;
	}

	/**
	 * Run WCDP's progress shortcode once and capture everything it computed.
	 *
	 * Two quirks of the upstream filter chain shape this method.
	 *
	 * First, `wcdp_progress_revenue` is applied twice under one name: once with
	 * the float total plus the shortcode attributes, and again with the
	 * already-formatted price string and no second argument. The callback
	 * therefore defaults its second parameter and tells the two calls apart.
	 *
	 * Second, the formatted strings are the only place a site's own filters get
	 * to speak. A multi-currency plugin converts the amounts on exactly these
	 * three filters, so capturing the strings — rather than re-formatting the
	 * base-currency float — is what keeps the widget showing the same figures
	 * as the rest of the page.
	 *
	 * Style 7 is used for the probe because it is the only cheap template that
	 * also triggers `wcdp_progress_remaining`.
	 *
	 * @param int   $product_id Primary product ID.
	 * @param int[] $addids     Additional product IDs.
	 * @param float $cheat      Manual correction.
	 * @param float  $goal              Goal passed through so WCDP does not re-read it.
	 * @param string $currency_position Symbol-position override, or '' to follow WooCommerce.
	 * @return array{raised: float|null, formatted: array<string, string>}
	 */
	private static function probe( int $product_id, array $addids, float $cheat, float $goal, string $currency_position = '' ): array {
		$result = array(
			'raised'    => null,
			'formatted' => array(),
		);

		if ( ! Shortcode_Runner::exists( 'wcdp_progress' ) ) {
			return $result;
		}

		$capture_revenue = static function ( $value, $probe_atts = null ) use ( &$result ) {
			if ( null !== $probe_atts && is_numeric( $value ) ) {
				$result['raised'] = (float) $value;
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				$result['formatted']['raised'] = $value;
			}

			return $value;
		};

		$capture_goal = static function ( $value ) use ( &$result ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$result['formatted']['goal'] = $value;
			}

			return $value;
		};

		$capture_remaining = static function ( $value ) use ( &$result ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$result['formatted']['remaining'] = $value;
			}

			return $value;
		};

		// PHP_INT_MAX so the captured strings are the final ones, after every
		// site filter — currency conversion included.
		add_filter( 'wcdp_progress_revenue', $capture_revenue, PHP_INT_MAX, 2 );
		add_filter( 'wcdp_progress_goal', $capture_goal, PHP_INT_MAX );
		add_filter( 'wcdp_progress_remaining', $capture_remaining, PHP_INT_MAX );

		// Most WCDP progress templates ask `wc_price()` for markup-free output
		// (`in_span => false`), which returns a bare string such as `250000₴`.
		// That leaves the Style tab's currency-symbol controls — hide, colour,
		// size — with no element to target, so they silently did nothing.
		// Restoring the span gives them something to hook and is visually inert.
		$restore_span = static function ( $price_args ) {
			$price_args['in_span'] = true;

			return $price_args;
		};

		add_filter( 'wc_price_args', $restore_span, PHP_INT_MAX );

		// Overriding the format here rather than rewriting the captured string
		// is what makes the symbol position work in "match the site" mode too:
		// WCDP formats these amounts itself, so there is no reliable way to
		// reorder them afterwards.
		$format   = self::price_format( $currency_position );
		$override = null;

		if ( '' !== $format ) {
			$override = static function () use ( $format ) {
				return $format;
			};

			add_filter( 'woocommerce_price_format', $override, PHP_INT_MAX );
		}

		Shortcode_Runner::run(
			'wcdp_progress',
			array(
				'id'     => $product_id,
				'style'  => 7,
				'goal'   => $goal > 0 ? $goal : 100,
				'cheat'  => $cheat,
				'addids' => implode( ',', $addids ),
			)
		);

		remove_filter( 'wcdp_progress_revenue', $capture_revenue, PHP_INT_MAX );
		remove_filter( 'wcdp_progress_goal', $capture_goal, PHP_INT_MAX );
		remove_filter( 'wcdp_progress_remaining', $capture_remaining, PHP_INT_MAX );

		remove_filter( 'wc_price_args', $restore_span, PHP_INT_MAX );

		if ( null !== $override ) {
			remove_filter( 'woocommerce_price_format', $override, PHP_INT_MAX );
		}

		return $result;
	}

	/* ─────────────────────────── Accessors ─────────────────────────── */

	/**
	 * Primary product ID.
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return $this->product_id;
	}

	/**
	 * Additional product IDs folded into the total.
	 *
	 * @return int[]
	 */
	public function get_addids(): array {
		return $this->addids;
	}

	/**
	 * Total raised.
	 *
	 * @return float
	 */
	public function get_raised(): float {
		return $this->raised;
	}

	/**
	 * Fundraising goal. Zero when none is configured.
	 *
	 * @return float
	 */
	public function get_goal(): float {
		return $this->goal;
	}

	/**
	 * Whether a usable goal is configured.
	 *
	 * WCDP silently substitutes 100 when the goal is missing, which turns an
	 * unconfigured campaign into a plausible-looking but meaningless bar. This
	 * lets a widget hide goal-dependent output instead.
	 *
	 * @return bool
	 */
	public function has_goal(): bool {
		return $this->goal > 0;
	}

	/**
	 * Raised as a percentage of the goal, uncapped.
	 *
	 * @return float
	 */
	public function get_percentage(): float {
		if ( ! $this->has_goal() ) {
			return 0.0;
		}

		return ( $this->raised * 100 ) / $this->goal;
	}

	/**
	 * Percentage clamped to 0–100, for bar width.
	 *
	 * @return float
	 */
	public function get_fill_percentage(): float {
		return max( 0.0, min( 100.0, $this->get_percentage() ) );
	}

	/**
	 * Amount still needed, never negative.
	 *
	 * @return float
	 */
	public function get_remaining(): float {
		if ( ! $this->has_goal() ) {
			return 0.0;
		}

		return max( 0.0, $this->goal - $this->raised );
	}

	/**
	 * Whether the goal has been reached or exceeded.
	 *
	 * @return bool
	 */
	public function is_goal_reached(): bool {
		return $this->has_goal() && $this->raised >= $this->goal;
	}

	/**
	 * Raw end-date meta value.
	 *
	 * @return string
	 */
	public function get_end_date(): string {
		return $this->end_date;
	}

	/**
	 * Whether an end date is configured.
	 *
	 * @return bool
	 */
	public function has_end_date(): bool {
		return '' !== trim( $this->end_date );
	}

	/**
	 * End date as a Unix timestamp.
	 *
	 * @return int Zero when unset or unparseable.
	 */
	public function get_end_timestamp(): int {
		if ( ! $this->has_end_date() ) {
			return 0;
		}

		$timestamp = strtotime( $this->end_date );

		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Whether the campaign deadline has passed.
	 *
	 * @return bool
	 */
	public function is_ended(): bool {
		$timestamp = $this->get_end_timestamp();

		return $timestamp > 0 && $timestamp < time();
	}

	/**
	 * Number of completed donations, summed across all folded products.
	 *
	 * @return int
	 */
	public function get_order_count(): int {
		if ( null !== $this->order_count ) {
			return $this->order_count;
		}

		$handler = Shortcode_Runner::get_handler( 'wcdp_order_counter' );

		if ( $handler && method_exists( $handler, 'get_order_count_product' ) ) {
			$count = (int) $handler->get_order_count_product( $this->product_id );

			foreach ( $this->addids as $addid ) {
				if ( Product_Resolver::is_donable( $addid ) ) {
					$count += (int) $handler->get_order_count_product( $addid );
				}
			}

			$this->order_count = $count;

			return $this->order_count;
		}

		$this->order_count = $this->parse_order_count_fallback();

		return $this->order_count;
	}

	/**
	 * Extract the donation count from the shortcode output.
	 *
	 * Only used when WCDP's shortcode handler is not reachable as an object.
	 *
	 * @return int
	 */
	private function parse_order_count_fallback(): int {
		$total = 0;
		$ids   = array_merge( array( $this->product_id ), $this->addids );

		foreach ( $ids as $product_id ) {
			$html = Shortcode_Runner::run(
				'wcdp_order_counter',
				array(
					'id'    => $product_id,
					'label' => '{ORDER_COUNT}',
				)
			);

			if ( preg_match( '#<span class="wcdp_order_count">(\d+)</span>#', $html, $matches ) ) {
				$total += (int) $matches[1];
			}
		}

		return $total;
	}

	/* ─────────────────────────── Site-formatted amounts ─────────────────────────── */

	/**
	 * An amount exactly as WCDP printed it, or null when unavailable.
	 *
	 * Prefer this over {@see Campaign::format_amount()} whenever the figure is
	 * shown to a visitor: it is the only variant that reflects the site's own
	 * currency and formatting filters.
	 *
	 * @param string $which `raised`, `goal` or `remaining`.
	 * @return string|null
	 */
	public function get_formatted( string $which ) {
		if ( empty( $this->formatted[ $which ] ) ) {
			return null;
		}

		// Goal-dependent figures are meaningless without a goal, and WCDP
		// computed them against its silent fallback of 100.
		if ( in_array( $which, array( 'goal', 'remaining' ), true ) && ! $this->has_goal() ) {
			return null;
		}

		// Upstream prints `goal - raised` unclamped, so an overfunded campaign
		// yields a negative "still needed". Fall back to a formatted zero.
		if ( 'remaining' === $which && $this->is_goal_reached() ) {
			return null;
		}

		return $this->formatted[ $which ];
	}

	/**
	 * Whether any site filter changed how amounts are formatted.
	 *
	 * @return bool
	 */
	public function has_formatted_amounts(): bool {
		return ! empty( $this->formatted );
	}

	/* ─────────────────────────── Formatting ─────────────────────────── */

	/**
	 * Format a monetary amount.
	 *
	 * Returns WooCommerce's own price markup so site currency settings, RTL
	 * handling and the `.woocommerce-Price-currencySymbol` hook all stay
	 * intact — the symbol is a separately targetable element rather than baked
	 * into a string.
	 *
	 * @param float                $amount Amount to format.
	 * @param array<string, mixed> $args   `decimals` (int|''), `trim_zeros` (bool),
	 *                                     `currency_position` ('' to follow WooCommerce).
	 * @return string
	 */
	public static function format_amount( float $amount, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'decimals'          => '',
				'trim_zeros'        => false,
				'currency_position' => '',
			)
		);

		$decimals = is_numeric( $args['decimals'] ) ? max( 0, (int) $args['decimals'] ) : null;

		if ( $args['trim_zeros'] && 0.0 === (float) ( $amount - floor( $amount ) ) ) {
			$decimals = 0;
		}

		if ( ! function_exists( 'wc_price' ) ) {
			return esc_html( number_format_i18n( $amount, null === $decimals ? 2 : $decimals ) );
		}

		$price_args = array();

		if ( null !== $decimals ) {
			$price_args['decimals'] = $decimals;
		}

		$format = self::price_format( (string) $args['currency_position'] );

		if ( '' !== $format ) {
			$price_args['price_format'] = $format;
		}

		return wc_price( $amount, $price_args );
	}

	/**
	 * Format an amount in compact notation (1.2K, 3.4M).
	 *
	 * @param float $amount   Amount to format.
	 * @param int   $decimals Decimal places in the mantissa.
	 * @return string
	 */
	public static function format_compact( float $amount, int $decimals = 1 ): string {
		$units = array(
			1000000000 => _x( 'B', 'billions abbreviation', 'styleraise' ),
			1000000    => _x( 'M', 'millions abbreviation', 'styleraise' ),
			1000       => _x( 'K', 'thousands abbreviation', 'styleraise' ),
		);

		foreach ( $units as $divisor => $suffix ) {
			if ( abs( $amount ) >= $divisor ) {
				return number_format_i18n( $amount / $divisor, $decimals ) . $suffix;
			}
		}

		return number_format_i18n( $amount, 0 );
	}

	/**
	 * Format the percentage raised.
	 *
	 * @param int $decimals Decimal places.
	 * @return string
	 */
	public function format_percentage( int $decimals = 0 ): string {
		$decimals = max( 0, $decimals );

		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $this->get_percentage(), $decimals ) . '%';
		}

		return number_format_i18n( $this->get_percentage(), $decimals ) . '%';
	}

	/**
	 * Human-readable time until (or since) the deadline.
	 *
	 * Delegates to WCDP so the translated "%s to go" / "ended %s ago" strings
	 * are reused, then rewrites WCDP's emphasis class to ours so the inner
	 * span stays independently styleable per widget instance.
	 *
	 * @return string Empty string when no end date is set.
	 */
	public function format_time_diff(): string {
		if ( ! $this->has_end_date() ) {
			return '';
		}

		if ( class_exists( 'WCDP_Progress' ) && method_exists( 'WCDP_Progress', 'get_human_time_diff' ) ) {
			$html = (string) \WCDP_Progress::get_human_time_diff( $this->end_date );

			return str_replace( 'wcdp-emphasized', 'wcdp-el-emphasis', $html );
		}

		$timestamp = $this->get_end_timestamp();

		if ( ! $timestamp ) {
			return '';
		}

		$diff = '<span class="wcdp-el-emphasis">' . esc_html( human_time_diff( $timestamp ) ) . '</span>';

		if ( $timestamp > time() ) {
			/* translators: %s: human readable time difference, e.g. "3 weeks". */
			return sprintf( esc_html__( '%s to go', 'styleraise' ), $diff );
		}

		/* translators: %s: human readable time difference, e.g. "3 weeks". */
		return sprintf( esc_html__( 'ended %s ago', 'styleraise' ), $diff );
	}

	/**
	 * Accessible label describing the progress, for `aria-label`.
	 *
	 * @return string
	 */
	public function get_aria_label(): string {
		if ( ! $this->has_goal() ) {
			/* translators: %s: amount raised. */
			return sprintf( __( '%s raised', 'styleraise' ), wp_strip_all_tags( self::format_amount( $this->raised ) ) );
		}

		return sprintf(
			/* translators: 1: amount raised, 2: fundraising goal. */
			__( '%1$s raised of %2$s goal', 'styleraise' ),
			wp_strip_all_tags( self::format_amount( $this->raised ) ),
			wp_strip_all_tags( self::format_amount( $this->goal ) )
		);
	}

	/**
	 * Drop the memoized snapshots. Intended for tests and long-running jobs.
	 */
	public static function flush_cache(): void {
		self::$cache = array();
	}
}
