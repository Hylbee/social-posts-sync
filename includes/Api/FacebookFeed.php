<?php
/**
 * Facebook Feed Fetcher
 *
 * Retrieves posts from Facebook Pages via the Meta Graph API
 * and normalizes them into the common social post shape.
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

/**
 * Fetches and normalizes Facebook Page posts.
 */
class FacebookFeed {

    /**
     * Fields requested for each Facebook post.
     */
    private const POST_FIELDS = 'id,message,full_picture,created_time,permalink_url,reactions.summary(total_count),attachments{media_type,media{image{src},source},subattachments{media_type,media{image{src},source}}}';

    /**
     * Fields requested for page info (avatar).
     */
    private const PAGE_FIELDS = 'id,name,picture{url}';

    private MetaApiClient $client;

    /**
     * Cache of page access tokens keyed by page ID.
     *
     * @var array<string, string>
     */
    private array $page_tokens = [];

    public function __construct(MetaApiClient $client) {
        $this->client = $client;
    }

    /**
     * Retrieve all Facebook Pages managed by the authenticated user.
     * Also caches their Page Access Tokens for subsequent requests.
     *
     * @return array Array of page objects with 'id', 'name', 'access_token'.
     *
     * @throws MetaApiException On API error.
     */
    public function getPages(): array {
        $data = $this->client->get('/me/accounts', ['fields' => 'id,name,access_token,picture{url}']);
        $pages = $data['data'] ?? [];

        // Cache page tokens
        $stored_tokens = (array) get_option('scps_page_tokens', []);
        foreach ($pages as $page) {
            if (!empty($page['id']) && !empty($page['access_token'])) {
                $this->page_tokens[$page['id']] = $page['access_token'];
                $stored_tokens[$page['id']]      = $page['access_token'];
            }
        }
        update_option('scps_page_tokens', $stored_tokens);

        return $pages;
    }

    /**
     * Get a MetaApiClient using the Page Access Token for a given page.
     * Falls back to the user token if no page token is found.
     *
     * @param string $pageId Facebook Page ID.
     *
     * @return MetaApiClient Client authenticated with the Page Access Token.
     */
    private function getPageClient(string $pageId): MetaApiClient {
        // Try in-memory cache first
        $token = $this->page_tokens[$pageId] ?? null;

        // Then try WP option (persisted from previous getPages() call)
        if (!$token) {
            $stored_tokens = (array) get_option('scps_page_tokens', []);
            $token         = (string) ($stored_tokens[$pageId] ?? '');
        }

        if ($token) {
            return new MetaApiClient($token);
        }

        // Fallback: fetch the page token now
        try {
            $data = $this->client->get('/me/accounts', ['fields' => 'id,access_token']);
            foreach (($data['data'] ?? []) as $page) {
                if ((string) $page['id'] === $pageId && !empty($page['access_token'])) {
                    $token                              = $page['access_token'];
                    $this->page_tokens[$pageId]         = $token;
                    $stored_tokens                      = (array) get_option('scps_page_tokens', []);
                    $stored_tokens[$pageId]             = $token;
                    update_option('scps_page_tokens', $stored_tokens);
                    return new MetaApiClient($token);
                }
            }
        } catch (\Throwable $e) {
            unset($e); // Silently fall back to user token
        }

        return $this->client;
    }

    /**
     * Fetch basic info for any public Facebook Page (without admin rights).
     *
     * Uses the user access token directly — no page token needed for public pages.
     *
     * @param string $pageId Facebook Page ID.
     *
     * @return array Associative array with 'id', 'name', 'avatar'.
     *
     * @throws MetaApiException If the page is inaccessible or not found.
     */
    public function fetchPublicPageInfo(string $pageId): array {
        $data = $this->client->get("/{$pageId}", ['fields' => 'id,name,picture{url}']);

        return [
            'id'     => (string) ($data['id']   ?? $pageId),
            'name'   => (string) ($data['name'] ?? ''),
            'avatar' => (string) ($data['picture']['data']['url'] ?? ''),
        ];
    }

    /**
     * Fetch recent posts from a Facebook Page.
     *
     * @param string $pageId Facebook Page ID.
     * @param int    $limit  Maximum number of posts to retrieve.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchPosts(string $pageId, int $limit = 0): array {
        $page_client = $this->getPageClient($pageId);
        $page_info   = $this->getPageInfo($pageId, $page_client);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $page_client->get("/{$pageId}/posts", [
            'fields' => self::POST_FIELDS,
            'limit'  => min($limit, 100), // API max is 100 per page
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalize($raw, $page_info);
        }

        return $posts;
    }

    /**
     * Fetch only posts published since a given Unix timestamp (incremental sync).
     *
     * @param string $pageId Facebook Page ID.
     * @param string $since  Unix timestamp string.
     * @param int    $limit  Maximum number of posts.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchSince(string $pageId, string $since, int $limit = 0): array {
        $page_client = $this->getPageClient($pageId);
        $page_info   = $this->getPageInfo($pageId, $page_client);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $page_client->get("/{$pageId}/posts", [
            'fields' => self::POST_FIELDS,
            'since'  => $since,
            'limit'  => min($limit, 100),
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalize($raw, $page_info);
        }

        return $posts;
    }

    /**
     * Fetch basic info for a page (name and avatar URL).
     *
     * @param string          $pageId      Facebook Page ID.
     * @param MetaApiClient   $page_client Client to use (should use Page Access Token).
     *
     * @return array Associative array with 'name' and 'avatar'.
     */
    private function getPageInfo(string $pageId, MetaApiClient $page_client): array {
        try {
            $data = $page_client->get("/{$pageId}", ['fields' => self::PAGE_FIELDS]);
            return [
                'name'   => (string) ($data['name'] ?? ''),
                'avatar' => (string) ($data['picture']['data']['url'] ?? ''),
            ];
        } catch (\Throwable $e) {
            unset($e); // Silently fail — page info is non-critical
            return ['name' => '', 'avatar' => ''];
        }
    }

