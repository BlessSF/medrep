<?php  
session_start();




// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Session check
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$admin_username = $_SESSION['username'];

// Get selected month and year
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// ------------------------------------------------------------
// Optional custom date-range filter. This is an alternative to the
// Month/Year dropdowns above -- it lets the period span across a year
// boundary (e.g. Dec 2025 - Feb 2026), which a single month+year pick
// can never do. When both dates are present and valid, the range wins
// and the month/year dropdowns are treated as "All" for display.
// ------------------------------------------------------------
$range_from = isset($_GET['range_from']) ? trim($_GET['range_from']) : '';
$range_to   = isset($_GET['range_to']) ? trim($_GET['range_to']) : '';
$use_range  = $range_from !== '' && $range_to !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_from)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_to);

if ($use_range && $range_from > $range_to) {
    // Swap so the range is always valid regardless of input order.
    [$range_from, $range_to] = [$range_to, $range_from];
}

// Fetch all transactions (ignoring month/year filter for total calculations)
$where_clauses = [];
if ($use_range) {
    $safe_from = $conn->real_escape_string($range_from);
    $safe_to = $conn->real_escape_string($range_to);
    $where_clauses[] = "DATE(created_at) BETWEEN '$safe_from' AND '$safe_to'";
    // Display the dropdowns as "All" since the range is what's actually applied.
    $selected_month = 0;
    $selected_year = 0;
} else {
    if ($selected_year != 0) {
        $where_clauses[] = "YEAR(created_at) = $selected_year";
    }
    if ($selected_month != 0) {
        $where_clauses[] = "MONTH(created_at) = $selected_month";
    }
}
$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);
}

// Query to get transactions based on selected month/year
$query = "
    SELECT 
        e.name,
        DATE(e.created_at) as transaction_date,
        e.dinein,
        e.takeout,
        e.deposit,
        e.cashout,
        e.giftcard,
        e.interest,
        e.sender_amount,
        e.payment_method,
        e.sender_name,
        e.block
    FROM employees e
    $where_sql
    ORDER BY e.created_at ASC
";

$result = $conn->query($query);

// Organize by dates
$transactions = [];
$all_dates = [];
$employee_balances = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $date = $row['transaction_date'];
        $name = $row['name'];

        if (!isset($transactions[$date])) {
            $transactions[$date] = [
                'dinein' => 0,
                'takeout' => 0,
                'deposit' => 0,
                'cashout' => 0,
                'giftcard' => 0,
                'interest' => 0,
                'sender_amount' => 0,
                'details' => []
            ];
            array_unshift($all_dates, $date); // Reverse the order here
        }

        $transactions[$date]['dinein'] += floatval($row['dinein']);
        $transactions[$date]['takeout'] += floatval($row['takeout']);
        
        if (empty($row['sender_name'])) {
            $transactions[$date]['deposit'] += floatval($row['deposit']);
        }

        $transactions[$date]['cashout'] += floatval($row['cashout']);
        $transactions[$date]['giftcard'] += floatval($row['giftcard']);
        $transactions[$date]['interest'] += floatval($row['interest']);
        $transactions[$date]['sender_amount'] += floatval($row['sender_amount']);

        $transactions[$date]['details'][] = $row;
    }
}

// Now calculate cumulative balance and total payable/receivable
$daily_summary = [];
$running_balances = [];
$total_payable = 0;
$total_receivable = 0;

// Loop through all transactions to calculate total payable/receivable across all months
foreach ($transactions as $date => $trans) {
    foreach ($trans['details'] as $trans_detail) {
        // Calculate total payable (not dependent on month/year)
        if ($trans_detail['payment_method'] == 'na' || $trans_detail['payment_method'] == 'paid') {
            $total_payable += floatval($trans_detail['dinein']) + floatval($trans_detail['takeout']) + floatval($trans_detail['giftcard']);
        }

        // Calculate total receivable (not dependent on month/year)
        $total_receivable += floatval($trans_detail['cashout']) + floatval($trans_detail['sender_amount']);
    }
}

