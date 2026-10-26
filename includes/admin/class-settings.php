<?php
/**
 * Settings screen.
 *
 * @package WCDP_Elementor
 */

namespace WCDP_Elementor\Admin;

use WCDP_Elementor\Data\Product_Resolver;
use WCDP_Elementor\Cache\Freshness;
use WCDP_Elementor\Cache\Location_Index;
use WCDP_Elementor\Cache\Purger;
use WCDP_Elementor\Cache\Script_Guard;
use WCDP_Elementor\Options;
use WCDP_Elementor\Plugin;
use WCDP_Elementor\Render\Shortcode_Runner;
use WCDP_Elementor\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single settings screen holding the only thing that has to be global — the
 * widget on/off list — plus diagnostics and a cache reset.
 *
 * Everything a user tunes per instance belongs in the Elementor panel, so this
 * screen is a plain form that posts and reloads: no JavaScript, no bundle, and
 * one small inline stylesheet. Colours come from WordPress's own design tokens
 * where the install provides them, with wp-admin's palette as the fallback, so
 * the screen matches whichever admin colour scheme is active.
 */
final class Settings {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'styleraise';

	/**
	 * Settings group for the Settings API.
	 */
	const GROUP = 'wcdp_el_settings_group';

	/**
	 * Action name for the cache-flush button.
	 */
	const FLUSH_ACTION = 'wcdp_el_flush_cache';

	/**
	 * Action name for the page-cache purge button.
	 */
	const PURGE_ACTION = 'wcdp_el_purge_pages';

	/**
	 * Hook suffix of the settings screen, set when the menu is registered.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::FLUSH_ACTION, array( $this, 'handle_flush' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCDP_EL_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Add the screen under the Elementor menu, where addon settings are looked
	 * for, falling back to Settings if that menu is absent.
	 */
	public function register_menu(): void {
		$title = esc_html__( 'WCDP Integration', 'styleraise' );

		$hook = add_submenu_page(
			'elementor',
			esc_html( WCDP_EL_NAME ),
			$title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( ! $hook ) {
			$hook = add_options_page(
				esc_html( WCDP_EL_NAME ),
				$title,
				'manage_options',
				self::PAGE,
				array( $this, 'render_page' )
			);
		}

		$this->hook_suffix = (string) $hook;
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( array $links ): array {
		$url = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'styleraise' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$clean = Options::defaults();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$registry = array_keys( Plugin::instance()->get_widget_registry() );
		$enabled  = isset( $input['enabled_widgets'] ) && is_array( $input['enabled_widgets'] )
			? array_map( 'sanitize_key', $input['enabled_widgets'] )
			: array();

		// The form submits what is *on*; storing what is *off* keeps a widget
		// added in a future release enabled by default rather than silently
		// hidden.
		$clean['disabled_widgets'] = array_values( array_diff( $registry, $enabled ) );

		$clean = $this->sanitize_freshness( $input, $clean );

		// Turning a widget off changes which controls exist, so the generated
		// per-page CSS has to be rebuilt.
		$this->clear_elementor_cache();

		add_settings_error(
			Options::OPTION,
			'wcdp_el_saved',
			esc_html__( 'Settings saved. Elementor\'s generated CSS was rebuilt.', 'styleraise' ),
			'success'
		);

		return $clean;
	}

	/**
	 * Validate the freshness block of the form.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @param array<string, mixed> $clean Values collected so far.
	 * @return array<string, mixed>
	 */
	private function sanitize_freshness( array $input, array $clean ): array {
		$pattern = isset( $input['freshness_pattern'] ) ? sanitize_key( $input['freshness_pattern'] ) : 'balanced';

		if ( 'custom' !== $pattern && ! isset( Freshness::patterns()[ $pattern ] ) ) {
			$pattern = 'balanced';
		}

		$clean['freshness_pattern'] = $pattern;

		$hydrate = isset( $input['freshness_hydrate'] ) ? sanitize_key( $input['freshness_hydrate'] ) : 'focus';

		if ( ! in_array( $hydrate, array( Freshness::HYDRATE_OFF, Freshness::HYDRATE_LOAD, Freshness::HYDRATE_FOCUS, Freshness::HYDRATE_INTERVAL ), true ) ) {
			$hydrate = Freshness::HYDRATE_FOCUS;
		}

		$purge = isset( $input['freshness_purge'] ) ? sanitize_key( $input['freshness_purge'] ) : 'index';

		if ( ! in_array( $purge, array( Freshness::PURGE_OFF, Freshness::PURGE_INDEX, Freshness::PURGE_URLS, Freshness::PURGE_ALL ), true ) ) {
			$purge = Freshness::PURGE_INDEX;
		}

		$clean['freshness_hydrate']        = $hydrate;
		$clean['freshness_interval']       = max( 10, min( 3600, absint( $input['freshness_interval'] ?? 30 ) ) );
		$clean['freshness_purge']          = $purge;
		$clean['freshness_purge_throttle'] = max( 0, min( 3600, absint( $input['freshness_purge_throttle'] ?? 60 ) ) );
		$clean['freshness_data_ttl']       = max( 0, min( 6 * HOUR_IN_SECONDS, absint( $input['freshness_data_ttl'] ?? 0 ) ) );
		$clean['freshness_force_recalc']   = ! empty( $input['freshness_force_recalc'] );

		// Stored as submitted text: one URL or path per line, resolved against
		// the site address only when it is used, so the list survives a domain
		// change.
		$urls = isset( $input['freshness_purge_urls'] ) ? (string) $input['freshness_purge_urls'] : '';
		$urls = preg_split( '/[\r\n]+/', $urls ) ?: array();
		$urls = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $urls ) ) );

