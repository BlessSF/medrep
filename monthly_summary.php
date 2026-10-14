<?php   
session_start();

require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("CONNECTION FAILED: " . $conn->connect_error);
}

// Redirect if user is not logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Get search query and company filter (if any)
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$company_filter = isset($_GET['company']) ? $_GET['company'] : '';

// Fetch unique company names for the filter dropdown
$companyQuery = "SELECT DISTINCT company FROM employees ORDER BY company";
$companyResult = $conn->query($companyQuery);

$sql = "SELECT 
UPPER(e.name) AS name, 
e.company,
SUM(e.deposit) AS total_deposit,
    
    SUM(
        CASE 
            WHEN e.payment_method IN ('na', 'paid') THEN e.dinein + e.takeout + e.giftcard
            ELSE 0 
        END
    ) AS total_payable,

    SUM(e.cashout) AS total_cashout,
-- Giftcard Calculation
SUM(e.giftcard) 
- SUM(CASE WHEN e.payment_method = 'giftcard' THEN e.dinein ELSE 0 END)
- SUM(CASE WHEN e.payment_method = 'giftcard' THEN e.takeout ELSE 0 END) AS total_giftcard,

    SUM(e.deposit) - 
    (
        SUM(
            CASE 
                WHEN e.payment_method IN ('na', 'paid') THEN e.dinein + e.takeout + e.giftcard
                ELSE 0 
            END
        ) + 
        SUM(e.cashout + e.interest) +
        SUM(e.sender_amount)
    ) AS total_balance,
           MAX(e.created_at) AS latest_transaction
        FROM employees e
        WHERE 1";


// Add search filter if provided
if (!empty($search_query)) {
    $sql .= " AND e.name LIKE ?";
}

// Add company filter if provided
if (!empty($company_filter)) {
    $sql .= " AND e.company = ?";
}

// Group by name to aggregate data
$sql .= " GROUP BY e.name, e.company ORDER BY latest_transaction DESC";

$stmt = $conn->prepare($sql);

// Bind parameters dynamically
if (!empty($search_query) && !empty($company_filter)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("ss", $search_param, $company_filter);
} elseif (!empty($search_query)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("s", $search_param);
} elseif (!empty($company_filter)) {
    $stmt->bind_param("s", $company_filter);
}

$stmt->execute();
$result = $stmt->get_result();

if (isset($_GET['query'])) {
    header('Content-Type: application/json');
    
    $query = $_GET['query'];
    $sql = "SELECT name, company FROM employees WHERE name LIKE ? OR company LIKE ?";
    $stmt = $conn->prepare($sql);
    $search_query = "%$query%";
    $stmt->bind_param("ss", $search_query, $search_query);
    $stmt->execute();
    $result = $stmt->get_result();

    $suggestions = [];
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row;
    }

    echo json_encode($suggestions);
    exit(); // <- make sure it doesn't output anything else
}

?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEDREP SUMMARY</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        background:rgb(227, 251, 203);
        padding: 20px;
        text-transform: uppercase; /* Ensures all text is in capital letters */
        color:rgb(0, 0, 0); /* Dark green text */
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background:rgb(227, 251, 203);
    }
    th, td {
        padding: 10px;
        border: 1px solid #c8e6c9; /* Light green border */
        text-align: center;
    }
    th {
        background-color: #1b5e20; /* Dark green */
        color: #fff;
        cursor: pointer;
    }
    tr:hover {
        background-color: #a5d6a7; /* Hover light green */
        cursor: pointer;
    }
    /* Action column buttons */
.action-buttons {
    display: flex;
    gap: 5px;
    justify-content: center;
}

.action-buttons button {
    background-color: #2e7d32;
    color: white;
    border: none;
    padding: 5px 10px;
    font-size: 14px;
    border-radius: 4px;
    width: 90px; /* Set equal width */
    cursor: pointer;
    transition: background-color 0.3s ease;
}

.action-buttons button:hover {
    background-color: #1b5e20;
}

/* Fix column width */
th:nth-child(5),
td:nth-child(5) {
    width: 180px;
}

    .cashout-button {
        background-color: #2e7d32; /* Medium green */
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 4px;
    }
    .cashout-button:hover {
        background-color: #1b5e20; /* Dark green hover */
    }
    .modal {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
    }
    button {
        background-color: #1b5e20;
        color: white;
        border: none;
        padding: 6px 15px;
        cursor: pointer;
        border-radius: 4px;
    }
    button:hover {
        background-color:rgb(136, 227, 141);
    }

