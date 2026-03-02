<?php
/**
 * Post Syncer
 *
 * Transforms normalized social API posts into WordPress social_post CPT entries.
 * Handles create-or-update logic, media sideloading, and meta field storage.
 *
 * @package SocialPostsSync\Sync
 */

declare(strict_types=1);

namespace SocialPostsSync\Sync;

defined('ABSPATH') || exit;

use SocialPostsSync\CPT\SocialPostCPT;

/**
 * Syncs normalized social posts to the WordPress social_post CPT.
 */
class PostSyncer {

    /**
     * Sync a normalized social post to WordPress.
     *
     * Checks for an existing post by source_id. If found, updates it; otherwise creates it.
     *
     * @param array $normalized_post Normalized post data (see FacebookFeed / InstagramFeed).
     *
     * @return int WordPress post ID of the created or updated post.
     *
     * @throws \RuntimeException If the post could not be created or updated.
     */
    public function sync(array $normalized_post): int {
        $post_id = $this->findExistingPost($normalized_post['source_id']);

        [$title, $body] = $this->splitTitleAndBody($normalized_post);
        $post_date   = $this->normalizeDate($normalized_post['published_at'] ?? '');
        $post_status = 'publish';

        $post_data = [
            'post_type'     => SocialPostCPT::POST_TYPE,
            'post_title'    => $title,
            'post_name'     => sanitize_title(remove_accents($this->stripUnicode($title))),
            'post_content'  => wp_kses_post($body),
            'post_status'   => $post_status,
            'post_date'     => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ];

        if ($post_id) {
            $post_data['ID'] = $post_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            throw new \RuntimeException(
                esc_html('Failed to save social post: ' . $result->get_error_message())
            );
        }

        $post_id = (int) $result;

        // Store all meta fields
        $this->saveMeta($post_id, $normalized_post);

        // Assign platform taxonomy term
        $this->assignPlatformTerm($post_id, $normalized_post['platform'] ?? '');

        // Sideload media — must run after post exists so attachments get a parent
        $attachment_ids = $this->sideloadMedia($post_id, $normalized_post['media_urls'] ?? []);
        update_post_meta($post_id, SocialPostCPT::META_MEDIA_IDS, wp_json_encode($attachment_ids));

        if (!empty($attachment_ids)) {
            // First image → featured image
            set_post_thumbnail($post_id, $attachment_ids[0]);

            // Remaining images → gallery (exclude the featured image to avoid duplication)
            $gallery_ids = count($attachment_ids) > 1 ? array_slice($attachment_ids, 1) : [];
            update_post_meta($post_id, SocialPostCPT::META_GALLERY_IDS, implode(',', $gallery_ids));
        }

        return $post_id;
    }

