<?php
// GET /api/reports/receivables.php?type=receivable|payable&scope=alltime|filtered|date&month=&year=&date=&range_from=&range_to=
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();

$type = (($_GET['type'] ?? 'receivable') === 'payable') ? 'payable' : 'receivable';
$scope = in_array($_GET['scope'] ?? '', ['alltime', 'filtered', 'date'], true) ? $_GET['scope'] : 'alltime';
$selected_month = (int)($_GET['month'] ?? 0);
$selected_year = (int)($_GET['year'] ?? 0);
$cutoff_date = trim($_GET['date'] ?? '');

// Optional custom date-range filter for scope=filtered -- an alternative to
// month/year that can span across a year boundary (e.g. Dec 2025 - Feb 2026).
$range_from = trim($_GET['range_from'] ?? '');
$range_to   = trim($_GET['range_to'] ?? '');
$use_range  = $range_from !== '' && $range_to !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_from)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_to);
if ($use_range && $range_from > $range_to) {
    [$range_from, $range_to] = [$range_to, $range_from];
}

$where_clauses = ["branch = '" . $conn->real_escape_string($branch) . "'", "(block IS NULL OR LOWER(TRIM(block)) <> 'block')"];
if ($scope === 'filtered') {
    if ($use_range) {
        $safe_from = $conn->real_escape_string($range_from);
        $safe_to = $conn->real_escape_string($range_to);
        $where_clauses[] = "DATE(created_at) BETWEEN '$safe_from' AND '$safe_to'";
    } else {
        if ($selected_year != 0) $where_clauses[] = "YEAR(created_at) = $selected_year";
        if ($selected_month != 0) $where_clauses[] = "MONTH(created_at) = $selected_month";
    }
}
if ($scope === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff_date)) {
    $safe_date = $conn->real_escape_string($cutoff_date);
    $where_clauses[] = "created_at <= '$safe_date 23:59:59'";
}
$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$sql = "
    SELECT name, MAX(company) AS company,
        SUM(COALESCE(deposit,0)) + SUM(COALESCE(receiver_amount,0)) AS dep,
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r', ''), '\\n', ''))) <> 'card'
                 THEN COALESCE(dinein,0) + COALESCE(takeout,0) + COALESCE(giftcard,0) ELSE 0 END) AS payable,
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r', ''), '\\n', ''))) <> 'card'
                 THEN COALESCE(cashout,0) + COALESCE(interest,0) ELSE 0 END) AS cashout_and_interest,
        SUM(COALESCE(sender_amount,0)) AS sender_sum
    FROM employees
    $where_sql
    GROUP BY name
";
$result = $conn->query($sql);
if (!$result) json_error('Query failed: ' . $conn->error, 500);

$by_company = [];
$grand_total = 0;
while ($row = $result->fetch_assoc()) {
    $net = (float)$row['dep'] - (float)$row['sender_sum'] - ((float)$row['payable'] + (float)$row['cashout_and_interest']);
    if ($type === 'receivable' && $net >= 0) continue;
    if ($type === 'payable' && $net <= 0) continue;

    $company = ($row['company'] !== null && $row['company'] !== '') ? $row['company'] : 'UNASSIGNED';
    if (!isset($by_company[$company])) $by_company[$company] = ['rows' => [], 'subtotal' => 0];
    $by_company[$company]['rows'][] = ['name' => $row['name'], 'balance' => $net];
    $by_company[$company]['subtotal'] += $net;
    $grand_total += $net;
}
ksort($by_company);
foreach ($by_company as &$group) {
    usort($group['rows'], function ($a, $b) use ($type) {
        return $type === 'receivable' ? $a['balance'] <=> $b['balance'] : $b['balance'] <=> $a['balance'];
    });
}
unset($group);

$total_medreps = array_sum(array_map(fn($g) => count($g['rows']), $by_company));

json_success([
    'type' => $type, 'scope' => $scope,
    'by_company' => $by_company,
    'grand_total' => $grand_total,
    'total_medreps' => $total_medreps,
    'use_range' => $use_range,
    'range_from' => $use_range ? $range_from : null,
    'range_to' => $use_range ? $range_to : null,
]);