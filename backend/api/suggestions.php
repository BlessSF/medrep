<?php
require_once __DIR__ . '/../includes/api_helpers.php';
require_login();
$branch = current_branch();

$query = trim($_GET['query'] ?? '');
if ($query === '') json_success(['suggestions' => []]);

$sql = 'SELECT DISTINCT name, company FROM employees WHERE (name LIKE ? OR company LIKE ?) AND branch = ? LIMIT 10';
$stmt = $conn->prepare($sql);
$search_query = "%$query%";
$stmt->bind_param('sss', $search_query, $search_query, $branch);
$stmt->execute();
$suggestions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
json_success(['suggestions' => $suggestions]);