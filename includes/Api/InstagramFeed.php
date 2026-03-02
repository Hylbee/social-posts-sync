<?php
/**
 * Instagram Feed Fetcher
 *
 * Retrieves media from Instagram Business accounts linked to Facebook Pages
 * via the Meta Graph API and normalizes them into the common social post shape.
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

/**
 * Fetches and normalizes Instagram Business account media.
 */
class InstagramFeed {

    /**
     * Fields to request for each Instagram media object.
     */
    private const MEDIA_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,username';

    /**
     * Fields to request for children of a CAROUSEL_ALBUM.
     */
    private const CHILDREN_FIELDS = 'media_url,media_type';

    /**
     * Fields to request via Business Discovery for third-party media.
     */
    private const DISCOVERY_MEDIA_FIELDS = 'id,media_type,media_url,thumbnail_url,permalink,timestamp,caption,like_count,comments_count,children{media_url,media_type}';

    private MetaApiClient $client;

    public function __construct(MetaApiClient $client) {
        $this->client = $client;
    }

    /**
     * Get the authenticated user's own Instagram Business Account ID.
     *
     * Result is cached in the 'scps_ig_business_id' option to avoid repeated API calls.
     *
     * @return string Instagram Business Account ID.
     *
     * @throws \RuntimeException If no IG Business Account is linked to any managed Facebook Page.
     */
    public function getMyIgBusinessId(): string {
        $cached = (string) get_option('scps_ig_business_id', '');
        if ($cached !== '') {
            return $cached;
        }

        $data = $this->client->get('/me/accounts', [
            'fields' => 'instagram_business_account{id}',
        ]);

        foreach (($data['data'] ?? []) as $page) {
            $ig_id = (string) ($page['instagram_business_account']['id'] ?? '');
            if ($ig_id !== '') {
                update_option('scps_ig_business_id', $ig_id);
                return $ig_id;
            }
        }

        throw new \RuntimeException(
            __('Aucun compte Instagram Business lié à vos Pages Facebook. Le compte cible et le vôtre doivent être de type Business ou Creator.', 'social-posts-sync')
        );
    }

    /**
     * Validate and fetch basic info for a third-party Instagram account via Business Discovery.
     *
     * @param string $username Target Instagram username (without @).
     *
     * @return array Associative array with 'id' (= username), 'username', 'name', 'avatar'.
     *
     * @throws \RuntimeException If no IG Business Account is available.
     * @throws MetaApiException  If the target account is inaccessible.
     */
    public function fetchDiscoveryAccountInfo(string $username): array {
        $ig_biz_id = $this->getMyIgBusinessId();

        $fields = 'business_discovery.username(' . $username . '){id,username,name,profile_picture_url}';
        $data   = $this->client->get("/{$ig_biz_id}", ['fields' => $fields]);

        $discovery = $data['business_discovery'] ?? null;
        if (!$discovery) {
            throw new MetaApiException(
                sprintf(
                    /* translators: %s: Instagram username */
                    __('Compte @%s introuvable ou non accessible via Business Discovery. Assurez-vous qu\'il s\'agit d\'un compte Business ou Creator public.', 'social-posts-sync'),
                    $username
                )
            );
        }

        return [
            'id'       => $username,
            'username' => (string) ($discovery['username']            ?? $username),
            'name'     => (string) ($discovery['name']                ?? $username),
            'avatar'   => (string) ($discovery['profile_picture_url'] ?? ''),
        ];
    }

