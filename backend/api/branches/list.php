<?php
require_once __DIR__ . '/../../includes/api_helpers.php';

$result = $conn->query("SELECT name FROM branches ORDER BY name");
$branches = [];
while ($row = $result->fetch_assoc()) {
    $branches[] = $row['name'];
}

json_success(['branches' => $branches]);