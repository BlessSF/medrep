<?php
session_start();

// Database connection

require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}
// ✅ If POST with ID, update "checked" column
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id']);

    $stmt = $conn->prepare("UPDATE employees SET checked = 'checked' WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $success]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Query failed']);
        exit;
    }
}

$name = isset($_GET['name']) ? strtoupper(trim($_GET['name'])) : '';
$transfer_amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

if (!$name) {
    die("MEDREP NOT FOUND: NAME PARAMETER IS MISSING.");
}

$profile_success_message = $_SESSION['success_message'] ?? '';
$profile_error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Fetch employee data
$employee_data = null;
$sql = "SELECT * FROM employees WHERE UPPER(name) = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $employee_data = $result->fetch_assoc();
    }
    $stmt->close();
}
if (!$employee_data) {
    die("MEDREP NOT FOUND IN DATABASE.");
}

// If the 'month' GET parameter is set, use that. If not, keep it empty for "All Months."
$month = isset($_GET['month']) ? $_GET['month'] : '';

// If the 'year' parameter is not set, we set the default year to the current year.
$year = isset($_GET['year']) && $_GET['year'] !== '' ? $_GET['year'] : date('Y');

// Check if the selected month is empty (i.e., All Months is selected)
$monthSelected = $month !== '' ? $month : null; // null means "All Months"

// Define the column that stores the date (replace with your actual date column)
$date_column = 'created_at';  // Example: Replace with the actual column name

// Add conditions to the SQL query
$where_clause = "WHERE UPPER(name) = ?";
$params = [$name];  // $name is assumed to be already defined
$types = "s";       // for $name

if (!empty($month)) {
    $where_clause .= " AND MONTH($date_column) = ?";
    $params[] = $month;
    $types .= "s";
}

if (!empty($year)) {
    $where_clause .= " AND YEAR($date_column) = ?";
    $params[] = $year;
    $types .= "s";
}

// Prepare and execute the query
$sql = "SELECT * FROM employees $where_clause ORDER BY $date_column DESC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $all_records = [];
}

$total_deposit = 0;
$total_payable = 0;
$total_cashout = 0;

$query = "SELECT SUM(giftcard) AS total_giftcard_balance FROM employees WHERE UPPER(name) = UPPER(?)";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $name);  // Bind the employee name
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
$total_giftcard_balance = $row['total_giftcard_balance'];

// Second query: Get total dine-in or takeout amounts where the payment method is giftcard
$query_transactions = "
    SELECT SUM(dinein + takeout) AS total_used_giftcard
    FROM employees
    WHERE payment_method = 'giftcard' AND UPPER(name) = UPPER(?)
";
$stmt_transactions = mysqli_prepare($conn, $query_transactions);
mysqli_stmt_bind_param($stmt_transactions, "s", $name);  // Bind the employee name
mysqli_stmt_execute($stmt_transactions);
$result_transactions = mysqli_stmt_get_result($stmt_transactions);
$row_transactions = mysqli_fetch_assoc($result_transactions);
$total_used_giftcard = $row_transactions['total_used_giftcard'];

// Calculate the remaining giftcard balance
$giftcard_balance = $total_giftcard_balance - $total_used_giftcard;

// Fetch total deposit (sum of deposits and receiver amounts). Deposits
// count normally no matter the payment method -- card only changes how
// Dine In/Takeout/Cashout/Interest/Gift Card are handled (see below).
$deposit_query = "SELECT SUM(deposit) AS total_deposit, SUM(receiver_amount) AS total_receiver FROM employees WHERE UPPER(name) = ?";
$deposit_stmt = $conn->prepare($deposit_query);
$deposit_stmt->bind_param("s", $name);
$deposit_stmt->execute();
$deposit_result = $deposit_stmt->get_result();
$row = $deposit_result->fetch_assoc();
$deposit_stmt->close();

$total_deposit = ($row['total_deposit'] ?? 0) + ($row['total_receiver'] ?? 0);

// Payable always includes dine-in/takeout/giftcard, regardless of how it
// was paid -- previously filtered to payment_method 'na'/'paid' only, so
// anything paid by cash/card/giftcard silently dropped out of Total
// Payable and Total Balance above. Card-paid rows are now excluded again,
// but only because they're tracked in $record_total instead (see below).
$payable_query = "SELECT 
                    SUM(CASE WHEN COALESCE(payment_method,'') <> 'card' THEN dinein ELSE 0 END) AS total_dinein, 
                    SUM(CASE WHEN COALESCE(payment_method,'') <> 'card' THEN takeout ELSE 0 END) AS total_takeout, 
                    SUM(CASE WHEN COALESCE(payment_method,'') <> 'card' THEN giftcard ELSE 0 END) AS total_giftcard 
                  FROM employees 
                  WHERE UPPER(name) = ?";

$payable_stmt = $conn->prepare($payable_query);
$payable_stmt->bind_param("s", $name);
$payable_stmt->execute();
$payable_result = $payable_stmt->get_result();
$row = $payable_result->fetch_assoc();

