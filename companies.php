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

// ------------------------------------------------------------
// Make sure the companies table exists. Company names used to
// live only as free-text values typed into the "Company" field
// on the customer/employee forms (no real list anywhere), so this
// also seeds the table once from whatever distinct values are
// already sitting in the employees table.
// ------------------------------------------------------------
$conn->query("
    CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$conn->query("
    INSERT IGNORE INTO companies (name)
    SELECT DISTINCT TRIM(company) FROM employees
    WHERE company IS NOT NULL AND TRIM(company) <> ''
");

$success_message = "";
$error_message = "";

// ------------------------------------------------------------
// Add a company
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error_message = "Company name can't be empty.";
    } else {
        $stmt = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) {
            $success_message = "Company \"$name\" saved.";
        } else {
            $error_message = ($conn->errno === 1062)
                ? "\"$name\" already exists."
                : "Could not save company.";
        }
        $stmt->close();
    }
}

// ------------------------------------------------------------
// Rename a company (also renames it on every existing customer
// record so history and balances stay attached to the right name)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename') {
    $id = intval($_POST['id'] ?? 0);
    $new_name = trim($_POST['name'] ?? '');

    if ($id > 0 && $new_name !== '') {
        $old_stmt = $conn->prepare("SELECT name FROM companies WHERE id = ?");
        $old_stmt->bind_param("i", $id);
        $old_stmt->execute();
        $old_row = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        if ($old_row) {
            $old_name = $old_row['name'];

            $stmt = $conn->prepare("UPDATE companies SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $new_name, $id);
            if ($stmt->execute()) {
                $upd = $conn->prepare("UPDATE employees SET company = ? WHERE company = ?");
                $upd->bind_param("ss", $new_name, $old_name);
                $upd->execute();
                $upd->close();
                $success_message = "Renamed \"$old_name\" to \"$new_name\".";
            } else {
                $error_message = "Could not rename company.";
            }
            $stmt->close();
        }
    }
}

// ------------------------------------------------------------
// Delete a company (only removes it from the companies list —
// existing customer records that used the name are left as-is)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $success_message = "Company deleted.";
    }
}

// ------------------------------------------------------------
// List companies with how many customer/employee records
// currently reference each one
// ------------------------------------------------------------
$companies = [];
$sql = "
    SELECT c.id, c.name, c.created_at,
           (SELECT COUNT(*) FROM employees e
            WHERE e.company COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci) AS record_count
    FROM companies c
    ORDER BY c.name ASC
