<?php

namespace PostAnalyzer;

if (! defined('ABSPATH')) {
    exit;
}

class Plugin
{
    public const SLUG = 'postanalyzer';
    private static ?self $instance = null;
    private string $plugin_dir;
    private string $plugin_url;
    private string $build_dir;
    private int $version;

    private function __construct()
    {
        $this->plugin_dir = plugin_dir_path(__DIR__);
        $this->plugin_url = plugin_dir_url(__DIR__);
        $this->build_dir  = $this->plugin_dir . 'build/';
        $this->version    = $this->get_build_version();

        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('init', [$this, 'load_endpoints']);
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_build_version(): int
    {
        $js = $this->build_dir . 'index.js';
        $css = $this->build_dir . 'main.css';
        $v1 = file_exists($js) ? filemtime($js) : time();
        $v2 = file_exists($css) ? filemtime($css) : $v1;
        return (int) max($v1, $v2);
    }

    public function register_admin_page(): void
    {
        add_menu_page(
            __('PostAnalyzer', 'postanalyzer'),
            __('PostAnalyzer', 'postanalyzer'),
            'manage_options',
            self::SLUG,
            [$this, 'render_admin_page'],
            'dashicons-search',
            56
        );
    }

    public function render_admin_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'postanalyzer'));
        }
        echo '<div class="wrap"><div id="postanalyzer-root" aria-live="polite"></div></div>';
    }

    public function enqueue_assets($hook = ''): void
    {
        // Only enqueue on our admin page
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }

        $js_file  = $this->build_dir . 'index.js';
        $css_file = $this->build_dir . 'main.css';

        if (file_exists($css_file)) {
            wp_enqueue_style('postanalyzer-admin', $this->plugin_url . 'build/main.css', [], $this->version);
        }

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'postanalyzer-admin',
                $this->plugin_url . 'build/index.js',
                ['wp-element'],
                $this->version,
                true
            );

            // provide runtime data to the React app
            wp_localize_script(
                'postanalyzer-admin',
                'postanalyzerWP',
                [
                    'restUrl' => esc_url_raw(rest_url('postanalyzer/v1/')),
                    'nonce'   => wp_create_nonce('wp_rest'),
                    'siteUrl' => esc_url_raw(get_site_url()),
                ]
            );
        } else {
            // helpful debug: show admin notice if build missing
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>PostAnalyzer: build/index.js not found. Run <code>npm run build</code> in plugin directory.</p></div>';
            });
        }
    }

    public function load_endpoints(): void
    {
        $classes = [
            \PostAnalyzer\API\AnalyzePost::class,
            \PostAnalyzer\API\Posts::class,
            \PostAnalyzer\API\Settings::class,
            \PostAnalyzer\API\Users::class,
        ];

        foreach ($classes as $class) {
            if (class_exists($class)) {
                new $class();
            }
        }
    }

    // helper for API output
    public function recursive_html_entity_decode($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'recursive_html_entity_decode'], $data);
        } elseif (is_string($data)) {
            return html_entity_decode($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $data;
    }

    private function __clone() {}
    public function __wakeup() {}
}
