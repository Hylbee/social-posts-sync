<?php
/**
 * Token Storage
 *
 * Thin abstraction over WordPress options for encrypted token persistence.
 * Decouples consumers (e.g. FacebookFeed) from the MetaOAuth encryption
 * implementation so they can be tested independently.
 *
 * @package SocialPostsSync\Auth
 */

declare(strict_types=1);

namespace SocialPostsSync\Auth;

defined('ABSPATH') || exit;

use SocialPostsSync\Helpers\Encryption;

class TokenStorage {

    private Encryption $encryption;

    public function __construct(Encryption $encryption) {
        $this->encryption = $encryption;
    }

    /**
     * Retrieve and decrypt a single token stored under $option_key.
     *
     * @param string $option_key WordPress option name.
     *
     * @return string Decrypted token, or empty string if missing / decryption failed.
     */
    public function get(string $option_key): string {
        $encrypted = (string) get_option($option_key, '');
        if ($encrypted === '') {
            return '';
        }

        $decrypted = $this->encryption->decrypt($encrypted);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Encrypt and persist a single token under $option_key.
     *
     * @param string $option_key WordPress option name.
     * @param string $value      Plain-text token to store.
     */
    public function set(string $option_key, string $value): void {
        update_option($option_key, $this->encryption->encrypt($value));
    }

    /**
     * Retrieve all encrypted tokens from a map stored in $option_key and return
     * them decrypted, keyed by their original map key.
     *
     * @param string $option_key WordPress option name holding an array of encrypted values.
     *
     * @return array<string, string> Map of key → decrypted token (missing/failed entries omitted).
     */
    public function getAll(string $option_key): array {
        $stored = (array) get_option($option_key, []);
        $result = [];
        foreach ($stored as $key => $encrypted) {
            $decrypted = $this->encryption->decrypt((string) $encrypted);
            if ($decrypted !== false && $decrypted !== '') {
                $result[(string) $key] = $decrypted;
            }
        }
        return $result;
    }

    /**
     * Encrypt all tokens in $tokens and persist the map under $option_key,
     * merging with any existing entries.
     *
     * @param string                $option_key WordPress option name.
     * @param array<string, string> $tokens     Map of key → plain-text token.
     */
    public function setAll(string $option_key, array $tokens): void {
        $stored = (array) get_option($option_key, []);
        foreach ($tokens as $key => $value) {
            $stored[(string) $key] = $this->encryption->encrypt($value);
        }
        update_option($option_key, $stored);
    }
}
