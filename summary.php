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

$name = isset($_GET['name']) ? strtoupper(trim($_GET['name'])) : '';
$transfer_amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

if (!$name) {
    die("MEDREP NOT FOUND: NAME PARAMETER IS MISSING.");
}

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

// Fetch total deposit (sum of deposits and receiver amounts)
// Card-paid ("Swipe" -- Maya/Metrobank/etc) rows are excluded here --
// they don't touch the normal running balance at all. Instead they're
// Deposits count normally no matter the payment method -- card only
// changes how Dine In/Takeout/Cashout/Interest/Gift Card are handled.
$deposit_query = "SELECT SUM(deposit) AS total_deposit, SUM(receiver_amount) AS total_receiver FROM employees WHERE UPPER(name) = ?";
$deposit_stmt = $conn->prepare($deposit_query);
$deposit_stmt->bind_param("s", $name);
$deposit_stmt->execute();
$deposit_result = $deposit_stmt->get_result();
$row = $deposit_result->fetch_assoc();
$deposit_stmt->close();

$total_deposit = ($row['total_deposit'] ?? 0) + ($row['total_receiver'] ?? 0);

// Card-paid rows excluded again here -- tracked in $record_total instead.
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

        // Card-paid ("Swipe") rows sit in the separate Record Total
        // instead of the normal running balance.
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

    // Card-paid ("Swipe") rows don't touch the running balance at all --
    // they're totalled separately in Record Total.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($_FILES['profile_photo']['type'], $allowedTypes)) {
        die("Invalid file type. Only JPG, PNG, and GIF are allowed.");
    }

    $fileTmpPath = $_FILES['profile_photo']['tmp_name'];
    $fileName = $name . ".jpg";
    $destination = $uploadDir . $fileName;

    if (move_uploaded_file($fileTmpPath, $destination)) {
        $photoPath = $destination;
    } else {
        echo "Error uploading file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEDREP PROFILE</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background:rgb(227, 251, 203);
            margin: 0;
            padding: 0;
            text-align: center;
            text-transform: uppercase;
        }
        .container {
            width: 97.5%;
            margin: 0px;
            background:rgb(227, 251, 203);
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            margin-top: 0px;
        }
        .profile-container {
            display: flex;
            align-items: center;
            justify-content: left;
            margin-left: 55px;
        }
        .profile-container img {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            margin-left: 300px;
        }
        .upload-label {
            cursor: pointer;
        }
        .upload-label img {
            width: 30px;
            height: 30px;
        }
        .profile-container h2, 
.profile-container h3 {
    margin-left: 30px;
    padding: 2px 0;
    line-height: 0;
}

