<?php
/**
 * Admin Settings Page
 *
 * Registers and renders the plugin settings UI under Settings > Social Posts Sync.
 * Contains four tabs: API Configuration, Sources, Sync, and Advanced.
 * AJAX handling is delegated to AjaxHandlers.
 *
 * @package SocialPostsSync\Admin
 */

declare(strict_types=1);

namespace SocialPostsSync\Admin;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;
use SocialPostsSync\Admin\Tabs\ApiTab;
use SocialPostsSync\Admin\Tabs\SourcesTab;
use SocialPostsSync\Admin\Tabs\SyncTab;
use SocialPostsSync\Admin\Tabs\AdvancedTab;

/**
 * Manages the plugin admin settings page, form handlers, and admin notices.
 * AJAX actions are handled by AjaxHandlers.
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
        add_action('admin_post_scps_save_sources',      [$this, 'handleSaveSources']);
        add_action('admin_post_scps_save_cron',         [$this, 'handleSaveCron']);
        add_action('admin_post_scps_save_sync_settings', [$this, 'handleSaveSyncSettings']);
        add_action('admin_post_scps_disconnect',        [$this, 'handleDisconnect']);
        add_action('admin_post_scps_save_advanced',     [$this, 'handleSaveAdvanced']);
        add_action('admin_notices', [$this, 'displayAdminNotices']);

        // Delegate all AJAX actions to a dedicated handler class
        (new AjaxHandlers($this->oauth))->init();
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
     * Handle saving of API configuration (licence key).
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
     * Handle saving of advanced settings (CPT slug, media sideload timeout).
     */
    public function handleSaveAdvanced(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'social-posts-sync'));
        }

        check_admin_referer('scps_save_advanced');

        $raw_slug = sanitize_title(wp_unslash($_POST['scps_cpt_slug'] ?? ''));
        // Fallback to default if empty or too short after sanitization (min 3 chars)
        $slug = (strlen($raw_slug) >= 3) ? $raw_slug : 'social-posts';

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
}
