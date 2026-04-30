<?php
// api/auth.php
// Handles: login, register, logout, session check (me)
// Called from login.html and app.js via fetch()

session_start();
header('Content-Type: application/json');

require_once 'db.php';
require_once 'google_config.php';
require_once 'email_service.php';

// Read the action from POST body or GET parameter
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Route to the correct function based on action
if     ($action === 'login')    handleLogin();
elseif ($action === 'register') handleRegister();
elseif ($action === 'logout')   handleLogout();
elseif ($action === 'me')       handleMe();
elseif ($action === 'profile')  handleProfileUpdate();
elseif ($action === 'google')   handleGoogleLogin();
elseif ($action === 'delete_account') handleDeleteAccount();
else   respond(400, ['error' => 'Unknown action: ' . $action]);

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ─────────────────────────────────────────────────────────────

// Send a JSON response and stop execution
function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Clean user input: removes HTML tags and trims whitespace
// This prevents XSS (cross-site scripting) attacks
// We use this on all text inputs before using them
function sanitizeText(string $val): string {
    return trim(strip_tags($val));
}

// Check if an email address has valid format
// FILTER_VALIDATE_EMAIL is PHP's built-in email format checker
function isValidEmail(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function sessionPayload(array $user): array {
    return [
        'id'       => (int) $user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'role'     => $user['role'],
        'active'   => (bool) $user['is_active'],
    ];
}

function uniqueUsername(PDO $pdo, string $name, string $email): string {
    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    $base = trim($base, '_');
    if (strlen($base) < 3) {
        $base = preg_replace('/[^a-zA-Z0-9_]/', '_', explode('@', $email)[0]);
    }
    $base = substr($base ?: 'google_user', 0, 40);
    $candidate = $base;
    $i = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) return $candidate;
        $candidate = substr($base, 0, 36) . '_' . $i;
        $i++;
    }
}

function fetchGoogleTokenInfo(string $credential): array {
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $raw = @file_get_contents($url);
        $status = $raw === false ? 0 : 200;
    }

    $data = json_decode((string)$raw, true);
    if ($status !== 200 || !is_array($data)) {
        respond(401, ['error' => 'Google token could not be verified.']);
    }
    return $data;
}

// ─────────────────────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────────────────────
function handleLogin(): void {
    $email    = sanitizeText($_POST['email']    ?? '');
    $password = $_POST['password'] ?? ''; // don't sanitize password — bcrypt handles it

    // Validate inputs before touching the database
    $errors = [];
    if (empty($email))        $errors[] = 'Email is required.';
    if (!isValidEmail($email)) $errors[] = 'Invalid email format.';
    if (empty($password))     $errors[] = 'Password is required.';
    if (!empty($errors))      respond(422, ['errors' => $errors]);

    $pdo = getDB();

    // Prepared statement: the ? is a placeholder
    // PDO replaces ? with the actual value safely — this prevents SQL injection
    // SQL injection would happen if we did: "WHERE email = '$email'" directly
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // password_verify compares the plain text password against the bcrypt hash in the DB
    if (!$user || !password_verify($password, $user['password'])) {
        respond(401, ['error' => 'Incorrect email or password.']);
    }

    if (!$user['is_active']) {
        respond(403, ['error' => 'Your account has been deactivated. Contact an administrator.']);
    }

    // Save safe user data in the PHP session
    // Never put the password hash in the session
    $_SESSION['user'] = sessionPayload($user);

    respond(200, ['user' => $_SESSION['user']]);
}

// ─────────────────────────────────────────────────────────────
// REGISTER
// ─────────────────────────────────────────────────────────────
function handleRegister(): void {
    $username  = sanitizeText($_POST['username']  ?? '');
    $email     = sanitizeText($_POST['email']     ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    $errors = [];

    if (empty($username))
        $errors[] = 'Username is required.';
    if (strlen($username) < 3)
        $errors[] = 'Username must be at least 3 characters.';
    if (strlen($username) > 50)
        $errors[] = 'Username is too long (max 50 characters).';
    // Only allow letters, numbers, underscores — no spaces or special characters
    // preg_match checks if the username matches the allowed pattern
    if (!empty($username) && !preg_match('/^[a-zA-Z0-9_]+$/', $username))
        $errors[] = 'Username may only contain letters, numbers, and underscores.';

    if (empty($email))
        $errors[] = 'Email is required.';
    if (!empty($email) && !isValidEmail($email))
        $errors[] = 'Invalid email format.';

    if (strlen($password) < 6)
        $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password2)
        $errors[] = 'Passwords do not match.';

    if (!empty($errors)) respond(422, ['errors' => $errors]);

    $pdo = getDB();

    // Check if this email is already registered
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        respond(409, ['errors' => ['This email address is already registered.']]);
    }

    // Check if username is taken
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        respond(409, ['errors' => ['This username is already taken.']]);
    }

    // Hash the password using bcrypt before storing
    // Never store plain text passwords in a database
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, "student")'
    );
    $stmt->execute([$username, $email, $hash]);
    $newId = (int) $pdo->lastInsertId();

    // Log the new user in automatically after registration
    $_SESSION['user'] = [
        'id'       => $newId,
        'username' => $username,
        'email'    => $email,
        'role'     => 'student',
        'active'   => true,
    ];

    sendEmail(
        $email,
        'Welcome to StudentBlog',
        "Hi $username,\n\nYour StudentBlog account has been created. You can now write posts, comment, and track moderation feedback.\n\nStudentBlog",
        '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p><p>Your StudentBlog account has been created. You can now write posts, comment, and track moderation feedback.</p><p>StudentBlog</p>'
    );

    respond(201, ['user' => $_SESSION['user']]);
}

