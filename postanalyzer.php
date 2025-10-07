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

if (! defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader if available (PSR-4 autoload)
$vendor = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendor)) {
    require_once $vendor;
}

// Fallback require of includes/plugin.php for backward compatibility
$plugin_file = __DIR__ . '/includes/Plugin.php';
if (! file_exists($plugin_file)) {
    // try lowercase fallback
    $plugin_file = __DIR__ . '/includes/plugin.php';
}
if (file_exists($plugin_file)) {
    require_once $plugin_file;
}

// instantiate plugin if class exists
if (class_exists('\\PostAnalyzer\\Plugin')) {
    \PostAnalyzer\Plugin::instance();
}