    /**
     * Fetch recent media from a third-party Instagram account via Business Discovery.
     *
     * @param string $username Target Instagram username (without @).
     * @param int    $limit    Maximum number of media items.
     *
     * @return array Normalized post arrays.
     *
     * @throws \RuntimeException If no IG Business Account is available.
     * @throws MetaApiException  On API error.
     */
    public function fetchDiscoveryPosts(string $username, int $limit = 0): array {
        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $ig_biz_id    = $this->getMyIgBusinessId();
        $media_fields = 'media.limit(' . min($limit, 100) . '){' . self::DISCOVERY_MEDIA_FIELDS . '}';
        $fields       = 'business_discovery.username(' . $username . '){username,name,profile_picture_url,' . $media_fields . '}';

        $data      = $this->client->get("/{$ig_biz_id}", ['fields' => $fields]);
        $discovery = $data['business_discovery'] ?? [];

        $account_info = [
            'name'     => (string) ($discovery['name']                ?? $username),
            'username' => (string) ($discovery['username']            ?? $username),
            'avatar'   => (string) ($discovery['profile_picture_url'] ?? ''),
        ];

        $posts = [];
        foreach (($discovery['media']['data'] ?? []) as $raw) {
            $posts[] = $this->normalize($raw, $account_info);
        }

        return $posts;
    }

    /**
     * Fetch media from a third-party Instagram account since a given timestamp (Business Discovery).
     *
     * The Business Discovery API does not support server-side `since` filtering,
     * so we fetch recent media and filter client-side by timestamp.
     *
     * @param string $username Target Instagram username (without @).
     * @param string $since    Unix timestamp string.
     * @param int    $limit    Maximum number of media items to fetch before filtering.
     *
     * @return array Normalized post arrays published after $since.
     */
    public function fetchDiscoverySince(string $username, string $since, int $limit = 0): array {
        $posts    = $this->fetchDiscoveryPosts($username, $limit);
        $since_ts = (int) $since;

        return array_values(array_filter($posts, function (array $post) use ($since_ts): bool {
            if (empty($post['published_at'])) {
                return true;
            }
            try {
                return (new \DateTimeImmutable($post['published_at']))->getTimestamp() > $since_ts;
            } catch (\Throwable) {
                return true;
            }
        }));
    }

    /**
     * Retrieve all Instagram Business accounts linked to a given Facebook Page.
     *
     * @param string $pageId Facebook Page ID.
     *
     * @return array Array of IG account objects, each with 'id', 'name', 'username'.
     *
     * @throws MetaApiException On API error.
     */
    public function getAccounts(string $pageId = 'me'): array {
        // Fetch IG accounts linked to pages the user manages
        $pages_data = $this->client->get('/me/accounts', [
            'fields' => 'id,name,instagram_business_account{id,name,username,profile_picture_url}',
        ]);

        $accounts = [];
        foreach (($pages_data['data'] ?? []) as $page) {
            $ig = $page['instagram_business_account'] ?? null;
            if ($ig) {
                $accounts[] = array_merge($ig, ['page_id' => $page['id'], 'page_name' => $page['name']]);
            }
        }

        return $accounts;
    }

    /**
     * Fetch recent media from an Instagram Business account.
     *
     * @param string $igAccountId Instagram Business Account ID.
     * @param int    $limit       Maximum number of media items.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchPosts(string $igAccountId, int $limit = 0): array {
        $account_info = $this->getAccountInfo($igAccountId);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $this->client->get("/{$igAccountId}/media", [
            'fields' => self::MEDIA_FIELDS,
            'limit'  => min($limit, 100),
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalize($raw, $account_info);
        }

        return $posts;
    }

    /**
     * Fetch only media published since a given Unix timestamp (incremental sync).
     *
     * @param string $igAccountId Instagram Business Account ID.
     * @param string $since       Unix timestamp string.
     * @param int    $limit       Maximum number of media items.
     *
     * @return array Normalized post arrays.
     *
     * @throws MetaApiException On API error.
     */
    public function fetchSince(string $igAccountId, string $since, int $limit = 0): array {
        $account_info = $this->getAccountInfo($igAccountId);

        if ($limit <= 0) {
            $limit = (int) get_option('scps_max_posts', 20);
        }

        $data = $this->client->get("/{$igAccountId}/media", [
            'fields' => self::MEDIA_FIELDS,
            'since'  => $since,
            'limit'  => min($limit, 100),
        ]);

        $posts = [];
        foreach (($data['data'] ?? []) as $raw) {
            $posts[] = $this->normalize($raw, $account_info);
        }

        return $posts;
    }