$total_dinein = $row['total_dinein'] ?? 0;
$total_takeout = $row['total_takeout'] ?? 0;
$total_giftcard = $row['total_giftcard'] ?? 0;

$payable_stmt->close();


// Calculate total payable
$total_payable = $total_dinein + $total_takeout + $total_giftcard;

// Fetch total cashout including interest (card-paid rows excluded, see above)
$cashout_query = "SELECT SUM(CASE WHEN COALESCE(payment_method,'') <> 'card' THEN cashout + interest ELSE 0 END) AS total_cashout FROM employees WHERE UPPER(name) = ?";
$cashout_stmt = $conn->prepare($cashout_query);
$cashout_stmt->bind_param("s", $name);
$cashout_stmt->execute();
$cashout_result = $cashout_stmt->get_result();
$total_cashout = $cashout_result->fetch_assoc()['total_cashout'] ?? 0;
$cashout_stmt->close();

$send_query = "SELECT SUM(sender_amount) AS total_send FROM employees WHERE UPPER(name) = ?";
$send_stmt = $conn->prepare($send_query);
$send_stmt->bind_param("s", $name);
$send_stmt->execute();
$send_result = $send_stmt->get_result();
$total_send = $send_result->fetch_assoc()['total_send'] ?? 0;
$send_stmt->close();

$total_balance = $total_deposit - $total_send - ($total_payable + $total_cashout);

// ------------------------------------------------------------
// RECORD TOTAL: card-paid ("Swipe" -- Maya/Metrobank/etc) Dine In,
// Takeout, Cashout, Interest, and Gift Card never touch the normal
// running balance above -- they're totalled here separately instead.
// Card-paid Deposit is NOT included here; it still adds to the normal
// balance like any other deposit.
// ------------------------------------------------------------
$record_query = "SELECT 
                    SUM(CASE WHEN payment_method = 'card' THEN dinein ELSE 0 END) AS r_dinein,
                    SUM(CASE WHEN payment_method = 'card' THEN takeout ELSE 0 END) AS r_takeout,
                    SUM(CASE WHEN payment_method = 'card' THEN cashout ELSE 0 END) AS r_cashout,
                    SUM(CASE WHEN payment_method = 'card' THEN interest ELSE 0 END) AS r_interest,
                    SUM(CASE WHEN payment_method = 'card' THEN giftcard ELSE 0 END) AS r_giftcard
                  FROM employees
                  WHERE UPPER(name) = ?";
$record_stmt = $conn->prepare($record_query);
$record_stmt->bind_param("s", $name);
$record_stmt->execute();
$record_row = $record_stmt->get_result()->fetch_assoc();
$record_stmt->close();

// Card-paid Deposit is NOT part of Record Total -- it still adds to the
// normal running balance like any other deposit (see below). Record
// Total only tracks card-paid Dine In/Takeout/Cashout/Interest/Gift
// Card, which are the amounts that don't reduce the running balance.
$record_total = (
        (float)($record_row['r_dinein'] ?? 0)
        + (float)($record_row['r_takeout'] ?? 0)
        + (float)($record_row['r_cashout'] ?? 0)
        + (float)($record_row['r_interest'] ?? 0)
        + (float)($record_row['r_giftcard'] ?? 0)
    );


// ------------------------------------------------------------
// STEP 1: Carry the balance forward from before the period being
// viewed (previous month, or Dec 31 of the previous year when
// viewing a full year / "All Months"), instead of resetting to 0.
//
// The old version here queried a `running_total` column on
// `employees` that is never actually written back to the database
// (it only ever existed in PHP memory, for display) so it always
// evaluated to 0 -- that's why a leftover balance at the end of one
// year never carried into the next. It also always used *today's*
// real-world month instead of the month/year currently being
// viewed, and never filtered by customer name.
// ------------------------------------------------------------
if (!empty($month)) {
    $period_start = sprintf('%04d-%02d-01', $year, $month);
} else {
    $period_start = sprintf('%04d-01-01', $year);
}

$initial_balance = 0;
$prior_sql = "SELECT dinein, takeout, deposit, receiver_amount, sender_amount, cashout, interest, giftcard, payment_method
              FROM employees
              WHERE UPPER(name) = ? AND created_at < ?
              ORDER BY created_at ASC";
