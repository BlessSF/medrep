<?php
/**
 * Single-entry JSON API for the React (Vite) frontend.
 * All requests hit /api.php?action=xxx (GET) or POST with a JSON body
 * that may itself contain an "action" field.
 */

// ---- CORS (Vite dev server runs on a different port than php -S) ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Merge GET params + JSON body (POST) into one $input array.
$body = [];
$rawInput = file_get_contents('php://input');
if ($rawInput) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
$input = array_merge($_GET, $body);
$action = $input['action'] ?? '';

$pdo = get_db();

// Run lightweight column/table migrations up front so every action below
// can assume the schema is current, regardless of which one runs first.
ensure_company_account_type_column($pdo);
ensure_deduction_remarks_column($pdo);
ensure_check_date_column($pdo);
ensure_payment_method_column($pdo);
ensure_ledger_company_name_column($pdo);
ensure_soa_status_column($pdo);
ensure_soa_tracker_duplicates_allowed($pdo);
ensure_balance_formula_migrated($pdo);

const PAYMENT_METHODS = ['Cash', 'Card', 'Check'];
const PAYMENT_BANKS = ['Metrobank', 'BPI', 'PBB', 'Maya', 'Other'];

function current_user_payload(): ?array
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'full_name' => $u['full_name'],
        'role' => $u['role'],
        'is_admin' => $u['role'] === 'admin',
    ];
}

// ------------------------------------------------------------------
// Public (no login required) actions
// ------------------------------------------------------------------
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $user = attempt_login($username, $password);
    if ($user) {
        json_response(['ok' => true, 'user' => current_user_payload()]);
    }
    json_response(['ok' => false, 'error' => 'Invalid username or password.'], 401);
}

if ($action === 'me') {
    json_response(['ok' => true, 'user' => current_user_payload()]);
}

if ($action === 'logout') {
    do_logout();
    json_response(['ok' => true]);
}

// ------------------------------------------------------------------
// Everything below requires a logged-in session
// ------------------------------------------------------------------
require_login_api();

function recalc_row(array $d): array
{
    $amount = (float) ($d['amount'] ?? 0);
    $tax = (float) ($d['tax_withheld'] ?? 0);
    $surcharge = (float) ($d['surcharge'] ?? 0);
    $rebate = (float) ($d['rebate'] ?? 0);
    $paid = (float) ($d['paid_amount'] ?? 0);
    $receivable = $amount - $tax + $surcharge - $rebate;
    $balance = $amount - $paid; // Balance = Amount - Paid
    return [$receivable, $balance];
}

function require_company_access($pdo, ?int $companyId): void
{
    if (!can_access_company($pdo, $companyId)) {
        json_response(['ok' => false, 'error' => 'You do not have access to that company.'], 403);
    }
}

