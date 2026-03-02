<?php
/**
 * Plugin Name:       Social Posts Sync
 * Plugin URI:        https://github.com/Hylbee/social-posts-sync
 * Description:       Fetches posts from your social media platforms and save them as custom WordPress post types.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Hylbee
 * Author URI:        https://hylbee.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-posts-sync
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Plugin constants
define('SCPS_VERSION',       '1.1.0');
define('SCPS_PLUGIN_FILE',   __FILE__);
define('SCPS_PLUGIN_DIR',    plugin_dir_path(__FILE__));
define('SCPS_PLUGIN_URL',    plugin_dir_url(__FILE__));
define('SCPS_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once SCPS_PLUGIN_DIR . 'includes/helpers.php';

// PSR-4–style autoloader for SocialPostsSync\* classes
spl_autoload_register(function (string $class): void {
    $prefix   = 'SocialPostsSync\\';
    $base_dir = SCPS_PLUGIN_DIR . 'includes/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Bootstrap
add_action('plugins_loaded', function (): void {

    // CPT
    add_action('init', function (): void {
        (new SocialPostsSync\CPT\SocialPostCPT())->register();
    });

    // Admin: settings page + asset loader
    if (is_admin()) {
        $settings = new SocialPostsSync\Admin\SettingsPage();
        $settings->init();

        (new SocialPostsSync\Admin\AssetLoader())->init();
    }

    // OAuth callback
    add_action('admin_init', function (): void {
        if (isset($_GET['scps_oauth_callback']) && current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handleCallback()
            (new SocialPostsSync\Auth\MetaOAuth())->handleCallback();
        }
    });

    // Cron
    (new SocialPostsSync\Cron\CronScheduler())->init();

    // Elementor dynamic tags
    add_action('elementor/dynamic_tags/register', function (\Elementor\Core\DynamicTags\Manager $manager): void {
        (new SocialPostsSync\Elementor\DynamicTags())->register($manager);
    });
});

// Settings link on plugins page
add_filter('plugin_action_links_' . SCPS_PLUGIN_BASENAME, function (array $links): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=social-posts-sync')),
        __('Réglages', 'social-posts-sync')
    );
    array_unshift($links, $settings_link);
    return $links;
});

// Activation / deactivation hooks (must run at file scope, before plugins_loaded)
register_activation_hook(SCPS_PLUGIN_FILE, function (): void {
    SocialPostsSync\Cron\CronScheduler::activate();

    $cpt = new SocialPostsSync\CPT\SocialPostCPT();
    $cpt->register();
    flush_rewrite_rules();
});

register_deactivation_hook(SCPS_PLUGIN_FILE, function (): void {
    SocialPostsSync\Cron\CronScheduler::deactivate();
    flush_rewrite_rules();
});
