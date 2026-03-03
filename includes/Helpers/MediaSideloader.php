<?php
/**
 * Media Sideloader Helper
 *
 * Downloads remote media URLs and registers them as WordPress attachments.
 *
 * Strategy:
 *  - Already-sideloaded URLs (tracked via _scps_source_url post meta) are
 *    reused without re-downloading to prevent duplicates.
 *  - New URLs are downloaded in parallel via curl_multi when available
 *    (multiple URLs) or sequentially via media_sideload_image as fallback.
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class MediaSideloader {

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

        $new_ids = (function_exists('curl_multi_init') && count($to_download) > 1)
            ? $this->downloadBatch($post_id, $to_download, $timeout)
            : $this->downloadSequential($post_id, $to_download, $timeout);

        return array_merge($attachment_ids, $new_ids);
    }

    // -------------------------------------------------------------------------
    // Private — Download strategies
    // -------------------------------------------------------------------------

    /**
     * Download multiple URLs in parallel using curl_multi.
     *
     * @param int      $post_id Parent post ID.
     * @param string[] $urls    URLs to download.
     * @param int      $timeout Per-request timeout in seconds.
     *
     * @return int[] Attachment IDs for successfully downloaded media.
     */
    private function downloadBatch(int $post_id, array $urls, int $timeout): array {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($urls as $i => $url) {
            $tmp = wp_tempnam($url);
            if (!$tmp) {
                continue;
            }

            $fh = fopen($tmp, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (!$fh) {
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => 'SocialPostsSync/' . SCPS_VERSION . ' WordPress/' . get_bloginfo('version'),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[$i] = ['ch' => $ch, 'fh' => $fh, 'tmp' => $tmp, 'url' => $url];
        }

        do {
            $status = curl_multi_exec($multi, $still_running);
            if ($still_running) {
                curl_multi_select($multi);
            }
        } while ($still_running && $status === CURLM_OK);

        $attachment_ids = [];

        foreach ($handles as $item) {
            $ch  = $item['ch'];
            $fh  = $item['fh'];
            $tmp = $item['tmp'];
            $url = $item['url'];

            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_errno($ch);

            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($curl_error !== 0 || $http_code < 200 || $http_code >= 300) {
                error_log(sprintf('[SCPS] Media sideload failed for %s — curl_errno=%d http_code=%d', $url, $curl_error, $http_code));
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                continue;
            }

            $attachment_id = $this->registerTempFile($tmp, $url, $post_id);
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
            }
        }

        curl_multi_close($multi);

        return $attachment_ids;
    }

    /**
     * Download URLs one by one via media_sideload_image (fallback).
     *
     * @param int      $post_id Parent post ID.
     * @param string[] $urls    URLs to download.
     * @param int      $timeout Per-request timeout in seconds.
     *
     * @return int[] Attachment IDs for successfully downloaded media.
     */
    private function downloadSequential(int $post_id, array $urls, int $timeout): array {
        $attachment_ids = [];
        $timeout_cb     = static fn() => $timeout;

        foreach ($urls as $url) {
            add_filter('http_request_timeout', $timeout_cb, 99);
            $attachment_id = media_sideload_image($url, $post_id, '', 'id');
            remove_filter('http_request_timeout', $timeout_cb, 99);

            if (is_wp_error($attachment_id)) {
                error_log(sprintf('[SCPS] media_sideload_image failed for %s — %s', $url, $attachment_id->get_error_message()));
                continue;
            }

            $attachment_id = (int) $attachment_id;
            update_post_meta($attachment_id, '_scps_source_url', $url);
            wp_update_post(['ID' => $attachment_id, 'post_parent' => $post_id]);

            $attachment_ids[] = $attachment_id;
        }

        return $attachment_ids;
    }

    /**
     * Register a temp file downloaded via curl as a WordPress attachment.
     *
     * @param string $tmp_path   Path to the temp file on disk.
     * @param string $source_url Original remote URL.
     * @param int    $post_id    Parent post ID.
     *
     * @return int|null Attachment ID on success, null on failure.
     */
    private function registerTempFile(string $tmp_path, string $source_url, int $post_id): ?int {
        $file_array = [
            'name'     => basename(parse_url($source_url, PHP_URL_PATH) ?: $source_url),
            'tmp_name' => $tmp_path,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            error_log(sprintf('[SCPS] media_handle_sideload failed for %s — %s', $source_url, $attachment_id->get_error_message()));
            @unlink($tmp_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
     * @param string $url Source URL.
     *
     * @return int|null Attachment post ID if found, null otherwise.
     */
    private function findBySourceUrl(string $url): ?int {
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
}