switch ($action) {

    // ============================================================
    // DASHBOARD SUMMARY (KPIs shown on the dashboard page)
    // ============================================================
    case 'get_dashboard': {
        [$branchWhere, $branchParams] = branch_where('c');
        [$branchWhereStd, $branchParamsStd] = branch_where_standalone('c');

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.amount),0) FROM ledger_entries l JOIN companies c ON c.id = l.company_id WHERE 1=1 {$branchWhere}");
        $stmt->execute($branchParams);
        $totalBilled = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.paid_amount),0) FROM ledger_entries l JOIN companies c ON c.id = l.company_id WHERE 1=1 {$branchWhere}");
        $stmt->execute($branchParams);
        $totalPaid = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.balance),0) FROM ledger_entries l JOIN companies c ON c.id = l.company_id WHERE 1=1 {$branchWhere}");
        $stmt->execute($branchParams);
        $totalBalance = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies c{$branchWhereStd}");
        $stmt->execute($branchParamsStd);
        $totalCompanies = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies c WHERE c.status='ACTIVE'{$branchWhere}");
        $stmt->execute($branchParams);
        $activeCompanies = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT l.billing_date, l.due_date, l.balance FROM ledger_entries l JOIN companies c ON c.id = l.company_id WHERE l.balance > 0 {$branchWhere}");
        $stmt->execute($branchParams);
        $pastDueTotal = 0.0;
        $pastDueCount = 0;
        foreach ($stmt->fetchAll() as $r) {
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $d = days_overdue($due);
            if ($d !== null && $d > 0) {
                $pastDueTotal += (float) $r['balance'];
                $pastDueCount++;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.status, COALESCE(SUM(l.balance),0) as outstanding
             FROM companies c LEFT JOIN ledger_entries l ON l.company_id = c.id
             WHERE 1=1 {$branchWhere}
             GROUP BY c.id ORDER BY outstanding DESC LIMIT 10"
        );
        $stmt->execute($branchParams);
        $topOutstanding = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT l.*, c.name as company_name FROM ledger_entries l
             JOIN companies c ON c.id = l.company_id
             WHERE 1=1 {$branchWhere}
             ORDER BY l.created_at DESC, l.id DESC LIMIT 10"
        );
        $stmt->execute($branchParams);
        $recent = $stmt->fetchAll();

        json_response(['ok' => true, 'data' => [
            'total_billed' => $totalBilled,
            'total_paid' => $totalPaid,
            'total_balance' => $totalBalance,
            'total_companies' => $totalCompanies,
            'active_companies' => $activeCompanies,
            'collection_rate' => $totalBilled > 0 ? round($totalPaid / $totalBilled * 100, 1) : 0,
            'past_due_total' => $pastDueTotal,
            'past_due_count' => $pastDueCount,
            'top_outstanding' => $topOutstanding,
            'recent' => $recent,
        ]]);
        break;
    }

    // ============================================================
    // SUMMARY (searchable/filterable ledger view across companies)
    // ============================================================
    case 'get_summary': {
        $companyId = isset($input['company_id']) && $input['company_id'] !== '' ? (int) $input['company_id'] : null;
        if ($companyId) {
            require_company_access($pdo, $companyId);
        }
        $bucketFilterMap = [
            'not_yet_due' => 'Not Yet Due',
            '0-30' => '0-30 days',
            '31-60' => '31-60 days',
            '61-90' => '61-90 days',
            'over90' => 'Over 90 days',
        ];
        $agingFilter = $input['aging'] ?? '';
        if (!isset($bucketFilterMap[$agingFilter])) {
            $agingFilter = '';
        }
        $showAll = !empty($input['show_all']);
        $searchQuery = trim($input['q'] ?? '');

        $sql = "SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id = l.company_id";
        $where = [];
        $params = [];
        if (!$showAll) {
            $where[] = 'l.balance > 0.009';
        }
        if ($companyId) {
            $where[] = 'l.company_id = ?';
            $params[] = $companyId;
        } else {
            [$branchSql, $branchParams] = branch_where_standalone('c');
            if ($branchSql !== '') {
                $where[] = ltrim(preg_replace('/^\s*WHERE\s*/i', '', $branchSql));
                $params = array_merge($params, $branchParams);
            }
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.billing_date ASC, l.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $summary = [];
        foreach ($rows as $r) {
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $daysOverdue = days_overdue($due);
            $daysSinceBilling = days_overdue($r['billing_date']);
            $isPaid = (float) ($r['balance'] ?? 0) <= 0.009;

            $r['deduction'] = (float) ($r['balance'] ?? 0);
            $r['is_paid'] = $isPaid;
            $r['is_overdue'] = !$isPaid && $daysOverdue !== null && $daysOverdue > 0;
            $r['days'] = $r['is_overdue'] ? $daysOverdue : $daysSinceBilling;
            $r['aging_bucket'] = $isPaid ? 'Paid' : aging_bucket($daysOverdue);
            $summary[] = $r;
        }

        if ($agingFilter) {
            $wantedBucket = $bucketFilterMap[$agingFilter];
            $summary = array_values(array_filter($summary, fn($r) => $r['aging_bucket'] === $wantedBucket));
        }

        if ($searchQuery !== '') {
            $needle = mb_strtolower($searchQuery);
            $summary = array_values(array_filter($summary, function ($r) use ($needle) {
                $haystack = mb_strtolower(
                    ($r['company_name'] ?? '') . ' ' . ($r['deduction_remarks'] ?? '') . ' ' .
                    ($r['remarks'] ?? '') . ' ' . ($r['soa_number'] ?? '') . ' ' .
                    ($r['billing_date'] ?? '') . ' ' . ($r['amount'] ?? '')
                );
                return mb_strpos($haystack, $needle) !== false;
            }));
        }

        json_response(['ok' => true, 'data' => $summary]);
        break;
    }

    // ============================================================
    // COMPANIES
    // ============================================================
    case 'get_companies': {
        [$where, $params] = branch_where_standalone('c');
        $sql = "SELECT c.*, COALESCE(SUM(l.balance),0) as outstanding, COUNT(l.id) as entry_count
             FROM companies c LEFT JOIN ledger_entries l ON l.company_id = c.id
             {$where}
             GROUP BY c.id ORDER BY c.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        break;
    }

    case 'get_company': {
        $id = (int) ($input['id'] ?? 0);
        require_company_access($pdo, $id);
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }
        json_response(['ok' => true, 'data' => $row]);
        break;
    }

    case 'create_company':
    case 'update_company': {
        $name = trim($input['name'] ?? '');
        $code = trim($input['code'] ?? '');
        $status = in_array($input['status'] ?? '', ['ACTIVE', 'INACTIVE', 'BANKRUPT'], true) ? $input['status'] : 'ACTIVE';
        $accountType = ($input['account_type'] ?? 'COMPANY') === 'HMO' ? 'HMO' : 'COMPANY';
        $contact = trim($input['contact_person'] ?? '');
        $number = trim($input['contact_number'] ?? '');
        $email = trim($input['email_address'] ?? '');
        $remarks = trim($input['remarks'] ?? '');

        if (is_admin()) {
            $branch = trim($input['branch'] ?? '') ?: null;
        } else {
            $branch = current_branch();
        }

        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Company name is required.'], 400);
        }

        if ($action === 'create_company') {
            $stmt = $pdo->prepare('INSERT INTO companies (name, code, status, account_type, branch, contact_person, contact_number, email_address, remarks) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name, $code, $status, $accountType, $branch, $contact, $number, $email, $remarks]);
            json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => "Company \"$name\" added."]);
        } else {
            $editId = (int) ($input['id'] ?? 0);
            require_company_access($pdo, $editId);
            $stmt = $pdo->prepare('UPDATE companies SET name=?, code=?, status=?, account_type=?, branch=?, contact_person=?, contact_number=?, email_address=?, remarks=? WHERE id=?');
            $stmt->execute([$name, $code, $status, $accountType, $branch, $contact, $number, $email, $remarks, $editId]);
            ensure_ledger_company_name_column($pdo);
            json_response(['ok' => true, 'message' => "Company \"$name\" updated."]);
        }
        break;
    }

    case 'delete_company': {
        $id = (int) ($input['id'] ?? 0);
        require_company_access($pdo, $id);
        $stmt = $pdo->prepare('SELECT name FROM companies WHERE id=?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM companies WHERE id=?')->execute([$id]);
        json_response(['ok' => true, 'message' => "Company \"$name\" and its ledger entries were deleted."]);
        break;
    }

    case 'update_company_status': {
        $id = (int) ($input['id'] ?? 0);
        $newStatus = $input['status'] ?? '';
        if (!in_array($newStatus, ['ACTIVE', 'INACTIVE', 'BANKRUPT'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid status value.'], 400);
        }
        require_company_access($pdo, $id);
        $pdo->prepare('UPDATE companies SET status=? WHERE id=?')->execute([$newStatus, $id]);
        json_response(['ok' => true, 'message' => 'Status updated to ' . $newStatus . '.']);
        break;
    }

    case 'get_branches': {
        $branchList = $pdo->query("SELECT username, full_name FROM users WHERE role = 'branch' ORDER BY username ASC")->fetchAll();
        $branchStats = [];
        if (is_admin()) {
            $statRows = $pdo->query(
                "SELECT c.branch, COUNT(DISTINCT c.id) AS company_count, COALESCE(SUM(l.balance),0) AS outstanding
                 FROM companies c LEFT JOIN ledger_entries l ON l.company_id = c.id
                 GROUP BY c.branch"
            )->fetchAll();
            foreach ($statRows as $row) {
                $branchStats[$row['branch'] ?? ''] = $row;
            }
        }
        json_response(['ok' => true, 'data' => ['branches' => $branchList, 'stats' => $branchStats]]);
        break;
    }

    // ============================================================
    // LEDGER
    // ============================================================
    case 'get_ledger': {
        $companyId = isset($input['company_id']) && $input['company_id'] !== '' ? (int) $input['company_id'] : null;
        if ($companyId) {
            require_company_access($pdo, $companyId);
        }
        $sql = 'SELECT l.*, c.name as company_name, c.status as company_status FROM ledger_entries l JOIN companies c ON c.id = l.company_id';
        $params = [];
        if ($companyId) {
            $sql .= ' WHERE l.company_id = ?';
            $params[] = $companyId;
        } else {
            [$branchSql, $branchParams] = branch_where_standalone('c');
            $sql .= $branchSql;
            $params = $branchParams;
        }
        $sql .= ' ORDER BY l.billing_date DESC, l.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        $selectedCompany = null;
        if ($companyId) {
            $stmt = $pdo->prepare('SELECT * FROM companies WHERE id=?');
            $stmt->execute([$companyId]);
            $selectedCompany = $stmt->fetch() ?: null;
        }

        $agingTally = [];
        foreach ($entries as $r) {
            if ((float) $r['balance'] <= 0) {
                continue;
            }
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $bucket = aging_bucket(days_overdue($due));
            $agingTally[$bucket] = ($agingTally[$bucket] ?? 0) + (float) $r['balance'];
        }

        // Per-entry aging info (used to badge rows / cards as "overdue" vs "due in N days").
        foreach ($entries as &$r) {
            $isPaid = (float) ($r['balance'] ?? 0) <= 0.009;
            if ($isPaid) {
                $r['is_paid'] = true;
                $r['is_overdue'] = false;
                $r['days_overdue'] = null;
                $r['aging_bucket'] = 'Paid';
                continue;
            }
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $d = days_overdue($due);
            $r['is_paid'] = false;
            $r['is_overdue'] = $d !== null && $d > 0;
            $r['days_overdue'] = $d;
            $r['aging_bucket'] = aging_bucket($d);
        }
        unset($r);

        // "All companies" view: one card per company that actually has at
        // least one ledger entry (including fully-paid / zero-balance ones) —
        // empty placeholder companies with no entries at all are skipped.
        // Surfaces whichever entry is most urgent: worst overdue, or soonest due.
        $companySummary = [];
        if (!$companyId) {
            [$branchWhereStd, $branchParamsStd] = branch_where_standalone('c');
            $stmt = $pdo->prepare(
                "SELECT c.id, c.name, c.status, COUNT(l.id) as entry_count, COALESCE(SUM(l.balance),0) as outstanding
                 FROM companies c LEFT JOIN ledger_entries l ON l.company_id = c.id
                 {$branchWhereStd}
                 GROUP BY c.id"
            );
            $stmt->execute($branchParamsStd);
            $byCompany = [];
            foreach ($stmt->fetchAll() as $row) {
                if ((int) $row['entry_count'] === 0) {
                    continue; // no ledger entries at all — nothing to show
                }
                $byCompany[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'outstanding' => (float) $row['outstanding'],
                    'worst_days_overdue' => null, // highest positive days-overdue seen
                    'soonest_days_until_due' => null, // smallest days-until-due among not-yet-due entries
                ];
            }
            foreach ($entries as $r) {
                if ($r['is_paid']) {
                    continue;
                }
                $cid = (int) $r['company_id'];
                if (!isset($byCompany[$cid])) {
                    continue;
                }
                $d = $r['days_overdue'];
                if ($d !== null) {
                    if ($d > 0) {
                        if ($byCompany[$cid]['worst_days_overdue'] === null || $d > $byCompany[$cid]['worst_days_overdue']) {
                            $byCompany[$cid]['worst_days_overdue'] = $d;
                        }
                    } else {
                        $untilDue = -$d;
                        if ($byCompany[$cid]['soonest_days_until_due'] === null || $untilDue < $byCompany[$cid]['soonest_days_until_due']) {
                            $byCompany[$cid]['soonest_days_until_due'] = $untilDue;
                        }
                    }
                }
            }
            $companySummary = array_values($byCompany);
            usort($companySummary, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
        }

        json_response(['ok' => true, 'data' => [
            'entries' => $entries,
            'company' => $selectedCompany,
            'totals' => [
                'amount' => array_sum(array_column($entries, 'amount')),
                'paid' => array_sum(array_column($entries, 'paid_amount')),
                'balance' => array_sum(array_column($entries, 'balance')),
            ],
            'aging_tally' => $agingTally,
            'company_summary' => $companySummary,
        ]]);
        break;
    }

    case 'quick_add_ledger': {
        $cid = (int) ($input['company_id'] ?? 0);
        require_company_access($pdo, $cid);
        $cStmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
        $cStmt->execute([$cid]);
        $cName = $cStmt->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO ledger_entries (company_id, company_name, billing_date, amount, tax_withheld, surcharge, rebate, paid_amount, receivable_amount, balance)
             VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, 0)'
        );
        $stmt->execute([$cid, $cName ?: null, date('Y-m-d')]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        break;
    }

    case 'inline_update_ledger': {
        $allowed = ['billing_date', 'soa_number', 'amount', 'tax_withheld', 'surcharge', 'rebate',
            'payment_date', 'check_ref', 'check_date', 'paid_amount', 'due_date', 'remarks', 'deduction_remarks',
            'payment_method', 'payment_method_bank', 'payment_method_other'];
        $id = (int) ($input['id'] ?? 0);
        $field = $input['field'] ?? '';
        $value = $input['value'] ?? null;

        if (!$id || !in_array($field, $allowed, true)) {
            json_response(['ok' => false, 'error' => 'Invalid field.'], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM ledger_entries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Entry not found.'], 404);
        }
        require_company_access($pdo, (int) $row['company_id']);

        $numericFields = ['amount', 'tax_withheld', 'surcharge', 'rebate', 'paid_amount'];
        if (in_array($field, $numericFields, true)) {
            $value = $value === '' || $value === null ? 0 : (float) $value;
        } elseif (in_array($field, ['billing_date', 'payment_date', 'due_date', 'check_date'], true)) {
            $value = $value === '' ? null : $value;
        } elseif ($field === 'check_ref') {
            $value = trim((string) $value) ?: null;
        } elseif ($field === 'soa_number') {
            $value = strtoupper(trim((string) $value)) ?: null;
        } elseif ($field === 'payment_method') {
            $value = trim((string) $value);
            $value = in_array($value, PAYMENT_METHODS, true) ? $value : null;
        } elseif ($field === 'payment_method_bank') {
            $value = trim((string) $value);
            $value = in_array($value, PAYMENT_BANKS, true) ? $value : null;
        } else {
            $value = trim((string) $value) ?: null;
        }

        if ($field === 'soa_number' && $value && soa_number_conflicts($pdo, $value, $id)) {
            json_response(['ok' => false, 'error' => "SOA number \"$value\" is already in use. Add a letter like A or B for a supplementary copy, or use a different number."], 409);
        }

        $row[$field] = $value;
        [$receivable, $balance] = recalc_row($row);

        $upd = $pdo->prepare("UPDATE ledger_entries SET {$field} = ?, receivable_amount = ?, balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$value, $receivable, $balance, $id]);

        if ($field === 'soa_number') {
            if ($value) {
                record_soa_number_used($pdo, $value, $row['company_id'], $id);
            } else {
                $pdo->prepare("UPDATE soa_tracker SET ledger_entry_id = NULL, status = 'UNUSED' WHERE ledger_entry_id = ?")->execute([$id]);
            }
        }

        $dueDate = $row['due_date'] ?: implicit_due_date($row['billing_date'], $pdo);
        $bucket = aging_bucket(days_overdue($dueDate));

        json_response([
            'ok' => true,
            'receivable_amount' => $receivable,
            'balance' => $balance,
            'deduction' => $balance,
            'aging_bucket' => $bucket,
        ]);
        break;
    }

    case 'delete_ledger': {
        $id = (int) ($input['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT company_id FROM ledger_entries WHERE id=?');
        $stmt->execute([$id]);
        $cid = $stmt->fetchColumn();
        if (!$cid) {
            json_response(['ok' => false, 'error' => 'Entry not found.'], 404);
        }
        require_company_access($pdo, (int) $cid);
        $pdo->prepare('DELETE FROM ledger_entries WHERE id=?')->execute([$id]);
        json_response(['ok' => true, 'message' => 'Ledger entry deleted.']);
        break;
    }

    case 'get_settings': {
        json_response(['ok' => true, 'data' => [
            'aging_grace_days' => aging_grace_days($pdo),
            'tax_withheld_percent' => tax_withheld_percent($pdo),
        ]]);
        break;
    }

    case 'save_aging_setting': {
        $days = max(0, (int) ($input['aging_grace_days'] ?? 0));
        set_setting($pdo, 'aging_grace_days', (string) $days);
        json_response(['ok' => true, 'message' => "Aging now starts {$days} day" . ($days === 1 ? '' : 's') . ' after billing.']);
        break;
    }

    case 'save_tax_setting': {
        $percent = (float) ($input['tax_withheld_percent'] ?? 2);
        $percent = max(0, min(100, $percent));
        set_setting($pdo, 'tax_withheld_percent', (string) $percent);

        $recalcCount = 0;
        $redirectCid = isset($input['company_id']) ? (int) $input['company_id'] : null;
        if (!empty($input['recalc_existing']) && $redirectCid && can_access_company($pdo, $redirectCid)) {
            $rows = $pdo->prepare('SELECT id, amount, surcharge, rebate, paid_amount, receivable_amount FROM ledger_entries WHERE company_id = ?');
            $rows->execute([$redirectCid]);
            $upd = $pdo->prepare('UPDATE ledger_entries SET tax_withheld = ?, receivable_amount = ?, paid_amount = ?, balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            foreach ($rows->fetchAll() as $row) {
                $amount = (float) $row['amount'];
                $tax = round($amount * $percent / 100, 2);
                $surcharge = (float) $row['surcharge'];
                $rebate = (float) $row['rebate'];
                $receivable = $amount - $tax + $surcharge - $rebate;
                $oldReceivable = (float) $row['receivable_amount'];
                $paid = (float) $row['paid_amount'];
                if (abs($paid - $oldReceivable) < 0.01) {
                    $paid = $receivable;
                }
                $balance = $amount - $paid;
                $upd->execute([$tax, $receivable, $paid, $balance, $row['id']]);
                $recalcCount++;
            }
        }
        $msg = "Tax withheld auto-fill now set to {$percent}%.";
        if ($recalcCount > 0) {
            $msg .= " Recalculated {$recalcCount} existing entr" . ($recalcCount === 1 ? 'y' : 'ies') . ' for this company.';
        }
        json_response(['ok' => true, 'message' => $msg]);
        break;
    }

    // ============================================================
    // AGING REPORT
    // ============================================================
    case 'get_aging': {
        [$branchSql, $branchParams] = branch_where('c');
        $stmt = $pdo->prepare(
            "SELECT l.*, c.name as company_name, c.status as company_status
             FROM ledger_entries l JOIN companies c ON c.id = l.company_id
             WHERE l.balance > 0.009 {$branchSql}
             ORDER BY c.name ASC, l.billing_date ASC"
        );
        $stmt->execute($branchParams);
        $rows = $stmt->fetchAll();

        $bucketLabels = ['Not Yet Due', '0-30 days', '31-60 days', '61-90 days', 'Over 90 days', 'N/A'];
        $buckets = array_fill_keys($bucketLabels, ['count' => 0, 'total' => 0.0]);
        $byCompany = [];

        foreach ($rows as $r) {
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $bucket = aging_bucket(days_overdue($due));
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['total'] += (float) $r['balance'];

            $cname = $r['company_name'];
            if (!isset($byCompany[$cname])) {
                $byCompany[$cname] = array_fill_keys($bucketLabels, 0.0);
                $byCompany[$cname]['total'] = 0.0;
            }
            $byCompany[$cname][$bucket] += (float) $r['balance'];
            $byCompany[$cname]['total'] += (float) $r['balance'];
        }
        uasort($byCompany, fn($a, $b) => $b['total'] <=> $a['total']);

        json_response(['ok' => true, 'data' => [
            'grace_days' => aging_grace_days($pdo),
            'buckets' => $buckets,
            'by_company' => array_map(fn($name, $b) => array_merge(['company' => $name], $b), array_keys($byCompany), array_values($byCompany)),
        ]]);
        break;
    }

    // ============================================================
    // PAST DUE
    // ============================================================
    case 'get_past_due': {
        [$branchSql, $branchParams] = branch_where('c');
        $stmt = $pdo->prepare(
            "SELECT l.*, c.name as company_name, c.status as company_status
             FROM ledger_entries l JOIN companies c ON c.id = l.company_id
             WHERE l.balance > 0.009 {$branchSql}
             ORDER BY l.billing_date ASC"
        );
        $stmt->execute($branchParams);
        $rows = $stmt->fetchAll();

        $pastDue = [];
        $byCompany = [];
        foreach ($rows as $r) {
            $due = $r['due_date'] ?: implicit_due_date($r['billing_date'], $pdo);
            $d = days_overdue($due);
            if ($d !== null && $d > 0) {
                $r['due'] = $due;
                $r['days'] = $d;
                $pastDue[] = $r;
                $byCompany[$r['company_name']] = ($byCompany[$r['company_name']] ?? 0) + (float) $r['balance'];
            }
        }
        usort($pastDue, fn($a, $b) => $b['days'] <=> $a['days']);
        arsort($byCompany);

        json_response(['ok' => true, 'data' => [
            'entries' => $pastDue,
            'total' => array_sum(array_column($pastDue, 'balance')),
            'by_company' => $byCompany,
        ]]);
        break;
    }

    // ============================================================
    // SOA TRACKER
    // ============================================================
    case 'get_soa_tracker': {
        $prefix = trim($input['prefix'] ?? 'SN-C') ?: 'SN-C';
        $suggested = next_soa_number($pdo, $prefix);
        [$branchSql, $branchParams] = branch_where('c');
        $stmt = $pdo->prepare(
            "SELECT t.*, c.name as company_name
             FROM soa_tracker t LEFT JOIN companies c ON c.id = t.company_id
             WHERE 1=1 " . str_replace('c.branch', 'c.branch', $branchSql) . "
             ORDER BY t.issued_date DESC, t.id DESC"
        );
        $stmt->execute($branchParams);
        json_response(['ok' => true, 'data' => [
            'entries' => $stmt->fetchAll(),
            'suggested' => $suggested,
            'prefix' => $prefix,
        ]]);
        break;
    }

    case 'soa_generate': {
        $companyId = (int) ($input['company_id'] ?? 0) ?: null;
        if ($companyId) {
            require_company_access($pdo, $companyId);
        }
        $soa = strtoupper(trim($input['soa_number'] ?? ''));
        if ($soa === '') {
            json_response(['ok' => false, 'error' => 'SOA number is required.'], 400);
        }
        if (soa_number_conflicts($pdo, $soa)) {
            json_response(['ok' => false, 'error' => "SOA number \"$soa\" is already in use."], 409);
        }
        $status = ($input['status'] ?? 'UNUSED') === 'USED' ? 'USED' : 'UNUSED';

        $existing = $pdo->prepare("SELECT id FROM soa_tracker WHERE soa_number = ? AND status <> 'USED' LIMIT 1");
        $existing->execute([$soa]);
        $existingId = $existing->fetchColumn();
        if ($existingId) {
            $pdo->prepare('UPDATE soa_tracker SET company_id = ?, status = ? WHERE id = ?')->execute([$companyId, $status, $existingId]);
        } else {
            $pdo->prepare('INSERT INTO soa_tracker (soa_number, company_id, status) VALUES (?, ?, ?)')->execute([$soa, $companyId, $status]);
        }
        json_response(['ok' => true, 'message' => "Saved SOA number: $soa"]);
        break;
    }

    case 'soa_update_entry': {
        $entryId = (int) ($input['id'] ?? 0);
        $companyId = (int) ($input['company_id'] ?? 0) ?: null;
        if ($companyId) {
            require_company_access($pdo, $companyId);
        }
        $soa = strtoupper(trim($input['soa_number'] ?? ''));
        if ($soa === '') {
            json_response(['ok' => false, 'error' => 'SOA number is required.'], 400);
        }
        if (soa_number_conflicts($pdo, $soa, null, $entryId)) {
            json_response(['ok' => false, 'error' => "SOA number \"$soa\" is already in use."], 409);
        }
        $status = ($input['status'] ?? 'UNUSED') === 'USED' ? 'USED' : 'UNUSED';
        $issuedDate = trim($input['issued_date'] ?? '');
        $issuedDate = $issuedDate !== '' ? str_replace('T', ' ', $issuedDate) . ':00' : null;

        $stmt = $pdo->prepare('UPDATE soa_tracker SET soa_number = ?, company_id = ?, status = ?' . ($issuedDate ? ', issued_date = ?' : '') . ' WHERE id = ?');
        $params = $issuedDate ? [$soa, $companyId, $status, $issuedDate, $entryId] : [$soa, $companyId, $status, $entryId];
        $stmt->execute($params);
        json_response(['ok' => true, 'message' => 'SOA entry updated.']);
        break;
    }

    case 'soa_check': {
        $number = strtoupper(trim($input['number'] ?? ''));
        if ($number === '') {
            json_response(['ok' => true, 'data' => ['tracker' => [], 'ledger' => []]]);
        }
        $t = $pdo->prepare('SELECT t.*, c.name as company_name FROM soa_tracker t LEFT JOIN companies c ON c.id = t.company_id WHERE t.soa_number = ?');
        $t->execute([$number]);
        $l = $pdo->prepare('SELECT l.*, c.name as company_name FROM ledger_entries l JOIN companies c ON c.id = l.company_id WHERE l.soa_number = ?');
        $l->execute([$number]);
        json_response(['ok' => true, 'data' => ['tracker' => $t->fetchAll(), 'ledger' => $l->fetchAll()]]);
        break;
    }

    // ============================================================
    // MONITORING
    // ============================================================
    case 'get_monitoring': {
        [$branchSql, $branchParams] = branch_where('c');
        $stmt = $pdo->prepare(
            "SELECT m.*, c.name as company_name FROM monitoring_entries m
             JOIN companies c ON c.id = m.company_id
             WHERE 1=1 {$branchSql}
             ORDER BY m.due_date ASC, m.id DESC"
        );
        $stmt->execute($branchParams);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
        break;
    }

    case 'create_monitoring':
    case 'update_monitoring': {
        $cId = (int) ($input['company_id'] ?? 0);
        require_company_access($pdo, $cId);
        $expected = ($input['expected_date'] ?? null) ?: null;
        $amount = (float) ($input['amount'] ?? 0);
        $remaining = (float) ($input['remaining_balance'] ?? 0);
        $due = ($input['due_date'] ?? null) ?: null;
        $remarks = trim($input['remarks'] ?? '') ?: null;

        if ($action === 'create_monitoring') {
            $stmt = $pdo->prepare('INSERT INTO monitoring_entries (company_id, expected_date, amount, remaining_balance, due_date, remarks) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$cId, $expected, $amount, $remaining, $due, $remarks]);
            json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => 'Monitoring entry added.']);
        } else {
            $editId = (int) ($input['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE monitoring_entries SET company_id=?, expected_date=?, amount=?, remaining_balance=?, due_date=?, remarks=? WHERE id=?');
            $stmt->execute([$cId, $expected, $amount, $remaining, $due, $remarks, $editId]);
            json_response(['ok' => true, 'message' => 'Monitoring entry updated.']);
        }
        break;
    }

    case 'delete_monitoring': {
        $id = (int) ($input['id'] ?? 0);
        $existing = $pdo->prepare('SELECT company_id FROM monitoring_entries WHERE id=?');
        $existing->execute([$id]);
        $cid = $existing->fetchColumn();
        if (!$cid) {
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }
        require_company_access($pdo, (int) $cid);
        $pdo->prepare('DELETE FROM monitoring_entries WHERE id=?')->execute([$id]);
        json_response(['ok' => true, 'message' => 'Monitoring entry deleted.']);
        break;
    }

    default:
        json_response(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}
