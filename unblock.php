<?php 
session_start();



// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if the admin user is logged in
if (!isset($_SESSION['username'])) {
    $_SESSION['message'] = "❌ User not logged in.";
    header("Location: index.php");
    exit;
}

$admin_username = $_SESSION['username']; // Logged-in user from the 'users' table

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the row can flip to "Unblocked" in place.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

// If 'name' is provided via GET request
if (isset($_GET['name'])) {
    $name = $_GET['name'];

    // First, check the current block status of the employee
    $checkStmt = $conn->prepare("SELECT block FROM employees WHERE name = ?");
    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $row = $checkResult->fetch_assoc();
        if ($row['block'] === 'unblock') {
            $_SESSION['message'] = "⚠️ $name is already unblocked.";
        } else {
            // Proceed to unblock the user
            $stmt = $conn->prepare("UPDATE employees SET block = 'unblock' WHERE name = ?");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $_SESSION['message'] = "✅ Successfully unblocked $name.";
                
                // Log the unblock action
                $action = "Unblocked medrep name";
                $details = "Unblocked medrep name: $name";
                $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
                $log_stmt->bind_param("sss", $action, $admin_username, $details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $_SESSION['message'] = "❌ Failed to unblock $name.";
            }
            $stmt->close();
        }
    } else {
        $_SESSION['message'] = "❌ Employee not found.";
    }

    $checkStmt->close();
}

$conn->close();

if ($isAjax) {
    header('Content-Type: application/json');
    $msg = $_SESSION['message'] ?? 'Done.';
    $ok = (strpos($msg, '❌') === false);
    unset($_SESSION['message']);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

header("Location: customer.php");
exit;
?>