    /**
     * Normalize a raw Facebook post into the common social post shape.
     *
     * @param array $raw       Raw API post object.
     * @param array $page_info Page name and avatar.
     *
     * @return array Normalized post.
     */
    private function normalize(array $raw, array $page_info): array {
        [$media_urls, $video_url] = $this->extractMedia($raw);

        // Always include full_picture as thumbnail (works for both images and video posts).
        // Compare without query-string parameters to avoid duplicates caused by differing
        // session tokens in Meta CDN URLs (e.g. ?oh=...&oe=...).
        if (!empty($raw['full_picture'])) {
            $full_pic_base = strtok($raw['full_picture'], '?');
            $already_present = false;
            foreach ($media_urls as $existing_url) {
                if (strtok($existing_url, '?') === $full_pic_base) {
                    $already_present = true;
                    break;
                }
            }
            if (!$already_present) {
                array_unshift($media_urls, $raw['full_picture']);
            }
        }

        $published_at = '';
        if (!empty($raw['created_time'])) {
            try {
                $dt = new \DateTimeImmutable($raw['created_time']);
                $published_at = $dt->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                $published_at = $raw['created_time'];
            }
        }

        $normalized = [
            'platform'      => 'facebook',
            'source_id'     => (string) ($raw['id'] ?? ''),
            'content'       => (string) ($raw['message'] ?? ''),
            'permalink'     => (string) ($raw['permalink_url'] ?? ''),
            'published_at'  => $published_at,
            'media_urls'    => $media_urls,
            'video_url'     => $video_url,
            'author_name'   => $page_info['name'],
            'author_avatar' => $page_info['avatar'],
            'likes_count'   => (int) ($raw['reactions']['summary']['total_count'] ?? 0),
            'raw'           => $raw,
        ];

        /**
         * Filter the normalized Facebook post before it is saved.
         *
         * @param array $normalized Normalized post data.
         * @param array $raw        Original raw API data.
         */
        return apply_filters('scps_normalize_post', $normalized, $raw);
    }

    /**
     * Extract image URLs and video URL from a post's attachments.
     *
     * Videos are stored separately and not sideloaded into the media library.
     * For video posts, `full_picture` is used as the thumbnail image.
     *
     * @param array $raw Raw API post object.
     *
     * @return array{0: string[], 1: string} [image_urls, video_url]
     */
    private function extractMedia(array $raw): array {
        $image_urls = [];
        $video_url  = '';

        $attachments = $raw['attachments']['data'] ?? [];
        foreach ($attachments as $attachment) {
            $media_type = strtolower((string) ($attachment['media_type'] ?? ''));

            if ($media_type === 'video') {
                // Store the video source URL, use full_picture as thumbnail (handled in normalize)
                $src = $attachment['media']['source'] ?? null;
                if ($src && !$video_url) {
                    $video_url = (string) $src;
                }
                continue;
            }

            // Subattachments (albums, carousels)
            // When subattachments exist, the parent attachment's image is just a
            // duplicate of the first child — skip it and use only the children.
            $subattachments = $attachment['subattachments']['data'] ?? [];
            if (!empty($subattachments)) {
                foreach ($subattachments as $sub) {
                    $sub_type = strtolower((string) ($sub['media_type'] ?? ''));
                    if ($sub_type === 'video') {
                        $sub_src = $sub['media']['source'] ?? null;
                        if ($sub_src && !$video_url) {
                            $video_url = (string) $sub_src;
                        }
                        continue;
                    }
                    $sub_src = $sub['media']['image']['src'] ?? null;
                    if ($sub_src) {
                        $image_urls[] = (string) $sub_src;
                    }
                }
            } else {
                // Single image attachment (no children)
                $src = $attachment['media']['image']['src'] ?? null;
                if ($src) {
                    $image_urls[] = (string) $src;
                }
            }
        }

        // Deduplicate by base URL (without query-string) to handle Meta CDN session tokens
        $seen_bases = [];
        $unique_urls = [];
        foreach ($image_urls as $url) {
            $base = strtok($url, '?');
            if (!isset($seen_bases[$base])) {
                $seen_bases[$base] = true;
                $unique_urls[] = $url;
            }
        }

        return [$unique_urls, $video_url];
    }
}
