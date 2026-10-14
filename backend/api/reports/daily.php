<?php
// GET /api/reports/daily.php?month=&year=  (0 = all)
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();

function branch_net_totals(mysqli $conn, string $branch, string $extra_where = '', array $extra_params = [], string $extra_types = ''): array {
    $where = ["branch = ?", "(block IS NULL OR LOWER(TRIM(block)) <> 'block')"];
    $params = [$branch];
    $types = 's';
    if ($extra_where !== '') {
        $where[] = $extra_where;
        $params = array_merge($params, $extra_params);
        $types .= $extra_types;
    }
    $where_sql = implode(' AND ', $where);
    $sql = "SELECT
        SUM(CASE WHEN net > 0 THEN net ELSE 0 END) AS payable,
        SUM(CASE WHEN net < 0 THEN net ELSE 0 END) AS receivable
        FROM (
            SELECT name,
                SUM(deposit) + SUM(receiver_amount)
                - SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r',''), '\\n','')))<>'card' THEN dinein+takeout+giftcard ELSE 0 END)
                - SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r',''), '\\n','')))<>'card' THEN cashout+interest ELSE 0 END)
                - SUM(sender_amount) AS net
            FROM employees WHERE $where_sql GROUP BY name
        ) t";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['payable' => (float)($row['payable'] ?? 0), 'receivable' => (float)($row['receivable'] ?? 0)];
}

$selected_month = (int)($_GET['month'] ?? date('m'));
$selected_year = (int)($_GET['year'] ?? date('Y'));

$where = ['branch = ?'];
$params = [$branch];
$types = 's';
$period_where = [];
$period_params = [];
$period_types = '';
if ($selected_year != 0) {
    $where[] = 'YEAR(created_at) = ?'; $params[] = $selected_year; $types .= 'i';
    $period_where[] = 'YEAR(created_at) = ?'; $period_params[] = $selected_year; $period_types .= 'i';
}
if ($selected_month != 0) {
    $where[] = 'MONTH(created_at) = ?'; $params[] = $selected_month; $types .= 'i';
    $period_where[] = 'MONTH(created_at) = ?'; $period_params[] = $selected_month; $period_types .= 'i';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// Deposits from transfers (sender_name set) are excluded from the "deposit"
// bucket, matching daily.php's per-row logic (only genuine cash deposits count).
$sql = "SELECT DATE(created_at) AS transaction_date,
        SUM(dinein) AS dinein,
        SUM(takeout) AS takeout,
        SUM(CASE WHEN sender_name IS NULL OR sender_name = '' THEN deposit ELSE 0 END) AS deposit,
        SUM(cashout) AS cashout,
        SUM(giftcard) AS giftcard,
        SUM(interest) AS interest,
        SUM(sender_amount) AS sender_amount,
        COUNT(*) AS record_count
    FROM employees $where_sql
    GROUP BY DATE(created_at)
    ORDER BY transaction_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// "As of that date" snapshot: branch-wide net balance across ALL medreps,
// split into payable (positive nets) and receivable (negative nets),
// computed independently per row using that row's date as the cutoff.
// This is the same technique as the Receivables page's "As of Date" scope.
foreach ($days as &$day) {
    $cutoff = $day['transaction_date'] . ' 23:59:59';
    $snap = branch_net_totals($conn, $branch, 'created_at <= ?', [$cutoff], 's');
    $day['total_sales'] = (float)$day['dinein'] + (float)$day['takeout'];
    $day['total_payable_snapshot'] = $snap['payable'];
    $day['total_receivable_snapshot'] = $snap['receivable'];
}
unset($day);

$all_time = branch_net_totals($conn, $branch);
$selected_period_where = $period_where ? implode(' AND ', $period_where) : '';
$selected_period = $selected_period_where !== ''
    ? branch_net_totals($conn, $branch, $selected_period_where, $period_params, $period_types)
    : $all_time;

json_success([
    'days' => $days,
    'month' => $selected_month,
    'year' => $selected_year,
    'total_receivable_all_time' => $all_time['receivable'],
    'total_payable_all_time' => $all_time['payable'],
    'total_receivable_selected_period' => $selected_period['receivable'],
    'total_payable_selected_period' => $selected_period['payable'],
]);