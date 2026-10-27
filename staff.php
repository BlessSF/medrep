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

$cashier_username = $_SESSION['username'];
$error_message   = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';

unset($_SESSION['error_message']);
unset($_SESSION['success_message']);

session_write_close();

$is_partial = isset($_GET['partial']);   // ?partial=1 -> only the list (used by the live filters)

// Existing names + companies (autocomplete on the form)
$sql = "SELECT DISTINCT name, company FROM employees ORDER BY name ASC";
$result = $conn->query($sql);
$employee_list = [];
if ($result && $result->num_rows > 0) {
    $seen = [];
    while ($row = $result->fetch_assoc()) {
        $key = strtolower($row['name'] . '|' . $row['company']);
        if (!isset($seen[$key])) {
            $employee_list[] = $row;
            $seen[$key] = true;
        }
    }
}

// Companies (managed on companies.php by an admin)
$conn->query("
    CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$conn->query("
    INSERT IGNORE INTO companies (name)
    SELECT DISTINCT TRIM(company) FROM employees
    WHERE company IS NOT NULL AND TRIM(company) <> ''
");
$company_list_result = $conn->query("SELECT name FROM companies ORDER BY name ASC");
$company_list = $company_list_result ? $company_list_result->fetch_all(MYSQLI_ASSOC) : [];

// ---------------- MEDREP LIST: filters, search & pagination ----------------
$filter_company = isset($_GET['f_company']) ? trim($_GET['f_company']) : '';
$filter_month   = isset($_GET['f_month']) ? trim($_GET['f_month']) : '';   // 'YYYY-MM' or '' = all months
$filter_q       = isset($_GET['f_q']) ? trim($_GET['f_q']) : '';

$per_page_options = [25, 50, 100, 500];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($per_page, $per_page_options, true)) {
    $per_page = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$months_result = $conn->query("
    SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym
    FROM employees
    WHERE created_at IS NOT NULL
    ORDER BY ym DESC
");
$available_months = $months_result ? $months_result->fetch_all(MYSQLI_ASSOC) : [];

$where = [];
$params = [];
$types = "";

if ($filter_company !== '') {
    $where[] = "company = ?";
    $params[] = $filter_company;
    $types .= "s";
}
if ($filter_month !== '') {
    $where[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}
if ($filter_q !== '') {
    $where[] = "(name LIKE ? OR company LIKE ?)";
    $like = "%{$filter_q}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$count_sql = "SELECT COUNT(*) AS total FROM employees $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types !== "") {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$list_sql = "SELECT name, company, remarks, sender_name, created_at
             FROM employees
             $where_sql
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?";
$list_stmt = $conn->prepare($list_sql);
$list_types = $types . "ii";
$list_params = array_merge($params, [$per_page, $offset]);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$result = $list_stmt->get_result();
if (!$result) {
    die("Error fetching employee records: " . $conn->error);
}

// Small numbers for the header tiles
$today_row = $conn->query("SELECT COUNT(*) AS c FROM employees WHERE DATE(created_at) = CURDATE()");
$today_count = $today_row ? (int)($today_row->fetch_assoc()['c'] ?? 0) : 0;
$all_row = $conn->query("SELECT COUNT(*) AS c FROM employees");
$all_count = $all_row ? (int)($all_row->fetch_assoc()['c'] ?? 0) : 0;

// ---------------- helpers ----------------
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Pagination links keep every active filter and only override the given key(s).
function medrep_list_url($overrides) {
    $params = $_GET;
    unset($params['partial']);
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    return 'staff.php?' . http_build_query($params) . '#medrep-list';
}

// Stable colour per person for the little avatar square
function medrep_avatar_color($name) {
    $palette = ['#1a7f3c', '#2f6f9f', '#8a5a9e', '#b5651d', '#0f8b8d', '#a23b5c', '#5a7d2a', '#3d5a99'];
    return $palette[abs(crc32(strtolower($name))) % count($palette)];
}

// The whole list block (meta line + table + pager). Used for the first paint
// AND for the live-filter refresh, so both always look identical.
function render_list_region($result, $total_rows, $offset, $per_page, $page, $total_pages) {
    ob_start(); ?>
    <div class="table-meta" id="tableMeta">
        <?php if ($total_rows > 0): ?>
            Showing <strong><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?></strong> of <strong><?= number_format($total_rows) ?></strong> record<?= $total_rows === 1 ? '' : 's' ?>
        <?php else: ?>
            No records found
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="list" id="medrepTable">
            <thead>
                <tr><th>Name</th><th>Company</th><th>Date updated</th><th style="width:40px"></th></tr>
            </thead>
            <tbody>
            <?php if ($total_rows === 0): ?>
                <tr><td colspan="4"><div class="empty-state"><i class="fas fa-inbox"></i>No matching transactions.<br>Try adjusting your filters.</div></td></tr>
            <?php else: while ($row = $result->fetch_assoc()):
                $nm = trim((string)$row['name']);
                $ts = strtotime($row['created_at']);
            ?>
                <tr class="row <?= ($row['remarks'] === 'cashout') ? 'cashout-row' : '' ?>" data-name="<?= h($nm) ?>" tabindex="0" role="link">
                    <td>
                        <div class="cell-person">
                            <span class="av sm" style="background:<?= medrep_avatar_color($nm) ?>"><?= h(mb_strtoupper(mb_substr($nm, 0, 1)) ?: '?') ?></span>
                            <span class="person-name"><?= h(mb_strtoupper($nm)) ?></span>
                            <?php if ($row['remarks'] === 'cashout'): ?><span class="badge badge-cashout">Cashout</span><?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-company"><?= h(mb_strtoupper((string)$row['company'])) ?></td>
                    <td class="cell-date"><b><?= $ts ? h(date('d M Y', $ts)) : '—' ?></b><span><?= $ts ? h(date('h:i A', $ts)) : '' ?></span></td>
                    <td><i class="fas fa-chevron-right go"></i></td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pager">
        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" data-page="<?= $page - 1 ?>"
           href="<?= $page > 1 ? h(medrep_list_url(['page' => $page - 1])) : '#' ?>"><i class="fas fa-chevron-left"></i> Prev</a>
        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
        <a class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" data-page="<?= $page + 1 ?>"
           href="<?= $page < $total_pages ? h(medrep_list_url(['page' => $page + 1])) : '#' ?>">Next <i class="fas fa-chevron-right"></i></a>
    </div>
    <?php endif;
    return ob_get_clean();
}

$list_html = render_list_region($result, $total_rows, $offset, $per_page, $page, $total_pages);

// Live-filter refresh: send back JSON only, no page.
if ($is_partial) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'html'      => $list_html,
        'total'     => $total_rows,
        'today'     => $today_count,
        'all'       => $all_count,
        'employees' => $employee_list,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$flash_type = $error_message !== '' ? 'error' : ($success_message !== '' ? 'success' : '');
$flash_msg  = $error_message !== '' ? $error_message : $success_message;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medrep — Daily Transaction</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/cashier.css">
</head>
<body class="cx">
<?php require_once __DIR__ . '/includes/cashier_ui.php'; cashier_appbar('transaction', $cashier_username); ?>

<div class="toasts" id="toasts" aria-live="polite"></div>
<?php if ($flash_type !== ''): ?>
    <!-- shown as a toast by the script below; stays visible as a banner if JavaScript is off -->
    <div id="flash" class="flash-banner <?= $flash_type ?>" data-type="<?= $flash_type ?>" data-msg="<?= h($flash_msg) ?>"><?= h($flash_msg) ?></div>
<?php endif; ?>

<main class="cx-main">
    <div class="page-head">
        <div>
            <h1>New transaction</h1>
            <p>Hello, <?= h($cashier_username) ?> — record a transaction, then find any medrep below.</p>
        </div>
        <div class="head-stats">
            <div class="stat"><b id="todayCount"><?= number_format($today_count) ?></b><span>Transactions today</span></div>
            <div class="stat"><b id="allCount"><?= number_format($all_count) ?></b><span>Total records</span></div>
        </div>
    </div>

    <div class="grid">
        <!-- ============================ FORM ============================ -->
        <section class="card form-card" aria-labelledby="formTitle">
            <div class="card-head">
                <h2 class="card-title" id="formTitle"><i class="fas fa-pen-to-square"></i> Transaction</h2>
            </div>
            <form class="card-body" id="txForm" action="submit.php" method="POST" autocomplete="off" novalidate>

                <div class="field">
                    <label for="name">Name</label>
                    <div class="input-icon">
                        <i class="fas fa-user lead"></i>
                        <input class="input upper" type="text" id="name" name="name" placeholder="Start typing a name…" autocomplete="off" required>
                    </div>
                    <div class="suggest" id="name-suggestions" role="listbox"></div>
                </div>

                <div class="field">
                    <label for="company">Company</label>
                    <div class="input-icon has-trail">
                        <i class="fas fa-building lead"></i>
                        <input class="input upper" type="text" id="company" name="company" placeholder="Start typing a company…" autocomplete="off" required>
                        <button type="button" class="trail" id="company-toggle" aria-label="Browse all companies"><i class="fas fa-chevron-down"></i></button>
                    </div>
                    <div class="suggest" id="company-suggestions" role="listbox"></div>
                </div>

                <div class="field">
                    <span class="label" id="typeLabel">Transaction type</span>
                    <div class="type-grid" role="radiogroup" aria-labelledby="typeLabel" id="typeGroup">
                        <button type="button" class="chip" role="radio" aria-checked="true"  data-value="deposit"><i class="fas fa-piggy-bank"></i>Deposit</button>
                        <button type="button" class="chip" role="radio" aria-checked="false" data-value="dinein"><i class="fas fa-utensils"></i>Dine in</button>
                        <button type="button" class="chip" role="radio" aria-checked="false" data-value="takeout"><i class="fas fa-bag-shopping"></i>Take out</button>
                        <button type="button" class="chip" role="radio" aria-checked="false" data-value="buygiftcard"><i class="fas fa-gift"></i>Gift card</button>
                        <button type="button" class="chip" role="radio" aria-checked="false" data-value="transfer" style="grid-column: span 2"><i class="fas fa-right-left"></i>Transfer to another medrep</button>
                    </div>
                    <input type="hidden" name="transaction_type" id="transaction_type" value="deposit">
                </div>

                <div class="panel-transfer" id="transferFields" hidden>
                    <div class="label-top"><i class="fas fa-right-left"></i> Send the balance to</div>
                    <div class="field">
                        <label for="transfer_name">Name</label>
                        <input class="input upper" type="text" id="transfer_name" name="transfer_name" placeholder="Start typing a name…" autocomplete="off">
                        <div class="suggest" id="transfer-name-suggestions" role="listbox"></div>
                    </div>
                    <div class="field">
                        <label for="transfer_company">Company</label>
                        <input class="input upper" type="text" id="transfer_company" name="transfer_company" readonly placeholder="Filled in automatically">
                    </div>
                </div>

                <div class="field">
                    <label for="amount" id="amountLabel">Amount</label>
                    <div class="amount-wrap">
                        <span class="peso">₱</span>
                        <input class="input" type="number" id="amount" name="amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00" required>
                    </div>
                </div>

                <div id="paymentMethodGroup">
                    <div class="field">
                        <span class="label" id="payLabel">Payment method</span>
                        <div class="chip-row" role="radiogroup" aria-labelledby="payLabel" id="payGroup">
                            <button type="button" class="chip" role="radio" aria-checked="true"  data-value="card"><i class="fas fa-credit-card"></i>Swipe</button>
                            <button type="button" class="chip" role="radio" aria-checked="false" data-value="cash"><i class="fas fa-money-bill-wave"></i>Cash</button>
                            <button type="button" class="chip" role="radio" aria-checked="false" data-value="paid"><i class="fas fa-circle-check"></i>Paid</button>
                            <button type="button" class="chip" role="radio" aria-checked="false" data-value="giftcard"><i class="fas fa-gift"></i>Gift card</button>
                            <button type="button" class="chip" role="radio" aria-checked="false" data-value="na"><i class="fas fa-ban"></i>N/A</button>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method" value="card">
                    </div>

                    <div class="field sub-field" id="cardGroup">
                        <span class="label" id="cardLabel">Card</span>
                        <div class="chip-row" role="radiogroup" aria-labelledby="cardLabel" id="cardChoiceGroup">
                            <button type="button" class="chip sm" role="radio" aria-checked="true"  data-value="maya">Maya</button>
                            <button type="button" class="chip sm" role="radio" aria-checked="false" data-value="metrobank">Metrobank</button>
                            <button type="button" class="chip sm" role="radio" aria-checked="false" data-value="others">Others</button>
                        </div>
                        <input class="input" type="text" id="card_other" name="card_other" placeholder="Specify card / bank name" style="margin-top:10px" hidden>
                        <input type="hidden" name="card" id="card" value="maya">
                    </div>
                </div>

                <div class="field">
                    <label for="remarks">Remarks <span style="font-weight:500;color:var(--muted)">(optional)</span></label>
                    <input class="input" type="text" id="remarks" name="remarks" placeholder="Add a note…">
                </div>

                <div class="recap empty" id="recap"><i class="fas fa-receipt"></i><span>Fill in the details to see a summary.</span></div>

                <div class="actions">
                    <button type="reset" class="btn btn-ghost" id="clearBtn">Clear</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-check btn-ico"></i><span class="spin"></span><span id="submitLabel">Submit transaction</span>
                    </button>
                </div>
                <p class="hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> to submit</p>
            </form>
        </section>

        <!-- ============================ LIST ============================ -->
        <section class="card" id="medrep-list" aria-labelledby="listTitle">
            <div class="card-head">
                <h2 class="card-title" id="listTitle"><i class="fas fa-list-ul"></i> Medrep list <span class="count-pill" id="countPill"><?= number_format($total_rows) ?></span></h2>
                <a href="monthly_summary.php" class="btn btn-line"><i class="fas fa-chart-column"></i> Monthly summary</a>
            </div>

            <form method="GET" action="staff.php" class="list-tools" id="filterForm">
                <input type="hidden" name="page" value="1">
                <div class="input-icon tool-search">
                    <i class="fas fa-search lead"></i>
                    <input class="input" type="search" id="f_q" name="f_q" placeholder="Search name or company…" value="<?= h($filter_q) ?>" aria-label="Search name or company">
                </div>
                <select class="select" id="f_company" name="f_company" aria-label="Company">
                    <option value="">All companies</option>
                    <?php foreach ($company_list as $c): $cname = $c['name']; ?>
                        <option value="<?= h($cname) ?>" <?= ($filter_company === $cname) ? 'selected' : '' ?>><?= h($cname) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="select" id="f_month" name="f_month" aria-label="Month">
                    <option value="">All months</option>
                    <?php foreach ($available_months as $m):
                        $ym = $m['ym']; if (!$ym) continue; ?>
                        <option value="<?= h($ym) ?>" <?= ($filter_month === $ym) ? 'selected' : '' ?>><?= h(date("F Y", strtotime($ym . "-01"))) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="select" id="per_page" name="per_page" aria-label="Rows per page">
                    <?php foreach ($per_page_options as $opt): ?>
                        <option value="<?= $opt ?>" <?= ($per_page === $opt) ? 'selected' : '' ?>><?= $opt ?> rows</option>
                    <?php endforeach; ?>
                </select>
                <a href="staff.php#medrep-list" class="btn btn-line" id="resetFilters" title="Clear filters"><i class="fas fa-rotate-left"></i> Reset</a>
                <noscript><button type="submit" class="btn btn-line">Apply</button></noscript>
            </form>

            <div id="listRegion"><?= $list_html ?></div>
        </section>
    </div>
</main>

<script>
(function () {
    'use strict';

    var employeeList = <?= json_encode($employee_list, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var companyList  = <?= json_encode(array_column($company_list, 'name'), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    var $ = function (id) { return document.getElementById(id); };
    var form = $('txForm'), nameI = $('name'), companyI = $('company'), amountI = $('amount'), remarksI = $('remarks');
    var typeI = $('transaction_type'), payI = $('payment_method'), cardI = $('card'), cardOther = $('card_other');
    var transferBox = $('transferFields'), transferName = $('transfer_name'), transferCompany = $('transfer_company');
    var payWrap = $('paymentMethodGroup'), cardGroup = $('cardGroup');
    var submitBtn = $('submitBtn'), submitLabel = $('submitLabel');
    var cardChoice = 'maya';

    var palette = ['#1a7f3c', '#2f6f9f', '#8a5a9e', '#b5651d', '#0f8b8d', '#a23b5c', '#5a7d2a', '#3d5a99'];
    function colorFor(s) { var h = 0; s = String(s).toLowerCase(); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return palette[h % palette.length]; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    /* ---------------------------------------------------------- toasts */
    function toast(msg, type) {
        var box = $('toasts'), t = document.createElement('div');
        t.className = 'toast' + (type === 'error' ? ' error' : '');
        t.setAttribute('role', type === 'error' ? 'alert' : 'status');
        t.innerHTML = '<span class="t-ico"><i class="fas ' + (type === 'error' ? 'fa-exclamation' : 'fa-check') + '"></i></span><span class="t-msg"></span><button class="t-x" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>';
        t.querySelector('.t-msg').textContent = msg;
        function kill() { t.classList.add('leaving'); setTimeout(function () { t.remove(); }, 200); }
        t.querySelector('.t-x').onclick = kill;
        box.appendChild(t);
        setTimeout(kill, type === 'error' ? 7000 : 4000);
    }

    var flash = $('flash');
    if (flash) { toast(flash.dataset.msg, flash.dataset.type); flash.remove(); }

    /* ----------------------------------------------------- chip groups */
    function chipGroup(groupEl, onPick) {
        var chips = Array.prototype.slice.call(groupEl.querySelectorAll('.chip'));
        function select(chip, focus) {
            chips.forEach(function (c) { c.setAttribute('aria-checked', c === chip ? 'true' : 'false'); c.tabIndex = c === chip ? 0 : -1; });
            if (focus) chip.focus();
            onPick(chip.dataset.value);
        }
        chips.forEach(function (c, i) {
            c.tabIndex = c.getAttribute('aria-checked') === 'true' ? 0 : -1;
            c.addEventListener('click', function () { select(c, false); });
            c.addEventListener('keydown', function (e) {
                var k = e.key, n = chips.length, to = -1;
                if (k === 'ArrowRight' || k === 'ArrowDown') to = (i + 1) % n;
                else if (k === 'ArrowLeft' || k === 'ArrowUp') to = (i - 1 + n) % n;
                if (to >= 0) { e.preventDefault(); select(chips[to], true); }
            });
        });
        return { set: function (v) { var c = chips.filter(function (x) { return x.dataset.value === v; })[0]; if (c) select(c, false); } };
    }

    var typeChips = chipGroup($('typeGroup'), function (v) { typeI.value = v; applyType(); recap(); });
    var payChips  = chipGroup($('payGroup'), function (v) { payI.value = v; applyPay(); recap(); });
    var cardChips = chipGroup($('cardChoiceGroup'), function (v) { cardChoice = v; applyPay(); recap(); });

    function syncCard() {
        if (payI.value !== 'card' || typeI.value === 'transfer') { cardI.value = ''; return; }
        cardI.value = cardChoice === 'others' ? cardOther.value.trim() : cardChoice;
    }
    function applyPay() {
        var isCard = payI.value === 'card' && typeI.value !== 'transfer';
        cardGroup.hidden = !isCard;
        cardOther.hidden = !(isCard && cardChoice === 'others');
        syncCard();
    }
    function applyType() {
        var isTransfer = typeI.value === 'transfer';
        transferBox.hidden = !isTransfer;
        payWrap.hidden = isTransfer;
        $('amountLabel').textContent = isTransfer ? 'Transfer amount' : 'Amount';
        applyPay();
    }
    cardOther.addEventListener('input', function () { syncCard(); recap(); });

    /* ----------------------------------------------------------- recap */
    var typeNames = { deposit: 'Deposit', dinein: 'Dine in', takeout: 'Take out', buygiftcard: 'Gift card purchase', transfer: 'Transfer' };
    var payNames  = { card: 'Swipe', cash: 'Cash', paid: 'Paid', giftcard: 'Gift card', na: 'N/A' };
    function recap() {
        var r = $('recap'), amt = parseFloat(amountI.value), nm = nameI.value.trim();
        if (!nm && !(amt > 0)) { r.className = 'recap empty'; r.innerHTML = '<i class="fas fa-receipt"></i><span>Fill in the details to see a summary.</span>'; return; }
        var parts = ['<b>' + esc(typeNames[typeI.value]) + '</b>'];
        if (amt > 0) parts.push('<b>' + peso(amt) + '</b>');
        if (typeI.value === 'transfer') { if (nm) parts.push('from ' + esc(nm.toUpperCase())); if (transferName.value.trim()) parts.push('to <b>' + esc(transferName.value.trim().toUpperCase()) + '</b>'); }
        else {
            var how = payNames[payI.value];
            if (payI.value === 'card') { var cn = cardI.value || (cardChoice === 'others' ? '…' : ''); if (cn) how += ' · ' + cn.charAt(0).toUpperCase() + cn.slice(1); }
            parts.push('via ' + esc(how));
            if (nm) parts.push('for ' + esc(nm.toUpperCase()));
        }
        r.className = 'recap';
        r.innerHTML = '<i class="fas fa-receipt"></i><span>' + parts.join(' · ') + '</span>';
    }
    [nameI, amountI, transferName].forEach(function (el) { el.addEventListener('input', recap); });

    /* ------------------------------------------------------- autocomplete */
    function attachAutocomplete(input, box, getMatches, render, onSelect, onClear) {
        var active = -1, matches = [];
        function close() { box.classList.remove('open'); box.innerHTML = ''; active = -1; matches = []; }
        function setActive(i) {
            var rows = box.querySelectorAll('.sg-item');
            rows.forEach(function (r) { r.classList.remove('active'); });
            if (i >= 0 && rows[i]) { rows[i].classList.add('active'); rows[i].scrollIntoView({ block: 'nearest' }); }
            active = i;
        }
        function open(list) {
            matches = list; active = -1; box.innerHTML = '';
            if (!list.length) { box.innerHTML = '<div class="sg-empty">No matches found</div>'; box.classList.add('open'); return; }
            list.forEach(function (item, idx) {
                var row = document.createElement('div');
                row.className = 'sg-item'; row.setAttribute('role', 'option');
                row.innerHTML = render(item);
                row.addEventListener('mousedown', function (e) { e.preventDefault(); onSelect(item); close(); });
                row.addEventListener('mouseenter', function () { setActive(idx); });
                box.appendChild(row);
            });
            box.classList.add('open');
        }
        input.addEventListener('input', function () {
            var v = input.value.trim();
            if (!v && onClear) onClear();
            v ? open(getMatches(v)) : close();
        });
        input.addEventListener('focus', function () { var v = input.value.trim(); if (v) open(getMatches(v)); });
        input.addEventListener('blur', function () { setTimeout(close, 120); });
        input.addEventListener('keydown', function (e) {
            if (!box.classList.contains('open') || !matches.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((active + 1) % matches.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((active - 1 + matches.length) % matches.length); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); e.stopPropagation(); onSelect(matches[active]); close(); }
            else if (e.key === 'Escape') close();
        });
        return { open: open, close: close, isOpen: function () { return box.classList.contains('open'); } };
    }
    function findEmployees(q) { q = q.toLowerCase(); return employeeList.filter(function (e) { return e.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 8); }
    function findCompanies(q, all) {
        q = q.toLowerCase(); var seen = {}, out = [];
        companyList.forEach(function (n) { var k = (n || '').toLowerCase(); if (n && k.indexOf(q) !== -1 && !seen[k]) { seen[k] = 1; out.push(n); } });
        return all ? out : out.slice(0, 8);
    }
    function personRow(e) {
        return '<span class="av sm" style="background:' + colorFor(e.name) + '">' + esc((e.name || '?').charAt(0).toUpperCase()) + '</span>' +
               '<span><span class="sg-main">' + esc(e.name) + '</span><span class="sg-sub">' + esc(e.company) + '</span></span>';
    }
    function refreshAutofill() { /* placeholder for future hooks */ }

    attachAutocomplete(nameI, $('name-suggestions'), findEmployees, personRow,
        function (e) { nameI.value = e.name; companyI.value = e.company; recap(); amountI.focus(); },
        function () { companyI.value = ''; recap(); });

    var companyAC = attachAutocomplete(companyI, $('company-suggestions'), findCompanies,
        function (n) { return '<span class="sg-main">' + esc(n) + '</span>'; },
        function (n) { companyI.value = n; });
    $('company-toggle').addEventListener('mousedown', function (e) {
        e.preventDefault();
        if (companyAC.isOpen()) companyAC.close();
        else { companyI.focus(); companyAC.open(findCompanies(companyI.value.trim(), true)); }
    });

    attachAutocomplete(transferName, $('transfer-name-suggestions'), findEmployees, personRow,
        function (e) { transferName.value = e.name; transferCompany.value = e.company; recap(); },
        function () { transferCompany.value = ''; recap(); });

    /* ------------------------------------------------------------ submit */
    function setBusy(b) {
        submitBtn.disabled = b; submitBtn.classList.toggle('busy', b);
        submitLabel.textContent = b ? 'Saving…' : 'Submit transaction';
    }
    function problem(msg, el) { toast(msg, 'error'); if (el) el.focus(); return false; }

    function validate() {
        if (!nameI.value.trim()) return problem('Please enter a name.', nameI);
        if (!companyI.value.trim()) return problem('Please enter a company.', companyI);
        if (!(parseFloat(amountI.value) > 0)) return problem('Please enter an amount greater than 0.', amountI);
        if (typeI.value === 'transfer') {
            if (!transferName.value.trim()) return problem('Please choose who to transfer to.', transferName);
            if (transferName.value.trim().toLowerCase() === nameI.value.trim().toLowerCase()) return problem('Cannot transfer to the same medrep.', transferName);
        } else if (payI.value === 'card' && cardChoice === 'others' && !cardOther.value.trim()) {
            return problem("Please specify the card/bank name for 'Others'.", cardOther);
        }
        return true;
    }

    function resetForm() {
        form.reset();
        typeChips.set('deposit'); payChips.set('card'); cardChips.set('maya');
        transferCompany.value = ''; cardOther.value = '';
        applyType(); recap();
        nameI.focus();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitBtn.disabled) return;
        syncCard();
        if (!validate()) return;
        setBusy(true);
        // Same endpoint as before. submit.php always answers with a redirect to staff.php; we follow it and read the message it carries.
        fetch('submit.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (res) {
                if (res.redirected && res.url.indexOf('staff.php') === -1) { location.href = res.url; return null; }
                return res.text();
            })
            .then(function (html) {
                if (html === null) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fl = doc.getElementById('flash');
                if (!fl) { toast('Could not read the server reply. Please check the list before retrying.', 'error'); return; }
                toast(fl.dataset.msg, fl.dataset.type);
                if (fl.dataset.type === 'success') { resetForm(); loadList(true); }
            })
            .catch(function () { toast('Network problem — nothing was confirmed. Check the list before retrying.', 'error'); })
            .then(function () { setBusy(false); });
    });

    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();                       // same as before: Enter never submits by accident
            if (e.ctrlKey || e.metaKey) form.requestSubmit();
        }
    });
    $('clearBtn').addEventListener('click', function () { setTimeout(function () { typeChips.set('deposit'); payChips.set('card'); cardChips.set('maya'); transferCompany.value = ''; applyType(); recap(); nameI.focus(); }, 0); });

    /* --------------------------------------------------------- live list */
    var filterForm = $('filterForm'), region = $('listRegion'), qI = $('f_q'), reqId = 0, t;
    function currentQuery(pageOverride) {
        var p = new URLSearchParams(new FormData(filterForm));
        if (pageOverride) p.set('page', pageOverride);
        return p.toString();
    }
    function loadList(keepPage, pageOverride) {
        var id = ++reqId, qs = currentQuery(pageOverride);
        region.classList.add('loading');
        fetch('staff.php?partial=1&' + qs, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (r.redirected && r.url.indexOf('staff.php') === -1) { location.href = r.url; return null; } return r.json(); })
            .then(function (d) {
                if (!d || id !== reqId) return;
                region.innerHTML = d.html;
                $('countPill').textContent = Number(d.total).toLocaleString('en-US');
                $('todayCount').textContent = Number(d.today).toLocaleString('en-US');
                $('allCount').textContent = Number(d.all).toLocaleString('en-US');
                if (d.employees) employeeList = d.employees;
                history.replaceState(null, '', 'staff.php?' + qs + '#medrep-list');
            })
            .catch(function () { if (id === reqId) location.href = 'staff.php?' + qs; })
            .then(function () { if (id === reqId) region.classList.remove('loading'); });
    }
    filterForm.addEventListener('submit', function (e) { e.preventDefault(); filterForm.page.value = 1; loadList(); });
    qI.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { filterForm.page.value = 1; loadList(); }, 220); });
    ['f_company', 'f_month', 'per_page'].forEach(function (id) { $(id).addEventListener('change', function () { filterForm.page.value = 1; loadList(); }); });
    $('resetFilters').addEventListener('click', function (e) {
        e.preventDefault();
        qI.value = ''; $('f_company').value = ''; $('f_month').value = ''; $('per_page').value = '25'; filterForm.page.value = 1;
        loadList();
    });

    // Row click + pager (event delegation, so it keeps working after each refresh)
    region.addEventListener('click', function (e) {
        var pg = e.target.closest('.page-btn');
        if (pg) {
            e.preventDefault();
            if (pg.classList.contains('disabled')) return;
            filterForm.page.value = pg.dataset.page; loadList(false, pg.dataset.page);
            $('medrep-list').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        var row = e.target.closest('tr.row');
        if (row && row.dataset.name) location.href = 'summary.php?name=' + encodeURIComponent(row.dataset.name);
    });
    region.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var row = e.target.closest && e.target.closest('tr.row');
        if (row && row.dataset.name) location.href = 'summary.php?name=' + encodeURIComponent(row.dataset.name);
    });

    // "/" jumps to the list search
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && !/^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) { e.preventDefault(); qI.focus(); qI.select(); }
    });

    applyType(); recap();
    nameI.focus();
})();
</script>
</body>
</html>