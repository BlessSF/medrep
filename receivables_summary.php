<?php
session_start();

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
// Monthly Summary: who has a RECEIVABLE or PAYABLE in a given month.
//
// "Month net" uses only the transactions dated inside the selected
// month, so it answers "who owes / is owed because of this month's
// activity". It is the same idea as the "Selected Period" cards on
// daily.php. "Balance as of month-end" is the medrep's running total
// from their very first transaction up to the last day of the month.
//
// Balance formula is identical to receivables_breakdown.php and
// customer_profile.php so numbers match everywhere:
//   deposit      = deposit + receiver_amount
//   payable      = dinein + takeout + giftcard   (non-card payments)
//   cashout      = cashout + interest            (non-card payments)
//   sent         = sender_amount
//   net          = deposit - sent - (payable + cashout)
//   net < 0  => RECEIVABLE (medrep owes the business)
//   net > 0  => PAYABLE    (business owes the medrep)
// Blocked medreps are excluded, same as the other pages.
// ------------------------------------------------------------
$month_names = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

$selected_month = isset($_GET['month']) ? intval($_GET['month']) : (int)date('n');
$selected_year  = isset($_GET['year'])  ? intval($_GET['year'])  : (int)date('Y');
if ($selected_month < 1 || $selected_month > 12) $selected_month = (int)date('n');
if ($selected_year < 2000 || $selected_year > 2100) $selected_year = (int)date('Y');

$view = $_GET['view'] ?? 'all';
if (!in_array($view, ['all', 'receivable', 'payable'], true)) $view = 'all';

$company_filter = isset($_GET['company']) ? trim($_GET['company']) : '';

$month_start = sprintf('%04d-%02d-01 00:00:00', $selected_year, $selected_month);
$month_end   = date('Y-m-t 23:59:59', strtotime($month_start));

// Company dropdown options
$companies = [];
$cres = $conn->query("SELECT DISTINCT company FROM employees WHERE company <> '' ORDER BY company");
if ($cres) {
    while ($c = $cres->fetch_assoc()) $companies[] = $c['company'];
}

$PM = "TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r', ''), '\\n', '')))";

$in_month = "(created_at >= ? AND created_at <= ?)";
$sql = "
    SELECT name, MAX(company) AS company,
        COUNT(CASE WHEN $in_month THEN 1 END) AS tx_count,

        SUM(CASE WHEN $in_month THEN COALESCE(deposit,0) + COALESCE(receiver_amount,0) ELSE 0 END) AS m_dep,
        SUM(CASE WHEN $in_month AND $PM <> 'card'
                 THEN COALESCE(dinein,0) + COALESCE(takeout,0) + COALESCE(giftcard,0) ELSE 0 END) AS m_pay,
        SUM(CASE WHEN $in_month AND $PM <> 'card'
                 THEN COALESCE(cashout,0) + COALESCE(interest,0) ELSE 0 END) AS m_cash,
        SUM(CASE WHEN $in_month THEN COALESCE(sender_amount,0) ELSE 0 END) AS m_sent,

        SUM(CASE WHEN created_at <= ? THEN COALESCE(deposit,0) + COALESCE(receiver_amount,0) ELSE 0 END) AS c_dep,
        SUM(CASE WHEN created_at <= ? AND $PM <> 'card'
                 THEN COALESCE(dinein,0) + COALESCE(takeout,0) + COALESCE(giftcard,0) ELSE 0 END) AS c_pay,
        SUM(CASE WHEN created_at <= ? AND $PM <> 'card'
                 THEN COALESCE(cashout,0) + COALESCE(interest,0) ELSE 0 END) AS c_cash,
        SUM(CASE WHEN created_at <= ? THEN COALESCE(sender_amount,0) ELSE 0 END) AS c_sent
    FROM employees
    WHERE (block IS NULL OR LOWER(TRIM(block)) <> 'block')
";
// placeholders in order of appearance: tx_count(2), m_dep(2), m_pay(2), m_cash(2), m_sent(2), c_dep(1), c_pay(1), c_cash(1), c_sent(1)
$types  = str_repeat('ss', 5) . 'ssss';
$params = [];
for ($i = 0; $i < 5; $i++) { $params[] = $month_start; $params[] = $month_end; }
for ($i = 0; $i < 4; $i++) { $params[] = $month_end; }

