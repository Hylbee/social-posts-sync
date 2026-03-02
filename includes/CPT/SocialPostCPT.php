<?php
/**
 * Custom Post Type: social_post
 *
 * Registers the social_post CPT and all associated meta fields.
 *
 * @package SocialPostsSync\CPT
 */

declare(strict_types=1);

namespace SocialPostsSync\CPT;

defined('ABSPATH') || exit;

/**
 * Handles registration of the social_post Custom Post Type and its meta fields.
 */
class SocialPostCPT {

    /**
     * The CPT slug.
     */
    public const POST_TYPE = 'social_post';

    /**
     * Meta field keys.
     */
    public const META_PLATFORM      = '_scps_platform';
    public const META_SOURCE_ID     = '_scps_source_id';
    public const META_CONTENT       = '_scps_content';
    public const META_PERMALINK     = '_scps_permalink';
    public const META_PUBLISHED_AT  = '_scps_published_at';
    public const META_MEDIA_URLS    = '_scps_media_urls';
    public const META_MEDIA_IDS     = '_scps_media_ids';
    public const META_AUTHOR_NAME   = '_scps_author_name';
    public const META_AUTHOR_AVATAR = '_scps_author_avatar';
    public const META_LIKES_COUNT   = '_scps_likes_count';
    public const META_RAW_DATA      = '_scps_raw_data';
    public const META_GALLERY_IDS   = '_scps_gallery_ids';
    public const META_VIDEO_URL     = '_scps_video_url';

