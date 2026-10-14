<?php  
session_start(); 



// Database connection
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) { 
    die("CONNECTION FAILED: " . $conn->connect_error); 
}  

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the summary page can refresh in place without a full
// browser navigation.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (!isset($_POST['sender_name'], $_POST['sender_company'], $_POST['receiver_name'], $_POST['receiver_company'], $_POST['sender_amount'], $_POST['receiver_amount'])) { 
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    die("All fields are required."); 
}  

// Get the posted data
$sender_name = trim($_POST['sender_name']); 
$sender_company = trim($_POST['sender_company']); 
$receiver_name = trim($_POST['receiver_name']); 
$receiver_company = trim($_POST['receiver_company']); 
$sender_amount = floatval($_POST['sender_amount']); 
$receiver_amount = floatval($_POST['receiver_amount']); 
$remarks = "Transfer";  

// Ensure amounts are positive
if ($sender_amount <= 0 || $receiver_amount <= 0) { 
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid transfer amounts.']);
        exit;
    }
    die("Invalid transfer amounts."); 
}  

// Insert sender transaction record into `employees` table
$insertSender = $conn->prepare("INSERT INTO employees (name, company, sender_amount, receiver_name, remarks) VALUES (?, ?, ?, ?, ?)");
$insertSender->bind_param("ssdss", $sender_name, $sender_company, $sender_amount, $receiver_name, $remarks);

// Insert receiver transaction record into `employees` table
$insertReceiver = $conn->prepare("INSERT INTO employees (name, company, receiver_amount, sender_name, remarks) VALUES (?, ?, ?, ?, ?)");
$insertReceiver->bind_param("ssdss", $receiver_name, $receiver_company, $receiver_amount, $sender_name, $remarks);

// Execute both insertions
if ($insertSender->execute() && $insertReceiver->execute()) { 
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Transfer of ₱" . number_format($sender_amount, 2) . " to $receiver_name recorded.",
        ]);
        $insertSender->close();
        $insertReceiver->close();
        $conn->close();
        exit;
    }
    echo "Data inserted successfully!"; 
    // Redirect to the summary page
    header("Location: summary.php?name=" . urlencode($sender_name) . "&amount=" . urlencode($sender_amount)); 
    exit; 
} else { 
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error inserting record: ' . $conn->error]);
        $insertSender->close();
        $insertReceiver->close();
        $conn->close();
        exit;
    }
    echo "Error inserting record: " . $conn->error; 
}

$insertSender->close(); 
$insertReceiver->close(); 
$conn->close();
?>
