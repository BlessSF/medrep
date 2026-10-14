<?php
require_once __DIR__ . '/../../includes/api_helpers.php';
$_SESSION = [];
session_destroy();
json_success();
