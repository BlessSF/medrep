<?php
// GET /api/reports/cashout.php?month=&year=&chart_year=  (chart_year -> 12-month breakdown for charts)
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();

$selected_month = (int)($_GET['month'] ?? date('m'));
$selected_year = (int)($_GET['year'] ?? date('Y'));

$stmt = $conn->prepare("SELECT COALESCE(SUM(cashout),0) AS total_cashout, COALESCE(SUM(interest),0) AS total_interest
    FROM employees WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND branch = ?");
$stmt->bind_param('iis', $selected_month, $selected_year, $branch);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT name, cashout, interest, created_at FROM employees
    WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND (cashout > 0 OR interest > 0) AND branch = ? ORDER BY created_at DESC");
$stmt->bind_param('iis', $selected_month, $selected_year, $branch);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chart = null;
if (isset($_GET['chart_year'])) {
    $chart_year = (int)$_GET['chart_year'];
    $chart = ['cashout' => [], 'interest' => []];
    for ($m = 1; $m <= 12; $m++) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(cashout),0) AS c, COALESCE(SUM(interest),0) AS i FROM employees WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND branch = ?");
        $stmt->bind_param('iis', $m, $chart_year, $branch);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $chart['cashout'][$m] = (float)$r['c'];
        $chart['interest'][$m] = (float)$r['i'];
        $stmt->close();
    }
}

json_success(['summary' => $summary, 'rows' => $rows, 'chart' => $chart, 'month' => $selected_month, 'year' => $selected_year]);