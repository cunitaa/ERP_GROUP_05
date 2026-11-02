<?php
declare(strict_types=1);

/**
 * Test Suite Khusus 4 Alur Bisnis Utama (Order-to-Cash, Procure-to-Pay, POS & Returns)
 * Memverifikasi eksekusi nyata end-to-end tanpa error di database.
 */

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

require_once dirname(__DIR__) . '/IntegrationEventTrigger.php';
require_once dirname(__DIR__) . '/Middleware.php';
require_once dirname(__DIR__) . '/reset_fixture.php';
require_once dirname(__DIR__) . '/controllers/BusinessFlowController.php';

use RantauMart\Config\Database;
use RantauMart\Controllers\BusinessFlowController;
use RantauMart\Services\AuthService;

$pass = 0;
$fail = 0;

function check(bool $condition, string $label): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}\n";
    }
}

echo "=== PENGUJIAN 4 BUSINESS FLOWS UTAMA PT RANTAUMART RIAU ===\n\n";

Database::resetBaselineSeed();
$pdo = Database::getConnection();

// =============================================================================
// FLOW 1: ORDER TO CASH (O2C)
// Lead -> Opportunity -> Quotation -> SO -> DO -> Invoice -> Payment
// =============================================================================
echo "--- FLOW 1: ORDER TO CASH ---\n";
AuthService::login('Sales');

// 1.1 Convert Lead
$f1Lead = BusinessFlowController::convertLead('LEAD-0001');
check(($f1Lead['allowed'] ?? false) === true && ($f1Lead['new_status'] ?? '') === 'Qualified', 'Flow 1.1: Lead LEAD-0001 berhasil dikonversi ke Opportunity (Qualified)');

// 1.2 Create Quotation
$f1Quote = BusinessFlowController::createQuotation([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 20,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'source_lead'     => 'LEAD-0001',
]);
$soNo = (string) ($f1Quote['document'] ?? $f1Quote['so_number'] ?? 'S00008');
check(($f1Quote['allowed'] ?? false) === true && ($f1Quote['new_status'] ?? '') === 'Draft', "Flow 1.2: Quotation {$soNo} tersimpan dengan status Draft");

