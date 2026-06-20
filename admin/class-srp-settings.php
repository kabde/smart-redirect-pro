<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SRP_Settings {

    const OPTION_KEY = 'srp_settings';

    /** @var string Settings page hook suffix */
    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* --- Menu --- */

    public function add_menu() {
        if ( srp_is_licensed() ) {
            $this->hook = add_submenu_page(
                'edit.php?post_type=srp_redirect',
                'Settings',
                'Settings',
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
            wp_die( 'You do not have sufficient permissions.' );
        }

        $licensed      = srp_is_licensed();
        $license_key   = get_option( 'srp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = srp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );
        $tabs = [
            'license'    => [ 'label' => 'Licence',      'icon' => 'dashicons-lock' ],
            'general'    => [ 'label' => 'Général',     'icon' => 'dashicons-admin-settings' ],
            'stats'      => [ 'label' => 'Statistiques', 'icon' => 'dashicons-chart-bar' ],
            'advanced'   => [ 'label' => 'Avancé',     'icon' => 'dashicons-admin-generic' ],
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
        .srp-sidebar-item.is-active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #6366f1; }
        .srp-sidebar-item .dashicons { font-size: 16px; width: 16px; height: 16px; opacity: 0.65; }
        .srp-sidebar-item.is-active .dashicons { opacity: 1; color: #6366f1; }

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
            background: linear-gradient(to top, #6366f1, #818cf8);
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
            .srp-sidebar-item.is-active { border-left: none; border-bottom-color: #6366f1; }
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
                            <h2>Licence</h2>
                            <div class="srp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="srp-license-active">&#10003; Licence Active</span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th>Cl&eacute; de licence</th>
                                            <td><code style="font-size:14px;"><?php echo esc_html( $license_key ); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th>Domaine</th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="srp-deactivate-btn" class="button button-secondary" style="color:#d63638;">D&eacute;sactiver la licence</button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;">Activez votre licence</h2>
                                    <p>Entrez votre cl&eacute; de licence pour activer Smart Redirect Pro.</p>
                                    <p>
                                        <input type="text" id="srp-license-key" placeholder="SRP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="srp-activate-btn" class="button button-primary button-hero" style="width:100%;">Activer la licence</button>
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
                                <h2>Paramètres généraux</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Slug de base</th>
                                        <td>
                                            <input type="text" name="srp_settings[base_slug]" value="<?php echo esc_attr( $s['base_slug'] ); ?>" class="regular-text" placeholder="go">
                                            <p class="description">Préfixe de l'URL courte. Ex: <code><?php echo esc_html( home_url() ); ?>/<strong><?php echo esc_html( $s['base_slug'] ); ?></strong>/mon-lien</code></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Format d'URL</th>
                                        <td>
                                            <select name="srp_settings[url_format]">
                                                <option value="slug" <?php selected( $s['url_format'], 'slug' ); ?>>/go/mon-lien (slug)</option>
                                                <option value="id" <?php selected( $s['url_format'], 'id' ); ?>>/go/?p=123 (ID)</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Type de redirection par défaut</th>
                                        <td>
                                            <select name="srp_settings[default_type]">
                                                <option value="301" <?php selected( $s['default_type'], '301' ); ?>>301 — Permanent</option>
                                                <option value="302" <?php selected( $s['default_type'], '302' ); ?>>302 — Temporaire</option>
                                                <option value="307" <?php selected( $s['default_type'], '307' ); ?>>307 — Temporaire strict</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Attributs par défaut</th>
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
                                                Transmettre les query parameters
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Stats Tab (read-only, no form fields) -->
                        <div id="srp-tab-stats" class="srp-tab-content">
                            <?php $this->render_stats_tab(); ?>
                        </div>

                        <!-- Advanced Tab -->
                        <div id="srp-tab-advanced" class="srp-tab-content">
                            <div class="srp-admin-section">
                                <h2>Tracking</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Exclure les bots</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="srp_settings[exclude_bots]" value="1" <?php checked( $s['exclude_bots'], '1' ); ?>>
                                                Ne pas compter les clics des robots/crawlers
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Exclure les admins</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="srp_settings[exclude_admins]" value="1" <?php checked( $s['exclude_admins'], '1' ); ?>>
                                                Ne pas compter les clics des administrateurs
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Rétention des clics</th>
                                        <td>
                                            <select name="srp_settings[click_retention]">
                                                <option value="30" <?php selected( $s['click_retention'], '30' ); ?>>30 jours</option>
                                                <option value="60" <?php selected( $s['click_retention'], '60' ); ?>>60 jours</option>
                                                <option value="90" <?php selected( $s['click_retention'], '90' ); ?>>90 jours</option>
                                                <option value="180" <?php selected( $s['click_retention'], '180' ); ?>>180 jours</option>
                                                <option value="365" <?php selected( $s['click_retention'], '365' ); ?>>1 an</option>
                                                <option value="unlimited" <?php selected( $s['click_retention'], 'unlimited' ); ?>>Illimité</option>
                                            </select>
                                            <p class="description">Durée de conservation des données de clics. Les données plus anciennes sont supprimées automatiquement.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="srp-admin-section">
                                <h2>CSS personnalisé</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Custom CSS</th>
                                        <td>
                                            <textarea id="srp_custom_css" name="srp_settings[custom_css]" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
                                            <p class="description">CSS personnalisé appliqué sur le front-end.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                    </form>
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

            $('#srp-activate-btn').on('click', function() {
                var btn = $(this);
                var key = $('#srp-license-key').val().trim();
                if (!key) return;

                btn.prop('disabled', true).text('Activation...');

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
                        btn.prop('disabled', false).text('Activer la licence');
                    }
                }).fail(function() {
                    $('#srp-license-message').html('<div class="notice notice-error inline"><p>Erreur de connexion.</p></div>').show();
                    btn.prop('disabled', false).text('Activer la licence');
                });
            });

            $('#srp-deactivate-btn').on('click', function() {
                if (!confirm('Désactiver la licence sur ce domaine ?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('Désactivation...');

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
                <strong style="font-size:2rem;color:#6366f1;"><?php echo absint( $stats['redirects'] ); ?></strong>
                <p>Redirections</p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#6366f1;"><?php echo absint( $stats['week'] ); ?></strong>
                <p>Clics (7 jours)</p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#6366f1;"><?php echo absint( $stats['month'] ); ?></strong>
                <p>Clics (30 jours)</p>
            </div>
            <div class="srp-admin-section" style="text-align:center;">
                <strong style="font-size:2rem;color:#6366f1;"><?php echo absint( $stats['total'] ); ?></strong>
                <p>Clics total</p>
            </div>
        </div>

        <!-- Top 10 -->
        <div class="srp-admin-section">
            <h2>Top 10 — 30 derniers jours</h2>
            <?php if ( ! empty( $top ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Redirection</th>
                            <th style="text-align:right;">Clics</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top as $i => $row ) : ?>
                            <tr>
                                <td><?php echo absint( $i + 1 ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->ID ) . '&action=edit' ) ); ?>">
                                        <?php echo esc_html( $row->post_title ?: '(sans titre)' ); ?>
                                    </a>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?php echo absint( $row->clicks ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#6b7280;">Aucune donnée disponible.</p>
            <?php endif; ?>
        </div>

        <!-- Daily chart (7 days) -->
        <div class="srp-admin-section">
            <h2>Clics par jour — 7 derniers jours</h2>
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
                <p style="color:#6b7280;">Aucune donnée disponible.</p>
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