if ($company_filter !== '') {
    $sql .= " AND company = ?";
    $types .= 's';
    $params[] = $company_filter;
}
$sql .= " GROUP BY name HAVING tx_count > 0";

$stmt = $conn->prepare($sql);
if (!$stmt) die("Query failed: " . $conn->error);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$total_receivable = 0; $total_payable = 0;
$count_receivable = 0; $count_payable = 0;

while ($r = $result->fetch_assoc()) {
    $month_net = floatval($r['m_dep']) - floatval($r['m_sent']) - (floatval($r['m_pay']) + floatval($r['m_cash']));
    $month_net = round($month_net, 2);
    if ($month_net == 0) continue; // settled this month, nothing owed either way

    $bal = floatval($r['c_dep']) - floatval($r['c_sent']) - (floatval($r['c_pay']) + floatval($r['c_cash']));

    $kind = $month_net < 0 ? 'receivable' : 'payable';
    if ($kind === 'receivable') { $total_receivable += $month_net; $count_receivable++; }
    else                        { $total_payable    += $month_net; $count_payable++; }

    if ($view !== 'all' && $view !== $kind) continue;

    $rows[] = [
        'name'    => $r['name'],
        'company' => $r['company'] !== '' ? $r['company'] : 'UNASSIGNED',
        'tx'      => (int)$r['tx_count'],
        'deposit' => floatval($r['m_dep']) ,
        'payable' => floatval($r['m_pay']),
        'cashout' => floatval($r['m_cash']) + floatval($r['m_sent']),
        'net'     => $month_net,
        'balance' => round($bal, 2),
        'kind'    => $kind,
    ];
}
$stmt->close();
$conn->close();

// Biggest amounts first (by absolute value)
usort($rows, function ($a, $b) { return abs($b['net']) <=> abs($a['net']); });

$start_year   = 2022;
$current_year = max((int)date('Y'), $selected_year);

