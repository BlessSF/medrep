<?php
session_start();



require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$admin_username = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $new_user = trim($_POST['new_username']);
        $new_pass = trim($_POST['new_password']);
        $new_role = $_POST['new_role'];

        if (!empty($new_user) && !empty($new_pass)) {
            // Check if username exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->bind_param("s", $new_user);
            $stmt->execute();
            $stmt->bind_result($userCount);
            $stmt->fetch();
            $stmt->close();

            if ($userCount > 0) {
                $_SESSION['message'] = "Username already exists.";
            } else {
                // Hash the password the same way login.php verifies it
                // (password_verify). The old version stored $new_pass as
                // plain text here, so any user added through this form
                // could never log in -- login.php's password_verify()
                // check needs a bcrypt hash, not raw text, on this side.
                $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $new_user, $hashed_pass, $new_role);
                if ($stmt->execute()) {
                    $_SESSION['message'] = "User added successfully.";

                    // Log the action
                    $action = "Added new user";
                    $details = "Admin $admin_username added user $new_user";
                    $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
                    $log_stmt->bind_param("sss", $action, $admin_username, $details);
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    $_SESSION['message'] = "Error adding user.";
                }
                $stmt->close();
            }
        } else {
            $_SESSION['message'] = "Please fill in all fields.";
        }

        // Redirect to clear POST data
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Handle user deletion
    if (isset($_POST['delete_username'])) {
        $usernameToDelete = $_POST['delete_username'];

        if ($usernameToDelete !== $admin_username) {
            $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
            $stmt->bind_param("s", $usernameToDelete);
            $stmt->execute();
            $stmt->close();

            // Log the action
            $action = "Deleted user";
            $details = "Admin $admin_username deleted user $usernameToDelete";
            $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
            $log_stmt->bind_param("sss", $action, $admin_username, $details);
            $log_stmt->execute();
            $log_stmt->close();
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Handle password change
    if (isset($_POST['change_password_username'], $_POST['new_password'])) {
        $usernameToChange = $_POST['change_password_username'];
        // Same fix as add_user above: hash before storing, otherwise the
        // account can't log in again until this is corrected (this is why
        // Christine's password stopped working after being changed here).
        $newPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
        $stmt->bind_param("ss", $newPassword, $usernameToChange);
        $stmt->execute();
        $stmt->close();

        // Log the action
        $action = "Changed password";
        $details = "Admin $admin_username changed password for user $usernameToChange";
        $log_stmt = $conn->prepare("INSERT INTO logs (action, username, details) VALUES (?, ?, ?)");
        $log_stmt->bind_param("sss", $action, $admin_username, $details);
        $log_stmt->execute();
        $log_stmt->close();

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Query to fetch users
$result = $conn->query("SELECT username, password, role FROM users ORDER BY username ASC");
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>USERS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0px;
            padding: 0;
            background:rgb(227, 251, 203);
            text-transform: uppercase; /* Ensures all text is in capital letters */
        }
        .sidebar {
            width: 230px;
            height: 100vh;
            background-color: #1b5e20;
            color: #fff;
            position: fixed;
            padding-top: 20px;
        }
        .sidebar h2 {
    text-align: center;
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
}

        .sidebar ul {
            list-style-type: none;
            padding: 0;
        }
        .sidebar ul li {
            padding: 15px;
            text-align: center;
        }
        .sidebar ul li button {
            width: 100%;
            padding: 15px;
            background-color: #abd67c;
            color: #000;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 15px;
            border-radius: 5px;

        }
        .sidebar ul li button:hover {
            background-color:rgb(36, 125, 59);
        }
       

        .main-content {
            margin-left: 240px;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background:rgb(227, 251, 203);
        }
        th, td {
            padding: 12px;
            border: 1px solid black;
            text-align: center;
        }
        th {
            background-color: #1b5e20;
            color: white;
        }
        .show-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            margin-left: 5px;
        }
        .action-btn {
            padding: 5px 10px;
            margin: 2px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 5px;
        }
        .delete-btn {
            background-color: green;
            color: white;
            border: none;
        }
        .password-btn {
            background-color: green;
            color: white;
            border: none;
        }
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 300px;
            text-align: center;
        }
        .modal input[type="password"] {
            width: 90%;
            padding: 8px;
            margin-bottom: 10px;
        }
        .close {
            color: red;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        form.add-user-form {
            margin-bottom: 30px;
            padding: 15px;
            background:rgb(227, 251, 203);
            border-radius: 10px;
        }
        form.add-user-form input,
        form.add-user-form select {
            margin-right: 10px;
            padding: 5px;
        }
        form.add-user-form button {
            padding: 6px 15px;
            background-color: green;
            color: white;
            border: none;
            border-radius: 5px;
        }
    </style>
    <link rel="stylesheet" href="assets/style.css">
<script src="assets/table-search.js"></script>
</head>
<body>
<div class="sidebar">
        <div class="sidebar-profile">
        <div class="sidebar-avatar"><i class="fas fa-user"></i></div>
        <div class="sidebar-profile-text">
            <span class="sidebar-greeting">Hello,</span>
            <span class="sidebar-username"><?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>
    <ul>
        <li><button onclick="location.href='admin.php'"><i class="fas fa-tachometer-alt"></i>   DASHBOARD</button></li>
        <li><button onclick="location.href='customer.php'"><i class="fas fa-users"></i>   CUSTOMERS</button></li>
        <li><button onclick="location.href='block_medrep.php'"><i class="fas fa-user-lock"></i>   BLOCK MEDREP</button></li>
        <li><button onclick="location.href='companies.php'"><i class="fas fa-building"></i>   COMPANIES</button></li>
                <li><button onclick="location.href='daily.php'"><i class="fas fa-calendar-day"></i>   RECEIVABLES/PAYABLES</button></li>
                <li><button onclick="location.href='receivables_summary.php'"><i class="fas fa-calendar-alt"></i>   SUMMARY</button></li>
        <li><button onclick="location.href='report.php'"><i class="fas fa-chart-line"></i>   SALES REPORTS</button></li>
        <li><button onclick="location.href='cashout.php'"><i class="fas fa-money-bill-wave"></i>   CASHOUT REPORT</button></li>
        <li><button onclick="location.href='users.php'"><i class="fas fa-users-cog"></i>   USERS</button></li>
        <li><button onclick="location.href='tracking.php'"><i class="fas fa-bell"></i>   ADMIN TRACKING</button></li>
        <li><button onclick="location.href='index.php'"><i class="fas fa-sign-out-alt"></i>   LOGOUT</button></li>
    </ul>
</div>

<div class="main-content">
    <h1>USER MANAGEMENT</h1>

    <!-- Add user form -->
    <form method="POST" class="add-user-form">
        <input type="hidden" name="add_user" value="1">
        <input type="text" name="new_username" placeholder="Username" required>
        <input type="text" name="new_password" placeholder="Password" required>
        <select name="new_role">
            <option value="admin">Admin</option>
            <option value="cashier">Cashier</option>
        </select>
        <button type="submit">Add User</button>
    </form>

    <div class="table-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="userSearch" placeholder="Search users...">
    </div>
    <table id="usersTable">
        <thead>
            <tr>
                <th>USERNAME</th>
                <th>PASSWORD</th>
                <th>ROLE</th>
                <th>ACTIONS</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td>
                        <span class="password-text" id="pw-<?php echo $row['username']; ?>">••••••••</span>
                        <button class="show-btn" onclick="togglePassword('<?php echo $row['username']; ?>', '<?php echo htmlspecialchars($row['password']); ?>')">👁️</button>
                    </td>
                    <td><?php echo htmlspecialchars($row['role']); ?></td>
                    <td>
                        <?php if ($row['username'] !== $admin_username): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="delete_username" value="<?php echo $row['username']; ?>">
                                <button class="action-btn delete-btn" onclick="return confirm('Delete this user?')">DELETE</button>
                            </form>
                        <?php endif; ?>
                        <button class="action-btn password-btn" onclick="openModal('<?php echo $row['username']; ?>')">CHANGE PASSWORD</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php if (isset($_SESSION['message'])): ?>
    <script>
        alert("<?= addslashes($_SESSION['message']) ?>");
    </script>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<!-- Change Password Modal -->
<div id="passwordModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">×</span>
        <h3>Change Password</h3>
        <form method="POST">
            <input type="hidden" id="change_password_username" name="change_password_username">
            <input type="password" name="new_password" placeholder="Enter new password" required>
            <br>
            <button type="submit">UPDATE</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    attachTableSearch('userSearch', '#usersTable');
});
function togglePassword(username, actualPassword) {
    const span = document.getElementById("pw-" + username);
    if (span.innerText === "••••••••") {
        span.innerText = actualPassword;
    } else {
        span.innerText = "••••••••";
    }
}

function openModal(username) {
    document.getElementById("change_password_username").value = username;
    document.getElementById("passwordModal").style.display = "block";
}

function closeModal() {
    document.getElementById("passwordModal").style.display = "none";
}
</script>

</body>
</html>