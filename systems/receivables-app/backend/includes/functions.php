<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function money(?float $amount): string
{
    if ($amount === null) {
        $amount = 0;
    }
    $formatted = number_format(abs($amount), 2);
    return ($amount < 0 ? '(' . $formatted . ')' : $formatted);
}

function fdate(?string $date, string $format = 'M d, Y'): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return htmlspecialchars($date);
    }
    return date($format, $ts);
}

/**
 * Days between a billing/due date and today. Positive = overdue.
 */
function days_overdue(?string $dueDate): ?int
{
    if (!$dueDate) {
        return null;
    }
    $due = strtotime($dueDate);
    if ($due === false) {
        return null;
    }
    $today = strtotime(date('Y-m-d'));
    return (int) round(($today - $due) / 86400);
}

/**
 * ------------------------------------------------------------------
 * Lightweight column migration for databases created before a given
 * feature existed (e.g. companies.account_type). Safe to call on every
 * request; only runs the ALTER once per column per process.
 * ------------------------------------------------------------------
 */
function ensure_column(PDO $pdo, string $table, string $column, string $sqliteDef, string $mysqlDef): void
{
    static $checked = [];
    $cacheKey = $table . '.' . $column;
    if (!empty($checked[$cacheKey])) {
        return;
    }

    if (DB_DRIVER === 'mysql') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        $exists = (bool) $stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$mysqlDef}");
        }
    } else {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
        $exists = false;
        foreach ($cols as $c) {
            if (strcasecmp($c['name'], $column) === 0) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$sqliteDef}");
        }
    }

    $checked[$cacheKey] = true;
}

function ensure_company_account_type_column(PDO $pdo): void
{
    ensure_column(
        $pdo,
        'companies',
        'account_type',
        "TEXT NOT NULL DEFAULT 'COMPANY'",
        "VARCHAR(20) NOT NULL DEFAULT 'COMPANY'"
    );
}

function ensure_deduction_remarks_column(PDO $pdo): void
{
    ensure_column($pdo, 'ledger_entries', 'deduction_remarks', 'TEXT', 'TEXT');
}

/**
 * One-time migration: existing rows had balance = Receivable - Paid (i.e.
 * netted out tax withheld/surcharge/rebate). Balance is now Amount - Paid,
 * so recompute every stored balance once to match the new formula.
 */
function ensure_balance_formula_migrated(PDO $pdo): void
{
    if (get_setting($pdo, 'balance_formula_v2', '') === '1') {
        return;
    }
    $pdo->exec('UPDATE ledger_entries SET balance = amount - paid_amount');
    set_setting($pdo, 'balance_formula_v2', '1');
}

function ensure_check_date_column(PDO $pdo): void
{
    ensure_column($pdo, 'ledger_entries', 'check_date', 'TEXT', 'VARCHAR(50)');
}

function ensure_payment_method_column(PDO $pdo): void
{
    ensure_column($pdo, 'ledger_entries', 'payment_method', 'TEXT', 'VARCHAR(30)');
    ensure_column($pdo, 'ledger_entries', 'payment_method_bank', 'TEXT', 'VARCHAR(30)');
    ensure_column($pdo, 'ledger_entries', 'payment_method_other', 'TEXT', 'VARCHAR(100)');
}

/**
 * Ledger entries store the company's name alongside company_id -- not just
 * the foreign key -- so the row is self-describing when browsed directly in
 * phpMyAdmin/the DB (no join needed to see which company it belongs to).
 * Keeps it filled in for any row where it's missing/stale.
 */
function ensure_ledger_company_name_column(PDO $pdo): void
{
    ensure_column($pdo, 'ledger_entries', 'company_name', 'TEXT', 'VARCHAR(255)');
    $pdo->exec(
        "UPDATE ledger_entries
         SET company_name = (SELECT c.name FROM companies c WHERE c.id = ledger_entries.company_id)
         WHERE company_name IS NULL OR company_name = ''
            OR company_name <> (SELECT c.name FROM companies c WHERE c.id = ledger_entries.company_id)"
    );
}

