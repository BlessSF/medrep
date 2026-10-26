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

$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';

unset($_SESSION['error_message']);
unset($_SESSION['success_message']);

session_write_close();



$sql = "SELECT DISTINCT name, company FROM employees ORDER BY name ASC";
$result = $conn->query($sql);
$employee_list = [];
if ($result && $result->num_rows > 0) {
    $seen = [];
while ($row = $result->fetch_assoc()) {
    $key = strtolower($row['name'] . '|' . $row['company']);
    if (!isset($seen[$key])) {
        $employee_list[] = $row;
        $seen[$key] = true;
    }
}

}

// Companies for the "Company" dropdown on the transaction form below --
// pulled live from the companies table (managed on companies.php by an
// admin) so it always matches what's currently in the system.
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
$company_list_result = $conn->query("SELECT name FROM companies ORDER BY name ASC");
$company_list = $company_list_result ? $company_list_result->fetch_all(MYSQLI_ASSOC) : [];

// ---------------- MEDREP LIST: filters, search & pagination ----------------
$filter_company = isset($_GET['f_company']) ? trim($_GET['f_company']) : '';
$filter_month   = isset($_GET['f_month']) ? trim($_GET['f_month']) : '';   // 'YYYY-MM' or '' = all months
$filter_q       = isset($_GET['f_q']) ? trim($_GET['f_q']) : '';

