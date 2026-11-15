<?php
/**
 * Keeps the donation form's JavaScript out of "delay until interaction".
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Cache;

use WCDP_Elementor\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops optimisation plugins from delaying the script that reveals the form.
 *
 * WCDP renders the donation form with `visibility:hidden` and a spinner, then
 * its own `wcdp.js` makes it visible. Every "delay JavaScript until user
 * interaction" feature holds that script back, so the form sits behind a
 * spinner until the visitor happens to move the mouse — indefinitely on mobile,
 * where there is no mouse to move. The markup is already there; only the reveal
 * is missing. It reads as a broken page on exactly the traffic a campaign paid
 * for.
 *
 * The script has to be excluded together with what it really depends on, and
 * that changed in WCDP 1.5.0:
 *
 * - **WCDP 1.5.0 and later** register `wcdp.js` with `wp-i18n` (which pulls in
 *   `wp-hooks`) and call `wp.i18n` as soon as it runs. Held back on its own,
 *   `wp-i18n` would leave the script failing and the form hidden — a worse
 *   failure than the spinner. jQuery is not among the dependencies: neither
 *   `wcdp.js` nor `wcdp.min.js` references it (checked in 1.5.0 and 1.5.1), so
 *   it stays delayed like the rest of the site.
 * - **WCDP 1.3.2–1.4.x** open `wcdp.js` with `jQuery(function ($) {…})` yet
 *   register it with **no dependencies**. Excluded alone, it runs before
 *   jQuery and dies with `jQuery is not defined`. There jQuery is excluded
 *   together with it, and the missing dependency is declared on top.
 *
 * Which case applies is read from WCDP's own registration: no `wp-i18n` among
 * the dependencies means the jQuery-based script.
 *
 * Two mechanisms, because no single one reaches every optimiser:
 *
 * - **Filters** — precise. They run while the optimiser processes the page
 *   buffer, which is late enough to know whether a form is on this page, so
 *   nothing is un-optimised anywhere else.
 * - **Attributes** — broad. The only way to reach edge-side optimisers such as
 *   Cloudflare Rocket Loader, which never run PHP. They cannot be conditional,
 *   so they are opt-in.
 */
final class Script_Guard {

	/**
	 * Script handles that must load together and on time (WCDP 1.5.0+).
	 *
	 * The WCDP form script, its real dependency chain (`wp-i18n` → `wp-hooks`)
	 * and the live-figures script.
	 */
	const HANDLES = array( 'wc-donation-platform', 'wcdp-el-live', 'wp-i18n', 'wp-hooks' );

	/**
	 * Script handles that must load together and on time (WCDP before 1.5.0).
	 *
	 * `jquery` is an alias with no file of its own; `jquery-core` is the one
	 * that actually emits a tag.
	 */
	const LEGACY_HANDLES = array( 'wc-donation-platform', 'wcdp-el-live', 'jquery', 'jquery-core', 'jquery-migrate' );

