<?php
// api/users.php
// Admin-only: list users, change roles, activate/deactivate accounts

session_start();
header('Content-Type: application/json');

require_once 'db.php';

function respond(int $c, array $d): void {
    http_response_code($c);
    echo json_encode($d);
    exit;
}

function sanitize(string $v): string {
    return trim(strip_tags($v));
}

// All actions in this file require admin role
function requireAdmin(): array {
    $u = $_SESSION['user'] ?? null;
    if (!$u) respond(401, ['error' => 'Login required.']);
    if ($u['role'] !== 'admin') respond(403, ['error' => 'Admin access required.']);
    return $u;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if      ($method === 'GET'  && $action === 'list')   listUsers();
elseif  ($method === 'POST' && $action === 'role')   changeRole();
elseif  ($method === 'POST' && $action === 'toggle') toggleActive();
else    respond(400, ['error' => 'Unknown action.']);

// ─────────────────────────────────────────────────────────────
// LIST all users
// ─────────────────────────────────────────────────────────────
function listUsers(): void {
    requireAdmin();
    $stmt = getDB()->query(
        'SELECT id, username, email, role, is_active, created_at FROM users ORDER BY id ASC'
    );
    respond(200, ['users' => $stmt->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────
// CHANGE user role
// ─────────────────────────────────────────────────────────────
function changeRole(): void {
    requireAdmin();

    $id   = (int)($_POST['id']   ?? 0);
    $role = sanitize($_POST['role'] ?? '');

    if (!$id) respond(400, ['error' => 'User ID is required.']);

    // Only allow these three role values
    if (!in_array($role, ['student', 'moderator', 'admin'])) {
        respond(422, ['error' => 'Invalid role. Must be student, moderator, or admin.']);
    }

    // Protect the primary admin account (id=1) from role changes
    if ($id === 1) {
        respond(403, ['error' => 'Cannot change the role of the primary admin account.']);
    }

    $stmt = getDB()->prepare('UPDATE users SET role = ? WHERE id = ?');
    $stmt->execute([$role, $id]);

    respond(200, ['message' => 'Role updated to ' . $role . '.']);
}

// ─────────────────────────────────────────────────────────────
// TOGGLE active/inactive status
// ─────────────────────────────────────────────────────────────
function toggleActive(): void {
    requireAdmin();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) respond(400, ['error' => 'User ID is required.']);

    if ($id === 1) {
        respond(403, ['error' => 'Cannot deactivate the primary admin account.']);
    }

    $pdo  = getDB();

    // First get the current status
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) respond(404, ['error' => 'User not found.']);

    // Flip the value: if active (1) make inactive (0), and vice versa
    $newVal = $user['is_active'] ? 0 : 1;

    $stmt = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $stmt->execute([$newVal, $id]);

    respond(200, [
        'active'  => (bool) $newVal,
        'message' => $newVal ? 'User reactivated.' : 'User deactivated.'
    ]);
}