if ($stmt = $conn->prepare($prior_sql)) {
    $stmt->bind_param("ss", $name, $period_start);
    $stmt->execute();
    $prior_result = $stmt->get_result();

    while ($prior = $prior_result->fetch_assoc()) {
        $p_dinein   = floatval($prior['dinein'] ?? 0);
        $p_takeout  = floatval($prior['takeout'] ?? 0);
        $p_deposit  = floatval(($prior['deposit'] ?? 0) + ($prior['receiver_amount'] ?? 0));
        $p_transfer = floatval($prior['sender_amount'] ?? 0);
        $p_cashout  = floatval($prior['cashout'] ?? 0);
        $p_interest = floatval($prior['interest'] ?? 0);
        $p_giftcard = floatval($prior['giftcard'] ?? 0);
        $p_is_card  = (strtolower(trim($prior['payment_method'] ?? '')) === 'card');

        // Dine-in, takeout, cashout, interest, and gift card usage always
        // reduce the balance, regardless of payment method -- previously
        // this was skipped for cash/card/giftcard payments, and separately
        // double-deducted cashout/interest/transfer for 'na'/'paid'
        // payments. Both bugs are fixed by a single unconditional line.
        // EXCEPT card-paid ("Swipe") rows -- those sit in the separate
        // Record Total (see above) instead of the normal running balance.
        // Card-paid Deposit still adds normally -- only card-paid Dine In,
        // Takeout, Cashout, Interest, and Gift Card are excluded (they sit
        // in the separate Record Total instead).
        $initial_balance += $p_deposit;
        if (!$p_is_card) {
            $initial_balance -= ($p_dinein + $p_takeout + $p_cashout + $p_interest + $p_giftcard);
        }
        $initial_balance -= $p_transfer;
    }
    $stmt->close();
}

// STEP 3: Sort in chronological order for proper running total calculation
usort($all_records, function ($a, $b) {
    return strtotime($a['created_at']) <=> strtotime($b['created_at']);
});


// STEP 4: Loop to calculate running_total
$running_total = $initial_balance;

foreach ($all_records as &$record) {
    $dinein_amount     = floatval($record['dinein'] ?? 0);
    $takeout_amount    = floatval($record['takeout'] ?? 0);
    $deposit_amount    = floatval(($record['deposit'] ?? 0) + ($record['receiver_amount'] ?? 0));
    $transfer_amount   = floatval($record['sender_amount'] ?? 0);
    $cashout_amount    = floatval($record['cashout'] ?? 0);
    $interest_amount   = floatval($record['interest'] ?? 0);
    $giftcard          = floatval($record['giftcard'] ?? 0);
    $is_card_payment   = (strtolower(trim($record['payment_method'] ?? '')) === 'card');

    // Dine-in, takeout, cashout, interest, and gift card always reduce the
    // running balance, no matter how the record's payment method is set --
    // previously they were skipped entirely for cash/card/giftcard, and
    // separately double-deducted (once in a batch, once individually) for
    // 'na'/'paid'. This single unconditional line fixes both.
    // EXCEPT card-paid ("Swipe") rows, which don't touch the running
    // balance at all -- they're totalled separately in Record Total.
    // Card-paid Deposit still adds normally -- only card-paid Dine In,
    // Takeout, Cashout, Interest, and Gift Card are excluded (they sit
    // in the separate Record Total instead).
    $running_total += $deposit_amount;
    if (!$is_card_payment) {
        $running_total -= ($dinein_amount + $takeout_amount + $cashout_amount + $interest_amount + $giftcard);
    }
    $running_total -= $transfer_amount;

    $record['running_total'] = $running_total;
}



// STEP 5: Sort records back to latest first if needed
usort($all_records, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});





// Remove the negative sign for payable balances
if ($total_balance > 0) {
    $total_balance_label = "DEPOSIT";
    $balance_color = "green";  // Color for positive balance
} elseif ($total_balance < 0) {
    $total_balance_label = "PAYABLE";
    $balance_color = "red";    // Color for negative balance (payable)
    // Do not change the value to absolute
} elseif ($total_balance == 0) {
    $total_balance_label = "BALANCE";
    $balance_color = "black";  // Color for zero balance
}



if (!$employee_data) {
    die("MEDREP NOT FOUND IN DATABASE.");
}


$uploadDir = "uploads/";
$photoPath = $uploadDir . $name . ".jpg";

// ------------------------------------------------------------
// Balance History (last 12 months): lets the medrep/admin see at a
// glance how the remaining balance moved month to month, not just
// its value right now. Pulls this customer's ENTIRE transaction
// history (independent of the month/year filter above) and replays
// the same running-balance rules used for the table's running total,
// then records the balance as of the end of each of the last 12
// calendar months. Months with no activity simply carry forward the
// balance from the month before, so the line reflects the true
// balance at every point, not just months with transactions.
// ------------------------------------------------------------
$balance_history_labels = [];
$balance_history_values = [];

$history_sql = "SELECT dinein, takeout, deposit, receiver_amount, sender_amount, cashout, interest, giftcard, payment_method, created_at
                 FROM employees
                 WHERE UPPER(name) = ?
                 ORDER BY created_at ASC";
if ($stmt = $conn->prepare($history_sql)) {
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $history_records = $history_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $history_records = [];
}

