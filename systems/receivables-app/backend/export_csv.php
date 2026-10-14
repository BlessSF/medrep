<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login_or_401();

$pdo = get_db();
ensure_check_date_column($pdo);
ensure_payment_method_column($pdo);

$type = $_GET['type'] ?? 'ledger';
$companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : null;

function csv_out(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if ($companyId && !can_access_company($pdo, $companyId)) {
    // Don't leak another branch's ledger via a guessed company_id.
    $companyId = null;
}

if ($type === 'companies') {
    [$branchSql, $branchParams] = branch_where_standalone('c');
    $stmt = $pdo->prepare("SELECT * FROM companies c{$branchSql} ORDER BY c.name ASC");
    $stmt->execute($branchParams);
    $companies = $stmt->fetchAll();
    $rows = array_map(fn($c) => [
        $c['name'], $c['code'], $c['status'], $c['contact_person'], $c['contact_number'], $c['email_address'], $c['remarks'],
    ], $companies);
    csv_out('companies.csv', ['Name', 'Code', 'Status', 'Contact Person', 'Contact Number', 'Email', 'Remarks'], $rows);
}

if ($type === 'aging') {
    [$branchSql, $branchParams] = branch_where('c');
    $stmt = $pdo->prepare(
        "SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id=l.company_id WHERE l.balance > 0.009 {$branchSql} ORDER BY c.name"
    );
    $stmt->execute($branchParams);
    $entries = $stmt->fetchAll();
    $rows = [];
    foreach ($entries as $r) {
        $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
        $d = days_overdue($due);
        $bucket = aging_bucket($d);
        $rows[] = [$r['company_name'], $r['billing_date'], $r['soa_number'], $due, $r['balance'], $d, $bucket];
    }
    csv_out('aging_report.csv', ['Company', 'Billing Date', 'SOA #', 'Due Date', 'Balance', 'Days Overdue', 'Aging Bucket'], $rows);
}

if ($type === 'past_due') {
    [$branchSql, $branchParams] = branch_where('c');
    $stmt = $pdo->prepare(
        "SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id=l.company_id WHERE l.balance > 0.009 {$branchSql} ORDER BY c.name"
    );
    $stmt->execute($branchParams);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
        $d = days_overdue($due);
        if ($d !== null && $d > 0) {
            $rows[] = [$r['company_name'], $r['billing_date'], $due, $d, $r['balance']];
        }
    }
    csv_out('past_due.csv', ['Company', 'Billing Date', 'Due Date', 'Days Overdue', 'Balance'], $rows);
}

// default: ledger
$sql = 'SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id=l.company_id';
$params = [];
if ($companyId) {
    $sql .= ' WHERE l.company_id=?';
    $params[] = $companyId;
} else {
    [$branchSql, $branchParams] = branch_where_standalone('c');
    $sql .= $branchSql;
    $params = array_merge($params, $branchParams);
}
$sql .= ' ORDER BY c.name ASC, l.billing_date ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$rows = array_map(function ($r) {
    $method = $r['payment_method'] ?? '';
    if ($method === 'Card') {
        $bank = $r['payment_method_bank'] ?? '';
        $method = 'Card' . ($bank ? ' - ' . ($bank === 'Other' ? ($r['payment_method_other'] ?? 'Other') : $bank) : '');
    }
    return [
        $r['company_name'], $r['billing_date'], $r['soa_number'], $r['amount'], $r['tax_withheld'],
        $r['surcharge'], $r['rebate'], $r['receivable_amount'], $r['payment_date'], $r['check_ref'], $r['check_date'] ?? '',
        $r['paid_amount'], $r['balance'], $method, $r['due_date'], $r['remarks'],
    ];
}, $entries);

$filename = $companyId ? 'ledger_company_' . $companyId . '.csv' : 'full_receivable_ledger.csv';
csv_out($filename, [
    'Company', 'Billing Date', 'SOA #', 'Amount', 'Tax Withheld', 'Surcharge', 'Rebate',
    'Receivable', 'Payment Date', 'Check #', 'Check Date', 'Paid', 'Balance', 'Payment Method', 'Due Date', 'Remarks',
], $rows);
