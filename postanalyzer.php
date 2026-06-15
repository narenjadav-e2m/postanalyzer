<?php
/**
 * Plugin Name:       PostAnalyzer
 * Plugin URI:        https://e2msolutions.com/postanalyzer
 * Description:       Automated SEO, accessibility, and content QA audits for WordPress posts — powered by AI.
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Naren Jadav
 * Author URI:        https://e2msolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postanalyzer
 * Domain Path:       /languages
 *
 * @package PostAnalyzer
 */

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-error"><p>'
		     . esc_html__( 'PostAnalyzer requires PHP 8.1 or higher.', 'postanalyzer' )
		     . '</p></div>';
	} );
	return;
}

define( 'POSTANALYZER_VERSION', '2.0.0' );
define( 'POSTANALYZER_FILE',    __FILE__ );
define( 'POSTANALYZER_DIR',     plugin_dir_path( __FILE__ ) );
define( 'POSTANALYZER_URL',     plugin_dir_url( __FILE__ ) );
define( 'POSTANALYZER_SLUG',    'postanalyzer' );

$vendor = POSTANALYZER_DIR . 'vendor/autoload.php';
if ( file_exists( $vendor ) ) {
	require_once $vendor;
}

require_once POSTANALYZER_DIR . 'includes/Plugin.php';

add_action( 'plugins_loaded', static function () {
	\PostAnalyzer\Plugin::instance();
}, 10 );

register_activation_hook( __FILE__, [ \PostAnalyzer\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \PostAnalyzer\Plugin::class, 'deactivate' ] );
