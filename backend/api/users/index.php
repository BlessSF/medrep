<?php
// GET  /api/users/index.php               -> list users (admin only)
// POST /api/users/index.php {action:add|delete|change_password, ...}
require_once __DIR__ . '/../../includes/api_helpers.php';
$admin_username = require_login();
require_role($conn, $admin_username, ['admin']);
$branch = current_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $action = $data['action'] ?? '';

    if ($action === 'add') {
        $new_user = trim($data['new_username'] ?? '');
        $new_pass = trim($data['new_password'] ?? '');
        $new_role = $data['new_role'] ?? 'cashier';

        if ($new_user === '' || $new_pass === '') json_error('Please fill in all fields.');

        $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND branch = ?');
        $stmt->bind_param('ss', $new_user, $branch);
        $stmt->execute();
        $stmt->bind_result($userCount);
        $stmt->fetch();
        $stmt->close();
        if ($userCount > 0) json_error('Username already exists in this branch.');

        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role, branch) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $new_user, $hashed_pass, $new_role, $branch);
        if (!$stmt->execute()) { $stmt->close(); json_error('Error adding user.', 500); }
        $stmt->close();

        log_action($conn, $admin_username, 'Added new user', "Admin $admin_username added user $new_user");
        json_success(['message' => 'User added successfully.']);
    }

    if ($action === 'delete') {
        $usernameToDelete = $data['delete_username'] ?? '';
        if ($usernameToDelete === $admin_username) json_error("You can't delete your own account while logged in.");
        $stmt = $conn->prepare('DELETE FROM users WHERE username = ? AND branch = ?');
        $stmt->bind_param('ss', $usernameToDelete, $branch);
        $stmt->execute();
        $stmt->close();
        log_action($conn, $admin_username, 'Deleted user', "Admin $admin_username deleted user $usernameToDelete");
        json_success(['message' => 'User deleted.']);
    }

    if ($action === 'change_password') {
        $usernameToChange = $data['change_password_username'] ?? '';
        $newPassword = password_hash($data['new_password'] ?? '', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE username = ? AND branch = ?');
        $stmt->bind_param('sss', $newPassword, $usernameToChange, $branch);
        $stmt->execute();
        $stmt->close();
        log_action($conn, $admin_username, 'Changed password', "Admin $admin_username changed password for user $usernameToChange");
        json_success(['message' => 'Password changed.']);
    }

    json_error('Unknown action.');
}

$stmt = $conn->prepare('SELECT username, role FROM users WHERE branch = ? ORDER BY username ASC');
$stmt->bind_param('s', $branch);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
json_success(['users' => $users, 'current_username' => $admin_username]);