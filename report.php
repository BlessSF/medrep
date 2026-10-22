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

function fetch_data($conn, $month, $year, $view) {
    $data = [];

    // Fetch Total Customers and Sales for Summary
    $summary_sql = "SELECT 
                COUNT(DISTINCT CONCAT(name, company)) AS total_customers, 
                SUM(dinein + takeout) AS total_sales,
                SUM(dinein) AS dine_in_sales,
                SUM(takeout) AS take_out_sales
                FROM employees 
                WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?";

    
    $stmt = $conn->prepare($summary_sql);
    $stmt->bind_param("ii", $month, $year);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $data['total_customers'] = $result['total_customers'] ?? 0;
    $data['total_sales'] = $result['total_sales'] ?? 0;
    $data['dine_in_sales'] = $result['dine_in_sales'] ?? 0;  // Now using dinein column
    $data['take_out_sales'] = $result['take_out_sales'] ?? 0; // Now using takeout column
    
    $stmt->close();

    if ($view === "yearly_sales") {
        $yearly_sales_sql = "SELECT MONTH(created_at) as month, SUM(dinein + takeout) as total_sales 
                             FROM employees 
                             WHERE YEAR(created_at) = ? 
                             GROUP BY MONTH(created_at)";
        $stmt = $conn->prepare($yearly_sales_sql);
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $result = $stmt->get_result();
        $yearly_sales = array_fill(1, 12, 0);
        $target = 100000;  // Monthly sales target
        
        while ($row = $result->fetch_assoc()) {
            $percentage = ($row['total_sales'] / $target) * 100;
            $yearly_sales[$row['month']] = round($percentage, 2);
        }
        $stmt->close();
        $data['yearly_sales'] = array_values($yearly_sales);
    }
    
    if ($view === "monthly_dine_takeout") {
        $monthly_dine_takeout_sql = "SELECT MONTH(created_at) as month, 
                                     SUM(dinein) as total_dinein, 
                                     SUM(takeout) as total_takeout 
                                     FROM employees 
                                     WHERE YEAR(created_at) = ? 
                                     GROUP BY MONTH(created_at)";
        $stmt = $conn->prepare($monthly_dine_takeout_sql);
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $result = $stmt->get_result();
        $dine_in = array_fill(1, 12, 0);
        $take_out = array_fill(1, 12, 0);
        while ($row = $result->fetch_assoc()) {
            $dine_in[$row['month']] = $row['total_dinein'];
            $take_out[$row['month']] = $row['total_takeout'];
        }
        $stmt->close();
        $data['dine_in'] = array_values($dine_in);
        $data['take_out'] = array_values($take_out);
    }

    if ($view === "monthly_transactions") {
        $transactions_sql = "SELECT 
                            COUNT(CASE WHEN dinein > 0 THEN 1 END) AS total_dine_in_transactions,
                            COUNT(CASE WHEN takeout > 0 THEN 1 END) AS total_take_out_transactions
                            FROM employees 
                            WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?";
        $stmt = $conn->prepare($transactions_sql);
        $stmt->bind_param("ii", $month, $year);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $data['total_dine_in_transactions'] = $result['total_dine_in_transactions'] ?? 0;
        $data['total_take_out_transactions'] = $result['total_take_out_transactions'] ?? 0;
        $stmt->close();
    }    

    return $data;
}


$view = $_GET['view'] ?? 'monthly';
$sales_data = fetch_data($conn, $selected_month, $selected_year, $view);

