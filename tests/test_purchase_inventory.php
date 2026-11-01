<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for PurchaseController & InventoryController
 * Covers Features F-09, F-10, F-12, F-13, F-21 and Rules SR-04, SR-09, SR-10, SR-17, SR-18, SR-20, SR-21, SR-22
 * Golden Tests: T-09 (SR-09), T-11, T-12, T-13, T-14, T-15, T-16, T-17, T-18
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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PurchaseController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'InventoryController.php';

use RantauMart\Config\Database;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PurchaseController;
use RantauMart\Controllers\SalesController;
use RantauMart\Services\AuthService;

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

echo "========================================================================\n";
echo " PENGUJIAN PURCHASE CONTROLLER & INVENTORY CONTROLLER (F-09..F-21)\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PEMESANAN VENDOR & AUTO PRICELIST (SR-20, F-12, T-15, T-16)
// -------------------------------------------------------------------------
echo "--- [1] Pemesanan Vendor (SR-20, F-12, T-15 & T-16) ---\n";
Database::resetBaselineSeed();
AuthService::login('Procurement');

$v01MiePrice = PurchaseController::getVendorUnitPrice('V01', 'MIE-GOR-80');
assertCheck(
    $v01MiePrice['found'] === true
    && $v01MiePrice['source'] === 'vendor_pricelist'
    && (float) $v01MiePrice['vendor_unit_price'] === 3000.0,
    'SR-20: Lookup V01 + MIE-GOR-80 otomatis mengambil Rp3.000 dari vendor_pricelist (bukan standard cost Rp2.800)'
);

$v03BerPrice = PurchaseController::getVendorUnitPrice('V03', 'BER-5KG');
assertCheck(
    (float) $v03BerPrice['vendor_unit_price'] === 60500.0 && $v03BerPrice['source'] === 'vendor_pricelist',
    'SR-20: Lookup V03 + BER-5KG otomatis mengambil Rp60.500 dari vendor_pricelist'
);

// T-15: Buat & Confirm PO00008 untuk V01, 100 pcs MIE-GOR-80 tanpa mengirim unit_price manual
$poCreateRes = PurchaseController::createPurchaseOrder([
    'vendor_id'            => 'V01',
    'variant_sku'          => 'MIE-GOR-80',
    'ordered_qty'          => 100,
    'destination_location' => 'WH-PKU',
    'confirm_now'          => '1',
]);

assertCheck(
    $poCreateRes['allowed'] === true
    && $poCreateRes['po_number'] === 'PO00008'
    && (float) $poCreateRes['unit_price'] === 3000.0
    && (float) $poCreateRes['subtotal_net'] === 300000.0
    && (float) $poCreateRes['tax_amount'] === 33000.0
    && (float) $poCreateRes['total_gross'] === 333000.0
    && $poCreateRes['status'] === 'Confirmed'
    && $poCreateRes['price_locked'] === true,
    'T-15 & SR-20: PO00008 (100 MIE-GOR-80 dari V01) otomatis @Rp3.000 -> Net Rp300.000, PPN Rp33.000, Gross Rp333.000, Confirmed & Price Locked'
);

$mieStockAfterPo = InventoryController::getLocationSkuStock('WH-PKU', 'MIE-GOR-80');
assertCheck(
    (int) $mieStockAfterPo['on_hand_qty'] === 120,
    'T-15: Konfirmasi PO tidak mengubah stok fisik On Hand WH-PKU MIE-GOR-80 (tetap 120 pcs)'
);

// T-16: Coba ubah harga beli PO00008 setelah Confirmed
$poEditPriceRes = PurchaseController::updatePurchaseOrderPrice('PO00008', 2500.0);
assertCheck(
    $poEditPriceRes['allowed'] === false
    && $poEditPriceRes['outcome'] === 'DENIED'
    && $poEditPriceRes['price_locked'] === true,
    'T-16 & SR-18: Percobaan mengubah unit_price pada PO00008 yang sudah Confirmed diblokir dan dicatat di audit_logs'
);

// -------------------------------------------------------------------------
// 2. PENERIMAAN BARANG VENDOR: ACCEPTED vs REJECTED (SR-10, F-13, T-17, T-18)
// -------------------------------------------------------------------------
echo "\n--- [2] Penerimaan Barang / Quality Acceptance (SR-10, F-13, T-17 & T-18) ---\n";
AuthService::login('Warehouse');

