<?php
session_start();

// Establish database connection
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// ------------------------------------------------------------
// Downloads the raw transaction rows from the employees table.
//   export_transactions.php                    -> every company, one CSV, all rows
//   export_transactions.php?company=Acme        -> just that company's rows, CSV
//   export_transactions.php?format=xlsx         -> every company, ONE Excel file
//                                                  with a separate worksheet (tab)
//                                                  per company
// Used by the "Download All Transactions" / "Download All (By Company)" buttons
// and each company's "Download" button on companies.php.
// ------------------------------------------------------------
$company = isset($_GET['company']) ? trim($_GET['company']) : '';
$format  = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

// ------------------------------------------------------------
// XLSX mode: one workbook, one worksheet per company, each sheet
// holding only that company's transactions.
// ------------------------------------------------------------
if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/simple_xlsx_writer.php';

    // A blank 500 with no message almost always means either PHP's error
    // display is off (APP_DEBUG=false in db_config.php) or the ZipArchive
    // class isn't available. Surface a real, readable reason for THIS
    // endpoint specifically, no matter what APP_DEBUG is set to, so it's
    // obvious what to fix instead of a dead "page isn't working" screen.
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
        $headers = [
            'Name', 'Company', 'Deposit', 'Dine In', 'Takeout', 'Gift Card',
            'Cashout', 'Interest', 'Sender Amount', 'Receiver Amount',
            'Sender Name', 'Receiver Name', 'Payment Method', 'Card',
            'Remarks', 'Cashier', 'Block Status', 'Created At'
        ];

        // Prepared once, re-bound per company below.
        $rowStmt = $conn->prepare(
            "SELECT name, company, deposit, dinein, takeout, giftcard,
                    cashout, interest, sender_amount, receiver_amount,
                    sender_name, receiver_name, payment_method, card,
                    remarks, cashier, block, created_at
             FROM employees
             WHERE company = ?
             ORDER BY created_at ASC"
        );
        if (!$rowStmt) {
            throw new RuntimeException("Query prepare failed: " . $conn->error);
        }

        // Which companies get a sheet? Either just the one requested via
        // ?company=, or every distinct company on file (including a bucket
        // for rows with no company set).
        $companyNames = [];
        if ($company !== '') {
            $companyNames[] = $company;
        } else {
            $listResult = $conn->query(
                "SELECT DISTINCT company FROM employees
                 WHERE company IS NOT NULL AND company <> ''
                 ORDER BY company ASC"
            );
            if (!$listResult) {
                throw new RuntimeException("Company list query failed: " . $conn->error);
            }
            while ($r = $listResult->fetch_assoc()) {
                $companyNames[] = $r['company'];
            }

            // Rows with a blank/NULL company still need to end up somewhere.
            $unassignedCheck = $conn->query(
                "SELECT COUNT(*) AS n FROM employees WHERE company IS NULL OR company = ''"
            );
            $hasUnassigned = $unassignedCheck && (($unassignedCheck->fetch_assoc()['n'] ?? 0) > 0);
        }

        $writer = new SimpleXlsxWriter();

        foreach ($companyNames as $cName) {
            $rowStmt->bind_param('s', $cName);
            $rowStmt->execute();
            $res = $rowStmt->get_result();

            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    $row['name'], $row['company'], $row['deposit'], $row['dinein'],
                    $row['takeout'], $row['giftcard'], $row['cashout'], $row['interest'],
                    $row['sender_amount'], $row['receiver_amount'], $row['sender_name'],
                    $row['receiver_name'], $row['payment_method'], $row['card'],
                    $row['remarks'], $row['cashier'], $row['block'], $row['created_at'],
                ];
            }
            $writer->addSheet($cName, $headers, $rows);
        }

        // Bucket for legacy/blank-company rows, only when exporting everything.
        if ($company === '' && !empty($hasUnassigned)) {
            $blankStmt = $conn->prepare(
                "SELECT name, company, deposit, dinein, takeout, giftcard,
                        cashout, interest, sender_amount, receiver_amount,
                        sender_name, receiver_name, payment_method, card,
                        remarks, cashier, block, created_at
                 FROM employees
                 WHERE company IS NULL OR company = ''
                 ORDER BY created_at ASC"
            );
            $blankStmt->execute();
            $res = $blankStmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    $row['name'], $row['company'], $row['deposit'], $row['dinein'],
                    $row['takeout'], $row['giftcard'], $row['cashout'], $row['interest'],
                    $row['sender_amount'], $row['receiver_amount'], $row['sender_name'],
                    $row['receiver_name'], $row['payment_method'], $row['card'],
                    $row['remarks'], $row['cashier'], $row['block'], $row['created_at'],
                ];
            }
            $writer->addSheet('Unassigned', $headers, $rows);
            $blankStmt->close();
        }

        $rowStmt->close();
        $conn->close();

        $filename = ($company !== '')
            ? 'transactions_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $company) . '_' . date('Y-m-d') . '.xlsx'
            : 'transactions_by_company_' . date('Y-m-d') . '.xlsx';

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
// CSV mode (default) — unchanged from before.
// ------------------------------------------------------------
$sql = "SELECT name, company, deposit, dinein, takeout, giftcard,
               cashout, interest, sender_amount, receiver_amount,
               sender_name, receiver_name, payment_method, card,
               remarks, cashier, block, created_at
        FROM employees";
$params = [];
$types = "";

if ($company !== '') {
    $sql .= " WHERE company = ?";
    $params[] = $company;
    $types .= "s";
}

$sql .= " ORDER BY created_at ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Query prepare failed: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Build a safe filename: all-companies, or the sanitized company name.
$slug = $company !== ''
    ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $company)
    : 'all_companies';
$filename = 'transactions_' . $slug . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Name', 'Company', 'Deposit', 'Dine In', 'Takeout', 'Gift Card',
    'Cashout', 'Interest', 'Sender Amount', 'Receiver Amount',
    'Sender Name', 'Receiver Name', 'Payment Method', 'Card',
    'Remarks', 'Cashier', 'Block Status', 'Created At'
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['name'],
        $row['company'],
        $row['deposit'],
        $row['dinein'],
        $row['takeout'],
        $row['giftcard'],
        $row['cashout'],
        $row['interest'],
        $row['sender_amount'],
        $row['receiver_amount'],
        $row['sender_name'],
        $row['receiver_name'],
        $row['payment_method'],
        $row['card'],
        $row['remarks'],
        $row['cashier'],
        $row['block'],
        $row['created_at'],
    ]);
}

fclose($out);
$stmt->close();
$conn->close();
exit;
