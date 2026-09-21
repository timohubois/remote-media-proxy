<?php

namespace RemoteMediaProxy\Helpers;

defined('ABSPATH') || exit;

/** Encrypt saved credentials using authenticated, site-bound keys outside the database. */
final class PasswordEncryption
{
    /**
     * Encrypt a password with a fresh nonce and the current site's derived key.
     *
     * @param string $password Plaintext credential to protect.
     * @return array{version:1,ciphertext:string} Versioned ciphertext containing its nonce.
     * @throws \RuntimeException If a usable encryption key is unavailable.
     * @throws \Random\RandomException If secure nonce generation fails.
     * @throws \SodiumException If encryption fails.
     */
    public static function encrypt(string $password): array
    {
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException('Password encryption is unavailable.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return [
            'version' => 1,
            'ciphertext' => base64_encode($nonce . sodium_crypto_secretbox($password, $nonce, $key)),
        ];
    }

    /**
     * Authenticate and decrypt a saved password without accepting unknown storage formats.
     *
     * @param mixed $stored Stored option value, potentially malformed or absent.
     * @return string|null Plaintext, an empty string for no saved password, or null for unreadable storage.
     */
    public static function decrypt(mixed $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        if (!is_array($stored) || ($stored['version'] ?? null) !== 1 || !is_string($stored['ciphertext'] ?? null)) {
            return null; // Reject unrecognized storage formats.
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $data = base64_decode($stored['ciphertext'], true);
        if ($data === false || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        try {
            $password = sodium_crypto_secretbox_open(
                substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key
            );
            return $password === false ? null : $password;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Derive a site-bound key from WordPress configuration, never database-backed salt fallbacks.
     *
     * @return string|null Binary encryption key, or null when prerequisites are unavailable.
     */
    private static function key(): ?string
    {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        // Do not use wp_salt(): it can fall back to keys stored in the same database.
        foreach (['AUTH_KEY', 'AUTH_SALT'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || strlen(constant($name)) < 32) {
                return null;
            }
        }
        return hash_hkdf(
            'sha256',
            \AUTH_KEY,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'remote-media-proxy:password:v1:site:' . get_current_blog_id(),
            \AUTH_SALT
        );
    }
}