// Build the 12 month "buckets" we want to plot: 11 months ago -> this month.
$month_cursor = new DateTime('first day of this month');
$month_cursor->modify('-11 months');
$month_buckets = []; // 'YYYY-Mm' => running balance as of end of that month
for ($i = 0; $i < 12; $i++) {
    $key = $month_cursor->format('Y-m');
    $balance_history_labels[$key] = $month_cursor->format('M Y');
    $month_buckets[$key] = null; // filled in below; null = "carry forward"
    $month_cursor->modify('+1 month');
}
$earliest_bucket_key = array_key_first($month_buckets);
$earliest_bucket_start = DateTime::createFromFormat('Y-m-d', $earliest_bucket_key . '-01');

// Replay full history to get the balance carried in before the chart window starts.
$hist_running_total = 0;
foreach ($history_records as $h) {
    $h_dinein   = floatval($h['dinein'] ?? 0);
    $h_takeout  = floatval($h['takeout'] ?? 0);
    $h_deposit  = floatval(($h['deposit'] ?? 0) + ($h['receiver_amount'] ?? 0));
    $h_transfer = floatval($h['sender_amount'] ?? 0);
    $h_cashout  = floatval($h['cashout'] ?? 0);
    $h_interest = floatval($h['interest'] ?? 0);
    $h_giftcard = floatval($h['giftcard'] ?? 0);
    $h_date     = new DateTime($h['created_at']);

    // Same unconditional formula as the running-total loop above: dine-in,
    // takeout, cashout, interest, gift card, and transfer always reduce
    // the balance regardless of payment method.
    $hist_running_total += $h_deposit;
    $hist_running_total -= ($h_dinein + $h_takeout + $h_cashout + $h_interest + $h_giftcard + $h_transfer);

    if ($h_date < $earliest_bucket_start) {
        continue; // still accumulating the "carried-in" balance, not a plotted month yet
    }
    $bucket_key = $h_date->format('Y-m');
    if (array_key_exists($bucket_key, $month_buckets)) {
        $month_buckets[$bucket_key] = $hist_running_total; // last write in the month wins (chronological order)
    }
}

