<?php
/**
 * Cron-triggered sync runner.
 *
 * Handles the WP-Cron sync callback: acquires the lock, runs the full sync
 * across all enabled sources, and releases the lock.
 *
 * @package SocialPostsSync\Sync
 */

declare(strict_types=1);

namespace SocialPostsSync\Sync;

defined('ABSPATH') || exit;

use SocialPostsSync\Auth\MetaOAuth;
use SocialPostsSync\Api\MetaApiClient;
use SocialPostsSync\Api\FacebookFeed;
use SocialPostsSync\Api\InstagramFeed;

class CronSync {

    /**
     * Run the full cron sync (entry point for the WP-Cron action).
     */
    public function run(): void {
        if (!scps_acquire_sync_lock()) {
            return;
        }

        try {
            $this->doSync();
        } finally {
            scps_release_sync_lock();
        }
    }

    /**
     * Execute the sync across all enabled Facebook and Instagram sources.
     */
    private function doSync(): void {
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

        $oauth = new MetaOAuth();
        $token = $oauth->getAccessToken();

        if (!$token) {
            scps_log_sync(['error' => 'No access token available for cron sync.']);
            return;
        }

        $client = new MetaApiClient($token);
        $syncer = new PostSyncer();
        $log    = ['timestamp' => current_time('c'), 'success' => 0, 'errors' => 0, 'sources' => []];

        do_action('scps_before_sync', $enabled_sources);

        // Facebook pages
        $fb_feed = new FacebookFeed($client);
        foreach (scps_extract_source_ids($enabled_sources, 'facebook') as $page_id) {
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
                        unset($e);
                    }
                }

                update_option("scps_last_sync_{$page_id}", (string) time());
                $log['sources'][$page_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
                unset($e);
            }
        }

        // Instagram accounts
        // Si l'ID est non-numérique → username tiers (Business Discovery)
        // Si l'ID est numérique → compte propre (accès direct par ID)
        $ig_feed = new InstagramFeed($client);
        foreach (scps_extract_source_ids($enabled_sources, 'instagram') as $ig_id) {
            $last_sync   = get_option("scps_last_sync_{$ig_id}", '');
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
                        unset($e);
                    }
                }

                update_option("scps_last_sync_{$ig_id}", (string) time());
                $log['sources'][$ig_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
                unset($e);
            }
        }

        do_action('scps_after_sync', $log);
        scps_log_sync($log);
    }
}
