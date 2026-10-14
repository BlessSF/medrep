<?php
// Database connection
require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$year = isset($_GET['year']) ? $_GET['year'] : date("Y");
$month = isset($_GET['month']) ? $_GET['month'] : date("m");
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAILY SALES SUMMARY</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px;        
            background:rgb(227, 251, 203);
        }
        h2 { margin-bottom: 20px; }
        .table-container {
    max-height: 500px; /* Set desired scroll height */
    overflow-y: auto;
    overflow-x: auto;
    position: relative;
    border: 1px solid #ccc;
    width: 100%; /* Full width */
}

table {
    width: 100%; /* Table fills the container */
    border-collapse: collapse;
    text-align: center;
}
.table-container {
    max-height: 550px;
    overflow-y: auto;
    overflow-x: auto;
    position: relative;
    border: 1px solid #ccc;
    width: 100%;
}

thead th {
    position: sticky;
    top: 0;
            background-color:rgb(186, 230, 139); 
    color: black;
    z-index: 2;
    text-align: center;
    height: 30px;
    border: 1px solid black; /* Add black borders to each cell */
    font: 1.5em sans-serif;

}
        td {    border: 1px solid black; /* Add black borders to each cell */
            font: 1.2em sans-serif;
        }
        .button-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

button {
        background-color: #1b5e20;
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 4px;
        font-size: 16px;
    }
    button:hover {
        background-color:rgb(136, 227, 141);
    }


.header-with-button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px; /* <--- make this smaller */
}

.print-btn {
    padding: 6px 15px;
    background-color: #1b5e20;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}

        /* Styling the form for better layout */
form {
    margin-top: 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    justify-content: flex-start;
    align-items: center;
}

form label {
    font-weight: bold;
}

form select {
    padding: 8px;
    font-size: 14px;
    border-radius: 4px;
    border: 1px solid #ccc;
    background-color: white;
}

form select:focus {
    border-color: #1b5e20;
    outline: none;
}

form button {
    padding: 8px 15px;
    background-color: #1b5e20;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

form button:hover {
    background-color: rgb(136, 227, 141);
}
 @media print {
            body {
                background: rgb(227, 251, 203) !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact;
            }

            thead th {
                background-color: rgb(96, 198, 103) !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            td[style*="background: #f0e68c;"] {
                background-color: #f0e68c !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .button-container {
                display: none;
            }

            .no-print {
                display: none !important;
            }

            .print-btn {
                display: none;
            }

            .table-container {
                max-height: none; /* Remove max-height for print */
                overflow: visible; /* Remove overflow to ensure the entire table is visible */
            }

            table {
                width: 100%; /* Ensure table is fully visible */
                border: 1px solid #ccc;
            }

            thead th {
                position: static; /* Make header sticky to regular position during printing */
                background-color: rgb(186, 230, 139);
                color: black;
            }
        }


    </style>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <button class="no-print" onclick="window.location.href='staff.php'">BACK</button>


<div id="printArea">
<div class="header-with-button">
    <h2>Daily Sales Summary - <?php echo date("F Y", strtotime("$year-$month-01")); ?></h2>
    <button class="print-btn no-print" onclick="printReceipt()">PRINT SALES</button>
</div>

   <div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Dine-In Sales</th>
                <th>Take-Out Sales</th>
                <th>Total Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $grandDineIn = 0;
            $grandTakeOut = 0;

            for ($day = 1; $day <= $daysInMonth; $day++) {
                // Format the date based on year, month, and day
                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);

                // Prepare the SQL query to get dine-in and take-out sales for the specific date
                $stmt = $conn->prepare("SELECT SUM(dinein) AS dinein_total, SUM(takeout) AS takeout_total FROM employees WHERE DATE(created_at) = ?");
                $stmt->bind_param("s", $date);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();

                // Fetch the dine-in and take-out sales, or default to 0 if null
                $dinein = $row['dinein_total'] ?? 0;
                $takeout = $row['takeout_total'] ?? 0;
                $total = $dinein + $takeout;

                // Accumulate grand totals
                $grandDineIn += $dinein;
                $grandTakeOut += $takeout;
            ?>
               <tr>
    <td><?php echo $date; ?></td>
    <td><?php echo number_format($dinein, 2); ?></td>
    <td><?php echo number_format($takeout, 2); ?></td>
    <td style="font-weight: bold; font-size: 1.2em; background: #f0e68c;"><?php echo number_format($total, 2); ?></td>
</tr>

            <?php
                $stmt->close();
            }
            ?>
            <tr style="font-weight: bold;font-size: 1.2em;">
                <td style="text-align: right;">Grand Total</td>
                <td><strong><?php echo number_format($grandDineIn, 2); ?></strong></td>
                <td><strong><?php echo number_format($grandTakeOut, 2); ?></strong></td>
                <td><strong><?php echo number_format($grandDineIn + $grandTakeOut, 2); ?></strong></td>
            </tr>
            </tbody>
    </table>
</div>
<script>
function printReceipt() {
    // Previously this swapped document.body.innerHTML to isolate the
    // printable area, called window.print(), restored the original
    // innerHTML, then called window.location.reload() to be safe --
    // which caused a visible full-page reload/flash after every print,
    // and any event listeners bound before the swap were lost for good
    // (innerHTML restore doesn't restore JS bindings).
    //
    // The @media print rules above already hide everything except
    // #printArea (via .no-print on the buttons), so printing can just
    // use the browser's native print scoping -- no DOM surgery, no
    // reload, nothing to restore.
    window.print();
}
</script>

</body>
</html>

<?php
$conn->close();
?>