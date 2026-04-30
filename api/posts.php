<?php
// api/posts.php
// Handles all post operations: list, single, search, create, update, delete, approve, reject
// Called from all dashboard pages and index.html via fetch()

session_start();
header('Content-Type: application/json');

require_once 'session_guard.php';
require_once 'db.php';
require_once 'email_service.php';

$method = $_SERVER['REQUEST_METHOD']; // GET, POST, PUT, DELETE
$action = $_GET['action'] ?? '';

// Route based on HTTP method and action parameter
if ($method === 'GET') {
    if      ($action === 'list')   listPosts();
    elseif  ($action === 'single') getSinglePost();
    elseif  ($action === 'search') searchPosts();
    elseif  ($action === 'mine')   myPosts();
    elseif  ($action === 'queue')  pendingQueue();
    elseif  ($action === 'all')    allPostsAdmin();
    else    respond(400, ['error' => 'Unknown action']);
} elseif ($method === 'POST') {
    if      ($action === 'create')  createPost();
    elseif  ($action === 'approve') moderatePost('approved');
    elseif  ($action === 'reject')  moderatePost('rejected');
    else    respond(400, ['error' => 'Unknown action']);
} elseif ($method === 'PUT') {
    updatePost();
} elseif ($method === 'DELETE') {
    deletePost();
} else {
    respond(405, ['error' => 'Method not allowed']);
}

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sanitize(string $v): string {
    return trim(strip_tags($v));
}

function sanitizeUrl(string $v): string {
    $url = trim(strip_tags($v));
    if ($url === '') return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    return preg_match('/(^|\.)cloudinary\.com$/i', $host) ? $url : '';
}

// Get the logged-in user from session, or null if not logged in
function sessionUser(): ?array {
    return $_SESSION['user'] ?? null;
}

// Require login — if not logged in, stop and return 401
function requireLogin(): array {
    $u = sessionUser();
    if (!$u) {
        respond(401, ['error' => 'You must be logged in to do this.']);
    }
    return $u;
}

// Require a specific role — if wrong role, stop and return 403
function requireRole(string ...$roles): array {
    $u = requireLogin();
    if (!in_array($u['role'], $roles)) {
        respond(403, ['error' => 'You do not have permission to do this.']);
    }
    return $u;
}

// Build a short excerpt from full content (first 200 characters)
function makeExcerpt(string $content): string {
    $clean = strip_tags($content);
    return strlen($clean) > 200 ? substr($clean, 0, 200) . '…' : $clean;
}

// ─────────────────────────────────────────────────────────────
// LIST approved posts — public, anyone can see
// ─────────────────────────────────────────────────────────────
function listPosts(): void {
    $pdo      = getDB();
    $cat      = sanitize($_GET['cat'] ?? '');

    if ($cat) {
        // Filter by category if provided
        $stmt = $pdo->prepare("
            SELECT p.id, p.title, p.content, p.cover_image_url, p.status, p.created_at,
                   u.username AS author, u.id AS author_id,
                   c.name AS category
            FROM posts p
            JOIN users u ON u.id = p.author_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.status = 'approved' AND c.name = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$cat]);
    } else {
        $stmt = $pdo->query("
            SELECT p.id, p.title, p.content, p.cover_image_url, p.status, p.created_at,
                   u.username AS author, u.id AS author_id,
                   c.name AS category
            FROM posts p
            JOIN users u ON u.id = p.author_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.status = 'approved'
            ORDER BY p.created_at DESC
        ");
    }

    $posts = $stmt->fetchAll();

    // Add excerpt to each post so frontend doesn't need to truncate
    foreach ($posts as &$p) {
        $p['excerpt'] = makeExcerpt($p['content']);
    }

    respond(200, ['posts' => $posts]);
}

// ─────────────────────────────────────────────────────────────
// SINGLE post by ID — public
// ─────────────────────────────────────────────────────────────
function getSinglePost(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(400, ['error' => 'Post ID is required.']);

    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, u.username AS author, c.name AS category
        FROM posts p
        JOIN users u ON u.id = p.author_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id = ? AND p.status = 'approved'
    ");
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) respond(404, ['error' => 'Post not found or not yet approved.']);

    $post['excerpt'] = makeExcerpt($post['content']);
    respond(200, ['post' => $post]);
}