";
$result = $conn->query($sql);
if ($result) {
    $companies = $result->fetch_all(MYSQLI_ASSOC);
} elseif (!$error_message) {
    // A failed query used to fall through silently and just render the
    // same "No companies yet" empty state as a genuinely empty table,
    // making the two impossible to tell apart. Surface the real reason
    // instead so a query problem doesn't look like a data problem.
    $error_message = "Could not load companies (" . $conn->error . ").";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>COMPANIES</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0px;
            padding: 0;
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

        .add-company-form {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .add-company-form label { display: block; margin-bottom: 6px; font-weight: bold; }
        .add-company-form input[type="text"] { padding: 10px; min-width: 260px; }

        .transactions-container { margin-top: 10px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); overflow: auto; }
        .transactions-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .transactions-table th, .transactions-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .transactions-table thead { background: #1b5e20; color: white; text-transform: uppercase; font-size: 12px; }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            background: #e6f4e8;
            color: #1b5e20;
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

        .rename-form { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
        .rename-form input[type="text"] { padding: 6px 8px; width: 160px; }
        .row-actions { display: flex; gap: 8px; }

        .company-name-cell { display: flex; align-items: center; gap: 8px; }
        .company-link {
            font-weight: 700;
            color: #1b5e20;
            text-decoration: none;
            font-size: 14.5px;
        }
        .company-link:hover { text-decoration: underline; }
        .rename-toggle {
            background: transparent !important;
            color: #6b7280 !important;
            box-shadow: none !important;
            padding: 4px 6px !important;
            font-size: 12px !important;
        }
        .rename-toggle:hover { color: #1b5e20 !important; background: #eef6ee !important; }
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
        <li><button onclick="location.href='daily.php'"><i class="fas fa-calendar-day"></i>   DAILY SALES</button></li>
        <li><button onclick="location.href='report.php'"><i class="fas fa-chart-line"></i>   SALES REPORTS</button></li>
        <li><button onclick="location.href='cashout.php'"><i class="fas fa-money-bill-wave"></i>   CASHOUT REPORT</button></li>
        <li><button onclick="location.href='users.php'"><i class="fas fa-users-cog"></i>   USERS</button></li>
        <li><button onclick="location.href='tracking.php'"><i class="fas fa-bell"></i>   ADMIN TRACKING</button></li>
        <li><button onclick="location.href='index.php'"><i class="fas fa-sign-out-alt"></i>   LOGOUT</button></li>
    </ul>
</div>

<div class="main-content">
    <h1>COMPANIES</h1>

    <?php if ($success_message): ?>
        <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <form class="add-company-form" method="POST" action="companies.php">
        <input type="hidden" name="action" value="add">
        <div>
            <label for="name">Company name</label>
            <input type="text" id="name" name="name" placeholder="e.g. Acme Corp" required>
        </div>
        <button type="submit" class="btn"><i class="fas fa-plus"></i> Add company</button>
    </form>

    <a href="export_transactions.php" class="btn" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; margin-bottom:24px;">
        <i class="fas fa-file-csv"></i> Download All Transactions (Single CSV)
    </a>
    <a href="export_transactions.php?format=xlsx" class="btn" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; margin-bottom:24px; margin-left:8px;"
       title="One Excel file, with a separate sheet/tab for each company's transactions">
        <i class="fas fa-file-excel"></i> Download All (Separated by Company — Excel)
    </a>

    <div class="transactions-container">
        <div class="table-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="companySearch" placeholder="Search companies...">
        </div>
        <table class="transactions-table" id="companiesTable">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Records assigned</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr><td colspan="4">No companies yet. Add one above.</td></tr>
                <?php else: ?>
                    <?php foreach ($companies as $c): ?>
                        <tr>
                            <td>
                                <div class="company-name-cell">
                                    <a class="company-link"
                                       href="customer.php?company=<?= urlencode($c['name']) ?>"
                                       title="View everyone registered to <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($c['name']) ?>
                                    </a>
                                    <button type="button" class="rename-toggle" title="Rename company"
                                            onclick="toggleRename(<?= (int)$c['id'] ?>)">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                </div>
                                <form class="rename-form" id="rename-form-<?= (int)$c['id'] ?>" method="POST" action="companies.php" style="display:none;">
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>">
                                    <button type="submit" class="btn edit-btn"><i class="fas fa-save"></i></button>
                                </form>
                            </td>
                            <td><span class="badge"><?= (int)$c['record_count'] ?></span></td>
                            <td><?= htmlspecialchars($c['created_at']) ?></td>
                            <td class="row-actions">
                                <a href="customer.php?company=<?= urlencode($c['name']) ?>" class="btn show-btn"><i class="fas fa-eye"></i> View / Edit</a>
                                <a href="export_transactions.php?company=<?= urlencode($c['name']) ?>" class="btn print-btn" title="Download <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>'s transactions as CSV"><i class="fas fa-file-csv"></i> Download</a>
                                <form method="POST" action="companies.php" onsubmit="return confirm('Delete company \'<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>\'? This does not delete its existing customer records.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn delete-btn"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    function toggleRename(id) {
        const form = document.getElementById('rename-form-' + id);
        const opening = form.style.display === 'none' || form.style.display === '';
        form.style.display = opening ? 'flex' : 'none';
        if (opening) {
            const input = form.querySelector('input[type="text"]');
            input.focus();
            input.select();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        attachTableSearch('companySearch', '#companiesTable');
    });
</script>
</body>
</html>
