<?php
/**
 * Meta OAuth 2.0 Handler — Proxy Mode
 *
 * Delegates the OAuth 2.0 flow to a centralized proxy server
 * (https://social-proxy.hylbee.pro). The proxy handles the Meta App
 * credentials; WordPress only stores the resulting long-lived access token
 * and the user's licence key.
 *
 * Token storage:
 *  - scps_meta_access_token      → AES-256-CBC encrypted access token
 *  - scps_meta_token_expires_at  → Unix timestamp of token expiry
 *  - scps_licence_key            → AES-256-CBC encrypted licence key
 *  - scps_meta_account_name      → Connected account name (display only)
 *
 * Encryption is delegated to Helpers\Encryption.
 * Proxy health checks are delegated to Helpers\ProxyClient.
 *
 * @package SocialPostsSync\Auth
 */

declare(strict_types=1);

namespace SocialPostsSync\Auth;

defined('ABSPATH') || exit;

use SocialPostsSync\Helpers\Encryption;
use SocialPostsSync\Helpers\ProxyClient;

/**
 * Handles Meta OAuth 2.0 flow via the Social Posts Sync proxy server.
 */
class MetaOAuth {

    /**
     * Meta API endpoint to verify token and get user info.
     */
    private const ME_URL = 'https://graph.facebook.com/v21.0/me';

    /**
     * Number of seconds before expiry at which the token is considered "expiring soon".
     */
    private const EXPIRY_THRESHOLD = 7 * DAY_IN_SECONDS;

    private Encryption  $encryption;
    private ProxyClient $proxy;

    public function __construct(?Encryption $encryption = null, ?ProxyClient $proxy = null) {
        $this->encryption = $encryption ?? new Encryption();
        $this->proxy      = $proxy      ?? new ProxyClient();
    }

    // -------------------------------------------------------------------------
    // Authorization URL
    // -------------------------------------------------------------------------

    /**
     * Generate the proxy OAuth authorization URL.
     *
     * The state nonce is embedded in the `back` redirect URL so it survives
     * the proxy round-trip and can be verified on callback.
     *
     * @return string Authorization URL to redirect the user to.
     */
    public function getAuthorizationUrl(): string {
        $state = wp_create_nonce('scps_oauth_state');

        $back = add_query_arg([
            'scps_oauth_callback' => '1',
            'state'               => $state,
        ], admin_url('options-general.php?page=social-posts-sync'));

        return add_query_arg([
            'back' => urlencode($back),
        ], ProxyClient::BASE_URL . '/auth/facebook');
    }

    /**
     * Build the OAuth redirect URI (the URL the proxy will redirect back to).
     *
     * @return string Redirect URI.
     */
    public function getRedirectUri(): string {
        return add_query_arg(
            ['scps_oauth_callback' => '1'],
            admin_url('options-general.php?page=social-posts-sync')
        );
    }

    // -------------------------------------------------------------------------
    // Callback Handling
    // -------------------------------------------------------------------------

