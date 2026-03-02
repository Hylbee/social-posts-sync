<?php
/**
 * WP-Cron schedule manager.
 *
 * Registers custom cron intervals, hooks the sync runner to the cron action,
 * and handles activation/deactivation scheduling.
 *
 * @package SocialPostsSync\Cron
 */

declare(strict_types=1);

namespace SocialPostsSync\Cron;

defined('ABSPATH') || exit;

use SocialPostsSync\Sync\CronSync;
use SocialPostsSync\Auth\MetaOAuth;

class CronScheduler {

    /**
     * Register hooks.
     */
    public function init(): void {
        add_filter('cron_schedules', [$this, 'addSchedules']);
        add_action('scps_sync_posts', [$this, 'runSync']);
        add_action('scps_refresh_token', [$this, 'runTokenRefresh']);

        // Ensure the refresh event is scheduled (handles sites already active before this feature)
        if (!wp_next_scheduled('scps_refresh_token')) {
            wp_schedule_event(time(), 'daily', 'scps_refresh_token');
        }
    }

    /**
     * Add custom cron intervals.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function addSchedules(array $schedules): array {
        $schedules['scps_every_6_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __('Toutes les 6 heures', 'social-posts-sync'),
        ];
        $schedules['scps_every_12_hours'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __('Toutes les 12 heures', 'social-posts-sync'),
        ];
        return $schedules;
    }

    /**
     * Callback for the scps_sync_posts cron action.
     */
    public function runSync(): void {
        (new CronSync())->run();
    }

    /**
     * Callback for the scps_refresh_token cron action.
     *
     * Refreshes the Meta access token via the proxy if it is expiring soon.
     */
    public function runTokenRefresh(): void {
        $oauth = new MetaOAuth();

        if (!$oauth->isConnected()) {
            return;
        }

        if (!$oauth->isTokenExpiring()) {
            return;
        }

        $oauth->refreshToken();
    }

    /**
     * Schedule the cron events on plugin activation.
     */
    public static function activate(): void {
        $interval = get_option('scps_cron_interval', 'hourly');
        if (!wp_next_scheduled('scps_sync_posts')) {
            wp_schedule_event(time(), $interval, 'scps_sync_posts');
        }
        if (!wp_next_scheduled('scps_refresh_token')) {
            wp_schedule_event(time(), 'daily', 'scps_refresh_token');
        }
    }

    /**
     * Remove the cron events on plugin deactivation.
     */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('scps_sync_posts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'scps_sync_posts');
        }
        $timestamp = wp_next_scheduled('scps_refresh_token');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'scps_refresh_token');
        }
    }
}
