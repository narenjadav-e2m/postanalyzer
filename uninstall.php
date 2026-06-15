<?php
/**
 * PostAnalyzer Uninstall
 *
 * Runs when the plugin is deleted from wp-admin.
 * Cleans up all plugin data from the database.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin settings.
delete_option( 'postanalyzer_settings' );

// Remove any transients we may set in future versions.
delete_transient( 'postanalyzer_cache' );