$per_page_options = [25, 50, 100, 500];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($per_page, $per_page_options, true)) {
    $per_page = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Months that actually have records, for the "Month" filter dropdown.
$months_result = $conn->query("
    SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym
    FROM employees
    WHERE created_at IS NOT NULL
    ORDER BY ym DESC
");
$available_months = $months_result ? $months_result->fetch_all(MYSQLI_ASSOC) : [];

// Build the WHERE clause from whichever filters are active.
$where = [];
$params = [];
$types = "";

if ($filter_company !== '') {
    $where[] = "company = ?";
    $params[] = $filter_company;
    $types .= "s";
}
if ($filter_month !== '') {
    $where[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}
if ($filter_q !== '') {
    $where[] = "(name LIKE ? OR company LIKE ?)";
    $like = "%{$filter_q}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
$where_sql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Total matching rows, used for "Showing X-Y of Z" and page count.
$count_sql = "SELECT COUNT(*) AS total FROM employees $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types !== "") {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$list_sql = "SELECT name, company, remarks, sender_name, created_at
             FROM employees
             $where_sql
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?";
$list_stmt = $conn->prepare($list_sql);
$list_types = $types . "ii";
$list_params = array_merge($params, [$per_page, $offset]);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$result = $list_stmt->get_result();
if (!$result) {
    die("Error fetching employee records: " . $conn->error);
}

// Rebuilds the query string for pagination links while keeping every
// active filter intact and only overriding the given key(s).
function medrep_list_url($overrides) {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    return 'staff.php?' . http_build_query($params) . '#medrep-list';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <title>MEDREP DAILY TRANSACTION</title>
    <style>
   * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    text-transform: uppercase;
}

body {
    font-family: 'Arial', sans-serif;
    background:rgb(227, 251, 203);
    color: rgb(30, 30, 30); /* Darker text for better readability */
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    min-height: 100vh;
}

/* Header */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(120deg, #14361c 0%, #1b5e20 45%, #2e7d32 100%);
    color: white;
    padding: 14px 26px;
    width: 100%;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1000;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.header-brand {
    display: flex;
    align-items: center;
    gap: 14px;
}

.header-logo {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #ffffff;
}

.header-titles {
    display: flex;
    flex-direction: column;
    gap: 3px;
}




/* Logout Button — full styling lives further down in the
   "Cashier-side visual polish" block, kept together with the rest of
   the redesigned header. */

/* Form Container */
.container {
    width: 100%;
    max-width: 900px; /* Was 1510px -- far wider than the form/table
                          inside it ever needed, which is what made
                          the page look stretched out edge-to-edge. */
    margin: 130px auto 30px; /* Adjust for fixed header + Transaction/Voucher tab bar (now slightly taller with the logo badge), and center it */
    padding: 20px;
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    align-items: stretch;
    background:rgb(227, 251, 203);
    box-sizing: border-box;
}

/* Form */
form {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 20px;
    background:rgb(227, 251, 203);
    border-radius: 10px;
    width: 100%;
    max-width: 560px; /* Keeps each input a comfortable reading/typing
                          width instead of stretching every field the
                          full width of the container. */
    margin: 0 auto;
    box-sizing: border-box;
}

/* Labels */
label {
    font-weight: bold;
    color: rgb(0, 0, 0);
    font-size: 16px;
}

/* Input Fields */
input, select {
    width: 100%;
    padding: 10px;
    font-size: 16px;
    border: 1px solid #2e7d32;
    border-radius: 5px;
    background-color: rgb(250, 250, 250); /* Light grey background for inputs */
    color: rgb(0, 0, 0);
    transition: border-color 0.3s, box-shadow 0.3s;
}

input:focus, select:focus {
    border-color: #388e3c;
    outline: none;
    box-shadow: 0px 0px 5px rgba(46, 125, 50, 0.3);
}

/* Submit Button */
.submit-button {
    padding: 12px;
    font-size: 16px;
    background-color: #2e7d32;
    color: white;
    border: none;
    cursor: pointer;
    border-radius: 5px;
    width: 100%;
    max-width: 260px;
    transition: background-color 0.3s;
    display: block;
    margin: 6px auto 0;
}

.submit-button:hover {
    background-color: #388e3c;
}

/* Autocomplete (Name / Company suggestion dropdowns) */
.autocomplete-wrap {
    position: relative;
    width: 100%;
}

.suggestions-box {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #a5d6a7;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    max-height: 220px;
    overflow-y: auto;
    z-index: 2000;
}

.suggestions-box.open {
    display: block;
}

.suggestion-item {
    padding: 9px 12px;
    cursor: pointer;
    font-size: 14px;
    text-transform: uppercase;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.suggestion-item:last-child {
    border-bottom: none;
}

.suggestion-item .s-main {
    font-weight: bold;
    color: #1b5e20;
}

.suggestion-item .s-sub {
    font-size: 12px;
    font-weight: normal;
    color: #5a5a5a;
}

.suggestion-item:hover,
.suggestion-item.active {
    background-color: #e8f5e9;
}

.suggestion-empty {
    padding: 9px 12px;
    font-size: 13px;
    color: #888;
    text-transform: none;
}

.dropdown-toggle {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    background: #2e7d32;
    color: white;
    border: none;
    border-radius: 4px;
    width: 26px;
    height: 26px;
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.dropdown-toggle:hover {
    background: #388e3c;
}

#company {
    padding-right: 38px;
}

/* Table */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    background:rgb(227, 251, 203);
}

th, td {
    border: 1px solid #2e7d32;
    padding: 12px;
    text-align: center;
    font-size: 16px;
}

th {
    background-color: #2e7d32;
    color: white;
}

td {
    background:rgb(227, 251, 203);
    color: rgb(0, 0, 0);
}

tr:nth-child(even) {
    background-color: rgba(230, 230, 230, 0.5); /* Light grey for even rows */
}

/* Responsive Design */
@media (max-width: 960px) {
    .container {
        max-width: 96%;
    }
}

@media (max-width: 600px) {
    .container {
        width: 100%;
        max-width: 100%;
        padding: 14px;
        border-radius: 0;
        margin-top: 160px; /* header wraps to 2 lines on very narrow screens */
    }

    form {
        padding: 14px;
    }

    .header {
        flex-wrap: wrap;
        gap: 8px;
        padding: 12px 16px;
    }

    .header-logo {
        width: 36px;
        height: 36px;
        font-size: 15px;
    }

    .header h1,
    .header .header-titles h1 { font-size: 17px; }

    .header-subtitle { font-size: 11.5px; }

    input, select {
        font-size: 14px;
        padding: 10px;
    }

    .submit-button {
        font-size: 14px;
        padding: 10px;
        max-width: 100%;
    }

    th, td {
        padding: 8px 6px;
        font-size: 13px;
    }

    .button-box {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .summary-button {
        margin-left: 0;
    }
}

.button-box {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}

.summary-button {
    padding: 6px 12px;
    font-size: 14px;
    background-color: #2e7d32;
    color: white;
    border: none;
    cursor: pointer;
    border-radius: 5px;
    text-decoration: none;
    text-align: center;
    margin-left: auto; /* Pushes button to the right */
    display: inline-block;
}

.summary-search-wrap {
    position: relative;
    margin-left: auto;
}

#summary-popover {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    width: 240px;
}

#summary-popover input {
    width: 100%;
}

.medrep-row {
    cursor: pointer;
}

.medrep-row:hover {
    background-color: #e8f5e9 !important;
}
.logout-button {
    padding: 9px 18px;
    font-size: 13px;
    background-color: #ffffff;
    color: #1b5e20;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 800;
    letter-spacing: 0.3px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
    transition: background-color 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
    flex-shrink: 0;
}

.logout-button:hover {
    background-color: #eafbea;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.22);
}

/* ================= Cashier-side visual polish ================= */

/* Explicit + !important because assets/style.css (loaded after this
   block) sets a global "h1 { color: var(--text) }" rule -- an
   inherited white from .header alone loses to any explicit rule, so
   the title needs its own explicit white here to stay readable on
   the green header. */
.header h1,
.header .header-titles h1 {
    color: #ffffff !important;
    font-size: 21px;
    font-weight: 800;
    letter-spacing: 0.4px;
    line-height: 1.15;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.header-subtitle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.88) !important;
    letter-spacing: 0.4px;
}

.header-subtitle i {
    font-size: 13px;
    color: #a5d6a7;
}

.container {
    box-shadow: 0 10px 30px rgba(20, 60, 20, 0.12);
}

form {
    background: #ffffff !important;
    border: 1px solid #cfe9d2;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

input, select {
    background-color: #ffffff;
}

.submit-button {
    letter-spacing: 0.4px;
    box-shadow: 0 4px 10px rgba(46, 125, 50, 0.25);
}

.button-box h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 18px;
    color: #1b5e20;
}

/* ---- Filter bar ---- */
/* NOTE: the generic "form { flex-direction:column; max-width:560px;
   margin:0 auto; }" rule above (written for the transaction form) also
   matches this filter bar, since it's a <form> too -- every one of
   those properties has to be explicitly re-set here or it wins by
   source order at equal specificity. */
.filter-bar {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: flex-end !important;
    gap: 14px;
    width: 100% !important;
    max-width: none !important;
    margin: 14px 0 0 !important;
    padding: 16px;
    background: #ffffff;
    border: 1px solid #cfe9d2;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    box-sizing: border-box;
}

.filter-field {
    display: flex !important;
    flex-direction: column !important;
    gap: 6px;
    flex: 1 1 160px;
    min-width: 140px;
}

.filter-field-search {
    flex: 2 1 220px;
}

.filter-field label {
    font-size: 12px;
    font-weight: 700;
    color: #1b5e20;
    display: flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.3px;
}

.filter-field input,
.filter-field select {
    width: 100%;
    padding: 9px 10px;
    font-size: 14px;
    border: 1px solid #bcdfc0;
    border-radius: 7px;
    background: #f9fdf9;
}

.filter-actions {
    display: flex;
    gap: 8px;
    flex: 0 0 auto;
}

.filter-apply-btn,
.filter-reset-btn {
    padding: 9px 16px;
    font-size: 14px;
    font-weight: bold;
    border: none;
    border-radius: 7px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    white-space: nowrap;
    transition: background-color 0.2s, transform 0.1s;
}

.filter-apply-btn {
    background: #2e7d32;
    color: #fff;
}
.filter-apply-btn:hover { background: #388e3c; }

.filter-reset-btn {
    background: #eef6ee;
    color: #2e7d32;
    border: 1px solid #bcdfc0;
}
.filter-reset-btn:hover { background: #e0f0e1; }

/* ---- table meta / row count ---- */
.table-meta {
    margin: 12px 2px 6px;
    font-size: 13px;
    color: #4c6b4e;
}
.table-meta strong { color: #1b5e20; }

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 8px;
}

.col-num {
    width: 48px;
    text-align: center;
    color: #4c6b4e;
    font-weight: bold;
    background: rgba(46, 125, 50, 0.06);
}
th.col-num { background-color: #2e7d32 !important; color: #fff !important; }

.empty-row {
    text-align: center !important;
    padding: 26px !important;
    color: #6b8a6d;
    font-style: normal;
}
.empty-row i { margin-right: 6px; }

/* highlight rows whose last action was a cashout */
.cashout-row {
    background: #fff3e0 !important;
}
.cashout-row:hover { background: #ffe6c2 !important; }

/* ---- pagination ---- */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin: 18px 0 6px;
}

.page-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #2e7d32;
    color: #fff;
    border-radius: 7px;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
    transition: background-color 0.2s;
}
.page-btn:hover { background: #388e3c; }
.page-btn.disabled {
    background: #cfd8cf;
    color: #f0f0f0;
    pointer-events: none;
    cursor: default;
}

.page-info {
    font-size: 13px;
    font-weight: bold;
    color: #1b5e20;
}

@media (max-width: 600px) {
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .filter-actions {
        justify-content: stretch;
    }
    .filter-apply-btn, .filter-reset-btn {
        flex: 1;
        justify-content: center;
    }
}

</style>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const nameInput = document.getElementById("name");
    const companyInput = document.getElementById("company");
    const employeeList = <?php echo json_encode($employee_list); ?>;
    const paymentMethodSelect = document.getElementById("payment_method");
    const cardChoiceSelect = document.getElementById("card_choice");
    const cardOtherInput = document.getElementById("card_other");
    const cardHiddenInput = document.getElementById("card");
    const cardTypeLabel = document.querySelector("label[for='card_choice']"); 
    const form = document.querySelector("form");
    const transactionTypeSelect = document.getElementById("transaction_type");
    const transferFields = document.getElementById("transferFields");
    const transferNameInput = document.getElementById("transfer_name");
    const transferCompanyInput = document.getElementById("transfer_company");
    const paymentMethodGroup = document.getElementById("paymentMethodGroup");
    const amountLabel = document.getElementById("amountLabel");

    // Prevent Enter key from submitting the form
    form.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
            e.preventDefault();
        }
    });
    
    // Function to toggle card type visibility (both label and dropdown)
    function toggleCardOptionVisibility() {
        if (paymentMethodSelect.value === "card") {
            cardChoiceSelect.style.display = "inline-block"; // Show card type dropdown
            cardTypeLabel.style.display = "inline-block"; // Show card type label
        } else {
            cardChoiceSelect.style.display = "none"; // Hide card type dropdown
            cardTypeLabel.style.display = "none"; // Hide card type label
            cardOtherInput.style.display = "none";
        }
        syncCardValue();
    }

    // Show/hide the free-text "Others" field and keep the hidden submitted
    // value (#card) in sync with either the dropdown pick or the typed text.
    function syncCardValue() {
        if (paymentMethodSelect.value !== "card") {
            cardHiddenInput.value = "";
            return;
        }
        if (cardChoiceSelect.value === "others") {
            cardOtherInput.style.display = "inline-block";
            cardHiddenInput.value = cardOtherInput.value.trim();
        } else {
            cardOtherInput.style.display = "none";
            cardHiddenInput.value = cardChoiceSelect.value;
        }
    }

    cardChoiceSelect.addEventListener("change", syncCardValue);
    cardOtherInput.addEventListener("input", syncCardValue);

    // Require the free-text name when "Others" is chosen
    form.addEventListener("submit", function (e) {
        if (paymentMethodSelect.value === "card" && cardChoiceSelect.value === "others"
            && cardOtherInput.value.trim() === "") {
            e.preventDefault();
            alert("Please specify the card/bank name for 'Others'.");
            cardOtherInput.focus();
        }
    });

    // Run on page load to set the initial visibility based on the payment method
    toggleCardOptionVisibility();

    // Event listener for payment method change
    paymentMethodSelect.addEventListener("change", toggleCardOptionVisibility);

    const companyList = <?php echo json_encode($company_list); ?>; // [{name: "..."}]

    // De-duplicated list of {name, company} pairs, used for the Name
    // and Transfer-To-Name suggestion dropdowns.
    function filterEmployees(query) {
        const q = query.toLowerCase();
        return employeeList.filter(emp =>
            emp.name.toLowerCase().includes(q)
        ).slice(0, 8);
    }

    // Unique company names, used for the Company suggestion dropdown.
    // showAll=true returns every match (used when the dropdown-arrow
    // button is clicked to browse); otherwise it's capped to 8 while typing.
    function filterCompanies(query, showAll) {
        const q = query.toLowerCase();
        const seen = new Set();
        const matches = [];
        companyList.forEach(c => {
            const name = c.name;
            if (name && name.toLowerCase().includes(q) && !seen.has(name.toLowerCase())) {
                seen.add(name.toLowerCase());
                matches.push(name);
            }
        });
        return showAll ? matches : matches.slice(0, 8);
    }

    /**
     * Wires a text input up to a styled dropdown of suggestions.
     *  - inputEl: the text field being typed into
     *  - boxEl: the .suggestions-box element to render results into
     *  - getMatches(query): returns an array of items for the current text
     *  - renderItem(item): returns the inner HTML for one suggestion row
     *  - onSelect(item): called when a suggestion is chosen (click or Enter)
     *  - onClear(): called when the field is emptied out
     */
    function attachAutocomplete(inputEl, boxEl, getMatches, renderItem, onSelect, onClear) {
        let activeIndex = -1;
        let currentMatches = [];

        function close() {
            boxEl.classList.remove("open");
            boxEl.innerHTML = "";
            activeIndex = -1;
            currentMatches = [];
        }

        function open(matches) {
            currentMatches = matches;
            activeIndex = -1;
            boxEl.innerHTML = "";

            if (matches.length === 0) {
                const empty = document.createElement("div");
                empty.className = "suggestion-empty";
                empty.textContent = "No matches found";
                boxEl.appendChild(empty);
                boxEl.classList.add("open");
                return;
            }

            matches.forEach((item, idx) => {
                const row = document.createElement("div");
                row.className = "suggestion-item";
                row.innerHTML = renderItem(item);
                row.addEventListener("mousedown", function (e) {
                    // mousedown (not click) so it fires before the input's blur
                    e.preventDefault();
                    onSelect(item);
                    close();
                });
                row.addEventListener("mouseenter", function () {
                    setActive(idx);
                });
                boxEl.appendChild(row);
            });

            boxEl.classList.add("open");
        }

        function setActive(idx) {
            const rows = boxEl.querySelectorAll(".suggestion-item");
            rows.forEach(r => r.classList.remove("active"));
            if (idx >= 0 && idx < rows.length) {
                rows[idx].classList.add("active");
                rows[idx].scrollIntoView({ block: "nearest" });
            }
            activeIndex = idx;
        }

        inputEl.addEventListener("input", function () {
            const value = inputEl.value.trim();
            if (value.length === 0 && onClear) {
                onClear();
            }
            if (value.length >= 1) {
                open(getMatches(value));
            } else {
                close();
            }
        });

        inputEl.addEventListener("keydown", function (e) {
            if (!boxEl.classList.contains("open") || currentMatches.length === 0) return;

            if (e.key === "ArrowDown") {
                e.preventDefault();
                setActive((activeIndex + 1) % currentMatches.length);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                setActive((activeIndex - 1 + currentMatches.length) % currentMatches.length);
            } else if (e.key === "Enter") {
                if (activeIndex >= 0) {
                    e.preventDefault();
                    onSelect(currentMatches[activeIndex]);
                    close();
                }
            } else if (e.key === "Escape") {
                close();
            }
        });

        inputEl.addEventListener("blur", function () {
            // Small delay so a mousedown-triggered onSelect can still run first.
            setTimeout(close, 100);
        });

        inputEl.addEventListener("focus", function () {
            const value = inputEl.value.trim();
            if (value.length >= 1) {
                open(getMatches(value));
            }
        });

        return {
            open: open,
            close: close,
            isOpen: () => boxEl.classList.contains("open")
        };
    }

    // ---- Name field: suggests "Name — Company", and auto-fills Company. ----
    attachAutocomplete(
        nameInput,
        document.getElementById("name-suggestions"),
        filterEmployees,
        emp => `<span class="s-main">${emp.name}</span><span class="s-sub">${emp.company}</span>`,
        emp => {
            nameInput.value = emp.name;
            companyInput.value = emp.company;
        },
        () => { companyInput.value = ""; }
    );

    // ---- Company field: suggests known company names. ----
    const companyAutocomplete = attachAutocomplete(
        companyInput,
        document.getElementById("company-suggestions"),
        filterCompanies,
        name => `<span class="s-main">${name}</span>`,
        name => { companyInput.value = name; },
        null
    );

    // ---- Company dropdown-arrow button: browse every company, even
    // before typing anything. ----
    const companyToggle = document.getElementById("company-toggle");
    companyToggle.addEventListener("mousedown", function (e) {
        // mousedown+preventDefault so clicking the arrow doesn't blur
        // the input first and close the box before it can open.
        e.preventDefault();
        if (companyAutocomplete.isOpen()) {
            companyAutocomplete.close();
        } else {
            companyInput.focus();
            companyAutocomplete.open(filterCompanies(companyInput.value.trim(), true));
        }
    });

    // ---- Transfer To (Name): same behavior as the main Name field,
    // auto-filling the (readonly) Transfer To (Company) field. ----
    attachAutocomplete(
        transferNameInput,
        document.getElementById("transfer-name-suggestions"),
        filterEmployees,
        emp => `<span class="s-main">${emp.name}</span><span class="s-sub">${emp.company}</span>`,
        emp => {
            transferNameInput.value = emp.name;
            transferCompanyInput.value = emp.company;
        },
        () => { transferCompanyInput.value = ""; }
    );

    // ---- View Monthly Summary button now just navigates straight to
    // monthly_summary.php (no more name-search popover). Handled via the
    // inline onclick on the button itself. ----


    // ---- Transaction Type: show/hide the Transfer To fields and the
    // Payment Method / Card fields depending on whether "Transfer" is
    // selected -- a transfer moves balance between two medreps, so it
    // doesn't need a payment method, but the Payment Method group needs
    // to come back exactly as it was for every other transaction type. ----
    function toggleTransferVisibility() {
        const isTransfer = transactionTypeSelect.value === "transfer";

        transferFields.style.display = isTransfer ? "block" : "none";
        transferNameInput.required = isTransfer;

        if (isTransfer) {
            paymentMethodGroup.style.display = "none";
            paymentMethodSelect.required = false;
            amountLabel.textContent = "Transfer Amount:";
        } else {
            paymentMethodGroup.style.display = "";
            paymentMethodSelect.required = true;
            amountLabel.textContent = "Amount:";
            toggleCardOptionVisibility();
        }
    }

    transactionTypeSelect.addEventListener("change", toggleTransferVisibility);
    toggleTransferVisibility();

    // Require the Transfer To name (and make sure it isn't the same
    // person sending it) before letting the form submit.
    form.addEventListener("submit", function (e) {
        if (transactionTypeSelect.value === "transfer") {
            if (transferNameInput.value.trim() === "") {
                e.preventDefault();
                alert("Please choose who to transfer to.");
                transferNameInput.focus();
                return;
            }
            if (transferNameInput.value.trim().toLowerCase() === nameInput.value.trim().toLowerCase()) {
                e.preventDefault();
                alert("Cannot transfer to the same medrep.");
                transferNameInput.focus();
            }
        }
    });
});

    
setTimeout(() => {
    let errorMessage = document.getElementById("errorMessage");
    let successMessage = document.getElementById("successMessage");

    if (errorMessage) {
        errorMessage.style.display = "none";
    }
    if (successMessage) {
        successMessage.style.display = "none";
    }
}, 5000); // Hides messages after 5 seconds

</script>

    <link rel="stylesheet" href="assets/style.css">
<script src="assets/table-search.js"></script>
</head>
<body>
<?php if (!empty($error_message)): ?>
    <div id='errorMessage' style='position: absolute;
            top: 120px; 
            width: 60%;
            left: 50%;
            transform: translateX(-50%);
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            z-index: 9999;
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;'> 
        <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<?php if (!empty($success_message)): ?>
    <div id='successMessage' style='position: absolute;
            top: 120px; 
            left: 50%;
            transform: translateX(-50%);
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            z-index: 9999;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;'> 
        <?php echo $success_message; ?>
    </div>
<?php endif; ?>

<div class="header">
    <div class="header-brand">
        <div class="header-logo"><i class="fas fa-hand-holding-medical"></i></div>
        <div class="header-titles">
            <h1>Medrep Daily Transaction</h1>
            <span class="header-subtitle"><i class="fas fa-circle-user"></i> Hello, <?= htmlspecialchars($_SESSION['username']) ?></span>
        </div>
    </div>
    <a href="index.php?logout=true" class="logout-button">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

<?php require_once __DIR__ . '/includes/cashier_tabs.php'; cashier_tabs('transaction'); ?>



    <div class="container">
    
    <form action="submit.php" method="POST">
    <label for="name">Name:</label>
<div class="autocomplete-wrap">
    <input type="text" id="name" name="name" autocomplete="off" placeholder="Start typing a name..." required>
    <div class="suggestions-box" id="name-suggestions"></div>
</div>

<label for="company">Company:</label>
<div class="autocomplete-wrap">
    <input type="text" id="company" name="company" autocomplete="off" placeholder="Start typing a company..." required>
    <button type="button" id="company-toggle" class="dropdown-toggle" aria-label="Browse all companies">&#9662;</button>
    <div class="suggestions-box" id="company-suggestions"></div>
</div>

<label for="transaction_type">Transaction Type:</label>
<select name="transaction_type" id="transaction_type" required>
    <option value="deposit">Deposit</option>
    <option value="dinein">Dine In</option>
    <option value="takeout">Take Out</option>
    <option value="buygiftcard">Buy Gift Card</option>
    <option value="transfer">Transfer To Another Medrep</option>
</select>

<div id="transferFields" style="display:none;">
    <label for="transfer_name">Transfer To (Name):</label>
    <div class="autocomplete-wrap">
        <input type="text" id="transfer_name" name="transfer_name" autocomplete="off" placeholder="Start typing a name...">
        <div class="suggestions-box" id="transfer-name-suggestions"></div>
    </div>

    <label for="transfer_company">Transfer To (Company):</label>
    <input type="text" id="transfer_company" name="transfer_company" readonly>
</div>

    <label for="amount" id="amountLabel">Amount:</label>
    <input type="number" name="amount" required step="0.01">

    <div id="paymentMethodGroup">
    <label for="payment_method">Payment Method:</label>
    <select name="payment_method" id="payment_method" required>
            <option value="card">Swipe</option>
    <option value="cash">Cash</option>
    <option value="paid">Paid</option>
    <option value="giftcard">Gift Card</option>
    <option value="na">N/A</option>
</select>
<label for="card_choice">Card Option</label>
<select id="card_choice">
    <option value="maya">Maya</option>
    <option value="metrobank">Metrobank</option>
    <option value="others">Others</option>
</select>
<input type="text" id="card_other" name="card_other" placeholder="Specify card/bank name"
       style="display:none;">
<!-- Actual submitted value: mirrors card_choice, or the typed text when "Others" is picked -->
<input type="hidden" name="card" id="card">
    </div>


    <label for="remarks">Remarks:</label>
    <input type="text" id="remarks" name="remarks">

    <input type="submit" value="Submit" class="submit-button">
</form>

        <div class="button-box" id="medrep-list">
            <h2><i class="fas fa-list-ul"></i> Medrep List</h2>
            <div class="summary-search-wrap">
                <button type="button" id="summary-btn" class="summary-button" onclick="window.location.href='monthly_summary.php'"><i class="fas fa-chart-column"></i> View Monthly Summary</button>
            </div>
        </div>

        <form method="GET" action="staff.php" class="filter-bar" id="filterForm">
            <input type="hidden" name="page" value="1">

            <div class="filter-field filter-field-search">
                <label for="f_q"><i class="fas fa-search"></i> Search</label>
                <input type="text" id="f_q" name="f_q" placeholder="Search name or company..." value="<?= htmlspecialchars($filter_q) ?>">
            </div>

            <div class="filter-field">
                <label for="f_company"><i class="fas fa-building"></i> Company</label>
                <select id="f_company" name="f_company" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($company_list as $c): $cname = $c['name']; ?>
                        <option value="<?= htmlspecialchars($cname) ?>" <?= ($filter_company === $cname) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="f_month"><i class="fas fa-calendar-days"></i> Month</label>
                <select id="f_month" name="f_month" onchange="this.form.submit()">
                    <option value="">All Months</option>
                    <?php foreach ($available_months as $m):
                        $ym = $m['ym'];
                        if (!$ym) continue;
                        $label = date("F Y", strtotime($ym . "-01"));
                    ?>
                        <option value="<?= htmlspecialchars($ym) ?>" <?= ($filter_month === $ym) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="per_page"><i class="fas fa-table-list"></i> Show</label>
                <select id="per_page" name="per_page" onchange="this.form.submit()">
                    <?php foreach ($per_page_options as $opt): ?>
                        <option value="<?= $opt ?>" <?= ($per_page === $opt) ? 'selected' : '' ?>><?= $opt ?> rows</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="filter-apply-btn"><i class="fas fa-filter"></i> Apply</button>
                <a href="staff.php#medrep-list" class="filter-reset-btn"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </form>

        <div class="table-meta">
            <?php if ($total_rows > 0): ?>
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?></strong> of <strong><?= $total_rows ?></strong> record<?= $total_rows === 1 ? '' : 's' ?>
            <?php else: ?>
                No records found
            <?php endif; ?>
        </div>

        <div class="table-scroll">
        <table id="medrepTable">
<tr>
    <th class="col-num">#</th>
    <th>NAME</th>
    <th>COMPANY</th>
    <th>DATE UPDATED</th>
</tr>
<?php if ($total_rows === 0): ?>
    <tr>
        <td colspan="4" class="empty-row"><i class="fas fa-inbox"></i> No matching transactions. Try adjusting your filters.</td>
    </tr>
<?php else: $rownum = $offset + 1; while ($row = $result->fetch_assoc()): ?>
    <tr class="medrep-row <?= ($row['remarks'] === 'cashout') ? 'cashout-row' : '' ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
        <td class="col-num"><?= $rownum++ ?></td>
        <td><?= strtoupper($row['name']) ?></td>
        <td><?= strtoupper($row['company']) ?></td>
        <td><?= strtoupper(date("d-M-Y h:i A", strtotime($row['created_at']))) ?></td> <!-- Corrected here -->
    </tr>
<?php endwhile; endif; ?>
</table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
               href="<?= $page > 1 ? medrep_list_url(['page' => $page - 1]) : '#' ?>">
                <i class="fas fa-chevron-left"></i> Prev
            </a>

            <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>

            <a class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"
               href="<?= $page < $total_pages ? medrep_list_url(['page' => $page + 1]) : '#' ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endif; ?>

    </div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#medrepTable tr.medrep-row').forEach(function (row) {
        row.addEventListener('click', function () {
            const name = row.getAttribute('data-name');
            if (name) {
                window.location.href = 'summary.php?name=' + encodeURIComponent(name);
            }
        });
    });
});
</script>
</body>
</html>