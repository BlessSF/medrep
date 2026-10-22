<?php 
session_start();




require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ------------------------------------------------------------
// Add a new medrep to a company. A "medrep" only shows up in the
// list below because it's grouped from rows in the employees
// table, so adding one just inserts a zero-amount starting row
// for that name/company -- the same table every deposit/cashout
// transaction already writes to.
// ------------------------------------------------------------
// AJAX callers (see assets/ajax-forms.js) get JSON back instead of a
// redirect, so the medrep list can refresh in place without a full
// page reload.
$isAjaxAddMedrep = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    // Fallback for hosting/proxy setups that strip the X-Requested-With
    // header before PHP sees it -- without this, such requests get
    // treated as a plain form submit and redirected, which fetch()
    // silently follows and returns as a misleading "200 OK" HTML page.
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_medrep') {
    $new_name = trim($_POST['medrep_name'] ?? '');
    $new_company = trim($_POST['medrep_company'] ?? '');
    $redirect_company = isset($_POST['redirect_company']) ? $_POST['redirect_company'] : '';
    $add_medrep_message = '';
    $add_medrep_ok = false;

    if ($new_name === '' || $new_company === '') {
        $add_medrep_message = "Medrep name and company are both required.";
        $_SESSION['error_message'] = $add_medrep_message;
    } else {
        $check = $conn->prepare("SELECT id FROM employees WHERE UPPER(name) = UPPER(?) LIMIT 1");
        $check->bind_param("s", $new_name);
        $check->execute();
        $already_exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($already_exists) {
            $add_medrep_message = "\"$new_name\" is already on the medrep list.";
            $_SESSION['error_message'] = $add_medrep_message;
        } else {
            $cashier_name = $_SESSION['username'] ?? 'admin';
            $remarks = 'Added via Medrep List';
            $payment_method = 'na';
            $insert = $conn->prepare("INSERT INTO employees
                (name, company, deposit, dinein, takeout, giftcard, remarks, payment_method, cashier, created_at)
                VALUES (?, ?, 0, 0, 0, 0, ?, ?, ?, NOW())");
            $insert->bind_param("sssss", $new_name, $new_company, $remarks, $payment_method, $cashier_name);
            if ($insert->execute()) {
                $add_medrep_ok = true;
                $add_medrep_message = "✅ \"$new_name\" added to $new_company.";
                $_SESSION['success_message'] = $add_medrep_message;
                $redirect_company = $new_company;
            } else {
                $add_medrep_message = "Could not add medrep: " . $insert->error;
                $_SESSION['error_message'] = $add_medrep_message;
            }
            $insert->close();
        }
    }

    if ($isAjaxAddMedrep) {
        unset($_SESSION['success_message'], $_SESSION['error_message']);
        header('Content-Type: application/json');
        echo json_encode(['success' => $add_medrep_ok, 'message' => $add_medrep_message]);
        exit;
    }

    header("Location: customer.php" . ($redirect_company !== '' ? '?company=' . urlencode($redirect_company) : ''));
    exit;
}

if (isset($_SESSION['success_message'])) {
  echo "<div class='success-message'>" . $_SESSION['success_message'] . "</div>";
  unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
  echo "<div class='error-message'>" . $_SESSION['error_message'] . "</div>";
  unset($_SESSION['error_message']);
}
if (isset($_SESSION['message'])) {
  echo "<div class='message-box'>{$_SESSION['message']}</div>";
  unset($_SESSION['message']);
}


// After session check and DB connection
$admin_username = $_SESSION['username'];
$admin_query = "SELECT username FROM users WHERE username = ?";
$admin_stmt = $conn->prepare($admin_query);
if (!$admin_stmt) {
    die("Query prepare failed: " . $conn->error);
}
$admin_stmt->bind_param("s", $admin_username);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin_row = $admin_result->fetch_assoc();
$admin_name = $admin_row['username'] ?? '';
$admin_stmt->close();

