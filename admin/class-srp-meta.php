<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metaboxes for redirect post type
 */
class SRP_Meta {

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
		add_action( 'save_post_srp_redirect', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
	}

	/* --------- 1. Metabox --------- */
	public function add_boxes() {
		if ( ! current_user_can( SRP_CAPABILITY ) ) {
			return;
		}

		add_meta_box(
			'srp_redirect_details',
			__( 'Redirect Details', 'smart-redirect-pro' ),
			[ $this, 'render_box' ],
			'srp_redirect',
			'normal',
			'high'
		);
	}

	public function render_box( $post ) {
		$status       = get_post_meta( $post->ID, '_srp_status', true ) ?: 'active';
		$dest_url     = get_post_meta( $post->ID, '_srp_destination_url', true );
		$redirect_type = get_post_meta( $post->ID, '_srp_redirect_type', true ) ?: '302';
		$nofollow     = get_post_meta( $post->ID, '_srp_nofollow', true );
		$sponsored    = get_post_meta( $post->ID, '_srp_sponsored', true );
		$pass_params  = get_post_meta( $post->ID, '_srp_pass_params', true );
		$warnings     = $this->get_configuration_warnings( $post->ID );

		// Default nofollow to checked for new posts
		if ( $nofollow === '' && $post->post_status === 'auto-draft' ) {
			$nofollow = '1';
		}

		wp_nonce_field( 'srp_save_redirect', 'srp_redirect_nonce' );
		?>
		<?php if ( $post->post_status !== 'publish' ) : ?>
		    <div class="notice notice-info inline" style="margin:10px 0;">
		        <p><?php esc_html_e( 'This redirect will not work until it is published.', 'smart-redirect-pro' ); ?></p>
		    </div>
		<?php endif; ?>
		<?php if ( $warnings ) : ?>
			<div class="notice notice-warning inline srp-config-warning">
				<p><strong><?php esc_html_e( 'Configuration required', 'smart-redirect-pro' ); ?></strong></p>
				<ul>
					<?php foreach ( $warnings as $warning ) : ?>
						<li><?php echo esc_html( $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="srp-admin-layout">
			<div class="srp-admin-main">
				<section class="srp-admin-section">
					<h3><?php esc_html_e( 'Status', 'smart-redirect-pro' ); ?></h3>
					<div class="srp-field-grid">
						<label><input type="radio" name="srp_status" value="active" <?php checked( $status, 'active' ); ?>/> <?php esc_html_e( 'Active', 'smart-redirect-pro' ); ?></label>
						<label><input type="radio" name="srp_status" value="inactive" <?php checked( $status, 'inactive' ); ?>/> <?php esc_html_e( 'Inactive', 'smart-redirect-pro' ); ?></label>
					</div>
				</section>

				<section class="srp-admin-section">
					<h3><?php esc_html_e( 'Custom Slug', 'smart-redirect-pro' ); ?></h3>
					<?php
					$base_slug = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'base_slug' ) : 'go';
					if ( empty( $base_slug ) ) $base_slug = 'go';
					$base_url = home_url( '/' . $base_slug . '/' );
					?>
					<div style="display:flex;align-items:center;gap:6px;">
						<span style="color:#6b7280;font-size:13px;white-space:nowrap;"><?php echo esc_html( $base_url ); ?></span>
						<input type="text" name="srp_custom_slug" id="srp-custom-slug" value="<?php echo esc_attr( $post->post_name ); ?>" style="flex:1;font-size:14px;font-family:monospace;padding:6px 10px;" placeholder="my-link">
					</div>
					<p class="description" style="margin:8px 0 0;"><?php esc_html_e( 'Customize the slug to create a memorable short URL.', 'smart-redirect-pro' ); ?></p>
				</section>

				<section class="srp-admin-section">
					<h3><?php esc_html_e( 'Destination', 'smart-redirect-pro' ); ?></h3>
					<input type="text" name="srp_destination_url" style="width:100%" placeholder="https://..." value="<?php echo esc_attr( $dest_url ); ?>">
					<p><a href="#" id="srp-test-link" target="_blank" class="button button-small"><?php esc_html_e( 'Test link', 'smart-redirect-pro' ); ?> &#8599;</a></p>
				</section>

				<section class="srp-admin-section">
					<h3><?php esc_html_e( 'Settings', 'smart-redirect-pro' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Redirect type', 'smart-redirect-pro' ); ?></th>
							<td>
								<label><input type="radio" name="srp_redirect_type" value="301" <?php checked( $redirect_type, '301' ); ?>> <?php esc_html_e( '301 — Permanent', 'smart-redirect-pro' ); ?></label><br>
								<label><input type="radio" name="srp_redirect_type" value="302" <?php checked( $redirect_type, '302' ); ?>> <?php esc_html_e( '302 — Temporary', 'smart-redirect-pro' ); ?></label><br>
								<label><input type="radio" name="srp_redirect_type" value="307" <?php checked( $redirect_type, '307' ); ?>> <?php esc_html_e( '307 — Strict Temporary', 'smart-redirect-pro' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Link attributes', 'smart-redirect-pro' ); ?></th>
							<td>
								<label><input type="checkbox" name="srp_nofollow" value="1" <?php checked( $nofollow, '1' ); ?>> nofollow</label><br>
								<label><input type="checkbox" name="srp_sponsored" value="1" <?php checked( $sponsored, '1' ); ?>> sponsored</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Pass parameters', 'smart-redirect-pro' ); ?></th>
							<td>
								<label><input type="checkbox" name="srp_pass_params" value="1" <?php checked( $pass_params, '1' ); ?>> <?php esc_html_e( 'Forward query parameters to destination', 'smart-redirect-pro' ); ?></label>
								<p class="description"><?php
									/* translators: example of parameter forwarding */
									esc_html_e( 'E.g.: /go/link?ref=123 → destination.com?ref=123', 'smart-redirect-pro' );
								?></p>
							</td>
						</tr>
					</table>
				</section>
			</div>

			<aside class="srp-admin-preview">
				<div class="srp-admin-section">
					<h3><?php esc_html_e( 'Short URL', 'smart-redirect-pro' ); ?></h3>
					<code id="srp-short-url" style="display:block;padding:8px;background:#f6f7f7;border-radius:4px;word-break:break-all;font-size:13px;">
						<?php echo esc_url( srp_get_short_url( $post->ID ) ); ?>
					</code>
					<button type="button" class="button button-small srp-copy-btn" id="srp-copy-url-btn" data-url="<?php echo esc_url( srp_get_short_url( $post->ID ) ); ?>" style="margin-top:8px;"><?php esc_html_e( 'Copy URL', 'smart-redirect-pro' ); ?></button>
				</div>

				<div class="srp-admin-section">
					<h3><?php esc_html_e( 'QR Code', 'smart-redirect-pro' ); ?></h3>
					<?php $srp_qr_url = srp_get_short_url( $post->ID ); ?>
					<img id="srp-qr-img" src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo rawurlencode( $srp_qr_url ); ?>" alt="QR" style="width:100%;max-width:200px;border-radius:4px;">
					<p style="margin-top:8px;">
						<a id="srp-qr-download" href="https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&format=png&data=<?php echo rawurlencode( $srp_qr_url ); ?>" download="qr-<?php echo esc_attr( $post->post_name ); ?>.png" class="button button-small" style="width:100%;text-align:center;"><?php
							/* translators: HD QR code download button */
							esc_html_e( 'Download HD (1000x1000)', 'smart-redirect-pro' );
						?></a>
					</p>
				</div>

				<div class="srp-admin-section">
					<h3><?php esc_html_e( 'Shortcode', 'smart-redirect-pro' ); ?></h3>
					<code id="srp-shortcode-display" style="display:block;padding:8px;background:#f6f7f7;border-radius:4px;font-size:12px;">[srp_link slug="<?php echo esc_attr( $post->post_name ); ?>"]</code>
				</div>

				<div class="srp-admin-section">
					<h3><?php esc_html_e( 'Statistics', 'smart-redirect-pro' ); ?></h3>
					<p><strong id="srp-total-clicks"><?php echo function_exists( 'srp_get_click_count' ) ? absint( srp_get_click_count( $post->ID ) ) : 0; ?></strong> <?php esc_html_e( 'total clicks', 'smart-redirect-pro' ); ?></p>
					<p><strong><?php echo function_exists( 'srp_get_click_count' ) ? absint( srp_get_click_count( $post->ID, 7 ) ) : 0; ?></strong> <?php esc_html_e( 'clicks (last 7 days)', 'smart-redirect-pro' ); ?></p>
				</div>
			</aside>
		</div>
		<?php
	}

	private function get_configuration_warnings( $post_id ) {
		$status   = get_post_meta( $post_id, '_srp_status', true ) ?: 'active';
		$dest_url = get_post_meta( $post_id, '_srp_destination_url', true );
		$warnings = [];

		if ( 'active' !== $status ) {
			return [];
		}

		if ( empty( $dest_url ) ) {
			$warnings[] = __( 'An active redirect must have a destination URL.', 'smart-redirect-pro' );
		}

		return $warnings;
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'srp_redirect' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		if ( isset( $_GET['srp_duplicated'] ) && '1' === sanitize_key( wp_unslash( $_GET['srp_duplicated'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Redirect duplicated as draft.', 'smart-redirect-pro' ) . '</p></div>';
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$warnings = $this->get_configuration_warnings( $post_id );
		if ( ! $warnings ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Incomplete redirect configuration.', 'smart-redirect-pro' ) . '</strong></p><ul>';
		foreach ( $warnings as $warning ) {
			echo '<li>' . esc_html( $warning ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* --------- 2. Save --------- */
	public function save( $post_id ) {
		$nonce = isset( $_POST['srp_redirect_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['srp_redirect_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'srp_save_redirect' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( SRP_CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) ) return;

		$post_data = wp_unslash( $_POST );

		// Status
		$status = isset( $post_data['srp_status'] ) && in_array( $post_data['srp_status'], [ 'active', 'inactive' ], true ) ? $post_data['srp_status'] : 'active';
		update_post_meta( $post_id, '_srp_status', $status );

		// Destination URL
		$dest_url = isset( $post_data['srp_destination_url'] ) ? esc_url_raw( $post_data['srp_destination_url'] ) : '';
		update_post_meta( $post_id, '_srp_destination_url', $dest_url );

		// Redirect type
		$redirect_type = isset( $post_data['srp_redirect_type'] ) && in_array( $post_data['srp_redirect_type'], [ '301', '302', '307' ], true ) ? $post_data['srp_redirect_type'] : '302';
		update_post_meta( $post_id, '_srp_redirect_type', $redirect_type );

		// Nofollow
		$nofollow = ! empty( $post_data['srp_nofollow'] ) ? '1' : '0';
		update_post_meta( $post_id, '_srp_nofollow', $nofollow );

		// Sponsored
		$sponsored = ! empty( $post_data['srp_sponsored'] ) ? '1' : '0';
		update_post_meta( $post_id, '_srp_sponsored', $sponsored );

		// Pass params
		$pass_params = ! empty( $post_data['srp_pass_params'] ) ? '1' : '0';
		update_post_meta( $post_id, '_srp_pass_params', $pass_params );

		// Custom slug
		if ( isset( $post_data['srp_custom_slug'] ) ) {
			$new_slug = sanitize_title( trim( $post_data['srp_custom_slug'] ) );
			if ( ! empty( $new_slug ) && $new_slug !== get_post_field( 'post_name', $post_id ) ) {
				wp_update_post( [
					'ID'        => $post_id,
					'post_name' => wp_unique_post_slug( $new_slug, $post_id, get_post_status( $post_id ), 'srp_redirect', 0 ),
				] );
			}
		}
	}

	/* --------- 3. Assets --------- */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'srp_redirect' ) {
			return;
		}

		// JS on both listing and edit screens (copy button works everywhere)
		$script_path = SRP_PATH . 'admin/js/srp-admin.js';
		$script_url  = SRP_URL . 'admin/js/srp-admin.js';

		wp_enqueue_script(
			'srp-admin',
			$script_url,
			[ 'jquery', 'wp-i18n' ],
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : SRP_VERSION,
			true
		);

		wp_set_script_translations( 'srp-admin', 'smart-redirect-pro', SRP_PATH . 'languages' );

		if ( $screen->base === 'post' ) {
			wp_enqueue_media();
		}

		// CSS for list table badges
		$css_path = SRP_PATH . 'admin/css/srp-admin.css';
		$css_url  = SRP_URL . 'admin/css/srp-admin.css';

		wp_enqueue_style(
			'srp-admin-css',
			$css_url,
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : SRP_VERSION
		);

		wp_register_style( 'srp-admin', false, [], SRP_VERSION );
		wp_enqueue_style( 'srp-admin' );
		wp_add_inline_style( 'srp-admin', '
			.srp-admin-layout {
				display: grid;
				grid-template-columns: minmax(0, 1fr) 320px;
				gap: 20px;
				align-items: start;
			}
			.srp-admin-main {
				min-width: 0;
			}
			.srp-admin-section {
				border: 1px solid #dcdcde;
				background: #fff;
				border-radius: 4px;
				margin: 0 0 14px;
				padding: 16px;
			}
			.srp-admin-section h3 {
				margin: 0 0 12px;
				font-size: 14px;
				line-height: 1.4;
			}
			.srp-field-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 8px 14px;
			}
			.srp-config-warning ul,
			.notice ul {
				list-style: disc;
				margin-left: 20px;
			}
			.srp-admin-preview {
				position: sticky;
				top: 42px;
				border: 1px solid #dcdcde;
				background: #fff;
				border-radius: 4px;
				padding: 12px;
			}
			@media (max-width: 960px) {
				.srp-admin-layout {
					grid-template-columns: 1fr;
				}
				.srp-admin-preview {
					position: static;
				}
			}
		' );
	}
}
