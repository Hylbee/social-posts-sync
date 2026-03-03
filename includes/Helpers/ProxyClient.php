<?php
/**
 * Proxy Client Helper
 *
 * Encapsulates all communication with the Social Posts Sync proxy server
 * (https://social-proxy.hylbee.pro), including health checks.
 * Result is cached in a WP transient for 5 minutes to avoid blocking
 * every admin page load.
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class ProxyClient {

    /**
     * Proxy base URL. Public so MetaOAuth can build endpoint URLs.
     */
    public const BASE_URL = 'https://social-proxy.hylbee.pro';

    private const HEALTH_TRANSIENT = 'scps_proxy_health';
    private const HEALTH_TTL       = 5 * MINUTE_IN_SECONDS;
    private const HEALTH_TIMEOUT   = 5;

    /**
     * Probe the proxy /health endpoint and cache the result for 5 minutes.
     *
     * @return bool True if the proxy responded with HTTP 200.
     */
    public function checkHealth(): bool {
        $response = wp_remote_get(self::BASE_URL . '/health', [
            'timeout'   => self::HEALTH_TIMEOUT,
            'sslverify' => true,
        ]);

        $healthy = !is_wp_error($response)
            && wp_remote_retrieve_response_code($response) === 200;

        set_transient(self::HEALTH_TRANSIENT, (int) $healthy, self::HEALTH_TTL);

        return $healthy;
    }

    /**
     * Return the cached proxy health status.
     * Triggers a fresh check only if no cached value exists yet.
     *
     * @return bool True if the proxy is (or was recently) reachable.
     */
    public function isReachable(): bool {
        $cached = get_transient(self::HEALTH_TRANSIENT);
        if ($cached !== false) {
            return (bool) $cached;
        }

        return $this->checkHealth();
    }
}