// Fetch companies for filtering -- pulled from the companies table
// (managed on companies.php) so adding/renaming/deleting a company
// there is reflected here immediately, instead of a static list.
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
$company_sql = "SELECT name AS company FROM companies ORDER BY name ASC";
$company_result = $conn->query($company_sql);
$company_list = $company_result ? $company_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch unique customers based on company filter and search query
$company_filter = isset($_GET['company']) ? $_GET['company'] : '';
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT 
    UPPER(e.name) AS name, 
    e.company,

    -- Deposits count normally no matter the payment method. Card only
    -- changes how Dine In/Takeout/Cashout/Interest/Gift Card are
    -- handled -- card-paid rows are excluded from payable/cashout/
    -- balance below since they're settled outside this running total.
    -- COALESCE each field to 0 before adding: SUM(a + b) computes the
    -- addition PER ROW first, and in SQL NULL + anything = NULL, so a
    -- single row with a NULL receiver_amount (not 0) would silently
    -- drop that row's entire deposit out of the total. Wrapping each
    -- field individually is what customer_profile.php and
    -- receivables_breakdown.php already do, which is why this page's
    -- totals could drift below theirs for a medrep with any blank
    -- field on any transaction row.
    SUM(COALESCE(e.deposit,0) + COALESCE(e.receiver_amount,0)) AS total_deposit,
    
    -- Payable always includes dine-in/takeout/giftcard usage, regardless of
    -- how it was paid -- it previously only counted these when
    -- payment_method was 'na'/'paid', so anything marked cash/card/giftcard
    -- silently dropped out of the payable and balance totals entirely.
    -- Card-paid rows are excluded again here, since those are settled
    -- separately outside the deposit/payable running balance.
    -- NORMALIZED_PM: strips \\r and \\n (plain SQL TRIM() does not touch those,
    -- only spaces) before trimming/lowercasing, matching customer_profile.php,
    -- so a payment_method like 'card\\r\\n' is detected as card here the same
    -- way it is on the profile page. Without this, the two pages disagreed on
    -- rows with trailing whitespace/newlines and Payable/Balance drifted apart.
    -- Same COALESCE reasoning as total_deposit above: a NULL dinein/takeout/
    -- giftcard on one row used to zero out that whole row's payable.
    SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) <> 'card' THEN COALESCE(e.dinein,0) + COALESCE(e.takeout,0) + COALESCE(e.giftcard,0) ELSE 0 END) AS total_payable,

    SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) <> 'card' THEN COALESCE(e.cashout,0) ELSE 0 END) AS total_cashout,

    -- Giftcard Calculation
    SUM(COALESCE(e.giftcard,0)) 
    - SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) = 'giftcard' THEN COALESCE(e.dinein,0) ELSE 0 END)
    - SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) = 'giftcard' THEN COALESCE(e.takeout,0) ELSE 0 END) AS total_giftcard,

    (SUM(COALESCE(e.deposit,0)) + SUM(COALESCE(e.receiver_amount,0))) - 
    (
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) <> 'card' THEN COALESCE(e.dinein,0) + COALESCE(e.takeout,0) + COALESCE(e.giftcard,0) ELSE 0 END) + 
        SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(e.payment_method,''), '\r', ''), '\n', ''))) <> 'card' THEN COALESCE(e.cashout,0) + COALESCE(e.interest,0) ELSE 0 END) +
        SUM(COALESCE(e.sender_amount,0))
    ) AS total_balance,

    MAX(e.created_at) AS latest_transaction,
    MAX(e.block) AS block_status
FROM employees e
WHERE 1";


$params = [];
$types = "";

// Apply filters if available
if (!empty($company_filter)) {
    $sql .= " AND e.company = ?";
    $params[] = $company_filter;
    $types .= "s";
}

if (!empty($search_filter)) {
    $sql .= " AND e.name LIKE ?";
    $params[] = "%" . $search_filter . "%";
    $types .= "s";
}

$sql .= " GROUP BY e.name ORDER BY latest_transaction DESC";

