<?php
declare(strict_types=1);

/**
 * Verifikasi Dasbor Eksekutif SR-19: metrik real-time, drill-down, dan visibilitas role.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'RantauMart\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
foreach (['Middleware.php', 'IntegrationEventTrigger.php', 'reset_fixture.php'] as $f) {
    require_once dirname(__DIR__) . '/' . $f;
}
foreach (['Sales', 'Purchase', 'Inventory', 'Accounting', 'Pos', 'Report'] as $c) {
    require_once dirname(__DIR__) . "/controllers/{$c}Controller.php";
}

use RantauMart\Config\Database;
use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\DashboardController;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PosController;
use RantauMart\Controllers\SalesController;
use RantauMart\Services\AuthService;

$pass = 0;
$fail = 0;
function check(bool $ok, string $label): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
}

Database::resetBaselineSeed();
$pdo = Database::getConnection();

// 1. Baseline: angka berasal dari transaksi (Jurnal Pembukaan + stok), bukan konstanta
AuthService::login('Owner');
$vm = DashboardController::buildViewModel();
$m  = (array) ($vm['metrics'] ?? []);
$sqlOnHand = (int) $pdo->query('SELECT SUM(on_hand_qty) FROM stock_by_location')->fetchColumn();
check(
    $vm !== null && abs($m['bank'] - 15000000) < 0.01 && abs($m['cash'] - 200000) < 0.01
    && abs($m['ar'] - 888000) < 0.01 && abs($m['ap'] - 555000) < 0.01
    && $m['stock']['on_hand'] === $sqlOnHand && $m['stock']['available'] === $m['stock']['on_hand'] - $m['stock']['reserved']
    && abs($m['pos_sales']) < 0.01 && abs($m['cogs']) < 0.01,
    'Baseline: Bank 15.000.000, Cash Drawer 200.000, AR 888.000, AP 555.000, stok = SQL stock_by_location'
);

// 2. Dinamis: transaksi mengubah angka
AuthService::login('Sales');
$so = SalesController::createSalesOrder(['customer_id' => 'C001', 'variant_sku' => 'AIR-600', 'ordered_qty' => 10, 'unit_price' => 4000, 'discount_pct' => 0, 'source_location' => 'WH-PKU', 'confirm_now' => '1']);
$soNo = (string) ($so['document'] ?? '');
$mid = DashboardController::getMetrics();
check($mid['stock']['reserved'] === $m['stock']['reserved'] + 10 && $mid['stock']['on_hand'] === $m['stock']['on_hand'], 'Dinamis: SO Confirmed menaikkan Reserved +10, On Hand tetap');

AuthService::login('Warehouse');
InventoryController::validateDeliveryOrder(['so_number' => $soNo, 'deliver_qty' => 10]);
AuthService::login('Finance');
$inv = AccountingController::postCustomerInvoice(['so_number' => $soNo]);
$after = DashboardController::getMetrics();
$arExpected = 888000 + (float) ($inv['total_gross'] ?? 0);
check(abs($after['ar'] - $arExpected) < 0.01 && $after['cogs'] > 0, 'Dinamis: posting Customer Invoice menaikkan AR ke ' . number_format($arExpected, 0, ',', '.') . ' & COGS delivery tercatat');

$drillAr = DashboardController::getDrillDown('ar');
$invRows = array_filter($drillAr['rows'], static fn ($r) => $r[0] === ($inv['invoice_number'] ?? '?'));
check(
    count($invRows) === 1 && abs($drillAr['total'] - $after['ar']) < 0.01 && abs($after['reconciliation']['ar_difference']) < 0.5,
    'Drill-down AR: daftar invoice belum lunas memuat ' . ($inv['invoice_number'] ?? '?') . ' dan totalnya = Saldo AR buku besar'
);

AuthService::login('Finance');
AccountingController::registerPayment(['document_number' => (string) $inv['invoice_number'], 'amount' => (float) $inv['total_gross']]);
$paid = DashboardController::getDrillDown('ar');
check(
    count(array_filter($paid['rows'], static fn ($r) => $r[0] === $inv['invoice_number'])) === 0
    && abs(DashboardController::getMetrics()['ar'] - 888000) < 0.01
    && abs(DashboardController::getMetrics()['bank'] - (15000000 + (float) $inv['total_gross'])) < 0.01,
    'Drill-down AR: invoice lunas hilang dari rincian; AR kembali 888.000 dan Bank bertambah'
);

AuthService::login('Cashier');
$open = PosController::openPosSession(['location_code' => 'STORE-PKU', 'opening_cash' => 200000]);
PosController::posCheckout(['session_number' => (string) $open['session_number'], 'items' => [['variant_sku' => 'AIR-600', 'qty' => 3]]]);
$posM = DashboardController::getMetrics();
check(abs($posM['pos_sales'] - 12000) < 0.01 && abs($posM['cogs'] - ($after['cogs'] + 7500)) < 0.01, 'Dinamis: checkout POS menambah Pendapatan POS Rp12.000 dan COGS Rp7.500');
foreach (['stock', 'ap', 'bank', 'cash', 'pos_sales', 'cogs'] as $k) {
    $d = DashboardController::getDrillDown($k);
    check(!empty($d['columns']) && $d['total'] !== null, "Drill-down '{$k}' menghasilkan tabel rincian");
}

// 3. Visibilitas role
foreach (['Sales', 'Warehouse', 'Procurement', 'Cashier'] as $role) {
    AuthService::login($role);
    check(DashboardController::buildViewModel() === null, "Role {$role}: dasbor eksekutif TIDAK dibangun");
}
AuthService::login('Sales');
DashboardController::buildViewModel('ar', true);
$denied = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'dashboard.executive.view' AND outcome = 'DENIED'")->fetchColumn();
check($denied === 1, 'Role Sales yang memaksa ?drill=ar dicatat DENIED di audit_logs (SR-18)');

foreach (['Owner' => true, 'Finance' => true, 'Sales' => false, 'Cashier' => false, 'Warehouse' => false, 'Procurement' => false] as $role => $visible) {
    AuthService::login($role);
    $_GET  = ['view' => 'list', 'drill' => 'ar'];
    $_POST = [];
    ob_start();
    require dirname(__DIR__) . '/index.php';
    $html = (string) ob_get_clean();
    $has  = str_contains($html, 'data-testid="exec-dashboard"');
    $drillShown = str_contains($html, 'data-testid="exec-drill"');
    check($has === $visible && $drillShown === $visible && (!$visible || substr_count($html, 'data-drill=') === 7), "Render index.php role {$role}: dasbor eksekutif " . ($visible ? 'tampil (7 kartu + rincian AR)' : 'tidak dirender'));
}

Database::resetBaselineSeed();
echo "\nHASIL: {$pass} PASSED, {$fail} FAILED\n";
exit($fail > 0 ? 1 : 0);