// ------------------------------------------------------------
// Financial overview: unlike the sales figures above (which are
// scoped to the selected month/year), balances are running totals
// as of right now, across every medrep -- so admins/staff can see
// total money owed to/by the business, and which companies carry
// outstanding balances, at a glance. Reuses the exact same balance
// formula as the Customers list (customer.php) so the numbers here
// always agree with what's shown per medrep.
// ------------------------------------------------------------
function fetch_balance_overview($conn) {
    // Deposits count normally no matter the payment method. Card only
    // changes how Dine In/Takeout/Cashout/Interest/Gift Card are
    // handled -- see total_record below, same rule as the Customers list.
    $balance_sql = "SELECT
            COALESCE(NULLIF(TRIM(e.company), ''), 'Unassigned') AS company,
            SUM(e.deposit + e.receiver_amount) AS total_deposit,
            SUM(CASE WHEN COALESCE(e.payment_method,'') <> 'card' THEN e.dinein + e.takeout + e.giftcard ELSE 0 END) AS total_payable,
            SUM(CASE WHEN COALESCE(e.payment_method,'') <> 'card' THEN e.cashout ELSE 0 END) AS total_cashout,
            SUM(e.deposit) - (
                SUM(CASE WHEN COALESCE(e.payment_method,'') <> 'card' THEN e.dinein + e.takeout + e.giftcard ELSE 0 END)
                + SUM(CASE WHEN COALESCE(e.payment_method,'') <> 'card' THEN e.cashout + e.interest ELSE 0 END)
                + SUM(e.sender_amount)
            ) AS total_balance,
            -SUM(CASE WHEN e.payment_method = 'card' THEN e.dinein + e.takeout + e.cashout + e.interest + e.giftcard ELSE 0 END) AS total_record
        FROM employees e
        GROUP BY company
        ORDER BY total_balance ASC";
    $result = $conn->query($balance_sql);
    $companies = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $overview = ['total_deposit' => 0, 'total_payable' => 0, 'total_cashout' => 0, 'total_balance' => 0, 'total_record' => 0];
    foreach ($companies as $c) {
        $overview['total_deposit'] += (float) $c['total_deposit'];
        $overview['total_payable'] += (float) $c['total_payable'];
        $overview['total_cashout'] += (float) $c['total_cashout'];
        $overview['total_balance'] += (float) $c['total_balance'];
        $overview['total_record'] += (float) $c['total_record'];
    }
    return ['companies' => $companies, 'overview' => $overview];
}

$balance_data = fetch_balance_overview($conn);
$company_balances = $balance_data['companies'];
$balance_overview = $balance_data['overview'];

if (isset($_GET['ajax'])) {
    echo json_encode($sales_data);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SALES REPORT</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        body {
    font-family: Arial, sans-serif;
    background-color: #f8f9fa;
    margin: 0;
    display: flex;
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
    margin-left: 220px;
    padding: 20px;
    flex-grow: 1;
    text-align: center;
}

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

/* Chart Styling */
.charts-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 20px;
    margin-top: 20px;
    width: 100%;
}

/* General Chart Box */
.chart-box {
    background:rgb(227, 251, 203);
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    flex: 1;
    max-width: 700px;
    min-width: 200px;
}

/* Medium-sized Chart Box */
.mid-chart-box {
    background: rgb(227, 251, 203);
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    flex: 1;
    max-width: 400px;
    min-width: 200px;
}

/* Full-Width Chart */
.full-width-chart {
    width: 100%;
    max-width: 1150px;
    margin: 0 auto;
}

/* Ensure Canvas Resizes Correctly */
.chart-box canvas,
.mid-chart-box canvas,
.full-width-chart canvas {
    width: 100% !important;
    height: auto !important;
    max-height: 250px;
}
.h2 {
    font-size: 10px;
}

.section-title {
    text-align: left;
    margin: 30px 0 12px;
    color: #1b5e20;
}
.section-subtitle {
    font-size: 13px;
    font-weight: normal;
    color: #666;
}

