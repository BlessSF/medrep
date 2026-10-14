<?php
// GET  /api/companies/index.php               -> list companies + record counts
// POST /api/companies/index.php {action:add|rename|delete, ...}
require_once __DIR__ . '/../../includes/api_helpers.php';
require_login();
$branch = current_branch();
ensure_companies_table($conn, $branch);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $action = $data['action'] ?? '';

    if ($action === 'add') {
        $name = trim($data['name'] ?? '');
        if ($name === '') json_error("Company name can't be empty.");
        $stmt = $conn->prepare('INSERT INTO companies (name, branch) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $branch);
        if (!$stmt->execute()) {
            $stmt->close();
            $msg = ($conn->errno === 1062) ? "\"$name\" already exists." : 'Could not save company.';
            json_error($msg);
        }
        $stmt->close();
        json_success(['message' => "Company \"$name\" saved."]);
    }

    if ($action === 'rename') {
        $id = (int)($data['id'] ?? 0);
        $new_name = trim($data['name'] ?? '');
        if ($id <= 0 || $new_name === '') json_error('Company id and new name are required.');

        $old_stmt = $conn->prepare('SELECT name FROM companies WHERE id = ? AND branch = ?');
        $old_stmt->bind_param('is', $id, $branch);
        $old_stmt->execute();
        $old_row = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();
        if (!$old_row) json_error('Company not found.', 404);
        $old_name = $old_row['name'];

        $stmt = $conn->prepare('UPDATE companies SET name = ? WHERE id = ? AND branch = ?');
        $stmt->bind_param('sis', $new_name, $id, $branch);
        if (!$stmt->execute()) { $stmt->close(); json_error('Could not rename company.', 500); }
        $stmt->close();

        $upd = $conn->prepare('UPDATE employees SET company = ? WHERE company = ? AND branch = ?');
        $upd->bind_param('sss', $new_name, $old_name, $branch);
        $upd->execute();
        $upd->close();

        json_success(['message' => "Renamed \"$old_name\" to \"$new_name\"."]);
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM companies WHERE id = ? AND branch = ?');
            $stmt->bind_param('is', $id, $branch);
            $stmt->execute();
            $stmt->close();
        }
        json_success(['message' => 'Company deleted.']);
    }

    json_error('Unknown action.');
}

// ---- GET ----
$stmt = $conn->prepare("SELECT c.id, c.name, c.created_at,
    (SELECT COUNT(*) FROM employees e WHERE e.company COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci AND e.branch COLLATE utf8mb4_unicode_ci = c.branch COLLATE utf8mb4_unicode_ci) AS record_count
    FROM companies c WHERE c.branch = ? ORDER BY c.name ASC");
if (!$stmt) {
    json_error('Query prepare failed: ' . $conn->error, 500);
}
$stmt->bind_param('s', $branch);
if (!$stmt->execute()) {
    json_error('Query execute failed: ' . $stmt->error, 500);
}
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
json_success(['companies' => $companies]);