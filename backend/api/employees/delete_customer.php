<?php
// POST /api/employees/delete_customer.php { name }
require_once __DIR__ . '/../../includes/api_helpers.php';
$admin_username = require_login();
$branch = current_branch();

$data = json_input();
$name = trim($data['name'] ?? '');
if ($name === '') json_error('Invalid request.');

$conn->begin_transaction();
try {
    $del_stmt = $conn->prepare('DELETE FROM employees WHERE name = ? AND branch = ?');
    $del_stmt->bind_param('ss', $name, $branch);
    $del_stmt->execute();
    $del_stmt->close();

    log_action($conn, $admin_username, 'Deleted a customer', "Deleted medrep name: $name");
    $conn->commit();
    json_success(['message' => 'Customer deleted successfully.', 'name' => $name]);
} catch (Exception $e) {
    $conn->rollback();
    json_error('Error deleting customer: ' . $conn->error, 500);
}