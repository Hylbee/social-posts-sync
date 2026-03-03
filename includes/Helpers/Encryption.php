<?php
/**
 * Encryption Helper
 *
 * AES-256-CBC encryption/decryption using the WordPress AUTH_KEY as the base
 * key material. An optional SCPS_ENCRYPTION_SALT constant can be defined in
 * wp-config.php to allow key rotation without changing AUTH_KEY.
 *
 * Used by MetaOAuth (access token, licence key) and TokenStorage (page tokens).
 *
 * @package SocialPostsSync\Helpers
 */

declare(strict_types=1);

namespace SocialPostsSync\Helpers;

defined('ABSPATH') || exit;

class Encryption {

    private const CIPHER = 'AES-256-CBC';

    /**
     * Encrypt a plain-text string using AES-256-CBC.
     *
     * @param string $value Plain-text value.
     *
     * @return string Base64-encoded ciphertext (IV prepended), or empty string on empty input.
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

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string previously encrypted with encrypt().
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

        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv     = substr($decoded, 0, $iv_len);
        $data   = substr($decoded, $iv_len);

        return openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Derive a 32-byte key from AUTH_KEY (+ optional SCPS_ENCRYPTION_SALT).
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
}
