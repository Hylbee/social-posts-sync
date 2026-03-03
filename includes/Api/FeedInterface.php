<?php
/**
 * Feed Interface
 *
 * Common contract for social platform feed fetchers.
 * Allows SyncRunner and other consumers to be agnostic of the underlying
 * platform (Facebook, Instagram, or future platforms like TikTok, LinkedIn…).
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

interface FeedInterface {

    /**
     * Fetch recent posts from a source.
     *
     * @param string $sourceId Platform-specific source identifier (page ID, account ID, username…).
     * @param int    $limit    Maximum number of posts to return. 0 = use plugin default.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchPosts(string $sourceId, int $limit = 0): array;

    /**
     * Fetch only posts published since a given Unix timestamp (incremental sync).
     *
     * @param string $sourceId Platform-specific source identifier.
     * @param string $since    Unix timestamp string.
     * @param int    $limit    Maximum number of posts to return. 0 = use plugin default.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchSince(string $sourceId, string $since, int $limit = 0): array;
}
