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
// After session check and DB connection
$admin_username = $_SESSION['username'];
$admin_query = "SELECT username FROM users WHERE username = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("s", $admin_username);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin_row = $admin_result->fetch_assoc();
$admin_name = $admin_row['username'] ?? '';
$admin_stmt->close();

$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');

function fetch_data($conn, $month, $year) {
    $data = [
        'total_cashout' => 0.00,
        'total_interest' => 0.00
    ];

    $summary_sql = "SELECT 
                    COALESCE(SUM(cashout), 0) AS total_cashout,
                    COALESCE(SUM(interest), 0) AS total_interest
                    FROM employees 
                    WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?";

    $stmt = $conn->prepare($summary_sql);
    $stmt->bind_param("ii", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result) {
        $data['total_cashout'] = $result['total_cashout'] ?? 0.00;
        $data['total_interest'] = $result['total_interest'] ?? 0.00;
    }

    $stmt->close();
    
    return $data;
}

$sales_data = fetch_data($conn, $selected_month, $selected_year);

$employees_sql = "SELECT name, cashout, interest, created_at 
                  FROM employees 
                  WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND (cashout > 0 OR interest > 0)";

$stmt = $conn->prepare($employees_sql);
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$employees_result = $stmt->get_result();

if (isset($_GET['ajax']) && isset($_GET['year'])) {
    $year = intval($_GET['year']);
    $monthly_data = [];

    for ($month = 1; $month <= 12; $month++) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(cashout), 0) AS total_cashout, COALESCE(SUM(interest), 0) AS total_interest FROM employees WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND (cashout > 0 OR interest > 0)");
        $stmt->bind_param("ii", $month, $year);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $monthly_data['cashout'][$month] = $result['total_cashout'] ?? 0;
        $monthly_data['interest'][$month] = $result['total_interest'] ?? 0;

        $stmt->close();
    }

    echo json_encode($monthly_data);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASHOUT REPORT</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex;
        text-transform: uppercase; /* Ensures all text is in capital letters */
        background:rgb(227, 251, 203);
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
       
        .main-content { margin-left: 220px; padding: 20px; flex: 1; text-align: center; }
        .summary-boxes { 
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .summary-box { 
            background-color: #abd67c;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            flex: 1;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
            min-width: 260px;
        }

        .summary-box:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.2);
        }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th {background-color: #1b5e20;
            color: white; }
        .buttons { margin: 20px; display: flex; gap: 10px; justify-content: center; }
        .hidden { display: none; }
        #analysis-section {
    margin-top: 20px;
    padding: 20px;
    text-align: center;
    background-color: rgb(227, 251, 203);
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
                <li><button onclick="location.href='voucher_monitoring.php'"><i class="fas fa-ticket-alt"></i>   VOUCHER MONITORING</button></li>
        <li><button onclick="location.href='report.php'"><i class="fas fa-chart-line"></i>   SALES REPORTS</button></li>
        <li><button onclick="location.href='cashout.php'"><i class="fas fa-money-bill-wave"></i>   CASHOUT REPORT</button></li>
        <li><button onclick="location.href='users.php'"><i class="fas fa-users-cog"></i>   USERS</button></li>
        <li><button onclick="location.href='tracking.php'"><i class="fas fa-bell"></i>   ADMIN TRACKING</button></li>
        <li><button onclick="location.href='index.php'"><i class="fas fa-sign-out-alt"></i>   LOGOUT</button></li>
    </ul>
</div>

<div class="main-content">
    <h1>Cashout Report</h1>
    <form method="GET" action="">
        <label for="month">Month:</label>
        <select name="month" id="month" onchange="this.form.submit()">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= ($m == $selected_month) ? 'selected' : '' ?>>
                    <?= date("F", mktime(0, 0, 0, $m, 1)) ?>
                </option>
            <?php endfor; ?>
        </select>
        <label for="year">Year:</label>
        <select name="year" id="year" onchange="this.form.submit()">
            <?php for ($y = date('Y'); $y >= 2000; $y--): ?>
                <option value="<?= $y ?>" <?= ($y == $selected_year) ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
<h1></h1>
    <div class="summary-boxes">
        <div class="summary-box"><h3>Total Cashout</h3><p id="totalCashout">₱<?= number_format($sales_data['total_cashout'], 2) ?></p></div>
        <div class="summary-box"><h3>Total Interest</h3><p id="totalInterest">₱<?= number_format($sales_data['total_interest'], 2) ?></p></div>
    </div>

    <div class="buttons">
    <button onclick="toggleSection('table-section')">Cashout Records</button>
    <button onclick="toggleSection('analysis-section')">Analysis</button>
</div>

<!-- Cashout Records Section -->
<div id="table-section">
    <?php if ($employees_result->num_rows > 0): ?>
        <div class="table-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="cashoutSearch" placeholder="Search cashout records...">
        </div>
        <table id="cashoutTable">
            <thead>
                <tr><th>Name</th><th>Cashout</th><th>Interest</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php while ($row = $employees_result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td>₱<?= number_format($row['cashout'], 2) ?></td>
                        <td>₱<?= number_format($row['interest'], 2) ?></td>
                        <td><?= date("d-M-Y h:i A", strtotime($row['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No transactions found for the selected month.</p>
    <?php endif; ?>
</div>

<div id="analysis-section" class="hidden">
    <canvas id="cashoutChart" width="1100" height="380"></canvas>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const lastActiveSection = localStorage.getItem("lastActiveSection") || "table-section";
    toggleSection(lastActiveSection);

    attachTableSearch('cashoutSearch', '#cashoutTable');

    fetchYearlyData(); // Fetch annual data for the chart on page load
});

function fetchYearlyData() {
    const selectedYear = document.getElementById("year").value;

    fetch(`cashout.php?ajax=true&year=${selectedYear}`)
        .then(response => response.json())
        .then(data => {
            renderChart(data);
        })
        .catch(error => console.error("Error fetching yearly data:", error));
}

function renderChart(data) {
    const ctx = document.getElementById('cashoutChart').getContext('2d');

    const labels = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const cashoutValues = labels.map((_, i) => data.cashout[i + 1] || 0);
    const interestValues = labels.map((_, i) => data.interest[i + 1] || 0);

    if (window.myChart) {
        window.myChart.destroy(); // Destroy old chart before re-rendering
    }

    window.myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: "Total Cashout",
                    data: cashoutValues,
                    backgroundColor: "rgb(255, 179, 0)",
                    borderColor: "rgb(238, 255, 154)",
                    borderWidth: 1
                },
                {
                    label: "Total Interest",
                    data: interestValues,
                    backgroundColor: "rgb(0, 120, 8)",
                    borderColor: "rgb(0, 255, 38)",
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: false,  // Prevent auto-resizing
            maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'Month' } },
                y: { beginAtZero: true, title: { display: true, text: 'Amount (₱)' } }
            }
        }
    });
}

function toggleSection(sectionId) {
    document.getElementById('table-section').classList.add('hidden');
    document.getElementById('analysis-section').classList.add('hidden');
    document.getElementById(sectionId).classList.remove('hidden');

    localStorage.setItem("lastActiveSection", sectionId);

    if (sectionId === 'analysis-section') {
        fetchYearlyData(); // Ensure the chart always loads yearly data
    }
}
</script>

</body>
</html>