<?php
/**
 * Shared base for WCDP dynamic tags.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as Tags_Module;
use WCDP_Elementor\Cache\Live_Target;
use WCDP_Elementor\Data\Campaign;
use WCDP_Elementor\Data\Value_Formatter;
use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Render\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for every campaign-data tag.
 *
 * These tags are the reason the addon does not need a widget for each figure:
 * with `{raised}`, `{goal}` and friends available as dynamic tags, a plain
 * Elementor Heading can show "still needed: ₴12,400" and be styled with
 * Elementor's own typography controls — no per-page custom CSS, which was the
 * concrete problem 1.0.x left unsolved.
 */
abstract class Tag_Base extends Tag {

	/**
	 * Dynamic tag group.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'wcdp';
	}

	/**
	 * Where this tag may be used.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( Tags_Module::TEXT_CATEGORY );
	}

	/**
	 * Register the campaign-source controls shared by every tag.
	 */
	protected function register_controls(): void {
		$this->register_source_controls();
	}

	/**
	 * Campaign-source controls.
	 *
	 * `Current post` is the default here (unlike the widgets, which keep
	 * 1.0.x's default for compatibility) because a tag is nearly always placed
	 * inside a product or CPT template.
	 */
	protected function register_source_controls(): void {
		$this->add_control(
			'product_source',
			array(
				'label'   => esc_html__( 'Campaign Source', 'styleraise' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => esc_html__( 'Current post / loop item', 'styleraise' ),
					'manual'  => esc_html__( 'Pick a campaign', 'styleraise' ),
					'dynamic' => esc_html__( 'Post meta field', 'styleraise' ),
				),
			)
		);

		$this->add_control(
			'product_id_manual',
			array(
				'label'       => esc_html__( 'Campaign', 'styleraise' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => Product_Resolver::get_options(),
				'default'     => '',
				'label_block' => true,
				'condition'   => array( 'product_source' => 'manual' ),
			)
		);

		$this->add_control(
			'product_meta_key',
			array(
				'label'       => esc_html__( 'Meta Key', 'styleraise' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'condition'   => array( 'product_source' => 'dynamic' ),
				'description' => esc_html__( 'Custom field on the current post holding the product ID.', 'styleraise' ),
			)
		);
	}

	/**
	 * Controls for formatting a monetary value.
	 */
	protected function register_amount_controls(): void {
		$this->add_control(
			'format',
			array(
				'label'       => esc_html__( 'Format', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'site',
				'separator'   => 'before',
				'options'     => array(
					'site'     => esc_html__( 'Match the rest of the site', 'styleraise' ),
					'currency' => esc_html__( 'Store currency format', 'styleraise' ),
					'compact'  => esc_html__( 'Compact (1.2K)', 'styleraise' ),
					'raw'      => esc_html__( 'Raw number', 'styleraise' ),
				),
				'description' => esc_html__( '"Match the rest of the site" follows a multi-currency switcher. Choose "Raw number" when the tag feeds a numeric control, such as a counter\'s end value.', 'styleraise' ),
			)
		);

		$this->add_control(
			'currency_position',
			array(
				'label'       => esc_html__( 'Currency Symbol Position', 'styleraise' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''            => esc_html__( 'Follow WooCommerce setting', 'styleraise' ),
					'auto'        => esc_html__( 'Automatic — let a currency switcher decide', 'styleraise' ),
					'left'        => esc_html__( 'Left — {symbol}100', 'styleraise' ),
					'right'       => esc_html__( 'Right — 100{symbol}', 'styleraise' ),
					'left_space'  => esc_html__( 'Left with space — {symbol} 100', 'styleraise' ),
					'right_space' => esc_html__( 'Right with space — 100 {symbol}', 'styleraise' ),
				),
				'description' => esc_html__( 'The default reads and enforces WooCommerce → Settings → General → Currency position. Choose "Automatic" to let a multi-currency switcher decide instead.', 'styleraise' ),
				'condition'   => array( 'format!' => array( 'compact', 'raw' ) ),
			)
		);

		$this->add_control(
			'decimals',
			array(
				'label'       => esc_html__( 'Decimals', 'styleraise' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 4,
				'default'     => '',
				'placeholder' => esc_html__( 'Store default', 'styleraise' ),
				'condition'   => array( 'format' => array( 'currency', 'raw' ) ),
			)
		);

		$this->add_control(
			'trim_zeros',
			array(
				'label'        => esc_html__( 'Hide .00 Decimals', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'format' => 'currency' ),
			)
		);
	}

	/**
	 * Format an amount according to the tag's own settings.
	 *
	 * @param float       $amount Amount to format.
	 * @param Campaign|null $campaign Campaign the amount came from.
	 * @param string      $which  `raised`, `goal` or `remaining`.
	 * @return string
	 */
	protected function format_amount( float $amount, $campaign = null, string $which = '' ): string {
		$format = (string) $this->get_settings( 'format' );

		// Default: reuse the string WCDP printed, which a currency switcher has
		// already converted. Re-formatting the base-currency float here would
		// show the wrong number under the visitor's currency symbol.
		if ( 'site' === $format || '' === $format ) {
			if ( $campaign instanceof Campaign && '' !== $which ) {
				$site_formatted = $campaign->get_formatted( $which );

				if ( null !== $site_formatted ) {
					return $site_formatted;
				}
			}

			return Campaign::format_amount(
				$amount,
				array(
					'trim_zeros'        => true,
					'currency_position' => (string) $this->get_settings( 'currency_position' ),
				)
			);
		}

		if ( 'raw' === $format ) {
			$decimals = $this->get_decimals();

			return number_format( $amount, null === $decimals ? 2 : $decimals, '.', '' );
		}

		if ( 'compact' === $format ) {
			return esc_html( Campaign::format_compact( $amount ) );
		}

		return Campaign::format_amount(
			$amount,
			array(
				'decimals'          => $this->get_settings( 'decimals' ),
				'trim_zeros'        => 'yes' === $this->get_settings( 'trim_zeros' ),
				'currency_position' => (string) $this->get_settings( 'currency_position' ),
			)
		);
	}

	/**
	 * Configured decimal places.
	 *
	 * @return int|null Null means "store default".
	 */
	protected function get_decimals() {
		$decimals = $this->get_settings( 'decimals' );

		return is_numeric( $decimals ) ? max( 0, (int) $decimals ) : null;
	}

	/**
	 * Register the opt-in live-refresh switch.
	 *
	 * Off by default, and deliberately so. Refreshing a value means wrapping it
	 * in a `<span>`, and a tag has no way of knowing what it is feeding — a
	 * heading, a number control, a URL. Wrapping the last two would break them,
	 * so the author has to say that this instance is safe.
	 */
	protected function register_live_control(): void {
		$this->add_control(
			'live_updates',
			array(
				'label'        => esc_html__( 'Live Updates', 'styleraise' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'description'  => esc_html__( 'Refreshes the figure after load, so a page served from cache still shows the real one. Wraps the value in a span — safe inside text, not inside a numeric or URL control.', 'styleraise' ),
			)
		);
	}

	/**
	 * The format descriptor matching this tag's own output.
	 *
	 * Returns null when the chosen format is one the refresh endpoint does not
	 * reproduce character for character. Hydrating those would rewrite the
	 * value into something subtly different, which is worse than leaving the
	 * server-rendered figure alone.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function live_fmt() {
		$format = (string) $this->get_settings( 'format' );

		// Raw numbers exist to feed numeric controls; a span would break them.
		if ( 'raw' === $format ) {
			return null;
		}

		$decimals = $this->get_settings( 'decimals' );

		return Value_Formatter::sanitize_fmt(
			array(
				'src' => ( 'site' === $format || '' === $format ) ? 'site' : 'custom',
				'cmp' => 'compact' === $format ? 1 : 0,
				'dec' => ( 'site' === $format || '' === $format ) ? '' : $decimals,
				'tz'  => ( 'site' === $format || '' === $format ) ? 1 : ( 'yes' === $this->get_settings( 'trim_zeros' ) ? 1 : 0 ),
				'cp'  => (string) $this->get_settings( 'currency_position' ),
				'pd'  => is_numeric( $decimals ) ? $decimals : 0,
				'cc'  => 0,
			)
		);
	}

	/**
	 * Print one value, marked for refreshing when the author opted in.
	 *
	 * @param string   $html       Already-escaped value markup.
	 * @param string   $value_name One of Value_Formatter::VALUES.
	 * @param Campaign $campaign   Campaign snapshot.
	 * @param bool     $refreshable Whether this particular output can be refreshed.
	 */
	protected function print_value( string $html, string $value_name, Campaign $campaign, bool $refreshable = true ): void {
		$enabled = $refreshable
			&& 'yes' === $this->get_settings( 'live_updates' )
			&& in_array( $value_name, Value_Formatter::VALUES, true );

		$fmt = $enabled ? $this->live_fmt() : null;

		if ( ! $enabled || null === $fmt ) {
			echo wp_kses( $html, Kses::markup() );

			return;
		}

		$descriptor = Live_Target::attribute( $campaign, $fmt, array(), 'on' );

		if ( '' === $descriptor ) {
			echo wp_kses( $html, Kses::markup() );

			return;
		}

		// Tags are not widgets and cannot declare a script dependency, so the
		// page is flagged instead and the script enqueued in the footer.
		Live_Target::mark_needed();

		printf(
			'<span class="wcdp-el-value wcdp-el-value--%1$s" %2$s="%1$s" %3$s=\'%4$s\'>%5$s</span>',
			esc_attr( $value_name ),
			esc_attr( Live_Target::VALUE_ATTRIBUTE ),
			esc_attr( Live_Target::ATTRIBUTE ),
			esc_attr( $descriptor ),
			wp_kses( $html, Kses::markup() )
		);
	}

	/**
	 * Resolve the campaign this tag refers to.
	 *
	 * @return Campaign|null
	 */
	protected function get_campaign() {
		$settings   = $this->get_settings();
		$product_id = Product_Resolver::resolve( $settings );

		if ( ! $product_id ) {
			return null;
		}

		$addids = Product_Resolver::get_translation_ids( $product_id );

		return Campaign::get(
			$product_id,
			array(
				'addids'            => $addids,
				'currency_position' => (string) $this->get_settings( 'currency_position' ),
			)
		);
	}
}
