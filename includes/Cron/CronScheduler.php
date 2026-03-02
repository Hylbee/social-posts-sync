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

class CronScheduler {

    /**
     * Register hooks.
     */
    public function init(): void {
        add_filter('cron_schedules', [$this, 'addSchedules']);
        add_action('scps_sync_posts', [$this, 'runSync']);
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
     * Schedule the cron event on plugin activation.
     */
    public static function activate(): void {
        $interval = get_option('scps_cron_interval', 'hourly');
        if (!wp_next_scheduled('scps_sync_posts')) {
            wp_schedule_event(time(), $interval, 'scps_sync_posts');
        }
    }

    /**
     * Remove the cron event on plugin deactivation.
     */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled('scps_sync_posts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'scps_sync_posts');
        }
    }
}