function ensure_soa_status_column(PDO $pdo): void
{
    ensure_column(
        $pdo,
        'soa_tracker',
        'status',
        "TEXT NOT NULL DEFAULT 'UNUSED'",
        "VARCHAR(20) NOT NULL DEFAULT 'UNUSED'"
    );
}

/**
 * ------------------------------------------------------------------
 * SOA numbers must be unique EXCEPT when they end in "A" or "B" -- that
 * suffix marks a supplementary copy of the same statement, so repeating
 * the same number with an A/B tacked on is expected, not a mistake.
 * ------------------------------------------------------------------
 */
function soa_number_has_supplementary_suffix(string $soaNumber): bool
{
    $last = strtoupper(substr(trim($soaNumber), -1));
    return $last === 'A' || $last === 'B';
}

/**
 * True if $soaNumber is already USED somewhere else (an existing ledger
 * entry, or a SOA tracker row already marked USED) and isn't exempt via
 * the A/B suffix rule above. A tracker row that's still UNUSED doesn't
 * count as a conflict -- it's just a reserved/pre-generated number
 * waiting to be claimed, which is exactly what typing it into the ledger
 * (or assigning it on the Tracker page) is supposed to do.
 * Pass $excludeLedgerId/$excludeTrackerId to ignore the very row being
 * saved, so editing a row back to its own existing number isn't flagged
 * as a conflict with itself.
 */