    /**
     * Handle the OAuth callback from the proxy.
     *
     * The proxy redirects back with:
     *   ?facebook_access_token=EAAxx...&expires_in=5183944&state=<nonce>
     *
     * - Validates the state nonce (CSRF protection).
     * - Reads the long-lived access token directly from query string.
     * - Stores the token and fetches the account name.
     * - Redirects back to the settings page.
     */
    public function handleCallback(): void {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Accès non autorisé.', 'social-posts-sync'),
                '',
                ['response' => 403]
            );
        }

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        if (!wp_verify_nonce($state, 'scps_oauth_state')) {
            wp_die(
                esc_html__('Invalid OAuth state. Please try again.', 'social-posts-sync'),
                esc_html__('OAuth Error', 'social-posts-sync'),
                ['response' => 403]
            );
        }

        if (isset($_GET['error'])) {
            $error_reason = sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error']));
            wp_safe_redirect(add_query_arg(
                ['page' => 'social-posts-sync', 'scps_error' => urlencode($error_reason)],
                admin_url('options-general.php')
            ));
            exit;
        }

        $token = sanitize_text_field(wp_unslash($_GET['facebook_access_token'] ?? ''));
        if (!$token) {
            wp_die(
                esc_html__('Missing access token in callback.', 'social-posts-sync'),
                esc_html__('OAuth Error', 'social-posts-sync'),
                ['response' => 400]
            );
        }

        $expires_in = absint($_GET['expires_in'] ?? (60 * DAY_IN_SECONDS));

        $this->storeAccessToken($token, $expires_in);
        $this->fetchAndStoreAccountName($token);

        wp_safe_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'scps_connected' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Token Refresh
    // -------------------------------------------------------------------------

    /**
     * Refresh the current access token via the proxy.
     *
     * Calls POST /auth/facebook/refresh with access_token and back in the
     * request body (application/x-www-form-urlencoded) and the licence key
     * in the X-Licence-Key header.
     * Expects JSON: { "facebook_access_token": "...", "expires_in": ... }
     *
     * @return bool True on success, false on failure.
     */
    public function refreshToken(): bool {
        $licence_key  = $this->getLicenceKey();
        $access_token = $this->getAccessToken();

        if (!$licence_key || !$access_token) {
            return false;
        }

        $back = add_query_arg(
            ['scps_oauth_callback' => '1'],
            admin_url('options-general.php?page=social-posts-sync')
        );

        $response = wp_remote_post(ProxyClient::BASE_URL . '/auth/facebook/refresh', [
            'headers' => [
                'X-Licence-Key' => $licence_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'access_token' => $access_token,
                'back'         => $back,
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['facebook_access_token'])) {
            return false;
        }

        $expires_in = absint($body['expires_in'] ?? (60 * DAY_IN_SECONDS));
        $this->storeAccessToken($body['facebook_access_token'], $expires_in);

        return true;
    }

    /**
     * Validate the licence key against the proxy.
     *
     * Calls GET /licence/validate?licence_key=KEY&domain=DOMAIN
     * Rate limited to max 5 attempts per IP per 5 minutes.
     *
     * @param string $key Licence key to validate.
     *
     * @return bool True if valid, false otherwise.
     */
    public function validateLicenceKey(string $key): bool {
        if (!$key) {
            return false;
        }

        $ip            = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $transient_key = 'scps_licence_attempts_' . md5($ip);
        $attempts      = (int) get_transient($transient_key);
        if ($attempts >= 5) {
            return false;
        }
        set_transient($transient_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);

        $response = wp_remote_get(add_query_arg([
            'licence_key' => $key,
            'domain'      => wp_parse_url(home_url(), PHP_URL_HOST),
        ], ProxyClient::BASE_URL . '/licence/validate'));

        if (is_wp_error($response)) {
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = json_decode(wp_remote_retrieve_body($response), true);

        return $http_code === 200 && !empty($body['success']);
    }

    // -------------------------------------------------------------------------
    // Token Storage (Encrypted)
    // -------------------------------------------------------------------------

    /**
     * Store an access token, encrypted with AES-256-CBC.
     *
     * @param string $token      Plain-text access token.
     * @param int    $expires_in Seconds until the token expires.
     */
    private function storeAccessToken(string $token, int $expires_in): void {
        update_option('scps_meta_access_token', $this->encryption->encrypt($token));
        update_option('scps_meta_token_expires_at', time() + $expires_in);
    }

    /**
     * Retrieve and decrypt the stored access token.
     *
     * @return string|null Plain-text token, or null if not set.
     */
    public function getAccessToken(): ?string {
        $encrypted = get_option('scps_meta_access_token', '');
        if (!$encrypted) {
            return null;
        }
        $token = $this->encryption->decrypt($encrypted);
        return $token ?: null;
    }

    /**
     * Store the licence key, encrypted.
     *
     * @param string $key Plain-text licence key.
     */
    public function storeLicenceKey(string $key): void {
        update_option('scps_licence_key', $this->encryption->encrypt($key));
    }

    /**
     * Retrieve and decrypt the stored licence key.
     *
     * @return string Plain-text licence key.
     */
    public function getLicenceKey(): string {
        $encrypted = get_option('scps_licence_key', '');
        if (!$encrypted) {
            return '';
        }
        return $this->encryption->decrypt($encrypted) ?: '';
    }

    /**
     * Disconnect: remove stored token and account name.
     */
    public function disconnect(): void {
        delete_option('scps_meta_access_token');
        delete_option('scps_meta_token_expires_at');
        delete_option('scps_meta_account_name');
    }

    // -------------------------------------------------------------------------
    // Proxy Health — delegated to ProxyClient
    // -------------------------------------------------------------------------

    /**
     * Probe the proxy server and cache the result for 5 minutes.
     *
     * @return bool True if the proxy responded with HTTP 200.
     */
    public function checkProxyHealth(): bool {
        return $this->proxy->checkHealth();
    }

    /**
     * Return the cached proxy health status without making a new HTTP request.
     *
     * @return bool True if the proxy is (or was recently) reachable.
     */
    public function isProxyReachable(): bool {
        return $this->proxy->isReachable();
    }

    // -------------------------------------------------------------------------
    // Token Status Checks
    // -------------------------------------------------------------------------

    /**
     * Check whether the stored token is expiring within 7 days.
     *
     * @return bool True if expiring soon, false otherwise.
     */
    public function isTokenExpiring(): bool {
        $expires_at = (int) get_option('scps_meta_token_expires_at', 0);
        if (!$expires_at) {
            return false;
        }
        return (time() + self::EXPIRY_THRESHOLD) >= $expires_at;
    }

    /**
     * Check whether a valid, non-expired access token is stored.
     *
     * @return bool True if connected.
     */
    public function isConnected(): bool {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $expires_at = (int) get_option('scps_meta_token_expires_at', 0);
        if ($expires_at && time() >= $expires_at) {
            return false;
        }

        return true;
    }

    /**
     * Get token expiry as a formatted date string.
     *
     * @return string Formatted date, or empty string if not set.
     */
    public function getTokenExpiryDate(): string {
        $expires_at = (int) get_option('scps_meta_token_expires_at', 0);
        if (!$expires_at) {
            return '';
        }
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expires_at);
    }

    /**
     * Get the connected account name.
     *
     * @return string Account name, or empty string.
     */
    public function getAccountName(): string {
        return (string) get_option('scps_meta_account_name', '');
    }

    // -------------------------------------------------------------------------
    // Account Info
    // -------------------------------------------------------------------------

    /**
     * Fetch the authenticated user's name from the Graph API and store it.
     *
     * @param string $token Access token.
     */
    private function fetchAndStoreAccountName(string $token): void {
        $response = wp_remote_get(add_query_arg([
            'access_token' => $token,
            'fields'       => 'name',
        ], self::ME_URL));

        if (is_wp_error($response)) {
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['name'])) {
            update_option('scps_meta_account_name', sanitize_text_field($body['name']));
        }
    }

    // -------------------------------------------------------------------------
    // Encryption passthrough — kept for backward compatibility with TokenStorage
    // -------------------------------------------------------------------------

    /**
     * Encrypt a value. Delegates to Helpers\Encryption.
     *
     * @param string $value Plain-text value.
     *
     * @return string Encrypted value.
     */
    public function encrypt(string $value): string {
        return $this->encryption->encrypt($value);
    }

    /**
     * Decrypt a value. Delegates to Helpers\Encryption.
     *
     * @param string $encrypted Encrypted value.
     *
     * @return string|false Plain-text value, or false on failure.
     */
    public function decrypt(string $encrypted): string|false {
        return $this->encryption->decrypt($encrypted);
    }
}