function rs_url($overrides = []) {
    global $selected_month, $selected_year, $view, $company_filter;
    $p = array_merge([
        'month' => $selected_month, 'year' => $selected_year,
        'view' => $view, 'company' => $company_filter,
    ], $overrides);
    if ($p['company'] === '') unset($p['company']);
    return 'receivables_summary.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Summary - Receivables/Payables</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: rgb(227, 251, 203); margin: 0; padding: 0; }
        .sidebar { width: 230px; height: 100vh; background-color: #1b5e20; color: #fff; position: fixed; padding-top: 20px; }
        .sidebar ul { list-style: none; padding: 0; }
        .sidebar ul li { padding: 15px; text-align: center; }
        .sidebar ul li button {
            width: 100%; padding: 15px; background-color: #abd67c; color: #000;
            border: none; cursor: pointer; font-weight: bold; font-size: 15px; border-radius: 5px;
        }
        .sidebar ul li button:hover { background-color: rgb(36, 125, 59); }
        .main-content { margin-left: 230px; padding: 24px 28px 60px; }

        .page-heading h1 { margin: 0 0 4px; font-size: 24px; text-transform: none; }
        .page-heading p { margin: 0 0 20px; color: #5c6b5e; font-size: 13.5px; max-width: 700px; }

        .toolbar {
            background: #fff; border-radius: 16px; box-shadow: 0 6px 18px rgba(20,25,50,0.08);
            padding: 16px 18px; margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; justify-content: space-between;
        }
        .toolbar form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        .field label { font-size: 10.5px; font-weight: 800; color: #5c6b5e; text-transform: uppercase; letter-spacing: .5px; }
        .field select {
            padding: 9px 12px; border-radius: 9px; border: 1px solid #dcebe0;
            font-family: inherit; font-size: 13.5px; background: #fff; color: #1f2b20; min-width: 140px;
        }
        .field select:focus { outline: none; border-color: #2e7d32; box-shadow: 0 0 0 3px rgba(46,125,50,0.15); }
        .month-nav { display: flex; gap: 6px; }
        .month-nav a {
            padding: 9px 12px; border-radius: 9px; background: #eaf6ec; color: #1b5e20;
            text-decoration: none; font-weight: 700; font-size: 13px;
        }
        .month-nav a:hover { background: #d3ebd7; }

        .segmented { display: inline-flex; background: #eaf6ec; border-radius: 999px; padding: 4px; gap: 2px; flex-wrap: wrap; }
        .segmented a {
            padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 700;
            color: #5c6b5e; text-decoration: none; white-space: nowrap;
        }
        .segmented a:hover { color: #1f2b20; background: rgba(255,255,255,0.6); }
        .segmented a.on-all { background: #1b5e20; color: #fff; }
        .segmented a.on-receivable { background: #e0364a; color: #fff; }
        .segmented a.on-payable { background: #2e7d32; color: #fff; }

        .cards { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
        .card {
            flex: 1 1 220px; background: #fff; border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1); padding: 16px 18px;
        }
        .card .label { font-size: 12.5px; color: #666; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; }
        .card .value { font-size: 22px; font-weight: 800; margin-top: 6px; }
        .card .sub { font-size: 12px; color: #6b7a6d; margin-top: 2px; }
        .red { color: #d9534f; }
        .green { color: #1b5e20; }

        .search-wrap { position: relative; max-width: 340px; margin-bottom: 14px; }
        .search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #2e7d32; }
        .search-wrap input {
            width: 100%; box-sizing: border-box; padding: 11px 14px 11px 38px;
            border: 1px solid #dcebe0; border-radius: 10px; font-size: 14px; background: #fff;
        }

        .table-wrap { overflow-x: auto; border-radius: 14px; box-shadow: 0 6px 18px rgba(20,25,50,0.08); background: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead th {
            background: #1b5e20; color: #fff; padding: 14px 12px; text-align: center;
            font-size: 12.5px; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap;
        }
        tbody td { padding: 12px; text-align: center; border-bottom: 1px solid #e5efe6; }
        tbody tr:nth-child(even) { background: #f3faf4; }
        tbody tr:hover { background: #e3f3e6; }
        td.name { text-align: left; font-weight: 700; }
        td.name a { color: #1f2b20; text-decoration: none; }
        td.name a:hover { text-decoration: underline; color: #1b5e20; }
        td.company { text-align: left; color: #5c6b5e; font-size: 13px; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: 11.5px; font-weight: 800; letter-spacing: .4px; color: #fff;
        }
        .badge.receivable { background: #e0364a; }
        .badge.payable { background: #2e7d32; }
        .amt { font-weight: 800; }
        .muted { color: #8a968c; }
        tfoot td { padding: 12px; font-weight: 800; background: #eaf6ec; text-align: center; }
        tfoot td:first-child { text-align: left; }
        .empty { padding: 50px 20px; text-align: center; color: #6b7a6d; }
        .empty i { font-size: 30px; color: #c7dccb; display: block; margin-bottom: 10px; }
        .note { margin-top: 14px; font-size: 12.5px; color: #5c6b5e; max-width: 820px; line-height: 1.5; }

        @media (max-width: 640px) {
            .main-content { margin-left: 0; padding: 18px; }
            .sidebar { display: none; }
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
    <div class="page-heading">
        <h1>Summary &ndash; <?= htmlspecialchars($month_names[$selected_month] . ' ' . $selected_year) ?></h1>
        <p>Medreps who have a receivable or a payable from this month's transactions.
           Receivable = the medrep owes the business. Payable = the business owes the medrep.</p>
    </div>

    <?php
        $prev_ts = strtotime("$selected_year-$selected_month-01 -1 month");
        $next_ts = strtotime("$selected_year-$selected_month-01 +1 month");
    ?>
    <div class="toolbar">
        <form method="GET" id="filterForm">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="field">
                <label for="month">Month</label>
                <select name="month" id="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $selected_month ? 'selected' : '' ?>><?= $month_names[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label for="year">Year</label>
                <select name="year" id="year" onchange="this.form.submit()">
                    <?php for ($y = $start_year; $y <= $current_year; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label for="company">Company</label>
                <select name="company" id="company" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $c === $company_filter ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="month-nav">
                <a href="<?= rs_url(['month' => (int)date('n', $prev_ts), 'year' => (int)date('Y', $prev_ts)]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                <a href="<?= rs_url(['month' => (int)date('n', $next_ts), 'year' => (int)date('Y', $next_ts)]) ?>">Next <i class="fas fa-chevron-right"></i></a>
            </div>
        </form>

        <div class="segmented">
            <a class="<?= $view === 'all' ? 'on-all' : '' ?>" href="<?= rs_url(['view' => 'all']) ?>">All</a>
            <a class="<?= $view === 'receivable' ? 'on-receivable' : '' ?>" href="<?= rs_url(['view' => 'receivable']) ?>">Receivables</a>
            <a class="<?= $view === 'payable' ? 'on-payable' : '' ?>" href="<?= rs_url(['view' => 'payable']) ?>">Payables</a>
        </div>
    </div>

    <div class="cards">
        <div class="card">
            <div class="label">Total Receivables (<?= htmlspecialchars($month_names[$selected_month]) ?>)</div>
            <div class="value red"><?= number_format($total_receivable, 2) ?></div>
            <div class="sub"><?= $count_receivable ?> medrep<?= $count_receivable === 1 ? '' : 's' ?></div>
        </div>
        <div class="card">
            <div class="label">Total Payables (<?= htmlspecialchars($month_names[$selected_month]) ?>)</div>
            <div class="value green"><?= number_format($total_payable, 2) ?></div>
            <div class="sub"><?= $count_payable ?> medrep<?= $count_payable === 1 ? '' : 's' ?></div>
        </div>
        <div class="card">
            <div class="label">Net for the Month</div>
            <?php $net_all = $total_payable + $total_receivable; ?>
            <div class="value <?= $net_all < 0 ? 'red' : 'green' ?>"><?= number_format($net_all, 2) ?></div>
            <div class="sub"><?= $count_receivable + $count_payable ?> medrep<?= ($count_receivable + $count_payable) === 1 ? '' : 's' ?> in total</div>
        </div>
    </div>

    <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="summarySearch" placeholder="Search medrep or company...">
    </div>

    <div class="table-wrap">
    <?php if (empty($rows)): ?>
        <div class="empty">
            <i class="fas fa-inbox"></i>
            No <?= $view === 'all' ? 'receivables or payables' : ($view === 'receivable' ? 'receivables' : 'payables') ?>
            found for <?= htmlspecialchars($month_names[$selected_month] . ' ' . $selected_year) ?>.
        </div>
    <?php else: ?>
        <table id="summaryTable">
            <thead>
                <tr>
                    <th style="text-align:left;">Medrep</th>
                    <th style="text-align:left;">Company</th>
                    <th>Status</th>
                    <th>Deposits</th>
                    <th>Sales Payable</th>
                    <th>Cashout / Sent</th>
                    <th>Month Amount</th>
                    <th>Balance as of <?= htmlspecialchars(date('M j', strtotime($month_end))) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $is_r = $r['kind'] === 'receivable'; ?>
                <tr>
                    <td class="name"><a href="customer_profile.php?name=<?= urlencode($r['name']) ?>"><?= htmlspecialchars($r['name']) ?></a></td>
                    <td class="company"><?= htmlspecialchars($r['company']) ?></td>
                    <td><span class="badge <?= $r['kind'] ?>"><?= $is_r ? 'RECEIVABLE' : 'PAYABLE' ?></span></td>
                    <td><?= number_format($r['deposit'], 2) ?></td>
                    <td><?= number_format($r['payable'], 2) ?></td>
                    <td><?= number_format($r['cashout'], 2) ?></td>
                    <td class="amt <?= $is_r ? 'red' : 'green' ?>"><?= number_format($r['net'], 2) ?></td>
                    <td class="<?= $r['balance'] < 0 ? 'red' : ($r['balance'] > 0 ? 'green' : 'muted') ?>"><strong><?= number_format($r['balance'], 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php
                    $shown_total = array_sum(array_column($rows, 'net'));
                ?>
                <tr>
                    <td colspan="6">TOTAL (<?= count($rows) ?> shown)</td>
                    <td class="<?= $shown_total < 0 ? 'red' : 'green' ?>"><?= number_format($shown_total, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
    </div>

    <p class="note">
        <strong>Month Amount</strong> = deposits &minus; (sales payable + cashout/interest + amounts sent), counting only
        transactions dated in <?= htmlspecialchars($month_names[$selected_month] . ' ' . $selected_year) ?>.
        <strong>Balance as of <?= htmlspecialchars(date('M j', strtotime($month_end))) ?></strong> is the medrep's running total from their first
        transaction up to the end of that month. Blocked medreps are not included. Click a name to open the profile.
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('summaryTable') && typeof attachTableSearch === 'function') {
        attachTableSearch('summarySearch', '#summaryTable');
    }
});
</script>
</body>
</html>