.upload-label {
    display: inline-block;
    margin-top: 2px;
}
        h2, p {
            text-transform: uppercase;
            font-weight: bold;
        }
        h2 {
            font-size: 45px;
        }
        h3{
            text-align: left;
            font-size: 45px;
            color: #1b5e20;
        }
        p {
            font-size: 30px;
        }
        h4{
            text-align: center;
            font-size: 20px;
            color: #000;
        }
        .total-box h4, .total-box p {
    line-height: 5px;
}
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid black;
            padding: 10px;
            text-align: center;
        }
        th {
            background-color: #1b5e20;
            color: white;
        }
        th:nth-child(1), td:nth-child(1) {
    width: 100px;
        }
    th:nth-child(2), td:nth-child(2) {
    width: 80px;
}
   th:nth-child(13), td:nth-child(13) {
    width: 5px;
} 
        .amount {
            font-weight: bold;
        }
        .balance-deducted-row td {
            color: red;
        }
        .total-box-container {
            display: flex;
            justify-content: space-around;
            margin: 0px 0;
            font-size: 20px;
        }
        .total-box {
            width:23%;
            padding:5px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            border-radius: 5px;
            text-align: center;
            background-color: white;
            cursor: pointer;
        }
        .total-box.balance { background-color:rgb(186, 230, 139); color:rgba(5, 4, 4, 0)}
        .total-box.deposit { background-color: rgb(186, 230, 139 ) }
        .total-box.payable { background-color:rgb(186, 230, 139) }
        .total-box.cashout { background-color: rgb(186, 230, 139) }
        .total-box.giftcard { background-color:rgb(186, 230, 139) }
        .total-box:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
        }
        @media print {
        * {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: black;
        }
        body {
            margin: 20px;
            padding: 20px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .no-print {
            display: none !important;
        }
        .print-header {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .print-subheader {
            text-align: center;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .company-logo {
            display: block;
            margin: 0 auto;
            max-width: 100px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            page-break-inside: avoid;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background-color: #ddd;
            font-weight: bold;
        }
        .total-box-container {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
        }
        .total-box {
            border: 1px solid black;
            padding: 10px;
            text-align: center;
            width: 23%;
            font-weight: bold;
        }
        .page-break {
            page-break-before: always;
        }
    }
    
     .print-button {
    margin-right: 10px;
    background-color: #1b5e20;
    color: white;
    border: none;
    padding: 8px 15px;
    cursor: pointer;
    border-radius: 4px;
}

.print-button:hover {
    background-color: rgb(136, 227, 141);
}

/* Running Total / Status -- matches the pill-style color coding used
   on the medrep profile page (customer_profile.php) instead of the
   old hard red/green table-cell backgrounds, so both pages read the
   same way: green = balance in the medrep's favor, red = payable. */
.amount-positive { color: var(--success, #2e7d32); font-weight: 700; }
.amount-negative { color: var(--danger, #e0364a); font-weight: 700; }

.status-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 12px;
}
.status-pill.balance { background: var(--success-bg, #e7f8ee); color: var(--success, #2e7d32); }
.status-pill.payable { background: var(--danger-bg, #fdeced); color: var(--danger, #e0364a); }
    </style>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 5px;">
    <button style="margin-top: 0px; background-color: #1b5e20; color: white; border: none; padding: 8px 15px; cursor: pointer; border-radius: 4px;"
        onmouseover="this.style.backgroundColor='rgb(136, 227, 141)'" 
        onmouseout="this.style.backgroundColor='#1b5e20'"
        onclick="window.location.href='staff.php'">BACK
    </button>
    <button style="margin-top: 0px; background-color: #1b5e20; color: white; border: none; padding: 8px 15px; cursor: pointer; border-radius: 4px;"
        onmouseover="this.style.backgroundColor='rgb(136, 227, 141)'" 
        onmouseout="this.style.backgroundColor='#1b5e20'"
        onclick="window.location.href='sales.php'">DAILY SALES
    </button>
</div>
    <div class="profile-container">
    <div style="position: relative; display: inline-block;">
        <img src="<?= file_exists($photoPath) ? $photoPath : 'assets/images/default.jpg' ?>" alt="Profile Picture">
        <form method="POST" enctype="multipart/form-data" style="position: absolute; bottom: 10px; right: 10px;">
            <label for="profile_photo" class="upload-label">
                <img src="assets/images/upload.png" alt="Upload" style="width: 30px; height: 30px; cursor: pointer;">
            </label>
            <input type="file" id="profile_photo" name="profile_photo" accept="image/*" style="display: none;" onchange="this.form.submit()">
        </form>
    </div>
    <div>
        <h2><?= strtoupper($employee_data['name'] ?? ''); ?></h2>
        <h3><?= strtoupper($employee_data['company'] ?? ''); ?></h3>
    </div>
    </div>

<h2></h2>
<div class="total-box-container">
    <div class="total-box balance">
        <h4>TOTAL BALANCE</h4>
        <p style="color: <?php echo $balance_color; ?>;"><strong>₱<?php echo number_format($total_balance, 2); ?></strong></p>

    </div>

    <div class="total-box deposit">
        <h4>TOTAL DEPOSIT</h4>
        <p><strong>₱<?= number_format($total_deposit, 2); ?></strong></p>
    </div>
    <div class="total-box payable">
        <h4>TOTAL PAYABLE</h4>
        <p><strong>₱<?= number_format($total_payable, 2); ?></strong></p>
    </div>
    <div class="total-box cashout">
        <h4>TOTAL CASHOUT</h4>
        <p><strong>₱<?= number_format($total_cashout, 2); ?></strong></p>
    </div>
    <div class="total-box record">
        <h4>RECORD TOTAL (CARD)</h4>
        <p style="color: <?= $record_total < 0 ? 'red' : ($record_total > 0 ? 'green' : 'black') ?>;"><strong>₱<?= number_format($record_total, 2); ?></strong></p>
    </div>

</div>

<div class="transaction-header" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
    <h4 style="margin: 0;">TRANSACTION RECORDS</h4>
    <button class="print-button no-print" onclick="printProfile()">PRINT TRANSACTIONS</button>

<iframe id="print-frame" style="display: none;"></iframe>

</div>

<form method="GET" style="margin: 20px 0;" onsubmit="this.submit();">
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




    <h2></h2>
    <table border="1">
    <thead>
        <tr>
            <th>DATE</th>
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

        </tr>
    </thead>
    <tbody>
    <?php if (empty($all_records)) : ?>
        <tr><td colspan="12">NO TRANSACTION FOR THIS MONTH.</td></tr>
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

<tr>
    <td><?= date("d-M-Y", strtotime($record['created_at'] ?? '')); ?></td>
    <td><?= ($dinein_amount > 0) ? '₱' . number_format($dinein_amount, 2) : ''; ?></td>
    <td><?= ($takeout_amount > 0) ? '₱' . number_format($takeout_amount, 2) : ''; ?></td>

    <!-- Deposit Column: Only one source, no double counting -->
    <td><?= ($deposit_amount > 0) ? '₱' . number_format($deposit_amount, 2) : ''; ?></td>

    <td><?= ($cashout_amount > 0) ? '₱' . number_format($cashout_amount, 2) : ''; ?></td>
    <td><?= ($interest_amount > 0) ? '₱' . number_format($interest_amount, 2) : ''; ?></td>
    <td><?= ($record['giftcard'] > 0) ? number_format($record['giftcard'], 2) : ''; ?></td>

    <!-- Transfer Name (Sender or Receiver Based on Transaction) -->
    <td><?= strtoupper($display_transfer_name); ?></td>

    <!-- Transfer Amount Column (Sender Amount) -->
    <td><?= ($transfer_amount > 0) ? '₱' . number_format($transfer_amount, 2) : ''; ?></td>

    <td><?= strtoupper(htmlspecialchars($record['payment_method'] ?? '')); ?></td>
    <td><?= strtoupper(htmlspecialchars($record['bank'] ?? '')); ?></td>

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
    <td><?= strtoupper(htmlspecialchars($record['remarks'] ?? '')); ?></td>

</tr>


<?php endforeach; endif; ?>

</tbody>

</table>
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

</script>


</body>
</html>