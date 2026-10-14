<?php  
session_start();

// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    die("Access denied. Please log in.");
}

$admin_username = $_SESSION['username']; // Logged-in admin's username

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the row can be removed from the table in place.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

$action = "Deleted a customer";
$details = ""; // This will be filled after getting the customer name

if (isset($_GET['name'])) {
    $name = $_GET['name'];
    $details = "Deleted medrep name: $name";

    // Start transaction
    $conn->begin_transaction();

    try {
        // Delete from employees table
        $del_stmt = $conn->prepare("DELETE FROM employees WHERE name = ?");
        $del_stmt->bind_param("s", $name);
        $del_stmt->execute();
        $del_stmt->close();

        // Log the deletion action
        $log_query = "INSERT INTO logs (action, username, details) VALUES (?, ?, ?)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bind_param("sss", $action, $admin_username, $details);
        $log_stmt->execute();
        $log_stmt->close();

        // Commit transaction
        $conn->commit();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Customer deleted successfully.', 'name' => $name]);
            exit();
        }

        // Redirect back with success message
        header("Location: customer.php?message=Customer deleted successfully");
        exit();
    } catch (Exception $e) {
        // Rollback transaction in case of error
        $conn->rollback();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error deleting customer: ' . $conn->error]);
            exit();
        }
        echo "Error deleting customer: " . $conn->error;
    }
} else {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit();
    }
    echo "Invalid request.";
}

$conn->close();
?>
