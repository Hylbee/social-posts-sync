<?php
/**
 * Meta OAuth 2.0 Handler
 *
 * Manages the OAuth 2.0 authorization code flow for the Meta (Facebook/Instagram)
 * Graph API. Handles token storage (AES-256-CBC encrypted), long-lived token
 * exchange, and token expiry detection.
 *
 * @package SocialPostsSync\Auth
 */

declare(strict_types=1);

namespace SocialPostsSync\Auth;

defined('ABSPATH') || exit;

/**
 * Handles Meta OAuth 2.0 flow and token lifecycle management.
 *
 * Token storage:
 *  - scps_meta_access_token      → AES-256-CBC encrypted access token
 *  - scps_meta_token_expires_at  → Unix timestamp of token expiry
 *  - scps_meta_app_id            → Meta App ID (plain text, not secret)
 *  - scps_meta_app_secret        → AES-256-CBC encrypted App Secret
 *  - scps_meta_account_name      → Connected account name (display only)
 */
class MetaOAuth {

    /**
     * Meta OAuth 2.0 dialog URL.
     */
    private const DIALOG_URL = 'https://www.facebook.com/v21.0/dialog/oauth';

    /**
     * Meta token exchange endpoint.
     */
    private const TOKEN_URL = 'https://graph.facebook.com/v21.0/oauth/access_token';

    /**
     * Meta API endpoint to verify token and get user info.
     */
    private const ME_URL = 'https://graph.facebook.com/v21.0/me';

