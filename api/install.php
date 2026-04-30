<?php
// Run once in the browser: http://localhost/Blog_system/api/install.php
// It creates the database tables and demo accounts for LD4 testing.

header('Content-Type: text/html; charset=utf-8');

$host = 'localhost';
$db   = 'studentblog';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $sql = file_get_contents(__DIR__ . '/../database.sql');
    $pdo->exec($sql);
    $pdo->exec("USE `$db`");

    $columns = $pdo->query("SHOW COLUMNS FROM comments")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('author_id', $columns, true) && !in_array('user_id', $columns, true)) {
        $pdo->exec('ALTER TABLE comments CHANGE author_id user_id INT NOT NULL');
    }

    $postColumns = $pdo->query("SHOW COLUMNS FROM posts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cover_image_url', $postColumns, true)) {
        $pdo->exec('ALTER TABLE posts ADD cover_image_url VARCHAR(500) NULL AFTER content');
    }

    $userColumns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('google_id', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD google_id VARCHAR(120) NULL UNIQUE AFTER password');
    }
    if (!in_array('auth_provider', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD auth_provider ENUM('local', 'google') NOT NULL DEFAULT 'local' AFTER google_id");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          recipient_email VARCHAR(120) NOT NULL,
          subject VARCHAR(255) NOT NULL,
          status ENUM('sent', 'failed', 'skipped') NOT NULL,
          provider_message TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $users = [
        ['Admin', 'admin@vdu.lt', 'admin123', 'admin'],
        ['Moderator', 'mod@vdu.lt', 'mod123', 'moderator'],
        ['Liwin', 'liwin@vdu.lt', 'password', 'student'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password, role)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE username = VALUES(username), role = VALUES(role), is_active = 1'
    );
    foreach ($users as [$username, $email, $password, $role]) {
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
    }

    $samplePosts = [
        ['The future of machine learning in education', 'Technology', 'Machine learning is changing how students practice, receive feedback, and discover learning gaps. A useful learning platform should still keep teachers in control while using models to support routine practice and reflection.', 'approved'],
        ['CRISPR gene editing: what students should know', 'Science', 'CRISPR is a powerful gene editing technique with medical promise and ethical risk. Students should understand the science, the limits of the method, and the importance of regulation before forming strong conclusions.', 'approved'],
        ['Why calculus is more intuitive than you think', 'Mathematics', 'Calculus becomes easier when we connect it to motion, area, and change. Derivatives describe rates while integrals combine small pieces into a whole, which makes many formulas feel less mysterious.', 'pending'],
    ];

    $authorId = (int) $pdo->query("SELECT id FROM users WHERE email = 'liwin@vdu.lt'")->fetchColumn();
    $postStmt = $pdo->prepare(
        'INSERT INTO posts (title, content, category_id, author_id, status)
         SELECT ?, ?, c.id, ?, ?
         FROM categories c
         WHERE c.name = ?
           AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.title = ?)'
    );
    foreach ($samplePosts as [$title, $category, $content, $status]) {
        $postStmt->execute([$title, $content, $authorId, $status, $category, $title]);
    }

    $firstPostId = (int) $pdo->query("SELECT id FROM posts WHERE status = 'approved' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($firstPostId) {
        $commentStmt = $pdo->prepare(
            'INSERT INTO comments (post_id, user_id, body)
             SELECT ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM comments WHERE post_id = ? AND user_id = ? AND body = ?)'
        );
        $comment = 'Great explanation. The examples make the topic much easier to understand.';
        $commentStmt->execute([$firstPostId, $authorId, $comment, $firstPostId, $authorId, $comment]);
    }

    echo '<h1>StudentBlog database installed</h1>';
    echo '<p>Demo users:</p>';
    echo '<ul>';
    echo '<li>admin@vdu.lt / admin123</li>';
    echo '<li>mod@vdu.lt / mod123</li>';
    echo '<li>liwin@vdu.lt / password</li>';
    echo '</ul>';
    echo '<p><a href="../login.html">Go to login</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Install failed</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
