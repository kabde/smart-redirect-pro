<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

function srp_uninstall_blog() {
    global $wpdb;

    // Delete all srp_redirect posts
    $posts = get_posts([
        'post_type'   => 'srp_redirect',
        'numberposts' => -1,
        'post_status' => 'any',
    ]);
    foreach ( $posts as $post ) {
        wp_delete_post( $post->ID, true );
    }

    // Delete srp_folder terms
    $folders = get_terms([
        'taxonomy'   => 'srp_folder',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if ( ! is_wp_error( $folders ) ) {
        foreach ( $folders as $term_id ) {
            wp_delete_term( $term_id, 'srp_folder' );
        }
    }

    // Delete srp_tag terms
    $tags = get_terms([
        'taxonomy'   => 'srp_tag',
        'hide_empty' => false,
        'fields'     => 'ids',
    ]);
    if ( ! is_wp_error( $tags ) ) {
        foreach ( $tags as $term_id ) {
            wp_delete_term( $term_id, 'srp_tag' );
        }
    }

    // Drop srp_clicks table
    $table = $wpdb->prefix . 'srp_clicks';
    $wpdb->query( "DROP TABLE IF EXISTS $table" );

    // Delete options
    delete_option( 'srp_settings' );
    delete_option( 'srp_license_key' );
    delete_option( 'srp_license_status' );
    delete_option( 'srp_license_domain' );
    delete_option( 'srp_db_version' );
    delete_transient( 'srp_license_valid' );

    // Remove capability
    $role = get_role( 'administrator' );
    if ( $role ) {
        $role->remove_cap( 'manage_srp' );
    }
}

if ( is_multisite() ) {
    $site_ids = get_sites([ 'fields' => 'ids', 'number' => 0 ]);
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        srp_uninstall_blog();
        restore_current_blog();
    }
} else {
    srp_uninstall_blog();
}
