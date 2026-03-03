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

    private PostSyncer $syncer;

    /**
     * @param PostSyncer|null $syncer Post syncer instance. Defaults to a new PostSyncer.
     */
    public function __construct(?PostSyncer $syncer = null) {
        $this->syncer = $syncer ?? new PostSyncer();
    }

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

        $log = ['timestamp' => current_time('c'), 'success' => 0, 'errors' => 0, 'sources' => []];

        do_action('scps_before_sync', $enabled_sources);

        // Facebook pages
        // Use the batch API when multiple pages need a full (non-incremental) fetch
        // to reduce round-trips. Incremental (since-based) fetches remain sequential
        // because they use page-specific tokens and timestamps.
        $fb_feed    = new FacebookFeed($client);
        $fb_ids     = scps_extract_source_ids($enabled_sources, 'facebook');
        $batch_ids  = array_filter($fb_ids, static fn($id) => !get_option("scps_last_sync_{$id}", ''));
        $single_ids = array_filter($fb_ids, static fn($id) => (bool) get_option("scps_last_sync_{$id}", ''));

        // Batch fetch for pages that have never synced (no last_sync timestamp)
        if (count($batch_ids) >= 2) {
            try {
                $batch_posts_map = $fb_feed->fetchPostsBatch(array_values($batch_ids));
            } catch (\Throwable $e) {
                error_log('[SCPS] Batch fetch failed, falling back to individual calls: ' . $e->getMessage());
                $batch_posts_map = [];
                $single_ids      = array_merge(array_values($single_ids), array_values($batch_ids));
                unset($e);
            }

            foreach ($batch_posts_map as $page_id => $posts) {
                foreach ($posts as $post) {
                    try {
                        $this->syncer->sync($post);
                        $log['success']++;
                    } catch (\Throwable $e) {
                        $log['errors']++;
                        error_log('[SCPS] Sync error (facebook post, page ' . $page_id . '): ' . $e->getMessage());
                        unset($e);
                    }
                }
                update_option("scps_last_sync_{$page_id}", (string) time());
                $log['sources'][$page_id] = count($posts);
            }
        } else {
            // Only 1 new page — merge into the sequential list
            $single_ids = array_merge(array_values($single_ids), array_values($batch_ids));
        }

        // Sequential fetch for incremental syncs (since-based)
        foreach ($single_ids as $page_id) {
            $last_sync = get_option("scps_last_sync_{$page_id}", '');
            try {
                $posts = $last_sync
                    ? $fb_feed->fetchSince($page_id, $last_sync)
                    : $fb_feed->fetchPosts($page_id);

                foreach ($posts as $post) {
                    try {
                        $this->syncer->sync($post);
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
                        $this->syncer->sync($post);
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