// Prepare the statement
$stmt = $conn->prepare($sql);
if (!$stmt) {
    // Surfaces the real SQL error instead of a blank HTTP 500 caused by
    // calling bind_param()/execute() on a failed (false) prepare().
    die("Query prepare failed: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$employees = [];
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <title>MEDREP LIST</title>
  <style>
body {
    font-family: Arial, sans-serif;
    margin: 0;
    padding: 0;
    background:rgb(227, 251, 203);
    text-transform: uppercase;
}
form {
    display: flex;
    align-items: center;
    gap: 10px;
    background:rgb(227, 251, 203);
    padding: 10px;
    border-radius: 8px;
    width: fit-content;
}

label {
    font-weight: bold;
    font-size: 14px;
    color: #333;
}
select, input[type="text"], button {
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 14px;
}

select {
    background: #fff;
    cursor: pointer;
}

input[type="text"] {
    width: 200px;
}


/* Sidebar Styles */
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
       


/* Main Content (MEDREP LIST Section) */
.main-content {
    margin-left: 232px; /* Ensures content is not hidden behind the sidebar */
    padding: 8px;
    color: #333;
}

/* Table Styling */
table {
    table-layout: fixed;
    border-collapse: collapse;
    width: 100%;
    border: 1px solid black;
}

th, td {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

th {
    background-color: #1b5e20;
    color: white;
    height: 40px;
    border: 1px solid black;
    text-align: center;
}


.clickable-row:hover {
    background-color: #e8f5e9;
    cursor: pointer;
}

th:nth-child(8), td:nth-child(8) {
    width: 90px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(7), td:nth-child(7) {
    width: 100px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(6), td:nth-child(6) {
    width: 110px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(5), td:nth-child(5) {
    width: 110px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(4), td:nth-child(4) {
    width: 110px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(3), td:nth-child(3) {
    width: 110px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(1), td:nth-child(1) {
    width: 200px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}
th:nth-child(2), td:nth-child(2) {
    width: 200px; /* Adjust width as needed */
    text-align: center;
    white-space: nowrap;
}

th:nth-child(9), td:nth-child(9) {
    width: 128px;
    overflow: visible;      /* don't clip the action buttons */
    white-space: normal;
}

/* Actions column: compact icon-only buttons in a single row instead of
   four full-text buttons stacked 2x2. Block/Unblock are mutually
   exclusive per row (see $isBlocked below), so only one ever renders. */
.row-actions {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    padding: 4px !important;
}

.row-actions .icon-btn {
    width: 30px;
    height: 30px;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px !important;
    border-radius: 7px !important;
    flex: none !important;
    /* background/color intentionally left to assets/style.css so
       Edit/Delete/Block/Unblock keep their distinct semantic colors */
}

.table-scroll {
    overflow-x: auto;
}

.list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    width: 100%;
}

.add-medrep-btn {
    white-space: nowrap;
}



/* Delete Button */
.delete-button {
    background-color: #1b5e20;
    color: white;
    border: none;
    padding: 7px 10px;
    cursor: pointer;
    border-radius: 3px;
    font-size: 0.9em;
    transition: background 0.3s ease-in-out;
}

.delete-button:hover {
    background-color: rgb(186, 230, 139);
}

.modal {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        text-transform: uppercase;
        font-family: Arial, sans-serif;
        color: #333;
    }
    
    .modal h2 {
        color: #1b5e20;
    }
    
    .modal input {
        width: 100%;
        padding: 8px;
        margin: 5px 0;
        border: 1px solid #ddd;
        border-radius: 4px;
        text-transform: uppercase;
    }
    
    .modal button {
        padding: 10px;
        border: none;
        cursor: pointer;
        font-size: 0.9em;
        border-radius: 3px;
        transition: background 0.3s ease-in-out;
    }
    
    .button {
        background-color: #1b5e20;
        color: white;
    border: none;
    padding: 7px 12px;
    cursor: pointer;
    border-radius: 3px;
    font-size: 0.9em;
    transition: background 0.3s ease-in-out;
}

.button:hover {
    background-color:rgb(186, 230, 139);

}
.submit {
    background-color: #1b5e20;
    color: white;
    border: none;
    padding: 7px 12px;
    cursor: pointer;
    border-radius: 3px;
    font-size: 0.9em;
    transition: background 0.3s ease-in-out;
}

.submit:hover {
    background-color:rgb(186, 230, 139);
}
@media (max-width: 768px) {
    .sidebar {
        width: 220px;
    }

    .main-content {
        margin-left: 220px;
    }
}

@media (max-width: 576px) {
    .sidebar {
        width: 100%;
        position: relative;
    }

    .main-content {
        margin-left: 0;
    }
}
.modal form {
    display: block; /* ensures vertical layout */
}
.modal label {
    display: block;
    margin-top: 10px;
}
.modal input {
    display: block;
    width: 90%;
}
.success-message {
    position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        padding: 10px 20px;
        background-color:rgb(218, 246, 189);
        color: black;
        border: 1px solid #c3e6cb;
        border-radius: 4px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        text-align: center;
        font-weight: bold;
}
.error-message {
    position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        padding: 10px 20px;
        background-color:rgb(218, 246, 189);
        color: black;
        border: 1px solid #c3e6cb;
        border-radius: 4px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        text-align: center;
        font-weight: bold;

}
.message-box {
  position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        padding: 10px 20px;
        background-color:rgb(218, 246, 189);
        color: black;
        border: 1px solid #c3e6cb;
        border-radius: 4px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        text-align: center;
        font-weight: bold;

}


    </style>
  <script>
    // NOTE: this page used to hijack these forms/links via fetch()
    // (assets/ajax-forms.js) for a flicker-free save. That's been
    // removed -- in this hosting environment fetch() to these same
    // endpoints was reliably coming back broken (non-JSON/redirected)
    // even though a normal browser form submission to the identical
    // endpoint works fine, so continuing to build on fetch() here
    // wasn't reliable. Everything below is now a plain form submit /
    // link navigation, which the backend PHP already fully supports
    // (that's the "non-AJAX" branch that was always there).
    function goToProfile(name) {
      window.location.href = 'customer_profile.php?name=' + encodeURIComponent(name);
    }

    function confirmDelete(name) {
      if (!window.confirm('Are you sure you want to delete this customer?')) return;
      window.location.href = 'delete_customer.php?name=' + encodeURIComponent(name);
    }

    // Modal functions for editing
    function openModal(name, company) {
      document.getElementById('edit_name').value = name;
      document.getElementById('edit_company').value = company;
      document.getElementById('original_name').value = name;
      document.getElementById('editModal').style.display = 'block';
    }

    function closeModal() {
      document.getElementById('editModal').style.display = 'none';
    }

    // Modal functions for adding a medrep
    function openAddMedrepModal() {
      document.getElementById('addMedrepModal').style.display = 'block';
      document.getElementById('medrep_name').focus();
    }

    function closeAddMedrepModal() {
      document.getElementById('addMedrepModal').style.display = 'none';
    }

    // Form submission via Enter key
    function handleKeyPress(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        document.getElementById("filterForm").submit();
      }
    }

    document.addEventListener("DOMContentLoaded", function () {
      // Action buttons (edit/delete/block/unblock) are handled with ONE
      // delegated listener on the table instead of binding a handler to
      // each button.
      const medrepTable = document.querySelector(".table-scroll table");
      if (medrepTable) {
        medrepTable.addEventListener("click", function (event) {
          const btn = event.target.closest(".edit-btn, .delete-btn, .block-btn, .view-blocked-btn");
          if (!btn) return;
          event.stopPropagation();

          const name = btn.getAttribute("data-name");

          if (btn.classList.contains("edit-btn")) {
            openModal(name, btn.getAttribute("data-company"));
          } else if (btn.classList.contains("delete-btn")) {
            confirmDelete(name);
          } else if (btn.classList.contains("block-btn")) {
            // Blocking hands off to the dedicated "Block Medrep" page,
            // which is where blocked medreps are reviewed and unblocked from.
            if (window.confirm('Are you sure you want to block this customer?')) {
              window.location.href = 'block.php?name=' + encodeURIComponent(name);
            }
          } else if (btn.classList.contains("view-blocked-btn")) {
            window.location.href = 'block_medrep.php';
          }
        });
      }

      // Make table rows clickable to view profile
      document.querySelectorAll(".clickable-row").forEach(row => {
        row.addEventListener("click", function () {
          const name = this.getAttribute("data-name");
          window.location.href = 'customer_profile.php?name=' + encodeURIComponent(name);
        });
      });

      // Edit Customer and Add Medrep forms are now plain <form> submits
      // (see action="edit.php" / action="customer.php" above) -- no JS
      // hijacking needed. The PHP redirects back to customer.php with
      // a session flash message on success/failure either way.

      // Setup the search suggestion box
      const searchInput = document.getElementById("search");
      const suggestionsBox = document.getElementById("suggestions");
      searchInput.addEventListener("keyup", function () {
        const query = this.value.trim();
        if (query.length >= 2) {
          fetch("search.php?q=" + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
              suggestionsBox.innerHTML = "";
              if (data.length > 0) {
                data.forEach(item => {
                  const div = document.createElement("div");
                  div.textContent = item;
                  div.style.padding = "5px";
                  div.style.cursor = "pointer";
                  div.addEventListener("click", () => {
                    searchInput.value = item;
                    suggestionsBox.innerHTML = "";
                    document.getElementById("filterForm").submit();
                  });
                  suggestionsBox.appendChild(div);
                });
              } else {
                suggestionsBox.innerHTML = "<div style='padding:5px;'>No matches found</div>";
              }
            });
        } else {
          suggestionsBox.innerHTML = "";
        }
      });
      document.addEventListener("click", () => {
        suggestionsBox.innerHTML = "";
      });
    });

    setTimeout(function () {
        const successMsg = document.querySelector('.success-message');
        const errorMsg = document.querySelector('.error-message');

        if (successMsg) {
            successMsg.style.transition = 'opacity 0.5s ease';
            successMsg.style.opacity = 0;
            setTimeout(() => successMsg.remove(), 500);
        }

        if (errorMsg) {
            errorMsg.style.transition = 'opacity 0.5s ease';
            errorMsg.style.opacity = 0;
            setTimeout(() => errorMsg.remove(), 500);
        }
    }, 3000); // 3000 milliseconds = 3 seconds
    
  </script>
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


  <!-- Modal Structure for Editing -->
  <div id="editModal" class="modal">
    <h2>Edit Customer</h2>
    <form id="editForm" method="post" action="edit.php">
      <input type="hidden" name="original_name" id="original_name">
      <label for="edit_name">Name:</label>
      <input type="text" id="edit_name" name="edit_name" required>
      <label for="edit_company">Company:</label>
      <select id="edit_company" name="edit_company" required>
        <option value="">-- Select company --</option>
        <?php foreach ($company_list as $c) { ?>
          <option value="<?= htmlspecialchars($c['company']) ?>"><?= htmlspecialchars($c['company']) ?></option>
        <?php } ?>
      </select>
      <br><br>
      <button type="submit" class="submit">Save Changes</button>
      <button type="button" class="button" onclick="closeModal()">Cancel</button>
    </form>
  </div>


  <div class="main-content">
    <h1>MEDREP LIST</h1>
    <div class="list-toolbar">
    <form method="get" action="customer.php" id="filterForm">
      <label for="company">Filter by Company:</label>
      <select name="company" id="company" onchange="this.form.submit()">
        <option value="">All Companies</option>
        <?php foreach ($company_list as $company_row) { ?>
            <option value="<?= htmlspecialchars($company_row['company']) ?>" <?= ($company_filter === $company_row['company']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($company_row['company']) ?>
            </option>
        <?php } ?>
      </select>
      <input type="text" id="search" name="search" placeholder="Search by Name" autocomplete="off">
      <div id="suggestions" style="position:absolute; background:#fff; border:1px solid #ccc; z-index:1000;"></div>
    </form>
    <button type="button" class="btn add-medrep-btn" onclick="openAddMedrepModal()"><i class="fas fa-user-plus"></i>&nbsp; Add Medrep</button>
    </div>

    <!-- Modal Structure for Adding a Medrep -->
    <div id="addMedrepModal" class="modal">
      <h2>Add Medrep</h2>
      <form id="addMedrepForm" method="post" action="customer.php">
        <input type="hidden" name="action" value="add_medrep">
        <input type="hidden" name="redirect_company" value="<?= htmlspecialchars($company_filter) ?>">
        <label for="medrep_name">Name:</label>
        <input type="text" id="medrep_name" name="medrep_name" placeholder="e.g. Juan Dela Cruz" required>
        <label for="medrep_company">Company:</label>
        <select id="medrep_company" name="medrep_company" required>
          <option value="">-- Select company --</option>
          <?php foreach ($company_list as $c) { ?>
            <option value="<?= htmlspecialchars($c['company']) ?>" <?= ($company_filter === $c['company']) ? 'selected' : '' ?>><?= htmlspecialchars($c['company']) ?></option>
          <?php } ?>
        </select>
        <br><br>
        <button type="submit" class="submit">Add Medrep</button>
        <button type="button" class="button" onclick="closeAddMedrepModal()">Cancel</button>
      </form>
    </div>

    

    <div class="table-scroll">
    <table border="1">
      <tr>
        <th>NAME</th>
        <th>COMPANY</th>
        <th>DEPOSIT</th>
        <th>PAYABLE</th>
        <th>CASHOUT</th>
        <th>GIFT CARD</th>
        <th>BALANCE</th>
        <th>REMARKS</th>
        <th>ACTIONS</th>
      </tr>
      <?php if (empty($employees)) { ?>
        <tr>
          <td colspan="9" style="text-align:center; font-weight: bold;">No data found.</td>
        </tr>
      <?php } else { 
            foreach ($employees as $row) { 
                $total_balance = floatval($row['total_balance']);
                $balance_color = ($total_balance >= 0) ? "#28a745" : "#d9534f";
                $remark_bg = ($balance_color === "#28a745") ? 'green' : 'red';
                $remark_label = ($balance_color === "#28a745") ? 'Balance' : 'Payable';
      ?>
      <tr class="clickable-row" data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
        <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?= htmlspecialchars($row['company'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td style="color: #28a745; font-weight: bold;">₱<?= number_format($row['total_deposit'], 2); ?></td>
        <td style="color: #d9534f; font-weight: bold;">₱<?= number_format($row['total_payable'], 2); ?></td>
        <td class="cashout">₱<?= number_format($row['total_cashout'], 2); ?></td>
        <td class="giftcard">₱<?= number_format($row['total_giftcard'], 2); ?></td>
        <td class="total_balance" style="text-align: center; white-space: nowrap;">
    <?php
    $total_balance = $row['total_balance'];
    // Check if the balance is zero, negative, or positive
    if ($total_balance < 0) {
        echo "<strong style='color: red;'>-" . number_format(abs($total_balance), 2) . "</strong>"; // Negative balance in red and bold
    } elseif ($total_balance == 0) {
        echo "<strong style='color: black;'>" . number_format($total_balance, 2) . "</strong>"; // Zero balance in black and bold
    } else {
        echo "<strong style='color: green;'>" . number_format($total_balance, 2) . "</strong>"; // Positive balance in green and bold
    }
    ?>
</td>

        <td style="background-color: <?= $remark_bg ?>; color: black; text-align: center;">
          <strong><?= $remark_label ?></strong>
        </td>
        <td class="row-actions">
          <!-- Updated action buttons -->
          <button class="edit-btn icon-btn" title="Edit" aria-label="Edit <?= htmlspecialchars($row['name']) ?>" data-name="<?= htmlspecialchars($row['name']) ?>" data-company="<?= htmlspecialchars($row['company']) ?>"><i class="fas fa-pen"></i></button>
          <button class="delete-btn icon-btn" title="Delete" aria-label="Delete <?= htmlspecialchars($row['name']) ?>" data-name="<?= htmlspecialchars($row['name']) ?>"><i class="fas fa-trash"></i></button>
          <?php $isBlocked = strtolower(trim((string)($row['block_status'] ?? ''))) === 'block'; ?>
          <?php if ($isBlocked): ?>
          <!-- Blocked medreps are managed on the dedicated Block Medrep
               page now -- clicking here just takes you there instead of
               unblocking inline. -->
          <button class="view-blocked-btn icon-btn" title="Blocked -- view in Block Medrep" aria-label="<?= htmlspecialchars($row['name']) ?> is blocked, view in Block Medrep" data-name="<?= htmlspecialchars($row['name']) ?>"><i class="fas fa-lock"></i></button>
          <?php else: ?>
          <button class="block-btn icon-btn" title="Block" aria-label="Block <?= htmlspecialchars($row['name']) ?>" data-name="<?= htmlspecialchars($row['name']) ?>"><i class="fas fa-ban"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php } } ?>
    </table>
    </div>
  </div>
</body>
</html>