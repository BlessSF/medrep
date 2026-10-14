<?php 
session_start(); // Start the session to access session variables





require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get logged-in user's username from the session
if (isset($_SESSION['username'])) {
    $admin_username = $_SESSION['username'];
} else {
    die("User not logged in."); // Or redirect to login page
}

// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the row can be removed from the table in place.
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    if (filter_var($id, FILTER_VALIDATE_INT) && intval($id) > 0) {
        $id = intval($id);

        // Step 1: Get employee data before deleting
        $stmt = $conn->prepare("SELECT name, deposit, dinein, takeout, cashout, interest, sender_amount, giftcard FROM employees WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $stmt->bind_result($employeeName, $deposit, $dinein, $takeout, $cashout, $interest, $transfer_amount, $giftcard);
            $stmt->fetch();
            $stmt->close();

            if ($employeeName) {
                // Step 2: Determine which amount and column to log
                $amount = 0;
                $columnName = '';

                $columns = [
                    'deposit' => $deposit,
                    'dinein' => $dinein,
                    'takeout' => $takeout,
                    'cashout' => $cashout,
                    'interest' => $interest,
                    'sender_amount' => $transfer_amount,
                    'giftcard' => $giftcard
                ];

                foreach ($columns as $column => $value) {
                    if ($value > 0) {
                        $amount = $value;
                        $columnName = $column;
                        break; // Stop after finding the first non-zero value
                    }
                }

                // Step 3: Delete the employee
                $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        // Step 4: Log the deletion
                        $deleteAction = "Deleted transaction";
                        if ($amount > 0 && $columnName != '') {
                            $deleteDetails = "Deleted transaction from $employeeName with the amount $amount in the column '$columnName'";
                        } else {
                            $deleteDetails = "Deleted transaction from $employeeName with no specific amount found.";
                        }

                        $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
                        if ($log_stmt) {
                            $log_stmt->bind_param("sss", $deleteAction, $admin_username, $deleteDetails);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }

                        // Step 5: Check if the same name still exists
                        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM employees WHERE name = ?");
                        if ($stmt) {
                            $stmt->bind_param("s", $employeeName);
                            $stmt->execute();
                            $stmt->bind_result($count);
                            $stmt->fetch();
                            $stmt->close();

                            $msg = urlencode("Employee '$employeeName' deleted successfully.");

                            if ($isAjax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'message' => "Deleted.",
                                    'id' => $id,
                                    'employee_still_exists' => $count > 0,
                                ]);
                                exit();
                            }

                            if ($count > 0) {
                                $nameParam = urlencode($employeeName);
                                header("Location: customer_profile.php?name=$nameParam&msg=$msg");
                            } else {
                                header("Location: customer.php?msg=$msg");
                            }
                            exit();
                        } else {
                            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Error checking remaining employees.']); exit(); }
                            echo "Error checking remaining employees.";
                        }
                    } else {
                        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Error deleting employee: ' . $stmt->error]); exit(); }
                        echo "Error deleting employee: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Employee not found.']); exit(); }
                echo "Employee not found.";
            }
        } else {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Error preparing SELECT statement: ' . $conn->error]); exit(); }
            echo "Error preparing SELECT statement: " . $conn->error;
        }
    } else {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit(); }
        echo "Invalid ID.";
    }
} else {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'No ID specified.']); exit(); }
    echo "No ID specified.";
}

$conn->close();
?>
