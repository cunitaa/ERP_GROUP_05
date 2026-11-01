<?php
declare(strict_types=1);

/**
 * Automated Test Suite for controllers/SalesController.php
 * Menguji Fitur F-05, F-06, F-07, F-08 & Skenario T-07, T-08, T-09, T-10 (SR-07, SR-08, SR-04, SR-09, SR-18).
 */

spl_autoload_register(function (string $class): void {
    $prefix  = 'RantauMart\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        require_once $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    }
});

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'SalesController.php';

use RantauMart\Config\Database;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Controllers\SalesController;
use RantauMart\Services\AuthService;

function assertCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

echo "=== Memulai Verifikasi SalesController (F-05, F-06, F-07, F-08 | T-07..T-10) ===\n";

// 0. Reset Baseline Seed & Login sebagai Sales
Database::resetBaselineSeed();
AuthService::login('Sales');
$pdo = Database::getConnection();

// 1. Uji T-07 & T-08 (F-05 CRM Pipeline: Convert LEAD-0001 ke Opportunity & Blokir Replay)
$conv1 = SalesController::convertLeadToOpportunity('LEAD-0001');
assertCheck($conv1['allowed'] === true && $conv1['new_status'] === 'Qualified', 'T-07 (F-05): LEAD-0001 berhasil dikonversi ke Opportunity (stage Qualified)');

$conv2 = SalesController::convertLeadToOpportunity('LEAD-0001');
assertCheck($conv2['allowed'] === false && $conv2['outcome'] === 'REPLAY_BLOCKED', 'T-08 (F-05 / SR-18): Convert kedua kali pada LEAD-0001 diblokir (REPLAY_BLOCKED)');

// 2. Uji T-09 (F-06, F-08, F-09: Buat SO baru C001 20 pcs AIR-600 @Rp4.000 dari WH-PKU; Confirm)
$soT09 = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 20,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'source_lead'     => 'LEAD-0001',
    'confirm_now'     => '1',
]);

assertCheck($soT09['allowed'] === true && $soT09['new_status'] === 'Confirmed', 'T-09 (F-06): SO S00008 berhasil dibuat dan dikonfirmasi');
assertCheck($soT09['price_locked'] === true, 'T-09 / SR-07: Harga produk otomatis terkunci (price_locked = true) saat Confirmed');

$soRow = $pdo->query("SELECT * FROM sales_orders WHERE so_number = 'S00008'")->fetch();
assertCheck(
    (float) $soRow['subtotal_net'] === 80000.0 &&
    (float) $soRow['tax_amount'] === 8800.0 &&
    (float) $soRow['total_gross'] === 88800.0,
    'T-09: Perhitungan Subtotal Rp80.000, PPN Rp8.800, Gross Rp88.800 akurat'
);

$stockAir = $pdo->query("SELECT on_hand_qty, reserved_qty, available_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetch();
assertCheck(
    (int) $stockAir['on_hand_qty'] === 80 &&
    (int) $stockAir['reserved_qty'] === 28 &&
    (int) $stockAir['available_qty'] === 52,
    'T-09 (F-09): Stok WH-PKU AIR-600 On Hand 80, Reserved 28, Available 52'
);

// 3. Uji T-10 Bagian A (Aturan SR-07: Blokir Diskon > 10%)
Database::resetBaselineSeed();
AuthService::login('Sales');

$resDisc15 = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 10,
    'unit_price'      => 4000,
    'discount_pct'    => 15,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '1',
]);
assertCheck(
    $resDisc15['allowed'] === false &&
    $resDisc15['outcome'] === 'DENIED' &&
    str_contains($resDisc15['reason'], 'SR-07'),
    'T-10 (SR-07): Transaksi SO dengan diskon 15% (>10%) diblokir secara mutlak'
);

// Uji juga tombol Confirm pada Draft SO jika ada diskon > 10%
$draftRes = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 5,
    'unit_price'      => 4000,
    'discount_pct'    => 5,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '0',
]);
$draftSoNo = (string) $draftRes['document'];
$confirmWith15 = SalesController::confirmSalesOrder($draftSoNo, 15.0);
assertCheck(
    $confirmWith15['allowed'] === false &&
    $confirmWith15['outcome'] === 'DENIED' &&
    str_contains($confirmWith15['reason'], 'SR-07'),
    'T-10 (SR-07): Tombol Confirm SO memblokir diskon > 10% dan menjaga status tetap Draft'
);

// 4. Uji T-10 Bagian B (Aturan SR-08: Blokir Konfirmasi jika Credit Exposure > Credit Limit)
Database::resetBaselineSeed();
AuthService::login('Sales');

// C001 memiliki Credit Limit Rp500.000 dan S00007 Confirmed sebesar Rp35.520.
// Coba konfirmasi SO 10 pcs BER-5KG @Rp76.000 (Gross Rp843.600) -> Exposure = Rp879.120 > Rp500.000
$resCreditExceed = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'BER-5KG',
    'ordered_qty'     => 10,
    'unit_price'      => 76000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '1',
]);
assertCheck(
    $resCreditExceed['allowed'] === false &&
    $resCreditExceed['outcome'] === 'DENIED' &&
    str_contains($resCreditExceed['reason'], 'SR-08'),
    'T-10 (SR-08): Konfirmasi SO C001 yang membuat Credit Exposure (Rp879.120) > Credit Limit (Rp500.000) diblokir'
);

// Uji juga pelanggan C002 yang punya Unpaid AR awal Rp888.000 (Credit Limit Rp2.000.000)
$resC002Exceed = SalesController::createSalesOrder([
    'customer_id'     => 'C002',
    'variant_sku'     => 'BER-5KG',
    'ordered_qty'     => 15,
    'unit_price'      => 76000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '1',
]);
assertCheck(
    $resC002Exceed['allowed'] === false &&
    $resC002Exceed['outcome'] === 'DENIED' &&
    str_contains($resC002Exceed['reason'], 'SR-08'),
    'SR-08: Konfirmasi SO C002 (Unpaid AR Rp888.000 + SO Ini Rp1.265.400 = Rp2.153.400 > Limit Rp2.000.000) diblokir'
);

// 5. Uji T-10 Bagian C (Aturan SR-07 Price Lock: Edit harga pada S00007 yang sudah Confirmed ditolak!)
$resEditLocked = SalesController::updateSalesOrderPrice('S00007', 3500, 0);
assertCheck(
    $resEditLocked['allowed'] === false &&
    $resEditLocked['outcome'] === 'DENIED' &&
    str_contains($resEditLocked['reason'], 'SR-07 Price Lock'),
    'T-10 (SR-07 Price Lock): Edit harga pada S00007 Confirmed diblokir secara mutlak (read-only)'
);

$s00007Line = $pdo->query("SELECT unit_price FROM sales_order_lines WHERE so_number = 'S00007'")->fetch();
assertCheck((float) $s00007Line['unit_price'] === 4000.0, 'DB Check: Harga S00007 di database tetap Rp4.000 (tidak berubah)');

// Kembalikan ke baseline seed
HomeAppsController::resetBaselineFixture();
echo "=== Seluruh Pengujian SalesController (F-05..F-08, SR-07, SR-08, T-07..T-10) LULUS 100% ===\n";

