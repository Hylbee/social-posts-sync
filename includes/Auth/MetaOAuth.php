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
 * @package SocialPostsSync\Auth
 */

declare(strict_types=1);

namespace SocialPostsSync\Auth;

defined('ABSPATH') || exit;

/**
 * Handles Meta OAuth 2.0 flow via the Social Posts Sync proxy server.
 */
class MetaOAuth {

    /**
     * Proxy base URL.
     */
    private const PROXY_BASE = 'https://social-proxy.hylbee.pro';

    /**
     * Meta API endpoint to verify token and get user info.
     */
    private const ME_URL = 'https://graph.facebook.com/v21.0/me';

    /**
     * Encryption cipher used for storing sensitive values.
     */
    private const CIPHER = 'AES-256-CBC';

    /**
     * Number of seconds before expiry at which the token is considered "expiring soon".
     */
    private const EXPIRY_THRESHOLD = 7 * DAY_IN_SECONDS;

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
        ], self::PROXY_BASE . '/auth/facebook');
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
        // Capability check — defence-in-depth (also verified by the caller)
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Accès non autorisé.', 'social-posts-sync'),
                '',
                ['response' => 403]
            );
        }

        // CSRF protection: verify state nonce
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        if (!wp_verify_nonce($state, 'scps_oauth_state')) {
            wp_die(
                esc_html__('Invalid OAuth state. Please try again.', 'social-posts-sync'),
                esc_html__('OAuth Error', 'social-posts-sync'),
                ['response' => 403]
            );
        }

        // Handle errors returned by the proxy
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

        // Store token and fetch account name
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
     * Calls GET /auth/facebook/refresh?access_token=TOKEN&back=URL
     * with the X-Licence-Key header.
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

        $response = wp_remote_get(add_query_arg([
            'access_token' => $access_token,
            'back'         => urlencode($back),
        ], self::PROXY_BASE . '/auth/facebook/refresh'), [
            'headers' => [
                'X-Licence-Key' => $licence_key,
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
     *
     * @param string $key Licence key to validate.
     *
     * @return bool True if valid, false otherwise.
     */
    public function validateLicenceKey(string $key): bool {
        if (!$key) {
            return false;
        }

        // Rate limiting: max 5 attempts per IP per 5 minutes
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
        ], self::PROXY_BASE . '/licence/validate'));

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
        update_option('scps_meta_access_token', $this->encrypt($token));
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
        $token = $this->decrypt($encrypted);
        return $token ?: null;
    }

    /**
     * Store the licence key, encrypted.
     *
     * @param string $key Plain-text licence key.
     */
    public function storeLicenceKey(string $key): void {
        update_option('scps_licence_key', $this->encrypt($key));
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
        return $this->decrypt($encrypted) ?: '';
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
    // Proxy Health
    // -------------------------------------------------------------------------

    /**
     * Probe the proxy server and cache the result for 5 minutes.
     *
     * Called at most once every 5 minutes (via transient) to avoid blocking
     * every admin page load. Returns true immediately if the cached result
     * is healthy.
     *
     * @return bool True if the proxy responded with HTTP 200, false otherwise.
     */
    public function checkProxyHealth(): bool {
        $cached = get_transient('scps_proxy_health');
        if ($cached !== false) {
            return (bool) $cached;
        }

        $response = wp_remote_get(self::PROXY_BASE . '/health', [
            'timeout'   => 5,
            'sslverify' => true,
        ]);

        $healthy = !is_wp_error($response)
            && wp_remote_retrieve_response_code($response) === 200;

        set_transient('scps_proxy_health', (int) $healthy, 5 * MINUTE_IN_SECONDS);

        return $healthy;
    }

    /**
     * Return the cached proxy health status without making a new HTTP request.
     *
     * If no cached value exists yet, triggers a fresh check.
     *
     * @return bool True if the proxy is (or was recently) reachable.
     */
    public function isProxyReachable(): bool {
        $cached = get_transient('scps_proxy_health');
        if ($cached !== false) {
            return (bool) $cached;
        }

        return $this->checkProxyHealth();
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
    // Encryption Helpers
    // -------------------------------------------------------------------------

    /**
     * Encrypt a string using AES-256-CBC with the WordPress AUTH_KEY as the key.
     *
     * @param string $value Plain-text value.
     *
     * @return string Base64-encoded ciphertext (iv:ciphertext).
     */
    public function encrypt(string $value): string {
        if (empty($value)) {
            return '';
        }

        $key    = $this->getEncryptionKey();
        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv     = openssl_random_pseudo_bytes($iv_len);

        $encrypted = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if (false === $encrypted) {
            return base64_encode($value);
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string that was encrypted with encrypt().
     *
     * @param string $encrypted Base64-encoded ciphertext (iv:ciphertext).
     *
     * @return string|false Plain-text value, or false on failure.
     */
    public function decrypt(string $encrypted): string|false {
        if (empty($encrypted)) {
            return false;
        }

        $key     = $this->getEncryptionKey();
        $decoded = base64_decode($encrypted, true);
        if (false === $decoded) {
            return false;
        }

        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv     = substr($decoded, 0, $iv_len);
        $data   = substr($decoded, $iv_len);

        $decrypted = openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted;
    }

    /**
     * Derive a 32-byte encryption key from the WordPress AUTH_KEY constant.
     *
     * @throws \RuntimeException If AUTH_KEY is not defined in wp-config.php.
     *
     * @return string 32-byte key.
     */
    private function getEncryptionKey(): string {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            throw new \RuntimeException(
                '[SCPS] AUTH_KEY is not defined in wp-config.php. Cannot encrypt/decrypt sensitive data.'
            );
        }

        $salt = (defined('SCPS_ENCRYPTION_SALT') && SCPS_ENCRYPTION_SALT !== '') ? SCPS_ENCRYPTION_SALT : '';
        return substr(hash('sha256', AUTH_KEY . $salt, true), 0, 32);
    }
}