function soa_number_conflicts(PDO $pdo, string $soaNumber, ?int $excludeLedgerId = null, ?int $excludeTrackerId = null): bool
{
    $soaNumber = trim($soaNumber);
    if ($soaNumber === '' || soa_number_has_supplementary_suffix($soaNumber)) {
        return false;
    }

    $sql = 'SELECT
        (SELECT COUNT(*) FROM ledger_entries WHERE soa_number = ?' . ($excludeLedgerId ? ' AND id <> ?' : '') . ') +
        (SELECT COUNT(*) FROM soa_tracker WHERE soa_number = ? AND status = \'USED\'' . ($excludeTrackerId ? ' AND id <> ?' : '') . ')';
    $params = [$soaNumber];
    if ($excludeLedgerId) {
        $params[] = $excludeLedgerId;
    }
    $params[] = $soaNumber;
    if ($excludeTrackerId) {
        $params[] = $excludeTrackerId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * One-time migration: soa_tracker.soa_number originally had a hard UNIQUE
 * constraint, which would block the intentional A/B-suffix duplicates
 * above at the database level regardless of the app-level check. Drops
 * that constraint (uniqueness is now enforced in PHP via
 * soa_number_conflicts() instead, where the A/B exception can apply).
 */
function ensure_soa_tracker_duplicates_allowed(PDO $pdo): void
{
    if (get_setting($pdo, 'soa_tracker_unique_dropped', '') === '1') {
        return;
    }

    if (DB_DRIVER === 'mysql') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soa_tracker'
               AND COLUMN_NAME = 'soa_number' AND NON_UNIQUE = 0"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $indexName) {
            if ($indexName === 'PRIMARY') {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE soa_tracker DROP INDEX `{$indexName}`");
            } catch (Exception $e) {
                // Already gone, or a name we can't drop this way -- ignore.
            }
        }
    } else {
        // SQLite has no ALTER TABLE ... DROP CONSTRAINT; the column-level
        // UNIQUE has to be removed by rebuilding the table.
        $indexes = $pdo->query("PRAGMA index_list(soa_tracker)")->fetchAll();
        $hasUniqueAutoIndex = false;
        foreach ($indexes as $idx) {
            if (!empty($idx['unique']) && stripos((string) $idx['name'], 'sqlite_autoindex') === 0) {
                $hasUniqueAutoIndex = true;
                break;
            }
        }
        if ($hasUniqueAutoIndex) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                $pdo->exec('ALTER TABLE soa_tracker RENAME TO soa_tracker_old_unique');
                $pdo->exec("CREATE TABLE soa_tracker (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    soa_number      TEXT NOT NULL,
                    company_id      INTEGER,
                    ledger_entry_id INTEGER,
                    status          TEXT NOT NULL DEFAULT 'UNUSED',
                    issued_date     TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL
                )");
                $pdo->exec(
                    'INSERT INTO soa_tracker (id, soa_number, company_id, ledger_entry_id, status, issued_date)
                     SELECT id, soa_number, company_id, ledger_entry_id, status, issued_date FROM soa_tracker_old_unique'
                );
                $pdo->exec('DROP TABLE soa_tracker_old_unique');
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    set_setting($pdo, 'soa_tracker_unique_dropped', '1');
}

/**
 * Called whenever an SOA number is typed straight into a ledger row: logs
 * it in the SOA tracker (creating the row if it's the first time this
 * number has been seen) and marks it USED against that company/entry --
 * so issuing it in the ledger is by itself enough, no separate manual
 * step in the SOA Tracker page is needed.
 *
 * If this ledger row previously pointed at a *different* SOA number, that
 * old tracker row is unlinked (and dropped back to UNUSED) so it doesn't
 * keep showing as used by an entry that's since moved on to a new number.
 */
function record_soa_number_used(PDO $pdo, string $soaNumber, ?int $companyId, int $ledgerEntryId): void
{
    $soaNumber = strtoupper(trim($soaNumber));
    if ($soaNumber === '') {
        return;
    }

    $pdo->prepare(
        "UPDATE soa_tracker SET ledger_entry_id = NULL, status = 'UNUSED'
         WHERE ledger_entry_id = ? AND soa_number <> ?"
    )->execute([$ledgerEntryId, $soaNumber]);

    // If more than one soa_tracker row happens to carry this exact number
    // (e.g. a leftover reserved/UNUSED row alongside one already USED),
    // prefer claiming the UNUSED one -- that's the row the SOA Tracker
    // page is showing as available, so that's the one that should get
    // updated. Picking arbitrarily here (plain LIMIT 1) is what let a
    // visibly "Unused" row sit untouched while a different, hidden row
    // for the same number stayed USED and kept blocking it.
    $stmt = $pdo->prepare(
        "SELECT id FROM soa_tracker WHERE soa_number = ?
         ORDER BY (status = 'USED') ASC, id ASC LIMIT 1"
    );
    $stmt->execute([$soaNumber]);
    $trackerId = $stmt->fetchColumn();

    if ($trackerId) {
        $pdo->prepare('UPDATE soa_tracker SET company_id = ?, ledger_entry_id = ?, status = ? WHERE id = ?')
            ->execute([$companyId, $ledgerEntryId, 'USED', $trackerId]);
    } else {
        $pdo->prepare(
            'INSERT INTO soa_tracker (soa_number, company_id, ledger_entry_id, status) VALUES (?, ?, ?, ?)'
        )->execute([$soaNumber, $companyId, $ledgerEntryId, 'USED']);
    }
}

/**
 * ------------------------------------------------------------------
 * App settings (key/value), e.g. the configurable aging grace period.
 * Lazily creates its own table so existing databases don't need a
 * manual migration.
 * ------------------------------------------------------------------
 */
function ensure_settings_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    )');
    $done = true;
}

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    ensure_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    ensure_settings_table($pdo);
    if (DB_DRIVER === 'mysql') {
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
        );
    }
    $stmt->execute([$key, $value]);
}

/**
 * How many days after billing an entry starts aging (grace period), when
 * no explicit due date is set on the entry itself. Editable in the UI
 * (e.g. 1 day, 10 days, 30 days...) -- falls back to DEFAULT_DUE_DAYS.
 */
function aging_grace_days(PDO $pdo): int
{
    return (int) get_setting($pdo, 'aging_grace_days', (string) DEFAULT_DUE_DAYS);
}

/**
 * The "Less Taxes Withheld" percentage auto-applied when an Amount is
 * typed into the ledger table (defaults to 2%, but editable in the UI --
 * some companies withhold at a different rate).
 */
function tax_withheld_percent(PDO $pdo): float
{
    return (float) get_setting($pdo, 'tax_withheld_percent', '2');
}

/**
 * Computes an implicit due date from billing date if no explicit due date is stored.
 */
