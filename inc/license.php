<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check if plugin is licensed
 */
function srp_is_licensed() {
    // Check cached result first
    static $result = null;
    if ( $result !== null ) return $result;

    $status = get_option( 'srp_license_status', '' );
    if ( $status === 'valid' ) {
        // Check transient for periodic revalidation
        if ( false === get_transient( 'srp_license_valid' ) ) {
            // Schedule revalidation but don't block
            if ( ! wp_next_scheduled( 'srp_validate_license_cron' ) ) {
                wp_schedule_single_event( time() + 10, 'srp_validate_license_cron' );
            }
        }
        $result = true;
        return true;
    }
    $result = false;
    return false;
}

/**
 * Activate license
 */
function srp_activate_license( $key ) {
    $attempts = (int) get_transient( 'srp_license_attempts' );
    if ( $attempts >= 5 ) {
        return [ 'success' => false, 'message' => __( 'Too many attempts. Please try again in a minute.', 'smart-redirect-pro' ) ];
    }
    set_transient( 'srp_license_attempts', $attempts + 1, MINUTE_IN_SECONDS );

    $key = strtoupper( sanitize_text_field( trim( $key ) ) );
    if ( ! preg_match( '/^SRP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key ) ) {
        return [ 'success' => false, 'message' => __( 'Invalid license format.', 'smart-redirect-pro' ) ];
    }

    $response = wp_remote_post( SRP_API_URL . '/activate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'smart-redirect-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    if ( is_wp_error( $response ) ) {
        error_log( '[SRP] License activation error: ' . $response->get_error_message() );
        /* translators: %s: error message from server */
        return [ 'success' => false, 'message' => sprintf( __( 'Connection error: %s', 'smart-redirect-pro' ), $response->get_error_message() ) ];
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['success'] ) ) {
        update_option( 'srp_license_key', $key );
        update_option( 'srp_license_status', 'valid' );
        update_option( 'srp_license_domain', home_url() );
        if ( isset( $body['expires_at'] ) ) {
            update_option( 'srp_license_expires_at', sanitize_text_field( $body['expires_at'] ) );
        }
        set_transient( 'srp_license_valid', 1, 72 * HOUR_IN_SECONDS );
        return [ 'success' => true, 'message' => $body['message'] ?? __( 'License activated.', 'smart-redirect-pro' ) ];
    }

    return [ 'success' => false, 'message' => $body['message'] ?? __( 'Activation failed.', 'smart-redirect-pro' ) ];
}

/**
 * Deactivate license
 */
function srp_deactivate_license() {
    $key = get_option( 'srp_license_key', '' );
    if ( empty( $key ) ) return;

    wp_remote_post( SRP_API_URL . '/deactivate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'smart-redirect-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    delete_option( 'srp_license_key' );
    delete_option( 'srp_license_status' );
    delete_option( 'srp_license_domain' );
    delete_option( 'srp_license_expires_at' );
    delete_transient( 'srp_license_valid' );
}

/**
 * Validate license (called by cron)
 */
function srp_validate_license() {
    $key = get_option( 'srp_license_key', '' );
    if ( empty( $key ) ) return;

    $response = wp_remote_post( SRP_API_URL . '/validate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'smart-redirect-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    if ( is_wp_error( $response ) ) {
        error_log( '[SRP] License validation error: ' . $response->get_error_message() );
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['valid'] ) ) {
        update_option( 'srp_license_status', 'valid' );
        if ( isset( $body['expires_at'] ) ) {
            update_option( 'srp_license_expires_at', sanitize_text_field( $body['expires_at'] ) );
        }
        set_transient( 'srp_license_valid', 1, 72 * HOUR_IN_SECONDS );
    } else {
        update_option( 'srp_license_status', 'invalid' );
        delete_transient( 'srp_license_valid' );
    }
}
add_action( 'srp_validate_license_cron', 'srp_validate_license' );

// Schedule cron
function srp_schedule_validation() {
    if ( ! wp_next_scheduled( 'srp_validate_license_cron' ) && srp_is_licensed() ) {
        wp_schedule_event( time(), 'twicedaily', 'srp_validate_license_cron' );
    }
}
add_action( 'init', 'srp_schedule_validation' );

// Cleanup cron on deactivation
register_deactivation_hook( SRP_FILE, function() {
    wp_clear_scheduled_hook( 'srp_validate_license_cron' );
    delete_transient( 'srp_license_valid' );
    delete_transient( 'srp_premium_fresh' );
});

/**
 * Auto-update via Worker
 */
function srp_check_plugin_update( $transient ) {
    if ( empty( $transient ) || ! is_object( $transient ) ) return $transient;

    $response = wp_remote_get( SRP_API_URL . '/update-check?product=smart-redirect-pro', [
        'timeout' => 10,
    ]);

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return $transient;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['version'] ) || ! version_compare( SRP_VERSION, $data['version'], '<' ) ) {
        return $transient;
    }

    $transient->response[ SRP_BASENAME ] = (object) [
        'slug'         => 'smart-redirect-pro',
        'plugin'       => SRP_BASENAME,
        'new_version'  => $data['version'],
        'url'          => $data['url'] ?? '',
        'package'      => $data['download_url'] ?? '',
        'tested'       => '7.0',
        'requires'     => '5.0',
        'requires_php' => '7.4',
    ];

    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'srp_check_plugin_update' );

// Force update check on admin pages (don't wait 12h)
function srp_force_update_check() {
    $last = get_option( 'srp_last_update_check', 0 );
    if ( time() - $last > 3600 ) { // max once per hour
        delete_site_transient( 'update_plugins' );
        update_option( 'srp_last_update_check', time() );
    }
}
add_action( 'admin_init', 'srp_force_update_check' );