/* Blur effect when modal is active */
body.modal-active {
    overflow: hidden; /* Prevent scrolling */
}

.container {
    filter: blur(8px); /* Apply blur effect */
    transition: filter 0.3s ease-in-out;
}

/* Overlay to cover the background */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.3); /* Semi-transparent black */
    backdrop-filter: blur(8px); /* Blurs entire background */
    z-index: 999;
}

/* Modal styling */
.modal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgb(245, 245, 245);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
    z-index: 1000;
    width: 700px;
    max-width: 95%;
    animation: fadeIn 0.3s ease-in-out;
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
    padding: 6px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 16px;
}

select {
    background: #fff;
    cursor: pointer;
}

input[type="text"] {
    width: 200px;
}

/* Fade-in animation */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translate(-50%, -55%);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%);
    }
}

.modal h3 {
    margin-top: 0;
    color: #1b5e20;
    text-align: center;
    font-size: 1.5em;
    border-bottom: 2px solid #1b5e20;
    padding-bottom: 10px;
}

.modal label {
    display: block;
    margin-top: 15px;
    font-weight: bold;
    color: #333;
}

.modal span {
    display: block;
    margin-top: 5px;
    font-size: 1.2em;
    color:rgb(0, 0, 0);
}

.modal input,
.modal select {
    width: 100%;
    padding: 12px;
    margin-top: 5px;
    border: 1px solid #ccc;
    border-radius: 7px;
    font-size: 1.1em;
    box-sizing: border-box;
}

.modal button {
    background-color: #1b5e20;
    color: white;
    border: none;
    padding: 12px 15px;
    cursor: pointer;
    border-radius: 7px;
    margin-top: 20px;
    width: 100%;
    font-size: 1.1em;
    transition: background-color 0.3s ease;
}

.modal button:hover {
    background-color: #2e7d32;
}

.modal button:last-child {
    background-color: #d9534f;
}

.modal button:last-child:hover {
    background-color: #c9302c;
}

.modal button + button {
    margin-top: 10px;
}

.filter-bar-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 15px;
    }

    .filter-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-left select,
    .filter-left input[type="text"],
    .filter-left button {
        padding: 6px 10px;
        font-size: 14px;
    }

    #saveForm button {
        padding: 6px 12px;
        font-size: 14px;
    }

    .daily-sales-btn {
        background-color: #1b5e20;
        color: white;
        padding: 6px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
    }

    .daily-sales-btn:hover {
        background-color: rgb(136, 227, 141);
    }
</style>

</head>
<body>
    <button onclick="window.location.href='staff.php'">BACK TO PAGE</button>
    <h2>MEDREP LIST</h2>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
    <form id="filterForm" method="GET" action="">
    <input type="text" id="searchInput" name="search" placeholder="Search for names.." value="<?= htmlspecialchars($search_query) ?>">
    
    <select id="companyFilter" name="company" onchange="document.getElementById('filterForm').submit()">
        <option value="">All Companies</option>
        <?php
        $company_stmt = $conn->query("SELECT DISTINCT company FROM employees ORDER BY company ASC");
        while ($row = $company_stmt->fetch_assoc()) {
            $selected = ($row['company'] == $company_filter) ? 'selected' : '';
            echo "<option value=\"" . htmlspecialchars($row['company']) . "\" $selected>" . htmlspecialchars($row['company']) . "</option>";
        }
        ?>
    </select>

    <button type="submit">Search</button>
</form>


    <div>
        <button class="daily-sales-btn" onclick="window.location.href='sales.php'">
            DAILY SALES
        </button>
    </div>
</div>


    <table>
        <tr>
        <tr>
    <th>NAME</th>
    <th>COMPANY</th>
    <th>GIFT CARD</th>
    <th>TOTAL BALANCE</th>
    <th>ACTION</th>
</tr>

