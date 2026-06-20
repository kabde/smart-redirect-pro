<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRP_CPT {

	const POST_TYPE = 'srp_redirect';

	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_taxonomies' ] );
		add_filter( 'manage_srp_redirect_posts_columns', [ $this, 'add_custom_columns' ] );
		add_action( 'manage_srp_redirect_posts_custom_column', [ $this, 'fill_custom_columns' ], 10, 2 );
		add_filter( 'manage_edit-srp_redirect_sortable_columns', [ $this, 'make_columns_sortable' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_admin_filters' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_admin_filters' ] );
		add_filter( 'post_row_actions', [ $this, 'add_duplicate_action' ], 10, 2 );
		add_action( 'admin_action_srp_duplicate_redirect', [ $this, 'duplicate_redirect' ] );
		add_shortcode( 'srp_link', [ $this, 'shortcode_link' ] );
		add_shortcode( 'srp_url', [ $this, 'shortcode_url' ] );
	}

	public static function capabilities() {
		return [
			'edit_post'              => SRP_CAPABILITY,
			'read_post'              => SRP_CAPABILITY,
			'delete_post'            => SRP_CAPABILITY,
			'edit_posts'             => SRP_CAPABILITY,
			'edit_others_posts'      => SRP_CAPABILITY,
			'publish_posts'          => SRP_CAPABILITY,
			'read_private_posts'     => SRP_CAPABILITY,
			'delete_posts'           => SRP_CAPABILITY,
			'delete_private_posts'   => SRP_CAPABILITY,
			'delete_published_posts' => SRP_CAPABILITY,
			'delete_others_posts'    => SRP_CAPABILITY,
			'edit_private_posts'     => SRP_CAPABILITY,
			'edit_published_posts'   => SRP_CAPABILITY,
			'create_posts'           => SRP_CAPABILITY,
		];
	}

	public static function register() {
		$instance = new self();
		$instance->register_post_type();
		$instance->register_taxonomies();
	}

	/* --------- 1. CPT Registration --------- */
	public function register_post_type() {

		$labels = [
			'name'               => 'Redirections',
			'singular_name'      => 'Redirection',
			'menu_name'          => 'Redirections',
			'add_new'            => 'Ajouter une redirection',
			'add_new_item'       => 'Ajouter une nouvelle redirection',
			'edit_item'          => 'Modifier la redirection',
			'new_item'           => 'Nouvelle redirection',
			'view_item'          => 'Voir la redirection',
			'search_items'       => 'Rechercher des redirections',
			'not_found'          => 'Aucune redirection trouvée',
			'not_found_in_trash' => 'Aucune redirection trouvée dans la corbeille',
		];

		$base_slug = function_exists( 'srp_get_setting' ) ? srp_get_setting( 'base_slug' ) : 'go';
		if ( empty( $base_slug ) ) {
			$base_slug = 'go';
		}

		$args = [
			'labels'              => $labels,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'menu_position'       => 22,
			'menu_icon'           => 'dashicons-randomize',
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => true,
			'capability_type'     => [ 'srp_redirect', 'srp_redirects' ],
			'capabilities'        => self::capabilities(),
			'map_meta_cap'        => false,
			'supports'            => [ 'title' ],
			'rewrite'             => [ 'slug' => $base_slug, 'with_front' => false ],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/* --------- 2. Taxonomies --------- */
	public function register_taxonomies() {

		// Folders (hierarchical)
		register_taxonomy( 'srp_folder', self::POST_TYPE, [
			'labels' => [
				'name'              => 'Dossiers',
				'singular_name'     => 'Dossier',
				'search_items'      => 'Rechercher des dossiers',
				'all_items'         => 'Tous les dossiers',
				'parent_item'       => 'Dossier parent',
				'parent_item_colon' => 'Dossier parent :',
				'edit_item'         => 'Modifier le dossier',
				'update_item'       => 'Mettre à jour le dossier',
				'add_new_item'      => 'Ajouter un dossier',
				'new_item_name'     => 'Nom du nouveau dossier',
				'menu_name'         => 'Dossiers',
			],
			'hierarchical'      => true,
			'show_admin_column'  => true,
			'show_in_rest'       => false,
			'rewrite'            => false,
		] );

		// Tags (non-hierarchical)
		register_taxonomy( 'srp_tag', self::POST_TYPE, [
			'labels' => [
				'name'                       => 'Tags',
				'singular_name'              => 'Tag',
				'search_items'               => 'Rechercher des tags',
				'popular_items'              => 'Tags populaires',
				'all_items'                  => 'Tous les tags',
				'edit_item'                  => 'Modifier le tag',
				'update_item'                => 'Mettre à jour le tag',
				'add_new_item'               => 'Ajouter un tag',
				'new_item_name'              => 'Nom du nouveau tag',
				'separate_items_with_commas' => 'Séparer les tags par des virgules',
				'add_or_remove_items'        => 'Ajouter ou retirer des tags',
				'choose_from_most_used'      => 'Choisir parmi les plus utilisés',
				'menu_name'                  => 'Tags',
			],
			'hierarchical'      => false,
			'show_admin_column'  => true,
			'show_in_rest'       => false,
			'rewrite'            => false,
		] );
	}

	/* --------- 3. Custom Columns --------- */
	public function add_custom_columns( $columns ) {
		$new_columns = [];

		$new_columns['cb']            = $columns['cb'];
		$new_columns['title']         = $columns['title'];
		$new_columns['short_url']     = 'URL courte';
		$new_columns['destination']   = 'Destination';
		$new_columns['redirect_type'] = 'Type';
		$new_columns['clicks']        = 'Clics';

		// Keep taxonomy columns that WP auto-adds
		foreach ( $columns as $key => $label ) {
			if ( in_array( $key, [ 'taxonomy-srp_folder', 'taxonomy-srp_tag' ], true ) ) {
				$new_columns[ $key ] = $label;
			}
		}

		$new_columns['date'] = $columns['date'];

		return $new_columns;
	}

	public function fill_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'short_url':
				$url = get_permalink( $post_id );
				echo '<code style="font-size:12px;">' . esc_html( str_replace( [ 'https://', 'http://' ], '', $url ) ) . '</code>';
				echo ' <button class="button button-small srp-copy-btn" data-url="' . esc_url( $url ) . '">Copier</button>';
				break;

			case 'destination':
				$dest = get_post_meta( $post_id, '_srp_destination_url', true );
				if ( $dest ) {
					$display = mb_strlen( $dest ) > 50 ? mb_substr( $dest, 0, 50 ) . '...' : $dest;
					echo '<a href="' . esc_url( $dest ) . '" target="_blank" rel="noopener">' . esc_html( $display ) . '</a>';
				} else {
					echo '<span style="color:#dc3232;">Non définie</span>';
				}
				break;

			case 'redirect_type':
				$type = get_post_meta( $post_id, '_srp_redirect_type', true ) ?: '302';
				$classes = [
					'301' => 'srp-badge srp-badge-301',
					'302' => 'srp-badge srp-badge-302',
					'307' => 'srp-badge srp-badge-307',
				];
				$class = isset( $classes[ $type ] ) ? $classes[ $type ] : 'srp-badge srp-badge-302';
				echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $type ) . '</span>';
				break;

			case 'clicks':
				if ( function_exists( 'srp_get_click_count' ) ) {
					echo absint( srp_get_click_count( $post_id ) );
				} else {
					echo '0';
				}
				break;
		}
	}

	public function make_columns_sortable( $columns ) {
		$columns['clicks'] = 'clicks';
		return $columns;
	}

	/* --------- 4. Admin Filters --------- */
	public function render_admin_filters( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Folder filter
		$terms = get_terms( [
			'taxonomy'   => 'srp_folder',
			'hide_empty' => false,
		] );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$current = isset( $_GET['srp_filter_folder'] ) ? sanitize_key( wp_unslash( $_GET['srp_filter_folder'] ) ) : '';
			echo '<select name="srp_filter_folder">';
			echo '<option value="">Tous les dossiers</option>';
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $current, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</select>';
		}

		// Type filter
		$current_type = isset( $_GET['srp_filter_type'] ) ? sanitize_key( wp_unslash( $_GET['srp_filter_type'] ) ) : '';
		echo '<select name="srp_filter_type">';
		echo '<option value="">Tous les types</option>';
		echo '<option value="301" ' . selected( $current_type, '301', false ) . '>301</option>';
		echo '<option value="302" ' . selected( $current_type, '302', false ) . '>302</option>';
		echo '<option value="307" ' . selected( $current_type, '307', false ) . '>307</option>';
		echo '</select>';
	}

	public function apply_admin_filters( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );

		// Filter by redirect type
		$type = isset( $_GET['srp_filter_type'] ) ? sanitize_key( wp_unslash( $_GET['srp_filter_type'] ) ) : '';
		if ( $type && in_array( $type, [ '301', '302', '307' ], true ) ) {
			$meta_query[] = [
				'key'     => '_srp_redirect_type',
				'value'   => $type,
				'compare' => '=',
			];
		}

		// Filter by folder
		$folder = isset( $_GET['srp_filter_folder'] ) ? sanitize_key( wp_unslash( $_GET['srp_filter_folder'] ) ) : '';
		if ( $folder ) {
			$query->set( 'tax_query', [
				[
					'taxonomy' => 'srp_folder',
					'field'    => 'slug',
					'terms'    => $folder,
				],
			] );
		}

		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query );
		}

		// Sortable clicks column
		$orderby = $query->get( 'orderby' );
		if ( 'clicks' === $orderby ) {
			$query->set( 'meta_key', '_srp_click_count' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/* --------- 5. Duplication --------- */
	public function add_duplicate_action( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( SRP_CAPABILITY ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=srp_duplicate_redirect&post=' . absint( $post->ID ) ),
			'srp_duplicate_redirect_' . absint( $post->ID )
		);

		$actions['srp_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dupliquer', 'smart-redirect-pro' ) . '</a>';
		return $actions;
	}

	public function duplicate_redirect() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $post_id || ! current_user_can( SRP_CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( $nonce, 'srp_duplicate_redirect_' . $post_id ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'smart-redirect-pro' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Redirection introuvable.', 'smart-redirect-pro' ) );
		}

		$new_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => sprintf( '%s (copie)', $post->post_title ),
				'post_author' => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( '_' !== substr( $meta_key, 0, 1 ) || '_edit_lock' === $meta_key || '_edit_last' === $meta_key ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $meta_key, maybe_unserialize( $value ) );
			}
		}

		// Copy taxonomies
		$taxonomies = get_object_taxonomies( self::POST_TYPE );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . absint( $new_id ) . '&srp_duplicated=1' ) );
		exit;
	}

	/* --------- 6. Shortcodes --------- */
	public function shortcode_link( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0, 'slug' => '', 'text' => '', 'new_tab' => 'yes' ], $atts, 'srp_link' );

		$post = $atts['id'] ? get_post( absint( $atts['id'] ) ) : ( $atts['slug'] ? get_page_by_path( sanitize_title( $atts['slug'] ), OBJECT, 'srp_redirect' ) : null );
		if ( ! $post || $post->post_type !== 'srp_redirect' ) return '';

		$url  = get_permalink( $post->ID );
		$text = $atts['text'] ? $atts['text'] : esc_html( $post->post_title );
		$rel_parts = [];
		if ( get_post_meta( $post->ID, '_srp_nofollow', true ) ) $rel_parts[] = 'nofollow';
		if ( get_post_meta( $post->ID, '_srp_sponsored', true ) ) $rel_parts[] = 'sponsored';
		$rel_parts[] = 'noopener';
		$rel    = implode( ' ', $rel_parts );
		$target = ( $atts['new_tab'] === 'yes' ) ? ' target="_blank"' : '';

		return '<a href="' . esc_url( $url ) . '" rel="' . esc_attr( $rel ) . '"' . $target . '>' . $text . '</a>';
	}

	public function shortcode_url( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0, 'slug' => '' ], $atts, 'srp_url' );
		$post = $atts['id'] ? get_post( absint( $atts['id'] ) ) : ( $atts['slug'] ? get_page_by_path( sanitize_title( $atts['slug'] ), OBJECT, 'srp_redirect' ) : null );
		if ( ! $post || $post->post_type !== 'srp_redirect' ) return '';
		return esc_url( get_permalink( $post->ID ) );
	}
}
