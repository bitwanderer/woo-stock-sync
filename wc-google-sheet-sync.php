<?php
/**
 * Plugin Name: WC Google Sheet Sync
 * Plugin URI:  https://github.com/bitwanderer/woo-stock-sync/
 * Description: One-way synchronization of stock and pricing data from a Google Sheet to WooCommerce products based on SKU.
 * Version:     1.0.0
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

/**
 * Define plugin constants.
 */
define( 'WC_GSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_GSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_GSS_VERSION', '1.0.0' );

/**
 * Declare HPOS compatibility.
 * This is crucial for WooCommerce to recognize the plugin as compatible
 * beyond just the plugin header.
 */
add_action( 'before_woocommerce_init', 'wc_gss_declare_hpos_compat' );

function wc_gss_declare_hpos_compat() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}


/**
 * The core plugin class that is responsible for including the admin-specific functions.
 */
require_once WC_GSS_PLUGIN_DIR . 'includes/class-wc-gss-admin.php';

/**
 * Initialize the plugin.
 */
function wc_gss_run_plugin() {
    $plugin = new WC_Google_Sheet_Sync_Admin();
    $plugin->init();
}
add_action( 'plugins_loaded', 'wc_gss_run_plugin' );

/**
 * Activation hook: Ensures WooCommerce is active.
 */
function wc_gss_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( __( 'WC Google Sheet Sync requires WooCommerce to be installed and active.', 'wc-google-sheet-sync' ), 'Plugin Dependency Error', array( 'back_link' => true ) );
    }
}
register_activation_hook( __FILE__, 'wc_gss_activate' );

// This is the main plugin file, it loads the necessary classes.
// No other logic needs to be here.