.balance-box.is-negative p { color: #c62828; }
.balance-box.is-positive p { color: #1b5e20; }

.company-balances-box {
    background: rgb(227, 251, 203);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    text-align: left;
    box-shadow: 0 0 10px rgba(0,0,0,0.08);
}

.company-balances-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    text-transform: none;
}
.company-balances-table th {
    background: #1b5e20;
    color: #fff;
    padding: 10px 12px;
    text-align: center;
    font-size: 13px;
}
.company-balances-table th:first-child { text-align: left; }
.company-balances-table td {
    padding: 10px 12px;
    text-align: center;
    border-bottom: 1px solid #e2e2e2;
    font-size: 14px;
}
.company-balances-table tbody tr:hover { background: #f4fbf0; }
.balance-negative { color: #c62828; font-weight: bold; }
.balance-positive { color: #1b5e20; font-weight: bold; }
</style>
    <link rel="stylesheet" href="assets/style.css">
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
    <h1>Sales Report</h1>

    <label for="month">Month:</label>
<select id="month" onchange="updateReport()">
    <script>
        let currentMonth = new Date().getMonth() + 1; // JavaScript months are 0-indexed
        for (let m = 1; m <= 12; m++) {
            document.write(`<option value="${m}" ${m === currentMonth ? "selected" : ""}>${new Date(2000, m - 1, 1).toLocaleString('default', { month: 'long' })}</option>`);
        }
    </script>
</select>

<label for="year">Year:</label>
<select id="year" onchange="updateReport()">
    <script>
        let currentYear = new Date().getFullYear();
        for (let y = currentYear; y >= 2000; y--) {
            document.write(`<option value="${y}" ${y === currentYear ? "selected" : ""}>${y}</option>`);
        }
    </script>
</select>

<h1></h1>
    <div class="summary-boxes">
        <div class="summary-box"><h3>Total Customers</h3><p id="totalCustomers">0</p></div>
        <div class="summary-box"><h3>Total Sales</h3><p id="totalSales">0</p></div>
        <div class="summary-box"><h3>Dine In Sales</h3><p id="dineInSales">0</p></div>
        <div class="summary-box"><h3>Takeout Sales</h3><p id="takeoutSales">0</p></div>
    </div>

    <h2 class="section-title">Financial Overview <span class="section-subtitle">(all-time, as of today)</span></h2>
    <div class="summary-boxes">
        <div class="summary-box"><h3>Total Deposits</h3><p>₱<?= number_format($balance_overview['total_deposit'], 2) ?></p></div>
        <div class="summary-box"><h3>Total Payable</h3><p>₱<?= number_format($balance_overview['total_payable'], 2) ?></p></div>
        <div class="summary-box"><h3>Total Cashouts</h3><p>₱<?= number_format($balance_overview['total_cashout'], 2) ?></p></div>
        <div class="summary-box balance-box <?= $balance_overview['total_balance'] < 0 ? 'is-negative' : 'is-positive' ?>">
            <h3>Net Outstanding Balance</h3>
            <p>₱<?= number_format($balance_overview['total_balance'], 2) ?></p>
        </div>
        <div class="summary-box balance-box <?= $balance_overview['total_record'] < 0 ? 'is-negative' : 'is-positive' ?>">
            <h3>Record Total (Card)</h3>
            <p>₱<?= number_format($balance_overview['total_record'], 2) ?></p>
        </div>
    </div>

    <div class="company-balances-box">
        <h2 style="margin-top:0;">Company Balances</h2>
        <p style="margin-top:-6px; font-size:14px; color:#666; text-transform:none;">Remaining balance per company — red means the company/its medreps currently owe a payable, green means they're in credit.</p>
        <div style="overflow-x:auto;">
        <table class="company-balances-table">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Total Deposit</th>
                    <th>Total Payable</th>
                    <th>Total Cashout</th>
                    <th>Remaining Balance</th>
                    <th>Record Total (Card)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($company_balances)): ?>
                <tr><td colspan="6">No company data yet.</td></tr>
                <?php else: ?>
                <?php foreach ($company_balances as $c): ?>
                <tr>
                    <td style="text-align:left;"><?= htmlspecialchars($c['company']) ?></td>
                    <td>₱<?= number_format((float)$c['total_deposit'], 2) ?></td>
                    <td>₱<?= number_format((float)$c['total_payable'], 2) ?></td>
                    <td>₱<?= number_format((float)$c['total_cashout'], 2) ?></td>
                    <td class="<?= (float)$c['total_balance'] < 0 ? 'balance-negative' : 'balance-positive' ?>">
                        ₱<?= number_format((float)$c['total_balance'], 2) ?>
                    </td>
                    <td class="<?= (float)$c['total_record'] < 0 ? 'balance-negative' : 'balance-positive' ?>">
                        ₱<?= number_format((float)$c['total_record'], 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="charts-container">
    <div class="chart-box">
    <h1 style="margin-bottom: 0;">TOTAL SALES FOR THE YEAR</h1>
    <p style="margin-top: 0; font-size: 14px; color: #666;">Target Sales: 100,000</p>
    <canvas id="totalSalesChart"></canvas>
</div>

            <div class="mid-chart-box">
                <h1>TOTAL ORDERS</h1>
                <canvas id="dineTakeOutPieChart"></canvas>
            </div>
        </div>
        <h1></h1>
        <div class="chart-box full-width-chart">
            <h1>MONTHLY DINE IN AND TAKEOUT SALES</h1>
            <canvas id="monthlyDineTakeOutChart"></canvas>
        </div>
    </div>
</div>
</div>

<script>
    let totalSalesChart;
let dineTakeOutPieChart;
let monthlyDineTakeOutChart;

function updateTotalSalesChart(year) {
    fetch(`?year=${year}&ajax=true&view=yearly_sales`)
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById("totalSalesChart").getContext("2d");
        if (totalSalesChart) totalSalesChart.destroy();

        totalSalesChart = new Chart(ctx, {
            type: "line",
            data: {
                labels: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
                datasets: [{
                    label: "", // leave this blank so it won't appear in tooltip or legend
                    data: data.yearly_sales,
                    backgroundColor: "#ffc107",
                    borderColor: "#1b5e20",
                    borderWidth: 2,
                    fill: false
                }]
            },
            options: { 
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + "%"; // only show value
                            }
                        }
                    }
                },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + "%";
                            }
                        }
                    } 
                }
            }
        });
    });
}



