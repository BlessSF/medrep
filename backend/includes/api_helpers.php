<?php
// ============================================================
//  api_helpers.php — shared bootstrap for every api/*.php endpoint.
//  Include this (not db_config.php directly) at the top of each
//  endpoint. It starts the session, sets CORS headers so the React
//  dev server (different origin) can call these with cookies, and
//  gives every endpoint the same JSON error/success helpers.
// ============================================================

// ---- CORS ---------------------------------------------------
// The frontend runs on its own origin (React dev server locally,
// or Vercel in production) while this API + PHP session cookie
// live on the PHP host. Reflect the request origin (instead of a
// static *) so credentials:'include' cookies are allowed by the
// browser — a hardcoded Access-Control-Allow-Origin only ever
// works for exactly that one origin.
$allowed_origins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
    'http://localhost:3001',
    'https://medrep-two.vercel.app',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origin_is_allowed = $origin !== '' && (
    in_array($origin, $allowed_origins, true)
    || preg_match('/^https:\/\/[a-z0-9-]+\.vercel\.app$/i', $origin) === 1
);
if ($origin_is_allowed) {
    header("Access-Control-Allow-Origin: $origin");
    // Tell any CDN/edge cache in front of this host that the response
    // varies per Origin, so it doesn't cache one origin's CORS header
    // and serve it to every other origin.
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Local dev (React on :5173/:3000/:3001 talking to PHP on :8000) is
// cross-PORT but same-SITE — browsers ignore port when deciding "site" —
// so SameSite=Lax cookies work over plain http://localhost just fine.
// A real deployment like Vercel (frontend) + Hostinger (backend) is
// cross-SITE (different registrable domains), which Lax cookies are
// NOT sent on for cross-site fetches — the browser would otherwise log
// in successfully but then silently drop the session on the very next
// request (e.g. on refresh). Cross-site cookies require SameSite=None +
// Secure, which in turn requires HTTPS. Detect which situation we're in
// from the request itself rather than hardcoding one or the other.
$is_https = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$session_lifetime = 60 * 60 * 24 * 30; // 30 days
// The cookie's own expiry (above) only controls the browser's copy. PHP
// separately garbage-collects the session's DATA on the server after
// session.gc_maxlifetime seconds (default ~24 minutes!) regardless of the
// cookie, so without this a "persistent" login would still silently die
// after sitting idle for less than half an hour.
ini_set('session.gc_maxlifetime', (string)$session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'samesite' => $is_https ? 'None' : 'Lax',
    'secure' => $is_https, // SameSite=None is rejected by browsers unless Secure is also set.
    'httponly' => true,
]);
session_start();

require_once __DIR__ . '/db_config.php';
if ($conn->connect_error) {
    json_error('Database connection failed: ' . $conn->connect_error, 500);
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST;
}

function json_success($data = [], int $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true] + (is_array($data) ? $data : ['data' => $data]));
    exit;
}

function json_error(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message, 'message' => $message]);
    exit;
}

function require_login(): string {
    if (!isset($_SESSION['username'])) {
        json_error('Not logged in', 401);
    }
    return $_SESSION['username'];
}

// Every endpoint that reads/writes employees, companies, users, or logs
// (i.e. anything AFTER login) should scope its query by this. Public,
// pre-login endpoints like branches/list.php should NOT call this.
function current_branch(): string {
    if (!isset($_SESSION['branch'])) {
        json_error('Not logged in', 401);
    }
    return $_SESSION['branch'];
}

function require_role(mysqli $conn, string $username, array $roles): string {
    $stmt = $conn->prepare("SELECT role FROM users WHERE LOWER(username) = LOWER(?)");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $role = strtolower($row['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        json_error('Forbidden: insufficient role', 403);
    }
    return $role;
}

function log_action(mysqli $conn, string $username, string $action, string $details, string $branch = null) {
    $branch = $branch ?? ($_SESSION['branch'] ?? 'STELLA');
    $stmt = $conn->prepare("INSERT INTO logs (action, username, details, branch) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('ssss', $action, $username, $details, $branch);
        $stmt->execute();
        $stmt->close();
    }
}

// Faithful port of the balance formula used across submit.php,
// customer_profile.php and receivables_breakdown.php: card-paid
// dinein/takeout/giftcard/cashout/interest rows are excluded from the
// "payable"/"cashout" side (they're settled outside the ledger), while
// deposits, transfers and sends always count.
function compute_balance(mysqli $conn, string $name, string $branch): array {
    $sql = "SELECT
                COALESCE(SUM(deposit),0) + COALESCE(SUM(receiver_amount),0) AS total_deposit,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r', ''), '\\n', ''))) <> 'card'
                    THEN dinein + takeout + giftcard ELSE 0 END),0) AS total_payable,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(REPLACE(REPLACE(COALESCE(payment_method,''), '\\r', ''), '\\n', ''))) <> 'card'
                    THEN cashout + interest ELSE 0 END),0) AS total_cashout,
                COALESCE(SUM(receiver_amount),0) AS received,
                COALESCE(SUM(sender_amount),0) AS sent,
                COALESCE(SUM(giftcard),0) AS total_giftcard
            FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $name, $branch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $used_stmt = $conn->prepare("SELECT COALESCE(SUM(dinein + takeout),0) AS used FROM employees WHERE payment_method = 'giftcard' AND UPPER(name) = UPPER(?) AND branch = ?");
    $used_stmt->bind_param('ss', $name, $branch);
    $used_stmt->execute();
    $used = $used_stmt->get_result()->fetch_assoc()['used'] ?? 0;
    $used_stmt->close();

    $total_deposit = (float)$row['total_deposit'];
    $total_payable = (float)$row['total_payable'];
    $total_cashout = (float)$row['total_cashout'];
    $received = (float)$row['received'];
    $sent = (float)$row['sent'];

    $balance = $total_deposit - ($total_payable + $total_cashout) + $received - $sent;
    $giftcard_balance = (float)$row['total_giftcard'] - (float)$used;

    return [
        'total_deposit' => $total_deposit,
        'total_payable' => $total_payable,
        'total_cashout' => $total_cashout,
        'received' => $received,
        'sent' => $sent,
        'balance' => $balance,
        'giftcard_balance' => $giftcard_balance,
    ];
}

function is_blocked(mysqli $conn, string $name, string $branch): bool {
    $stmt = $conn->prepare("SELECT block FROM employees WHERE UPPER(name) = UPPER(?) AND branch = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ss', $name, $branch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && strtolower($row['block'] ?? '') === 'block';
}

function ensure_companies_table(mysqli $conn, string $branch) {
    $conn->query("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        branch VARCHAR(100) NOT NULL DEFAULT 'STELLA',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY branch_name (branch, name)
    )");
    $stmt = $conn->prepare("INSERT IGNORE INTO companies (name, branch)
        SELECT DISTINCT TRIM(company), branch FROM employees
        WHERE company IS NOT NULL AND TRIM(company) <> '' AND branch = ?");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $stmt->close();
}