// Now calculate running balance for each employee
foreach (array_reverse($all_dates) as $date) { // Reverse loop here
    foreach ($transactions[$date]['details'] as $trans) {
        $name = $trans['name'];

        // Blocked medreps are excluded from the payable/receivable
        // running totals entirely (their balance no longer counts
        // toward Total Payable / Total Receivable on this page).
        $is_blocked = strtolower(trim((string)($trans['block'] ?? ''))) === 'block';
        if ($is_blocked) {
            continue;
        }

        if (!isset($running_balances[$name])) {
            $running_balances[$name] = 0;
        }

        $deposit = (empty($trans['sender_name'])) ? floatval($trans['deposit']) : 0;
        $payable = 0;
        if ($trans['payment_method'] == 'na' || $trans['payment_method'] == 'paid') {
            $payable = floatval($trans['dinein']) + floatval($trans['takeout']) + floatval($trans['giftcard']);
        } elseif ($trans['payment_method'] == 'giftcard') {
            $payable = 0;
        }

        $cashout = floatval($trans['cashout']);
        $interest = floatval($trans['interest']);
        $sender_amount = floatval($trans['sender_amount']);

        $running_balances[$name] += $deposit - ($payable + $cashout + $interest + $sender_amount);
    }

    $positive_total = 0;
    $negative_total = 0;

    foreach ($running_balances as $balance) {
        if ($balance >= 0) {
            $positive_total += $balance;
        } else {
            $negative_total += $balance;
        }
    }

    $daily_summary[$date] = [
        'dinein' => $transactions[$date]['dinein'],
        'takeout' => $transactions[$date]['takeout'],
        'total_sales' => $transactions[$date]['dinein'] + $transactions[$date]['takeout'],
        'total_deposit' => $transactions[$date]['deposit'],
        'positive_total' => $positive_total,
        'negative_total' => $negative_total
    ];
}

// ------------------------------------------------------------
// Grand total for the CURRENTLY SELECTED month/year filter: this is
// just the most recent date's running snapshot, since positive_total/
// negative_total already accumulate everything up to and including
// that date within the filtered range.
// ------------------------------------------------------------
$selection_positive_total = 0;
$selection_negative_total = 0;
if (!empty($daily_summary)) {
    $last_summary = end($daily_summary);
    $selection_positive_total = $last_summary['positive_total'];
    $selection_negative_total = $last_summary['negative_total'];
}

// ------------------------------------------------------------
// Grand total across ALL TIME, regardless of the month/year filter
// above -- always excludes blocked medreps. Computed directly in SQL
// (one row per medrep) rather than re-walking every transaction.
// ------------------------------------------------------------
$alltime_positive_total = 0;
$alltime_negative_total = 0;
$alltime_sql = "
    SELECT name,
        SUM(CASE WHEN (sender_name IS NULL OR sender_name = '') THEN deposit ELSE 0 END) AS dep,
        SUM(CASE WHEN payment_method IN ('na','paid') THEN dinein + takeout + giftcard ELSE 0 END) AS payable,
        SUM(cashout) AS cashout_sum,
        SUM(interest) AS interest_sum,
        SUM(sender_amount) AS sender_sum
    FROM employees
    WHERE (block IS NULL OR LOWER(TRIM(block)) <> 'block')
    GROUP BY name
";
$alltime_result = $conn->query($alltime_sql);
if ($alltime_result) {
    while ($arow = $alltime_result->fetch_assoc()) {
        $net = floatval($arow['dep']) - (floatval($arow['payable']) + floatval($arow['cashout_sum']) + floatval($arow['interest_sum']) + floatval($arow['sender_sum']));
        if ($net >= 0) {
            $alltime_positive_total += $net;
        } else {
            $alltime_negative_total += $net;
        }
    }
}

