<?php
// Shared session timeout guard for all JSON API endpoints.
// A logged-in session expires after this many seconds of inactivity.

define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes.

function expireInactiveSession(): void {
    if (empty($_SESSION['user'])) {
        return;
    }

    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);

    if (($now - $lastActivity) <= SESSION_TIMEOUT_SECONDS) {
        $_SESSION['last_activity'] = $now;
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    session_start();
    $GLOBALS['SESSION_EXPIRED'] = true;
}

function markSessionActive(): void {
    $_SESSION['last_activity'] = time();
}

function sessionInfo(): array {
    $lastActivity = (int)($_SESSION['last_activity'] ?? time());
    $remaining = max(0, SESSION_TIMEOUT_SECONDS - (time() - $lastActivity));

    return [
        'timeoutSeconds' => SESSION_TIMEOUT_SECONDS,
        'remainingSeconds' => $remaining,
    ];
}

function wasSessionExpired(): bool {
    return !empty($GLOBALS['SESSION_EXPIRED']);
}

expireInactiveSession();