	/**
	 * Whether a donation form widget rendered during this request.
	 *
	 * @var bool
	 */
	private static $form_rendered = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( 'off' === self::mode() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'declare_jquery_dependency' ), 20 );

		$this->register_filter_exclusions();

		if ( 'attributes' === self::mode() ) {
			add_filter( 'script_loader_tag', array( $this, 'mark_script_tag' ), 20, 3 );
		}
	}

	/**
	 * The configured protection mode.
	 *
	 * @return string `auto`, `attributes` or `off`.
	 */
	public static function mode(): string {
		$mode = (string) Options::get( 'script_guard', 'auto' );

		return in_array( $mode, array( 'auto', 'attributes', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Record that the donation form widget is about to print a form.
	 *
	 * Called from the widget's render. WCDP 1.5.1 sets `WCDP_FORM` to `false`
	 * on Elementor pages even when they carry a form, so the widget reports it
	 * itself.
	 */
	public static function mark_form_rendered(): void {
		self::$form_rendered = true;
	}

	/**
	 * Whether this request renders a donation form.
	 *
	 * True when the widget reported a form, or when WCDP queued its form
	 * script — which it does for every form it renders, whatever placed it:
	 * shortcode, block, product page or checkout. `WCDP_FORM` is not used: WCDP
	 * defines it, as `true` or `false`, on every page with a post. Every filter
	 * below runs during buffer processing, long after the form rendered.
	 *
	 * @return bool
	 */
	public static function should_protect(): bool {
		/**
		 * Filter whether the donation form's scripts are protected on this page.
		 *
		 * @param bool $protect Whether to exclude the scripts from delaying.
		 */
		return (bool) apply_filters(
			'wcdp_el_protect_form_scripts',
			self::$form_rendered || wp_script_is( 'wc-donation-platform', 'enqueued' )
		);
	}

	/**
	 * Whether the installed WCDP ships the jQuery-based form script.
	 *
	 * WCDP 1.5.0 moved `wcdp.js` off jQuery and onto `wp.i18n`, and registers
	 * it with `wp-i18n` since. Its registration is the most direct evidence;
	 * before WCDP has registered the script, its version stands in.
	 *
	 * @return bool True for WCDP before 1.5.0.
	 */
	public static function wcdp_needs_jquery(): bool {
		$scripts = wp_scripts();

		if ( isset( $scripts->registered['wc-donation-platform'] ) ) {
			return ! in_array( 'wp-i18n', (array) $scripts->registered['wc-donation-platform']->deps, true );
		}

		return defined( 'WCDP_VERSION' ) && version_compare( (string) WCDP_VERSION, '1.5.0', '<' );
	}

	/**
	 * Script handles guarded for the installed WCDP.
	 *
	 * @return string[]
	 */
	public static function handles(): array {
		return self::wcdp_needs_jquery() ? self::LEGACY_HANDLES : self::HANDLES;
	}

	/**
	 * Declare the jQuery dependency WCDP before 1.5.0 omits.
	 *
	 * There `wcdp.js` is wrapped in `jQuery(function ($) {…})` yet registered
	 * with an empty dependency array, and works only because something else
	 * happens to load jQuery first. Stating it makes WordPress — and every
	 * optimiser that respects dependency order — keep the two in sequence.
	 * WCDP 1.5.0 and later are left untouched: their script needs no jQuery.
	 */
	public function declare_jquery_dependency(): void {
		$scripts = wp_scripts();

		if ( ! isset( $scripts->registered['wc-donation-platform'] ) || ! self::wcdp_needs_jquery() ) {
			return;
		}

		$script = $scripts->registered['wc-donation-platform'];

		if ( ! in_array( 'jquery', (array) $script->deps, true ) ) {
			$script->deps[] = 'jquery';
		}
	}

	/* ─────────────────────────── Filter-based ─────────────────────────── */

	/**
	 * Hook every optimiser that exposes an exclusion filter.
	 *
	 * Only hook names verified against each plugin's own documentation are
	 * listed. A guessed name is silently dead code that looks like support.
	 */
	private function register_filter_exclusions(): void {
		// WP Rocket matches its patterns as regular expressions.
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'add_regex_patterns' ) );

		// LiteSpeed and Perfmatters match plain substrings.
		foreach ( array(
			'litespeed_optm_js_defer_exc',
			'litespeed_optimize_js_excludes',
			'litespeed_optm_gm_js_exc',
			'perfmatters_delay_js_exclusions',
			'perfmatters_defer_js_exclusions',
		) as $hook ) {
			add_filter( $hook, array( $this, 'add_plain_patterns' ) );
		}

		// Autoptimize takes one comma-separated string.
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'add_comma_patterns' ) );
	}

	/**
	 * Patterns for optimisers that treat the list as regular expressions.
	 *
	 * @return string[]
	 */
	public static function regex_patterns(): array {
		if ( self::wcdp_needs_jquery() ) {
			return self::filter_patterns(
				array(
					'wc-donation-platform',
					'/jquery-?[0-9.]*(\.min|\.slim|\.slim\.min)?\.js',
					'jquery-migrate',
				)
			);
		}

		return self::filter_patterns(
			array(
				'wc-donation-platform',
				'/wp-includes/js/dist/i18n',
				'/wp-includes/js/dist/hooks',
				// WordPress prints the locale data right after `wp-i18n`.
				'wp-i18n-js-after',
			)
		);
	}

	/**
	 * Patterns for optimisers that match plain substrings.
	 *
	 * @return string[]
	 */
	public static function plain_patterns(): array {
		if ( self::wcdp_needs_jquery() ) {
			return self::filter_patterns(
				array(
					'wc-donation-platform',
					'jquery.js',
					'jquery.min.js',
					'jquery-migrate',
				)
			);
		}

		return self::filter_patterns(
			array(
				'wc-donation-platform',
				'/wp-includes/js/dist/i18n',
				'/wp-includes/js/dist/hooks',
				'wp-i18n-js-after',
			)
		);
	}

	/**
	 * Patterns excluded on every page, form or not.
	 *
	 * The live-figures script depends on nothing and exists precisely to
	 * correct a cached total before the visitor touches anything. Delaying it
	 * until first interaction would defeat it on archive pages, which carry
	 * progress bars but no form — and excluding it costs the rest of the site
	 * nothing.
	 *
	 * @return string[]
	 */
	public static function always_patterns(): array {
		/**
		 * Filter the patterns excluded regardless of whether a form is present.
		 *
		 * @param string[] $patterns Patterns.
		 */
		return (array) apply_filters( 'wcdp_el_script_guard_always_patterns', array( 'wcdp-el-live' ) );
	}

	/**
	 * Allow a site to adjust the form-conditional patterns.
	 *
	 * @param string[] $patterns Default patterns.
	 * @return string[]
	 */
	private static function filter_patterns( array $patterns ): array {
		/**
		 * Filter the script patterns excluded from delay/defer optimisation.
		 *
		 * @param string[] $patterns Patterns.
		 */
		return array_values( array_unique( (array) apply_filters( 'wcdp_el_script_guard_patterns', $patterns ) ) );
	}

	/**
	 * The patterns that apply to this request.
	 *
	 * @param string $flavour `regex` or `plain`.
	 * @return string[]
	 */
	private static function patterns_for_request( string $flavour ): array {
		$patterns = self::always_patterns();

		if ( self::should_protect() ) {
			$patterns = array_merge(
				$patterns,
				'regex' === $flavour ? self::regex_patterns() : self::plain_patterns()
			);
		}

		return array_values( array_unique( array_filter( $patterns ) ) );
	}

	/**
	 * Append the regex patterns to an optimiser's exclusion array.
	 *
	 * @param mixed $excluded Existing exclusions.
	 * @return array<int, string>
	 */
	public function add_regex_patterns( $excluded ): array {
		$excluded = is_array( $excluded ) ? $excluded : array();

		return array_values( array_unique( array_merge( $excluded, self::patterns_for_request( 'regex' ) ) ) );
	}

	/**
	 * Append the plain patterns to an optimiser's exclusion array.
	 *
	 * @param mixed $excluded Existing exclusions.
	 * @return array<int, string>
	 */
	public function add_plain_patterns( $excluded ): array {
		$excluded = is_array( $excluded ) ? $excluded : array();

		return array_values( array_unique( array_merge( $excluded, self::patterns_for_request( 'plain' ) ) ) );
	}

	/**
	 * Append the plain patterns to a comma-separated exclusion string.
	 *
	 * @param mixed $excluded Existing comma-separated exclusions.
	 * @return string
	 */
	public function add_comma_patterns( $excluded ): string {
		$excluded = is_string( $excluded ) ? $excluded : '';

		$current = array_filter( array_map( 'trim', explode( ',', $excluded ) ) );
		$merged  = array_unique( array_merge( $current, self::patterns_for_request( 'plain' ) ) );

		return implode( ',', $merged );
	}

	/* ─────────────────────────── Attribute-based ─────────────────────────── */

	/**
	 * Mark a guarded script so attribute-driven optimisers leave it alone.
	 *
	 * Unconditional by necessity: the dependencies (`wp-i18n` and `wp-hooks`,
	 * or jQuery before WCDP 1.5.0) are printed in the head, before anything has
	 * rendered, so there is no way to know yet whether this page carries a
	 * form. That is exactly why the mode is opt-in.
	 *
	 * The string WordPress passes here can hold several tags: the inline
	 * translations WCDP 1.5.1 sets up for `wcdp.js` precede the main tag, and
	 * `wp-i18n` is followed by its inline locale data. Every opening tag is
	 * marked, so none of them is left behind for an optimiser to delay.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 * @return string
	 */
	public function mark_script_tag( $tag, $handle, $src ): string {
		unset( $src );

		if ( ! in_array( $handle, self::handles(), true ) ) {
			return $tag;
		}

		/**
		 * Filter the opt-out attributes added to guarded scripts.
		 *
		 * `data-cfasync` must stay first: Cloudflare only honours it when it
		 * appears before `src`.
		 *
		 * @param string $attributes Attribute string.
		 * @param string $handle     Script handle.
		 */
		$attributes = (string) apply_filters(
			'wcdp_el_script_guard_attributes',
			'data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" data-wpmeteor-nooptimize="true"',
			$handle
		);

		$marked = preg_replace_callback(
			'/<script(\s[^>]*)?>/i',
			static function ( array $match ) use ( $attributes ): string {
				$existing = isset( $match[1] ) ? $match[1] : '';

				// Already marked, by us or by someone else.
				if ( false !== strpos( $existing, 'data-cfasync' ) ) {
					return $match[0];
				}

				// Cloudflare ignores `data-cfasync` when a legacy type attribute
				// is present, so the type WordPress adds for non-HTML5 themes
				// has to go.
				$existing = str_replace(
					array( " type='text/javascript'", ' type="text/javascript"' ),
					'',
					$existing
				);

				return '<script ' . $attributes . $existing . '>';
			},
			(string) $tag
		);

		return null === $marked ? (string) $tag : $marked;
	}

	/* ─────────────────────────── Diagnostics ─────────────────────────── */

	/**
	 * Optimisers detected on this site that delay or defer JavaScript.
	 *
	 * @return array<string, bool> Label → whether this addon can reach it through a filter.
	 */
	public static function detected_optimisers(): array {
		$found = array();

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found['WP Rocket'] = true;
		}

		if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' ) ) {
			$found['LiteSpeed Cache'] = true;
		}

		if ( defined( 'PERFMATTERS_VERSION' ) || function_exists( 'perfmatters' ) ) {
			$found['Perfmatters'] = true;
		}

		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) || class_exists( '\autoptimizeMain' ) ) {
			$found['Autoptimize'] = true;
		}

		// Reachable only through attributes: they run at the edge or rewrite
		// the HTML outside PHP.
		if ( defined( 'WPMETEOR_VERSION' ) ) {
			$found['WP Meteor'] = false;
		}

		if ( defined( 'SiteGround_Optimizer\VERSION' ) ) {
			$found['SiteGround Optimizer'] = false;
		}

		if ( defined( 'NITROPACK_VERSION' ) || class_exists( '\NitroPack\SDK\NitroPack' ) ) {
			$found['NitroPack'] = false;
		}

		return $found;
	}
}
