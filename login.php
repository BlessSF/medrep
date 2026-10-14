<?php
session_start(); // Start session before any output



// Create connection
require_once __DIR__ . '/includes/db_config.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtolower(trim($_POST['username']));
    $password = trim($_POST['password']);

    // Query to fetch user details from the database based on the provided username
    $stmt = $conn->prepare("SELECT username, password, role FROM users WHERE LOWER(username) = ?");
    $stmt->bind_param("s", $username); // Bind the username to the query
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) { // User exists in the database
        $user = $result->fetch_assoc();

        // Check if the entered password matches the stored password
        if (password_verify($password, $user['password'])) {
            $_SESSION['username'] = $user['username']; // Store the actual username in session

            // Redirect based on user role
            if (strtolower($user['role']) === 'cashier') {
                header("Location: staff.php"); // Redirect to cashier's page
                exit();
            } elseif (strtolower($user['role']) === 'admin') {
                header("Location: admin.php"); // Redirect to admin's page
                exit();
            } else {
                // Handle unexpected roles (optional)
                $error_message = "Invalid role assigned to the user.";
            }
        } else {
            $error_message = "Incorrect password.";
        }
    } else {
        $error_message = "User not found.";
    }

    $stmt->close();
}

$conn->close();
?>