$conn->close();
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Sales</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background:rgb(227, 251, 203);
            margin: 0;
            padding: 0;
            text-transform: uppercase;
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
        }
        .sidebar ul {
            list-style: none;
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
            margin-left: 250px;
            padding: 10px;
        }
        .filter-form {
            margin-bottom: 20px;
        }
        .filter-form select {
            padding: 5px;
            font-size: 16px;
            margin-right: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            border: 1px solid black;
        }
        table th, table td {
            padding: 12px;
            text-align: center;
            border: 1px solid black;
        }
        table thead {
            background: #1b5e20;
            color: white;
        }
        .bold {
            font-weight: bold;
        }
        .summary-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-card {
            flex: 1 1 220px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 16px 18px;
            text-decoration: none;
            display: block;
            transition: transform 0.1s ease, box-shadow 0.15s ease;
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0,0,0,0.15);
        }
        .summary-label {
            font-size: 12.5px;
            color: #666;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .summary-value {
            font-size: 22px;
            font-weight: 800;
            margin-top: 6px;
        }
        .summary-value.green { color: #1b5e20; }
        .summary-value.red { color: #d9534f; }
        .cell-link {
            text-decoration: none;
        }
        .cell-link:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="assets/style.css">
<script src="assets/table-search.js"></script>
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
        <li><button onclick="location.href='report.php'"><i class="fas fa-chart-line"></i>   SALES REPORTS</button></li>
        <li><button onclick="location.href='cashout.php'"><i class="fas fa-money-bill-wave"></i>   CASHOUT REPORT</button></li>
        <li><button onclick="location.href='users.php'"><i class="fas fa-users-cog"></i>   USERS</button></li>
        <li><button onclick="location.href='tracking.php'"><i class="fas fa-bell"></i>   ADMIN TRACKING</button></li>
        <li><button onclick="location.href='index.php'"><i class="fas fa-sign-out-alt"></i>   LOGOUT</button></li>
    </ul>
</div>

<div class="main-content">
    <h1>Daily Sales</h1>

    <form method="GET" class="filter-form" id="filterForm">
        <label for="month">Month:</label>
        <select name="month" id="month" <?php echo $use_range ? 'disabled' : ''; ?> onchange="document.getElementById('filterForm').submit();">
            <option value="0" <?php echo ($selected_month == 0) ? 'selected' : ''; ?>>All Months</option>
            <?php
            for ($m = 1; $m <= 12; $m++) {
                $month_label = date('F', mktime(0, 0, 0, $m, 10));
                $selected = ($m == $selected_month) ? 'selected' : '';
                echo "<option value='$m' $selected>$month_label</option>";
            }
            ?>
        </select>

        <label for="year">Year:</label>
        <select name="year" id="year" <?php echo $use_range ? 'disabled' : ''; ?> onchange="document.getElementById('filterForm').submit();">
            <option value="0" <?php echo ($selected_year == 0) ? 'selected' : ''; ?>>ALL YEARS</option>
            <?php
            $start_year = 2022;
            $current_year = date('Y');
            for ($y = $start_year; $y <= $current_year; $y++) {
                $selected = ($y == $selected_year) ? 'selected' : '';
                echo "<option value='$y' $selected>$y</option>";
            }
            ?>
        </select>
    </form>

    <form method="GET" class="filter-form range-filter-form" id="rangeFilterForm" style="display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-top:-6px;">
        <span style="font-weight:bold; text-transform:none;">— or a custom date range (spans any years):</span>
        <label for="range_from" style="text-transform:none;">From:</label>
        <input type="date" name="range_from" id="range_from" value="<?php echo htmlspecialchars($range_from); ?>">
        <label for="range_to" style="text-transform:none;">To:</label>
        <input type="date" name="range_to" id="range_to" value="<?php echo htmlspecialchars($range_to); ?>">
        <button type="submit" style="padding:6px 14px; border-radius:6px; border:none; background:#1b5e20; color:#fff; font-weight:bold; cursor:pointer; text-transform:none;">Apply Range</button>
        <?php if ($use_range): ?>
            <a href="daily.php" style="text-transform:none; color:#d9534f; font-weight:bold; text-decoration:none;">&times; Clear range</a>
        <?php endif; ?>
    </form>

    <div class="summary-cards">
        <a class="summary-card" href="receivables_breakdown.php?type=receivable&scope=alltime">
            <div class="summary-label">TOTAL RECEIVABLES (ALL TIME)</div>
            <div class="summary-value red"><?php echo number_format($alltime_negative_total, 2); ?></div>
        </a>
        <a class="summary-card" href="receivables_breakdown.php?type=payable&scope=alltime">
            <div class="summary-label">TOTAL PAYABLES (ALL TIME)</div>
            <div class="summary-value green"><?php echo number_format($alltime_positive_total, 2); ?></div>
        </a>
        <a class="summary-card" href="receivables_breakdown.php?type=receivable&scope=filtered<?= $use_range ? '&range_from=' . urlencode($range_from) . '&range_to=' . urlencode($range_to) : '&month=' . (int)$selected_month . '&year=' . (int)$selected_year ?>">
            <div class="summary-label">TOTAL RECEIVABLES (SELECTED PERIOD)</div>
            <div class="summary-value red"><?php echo number_format($selection_negative_total, 2); ?></div>
        </a>
        <a class="summary-card" href="receivables_breakdown.php?type=payable&scope=filtered<?= $use_range ? '&range_from=' . urlencode($range_from) . '&range_to=' . urlencode($range_to) : '&month=' . (int)$selected_month . '&year=' . (int)$selected_year ?>">
            <div class="summary-label">TOTAL PAYABLES (SELECTED PERIOD)</div>
            <div class="summary-value green"><?php echo number_format($selection_positive_total, 2); ?></div>
        </a>
    </div>
    <?php if ($use_range): ?>
        <p style="margin: -10px 0 20px; font-weight: bold; color:#1b5e20; text-transform:none;">
            Showing custom range: <?php echo htmlspecialchars(date('M j, Y', strtotime($range_from))); ?> &ndash; <?php echo htmlspecialchars(date('M j, Y', strtotime($range_to))); ?>
        </p>
    <?php endif; ?>

    <div class="table-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="dailySearch" placeholder="Search by date...">
    </div>
    <table id="dailyTable">
    <thead>
        <tr>
            <th>Date</th>
            <th>Dine In</th>
            <th>Take Out</th>
            <th>Total Sales</th>
            <th>Total Deposit</th>
            <th>Total Payable</th>
            <th>Total Receivable</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!empty($daily_summary)): ?>
        <?php foreach (array_reverse($daily_summary) as $date => $data): ?> <!-- Reverse loop here -->
            <tr>
                <td><?php echo date('Y-M-d', strtotime($date)); ?></td>
                <td><?php echo number_format($data['dinein'], 2); ?></td>
                <td><?php echo number_format($data['takeout'], 2); ?></td>
                <td><strong><?php echo number_format($data['total_sales'], 2); ?></strong></td>
                <td><?php echo number_format($data['total_deposit'], 2); ?></td>
                <td style="color: green;"><a class="cell-link" style="color: green;" href="receivables_breakdown.php?type=payable&scope=date&date=<?= urlencode($date) ?>"><strong><?php echo number_format($data['positive_total'], 2); ?></strong></a></td>
                <td style="color: red;"><a class="cell-link" style="color: red;" href="receivables_breakdown.php?type=receivable&scope=date&date=<?= urlencode($date) ?>"><strong><?php echo number_format($data['negative_total'], 2); ?></strong></a></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="7">No records found.</td></tr>
    <?php endif; ?>
</tbody>

</table>



</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    attachTableSearch('dailySearch', '#dailyTable');
});
</script>

</body>
</html>

<?php
?>