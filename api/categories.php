<?php
// api/categories.php
// List categories (public), create and delete (admin only)

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

function requireAdmin(): void {
    $u = $_SESSION['user'] ?? null;
    if (!$u)                    respond(401, ['error' => 'Login required.']);
    if ($u['role'] !== 'admin') respond(403, ['error' => 'Admin access required.']);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if      ($method === 'GET')                          listCategories();
elseif  ($method === 'POST'   && $action === 'create') createCategory();
elseif  ($method === 'DELETE' && $action === 'delete') deleteCategory();
else    respond(400, ['error' => 'Unknown action.']);

// ─────────────────────────────────────────────────────────────
// LIST — public, used by dropdowns in all dashboards
// ─────────────────────────────────────────────────────────────
function listCategories(): void {
    $stmt = getDB()->query("
        SELECT c.id, c.name,
               COUNT(CASE WHEN p.status = 'approved' THEN 1 END) AS post_count
        FROM categories c
        LEFT JOIN posts p ON p.category_id = c.id
        GROUP BY c.id, c.name
        ORDER BY c.name ASC
    ");
    respond(200, ['categories' => $stmt->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────
// CREATE — admin only
// ─────────────────────────────────────────────────────────────
function createCategory(): void {
    requireAdmin();

    $name = sanitize($_POST['name'] ?? '');

    if (strlen($name) < 2) {
        respond(422, ['error' => 'Category name must be at least 2 characters.']);
    }
    if (strlen($name) > 80) {
        respond(422, ['error' => 'Category name is too long (max 80 characters).']);
    }
    // Only allow letters, numbers, spaces, hyphens
    if (!preg_match('/^[a-zA-Z0-9 \-]+$/', $name)) {
        respond(422, ['error' => 'Category name may only contain letters, numbers, spaces, and hyphens.']);
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->execute([$name]);
        respond(201, [
            'id'      => (int) $pdo->lastInsertId(),
            'name'    => $name,
            'message' => 'Category "' . $name . '" created.'
        ]);
    } catch (PDOException $e) {
        // Unique constraint violation — category already exists
        respond(409, ['error' => 'A category with this name already exists.']);
    }
}

// ─────────────────────────────────────────────────────────────
// DELETE — admin only
// ─────────────────────────────────────────────────────────────
function deleteCategory(): void {
    requireAdmin();

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(400, ['error' => 'Category ID is required.']);

    $pdo  = getDB();

    // Check it exists first
    $stmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat) respond(404, ['error' => 'Category not found.']);

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);

    respond(200, ['message' => 'Category "' . $cat['name'] . '" deleted.']);
}