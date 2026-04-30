<?php
// api/comments.php
// Handles comments: list by post, list my comments, create, update, delete.

session_start();
header('Content-Type: application/json');

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    if ($action === 'list') listComments();
    elseif ($action === 'mine') myComments();
    else respond(400, ['error' => 'Unknown action.']);
} elseif ($method === 'POST' && $action === 'create') {
    createComment();
} elseif ($method === 'PUT') {
    updateComment();
} elseif ($method === 'DELETE') {
    deleteComment();
} else {
    respond(405, ['error' => 'Method not allowed.']);
}

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sanitize(string $value): string {
    return trim(strip_tags($value));
}

function sessionUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $user = sessionUser();
    if (!$user) {
        respond(401, ['error' => 'You must be logged in to comment.']);
    }
    return $user;
}

function validateComment(string $body): array {
    $errors = [];
    if (strlen($body) < 3) {
        $errors[] = 'Comment must be at least 3 characters.';
    }
    if (strlen($body) > 1000) {
        $errors[] = 'Comment is too long (max 1000 characters).';
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body)) {
        $errors[] = 'Comment contains invalid control characters.';
    }
    return $errors;
}

function listComments(): void {
    $postId = (int)($_GET['post_id'] ?? 0);
    if (!$postId) respond(400, ['error' => 'Post ID is required.']);

    $stmt = getDB()->prepare("
        SELECT c.id, c.post_id, c.user_id, c.body, c.created_at,
               u.username AS author
        FROM comments c
        JOIN users u ON u.id = c.user_id
        JOIN posts p ON p.id = c.post_id
        WHERE c.post_id = ? AND p.status = 'approved'
        ORDER BY c.created_at DESC, c.id DESC
    ");
    $stmt->execute([$postId]);

    respond(200, [
        'comments' => $stmt->fetchAll(),
        'user' => sessionUser(),
    ]);
}

function myComments(): void {
    $user = requireLogin();

    $stmt = getDB()->prepare("
        SELECT c.id, c.post_id, c.body, c.created_at,
               p.title AS post_title,
               p.status AS post_status
        FROM comments c
        JOIN posts p ON p.id = c.post_id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC, c.id DESC
    ");
    $stmt->execute([$user['id']]);
    respond(200, ['comments' => $stmt->fetchAll()]);
}

function createComment(): void {
    $user = requireLogin();
    $postId = (int)($_POST['post_id'] ?? 0);
    $body = sanitize($_POST['body'] ?? '');

    if (!$postId) respond(400, ['error' => 'Post ID is required.']);
    $errors = validateComment($body);
    if (!empty($errors)) respond(422, ['errors' => $errors]);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND status = 'approved'");
    $stmt->execute([$postId]);
    if (!$stmt->fetch()) {
        respond(404, ['error' => 'Post not found or not open for comments.']);
    }

    $stmt = $pdo->prepare('INSERT INTO comments (post_id, user_id, body) VALUES (?, ?, ?)');
    $stmt->execute([$postId, $user['id'], $body]);

    respond(201, [
        'id' => (int)$pdo->lastInsertId(),
        'message' => 'Comment added successfully.',
    ]);
}

function updateComment(): void {
    $user = requireLogin();
    parse_str(file_get_contents('php://input'), $data);

    $id = (int)($data['id'] ?? 0);
    $body = sanitize($data['body'] ?? '');
    if (!$id) respond(400, ['error' => 'Comment ID is required.']);

    $errors = validateComment($body);
    if (!empty($errors)) respond(422, ['errors' => $errors]);

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    $comment = $stmt->fetch();
    if (!$comment) respond(404, ['error' => 'Comment not found.']);

    if ((int)$comment['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') {
        respond(403, ['error' => 'You can only edit your own comments.']);
    }

    $stmt = $pdo->prepare('UPDATE comments SET body = ? WHERE id = ?');
    $stmt->execute([$body, $id]);
    respond(200, ['message' => 'Comment updated successfully.']);
}

function deleteComment(): void {
    $user = requireLogin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(400, ['error' => 'Comment ID is required.']);

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    $comment = $stmt->fetch();
    if (!$comment) respond(404, ['error' => 'Comment not found.']);

    if ((int)$comment['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') {
        respond(403, ['error' => 'You can only delete your own comments.']);
    }

    $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    respond(200, ['message' => 'Comment deleted successfully.']);
}
