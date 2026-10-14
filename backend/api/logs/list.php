<?php
// GET /api/logs/list.php?month=&year=
require_once __DIR__ . '/../../includes/api_helpers.php';
$admin_username = require_login();
require_role($conn, $admin_username, ['admin']);
$branch = current_branch();

$selected_month = (int)($_GET['month'] ?? date('m'));
$selected_year = (int)($_GET['year'] ?? date('Y'));

$stmt = $conn->prepare('SELECT * FROM logs WHERE MONTH(timestamp) = ? AND YEAR(timestamp) = ? AND branch = ? ORDER BY timestamp DESC');
$stmt->bind_param('iis', $selected_month, $selected_year, $branch);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$year_stmt = $conn->prepare('SELECT DISTINCT YEAR(timestamp) AS year FROM logs WHERE branch = ? ORDER BY year DESC');
$year_stmt->bind_param('s', $branch);
$year_stmt->execute();
$years = array_column($year_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'year');
$year_stmt->close();

json_success(['logs' => $logs, 'years' => $years, 'month' => $selected_month, 'year' => $selected_year]);