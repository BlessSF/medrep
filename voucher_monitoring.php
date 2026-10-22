<?php
session_start();

require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Admin only (same rule the login uses to send people to admin.php)
if (!isset($_SESSION['username']) || empty($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}
$admin_username = $_SESSION['username'];

// ------------------------------------------------------------
// Tables are created on first visit, so the page works even if
// voucher_monitoring_setup.sql hasn't been imported yet (the SQL
// file is only needed to load the Excel data).
// ------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS voucher_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    beginning INT NOT NULL DEFAULT 80,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_id INT NOT NULL,
    series_no INT NOT NULL,
    date_sold DATE NULL,
    sold_out TINYINT NOT NULL DEFAULT 0,
    customer_name VARCHAR(150) NULL,
    redeemed_by VARCHAR(150) NULL,
    mop VARCHAR(60) NULL,
    remarks VARCHAR(255) NULL,
    date_availed DATE NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type_series (type_id, series_no),
    KEY idx_customer (customer_name),
    FOREIGN KEY (type_id) REFERENCES voucher_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Older installs: add the "Redeemed By" column if it isn't there yet.
$chk = $conn->query("SHOW COLUMNS FROM vouchers LIKE 'redeemed_by'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE vouchers ADD COLUMN redeemed_by VARCHAR(150) NULL AFTER customer_name");
}

if (empty($_SESSION['vm_token'])) {
    $_SESSION['vm_token'] = bin2hex(random_bytes(16));
}

function vm_log($conn, $action, $user, $details) {
    $s = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
    if ($s) { $s->bind_param('sss', $action, $user, $details); $s->execute(); $s->close(); }
}
function vm_date($v) {
    $v = trim((string)$v);
    return ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
}
function vm_text($v) {
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, 255);
}

// Create empty (in-stock) rows for series 1..$n that don't exist yet for a voucher type.
function vm_fill_series($conn, $type_id, $n) {
    $n = min(max(0, (int)$n), 5000);
    for ($start = 1; $start <= $n; $start += 500) {
        $vals = [];
        for ($i = $start; $i < $start + 500 && $i <= $n; $i++) { $vals[] = "($type_id,$i)"; }
        $conn->query("INSERT IGNORE INTO vouchers (type_id, series_no) VALUES " . implode(',', $vals));
    }
}

$type_id = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$flash = '';
$flash_type = 'ok';