/**
 * Admin notice when not licensed
 */
function srp_admin_notice_no_license() {
    if ( srp_is_licensed() ) return;
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'toplevel_page_srp-settings' ) return;

    echo '<div class="notice notice-warning"><p>';
    echo '<strong>Smart Redirect Pro</strong> — ';
    printf(
        /* translators: %s: URL to the settings page */
        esc_html__( 'Please %s to use the plugin.', 'smart-redirect-pro' ),
        '<a href="' . esc_url( admin_url( 'admin.php?page=srp-settings' ) ) . '">' . esc_html__( 'activate your license', 'smart-redirect-pro' ) . '</a>'
    );
    echo '</p></div>';
}
add_action( 'admin_notices', 'srp_admin_notice_no_license' );

function srp_admin_notice_expiring() {
    if ( ! srp_is_licensed() ) return;
    $expires = get_option( 'srp_license_expires_at', '' );
    if ( ! $expires ) return;
    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
    if ( $days > 14 ) return;
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'toplevel_page_srp-settings' ) return;

    if ( $days <= 0 ) {
        echo '<div class="notice notice-error"><p><strong>Smart Redirect Pro</strong> — ';
        printf(
            /* translators: %s: URL to renewal page */
            esc_html__( 'Your license has expired. %s', 'smart-redirect-pro' ),
            '<a href="' . esc_url( admin_url( 'edit.php?post_type=srp_redirect&page=srp-settings' ) ) . '">' . esc_html__( 'Renew', 'smart-redirect-pro' ) . '</a>'
        );
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p><strong>Smart Redirect Pro</strong> — ';
        printf(
            /* translators: 1: number of days, 2: link to settings page */
            esc_html( _n( 'Your license expires in %1$d day. %2$s', 'Your license expires in %1$d days. %2$s', $days, 'smart-redirect-pro' ) ),
            $days,
            '<a href="' . esc_url( admin_url( 'edit.php?post_type=srp_redirect&page=srp-settings' ) ) . '">' . esc_html__( 'View', 'smart-redirect-pro' ) . '</a>'
        );
        echo '</p></div>';
    }
}
add_action( 'admin_notices', 'srp_admin_notice_expiring' );

/**
 * AJAX handlers
 */
function srp_ajax_activate_license() {
    check_ajax_referer( 'srp_license_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied.', 'smart-redirect-pro' ) );

    $key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
    $result = srp_activate_license( $key );

    if ( $result['success'] ) {
        wp_send_json_success( $result['message'] );
    } else {
        wp_send_json_error( $result['message'] );
    }
}
add_action( 'wp_ajax_srp_activate_license', 'srp_ajax_activate_license' );

function srp_ajax_deactivate_license() {
    check_ajax_referer( 'srp_license_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied.', 'smart-redirect-pro' ) );

    srp_deactivate_license();
    wp_send_json_success( __( 'License deactivated.', 'smart-redirect-pro' ) );
}
add_action( 'wp_ajax_srp_deactivate_license', 'srp_ajax_deactivate_license' );

/**
 * Derive encryption key from license key.
 */
function srp_get_encryption_key() {
    $key = get_option( 'srp_license_key', '' );
    if ( ! $key ) return '';
    $raw = strtoupper( str_replace( '-', '', $key ) );
    return str_pad( substr( $raw, 0, 32 ), 32, '0' );
}

/**
 * Decrypt AES-256-GCM data from Worker.
 */
function srp_decrypt_aes( $encrypted, $key ) {
    $raw = base64_decode( $encrypted, true );
    if ( ! $raw || strlen( $raw ) < 29 ) return false; // 12 IV + 1 min data + 16 tag

    $iv         = substr( $raw, 0, 12 );
    $ciphertext = substr( $raw, 12 );

    $decrypted = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr( $ciphertext, -16 ) );

    // openssl_decrypt with GCM: tag is appended to ciphertext
    // Try alternative: separate tag
    if ( $decrypted === false ) {
        $tag  = substr( $raw, -16 );
        $data = substr( $raw, 12, -16 );
        $decrypted = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    }

    return $decrypted;
}

/**
 * Download premium PHP files from Worker.
 */
function srp_download_premium() {
    $key    = get_option( 'srp_license_key', '' );
    $domain = home_url();

    if ( ! $key ) return false;

    $response = wp_remote_post( SRP_API_URL . '/premium', [
        'timeout' => 30,
        'body'    => wp_json_encode( [
            'license_key' => $key,
            'domain'      => $domain,
            'product'     => 'smart-redirect-pro',
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[SRP] Premium download error: ' . $response->get_error_message() );
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['files'] ) || ! is_array( $body['files'] ) ) return false;

    update_option( 'srp_premium_files', $body['files'], false );
    set_transient( 'srp_premium_fresh', 1, DAY_IN_SECONDS );
    return true;
}

/**
 * Load premium code from stored encrypted files.
 */
function srp_load_premium_code() {
    if ( ! srp_is_licensed() ) return;

    // Re-download if stale
    if ( false === get_transient( 'srp_premium_fresh' ) ) {
        srp_download_premium();
    }

    $files = get_option( 'srp_premium_files', [] );
    if ( ! is_array( $files ) || empty( $files ) ) return;

    $enc_key = srp_get_encryption_key();
    if ( ! $enc_key ) return;

    // Load order
    $load_order = [ 'click-log' ];

    foreach ( $load_order as $name ) {
        if ( ! isset( $files[ $name ] ) ) continue;
        $code = srp_decrypt_aes( $files[ $name ], $enc_key );
        if ( $code && is_string( $code ) ) {
            eval( $code );
        }
    }
}
