<?php
/**
 * Database bootstrap.
 * Default: SQLite file at database/receivables.sqlite (zero-config).
 * To use MySQL instead, edit config.php and set DB_DRIVER to 'mysql'.
 */

require_once __DIR__ . '/config.php';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (DB_DRIVER === 'mysql') {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Auto-init + auto-seed on first run, same as SQLite: if the
        // "companies" table doesn't exist yet, this is a fresh database.
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = '" . DB_NAME . "' AND table_name = 'companies'"
        )->fetchColumn();

        if (!$exists) {
            init_database($pdo);
        }
    } else {
        $dbFile = __DIR__ . '/../database/receivables.sqlite';
        $needsInit = !file_exists($dbFile);

        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($needsInit) {
            init_database($pdo);
        }
    }

    return $pdo;
}

function init_database(PDO $pdo): void
{
    // Pick the schema file that matches the active driver. Both are plain
    // PHP-executed SQL -- no external tools or scripts are involved.
    $schemaFile = DB_DRIVER === 'mysql'
        ? __DIR__ . '/../database/schema_mysql.sql'
        : __DIR__ . '/../database/schema.sql';

    $schema = file_get_contents($schemaFile);

    if (DB_DRIVER === 'mysql') {
        // Run each statement separately; PDO_MYSQL doesn't reliably support
        // multi-statement exec() the way SQLite's PDO driver does.
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $pdo->exec($stmt);
        }
    } else {
        $pdo->exec($schema);
    }

    // Default login accounts (change passwords after first login!)
    // role 'admin'  -> full access, sees every branch's companies & ledgers
    // role 'branch' -> restricted to companies/ledger entries tagged with
    //                  their own username as the branch (data isolation)
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
    $defaultUsers = [
        ['demic', 'demic123', 'Demic-lab', 'admin'],
        ['dois', 'dois123', 'Dois', 'branch'],
        ['stella', 'stella123', 'Stella', 'branch'],
        ['h', 'h123', 'H', 'branch'],
        ['pub', 'pub123', 'Pub', 'branch'],
        ['owner', 'owner123', 'Owner', 'admin'],
        ['accounting', 'accounting123', 'Accounting', 'admin'],
    ];
    foreach ($defaultUsers as [$username, $password, $fullName, $role]) {
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
    }

    // Seed companies + ledger entries from seed_data.json, if present
    $seedFile = __DIR__ . '/../database/seed_data.json';
    if (file_exists($seedFile)) {
        $data = json_decode(file_get_contents($seedFile), true);
        if (is_array($data)) {
            seed_from_json($pdo, $data);
        }
    }
}

function seed_from_json(PDO $pdo, array $companies): void
{
    $insCompany = $pdo->prepare(
        'INSERT INTO companies (name, status) VALUES (?, ?)'
    );
    $insLedger = $pdo->prepare(
        'INSERT INTO ledger_entries
            (company_id, billing_date, soa_number, amount, tax_withheld, surcharge, rebate,
             receivable_amount, payment_date, check_ref, paid_amount, balance, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    foreach ($companies as $c) {
        $status = 'ACTIVE';
        if (stripos($c['name'], 'bankrupt') !== false) {
            $status = 'BANKRUPT';
        }
        $insCompany->execute([$c['name'], $status]);
        $companyId = (int)$pdo->lastInsertId();

        foreach ($c['entries'] as $e) {
            $amount = (float)($e['amount'] ?? 0);
            $tax = (float)($e['tax_withheld'] ?? 0);
            $surcharge = (float)($e['surcharge'] ?? 0);
            $rebate = (float)($e['rebate'] ?? 0);
            $paid = (float)($e['paid_amount'] ?? 0);
            $receivable = $amount - $tax + $surcharge - $rebate;
            $balance = isset($e['balance']) && $e['balance'] !== null
                ? (float)$e['balance']
                : max(0, $receivable - $paid);

            $insLedger->execute([
                $companyId,
                $e['billing_date'] ?? null,
                $e['soa_number'] ?? null,
                $amount,
                $tax,
                $surcharge,
                $rebate,
                $receivable,
                $e['payment_date'] ?? null,
                $e['check_ref'] ?? null,
                $paid,
                $balance,
                $e['remarks'] ?? null,
            ]);
        }
    }
    $pdo->commit();
}
