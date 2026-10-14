<?php  
session_start();




require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


if (isset($_GET['name'])) {
    $name = urldecode($_GET['name']);
} else {
    // fallback if no name
    header('Location: summary_customer.php');
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


// Remove the label and only use color for balance
if ($total_balance > 0) {
    $balance_color = "green";  // Color for positive balance
} elseif ($total_balance < 0) {
    $balance_color = "red";    // Color for negative balance (payable)
} elseif ($total_balance == 0) {
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
    <title>Print MEDREP Profile</title>
    <style>
    body {
    font-family: Arial, sans-serif;
    background: rgb(227, 251, 203); /* Background color of the page */
    margin: 0;
    padding: 0;
    text-align: center;
    text-transform: uppercase;
}

.container {
    width: 100%;
    max-width: 800px; /* Maximum width to simulate the print width */
    margin: 20px auto;
    background: #fff; /* White background for the content */
    padding: 30px;
    border: 1px solid rgba(0, 0, 0, 0.1); /* Thin border around the container */
    border-radius: 8px; /* Rounded corners for a smooth effect */
    text-align: left;
    background-image: none; /* No texture */
}

h2, h3 {
    text-transform: uppercase;
    font-weight: bold;
    font-size: 40px;
    margin: 0; /* Remove extra space between headings */
}

h2 {
    font-size: 45px;
}

h4 {
    font-size: 25px;
    margin-bottom: 2px;
}

.profile-info {
    margin: 0;
    padding: 0;
}

.profile-info p {
    font-size: 24px;
    margin: 0;
    padding: 0;
}

.total_balance {
    font-size: 40px;
    color: #1b5e20;
    font-weight: bold;
    margin-bottom: 20px;
}

/* IDs + !important below: assets/style.css ships global, !important,
   higher-specificity rules for any bare "table"/"th"/"td"/".container" on
   the site (used to style the main app tables/cards). Those silently beat
   the plain element-selector rules above, both on screen and when actually
   printing, which is why width/font/padding tweaks here kept getting
   ignored. Scoping to #print-table / #print-container with !important is
   the only way to reliably win against that sitewide sheet. */
#print-table {
    width: 100% !important;
    table-layout: fixed !important; /* Ensures the table adjusts to container width */
    border-collapse: collapse !important;
    margin-top: 0px !important;
    text-align: center !important;
    font-size: 12px !important;
    /* assets/style.css has a responsive safety-net rule that turns any
       plain <table> under a 900px viewport into display:block +
       white-space:nowrap (for scrollable data tables elsewhere in the
       app). That shrinks this table to its content width instead of
       filling the container, leaving the blank gap on the right --
       these three overrides opt this table back out of that behavior. */
    display: table !important;
    white-space: normal !important;
    overflow-x: visible !important;
}

#print-table th, #print-table td {
    border: 1px solid #ddd !important;
    padding: 6px 5px !important;
    word-wrap: break-word !important; /* Ensure text fits within cells */
    font-size: 12px !important;
}

#print-table th {
    background-color: #1b5e20 !important;
    color: #fff !important;
    font-size: 11px !important;
}

@media print {
    @page {
        size: A4 portrait; /* back to portrait per request -- uses less
                              paper than landscape. Columns are made to fit
                              by using a compact fixed-width table below,
                              with header text wrapping onto two lines
                              (e.g. "Sender Amount") the same way the
                              reference layout does. */
        margin: 8mm;
    }

    body {
        margin: 0;
        padding: 0;
        font-size: 12px;
    }

    .container, #print-container {
        width: 100% !important;
        max-width: none !important; /* was 800px -- narrower than the printable page
                             width once the table has 10 columns */
        margin: 0 auto !important;
        background: #fff !important;
        padding: 10px !important;
        border-radius: 0 !important;
        text-align: left !important;
        background-image: none !important; /* No texture */
        box-shadow: none !important; /* Remove shadow for print */
        border: none !important; /* Ensure no border during print */
    }

    h2, h3 {
        font-size: 20px; /* Adjust size for print */
        margin-bottom: 5px;
    }

    h4 {
        font-size: 13px; /* Adjust size for print */
    }

    .total_balance {
        font-size: 16px;
        margin-bottom: 8px;
    }

    #print-table {
        width: 100% !important;
        table-layout: fixed !important; /* even column widths across the full page
                                 width, so every column is guaranteed to fit
                                 and none get cut off */
        border-collapse: collapse !important;
        margin-top: 0px !important;
        text-align: center !important;
        display: table !important;
        white-space: normal !important;
        overflow-x: visible !important;
    }

    #print-table th, #print-table td {
        padding: 4px 3px !important;
        text-align: center !important;
        font-size: 9px !important;
        line-height: 1.25 !important;
        overflow-wrap: break-word !important; /* Ensure text wraps and doesn't overflow */
    }

    #print-table th {
        background-color: #1b5e20 !important;
        color: #fff !important;
        font-size: 8px !important; /* Adjust size for print */
    }

    .no-print {
        display: none; /* Hide elements you don't want printed */
    }
}

</style>


    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container" id="print-container">
        <div class="profile-info">
            <h2>Name: <?= strtoupper($employee_data['name']) ?></h2>
            <h3>Company: <?= strtoupper($employee_data['company']) ?></h3>
            <div class="total_balance" style="color: <?= $balance_color ?>;">
    <strong>Total Balance: ₱<?= number_format($total_balance, 2) ?></strong> <!-- Keep the negative sign for payable -->
</div>
            <div class="total_balance" style="color: <?= $record_total < 0 ? 'red' : ($record_total > 0 ? 'green' : 'black') ?>;">
    <strong>Record Total (Card): ₱<?= number_format($record_total, 2) ?></strong>
</div>


        </div>
        <h4>Transaction History</h4>
        <table id="print-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Dine-in</th>
                    <th>Takeout</th>
                    <th>Deposit</th>
                    <th>Sender Amount</th>
                    <th>Cashout</th>
                    <th>Interest</th>
                    <th>Giftcard</th>
                    <th>Payment Method</th>
                    <th>Running Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_records as $transaction): ?>
                    <tr>
                        <td><?= date('Y-m-d H:i:s', strtotime($transaction['created_at'])) ?></td>
                        <td>₱<?= number_format($transaction['dinein'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['takeout'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['deposit'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['sender_amount'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['cashout'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['interest'] ?? 0, 2) ?></td>
                        <td>₱<?= number_format($transaction['giftcard'] ?? 0, 2) ?></td>
                        <td><?= ucfirst($transaction['payment_method'] ?? '') ?></td>
                        <td><?= ucfirst($transaction['running_total'] ?? '') ?></td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()">Print this page</button>
            <button onclick="window.history.back()">Go back</button>
        </div>
    </div>

    <body onload="triggerPrintAndRedirect()">

<script>
function triggerPrintAndRedirect() {
    window.print();
    window.onafterprint = function() {
        // Redirect back to summary_customer.php with the customer name
        window.location.href = "summary_customer.php?name=<?php echo urlencode($name); ?>";
    };
}
</script>
</body>
</html>