function implicit_due_date(?string $billingDate, ?PDO $pdo = null): ?string
{
    if (!$billingDate) {
        return null;
    }
    $ts = strtotime($billingDate);
    if ($ts === false) {
        return null;
    }
    $pdo = $pdo ?? get_db();
    $graceDays = aging_grace_days($pdo);
    return date('Y-m-d', strtotime('+' . $graceDays . ' days', $ts));
}

/**
 * Aging bucket label based on days overdue.
 */
function aging_bucket(?int $daysOverdue): string
{
    if ($daysOverdue === null) {
        return 'N/A';
    }
    if ($daysOverdue < 0) {
        return 'Not Yet Due';
    }
    if ($daysOverdue <= 30) {
        return '0-30 days';
    }
    if ($daysOverdue <= 60) {
        return '31-60 days';
    }
    if ($daysOverdue <= 90) {
        return '61-90 days';
    }
    return 'Over 90 days';
}

function aging_badge_class(string $bucket): string
{
    return match ($bucket) {
        'Not Yet Due' => 'badge-secondary',
        '0-30 days' => 'badge-success',
        '31-60 days' => 'badge-warning',
        '61-90 days' => 'badge-orange',
        'Over 90 days' => 'badge-danger',
        default => 'badge-secondary',
    };
}

function next_soa_number(PDO $pdo, string $prefix = 'SN-C'): string
{
    // Find the highest number already used with this exact prefix -- checking
    // both the tracker log AND every ledger entry, so a number that was typed
    // straight into the ledger (with or without a trailing A/B/C) still counts
    // as the high-water mark. Only the leading digits are read, so "SN-C00050A"
    // is treated the same as "SN-C00050" for counting purposes.
    $escapedPrefix = preg_quote($prefix, '/');
    $max = 0;

    $stmt = $pdo->prepare('SELECT soa_number FROM soa_tracker WHERE soa_number LIKE ?');
    $stmt->execute([$prefix . '%']);
    $numbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare('SELECT soa_number FROM ledger_entries WHERE soa_number LIKE ?');
    $stmt->execute([$prefix . '%']);
    $numbers = array_merge($numbers, $stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($numbers as $num) {
        if ($num && preg_match('/^' . $escapedPrefix . '(\d+)/', $num, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    return sprintf('%s%05d', $prefix, $max + 1);
}

function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * ------------------------------------------------------------------
 * Branch data isolation helpers.
 *
 * Each branch account (dois, stella, h, pub, ...) only sees companies
 * (and, through them, ledger/monitoring/SOA entries) tagged with its own
 * username in companies.branch. The 'admin' role has no restriction and
 * sees every branch's data.
 * ------------------------------------------------------------------
 */

/**
 * SQL fragment + params to restrict a companies query to the current
 * branch. Returns ['', []] for admin (no restriction).
 * Usage: [$where, $params] = branch_where('c'); $sql .= $where; ...
 */
function branch_where(string $alias = 'c'): array
{
    $branch = current_branch();
    if ($branch === null) {
        return ['', []];
    }
    return [" AND {$alias}.branch = ?", [$branch]];
}

/**
 * Same as branch_where() but for a query that has no existing WHERE
 * clause yet (uses WHERE instead of AND).
 */
function branch_where_standalone(string $alias = 'c'): array
{
    $branch = current_branch();
    if ($branch === null) {
        return ['', []];
    }
    return [" WHERE {$alias}.branch = ?", [$branch]];
}

/**
 * Companies visible to the current user, for dropdowns etc.
 */
function accessible_companies(PDO $pdo): array
{
    [$where, $params] = branch_where_standalone('c');
    $stmt = $pdo->prepare("SELECT id, name FROM companies c{$where} ORDER BY c.name ASC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Whether the current user is allowed to view/edit the given company id.
 * Admin can access any company; a branch user only their own branch's.
 */
function can_access_company(PDO $pdo, ?int $companyId): bool
{
    if (!$companyId) {
        return false;
    }
    if (is_admin()) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ? AND branch = ?');
    $stmt->execute([$companyId, current_branch()]);
    return (bool) $stmt->fetchColumn();
}
