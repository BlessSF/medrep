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

$admin_username = $_SESSION['username'];

// ------------------------------------------------------------
// type:  receivable (negative net balance -- medrep owes the company)
//        payable    (positive net balance -- company owes the medrep)
// scope: alltime  -> every transaction ever, ignoring the month/year filter
//        filtered -> respects the month/year dropdown (same as daily.php)
//        date     -> respects month/year AND a specific cutoff date
//                    (everything up to and including that date)
// Blocked medreps are always excluded, same as daily.php.
// ------------------------------------------------------------
$type = ($_GET['type'] ?? 'receivable') === 'payable' ? 'payable' : 'receivable';
$scope = $_GET['scope'] ?? 'alltime';
if (!in_array($scope, ['alltime', 'filtered', 'date'], true)) {
    $scope = 'alltime';
}
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$cutoff_date = isset($_GET['date']) ? trim($_GET['date']) : '';

// Optional custom date-range filter for scope=filtered -- an alternative to
// month/year that can span across a year boundary (e.g. Dec 2025 - Feb
// 2026), matching the same control on daily.php.
$range_from = isset($_GET['range_from']) ? trim($_GET['range_from']) : '';
$range_to   = isset($_GET['range_to']) ? trim($_GET['range_to']) : '';
$use_range  = $range_from !== '' && $range_to !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_from)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_to);
if ($use_range && $range_from > $range_to) {
    [$range_from, $range_to] = [$range_to, $range_from];
}

// Helper to build links/forms that preserve the other active filters --
// used by the toolbar so switching one control never resets the rest.
function rb_url($overrides = []) {
    global $type, $scope, $selected_month, $selected_year, $cutoff_date, $range_from, $range_to, $use_range;
    $params = array_merge([
        'type'  => $type,
        'scope' => $scope,
        'month' => $selected_month,
        'year'  => $selected_year,
        'date'  => $cutoff_date,
        'range_from' => $use_range ? $range_from : '',
        'range_to'   => $use_range ? $range_to : '',
    ], $overrides);

    if ($params['scope'] === 'alltime') {
        unset($params['month'], $params['year'], $params['date'], $params['range_from'], $params['range_to']);
    } elseif ($params['scope'] === 'filtered') {
        unset($params['date']);
    } elseif ($params['scope'] === 'date') {
        unset($params['range_from'], $params['range_to']);
    }
    if (empty($params['date'])) {
        unset($params['date']);
    }
    if (empty($params['range_from']) || empty($params['range_to'])) {
        unset($params['range_from'], $params['range_to']);
    }
    if (empty($params['month'])) { $params['month'] = 0; }
    if (empty($params['year'])) { $params['year'] = 0; }

    return 'receivables_breakdown.php?' . http_build_query($params);
}

$where_clauses = ["(block IS NULL OR LOWER(TRIM(block)) <> 'block')"];

// "filtered" (Selected Period) respects the month/year dropdown (or the
// custom date range, if set), same as daily.php. "date" (As of Date) means
// the medrep's real running total up to and including that date -- it must
// NOT also be restricted to the selected month/year/range, or it stops
// being a cumulative balance and instead only reflects that narrower
// activity (which is why a medrep's "As of Date" balance could disagree
// with their profile page's all-time total).
if ($scope === 'filtered') {
    if ($use_range) {
        $safe_from = $conn->real_escape_string($range_from);
        $safe_to = $conn->real_escape_string($range_to);
        $where_clauses[] = "DATE(created_at) BETWEEN '$safe_from' AND '$safe_to'";
    } else {
        if ($selected_year != 0) {
            $where_clauses[] = "YEAR(created_at) = " . intval($selected_year);
        }
        if ($selected_month != 0) {
            $where_clauses[] = "MONTH(created_at) = " . intval($selected_month);
        }
    }
}