// ─────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────
function handleGoogleLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'Method not allowed.']);
    }

    if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
        respond(500, ['error' => 'Google OAuth is not configured on the server.']);
    }

    $credential = $_POST['credential'] ?? '';
    if ($credential === '') {
        respond(422, ['error' => 'Google credential is required.']);
    }

    $info = fetchGoogleTokenInfo($credential);

    if (($info['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
        respond(401, ['error' => 'Google token audience does not match this application.']);
    }
    if (($info['email_verified'] ?? 'false') !== 'true') {
        respond(401, ['error' => 'Google email is not verified.']);
    }

    $googleId = sanitizeText($info['sub'] ?? '');
    $email = sanitizeText($info['email'] ?? '');
    $name = sanitizeText($info['name'] ?? '');

    if ($googleId === '' || !isValidEmail($email)) {
        respond(401, ['error' => 'Google account data is incomplete.']);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1');
    $stmt->execute([$googleId, $email]);
    $user = $stmt->fetch();

    if ($user) {
        if (!$user['is_active']) {
            respond(403, ['error' => 'Your account has been deactivated. Contact an administrator.']);
        }
        if (empty($user['google_id'])) {
            $stmt = $pdo->prepare('UPDATE users SET google_id = ?, auth_provider = "google" WHERE id = ?');
            $stmt->execute([$googleId, $user['id']]);
            $user['google_id'] = $googleId;
            $user['auth_provider'] = 'google';
        }
    } else {
        $username = uniqueUsername($pdo, $name, $email);
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password, google_id, auth_provider, role) VALUES (?, ?, ?, ?, "google", "student")'
        );
        $stmt->execute([$username, $email, $hash, $googleId]);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        $user = $stmt->fetch();

        sendEmail(
            $email,
            'Welcome to StudentBlog',
            "Hi {$user['username']},\n\nYour StudentBlog account was created with Google sign-in.\n\nStudentBlog",
            '<p>Hi ' . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . ',</p><p>Your StudentBlog account was created with Google sign-in.</p><p>StudentBlog</p>'
        );
    }

    $_SESSION['user'] = sessionPayload($user);
    respond(200, ['user' => $_SESSION['user']]);
}

function handleLogout(): void {
    // session_destroy removes all session data from the server
    session_destroy();
    respond(200, ['ok' => true]);
}

// ─────────────────────────────────────────────────────────────
// ME — check who is currently logged in
// ─────────────────────────────────────────────────────────────
function handleMe(): void {
    if (isset($_SESSION['user'])) {
        respond(200, ['user' => $_SESSION['user']]);
    } else {
        respond(200, ['user' => null]); // not logged in, but not an error
    }
}

function handleDeleteAccount(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'Method not allowed.']);
    }

    $sessionUser = $_SESSION['user'] ?? null;
    if (!$sessionUser) {
        respond(401, ['error' => 'You must be logged in to delete your account.']);
    }

    $confirm = sanitizeText($_POST['confirm'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($confirm !== 'DELETE') {
        respond(422, ['errors' => ['Type DELETE to confirm permanent account deletion.']]);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$sessionUser['id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        respond(404, ['error' => 'Account not found.']);
    }

    if ((int)$user['id'] === 1 || $user['role'] === 'admin') {
        respond(403, ['error' => 'Admin accounts cannot be deleted from the profile page.']);
    }

    $provider = $user['auth_provider'] ?? 'local';
    if ($provider !== 'google') {
        if ($password === '') {
            respond(422, ['errors' => ['Current password is required to delete this account.']]);
        }
        if (!password_verify($password, $user['password'])) {
            respond(401, ['error' => 'Current password is incorrect.']);
        }
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    session_destroy();

    respond(200, ['message' => 'Your account has been permanently deleted.']);
}

// Update the logged-in user's profile.
function handleProfileUpdate(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'Method not allowed.']);
    }

    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        respond(401, ['error' => 'You must be logged in to update your profile.']);
    }

    $username = sanitizeText($_POST['username'] ?? '');
    $email    = sanitizeText($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $errors = [];
    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (strlen($username) > 50) {
        $errors[] = 'Username is too long (max 50 characters).';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username may only contain letters, numbers, and underscores.';
    }
    if (!isValidEmail($email)) {
        $errors[] = 'Invalid email format.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }

    if (!empty($errors)) respond(422, ['errors' => $errors]);

    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
    $stmt->execute([$username, $user['id']]);
    if ($stmt->fetch()) {
        respond(409, ['errors' => ['This username is already taken.']]);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) {
        respond(409, ['errors' => ['This email address is already registered.']]);
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?');
        $stmt->execute([$username, $email, $hash, $user['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
        $stmt->execute([$username, $email, $user['id']]);
    }

    $_SESSION['user']['username'] = $username;
    $_SESSION['user']['email']    = $email;

    respond(200, [
        'message' => 'Profile updated successfully.',
        'user'    => $_SESSION['user'],
    ]);
}
