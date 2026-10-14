<?php
session_start();

require_once __DIR__ . '/includes/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Whitelist of columns that can be edited inline from a table, and their type.
// 'd' = decimal/number, 's' = short text
$allowed_fields = [
    'dinein'        => 'd',
    'takeout'       => 'd',
    'deposit'       => 'd',
    'cashout'       => 'd',
    'interest'      => 'd',
    'giftcard'      => 'd',
    'sender_amount' => 'd',
    'remarks'       => 's',
    'card'          => 's',
    'created_at'    => 'date',
];

$id    = intval($_POST['id'] ?? 0);
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? '';

if ($id <= 0 || !array_key_exists($field, $allowed_fields)) {
    echo json_encode(['success' => false, 'error' => 'Invalid field or record id']);
    exit;
}

$type = $allowed_fields[$field];
$display = null;

if ($type === 'd') {
    $clean = str_replace(['₱', ',', ' '], '', $value);
    if ($clean !== '' && !is_numeric($clean)) {
        echo json_encode(['success' => false, 'error' => 'Value must be a number']);
        exit;
    }
    $value = $clean === '' ? 0 : round((float)$clean, 2);
    $display = $value > 0 ? '₱' . number_format($value, 2) : '';
} elseif ($type === 'date') {
    // Accepts whatever text was in the cell (e.g. "20-Aug-2026") and
    // re-parses it into a real date. The existing time-of-day is kept as-is
    // -- only the calendar date moves -- so records still sort correctly
    // against same-day transactions.
    // Contenteditable cells can quietly insert non-breaking spaces (\xC2\xA0)
    // and stray control characters when text is retyped in the browser;
    // strtotime() fails on those even though the date "looks" normal, so
    // normalize whitespace before parsing.
    $clean_date_str = preg_replace('/[\x{00A0}\s]+/u', ' ', trim($value));
    $parsed = strtotime($clean_date_str);
    if ($parsed === false) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid date (e.g. 20-Aug-2026).']);
        exit;
    }

    $time_stmt = $conn->prepare("SELECT created_at FROM employees WHERE id = ?");
    $time_stmt->bind_param('i', $id);
    $time_stmt->execute();
    $existing = $time_stmt->get_result()->fetch_assoc();
    $time_stmt->close();

    $time_part = '00:00:00';
    if ($existing && !empty($existing['created_at'])) {
        $time_part = date('H:i:s', strtotime($existing['created_at']));
    }

    $value = date('Y-m-d', $parsed) . ' ' . $time_part;
    $display = date('d-M-Y', $parsed);
} else {
    $value = trim($value);
    if (mb_strlen($value) > 255) {
        echo json_encode(['success' => false, 'error' => 'Value too long']);
        exit;
    }
    $display = strtoupper($value);
}

// Column name comes only from the whitelist keys above, never from raw input,
// so it's safe to interpolate into the query.
$sql = "UPDATE employees SET `$field` = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Query preparation failed']);
    exit;
}

if ($type === 'd') {
    $stmt->bind_param('di', $value, $id);
} else {
    $stmt->bind_param('si', $value, $id);
}

$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    // Best-effort audit log, matching the pattern used by edit.php
    $username = $_SESSION['username'];
    $action = 'Edited Transaction (inline)';
    $details = "Updated record #{$id}: {$field} = " . (is_numeric($value) ? number_format((float)$value, 2) : $value);
    $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
    if ($log_stmt) {
        $log_stmt->bind_param('sss', $action, $username, $details);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

echo json_encode(['success' => $ok, 'display' => $display]);
$conn->close();
