<?php
// DELETE/POST /api/employees/transaction/delete.php  { id }
require_once __DIR__ . '/../../../includes/api_helpers.php';
$admin_username = require_login();
$branch = current_branch();

$data = json_input();
$id = (int)($data['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) json_error('Invalid ID.');

$stmt = $conn->prepare("SELECT name, deposit, dinein, takeout, cashout, interest, sender_amount, giftcard FROM employees WHERE id = ? AND branch = ?");
$stmt->bind_param('is', $id, $branch);
$stmt->execute();
$stmt->bind_result($employeeName, $deposit, $dinein, $takeout, $cashout, $interest, $transfer_amount, $giftcard);
$found = $stmt->fetch();
$stmt->close();

if (!$found || !$employeeName) json_error('Employee not found.', 404);

$columns = ['deposit' => $deposit, 'dinein' => $dinein, 'takeout' => $takeout, 'cashout' => $cashout,
    'interest' => $interest, 'sender_amount' => $transfer_amount, 'giftcard' => $giftcard];
$amount = 0; $columnName = '';
foreach ($columns as $column => $value) {
    if ($value > 0) { $amount = $value; $columnName = $column; break; }
}

$stmt = $conn->prepare('DELETE FROM employees WHERE id = ? AND branch = ?');
$stmt->bind_param('is', $id, $branch);
if (!$stmt->execute()) json_error('Error deleting employee: ' . $stmt->error, 500);
$stmt->close();

$details = $amount > 0
    ? "Deleted transaction from $employeeName with the amount $amount in the column '$columnName'"
    : "Deleted transaction from $employeeName with no specific amount found.";
log_action($conn, $admin_username, 'Deleted transaction', $details);

$stmt = $conn->prepare('SELECT COUNT(*) AS count FROM employees WHERE name = ? AND branch = ?');
$stmt->bind_param('ss', $employeeName, $branch);
$stmt->execute();
$count = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
$stmt->close();

json_success(['id' => $id, 'employee_still_exists' => $count > 0]);