// Fill forward: a month with no transactions keeps the previous month's balance.
$carry = null;
foreach ($month_buckets as $key => $val) {
    if ($val === null) {
        $month_buckets[$key] = $carry ?? 0;
    } else {
        $carry = $val;
    }
    $balance_history_values[] = round($month_buckets[$key], 2);
}
$balance_history_labels = array_values($balance_history_labels);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>MEDREP PROFILE</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            text-align: left;
        }

        .profile-page {
            max-width: 1300px;
            margin: 0 auto;
            padding: 24px 20px 60px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
        }

        /* ---------- profile header card ---------- */
        .profile-card {
            display: flex;
            align-items: center;
            gap: 26px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg, 20px);
            box-shadow: var(--shadow-sm);
            padding: 26px 30px;
            margin-bottom: 24px;
        }
        .profile-photo-wrap { position: relative; flex-shrink: 0; }
        .profile-photo-wrap img {
            width: 116px;
            height: 116px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            display: block;
        }
        .upload-label {
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: var(--primary);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
        }
        .upload-label img { width: 16px; height: 16px; filter: brightness(0) invert(1); }

        .profile-info h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 6px;
            color: var(--text);
            letter-spacing: 0.2px;
        }
        .company-pill {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 12.5px;
            letter-spacing: 0.4px;
            padding: 5px 14px;
            border-radius: 999px;
        }

        /* ---------- summary cards ---------- */
        .summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 26px; }
        @media (max-width: 1100px) { .summary { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 680px)  { .summary { grid-template-columns: repeat(2, 1fr); } }

        /* ---------- balance history chart ---------- */
        .balance-history-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 26px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        /* ---------- transactions section ---------- */
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
        }
        .transaction-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-form label { font-size: 13px; }
        .filter-form select { min-width: 130px; }

        .transactions-container { padding: 18px; }

        table { text-transform: none; }
        table thead tr { text-transform: uppercase; }
        td { font-weight: 600; }

        .amount-positive { color: var(--success); font-weight: 700; }
        .amount-negative { color: var(--danger); font-weight: 700; }

        .status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 12px;
        }
        .status-pill.balance { background: var(--success-bg); color: var(--success); }
        .status-pill.payable { background: var(--danger-bg); color: var(--danger); }

        .delete-record-btn {
            border: none;
            color: #fff;
            padding: 6px 12px !important;
            border-radius: var(--radius-sm) !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            cursor: pointer;
            background: var(--danger) !important;
        }
        .delete-record-btn:disabled {
            background: #b9c0c2 !important;
            cursor: not-allowed;
        }

        .check-toggle {
            font-size: 20px;
            cursor: pointer;
            display: inline-block;
        }

        .no-print { }
        @media print { .no-print { display: none !important; } }

        /* ---------- quick-add toolbar ---------- */
        .quick-add-toolbar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .quick-add-label {
            font-weight: 700;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-right: 4px;
        }
        .quick-add-btn { font-size: 13px !important; padding: 9px 14px !important; }
        .qa-dinein   { background: #2e7d32 !important; }
        .qa-takeout  { background: #43a047 !important; }
        .qa-deposit  { background: #1b5e20 !important; }
        .qa-cashout  { background: #d98c00 !important; }
        .qa-interest { background: #8d6e63 !important; }
        .qa-giftcard { background: #ad1457 !important; }
        .qa-dinein:hover   { background: #26692a !important; }
        .qa-takeout:hover  { background: #388e3c !important; }
        .qa-deposit:hover  { background: #144a18 !important; }
        .qa-cashout:hover  { background: #b87500 !important; }
        .qa-interest:hover { background: #6d5450 !important; }
        .qa-giftcard:hover { background: #8c1045 !important; }

        /* ---------- shared modal (used by quick-add) ---------- */
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            padding: 24px 26px;
            border-radius: var(--radius, 14px);
            box-shadow: var(--shadow-lg, 0 16px 40px rgba(20,25,50,0.2));
            z-index: 2000;
            width: 340px;
            max-width: 90vw;
        }
        .modal h2 { color: var(--primary-dark); font-size: 18px; margin-bottom: 6px; }
        .modal form { display: block; }
        .modal label { display: block; margin-top: 12px; margin-bottom: 4px; }
        .modal input, .modal select { display: block; width: 100%; }
    </style>
    <script src="assets/table-search.js"></script>
    <script src="assets/inline-edit.js"></script>
</head>
<body>
<div class="profile-page">

    <?php if ($profile_success_message): ?>
        <div class="success-message"><?= htmlspecialchars($profile_success_message) ?></div>
    <?php endif; ?>
    <?php if ($profile_error_message): ?>
        <div class="error-message"><?= htmlspecialchars($profile_error_message) ?></div>
    <?php endif; ?>

    <button class="btn back-btn" onclick="window.location.href='customer.php'">
        <i class="fas fa-arrow-left"></i> Back
    </button>

    <div class="profile-card">
        <div class="profile-photo-wrap">
            <img src="<?= file_exists($photoPath) ? $photoPath : 'assets/images/default.jpg' ?>" alt="Profile Picture">
            <form method="POST" enctype="multipart/form-data" style="margin:0;">
                <label for="profile_photo" class="upload-label" title="Change photo">
                    <img src="assets/images/upload.png" alt="Upload">
                </label>
                <input type="file" id="profile_photo" name="profile_photo" accept="image/*" style="display: none;" onchange="this.form.submit()">
            </form>
        </div>
        <div class="profile-info">
            <h1><?= strtoupper($employee_data['name'] ?? ''); ?></h1>
            <span class="company-pill"><?= strtoupper($employee_data['company'] ?? ''); ?></span>
        </div>
    </div>

    <div class="summary">
        <div class="summary-item">
            <h2>TOTAL BALANCE</h2>
            <p style="color: <?php echo $balance_color; ?>;">₱<?php echo number_format($total_balance, 2); ?></p>
        </div>
        <div class="summary-item">
            <h2>TOTAL DEPOSIT</h2>
            <p>₱<?= number_format($total_deposit, 2); ?></p>
        </div>
        <div class="summary-item">
            <h2>TOTAL PAYABLE</h2>
            <p>₱<?= number_format($total_payable, 2); ?></p>
        </div>
        <div class="summary-item">
            <h2>TOTAL CASHOUT</h2>
            <p>₱<?= number_format($total_cashout, 2); ?></p>
        </div>
        <div class="summary-item">
            <h2>GIFTCARD BALANCE</h2>
            <p>₱<?= number_format($giftcard_balance, 2); ?></p>
        </div>
        <div class="summary-item">
            <h2>RECORD TOTAL <span style="font-weight:normal; font-size:11px;">(card)</span></h2>
            <p style="color: <?= $record_total < 0 ? 'red' : ($record_total > 0 ? 'green' : 'black') ?>;">₱<?= number_format($record_total, 2); ?></p>
        </div>
    </div>

    <div class="balance-history-box no-print">
        <h2 style="margin-top:0;">Balance History <span style="font-weight:normal; font-size:13px; color:#666;">— last 12 months</span></h2>
        <p style="margin-top:-6px; font-size:13px; color:#666;">See at a glance whether <?= htmlspecialchars(strtoupper($employee_data['name'] ?? '')) ?>'s remaining balance was higher or lower last month, or a year ago.</p>
        <canvas id="balanceHistoryChart" height="90"></canvas>
    </div>

    <div class="transaction-header">
        <h2>Transaction Records</h2>
        <button class="btn print-button no-print" onclick="printProfile()"><i class="fas fa-print"></i> Print Transactions</button>
        <iframe id="print-frame" style="display: none;"></iframe>
    </div>

    <div class="quick-add-toolbar no-print">
        <span class="quick-add-label">Quick add:</span>
        <button type="button" class="btn quick-add-btn qa-dinein" onclick="openQuickAdd('dinein','DINE IN')"><i class="fas fa-utensils"></i> Dine In</button>
        <button type="button" class="btn quick-add-btn qa-takeout" onclick="openQuickAdd('takeout','TAKEOUT')"><i class="fas fa-shopping-bag"></i> Takeout</button>
        <button type="button" class="btn quick-add-btn qa-deposit" onclick="openQuickAdd('deposit','DEPOSIT')"><i class="fas fa-piggy-bank"></i> Deposit</button>
        <button type="button" class="btn quick-add-btn qa-cashout" onclick="openQuickAdd('cashout','CASHOUT')"><i class="fas fa-money-bill-wave"></i> Cashout</button>
        <button type="button" class="btn quick-add-btn qa-interest" onclick="openQuickAdd('interest','INTEREST')"><i class="fas fa-percentage"></i> Interest</button>
        <button type="button" class="btn quick-add-btn qa-giftcard" onclick="openQuickAdd('giftcard','GIFT CARD')"><i class="fas fa-gift"></i> Gift Card</button>
    </div>

    <!-- Modal: quick-add a transaction of the type picked above -->
    <div id="quickAddModal" class="modal">
      <h2 id="quickAddTitle">Add Transaction</h2>
      <form id="quickAddForm" method="post" action="add_transaction.php">
        <input type="hidden" name="name" value="<?= htmlspecialchars($employee_data['name'] ?? '') ?>">
        <input type="hidden" name="company" value="<?= htmlspecialchars($employee_data['company'] ?? '') ?>">
        <input type="hidden" name="type" id="quickAddType" value="">
        <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($month) ?>">
        <input type="hidden" name="redirect_year" value="<?= htmlspecialchars($year) ?>">

        <label for="quickAddAmount">Amount:</label>
        <input type="number" step="0.01" min="0.01" id="quickAddAmount" name="amount" required>

        <label for="quickAddPaymentMethod">Payment Method:</label>
        <select id="quickAddPaymentMethod" name="payment_method">
            <option value="na">N/A</option>
            <option value="card">Swipe</option>
            <option value="cash">Cash</option>
            <option value="paid">Paid</option>
            <option value="giftcard">Gift Card</option>
        </select>

        <label for="quickAddCardChoice">Bank / Card:</label>
        <select id="quickAddCardChoice">
            <option value="">-- None --</option>
            <option value="maya">Maya</option>
            <option value="metrobank">Metrobank</option>
            <option value="others">Others</option>
        </select>
        <input type="text" id="quickAddCardOther" placeholder="Specify card/bank name" style="display:none;">
        <input type="hidden" id="quickAddCard" name="card">

        <label for="quickAddRemarks">Remarks:</label>
        <input type="text" id="quickAddRemarks" name="remarks" placeholder="Optional">

        <br><br>
        <button type="submit" class="submit">Save</button>
        <button type="button" class="button" onclick="closeQuickAdd()">Cancel</button>
      </form>
    </div>

    <form method="GET" class="filter-form" id="filterForm">
        <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">

        <label for="month">Month:</label>
        <select name="month" id="month" onchange="this.form.submit()">
            <option value="">All Months</option>
            <?php for ($m = 1; $m <= 12; $m++):
                $monthValue = str_pad($m, 2, "0", STR_PAD_LEFT); // Ensuring 2-digit format
                $monthLabel = date("F", mktime(0, 0, 0, $m, 1));
            ?>
                <option value="<?= $monthValue ?>" <?= ($monthSelected == $monthValue) ? 'selected' : '' ?>>
                    <?= $monthLabel ?>
                </option>
            <?php endfor; ?>
        </select>

        <label for="year">Year:</label>
        <select name="year" id="year" onchange="this.form.submit()">
            <option value="">All Years</option>
            <?php
            $startYear = 2023; // Adjust as needed
            $currentYear = date('Y');
            for ($y = $startYear; $y <= $currentYear; $y++):
            ?>
                <option value="<?= $y ?>" <?= ($year == $y) ? 'selected' : '' ?>>
                    <?= $y ?>
                </option>
            <?php endfor; ?>
        </select>
    </form>

    <div class="table-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="txnSearch" placeholder="Search this table (date, cashier, amount, remarks...)">
    </div>

    <div class="transactions-container table-scroll">
    <table class="transactions-table" id="txnTable">
    <thead>
        <tr>
        <th>DATE</th>
        <th>ID NO.</th>
        <th>CASHIER</th>
            <th>DINE IN</th>
            <th>TAKEOUT</th>
            <th>DEPOSIT</th>
            <th>CASHOUT</th>
            <th>INTEREST</th>
            <th>GIFT CARD</th>
            <th>TRANSFER NAME</th>
            <th>TRANSFER AMOUNT</th>
            <th>PAYMENT METHOD</th>
            <th>BANK</th>
            <th>RUNNING TOTAL</th>
            <th>STATUS</th>
            <th>REMARKS</th>
            <th>ACTION</th>
            <th>CHECKED</th>


        </tr>
    </thead>
    <tbody>
    <?php if (empty($all_records)) : ?>
        <tr><td colspan="18" style="text-align:center;">NO TRANSACTION FOR THIS MONTH.</td></tr>
        <?php else: foreach ($all_records as $record) : ?>
    <?php
        // Initialize financial fields
        $dinein_amount = $record['dinein'] ?? 0;
        $takeout_amount = $record['takeout'] ?? 0;
        $cashout_amount = $record['cashout'] ?? 0;
        $interest_amount = $record['interest'] ?? 0;

        // Initialize deposit and transfer variables
        $display_transfer_name = '';
        $transfer_amount = 0;
        
        // Correctly fetch deposit amount
        $deposit_amount = $record['deposit'] ?? 0; 
        
        // If receiver_amount exists, add it to the deposit column
        if (isset($record['receiver_amount']) && !empty($record['receiver_amount'])) {
            $deposit_amount += $record['receiver_amount']; // Include both deposits
            $display_transfer_name = $record['sender_name']; // Show sender name
        }

        // Check if the amount is in sender_amount (for transfers)
        if (isset($record['sender_amount']) && $record['sender_name'] === $employee_data['sender_name']) {
            $transfer_amount = $record['sender_amount']; // Set transfer amount
            $display_transfer_name = strtoupper($record['receiver_name'] ?? '');
        }
    ?>
    <?php
        // Records already checked/reconciled are locked from inline editing,
        // the same way they're already locked from deletion.
        $is_locked = ($record['checked'] === 'checked');
        $editable_attr = $is_locked ? '' : 'class="editable-cell" data-id="' . (int)$record['id'] . '"';
        $has_receiver_split = isset($record['receiver_amount']) && !empty($record['receiver_amount']);
    ?>


<tr>
<td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="created_at" data-type="date"' ?>><?= date("d-M-Y", strtotime($record['created_at'] ?? '')); ?></td>
<td><?= strtoupper(htmlspecialchars($record['id'] ?? '')); ?></td>
<td><?= strtoupper(htmlspecialchars($record['cashier'] ?? '')); ?></td>

    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="dinein" data-type="number"' ?>><?= ($dinein_amount > 0) ? '₱' . number_format($dinein_amount, 2) : ''; ?></td>
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="takeout" data-type="number"' ?>><?= ($takeout_amount > 0) ? '₱' . number_format($takeout_amount, 2) : ''; ?></td>

    <!-- Deposit Column: Only one source, no double counting.
         Left read-only when a transfer (receiver_amount) is folded into the
         displayed total, since editing would no longer map to a single field. -->
    <td <?= (!$is_locked) ? $editable_attr . ' data-field="deposit" data-type="number"' : '' ?>><?= ($deposit_amount > 0) ? '₱' . number_format($deposit_amount, 2) : ''; ?></td>

    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="cashout" data-type="number"' ?>><?= ($cashout_amount > 0) ? '₱' . number_format($cashout_amount, 2) : ''; ?></td>
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="interest" data-type="number"' ?>><?= ($interest_amount > 0) ? '₱' . number_format($interest_amount, 2) : ''; ?></td>
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="giftcard" data-type="number"' ?>><?= ($record['giftcard'] > 0) ? number_format($record['giftcard'], 2) : ''; ?></td>

    <!-- Transfer Name (Sender or Receiver Based on Transaction) -->
    <td><?= strtoupper($display_transfer_name); ?></td>

    <!-- Transfer Amount Column (Sender Amount) -->
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="sender_amount" data-type="number"' ?>><?= ($transfer_amount > 0) ? '₱' . number_format($transfer_amount, 2) : ''; ?></td>

    <td><?= strtoupper(htmlspecialchars($record['payment_method'] ?? '')); ?></td>
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="card" data-type="text"' ?>><?= strtoupper(htmlspecialchars($record['card'] ?? '')); ?></td>

    <td class="<?= ($record['running_total'] < 0) ? 'amount-negative' : 'amount-positive'; ?>">
    <?= number_format($record['running_total'], 2) ?>
</td>

<td>
        <?php if ($record['running_total'] < 0): ?>
            <span class="status-pill payable">Payable</span>
        <?php else: ?>
            <span class="status-pill balance">Balance</span>
        <?php endif; ?>
    </td>
    <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="remarks" data-type="text"' ?>>
    <?= strtoupper(htmlspecialchars($record['remarks'] ?? '')); ?>
</td>
<td>
    <form method="GET" action="delete.php" 
    onsubmit="return confirm('Are you sure you want to delete this record?');" 
    style="margin: 0;">
        <input type="hidden" name="id" value="<?= $record['id']; ?>">
        <button type="submit" class="delete-record-btn">
    DELETE
</button>

    </form>


</td>
<td>
    <span 
        class="check-toggle" 
        data-id="<?= $record['id'] ?>" 
        data-checked="<?= htmlspecialchars($record['checked']) ?>"
    >
        <?= strtolower(trim($record['checked'])) === 'checked' ? '✔️' : '⬜' ?>
    </span>
</td>


</tr>


<?php endforeach; endif; ?>

</tbody>


</table>
    </div>
</div>

<script>
    document.querySelectorAll("td.amount").forEach(function(td) {
        let amount = parseFloat(td.textContent.replace(/[^\d.]/g, '')) || 0;
        if (amount > 0) {
            td.innerHTML = `<b>₱${amount.toFixed(2)}</b>`;
        } else {
            td.textContent = "";
        }
    });
    function autoSubmit() {
        document.getElementById('filterForm').submit();
    }

    // Quick-add transaction modal
    function openQuickAdd(type, label) {
        document.getElementById('quickAddType').value = type;
        document.getElementById('quickAddTitle').textContent = 'Add ' + label + ' Transaction';
        document.getElementById('quickAddAmount').value = '';
        document.getElementById('quickAddRemarks').value = '';
        document.getElementById('quickAddCardChoice').value = '';
        document.getElementById('quickAddCardOther').value = '';
        document.getElementById('quickAddCardOther').style.display = 'none';
        document.getElementById('quickAddCard').value = '';
        document.getElementById('quickAddModal').style.display = 'block';
        document.getElementById('quickAddAmount').focus();
    }

    function closeQuickAdd() {
        document.getElementById('quickAddModal').style.display = 'none';
    }

    // Keep the hidden submitted "card" value in sync with the Bank/Card
    // dropdown, or the free-typed name when "Others" is selected.
    (function () {
        const choice = document.getElementById('quickAddCardChoice');
        const other = document.getElementById('quickAddCardOther');
        const hidden = document.getElementById('quickAddCard');
        if (!choice || !other || !hidden) return;

        function sync() {
            if (choice.value === 'others') {
                other.style.display = 'inline-block';
                hidden.value = other.value.trim();
            } else {
                other.style.display = 'none';
                hidden.value = choice.value;
            }
        }
        choice.addEventListener('change', sync);
        other.addEventListener('input', sync);

        document.getElementById('quickAddForm').addEventListener('submit', function (e) {
            if (choice.value === 'others' && other.value.trim() === '') {
                e.preventDefault();
                alert("Please specify the card/bank name for 'Others'.");
                other.focus();
            }
        });
    })();


    function printProfile() {
    const urlParams = new URLSearchParams(window.location.search);
    const name = urlParams.get('name') || '';
    const month = urlParams.get('month') || '';
    const year = urlParams.get('year') || '';

    const printUrl = `print.php?name=${encodeURIComponent(name)}&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`;

    const iframe = document.getElementById('print-frame');
    iframe.src = printUrl;

    iframe.onload = function() {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();

        iframe.onload = null;
    };
}
attachTableSearch('txnSearch', '#txnTable', { emptyMessage: 'No transactions match your search.' });
enableInlineEdit('#txnTable', 'update_field.php');

document.querySelectorAll(".check-toggle").forEach(function(span) {
    span.addEventListener("click", function () {
        const id = this.getAttribute("data-id");
        const isChecked = this.getAttribute("data-checked");

        if (isChecked === "checked") return; // Already checked

        fetch(window.location.pathname, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `id=${encodeURIComponent(id)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.textContent = "✔️";
                this.setAttribute("data-checked", "checked");

                // Note: checked records stay deletable -- checking only
                // locks the row from further inline edits below, it no
                // longer disables the DELETE button.

                // Lock this row's editable cells now that it's checked
                this.closest("tr").querySelectorAll(".editable-cell").forEach(function (cell) {
                    cell.setAttribute("contenteditable", "false");
                    cell.classList.remove("editable-cell", "inline-editable");
                    cell.removeAttribute("title");
                });
            } else {
                alert("Failed to save: " + (data.error || "Unknown error"));
            }
        })
        .catch(err => {
            alert("Request failed: " + err.message);
        });
    });
});

setTimeout(function () {
    const successMsg = document.querySelector('.success-message');
    const errorMsg = document.querySelector('.error-message');
    [successMsg, errorMsg].forEach(function (el) {
        if (el) {
            el.style.transition = 'opacity 0.5s ease';
            el.style.opacity = 0;
            setTimeout(() => el.remove(), 500);
        }
    });
}, 3000);

</script>

<script>
(function () {
    const labels = <?= json_encode($balance_history_labels) ?>;
    const values = <?= json_encode($balance_history_values) ?>;
    const ctx = document.getElementById('balanceHistoryChart').getContext('2d');
    const lineColor = values[values.length - 1] < 0 ? '#c62828' : '#1b5e20';
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Remaining balance',
                data: values,
                borderColor: lineColor,
                backgroundColor: lineColor + '22',
                borderWidth: 2,
                tension: 0.25,
                fill: true,
                pointRadius: 3,
                pointBackgroundColor: values.map(v => v < 0 ? '#c62828' : '#1b5e20')
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return '₱' + Number(context.parsed.y).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function (value) { return '₱' + Number(value).toLocaleString(); }
                    }
                }
            }
        }
    });
})();
</script>

</body>
</html>