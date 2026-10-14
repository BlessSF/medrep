<?php
session_start();




require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the logged-in admin's username from session
$admin_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown User';

// Sanitize and retrieve POST data
$original_name = isset($_POST['original_name']) ? trim($_POST['original_name']) : '';
$edit_name = isset($_POST['edit_name']) ? trim($_POST['edit_name']) : '';
$edit_company = isset($_POST['edit_company']) ? trim($_POST['edit_company']) : '';

// AJAX callers (see assets/ajax-forms.js) get a JSON response instead of
// a redirect, so the customer list can update in place without a full
// page reload. Non-JS/legacy callers keep working exactly as before.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (!empty($original_name) && !empty($edit_name) && !empty($edit_company)) {

    // Fetch original data
    $result = $conn->prepare("SELECT name, company FROM employees WHERE name = ?");
    $result->bind_param("s", $original_name);
    $result->execute();
    $result->bind_result($orig_name_db, $orig_company_db);
    $result->fetch();
    $result->close();

    // Prepare the update statement
    $stmt = $conn->prepare("UPDATE employees SET name = ?, company = ? WHERE name = ?");
    $stmt->bind_param("sss", $edit_name, $edit_company, $original_name);

    if ($stmt->execute()) {
        // Create appropriate log message
        $action = "Updated Medrep Information";
        $details = "";

        if ($edit_name !== $orig_name_db && $edit_company !== $orig_company_db) {
            $details = "Updated '{$orig_name_db}' from '{$orig_company_db}' to new name '{$edit_name}' under company '{$edit_company}'";
        } elseif ($edit_name !== $orig_name_db) {
            $details = "Updated name from '{$orig_name_db}' to '{$edit_name}' under company '{$edit_company}'";
        } elseif ($edit_company !== $orig_company_db) {
            $details = "Updated '{$edit_name}' company from '{$orig_company_db}' to '{$edit_company}'";
        } else {
            $details = "No changes made to '{$edit_name}'";
        }

        // Only log if any change occurred
        if ($details !== "No changes made to '{$edit_name}'") {
            $log_query = "INSERT INTO logs (action, username, details) VALUES (?, ?, ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("sss", $action, $admin_username, $details);
            $log_stmt->execute();
            $log_stmt->close();
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Customer updated successfully.',
                'name' => $edit_name,
                'company' => $edit_company,
                'original_name' => $original_name,
            ]);
            exit();
        }

        // Redirect back with success message
        header("Location: customer.php?message=Customer updated successfully");
        exit();
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error updating record: ' . $stmt->error]);
            exit();
        }
        echo "Error updating record: " . $stmt->error;
    }

    $stmt->close();
} else {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit();
    }
    echo "Please fill in all required fields.";
}

$conn->close();
?>
