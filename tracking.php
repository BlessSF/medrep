<?php  
session_start();



// Establish database connection
require_once __DIR__ . '/includes/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$admin_username = $_SESSION['username'];

// Get the current selected month and year, or set defaults
$selected_month = isset($_POST['month']) ? $_POST['month'] : date('m');
$selected_year = isset($_POST['year']) ? $_POST['year'] : date('Y');

// Fetch logs filtered by selected month and year
$logs_query = "SELECT * FROM logs 
               WHERE MONTH(timestamp) = $selected_month 
               AND YEAR(timestamp) = $selected_year 
               ORDER BY timestamp DESC";
$logs_result = $conn->query($logs_query);

// Get list of years for dropdown
$year_query = "SELECT DISTINCT YEAR(timestamp) AS year FROM logs ORDER BY year DESC";
$year_result = $conn->query($year_query);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Properly closed meta tag -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"> <!-- Correct Font Awesome link -->
    <title>ADMIN TRACKING</title>
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
            margin-left: 235px;
            padding: 20px;
            background:rgb(227, 251, 203);
        }
        .logs-container {
            margin-top: 20px;
            background: white;
            padding: 20px;
            border-radius: 0px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow: auto;
        }
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            border: 1px solid black;
        }
        .logs-table th, .logs-table td {
            padding: 12px;
            text-align: center;
            border: 1px solid black;
        }
        .logs-table thead {
            background: #1b5e20;
            color: white;
            text-transform: uppercase;
            font-size: 13px;
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
    <h1>Admin Tracking</h1>
    
    <!-- Filter Form -->
    <form method="POST" action="tracking.php" style="margin-bottom: 20px;">
        <label for="month">Month:</label>
        <select name="month" id="month" onchange="this.form.submit()">
            <?php
            for ($i = 1; $i <= 12; $i++) {
                echo '<option value="' . str_pad($i, 2, '0', STR_PAD_LEFT) . '" ' . ($selected_month == str_pad($i, 2, '0', STR_PAD_LEFT) ? 'selected' : '') . '>' . date('F', strtotime("2021-$i-01")) . '</option>';
            }
            ?>
        </select>
        
        <label for="year">Year:</label>
        <select name="year" id="year" onchange="this.form.submit()">
            <?php
            while ($year = $year_result->fetch_assoc()) {
                echo '<option value="' . $year['year'] . '" ' . ($selected_year == $year['year'] ? 'selected' : '') . '>' . $year['year'] . '</option>';
            }
            ?>
        </select>
    </form>

    <div class="logs-container">
        <h2>RECENT TRANSACTIONS (<?php echo date('F Y', strtotime("$selected_year-$selected_month-01")); ?>)</h2>
        <div class="table-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="logsSearch" placeholder="Search logs...">
        </div>
        <table class="logs-table" id="logsTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                    <th>Username</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($log = $logs_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('Y-M-d', strtotime($log['timestamp'])); ?></td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td><?php echo htmlspecialchars($log['username']); ?></td>
                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    attachTableSearch('logsSearch', '#logsTable');
});
</script>
</body>
</html>

<?php $conn->close(); ?>