// 1.3 Confirm Sales Order & Trigger Reserve Stock
$f1Confirm = BusinessFlowController::confirmSalesOrder($soNo);
$stockWh = $pdo->query("SELECT on_hand_qty, reserved_qty, available_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetch(PDO::FETCH_ASSOC);
check(
    ($f1Confirm['allowed'] ?? false) === true
    && ($f1Confirm['new_status'] ?? '') === 'Confirmed'
    && (int) ($stockWh['on_hand_qty'] ?? 0) === 80
    && (int) ($stockWh['reserved_qty'] ?? 0) === 28
    && (int) ($stockWh['available_qty'] ?? 0) === 52,
    "Flow 1.3: Konfirmasi SO {$soNo} otomatis mencadangkan stok (Reserved 8->28, On Hand tetap 80)"
);

// 1.4 Deliver Order (DO) -> Kurangi Stok Fisik & Catat COGS
AuthService::login('Warehouse');
$f1Do = BusinessFlowController::deliverOrder($soNo, 12);
$stockAfterDo = $pdo->query("SELECT on_hand_qty, reserved_qty, available_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetch(PDO::FETCH_ASSOC);
check(
    ($f1Do['allowed'] ?? false) === true
    && (int) ($stockAfterDo['on_hand_qty'] ?? 0) === 68
    && (int) ($stockAfterDo['reserved_qty'] ?? 0) === 16
    && abs((float) ($f1Do['cogs_amount'] ?? 0) - 30000.0) < 0.01,
    'Flow 1.4: Delivery Order 12 pcs memotong fisik On Hand (80->68) & memposting COGS Rp30.000'
);

// 1.5 Customer Invoice (Hanya atas Delivered Qty -> Menambah AR & PPN)
AuthService::login('Finance');
$f1Inv = BusinessFlowController::createCustomerInvoice($soNo, 12);
$invNo = (string) ($f1Inv['invoice_number'] ?? 'INV-0001');
check(
    ($f1Inv['allowed'] ?? false) === true
    && abs((float) ($f1Inv['subtotal_net'] ?? 0) - 48000.0) < 0.01
    && abs((float) ($f1Inv['tax_amount'] ?? 0) - 5280.0) < 0.01
    && abs((float) ($f1Inv['total_gross'] ?? 0) - 53280.0) < 0.01,
    "Flow 1.5: Customer Invoice {$invNo} atas 12 pcs terkirim berhasil diterbitkan (DPP Rp48.000 + PPN Rp5.280 = Rp53.280)"
);

// 1.6 Payment Customer Invoice -> Potong AR & Tambah Bank
$f1Pay = BusinessFlowController::payCustomerInvoice($invNo, 53280.0);
check(
    ($f1Pay['allowed'] ?? false) === true
    && ($f1Pay['status'] ?? '') === 'Paid'
    && abs((float) ($f1Pay['outstanding_amount'] ?? 0) - 0.0) < 0.01,
    "Flow 1.6: Pembayaran penuh Customer Invoice {$invNo} berhasil (Status: Paid, Sisa AR: Rp0)"
);

// =============================================================================
// FLOW 2: PROCURE TO PAY (P2P)
// RFQ -> Purchase Order -> Receipt -> Vendor Bill -> Payment
// =============================================================================
echo "\n--- FLOW 2: PROCURE TO PAY ---\n";
AuthService::login('Procurement');

// 2.1 Create RFQ
$f2Rfq = BusinessFlowController::createRfq([
    'vendor_id'            => 'V01',
    'variant_sku'          => 'MIE-GOR-80',
    'ordered_qty'          => 100,
    'destination_location' => 'WH-PKU',
]);
$poNo = (string) ($f2Rfq['po_number'] ?? 'PO00008');
check(($f2Rfq['allowed'] ?? false) === true && ($f2Rfq['status'] ?? '') === 'RFQ', "Flow 2.1: RFQ {$poNo} tersimpan dengan status RFQ");

// 2.2 Confirm Purchase Order (Lock Harga Beli)
$f2ConfirmPo = BusinessFlowController::confirmPurchaseOrder($poNo);
check(
    ($f2ConfirmPo['allowed'] ?? false) === true
    && ($f2ConfirmPo['status'] ?? '') === 'Confirmed'
    && abs((float) ($f2ConfirmPo['unit_price'] ?? 0) - 3000.0) < 0.01,
    "Flow 2.2: Konfirmasi PO {$poNo} mengunci harga beli vendor_pricelist (@Rp3.000)"
);

// 2.3 Receive Goods (HANYA Accepted Menambah On Hand)
AuthService::login('Warehouse');
$f2Receipt = BusinessFlowController::receiveGoods($poNo, 85, 15);
$mieStock = $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'MIE-GOR-80'")->fetch(PDO::FETCH_ASSOC);
check(
    ($f2Receipt['allowed'] ?? false) === true
    && (int) ($f2Receipt['accepted_qty'] ?? 0) === 85
    && (int) ($mieStock['on_hand_qty'] ?? 0) === 205, // 120 baseline + 85 accepted
    "Flow 2.3: Penerimaan PO {$poNo} HANYA menambahkan Qty Accepted (85 pcs) ke On Hand (120->205 pcs, 15 rejected diisolasi)"
);

// 2.4 Vendor Bill Three-Way Match Sesuai Qty Accepted
AuthService::login('Finance');
$f2Bill = BusinessFlowController::createVendorBill($poNo, 85);
$billNo = (string) ($f2Bill['bill_number'] ?? 'BILL-0001');
check(
    ($f2Bill['allowed'] ?? false) === true
    && abs((float) ($f2Bill['interim_cleared'] ?? 0) - 238000.0) < 0.01
    && abs((float) ($f2Bill['ppv_amount'] ?? 0) - 17000.0) < 0.01
    && abs((float) ($f2Bill['tax_amount'] ?? 0) - 28050.0) < 0.01
    && abs((float) ($f2Bill['total_gross'] ?? 0) - 283050.0) < 0.01,
    "Flow 2.4: Vendor Bill {$billNo} diposting atas 85 pcs Accepted (Interim Rp238.000 bersih, PPV Rp17.000, Input VAT Rp28.050, AP Rp283.050)"
);

// 2.5 Pay Vendor Bill -> Memotong Saldo Bank & AP
$f2Pay = BusinessFlowController::payVendorBill($billNo, 283050.0);
check(
    ($f2Pay['allowed'] ?? false) === true
    && ($f2Pay['status'] ?? '') === 'Paid'
    && !empty($f2Pay['journal_entry_number']),
    "Flow 2.5: Pelunasan Vendor Bill {$billNo} berhasil memotong saldo Bank & AP secara seimbang"
);

// =============================================================================
// FLOW 3: POINT OF SALE & CLOSING (POS)
// Open Session -> Real-time Stock Move Checkout -> Validated Ledger Closing
// =============================================================================
echo "\n--- FLOW 3: POINT OF SALE & CLOSING ---\n";
AuthService::login('Cashier');

// 3.1 Open POS Session
$f3Open = BusinessFlowController::openPosSession('STORE-PKU', 200000.0);
$sessNo = (string) ($f3Open['session_number'] ?? 'POS-SESSION-0001');
check(($f3Open['allowed'] ?? false) === true && ($f3Open['status'] ?? '') === 'Open', "Flow 3.1: Sesi kasir {$sessNo} dibuka dengan Opening Cash Rp200.000");

// 3.2 Real-time Stock Move Checkout
$storeAirBefore = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'STORE-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
$f3Pay = BusinessFlowController::posCashCheckout($sessNo, [
    ['variant_sku' => 'AIR-600', 'qty' => 3],
], 15000.0);
$storeAirAfter = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'STORE-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
$rcptNo = (string) ($f3Pay['receipt_number'] ?? 'POS-RCPT-0001');
check(
    ($f3Pay['allowed'] ?? false) === true
    && ($storeAirBefore - $storeAirAfter) === 3
    && ($f3Pay['status'] ?? '') === 'Paid',
    "Flow 3.2: POS Checkout tunai langsung mengeksekusi Stock Move real-time di toko (stok AIR-600 berkurang 3 pcs)"
);

// 3.3 Close Session & Posting Ledger
AuthService::login('Finance');
$f3Close = BusinessFlowController::closePosSession($sessNo, (float) ($f3Pay['expected_closing_cash'] ?? 213320.0), true);
check(
    ($f3Close['allowed'] ?? false) === true
    && ($f3Close['status'] ?? '') === 'Closed'
    && !empty($f3Close['journal_entry_number']),
    "Flow 3.3: Penutupan sesi kasir {$sessNo} tervalidasi memposting pendapatan kasir & COGS ke buku besar (Ledger)"
);

// =============================================================================
// FLOW 4: RETURNS & RESTOCK
// Return DO/Invoice -> Inspection -> Restock -> Credit Note -> Reverse Journal
// =============================================================================
echo "\n--- FLOW 4: RETURNS & RESTOCK ---\n";

// 4.1 B2B Return: Layak Jual -> Pulihkan Stok Fisik Gudang & Balikkan Jurnal
$whAirBeforeRet = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
$f4RetDo = BusinessFlowController::returnDeliveryOrder($invNo, 2, true);
$whAirAfterRet = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
check(
    ($f4RetDo['allowed'] ?? false) === true
    && ($whAirAfterRet - $whAirBeforeRet) === 2
    && abs((float) ($f4RetDo['refund_gross'] ?? 0) - 8880.0) < 0.01
    && abs((float) ($f4RetDo['reversed_cogs'] ?? 0) - 5000.0) < 0.01,
    'Flow 4.1: Retur DO 2 pcs layak jual berhasil memulihkan stok gudang (+2 pcs), membalik COGS Rp5.000, & menerbitkan Credit Note Rp8.880'
);

// 4.2 POS Return: Layak Jual -> Pulihkan Stok Toko & Balikkan Jurnal Kasir
$storeAirBeforePosRet = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'STORE-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
$f4RetPos = BusinessFlowController::returnPosReceipt($rcptNo, 'AIR-600', 1, true);
$storeAirAfterPosRet = (int) $pdo->query("SELECT on_hand_qty FROM stock_by_location WHERE location_code = 'STORE-PKU' AND variant_sku = 'AIR-600'")->fetchColumn();
check(
    ($f4RetPos['allowed'] ?? false) === true
    && ($storeAirAfterPosRet - $storeAirBeforePosRet) === 1
    && abs((float) ($f4RetPos['refund_gross'] ?? 0) - 4440.0) < 0.01,
    'Flow 4.2: Retur POS Receipt 1 pcs layak jual berhasil memulihkan stok toko (+1 pcs) & menyesuaikan kas serta jurnal akuntansi'
);

// Keseimbangan Jurnal Pasca Seluruh Flow
$jeBal = $pdo->query("SELECT SUM(debit) AS td, SUM(credit) AS tc FROM journal_entry_lines")->fetch(PDO::FETCH_ASSOC);
$totalD = (float) ($jeBal['td'] ?? 0);
$totalC = (float) ($jeBal['tc'] ?? 0);
check(
    abs($totalD - $totalC) < 0.01 && $totalD > 0,
    sprintf('Keseimbangan Akhir Buku Besar: Debit Rp%s = Kredit Rp%s (SEIMBANG)', number_format($totalD, 2, ',', '.'), number_format($totalC, 2, ',', '.'))
);

Database::resetBaselineSeed();

echo "\n=======================================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "=======================================================\n";

exit($fail > 0 ? 1 : 0);
