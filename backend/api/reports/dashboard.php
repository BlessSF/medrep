<?php
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();

$stmt = $conn->prepare("SELECT SUM(giftcard) AS v FROM employees WHERE branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_giftcard_balance = (float)($row['v'] ?? 0);

$stmt = $conn->prepare("SELECT SUM(dinein + takeout) AS v FROM employees WHERE payment_method = 'giftcard' AND branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_used_giftcard = (float)($row['v'] ?? 0);
$giftcard_balance = $total_giftcard_balance - $total_used_giftcard;

$stmt = $conn->prepare("SELECT SUM(deposit + receiver_amount) AS v FROM employees WHERE branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_deposit = (float)($row['v'] ?? 0);

$stmt = $conn->prepare("SELECT SUM(dinein) dinein, SUM(takeout) takeout, SUM(giftcard) giftcard FROM employees WHERE branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_dinein = (float)($row['dinein'] ?? 0);
$total_takeout = (float)($row['takeout'] ?? 0);
$total_giftcard = (float)($row['giftcard'] ?? 0);
$total_payable = $total_dinein + $total_takeout + $total_giftcard;

$stmt = $conn->prepare("SELECT SUM(cashout + interest) AS v FROM employees WHERE branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_cashout = (float)($row['v'] ?? 0);

$total_balance = $total_deposit - ($total_payable + $total_cashout);

$stmt = $conn->prepare("SELECT id, name, company, deposit, dinein, takeout, cashout, interest, giftcard,
        sender_amount, receiver_amount, sender_name, receiver_name, remarks, payment_method, created_at
    FROM employees WHERE branch = ? ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param('s', $branch); $stmt->execute();
$latest = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT name) AS c FROM employees WHERE branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$medrep_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT company) AS c FROM employees WHERE company IS NOT NULL AND company <> '' AND branch = ?");
$stmt->bind_param('s', $branch); $stmt->execute();
$company_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();

json_success([
    'total_giftcard_balance' => $giftcard_balance,
    'total_deposit' => $total_deposit,
    'total_payable' => $total_payable,
    'total_cashout' => $total_cashout,
    'total_balance' => $total_balance,
    'medrep_count' => $medrep_count,
    'company_count' => $company_count,
    'latest_transactions' => $latest,
]);