// T-17: Terima PO00008 (Ordered 100): Received 100, Accepted 85, Rejected 15
$receiptRes = InventoryController::receivePurchaseOrder([
    'po_number'    => 'PO00008',
    'received_qty' => 100,
    'accepted_qty' => 85,
    'rejected_qty' => 15,
]);

assertCheck(
    $receiptRes['allowed'] === true
    && (int) $receiptRes['accepted_qty'] === 85
    && (int) $receiptRes['rejected_qty'] === 15
    && (int) $receiptRes['on_hand_before'] === 120
    && (int) $receiptRes['on_hand_after'] === 205
    && (float) $receiptRes['valuation_added'] === 238000.0,
    'T-17 & SR-10: Hanya Accepted (85 pcs) yang menambah On Hand WH-PKU MIE-GOR-80 (120 -> 205 pcs) & Stock Valuation +Rp238.000 (Rejected 15 pcs tidak masuk stok)'
);

// T-18: Coba validasi Receipt kedua kali (Replay) pada PO00008
$replayReceiptRes = InventoryController::receivePurchaseOrder([
    'po_number'    => 'PO00008',
    'accepted_qty' => 85,
    'rejected_qty' => 15,
]);
$mieStockAfterReplay = InventoryController::getLocationSkuStock('WH-PKU', 'MIE-GOR-80');

assertCheck(
    $replayReceiptRes['allowed'] === false
    && $replayReceiptRes['outcome'] === 'REPLAY_BLOCKED'
    && (int) $mieStockAfterReplay['on_hand_qty'] === 205,
    'T-18 & SR-18: Validasi Receipt kedua pada PO00008 diblokir (REPLAY_BLOCKED) dan stok tetap 205 pcs'
);

// -------------------------------------------------------------------------
// 3. RESERVASI STOK (SR-09) & PARTIAL DELIVERY / BACKORDER (F-09, F-10, T-09, T-11, T-12)
// -------------------------------------------------------------------------
echo "\n--- [3] Reservasi Stok (SR-09) & Partial Delivery / Backorder (F-09, F-10, T-09, T-11, T-12) ---\n";
Database::resetBaselineSeed();
AuthService::login('Sales');

// Buat & Confirm S00008 untuk C001: 20 pcs AIR-600 @Rp4.000 dari WH-PKU (T-09)
$soCreateRes = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 20,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '1',
]);

$airAfterReserve = InventoryController::getLocationSkuStock('WH-PKU', 'AIR-600');
assertCheck(
    $soCreateRes['allowed'] === true
    && (int) $airAfterReserve['on_hand_qty'] === 80
    && (int) $airAfterReserve['reserved_qty'] === 28
    && (int) $airAfterReserve['available_qty'] === 52,
    'SR-09 (T-09): Saat S00008 (20 pcs AIR-600) dikonfirmasi, Reserved bertambah (8 -> 28) & Available berkurang (72 -> 52), TETAPI On Hand tetap 80 pcs!'
);

// T-11: Warehouse melakukan Partial Delivery 12 pcs dari 20 pcs pada S00008
AuthService::login('Warehouse');
$delivRes = InventoryController::validateDeliveryOrder([
    'so_number'   => 'S00008',
    'deliver_qty' => 12,
]);

assertCheck(
    $delivRes['allowed'] === true
    && (int) $delivRes['done_qty'] === 12
    && (int) $delivRes['backorder_qty'] === 8
    && (int) $delivRes['on_hand_before'] === 80
    && (int) $delivRes['on_hand_after'] === 68
    && (int) $delivRes['reserved_before'] === 28
    && (int) $delivRes['reserved_after'] === 16
    && (int) $delivRes['available_after'] === 52
    && (float) $delivRes['cogs_amount'] === 30000.0,
    'T-11 & F-10: Partial Delivery 12 dari 20 pcs pada S00008 -> On Hand 80->68, Reserved 28->16, Available tetap 52, Backorder 8 pcs, COGS Rp30.000'
);

// T-12: Reset baseline, coba kirim 73 pcs AIR-600 dari WH-PKU (saat Available = 72 pcs)
Database::resetBaselineSeed();
AuthService::login('Warehouse');

$overDelivRes = InventoryController::validateDeliveryOrder([
    'so_number'       => 'S00007',
    'deliver_qty'     => 73,
    'source_location' => 'WH-PKU',
    'variant_sku'     => 'AIR-600',
]);
$airAfterOverDeliv = InventoryController::getLocationSkuStock('WH-PKU', 'AIR-600');

