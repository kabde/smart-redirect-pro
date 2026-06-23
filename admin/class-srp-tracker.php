<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRP_Tracker {

	const TABLE_NAME  = 'srp_clicks';
	const DB_VERSION  = '2.0';

	public function __construct() {
		self::maybe_migrate();
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
			ip_address VARCHAR(45) DEFAULT '',
			user_agent VARCHAR(255) DEFAULT '',
			referer VARCHAR(500) DEFAULT '',
			country VARCHAR(2) DEFAULT '',
			browser VARCHAR(100) DEFAULT '',
			os VARCHAR(100) DEFAULT '',
			device VARCHAR(20) DEFAULT 'desktop',
			is_bot TINYINT(1) DEFAULT 0,
			INDEX idx_redirect_id (redirect_id),
			INDEX idx_clicked_at (clicked_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'srp_db_version', self::DB_VERSION );
	}

	public static function maybe_migrate() {
		$current = get_option( 'srp_db_version', '1.0' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			self::create_table(); // dbDelta handles ALTER TABLE
			update_option( 'srp_db_version', self::DB_VERSION );
		}
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
function srp_parse_user_agent( $ua ) {
    $browser = 'Unknown';
    $os      = 'Unknown';
    $device  = 'desktop';

    // Browser detection
    if ( preg_match( '/Edg[e\/]/i', $ua ) )                          $browser = 'Edge';
    elseif ( preg_match( '/OPR\//i', $ua ) )                         $browser = 'Opera';
    elseif ( preg_match( '/Chrome\/[\d]/i', $ua ) && ! preg_match( '/Edg/i', $ua ) ) $browser = 'Chrome';
    elseif ( preg_match( '/Firefox\//i', $ua ) )                     $browser = 'Firefox';
    elseif ( preg_match( '/Safari\//i', $ua ) && ! preg_match( '/Chrome/i', $ua ) ) $browser = 'Safari';
    elseif ( preg_match( '/MSIE|Trident/i', $ua ) )                  $browser = 'IE';

    // OS detection
    if ( preg_match( '/Windows NT/i', $ua ) )                        $os = 'Windows';
    elseif ( preg_match( '/Macintosh|Mac OS/i', $ua ) )              $os = 'macOS';
    elseif ( preg_match( '/Android/i', $ua ) )                       { $os = 'Android'; $device = 'mobile'; }
    elseif ( preg_match( '/iPhone/i', $ua ) )                        { $os = 'iOS'; $device = 'mobile'; }
    elseif ( preg_match( '/iPad/i', $ua ) )                          { $os = 'iPadOS'; $device = 'tablet'; }
    elseif ( preg_match( '/Linux/i', $ua ) )                         $os = 'Linux';
    elseif ( preg_match( '/CrOS/i', $ua ) )                         $os = 'ChromeOS';

    return compact( 'browser', 'os', 'device' );
}

function srp_track_click( $post_id ) {
	global $wpdb;

	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

	// Detect bot
	$is_bot = preg_match( '/bot|crawl|spider|slurp|googlebot|bingbot|yandex|baidu|semrush|ahrefs|mj12bot|dotbot|petalbot|bytespider|gptbot|claudebot|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|discordbot|applebot/i', $ua ) ? 1 : 0;

	// Skip bots if setting enabled (but still track them if setting disabled)
	$exclude_bots = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'exclude_bots' ) : '0';
	if ( $exclude_bots === '1' && $is_bot ) return;

	// Skip admins
	$exclude_admins = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'exclude_admins' ) : '1';
	if ( $exclude_admins !== '0' && is_user_logged_in() && current_user_can( 'manage_options' ) ) return;

	// Get real IP (CF-Connecting-IP for Cloudflare, fallback to REMOTE_ADDR)
	$ip = '';
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	// Rate limit: same IP within 5 seconds
	$table  = $wpdb->prefix . SRP_Tracker::TABLE_NAME;
	$recent = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE redirect_id = %d AND ip_address = %s AND clicked_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)",
		$post_id, $ip
	) );
	if ( $recent ) return;

	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : '';

	// Country from Cloudflare header
	$country = '';
	if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
		if ( $country === 'XX' || $country === 'T1' ) $country = '';
	}

	// Parse user agent
	$parsed = srp_parse_user_agent( $ua );

	$wpdb->insert( $table, [
		'redirect_id' => absint( $post_id ),
		'clicked_at'  => current_time( 'mysql' ),
		'ip_address'  => $ip,
		'user_agent'  => $ua,
		'referer'     => $referer,
		'country'     => $country,
		'browser'     => $parsed['browser'],
		'os'          => $parsed['os'],
		'device'      => $parsed['device'],
		'is_bot'      => $is_bot,
	], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );

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
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
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

	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
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

	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) return [];

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

	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) return [];

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(clicked_at) as day, COUNT(*) as clicks
		 FROM $table
		 WHERE clicked_at > DATE_SUB(NOW(), INTERVAL %d DAY)
		 GROUP BY DATE(clicked_at)
		 ORDER BY day ASC",
		$days
	) );
}
