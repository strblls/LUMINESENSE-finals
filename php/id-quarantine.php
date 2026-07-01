<?php
/**
 * IdQuarantine.php
 * ---------------------------------------------------------
 * Handles encrypt-on-write / decrypt-on-read for the small
 * subset of ID images that failed OCR matching and need a
 * human (Head Teacher) to review them.
 *
 * Key lives in the server environment (ID_ENCRYPTION_KEY),
 * never in the database. Without that env var, decrypt is
 * impossible even with full DB access — that's the point.
 *
 * Encryption: AES-256-GCM (authenticated — tamper detection
 * built in, not just confidentiality).
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

class IdQuarantine
{
    private const CIPHER = 'aes-256-gcm';

    /** Encrypts raw image bytes -> single base64 string (iv + tag + ciphertext). */
    public static function encrypt(string $imageBytes): string
    {
        $key = self::getKey();
        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $ciphertext = openssl_encrypt(
            $imageBytes,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        // Pack iv + tag + ciphertext together, then base64 the whole thing.
        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Reverses encrypt(). Returns raw image bytes. */
    public static function decrypt(string $encodedBlob): string
    {
        $key  = self::getKey();
        $raw  = base64_decode($encodedBlob);

        $ivLen  = openssl_cipher_iv_length(self::CIPHER);
        $tagLen = 16; // GCM tag is always 16 bytes

        $iv         = substr($raw, 0, $ivLen);
        $tag        = substr($raw, $ivLen, $tagLen);
        $ciphertext = substr($raw, $ivLen + $tagLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — key mismatch or tampered data.');
        }

        return $plaintext;
    }

    private static function getKey(): string
    {
        $key = getenv('ID_ENCRYPTION_KEY');
        if (!$key && defined('ID_ENCRYPTION_KEY')) {
            $key = ID_ENCRYPTION_KEY;
        }
        if (!$key) {
            throw new \RuntimeException('ID_ENCRYPTION_KEY is not set in the server environment or config.php.');
        }
        // Expecting a 32-byte key, base64-encoded in the env var / config.
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('ID_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }
        return $decoded;
    }
}

/**
 * One-time setup: generate a key and put it in your .env / server config.
 * Run this once from CLI: php -r "require 'IdQuarantine.php'; echo base64_encode(random_bytes(32));"
 * Then set: ID_ENCRYPTION_KEY=<that output>
 */

