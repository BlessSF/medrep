<?php
// POST /api/employees/transaction/add.php
// body: { name, company, amount, type, payment_method, card, remarks, transfer_name, transfer_company }
// type: deposit | dinein | takeout | giftcard | cashout | interest | transfer
require_once __DIR__ . '/../../../includes/api_helpers.php';
$cashier_name = require_login();
$branch = current_branch();

$data = json_input();
$name = trim($data['name'] ?? '');
$company = trim($data['company'] ?? '');
$amount = (float)($data['amount'] ?? 0);
$type = trim($data['type'] ?? '');
$payment_method = trim($data['payment_method'] ?? 'na');
$card = trim($data['card'] ?? '');
$remarks = trim($data['remarks'] ?? '');

if ($name === '' || $amount <= 0) {
    json_error('Invalid transaction amount or missing medrep name.');
}

if (is_blocked($conn, $name, $branch)) {
    json_error("🚫 Transaction denied! {$name} is currently blocked.");
}

// ---- Transfer: two linked rows, sender debit + receiver credit ----
if ($type === 'transfer') {
    $transfer_name = trim($data['transfer_name'] ?? '');
    $transfer_company = trim($data['transfer_company'] ?? '');

    if ($transfer_name === '') json_error('Please choose who to transfer to.');
    if (strcasecmp($transfer_name, $name) === 0) json_error('Cannot transfer to the same medrep.');

    $transfer_remarks = $remarks !== '' ? $remarks : 'Transfer';

    $insertSender = $conn->prepare("INSERT INTO employees (name, company, sender_amount, receiver_name, remarks, cashier, branch, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $insertSender->bind_param('ssdssss', $name, $company, $amount, $transfer_name, $transfer_remarks, $cashier_name, $branch);

    $insertReceiver = $conn->prepare("INSERT INTO employees (name, company, receiver_amount, sender_name, remarks, cashier, branch, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $insertReceiver->bind_param('ssdssss', $transfer_name, $transfer_company, $amount, $name, $transfer_remarks, $cashier_name, $branch);

    if ($insertSender->execute() && $insertReceiver->execute()) {
        json_success(['message' => '✅ Transfer successful!']);
    }
    json_error('Error processing transfer: ' . $conn->error, 500);
}

$allowed_types = ['dinein', 'takeout', 'deposit', 'cashout', 'interest', 'giftcard', 'buygiftcard'];
if (!in_array($type, $allowed_types, true)) json_error('Invalid transaction type.');

$dinein = $takeout = $deposit = $cashout = $interest = $giftcard = 0;
switch ($type) {
    case 'dinein': $dinein = $amount; break;
    case 'takeout': $takeout = $amount; break;
    case 'deposit': $deposit = $amount; break;
    case 'cashout': $cashout = $amount; break;
    case 'interest': $interest = $amount; break;
    case 'giftcard': case 'buygiftcard': $giftcard = $amount; break;
}

// $5000 balance cap check (only applies to dinein/takeout/giftcard paid 'na', mirroring submit.php)
if ($type !== 'deposit' && $payment_method === 'na') {
    $bal = compute_balance($conn, $name, $branch);
    $new_total_payable = $bal['total_payable'] + $dinein + $takeout + $giftcard;
    $new_balance = $bal['total_deposit'] - ($new_total_payable + $bal['total_cashout']) + $bal['received'] - $bal['sent'];
    if ($new_balance > 5000) {
        json_error("⚠️ Transaction denied! {$name}'s balance cannot exceed 5000.");
    }
}

// Gift card balance check
if (in_array($type, ['dinein', 'takeout'], true) && $payment_method === 'giftcard') {
    $bal = compute_balance($conn, $name, $branch);
    if (($dinein + $takeout) > $bal['giftcard_balance']) {
        json_error('❌ Insufficient gift card balance for ' . $name . '. Available: ' . number_format($bal['giftcard_balance'], 2));
    }
}

$stmt = $conn->prepare("INSERT INTO employees
    (name, company, dinein, takeout, deposit, cashout, interest, giftcard, remarks, payment_method, card, cashier, branch, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param('ssddddddsssss', $name, $company, $dinein, $takeout, $deposit, $cashout, $interest, $giftcard, $remarks, $payment_method, $card, $cashier_name, $branch);

if (!$stmt->execute()) {
    json_error('Error inserting record: ' . $stmt->error, 500);
}
$stmt->close();

json_success(['message' => '✅ ' . ucfirst($type) . ' of ' . number_format($amount, 2) . ' added for ' . $name . '.']);