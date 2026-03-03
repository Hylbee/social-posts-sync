<?php
/**
 * Encryption Helper
 *
 * AES-256-CBC encryption/decryption using the WordPress AUTH_KEY as the base
 * key material. An optional SCPS_ENCRYPTION_SALT constant can be defined in
 * wp-config.php to allow key rotation without changing AUTH_KEY.
 *
 * Uses an encrypt-then-MAC scheme (HMAC-SHA256) to detect ciphertext tampering.
 * Legacy values encrypted without HMAC (IV + ciphertext only) are still
 * decryptable via a backward-compatible fallback path.
 *
 * Used by MetaOAuth (access token, licence key) and TokenStorage (page tokens).
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class Encryption {

    private const CIPHER    = 'AES-256-CBC';
    private const HMAC_ALGO = 'sha256';
    private const HMAC_LEN  = 32; // bytes — fixed for SHA-256 raw output

    /**
     * Encrypt a plain-text string using AES-256-CBC with HMAC-SHA256 authentication.
     *
     * Storage format: base64( IV[16] + ciphertext + HMAC[32] )
     *
     * @param string $value Plain-text value.
     *
     * @return string Base64-encoded authenticated ciphertext, or empty string on empty input.
     *
     * @throws \RuntimeException If openssl_encrypt() fails.
     */
    public function encrypt(string $value): string {
        if ($value === '') {
            return '';
        }

        $key    = $this->deriveKey();
        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv     = openssl_random_pseudo_bytes($iv_len);

        $encrypted = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if (false === $encrypted) {
            throw new \RuntimeException(
                '[SCPS] openssl_encrypt() failed. Check that the OpenSSL extension is properly installed and AES-256-CBC is supported.'
            );
        }

        $mac = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $this->deriveHmacKey(), true);

        return base64_encode($iv . $encrypted . $mac);
    }

    /**
     * Decrypt a string previously encrypted with encrypt().
     *
     * Supports two formats for backward compatibility:
     *  - New: base64( IV[16] + ciphertext + HMAC[32] ) — HMAC verified before decryption.
     *  - Legacy: base64( IV[16] + ciphertext ) — decrypted directly (no HMAC).
     *
     * @param string $encrypted Base64-encoded ciphertext.
     *
     * @return string|false Plain-text value, or false on failure.
     */
    public function decrypt(string $encrypted): string|false {
        if ($encrypted === '') {
            return false;
        }

        $key     = $this->deriveKey();
        $decoded = base64_decode($encrypted, true);
        if (false === $decoded) {
            return false;
        }

        $iv_len      = openssl_cipher_iv_length(self::CIPHER);
        $decoded_len = strlen($decoded);

        // Attempt authenticated decryption (new format: IV + ciphertext + HMAC).
        // Minimum viable length: iv_len + at least 1 byte of ciphertext + hmac_len.
        if ($decoded_len > $iv_len + self::HMAC_LEN) {
            $iv         = substr($decoded, 0, $iv_len);
            $mac        = substr($decoded, -self::HMAC_LEN);
            $ciphertext = substr($decoded, $iv_len, $decoded_len - $iv_len - self::HMAC_LEN);

            $expected_mac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $this->deriveHmacKey(), true);

            if (hash_equals($expected_mac, $mac)) {
                return openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
            }
            // HMAC mismatch: fall through to legacy path so existing wp_options values
            // encrypted before this change was deployed continue to work.
        }

        // Legacy path: IV + ciphertext only (no HMAC).
        $iv   = substr($decoded, 0, $iv_len);
        $data = substr($decoded, $iv_len);

        return openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Derive a 32-byte AES key from AUTH_KEY (+ optional SCPS_ENCRYPTION_SALT).
     *
     * @throws \RuntimeException If AUTH_KEY is not defined.
     *
     * @return string 32-byte binary key.
     */
    private function deriveKey(): string {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            throw new \RuntimeException(
                '[SCPS] AUTH_KEY is not defined in wp-config.php. Cannot encrypt/decrypt sensitive data.'
            );
        }

        $salt = (defined('SCPS_ENCRYPTION_SALT') && SCPS_ENCRYPTION_SALT !== '') ? SCPS_ENCRYPTION_SALT : '';
        return substr(hash('sha256', AUTH_KEY . $salt, true), 0, 32);
    }

    /**
     * Derive a 32-byte HMAC key from AUTH_KEY, using a separate domain label.
     *
     * Using a distinct label ('scps_hmac_v1') ensures the HMAC key is different
     * from the AES key even when derived from the same secret material.
     *
     * @throws \RuntimeException If AUTH_KEY is not defined.
     *
     * @return string 32-byte binary key.
     */
    private function deriveHmacKey(): string {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            throw new \RuntimeException(
                '[SCPS] AUTH_KEY is not defined in wp-config.php. Cannot derive HMAC key.'
            );
        }

        $salt = (defined('SCPS_ENCRYPTION_SALT') && SCPS_ENCRYPTION_SALT !== '') ? SCPS_ENCRYPTION_SALT : '';
        return substr(hash('sha256', 'scps_hmac_v1' . AUTH_KEY . $salt, true), 0, 32);
    }
}
