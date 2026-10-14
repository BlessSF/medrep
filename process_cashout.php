<?php
session_start();



// Connect to DB
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("CONNECTION FAILED: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and fetch POST data
    $name = trim($_POST['name']);
    $company = trim($_POST['company']);
    $cashOutAmount = floatval($_POST['cashOutAmount']);  // Ensure float
    $operation = $_POST['operation']; // "add" or "deduct"

    // Interest percent is now editable by the cashier per transaction.
    // Defaults to 35% if not provided (kept for backward compatibility),
    // clamped to a sane 0-100 range.
    $percent = isset($_POST['percent']) ? floatval($_POST['percent']) : 35;
    if ($percent < 0) $percent = 0;
    if ($percent > 100) $percent = 100;
    $interestRate = $percent / 100;

    // Validation
    if (empty($name) || empty($company) || $cashOutAmount <= 0 || empty($operation)) {
        echo json_encode(["status" => "error", "message" => "Invalid data provided"]);
        $conn->close();
        exit();
    }

    // Use float for calculation
    $interest = round($cashOutAmount * $interestRate, 2); // editable interest %, 2 decimal places
    $cashoutValue = 0.00;

    if ($operation === "add") {
        $cashoutValue = $cashOutAmount;
    } elseif ($operation === "deduct") {
        $cashoutValue = $cashOutAmount - $interest;
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid operation"]);
        $conn->close();
        exit();
    }

    // Save to database with decimal precision
    $stmt = $conn->prepare("INSERT INTO employees (name, company, cashout, interest) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssdd", $name, $company, $cashoutValue, $interest);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Transaction recorded successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to execute the query"]);
    }

    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
}

$conn->close();
?>
