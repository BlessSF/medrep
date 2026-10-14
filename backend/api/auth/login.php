<?php
require_once __DIR__ . '/../../includes/api_helpers.php';

$data = json_input();
$username = strtolower(trim($data['username'] ?? ''));
$password = trim($data['password'] ?? '');
$branch = trim($data['branch'] ?? '');

if ($username === '' || $password === '' || $branch === '') {
    json_error('Branch, username and password are required.');
}

$stmt = $conn->prepare("SELECT username, password, role, branch FROM users WHERE LOWER(username) = ? AND branch = ?");
$stmt->bind_param('ss', $username, $branch);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    json_error('User not found.', 401);
}

$user = $result->fetch_assoc();
if (!password_verify($password, $user['password'])) {
    json_error('Incorrect password.', 401);
}

$role = strtolower($user['role']);
if (!in_array($role, ['admin', 'cashier'], true)) {
    json_error('Invalid role assigned to the user.', 403);
}

$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $role;
$_SESSION['branch'] = $user['branch'];


json_success(['username' => $user['username'], 'role' => $role, 'branch' => $user['branch']]);