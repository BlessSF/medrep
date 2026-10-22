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
    mop VARCHAR(60) NULL,
    remarks VARCHAR(255) NULL,
    date_availed DATE NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type_series (type_id, series_no),
    KEY idx_customer (customer_name),
    FOREIGN KEY (type_id) REFERENCES voucher_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    $fields = ['date_sold' => 'date', 'date_availed' => 'date', 'customer_name' => 'upper', 'mop' => 'upper', 'remarks' => 'text', 'note' => 'text'];
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
    $s = $conn->prepare("SELECT id, series_no, date_sold, sold_out, customer_name, mop, remarks, date_availed, note FROM vouchers WHERE type_id = ? ORDER BY series_no");
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
function fmt_date($d) { return $d ? date('M j, Y', strtotime($d)) : ''; }
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

        .table-wrap { background: #fff; border-radius: 12px; overflow: auto; max-height: 62vh; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { position: sticky; top: 0; background: #1b5e20; color: #fff; padding: 12px 10px; font-size: 12px; letter-spacing: .5px; text-align: center; }
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
        .main-content .linkbtn { background: none !important; color: #1b5e20 !important; box-shadow: none !important; padding: 2px 4px !important; font-size: 12px !important; text-decoration: underline; transform: none !important; }
        .main-content .btn.ghost, .modal .btn.ghost { background: #e6f4e8 !important; color: #1b5e20 !important; }
                .main-content .tab.add { background: transparent !important; color: #1b5e20 !important; border: 1px dashed #1b5e20 !important; box-shadow: none !important; padding: 9px 16px !important; }
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
                    <th>DATE</th><th>SOLD OUT</th><th>SERIES NO.</th><th>CUSTOMER NAME</th>
                    <th>MODE OF PAYMENT</th><th>REMARKS (SPONSORSHIP OR SOLD)</th><th>DATE AVAILED</th><th>NOTE (C/O ACCTNG)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
                <tr class="<?= $r['sold_out'] ? 'out' : 'free' ?>" data-id="<?= $rid ?>">
                    <td><input type="date" data-f="date_sold" value="<?= h($r['date_sold']) ?>"></td>
                    <td><span class="pill <?= $r['sold_out'] ? 'out' : 'free' ?>"><?= $r['sold_out'] ? 'OUT' : 'IN STOCK' ?></span></td>
                    <td><b><?= str_pad((string)$r['series_no'], 3, '0', STR_PAD_LEFT) ?></b></td>
                    <td><input type="text" data-f="customer_name" value="<?= h($r['customer_name']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="mop" list="mopList" value="<?= h($r['mop']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="remarks" list="remList" value="<?= h($r['remarks']) ?>" autocomplete="off"></td>
                    <td><input type="date" data-f="date_availed" value="<?= h($r['date_availed']) ?>"></td>
                    <td><input type="text" data-f="note" value="<?= h($r['note']) ?>" autocomplete="off"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?><div class="empty">No series rows yet. Click <b>Change</b> under Beginning and enter the number of vouchers.</div><?php endif; ?>
    </div>

    <datalist id="mopList"><option value="CASH"><option value="CHEQUE"><option value="CARD"><option value="GCASH"><option value="BANK TRANSFER"></datalist>
    <datalist id="remList"><option value="SPONSORSHIP"><option value="SOLD"></datalist>

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

function saveField(inp){
    const tr = inp.closest('tr');
    const fd = new URLSearchParams({action:'autosave', token:TOKEN, id:tr.dataset.id, field:inp.dataset.f, value:inp.value});
    inp.classList.remove('saved','failed'); inp.classList.add('saving');
    setState('Saving...');
    fetch('voucher_monitoring.php', {method:'POST', body:fd, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
      .then(r => r.json().then(j => ({ok:r.ok && j.ok, j})))
      .then(({ok, j}) => {
        inp.classList.remove('saving');
        if (!ok) throw new Error(j.error || 'Could not save');
        if (inp.dataset.f === 'customer_name' || inp.dataset.f === 'mop') { inp.value = j.value || ''; }
        inp.dataset.last = inp.value;
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

document.querySelectorAll('#vt tbody input').forEach(inp => {
    inp.dataset.last = inp.value;
    const go = () => { if (inp.value !== inp.dataset.last) saveField(inp); };
    inp.addEventListener('change', go);                       // dates, datalist picks, leaving the cell
    inp.addEventListener('blur', go);
    inp.addEventListener('input', () => {                     // typing: save shortly after you stop
        if (inp.type === 'date') return;
        clearTimeout(timers.get(inp)); timers.set(inp, setTimeout(go, 700));
    });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); inp.blur(); } });
});
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