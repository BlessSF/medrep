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

// ------------------------------------------------------------
// Quick-add a single transaction for a medrep straight from their
// profile page, instead of having to go back to the cashier screen.
// One column per type, matching the columns already on the
// transaction table: DINE IN, TAKEOUT, DEPOSIT, CASHOUT, INTEREST,
// GIFT CARD.
// ------------------------------------------------------------
$allowed_types = ['dinein', 'takeout', 'deposit', 'cashout', 'interest', 'giftcard'];

$name        = trim($_POST['name'] ?? '');
$company     = trim($_POST['company'] ?? '');
$type        = trim($_POST['type'] ?? '');
$amount      = (float) ($_POST['amount'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? 'na');
$card        = trim($_POST['card'] ?? '');
$remarks     = trim($_POST['remarks'] ?? '');
$redirect_month = $_POST['redirect_month'] ?? '';
$redirect_year  = $_POST['redirect_year'] ?? '';

$redirect = "customer_profile.php?name=" . urlencode($name)
    . ($redirect_month !== '' ? "&month=" . urlencode($redirect_month) : "")
    . ($redirect_year !== '' ? "&year=" . urlencode($redirect_year) : "");

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the profile page can refresh its transaction table in
// place without a full reload.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if ($name === '' || $company === '' || !in_array($type, $allowed_types, true) || $amount <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Couldn't add transaction: missing or invalid data."]);
        exit();
    }
    $_SESSION['error_message'] = "Couldn't add transaction: missing or invalid data.";
    header("Location: $redirect");
    exit();
}

$dinein = $takeout = $deposit = $cashout = $interest = $giftcard = 0;
switch ($type) {
    case 'dinein':   $dinein = $amount; break;
    case 'takeout':  $takeout = $amount; break;
    case 'deposit':  $deposit = $amount; break;
    case 'cashout':  $cashout = $amount; break;
    case 'interest': $interest = $amount; break;
    case 'giftcard': $giftcard = $amount; break;
}

$cashier_name = $_SESSION['username'];

$stmt = $conn->prepare("INSERT INTO employees
    (name, company, dinein, takeout, deposit, cashout, interest, giftcard, remarks, payment_method, card, cashier, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param(
    "ssddddddssss",
    $name,
    $company,
    $dinein,
    $takeout,
    $deposit,
    $cashout,
    $interest,
    $giftcard,
    $remarks,
    $payment_method,
    $card,
    $cashier_name
);

if ($stmt->execute()) {
    $success_message = "✅ " . ucfirst($type) . " of ₱" . number_format($amount, 2) . " added for $name.";
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $success_message]);
        $stmt->close();
        $conn->close();
        exit();
    }
    $_SESSION['success_message'] = $success_message;
} else {
    $error_message = "Could not add transaction: " . $stmt->error;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error_message]);
        $stmt->close();
        $conn->close();
        exit();
    }
    $_SESSION['error_message'] = $error_message;
}
$stmt->close();
$conn->close();

header("Location: $redirect");
exit();
