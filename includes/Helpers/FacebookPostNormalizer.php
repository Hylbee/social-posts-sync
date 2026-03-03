<?php
/**
 * Facebook Post Normalizer Helper
 *
 * Converts raw Facebook Graph API post objects into the common normalized
 * social post shape used throughout the plugin.
 *
 * Extracted from FacebookFeed to isolate the normalization logic, making it
 * independently testable without needing an API client.
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class FacebookPostNormalizer {

    /**
     * Normalize a raw Facebook post into the common social post shape.
     *
     * @param array $raw       Raw API post object.
     * @param array $page_info Page name and avatar: ['name' => string, 'avatar' => string].
     *
     * @return array Normalized post.
     */
    public function normalize(array $raw, array $page_info): array {
        [$media_urls, $video_url] = $this->extractMedia($raw);

        // Always include full_picture as thumbnail (works for both images and video posts).
        // Compare without query-string parameters to avoid duplicates caused by differing
        // session tokens in Meta CDN URLs (e.g. ?oh=...&oe=...).
        if (!empty($raw['full_picture'])) {
            $full_pic_base   = strtok($raw['full_picture'], '?');
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
                $dt           = new \DateTimeImmutable($raw['created_time']);
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
    public function extractMedia(array $raw): array {
        $image_urls = [];
        $video_url  = '';

        $attachments = $raw['attachments']['data'] ?? [];
        foreach ($attachments as $attachment) {
            $media_type = strtolower((string) ($attachment['media_type'] ?? ''));

            if ($media_type === 'video') {
                if (!$video_url) {
                    $video_url = $this->extractVideoUrl($attachment['media'] ?? []);
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
                        if (!$video_url) {
                            $video_url = $this->extractVideoUrl($sub['media'] ?? []);
                        }
                        continue;
                    }
                    $sub_src = $sub['media']['image']['src'] ?? null;
                    if ($sub_src) {
                        $image_urls[] = (string) $sub_src;
                    }
                }
            } else {
                $src = $attachment['media']['image']['src'] ?? null;
                if ($src) {
                    $image_urls[] = (string) $src;
                }
            }
        }

        // Deduplicate by base URL (without query-string) to handle Meta CDN session tokens
        $seen_bases  = [];
        $unique_urls = [];
        foreach ($image_urls as $url) {
            $base = strtok($url, '?');
            if (!isset($seen_bases[$base])) {
                $seen_bases[$base] = true;
                $unique_urls[]     = $url;
            }
        }

        return [$unique_urls, $video_url];
    }

    /**
     * Extract the video source URL from a media data array.
     *
     * @param array $media_data The 'media' sub-array of an attachment or subattachment.
     *
     * @return string Video source URL, or empty string if not present.
     */
    private function extractVideoUrl(array $media_data): string {
        return (string) ($media_data['source'] ?? '');
    }
}