    /**
     * Fetch basic info for an Instagram account.
     *
     * @param string $igAccountId Instagram Business Account ID.
     *
     * @return array Associative array with 'name', 'username', 'avatar'.
     */
    private function getAccountInfo(string $igAccountId): array {
        try {
            $data = $this->client->get("/{$igAccountId}", [
                'fields' => 'id,name,username,profile_picture_url',
            ]);
            return [
                'name'     => (string) ($data['name']                ?? $data['username'] ?? ''),
                'username' => (string) ($data['username']            ?? ''),
                'avatar'   => (string) ($data['profile_picture_url'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('[SCPS InstagramFeed] Failed to fetch account info for account ' . $igAccountId . ': ' . $e->getMessage());
            return ['name' => '', 'username' => '', 'avatar' => ''];
        }
    }

    /**
     * Fetch children media URLs for a CAROUSEL_ALBUM post.
     *
     * @param string $mediaId Instagram media ID.
     *
     * @return array Array of media URL strings.
     */
    private function fetchCarouselChildren(string $mediaId): array {
        try {
            $data = $this->client->get("/{$mediaId}/children", [
                'fields' => self::CHILDREN_FIELDS,
            ]);
        } catch (\Throwable $e) {
            error_log('[SCPS InstagramFeed] Failed to fetch carousel children for media ' . $mediaId . ': ' . $e->getMessage());
            return [];
        }

        $urls = [];
        foreach (($data['data'] ?? []) as $child) {
            $url = $child['media_url'] ?? null;
            if ($url) {
                $urls[] = (string) $url;
            }
        }

        return $urls;
    }

    /**
     * Normalize a raw Instagram media object into the common social post shape.
     *
     * @param array $raw          Raw API media object.
     * @param array $account_info Account name and avatar.
     *
     * @return array Normalized post.
     */
    private function normalize(array $raw, array $account_info): array {
        $media_type = (string) ($raw['media_type'] ?? '');
        $media_urls = [];
        $video_url  = '';

        if ('CAROUSEL_ALBUM' === $media_type) {
            $media_urls = $this->fetchCarouselChildren((string) ($raw['id'] ?? ''));
        } elseif ('VIDEO' === $media_type) {
            // Store video URL separately — do not sideload into media library
            $video_url = (string) ($raw['media_url'] ?? '');
            // Use thumbnail as the representative image for the post
            if (!empty($raw['thumbnail_url'])) {
                $media_urls[] = (string) $raw['thumbnail_url'];
            }
        } elseif (!empty($raw['media_url'])) {
            $media_urls[] = (string) $raw['media_url'];
        }

        $published_at = '';
        if (!empty($raw['timestamp'])) {
            try {
                $dt = new \DateTimeImmutable($raw['timestamp']);
                $published_at = $dt->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                $published_at = $raw['timestamp'];
            }
        }

        $normalized = [
            'platform'      => 'instagram',
            'source_id'     => (string) ($raw['id']         ?? ''),
            'content'       => (string) ($raw['caption']    ?? ''),
            'permalink'     => (string) ($raw['permalink']  ?? ''),
            'published_at'  => $published_at,
            'media_urls'    => $media_urls,
            'video_url'     => $video_url,
            'author_name'   => $account_info['name'],
            'author_avatar' => $account_info['avatar'],
            'likes_count'   => (int) ($raw['like_count']   ?? 0),
            'raw'           => $raw,
        ];

        /**
         * Filter the normalized Instagram post before it is saved.
         *
         * @param array $normalized Normalized post data.
         * @param array $raw        Original raw API data.
         */
        return apply_filters('scps_normalize_post', $normalized, $raw);
    }
}
