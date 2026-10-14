<?php
// POST /api/branches/delete.php { name }
// Admin-only. Permanently deletes a branch AND everything under it
// (employees/transactions, companies, users, logs). This cannot be undone.
require_once __DIR__ . '/../../includes/api_helpers.php';
$username = require_login();
require_role($conn, $username, ['admin']);
$current_branch = current_branch();

$data = json_input();
$name = strtoupper(trim($data['name'] ?? ''));
if ($name === '') json_error('Branch name is required.');

if ($name === $current_branch) {
    json_error("You can't delete the branch you're currently signed in to. Log in to a different branch first.");
}

$count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM branches");
$count_stmt->execute();
$total_branches = (int)($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
if ($total_branches <= 1) {
    json_error('At least one branch must always exist.');
}

$check = $conn->prepare("SELECT id FROM branches WHERE name = ?");
$check->bind_param('s', $name);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    json_error('Branch not found.', 404);
}

$conn->begin_transaction();
$ok = true;
foreach (['employees', 'companies', 'users', 'logs'] as $table) {
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE branch = ?");
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) { $ok = false; break; }
}
if ($ok) {
    $stmt = $conn->prepare("DELETE FROM branches WHERE name = ?");
    $stmt->bind_param('s', $name);
    $ok = $stmt->execute();
}

if (!$ok) {
    $conn->rollback();
    json_error('Could not delete branch: ' . $conn->error, 500);
}
$conn->commit();

log_action($conn, $username, 'delete_branch', "Deleted branch \"$name\" and all its data.");
json_success(['message' => "Branch \"$name\" and all its data have been permanently deleted."]);