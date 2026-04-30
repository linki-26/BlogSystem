<?php
// api/email_service.php
// Sends email through Resend and records every attempt in email_logs.

require_once 'db.php';
require_once 'resend_config.php';

function emailProviderConfigured(): bool {
    return RESEND_API_KEY !== 'YOUR_RESEND_API_KEY'
        && trim(RESEND_FROM_EMAIL) !== '';
}

function logEmailAttempt(string $to, string $subject, string $status, string $providerMessage = ''): void {
    try {
        $stmt = getDB()->prepare(
            'INSERT INTO email_logs (recipient_email, subject, status, provider_message) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$to, $subject, $status, substr($providerMessage, 0, 1000)]);
    } catch (Throwable $e) {
        // Email logging must never break the main website action.
    }
}

function sendEmail(string $to, string $subject, string $textBody, string $htmlBody = ''): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        logEmailAttempt($to, $subject, 'failed', 'Invalid recipient email.');
        return ['ok' => false, 'status' => 'failed', 'message' => 'Invalid recipient email.'];
    }

    if (!emailProviderConfigured()) {
        logEmailAttempt($to, $subject, 'skipped', 'Resend is not configured.');
        return ['ok' => false, 'status' => 'skipped', 'message' => 'Resend is not configured.'];
    }

    if (!function_exists('curl_init')) {
        logEmailAttempt($to, $subject, 'failed', 'PHP cURL extension is not enabled.');
        return ['ok' => false, 'status' => 'failed', 'message' => 'PHP cURL extension is not enabled.'];
    }

    $payload = [
        'from' => RESEND_FROM_EMAIL,
        'to' => [$to],
        'subject' => $subject,
        'text' => $textBody,
    ];

    if ($htmlBody !== '') {
        $payload['html'] = $htmlBody;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($statusCode >= 200 && $statusCode < 300) {
        logEmailAttempt($to, $subject, 'sent', 'Resend HTTP ' . $statusCode . ': ' . (string)$response);
        return ['ok' => true, 'status' => 'sent', 'message' => 'Email sent.'];
    }

    $message = $curlError ?: ('Resend HTTP ' . $statusCode . ': ' . (string)$response);
    logEmailAttempt($to, $subject, 'failed', $message);
    return ['ok' => false, 'status' => 'failed', 'message' => $message];
}
