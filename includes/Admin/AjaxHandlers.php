<?php
/**
 * AJAX Handlers
 *
 * Registers and handles all wp_ajax_scps_* actions for the admin UI.
 * Extracted from SettingsPage to keep each class focused on a single concern.
 *
 * @package SocialPostsSync\Admin
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;
use SocialPostsSync\Api\MetaApiClient;
use SocialPostsSync\Api\FacebookFeed;
use SocialPostsSync\Api\InstagramFeed;
use SocialPostsSync\Sync\SyncRunner;

class AjaxHandlers {

    private MetaOAuth $oauth;

    public function __construct(MetaOAuth $oauth) {
        $this->oauth = $oauth;
    }

    /**
     * Register all AJAX action hooks.
     */
    public function init(): void {
        add_action('wp_ajax_scps_sync_now',        [$this, 'handleSyncNow']);
        add_action('wp_ajax_scps_sync_status',     [$this, 'handleSyncStatus']);
        add_action('wp_ajax_scps_unlock_sync',     [$this, 'handleUnlockSync']);
        add_action('wp_ajax_scps_load_sources',    [$this, 'handleLoadSources']);
        add_action('wp_ajax_scps_validate_source', [$this, 'handleValidateSource']);
        add_action('wp_ajax_scps_reset_sync',      [$this, 'handleResetSync']);
        add_action('wp_ajax_scps_purge_all',       [$this, 'handlePurgeAll']);
    }

    // -------------------------------------------------------------------------
    // Sync
    // -------------------------------------------------------------------------

    /**
     * AJAX: Trigger an immediate sync for all enabled sources.
     */
    public function handleSyncNow(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        if (!scps_acquire_sync_lock()) {
            wp_send_json_error([
                'message' => __('Une synchronisation est déjà en cours. Veuillez patienter.', 'social-posts-sync'),
                'locked'  => true,
            ]);
            return;
        }

        try {
            $token = $this->oauth->getAccessToken();
            if (!$token) {
                throw new \RuntimeException(esc_html__('Aucun token d\'accès disponible. Reconnectez-vous à Meta.', 'social-posts-sync'));
            }

            $client = new MetaApiClient($token);
            (new SyncRunner())->run($client);
        } catch (\Throwable $e) {
            scps_release_sync_lock();
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        scps_release_sync_lock();

        $log  = get_option('scps_sync_log', []);
        $last = !empty($log) ? $log[0] : [];

        wp_send_json_success([
            'message' => __('Synchronisation terminée.', 'social-posts-sync'),
            'log'     => $last,
        ]);
    }

    /**
     * AJAX: Return current sync lock status.
     */
    public function handleSyncStatus(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
            return;
        }

        wp_send_json_success(['running' => scps_is_sync_running()]);
    }

    /**
     * AJAX: Force-release a stuck sync lock.
     */
    public function handleUnlockSync(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
            return;
        }

        scps_release_sync_lock();
        wp_send_json_success(['message' => __('Verrou libéré.', 'social-posts-sync')]);
    }

    /**
     * AJAX: Reset sync timestamps so the next sync re-fetches all posts.
     */
    public function handleResetSync(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

        foreach (scps_extract_source_ids($enabled_sources, 'facebook') as $page_id) {
            delete_option("scps_last_sync_{$page_id}");
        }
        foreach (scps_extract_source_ids($enabled_sources, 'instagram') as $ig_id) {
            delete_option("scps_last_sync_{$ig_id}");
        }

        wp_send_json_success(['message' => __('Timestamps réinitialisés. La prochaine sync récupèrera tous les posts.', 'social-posts-sync')]);
    }

    // -------------------------------------------------------------------------
    // Sources
    // -------------------------------------------------------------------------

    /**
     * AJAX: Load Facebook Pages and Instagram accounts for the Sources tab.
     */
    public function handleLoadSources(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        $token = $this->oauth->getAccessToken();
        if (!$token) {
            wp_send_json_error(['message' => __('Non connecté à Meta.', 'social-posts-sync')]);
            return;
        }

        try {
            $client   = new MetaApiClient($token);
            $fb_feed  = new FacebookFeed($client);
            $ig_feed  = new InstagramFeed($client);

            wp_send_json_success([
                'pages'    => $fb_feed->getPages(),
                'accounts' => $ig_feed->getAccounts(),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Validate a Facebook Page ID or Instagram username before adding it as a source.
     *
     * Detects the platform automatically:
     *  - Identifier starting with '@' or containing only letters/digits/underscores → Instagram
     *  - Purely numeric identifier → Facebook
     *
     * Returns: { platform, id, name, avatar [, username] }
     */
    public function handleValidateSource(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        $token = $this->oauth->getAccessToken();
        if (!$token) {
            wp_send_json_error(['message' => __('Non connecté à Meta.', 'social-posts-sync')]);
            return;
        }

        $identifier = sanitize_text_field(wp_unslash($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            wp_send_json_error(['message' => __('Identifiant vide.', 'social-posts-sync')]);
            return;
        }

        // Auto-detect platform: @ or non-numeric → Instagram, numeric → Facebook
        $clean    = ltrim($identifier, '@');
        $platform = ctype_digit($clean) ? 'facebook' : 'instagram';

        if ($platform === 'facebook' && !preg_match('/^\d{1,20}$/', $clean)) {
            wp_send_json_error(['message' => __('ID Facebook invalide (doit être numérique, max 20 chiffres).', 'social-posts-sync')]);
            return;
        }
        if ($platform === 'instagram' && !preg_match('/^[a-zA-Z0-9_.]{1,30}$/', $clean)) {
            wp_send_json_error(['message' => __('Nom d\'utilisateur Instagram invalide (lettres, chiffres, . et _ uniquement, max 30 caractères).', 'social-posts-sync')]);
            return;
        }

        try {
            $client = new MetaApiClient($token);

            if ($platform === 'facebook') {
                $info = (new FacebookFeed($client))->fetchPublicPageInfo($clean);

                wp_send_json_success([
                    'platform' => 'facebook',
                    'id'       => $info['id'],
                    'name'     => $info['name'],
                    'avatar'   => $info['avatar'],
                ]);
            } else {
                $info = (new InstagramFeed($client))->fetchDiscoveryAccountInfo($clean);

                wp_send_json_success([
                    'platform' => 'instagram',
                    'id'       => $info['id'],
                    'username' => $info['username'],
                    'name'     => $info['name'],
                    'avatar'   => $info['avatar'],
                ]);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Purge
    // -------------------------------------------------------------------------

    /**
     * AJAX: Purge all social_post entries (and optionally all plugin options).
     */
    public function handlePurgeAll(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        $scope = sanitize_text_field(wp_unslash($_POST['scope'] ?? 'posts'));

        // Delete all social_post entries (permanently, bypass trash)
        $posts = get_posts([
            'post_type'      => \SocialPostsSync\CPT\SocialPostCPT::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $deleted_posts = 0;
        foreach ($posts as $post_id) {
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'post_parent'    => $post_id,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            foreach ($attachments as $att_id) {
                wp_delete_attachment($att_id, true);
            }
            wp_delete_post($post_id, true);
            $deleted_posts++;
        }

        // Reset sync timestamps
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (is_array($enabled_sources)) {
            foreach (scps_extract_source_ids($enabled_sources, 'facebook') as $page_id) {
                delete_option("scps_last_sync_{$page_id}");
            }
            foreach (scps_extract_source_ids($enabled_sources, 'instagram') as $ig_id) {
                delete_option("scps_last_sync_{$ig_id}");
            }
        }

        // Full reset: also wipe settings and connection
        if ($scope === 'all') {
            $this->oauth->disconnect();
            delete_option('scps_licence_key');
            delete_option('scps_enabled_sources');
            delete_option('scps_sync_log');
            delete_option('scps_max_posts');
            delete_option('scps_cron_interval');
            delete_option('scps_sync_lock');
            delete_option('scps_cpt_slug');
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of deleted posts */
                _n('%d publication supprimée.', '%d publications supprimées.', $deleted_posts, 'social-posts-sync'),
                $deleted_posts
            ),
        ]);
    }
}
