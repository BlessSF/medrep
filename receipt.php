<?php
session_start();



require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Redirect if user is not logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$name = $company = "";
$cashOutAmount = $interest = $totalCashOut = 0;

if ($requestMethod === 'POST') {
    $name = $_POST['name'] ?? '';
    $company = $_POST['company'] ?? '';
    $cashOutAmount = $_POST['cashOutAmount'] ?? 0;
    $interest = $_POST['interest'] ?? 0;
    $totalCashOut = $_POST['totalCashOut'] ?? 0;
} elseif ($requestMethod === 'GET') {
    $name = $_GET['name'] ?? '';
    $company = $_GET['company'] ?? '';
    $cashOutAmount = $_GET['cashOutAmount'] ?? 0;
    $interest = $_GET['interest'] ?? 0;
    $totalCashOut = $_GET['totalCashOut'] ?? 0;
} else {
    die("Invalid request method.");
}

// Convert amounts to numbers
$cashOutAmount = floatval($cashOutAmount);
$interest = floatval($interest);
$totalCashOut = floatval($totalCashOut);

// Check if valid data received
if (empty($name) || empty($company) || $totalCashOut <= 0) {
    die("Invalid data received.");
}

// Update the payment method in employees table
$sql = "UPDATE employees SET `payment method` = 'Cash' WHERE name = ?";$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $name);
$stmt->execute();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; background-color: #f4f4f4; padding: 20px; }
        .receipt-container { background: white; padding: 20px; width: 60%; max-width: 400px; margin: auto; box-shadow: 0 0 10px rgba(0, 0, 0, 0.2); border-radius: 5px; border: 2px solid #000; }
        h2, h4 { color: #333; margin: 5px 0; }
        .receipt-details p { font-size: 16px; line-height: 1.5; display: flex; justify-content: space-between; }
        .total { font-weight: bold; font-size: 18px; border-top: 2px solid black; padding-top: 10px; }
        .buttons { margin-top: 20px; }
        .btn { background-color: #007BFF; color: white; border: none; padding: 10px 15px; cursor: pointer; margin: 5px; }
        .btn:hover { background-color: #0056b3; }
        @media print {
    @page { margin: 0; }
    body { margin: 0; }
}
    </style>
</head>
<body>
<div class="receipt-container">
    <div class="receipt-header">
        <h2>MEDREP CASHOUT RECEIPT</h2>
        <h4><?php echo date("F d, Y h:i A"); ?></h4>
    </div>
    <div class="receipt-details">
        <p><strong>Name:</strong> <span><?php echo htmlspecialchars(strtoupper($name)); ?></span></p>
        <p><strong>Company:</strong> <span><?php echo htmlspecialchars(strtoupper($company)); ?></span></p>
        <p><strong>Cashout Amount:</strong> <span>₱<?php echo number_format($cashOutAmount, 2); ?></span></p>
        <p><strong>Interest:</strong> <span>₱<?php echo number_format($interest, 2); ?></span></p>
        <p class="total"><strong>Total Cashout:</strong> <span>₱<?php echo number_format($totalCashOut, 2); ?></span></p>
    </div>
    <div class="buttons">
        <button class="btn" onclick="printAndGoBack()">Print Receipt</button>
    </div>
</div>
<script>
function printAndGoBack() {
    window.print();
    setTimeout(function() {
        window.location.href = 'staff.php';
    }, 500);
}
</script>
</body>
</html>