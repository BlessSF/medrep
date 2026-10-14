<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_writer.php';
require_login_or_401();
$pdo = get_db();
ensure_check_date_column($pdo);
ensure_payment_method_column($pdo);

$type = $_GET['type'] ?? 'ledger';
$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : null;
if ($companyId && !can_access_company($pdo, $companyId)) {
    // Don't leak another branch's ledger via a guessed company_id.
    $companyId = null;
}

/** Maps an aging bucket label to the badge color key used by the writer. */
function bucket_badge(string $bucket): ?string
{
    return match ($bucket) {
        'Not Yet Due' => 'gray',
        '0-30 days' => 'green',
        '31-60 days' => 'amber',
        '61-90 days' => 'orange',
        'Over 90 days' => 'red',
        default => null,
    };
}

if ($type === 'companies') {
    [$branchSql, $branchParams] = branch_where_standalone('c');
    $stmt = $pdo->prepare("SELECT * FROM companies c{$branchSql} ORDER BY c.name ASC");
    $stmt->execute($branchParams);
    $companies = $stmt->fetchAll();

    $xlsx = new XlsxWriter();
    $sheet = $xlsx->addSheet('Companies', [
        ['header' => 'Name', 'width' => 26, 'type' => 'string'],
        ['header' => 'Code', 'width' => 12, 'type' => 'string'],
        ['header' => 'Status', 'width' => 12, 'type' => 'string', 'align' => 'center'],
        ['header' => 'Contact Person', 'width' => 20, 'type' => 'string'],
        ['header' => 'Contact Number', 'width' => 16, 'type' => 'string'],
        ['header' => 'Email', 'width' => 26, 'type' => 'string'],
        ['header' => 'Remarks', 'width' => 28, 'type' => 'string'],
    ]);
    foreach ($companies as $c) {
        $status = $c['status'] ?? '';
        $badge = strtolower($status) === 'active' ? 'green' : (strtolower($status) === 'inactive' ? 'gray' : null);
        $sheet->addRow([
            $c['name'], $c['code'], $status, $c['contact_person'], $c['contact_number'], $c['email_address'], $c['remarks'],
        ], $badge !== null ? [2 => $badge] : []);
    }
    $xlsx->output('companies.xlsx');
}

if ($type === 'aging') {
    [$branchSql, $branchParams] = branch_where('c');
    $stmt = $pdo->prepare(
        "SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id=l.company_id WHERE l.balance > 0.009 {$branchSql} ORDER BY c.name"
    );
    $stmt->execute($branchParams);
    $entries = $stmt->fetchAll();

    $xlsx = new XlsxWriter();
    $sheet = $xlsx->addSheet('Aging Report', [
        ['header' => 'Company', 'width' => 22, 'type' => 'string'],
        ['header' => 'Billing Date', 'width' => 13, 'type' => 'date'],
        ['header' => 'SOA #', 'width' => 14, 'type' => 'string'],
        ['header' => 'Due Date', 'width' => 13, 'type' => 'date'],
        ['header' => 'Balance', 'width' => 14, 'type' => 'money'],
        ['header' => 'Days Overdue', 'width' => 14, 'type' => 'int'],
        ['header' => 'Aging Bucket', 'width' => 16, 'type' => 'string', 'align' => 'center'],
    ]);
    $sheet->freezeFirstCol = true;
    $totalBalance = 0.0;
    foreach ($entries as $r) {
        $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
        $d = days_overdue($due);
        $bucket = aging_bucket($d);
        $badge = bucket_badge($bucket);
        $totalBalance += (float) $r['balance'];
        $sheet->addRow(
            [$r['company_name'], $r['billing_date'], $r['soa_number'], $due, (float) $r['balance'], $d, $bucket],
            $badge !== null ? [4 => $badge, 6 => $badge] : []
        );
    }
    $sheet->setTotals(['TOTAL', null, null, null, $totalBalance, null, null]);
    $xlsx->output('aging_report.xlsx');
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

$companyName = null;
if ($companyId) {
    $stmt2 = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $stmt2->execute([$companyId]);
    $companyName = $stmt2->fetchColumn() ?: null;
}

$xlsx = new XlsxWriter();
$sheet = $xlsx->addSheet('Receivable Ledger', [
    ['header' => 'Company', 'width' => 22, 'type' => 'string'],
    ['header' => 'Billing Date', 'width' => 13, 'type' => 'date'],
    ['header' => 'SOA #', 'width' => 14, 'type' => 'string'],
    ['header' => 'Amount', 'width' => 13, 'type' => 'money'],
    ['header' => 'Tax Withheld', 'width' => 13, 'type' => 'money'],
    ['header' => 'Surcharge', 'width' => 12, 'type' => 'money'],
    ['header' => 'Rebate', 'width' => 12, 'type' => 'money'],
    ['header' => 'Receivable', 'width' => 13, 'type' => 'money'],
    ['header' => 'Payment Date', 'width' => 13, 'type' => 'date'],
    ['header' => 'Check #', 'width' => 18, 'type' => 'string'],
    ['header' => 'Check Date', 'width' => 13, 'type' => 'date'],
    ['header' => 'Paid', 'width' => 13, 'type' => 'money'],
    ['header' => 'Balance', 'width' => 13, 'type' => 'money'],
    ['header' => 'Payment Method', 'width' => 20, 'type' => 'string'],
    ['header' => 'Due Date', 'width' => 13, 'type' => 'date'],
    ['header' => 'Remarks', 'width' => 26, 'type' => 'string'],
]);
$sheet->freezeFirstCol = true;

$totals = ['amount' => 0.0, 'tax_withheld' => 0.0, 'surcharge' => 0.0, 'rebate' => 0.0, 'receivable_amount' => 0.0, 'paid_amount' => 0.0, 'balance' => 0.0];
foreach ($entries as $r) {
    $method = $r['payment_method'] ?? '';
    if ($method === 'Card') {
        $bank = $r['payment_method_bank'] ?? '';
        $method = 'Card' . ($bank ? ' - ' . ($bank === 'Other' ? ($r['payment_method_other'] ?? 'Other') : $bank) : '');
    }
    $balance = (float) $r['balance'];
    $badge = $balance <= 0.009 ? 'green' : null; // paid balances shown in green; unpaid stays neutral (no aging context here)
    foreach (array_keys($totals) as $k) {
        $totals[$k] += (float) ($r[$k] ?? 0);
    }
    $sheet->addRow([
        $r['company_name'], $r['billing_date'], $r['soa_number'], (float) $r['amount'], (float) $r['tax_withheld'],
        (float) $r['surcharge'], (float) $r['rebate'], (float) $r['receivable_amount'], $r['payment_date'], $r['check_ref'],
        $r['check_date'] ?? '', (float) $r['paid_amount'], $balance, $method, $r['due_date'], $r['remarks'],
    ], $badge !== null ? [12 => $badge] : []);
}
$sheet->setTotals([
    'TOTAL', null, null, $totals['amount'], $totals['tax_withheld'], $totals['surcharge'], $totals['rebate'],
    $totals['receivable_amount'], null, null, null, $totals['paid_amount'], $totals['balance'], null, null, null,
]);

$filename = $companyId ? 'ledger_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $companyName ?? (string) $companyId) . '.xlsx' : 'full_receivable_ledger.xlsx';
$xlsx->output($filename);
