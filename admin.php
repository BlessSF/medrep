<?php  
session_start();


// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$admin_username = $_SESSION['username'];
$admin_query = "SELECT username FROM users WHERE username = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("s", $admin_username);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin_row = $admin_result->fetch_assoc();
$admin_name = $admin_row['username'] ?? '';
$admin_stmt->close();



// Query 1: Get total gift card balance from employees
$query = "SELECT SUM(giftcard) AS total_giftcard_balance FROM employees";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
$total_giftcard_balance = $row['total_giftcard_balance'] ?? 0;

// Query 2: Get total dine-in and takeout paid with gift card from transactions table
$query_transactions = "
    SELECT SUM(dinein + takeout) AS total_used_giftcard
    FROM employees
    WHERE payment_method = 'giftcard'
";
$stmt_transactions = mysqli_prepare($conn, $query_transactions);
mysqli_stmt_execute($stmt_transactions);
$result_transactions = mysqli_stmt_get_result($stmt_transactions);
$row_transactions = mysqli_fetch_assoc($result_transactions);
$total_used_giftcard = $row_transactions['total_used_giftcard'] ?? 0;

// Calculate remaining gift card balance
$giftcard_balance = $total_giftcard_balance - $total_used_giftcard;

// Fetch total deposit (sum of deposits and receiver amounts)
$deposit_query = "SELECT SUM(deposit + receiver_amount) AS total_deposit FROM employees";
$deposit_stmt = $conn->prepare($deposit_query);
$deposit_stmt->execute();
$deposit_result = $deposit_stmt->get_result();
$row = $deposit_result->fetch_assoc();
$raw_total_deposit = ($row['total_deposit'] ?? 0);
$deposit_stmt->close();

// Query 2: Total payables -- dine-in/takeout/giftcard always count toward
// payable regardless of payment method (previously excluded 'cash' and
// 'card' payments entirely, understating what's actually owed).
$payable_query = "SELECT 
                    SUM(dinein) AS total_dinein, 
                    SUM(takeout) AS total_takeout, 
                    SUM(giftcard) AS total_giftcard
                  FROM employees";
$payable_stmt = $conn->prepare($payable_query);
$payable_stmt->execute();
$payable_result = $payable_stmt->get_result();
$row = $payable_result->fetch_assoc();

$total_dinein = $row['total_dinein'] ?? 0;
$total_takeout = $row['total_takeout'] ?? 0;
$total_giftcard = $row['total_giftcard'] ?? 0;

$raw_total_payable = $total_dinein + $total_takeout + $total_giftcard;
$payable_stmt->close();


// Adjust values according to business logic
if ($raw_total_payable >= $raw_total_deposit) {
    $adjusted_total_deposit = 0;
    $adjusted_total_receivable = $raw_total_payable - $raw_total_deposit;
} else {
    $adjusted_total_deposit = $raw_total_deposit - $raw_total_payable;
    $adjusted_total_receivable = 0;
}


// Fetch total cashout including interest
$cashout_query = "SELECT SUM(cashout + interest) AS total_cashout FROM employees ";
$cashout_stmt = $conn->prepare($cashout_query);
$cashout_stmt->execute();
$cashout_result = $cashout_stmt->get_result();
$total_cashout = $cashout_result->fetch_assoc()['total_cashout'] ?? 0;
$cashout_stmt->close();


// Fetch latest 20 transactions
$latest_transactions_sql = "
    SELECT 
        id,
        name,
        company, 
        deposit, 
        cashout, 
        interest, 
        giftcard,
        payment_method,
        card, 
        remarks, 
        dinein, 
        takeout,
        sender_amount,
        sender_name,
        receiver_amount,
        receiver_name, 
        cashier,
        created_at,
        checked
    FROM employees 
    ORDER BY created_at DESC 
    LIMIT 20";

