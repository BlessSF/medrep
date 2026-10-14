<?php
// GET /api/employees/profile.php?name=&month=&year=
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();

$name = trim($_GET['name'] ?? '');
if ($name === '') json_error('name is required.');

$stmt = $conn->prepare("SELECT name, company FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ? LIMIT 1");
$stmt->bind_param('ss', $name, $branch);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$employee) json_error('Medrep not found.', 404);

$month = trim($_GET['month'] ?? '');
// An empty "year" now means "all years" instead of defaulting to the
// current year, so the transaction history can show the full history.
$year = trim($_GET['year'] ?? '');

$where = ['UPPER(name) = ?', 'branch = ?'];
$params = [strtoupper($name), $branch];
$types = 'ss';
$date_column = 'created_at';
if ($month !== '' && $year !== '') {
    $where[] = 'MONTH(created_at) = ? AND YEAR(created_at) = ?';
    $params[] = (int)$month; $params[] = (int)$year;
    $types .= 'ii';
} elseif ($month !== '') {
    // Month selected with "All years": match that month across every year.
    $where[] = 'MONTH(created_at) = ?';
    $params[] = (int)$month;
    $types .= 'i';
} elseif ($year !== '') {
    $where[] = 'YEAR(created_at) = ?';
    $params[] = (int)$year;
    $types .= 'i';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$stmt = $conn->prepare("SELECT * FROM employees $where_sql ORDER BY $date_column DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$balance = compute_balance($conn, $name, $branch); // always all-time, matches original
$blocked = is_blocked($conn, $name, $branch);

// Per-year breakdown, so past years' outstanding balance/payable can be
// surfaced even while viewing a different year (or "All years").
$yearly_summary = [];
$stmt = $conn->prepare("SELECT * FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ?");
$stmt->bind_param('ss', $name, $branch);
$stmt->execute();
$all_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$by_year = [];
foreach ($all_rows as $row) {
    $yr = date('Y', strtotime($row['created_at']));
    if (!isset($by_year[$yr])) $by_year[$yr] = [];
    $by_year[$yr][] = $row;
}
foreach ($by_year as $yr => $rows) {
    $net = 0.0;
    foreach ($rows as $row) {
        $method = strtolower(trim(str_replace(["\r", "\n"], '', $row['payment_method'] ?? '')));
        $is_card = $method === 'card';
        $payable = $is_card ? 0 : ((float)$row['dinein'] + (float)$row['takeout'] + (float)$row['giftcard']);
        $cashout_part = $is_card ? 0 : ((float)$row['cashout'] + (float)$row['interest']);
        $net += (float)$row['deposit'] + (float)$row['receiver_amount'] - $payable - $cashout_part - (float)$row['sender_amount'];
    }
    if (abs($net) > 0.001) {
        $yearly_summary[] = ['year' => (int)$yr, 'net' => $net];
    }
}
usort($yearly_summary, fn($a, $b) => $b['year'] <=> $a['year']);

json_success([
    'employee' => $employee,
    'records' => $records,
    'totals' => $balance,
    'blocked' => $blocked,
    'yearly_summary' => $yearly_summary,
]);