    /**
     * Register the CPT, taxonomy, meta fields, and admin metaboxes.
     */
    public function register(): void {
        $this->register_post_type();
        $this->register_taxonomy();
        $this->register_meta_fields();
        add_action('add_meta_boxes', fn() => (new \SocialPostsSync\Admin\Metaboxes\SocialInfoMetabox())->register());
        add_action('add_meta_boxes', fn() => (new \SocialPostsSync\Admin\Metaboxes\GalleryMetabox())->register());
        // Block direct term creation/editing by non-super-admins
        add_filter('map_meta_cap', [$this, 'restrict_platform_term_caps'], 10, 4);
        // Admin list columns
        add_filter('manage_' . self::POST_TYPE . '_posts_columns',       [$this, 'add_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'sortable_admin_columns']);
        add_action('pre_get_posts', [$this, 'sort_admin_columns_query']);
    }

    /**
     * Register the scps_platform taxonomy.
     */
    private function register_taxonomy(): void {
        $labels = [
            'name'              => _x('Plateformes sociales', 'taxonomy general name', 'social-posts-sync'),
            'singular_name'     => _x('Plateforme sociale', 'taxonomy singular name', 'social-posts-sync'),
            'search_items'      => __('Rechercher une plateforme', 'social-posts-sync'),
            'all_items'         => __('Toutes les plateformes', 'social-posts-sync'),
            'edit_item'         => __('Modifier la plateforme', 'social-posts-sync'),
            'update_item'       => __('Mettre à jour', 'social-posts-sync'),
            'add_new_item'      => __('Ajouter une plateforme', 'social-posts-sync'),
            'new_item_name'     => __('Nom de la plateforme', 'social-posts-sync'),
            'menu_name'         => __('Plateformes', 'social-posts-sync'),
        ];

        register_taxonomy('scps_platform', self::POST_TYPE, [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => false,  // Hide edit UI — managed programmatically only
            'show_admin_column' => true,   // Still visible as a column in post list
            'show_in_rest'      => false,  // Not exposed in REST / block editor
            'rewrite'           => ['slug' => 'social-platform'],
            'capabilities'      => [
                'manage_terms' => 'do_not_allow',
                'edit_terms'   => 'do_not_allow',
                'delete_terms' => 'do_not_allow',
                'assign_terms' => 'edit_posts',
            ],
        ]);

        // Ensure default terms exist
        foreach (['Facebook', 'Instagram'] as $term) {
            if (!term_exists($term, 'scps_platform')) {
                wp_insert_term($term, 'scps_platform');
            }
        }
    }

    /**
     * Register the social_post post type.
     */
    private function register_post_type(): void {
        $labels = [
            'name'                  => _x('Publications sociales', 'post type general name', 'social-posts-sync'),
            'singular_name'         => _x('Publication sociale', 'post type singular name', 'social-posts-sync'),
            'menu_name'             => _x('Publications sociales', 'admin menu', 'social-posts-sync'),
            'name_admin_bar'        => _x('Publication sociale', 'add new on toolbar', 'social-posts-sync'),
            'add_new'               => _x('Ajouter', 'social post', 'social-posts-sync'),
            'add_new_item'          => __('Ajouter une publication', 'social-posts-sync'),
            'new_item'              => __('Nouvelle publication', 'social-posts-sync'),
            'edit_item'             => __('Modifier la publication', 'social-posts-sync'),
            'view_item'             => __('Voir la publication', 'social-posts-sync'),
            'all_items'             => __('Toutes les publications', 'social-posts-sync'),
            'search_items'          => __('Rechercher des publications', 'social-posts-sync'),
            'parent_item_colon'     => __('Publications parentes :', 'social-posts-sync'),
            'not_found'             => __('Aucune publication trouvée.', 'social-posts-sync'),
            'not_found_in_trash'    => __('Aucune publication dans la corbeille.', 'social-posts-sync'),
            'featured_image'        => __('Image à la une', 'social-posts-sync'),
            'set_featured_image'    => __('Définir l\'image à la une', 'social-posts-sync'),
            'remove_featured_image' => __('Supprimer l\'image à la une', 'social-posts-sync'),
            'use_featured_image'    => __('Utiliser comme image à la une', 'social-posts-sync'),
            'archives'              => __('Archives des publications', 'social-posts-sync'),
            'insert_into_item'      => __('Insérer dans la publication', 'social-posts-sync'),
            'uploaded_to_this_item' => __('Téléversé dans cette publication', 'social-posts-sync'),
            'items_list'            => __('Liste des publications', 'social-posts-sync'),
            'items_list_navigation' => __('Navigation de la liste', 'social-posts-sync'),
            'filter_items_list'     => __('Filtrer la liste', 'social-posts-sync'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => ['slug' => (string) get_option('scps_cpt_slug', 'social-posts')],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-share',
            'supports'           => ['title', 'editor', 'thumbnail'],
            'show_in_rest'       => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    // -------------------------------------------------------------------------
    // Admin List Columns
    // -------------------------------------------------------------------------

    /**
     * Add custom columns to the social_post list table.
     *
     * @param array $columns Existing columns.
     *
     * @return array Modified columns.
     */
    public function add_admin_columns(array $columns): array {
        // Insert after 'title': thumbnail, then likes
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['scps_likes'] = esc_html__('Likes', 'social-posts-sync');
            }
        }
        return $new;
    }

    /**
     * Render custom column cell content.
     *
     * @param string $column  Column key.
     * @param int    $post_id Post ID.
     */
    public function render_admin_column(string $column, int $post_id): void {
        if ($column !== 'scps_likes') {
            return;
        }

        $likes = (int) get_post_meta($post_id, self::META_LIKES_COUNT, true);
        echo '<span style="font-weight:600;">' . esc_html(number_format_i18n($likes)) . '</span>';
    }

    /**
     * Declare the likes column as sortable.
     *
     * @param array $columns Sortable columns.
     *
     * @return array Modified sortable columns.
     */
    public function sortable_admin_columns(array $columns): array {
        $columns['scps_likes'] = 'scps_likes';
        return $columns;
    }

    /**
     * Handle sorting by likes in admin queries.
     *
     * @param \WP_Query $query Current query.
     */
    public function sort_admin_columns_query(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        if ($query->get('orderby') === 'scps_likes') {
            $query->set('meta_key', self::META_LIKES_COUNT);
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Prevent anyone from managing scps_platform terms through normal WordPress capabilities.
     * Terms are only assigned programmatically by the sync process.
     *
     * @param array  $caps    Primitive capabilities required.
     * @param string $cap     Meta capability being checked.
     * @param int    $user_id User ID.
     * @param array  $args    Additional arguments.
     *
     * @return array Modified capabilities array.
     */
    public function restrict_platform_term_caps(array $caps, string $cap, int $user_id, array $args): array {
        $restricted = ['manage_scps_platform', 'edit_scps_platform', 'delete_scps_platform'];
        if (in_array($cap, $restricted, true)) {
            return ['do_not_allow'];
        }
        return $caps;
    }

    /**
     * Register all post meta fields for the social_post CPT.
     */
    private function register_meta_fields(): void {
        // Fields exposed to REST (readable by Elementor dynamic tags)
        $rest_fields = [
            self::META_PLATFORM,
            self::META_PERMALINK,
            self::META_PUBLISHED_AT,
            self::META_AUTHOR_NAME,
            self::META_AUTHOR_AVATAR,
        ];

        foreach ($rest_fields as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'type'              => 'string',
                'description'       => $key,
                'single'            => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => fn() => current_user_can('edit_posts'),
                'show_in_rest'      => true,
            ]);
        }

        // Internal fields not exposed to REST
        $private_fields = [
            self::META_SOURCE_ID,
            self::META_CONTENT,
            self::META_MEDIA_URLS,
            self::META_MEDIA_IDS,
            self::META_RAW_DATA,
            self::META_GALLERY_IDS,
            self::META_VIDEO_URL,
        ];

        foreach ($private_fields as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'type'              => 'string',
                'description'       => $key,
                'single'            => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => fn() => current_user_can('edit_posts'),
                'show_in_rest'      => false,
            ]);
        }

        register_post_meta(self::POST_TYPE, self::META_LIKES_COUNT, [
            'type'              => 'integer',
            'description'       => 'Likes count from the social platform.',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'auth_callback'     => fn() => current_user_can('edit_posts'),
            'show_in_rest'      => false,
        ]);
    }
}
