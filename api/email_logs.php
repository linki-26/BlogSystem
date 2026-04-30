<?php
// api/email_logs.php
// Admin-only endpoint to view email API attempts stored in the database.

session_start();
header('Content-Type: application/json');

require_once 'db.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required.']);
    exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required.']);
    exit;
}

$stmt = getDB()->query(
    'SELECT id, recipient_email, subject, status, provider_message, created_at
     FROM email_logs
     ORDER BY created_at DESC, id DESC
     LIMIT 50'
);

echo json_encode(['logs' => $stmt->fetchAll()]);
