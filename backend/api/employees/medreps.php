<?php
// GET  /api/employees/medreps.php?company=&q=   -> list of medreps (name, company, balance, blocked)
// POST /api/employees/medreps.php                -> register a new medrep {medrep_name, medrep_company}
require_once __DIR__ . '/../../includes/api_helpers.php';
$username = require_login();
$branch = current_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $new_name = trim($data['medrep_name'] ?? '');
    $new_company = trim($data['medrep_company'] ?? '');

    if ($new_name === '' || $new_company === '') {
        json_error('Medrep name and company are both required.');
    }

    $check = $conn->prepare("SELECT id FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ? LIMIT 1");
    $check->bind_param('ss', $new_name, $branch);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        json_error("\"$new_name\" is already on the medrep list.");
    }
    $check->close();

    $remarks = 'Added via Medrep List';
    $payment_method = 'na';
    $insert = $conn->prepare("INSERT INTO employees
        (name, company, deposit, dinein, takeout, giftcard, remarks, payment_method, cashier, branch, created_at)
        VALUES (?, ?, 0, 0, 0, 0, ?, ?, ?, ?, NOW())");
    $insert->bind_param('ssssss', $new_name, $new_company, $remarks, $payment_method, $username, $branch);
    if (!$insert->execute()) {
        json_error('Could not add medrep: ' . $insert->error);
    }
    $insert->close();
    json_success(['message' => "\"$new_name\" added to $new_company."]);
}

// ---- GET: distinct medrep list with live balances ----
ensure_companies_table($conn, $branch);
$company = trim($_GET['company'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = ['branch = ?'];
$params = [$branch];
$types = 's';
if ($company !== '') { $where[] = 'company = ?'; $params[] = $company; $types .= 's'; }
if ($q !== '') { $where[] = '(name LIKE ? OR company LIKE ?)'; $like = "%$q%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }
$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT name, MAX(company) AS company, MAX(created_at) AS last_activity,
            MAX(CASE WHEN block = 'block' THEN 1 ELSE 0 END) AS blocked
        FROM employees $where_sql GROUP BY name ORDER BY name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$row) {
    $bal = compute_balance($conn, $row['name'], $branch);
    $row['total_deposit'] = $bal['total_deposit'];
    $row['total_payable'] = $bal['total_payable'];
    $row['total_cashout'] = $bal['total_cashout'];
    $row['balance'] = $bal['balance'];
    $row['giftcard_balance'] = $bal['giftcard_balance'];
    $row['blocked'] = (bool)$row['blocked'];
}
unset($row);

json_success(['medreps' => $rows]);