<?php
/**
 * Admin Settings Page
 *
 * Registers and renders the plugin settings UI under Settings > Social Posts Sync.
 * Contains four tabs: API Configuration, Sources, Sync, and Advanced.
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
use SocialPostsSync\Sync\PostSyncer;
use SocialPostsSync\Admin\Tabs\ApiTab;
use SocialPostsSync\Admin\Tabs\SourcesTab;
use SocialPostsSync\Admin\Tabs\SyncTab;
use SocialPostsSync\Admin\Tabs\AdvancedTab;

/**
 * Manages the plugin admin settings page and all associated AJAX actions.
 */
class SettingsPage {

    private MetaOAuth $oauth;

    public function __construct() {
        $this->oauth = new MetaOAuth();
    }

    /**
     * Register all hooks for the settings page.
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_scps_save_api_settings', [$this, 'handleSaveApiSettings']);
        add_action('admin_post_scps_save_sources', [$this, 'handleSaveSources']);
        add_action('admin_post_scps_save_cron', [$this, 'handleSaveCron']);
        add_action('admin_post_scps_save_sync_settings', [$this, 'handleSaveSyncSettings']);
        add_action('admin_post_scps_disconnect', [$this, 'handleDisconnect']);
        add_action('admin_post_scps_save_advanced', [$this, 'handleSaveAdvanced']);
        add_action('wp_ajax_scps_sync_now', [$this, 'handleAjaxSyncNow']);
        add_action('wp_ajax_scps_sync_status', [$this, 'handleAjaxSyncStatus']);
        add_action('wp_ajax_scps_unlock_sync', [$this, 'handleAjaxUnlockSync']);
        add_action('wp_ajax_scps_load_sources', [$this, 'handleAjaxLoadSources']);
        add_action('wp_ajax_scps_validate_source', [$this, 'handleAjaxValidateSource']);
        add_action('wp_ajax_scps_reset_sync', [$this, 'handleAjaxResetSync']);
        add_action('wp_ajax_scps_purge_all', [$this, 'handleAjaxPurgeAll']);
        add_action('admin_notices', [$this, 'displayAdminNotices']);
    }

    /**
     * Add the settings page to the WordPress admin menu.
     */
    public function addMenuPage(): void {
        add_options_page(
            __('Social Posts Sync', 'social-posts-sync'),
            __('Social Posts Sync', 'social-posts-sync'),
            'manage_options',
            'social-posts-sync',
            [$this, 'renderPage']
        );
    }

    /**
     * Register plugin settings with the Settings API.
     */
    public function registerSettings(): void {
        // Licence key and access token are stored encrypted, not via Settings API
    }

    // -------------------------------------------------------------------------
    // Form Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle saving of API configuration (App ID and App Secret).
     */
    public function handleSaveApiSettings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_api_settings');

