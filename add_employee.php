<?php

require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


// Get the JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Prepare and bind
$stmt = $conn->prepare("INSERT INTO employees (name, company, balance, remarks, payment_method, bank) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssisss", $name, $company, $balance, $remarks, $paymentMethod, $bank);

$name = $data['name'];
$company = $data['company'];
$balance = $data['balance'];
$remarks = $data['remarks'];
$paymentMethod = $data['paymentMethod'];
$bank = $data['bank'];

$response = [];
if ($stmt->execute()) {
    $response['success'] = true;
} else {
    $response['success'] = false;
    $response['message'] = "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>