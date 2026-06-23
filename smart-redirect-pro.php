<?php
/**
 * Plugin Name: Smart Redirect Pro
 * Description: Professional URL redirect manager with click tracking, folders, tags, QR codes, and shortcodes.
 * Version:     1.0.0
 * Author:      Abderrahim KHALID
 * Text Domain: smart-redirect-pro
 * Domain Path: /languages
 * Network:     true
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SRP_VERSION', '1.0.0' );
define( 'SRP_FILE', __FILE__ );
define( 'SRP_BASENAME', plugin_basename( __FILE__ ) );
define( 'SRP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SRP_URL',  plugin_dir_url( __FILE__ ) );
define( 'SRP_CAPABILITY', 'manage_srp' );
define( 'SRP_API_URL', 'https://dp-starter.khalid.digital' );

// Load translations
function srp_load_textdomain() {
    load_plugin_textdomain( 'smart-redirect-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'srp_load_textdomain' );

// License system FIRST
require_once SRP_PATH . 'inc/license.php';

// Settings page (always loaded — includes license tab)
require_once SRP_PATH . 'admin/class-srp-settings.php';
new SRP_Settings();

// Only load the rest if licensed
if ( srp_is_licensed() ) {
    require_once SRP_PATH . 'admin/class-srp-cpt.php';
    require_once SRP_PATH . 'admin/class-srp-redirect.php';
    require_once SRP_PATH . 'admin/class-srp-tracker.php';

    if ( is_admin() ) {
        require_once SRP_PATH . 'admin/class-srp-meta.php';
    }

    new SRP_CPT();
    new SRP_Redirect();
    new SRP_Tracker();

    if ( is_admin() ) {
        new SRP_Meta();
    }

    // Load premium code from Worker
    srp_load_premium_code();
}

function srp_add_caps_for_blog() {
    $role = get_role( 'administrator' );
    if ( ! $role ) return;
    $role->add_cap( SRP_CAPABILITY );
}

function srp_activate( $network_wide = false ) {
    if ( is_multisite() && $network_wide ) {
        $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            srp_add_caps_for_blog();
            restore_current_blog();
        }
    } else {
        srp_add_caps_for_blog();
    }
    if ( srp_is_licensed() ) {
        SRP_CPT::register();
    }
    // Create tracker table
    if ( class_exists( 'SRP_Tracker' ) ) {
        SRP_Tracker::create_table();
    } else {
        require_once SRP_PATH . 'admin/class-srp-tracker.php';
        SRP_Tracker::create_table();
    }
    // Initialize settings defaults if not set
    if ( function_exists('srp_settings_defaults') ) {
        $defaults = srp_settings_defaults();
        $current = get_option( 'srp_settings', [] );
        if ( empty( $current ) ) {
            update_option( 'srp_settings', $defaults );
        }
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'srp_activate' );

function srp_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'srp_deactivate' );

function srp_add_caps_on_new_blog( $blog_id ) {
    if ( ! is_multisite() ) return;
    switch_to_blog( $blog_id );
    srp_add_caps_for_blog();
    restore_current_blog();
}
add_action( 'wpmu_new_blog', 'srp_add_caps_on_new_blog' );

function srp_maybe_add_caps() {
    $role = get_role( 'administrator' );
    if ( $role && ! $role->has_cap( SRP_CAPABILITY ) ) {
        $role->add_cap( SRP_CAPABILITY );
    }
}
add_action( 'admin_init', 'srp_maybe_add_caps' );
