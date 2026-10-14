<?php
// POST /api/employees/unblock.php { name }
require_once __DIR__ . '/../../includes/api_helpers.php';
$admin_username = require_login();
$branch = current_branch();

$data = json_input();
$name = trim($data['name'] ?? '');
if ($name === '') json_error('No name parameter provided.');

$check = $conn->prepare('SELECT block FROM employees WHERE name = ? AND branch = ?');
$check->bind_param('ss', $name, $branch);
$check->execute();
$result = $check->get_result();
if ($result->num_rows === 0) { $check->close(); json_error('❌ Employee not found.'); }

$row = $result->fetch_assoc();
$check->close();
if ($row['block'] === 'unblock') json_error("⚠️ $name is already unblocked.");

$stmt = $conn->prepare("UPDATE employees SET block = 'unblock' WHERE name = ? AND branch = ?");
$stmt->bind_param('ss', $name, $branch);
if (!$stmt->execute()) json_error("❌ Failed to unblock $name.", 500);
$stmt->close();

log_action($conn, $admin_username, 'Unblocked medrep name', "Unblocked medrep name: $name");
json_success(['message' => "✅ Successfully unblocked $name."]);