// ------------------------------------------------------------
// AJAX auto-save: one field of one row at a time. Nothing here can
// delete a row or a voucher type.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autosave') {
    header('Content-Type: application/json; charset=utf-8');
    $out = function ($arr, $code = 200) { http_response_code($code); echo json_encode($arr); exit(); };
    if (!hash_equals($_SESSION['vm_token'], (string)($_POST['token'] ?? ''))) {
        $out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 403);
    }
    $id    = (int)($_POST['id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $raw   = trim((string)($_POST['value'] ?? ''));
    $fields = ['date_sold' => 'date', 'date_availed' => 'date', 'customer_name' => 'upper', 'redeemed_by' => 'upper', 'mop' => 'upper', 'remarks' => 'text', 'note' => 'text'];
    if (!isset($fields[$field])) { $out(['ok' => false, 'error' => 'Invalid field.'], 400); }

    if ($fields[$field] === 'date') {
        $val = vm_date($raw);
        if ($raw !== '' && $val === null) { $out(['ok' => false, 'error' => 'Invalid date.'], 400); }
    } else {
        $val = vm_text($raw);
        if ($val !== null && $fields[$field] === 'upper') { $val = mb_strtoupper($val); }
    }

    $g = $conn->prepare("SELECT v.type_id, v.series_no, v.$field AS old, t.name FROM vouchers v JOIN voucher_types t ON t.id = v.type_id WHERE v.id = ?");
    $g->bind_param('i', $id);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    if (!$row) { $out(['ok' => false, 'error' => 'Row not found.'], 404); }

    $u = $conn->prepare("UPDATE vouchers SET $field = ? WHERE id = ?");
    $u->bind_param('si', $val, $id);
    if (!$u->execute()) { $out(['ok' => false, 'error' => 'Could not save.'], 500); }

    // A voucher counts as given out as soon as it has a customer name.
    $conn->query("UPDATE vouchers SET sold_out = IF(customer_name IS NULL OR customer_name = '', 0, 1) WHERE id = $id");
    $so = (int)$conn->query("SELECT sold_out FROM vouchers WHERE id = $id")->fetch_row()[0];

    if ((string)$row['old'] !== (string)$val) {
        vm_log($conn, 'Voucher updated', $admin_username,
            $row['name'] . ' series ' . str_pad((string)$row['series_no'], 3, '0', STR_PAD_LEFT) . " $field: '" . ($row['old'] ?? '') . "' -> '" . ($val ?? '') . "'");
    }

    $tid = (int)$row['type_id'];
    $st = $conn->query("SELECT t.beginning,
            (SELECT COUNT(*) FROM vouchers WHERE type_id = t.id AND sold_out = 1) AS total_out,
            (SELECT COUNT(*) FROM vouchers WHERE type_id = t.id AND date_availed IS NOT NULL) AS availed
        FROM voucher_types t WHERE t.id = $tid")->fetch_assoc();
    $out(['ok' => true, 'value' => $val, 'sold_out' => $so,
          'beginning' => (int)$st['beginning'], 'total_out' => (int)$st['total_out'],
          'ending' => (int)$st['beginning'] - (int)$st['total_out'], 'availed' => (int)$st['availed']]);
}

// ------------------------------------------------------------
// POST actions (create a voucher type / change beginning stock)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_id = (int)($_POST['type_id'] ?? $type_id);
    if (!hash_equals($_SESSION['vm_token'], (string)($_POST['token'] ?? ''))) {
        $flash = 'Session expired. Please try again.';
        $flash_type = 'err';
    } else {
        $act = $_POST['action'] ?? '';

        if ($act === 'add_type') {
            $name = strtoupper(trim((string)($_POST['name'] ?? '')));
            $beg  = min(5000, max(0, (int)($_POST['beginning'] ?? 0)));
            if ($name === '') {
                $flash = 'Voucher name is required.'; $flash_type = 'err';
            } else {
                $so = (int)$conn->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM voucher_types")->fetch_row()[0];
                $s = $conn->prepare("INSERT INTO voucher_types (name, beginning, sort_order) VALUES (?, ?, ?)");
                $s->bind_param('sii', $name, $beg, $so);
                if ($s->execute()) {
                    $type_id = $conn->insert_id;
                    vm_fill_series($conn, $type_id, $beg);
                    vm_log($conn, 'Voucher type added', $admin_username, "Added voucher type $name (beginning $beg)");
                    $flash = "Voucher type added with $beg series rows (001-" . str_pad((string)$beg, 3, '0', STR_PAD_LEFT) . ").";
                } else {
                    $flash = 'That voucher name already exists.'; $flash_type = 'err';
                }
            }
        } elseif ($act === 'set_beginning') {
            $beg = min(5000, max(0, (int)($_POST['beginning'] ?? 0)));
            $s = $conn->prepare("UPDATE voucher_types SET beginning = ? WHERE id = ?");
            $s->bind_param('ii', $beg, $type_id);
            $s->execute();
            vm_fill_series($conn, $type_id, $beg);   // adds any missing series rows up to the new beginning
            vm_log($conn, 'Voucher beginning updated', $admin_username, "Type #$type_id beginning set to $beg");
            $flash = 'Beginning stock updated. Missing series rows were added.';
        }
    }
    // Post/Redirect/Get so a refresh doesn't resubmit
    $_SESSION['vm_flash'] = [$flash, $flash_type];
    header("Location: voucher_monitoring.php" . ($type_id ? "?type=$type_id" : ''));
    exit();
}
if (!empty($_SESSION['vm_flash'])) {
    [$flash, $flash_type] = $_SESSION['vm_flash'];
    unset($_SESSION['vm_flash']);
}

// ------------------------------------------------------------
// Load data
// ------------------------------------------------------------
$types = [];
$tr = $conn->query("SELECT t.id, t.name, t.beginning,
        (SELECT COUNT(*) FROM vouchers v WHERE v.type_id = t.id AND v.sold_out = 1) AS total_out
    FROM voucher_types t ORDER BY t.sort_order, t.id");
while ($r = $tr->fetch_assoc()) { $types[$r['id']] = $r; }

if (!isset($types[$type_id])) {
    $type_id = $types ? (int)array_key_first($types) : 0;
}
$cur = $type_id ? $types[$type_id] : null;

$rows = [];
$next_series = 1;
if ($cur) {
    $s = $conn->prepare("SELECT id, series_no, date_sold, sold_out, customer_name, redeemed_by, mop, remarks, date_availed, note FROM vouchers WHERE type_id = ? ORDER BY series_no");
    $s->bind_param('i', $type_id);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; $next_series = $r['series_no'] + 1; }
}
$beginning = $cur ? (int)$cur['beginning'] : 0;
$total_out = $cur ? (int)$cur['total_out'] : 0;
$ending    = $beginning - $total_out;
$availed   = 0;
foreach ($rows as $r) { if ($r['date_availed']) $availed++; }

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_date($d) { return $d ? date('F j, Y', strtotime($d)) : ''; }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: rgb(227, 251, 203); }
        .sidebar { width: 230px; height: 100vh; background-color: #1b5e20; color: #fff; position: fixed; padding-top: 20px; }
        .sidebar ul { list-style-type: none; padding: 0; }
        .sidebar ul li { padding: 15px; text-align: center; }
        .sidebar ul li button { width: 100%; padding: 15px; background-color: #abd67c; color: #000; border: none; cursor: pointer; font-weight: bold; font-size: 15px; border-radius: 5px; }
        .main-content { margin-left: 230px; padding: 20px; }

        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .tab { padding: 9px 16px; border-radius: 999px; background: #fff; border: 1px solid #cfe3d3; color: #1b5e20; font-weight: 700; font-size: 13px; text-decoration: none; }
        .tab:hover { background: #e6f4e8; }
        .tab.active { background: #1b5e20; color: #fff; border-color: #1b5e20; }
        .tab.add { border-style: dashed; cursor: pointer; background: transparent; }

        .summary { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 20px; }
        .summary-item { flex: 1 1 180px; background: #fff; border-radius: 12px; padding: 18px 16px; text-align: center; border-top: 3px solid #2e7d32; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .summary-item h2 { margin: 0 0 10px; font-size: 13px; letter-spacing: .6px; color: #5c6b5e; }
        .summary-item p { margin: 0; font-size: 28px; font-weight: 800; }
        .summary-item.neg { border-top-color: #e0364a; }
        .summary-item.neg p { color: #e0364a; }
        .summary-item small { display: block; margin-top: 6px; color: #5c6b5e; font-size: 11px; }
        .linkbtn { background: none; border: none; color: #1b5e20; cursor: pointer; text-decoration: underline; font-size: 11px; padding: 0; }

        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 14px; }
        .search { position: relative; }
        .search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #1b5e20; }
        .search input { padding: 11px 14px 11px 38px !important; border: 1px solid #cfe3d3; border-radius: 8px; width: 280px; font-size: 14px; }
        .btn { background: #2e7d32; color: #fff; border: none; border-radius: 8px; padding: 11px 18px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #1b5e20; }
        .btn.ghost { background: #e6f4e8; color: #1b5e20; }
        .btn.danger { background: #e0364a; }
        .spacer { flex: 1; }

        .table-wrap { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { position: sticky; top: 0; z-index: 5; background: #1b5e20; color: #fff; padding: 12px 10px; font-size: 12px; letter-spacing: .5px; text-align: center; }
        td { padding: 10px; border-bottom: 1px solid #e3efe5; text-align: center; }
        tbody tr:nth-child(even) { background: #f3faf4; }
        tbody tr.free td { color: #8a978c; }
        td.left { text-align: left; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .pill.out { background: #fff1d6; color: #8a5a00; }
        .pill.free { background: #e6f4e8; color: #1b5e20; }
        .icon-btn { background: #eaf6ec; border: none; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; color: #1b5e20; }
        .icon-btn.del { color: #e0364a; background: #fdeced; }
        .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 700; font-size: 14px; }
        .flash.ok { background: #e7f8ee; color: #1b5e20; }
        .flash.err { background: #fdeced; color: #b3202f; }
        td input { width: 100%; box-sizing: border-box; border: 1px solid transparent; background: transparent; padding: 8px 8px; font-size: 14px; text-align: center; border-radius: 6px; font-family: inherit; }
        td input:hover { border-color: #cfe3d3; background: #fff; }
        td input:focus { outline: none; border-color: #2e7d32; background: #fff; box-shadow: 0 0 0 2px #c9e6cd; }
        td input.saving { border-color: #f0b429; }
        td input.saved { border-color: #2e7d32; background: #e7f8ee; }
        td input.failed { border-color: #e0364a; background: #fdeced; }
        td input[type=text] { text-align: left; min-width: 120px; }
        td.dcell { white-space: nowrap; }
        .dwrap { position: relative; display: inline-block; }
        .dwrap input[data-date] { padding-right: 30px; text-align: center; }
        .dwrap .hid { position: absolute; right: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none; padding: 0; border: 0; }
        .main-content .dwrap .cal { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); background: none !important; color: #2e7d32 !important; box-shadow: none !important; padding: 4px !important; font-size: 13px !important; }
        /* custom dropdown for Mode of Payment / Remarks */
        td input[data-opts] { cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%232e7d32' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 26px !important; }
        .vm-menu { position: fixed; z-index: 1000; min-width: 190px; max-height: 280px; overflow-y: auto; background: #fff; border: 1px solid #cfe3d3; border-radius: 12px; box-shadow: 0 12px 32px rgba(20,54,28,.22); padding: 6px; display: none; font-family: Arial, sans-serif; text-transform: none; }
        .vm-menu.open { display: block; animation: vmIn .12s ease-out; }
        @keyframes vmIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
        .vm-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13.5px; font-weight: 700; color: #1b3a22; cursor: pointer; letter-spacing: .2px; }
        .vm-item::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #c9e6cd; flex: none; }
        .vm-item.active, .vm-item:hover { background: #e6f4e8; color: #1b5e20; }
        .vm-item.active::before, .vm-item:hover::before { background: #2e7d32; }
        .vm-item.selected { background: #2e7d32; color: #fff; }
        .vm-item.selected::before { background: #fff; }
        .vm-item.clear { color: #b3202f; font-weight: 600; margin-top: 4px; border-top: 1px solid #e3efe5; border-radius: 0 0 8px 8px; }
        .vm-item.clear::before { background: #f3c1c6; }
        .vm-item.clear:hover { background: #fdeced; color: #b3202f; }
        .vm-item.clear:hover::before { background: #e0364a; }
        .savestate { font-size: 13px; font-weight: 700; color: #5c6b5e; }
        .savestate.err { color: #e0364a; }
        .empty { padding: 40px; text-align: center; color: #5c6b5e; }

        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 50; align-items: center; justify-content: center; }
        .modal-bg.open { display: flex; }
        .modal { background: #fff; border-radius: 14px; width: 94vw; max-width: 520px; max-height: 92vh; overflow: auto; padding: 22px; }
        .modal h2 { margin: 0 0 14px; font-size: 18px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid .full { grid-column: 1 / -1; }
        .field label { display: block; font-size: 11px; font-weight: 700; color: #5c6b5e; margin-bottom: 4px; letter-spacing: .4px; }
        .field input, .field select { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cfe3d3; border-radius: 8px; font-size: 14px; }
        .modal .actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
        /* assets/style.css forces every <button> green with !important; re-assert the variants used here */
        .sidebar { z-index: 100 !important; }   /* always stay above the table, even when the page scrolls sideways */
        .main-content .linkbtn { background: none !important; color: #1b5e20 !important; box-shadow: none !important; padding: 2px 4px !important; font-size: 12px !important; text-decoration: underline; transform: none !important; }
        .main-content .btn.ghost, .modal .btn.ghost { background: #e6f4e8 !important; color: #1b5e20 !important; }
                .main-content .tab.add { background: transparent !important; color: #1b5e20 !important; border: 1px dashed #1b5e20 !important; box-shadow: none !important; padding: 9px 16px !important; }
        /* assets/style.css puts overflow:hidden on every <table>, which stops sticky headers from working.
           Undo it here so the dark green header row stays visible while scrolling. */
        .main-content #vt { overflow: visible !important; border: none !important; border-radius: 0 !important; }
        .main-content #vt thead, .main-content #vt thead tr, .main-content #vt th { position: sticky; top: 0; z-index: 5; background: #1b5e20 !important; box-shadow: 0 2px 4px rgba(0,0,0,.18); }
        .main-content #vt th:first-child { border-top-left-radius: 12px; }
        .main-content #vt th:last-child  { border-top-right-radius: 12px; }
        .main-content #vt thead, .main-content #vt thead tr { position: static; box-shadow: none; }

        /* search box: icon sits inside the box, text starts after it */
        .main-content .search { position: relative; display: inline-block; }
        .main-content .search input[type="text"] { padding: 11px 14px 11px 42px !important; height: 44px; box-sizing: border-box; width: 300px; border: 1px solid #cfe3d3 !important; border-radius: 10px !important; background: #fff !important; }
        .main-content .search input[type="text"]:focus { outline: none; border-color: #2e7d32 !important; box-shadow: 0 0 0 3px #c9e6cd; }
        .main-content .search i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); font-size: 15px; color: #2e7d32; pointer-events: none; }
        @media (max-width: 640px) { .main-content { margin-left: 0; } .sidebar { display: none; } .grid { grid-template-columns: 1fr; } }
    </style>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="sidebar">
    <div class="sidebar-profile">
        <div class="sidebar-avatar"><i class="fas fa-user"></i></div>
        <div class="sidebar-profile-text">
            <span class="sidebar-greeting">Hello,</span>
            <span class="sidebar-username"><?= h($admin_username) ?></span>
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
    <h1>VOUCHER MONITORING</h1>

    <?php if ($flash): ?><div class="flash <?= h($flash_type) ?>"><?= h($flash) ?></div><?php endif; ?>

    <div class="tabs">
        <?php foreach ($types as $t): ?>
            <a class="tab <?= $t['id'] == $type_id ? 'active' : '' ?>" href="?type=<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a>
        <?php endforeach; ?>
        <button type="button" class="tab add" onclick="openModal('typeModal')"><i class="fas fa-plus"></i> New voucher</button>
    </div>

<?php if (!$cur): ?>
    <div class="table-wrap"><div class="empty">No voucher types yet. Click <b>New voucher</b> to add one, or import <b>voucher_monitoring_setup.sql</b> to load your Excel data.</div></div>
<?php else: ?>
    <div class="summary">
        <div class="summary-item">
            <h2>BEGINNING</h2>
            <p id="c_beg"><?= $beginning ?></p>
            <small><button class="linkbtn" onclick="openModal('begModal')">Change</button></small>
        </div>
        <div class="summary-item">
            <h2>TOTAL OUT</h2>
            <p id="c_out"><?= $total_out ?></p>
            <small>vouchers given to customers</small>
        </div>
        <div class="summary-item <?= $ending < 0 ? 'neg' : '' ?>" id="endCard">
            <h2>ENDING</h2>
            <p id="c_end"><?= $ending ?></p>
            <small id="endNote"><?= $ending < 0 ? 'Over the beginning stock' : 'still available' ?></small>
        </div>
        <div class="summary-item">
            <h2>AVAILED</h2>
            <p id="c_av"><?= $availed ?></p>
            <small>with a date availed</small>
        </div>
    </div>

    <div class="toolbar">
        <div class="search"><i class="fas fa-search"></i><input type="text" id="q" placeholder="Search this table..." oninput="filterRows()"></div>
        <label style="font-size:13px;font-weight:700;color:#1b5e20;"><input type="checkbox" id="onlyOut" onchange="filterRows()"> Show given out only</label>
        <div class="spacer"></div>
        <span id="saveState" class="savestate">All changes save automatically</span>
    </div>

    <div class="table-wrap">
        <table id="vt">
            <thead>
                <tr>
                    <th>DATE</th><th>SOLD OUT</th><th>SERIES NO.</th><th>CUSTOMER NAME</th><th>REDEEMED BY</th>
                    <th>MODE OF PAYMENT</th><th>REMARKS (SPONSORSHIP OR SOLD)</th><th>DATE AVAILED</th><th>NOTE (C/O ACCTNG)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
                <tr class="<?= $r['sold_out'] ? 'out' : 'free' ?>" data-id="<?= $rid ?>">
                    <td class="dcell"><div class="dwrap"><input type="text" data-f="date_sold" data-date="1" data-iso="<?= h($r['date_sold']) ?>" value="<?= h(fmt_date($r['date_sold'])) ?>" placeholder="Pick a date" autocomplete="off"><button type="button" class="cal" tabindex="-1" title="Pick from calendar"><i class="fas fa-calendar-alt"></i></button><input type="date" class="hid" tabindex="-1" value="<?= h($r['date_sold']) ?>"></div></td>
                    <td><span class="pill <?= $r['sold_out'] ? 'out' : 'free' ?>"><?= $r['sold_out'] ? 'OUT' : 'IN STOCK' ?></span></td>
                    <td><b><?= str_pad((string)$r['series_no'], 3, '0', STR_PAD_LEFT) ?></b></td>
                    <td><input type="text" data-f="customer_name" value="<?= h($r['customer_name']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="redeemed_by" value="<?= h($r['redeemed_by']) ?>" placeholder="Who redeemed it" autocomplete="off"></td>
                    <td><input type="text" data-f="mop" data-opts="mop" value="<?= h($r['mop']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="remarks" data-opts="remarks" value="<?= h($r['remarks']) ?>" autocomplete="off"></td>
                    <td class="dcell"><div class="dwrap"><input type="text" data-f="date_availed" data-date="1" data-iso="<?= h($r['date_availed']) ?>" value="<?= h(fmt_date($r['date_availed'])) ?>" placeholder="Pick a date" autocomplete="off"><button type="button" class="cal" tabindex="-1" title="Pick from calendar"><i class="fas fa-calendar-alt"></i></button><input type="date" class="hid" tabindex="-1" value="<?= h($r['date_availed']) ?>"></div></td>
                    <td><input type="text" data-f="note" value="<?= h($r['note']) ?>" autocomplete="off"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?><div class="empty">No series rows yet. Click <b>Change</b> under Beginning and enter the number of vouchers.</div><?php endif; ?>
    </div>

    <!-- Beginning stock -->
    <div class="modal-bg" id="begModal">
        <form class="modal" method="POST" style="max-width:360px">
            <h2>Beginning stock</h2>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="set_beginning">
            <div class="field"><label>BEGINNING (NUMBER OF VOUCHERS)</label><input type="number" min="0" name="beginning" value="<?= $beginning ?>" required></div>
            <div class="actions">
                <button type="button" class="btn ghost" onclick="closeModal('begModal')">Cancel</button>
                <button class="btn">Save</button>
            </div>
        </form>
    </div>
<?php endif; ?>

    <!-- New voucher type -->
    <div class="modal-bg" id="typeModal">
        <form class="modal" method="POST" style="max-width:400px">
            <h2>New voucher</h2>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="add_type">
            <div class="grid">
                <div class="field full"><label>VOUCHER NAME</label><input type="text" name="name" required></div>
                <div class="field full"><label>BEGINNING / TOTAL VOUCHERS (creates series 1 up to this number)</label><input type="number" min="0" max="5000" name="beginning" value="80" required></div>
            </div>
            <div class="actions">
                <button type="button" class="btn ghost" onclick="closeModal('typeModal')">Cancel</button>
                <button class="btn">Create</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m => m.addEventListener('mousedown', e => { if (e.target === m) m.classList.remove('open'); }));

const TOKEN = <?= json_encode($_SESSION['vm_token']) ?>;
const saveState = document.getElementById('saveState');
const timers = new WeakMap();

function setState(msg, err){ if (saveState){ saveState.textContent = msg; saveState.classList.toggle('err', !!err); } }

function saveField(inp, val){
    const tr = inp.closest('tr');
    const fd = new URLSearchParams({action:'autosave', token:TOKEN, id:tr.dataset.id, field:inp.dataset.f, value:(val !== undefined ? val : inp.value)});
    inp.classList.remove('saved','failed'); inp.classList.add('saving');
    setState('Saving...');
    fetch('voucher_monitoring.php', {method:'POST', body:fd, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
      .then(r => r.json().then(j => ({ok:r.ok && j.ok, j})))
      .then(({ok, j}) => {
        inp.classList.remove('saving');
        if (!ok) throw new Error(j.error || 'Could not save');
        if (inp.dataset.f === 'customer_name' || inp.dataset.f === 'redeemed_by' || inp.dataset.f === 'mop') { inp.value = j.value || ''; }
        if (inp.dataset.date) { inp.dataset.iso = j.value || ''; inp.dataset.last = j.value || ''; inp.value = longDate(j.value || ''); fit(inp); }
        else { inp.dataset.last = inp.value; fit(inp); }
        inp.classList.add('saved'); setTimeout(() => inp.classList.remove('saved'), 1200);
        tr.classList.toggle('out', j.sold_out === 1); tr.classList.toggle('free', j.sold_out !== 1);
        const pill = tr.querySelector('.pill');
        pill.className = 'pill ' + (j.sold_out ? 'out' : 'free'); pill.textContent = j.sold_out ? 'OUT' : 'IN STOCK';
        document.getElementById('c_beg').textContent = j.beginning;
        document.getElementById('c_out').textContent = j.total_out;
        document.getElementById('c_end').textContent = j.ending;
        document.getElementById('c_av').textContent = j.availed;
        document.getElementById('endCard').classList.toggle('neg', j.ending < 0);
        document.getElementById('endNote').textContent = j.ending < 0 ? 'Over the beginning stock' : 'still available';
        setState('All changes saved');
      })
      .catch(e => { inp.classList.remove('saving'); inp.classList.add('failed'); setState('Not saved: ' + e.message, true); });
}

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
function longDate(iso){
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? MONTHS[+m[2]-1] + ' ' + (+m[3]) + ', ' + m[1] : '';
}
// Accepts: 2026-01-15, 1/15/2026, 1-15-26, January 15, 2026, Jan 15 2026, 15 January 2026
function parseDate(str){
    str = (str || '').trim();
    if (str === '') return '';
    let y, mo, d, m;
    const pad = n => String(n).padStart(2, '0');
    const fy = v => v < 100 ? 2000 + v : v;
    if ((m = /^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/.exec(str))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/.exec(str))) { mo = +m[1]; d = +m[2]; y = fy(+m[3]); }
    else if ((m = /^([A-Za-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{2,4})$/.exec(str))) { mo = monthIdx(m[1]); d = +m[2]; y = fy(+m[3]); }
    else if ((m = /^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\.?,?\s+(\d{2,4})$/.exec(str))) { d = +m[1]; mo = monthIdx(m[2]); y = fy(+m[3]); }
    else return null;
    if (!mo || mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    const t = new Date(y, mo - 1, d);
    if (t.getFullYear() !== y || t.getMonth() !== mo - 1 || t.getDate() !== d) return null;
    return y + '-' + pad(mo) + '-' + pad(d);
}
function monthIdx(name){
    name = name.toLowerCase();
    const i = MONTHS.findIndex(x => x.toLowerCase() === name || x.toLowerCase().slice(0,3) === name.slice(0,3));
    return i < 0 ? 0 : i + 1;
}
// Make every cell exactly as wide as what is typed in it
const measureCtx = document.createElement('canvas').getContext('2d');
function fit(inp){
    const cs = getComputedStyle(inp);
    measureCtx.font = cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
    let t = inp.value || inp.placeholder || '';
    if (cs.textTransform === 'uppercase') t = t.toUpperCase();
    const pad = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
    const min = inp.dataset.date ? 190 : (inp.dataset.opts ? 150 : 130);
    const max = (inp.dataset.date || inp.dataset.opts) ? 400 : 260;   // very long text scrolls inside the cell instead of widening the table
    inp.style.width = Math.ceil(Math.min(max, Math.max(min, measureCtx.measureText(t).width + pad + 14))) + 'px';
}

document.querySelectorAll('#vt tbody input[data-f]').forEach(inp => {
    const isDate = !!inp.dataset.date;
    inp.dataset.last = isDate ? inp.dataset.iso : inp.value;
    fit(inp);

    const current = () => {                       // the value to send to the server
        if (!isDate) return inp.value;
        const iso = parseDate(inp.value);
        return iso;                               // null = could not understand the date
    };
    const go = () => {
        const v = current();
        if (v === null) {                         // bad date: put the old one back
            inp.classList.add('failed'); setState('Not saved: type a date like January 15, 2026', true);
            inp.value = longDate(inp.dataset.last); fit(inp);
            setTimeout(() => inp.classList.remove('failed'), 1500);
            return;
        }
        if (v !== inp.dataset.last) saveField(inp, v);
        else if (isDate) { inp.value = longDate(v); fit(inp); }
    };
    inp.addEventListener('change', go);
    inp.addEventListener('blur', go);
    inp.addEventListener('input', () => {
        fit(inp);
        if (isDate) return;                       // dates save when you leave the cell / press Enter
        clearTimeout(timers.get(inp)); timers.set(inp, setTimeout(go, 700));
    });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); inp.blur(); } });

    if (isDate) {                                 // calendar button -> native picker -> long format
        const wrap = inp.closest('.dwrap');
        const pick = wrap.querySelector('.hid');
        wrap.querySelector('.cal').addEventListener('click', () => {
            pick.value = parseDate(inp.value) || '';
            try { pick.showPicker(); } catch (e) { pick.focus(); pick.click(); }
        });
        pick.addEventListener('change', () => { inp.value = longDate(pick.value); fit(inp); go(); });
    }
});
// ---------- styled dropdown (replaces the plain browser list) ----------
const OPTIONS = {
    mop: ['CASH', 'CHEQUE', 'CARD', 'GCASH', 'BANK TRANSFER'],
    remarks: ['SPONSORSHIP', 'SOLD']
};
const menu = document.createElement('div');
menu.className = 'vm-menu';
document.body.appendChild(menu);
let menuInput = null, menuIdx = -1;

function menuItems(){ return Array.from(menu.querySelectorAll('.vm-item')); }
function closeMenu(){ menu.classList.remove('open'); menuInput = null; menuIdx = -1; }
function setActive(i){
    const items = menuItems();
    items.forEach(x => x.classList.remove('active'));
    menuIdx = items.length ? (i + items.length) % items.length : -1;
    if (menuIdx >= 0) { items[menuIdx].classList.add('active'); items[menuIdx].scrollIntoView({block:'nearest'}); }
}
function choose(inp, v){
    inp.value = v; fit(inp);
    inp.dispatchEvent(new Event('change'));
    closeMenu();
}
function openMenu(inp, showAll){
    const all = OPTIONS[inp.dataset.opts] || [];
    const q = inp.value.trim().toUpperCase();
    const list = (showAll || q === '') ? all : all.filter(o => o.includes(q));
    menu.innerHTML = '';
    list.forEach(o => {
        const d = document.createElement('div');
        d.className = 'vm-item' + (o === inp.value.trim().toUpperCase() ? ' selected' : '');
        d.textContent = o;
        d.addEventListener('mousedown', e => { e.preventDefault(); choose(inp, o); });
        menu.appendChild(d);
    });
    if (inp.value.trim() !== '') {
        const c = document.createElement('div');
        c.className = 'vm-item clear'; c.textContent = 'Clear';
        c.addEventListener('mousedown', e => { e.preventDefault(); choose(inp, ''); });
        menu.appendChild(c);
    }
    if (!menu.children.length) { closeMenu(); return; }
    const r = inp.getBoundingClientRect();
    const h = Math.min(280, menu.children.length * 42 + 16);
    const below = window.innerHeight - r.bottom > h + 12;
    menu.style.minWidth = Math.max(190, r.width) + 'px';
    menu.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
    menu.style.top = (below ? r.bottom + 6 : Math.max(8, r.top - h - 6)) + 'px';
    menu.classList.add('open');
    menuInput = inp; setActive(-1);
}
document.querySelectorAll('#vt tbody input[data-opts]').forEach(inp => {
    inp.addEventListener('focus', () => openMenu(inp, true));
    inp.addEventListener('click', () => openMenu(inp, true));
    inp.addEventListener('input', () => openMenu(inp, false));
    inp.addEventListener('blur', () => setTimeout(() => { if (menuInput === inp) closeMenu(); }, 0));
    inp.addEventListener('keydown', e => {
        if (!menu.classList.contains('open') || menuInput !== inp) { if (e.key === 'ArrowDown') { openMenu(inp, true); e.preventDefault(); } return; }
        if (e.key === 'ArrowDown') { setActive(menuIdx + 1); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { setActive(menuIdx - 1); e.preventDefault(); }
        else if (e.key === 'Escape') { closeMenu(); }
        else if (e.key === 'Enter' && menuIdx >= 0) { e.preventDefault(); e.stopImmediatePropagation(); menuItems()[menuIdx].dispatchEvent(new MouseEvent('mousedown', {cancelable:true})); inp.blur(); }
    }, true);
});
window.addEventListener('scroll', closeMenu, true);
window.addEventListener('resize', closeMenu);

const refitAll = () => document.querySelectorAll('#vt tbody input[data-f]').forEach(fit);
if (document.fonts && document.fonts.ready) document.fonts.ready.then(refitAll);
window.addEventListener('load', refitAll);

window.addEventListener('beforeunload', e => { if (document.querySelector('#vt input.saving')) { e.preventDefault(); e.returnValue = ''; } });

function filterRows(){
    const q = document.getElementById('q').value.toLowerCase();
    const only = document.getElementById('onlyOut').checked;
    document.querySelectorAll('#vt tbody tr').forEach(tr => {
        const txt = Array.from(tr.querySelectorAll('input')).map(i => i.value).join(' ') + ' ' + tr.textContent;
        const ok = txt.toLowerCase().includes(q) && (!only || tr.classList.contains('out'));
        tr.style.display = ok ? '' : 'none';
    });
}
</script>
</body>
</html>