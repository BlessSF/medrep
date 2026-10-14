<?php
// Admin-only: creates a brand-new, completely empty branch, plus its first
// admin user (branches have their own separate user accounts, so a branch
// is unusable until it has at least one login).
require_once __DIR__ . '/../../includes/api_helpers.php';
$username = require_login();
require_role($conn, $username, ['admin']);

$data = json_input();
$name = strtoupper(trim($data['name'] ?? ''));
$admin_username = strtolower(trim($data['admin_username'] ?? ''));
$admin_password = trim($data['admin_password'] ?? '');

if ($name === '' || $admin_username === '' || $admin_password === '') {
    json_error('Branch name, admin username, and admin password are all required.');
}
if (!preg_match('/^[A-Z0-9 _-]+$/', $name)) {
    json_error('Branch name can only contain letters, numbers, spaces, - and _.');
}

$check = $conn->prepare("SELECT id FROM branches WHERE name = ?");
$check->bind_param('s', $name);
$check->execute();
$existing_branch = $check->get_result()->fetch_assoc();

if ($existing_branch) {
    // Branch row already exists. Only allow proceeding if it's an orphan
    // (e.g. an earlier attempt registered the branch but the admin user
    // creation failed) — never let this re-seed a branch that's actually in use.
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE branch = ?");
    $count_stmt->bind_param('s', $name);
    $count_stmt->execute();
    $user_count = (int)($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    if ($user_count > 0) {
        json_error('A branch with that name already exists.', 409);
    }
    // else: fall through and just create the missing admin user below.
}

$conn->begin_transaction();

if (!$existing_branch) {
    $stmt = $conn->prepare("INSERT INTO branches (name) VALUES (?)");
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        $conn->rollback();
        json_error('Could not save the branch: ' . $conn->error, 500);
    }
}

$hash = password_hash($admin_password, PASSWORD_DEFAULT);
$userStmt = $conn->prepare("INSERT INTO users (username, password, role, branch) VALUES (?, ?, 'admin', ?)");
$userStmt->bind_param('sss', $admin_username, $hash, $name);
if (!$userStmt->execute()) {
    $conn->rollback();
    // Most common cause: the `users` table still has a UNIQUE index on
    // `username` ALONE (from before branches existed), so an admin_username
    // that already exists in another branch (e.g. "admin") collides here,
    // even though it should be allowed once usernames are unique per-branch.
    json_error('Could not create the admin account: ' . $conn->error
        . '. If this mentions a duplicate/unique key on username, your users table '
        . 'still needs the composite (branch, username) unique index — see the notes '
        . 'in migrations/001_add_branches.sql.', 500);
}

$conn->commit();

log_action($conn, $username, 'create_branch', "Created branch \"$name\" with admin \"$admin_username\".");
json_success(['message' => "Branch \"$name\" created with admin account \"$admin_username\"."]);