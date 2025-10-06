<?php

/**
 * Plugin Name: PostAnalyzer
 * Plugin URI: https://e2msolutions.com
 * Description: WordPress plugin that performs automated QA on blog posts based on SOPs, with AI-generated suggestions for missing metadata, SEO, and categorization.
 * Version: 1.0.0
 * Author: E2M Solutions
 * Author URI: https://e2msolutions.com
 * License: GPL2
 * Text Domain: postanalyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PostAnalyzer_Plugin
{
    const SLUG = 'postanalyzer';
    private static $instance = null;
    private $plugin_dir;
    private $plugin_url;
    private $build_dir;
    private $version;

    private function __construct()
    {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        $this->build_dir  = $this->plugin_dir . 'build/';
        $this->version    = $this->get_build_version();

        // Hooks
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_action('admin_menu', [$this, 'register_admin_page'], 10);
        add_action('init', [$this, 'load_rest'], 5);
    }

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_build_version()
    {
        $index = $this->build_dir . 'index.js';
        $css   = $this->build_dir . 'main.css';
        $v1    = file_exists($index) ? filemtime($index) : time();
        $v2    = file_exists($css) ? filemtime($css) : $v1;
        return max($v1, $v2);
    }

    public function enqueue_assets($hook)
    {
        // Only load on our admin page
        if ('toplevel_page_' . self::SLUG !== $hook) {
            return;
        }

        $js_file  = $this->build_dir . 'index.js';
        $css_file = $this->build_dir . 'main.css';

        if (file_exists($js_file)) {
            wp_register_script('postanalyzer-admin', $this->plugin_url . 'build/index.js', ['wp-element'], $this->version, true);
            wp_enqueue_script('postanalyzer-admin');
        }

        if (file_exists($css_file)) {
            wp_register_style('postanalyzer-admin', $this->plugin_url . 'build/main.css', ['wp-admin'], $this->version, 'all');
            wp_enqueue_style('postanalyzer-admin');
        }

        if (wp_script_is('postanalyzer-admin', 'enqueued')) {
            wp_localize_script(
                'postanalyzer-admin',
                'postanalyzerWP',
                [
                    'restUrl' => esc_url_raw(rest_url('postanalyzer/v1/')),
                    'nonce'   => wp_create_nonce('wp_rest'),
                    'siteUrl' => esc_url_raw(get_site_url()),
                ]
            );
        }
    }

    public function register_admin_page()
    {
        add_menu_page(__('PostAnalyzer', 'postanalyzer'), __('PostAnalyzer', 'postanalyzer'), 'manage_options', self::SLUG, [$this, 'render_admin_page'], 'dashicons-search', 56);
    }

    public function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions to access PostAnalyzer', 'postanalyzer'));
        }
        echo '<div class="wrap"><div id="postanalyzer-root"></div></div>';
    }

    public function load_rest()
    {
        $api_dir = $this->plugin_dir . 'api/';

        if (is_dir($api_dir)) {
            foreach (glob($api_dir . '*.php') as $file) {
                require_once $file;
            }
        }
    }

    // Prevent cloning / unserialization
    private function __clone() {}
    private function __wakeup() {}
}

PostAnalyzer_Plugin::instance();

function recursive_html_entity_decode($data)
{
    if (is_array($data)) {
        return array_map('recursive_html_entity_decode', $data);
    } elseif (is_string($data)) {
        return html_entity_decode($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $data;
}
