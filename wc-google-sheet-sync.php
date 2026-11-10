<?php
/**
 * Plugin Name: WC Google Sheet Sync
 * Plugin URI:  https://github.com/bitwanderer/woo-stock-sync/
 * Description: One-way synchronization of stock and pricing data from a Google Sheet to WooCommerce products based on SKU.
 * Version:     1.0.10
 * Author:      WebLynx
 * Author URI:  https://weblynx.pro
 * License:     GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wc-google-sheet-sync
 * Domain Path: /languages
 * WC requires at least: 7.0   # Updated for HPOS compatibility
 * WC tested up to: 9.0       # Updated to a recent WooCommerce version
 * HPOS compatible: yes       # Explicitly declare HPOS compatibility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Another copy may already have loaded under a different plugin directory.
if ( defined( 'WC_GSS_VERSION' ) || function_exists( 'wc_gss_run_plugin' ) ) {
    return;
}

$wc_gss_legacy_plugin = 'woo-stock-sync-main/wc-google-sheet-sync.php';
if ( plugin_basename( __FILE__ ) !== $wc_gss_legacy_plugin &&
    is_file( WP_PLUGIN_DIR . '/' . $wc_gss_legacy_plugin ) &&
    ( in_array( $wc_gss_legacy_plugin, (array) get_option( 'active_plugins', array() ), true ) ||
        ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $wc_gss_legacy_plugin ] ) ) ) ) {
    // Let the active legacy copy load on its own until it is deactivated.
    return;
}

define( 'WC_GSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_GSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_GSS_VERSION', '1.0.10' );

require_once WC_GSS_PLUGIN_DIR . 'includes/class-wc-gss-google-oauth.php';
require_once WC_GSS_PLUGIN_DIR . 'includes/class-wc-gss-private-sheet.php';
require_once WC_GSS_PLUGIN_DIR . 'includes/class-wc-gss-admin.php';

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', static function () {
    $plugin = new WC_Google_Sheet_Sync_Admin();
    $plugin->init();
} );

register_activation_hook( __FILE__, static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( __( 'WC Google Sheet Sync requires WooCommerce to be installed and active.', 'wc-google-sheet-sync' ), 'Plugin Dependency Error', array( 'back_link' => true ) );
    }
} );
