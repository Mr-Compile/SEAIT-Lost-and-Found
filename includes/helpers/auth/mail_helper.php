<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

if (!class_exists(PHPMailer::class)) {
    $phpMailerBase = dirname(__DIR__, 3) . '/vendor/phpmailer/phpmailer/src/';

    $requiredFiles = [
        $phpMailerBase . 'Exception.php',
        $phpMailerBase . 'PHPMailer.php',
        $phpMailerBase . 'SMTP.php',
    ];

    foreach ($requiredFiles as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('PHPMailer dependency file missing: ' . $file);
        }
        require_once $file;
    }
}

function isGmailSmtpConfigured(): bool
{
    if (!function_exists('envValue')) {
        return false;
    }

    return (string)envValue('GMAIL_SMTP_HOST', '') !== ''
        && (string)envValue('GMAIL_SMTP_PORT', '') !== ''
        && (string)envValue('GMAIL_SMTP_USER', '') !== ''
        && (string)envValue('GMAIL_SMTP_PASS', '') !== ''
        && (string)envValue('GMAIL_FROM_EMAIL', '') !== '';
}

function sendAppEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = ''): array
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid recipient email.'];
    }

    if (!isGmailSmtpConfigured()) {
        return ['success' => false, 'error' => 'SMTP is not configured in environment.'];
    }

    $host = (string)envValue('GMAIL_SMTP_HOST', 'smtp.gmail.com');
    $port = (int)envValue('GMAIL_SMTP_PORT', '587');
    $username = (string)envValue('GMAIL_SMTP_USER', '');
    $password = (string)envValue('GMAIL_SMTP_PASS', '');
    $fromEmail = (string)envValue('GMAIL_FROM_EMAIL', $username);
    $fromName = (string)envValue('GMAIL_FROM_NAME', 'FindIt');

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;

        if ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody !== '' ? $plainBody : trim(strip_tags($htmlBody));

        $mail->send();

        return ['success' => true];
    } catch (Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Unable to send email at this time.'];
    }
}
