<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Cross-origin (Vite dev server on :5173 -> PHP on :8000) needs
    // SameSite=None + Secure=false to still send/receive the session
    // cookie over plain http on localhost.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function current_branch(): ?string
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    return $user['role'] === 'admin' ? null : $user['username'];
}

function require_login_api(): void
{
    if (!current_user()) {
        json_response(['ok' => false, 'error' => 'Not logged in.'], 401);
    }
}

/** For non-JSON endpoints (file downloads) — plain 401 instead of a JSON body. */
function require_login_or_401(): void
{
    if (!current_user()) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo 'Not logged in.';
        exit;
    }
}

function attempt_login(string $username, string $password): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
        ];
        return $_SESSION['user'];
    }
    return null;
}

function do_logout(): void
{
    $_SESSION = [];
    session_destroy();
}