if ($scope === 'date' && $cutoff_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff_date)) {
    $safe_date = $conn->real_escape_string($cutoff_date);
    $where_clauses[] = "created_at <= '$safe_date 23:59:59'";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// ------------------------------------------------------------
// Balance formula: kept identical to customer_profile.php's canonical
// calculation, so a medrep's balance here always matches their profile
// page. Previously this used a different (buggy) formula that dropped
// incoming transfers, only treated payment_method 'na'/'paid' as
// payable (missing cash/giftcard-paid Dine In/Takeout/Gift Card), and
// counted card-paid cashout/interest against the balance -- all three
// made the numbers here disagree with the profile page.
//   total_deposit = deposit + receiver_amount (money received, incl. transfers in)
//   total_payable = dinein + takeout + giftcard, for any NON-card payment
//                   method (card-paid rows are tracked separately as
//                   "Record Total (Card)" and never touch this balance)
//   total_cashout = cashout + interest, for any NON-card payment method
//   total_send    = sender_amount (money sent out via transfer)
//   balance       = total_deposit - total_send - (total_payable + total_cashout)
// NORMALIZED_PM strips stray \r/\n before trimming/lowercasing so a
// payment_method like "card\r\n" is still detected as card, matching
// the PHP-side check used elsewhere.
// ------------------------------------------------------------
$sql = "
    SELECT name, MAX(company) AS company,
        SUM(COALESCE(deposit,0)) + SUM(COALESCE(receiver_amount,0)) AS dep,
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\r', ''), '\n', ''))) <> 'card'
                 THEN COALESCE(dinein,0) + COALESCE(takeout,0) + COALESCE(giftcard,0) ELSE 0 END) AS payable,
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\r', ''), '\n', ''))) <> 'card'
                 THEN COALESCE(cashout,0) + COALESCE(interest,0) ELSE 0 END) AS cashout_and_interest,
        SUM(COALESCE(sender_amount,0)) AS sender_sum
    FROM employees
    $where_sql
    GROUP BY name
";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

$by_company = [];
$grand_total = 0;

while ($row = $result->fetch_assoc()) {
    $net = floatval($row['dep'])
         - floatval($row['sender_sum'])
         - (floatval($row['payable']) + floatval($row['cashout_and_interest']));

    if ($type === 'receivable' && $net >= 0) continue;
    if ($type === 'payable' && $net <= 0) continue;

    $company = $row['company'] !== null && $row['company'] !== '' ? $row['company'] : 'UNASSIGNED';
    if (!isset($by_company[$company])) {
        $by_company[$company] = ['rows' => [], 'subtotal' => 0];
    }
    $by_company[$company]['rows'][] = ['name' => $row['name'], 'balance' => $net];
    $by_company[$company]['subtotal'] += $net;
    $grand_total += $net;
}
$conn->close();

// Sort companies alphabetically, and medreps within a company by
// balance -- most negative first for receivables, most positive
// first for payables, so the biggest amounts surface at the top.
ksort($by_company);
foreach ($by_company as &$group) {
    usort($group['rows'], function ($a, $b) use ($type) {
        return $type === 'receivable' ? $a['balance'] <=> $b['balance'] : $b['balance'] <=> $a['balance'];
    });
}
unset($group);

$total_medreps = array_sum(array_map(fn($g) => count($g['rows']), $by_company));

$type_label = $type === 'receivable' ? 'Receivables' : 'Payables';
$scope_label = 'All Time';
if ($scope === 'filtered') {
    $scope_label = 'Selected Period';
    if ($use_range) {
        $scope_label = 'Selected Period (' . date('M j, Y', strtotime($range_from)) . ' - ' . date('M j, Y', strtotime($range_to)) . ')';
    }
} elseif ($scope === 'date') {
    $scope_label = $cutoff_date !== '' ? 'As of ' . date('M j, Y', strtotime($cutoff_date)) : 'As of Date';
}