function updateMonthlyDineTakeOutChart(year) {
    fetch(`?year=${year}&ajax=true&view=monthly_dine_takeout`)
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById("monthlyDineTakeOutChart").getContext("2d");
        if (monthlyDineTakeOutChart) monthlyDineTakeOutChart.destroy();
        monthlyDineTakeOutChart = new Chart(ctx, {
            type: "bar",
            data: {
                labels: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
                datasets: [
                    { label: "Dine In", data: data.dine_in, backgroundColor: "#28a745" },
                    { label: "Take Out", data: data.take_out, backgroundColor: "#ffc107" }
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    });
}

function updateDineTakeOutPieChart(month, year) {
    fetch(`?month=${month}&year=${year}&ajax=true&view=monthly_transactions`)
    .then(response => response.json())
    .then(data => {
        const ctx = document.getElementById("dineTakeOutPieChart").getContext("2d");
        if (dineTakeOutPieChart) dineTakeOutPieChart.destroy();
        dineTakeOutPieChart = new Chart(ctx, {
            type: "pie",
            data: {
                labels: ["Dine In Transactions", "Take Out Transactions"],
                datasets: [{
                    data: [data.total_dine_in_transactions, data.total_take_out_transactions, ], // Reflects transaction count
                    backgroundColor: ["#03c03c", "#ffe135"]
                }]
            }
        });
    });
}

function updateReport() {
    const month = document.getElementById("month").value;
    const year = document.getElementById("year").value;

    fetch(`?month=${month}&year=${year}&ajax=true`)
    .then(response => response.json())
    .then(data => {
        document.getElementById("totalCustomers").textContent = data.total_customers;
        document.getElementById("totalSales").textContent = data.total_sales;
        document.getElementById("dineInSales").textContent = data.dine_in_sales;
        document.getElementById("takeoutSales").textContent = data.take_out_sales;

        updateDineTakeOutPieChart(month, year);
    });

    updateTotalSalesChart(year);
    updateMonthlyDineTakeOutChart(year);
}

document.addEventListener("DOMContentLoaded", function() {
    updateReport();
});

</script>

</body>
</html>