// ─────────────────────────────────────────────────────────────
// SEARCH posts — public
// ─────────────────────────────────────────────────────────────
function searchPosts(): void {
    $q = sanitize($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        respond(422, ['error' => 'Search term must be at least 2 characters.']);
    }

    $pdo  = getDB();
    // % wildcards allow partial matching: searching "calcul" finds "calculus"
    // The ? placeholders keep this safe from SQL injection even with wildcards
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.content, p.cover_image_url, p.created_at,
               u.username AS author, c.name AS category
        FROM posts p
        JOIN users u ON u.id = p.author_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'approved'
          AND (p.title LIKE ? OR p.content LIKE ? OR c.name LIKE ?)
        ORDER BY p.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$like, $like, $like]);
    $results = $stmt->fetchAll();

    foreach ($results as &$p) {
        $p['excerpt'] = makeExcerpt($p['content']);
    }

    respond(200, [
        'posts' => $results,
        'count' => count($results),
        'query' => $q,
        'message' => count($results) === 0 ? 'No posts found for "' . $q . '".' : ''
    ]);
}

// ─────────────────────────────────────────────────────────────
// MY POSTS — logged in student sees their own posts
// ─────────────────────────────────────────────────────────────
function myPosts(): void {
    $user = requireLogin();
    $pdo  = getDB();

    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.content, p.cover_image_url, p.status, p.rejection_note, p.created_at,
               c.name AS category
        FROM posts p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.author_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $posts = $stmt->fetchAll();

    foreach ($posts as &$p) {
        $p['excerpt'] = makeExcerpt($p['content']);
    }

    respond(200, ['posts' => $posts]);
}

