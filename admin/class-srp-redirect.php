<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRP_Redirect {

	public function __construct() {
		// Two hooks: parse_request (early, manual URL parsing) + template_redirect (fallback via is_singular)
		add_action( 'parse_request', [ $this, 'maybe_redirect' ], 0 );
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], -1 );
	}

	public function maybe_redirect() {
		// Only on frontend requests
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$post_id = $this->get_redirect_post_id();
if ( ! $post_id ) return;

		$status = get_post_meta( $post_id, '_srp_status', true ) ?: 'active';
		if ( $status !== 'active' ) return;

		$url = get_post_meta( $post_id, '_srp_destination_url', true );
		if ( ! $url || ! wp_http_validate_url( $url ) ) return;

		// Prevent infinite redirect loop
		$short_url = function_exists('srp_get_short_url') ? srp_get_short_url($post_id) : '';
		if ( $short_url && $url === $short_url ) return;

		// Pass-through query params
		if ( get_post_meta( $post_id, '_srp_pass_params', true ) ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
			if ( $query ) {
				$separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
				$url .= $separator . $query;
			}
		}

		// Track click
		if ( function_exists( 'srp_track_click' ) ) {
			srp_track_click( $post_id );
		}

		// Nofollow header
		if ( get_post_meta( $post_id, '_srp_nofollow', true ) ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		$type = absint( get_post_meta( $post_id, '_srp_redirect_type', true ) ?: 302 );
		if ( ! in_array( $type, [ 301, 302, 307 ], true ) ) $type = 302;

		wp_redirect( esc_url_raw( $url ), $type );
		exit;
	}

	private function get_redirect_post_id() {
		$base = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'base_slug' ) : 'go';
		if ( empty( $base ) ) $base = 'go';

		$format = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'url_format' ) : 'slug';
		if ( empty( $format ) ) $format = 'slug';

		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$path = trim( (string) $path, '/' );

		// Remove home path prefix (for WP in subdirectory)
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}

		$parts = array_values( array_filter( explode( '/', $path ) ) );

		// Must start with base slug
if ( empty( $parts ) || $parts[0] !== $base ) return 0;

		// Try ID format first: /base/?p=ID (always works as fallback)
		$id = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0;
		if ( $id ) {
			$post = get_post( $id );
			if ( $post && $post->post_type === 'srp_redirect' && $post->post_status === 'publish' ) {
				return $post->ID;
			}
		}

		// Try slug format: /base/slug
		if ( count( $parts ) === 2 ) {
			$slug = sanitize_title( rawurldecode( $parts[1] ) );
			$post = get_page_by_path( $slug, OBJECT, 'srp_redirect' );
if ( $post && $post->post_status === 'publish' ) {
				return $post->ID;
			}
		}

		// Fallback: WP resolved the CPT via rewrite rules
		if ( is_singular( 'srp_redirect' ) ) {
			return get_queried_object_id();
		}

		return 0;
	}
}
