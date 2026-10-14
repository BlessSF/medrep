<?php

define('DB_DRIVER', 'mysql');

// Only used when DB_DRIVER = 'mysql'
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'receivables_system');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Receivables Management System');
define('COMPANY_NAME', 'Multipliers');

// How many days after billing date an SOA is considered "due" if no explicit due_date is set
define('DEFAULT_DUE_DAYS', 90);

date_default_timezone_set('Asia/Manila');
