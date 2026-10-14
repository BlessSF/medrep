<?php
session_start();



require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("CONNECTION FAILED: " . $conn->connect_error);
}

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if (!empty($query)) {
    // Fetch suggestions for both name and company
    $sql = "SELECT name, company FROM employees WHERE name LIKE ? OR company LIKE ? LIMIT 10";
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
} else {
    echo json_encode([]);
}
?>
