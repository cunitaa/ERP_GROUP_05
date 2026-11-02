<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix  = 'RantauMart\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use RantauMart\Config\Database;
use RantauMart\Controllers\DashboardController;
use RantauMart\Services\AuthService;

function check(bool $cond, string $label): void {
    if (!$cond) {
        echo "[FAIL] {$label}\n";
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

echo "=== VERIFIKASI OPENING CONTROL BASELINE (SEED DATA) ===\n\n";

$pdo = Database::getConnection();

// 1. Verifikasi Total Stok & Valuasi Keseluruhan
$stockTotals = DashboardController::queryStockTotals();
check((int) $stockTotals['on_hand'] === 1500, "1.1 Total On Hand persis 1.500 pcs (Diterima: {$stockTotals['on_hand']})");
check(abs((float) $stockTotals['valuation'] - 9628900.0) < 0.01, "1.2 Total Stock Valuation persis Rp9.628.900,00 (Diterima: Rp" . number_format($stockTotals['valuation'], 2, ',', '.') . ")");
check((int) $stockTotals['reserved'] === 8, "1.3 Total Reserved persis 8 pcs (Diterima: {$stockTotals['reserved']})");
check((int) $stockTotals['available'] === 1492, "1.4 Total Available persis 1.492 pcs (Diterima: {$stockTotals['available']})");

// 2. Verifikasi Distribusi Valuasi per Lokasi
$stmtLoc = $pdo->query("
    SELECT location_code,
           SUM(on_hand_qty) AS pcs,
           SUM(on_hand_qty * standard_cost) AS val
    FROM stock_by_location
    GROUP BY location_code
    ORDER BY location_code
");
$locs = [];
while ($row = $stmtLoc->fetch()) {
    $locs[$row['location_code']] = (float) $row['val'];
}

$whVal    = (float) ($locs['WH-PKU'] ?? 0);
$storePku = (float) ($locs['STORE-PKU'] ?? 0);
$storeDum = (float) ($locs['STORE-DUM'] ?? 0);

check(abs($whVal - 4997000.0) < 0.01, "2.1 Valuasi WH-PKU persis Rp4.997.000,00 (Diterima: Rp" . number_format($whVal, 2, ',', '.') . ")");
check(abs($storePku - 2979100.0) < 0.01, "2.2 Valuasi STORE-PKU persis Rp2.979.100,00 (Diterima: Rp" . number_format($storePku, 2, ',', '.') . ")");
check(abs($storeDum - 1652800.0) < 0.01, "2.3 Valuasi STORE-DUM persis Rp1.652.800,00 (Diterima: Rp" . number_format($storeDum, 2, ',', '.') . ")");
check(abs(($whVal + $storePku + $storeDum) - 9628900.0) < 0.01, "2.4 Penjumlahan 3 lokasi persis Rp9.628.900,00");

// 3. Verifikasi Baseline Reservasi SO S00007
$so = $pdo->query("SELECT * FROM sales_orders WHERE so_number = 'S00007'")->fetch();
check($so !== false, "3.1 Dokumen Sales Order S00007 terdaftar di sales_orders");
check(($so['status'] ?? '') === 'Confirmed', "3.2 Status SO S00007 adalah 'Confirmed'");
check(($so['source_location'] ?? '') === 'WH-PKU', "3.3 Lokasi sumber SO S00007 adalah 'WH-PKU'");
check((int) ($so['price_locked'] ?? 0) === 1, "3.4 Harga produk pada SO S00007 berstatus price_locked = TRUE");

$soLine = $pdo->query("SELECT * FROM sales_order_lines WHERE so_number = 'S00007'")->fetch();
check($soLine !== false, "3.5 Baris rincian SO S00007 terdaftar");
check(($soLine['variant_sku'] ?? '') === 'AIR-600', "3.6 Produk SO S00007 adalah 'AIR-600'");
check((int) ($soLine['ordered_qty'] ?? 0) === 8, "3.7 Qty pemesanan SO S00007 adalah 8 pcs");

// 4. Verifikasi Kalkulasi Kuantitas AIR-600
$stmtAir = $pdo->query("
    SELECT location_code, on_hand_qty, reserved_qty, (on_hand_qty - reserved_qty) AS available_qty
    FROM stock_by_location
    WHERE variant_sku = 'AIR-600'
    ORDER BY location_code
");
$airRows = [];
while ($row = $stmtAir->fetch()) {
    $airRows[$row['location_code']] = $row;
}

$airWh = $airRows['WH-PKU'] ?? [];
check((int) ($airWh['on_hand_qty'] ?? 0) === 80, "4.1 AIR-600 di WH-PKU: On Hand = 80 pcs");
check((int) ($airWh['reserved_qty'] ?? 0) === 8, "4.2 AIR-600 di WH-PKU: Reserved = 8 pcs (dari S00007)");
check((int) ($airWh['available_qty'] ?? 0) === 72, "4.3 AIR-600 di WH-PKU: Available = 72 pcs");

$airPku = $airRows['STORE-PKU'] ?? [];
check((int) ($airPku['on_hand_qty'] ?? 0) === 40, "4.4 AIR-600 di STORE-PKU: On Hand = 40 pcs");
check((int) ($airPku['reserved_qty'] ?? 0) === 0, "4.5 AIR-600 di STORE-PKU: Reserved = 0 pcs");
check((int) ($airPku['available_qty'] ?? 0) === 40, "4.6 AIR-600 di STORE-PKU: Available = 40 pcs");

$airDum = $airRows['STORE-DUM'] ?? [];
check((int) ($airDum['on_hand_qty'] ?? 0) === 20, "4.7 AIR-600 di STORE-DUM: On Hand = 20 pcs");
check((int) ($airDum['reserved_qty'] ?? 0) === 0, "4.8 AIR-600 di STORE-DUM: Reserved = 0 pcs");
check((int) ($airDum['available_qty'] ?? 0) === 20, "4.9 AIR-600 di STORE-DUM: Available = 20 pcs");

// 5. Verifikasi semua SKU selain AIR-600 di semua lokasi memiliki reserved_qty = 0
$otherReserved = (int) $pdo->query("
    SELECT COUNT(*)
    FROM stock_by_location
    WHERE NOT (location_code = 'WH-PKU' AND variant_sku = 'AIR-600')
      AND reserved_qty <> 0
")->fetchColumn();
check($otherReserved === 0, "5.1 Seluruh produk dan lokasi lainnya memiliki Reserved = 0 (Total non-zero: {$otherReserved})");

// 6. Verifikasi Saldo Jurnal Pembukaan
$je = $pdo->query("SELECT * FROM journal_entries WHERE entry_number = 'JE-OPEN-0001'")->fetch();
check($je !== false, "6.1 Jurnal Pembukaan JE-OPEN-0001 terdaftar");
check((float) $je['total_debit'] === 25716900.0 && (float) $je['total_credit'] === 25716900.0, "6.2 Jurnal Pembukaan seimbang persis Rp25.716.900,00");

echo "\n=======================================================\n";
echo "HASIL AKHIR: SELURUH 20 ASSERTION OPENING BASELINE LOLOS!\n";
echo "=======================================================\n";