$latest_transactions_result = $conn->query($latest_transactions_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <title>ADMIN DASHBOARD</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0px;
            padding: 0;
            background:rgb(227, 251, 203);
            text-transform: uppercase; /* Ensures all text is in capital letters */
        }
        .sidebar {
            width: 230px;
            height: 100vh;
            background-color: #1b5e20;
            color: #fff;
            position: fixed;
            padding-top: 20px;
        }
        .sidebar h2 {
    text-align: center;
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
}

        .sidebar ul {
            list-style-type: none;
            padding: 0;
        }
        .sidebar ul li {
            padding: 15px;
            text-align: center;
        }
        .sidebar ul li button {
            width: 100%;
            padding: 15px;
            background-color: #abd67c;
            color: #000;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 15px;
            border-radius: 5px;

        }
        .sidebar ul li button:hover {
            background-color:rgb(36, 125, 59);
        }
        

        .main-content {
            margin-left: 230px;
            padding: 20px;
            background:rgb(227, 251, 203);
        }
        .summary {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.summary-item h2 {
    font-size: 16px; /* Make the heading smaller */
    font-weight: normal;
    margin-bottom: 5px;
}

.summary-item p {
    font-size: 24px; /* Make the numerical values larger */
    font-weight: bold;
}
.summary-item {
    background-color: #abd67c; 
    padding: 5px; /* Reduced padding to make it smaller */
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    flex: 1;
    text-align: center;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    min-width: 200px;
}

.summary-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}
.transactions-container {
    margin-right: 2px;
    margin-top: 20px;
    background: white;
    padding: 20px;
    border-radius: 0px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    overflow: auto;
}

.transactions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    border: 1px solid black;
}

.transactions-table th,
.transactions-table td {
    padding: 12px;
    text-align: center;
    border: 1px solid black; /* Add black borders to each cell */
}

/* Header Design */
.transactions-table thead {
    background: #1b5e20;
    color: white;
    text-transform: uppercase;
    font-size: 13px;
}
.transactions-table td:nth-child(1), /* Name column */
.transactions-table th:nth-child(1) {
    width: 180px;
}

/* Adjust specific column widths */
.transactions-table td:nth-child(2), /* Name column */
.transactions-table th:nth-child(2) {
    width: 130px;
}

.transactions-table td:nth-child(3), /* Company column */
.transactions-table th:nth-child(3) {
    width: 130px;
}
.transactions-table td:nth-child(10), /* Name column */
.transactions-table th:nth-child(10) {
    width: 130px;
}

.transactions-table td:nth-child(12), /* Payment Method column */
.transactions-table th:nth-child(12) {
    width: 75px;
}


/* Bold Numbers */
.bold {
    font-weight: bold;
}

    </style>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/table-search.js"></script>
    <script src="assets/inline-edit.js"></script>
</head>
<body>
<div class="sidebar">
        <div class="sidebar-profile">
        <div class="sidebar-avatar"><i class="fas fa-user"></i></div>
        <div class="sidebar-profile-text">
            <span class="sidebar-greeting">Hello,</span>
            <span class="sidebar-username"><?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>
    <ul>
        <li><button onclick="location.href='admin.php'"><i class="fas fa-tachometer-alt"></i>   DASHBOARD</button></li>
        <li><button onclick="location.href='customer.php'"><i class="fas fa-users"></i>   CUSTOMERS</button></li>
        <li><button onclick="location.href='block_medrep.php'"><i class="fas fa-user-lock"></i>   BLOCK MEDREP</button></li>
        <li><button onclick="location.href='companies.php'"><i class="fas fa-building"></i>   COMPANIES</button></li>
                <li><button onclick="location.href='daily.php'"><i class="fas fa-calendar-day"></i>   RECEIVABLES/PAYABLES</button></li>
                <li><button onclick="location.href='receivables_summary.php'"><i class="fas fa-calendar-alt"></i>   SUMMARY</button></li>
                <li><button onclick="location.href='voucher_monitoring.php'"><i class="fas fa-ticket-alt"></i>   VOUCHER MONITORING</button></li>
        <li><button onclick="location.href='report.php'"><i class="fas fa-chart-line"></i>   SALES REPORTS</button></li>
        <li><button onclick="location.href='cashout.php'"><i class="fas fa-money-bill-wave"></i>   CASHOUT REPORT</button></li>
        <li><button onclick="location.href='users.php'"><i class="fas fa-users-cog"></i>   USERS</button></li>
        <li><button onclick="location.href='tracking.php'"><i class="fas fa-bell"></i>   ADMIN TRACKING</button></li>
        <li><button onclick="location.href='index.php'"><i class="fas fa-sign-out-alt"></i>   LOGOUT</button></li>
    </ul>
