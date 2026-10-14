<?php

require_once __DIR__ . '/../includes/api_helpers.php';
require_login();
$branch = current_branch();

header_remove('Content-Type');

$company = isset($_GET['company']) ? trim($_GET['company']) : '';
// 'medrep' downloads a single customer's transaction history (used from the
// Customers list / customer profile page). 'name' is accepted as an alias.
$medrep  = isset($_GET['medrep']) ? trim($_GET['medrep']) : (isset($_GET['name']) ? trim($_GET['name']) : '');
$format  = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';
$year    = isset($_GET['year'])  && $_GET['year']  !== '' ? (int)$_GET['year']  : null;
$month   = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;

$COLUMNS = "name, company, deposit, dinein, takeout, giftcard,
            cashout, interest, sender_amount, receiver_amount,
            sender_name, receiver_name, payment_method, card,
            remarks, cashier, block, created_at";

$HEADERS = [
    'Name', 'Company', 'Deposit', 'Dine In', 'Takeout', 'Gift Card',
    'Cashout', 'Interest', 'Sender Amount', 'Receiver Amount',
    'Sender Name', 'Receiver Name', 'Payment Method', 'Card',
    'Remarks', 'Cashier', 'Block Status', 'Created At'
];

function rowToArray(array $row): array {
    return [
        $row['name'], $row['company'], $row['deposit'], $row['dinein'],
        $row['takeout'], $row['giftcard'], $row['cashout'], $row['interest'],
        $row['sender_amount'], $row['receiver_amount'], $row['sender_name'],
        $row['receiver_name'], $row['payment_method'], $row['card'],
        $row['remarks'], $row['cashier'], $row['block'], $row['created_at'],
    ];
}

// Shared WHERE builder: branch is always required; company/medrep/year/month
// are all optional and combine with AND. Used by both the CSV and XLSX paths
// so filtering behaves identically no matter the format.
function buildFilter(string $branch, string $company, string $medrep, ?int $year, ?int $month): array {
    $sql    = " WHERE branch = ?";
    $params = [$branch];
    $types  = "s";

    if ($company !== '') {
        $sql .= " AND company = ?";
        $params[] = $company;
        $types .= "s";
    }
    if ($medrep !== '') {
        $sql .= " AND name = ?";
        $params[] = $medrep;
        $types .= "s";
    }
    if ($year !== null) {
        $sql .= " AND YEAR(created_at) = ?";
        $params[] = $year;
        $types .= "i";
    }
    if ($month !== null) {
        $sql .= " AND MONTH(created_at) = ?";
        $params[] = $month;
        $types .= "i";
    }
    return [$sql, $params, $types];
}

// Sanitized filename slug: prefers the medrep name, then company, then 'all'.
function filenameSlug(string $company, string $medrep): string {
    if ($medrep !== '') return preg_replace('/[^A-Za-z0-9_-]+/', '_', $medrep);
    if ($company !== '') return preg_replace('/[^A-Za-z0-9_-]+/', '_', $company);
    return 'all_companies';
}

function phpAmount($n): string {
    return 'PHP ' . number_format((float)$n, 2);
}

function paymentMethodLabel(?string $pm): string {
    $clean = strtolower(trim(str_replace(["\r", "\n"], '', (string)$pm)));
    if ($clean === '' || $clean === 'na') return 'N/A';
    return ucfirst($clean);
}

