<?php
// Shared top tab bar for the cashier side: Transaction | Voucher.
// Usage:  require_once __DIR__ . '/includes/cashier_tabs.php';  cashier_tabs('transaction');  (or 'voucher')
// The bar is fixed directly under the green header (header height = 70px).
function cashier_tabs($active) {
    $tabs = [
        'transaction' => ['staff.php',              'Transaction', 'fa-receipt'],
        'voucher'     => ['voucher_monitoring.php', 'Voucher',     'fa-ticket'],
    ];
    ?>
<style>
    .cashier-tabs { position: fixed; top: 70px; left: 0; right: 0; height: 44px; z-index: 900; display: flex; align-items: stretch; gap: 0;
                    padding: 0 26px; background: #ffffff; border-bottom: 1px solid #b9c9b4; }
    .cashier-tabs a { display: flex; align-items: center; gap: 8px; padding: 0 22px; font-size: 14px; font-weight: 700; color: #4a5a4c; text-decoration: none;
                      text-transform: uppercase; letter-spacing: .3px; border-bottom: 3px solid transparent; margin-bottom: -1px; }
    .cashier-tabs a:hover { color: #1b5e20; background: #f2f7ef; }
    .cashier-tabs a.active { color: #1b5e20; border-bottom-color: #1b5e20; background: #f2f7ef; }
    @media (max-width: 520px) { .cashier-tabs { padding: 0 8px; } .cashier-tabs a { flex: 1; justify-content: center; padding: 0 8px; } }
</style>
<nav class="cashier-tabs">
    <?php foreach ($tabs as $key => [$href, $label, $icon]): ?>
        <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>"><i class="fas <?= $icon ?>"></i> <?= $label ?></a>
    <?php endforeach; ?>
</nav>
<?php
}