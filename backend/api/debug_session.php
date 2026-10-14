<?php
// TEMPORARY DEBUG FILE — delete after use. Shows exactly what PHP sees
// for the current session: the ID, its data, and where session files
// are being saved on this specific host.
require_once __DIR__ . '/../includes/api_helpers.php';

header_remove('Content-Type');
header('Content-Type: text/plain; charset=utf-8');

echo "Session ID: " . session_id() . "\n";
echo "Session save path: " . session_save_path() . "\n";
echo "Session save handler: " . ini_get('session.save_handler') . "\n";
echo "Session data:\n";
var_export($_SESSION);
echo "\n\n";
echo "Cookie received: " . ($_COOKIE['PHPSESSID'] ?? '(none)') . "\n";