    /**
     * Assign the correct scps_platform taxonomy term to a post.
     *
     * @param int    $post_id  Post ID.
     * @param string $platform 'facebook' or 'instagram'.
     */
    private function assignPlatformTerm(int $post_id, string $platform): void {
        $term_name = match (strtolower($platform)) {
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            default     => ucfirst($platform),
        };

        $term = get_term_by('name', $term_name, 'scps_platform');
        if (!$term) {
            $inserted = wp_insert_term($term_name, 'scps_platform');
            $term_id  = is_wp_error($inserted) ? 0 : (int) $inserted['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }

        if ($term_id) {
            wp_set_object_terms($post_id, $term_id, 'scps_platform');
        }
    }

    // -------------------------------------------------------------------------
    // Post Lookup
    // -------------------------------------------------------------------------

    /**
     * Find an existing social_post by its source_id meta.
     *
     * @param string $source_id Original post ID on the social platform.
     *
     * @return int|null Post ID if found, null otherwise.
     */
    private function findExistingPost(string $source_id): ?int {
        if (!$source_id) {
            return null;
        }

        $query = new \WP_Query([
            'post_type'      => SocialPostCPT::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => SocialPostCPT::META_SOURCE_ID,
                    'value' => $source_id,
                ],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $ids = $query->posts;
        return !empty($ids) ? (int) $ids[0] : null;
    }

    // -------------------------------------------------------------------------
    // Meta Saving
    // -------------------------------------------------------------------------

    /**
     * Save all normalized post meta fields to the given post.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Normalized post data.
     */
    private function saveMeta(int $post_id, array $data): void {
        update_post_meta($post_id, SocialPostCPT::META_PLATFORM,     sanitize_text_field($data['platform']      ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_SOURCE_ID,    sanitize_text_field($data['source_id']     ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_CONTENT,      sanitize_textarea_field($data['content']   ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_PERMALINK,    esc_url_raw($data['permalink']             ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_PUBLISHED_AT, sanitize_text_field($data['published_at']  ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_MEDIA_URLS,   wp_json_encode($data['media_urls']         ?? []));
        update_post_meta($post_id, SocialPostCPT::META_VIDEO_URL,    esc_url_raw($data['video_url']             ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_AUTHOR_NAME,  sanitize_text_field($data['author_name']   ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_AUTHOR_AVATAR, esc_url_raw($data['author_avatar']        ?? ''));
        update_post_meta($post_id, SocialPostCPT::META_LIKES_COUNT,  absint($data['likes_count']                ?? 0));
        update_post_meta($post_id, SocialPostCPT::META_RAW_DATA,     wp_json_encode($data['raw']                ?? []));
    }

    // -------------------------------------------------------------------------
    // Media Sideloading
    // -------------------------------------------------------------------------

    /**
     * Sideload an array of image/video URLs into the WordPress Media Library.
     *
     * Returns an array of attachment IDs for successfully sideloaded media.
     * Already-sideloaded media (detected by source URL meta) is skipped to prevent duplicates.
     *
     * @param int      $post_id   Parent post ID.
     * @param string[] $media_urls Array of remote media URLs.
     *
     * @return int[] Array of WP Media Library attachment IDs.
     */
    private function sideloadMedia(int $post_id, array $media_urls): array {
        if (empty($media_urls)) {
            return [];
        }

        // Always load admin media helpers — required in cron context too
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = [];

        foreach ($media_urls as $url) {
            $url = esc_url_raw((string) $url);
            if (!$url) {
                continue;
            }

            // Return existing attachment if already sideloaded
            $existing_id = $this->findAttachmentBySourceUrl($url);
            if ($existing_id) {
                $attachment_ids[] = $existing_id;
                continue;
            }

            // Sideload — returns attachment ID (int) on success
            $sideload_timeout = (int) get_option('scps_sideload_timeout', 30);
            $timeout_cb = static fn() => $sideload_timeout;
            add_filter('http_request_timeout', $timeout_cb, 99);
            $attachment_id = media_sideload_image($url, $post_id, '', 'id');
            remove_filter('http_request_timeout', $timeout_cb, 99);

            if (is_wp_error($attachment_id)) {
                // Media sideload failed — skip this URL silently in production
                continue;
            }

            $attachment_id = (int) $attachment_id;

            // Store source URL for deduplication on future syncs
            update_post_meta($attachment_id, '_scps_source_url', $url);

            // Ensure the attachment is attached to the parent post
            wp_update_post([
                'ID'          => $attachment_id,
                'post_parent' => $post_id,
            ]);

            $attachment_ids[] = $attachment_id;
        }

        return $attachment_ids;
    }

    /**
     * Find an attachment by its sideloaded source URL.
     *
     * @param string $url Source URL.
     *
     * @return int|null Attachment post ID if found, null otherwise.
     */
    private function findAttachmentBySourceUrl(string $url): ?int {
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_scps_source_url',
                    'value' => $url,
                ],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $ids = $query->posts;
        return !empty($ids) ? (int) $ids[0] : null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strip non-ASCII / Unicode fancy characters that survive remove_accents():
     * emojis, mathematical bold letters, Enclosed Alphanumerics, etc.
     * Keeps only basic Latin + extended Latin so sanitize_title() gets clean input.
     *
     * Strategy: use iconv transliteration if available, then strip anything
     * outside the Latin Unicode blocks (\x00-\x024F).
     *
     * @param string $value Raw string (UTF-8).
     *
     * @return string ASCII-safe string.
     */
    private function stripUnicode(string $value): string {
        // Normalize unicode (NFC → NFD decomposition strips combining marks)
        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($value, \Normalizer::FORM_D);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        // Transliterate to ASCII via iconv when available
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false && $ascii !== '') {
                return $ascii;
            }
        }

        // Fallback: remove any character outside printable ASCII range
        return preg_replace('/[^\x20-\x7E]/u', '', $value) ?? $value;
    }

    /**
     * Split a post's content into a title (first line) and body (remaining lines).
     *
     * - Title  = first non-empty line of content (trimmed).
     * - Body   = everything after the first line (trimmed), or empty string.
     * - If content is empty, falls back to a platform + date title with empty body.
     *
     * @param array $data Normalized post data.
     *
     * @return array{0: string, 1: string} [title, body]
     */
    private function splitTitleAndBody(array $data): array {
        $content  = trim($data['content'] ?? '');
        $platform = ucfirst(sanitize_text_field($data['platform'] ?? ''));
        $date     = '';

        if (!empty($data['published_at'])) {
            try {
                $dt   = new \DateTimeImmutable($data['published_at']);
                $date = $dt->format('d/m/Y');
            } catch (\Throwable) {}
        }

        if ($content) {
            // Split on the first newline (handles \r\n, \n, \r)
            $lines = preg_split('/\r\n|\r|\n/', $content, 2);
            $title = trim($lines[0] ?? '');
            $body  = isset($lines[1]) ? trim($lines[1]) : '';

            // If the first line is somehow empty (e.g. content starts with a blank line), use full content as title
            if ($title === '') {
                $title = mb_substr($content, 0, 80);
                $body  = '';
            }

            return [$title, $body];
        }

        // Fallback: no content
        if ($date) {
            /* translators: 1: Platform name (e.g. Facebook), 2: Publication date */
            $title = sprintf(__('Post %1$s du %2$s', 'social-posts-sync'), $platform, $date);
        } else {
            /* translators: %s: Platform name (e.g. Facebook) */
            $title = sprintf(__('Post %s', 'social-posts-sync'), $platform);
        }

        return [$title, ''];
    }

    /**
     * Convert an ISO 8601 date string to WordPress-compatible MySQL date format.
     *
     * @param string $iso_date ISO 8601 date string.
     *
     * @return string MySQL date string (Y-m-d H:i:s) in local time, or current time.
     */
    private function normalizeDate(string $iso_date): string {
        if (!$iso_date) {
            return current_time('mysql');
        }

        try {
            $dt = new \DateTimeImmutable($iso_date);
            // Convert to site timezone
            $timezone = new \DateTimeZone(wp_timezone_string());
            $dt = $dt->setTimezone($timezone);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return current_time('mysql');
        }
    }
}
