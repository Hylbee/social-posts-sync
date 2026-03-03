<?php
/**
 * Media Sideloader Helper
 *
 * Downloads remote media URLs and registers them as WordPress attachments.
 *
 * Strategy:
 *  - Already-sideloaded URLs (tracked via _scps_source_url post meta) are
 *    reused without re-downloading to prevent duplicates.
 *  - New URLs are downloaded via wp_remote_get() and registered as attachments
 *    using media_handle_sideload(). Multiple URLs are processed sequentially.
 *  - Downloaded files are validated against an allowed MIME type whitelist
 *    before being registered as WordPress attachments.
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class MediaSideloader {

    /**
     * Allowed MIME types for sideloaded media.
     * Files whose Content-Type is not in this list are rejected.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
    ];

    /**
     * Sideload an array of image URLs into the WordPress Media Library.
     *
     * @param int      $post_id    Parent post ID.
     * @param string[] $media_urls Remote media URLs to sideload.
     *
     * @return int[] Attachment IDs (existing + newly sideloaded).
     */
    public function sideload(int $post_id, array $media_urls): array {
        if (empty($media_urls)) {
            return [];
        }

        // Always load admin media helpers — required in cron context too
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = [];
        $to_download    = [];

        // First pass: reuse already-sideloaded URLs, collect new ones
        foreach ($media_urls as $raw_url) {
            $url = esc_url_raw((string) $raw_url);
            if (!$url) {
                continue;
            }

            $existing_id = $this->findBySourceUrl($url);
            if ($existing_id) {
                $attachment_ids[] = $existing_id;
            } else {
                $to_download[] = $url;
            }
        }

        if (empty($to_download)) {
            return $attachment_ids;
        }

        $timeout = (int) get_option('scps_sideload_timeout', 30);
        $new_ids = $this->downloadUrls($post_id, $to_download, $timeout);

        return array_merge($attachment_ids, $new_ids);
    }

    // -------------------------------------------------------------------------
    // Private — Download strategy
    // -------------------------------------------------------------------------

    /**
     * Download URLs via wp_remote_get() and register each as a WordPress attachment.
     *
     * @param int      $post_id Parent post ID.
     * @param string[] $urls    URLs to download.
     * @param int      $timeout Per-request timeout in seconds.
     *
     * @return int[] Attachment IDs for successfully downloaded media.
     */
    private function downloadUrls(int $post_id, array $urls, int $timeout): array {
        $attachment_ids = [];

        foreach ($urls as $url) {
            $id = $this->downloadSingle($url, $timeout, $post_id);
            if ($id) {
                $attachment_ids[] = $id;
            }
        }

        return $attachment_ids;
    }

    /**
     * Download a single URL via wp_remote_get() and register it as an attachment.
     *
     * @param string $url     Remote URL to download.
     * @param int    $timeout Request timeout in seconds.
     * @param int    $post_id Parent post ID.
     *
     * @return int|null Attachment ID on success, null on failure.
     */
    private function downloadSingle(string $url, int $timeout, int $post_id): ?int {
        $response = wp_remote_get($url, [
            'timeout'    => $timeout,
            'user-agent' => 'SocialPostsSync/' . SCPS_VERSION . ' WordPress/' . get_bloginfo('version'),
            'sslverify'  => true,
            'redirection' => 5,
        ]);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[SCPS] Media download failed for %s — %s', $url, $response->get_error_message()));
            }
            return null;
        }

        $http_code    = (int) wp_remote_retrieve_response_code($response);
        $content_type = strtolower(strtok((string) wp_remote_retrieve_header($response, 'content-type'), ';'));

        if ($http_code < 200 || $http_code >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[SCPS] Media sideload failed for %s — HTTP %d', $url, $http_code));
            }
            return null;
        }

        if (!in_array($content_type, self::ALLOWED_MIME_TYPES, true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[SCPS] Rejected media for %s — disallowed MIME type: %s', $url, $content_type));
            }
            return null;
        }

        // Write response body to a temp file and register as attachment
        $tmp = wp_tempnam($url);
        if (!$tmp) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (false === file_put_contents($tmp, $body)) {
            wp_delete_file($tmp);
            return null;
        }

        return $this->registerTempFile($tmp, $url, $post_id);
    }

    /**
     * Register a temp file downloaded via wp_remote_get as a WordPress attachment.
     *
     * @param string $tmp_path   Path to the temp file on disk.
     * @param string $source_url Original remote URL.
     * @param int    $post_id    Parent post ID.
     *
     * @return int|null Attachment ID on success, null on failure.
     */
    private function registerTempFile(string $tmp_path, string $source_url, int $post_id): ?int {
        $file_array = [
            'name'     => basename(wp_parse_url($source_url, PHP_URL_PATH) ?: $source_url),
            'tmp_name' => $tmp_path,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('[SCPS] media_handle_sideload failed for %s — %s', $source_url, $attachment_id->get_error_message()));
            }
            wp_delete_file($tmp_path);
            return null;
        }

        $attachment_id = (int) $attachment_id;
        update_post_meta($attachment_id, '_scps_source_url', $source_url);
        wp_update_post(['ID' => $attachment_id, 'post_parent' => $post_id]);

        return $attachment_id;
    }

    /**
     * Find an existing attachment by its sideloaded source URL.
     *
     * Uses a direct meta_key+meta_value lookup. The scps_meta_key_value index
     * created on activation ensures this query is covered by an index.
     *
     * @param string $url Source URL.
     *
     * @return int|null Attachment post ID if found, null otherwise.
     */
    private function findBySourceUrl(string $url): ?int {
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- covered by scps_meta_key_value index (meta_key, meta_value) created on activation
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
}