<?php 
        while ($row = $result->fetch_assoc()) { 
            $total_balance = floatval($row['total_balance']); // Use SQL-calculated total_balance
            $balance_color = ($total_balance >= 0) ? "green" : "red";
        ?>
          <tr>
          <td onclick="redirectToProfile('<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['company']); ?>')">
    <?php echo strtoupper(htmlspecialchars($row['name'])); ?>
</td>
<td onclick="redirectToProfile('<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['company']); ?>')">
    <?php echo strtoupper(htmlspecialchars($row['company'])); ?>
</td>
<td>
    <strong>₱<?php echo number_format($row['total_giftcard'], 2); ?></strong>
</td>

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

    
<td>
    <div class="action-buttons">
    <button class="cashout-button" onclick="event.stopPropagation(); openCashOutModal('<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['company']); ?>', <?php echo $row['total_deposit']; ?>)"> 
        Cash Out 
    </button>
    <button class="transfer-button" onclick="event.stopPropagation(); opentransferModal('<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['company']); ?>', <?php echo $row['total_deposit']; ?>)"> 
        Transfer 
    </button>
    </div>
</td>

</tr>

        <?php } ?>
    </table>

    <div id="modalOverlay" class="modal-overlay"></div>

<!-- Cashout modal -->
<div id="cashOutModal" class="modal">
    <h3>CASH OUT</h3>
    <label>NAME:</label> <span id="cashOutName"></span><br>
    <label>COMPANY:</label> <span id="cashOutCompany"></span><br>
    <label>AMOUNT TO CASHOUT:</label> <input type="number" id="cashOutAmount" oninput="calculateInterest()" required /> <br />
    <label>INTEREST OPTION:</label>
    <select id="interestOption" onchange="calculateInterest()">
        <option value="add">Add 35%</option>
        <option value="deduct">Deduct 35%</option>
    </select><br>
    <label>INTEREST:</label> <span id="interestAmount">0</span><br>
    <label>TOTAL CASH OUT:</label> <span id="totalCashOut">0</span><br>
    <button id="cashOutButton" onclick="submitCashOut()">Submit</button>
    <button onclick="closeModal()">Cancel</button>
</div>

<!-- Transfer Modal -->
<form id="transferForm" action="process_transfer.php" method="POST">
    <div id="transferModal" class="modal">
        <h3>TRANSFER</h3>

        <label>Sender Name:</label> 
        <input type="text" name="sender_name" id="sender_name" readonly><br>

        <label>Sender Company:</label>
        <input type="text" name="sender_company" id="sender_company" readonly><br>

        <label>Transfer To:</label>
        <input type="text" id="receiver_name" name="receiver_name" oninput="fetchSuggestions()" required><br>

        <div id="suggestions" style="display: none;"></div>

        <label>Company:</label>
        <input type="text" id="receiver_company" name="receiver_company" readonly><br>

        <!-- Visible amount field -->
        <label>Amount:</label>
        <input type="number" name="receiver_amount" id="receiver_amount" required oninput="syncAmount()"><br>

        <!-- Hidden amount field -->
        <input type="number" name="sender_amount" id="sender_amount" style="display: none;">

        <button type="submit">Confirm Transfer</button>
        <button type="button" onclick="closeModal()">Cancel</button>
    </div>
</form>



    <script src="assets/ajax-forms.js"></script>
    <script>
    let selectedName = "";
let selectedCompany = "";
let totalDeposit = 0;
const interestRate = 0.35;

function openCashOutModal(name, company, deposit) {
    selectedName = name;
    selectedCompany = company;
    totalDeposit = deposit;
    
    document.getElementById('cashOutName').innerText = name;
    document.getElementById('cashOutCompany').innerText = company;
    document.getElementById('cashOutAmount').value = "";
    document.getElementById('interestAmount').innerText = "0";
    document.getElementById('totalCashOut').innerText = "0";
    document.getElementById('interestOption').value = "add";

    document.getElementById('cashOutModal').style.display = 'block';
    document.getElementById('modalOverlay').style.display = 'block';  // Ensure overlay is displayed

    document.body.classList.add("modal-active"); // Prevent scrolling
}


function calculateInterest() {
    let cashOutAmount = parseFloat(document.getElementById("cashOutAmount").value) || 0;
    let interestOption = document.getElementById("interestOption").value;
    let interest = cashOutAmount * interestRate;
    let finalCashOut = interestOption === "add" ? cashOutAmount + interest : cashOutAmount - interest;
    
    document.getElementById('interestAmount').innerText = interest.toFixed(2);
    document.getElementById('totalCashOut').innerText = finalCashOut.toFixed(2);
}

