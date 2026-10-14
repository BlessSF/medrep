<?php
// Single source of truth for the DB connection. Same 4 credentials the
// original app used — edit these for your environment (XAMPP/local or
// your live host).
define('APP_DEBUG', true);
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

mysqli_report(MYSQLI_REPORT_OFF);

$servername = "127.0.0.1:3306";
$username   = "u905106266_hero";
$password   = "Ilovehero8";
$dbname     = "u905106266_medrep_hero";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset('utf8mb4');