// ─────────────────────────────────────────────────────────────
// PENDING QUEUE — for moderator and admin
// ─────────────────────────────────────────────────────────────
function pendingQueue(): void {
    requireRole('admin', 'moderator');
    $pdo  = getDB();
    $stmt = $pdo->query("
        SELECT p.id, p.title, p.content, p.cover_image_url, p.created_at,
               u.username AS author, c.name AS category
        FROM posts p
        JOIN users u ON u.id = p.author_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'pending'
        ORDER BY p.created_at ASC
    ");
    $posts = $stmt->fetchAll();
    foreach ($posts as &$p) {
        $p['excerpt'] = makeExcerpt($p['content']);
    }
    respond(200, ['posts' => $posts]);
}

// ─────────────────────────────────────────────────────────────
// ALL POSTS — admin only, sees all statuses
// ─────────────────────────────────────────────────────────────
function allPostsAdmin(): void {
    requireRole('admin');
    $pdo  = getDB();
    $stmt = $pdo->query("
        SELECT p.id, p.title, p.content, p.cover_image_url, p.status, p.rejection_note, p.created_at,
               u.username AS author, u.id AS author_id,
               c.name AS category
        FROM posts p
        JOIN users u ON u.id = p.author_id
        LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.created_at DESC
    ");
    $posts = $stmt->fetchAll();
    foreach ($posts as &$p) {
        $p['excerpt'] = makeExcerpt($p['content']);
    }
    respond(200, ['posts' => $posts]);
}

// ─────────────────────────────────────────────────────────────
// CREATE post — logged in student
// ─────────────────────────────────────────────────────────────
function createPost(): void {
    $user  = requireLogin();
    $pdo   = getDB();

    $title   = sanitize($_POST['title']    ?? '');
    $content = sanitize($_POST['content']  ?? '');
    $catName = sanitize($_POST['category'] ?? '');
    $coverImageUrl = sanitizeUrl($_POST['cover_image_url'] ?? '');

    $errors = [];
    if (strlen($title) < 5)
        $errors[] = 'Title must be at least 5 characters.';
    if (strlen($title) > 255)
        $errors[] = 'Title is too long (max 255 characters).';
    if (empty($catName))
        $errors[] = 'Please select a category.';
    // str_word_count counts the number of words in a string
    if (str_word_count($content) < 10)
        $errors[] = 'Content must be at least 10 words long.';
    if (!empty($_POST['cover_image_url']) && $coverImageUrl === '')
        $errors[] = 'Invalid cover image URL.';

    if (!empty($errors)) respond(422, ['errors' => $errors]);

    // Look up the category ID from the name
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
    $stmt->execute([$catName]);
    $cat = $stmt->fetch();
    if (!$cat) respond(422, ['errors' => ['Invalid category selected.']]);

    $stmt = $pdo->prepare(
        'INSERT INTO posts (title, content, cover_image_url, category_id, author_id, status) VALUES (?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([$title, $content, $coverImageUrl ?: null, $cat['id'], $user['id']]);

    respond(201, [
        'id'      => (int) $pdo->lastInsertId(),
        'message' => 'Post submitted for review successfully.'
    ]);
}

// ─────────────────────────────────────────────────────────────
// UPDATE post — author or admin only
// ─────────────────────────────────────────────────────────────
function updatePost(): void {
    $user = requireLogin();

    // PUT requests send data differently — we read it from php://input
    parse_str(file_get_contents('php://input'), $data);

    $id      = (int)($data['id']       ?? 0);
    $title   = sanitize($data['title']   ?? '');
    $content = sanitize($data['content'] ?? '');
    $catName = sanitize($data['category'] ?? '');
    $coverImageUrl = sanitizeUrl($data['cover_image_url'] ?? '');

    if (!$id) respond(400, ['error' => 'Post ID is required.']);

    $errors = [];
    if (strlen($title) < 5)
        $errors[] = 'Title must be at least 5 characters.';
    if (str_word_count($content) < 10)
        $errors[] = 'Content must be at least 10 words.';
    if (!empty($data['cover_image_url']) && $coverImageUrl === '')
        $errors[] = 'Invalid cover image URL.';
    if (!empty($errors)) respond(422, ['errors' => $errors]);

    $pdo  = getDB();

    // First fetch the post to check if it exists and who owns it
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) respond(404, ['error' => 'Post not found.']);

    // Ownership check: only the author or an admin can edit
    // This is a key LD4 security requirement
    if ((int)$post['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        respond(403, ['error' => 'You can only edit your own posts.']);
    }

    // Look up new category if provided
    $catId = $post['category_id']; // keep old category by default
    if (!empty($catName)) {
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $stmt->execute([$catName]);
        $cat = $stmt->fetch();
        if (!$cat) {
            respond(422, ['errors' => ['Invalid category selected.']]);
        }
        $catId = $cat['id'];
    }

    $newStatus = $user['role'] === 'admin' ? $post['status'] : 'pending';
    $newCoverImageUrl = array_key_exists('cover_image_url', $data)
        ? ($coverImageUrl ?: null)
        : $post['cover_image_url'];
    $stmt = $pdo->prepare(
        'UPDATE posts SET title = ?, content = ?, cover_image_url = ?, category_id = ?, status = ?, rejection_note = NULL WHERE id = ?'
    );
    $stmt->execute([$title, $content, $newCoverImageUrl, $catId, $newStatus, $id]);

    respond(200, ['message' => 'Post updated successfully.']);
}

// ─────────────────────────────────────────────────────────────
// DELETE post — author or admin only
// ─────────────────────────────────────────────────────────────
function deletePost(): void {
    $user = requireLogin();
    $id   = (int)($_GET['id'] ?? 0);

    if (!$id) respond(400, ['error' => 'Post ID is required.']);

    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) respond(404, ['error' => 'Post not found.']);

    // Ownership check: only author or admin can delete
    if ((int)$post['author_id'] !== $user['id'] && $user['role'] !== 'admin') {
        respond(403, ['error' => 'You can only delete your own posts.']);
    }

    $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    respond(200, ['message' => 'Post deleted successfully.']);
}

// ─────────────────────────────────────────────────────────────
// MODERATE — approve or reject a post (mod/admin only)
// ─────────────────────────────────────────────────────────────
function moderatePost(string $newStatus): void {
    requireRole('admin', 'moderator');

    $id   = (int)($_POST['id'] ?? 0);
    if (!$id) respond(400, ['error' => 'Post ID is required.']);

    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT p.title, u.email, u.username
        FROM posts p
        JOIN users u ON u.id = p.author_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) respond(404, ['error' => 'Post not found.']);

    if ($newStatus === 'rejected') {
        $note = sanitize($_POST['rejection_note'] ?? '');
        if (empty($note)) {
            respond(422, ['errors' => ['A rejection reason is required so the student knows what to fix.']]);
        }
        $stmt = $pdo->prepare(
            'UPDATE posts SET status = "rejected", rejection_note = ? WHERE id = ?'
        );
        $stmt->execute([$note, $id]);
        sendEmail(
            $post['email'],
            'Your StudentBlog post needs changes',
            "Hi {$post['username']},\n\nYour post \"{$post['title']}\" was rejected.\n\nFeedback: $note\n\nPlease edit and resubmit it from your dashboard.\n\nStudentBlog",
            '<p>Hi ' . htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8') . ',</p><p>Your post <strong>' . htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') . '</strong> was rejected.</p><p><strong>Feedback:</strong> ' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p><p>Please edit and resubmit it from your dashboard.</p><p>StudentBlog</p>'
        );
    } else {
        $stmt = $pdo->prepare(
            'UPDATE posts SET status = "approved", rejection_note = NULL WHERE id = ?'
        );
        $stmt->execute([$id]);
        sendEmail(
            $post['email'],
            'Your StudentBlog post was approved',
            "Hi {$post['username']},\n\nYour post \"{$post['title']}\" was approved and is now public.\n\nStudentBlog",
            '<p>Hi ' . htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8') . ',</p><p>Your post <strong>' . htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') . '</strong> was approved and is now public.</p><p>StudentBlog</p>'
        );
    }

    respond(200, ['message' => 'Post ' . $newStatus . ' successfully.']);
}