    /**
     * OAuth scopes required by the plugin.
     */
    private const SCOPES = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_read_user_content',
        'instagram_basic',
        'instagram_content_publish',
    ];

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
     * Generate the Meta OAuth authorization URL.
     *
     * The state parameter is a WordPress nonce bound to the current user to
     * prevent CSRF attacks.
     *
     * @return string Authorization URL to redirect the user to.
     */
    public function getAuthorizationUrl(): string {
        $state = wp_create_nonce('scps_oauth_state');

        return add_query_arg([
            'client_id'     => $this->getAppId(),
            'redirect_uri'  => urlencode($this->getRedirectUri()),
            'scope'         => implode(',', self::SCOPES),
            'response_type' => 'code',
            'state'         => $state,
        ], self::DIALOG_URL);
    }

    /**
     * Build the OAuth redirect URI (must be registered in your Meta App dashboard).
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
     * Handle the OAuth callback from Meta.
     *
     * - Validates the state nonce.
     * - Exchanges the authorization code for a short-lived token.
     * - Exchanges the short-lived token for a long-lived token.
     * - Stores the long-lived token.
     * - Redirects back to the settings page.
     */
    public function handleCallback(): void {
        // CSRF protection: verify state nonce
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        if (!wp_verify_nonce($state, 'scps_oauth_state')) {
            wp_die(
                esc_html__('Invalid OAuth state. Please try again.', 'social-posts-sync'),
                esc_html__('OAuth Error', 'social-posts-sync'),
                ['response' => 403]
            );
        }

        // Handle user-denied authorization
        if (isset($_GET['error'])) {
            $error_reason = sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error']));
            wp_redirect(add_query_arg(
                ['page' => 'social-posts-sync', 'scps_error' => urlencode($error_reason)],
                admin_url('options-general.php')
            ));
            exit;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (!$code) {
            wp_die(
                esc_html__('Missing authorization code.', 'social-posts-sync'),
                esc_html__('OAuth Error', 'social-posts-sync'),
                ['response' => 400]
            );
        }

        // Exchange code for short-lived token
        $short_lived = $this->exchangeCodeForToken($code);
        if (!$short_lived) {
            wp_redirect(add_query_arg(
                ['page' => 'social-posts-sync', 'scps_error' => urlencode('Token exchange failed.')],
                admin_url('options-general.php')
            ));
            exit;
        }

        // Exchange short-lived token for long-lived token
        $long_lived = $this->exchangeForLongLivedToken($short_lived);
        if (!$long_lived) {
            wp_redirect(add_query_arg(
                ['page' => 'social-posts-sync', 'scps_error' => urlencode('Long-lived token exchange failed.')],
                admin_url('options-general.php')
            ));
            exit;
        }

        // Store token and fetch account name
        $this->storeAccessToken($long_lived['token'], $long_lived['expires_in']);
        $this->fetchAndStoreAccountName($long_lived['token']);

        wp_redirect(add_query_arg(
            ['page' => 'social-posts-sync', 'scps_connected' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Token Exchange
    // -------------------------------------------------------------------------

    /**
     * Exchange an authorization code for a short-lived access token.
     *
     * @param string $code Authorization code from Meta.
     *
     * @return string|null Short-lived token, or null on failure.
     */
    private function exchangeCodeForToken(string $code): ?string {
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id'     => $this->getAppId(),
                'client_secret' => $this->getAppSecret(),
                'redirect_uri'  => $this->getRedirectUri(),
                'code'          => $code,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[SCPS OAuth] Code exchange failed: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $error_code = isset($body['error']['code']) ? (int) $body['error']['code'] : 0;
            $error_msg  = isset($body['error']['message']) ? sanitize_text_field($body['error']['message']) : 'unknown error';
            error_log('[SCPS OAuth] Code exchange error (code ' . $error_code . '): ' . $error_msg);
            return null;
        }

        return $body['access_token'];
    }

    /**
     * Exchange a short-lived token for a long-lived token (~60 days).
     *
     * @param string $short_token Short-lived access token.
     *
     * @return array|null Array with 'token' and 'expires_in', or null on failure.
     */
    private function exchangeForLongLivedToken(string $short_token): ?array {
        $response = wp_remote_get(add_query_arg([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->getAppId(),
            'client_secret'     => $this->getAppSecret(),
            'fb_exchange_token' => $short_token,
        ], self::TOKEN_URL));

        if (is_wp_error($response)) {
            error_log('[SCPS OAuth] Long-lived exchange failed: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            return null;
        }

        return [
            'token'      => $body['access_token'],
            'expires_in' => (int) ($body['expires_in'] ?? (60 * DAY_IN_SECONDS)),
        ];
    }

    /**
     * Attempt to refresh the current long-lived token.
     *
     * Meta long-lived tokens can be refreshed by exchanging them again.
     *
     * @return bool True on success, false on failure.
     */
    public function refreshToken(): bool {
        $current_token = $this->getAccessToken();
        if (!$current_token) {
            return false;
        }

        $refreshed = $this->exchangeForLongLivedToken($current_token);
        if (!$refreshed) {
            return false;
        }

        $this->storeAccessToken($refreshed['token'], $refreshed['expires_in']);
        return true;
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
     * Store the Meta App Secret, encrypted.
     *
     * @param string $secret Plain-text App Secret.
     */
    public function storeAppSecret(string $secret): void {
        update_option('scps_meta_app_secret', $this->encrypt($secret));
    }

    /**
     * Retrieve and decrypt the stored App Secret.
     *
     * @return string Plain-text App Secret.
     */
    public function getAppSecret(): string {
        $encrypted = get_option('scps_meta_app_secret', '');
        if (!$encrypted) {
            return '';
        }
        return $this->decrypt($encrypted) ?: '';
    }

    /**
     * Store the Meta App ID (not a secret, stored plain).
     *
     * @param string $app_id App ID.
     */
    public function storeAppId(string $app_id): void {
        update_option('scps_meta_app_id', sanitize_text_field($app_id));
    }

    /**
     * Get the stored Meta App ID.
     *
     * @return string App ID.
     */
    public function getAppId(): string {
        return (string) get_option('scps_meta_app_id', '');
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
    private function encrypt(string $value): string {
        if (empty($value)) {
            return '';
        }

        $key    = $this->getEncryptionKey();
        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv     = openssl_random_pseudo_bytes($iv_len);

        $encrypted = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if (false === $encrypted) {
            // Fallback: store as-is if encryption fails (should not happen)
            error_log('[SCPS] openssl_encrypt failed.');
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
    private function decrypt(string $encrypted): string|false {
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
        return substr(hash('sha256', AUTH_KEY, true), 0, 32);
    }
}
