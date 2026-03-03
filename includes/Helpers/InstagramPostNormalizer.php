<?php
/**
 * Instagram Post Normalizer Helper
 *
 * Converts raw Instagram Graph API media objects into the common normalized
 * social post shape used throughout the plugin.
 *
 * Extracted from InstagramFeed to isolate the normalization logic, making it
 * independently testable without needing an API client.
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

use SocialPostsSync\Api\MetaApiClient;

class InstagramPostNormalizer {

    /**
     * Fields to request for children of a CAROUSEL_ALBUM.
     */
    private const CHILDREN_FIELDS = 'media_url,media_type';

    private MetaApiClient $client;

    public function __construct(MetaApiClient $client) {
        $this->client = $client;
    }

    /**
     * Normalize a raw Instagram media object into the common social post shape.
     *
     * @param array $raw          Raw API media object.
     * @param array $account_info Account name and avatar: ['name', 'username', 'avatar'].
     *
     * @return array Normalized post.
     */
    public function normalize(array $raw, array $account_info): array {
        $media_type = (string) ($raw['media_type'] ?? '');
        $media_urls = [];
        $video_url  = '';

        if ('CAROUSEL_ALBUM' === $media_type) {
            $media_urls = $this->fetchCarouselChildren((string) ($raw['id'] ?? ''));
        } elseif ('VIDEO' === $media_type) {
            $video_url = (string) ($raw['media_url'] ?? '');
            if (!empty($raw['thumbnail_url'])) {
                $media_urls[] = (string) $raw['thumbnail_url'];
            }
        } elseif (!empty($raw['media_url'])) {
            $media_urls[] = (string) $raw['media_url'];
        }

        $published_at = '';
        if (!empty($raw['timestamp'])) {
            try {
                $dt           = new \DateTimeImmutable($raw['timestamp']);
                $published_at = $dt->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                $published_at = $raw['timestamp'];
            }
        }

        $normalized = [
            'platform'      => 'instagram',
            'source_id'     => (string) ($raw['id']        ?? ''),
            'content'       => (string) ($raw['caption']   ?? ''),
            'permalink'     => (string) ($raw['permalink'] ?? ''),
            'published_at'  => $published_at,
            'media_urls'    => $media_urls,
            'video_url'     => $video_url,
            'author_name'   => $account_info['name'],
            'author_avatar' => $account_info['avatar'],
            'likes_count'   => (int) ($raw['like_count'] ?? 0),
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

    /**
     * Fetch children media URLs for a CAROUSEL_ALBUM post.
     *
     * @param string $mediaId Instagram media ID.
     *
     * @return array Array of media URL strings.
     */
    public function fetchCarouselChildren(string $mediaId): array {
        try {
            $data = $this->client->get("/{$mediaId}/children", [
                'fields' => self::CHILDREN_FIELDS,
            ]);
        } catch (\Throwable $e) {
            unset($e);
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
}