if ($format === 'pdf') {
    require_once __DIR__ . '/../includes/simple_pdf_writer.php';

    if ($medrep === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "PDF export needs a specific medrep (?medrep=Name) — it's a per-customer statement, not a bulk export.\n";
        exit;
    }

    try {
        $empStmt = $conn->prepare("SELECT name, company FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ? LIMIT 1");
        $empStmt->bind_param('ss', $medrep, $branch);
        $empStmt->execute();
        $employee = $empStmt->get_result()->fetch_assoc();
        $empStmt->close();
        if (!$employee) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Medrep not found.\n";
            exit;
        }

        // Total Balance is always all-time, matching the "Balance" stat
        // card on the profile page (independent of the year/month filter
        // applied to the transaction list below).
        $totals = compute_balance($conn, $medrep, $branch);

        // Record Total (Card): total value moved through card payments,
        // which are excluded from the balance/payable calc above.
        $cardStmt = $conn->prepare(
            "SELECT COALESCE(SUM(dinein + takeout + giftcard), 0) AS card_total
             FROM employees
             WHERE UPPER(name) = UPPER(?) AND branch = ?
               AND TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\r', ''), '\n', ''))) = 'card'"
        );
        $cardStmt->bind_param('ss', $medrep, $branch);
        $cardStmt->execute();
        $cardTotal = (float)($cardStmt->get_result()->fetch_assoc()['card_total'] ?? 0);
        $cardStmt->close();

        // Pull the (optionally year/month-filtered) rows oldest-first so we
        // can accumulate a running total exactly like the profile page does,
        // then reverse to newest-first for display.
        [$sql, $params, $types] = buildFilter($branch, '', $medrep, $year, $month);
        $stmt = $conn->prepare("SELECT * FROM employees" . $sql . " ORDER BY created_at ASC");
        if (!$stmt) throw new RuntimeException("Query prepare failed: " . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $ordered = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $running = 0.0;
        $withRunning = [];
        foreach ($ordered as $row) {
            $method = strtolower(trim(str_replace(["\r", "\n"], '', $row['payment_method'] ?? '')));
            $isCard = $method === 'card';
            $payable = $isCard ? 0 : ((float)$row['dinein'] + (float)$row['takeout'] + (float)$row['giftcard']);
            $cashoutPart = $isCard ? 0 : ((float)$row['cashout'] + (float)$row['interest']);
            $running += (float)$row['deposit'] + (float)$row['receiver_amount'] - $payable - $cashoutPart - (float)$row['sender_amount'];
            $row['_running'] = $running;
            $withRunning[] = $row;
        }
        $withRunning = array_reverse($withRunning); // newest first, matching the on-screen table

        $columns = ['Date', 'Dine In', 'Takeout', 'Deposit', 'Sender Amount', 'Cashout', 'Interest', 'Gift Card', 'Payment Method', 'Running Total'];
        $colAlign = ['L', 'R', 'R', 'R', 'R', 'R', 'R', 'R', 'L', 'R'];
        $pdfRows = [];
        foreach ($withRunning as $row) {
            $pdfRows[] = [
                $row['created_at'],
                phpAmount($row['dinein']),
                phpAmount($row['takeout']),
                phpAmount($row['deposit']),
                phpAmount($row['sender_amount']),
                phpAmount($row['cashout']),
                phpAmount($row['interest']),
                phpAmount($row['giftcard']),
                paymentMethodLabel($row['payment_method']),
                phpAmount($row['_running']),
            ];
        }

        $conn->close();

        $writer = new SimplePdfWriter(SimplePdfWriter::colorsForBranch($branch));
        $writer->addStatement(
            ['NAME: ' . strtoupper($employee['name']), 'COMPANY: ' . strtoupper($employee['company'])],
            ['Total Balance' => phpAmount($totals['balance']), 'Record Total (Card)' => phpAmount($cardTotal)],
            $columns,
            $pdfRows,
            $colAlign
        );

        $filename = 'statement_' . filenameSlug('', $medrep) . '_' . date('Y-m-d') . '.pdf';
        $writer->output($filename);
        // output() exits, nothing below runs in this branch.
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "PDF export failed: " . $e->getMessage() . "\n";
        exit;
    }
}

