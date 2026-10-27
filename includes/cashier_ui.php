<?php
// Shared top bar for the cashier side: brand, Transaction | Voucher tabs, signed-in user, logout.
// Usage (inside <body>, before the page content):
//     require_once __DIR__ . '/includes/cashier_ui.php';
//     cashier_appbar('transaction', $_SESSION['username']);      // or 'voucher'
// Styles live in assets/cashier.css (load it in <head> together with the Inter font).
function cashier_appbar($active, $username) {
    $tabs = [
        'transaction' => ['staff.php',              'Transaction', 'fa-receipt'],
        'voucher'     => ['voucher_monitoring.php', 'Voucher',     'fa-ticket'],
    ];
    $initial = strtoupper(mb_substr(trim((string)$username) ?: '?', 0, 1));
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    ?>
<header class="appbar">
    <a class="brand" href="staff.php">
        <span class="brand-logo"><i class="fas fa-hand-holding-medical"></i></span>
        <span class="brand-text"><span class="brand-name">Medrep</span><span class="brand-sub">Daily transaction</span></span>
    </a>

    <nav class="nav" aria-label="Cashier sections">
        <?php foreach ($tabs as $key => [$href, $label, $icon]): ?>
            <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <i class="fas <?= $icon ?>"></i><span><?= $label ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="appbar-right">
        <div class="user">
            <span class="avatar"><?= $e($initial) ?></span>
            <span class="user-meta"><span class="user-name"><?= $e($username) ?></span><span class="user-role">Cashier</span></span>
        </div>
        <a href="index.php?logout=true" class="logout" title="Log out"><i class="fas fa-arrow-right-from-bracket"></i><span>Logout</span></a>
    </div>
</header>
<?php
}