</div>


    <div class="main-content">

        <h1>ADMIN DASHBOARD</h1>
        <div class="summary">
            <div class="summary-item">
                <h2>GIFT CARD BALANCE</h2>
                <p><?php echo $giftcard_balance; ?></p>
            </div>
            <div class="summary-item">
    <h2>TOTAL DEPOSIT</h2>
    <p>₱<?php echo number_format($adjusted_total_deposit, 2); ?></p>
</div>
            <div class="summary-item">
                <h2>TOTAL CASHOUT</h2>
                <p>₱<?php echo number_format($total_cashout, 2); ?></p>
            </div>
            <div class="summary-item">
    <h2>TOTAL RECEIVABLE</h2>
    <p>₱<?php echo number_format($adjusted_total_receivable, 2); ?></p>
</div>
        </div>

    <h2>Latest Transactions</h2>
    <div class="table-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="adminTxnSearch" placeholder="Search this table...">
    </div>
    <table class="transactions-table" id="adminTxnTable">
        <thead>
            <t>
            <th>DATE</th>
            <th>MEDREP NAME</th>
            <th>COMPANY</th>
            <th>DINE IN</th>
            <th>TAKEOUT</th>
            <th>DEPOSIT</th>
            <th>CASHOUT</th>
            <th>INTEREST</th>
            <th>GIFT CARD</th>
            <th>TRANSFER NAME</th>
            <th>TRANSFER AMOUNT</th>
            <th>PAYMENT METHOD</th>
            <th>CASHIER</th>
        </thead>
        <tbody>
<?php while ($row = $latest_transactions_result->fetch_assoc()): ?>
    <?php
        $is_locked = ($row['checked'] === 'checked');
        $rid = (int)$row['id'];
        $editable_attr = $is_locked ? '' : ('class="editable-cell" data-id="' . $rid . '"');
        $has_receiver_split = !empty($row['receiver_amount']);
    ?>
    <tr>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="created_at" data-type="date"' ?>><?php echo date('Y-M-d ', strtotime($row['created_at'])); ?></td>
        <td><?php echo htmlspecialchars($row['name'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($row['company'] ?? ''); ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="dinein" data-type="number"' ?>><?php echo ($row['dinein'] != 0) ? htmlspecialchars($row['dinein']) : ''; ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="takeout" data-type="number"' ?>><?php echo ($row['takeout'] != 0) ? htmlspecialchars($row['takeout']) : ''; ?></td>
        <td <?= (!$is_locked && !$has_receiver_split) ? $editable_attr . ' data-field="deposit" data-type="number"' : '' ?>><?php 
            $deposit = $row['deposit'] ?? 0;
            $receiver_amount = $row['receiver_amount'] ?? 0;
            $total_deposit = $deposit + $receiver_amount;
            echo ($total_deposit != 0) ? htmlspecialchars($total_deposit) : '';
        ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="cashout" data-type="number"' ?>><?php echo ($row['cashout'] != 0) ? htmlspecialchars($row['cashout']) : ''; ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="interest" data-type="number"' ?>><?php echo ($row['interest'] != 0) ? htmlspecialchars($row['interest']) : ''; ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="giftcard" data-type="number"' ?>><?php echo ($row['giftcard'] != 0) ? htmlspecialchars($row['giftcard']) : ''; ?></td>
        <td><?php 
            $transfer_name = trim(($row['sender_name'] ?? '') . ' ' . ($row['receiver_name'] ?? ''));
            echo !empty(trim($transfer_name)) ? htmlspecialchars($transfer_name) : '';
        ?></td>
        <td <?= $editable_attr ?> <?= $is_locked ? '' : 'data-field="sender_amount" data-type="number"' ?>><?php echo ($row['sender_amount'] != 0) ? htmlspecialchars($row['sender_amount']) : ''; ?></td>
        <td><?php echo htmlspecialchars($row['payment_method'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($row['cashier'] ?? ''); ?></td>
    </tr>
<?php endwhile; ?>
</tbody>


    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    attachTableSearch('adminTxnSearch', '#adminTxnTable', { emptyMessage: 'No transactions match your search.' });
    enableInlineEdit('#adminTxnTable', 'update_field.php');
});
</script>

</body>
</html>