if ($format === 'xlsx') {
    require_once __DIR__ . '/../includes/simple_xlsx_writer.php';

    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Excel export unavailable: the PHP 'zip' extension (ZipArchive) is not enabled on this server.\n\n"
           . "How to fix:\n"
           . "- XAMPP (Windows/Mac): open php.ini, uncomment (remove the leading ';' from) the line\n"
           . "  ';extension=zip', save, then fully restart Apache from the XAMPP control panel.\n"
           . "- To find which php.ini is active, create a file with <?php phpinfo(); and look for\n"
           . "  'Loaded Configuration File'.\n"
           . "- On Linux/shared hosting: install/enable php-zip (or php8.x-zip) and restart PHP/Apache.\n"
           . "The single-CSV download (no ?format=xlsx) does not need this extension and will still work.\n";
        exit;
    }

    try {
        $writer = new SimpleXlsxWriter(SimpleXlsxWriter::colorsForBranch($branch));

        // A specific medrep or a specific company both mean "one sheet" —
        // grouping by company only happens for the "download everything" case.
        if ($medrep !== '' || $company !== '') {
            [$sql, $params, $types] = buildFilter($branch, $company, $medrep, $year, $month);
            $stmt = $conn->prepare("SELECT $COLUMNS FROM employees" . $sql . " ORDER BY created_at ASC");
            if (!$stmt) throw new RuntimeException("Query prepare failed: " . $conn->error);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) $rows[] = rowToArray($row);
            $stmt->close();

            $sheetName = $medrep !== '' ? $medrep : $company;
            $writer->addSheet($sheetName, $HEADERS, $rows);
        } else {
            // Full export: one sheet per company, plus an "Unassigned" bucket
            // for rows with no company set.
            $rowStmt = $conn->prepare("SELECT $COLUMNS FROM employees WHERE company = ? AND branch = ? ORDER BY created_at ASC");
            if (!$rowStmt) throw new RuntimeException("Query prepare failed: " . $conn->error);

            $companyNames = [];
            $listResult = $conn->prepare(
                "SELECT DISTINCT company FROM employees
                 WHERE company IS NOT NULL AND company <> '' AND branch = ?
                 ORDER BY company ASC"
            );
            $listResult->bind_param('s', $branch);
            $listResult->execute();
            $listResult = $listResult->get_result();
            if (!$listResult) throw new RuntimeException("Company list query failed: " . $conn->error);
            while ($r = $listResult->fetch_assoc()) $companyNames[] = $r['company'];

            $unassignedCheck = $conn->prepare("SELECT COUNT(*) AS n FROM employees WHERE (company IS NULL OR company = '') AND branch = ?");
            $unassignedCheck->bind_param('s', $branch);
            $unassignedCheck->execute();
            $unassignedCheck = $unassignedCheck->get_result();
            $hasUnassigned = $unassignedCheck && (($unassignedCheck->fetch_assoc()['n'] ?? 0) > 0);

            foreach ($companyNames as $cName) {
                $rowStmt->bind_param('ss', $cName, $branch);
                $rowStmt->execute();
                $res = $rowStmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) $rows[] = rowToArray($row);
                $writer->addSheet($cName, $HEADERS, $rows);
            }
            $rowStmt->close();

            if ($hasUnassigned) {
                $blankStmt = $conn->prepare("SELECT $COLUMNS FROM employees WHERE (company IS NULL OR company = '') AND branch = ? ORDER BY created_at ASC");
                $blankStmt->bind_param('s', $branch);
                $blankStmt->execute();
                $res = $blankStmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) $rows[] = rowToArray($row);
                $writer->addSheet('Unassigned', $HEADERS, $rows);
                $blankStmt->close();
            }
        }

        $conn->close();

        $filename = 'transactions_' . filenameSlug($company, $medrep) . '_' . date('Y-m-d') . '.xlsx';
        $writer->output($filename);
        // output() exits, nothing below runs in this branch.
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Excel export failed: " . $e->getMessage() . "\n";
        exit;
    }
}

// ------------------------------------------------------------
// CSV mode (default)
// ------------------------------------------------------------
[$sql, $params, $types] = buildFilter($branch, $company, $medrep, $year, $month);
$stmt = $conn->prepare("SELECT $COLUMNS FROM employees" . $sql . " ORDER BY created_at ASC");
if (!$stmt) {
    die("Query prepare failed: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$filename = 'transactions_' . filenameSlug($company, $medrep) . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fputcsv($out, $HEADERS, ',', '"', '\\');
while ($row = $result->fetch_assoc()) {
    fputcsv($out, rowToArray($row), ',', '"', '\\');
}

fclose($out);
$stmt->close();
$conn->close();
exit;