        $licence_key = sanitize_text_field(wp_unslash($_POST['scps_licence_key'] ?? ''));
        if ($licence_key) {
            if ($this->oauth->validateLicenceKey($licence_key)) {
                $this->oauth->storeLicenceKey($licence_key);
                $redirect_args = ['page' => 'social-posts-sync', 'tab' => 'api', 'scps_licence' => 'valid'];
            } else {
                $redirect_args = ['page' => 'social-posts-sync', 'tab' => 'api', 'scps_licence' => 'invalid'];
            }
        } else {
            $redirect_args = ['page' => 'social-posts-sync', 'tab' => 'api', 'scps_saved' => '1'];
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle saving of enabled sources configuration.
     * Expects a JSON-encoded payload in $_POST['scps_sources_json']:
     * { "facebook": [{"id":"...","name":"..."},...], "instagram": [...] }
     */
    public function handleSaveSources(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_sources');

        $raw_json = sanitize_textarea_field(wp_unslash($_POST['scps_sources_json'] ?? ''));
        $decoded  = json_decode($raw_json, true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $enabled_sources = [
            'facebook'  => [],
            'instagram' => [],
        ];

        foreach (['facebook', 'instagram'] as $platform) {
            foreach ((array) ($decoded[$platform] ?? []) as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $id   = sanitize_text_field((string) ($source['id']   ?? ''));
                $name = sanitize_text_field((string) ($source['name'] ?? ''));
                if ($id !== '') {
                    $enabled_sources[$platform][] = ['id' => $id, 'name' => $name];
                }
            }
        }

        update_option('scps_enabled_sources', $enabled_sources);

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'tab' => 'sources', 'scps_saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Extract a flat list of IDs for a given platform from enabled_sources.
     *
     * @param array  $enabled_sources The scps_enabled_sources option value.
     * @param string $platform        'facebook' or 'instagram'.
     *
     * @return string[]
     */
    private function extractIds(array $enabled_sources, string $platform): array {
        $ids = [];
        foreach (($enabled_sources[$platform] ?? []) as $source) {
            if (is_array($source) && !empty($source['id'])) {
                $ids[] = (string) $source['id'];
            } elseif (is_string($source) && $source !== '') {
                // Backwards-compat: old flat format
                $ids[] = $source;
            }
        }
        return $ids;
    }

    /**
     * Handle saving of cron schedule.
     */
    public function handleSaveCron(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_cron');

        $allowed_intervals = ['hourly', 'twicedaily', 'daily', 'scps_every_6_hours', 'scps_every_12_hours'];
        $interval = sanitize_text_field(wp_unslash($_POST['scps_cron_interval'] ?? 'hourly'));

        if (!in_array($interval, $allowed_intervals, true)) {
            $interval = 'hourly';
        }

        // Reschedule cron event
        $timestamp = wp_next_scheduled('scps_sync_posts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'scps_sync_posts');
        }
        update_option('scps_cron_interval', $interval);
        wp_schedule_event(time(), $interval, 'scps_sync_posts');

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'tab' => 'sync', 'scps_saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Handle OAuth disconnection.
     */
    public function handleDisconnect(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_disconnect');
        $this->oauth->disconnect();

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'tab' => 'api', 'scps_disconnected' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Handle saving of advanced settings (CPT slug, etc.).
     */
    public function handleSaveAdvanced(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_advanced');

        $raw_slug = sanitize_title(wp_unslash($_POST['scps_cpt_slug'] ?? ''));
        // Fallback to default if empty after sanitization
        $slug = $raw_slug ?: 'social-posts';

        $old_slug = (string) get_option('scps_cpt_slug', 'social-posts');
        update_option('scps_cpt_slug', $slug);

        // Flush rewrite rules only if the slug actually changed
        if ($slug !== $old_slug) {
            flush_rewrite_rules();
        }

        // Media sideload timeout
        $sideload_timeout = absint($_POST['scps_sideload_timeout'] ?? 0);
        if ($sideload_timeout < 10 || $sideload_timeout > 120) {
            $sideload_timeout = 30;
        }
        update_option('scps_sideload_timeout', $sideload_timeout);

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'tab' => 'advanced', 'scps_saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Handle saving of sync settings (max posts per source).
     */
    public function handleSaveSyncSettings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_sync_settings');

        $max_posts = absint(wp_unslash($_POST['scps_max_posts'] ?? 20));
        $max_posts = max(1, min(100, $max_posts)); // Clamp between 1 and 100

        update_option('scps_max_posts', $max_posts);

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'tab' => 'sync', 'scps_saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Trigger an immediate sync for all enabled sources.
     */
    public function handleAjaxSyncNow(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        // Acquire lock — returns false if already running
        if (!scps_acquire_sync_lock()) {
            wp_send_json_error([
                'message' => __('Une synchronisation est déjà en cours. Veuillez patienter.', 'social-posts-sync'),
                'locked'  => true,
            ]);
            return;
        }

        try {
            $this->runSync();
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
     * Internal sync logic for AJAX-triggered syncs.
     * Mirrors SocialPostsSync::do_sync() but runs in the SettingsPage context.
     *
     * @throws \RuntimeException If no access token is available.
     */
    private function runSync(): void {
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

        $token = $this->oauth->getAccessToken();
        if (!$token) {
            throw new \RuntimeException(esc_html__('Aucun token d\'accès disponible. Reconnectez-vous à Meta.', 'social-posts-sync'));
        }

        $client  = new MetaApiClient($token);
        $syncer  = new PostSyncer();
        $log     = ['timestamp' => current_time('c'), 'success' => 0, 'errors' => 0, 'sources' => []];

        do_action('scps_before_sync', $enabled_sources);

        // Facebook pages
        $fb_feed = new FacebookFeed($client);
        foreach ($this->extractIds($enabled_sources, 'facebook') as $page_id) {
            $last_sync = get_option("scps_last_sync_{$page_id}", '');
            try {
                $posts = $last_sync
                    ? $fb_feed->fetchSince($page_id, $last_sync)
                    : $fb_feed->fetchPosts($page_id);

                foreach ($posts as $post) {
                    try {
                        $syncer->sync($post);
                        $log['success']++;
                    } catch (\Throwable $e) {
                        $log['errors']++;
                    }
                }

                update_option("scps_last_sync_{$page_id}", (string) time());
                $log['sources'][$page_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
            }
        }

        // Instagram accounts
        // Si l'ID est non-numérique → username tiers (Business Discovery)
        // Si l'ID est numérique → compte propre (accès direct par ID)
        $ig_feed = new InstagramFeed($client);
        foreach ($this->extractIds($enabled_sources, 'instagram') as $ig_id) {
            $last_sync  = get_option("scps_last_sync_{$ig_id}", '');
            $is_username = !ctype_digit($ig_id);
            try {
                if ($is_username) {
                    $posts = $last_sync
                        ? $ig_feed->fetchDiscoverySince($ig_id, $last_sync)
                        : $ig_feed->fetchDiscoveryPosts($ig_id);
                } else {
                    $posts = $last_sync
                        ? $ig_feed->fetchSince($ig_id, $last_sync)
                        : $ig_feed->fetchPosts($ig_id);
                }

                foreach ($posts as $post) {
                    try {
                        $syncer->sync($post);
                        $log['success']++;
                    } catch (\Throwable $e) {
                        $log['errors']++;
                    }
                }

                update_option("scps_last_sync_{$ig_id}", (string) time());
                $log['sources'][$ig_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
            }
        }

        do_action('scps_after_sync', $log);
        scps_log_sync($log);
    }

    /**
     * AJAX: Return current sync lock status (used to poll button state on page load).
     */
    public function handleAjaxSyncStatus(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        wp_send_json_success(['running' => scps_is_sync_running()]);
    }

    /**
     * AJAX: Force-release a stuck sync lock.
     */
    public function handleAjaxUnlockSync(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        scps_release_sync_lock();
        wp_send_json_success(['message' => __('Verrou libéré.', 'social-posts-sync')]);
    }

    /**
     * AJAX: Reset sync timestamps so the next sync re-fetches all posts.
     */
    public function handleAjaxResetSync(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
            return;
        }

        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

        foreach ($this->extractIds($enabled_sources, 'facebook') as $page_id) {
            delete_option("scps_last_sync_{$page_id}");
        }
        foreach ($this->extractIds($enabled_sources, 'instagram') as $ig_id) {
            delete_option("scps_last_sync_{$ig_id}");
        }

        wp_send_json_success(['message' => __('Timestamps réinitialisés. La prochaine sync récupèrera tous les posts.', 'social-posts-sync')]);
    }

    /**
     * AJAX: Load Facebook Pages and Instagram accounts for the Sources tab.
     */
    public function handleAjaxLoadSources(): void {
        check_ajax_referer('scps_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'social-posts-sync')]);
        }

        $token = $this->oauth->getAccessToken();
        if (!$token) {
            wp_send_json_error(['message' => __('Non connecté à Meta.', 'social-posts-sync')]);
        }

        try {
            $client   = new MetaApiClient($token);
            $fb_feed  = new FacebookFeed($client);
            $ig_feed  = new InstagramFeed($client);

            $pages    = $fb_feed->getPages();
            $accounts = $ig_feed->getAccounts();

            wp_send_json_success([
                'pages'    => $pages,
                'accounts' => $accounts,
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
    public function handleAjaxValidateSource(): void {
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

        // Détection automatique : @ ou non-numérique → Instagram, numérique → Facebook
        $clean    = ltrim($identifier, '@');
        $platform = ctype_digit($clean) ? 'facebook' : 'instagram';

        try {
            $client = new MetaApiClient($token);

            if ($platform === 'facebook') {
                $fb_feed = new FacebookFeed($client);
                $info    = $fb_feed->fetchPublicPageInfo($clean);

                wp_send_json_success([
                    'platform' => 'facebook',
                    'id'       => $info['id'],
                    'name'     => $info['name'],
                    'avatar'   => $info['avatar'],
                ]);
            } else {
                $ig_feed = new InstagramFeed($client);
                $info    = $ig_feed->fetchDiscoveryAccountInfo($clean);

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
    // Admin Notices
    // -------------------------------------------------------------------------

    /**
     * Display admin notices for save confirmations and OAuth status.
     */
    public function displayAdminNotices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_social-posts-sync') {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET flags set by wp_safe_redirect after nonce-verified form processing
        if (isset($_GET['scps_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Paramètres enregistrés.', 'social-posts-sync');
            echo '</p></div>';
        }

        if (isset($_GET['scps_licence'])) {
            if ($_GET['scps_licence'] === 'valid') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                esc_html_e('Clé de licence valide et enregistrée.', 'social-posts-sync');
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>';
                esc_html_e('Clé de licence invalide. Vérifiez votre licence et réessayez.', 'social-posts-sync');
                echo '</p></div>';
            }
        }

        if (isset($_GET['scps_connected'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Connexion à Meta réussie !', 'social-posts-sync');
            echo '</p></div>';
        }

        if (isset($_GET['scps_disconnected'])) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            esc_html_e('Déconnecté de Meta.', 'social-posts-sync');
            echo '</p></div>';
        }

        if (isset($_GET['scps_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['scps_error']));
            /* translators: %s: OAuth error message returned by Meta */
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html(sprintf(__('Erreur OAuth : %s', 'social-posts-sync'), $error));
            echo '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Token expiry warning
        if ($this->oauth->isConnected() && $this->oauth->isTokenExpiring()) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            esc_html_e('Votre token Meta expire bientôt. Reconnectez-vous pour éviter toute interruption.', 'social-posts-sync');
            echo '</p></div>';
        }
    }

    // -------------------------------------------------------------------------
    // Page Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the main settings page.
     */
    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = sanitize_text_field(wp_unslash($_GET['tab'] ?? 'api')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switcher, no data mutation
        $tabs = [
            'api'      => __('Configuration API', 'social-posts-sync'),
            'sources'  => __('Sources', 'social-posts-sync'),
            'sync'     => __('Synchronisation', 'social-posts-sync'),
            'advanced' => __('Avancé', 'social-posts-sync'),
        ];

        if (!array_key_exists($active_tab, $tabs)) {
            $active_tab = 'api';
        }
        ?>
        <div class="wrap scps-settings-wrap">
            <h1>
                <span class="dashicons dashicons-share scps-page-icon"></span>
                <?php esc_html_e('Social Posts Sync', 'social-posts-sync'); ?>
            </h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'social-posts-sync', 'tab' => $tab_id], admin_url('options-general.php'))); ?>"
                       class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="tab-content">
                <?php $this->renderActiveTab($active_tab); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Instantiate and render the appropriate tab class.
     *
     * @param string $active_tab The currently active tab slug.
     */
    private function renderActiveTab(string $active_tab): void {
        switch ($active_tab) {
            case 'api':
                (new ApiTab($this->oauth))->render();
                break;
            case 'sources':
                (new SourcesTab($this->oauth))->render();
                break;
            case 'sync':
                (new SyncTab())->render();
                break;
            case 'advanced':
                (new AdvancedTab())->render();
                break;
            default:
                (new ApiTab($this->oauth))->render();
                break;
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: Purge
    // -------------------------------------------------------------------------

    /**
     * AJAX: Purge all social_post entries (and optionally all plugin options).
     */
    public function handleAjaxPurgeAll(): void {
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
            // Delete attached media too
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
            foreach ($this->extractIds($enabled_sources, 'facebook') as $page_id) {
                delete_option("scps_last_sync_{$page_id}");
            }
            foreach ($this->extractIds($enabled_sources, 'instagram') as $ig_id) {
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
            delete_option('scps_sync_running');
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
