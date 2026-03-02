<?php
/**
 * Meta Graph API Client
 *
 * Generic wrapper for all Meta Graph API requests with caching and rate-limit awareness.
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

/**
 * Generic HTTP client for Meta Graph API v21.0.
 *
 * Features:
 * - WP Transient-based caching with configurable TTL
 * - Rate-limit back-off (error codes 4 and 17)
 * - Automatic access token injection
 */
class MetaApiClient {

    /**
     * Base URL for the Meta Graph API.
     */
    private const BASE_URL = 'https://graph.facebook.com/v21.0';

    /**
     * Default cache TTL in seconds (15 minutes).
     */
    private const DEFAULT_CACHE_TTL = 900;

    /**
     * Option key for storing the rate-limit back-off timestamp.
     */
    private const BACKOFF_OPTION = 'scps_api_backoff_until';

    /**
     * Rate-limit error codes that trigger back-off.
     */
    private const RATE_LIMIT_CODES = [4, 17];

    /**
     * Back-off base duration in seconds (15 minutes).
     */
    private const BACKOFF_BASE = 900;

    /**
     * Maximum back-off duration in seconds (4 hours).
     */
    private const BACKOFF_MAX = 14400;

    /**
     * Option key storing the consecutive rate-limit hit count (for exponential back-off).
     */
    private const BACKOFF_COUNT_OPTION = 'scps_api_backoff_count';

    private string $access_token;
    private int    $cache_ttl;

    /**
     * @param string $access_token Meta Graph API access token.
     * @param int    $cache_ttl    Cache TTL in seconds. Default 15 minutes.
     */
    public function __construct(string $access_token, int $cache_ttl = self::DEFAULT_CACHE_TTL) {
        $this->access_token = $access_token;
        $this->cache_ttl    = $cache_ttl;
    }

    /**
     * Perform a GET request to the Meta Graph API.
     *
     * @param string $endpoint Endpoint path, e.g. '/me/accounts'.
     * @param array  $params   Query parameters (excluding access_token).
     *
     * @return array Decoded JSON response.
     *
     * @throws MetaApiException On API error or rate limit.
     * @throws \RuntimeException On HTTP / network error.
     */
    public function get(string $endpoint, array $params = []): array {
        if ($this->isBackingOff()) {
            $until = (int) get_option(self::BACKOFF_OPTION, 0);
            throw new MetaApiException(
                esc_html(sprintf('Rate limit active. Retry after %s.', gmdate('Y-m-d H:i:s', $until))),
                429
            );
        }

        $cache_key = $this->buildCacheKey($endpoint, $params);
        $cached    = get_transient($cache_key);
        if (false !== $cached && is_array($cached)) {
            return $cached;
        }

        $url = $this->buildUrl($endpoint, $params);

        $response = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'SocialPostsSync/' . SCPS_VERSION . ' WordPress/' . get_bloginfo('version'),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                esc_html('HTTP request failed: ' . $response->get_error_message())
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from Meta API.');
        }

        if (isset($data['error'])) {
            $this->handleApiError($data['error']);
        }

        set_transient($cache_key, $data, $this->cache_ttl);

        return $data;
    }

    /**
     * Build the full request URL with query parameters.
     */
    private function buildUrl(string $endpoint, array $params): string {
        // Ensure endpoint starts with /
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        $params['access_token'] = $this->access_token;

        return self::BASE_URL . $endpoint . '?' . http_build_query($params);
    }

    /**
     * Build a deterministic cache key for a request.
     */
    private function buildCacheKey(string $endpoint, array $params): string {
        ksort($params);
        return 'scps_api_' . md5($endpoint . serialize($params));
    }

    /**
     * Handle an API error object returned by Meta.
     *
     * @throws MetaApiException Always.
     */
    private function handleApiError(array $error): void {
        $code       = (int)    ($error['code']       ?? 0);
        $type       = (string) ($error['type']       ?? '');
        $message    = (string) ($error['message']    ?? 'Unknown API error');
        $fbtrace_id = (string) ($error['fbtrace_id'] ?? '');

        // Trigger exponential back-off for rate limit errors
        if (in_array($code, self::RATE_LIMIT_CODES, true)) {
            $count    = (int) get_option(self::BACKOFF_COUNT_OPTION, 0) + 1;
            $duration = min(self::BACKOFF_BASE * (2 ** ($count - 1)), self::BACKOFF_MAX);
            update_option(self::BACKOFF_COUNT_OPTION, $count);
            update_option(self::BACKOFF_OPTION, time() + $duration);
        }

        throw new MetaApiException(esc_html($message), $code, esc_html($type), esc_html($fbtrace_id));
    }

    /**
     * Check whether the client is currently in a rate-limit back-off period.
     */
    private function isBackingOff(): bool {
        $until = (int) get_option(self::BACKOFF_OPTION, 0);
        if ($until > 0 && time() < $until) {
            return true;
        }
        // Clear expired back-off and reset consecutive count
        if ($until > 0) {
            delete_option(self::BACKOFF_OPTION);
            delete_option(self::BACKOFF_COUNT_OPTION);
        }
        return false;
    }

    /**
     * Invalidate the transient cache for a specific endpoint / params combination.
     */
    public function invalidateCache(string $endpoint, array $params = []): void {
        $cache_key = $this->buildCacheKey($endpoint, $params);
        delete_transient($cache_key);
    }
}
