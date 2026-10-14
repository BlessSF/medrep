<?php 
session_start();




// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Make sure the user is logged in
if (!isset($_SESSION['username'])) {
    $_SESSION['error_message'] = "You must be logged in to perform this action.";
    header("Location: customer.php");
    exit;
}

$admin_username = $_SESSION['username'];

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the row can flip to "Blocked" in place. Legacy/non-JS
// callers keep the original redirect-to-customer.php behavior.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['name'])) {
    $name = trim($_GET['name']);
    $block = "block";

    // Check if the employee is already blocked
    $check = $conn->prepare("SELECT block FROM employees WHERE name = ?");
    $check->bind_param("s", $name);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['block'] === 'block') {
            $_SESSION['error_message'] = "⚠️ '$name' is already blocked.";
        } else {
            // Proceed to block the employee
            $update = $conn->prepare("UPDATE employees SET block = ? WHERE name = ?");
            $update->bind_param("ss", $block, $name);
            if ($update->execute()) {
                $_SESSION['success_message'] = "✅ '$name' has been blocked successfully.";

                // Log the block action
                $action = "Blocked medrep customer";
                $details = "Blocked medrep customer: $name";
                $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param("sss", $action, $admin_username, $details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $_SESSION['error_message'] = "Update error: " . $update->error;
            }
            $update->close();
        }
    } else {
        $_SESSION['error_message'] = "❌ No employee found with the name '$name'.";
    }
    $check->close();
} else {
    $_SESSION['error_message'] = "No name parameter provided.";
}

$conn->close();

if ($isAjax) {
    header('Content-Type: application/json');
    $msg = $_SESSION['success_message'] ?? $_SESSION['error_message'] ?? 'Done.';
    $ok = isset($_SESSION['success_message']);
    unset($_SESSION['success_message'], $_SESSION['error_message']);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

// Redirect back to customer.php
header("Location: customer.php");
exit;
?>
