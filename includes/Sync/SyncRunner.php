<?php
/**
 * Sync Runner
 *
 * Shared sync execution service used by both the cron runner and the manual
 * AJAX-triggered sync. Iterates all enabled Facebook and Instagram sources,
 * fetches new posts, and syncs them to WordPress via PostSyncer.
 *
 * @package SocialPostsSync\Sync
 */

declare(strict_types=1);

namespace SocialPostsSync\Sync;

defined('ABSPATH') || exit;

use SocialPostsSync\Api\MetaApiClient;
use SocialPostsSync\Api\FacebookFeed;
use SocialPostsSync\Api\InstagramFeed;

class SyncRunner {

    /**
     * Execute the sync across all enabled Facebook and Instagram sources.
     *
     * @param MetaApiClient $client Authenticated Graph API client.
     *
     * @return array Log entry: { timestamp, success, errors, sources }
     */
    public function run(MetaApiClient $client): array {
        $enabled_sources = get_option('scps_enabled_sources', ['facebook' => [], 'instagram' => []]);
        if (!is_array($enabled_sources)) {
            $enabled_sources = ['facebook' => [], 'instagram' => []];
        }

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
                        error_log('[SCPS] Sync error (facebook post, page ' . $page_id . '): ' . $e->getMessage());
                        unset($e);
                    }
                }

                update_option("scps_last_sync_{$page_id}", (string) time());
                $log['sources'][$page_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
                error_log('[SCPS] Sync error (facebook page ' . $page_id . '): ' . $e->getMessage());
                unset($e);
            }
        }

        // Instagram accounts
        // Non-numeric ID → third-party username (Business Discovery)
        // Numeric ID → own account (direct access)
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
                        error_log('[SCPS] Sync error (instagram post, account ' . $ig_id . '): ' . $e->getMessage());
                        unset($e);
                    }
                }

                update_option("scps_last_sync_{$ig_id}", (string) time());
                $log['sources'][$ig_id] = count($posts);
            } catch (\Throwable $e) {
                $log['errors']++;
                error_log('[SCPS] Sync error (instagram account ' . $ig_id . '): ' . $e->getMessage());
                unset($e);
            }
        }

        do_action('scps_after_sync', $log);
        scps_log_sync($log);

        return $log;
    }
}
