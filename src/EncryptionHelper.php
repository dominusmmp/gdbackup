<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

/**
 * Provides secure encryption and decryption functionalities using AES-256-CBC.
 */
class EncryptionHelper
{
    /**
     * Encrypts data using AES-256-CBC with a derived key from password and key.
     *
     * @param string $text The plaintext to encrypt.
     * @param string $password The password for key derivation.
     * @param string $key The key/UUID for key derivation.
     * @return string The encrypted data (IV + ciphertext).
     * @throws InvalidArgumentException If required arguments are missing or empty.
     * @throws RuntimeException If encryption fails.
     */
    public function encryptData(string $text, string $password, string $key): string
    {
        $missingArgs = array_filter([
            empty($text) ? 'text' : null,
            empty($password) ? 'password' : null,
            empty($key) ? 'key' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(
                sprintf('[EncryptionHelper::encryptData] Missing or empty required arguments: %s', implode(', ', $missingArgs))
            );
        }

        $derivedKey = hash_pbkdf2(
            'sha256',
            $password,
            base64_encode($key),
            100000,
            32,
            true
        );

        try {
            $iv = random_bytes(16);
        } catch (Exception $e) {
            throw new RuntimeException('[EncryptionHelper::encryptData] Failed to generate IV: ' . $e->getMessage());
        }

        $encrypted = openssl_encrypt(
            $text,
            'aes-256-cbc',
            $derivedKey,
            0,
            $iv
        );

        if ($encrypted === false) {
            throw new RuntimeException('[EncryptionHelper::encryptData] Encryption failed: ' . openssl_error_string());
        }

        return $iv . $encrypted;
    }

    /**
     * Decrypts data encrypted with AES-256-CBC.
     *
     * @param string $text The encrypted data (IV + ciphertext).
     * @param string $password The password used for key derivation.
     * @param string $key The key/UUID used for key derivation.
     * @return string The decrypted plaintext.
     * @throws InvalidArgumentException If required arguments are missing or empty.
     * @throws RuntimeException If decryption fails or IV is invalid.
     */
    public function decryptData(string $text, string $password, string $key): string
    {
        $missingArgs = array_filter([
            empty($text) ? 'text' : null,
            empty($password) ? 'password' : null,
            empty($key) ? 'key' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(
                sprintf('[EncryptionHelper::decryptData] Missing or empty required arguments: %s', implode(', ', $missingArgs))
            );
        }

        if (strlen($text) < 16) {
            throw new RuntimeException('[EncryptionHelper::decryptData] Invalid encrypted data: too short.');
        }

        $iv = substr($text, 0, 16);
        $encrypted = substr($text, 16);

        if (strlen($iv) !== 16) {
            throw new RuntimeException('[EncryptionHelper::decryptData] Invalid IV length.');
        }

        $derivedKey = hash_pbkdf2(
            'sha256',
            $password,
            base64_encode($key),
            100000,
            32,
            true
        );

        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $derivedKey,
            0,
            $iv
        );

        if ($decrypted === false) {
            throw new RuntimeException('[EncryptionHelper::decryptData] Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }
}