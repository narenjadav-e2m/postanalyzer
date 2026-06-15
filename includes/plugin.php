<?php

namespace PostAnalyzer;

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin singleton.
 *
 * Responsible for:
 *  - Admin menu & admin-bar integration
 *  - Asset enqueueing (build files with cache-busting)
 *  - REST endpoint registration
 *  - Activation / deactivation hooks
 *
 * @package PostAnalyzer
 * @since   2.0.0
 */
final class Plugin {

	/** @var self|null */
	private static ?self $instance = null;

	// ── Lifecycle ────────────────────────────────────────────────────────────

	private function __construct() {
		$this->hooks();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __clone() {}
	public function __wakeup() {}

	// ── Hooks ────────────────────────────────────────────────────────────────

	private function hooks(): void {
		add_action( 'admin_menu',            [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu',        [ $this, 'add_admin_bar_node' ], 100 );
		add_action( 'init',                  [ $this, 'load_textdomain' ] );
		add_action( 'init',                  [ $this, 'load_endpoints' ] );
	}

	// ── Admin menu ───────────────────────────────────────────────────────────

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'Post Analyzer', 'postanalyzer' ),
			__( 'Post Analyzer', 'postanalyzer' ),
			'edit_posts',
			POSTANALYZER_SLUG,
			[ $this, 'render_admin_page' ],
			'dashicons-search',
			56
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'postanalyzer' ) );
		}
		echo '<div class="wrap"><div id="postanalyzer-root" aria-live="polite"></div></div>';
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . POSTANALYZER_SLUG ) {
			return;
		}

		$build_dir = POSTANALYZER_DIR . 'build/';
		$build_url = POSTANALYZER_URL . 'build/';
		$ver       = $this->build_version( $build_dir );

		$css = $build_dir . 'main.css';
		$js  = $build_dir . 'index.js';

		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'postanalyzer-admin',
				$build_url . 'main.css',
				[],
				$ver
			);
		}

		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'postanalyzer-admin',
				$build_url . 'index.js',
				[ 'wp-element' ],
				$ver,
				true
			);

			wp_localize_script(
				'postanalyzer-admin',
				'postanalyzerWP',
				[
					'restUrl'     => esc_url_raw( rest_url( 'postanalyzer/v1/' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'siteUrl'     => esc_url_raw( get_site_url() ),
					'version'     => POSTANALYZER_VERSION,
					'user_level'  => current_user_can( 'manage_options' ) ? 'admin' : 'editor',
					'postTypes'   => $this->get_analyzable_post_types(),
				]
			);
		} else {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p>'
				     . wp_kses(
					     __( 'PostAnalyzer: <code>build/index.js</code> not found. Run <code>npm run build</code> in the plugin directory.', 'postanalyzer' ),
					     [ 'code' => [] ]
				     )
				     . '</p></div>';
			} );
		}
	}

	// ── Admin bar ────────────────────────────────────────────────────────────

	public function add_admin_bar_node( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'postanalyzer_analyze',
			'title' => '<span class="ab-icon dashicons dashicons-search" style="top:2px"></span>'
			           . __( 'Analyze Post', 'postanalyzer' ),
			'href'  => admin_url( 'admin.php?page=' . POSTANALYZER_SLUG ),
			'meta'  => [
				'class' => 'postanalyzer-adminbar-node',
				'title' => __( 'Open Post Analyzer', 'postanalyzer' ),
			],
		] );
	}

	// ── REST endpoints ───────────────────────────────────────────────────────

	public function load_endpoints(): void {
		$endpoints = [
			\PostAnalyzer\API\Analyze_Post::class,
			\PostAnalyzer\API\Edit_Field::class,
			\PostAnalyzer\API\Posts::class,
			\PostAnalyzer\API\Settings::class,
			\PostAnalyzer\API\Users::class,
		];

		foreach ( $endpoints as $class ) {
			if ( class_exists( $class ) ) {
				new $class();
			}
		}
	}

	// ── i18n ─────────────────────────────────────────────────────────────────

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'postanalyzer',
			false,
			dirname( plugin_basename( POSTANALYZER_FILE ) ) . '/languages'
		);
	}

	// ── Activation / Deactivation ─────────────────────────────────────────────

	public static function activate(): void {
		// Future: run DB migrations, set default options, flush rewrite rules.
		if ( ! get_option( 'postanalyzer_settings' ) ) {
			update_option( 'postanalyzer_settings', wp_json_encode( [
				'ai_platform' => 'groq',
				'api_keys'    => [],
				'author_id'   => 0,
			] ), false );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns a cache-busting version string derived from build file mtimes.
	 */
	private function build_version( string $build_dir ): string {
		$js  = $build_dir . 'index.js';
		$css = $build_dir . 'main.css';
		$v   = max(
			file_exists( $js )  ? (int) filemtime( $js )  : 0,
			file_exists( $css ) ? (int) filemtime( $css ) : 0
		);
		return $v > 0 ? (string) $v : POSTANALYZER_VERSION;
	}

	/**
	 * Returns post types that can be analyzed (filterable by 3rd-party plugins).
	 *
	 * @return string[]
	 */
	private function get_analyzable_post_types(): array {
		return (array) apply_filters( 'postanalyzer_post_types', [ 'post', 'page' ] );
	}

	/**
	 * Recursively decode HTML entities in API response data.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public static function recursive_html_entity_decode( mixed $data ): mixed {
		if ( is_array( $data ) ) {
			return array_map( [ self::class, 'recursive_html_entity_decode' ], $data );
		}
		if ( is_string( $data ) ) {
			return html_entity_decode( $data, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $data;
	}
}
