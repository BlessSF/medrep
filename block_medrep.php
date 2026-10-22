<?php
session_start();

// Establish database connection
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$admin_username = $_SESSION['username'];

// ------------------------------------------------------------
// List every medrep that is currently blocked. A medrep is grouped
// from rows in the employees table the same way customer.php does it,
// so "blocked" here means the most recent status for that name is
// 'block' (MAX(e.block) matches how customer.php / block.php /
// unblock.php already treat this column).
// ------------------------------------------------------------
$blocked = [];
$sql = "
    SELECT e.name,
           MAX(e.company)      AS company,
           MAX(e.created_at)   AS latest_transaction,
           MAX(e.block)        AS block_status
    FROM employees e
    GROUP BY e.name
    HAVING LOWER(TRIM(block_status)) = 'block'
    ORDER BY e.name ASC
";
$result = $conn->query($sql);
if ($result) {
    $blocked = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $error_message = "Could not load blocked medreps (" . $conn->error . ").";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>BLOCK MEDREP</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: rgb(227, 251, 203);
        }
        .sidebar {
            width: 230px;
            height: 100vh;
            background-color: #1b5e20;
            color: #fff;
            position: fixed;
            padding-top: 20px;
        }
        .sidebar h2 { text-align: center; width: 100%; display: flex; justify-content: center; align-items: center; }
        .sidebar ul { list-style-type: none; padding: 0; }
        .sidebar ul li { padding: 15px; text-align: center; }
        .sidebar ul li button {
            width: 100%; padding: 15px; background-color: #abd67c; color: #000;
            border: none; cursor: pointer; font-weight: bold; font-size: 15px; border-radius: 5px;
        }
        .sidebar ul li button:hover { background-color: rgb(36, 125, 59); }

        .main-content { margin-left: 230px; padding: 20px; }

        .transactions-container { margin-top: 10px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); overflow: auto; }
        .transactions-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .transactions-table th, .transactions-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .transactions-table thead { background: #1b5e20; color: white; text-transform: uppercase; font-size: 12px; }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            background: #fdeced;
            color: #e0364a;
            font-weight: 700;
            font-size: 12.5px;
        }

        .success-message, .error-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }
        .success-message { background: #e7f8ee; color: #16a34a; }
        .error-message { background: #fdeced; color: #e0364a; }

        .row-actions { display: flex; gap: 8px; }
        .empty-state { text-align: center; padding: 24px; color: #666; }
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
    <h1><i class="fas fa-user-lock"></i> BLOCK MEDREP</h1>
    <p style="color:#555; margin-top:-8px;">Medreps currently blocked from submitting new transactions. Unblock a name here to restore access.</p>

    <?php if (!empty($error_message)): ?>
        <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="transactions-container">
        <div class="table-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="blockedSearch" placeholder="Search blocked medreps...">
        </div>
        <table class="transactions-table" id="blockedTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Last Transaction</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blocked)): ?>
                    <tr><td colspan="5" class="empty-state">No blocked medreps right now.</td></tr>
                <?php else: ?>
                    <?php foreach ($blocked as $b): ?>
                        <tr data-name="<?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= htmlspecialchars($b['company'] ?? '') ?></td>
                            <td><?= htmlspecialchars($b['latest_transaction'] ?? '') ?></td>
                            <td><span class="badge">BLOCKED</span></td>
                            <td class="row-actions">
                                <button type="button" class="show-btn btn"
                                        onclick="location.href='customer_profile.php?name=<?= urlencode($b['name']) ?>'">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" class="unblock-btn btn" data-name="<?= htmlspecialchars($b['name']) ?>">
                                    <i class="fas fa-lock-open"></i> Unblock
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="assets/ajax-forms.js"></script>
<script>
    attachTableSearch('blockedSearch', '#blockedTable', { emptyMessage: 'No blocked medreps match your search.' });

    document.getElementById('blockedTable').addEventListener('click', function (event) {
        const btn = event.target.closest('.unblock-btn');
        if (!btn) return;

        const name = btn.getAttribute('data-name');
        ajaxAction({
            url: 'unblock.php?name=' + encodeURIComponent(name),
            confirmMessage: 'Are you sure you want to unblock "' + name + '"?',
            onSuccess: function () {
                const row = btn.closest('tr');
                if (row) row.remove();

                const tbody = document.querySelector('#blockedTable tbody');
                if (tbody && tbody.querySelectorAll('tr').length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="5" class="empty-state">No blocked medreps right now.</td>';
                    tbody.appendChild(tr);
                }
            }
        });
    });
</script>
</body>
</html>