assertCheck(
    $overDelivRes['allowed'] === false
    && $overDelivRes['outcome'] === 'DENIED'
    && (int) $airAfterOverDeliv['on_hand_qty'] === 80
    && (int) $airAfterOverDeliv['reserved_qty'] === 8
    && (int) $airAfterOverDeliv['available_qty'] === 72,
    'T-12 & SR-04: Pengiriman 73 pcs AIR-600 yang melebihi Available/Pesanan ditolak; stok WH-PKU tetap On Hand 80, Reserved 8, Available 72'
);

// -------------------------------------------------------------------------
// 4. INTERNAL TRANSFER ANTAR GUDANG (SR-17, F-21, T-13, T-14)
// -------------------------------------------------------------------------
echo "\n--- [4] Internal Transfer Antar Lokasi (SR-17, F-21, T-13 & T-14) ---\n";
Database::resetBaselineSeed();
AuthService::login('Warehouse');

// T-13: Transfer 15 pcs AIR-600 dari WH-PKU ke STORE-PKU
$transferRes = InventoryController::createInternalTransfer([
    'from_location' => 'WH-PKU',
    'to_location'   => 'STORE-PKU',
    'variant_sku'   => 'AIR-600',
    'qty_move'      => 15,
]);

assertCheck(
    $transferRes['allowed'] === true
    && (int) $transferRes['from_on_hand_before'] === 80
    && (int) $transferRes['from_on_hand_after'] === 65
    && (int) $transferRes['to_on_hand_before'] === 40
    && (int) $transferRes['to_on_hand_after'] === 55
    && (int) $transferRes['company_pcs_before'] === 1500
    && (int) $transferRes['company_pcs_after'] === 1500
    && (float) $transferRes['company_valuation_before'] === 9628900.0
    && (float) $transferRes['company_valuation_after'] === 9628900.0
    && $transferRes['total_valuation_unchanged'] === true
    && $transferRes['journal_entry_number'] === null,
    'T-13 & SR-17: Transfer 15 AIR-600 (WH-PKU 80->65, STORE-PKU 40->55) menjaga total stok 1.500 pcs & total valuasi perusahaan tetap Rp9.628.900 tanpa jurnal penjualan/COGS'
);

// T-14: Reset baseline, coba transfer 81 pcs AIR-600 dari WH-PKU ke STORE-PKU (saat Available = 72)
Database::resetBaselineSeed();
AuthService::login('Warehouse');

$overTransferRes = InventoryController::createInternalTransfer([
    'from_location' => 'WH-PKU',
    'to_location'   => 'STORE-PKU',
    'variant_sku'   => 'AIR-600',
    'qty_move'      => 81,
]);
$whAirAfterOverTransfer = InventoryController::getLocationSkuStock('WH-PKU', 'AIR-600');

assertCheck(
    $overTransferRes['allowed'] === false
    && $overTransferRes['outcome'] === 'DENIED'
    && (int) $whAirAfterOverTransfer['on_hand_qty'] === 80
    && (int) $whAirAfterOverTransfer['available_qty'] === 72,
    'T-14 & SR-17/SR-04: Transfer 81 pcs AIR-600 dari WH-PKU melebihi Available (72 pcs) ditolak dan stok tetap utuh'
);

// -------------------------------------------------------------------------
// 5. INTEGRASI UI INDEX.PHP (PROCUREMENT & WAREHOUSE WORKSPACES)
// -------------------------------------------------------------------------
echo "\n--- [5] Integrasi UI index.php (Procurement & Warehouse) ---\n";
foreach (['Procurement', 'Warehouse', 'Sales', 'Finance', 'Cashier'] as $testRole) {
    AuthService::login($testRole);
    $_GET  = ['view' => 'list'];
    $_POST = [];
    ob_start();
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
    $html = (string) ob_get_clean();
    assertCheck(
        strlen($html) > 5000 && str_contains($html, 'Role: ' . $testRole),
        "UI index.php berhasil merender halaman untuk role '{$testRole}' tanpa warning (" . strlen($html) . " bytes)"
    );
}

// Kembalikan database ke kondisi baseline bersih
Database::resetBaselineSeed();
AuthService::login('Sales');

echo "\n========================================================================\n";
echo " HASIL AKHIR: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
