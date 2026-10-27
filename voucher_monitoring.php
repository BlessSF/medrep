<?php
session_start();

require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Admin and cashier can both open this page.
//   - admin   : everything (add / remove voucher types, change beginning stock, edit rows)
//   - cashier : view, search, edit the rows and export. Cannot add/remove vouchers or change stock.
$is_cashier = !empty($_SESSION['cashier']);
$is_admin   = !empty($_SESSION['admin']) && !$is_cashier;   // a cashier login always gets the cashier page
if (!isset($_SESSION['username']) || (!$is_admin && !$is_cashier)) {
    header("Location: index.php");
    exit();
}
$cashier_view   = $is_cashier;   // cashier layout = green header + Transaction/Voucher tabs, no sidebar
$admin_username = $_SESSION['username'];       // (name used in the logs for whoever is signed in)

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
        } elseif ($act === 'delete_type') {
            $s = $conn->prepare("SELECT name, (SELECT COUNT(*) FROM vouchers WHERE type_id = voucher_types.id) AS n FROM voucher_types WHERE id = ?");
            $s->bind_param('i', $type_id);
            $s->execute();
            $vt = $s->get_result()->fetch_assoc();
            $typed = mb_strtoupper(trim((string)($_POST['confirm_name'] ?? '')));
            if (!$vt) {
                $flash = 'Voucher not found.'; $flash_type = 'err';
            } elseif ($typed !== mb_strtoupper(trim($vt['name']))) {
                $flash = 'Not removed: the name you typed did not match.'; $flash_type = 'err';
            } else {
                $d1 = $conn->prepare("DELETE FROM vouchers WHERE type_id = ?");
                $d1->bind_param('i', $type_id); $d1->execute();
                $d2 = $conn->prepare("DELETE FROM voucher_types WHERE id = ?");
                $d2->bind_param('i', $type_id); $d2->execute();
                vm_log($conn, 'Voucher removed', $admin_username, "Removed voucher {$vt['name']} and its {$vt['n']} entries");
                $flash = "Voucher \"{$vt['name']}\" was removed.";
                $type_id = 0;
            }
        } elseif ($act === 'edit_type') {
            // Rename a voucher and/or change its beginning stock (missing series rows are added).
            $name = mb_strtoupper(trim((string)($_POST['name'] ?? '')));
            $beg  = min(5000, max(0, (int)($_POST['beginning'] ?? 0)));
            if ($name === '') {
                $flash = 'Voucher name is required.'; $flash_type = 'err';
            } else {
                $s = $conn->prepare("UPDATE voucher_types SET name = ?, beginning = ? WHERE id = ?");
                $s->bind_param('sii', $name, $beg, $type_id);
                if ($s->execute()) {
                    vm_fill_series($conn, $type_id, $beg);
                    vm_log($conn, 'Voucher edited', $admin_username, "Voucher #$type_id is now $name (beginning $beg)");
                    $flash = 'Voucher updated.';
                } else {
                    $flash = 'Another voucher already uses that name.'; $flash_type = 'err';
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

// ---- Paging / filtering (done in SQL so only one page of rows is ever loaded) ----
$per_opts = [20, 50, 80];
$per      = (int)($_GET['per'] ?? 20);
if (!in_array($per, $per_opts, true)) $per = 20;
$q        = trim((string)($_GET['q'] ?? ''));
$only_out = !empty($_GET['out']);
$pg       = max(1, (int)($_GET['pg'] ?? 1));

$rows = [];
$total_rows = 0;      // every series row of this voucher (ignores search/filter)
$match_rows = 0;      // rows matching the current search/filter
$availed    = 0;
if ($cur) {
    $total_rows = (int)$conn->query("SELECT COUNT(*) FROM vouchers WHERE type_id = " . (int)$type_id)->fetch_row()[0];
    $availed    = (int)$conn->query("SELECT COUNT(*) FROM vouchers WHERE type_id = " . (int)$type_id . " AND date_availed IS NOT NULL")->fetch_row()[0];

    // Build the filter with escaped values (no bind_param, so a bad query can never fatal the page).
    $where = "type_id = " . (int)$type_id;
    if ($only_out) $where .= " AND sold_out = 1";
    if ($q !== '') {
        if (ctype_digit($q)) {
            // A number finds that exact series number (001, 1, 0001 all match series 1).
            $where .= " AND series_no = " . (int)$q;
        } else {
            $esc = $conn->real_escape_string(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q));
            $like = "'%" . $esc . "%'";
            $where .= " AND (customer_name LIKE $like OR redeemed_by LIKE $like OR mop LIKE $like OR remarks LIKE $like OR note LIKE $like)";
        }
    }

    $cr = $conn->query("SELECT COUNT(*) FROM vouchers WHERE $where");
    $match_rows = $cr ? (int)$cr->fetch_row()[0] : 0;

    $pages = max(1, (int)ceil($match_rows / $per));
    if ($pg > $pages) $pg = $pages;
    $offset = ($pg - 1) * $per;

    $res = $conn->query("SELECT id, series_no, date_sold, sold_out, customer_name, redeemed_by, mop, remarks, date_availed, note FROM vouchers WHERE $where ORDER BY series_no LIMIT $per OFFSET $offset");
    if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
}
$pages = $pages ?? 1;
$beginning = $cur ? (int)$cur['beginning'] : 0;
$total_out = $cur ? (int)$cur['total_out'] : 0;
$ending    = $beginning - $total_out;

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_date($d) { return $d ? date('F j, Y', strtotime($d)) : ''; }
?>
<?php if ($cashier_view): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        /* Self-contained on purpose: this page does not load assets/style.css, so the
           global "every button is green + lifts on hover" rules cannot leak in. */
        *, *::before, *::after { box-sizing: border-box; }
        :root { --ink: #1f2a20; --muted: #667467; --line: #c3d0bf; --green: #2e7d32; --dark: #1b5e20; --head: #2f5d34; --paper: #ffffff; --stick: 0px; }
        body { margin: 0; font: 14px/1.45 "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e3fbcb; }
        body.cashier { --stick: 114px; }
        a { color: var(--dark); }
        button { font-family: inherit; }

        /* ---------- admin sidebar (flat list) ---------- */
        .sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: 220px; overflow-y: auto; z-index: 100; background: #1d4a22; color: #dfeadc; }
        .sidebar .who { padding: 16px 18px; border-bottom: 1px solid rgba(255,255,255,.14); font-size: 13px; color: #b7cdb3; }
        .sidebar .who b { display: block; color: #fff; font-size: 15px; margin-top: 2px; }
        .sidebar a { display: block; padding: 11px 18px; color: #dfeadc; text-decoration: none; font-size: 13px; font-weight: 600; border-left: 3px solid transparent; }
        .sidebar a i { width: 22px; color: #9fc59a; }
        .sidebar a:hover { background: rgba(255,255,255,.08); }
        .sidebar a.on { background: rgba(255,255,255,.14); border-left-color: #fff; color: #fff; }

        /* ---------- cashier header (same look as the Transaction page) ---------- */
        .header { display: flex; justify-content: space-between; align-items: center; position: fixed; top: 0; left: 0; width: 100%; height: 70px; z-index: 1000; padding: 14px 26px;
                  background: linear-gradient(120deg, #14361c 0%, #1b5e20 45%, #2e7d32 100%); color: #fff; border-bottom: 1px solid rgba(255,255,255,.12); }
        .header-brand { display: flex; align-items: center; gap: 14px; }
        .header-logo { width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.35); display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .header h1 { margin: 0; font-size: 21px; font-weight: 800; letter-spacing: .4px; line-height: 1.15; color: #fff; text-transform: uppercase; }
        .header-subtitle { display: block; margin-top: 3px; font-size: 12.5px; font-weight: 700; color: rgba(255,255,255,.88); letter-spacing: .4px; text-transform: uppercase; }
        .logout-button { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 8px; background: #fff; color: #1b5e20; font-size: 13px; font-weight: 800; text-decoration: none; text-transform: uppercase; }
        .logout-button:hover { background: #eafbea; }

        /* ---------- page ---------- */
        .page { padding: 20px 24px 40px; }
        body.admin .page { margin-left: 220px; }
        body.cashier .page { margin-top: 114px; }
        .titlebar { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px 18px; margin-bottom: 14px; }
        .titlebar h1 { margin: 0; font-size: 22px; font-weight: 700; }
        .titlebar .sub { color: var(--muted); font-size: 13px; }
        .titlebar .all { margin-left: auto; color: var(--muted); font-size: 13px; }
        .titlebar .all b { color: var(--ink); }

        .flash { padding: 10px 14px; margin-bottom: 12px; border: 1px solid; border-radius: 3px; font-size: 14px; }
        .flash.ok { background: #f1faee; border-color: #9fcf99; color: #1b5e20; }
        .flash.err { background: #fff2f1; border-color: #e5a5a0; color: #a52a22; }

        /* ---------- voucher "sheet" tabs, like the Excel file they came from ---------- */
        .sheets { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 3px; margin: 0; padding: 0; position: relative; z-index: 1; }
        .sheet { display: block; padding: 8px 16px 9px; border: 1px solid var(--line); border-bottom: none; border-radius: 3px 3px 0 0; background: #d3e3cd; color: #35503a; text-decoration: none; font-weight: 600; font-size: 13px; }
        .sheet small { display: block; margin-top: 1px; font-weight: 400; font-size: 11.5px; color: var(--muted); }
        .sheet:hover { background: #e4eede; }
        .sheet.active { background: var(--paper); color: var(--ink); padding-bottom: 10px; margin-bottom: -1px; }
        .sheet.over small { color: #b3261e; }
        .sheet.add { background: transparent; border: 1px dashed #8fae8a; border-bottom: 1px dashed #8fae8a; color: var(--dark); cursor: pointer; font: inherit; font-weight: 600; font-size: 13px; padding: 8px 14px; margin-bottom: 1px; }
        .sheet.add:hover { background: #e4eede; }

        .panel { background: var(--paper); border: 1px solid var(--line); border-radius: 0 3px 3px 3px; padding: 16px; }
        .empty { padding: 36px 10px; text-align: center; color: var(--muted); }

        /* ---------- totals ---------- */
        .figures { display: flex; flex-wrap: wrap; margin: 0 0 14px; border: 1px solid var(--line); }
        .figures > div { flex: 1 1 150px; padding: 10px 16px; border-right: 1px solid var(--line); }
        .figures > div:last-child { border-right: none; }
        .figures dt { margin: 0; font-size: 12px; color: var(--muted); }
        .figures dd { margin: 2px 0 0; font-size: 24px; font-weight: 700; line-height: 1.15; }
        .figures dd small { display: block; margin-top: 2px; font-size: 11.5px; font-weight: 400; color: var(--muted); }
        .figures .neg dd { color: #b3261e; }
        .linkbtn { background: none; border: none; padding: 0; color: var(--dark); text-decoration: underline; font-size: 12px; cursor: pointer; }

        /* ---------- toolbar ---------- */
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; margin-bottom: 12px; }
        .toolbar input[type=text], .toolbar select { height: 34px; padding: 0 10px; border: 1px solid #a9b9a5; border-radius: 3px; font: inherit; background: #fff; color: var(--ink); }
        .toolbar input[type=text] { width: 270px; }
        .toolbar label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
        .toolbar input:focus, .toolbar select:focus { outline: 2px solid #9fcf99; outline-offset: 0; border-color: var(--green); }
        .spacer { flex: 1; }
        .btn { display: inline-block; height: 34px; padding: 0 14px; border: 1px solid var(--green); border-radius: 3px; background: var(--green); color: #fff; font: inherit; font-weight: 600; font-size: 13px; line-height: 32px; text-decoration: none; cursor: pointer; }
        .btn:hover { background: var(--dark); border-color: var(--dark); }
        .btn.plain { background: #fff; color: var(--dark); border-color: #8fae8a; }
        .btn.plain:hover { background: #eef5ea; }
        .btn.danger-outline { background: #fff; color: #a52a22; border-color: #d9a29d; }
        .btn.danger-outline:hover { background: #fff2f1; }
        .savestate { font-size: 12.5px; color: var(--muted); }
        .savestate.err { color: #b3261e; font-weight: 600; }

        /* ---------- table ---------- */
        .table-wrap { border: 1px solid var(--line); }
        #vt { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        #vt th { position: sticky; top: var(--stick); z-index: 5; background: var(--head); color: #fff; padding: 9px 10px; font-size: 12px; font-weight: 600; text-align: left; white-space: nowrap; border: 1px solid #244a28; }
        #vt td { padding: 2px 4px; border: 1px solid #dbe5d8; vertical-align: middle; white-space: nowrap; }
        #vt tbody tr:hover td { background: #f3f9f0; }
        #vt tbody tr.free td { color: #7d897e; }
        #vt td.c { text-align: center; }
        .st { display: inline-block; min-width: 62px; font-size: 12px; font-weight: 600; }
        .st.out { color: #9a5b00; }
        .st.in { color: #7d897e; font-weight: 400; }
        .series { font-weight: 700; font-variant-numeric: tabular-nums; }

        #vt td input { width: 100%; border: 1px solid transparent; background: transparent; padding: 7px 7px; font: inherit; color: inherit; border-radius: 2px; text-align: left; }
        #vt td input:hover { border-color: #c3d0bf; background: #fff; }
        #vt td input:focus { outline: none; border-color: var(--green); background: #fff; color: var(--ink); box-shadow: 0 0 0 2px #cfe6cb; }
        #vt td input.saving { border-color: #e0a526; }
        #vt td input.saved { border-color: var(--green); background: #f1faee; }
        #vt td input.failed { border-color: #c9392f; background: #fff2f1; }
        #vt td input[data-f=customer_name], #vt td input[data-f=redeemed_by], #vt td input[data-f=mop] { text-transform: uppercase; }
        #vt td input[data-f=customer_name]::placeholder, #vt td input[data-f=redeemed_by]::placeholder { text-transform: none; }
        .dwrap { position: relative; display: inline-block; vertical-align: middle; }
        .dwrap input[data-date] { padding-right: 28px; }
        .dwrap .hid { position: absolute; right: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none; padding: 0; border: 0; }
        .dwrap .cal { position: absolute; right: 3px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6a8a68; padding: 4px; font-size: 13px; cursor: pointer; }
        .dwrap .cal:hover { color: var(--dark); }
        #vt td input[data-opts] { cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23667467' stroke-width='1.6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; padding-right: 24px; }

        /* dropdown list for Mode of Payment / Remarks */
        .vm-menu { position: fixed; z-index: 1000; min-width: 180px; max-height: 280px; overflow-y: auto; background: #fff; border: 1px solid #8fae8a; border-radius: 3px; box-shadow: 0 3px 10px rgba(0,0,0,.18); display: none; text-transform: none; }
        .vm-menu.open { display: block; }
        .vm-item { padding: 8px 12px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #edf2eb; }
        .vm-item:last-child { border-bottom: none; }
        .vm-item.active, .vm-item:hover { background: #e4f0df; }
        .vm-item.selected { background: var(--green); color: #fff; }
        .vm-item.clear { color: #a52a22; font-size: 12.5px; }

        /* ---------- pager ---------- */
        .pager { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-top: 12px; font-size: 13px; }
        .pbtns { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
        .pbtns button { height: 30px; padding: 0 12px; border: 1px solid #8fae8a; border-radius: 3px; background: #fff; color: var(--dark); font-size: 13px; cursor: pointer; }
        .pbtns button:hover:not(:disabled) { background: #eef5ea; }
        .pbtns button:disabled { opacity: .4; cursor: default; }
        .pcur input { width: 56px; height: 30px; padding: 0 6px; border: 1px solid #a9b9a5; border-radius: 3px; text-align: center; font: inherit; }

        /* ---------- dialogs ---------- */
        .modal-bg { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.4); align-items: center; justify-content: center; }
        .modal-bg.open { display: flex; }
        .modal { width: 94vw; max-width: 420px; max-height: 92vh; overflow: auto; background: #fff; border: 1px solid #8fae8a; border-radius: 3px; padding: 20px; }
        .modal h2 { margin: 0 0 12px; font-size: 17px; }
        .modal .actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
        .field { margin-bottom: 12px; }
        .field label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: var(--muted); }
        .field input { width: 100%; height: 36px; padding: 0 10px; border: 1px solid #a9b9a5; border-radius: 3px; font: inherit; }
        .field input:focus { outline: 2px solid #9fcf99; border-color: var(--green); }
        .rm-text { margin: 0 0 10px; font-size: 13.5px; line-height: 1.5; }
        .btn.rmgo { background: #c9392f; border-color: #c9392f; }
        .btn.rmgo:hover { background: #a52a22; border-color: #a52a22; }
        .btn.rmgo:disabled { background: #e6b5b1; border-color: #e6b5b1; cursor: not-allowed; }


        /* ---------- cashier layout ---------- */
        body.cashier .page { padding: 16px 22px 40px; }
        .vsec { display: flex; align-items: center; justify-content: space-between; margin: 2px 0 8px; }
        .vsec h2 { margin: 0; font-size: 13px; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); }
        .vpick { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; margin-bottom: 18px; }
        .vcard { display: block; padding: 10px 12px 11px; background: #fff; border: 1px solid var(--line); border-radius: 3px; color: var(--ink); text-decoration: none; }
        .vcard:hover { border-color: var(--green); background: #f6fbf3; }
        .vcard b { display: block; font-size: 13.5px; line-height: 1.25; text-transform: uppercase; }
        .vcard span { display: block; margin-top: 3px; font-size: 12px; color: var(--muted); }
        .vcard i { display: block; height: 4px; margin-top: 8px; background: #e3ebe0; font-style: normal; }
        .vcard i em { display: block; height: 100%; background: #7fb07a; }
        .vcard.active { background: var(--dark); border-color: var(--dark); color: #fff; }
        .vcard.active span { color: #cfe3cb; }
        .vcard.active i { background: rgba(255,255,255,.25); }
        .vcard.active i em { background: #fff; }
        .vcard.over i em { background: #e0584c; }
        .vcard.addcard { display: flex; align-items: center; justify-content: center; border: 2px dashed #8fae8a; background: transparent; color: var(--dark); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; min-height: 70px; }
        .vcard.addcard:hover { background: #eef7ea; }

        .vtitle { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
        .vtitle h1 { margin: 0; font-size: 22px; text-transform: uppercase; }
        .vtitle .spacer { flex: 1; }

        .vstats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .tile { background: #fff; border: 1px solid var(--line); border-top: 5px solid var(--dark); padding: 12px 16px 14px; }
        .tile .lab { font-size: 12px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); }
        .tile .num { margin-top: 4px; font-size: 38px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
        .tile .sub { margin-top: 6px; font-size: 12px; color: var(--muted); }
        .tile.t-out { border-top-color: #d99100; }
        .tile.t-out .num { color: #9a5b00; }
        .tile.t-end { border-top-color: #2e7d32; }
        .tile.t-end .num { color: #1b5e20; }
        .tile.t-end.neg { border-top-color: #c9392f; }
        .tile.t-end.neg .num { color: #b3261e; }
        .tile.t-av { border-top-color: #4a6a8a; }
        .tile.t-av .num { color: #34526f; }
        .meter { height: 6px; margin-top: 9px; background: #e3ebe0; }
        .meter span { display: block; height: 100%; background: #2e7d32; }
        .t-end.neg .meter span { background: #c9392f; }
        @media (max-width: 900px) { .vstats { grid-template-columns: repeat(2, 1fr); } }

        body.cashier .panel { background: transparent; border: none; padding: 0; }
        body.cashier .toolbar { padding: 4px 0; }
        body.cashier #vt td input { padding: 10px 8px; font-size: 14.5px; }
        body.cashier .table-wrap { background: #fff; }
        body.cashier #vt tbody tr:nth-child(even) td { background: #f5faf2; }
        body.cashier #vt tbody tr:hover td { background: #e9f4e4; }

        @media (max-width: 760px) {
            body.admin .page { margin-left: 0; }
            .sidebar { display: none; }
            .page { padding: 14px 10px 30px; }
            .toolbar input[type=text] { width: 100%; }
            .header { padding: 14px 12px; }
            .header h1 { font-size: 16px; }
        }
    </style>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/cashier.css">
</head>
<body class="cashier cx">

<?php require_once __DIR__ . '/includes/cashier_ui.php'; cashier_appbar('voucher', $admin_username); ?>

<div class="page">
    <?php if ($flash): ?><div class="flash <?= h($flash_type) ?>"><?= h($flash) ?></div><?php endif; ?>

    <div class="vsec"><h2>Vouchers</h2></div>
    <div class="vpick">
        <?php foreach ($types as $t):
            $tb = (int)$t['beginning']; $to = (int)$t['total_out']; $left = $tb - $to;
            $pct = $tb > 0 ? min(100, round($to / $tb * 100)) : 0; $isCur = ($t['id'] == $type_id); ?>
            <a class="vcard <?= $isCur ? 'active' : '' ?> <?= $left < 0 ? 'over' : '' ?>" href="?type=<?= (int)$t['id'] ?>">
                <b><?= h($t['name']) ?></b>
                <span <?= $isCur ? 'id="vcCount"' : '' ?>><?= $to ?> out &middot; <?= $left ?> left</span>
                <i><em style="width:<?= $pct ?>%"></em></i>
            </a>
        <?php endforeach; ?>
        <button type="button" class="vcard addcard" onclick="openModal('typeModal')">+ Add voucher</button>
    </div>

<?php if (!$cur): ?>
    <div class="empty">No vouchers yet. Click <b>Add voucher</b> to create one.</div>
<?php else: ?>
    <div class="vtitle">
        <h1><?= h($cur['name']) ?></h1>
        <div class="spacer"></div>
        <button type="button" class="btn plain" onclick="openModal('editModal')"><i class="fas fa-pen"></i> Edit voucher</button>
        <button type="button" class="btn danger-outline" onclick="openModal('rmModal')"><i class="fas fa-trash-alt"></i> Delete voucher</button>
    </div>

    <div class="vstats">
        <div class="tile">
            <div class="lab">Beginning</div>
            <div class="num" id="c_beg"><?= $beginning ?></div>
            <div class="sub">vouchers in stock at the start</div>
        </div>
        <div class="tile t-out">
            <div class="lab">Total out</div>
            <div class="num" id="c_out"><?= $total_out ?></div>
            <div class="sub">given to customers</div>
        </div>
        <div class="tile t-end <?= $ending < 0 ? 'neg' : '' ?>" id="endCard">
            <div class="lab">Ending</div>
            <div class="num" id="c_end"><?= $ending ?></div>
            <div class="sub" id="endNote"><?= $ending < 0 ? 'Over the beginning stock' : 'still available' ?></div>
            <div class="meter"><span id="usedBar" style="width:<?= $beginning > 0 ? min(100, round($total_out / $beginning * 100)) : 0 ?>%"></span></div>
        </div>
        <div class="tile t-av">
            <div class="lab">Availed</div>
            <div class="num" id="c_av"><?= $availed ?></div>
            <div class="sub">with a date availed</div>
        </div>
    </div>

    <div class="panel">
    <div class="toolbar">
        <input type="text" id="q" value="<?= h($q) ?>" placeholder="Search series no., name, note" autocomplete="off" oninput="searchSoon()" onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}">
        <label><input type="checkbox" id="onlyOut" <?= $only_out ? 'checked' : '' ?> onchange="applyFilters()"> Given out only</label>
        <label>Rows
            <select id="perSel" onchange="applyFilters()">
                <?php foreach ($per_opts as $o): ?><option value="<?= $o ?>" <?= $o === $per ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
            </select>
        </label>
        <div class="spacer"></div>
        <span id="saveState" class="savestate">Changes save automatically</span>
        <a class="btn plain" href="voucher_export.php" title="Download every voucher as its own sheet in one Excel file"><i class="fas fa-file-excel"></i> Export to Excel</a>
    </div>

    <div class="table-wrap">
        <table id="vt">
            <thead>
                <tr>
                    <th>Date</th><th>Status</th><th>Series no.</th><th>Customer name</th><th>Redeemed by</th>
                    <th>Mode of payment</th><th>Remarks (sponsorship or sold)</th><th>Date availed</th><th>Note (c/o acctng)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
                <tr class="<?= $r['sold_out'] ? 'out' : 'free' ?>" data-id="<?= $rid ?>">
                    <td><div class="dwrap"><input type="text" data-f="date_sold" data-date="1" data-iso="<?= h($r['date_sold']) ?>" value="<?= h(fmt_date($r['date_sold'])) ?>" placeholder="Pick a date" autocomplete="off"><button type="button" class="cal" tabindex="-1" title="Pick from calendar"><i class="fas fa-calendar-alt"></i></button><input type="date" class="hid" tabindex="-1" value="<?= h($r['date_sold']) ?>"></div></td>
                    <td class="c"><span class="st <?= $r['sold_out'] ? 'out' : 'in' ?>"><?= $r['sold_out'] ? 'Out' : 'In stock' ?></span></td>
                    <td class="c"><span class="series"><?= str_pad((string)$r['series_no'], 3, '0', STR_PAD_LEFT) ?></span></td>
                    <td><input type="text" data-f="customer_name" value="<?= h($r['customer_name']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="redeemed_by" value="<?= h($r['redeemed_by']) ?>" placeholder="Who redeemed it" autocomplete="off"></td>
                    <td><input type="text" data-f="mop" data-opts="mop" value="<?= h($r['mop']) ?>" autocomplete="off"></td>
                    <td><input type="text" data-f="remarks" data-opts="remarks" value="<?= h($r['remarks']) ?>" autocomplete="off"></td>
                    <td><div class="dwrap"><input type="text" data-f="date_availed" data-date="1" data-iso="<?= h($r['date_availed']) ?>" value="<?= h(fmt_date($r['date_availed'])) ?>" placeholder="Pick a date" autocomplete="off"><button type="button" class="cal" tabindex="-1" title="Pick from calendar"><i class="fas fa-calendar-alt"></i></button><input type="date" class="hid" tabindex="-1" value="<?= h($r['date_availed']) ?>"></div></td>
                    <td><input type="text" data-f="note" value="<?= h($r['note']) ?>" autocomplete="off"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows && !$total_rows): ?><div class="empty">No series rows yet. Click <b>Edit voucher</b> and enter the number of vouchers.</div>
        <?php elseif (!$rows): ?><div class="empty">No vouchers match your search.</div><?php endif; ?>
    </div>

    <?php if ($total_rows): $from = $match_rows ? $offset + 1 : 0; $to = $offset + count($rows); ?>
    <div class="pager">
        <span class="pinfo">Showing <b><?= $from ?>&ndash;<?= $to ?></b> of <b><?= $match_rows ?></b><?= $match_rows !== $total_rows ? " (filtered from $total_rows)" : '' ?></span>
        <div class="pbtns">
            <button type="button" onclick="goPage(1)" <?= $pg <= 1 ? 'disabled' : '' ?> title="First">&laquo;</button>
            <button type="button" onclick="goPage(<?= $pg - 1 ?>)" <?= $pg <= 1 ? 'disabled' : '' ?>>&lsaquo; Prev</button>
            <span class="pcur">Page <input type="number" id="pgIn" min="1" max="<?= $pages ?>" value="<?= $pg ?>" onkeydown="if(event.key==='Enter'){goPage(this.value);}"> of <?= $pages ?></span>
            <button type="button" onclick="goPage(<?= $pg + 1 ?>)" <?= $pg >= $pages ? 'disabled' : '' ?>>Next &rsaquo;</button>
            <button type="button" onclick="goPage(<?= $pages ?>)" <?= $pg >= $pages ? 'disabled' : '' ?> title="Last">&raquo;</button>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- /panel -->

    <!-- Edit voucher -->
    <div class="modal-bg" id="editModal">
        <form class="modal" method="POST">
            <h2>Edit voucher</h2>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="edit_type">
            <div class="field"><label>Voucher name</label><input type="text" name="name" value="<?= h($cur['name']) ?>" required></div>
            <div class="field"><label>Beginning (number of vouchers)</label><input type="number" min="0" max="5000" name="beginning" value="<?= $beginning ?>" required></div>
            <div class="actions">
                <button type="button" class="btn plain" onclick="closeModal('editModal')">Cancel</button>
                <button class="btn">Save changes</button>
            </div>
        </form>
    </div>

    <!-- Delete voucher -->
    <div class="modal-bg" id="rmModal">
        <form class="modal" method="POST" onsubmit="return rmReady();">
            <h2>Delete this voucher?</h2>
            <p class="rm-text">This permanently deletes <b><?= h($cur['name']) ?></b> and all <b><?= $total_rows ?> entries</b> in it (customers, dates, notes). It cannot be undone.</p>
            <p class="rm-text"><a href="voucher_export.php">Download an Excel backup first</a></p>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="delete_type">
            <div class="field"><label>Type the voucher name to confirm</label>
                <input type="text" name="confirm_name" id="rmName" autocomplete="off" placeholder="<?= h($cur['name']) ?>" oninput="rmCheck()"></div>
            <div class="actions">
                <button type="button" class="btn plain" onclick="closeModal('rmModal')">Cancel</button>
                <button class="btn rmgo" id="rmGo" disabled>Delete voucher</button>
            </div>
        </form>
    </div>
<?php endif; ?>

    <!-- Add voucher -->
    <div class="modal-bg" id="typeModal">
        <form class="modal" method="POST">
            <h2>Add voucher</h2>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="add_type">
            <div class="field"><label>Voucher name</label><input type="text" name="name" required></div>
            <div class="field"><label>Beginning / total vouchers (creates series 1 up to this number)</label><input type="number" min="0" max="5000" name="beginning" value="80" required></div>
            <div class="actions">
                <button type="button" class="btn plain" onclick="closeModal('typeModal')">Cancel</button>
                <button class="btn">Create</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
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

        /* ---------- page header ---------- */
        .pagehead { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 4px 0 22px; padding: 20px 24px; border-radius: 18px; color: #fff;
                    background: radial-gradient(circle at 92% -20%, rgba(255,255,255,.18), transparent 45%), linear-gradient(120deg, #14361c 0%, #1b5e20 55%, #2e7d32 100%);
                    box-shadow: 0 10px 28px rgba(27,94,32,.28); }
        .ph-badge { width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff;
                    background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); transform: rotate(-6deg); }
        .ph-title { font-size: 26px; font-weight: 800; letter-spacing: .2px; line-height: 1.1; }
        .ph-sub { margin-top: 5px; font-size: 13px; color: #cfe9d3; }
        .ph-stats { margin-left: auto; display: flex; gap: 10px; flex-wrap: wrap; }
        .ph-stat { min-width: 92px; padding: 10px 16px; border-radius: 12px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); text-align: center; }
        .ph-stat b { display: block; font-size: 22px; line-height: 1.1; }
        .ph-stat span { font-size: 11px; letter-spacing: .5px; color: #cfe9d3; text-transform: uppercase; }

        /* ---------- voucher tabs ---------- */
        .tabs { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
        .tab { display: flex; flex-direction: column; gap: 7px; min-width: 190px; max-width: 260px; padding: 13px 16px; border-radius: 14px; background: #fff; border: 1px solid #d7e8da;
               color: #1b3a22; text-decoration: none; text-align: left; box-shadow: 0 1px 3px rgba(20,54,28,.08); transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
        .tab:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(20,54,28,.14); border-color: #2e7d32; }
        .tab-top { display: flex; align-items: center; gap: 9px; }
        .tab-top i { width: 28px; height: 28px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #2e7d32; background: #e6f4e8; flex: none; }
        .tab-name { font-size: 13px; font-weight: 800; letter-spacing: .3px; line-height: 1.2; }
        .tab-meta { font-size: 12px; color: #5c6b5e; }
        .tab-meta b { color: #1b3a22; font-size: 13px; }
        .tab-bar { display: block; height: 6px; border-radius: 999px; background: #e3efe5; overflow: hidden; }
        .tab-bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #43a047, #2e7d32); }
        .tab.over .tab-bar span { background: linear-gradient(90deg, #ef6b78, #e0364a); }
        .tab.active { background: linear-gradient(135deg, #1b5e20, #2e7d32); border-color: #1b5e20; color: #fff; box-shadow: 0 10px 22px rgba(27,94,32,.35); }
        .tab.active .tab-top i { background: rgba(255,255,255,.2); color: #fff; }
        .tab.active .tab-meta, .tab.active .tab-meta b { color: #e3f3e6; }
        .tab.active .tab-bar { background: rgba(255,255,255,.25); }
        .tab.active .tab-bar span { background: #fff; }
        .tab.active.over .tab-bar span { background: #ffd0d4; }
        .tab.add { flex-direction: row; align-items: center; justify-content: center; gap: 10px; min-width: 150px; background: transparent; border: 2px dashed #7fb986; color: #1b5e20; cursor: pointer; box-shadow: none; }
        .tab.add:hover { background: #e6f4e8; border-color: #2e7d32; }
        .tab.add .plus { width: 28px; height: 28px; border-radius: 50%; background: #2e7d32; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; }

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
        td input[data-f=customer_name], td input[data-f=redeemed_by], td input[data-f=mop] { text-transform: uppercase; }
        td input[data-f=customer_name]::placeholder, td input[data-f=redeemed_by]::placeholder { text-transform: none; }
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
        .main-content a.exportbtn { text-decoration: none; display: inline-block; background: #fff !important; color: #1b5e20 !important; border: 1px solid #2e7d32; border-radius: 8px; padding: 10px 16px; font-weight: 700; font-size: 14px; }
        .main-content a.exportbtn:hover { background: #e6f4e8 !important; }
        .main-content .btn.removebtn { background: #fff !important; color: #c62839 !important; border: 1px solid #e8a3ab !important; box-shadow: none !important; }
        .main-content .btn.removebtn:hover { background: #fdeced !important; border-color: #e0364a !important; }
        .rm-icon { width: 58px; height: 58px; margin: 0 auto 10px; border-radius: 50%; background: #fdeced; color: #e0364a; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .rm-text { font-size: 13.5px; color: #3b4a3e; line-height: 1.5; text-align: center; text-transform: none; margin: 6px 0; }
        .rm-text a { color: #1b5e20; font-weight: 700; }
        .modal .btn.rmgo { background: #e0364a !important; color: #fff !important; }
        .modal .btn.rmgo:hover { background: #c62839 !important; }
        .modal .btn.rmgo:disabled { background: #f1b9bf !important; cursor: not-allowed; transform: none; }
        .perpage { font-size: 13px; font-weight: 700; color: #1b5e20; display: inline-flex; align-items: center; gap: 6px; }
        .perpage select { padding: 8px 10px; border: 1px solid #cfe3d3; border-radius: 8px; font-size: 14px; font-weight: 700; color: #1b5e20; background: #fff; }
        .pager { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin: 14px 0 6px; font-size: 13px; color: #1b5e20; }
        .pbtns { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .pbtns button { padding: 8px 14px; border: 1px solid #2e7d32; border-radius: 8px; background: #fff; color: #1b5e20; font-weight: 700; cursor: pointer; }
        .pbtns button:hover:not(:disabled) { background: #e6f4e8; }
        .pbtns button:disabled { opacity: .4; cursor: default; }
        .pcur { font-weight: 700; padding: 0 6px; }
        .pcur input { width: 60px; padding: 7px; border: 1px solid #cfe3d3; border-radius: 8px; text-align: center; font-size: 14px; }
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
                .main-content .tab.add { background: transparent !important; color: #1b5e20 !important; border: 2px dashed #7fb986 !important; box-shadow: none !important; padding: 13px 16px !important; font-size: 13px !important; letter-spacing: .3px; transform: none; }
        .main-content .tab.add:hover { background: #e6f4e8 !important; border-color: #2e7d32 !important; }
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
    <div class="pagehead">
        <div class="ph-badge"><i class="fas fa-ticket-alt"></i></div>
        <div>
            <div class="ph-title">Voucher Monitoring</div>
            <div class="ph-sub">Track every voucher from stock to customer to redeemed.</div>
        </div>
        <div class="ph-stats">
            <?php $all_out = 0; $all_beg = 0; foreach ($types as $tt) { $all_out += (int)$tt['total_out']; $all_beg += (int)$tt['beginning']; } ?>
            <div class="ph-stat"><b><?= count($types) ?></b><span>Vouchers</span></div>
            <div class="ph-stat"><b><?= $all_out ?></b><span>Given out</span></div>
            <div class="ph-stat"><b><?= $all_beg - $all_out ?></b><span>Left in stock</span></div>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash <?= h($flash_type) ?>"><?= h($flash) ?></div><?php endif; ?>

    <div class="tabs">
        <?php foreach ($types as $t):
            $tb = (int)$t['beginning']; $to = (int)$t['total_out']; $left = $tb - $to;
            $pct = $tb > 0 ? min(100, round($to / $tb * 100)) : 0; ?>
            <a class="tab <?= $t['id'] == $type_id ? 'active' : '' ?> <?= $left < 0 ? 'over' : '' ?>" href="?type=<?= (int)$t['id'] ?>">
                <span class="tab-top"><i class="fas fa-ticket-alt"></i><span class="tab-name"><?= h($t['name']) ?></span></span>
                <span class="tab-meta"><b><?= $to ?></b> out &middot; <b><?= $left ?></b> left</span>
                <span class="tab-bar"><span style="width:<?= $pct ?>%"></span></span>
            </a>
        <?php endforeach; ?>
        <button type="button" class="tab add" onclick="openModal('typeModal')">
            <span class="plus"><i class="fas fa-plus"></i></span>
            <span class="tab-name">New voucher</span>
        </button>
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
        <div class="search"><i class="fas fa-search"></i><input type="text" id="q" value="<?= h($q) ?>" placeholder="Search series no., name, note..." autocomplete="off" oninput="searchSoon()" onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}"></div>
        <label style="font-size:13px;font-weight:700;color:#1b5e20;"><input type="checkbox" id="onlyOut" <?= $only_out ? 'checked' : '' ?> onchange="applyFilters()"> Show given out only</label>
        <label class="perpage">Show
            <select id="perSel" onchange="applyFilters()">
                <?php foreach ($per_opts as $o): ?><option value="<?= $o ?>" <?= $o === $per ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
            </select> rows
        </label>
        <div class="spacer"></div>
        <a class="btn exportbtn" href="voucher_export.php" title="Download every voucher as its own sheet in one Excel file"><i class="fas fa-file-excel"></i> Export to Excel</a>
        <button type="button" class="btn removebtn" onclick="openModal('rmModal')"><i class="fas fa-trash-alt"></i> Remove voucher</button>
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
        <?php if (!$rows && !$total_rows): ?><div class="empty">No series rows yet. Click <b>Change</b> under Beginning and enter the number of vouchers.</div>
        <?php elseif (!$rows): ?><div class="empty">No vouchers match your search.</div><?php endif; ?>
    </div>

    <?php if ($total_rows): $from = $match_rows ? $offset + 1 : 0; $to = $offset + count($rows); ?>
    <div class="pager">
        <span class="pinfo">Showing <b><?= $from ?>–<?= $to ?></b> of <b><?= $match_rows ?></b><?= $match_rows !== $total_rows ? " (filtered from $total_rows)" : '' ?></span>
        <div class="pbtns">
            <button type="button" onclick="goPage(1)" <?= $pg <= 1 ? 'disabled' : '' ?> title="First">&laquo;</button>
            <button type="button" onclick="goPage(<?= $pg - 1 ?>)" <?= $pg <= 1 ? 'disabled' : '' ?>>&lsaquo; Prev</button>
            <span class="pcur">Page <input type="number" id="pgIn" min="1" max="<?= $pages ?>" value="<?= $pg ?>" onkeydown="if(event.key==='Enter'){goPage(this.value);}"> of <?= $pages ?></span>
            <button type="button" onclick="goPage(<?= $pg + 1 ?>)" <?= $pg >= $pages ? 'disabled' : '' ?>>Next &rsaquo;</button>
            <button type="button" onclick="goPage(<?= $pages ?>)" <?= $pg >= $pages ? 'disabled' : '' ?> title="Last">&raquo;</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Remove voucher -->
    <div class="modal-bg" id="rmModal">
        <form class="modal" method="POST" style="max-width:460px" onsubmit="return rmReady();">
            <div class="rm-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 style="text-align:center">Remove this voucher?</h2>
            <p class="rm-text">You are about to permanently remove <b><?= h($cur['name']) ?></b> and all
                <b><?= $total_rows ?> entries</b> in it (customers, dates, notes). This cannot be undone.</p>
            <p class="rm-text"><a href="voucher_export.php">Download an Excel backup first</a></p>
            <input type="hidden" name="token" value="<?= h($_SESSION['vm_token']) ?>">
            <input type="hidden" name="type_id" value="<?= $type_id ?>">
            <input type="hidden" name="action" value="delete_type">
            <div class="field"><label>TYPE THE VOUCHER NAME TO CONFIRM</label>
                <input type="text" name="confirm_name" id="rmName" autocomplete="off" placeholder="<?= h($cur['name']) ?>" oninput="rmCheck()"></div>
            <div class="actions">
                <button type="button" class="btn ghost" onclick="closeModal('rmModal')">Cancel</button>
                <button class="btn rmgo" id="rmGo" disabled>Remove voucher</button>
            </div>
        </form>
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

<?php endif; ?>
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
    inp.dataset.sent = inp.value;
    inp.classList.remove('saved','failed'); inp.classList.add('saving');
    setState('Saving...');
    fetch('voucher_monitoring.php', {method:'POST', keepalive:true, body:fd, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
      .then(r => r.json().then(j => ({ok:r.ok && j.ok, j})))
      .then(({ok, j}) => {
        inp.classList.remove('saving');
        if (!ok) throw new Error(j.error || 'Could not save');
        if (document.activeElement !== inp && (inp.dataset.f === 'customer_name' || inp.dataset.f === 'redeemed_by' || inp.dataset.f === 'mop')) { inp.value = j.value || ''; }
        if (inp.dataset.date) { inp.dataset.iso = j.value || ''; inp.dataset.last = j.value || ''; if (document.activeElement !== inp) { inp.value = longDate(j.value || ''); } fit(inp); }
        else { inp.dataset.last = (j.value || ''); fit(inp); }
        inp.classList.add('saved'); setTimeout(() => inp.classList.remove('saved'), 1200);
        tr.classList.toggle('out', j.sold_out === 1); tr.classList.toggle('free', j.sold_out !== 1);
        const pill = tr.querySelector('.pill, .st');
        if (pill.classList.contains('st')) { pill.className = 'st ' + (j.sold_out ? 'out' : 'in'); pill.textContent = j.sold_out ? 'Out' : 'In stock'; }
        else { pill.className = 'pill ' + (j.sold_out ? 'out' : 'free'); pill.textContent = j.sold_out ? 'OUT' : 'IN STOCK'; }
        document.getElementById('c_beg').textContent = j.beginning;
        document.getElementById('c_out').textContent = j.total_out;
        document.getElementById('c_end').textContent = j.ending;
        document.getElementById('c_av').textContent = j.availed;
        document.getElementById('endCard').classList.toggle('neg', j.ending < 0);
        const ub = document.getElementById('usedBar'); if (ub) ub.style.width = (j.beginning > 0 ? Math.min(100, Math.round(j.total_out / j.beginning * 100)) : 0) + '%';
        const vc = document.getElementById('vcCount'); if (vc) vc.innerHTML = j.total_out + ' out &middot; ' + j.ending + ' left';
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
        if (inp.classList.contains('saving') && inp.dataset.sent === inp.value) return;   // already being saved
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
    inp.addEventListener('input', () => {         // typing only resizes the cell; saving happens when you finish
        fit(inp);
        setState('Press Enter or click away to save');
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

const RM_NAME = <?= json_encode($cur ? mb_strtoupper(trim($cur['name'])) : '') ?>;
function rmCheck(){ document.getElementById('rmGo').disabled = document.getElementById('rmName').value.trim().toUpperCase() !== RM_NAME; }
function rmReady(){ return document.getElementById('rmName').value.trim().toUpperCase() === RM_NAME; }

// Search / filter / paging all run on the server so only one page of rows is loaded.
let searchTimer = null;
function buildUrl(pg){
    const u = new URLSearchParams();
    u.set('type', <?= (int)$type_id ?>);
    u.set('per', document.getElementById('perSel').value);
    const q = document.getElementById('q').value.trim();
    if (q) u.set('q', q);
    if (document.getElementById('onlyOut').checked) u.set('out', '1');
    if (pg > 1) u.set('pg', pg);
    return 'voucher_monitoring.php?' + u.toString();
}
function applyFilters(){ location.href = buildUrl(1); }
function searchSoon(){ clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 600); }
function goPage(n){ n = parseInt(n, 10); if (!n || n < 1) n = 1; location.href = buildUrl(n); }
// keep the cursor at the end of the search box after the page reloads
(function(){ const q = document.getElementById('q'); if (q && q.value) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); } })();
</script>
</body>
</html>