$month_names = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$start_year = 2022;
$current_year = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title><?= htmlspecialchars($type_label) ?> Breakdown</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: rgb(227, 251, 203);
            margin: 0;
            padding: 0;
        }
        .sidebar {
            width: 230px;
            height: 100vh;
            background-color: #1b5e20;
            color: #fff;
            position: fixed;
            padding-top: 20px;
        }
        .sidebar ul { list-style: none; padding: 0; }
        .sidebar ul li { padding: 15px; text-align: center; }
        .sidebar ul li button {
            width: 100%; padding: 15px; background-color: #abd67c; color: #000;
            border: none; cursor: pointer; font-weight: bold; font-size: 15px; border-radius: 5px;
        }
        .sidebar ul li button:hover { background-color: rgb(36, 125, 59); }
        .main-content { margin-left: 230px; padding: 24px 28px 60px; }

        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            margin-bottom: 18px; color: #1b5e20; font-weight: 700; text-decoration: none;
            font-size: 13.5px;
        }
        .back-link:hover { text-decoration: underline; }

        .page-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .page-heading h1 { margin: 0 0 4px; font-size: 22px; display: flex; align-items: center; gap: 10px; }
        .page-heading h1 .type-icon {
            width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; color: #fff; flex: none;
        }
        .type-icon.receivable { background: #e0364a; }
        .type-icon.payable { background: #2e7d32; }
        .page-heading p { margin: 0; color: #5c6b5e; font-size: 13.5px; max-width: 640px; }

        /* ---------- filter toolbar ---------- */
        .rb-toolbar {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(20,25,50,0.08);
            padding: 16px 18px;
            margin-bottom: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .rb-toolbar-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }
        .segmented {
            display: inline-flex;
            background: #eaf6ec;
            border-radius: 999px;
            padding: 4px;
            gap: 2px;
            flex-wrap: wrap;
        }
        .segmented-link {
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            color: #5c6b5e;
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s ease, color .15s ease;
        }
        .segmented-link:hover { color: #1f2b20; background: rgba(255,255,255,0.6); }
        .segmented-link.active-receivable { background: #e0364a; color: #fff; box-shadow: 0 1px 2px rgba(20,25,50,0.15); }
        .segmented-link.active-payable { background: #2e7d32; color: #fff; box-shadow: 0 1px 2px rgba(20,25,50,0.15); }
        .segmented-link.active-scope { background: #1b5e20; color: #fff; box-shadow: 0 1px 2px rgba(20,25,50,0.15); }

        .rb-filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid #e5efe6;
        }
        .rb-filter-form .field { display: flex; flex-direction: column; gap: 4px; }
        .rb-filter-form label {
            font-size: 10.5px; font-weight: 800; color: #5c6b5e;
            text-transform: uppercase; letter-spacing: .5px;
        }
        .rb-filter-form select,
        .rb-filter-form input[type="date"] {
            padding: 9px 12px;
            border-radius: 9px;
            border: 1px solid #dcebe0;
            font-family: inherit;
            font-size: 13.5px;
            background: #fff;
            color: #1f2b20;
            min-width: 140px;
        }
        .rb-filter-form select:focus,
        .rb-filter-form input[type="date"]:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.15);
        }
        .rb-apply-btn {
            align-self: flex-end;
            display: inline-flex; align-items: center; gap: 6px;
            background: #1b5e20; color: #fff; border: none;
            padding: 10px 18px; border-radius: 9px;
            font-weight: 700; font-size: 13.5px; cursor: pointer;
            box-shadow: 0 1px 2px rgba(20,25,50,0.15);
            transition: background .15s ease, transform .1s ease;
        }
        .rb-apply-btn:hover { background: #12461a; transform: translateY(-1px); }

        /* ---------- grand total banner ---------- */
        .grand-total-banner {
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(20,25,50,0.08);
            padding: 20px 24px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-left: 6px solid;
        }
        .grand-total-banner.receivable { background: #fdeced; border-left-color: #e0364a; }
        .grand-total-banner.payable { background: #e7f8ee; border-left-color: #2e7d32; }
        .grand-total-banner .label-block { display: flex; flex-direction: column; gap: 2px; }
        .grand-total-banner .label { font-weight: 700; color: #444; font-size: 13px; text-transform: uppercase; letter-spacing: .4px; }
        .grand-total-banner .sub-label { font-size: 12px; color: #777; }
        .grand-total-banner .value { font-size: 30px; font-weight: 800; }
        .value.receivable { color: #d9534f; }
        .value.payable { color: #1b5e20; }

        /* ---------- company groups ---------- */
        .company-group {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(20,25,50,0.08);
            margin-bottom: 18px;
            overflow: hidden;
            border: 1px solid #eef3ee;
        }
        .company-header {
            background: #f4faf5;
            border-left: 5px solid #2e7d32;
            color: #1f2b20;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .company-header.receivable-scheme { border-left-color: #e0364a; }
        .company-header .company-name {
            font-weight: 800;
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: .4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .company-header .count-badge {
            background: #fff;
            color: #5c6b5e;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            border: 1px solid #dcebe0;
            text-transform: none;
            letter-spacing: 0;
        }
        .company-header .subtotal { font-size: 17px; font-weight: 800; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 18px; text-align: left; }
        th {
            background: #1b5e20; color: #fff;
            text-transform: uppercase; font-size: 11.5px; letter-spacing: .4px; font-weight: 700;
        }
        td { border-bottom: 1px solid #eef3ee; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:nth-child(even) { background: #fafdfb; }
        tbody tr:hover { background: #f1f8f2; }

        .balance-pill {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 13.5px;
        }
        .balance-pill.receivable { background: #fdeced; color: #d9534f; }
        .balance-pill.payable { background: #e7f8ee; color: #1b5e20; }

        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            background: #e6f4e8;
            color: #1b5e20;
            font-weight: 700;
            font-size: 12.5px;
            text-decoration: none;
            border: 1px solid #cfe9d3;
            transition: background .15s ease, color .15s ease, transform .1s ease, box-shadow .15s ease;
        }
        .view-btn:hover {
            background: #1b5e20;
            color: #fff;
            box-shadow: 0 3px 8px rgba(27,94,32,0.3);
            transform: translateY(-1px);
        }
        .view-btn i { font-size: 11px; }

        .empty-state {
            text-align: center;
            padding: 46px 20px;
            color: #8a988c;
        }
        .empty-state i { font-size: 30px; margin-bottom: 10px; display: block; color: #c7dccb; }
        .empty-state p { margin: 0; font-size: 14px; }

        @media (max-width: 640px) {
            .main-content { margin-left: 0; padding: 18px; }
            .sidebar { display: none; }
            .grand-total-banner .value { font-size: 24px; }
        }
    </style>
    <link rel="stylesheet" href="assets/style.css">
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
    <a class="back-link" href="daily.php?<?= $use_range ? 'range_from=' . urlencode($range_from) . '&range_to=' . urlencode($range_to) : 'month=' . (int)$selected_month . '&year=' . (int)$selected_year ?>"><i class="fas fa-arrow-left"></i> Back to Receivables/Payables</a>

    <div class="page-heading">
        <div>
            <h1>
                <span class="type-icon <?= $type ?>"><i class="fas <?= $type === 'receivable' ? 'fa-hand-holding-dollar' : 'fa-money-bill-transfer' ?>"></i></span>
                <?= htmlspecialchars($type_label) ?> &mdash; <?= htmlspecialchars($scope_label) ?>
            </h1>
            <p>
                <?= $type === 'receivable' ? 'Medreps who currently owe the company money, grouped by company.' : 'Amounts the company currently owes each medrep, grouped by company.' ?>
                Blocked medreps are excluded.
            </p>
        </div>
    </div>

    <div class="rb-toolbar">
        <div class="rb-toolbar-row">
            <div class="segmented" role="group" aria-label="Balance type">
                <a class="segmented-link <?= $type === 'receivable' ? 'active-receivable' : '' ?>" href="<?= htmlspecialchars(rb_url(['type' => 'receivable'])) ?>">
                    <i class="fas fa-hand-holding-dollar"></i> Receivables
                </a>
                <a class="segmented-link <?= $type === 'payable' ? 'active-payable' : '' ?>" href="<?= htmlspecialchars(rb_url(['type' => 'payable'])) ?>">
                    <i class="fas fa-money-bill-transfer"></i> Payables
                </a>
            </div>
            <div class="segmented" role="group" aria-label="Scope">
                <a class="segmented-link <?= $scope === 'alltime' ? 'active-scope' : '' ?>" href="<?= htmlspecialchars(rb_url(['scope' => 'alltime'])) ?>">All Time</a>
                <a class="segmented-link <?= $scope === 'filtered' ? 'active-scope' : '' ?>" href="<?= htmlspecialchars(rb_url(['scope' => 'filtered'])) ?>">Selected Period</a>
                <a class="segmented-link <?= $scope === 'date' ? 'active-scope' : '' ?>" href="<?= htmlspecialchars(rb_url(['scope' => 'date'])) ?>">As of Date</a>
            </div>
        </div>

        <?php if ($scope === 'filtered' || $scope === 'date'): ?>
        <form method="GET" class="rb-filter-form" id="rbFilterForm">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="scope" value="<?= htmlspecialchars($scope) ?>">

            <?php if ($scope === 'filtered'): ?>
            <div class="field">
                <label for="rb-month">Month</label>
                <select name="month" id="rb-month" <?= $use_range ? 'disabled' : '' ?>>
                    <option value="0" <?= $selected_month == 0 ? 'selected' : '' ?>>All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $selected_month ? 'selected' : '' ?>><?= $month_names[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label for="rb-year">Year</label>
                <select name="year" id="rb-year" <?= $use_range ? 'disabled' : '' ?>>
                    <option value="0" <?= $selected_year == 0 ? 'selected' : '' ?>>All Years</option>
                    <?php for ($y = $start_year; $y <= $current_year; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label for="rb-range-from">Or From</label>
                <input type="date" name="range_from" id="rb-range-from" value="<?= htmlspecialchars($range_from) ?>">
            </div>
            <div class="field">
                <label for="rb-range-to">To (any year)</label>
                <input type="date" name="range_to" id="rb-range-to" value="<?= htmlspecialchars($range_to) ?>">
            </div>
            <?php if ($use_range): ?>
                <div class="field">
                    <label>&nbsp;</label>
                    <a href="<?= htmlspecialchars(rb_url(['range_from' => '', 'range_to' => ''])) ?>" style="color:#d9534f; font-weight:700; font-size:13.5px; text-decoration:none;">&times; Clear range</a>
                </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($scope === 'date'): ?>
            <div class="field">
                <label for="rb-date">Cutoff Date</label>
                <input type="date" name="date" id="rb-date" value="<?= htmlspecialchars($cutoff_date) ?>">
            </div>
            <?php endif; ?>

            <button type="submit" class="rb-apply-btn"><i class="fas fa-check"></i> Apply</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="grand-total-banner <?= $type ?>">
        <div class="label-block">
            <span class="label">Grand Total <?= htmlspecialchars($type_label) ?></span>
            <span class="sub-label"><?= $total_medreps ?> medrep<?= $total_medreps == 1 ? '' : 's' ?> across <?= count($by_company) ?> compan<?= count($by_company) == 1 ? 'y' : 'ies' ?></span>
        </div>
        <span class="value <?= $type ?>"><?php echo number_format($grand_total, 2); ?></span>
    </div>

    <?php if (!empty($by_company)): ?>
    <div class="table-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="rbSearch" placeholder="Search by medrep or company...">
    </div>
    <?php endif; ?>

    <?php if (empty($by_company)): ?>
        <div class="company-group">
            <div class="empty-state">
                <i class="fas fa-circle-check"></i>
                <p>No <?= strtolower(htmlspecialchars($type_label)) ?> found for this period.</p>
            </div>
        </div>
    <?php else: ?>
        <div id="rbNoResults" class="company-group" style="display:none;">
            <div class="empty-state">
                <i class="fas fa-circle-check"></i>
                <p>No medreps or companies match your search.</p>
            </div>
        </div>
        <?php foreach ($by_company as $company => $group): ?>
            <div class="company-group" data-company="<?= htmlspecialchars(strtolower($company)) ?>">
                <div class="company-header <?= $type === 'receivable' ? 'receivable-scheme' : '' ?>">
                    <span class="company-name">
                        <?= htmlspecialchars($company) ?>
                        <span class="count-badge"><?= count($group['rows']) ?> medrep<?= count($group['rows']) == 1 ? '' : 's' ?></span>
                    </span>
                    <span class="subtotal <?= $type ?>"><?php echo number_format($group['subtotal'], 2); ?></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Medrep</th>
                                <th>Balance</th>
                                <th>Profile</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['rows'] as $r): ?>
                                <tr data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>">
                                    <td><?= htmlspecialchars($r['name']) ?></td>
                                    <td>
                                        <span class="balance-pill <?= $type ?>"><?php echo number_format($r['balance'], 2); ?></span>
                                    </td>
                                    <td>
                                        <a class="view-btn" href="customer_profile.php?name=<?= urlencode($r['name']) ?>">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    // Auto-submit the period form when month/year change, same convention as daily.php.
    document.getElementById('rb-month')?.addEventListener('change', () => document.getElementById('rbFilterForm').submit());
    document.getElementById('rb-year')?.addEventListener('change', () => document.getElementById('rbFilterForm').submit());

    // Search across medrep name and company name. A company whose name
    // matches shows in full; otherwise its rows are filtered individually
    // by medrep name, and the whole company group hides if nothing in it
    // matches. Subtotal/grand-total figures are left as-is (they always
    // reflect the full period, not just what's currently visible).
    (function () {
        const input = document.getElementById('rbSearch');
        const groups = Array.from(document.querySelectorAll('.company-group[data-company]'));
        const noResults = document.getElementById('rbNoResults');
        if (!input || groups.length === 0) return;

        function applyFilter() {
            const query = input.value.trim().toLowerCase();
            let anyVisible = false;

            groups.forEach(function (group) {
                const companyName = group.getAttribute('data-company') || '';
                const companyMatches = query === '' || companyName.includes(query);
                const rows = Array.from(group.querySelectorAll('tbody tr'));
                let groupHasVisibleRow = false;

                rows.forEach(function (row) {
                    const nameMatches = companyMatches || (row.getAttribute('data-name') || '').includes(query);
                    row.style.display = nameMatches ? '' : 'none';
                    if (nameMatches) groupHasVisibleRow = true;
                });

                const showGroup = groupHasVisibleRow;
                group.style.display = showGroup ? '' : 'none';
                if (showGroup) anyVisible = true;
            });

            if (noResults) {
                noResults.style.display = anyVisible ? 'none' : '';
            }
        }

        input.addEventListener('input', applyFilter);
    })();
</script>
</body>
</html>