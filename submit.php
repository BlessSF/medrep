<?php 
session_start();

// DB connection
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['username'])) {
    $_SESSION['error_message'] = "Cashier information not available.";
    header("Location: staff.php");
    exit();
}

$session_username = $_SESSION['username'];

$stmt = $conn->prepare("SELECT username FROM users WHERE LOWER(username) = LOWER(?)");
$stmt->bind_param("s", $session_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user_row = $result->fetch_assoc();
    $cashier_name = $user_row['username'];
} else {
    $cashier_name = $session_username;
}
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? '');
    $card = trim($_POST['card'] ?? ''); // <- Card option
    $remarks = trim($_POST['remarks'] ?? '');
    $cashier_name = $_SESSION['username'] ?? $cashier_name;

    if ($amount <= 0) {
        $_SESSION['error_message'] = "Invalid transaction amount.";
        header("Location: staff.php");
        exit();
    }

    // ------------------------------------------------------------
    // Transfer: moves balance from the medrep in "Name" to another
    // medrep, instead of adding a dinein/takeout/deposit/giftcard
    // amount. Handled separately (two linked rows -- one debiting the
    // sender, one crediting the receiver) since it doesn't fit the
    // single-column switch below. Mirrors process_transfer.php's
    // insert pattern, but also stamps the cashier + timestamp (like
    // every other transaction here does) and re-uses the same
    // block-check as normal transactions.
    // ------------------------------------------------------------
    if ($transaction_type === 'transfer') {
        $transfer_name = trim($_POST['transfer_name'] ?? '');
        $transfer_company = trim($_POST['transfer_company'] ?? '');

        if ($transfer_name === '') {
            $_SESSION['error_message'] = "Please choose who to transfer to.";
            header("Location: staff.php");
            exit();
        }

        if (strcasecmp($transfer_name, $name) === 0) {
            $_SESSION['error_message'] = "Cannot transfer to the same medrep.";
            header("Location: staff.php");
            exit();
        }

        $block_check = $conn->prepare("SELECT block FROM employees WHERE UPPER(name) = UPPER(?) ORDER BY id DESC LIMIT 1");
        $block_check->bind_param("s", $name);
        $block_check->execute();
        $block_row = $block_check->get_result()->fetch_assoc();
        $block_check->close();

        if ($block_row && strtolower($block_row['block']) === 'block') {
            $_SESSION['error_message'] = "🚫 Transaction denied! {$name} is currently blocked.";
            header("Location: staff.php");
            exit();
        }

        $transfer_remarks = $remarks !== '' ? $remarks : 'Transfer';

        $insertSender = $conn->prepare("INSERT INTO employees (name, company, sender_amount, receiver_name, remarks, cashier, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $insertSender->bind_param("ssdsss", $name, $company, $amount, $transfer_name, $transfer_remarks, $cashier_name);

        $insertReceiver = $conn->prepare("INSERT INTO employees (name, company, receiver_amount, sender_name, remarks, cashier, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $insertReceiver->bind_param("ssdsss", $transfer_name, $transfer_company, $amount, $name, $transfer_remarks, $cashier_name);

        if ($insertSender->execute() && $insertReceiver->execute()) {
            $_SESSION['success_message'] = "✅ Transfer successful!";
        } else {
            $_SESSION['error_message'] = "Error processing transfer: " . $conn->error;
        }

        $insertSender->close();
        $insertReceiver->close();
        $conn->close();

        header("Location: staff.php");
        exit();
    }

    $deposit = $dinein = $takeout = $giftcard = 0;
    switch ($transaction_type) {
        case "deposit": $deposit = $amount; break;
        case "dinein": $dinein = $amount; break;
        case "takeout": $takeout = $amount; break;
        case "buygiftcard": $giftcard = $amount; break;
        default:
            $_SESSION['error_message'] = "Invalid transaction type.";
            header("Location: staff.php");
            exit();
    }

    $sql = "
        SELECT 
            SUM(deposit) + SUM(receiver_amount) AS total_deposit,
            SUM(dinein + takeout + giftcard) AS total_payable,
            SUM(cashout + interest) AS total_cashout,
            SUM(CASE WHEN receiver_amount IS NOT NULL THEN receiver_amount ELSE 0 END) AS received,
            SUM(CASE WHEN sender_amount IS NOT NULL THEN sender_amount ELSE 0 END) AS sent
        FROM employees
        WHERE UPPER(name) = UPPER(?)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total_deposit = (float) $row['total_deposit'];
    $total_payable = (float) $row['total_payable'];
    $total_cashout = (float) $row['total_cashout'];
    $received = (float) $row['received'];
    $sent = (float) $row['sent'];

    $current_balance = $total_deposit - ($total_payable + $total_cashout) + $received - $sent;

    if ($transaction_type != 'deposit' && $payment_method == 'na') {
        $new_total_payable = $total_payable + $dinein + $takeout + $giftcard;
        $new_balance = $total_deposit - ($new_total_payable + $total_cashout) + $received - $sent;

        if ($new_balance > 5000) {
            $_SESSION['error_message'] = "⚠️ Transaction denied! {$name}'s balance cannot exceed 5000.";
            header("Location: staff.php");
            exit();
        }
    }

    // Giftcard validation
    if (in_array($transaction_type, ['dinein', 'takeout']) && $payment_method == 'giftcard') {
        $stmt = $conn->prepare("SELECT SUM(giftcard) AS total_giftcard FROM employees WHERE UPPER(name) = UPPER(?)");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $giftcard_total = $stmt->get_result()->fetch_assoc()['total_giftcard'];
        $stmt->close();

        $stmt = $conn->prepare("SELECT SUM(dinein + takeout) AS used_giftcard FROM employees WHERE payment_method = 'giftcard' AND UPPER(name) = UPPER(?)");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $used_total = $stmt->get_result()->fetch_assoc()['used_giftcard'];
        $stmt->close();

        $giftcard_balance = (float)$giftcard_total - (float)$used_total;

        if (($dinein + $takeout) > $giftcard_balance) {
            $_SESSION['error_message'] = "❌ Insufficient gift card balance for {$name}. Available: " . number_format($giftcard_balance, 2);
            header("Location: staff.php");
            exit();
        }
    }

    // Check if the employee is blocked
    $block_check = $conn->prepare("SELECT block FROM employees WHERE UPPER(name) = UPPER(?) ORDER BY id DESC LIMIT 1");
    $block_check->bind_param("s", $name);
    $block_check->execute();
    $block_result = $block_check->get_result();
    $block_row = $block_result->fetch_assoc();
    $block_check->close();

    if ($block_row && strtolower($block_row['block']) === 'block') {
        $_SESSION['error_message'] = "🚫 Transaction denied! {$name} is currently blocked.";
        header("Location: staff.php");
        exit();
    }

    // Insert into employees table
    $stmt = $conn->prepare("INSERT INTO employees 
        (name, company, deposit, dinein, takeout, giftcard, remarks, payment_method, card, cashier, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmt->bind_param("ssddddssss", 
        $name, 
        $company, 
        $deposit, 
        $dinein, 
        $takeout, 
        $giftcard, 
        $remarks, 
        $payment_method, 
        $card, 
        $cashier_name
    );

    if ($stmt->execute()) {
        $last_inserted_id = $conn->insert_id;
        $formatted_id = str_pad($last_inserted_id, 6, "0", STR_PAD_LEFT);
        $_SESSION['success_message'] = "✅ Transaction successful!";
    } else {
        $_SESSION['error_message'] = "Error inserting record: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();

    header("Location: staff.php");
    exit();
}
?>