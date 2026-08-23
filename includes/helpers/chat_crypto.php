<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';

/**
 * Returns the encryption key for chat message encryption.
 *
 * Requirements:
 * - Set CHAT_ENCRYPTION_KEY in .env as a base64-encoded 32-byte key.
 *   Example (PowerShell):
 *     [Convert]::ToBase64String((1..32 | ForEach-Object {Get-Random -Max 256}))
 */
function getChatEncryptionKey(): string
{
    $raw = envValue('CHAT_ENCRYPTION_KEY', '') ?? '';
    $raw = trim($raw);

    if ($raw === '') {
        throw new RuntimeException('CHAT_ENCRYPTION_KEY is not configured.');
    }

    $key = base64_decode($raw, true);
    // Accept 32-byte key even if sodium constants aren't available.
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('CHAT_ENCRYPTION_KEY must be base64 of 32 bytes.');
    }

    return $key;
}

/**
 * OpenSSL fallback encryption.
 * Format:
 *   v2:<base64(iv)>:<base64(ciphertext)>:<base64(tag)>
 */
function encryptChatMessageOpenSslV2(string $plaintext, string $key): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for chat encryption fallback.');
    }

    $ivLen = 12; // recommended for GCM
    $iv = random_bytes($ivLen);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('OpenSSL encryption failed.');
    }

    return 'v2:' . base64_encode($iv) . ':' . base64_encode($ciphertext) . ':' . base64_encode($tag);
}

function decryptChatMessageOpenSslV2(string $storedValue, string $key): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for chat decryption fallback.');
    }

    $parts = explode(':', $storedValue, 4);
    if (count($parts) !== 4) {
        return '';
    }

    [, $ivB64, $cipherB64, $tagB64] = $parts;
    $iv = base64_decode($ivB64, true);
    $cipher = base64_decode($cipherB64, true);
    $tag = base64_decode($tagB64, true);
    if ($iv === false || $cipher === false || $tag === false) {
        return '';
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        ''
    );

    return $plain === false ? '' : $plain;
}

/**
 * Encrypts a plaintext chat message.
 * Output format (string stored in DB):
 *   v1:<base64(nonce)>:<base64(ciphertext)>
 */
function encryptChatMessage(string $plaintext): string
{
    if ($plaintext === '') {
        return $plaintext;
    }

    try {
        $key = getChatEncryptionKey();

        // Prefer libsodium when available (v1)
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return 'v1:' . base64_encode($nonce) . ':' . base64_encode($cipher);
        }

        // Fallback to OpenSSL AES-256-GCM (v2)
        return encryptChatMessageOpenSslV2($plaintext, $key);
    } catch (Throwable $e) {
        // Last-resort fail open: keep app usable.
        error_log('chat_crypto: encrypt failed; storing plaintext message. ' . $e->getMessage());
        return $plaintext;
    }
}

/**
 * Decrypts an encrypted chat message (or returns plaintext if not encrypted).
 */
function decryptChatMessage(string $storedValue): string
{
    if ($storedValue === '') {
        return $storedValue;
    }

    if (!str_starts_with($storedValue, 'v1:') && !str_starts_with($storedValue, 'v2:')) {
        // Backwards compatibility: plaintext already stored.
        return $storedValue;
    }

    try {
        $key = getChatEncryptionKey();

        if (str_starts_with($storedValue, 'v2:')) {
            return decryptChatMessageOpenSslV2($storedValue, $key);
        }

        // v1
        if (!function_exists('sodium_crypto_secretbox_open')) {
            error_log('chat_crypto: libsodium missing; cannot decrypt v1 message.');
            return '';
        }

        $parts = explode(':', $storedValue, 3);
        if (count($parts) !== 3) {
            return '';
        }

        [, $nonceB64, $cipherB64] = $parts;
        $nonce = base64_decode($nonceB64, true);
        $cipher = base64_decode($cipherB64, true);
        if ($nonce === false || $cipher === false) {
            return '';
        }

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? '' : $plain;
    } catch (Throwable $e) {
        error_log('chat_crypto: decrypt failed. ' . $e->getMessage());
        return '';
    }
}

/**
 * Detects if a message string is encrypted with our format.
 */
function isChatMessageEncrypted(string $storedValue): bool
{
    return str_starts_with($storedValue, 'v1:') || str_starts_with($storedValue, 'v2:');
}
