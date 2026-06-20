<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRP_Tracker {

	const TABLE_NAME  = 'srp_clicks';
	const DB_VERSION  = '1.0';

	public function __construct() {
		add_action( 'srp_cleanup_clicks_cron', [ $this, 'cleanup_old_clicks' ] );
		// Schedule weekly cleanup
		if ( ! wp_next_scheduled( 'srp_cleanup_clicks_cron' ) ) {
			wp_schedule_event( time(), 'weekly', 'srp_cleanup_clicks_cron' );
		}
	}

	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			redirect_id BIGINT UNSIGNED NOT NULL,
			clicked_at DATETIME NOT NULL,
			ip_hash VARCHAR(64) DEFAULT '',
			user_agent VARCHAR(255) DEFAULT '',
			referer VARCHAR(500) DEFAULT '',
			INDEX idx_redirect_id (redirect_id),
			INDEX idx_clicked_at (clicked_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'srp_db_version', self::DB_VERSION );
	}

	public function cleanup_old_clicks() {
		global $wpdb;
		$retention = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'click_retention' ) : '90';
		if ( empty( $retention ) ) {
			$retention = '90';
		}
		if ( $retention === 'unlimited' ) return;
		$days = absint( $retention );
		if ( $days < 1 ) return;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE clicked_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}
}

// Standalone functions (used by redirect and meta)
function srp_track_click( $post_id ) {
	global $wpdb;

	// Skip bots
	$exclude_bots = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'exclude_bots' ) : '1';
	if ( $exclude_bots !== '0' ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/bot|crawl|spider|slurp|googlebot|bingbot|yandex|baidu/i', $ua ) ) return;
	}

	// Skip admins
	$exclude_admins = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'exclude_admins' ) : '1';
	if ( $exclude_admins !== '0' && is_user_logged_in() && current_user_can( 'manage_options' ) ) return;

	// Rate limit: same IP hash within 5 seconds
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$salt    = wp_salt( 'auth' );
	$ip_hash = hash( 'sha256', $ip . $salt );

	$table  = $wpdb->prefix . SRP_Tracker::TABLE_NAME;
	$recent = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE redirect_id = %d AND ip_hash = %s AND clicked_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)",
		$post_id, $ip_hash
	) );
	if ( $recent ) return;

	$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : '';

	$wpdb->insert( $table, [
		'redirect_id' => absint( $post_id ),
		'clicked_at'  => current_time( 'mysql' ),
		'ip_hash'     => $ip_hash,
		'user_agent'  => $ua,
		'referer'     => $referer,
	], [ '%d', '%s', '%s', '%s', '%s' ] );

	// Update cached count on post meta for fast column display
	$total = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE redirect_id = %d",
		$post_id
	) );
	update_post_meta( $post_id, '_srp_click_count', absint( $total ) );
}

function srp_get_click_count( $post_id, $days = 0 ) {
	global $wpdb;
	$table = $wpdb->prefix . SRP_Tracker::TABLE_NAME;

	// Check if table exists
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		return 0;
	}

	if ( $days > 0 ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE redirect_id = %d AND clicked_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
			$post_id, $days
		) );
	}

	// Use cached meta for total (faster)
	$cached = get_post_meta( $post_id, '_srp_click_count', true );
	if ( $cached !== '' ) return absint( $cached );

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE redirect_id = %d",
		$post_id
	) );
}

// Stats functions for settings page
function srp_get_stats_overview() {
	global $wpdb;
	$table = $wpdb->prefix . SRP_Tracker::TABLE_NAME;

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		return [ 'total' => 0, 'week' => 0, 'month' => 0, 'redirects' => 0 ];
	}

	return [
		'redirects' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'srp_redirect' AND post_status = 'publish'" ),
		'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
		'week'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE clicked_at > DATE_SUB(NOW(), INTERVAL 7 DAY)" ),
		'month'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE clicked_at > DATE_SUB(NOW(), INTERVAL 30 DAY)" ),
	];
}

function srp_get_top_redirects( $limit = 10, $days = 30 ) {
	global $wpdb;
	$table = $wpdb->prefix . SRP_Tracker::TABLE_NAME;

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return [];

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, COUNT(c.id) as clicks
		 FROM $table c
		 JOIN {$wpdb->posts} p ON c.redirect_id = p.ID
		 WHERE c.clicked_at > DATE_SUB(NOW(), INTERVAL %d DAY)
		 GROUP BY p.ID
		 ORDER BY clicks DESC
		 LIMIT %d",
		$days, $limit
	) );
}

function srp_get_daily_clicks( $days = 7 ) {
	global $wpdb;
	$table = $wpdb->prefix . SRP_Tracker::TABLE_NAME;

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return [];

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(clicked_at) as day, COUNT(*) as clicks
		 FROM $table
		 WHERE clicked_at > DATE_SUB(NOW(), INTERVAL %d DAY)
		 GROUP BY DATE(clicked_at)
		 ORDER BY day ASC",
		$days
	) );
}