		$clean['freshness_purge_urls'] = implode( "\n", array_slice( array_values( $urls ), 0, 50 ) );

		$guard = isset( $input['script_guard'] ) ? sanitize_key( $input['script_guard'] ) : 'auto';

		$clean['script_guard'] = in_array( $guard, array( 'auto', 'attributes', 'off' ), true ) ? $guard : 'auto';

		Freshness::flush();

		return $clean;
	}

	/**
	 * Handle the cache-flush button.
	 */
	public function handle_flush(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'styleraise' ), 403 );
		}

		check_admin_referer( self::FLUSH_ACTION );

		Product_Resolver::flush_options_cache();
		$this->clear_elementor_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'wcdp-el-msg' => 'flushed',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Drop Elementor's generated CSS files.
	 */
	private function clear_elementor_cache(): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->files_manager ) ) {
			$elementor->files_manager->clear_cache();
		}
	}

	/* ─────────────────────────── Rendering ─────────────────────────── */

	/**
	 * Render the screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry = Plugin::instance()->get_widget_registry();

		?>
		<div class="wrap wcdp-el-settings">

			<div class="wcdp-el-hero">
				<div>
					<h1 class="wcdp-el-hero__title">
						<?php echo esc_html( WCDP_EL_NAME ); ?>
						<span class="wcdp-el-pill wcdp-el-pill--neutral">
							<?php echo esc_html( WCDP_EL_VERSION ); ?>
						</span>
					</h1>
					<p class="wcdp-el-hero__text">
						<?php esc_html_e( 'Donation widgets, dynamic tags and a complete Style tab for the WCDP donation platform. Everything visual is configured per widget inside Elementor — this screen only holds what has to be site-wide.', 'styleraise' ); ?>
					</p>
				</div>
			</div>

			<?php
			$message = isset( $_GET['wcdp-el-msg'] ) ? sanitize_key( wp_unslash( $_GET['wcdp-el-msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-checked redirect.

			if ( 'flushed' === $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'Caches cleared.', 'styleraise' )
					. '</p></div>';
			}

			if ( 'purged' === $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'Page caches purged.', 'styleraise' )
					. '</p></div>';
			}

			settings_errors( Options::OPTION );
			?>

			<div class="wcdp-el-grid">

				<form method="post" action="options.php" class="wcdp-el-main">
					<?php settings_fields( self::GROUP ); ?>

					<?php $this->render_freshness_card(); ?>

					<div class="wcdp-el-card">
					<h2 class="wcdp-el-card__title"><?php esc_html_e( 'Widgets', 'styleraise' ); ?></h2>
					<p class="wcdp-el-card__hint">
						<?php esc_html_e( 'Turning a widget off removes it from the Elementor panel and skips loading its class. Pages already using it stop rendering it, so switch one off only when it is unused.', 'styleraise' ); ?>
					</p>

					<?php if ( empty( $registry ) ) : ?>
						<p class="wcdp-el-empty">
							<?php esc_html_e( 'No widgets are registered. A filter on wcdp_el_widget_registry may have removed them all.', 'styleraise' ); ?>
						</p>
					<?php else : ?>
						<ul class="wcdp-el-list">
							<?php foreach ( $registry as $widget_name => $class_name ) : ?>
								<?php
								$exists  = class_exists( 'WCDP_Elementor\\Widgets\\' . $class_name );
								$enabled = ! Options::is_widget_disabled( $widget_name );
								$id      = 'wcdp-el-widget-' . sanitize_html_class( $widget_name );
								?>
								<li class="wcdp-el-list__row">
									<label for="<?php echo esc_attr( $id ); ?>" class="wcdp-el-toggle">
										<input type="checkbox"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( Options::OPTION ); ?>[enabled_widgets][]"
											value="<?php echo esc_attr( $widget_name ); ?>"
											<?php checked( $enabled ); ?>
											<?php disabled( ! $exists ); ?>>
										<span class="wcdp-el-toggle__track" aria-hidden="true"></span>
										<span class="wcdp-el-toggle__text">
											<span class="wcdp-el-toggle__name"><?php echo esc_html( $this->get_widget_label( $class_name ) ); ?></span>
											<code class="wcdp-el-toggle__slug"><?php echo esc_html( $widget_name ); ?></code>
										</span>
									</label>

									<?php if ( ! $exists ) : ?>
										<p class="wcdp-el-list__error">
											<?php esc_html_e( 'Class file missing — reinstall the plugin.', 'styleraise' ); ?>
										</p>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					</div>

					<div class="wcdp-el-card wcdp-el-card__actions">
						<?php submit_button( esc_html__( 'Save changes', 'styleraise' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<div class="wcdp-el-side">

					<div class="wcdp-el-card">
						<h2 class="wcdp-el-card__title"><?php esc_html_e( 'Diagnostics', 'styleraise' ); ?></h2>
						<dl class="wcdp-el-facts">
							<?php foreach ( $this->get_diagnostics() as $fact ) : ?>
								<dt><?php echo esc_html( $fact['label'] ); ?></dt>
								<dd>
									<?php if ( 'neutral' === $fact['state'] ) : ?>
										<?php echo esc_html( $fact['value'] ); ?>
									<?php else : ?>
										<span class="wcdp-el-pill wcdp-el-pill--<?php echo esc_attr( $fact['state'] ); ?>">
											<?php echo esc_html( $fact['value'] ); ?>
										</span>
									<?php endif; ?>
								</dd>
							<?php endforeach; ?>
						</dl>
					</div>

					<div class="wcdp-el-card">
						<h2 class="wcdp-el-card__title"><?php esc_html_e( 'Page caches', 'styleraise' ); ?></h2>
						<p class="wcdp-el-card__hint">
							<?php esc_html_e( 'Rebuilds every page showing a campaign, using whichever caching plugins are installed. Use it after changing a goal, or when a figure looks stuck.', 'styleraise' ); ?>
						</p>
						<p class="wcdp-el-card__hint">
							<strong><?php esc_html_e( 'Detected:', 'styleraise' ); ?></strong>
							<?php echo esc_html( $this->detected_caches_text() ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_ACTION ); ?>">
							<?php wp_nonce_field( self::PURGE_ACTION ); ?>
							<?php submit_button( esc_html__( 'Purge page caches', 'styleraise' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>

					<div class="wcdp-el-card">
						<h2 class="wcdp-el-card__title"><?php esc_html_e( 'Internal caches', 'styleraise' ); ?></h2>
						<p class="wcdp-el-card__hint">
							<?php esc_html_e( 'Clears the campaign picker list and Elementor\'s generated CSS. Use it after enabling donations on a product that does not appear in the picker.', 'styleraise' ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::FLUSH_ACTION ); ?>">
							<?php wp_nonce_field( self::FLUSH_ACTION ); ?>
							<?php submit_button( esc_html__( 'Clear caches', 'styleraise' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Human label for a widget class.
	 *
	 * @param string $class_name Widget class short name.
	 * @return string
	 */
	private function get_widget_label( string $class_name ): string {
		$fqcn = 'WCDP_Elementor\\Widgets\\' . $class_name;

		if ( class_exists( $fqcn ) && method_exists( $fqcn, 'get_title' ) ) {
			$widget = new $fqcn();

			return (string) $widget->get_title();
		}

		return str_replace( '_', ' ', $class_name );
	}

	/**
	 * Environment facts worth knowing when something misbehaves.
	 *
	 * Each entry carries a state so the value can be rendered as a status pill
	 * rather than a wall of identical grey text: `ok`, `warn` or `neutral`.
	 *
	 * @return array<int, array{label: string, value: string, state: string}>
	 */
	private function get_diagnostics(): array {
		$unknown = esc_html__( 'not detected', 'styleraise' );

		$missing = array();

		foreach ( array( 'wcdp_progress', 'wcdp_order_counter', 'wcdp_leaderboard', 'wcdp_donation_form' ) as $shortcode ) {
			if ( ! Shortcode_Runner::exists( $shortcode ) ) {
				$missing[] = $shortcode;
			}
		}

		$multilingual = esc_html__( 'none', 'styleraise' );

		if ( function_exists( 'pll_get_post_language' ) ) {
			$multilingual = 'Polylang';
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$multilingual = 'WPML';
		}

		$analytics_on = 'no' !== get_option( 'woocommerce_analytics_enabled', 'yes' );

		return array(
			array(
				'label' => esc_html__( 'Elementor', 'styleraise' ),
				'value' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : $unknown,
				'state' => defined( 'ELEMENTOR_VERSION' ) ? 'ok' : 'warn',
			),
			array(
				'label' => esc_html__( 'WCDP', 'styleraise' ),
				'value' => defined( 'WCDP_VERSION' ) ? WCDP_VERSION : $unknown,
				'state' => defined( 'WCDP_VERSION' ) ? 'ok' : 'warn',
			),
			array(
				'label' => esc_html__( 'WooCommerce', 'styleraise' ),
				'value' => defined( 'WC_VERSION' ) ? WC_VERSION : $unknown,
				'state' => defined( 'WC_VERSION' ) ? 'ok' : 'warn',
			),
			array(
				'label' => esc_html__( 'WCDP shortcodes', 'styleraise' ),
				'value' => empty( $missing )
					? esc_html__( 'all available', 'styleraise' )
					: sprintf(
						/* translators: %s: comma-separated shortcode names. */
						esc_html__( 'missing: %s', 'styleraise' ),
						implode( ', ', $missing )
					),
				'state' => empty( $missing ) ? 'ok' : 'warn',
			),
			array(
				'label' => esc_html__( 'WooCommerce Analytics', 'styleraise' ),
				'value' => $analytics_on
					? esc_html__( 'enabled', 'styleraise' )
					: esc_html__( 'disabled — figures will be wrong', 'styleraise' ),
				'state' => $analytics_on ? 'ok' : 'warn',
			),
			array(
				'label' => esc_html__( 'Multilingual plugin', 'styleraise' ),
				'value' => $multilingual,
				'state' => 'neutral',
			),
			array(
				'label' => esc_html__( 'WordPress / PHP', 'styleraise' ),
				'value' => get_bloginfo( 'version' ) . ' / ' . PHP_VERSION,
				'state' => 'neutral',
			),
			array(
				'label' => esc_html__( 'Minimum required', 'styleraise' ),
				'value' => sprintf(
					/* translators: 1: Elementor version, 2: WCDP version. */
					esc_html__( 'Elementor %1$s · WCDP %2$s', 'styleraise' ),
					Requirements::MINIMUM_ELEMENTOR_VERSION,
					Requirements::MINIMUM_WCDP_VERSION
				),
				'state' => 'neutral',
			),
			$this->freshness_fact(),
			$this->purge_fact(),
			$this->index_fact(),
			$this->script_guard_fact(),
		);
	}

	/**
	 * Diagnostics row describing form-script protection.
	 *
	 * @return array{label:string,value:string,state:string}
	 */
	private function script_guard_fact(): array {
		$mode        = Script_Guard::mode();
		$optimisers  = Script_Guard::detected_optimisers();
		$unreachable = array_filter( $optimisers, static function ( $reachable ) { return ! $reachable; } );

		if ( 'off' === $mode ) {
			return array(
				'label' => esc_html__( 'Form script', 'styleraise' ),
				'value' => empty( $optimisers )
					? esc_html__( 'unprotected, no optimiser found', 'styleraise' )
					: esc_html__( 'unprotected — the form may hang on a spinner', 'styleraise' ),
				'state' => empty( $optimisers ) ? 'neutral' : 'warn',
			);
		}

		// An optimiser that only attributes can reach, while attribute mode is
		// off, is the exact configuration that leaves a spinner on screen.
		if ( 'auto' === $mode && ! empty( $unreachable ) ) {
			return array(
				'label' => esc_html__( 'Form script', 'styleraise' ),
				'value' => sprintf(
					/* translators: %s: comma-separated optimisation plugin names. */
					esc_html__( 'protected, but %s needs tag marking', 'styleraise' ),
					implode( ', ', array_keys( $unreachable ) )
				),
				'state' => 'warn',
			);
		}

		return array(
			'label' => esc_html__( 'Form script', 'styleraise' ),
			'value' => 'attributes' === $mode
				? esc_html__( 'protected, tags marked', 'styleraise' )
				: esc_html__( 'protected on form pages', 'styleraise' ),
			'state' => 'ok',
		);
	}

	/**
	 * Diagnostics row describing the active freshness pattern.
	 *
	 * @return array{label:string,value:string,state:string}
	 */
	private function freshness_fact(): array {
		$pattern = Freshness::pattern();
		$labels  = Freshness::pattern_labels();
		$config  = Freshness::config();

		$refreshing = Freshness::HYDRATE_OFF !== $config['hydrate'];

		return array(
			'label' => esc_html__( 'Freshness', 'styleraise' ),
			'value' => sprintf(
				/* translators: 1: pattern name, 2: whether figures refresh in the browser. */
				esc_html__( '%1$s · %2$s', 'styleraise' ),
				$labels[ $pattern ]['title'] ?? $pattern,
				$refreshing
					? esc_html__( 'figures refresh', 'styleraise' )
					: esc_html__( 'no refreshing', 'styleraise' )
			),
			// Without refreshing, a cached page keeps its original totals for
			// anyone whose browser or CDN already holds it.
			'state' => $refreshing ? 'ok' : 'warn',
		);
	}

	/**
	 * Diagnostics row describing the last purge.
	 *
	 * @return array{label:string,value:string,state:string}
	 */
	private function purge_fact(): array {
		$last = Purger::last_purge();

		if ( ! $last || empty( $last['time'] ) ) {
			return array(
				'label' => esc_html__( 'Last purge', 'styleraise' ),
				'value' => esc_html__( 'never', 'styleraise' ),
				'state' => 'neutral',
			);
		}

		$pages = (int) ( $last['posts'] ?? 0 );

		return array(
			'label' => esc_html__( 'Last purge', 'styleraise' ),
			// Raw on purpose: the diagnostics table escapes every value on output.
			'value' => sprintf(
				/* translators: 1: human time difference, 2: number of pages, e.g. "3 pages". */
				__( '%1$s ago · %2$s', 'styleraise' ),
				human_time_diff( (int) $last['time'] ),
				self::count_pages( $pages )
			),
			'state' => 'neutral',
		);
	}

	/**
	 * Diagnostics row describing the campaign location index.
	 *
	 * @return array{label:string,value:string,state:string}
	 */
	private function index_fact(): array {
		$stats = Location_Index::stats();

		$campaigns = (int) $stats['campaigns'];

		return array(
			'label' => esc_html__( 'Known locations', 'styleraise' ),
			// Raw on purpose: the diagnostics table escapes every value on output.
			'value' => sprintf(
				/* translators: 1: number of campaigns, e.g. "2 campaigns"; 2: number of pages, e.g. "5 pages". */
				__( '%1$s on %2$s', 'styleraise' ),
				sprintf(
					/* translators: %d: number of campaigns. */
					_n( '%d campaign', '%d campaigns', $campaigns, 'styleraise' ),
					$campaigns
				),
				self::count_pages( (int) $stats['pages'] )
			),
			'state' => 'neutral',
		);
	}

	/**
	 * "1 page" / "5 pages", with the plural form of the site language.
	 *
	 * @param int $pages Number of pages.
	 * @return string Unescaped.
	 */
	private static function count_pages( int $pages ): string {
		return sprintf(
			/* translators: %d: number of pages. */
			_n( '%d page', '%d pages', $pages, 'styleraise' ),
			$pages
		);
	}

	/**
	 * Handle the page-cache purge button.
	 */
	public function handle_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'styleraise' ), 403 );
		}

		check_admin_referer( self::PURGE_ACTION );

		// A manual purge is a deliberate act, so it ignores the throttle and
		// covers every campaign the index knows about.
		$campaigns = array_keys( Location_Index::all() );

		if ( empty( $campaigns ) ) {
			Purger::purge_all();
		} else {
			Purger::purge_campaigns( $campaigns, true );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'wcdp-el-msg' => 'purged',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Names of the page caches this site actually has.
	 *
	 * @return string
	 */
	private function detected_caches_text(): string {
		$labels = array();

		foreach ( Purger::active_adapters() as $slug => $adapter ) {
			// The generic core-signal adapter is always active and would tell
			// the reader nothing about their own stack.
			if ( 'generic' === $slug ) {
				continue;
			}

			$labels[] = (string) $adapter['label'];
		}

		if ( empty( $labels ) ) {
			return esc_html__( 'no page caching plugin found — only the core purge signal will be sent', 'styleraise' );
		}

		return implode( ', ', $labels );
	}

	/**
	 * The freshness card.
	 *
	 * Page caches store the figures that were current when a page was written,
	 * which is why an active fundraiser can spend hours advertising its opening
	 * total. This card is where that is dealt with.
	 */
	private function render_freshness_card(): void {
		$pattern = Freshness::pattern();
		$labels  = Freshness::pattern_labels();
		$name    = Options::OPTION;
		?>
		<div class="wcdp-el-card wcdp-el-freshness">
			<h2 class="wcdp-el-card__title"><?php esc_html_e( 'Freshness', 'styleraise' ); ?></h2>
			<p class="wcdp-el-card__hint">
				<?php esc_html_e( 'A page cache serves the totals that were current when it stored the page — often for hours. These patterns decide how hard the plugin works to correct that: by refreshing figures in the visitor\'s browser, and by telling the cache to rebuild when a donation lands.', 'styleraise' ); ?>
			</p>

			<div class="wcdp-el-patterns">
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php $id = 'wcdp-el-pattern-' . sanitize_html_class( $key ); ?>
					<label class="wcdp-el-pattern" for="<?php echo esc_attr( $id ); ?>">
						<input type="radio"
							id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $name ); ?>[freshness_pattern]"
							value="<?php echo esc_attr( $key ); ?>"
							<?php checked( $key, $pattern ); ?>>
						<span class="wcdp-el-pattern__body">
							<span class="wcdp-el-pattern__name"><?php echo esc_html( $label['title'] ); ?></span>
							<span class="wcdp-el-pattern__text"><?php echo esc_html( $label['text'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="wcdp-el-custom">
				<h3 class="wcdp-el-subtitle"><?php esc_html_e( 'Custom settings', 'styleraise' ); ?></h3>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wcdp-el-hydrate"><?php esc_html_e( 'Refresh figures', 'styleraise' ); ?></label></th>
							<td>
								<select id="wcdp-el-hydrate" name="<?php echo esc_attr( $name ); ?>[freshness_hydrate]">
									<?php
									$modes = array(
										Freshness::HYDRATE_OFF      => esc_html__( 'Never', 'styleraise' ),
										Freshness::HYDRATE_LOAD     => esc_html__( 'On page load', 'styleraise' ),
										Freshness::HYDRATE_FOCUS    => esc_html__( 'On load and when the tab regains focus', 'styleraise' ),
										Freshness::HYDRATE_INTERVAL => esc_html__( 'Continuously, on a timer', 'styleraise' ),
									);

									foreach ( $modes as $value => $mode_label ) {
										printf(
											'<option value="%1$s" %2$s>%3$s</option>',
											esc_attr( $value ),
											selected( $value, Options::get( 'freshness_hydrate', 'focus' ), false ),
											esc_html( $mode_label )
										);
									}
									?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Fetches current figures over a route no page cache touches, then patches them into the page. This is what makes cached HTML harmless.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcdp-el-interval"><?php esc_html_e( 'Timer interval', 'styleraise' ); ?></label></th>
							<td>
								<input type="number" id="wcdp-el-interval" class="small-text" min="10" max="3600" step="5"
									name="<?php echo esc_attr( $name ); ?>[freshness_interval]"
									value="<?php echo esc_attr( (string) Options::get( 'freshness_interval', 30 ) ); ?>">
								<?php esc_html_e( 'seconds', 'styleraise' ); ?>
								<p class="description">
									<?php esc_html_e( 'Only used by the timer mode. Every open tab makes one request per interval, so short values cost real server time under heavy traffic.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcdp-el-purge"><?php esc_html_e( 'Purge on donation', 'styleraise' ); ?></label></th>
							<td>
								<select id="wcdp-el-purge" name="<?php echo esc_attr( $name ); ?>[freshness_purge]">
									<?php
									$purges = array(
										Freshness::PURGE_OFF   => esc_html__( 'Never', 'styleraise' ),
										Freshness::PURGE_INDEX => esc_html__( 'Pages that show the campaign', 'styleraise' ),
										Freshness::PURGE_URLS  => esc_html__( 'Only the URLs listed below', 'styleraise' ),
										Freshness::PURGE_ALL   => esc_html__( 'The entire site cache', 'styleraise' ),
									);

									foreach ( $purges as $value => $purge_label ) {
										printf(
											'<option value="%1$s" %2$s>%3$s</option>',
											esc_attr( $value ),
											selected( $value, Options::get( 'freshness_purge', 'index' ), false ),
											esc_html( $purge_label )
										);
									}
									?>
								</select>
								<p class="description">
									<?php esc_html_e( 'The plugin records which pages display which campaign as they render, so it can rebuild exactly those and leave the rest of the site cached.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcdp-el-throttle"><?php esc_html_e( 'Minimum time between purges', 'styleraise' ); ?></label></th>
							<td>
								<input type="number" id="wcdp-el-throttle" class="small-text" min="0" max="3600" step="5"
									name="<?php echo esc_attr( $name ); ?>[freshness_purge_throttle]"
									value="<?php echo esc_attr( (string) Options::get( 'freshness_purge_throttle', 60 ) ); ?>">
								<?php esc_html_e( 'seconds', 'styleraise' ); ?>
								<p class="description">
									<?php esc_html_e( 'Stops a burst of donations from emptying the cache continuously, which would leave every visitor waiting for a full render.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcdp-el-purge-urls"><?php esc_html_e( 'Always purge these URLs', 'styleraise' ); ?></label></th>
							<td>
								<textarea id="wcdp-el-purge-urls" rows="4" class="large-text code"
									name="<?php echo esc_attr( $name ); ?>[freshness_purge_urls]"
									placeholder="/&#10;/campaign/"><?php echo esc_textarea( (string) Options::get( 'freshness_purge_urls', '' ) ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'One per line. A path such as /campaign/ is resolved against the site address, so the list survives a domain change.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wcdp-el-data-ttl"><?php esc_html_e( 'WCDP cache lifetime', 'styleraise' ); ?></label></th>
							<td>
								<input type="number" id="wcdp-el-data-ttl" class="small-text" min="0" max="21600" step="10"
									name="<?php echo esc_attr( $name ); ?>[freshness_data_ttl]"
									value="<?php echo esc_attr( (string) Options::get( 'freshness_data_ttl', 0 ) ); ?>">
								<?php esc_html_e( 'seconds (0 keeps WCDP\'s own six hours)', 'styleraise' ); ?>
								<p class="description">
									<?php esc_html_e( 'Applies to the donation counter and the leaderboard. The raised total is not affected: WCDP already drops it within about thirty seconds of a donation, through WooCommerce Analytics.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Force recalculation', 'styleraise' ); ?></th>
							<td>
								<label for="wcdp-el-force-recalc">
									<input type="checkbox" id="wcdp-el-force-recalc" value="1"
										name="<?php echo esc_attr( $name ); ?>[freshness_force_recalc]"
										<?php checked( (bool) Options::get( 'freshness_force_recalc', false ) ); ?>>
									<?php esc_html_e( 'Drop WCDP\'s stored total on every donation', 'styleraise' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Only needed where WooCommerce Analytics is disabled or lagging. It costs one heavy database join on the next uncached render.', 'styleraise' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h3 class="wcdp-el-subtitle"><?php esc_html_e( 'Donation form script', 'styleraise' ); ?></h3>

			<p class="wcdp-el-card__hint">
				<?php esc_html_e( 'WCDP renders the form hidden and reveals it with its own script. Every "delay JavaScript until interaction" feature holds that script back, so the form waits behind a spinner until the visitor moves the mouse — which on a phone may never happen.', 'styleraise' ); ?>
			</p>

			<?php $optimisers = Script_Guard::detected_optimisers(); ?>

			<?php if ( ! empty( $optimisers ) ) : ?>
				<p class="wcdp-el-card__hint">
					<strong><?php esc_html_e( 'Detected:', 'styleraise' ); ?></strong>
					<?php
					$rendered = array();

					foreach ( $optimisers as $label => $reachable ) {
						$rendered[] = $reachable
							? $label
							/* translators: %s: optimisation plugin name. */
							: sprintf( esc_html__( '%s (needs "Also mark the tags")', 'styleraise' ), $label );
					}

					echo esc_html( implode( ', ', $rendered ) );
					?>
				</p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protection', 'styleraise' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Donation form script protection', 'styleraise' ); ?></legend>

								<?php
								$guard_mode = Script_Guard::mode();

								$guard_options = array(
									'auto'       => array(
										esc_html__( 'Only where the form is (recommended)', 'styleraise' ),
										esc_html__( 'Tells WP Rocket, LiteSpeed, Perfmatters and Autoptimize to leave the form script — and jQuery, which it needs — alone, but only on pages that actually contain a form. The rest of the site keeps every optimisation.', 'styleraise' ),
									),
									'attributes' => array(
										esc_html__( 'Also mark the tags (widest coverage)', 'styleraise' ),
										esc_html__( 'Adds opt-out attributes the above plus Cloudflare Rocket Loader, WP Meteor and NitroPack understand. These cannot be limited to form pages, so jQuery stops being delayed site-wide. Use it if the form still shows a spinner.', 'styleraise' ),
									),
									'off'        => array(
										esc_html__( 'Off', 'styleraise' ),
										esc_html__( 'Change nothing. Only sensible if you maintain the exclusions by hand in your caching plugin.', 'styleraise' ),
									),
								);

								foreach ( $guard_options as $value => $copy ) :
									$id = 'wcdp-el-guard-' . sanitize_html_class( $value );
									?>
									<label class="wcdp-el-choice" for="<?php echo esc_attr( $id ); ?>">
										<input type="radio"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( Options::OPTION ); ?>[script_guard]"
											value="<?php echo esc_attr( $value ); ?>"
											<?php checked( $value, $guard_mode ); ?>>
										<span>
											<strong><?php echo esc_html( $copy[0] ); ?></strong><br>
											<span class="wcdp-el-choice__text"><?php echo esc_html( $copy[1] ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</fieldset>

							<p class="description">
								<?php esc_html_e( 'With this on, remove any manual exclusion you added for the donation form or jQuery in your caching plugin — a manual jQuery exclusion applies to the whole site and undoes the point of the targeted mode.', 'styleraise' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Enqueue the screen's stylesheet on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcdp-el-settings',
			WCDP_EL_URL . 'assets/css/wcdp-el-settings.css',
			array(),
			WCDP_EL_VERSION
		);
	}
}
