<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SRP_Settings {

    const OPTION_KEY = 'srp_settings';

    /** @var string Settings page hook suffix */
    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* --- Menu --- */

    public function add_menu() {
        if ( srp_is_licensed() ) {
            $this->hook = add_submenu_page(
                'edit.php?post_type=srp_redirect',
                __( 'Settings', 'smart-redirect-pro' ),
                __( 'Settings', 'smart-redirect-pro' ),
                'manage_options',
                'srp-settings',
                [ $this, 'render' ]
            );
        } else {
            $this->hook = add_menu_page(
                'Smart Redirect Pro',
                'Smart Redirect Pro',
                'manage_options',
                'srp-settings',
                [ $this, 'render' ],
                'dashicons-randomize',
                22
            );
        }
    }

    /* --- Register --- */

    public function register_settings() {
        register_setting( 'srp_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize' ],
        ] );

        add_filter( 'allowed_options', function ( $allowed ) {
            $allowed['srp_settings_group'] = [ 'srp_settings' ];
            return $allowed;
        } );
    }

    /* --- Sanitize --- */

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];
        $clean = [];
        $old   = get_option( self::OPTION_KEY, [] );

        // General
        $base_slug = isset( $input['base_slug'] ) ? sanitize_title( $input['base_slug'] ) : 'go';
        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base_slug ) || empty( $base_slug ) ) {
            $base_slug = 'go';
        }
        $clean['base_slug'] = $base_slug;

        $clean['url_format']          = in_array( $input['url_format'] ?? '', [ 'slug', 'id' ], true ) ? $input['url_format'] : 'slug';
        $clean['default_type']        = in_array( $input['default_type'] ?? '', [ '301', '302', '307' ], true ) ? $input['default_type'] : '302';
        $clean['default_nofollow']    = empty( $input['default_nofollow'] ) ? '0' : '1';
        $clean['default_sponsored']   = empty( $input['default_sponsored'] ) ? '0' : '1';
        $clean['default_pass_params'] = empty( $input['default_pass_params'] ) ? '0' : '1';

        // Advanced
        $clean['exclude_bots']    = empty( $input['exclude_bots'] ) ? '0' : '1';
        $clean['exclude_admins']  = empty( $input['exclude_admins'] ) ? '0' : '1';
        $clean['click_retention'] = in_array( $input['click_retention'] ?? '', [ '30', '60', '90', '180', '365', 'unlimited' ], true ) ? $input['click_retention'] : '90';
        $clean['custom_css']      = wp_strip_all_tags( $input['custom_css'] ?? '' );

        // Flush rewrite rules if base_slug changed
        $old_slug = isset( $old['base_slug'] ) ? $old['base_slug'] : 'go';
        if ( $clean['base_slug'] !== $old_slug ) {
            add_action( 'shutdown', 'flush_rewrite_rules' );
        }

        return $clean;
    }

    /* --- Assets --- */

    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_media();

        // Code editor for custom CSS
        if ( function_exists( 'wp_enqueue_code_editor' ) ) {
            $editor = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
            if ( false !== $editor ) {
                wp_add_inline_script( 'code-editor', sprintf(
                    'jQuery(function(){if(document.getElementById("srp_custom_css")){wp.codeEditor.initialize("srp_custom_css",%s);}});',
                    wp_json_encode( $editor )
                ) );
            }
        }
    }

    /* --- Render --- */

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'smart-redirect-pro' ) );
        }

        $licensed      = srp_is_licensed();
        $license_key   = get_option( 'srp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = srp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );
        $tabs = [
            'license'    => [ 'label' => __( 'License', 'smart-redirect-pro' ),       'icon' => 'dashicons-lock' ],
            'general'    => [ 'label' => __( 'General', 'smart-redirect-pro' ),       'icon' => 'dashicons-admin-settings' ],
            'stats'      => [ 'label' => __( 'Statistics', 'smart-redirect-pro' ),    'icon' => 'dashicons-chart-bar' ],
            'advanced'   => [ 'label' => __( 'Advanced', 'smart-redirect-pro' ),      'icon' => 'dashicons-admin-generic' ],
            'docs'       => [ 'label' => __( 'Documentation', 'smart-redirect-pro' ), 'icon' => 'dashicons-book' ],
        ];

        // Only show non-license tabs when licensed
        if ( ! $licensed ) {
            $tabs = [ 'license' => $tabs['license'] ];
        }

        $nonce = wp_create_nonce( 'srp_license_nonce' );
        ?>
        <style>
        /* -- Layout -- */
        #srp-settings-wrap { max-width: 1140px; margin-top: 20px; }
        .srp-settings-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .srp-settings-header h1 { margin: 0; font-size: 1.6rem; font-weight: 800; color: #1d2327; }
        .srp-settings-version { background: #f0f0f1; color: #787c82; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .srp-settings-layout { display: grid; grid-template-columns: 220px 1fr; gap: 0; min-height: 600px; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; background: #f6f7f7; }

        /* -- Sidebar -- */
        .srp-settings-sidebar { background: #1d2327; padding: 12px 0; display: flex; flex-direction: column; }
        .srp-sidebar-item { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: #bbc8d4; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 120ms; border-left: 3px solid transparent; cursor: pointer; }
        .srp-sidebar-item:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .srp-sidebar-item:focus { color: #fff; box-shadow: none; outline: none; }
        .srp-sidebar-item.is-active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #ffc45e; }
        .srp-sidebar-item .dashicons { font-size: 16px; width: 16px; height: 16px; opacity: 0.65; }
        .srp-sidebar-item.is-active .dashicons { opacity: 1; color: #ffc45e; }

        /* -- Panel -- */
        .srp-settings-panel { background: #fff; padding: 28px 32px; overflow-y: auto; }
        .srp-tab-content { display: none; }
        .srp-tab-content.is-active { display: block; animation: srpFadeIn 200ms ease; }
        @keyframes srpFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* -- Sections -- */
        .srp-admin-section { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px 28px; margin: 0 0 20px; }
        .srp-admin-section h2 { margin: 0 0 16px; padding: 0 0 12px; border-bottom: 1px solid #e5e7eb; font-size: 1.05em; font-weight: 700; color: #1d2327; }
        .srp-admin-section .form-table th { font-weight: 600; color: #374151; padding-top: 16px; }
        .srp-admin-section .form-table td { padding-top: 12px; }

        /* -- Submit button -- */
        .srp-settings-panel .submit { margin-top: 8px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .srp-settings-panel #submit { background: #1d2327; border-color: #1d2327; color: #fff; border-radius: 6px; padding: 6px 24px; font-weight: 600; transition: background 120ms; }
        .srp-settings-panel #submit:hover { background: #2c3338; }

        /* -- License card -- */
        .srp-license-card { max-width: 600px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 30px; }
        .srp-license-active { display: inline-block; background: #00a32a; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }
        .srp-license-inactive { display: inline-block; background: #dba617; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }

        /* -- Stats chart -- */
        .srp-chart {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 160px;
            padding: 0 4px;
            margin-bottom: 24px;
        }
        .srp-chart-bar {
            flex: 1;
            background: linear-gradient(to top, #ffc45e, #ffd47f);
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            transition: height 300ms ease;
        }
        .srp-chart-value {
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            padding-top: 4px;
        }
        .srp-chart-label {
            position: absolute;
            bottom: -20px;
            font-size: 10px;
            color: #6b7280;
            white-space: nowrap;
        }

        /* -- Responsive -- */
        @media (max-width: 960px) {
            .srp-settings-layout { grid-template-columns: 1fr; }
            .srp-settings-sidebar { flex-direction: row; flex-wrap: wrap; padding: 8px; gap: 4px; }
            .srp-sidebar-item { padding: 8px 12px; border-left: none; border-bottom: 2px solid transparent; font-size: 12px; }
            .srp-sidebar-item.is-active { border-left: none; border-bottom-color: #ffc45e; }
            .srp-sidebar-item .dashicons { display: none; }
            .srp-settings-panel { padding: 20px 16px; }
        }
        </style>

        <div id="srp-settings-wrap" class="wrap">

            <!-- Header -->
            <div class="srp-settings-header">
                <h1>Smart Redirect Pro</h1>
                <span class="srp-settings-version">v<?php echo esc_html( SRP_VERSION ); ?></span>
            </div>

            <div class="srp-settings-layout">

                <!-- Sidebar -->
                <nav class="srp-settings-sidebar">
                    <?php foreach ( $tabs as $slug => $tab ) : ?>
                        <a href="#<?php echo esc_attr( $slug ); ?>" class="srp-sidebar-item" data-tab="<?php echo esc_attr( $slug ); ?>">
                            <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                            <?php echo esc_html( $tab['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Panel -->
                <div class="srp-settings-panel">

                    <!-- License Tab -->
                    <div id="srp-tab-license" class="srp-tab-content">
                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'License', 'smart-redirect-pro' ); ?></h2>
                            <div class="srp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="srp-license-active">&#10003; <?php esc_html_e( 'License Active', 'smart-redirect-pro' ); ?></span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th><?php esc_html_e( 'License key', 'smart-redirect-pro' ); ?></th>
                                            <td><?php
$masked = substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4);
?><code style="font-size:14px;"><?php echo esc_html($masked); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Domain', 'smart-redirect-pro' ); ?></th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Expiration', 'smart-redirect-pro' ); ?></th>
                                            <td>
                                                <?php
                                                $expires = get_option( 'srp_license_expires_at', '' );
                                                if ( $expires ) {
                                                    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
                                                    $date_formatted = wp_date( 'd F Y', strtotime( $expires ) );
                                                    if ( $days <= 0 ) {
                                                        /* translators: %s: formatted expiration date */
                                                        echo '<span style="color:#dc2626;font-weight:600;">' . esc_html( sprintf( __( 'Expired on %s', 'smart-redirect-pro' ), $date_formatted ) ) . '</span>';
                                                    } elseif ( $days <= 30 ) {
                                                        /* translators: 1: formatted date, 2: number of days remaining */
                                                        echo '<span style="color:#d97706;font-weight:600;">' . esc_html( sprintf( _n( '%1$s (%2$d day remaining)', '%1$s (%2$d days remaining)', $days, 'smart-redirect-pro' ), $date_formatted, $days ) ) . '</span>';
                                                    } else {
                                                        /* translators: 1: formatted date, 2: number of days remaining */
                                                        echo '<span style="color:#16a34a;">' . esc_html( sprintf( __( '%1$s (%2$d days remaining)', 'smart-redirect-pro' ), $date_formatted, $days ) ) . '</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#16a34a;">' . esc_html__( 'Lifetime (no expiration)', 'smart-redirect-pro' ) . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="srp-deactivate-btn" class="button button-secondary" style="color:#d63638;"><?php esc_html_e( 'Deactivate license', 'smart-redirect-pro' ); ?></button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;"><?php esc_html_e( 'Activate your license', 'smart-redirect-pro' ); ?></h2>
                                    <p><?php esc_html_e( 'Enter your license key to activate Smart Redirect Pro.', 'smart-redirect-pro' ); ?></p>
                                    <p>
                                        <input type="text" id="srp-license-key" placeholder="SRP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="srp-activate-btn" class="button button-primary button-hero" style="width:100%;"><?php esc_html_e( 'Activate license', 'smart-redirect-pro' ); ?></button>
                                    </p>
                                    <div id="srp-license-message" style="margin-top:15px;display:none;"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ( $licensed ) : ?>

                    <!-- Form wraps General + Stats + Advanced -->
                    <form method="post" action="options.php" id="srp-settings-form">
                        <?php settings_fields( 'srp_settings_group' ); ?>
                        <input type="hidden" id="srp_active_tab" name="srp_active_tab" value="">

                        <!-- General Tab -->
                        <div id="srp-tab-general" class="srp-tab-content">
                            <div class="srp-admin-section">
                                <h2><?php esc_html_e( 'General Settings', 'smart-redirect-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Base slug', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <input type="text" name="srp_settings[base_slug]" value="<?php echo esc_attr( $s['base_slug'] ); ?>" class="regular-text" placeholder="go">
                                            <p class="description"><?php
                                                /* translators: %1$s: site URL, %2$s: current base slug */
                                                printf( esc_html__( 'Short URL prefix. E.g.: %1$s/%2$s/my-link', 'smart-redirect-pro' ), '<code>' . esc_html( home_url() ) . '</code>', '<strong>' . esc_html( $s['base_slug'] ) . '</strong>' );
                                            ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'URL format', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <select name="srp_settings[url_format]">
                                                <option value="slug" <?php selected( $s['url_format'], 'slug' ); ?>><?php esc_html_e( '/go/my-link (slug)', 'smart-redirect-pro' ); ?></option>
                                                <option value="id" <?php selected( $s['url_format'], 'id' ); ?>><?php esc_html_e( '/go/?p=123 (ID)', 'smart-redirect-pro' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Default redirect type', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <select name="srp_settings[default_type]">
                                                <option value="301" <?php selected( $s['default_type'], '301' ); ?>><?php esc_html_e( '301 — Permanent', 'smart-redirect-pro' ); ?></option>
                                                <option value="302" <?php selected( $s['default_type'], '302' ); ?>><?php esc_html_e( '302 — Temporary', 'smart-redirect-pro' ); ?></option>
                                                <option value="307" <?php selected( $s['default_type'], '307' ); ?>><?php esc_html_e( '307 — Strict Temporary', 'smart-redirect-pro' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Default attributes', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <label style="display:block;margin-bottom:6px;">
                                                <input type="checkbox" name="srp_settings[default_nofollow]" value="1" <?php checked( $s['default_nofollow'], '1' ); ?>>
                                                nofollow
                                            </label>
                                            <label style="display:block;margin-bottom:6px;">
                                                <input type="checkbox" name="srp_settings[default_sponsored]" value="1" <?php checked( $s['default_sponsored'], '1' ); ?>>
                                                sponsored
                                            </label>
                                            <label style="display:block;">
                                                <input type="checkbox" name="srp_settings[default_pass_params]" value="1" <?php checked( $s['default_pass_params'], '1' ); ?>>
                                                <?php esc_html_e( 'Forward query parameters', 'smart-redirect-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( __( 'Save', 'smart-redirect-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Stats Tab (read-only, no form fields) -->
                        <div id="srp-tab-stats" class="srp-tab-content">
                            <?php $this->render_stats_tab(); ?>
                        </div>

                        <!-- Advanced Tab -->
                        <div id="srp-tab-advanced" class="srp-tab-content">
                            <div class="srp-admin-section">
                                <h2><?php esc_html_e( 'Tracking', 'smart-redirect-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Exclude bots', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="srp_settings[exclude_bots]" value="1" <?php checked( $s['exclude_bots'], '1' ); ?>>
                                                <?php esc_html_e( 'Do not count clicks from robots/crawlers', 'smart-redirect-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Exclude admins', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="srp_settings[exclude_admins]" value="1" <?php checked( $s['exclude_admins'], '1' ); ?>>
                                                <?php esc_html_e( 'Do not count clicks from administrators', 'smart-redirect-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Click retention', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <select name="srp_settings[click_retention]">
                                                <option value="30" <?php selected( $s['click_retention'], '30' ); ?>><?php
                                                    /* translators: %d: number of days */
                                                    printf( esc_html__( '%d days', 'smart-redirect-pro' ), 30 ); ?></option>
                                                <option value="60" <?php selected( $s['click_retention'], '60' ); ?>><?php printf( esc_html__( '%d days', 'smart-redirect-pro' ), 60 ); ?></option>
                                                <option value="90" <?php selected( $s['click_retention'], '90' ); ?>><?php printf( esc_html__( '%d days', 'smart-redirect-pro' ), 90 ); ?></option>
                                                <option value="180" <?php selected( $s['click_retention'], '180' ); ?>><?php printf( esc_html__( '%d days', 'smart-redirect-pro' ), 180 ); ?></option>
                                                <option value="365" <?php selected( $s['click_retention'], '365' ); ?>><?php esc_html_e( '1 year', 'smart-redirect-pro' ); ?></option>
                                                <option value="unlimited" <?php selected( $s['click_retention'], 'unlimited' ); ?>><?php esc_html_e( 'Unlimited', 'smart-redirect-pro' ); ?></option>
                                            </select>
                                            <p class="description"><?php esc_html_e( 'How long click data is kept. Older data is automatically deleted.', 'smart-redirect-pro' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="srp-admin-section">
                                <h2><?php esc_html_e( 'Custom CSS', 'smart-redirect-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Custom CSS', 'smart-redirect-pro' ); ?></th>
                                        <td>
                                            <textarea id="srp_custom_css" name="srp_settings[custom_css]" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
                                            <p class="description"><?php esc_html_e( 'Custom CSS applied on the front-end.', 'smart-redirect-pro' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( __( 'Save', 'smart-redirect-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                    </form>

                    <!-- Documentation tab (outside the form, no save needed) -->
                    <div id="srp-tab-docs" class="srp-tab-content">

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Getting Started', 'smart-redirect-pro' ); ?></h2>
                            <ol style="line-height:2;font-size:14px;color:#374151;">
                                <li><?php
                                    /* translators: %s: bold "Redirects > Add Redirect" menu path */
                                    printf( esc_html__( 'Go to %s', 'smart-redirect-pro' ), '<strong>' . esc_html__( 'Redirects &rarr; Add Redirect', 'smart-redirect-pro' ) . '</strong>' );
                                ?></li>
                                <li><?php
                                    /* translators: %s: bold word "title" */
                                    printf( esc_html__( 'Give it a %s (e.g. "Amazon France")', 'smart-redirect-pro' ), '<strong>' . esc_html__( 'title', 'smart-redirect-pro' ) . '</strong>' );
                                ?></li>
                                <li><?php
                                    printf(
                                        /* translators: 1: bold "slug", 2: example slug code, 3: resulting URL code */
                                        esc_html__( 'Customize the %1$s (e.g. %2$s) to get the URL %3$s', 'smart-redirect-pro' ),
                                        '<strong>' . esc_html__( 'slug', 'smart-redirect-pro' ) . '</strong>',
                                        '<code>amazon</code>',
                                        '<code>' . esc_html( home_url( '/' . $s['base_slug'] . '/amazon/' ) ) . '</code>'
                                    );
                                ?></li>
                                <li><?php
                                    printf( esc_html__( 'Paste the %s', 'smart-redirect-pro' ), '<strong>' . esc_html__( 'destination URL', 'smart-redirect-pro' ) . '</strong>' );
                                ?></li>
                                <li><?php
                                    printf( esc_html__( 'Choose the %s and attributes', 'smart-redirect-pro' ), '<strong>' . esc_html__( 'redirect type', 'smart-redirect-pro' ) . '</strong>' );
                                ?></li>
                                <li><?php
                                    printf( esc_html__( '%s — the redirect is active immediately', 'smart-redirect-pro' ), '<strong>' . esc_html__( 'Publish', 'smart-redirect-pro' ) . '</strong>' );
                                ?></li>
                            </ol>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Redirect Types', 'smart-redirect-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Code', 'smart-redirect-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Name', 'smart-redirect-pro' ); ?></th>
                                        <th><?php esc_html_e( 'When to use', 'smart-redirect-pro' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="srp-badge srp-badge-301">301</span></td>
                                        <td><?php esc_html_e( 'Permanent', 'smart-redirect-pro' ); ?></td>
                                        <td><?php esc_html_e( 'The link has permanently changed. Search engines transfer "link juice" to the destination. Caution: browsers cache this, hard to reverse.', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><span class="srp-badge srp-badge-302">302</span></td>
                                        <td><?php esc_html_e( 'Temporary', 'smart-redirect-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Temporary redirect. Ideal for affiliate links, promotions, A/B tests. Recommended by default.', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><span class="srp-badge srp-badge-307">307</span></td>
                                        <td><?php esc_html_e( 'Strict Temporary', 'smart-redirect-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Like 302, but guarantees the HTTP method (GET/POST) is preserved. Used for forms or APIs.', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Link Attributes', 'smart-redirect-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Attribute', 'smart-redirect-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Effect', 'smart-redirect-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Recommendation', 'smart-redirect-pro' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>nofollow</code></td>
                                        <td><?php esc_html_e( 'Tells search engines not to follow the link and not to transfer SEO authority.', 'smart-redirect-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Enabled by default. Keep it for affiliate links and external links.', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>sponsored</code></td>
                                        <td><?php esc_html_e( 'Tells Google this is a commercial or sponsored link.', 'smart-redirect-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Recommended for affiliate links. Compliant with Google guidelines.', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Shortcodes', 'smart-redirect-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Shortcode', 'smart-redirect-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Result', 'smart-redirect-pro' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>[srp_link slug="amazon"]</code></td>
                                        <td><?php
                                            /* translators: %s: example HTML link code */
                                            printf( esc_html__( 'Generates an HTML link: %s', 'smart-redirect-pro' ), '<code>&lt;a href="/' . esc_html( $s['base_slug'] ) . '/amazon/"&gt;Amazon&lt;/a&gt;</code>' );
                                        ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[srp_link slug="amazon" text="See on Amazon"]</code></td>
                                        <td><?php esc_html_e( 'Link with custom text', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[srp_link id="123"]</code></td>
                                        <td><?php esc_html_e( 'Link by post ID', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[srp_link slug="amazon" new_tab="no"]</code></td>
                                        <td><?php esc_html_e( 'Opens in the same tab (default: new tab)', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[srp_url slug="amazon"]</code></td>
                                        <td><?php esc_html_e( 'Returns only the URL (no HTML link) — useful in href attributes', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Organization: Folders & Tags', 'smart-redirect-pro' ); ?></h2>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                                <div>
                                    <h3 style="margin-top:0;"><?php esc_html_e( 'Folders', 'smart-redirect-pro' ); ?></h3>
                                    <p style="color:#6b7280;"><?php esc_html_e( 'Hierarchical (like categories). Used to group redirects by project, client, or theme.', 'smart-redirect-pro' ); ?></p>
                                    <p><strong><?php esc_html_e( 'Examples:', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( 'Affiliate, Partners, Offers, Social Media', 'smart-redirect-pro' ); ?></p>
                                </div>
                                <div>
                                    <h3 style="margin-top:0;"><?php esc_html_e( 'Tags', 'smart-redirect-pro' ); ?></h3>
                                    <p style="color:#6b7280;"><?php esc_html_e( 'Non-hierarchical (like labels). Used to add cross-cutting tags.', 'smart-redirect-pro' ); ?></p>
                                    <p><strong><?php esc_html_e( 'Examples:', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( 'Amazon, High-ticket, Summer-promo, Urgent', 'smart-redirect-pro' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'URL Settings', 'smart-redirect-pro' ); ?></h2>
                            <h3><?php esc_html_e( 'Base slug', 'smart-redirect-pro' ); ?></h3>
                            <p style="color:#374151;"><?php
                                printf(
                                    /* translators: 1: default slug code, 2: bold settings path */
                                    esc_html__( 'The short URL prefix. Default: %1$s. Changeable in %2$s.', 'smart-redirect-pro' ),
                                    '<code>go</code>',
                                    '<strong>' . esc_html__( 'Settings &rarr; General', 'smart-redirect-pro' ) . '</strong>'
                                );
                            ?></p>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr><th><?php esc_html_e( 'Base slug', 'smart-redirect-pro' ); ?></th><th><?php esc_html_e( 'Generated URL', 'smart-redirect-pro' ); ?></th></tr></thead>
                                <tbody>
                                    <tr><td><code>go</code></td><td><code><?php echo esc_html( home_url() ); ?>/go/amazon/</code></td></tr>
                                    <tr><td><code>r</code></td><td><code><?php echo esc_html( home_url() ); ?>/r/amazon/</code></td></tr>
                                    <tr><td><code>link</code></td><td><code><?php echo esc_html( home_url() ); ?>/link/amazon/</code></td></tr>
                                    <tr><td><code>do</code></td><td><code><?php echo esc_html( home_url() ); ?>/do/amazon/</code></td></tr>
                                </tbody>
                            </table>
                            <p style="color:#9ca3af;margin-top:12px;font-size:13px;"><?php esc_html_e( 'After modification, permalinks are automatically updated.', 'smart-redirect-pro' ); ?></p>

                            <h3 style="margin-top:24px;"><?php esc_html_e( 'URL format', 'smart-redirect-pro' ); ?></h3>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr>
                                    <th><?php esc_html_e( 'Format', 'smart-redirect-pro' ); ?></th>
                                    <th><?php esc_html_e( 'Example', 'smart-redirect-pro' ); ?></th>
                                    <th><?php esc_html_e( 'Advantage', 'smart-redirect-pro' ); ?></th>
                                </tr></thead>
                                <tbody>
                                    <tr>
                                        <td><strong><?php esc_html_e( 'Slug (default)', 'smart-redirect-pro' ); ?></strong></td>
                                        <td><code>/go/amazon/</code></td>
                                        <td><?php esc_html_e( 'SEO-friendly, memorable', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e( 'ID', 'smart-redirect-pro' ); ?></strong></td>
                                        <td><code>/go/?p=123</code></td>
                                        <td><?php esc_html_e( 'No slug conflict, shorter', 'smart-redirect-pro' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="color:#9ca3af;margin-top:12px;font-size:13px;"><?php esc_html_e( 'Both formats always work in parallel, regardless of the choice.', 'smart-redirect-pro' ); ?></p>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Parameter Pass-through', 'smart-redirect-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'When enabled on a redirect, query parameters from the short URL are forwarded to the destination:', 'smart-redirect-pro' ); ?></p>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr>
                                    <th><?php esc_html_e( 'Visited URL', 'smart-redirect-pro' ); ?></th>
                                    <th><?php esc_html_e( 'Destination', 'smart-redirect-pro' ); ?></th>
                                </tr></thead>
                                <tbody>
                                    <tr><td><code>/go/amazon/?ref=123&amp;utm_source=fb</code></td><td><code>https://amazon.com/product?ref=123&amp;utm_source=fb</code></td></tr>
                                </tbody>
                            </table>
                            <p style="color:#6b7280;margin-top:8px;font-size:13px;"><?php esc_html_e( 'Useful for affiliate tracking, UTM parameters, or campaign IDs.', 'smart-redirect-pro' ); ?></p>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'QR Codes', 'smart-redirect-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'A QR Code is automatically generated for each redirect. It is visible in the editor sidebar. The QR Code points to the short URL — if you change the destination, the QR Code remains the same.', 'smart-redirect-pro' ); ?></p>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Tracking', 'smart-redirect-pro' ); ?> <span style="background:#f0f0f1;color:#787c82;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;">Premium</span></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'Tracking is a premium feature that records each click with:', 'smart-redirect-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><strong><?php esc_html_e( 'Date and time', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( 'of the click', 'smart-redirect-pro' ); ?></li>
                                <li><strong><?php esc_html_e( 'Country', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( 'of the visitor (detected via Cloudflare)', 'smart-redirect-pro' ); ?></li>
                                <li><strong><?php esc_html_e( 'Browser', 'smart-redirect-pro' ); ?></strong> (Chrome, Firefox, Safari, Edge...)</li>
                                <li><strong><?php esc_html_e( 'Operating system', 'smart-redirect-pro' ); ?></strong> (Windows, macOS, iOS, Android...)</li>
                                <li><strong><?php esc_html_e( 'Device', 'smart-redirect-pro' ); ?></strong> (Desktop, Mobile, Tablet)</li>
                                <li><strong><?php esc_html_e( 'Referer', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( '(where the visitor came from)', 'smart-redirect-pro' ); ?></li>
                                <li><strong><?php esc_html_e( 'Hashed IP', 'smart-redirect-pro' ); ?></strong> <?php esc_html_e( '(privacy-friendly, never stored in plain text)', 'smart-redirect-pro' ); ?></li>
                            </ul>
                            <p style="color:#374151;"><?php
                                printf(
                                    /* translators: %s: bold menu path "Redirects > Tracking" */
                                    esc_html__( 'Accessible via %s. Filterable by redirect, country, browser, and date. CSV export available.', 'smart-redirect-pro' ),
                                    '<strong>' . esc_html__( 'Redirects &rarr; Tracking', 'smart-redirect-pro' ) . '</strong>'
                                );
                            ?></p>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'Security & Privacy', 'smart-redirect-pro' ); ?></h2>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php esc_html_e( 'IP addresses are never stored in plain text — they are hashed (SHA-256 + salt)', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'Bots (Googlebot, Bingbot...) are automatically excluded from statistics', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'Administrators are excluded by default (configurable)', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'Rate limiting prevents duplicate clicks (same IP within 5 seconds)', 'smart-redirect-pro' ); ?></li>
                                <li><?php
                                    printf(
                                        /* translators: %s: X-Robots-Tag header code */
                                        esc_html__( 'The %s header is sent when nofollow is enabled', 'smart-redirect-pro' ),
                                        '<code>X-Robots-Tag: noindex, nofollow</code>'
                                    );
                                ?></li>
                                <li><?php esc_html_e( 'Click data is automatically purged according to the configured retention', 'smart-redirect-pro' ); ?></li>
                            </ul>
                        </div>

                        <div class="srp-admin-section">
                            <h2><?php esc_html_e( 'License', 'smart-redirect-pro' ); ?></h2>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php
                                    printf(
                                        /* translators: %s: license key format code */
                                        esc_html__( 'The plugin requires a license key in the format %s', 'smart-redirect-pro' ),
                                        '<code>SRP-XXXX-XXXX-XXXX</code>'
                                    );
                                ?></li>
                                <li><?php esc_html_e( 'The license is automatically validated every 72 hours', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'Depending on your license, it can be single-domain (one site) or multi-domain (unlimited)', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'When changing domains, deactivate the license on the old domain first', 'smart-redirect-pro' ); ?></li>
                                <li><?php esc_html_e( 'Plugin updates are automatic via the WordPress admin', 'smart-redirect-pro' ); ?></li>
                            </ul>
                        </div>

                        <div class="srp-admin-section" style="background:#fefce8;border-color:#fde68a;">
                            <h2 style="border-color:#fde68a;"><?php esc_html_e( 'Support', 'smart-redirect-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'For any question or issue:', 'smart-redirect-pro' ); ?></p>
                            <ul style="list-style:none;padding:0;line-height:2.2;">
                                <li><?php esc_html_e( 'Email:', 'smart-redirect-pro' ); ?> <a href="mailto:contact@khalid.digital">contact@khalid.digital</a></li>
                                <li>GitHub: <a href="https://github.com/kabde/smart-redirect-pro" target="_blank">github.com/kabde/smart-redirect-pro</a></li>
                            </ul>
                        </div>

                    </div>

                    <?php endif; ?>

                </div><!-- .srp-settings-panel -->
            </div><!-- .srp-settings-layout -->
        </div><!-- #srp-settings-wrap -->

        <script>
        jQuery(function($) {
            /* -- Tab switching -- */
            var $items = $('.srp-sidebar-item');
            var $tabs  = $('.srp-tab-content');

            function activateTab(slug) {
                $items.removeClass('is-active');
                $tabs.removeClass('is-active');
                $items.filter('[data-tab="' + slug + '"]').addClass('is-active');
                $('#srp-tab-' + slug).addClass('is-active');
                $('#srp_active_tab').val(slug);
                if (history.replaceState) {
                    history.replaceState(null, null, '#' + slug);
                }
            }

            $items.on('click', function(e) {
                e.preventDefault();
                activateTab($(this).data('tab'));
            });

            // Determine initial tab
            var hash = window.location.hash.replace('#', '');
            var validTabs = [];
            $items.each(function() { validTabs.push($(this).data('tab')); });

            if (hash && validTabs.indexOf(hash) !== -1) {
                activateTab(hash);
            } else {
                activateTab(validTabs[0] || 'license');
            }

            /* -- License AJAX -- */
            var licenseNonce = '<?php echo esc_js( $nonce ); ?>';
            var srpI18n = {
                activating: '<?php echo esc_js( __( 'Activating...', 'smart-redirect-pro' ) ); ?>',
                activateLicense: '<?php echo esc_js( __( 'Activate license', 'smart-redirect-pro' ) ); ?>',
                connectionError: '<?php echo esc_js( __( 'Connection error.', 'smart-redirect-pro' ) ); ?>',
                deactivating: '<?php echo esc_js( __( 'Deactivating...', 'smart-redirect-pro' ) ); ?>',
                confirmDeactivate: '<?php echo esc_js( __( 'Deactivate the license on this domain?', 'smart-redirect-pro' ) ); ?>'
            };

            $('#srp-activate-btn').on('click', function() {
                var btn = $(this);
                var key = $('#srp-license-key').val().trim();
                if (!key) return;

                btn.prop('disabled', true).text(srpI18n.activating);

                $.post(ajaxurl, {
                    action: 'srp_activate_license',
                    nonce: licenseNonce,
                    license_key: key
                }, function(response) {
                    if (response.success) {
                        $('#srp-license-message').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>').show();
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        $('#srp-license-message').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>').show();
                        btn.prop('disabled', false).text(srpI18n.activateLicense);
                    }
                }).fail(function() {
                    $('#srp-license-message').html('<div class="notice notice-error inline"><p>' + srpI18n.connectionError + '</p></div>').show();
                    btn.prop('disabled', false).text(srpI18n.activateLicense);
                });
            });

            $('#srp-deactivate-btn').on('click', function() {
                if (!confirm(srpI18n.confirmDeactivate)) return;
                var btn = $(this);
                btn.prop('disabled', true).text(srpI18n.deactivating);

                $.post(ajaxurl, {
                    action: 'srp_deactivate_license',
                    nonce: licenseNonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /* --- Stats Tab Rendering --- */

    private function render_stats_tab() {
        $stats = function_exists( 'srp_get_stats_overview' ) ? srp_get_stats_overview() : [ 'redirects' => 0, 'total' => 0, 'week' => 0, 'month' => 0 ];
        $top   = function_exists( 'srp_get_top_redirects' ) ? srp_get_top_redirects( 10, 30 ) : [];
        $daily = function_exists( 'srp_get_daily_clicks' ) ? srp_get_daily_clicks( 7 ) : [];

        $maxClicks = 0;
        foreach ( $daily as $d ) {
            if ( $d->clicks > $maxClicks ) $maxClicks = $d->clicks;
        }
        ?>
        <!-- Stats cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#ffc45e;"><?php echo absint( $stats['redirects'] ); ?></strong>
                <p><?php esc_html_e( 'Redirects', 'smart-redirect-pro' ); ?></p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#ffc45e;"><?php echo absint( $stats['week'] ); ?></strong>
                <p><?php esc_html_e( 'Clicks (7 days)', 'smart-redirect-pro' ); ?></p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#ffc45e;"><?php echo absint( $stats['month'] ); ?></strong>
                <p><?php esc_html_e( 'Clicks (30 days)', 'smart-redirect-pro' ); ?></p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#ffc45e;"><?php echo absint( $stats['total'] ); ?></strong>
                <p><?php esc_html_e( 'Total clicks', 'smart-redirect-pro' ); ?></p>
            </div>
        </div>

        <!-- Top 10 -->
        <div class="srp-admin-section">
            <h2><?php esc_html_e( 'Top 10 — Last 30 days', 'smart-redirect-pro' ); ?></h2>
            <?php if ( ! empty( $top ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Redirect', 'smart-redirect-pro' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Clicks', 'smart-redirect-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top as $i => $row ) : ?>
                            <tr>
                                <td><?php echo absint( $i + 1 ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->ID ) . '&action=edit' ) ); ?>">
                                        <?php echo esc_html( $row->post_title ?: __( '(no title)', 'smart-redirect-pro' ) ); ?>
                                    </a>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?php echo absint( $row->clicks ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#6b7280;"><?php esc_html_e( 'No data available.', 'smart-redirect-pro' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Daily chart (7 days) -->
        <div class="srp-admin-section">
            <h2><?php esc_html_e( 'Clicks per day — Last 7 days', 'smart-redirect-pro' ); ?></h2>
            <?php if ( ! empty( $daily ) ) : ?>
                <div class="srp-chart">
                    <?php foreach ( $daily as $d ) : ?>
                        <div class="srp-chart-bar" style="height:<?php echo $maxClicks ? round( $d->clicks / $maxClicks * 100 ) : 0; ?>%">
                            <span class="srp-chart-value"><?php echo absint( $d->clicks ); ?></span>
                            <span class="srp-chart-label"><?php echo esc_html( date( 'd/m', strtotime( $d->day ) ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p style="color:#6b7280;"><?php esc_html_e( 'No data available.', 'smart-redirect-pro' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

/* --- Defaults --- */

function srp_settings_defaults() {
    return [
        'base_slug'           => 'go',
        'url_format'          => 'slug',
        'default_type'        => '302',
        'default_nofollow'    => '1',
        'default_sponsored'   => '0',
        'default_pass_params' => '0',
        'exclude_bots'        => '1',
        'exclude_admins'      => '1',
        'click_retention'     => '90',
        'custom_css'          => '',
    ];
}

/* --- Helper --- */

function srp_get_setting( $key ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( SRP_Settings::OPTION_KEY, [] );
    }
    $defaults = srp_settings_defaults();
    return isset( $settings[ $key ] ) && $settings[ $key ] !== '' ? $settings[ $key ] : ( $defaults[ $key ] ?? '' );
}
