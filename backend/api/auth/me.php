<?php
require_once __DIR__ . '/../../includes/api_helpers.php';
if (!isset($_SESSION['username'])) {
    json_error('Not logged in', 401);
}
json_success(['username' => $_SESSION['username'], 'role' => $_SESSION['role'] ?? '', 'branch' => $_SESSION['branch'] ?? '']);