<?php
// ============================================================
//  db_config.php — single source of truth for the DB connection
// ------------------------------------------------------------
//  Every page used to hardcode these same 4 credentials on its own
//  (26 separate copies). Besides being hard to rotate, one copy
//  (admin.php) had a typo'd $dbname ("medrep" instead of
//  "u905106266_medrep") that silently pointed the admin dashboard
//  at the wrong database. Now there's one place to update.
//
//  Usage: require_once __DIR__ . '/includes/db_config.php'; at the top of a
//  page gives it a ready-to-use $conn. Each page still does its own
//  connect_error check afterward, since some show a plain die()
//  message and others need to respond with JSON.
// ============================================================

// Show PHP errors on-screen only while actively debugging locally.
// Leaving display_errors on in production (as every page used to)
// prints file paths, table/column names, and query fragments to
// anyone who can trigger a warning or notice. Errors are still
// logged either way.
define('APP_DEBUG', false);
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// On PHP 8.1+, mysqli's default error mode switched to throwing a
// mysqli_sql_exception on any failed query instead of returning false.
// Every page in this app was written against the old behavior (checking
// ->connect_error, checking if execute() returned false, etc.), so
// without this line a failed query is an *uncaught exception* — a blank
// HTTP 500 with nothing logged to the screen — instead of the friendly
// error handling each page already has. This restores the old behavior
// site-wide.
mysqli_report(MYSQLI_REPORT_OFF);

// InfinityFree hosting database (medrep.infinityfree.me).
// To switch back to local development (XAMPP/phpMyAdmin at 127.0.0.1,
// database "medrep"), swap these 4 lines for:
//   $servername = "localhost"; $username = "root"; $password = ""; $dbname = "medrep";
$servername = "194.59.164.80";
$username   = "u905106266_medrep";
$password   = "Medrep123";
$dbname     = "u905106266_medrep";

$conn = new mysqli($servername, $username, $password, $dbname);
