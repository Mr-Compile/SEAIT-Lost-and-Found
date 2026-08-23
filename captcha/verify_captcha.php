<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const CAPTCHA_SESSION_KEY = 'secure_text_captcha';
const CAPTCHA_TTL_SECONDS = 120;
const CAPTCHA_MAX_ATTEMPTS = 3;

function captchaGetClientIp(): string
{
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}

function captchaGenerateCode(int $minLength = 5, int $maxLength = 7): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $length = random_int($minLength, $maxLength);
    $maxIndex = strlen($alphabet) - 1;
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $maxIndex)];
    }

    return $code;
}

function captchaGetLogFilePath(): string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'findit_captcha_logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    return $directory . DIRECTORY_SEPARATOR . 'failed_attempts.log';
}

function captchaLogFailedAttempt(string $ip, string $reason): void
{
    $line = sprintf("%s\t%s\t%s\n", date('c'), $ip, $reason);
    @file_put_contents(captchaGetLogFilePath(), $line, FILE_APPEND | LOCK_EX);
}

function captchaCreateChallenge(?int $attemptsLeft = null, ?int $expiresAt = null): array
{
    $challenge = [
        'code' => captchaGenerateCode(5, 7),
        'expires_at' => $expiresAt ?? (time() + CAPTCHA_TTL_SECONDS),
        'attempts_left' => $attemptsLeft ?? CAPTCHA_MAX_ATTEMPTS,
        'created_at' => time(),
    ];

    $_SESSION[CAPTCHA_SESSION_KEY] = $challenge;

    return $challenge;
}

function captchaGetChallenge(bool $forceRefresh = false): array
{
    $challenge = $_SESSION[CAPTCHA_SESSION_KEY] ?? null;

    if (is_array($challenge)) {
        $expiresAt = (int) ($challenge['expires_at'] ?? 0);
        $attemptsLeft = (int) ($challenge['attempts_left'] ?? 0);

        // Keep lock state intact until expiry to prevent refresh-based bypass.
        if ($expiresAt >= time() && $attemptsLeft <= 0) {
            return $challenge;
        }
    }

    if ($forceRefresh || !is_array($challenge)) {
        return captchaCreateChallenge();
    }

    $expiresAt = (int) ($challenge['expires_at'] ?? 0);
    if ($expiresAt < time()) {
        return captchaCreateChallenge();
    }

    $attemptsLeft = (int) ($challenge['attempts_left'] ?? 0);
    if ($attemptsLeft <= 0) {
        return $challenge;
    }

    $code = (string) ($challenge['code'] ?? '');
    if (!preg_match('/^[A-Z0-9]{5,7}$/', $code)) {
        return captchaCreateChallenge($attemptsLeft, $expiresAt);
    }

    return $challenge;
}

function captchaGetCurrentCode(bool $forceRefresh = false): string
{
    $challenge = captchaGetChallenge($forceRefresh);
    return (string) ($challenge['code'] ?? '');
}

function captchaVerifyInput(string $userInput): array
{
    $challenge = $_SESSION[CAPTCHA_SESSION_KEY] ?? null;

    if (!is_array($challenge)) {
        captchaCreateChallenge();
        return [
            'ok' => false,
            'message' => 'Captcha expired. A new challenge was generated.',
            'refreshCaptcha' => true,
        ];
    }

    $now = time();
    $expiresAt = (int) ($challenge['expires_at'] ?? 0);
    if ($expiresAt < $now) {
        captchaCreateChallenge();
        return [
            'ok' => false,
            'message' => 'Captcha expired after 2 minutes. A new one is ready.',
            'refreshCaptcha' => true,
        ];
    }

    $attemptsLeft = (int) ($challenge['attempts_left'] ?? 0);
    if ($attemptsLeft <= 0) {
        $retryAfter = max(1, $expiresAt - $now);
        return [
            'ok' => false,
            'message' => 'Captcha locked after 3 failed attempts. Try again in ' . $retryAfter . ' second(s).',
            'refreshCaptcha' => true,
            'locked' => true,
        ];
    }

    $normalizedInput = strtoupper(trim($userInput));
    $normalizedInput = preg_replace('/\s+/', '', $normalizedInput) ?? '';

    $expectedCode = strtoupper((string) ($challenge['code'] ?? ''));
    $isValidPattern = (bool) preg_match('/^[A-Z0-9]{5,7}$/', $normalizedInput);

    if ($isValidPattern && $expectedCode !== '' && hash_equals($expectedCode, $normalizedInput)) {
        unset($_SESSION[CAPTCHA_SESSION_KEY]);
        return [
            'ok' => true,
            'message' => 'Captcha verified.',
        ];
    }

    $attemptsLeft -= 1;
    captchaLogFailedAttempt(captchaGetClientIp(), 'captcha_failed_validation');

    if ($attemptsLeft <= 0) {
        $_SESSION[CAPTCHA_SESSION_KEY] = [
            'code' => captchaGenerateCode(5, 7),
            'expires_at' => $expiresAt,
            'attempts_left' => 0,
            'created_at' => (int) ($challenge['created_at'] ?? $now),
        ];

        $retryAfter = max(1, $expiresAt - $now);
        return [
            'ok' => false,
            'message' => 'Captcha lock activated after 3 failed attempts. Wait ' . $retryAfter . ' second(s), then refresh.',
            'refreshCaptcha' => true,
            'locked' => true,
        ];
    }

    captchaCreateChallenge($attemptsLeft, $expiresAt);

    return [
        'ok' => false,
        'message' => 'Captcha incorrect. ' . $attemptsLeft . ' attempt(s) left. New captcha generated.',
        'refreshCaptcha' => true,
        'attemptsLeft' => $attemptsLeft,
    ];
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'message' => 'Method not allowed.',
        ]);
        exit;
    }

    $captchaInput = (string) ($_POST['captcha'] ?? '');
    echo json_encode(captchaVerifyInput($captchaInput));
    exit;
}
