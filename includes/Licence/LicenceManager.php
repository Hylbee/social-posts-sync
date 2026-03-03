<?php
/**
 * Licence Manager
 *
 * Handles all licence key operations: validation, storage (encrypted),
 * retrieval, and revocation via the proxy API.
 *
 * Storage:
 *  - scps_licence_key → AES-256-CBC encrypted licence key
 *
 * @package SocialPostsSync\Licence
 */

declare(strict_types=1);

namespace SocialPostsSync\Licence;

defined('ABSPATH') || exit;

use SocialPostsSync\Helpers\Encryption;
use SocialPostsSync\Helpers\ProxyClient;

/**
 * Manages the plugin licence key: validation, storage and revocation.
 */
class LicenceManager {

    private Encryption $encryption;

    public function __construct(?Encryption $encryption = null) {
        $this->encryption = $encryption ?? new Encryption();
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validate a licence key against the proxy server.
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
    // Storage (Encrypted)
    // -------------------------------------------------------------------------

    /**
     * Store the licence key, encrypted with AES-256-CBC.
     *
     * @param string $key Plain-text licence key.
     */
    public function storeLicenceKey(string $key): void {
        update_option('scps_licence_key', $this->encryption->encrypt($key));
    }

    /**
     * Retrieve and decrypt the stored licence key.
     *
     * @return string Plain-text licence key, or empty string if not set.
     */
    public function getLicenceKey(): string {
        $encrypted = get_option('scps_licence_key', '');
        if (!$encrypted) {
            return '';
        }
        return $this->encryption->decrypt($encrypted) ?: '';
    }

    /**
     * Delete the locally stored licence key.
     */
    public function deleteLicenceKey(): void {
        delete_option('scps_licence_key');
    }

    // -------------------------------------------------------------------------
    // Revocation
    // -------------------------------------------------------------------------

    /**
     * Revoke the licence on the proxy server and delete the local key.
     *
     * Calls POST /licence/revoke with licence_key and domain.
     * Deletes the local licence key regardless of the API response.
     *
     * @return bool True if the proxy confirmed revocation, false on error.
     *              Returns true if no licence key is stored (nothing to revoke).
     */
    public function revokeLicence(): bool {
        $key = $this->getLicenceKey();

        if (!$key) {
            return true;
        }

        $response = wp_remote_post(ProxyClient::BASE_URL . '/licence/revoke', [
            'headers' => [
                'Accept'           => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
                'Content-Type'     => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'licence_key' => $key,
                'domain'      => wp_parse_url(home_url(), PHP_URL_HOST),
            ],
            'timeout'   => 10,
            'sslverify' => true,
        ]);

        // Always delete the local key, even if the API call fails
        $this->deleteLicenceKey();

        if (is_wp_error($response)) {
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = json_decode(wp_remote_retrieve_body($response), true);

        return $http_code === 200 && !empty($body['success']);
    }
}