function submitCashOut() {
    const cashOutAmount = parseFloat(document.getElementById("cashOutAmount").value) || 0;
    const interest = parseFloat(document.getElementById("interestAmount").innerText.replace('₱', ''));
    const totalCashOut = parseFloat(document.getElementById("totalCashOut").innerText.replace('₱', ''));
    const cashOutButton = document.getElementById('cashOutButton');

    if (cashOutAmount <= 0) {
        alert('Please enter a valid cashout amount.');
        return;
    }

    const selectedName = document.getElementById('cashOutName').innerText.trim();
    const selectedCompany = document.getElementById('cashOutCompany').innerText.trim();
    const interestOption = document.getElementById("interestOption").value; // "add" or "deduct"

    // Disable button to prevent duplicate submissions
    cashOutButton.disabled = true;
    cashOutButton.innerText = 'Processing...';

    // Send data to server via fetch
    fetch('process_cashout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            name: selectedName,
            company: selectedCompany,
            cashOutAmount: cashOutAmount,
            interest: interest,
            totalCashOut: totalCashOut,
            operation: interestOption // Add or Deduct
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast('Cashout successfully recorded!', 'success');
            closeModal(); // Close modal on success
            setTimeout(function () { window.location.reload(); }, 400); // Refresh the page to reflect changes
        } else {
            showToast(`Error: ${data.message || 'Failed to process cashout'}`, 'error');
        }
    })
    .catch(error => {
        showToast('An error occurred while processing the request.', 'error');
        console.error('Error:', error);
    })
    .finally(() => {
        // Re-enable the button
        cashOutButton.disabled = false;
        cashOutButton.innerText = 'Submit';
    });
}

        function redirectToProfile(name, company) {
        window.location.href = 'summary.php?name=' + name + '&company=' + company;
    }
    function autoSubmit() {
            document.getElementById("filterForm").submit();
        }


function opentransferModal(name, company) {
    document.getElementById("sender_name").value = name;
    document.getElementById("sender_company").value = company;
    document.getElementById("transferModal").style.display = "block";
    document.getElementById("modalOverlay").style.display = "block";
}

function fetchSuggestions() {
    let input = document.getElementById("receiver_name").value;
    
    if (input.length < 2) {
        document.getElementById("suggestions").style.display = "none";
        return;
    }

    fetch("suggestions.php?query=" + encodeURIComponent(input))
        .then(response => response.json())
        .then(data => {
            let suggestionsDiv = document.getElementById("suggestions");
            suggestionsDiv.innerHTML = "";
            suggestionsDiv.style.display = "block";

            data.forEach(item => {
                let suggestion = document.createElement("div");
                suggestion.innerText = item.name + " - " + item.company;
                suggestion.style.cursor = "pointer";
                suggestion.onclick = function () {
                    document.getElementById("receiver_name").value = item.name;
                    document.getElementById("receiver_company").value = item.company;
                    suggestionsDiv.style.display = "none";
                };
                suggestionsDiv.appendChild(suggestion);
            });
        })
        .catch(error => console.error("Error fetching suggestions:", error));
}

    function selectReceiver(name, company) {
        document.getElementById('receiver_name').value = name;
        document.getElementById('receiver_company').value = company;
        document.getElementById('suggestions').style.display = 'none';
    }

     // Function to sync the values of both amount fields
     function syncAmount() {
        var amount = document.getElementById("receiver_amount").value;
        document.getElementById("sender_amount").value = amount;
    }

    function closeModal() {
    document.getElementById('cashOutModal').style.display = 'none';
    document.getElementById('transferModal').style.display = 'none';
    document.getElementById('modalOverlay').style.display = 'none'; 
    document.body.classList.remove("modal-active"); // Enable scrolling
}

// Transfer, like cashout, changes balances for two different medreps at
// once, so it saves via AJAX (no full-page POST navigation) and does a
// single fast reload after a toast confirms it went through, instead of
// the old form POST -> redirect chain.
ajaxForm('#transferForm', {
    onSuccess: function () {
        closeModal();
        setTimeout(function () { window.location.reload(); }, 400);
    }
});




        function redirectToProfile(name, company) {
        window.location.href = 'summary.php?name=' + name + '&company=' + company;
    }
    document.getElementById('searchInput').addEventListener("keypress", function(e) {
    if (e.key === "Enter") {
        document.getElementById('filterForm').submit();
    }
});
    </script>
</body>
</html>
