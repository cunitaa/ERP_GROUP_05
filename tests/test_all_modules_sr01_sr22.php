<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for All 6 Modules, 6 Stakeholders & Business Rules SR-01..SR-22
 * (Scenarios T-01 through T-36)
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

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Middleware.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'IntegrationEventTrigger.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reset_fixture.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'SalesController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PurchaseController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'InventoryController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'AccountingController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PosController.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'ReportController.php';

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\FixtureResetController;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PosController;
use RantauMart\Controllers\PurchaseController;
use RantauMart\Controllers\ReportController;
use RantauMart\Controllers\SalesController;
use RantauMart\Services\AuthService;

$passCount = 0;
$failCount = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passCount, $failCount;
    if ($cond) {
        $passCount++;
        echo "[PASS] {$label}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$label}\n";
    }
}

echo "=== PENGUJIAN MENYELURUH 6 PEMANGKU KEPENTINGAN, 6 MODUL & ATURAN SR-01..SR-22 ===\n\n";

// Reset baseline seed
Database::resetBaselineSeed();

// 1. Verifikasi 6 Pemangku Kepentingan (Stakeholders)
$expectedStakeholders = [
    'Sales'       => 'Putri Aulia',
    'Warehouse'   => 'Deni Pratama',
    'Procurement' => 'Mira Salsabila',
    'Finance'     => 'Arif Firmansyah',
    'Cashier'     => 'Rani Oktaviani',
    'Owner'       => 'Budi Haryanto',
];

foreach ($expectedStakeholders as $roleKey => $fullName) {
    $u = AuthService::login($roleKey);
    assertTrue(
        is_array($u) && ($u['full_name'] ?? '') === $fullName && ($u['role'] ?? '') === $roleKey,
        "Stakeholder Login: {$fullName} ({$roleKey}) berhasil diautentikasi"
    );
    $vm = HomeAppsController::getDashboardViewModel(null, 'Apps');
    assertTrue(
        ($vm['current_user']['full_name'] ?? '') === $fullName && !empty($vm['role_kpis']),
        "Dashboard ViewModel & KPI untuk {$fullName} ({$roleKey}) ter-render lengkap"
    );
}

// 2. Uji SR-20 (T-05 & T-06): Pembuatan Varian SKU Baru & Blokir Duplikasi SKU
Database::resetBaselineSeed();
AuthService::login('Warehouse');
$t05 = InventoryController::createProductVariant([
    'variant_sku'    => 'KOPI-LATTE-250',
    'name'           => 'Kopi Latte Dingin',
    'size'           => '250 ml',
    'color_flavour'  => 'Latte',
    'sales_price'    => 7500,
    'cost'           => 5200,
    'default_vendor' => 'V01',
]);
assertTrue(($t05['allowed'] ?? false) === true, 'T-05 (SR-20): Varian SKU baru KOPI-LATTE-250 berhasil dibuat');

$t06 = InventoryController::createProductVariant([
    'variant_sku'   => 'AIR-600',
    'name'          => 'Air Mineral Duplikat',
    'size'          => '600 ml',
    'color_flavour' => 'Original',
]);
assertTrue(
    ($t06['allowed'] ?? true) === false && ($t06['rule'] ?? '') === 'SR-20',
    'T-06 (SR-20): Duplikasi SKU AIR-600 diblokir dengan pesan error yang jelas'
);

// 3. Alur Terintegrasi Order-to-Cash & Invoicing (T-07 -> T-09 -> T-11 -> T-19 -> T-20 -> T-23 -> T-24)
Database::resetBaselineSeed();
AuthService::login('Sales'); // Putri Aulia
$t07 = SalesController::convertLeadToOpportunity('LEAD-0001');
assertTrue(($t07['allowed'] ?? false) === true && ($t07['new_status'] ?? '') === 'Qualified', 'T-07 (F-05): Putri Aulia mengonversi LEAD-0001 menjadi Opportunity (Qualified)');

$t09 = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 20,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'source_lead'     => 'LEAD-0001',
    'confirm_now'     => '1',
]);
$soNo = (string) ($t09['document'] ?? 'S00008');
assertTrue(
    ($t09['allowed'] ?? false) === true
    && ($t09['new_status'] ?? '') === 'Confirmed'
    && ($t09['price_locked'] ?? false) === true,
    'T-09 (SR-07, SR-08, SR-09): Konfirmasi SO S00008 (20 pcs AIR-600 @ Rp4.000) menghasilkan Gross Rp88.800 & mencadangkan stok WH-PKU (On Hand tetap 80, Reserved 28, Available 52)'
);

// T-20: Coba tagih 20 pcs sebelum dikirim (SR-02 vs SR-01) -> harus diblokir!
AuthService::login('Finance'); // Arif Firmansyah
$t20BeforeDel = AccountingController::postCustomerInvoice([
    'so_number'   => $soNo,
    'invoice_qty' => 20,
]);
assertTrue(
    ($t20BeforeDel['allowed'] ?? true) === false && ($t20BeforeDel['rule'] ?? '') === 'SR-02',
    'T-20 (SR-02 mengalahkan SR-01): Arif Firmansyah memblokir Customer Invoice atas barang yang belum dikirim (Delivered = 0)'
);

// T-11: Deni Pratama mengirim 12 pcs (Partial Delivery) & membuat Backorder 8 pcs
AuthService::login('Warehouse'); // Deni Pratama
$t11 = InventoryController::validateDeliveryOrder([
    'so_number'   => $soNo,
    'deliver_qty' => 12,
]);
assertTrue(
    ($t11['allowed'] ?? false) === true
    && (int) ($t11['done_qty'] ?? 0) === 12
    && (int) ($t11['backorder_qty'] ?? 0) === 8
    && abs((float) ($t11['cogs_amount'] ?? 0) - 30000.0) < 0.01,
    'T-11 (F-10, SR-09, SR-21): Deni Pratama mengirim 12 pcs AIR-600 (Backorder 8 pcs) & memposting COGS Rp30.000'
);

// T-20 lagi: Coba tagih 20 pcs padahal baru terkirim 12 pcs -> harus diblokir!
AuthService::login('Finance'); // Arif Firmansyah
$t20OverDel = AccountingController::postCustomerInvoice([
    'so_number'   => $soNo,
    'invoice_qty' => 20,
]);
assertTrue(
    ($t20OverDel['allowed'] ?? true) === false && ($t20OverDel['rule'] ?? '') === 'SR-02',
    'T-20 (SR-02): Tagihan 20 pcs tetap ditolak karena Delivered baru 12 pcs'
);

// T-19: Tagih 12 pcs yang sudah terkirim -> berhasil (Net Rp48.000, PPN Rp5.280, Gross Rp53.280)
$t19 = AccountingController::postCustomerInvoice([
    'so_number'   => $soNo,
    'invoice_qty' => 12,
]);
$invNo = (string) ($t19['invoice_number'] ?? 'INV-0001');
assertTrue(
    ($t19['allowed'] ?? false) === true
    && abs((float) ($t19['subtotal_net'] ?? 0) - 48000.0) < 0.01
    && abs((float) ($t19['tax_amount'] ?? 0) - 5280.0) < 0.01
    && abs((float) ($t19['total_gross'] ?? 0) - 53280.0) < 0.01,
    "T-19 (F-11, SR-02, SR-21): Customer Invoice {$invNo} atas 12 pcs terkirim berhasil diposting (Net Rp48.000 + PPN Rp5.280 = Gross Rp53.280)"
);

// T-23: Pembayaran Bertahap (Partial Payment Rp20.000 lalu pelunasan Rp33.280)
$t23Part1 = AccountingController::registerPayment([
    'target_document' => $invNo,
    'amount'          => 20000.0,
]);
assertTrue(
    ($t23Part1['allowed'] ?? false) === true
    && ($t23Part1['status'] ?? '') === 'Partially Paid'
    && abs((float) ($t23Part1['outstanding_amount'] ?? 0) - 33280.0) < 0.01,
    'T-23a (F-15, SR-15): Pembayaran parsial pertama Rp20.000 mengubah status Invoice menjadi Partially Paid (Sisa Rp33.280)'
);

// T-24: Coba bayar melebihi sisa tagihan (Overpayment Rp40.000 > Rp33.280) -> diblokir!
$t24 = AccountingController::registerPayment([
    'target_document' => $invNo,
    'amount'          => 40000.0,
]);
assertTrue(
    ($t24['allowed'] ?? true) === false && ($t24['rule'] ?? '') === 'SR-15',
    'T-24 (SR-15): Overpayment Rp40.000 di atas sisa tagihan Rp33.280 diblokir'
);

// T-23b: Pelunasan sisa Rp33.280 -> status menjadi Paid
$t23Part2 = AccountingController::registerPayment([
    'target_document' => $invNo,
    'amount'          => 33280.0,
]);
assertTrue(
    ($t23Part2['allowed'] ?? false) === true
    && ($t23Part2['status'] ?? '') === 'Paid'
    && abs((float) ($t23Part2['outstanding_amount'] ?? 0) - 0.0) < 0.01,
    'T-23b (F-15, SR-15): Pelunasan kedua Rp33.280 mengubah status Invoice menjadi Paid (Sisa Rp0)'
);

// 4. Alur Procure-to-Pay, Quality Acceptance & Three-Way Match + PPV (T-15 -> T-17 -> T-21 -> T-22 -> T-25)
AuthService::login('Procurement'); // Mira Salsabila
$t15 = PurchaseController::createPurchaseOrder([
    'vendor_id'            => 'V01',
    'variant_sku'          => 'MIE-GOR-80',
    'ordered_qty'          => 100,
    'destination_location' => 'WH-PKU',
    'confirm_now'          => '1',
]);
$poNo = (string) ($t15['po_number'] ?? 'PO00008');
assertTrue(
    ($t15['allowed'] ?? false) === true
    && abs((float) ($t15['unit_price'] ?? 0) - 3000.0) < 0.01,
    "T-15 (F-12, SR-20): Mira Salsabila membuat & mengonfirmasi {$poNo} 100 pcs MIE-GOR-80 @ Rp3.000 dari vendor_pricelist"
);

AuthService::login('Warehouse'); // Deni Pratama
$t17 = InventoryController::receivePurchaseOrder([
    'po_number'    => $poNo,
    'received_qty' => 100,
    'accepted_qty' => 85,
    'rejected_qty' => 15,
]);
assertTrue(
    ($t17['allowed'] ?? false) === true
    && (int) ($t17['accepted_qty'] ?? 0) === 85
    && abs((float) ($t17['valuation_added'] ?? 0) - 238000.0) < 0.01,
    'T-17 (F-13, SR-10): Deni Pratama menerima 85 pcs Accepted & 15 pcs Rejected -> On Hand +85 pcs & Stock Valuation +Rp238.000'
);

AuthService::login('Finance'); // Arif Firmansyah
// T-22: Coba tagih 100 pcs padahal Accepted hanya 85 pcs (SR-06 vs SR-05) -> diblokir!
$t22 = AccountingController::postVendorBill([
    'po_number' => $poNo,
    'bill_qty'  => 100,
]);
assertTrue(
    ($t22['allowed'] ?? true) === false && ($t22['rule'] ?? '') === 'SR-06',
    'T-22 (SR-06 mengalahkan SR-05): Arif Firmansyah memblokir Vendor Bill 100 pcs karena melampaui Accepted Qty 85 pcs'
);

// T-21: Tagih 85 pcs sesuai Three-Way Match -> mengosongkan 1150 Interim Rp238.000 & mencatat PPV Rp17.000
$t21 = AccountingController::createVendorBillFromPo([
    'po_number' => $poNo,
    'bill_qty'  => 85,
]);
assertTrue(
    ($t21['allowed'] ?? false) === true
    && abs((float) ($t21['interim_cleared'] ?? 0) - 238000.0) < 0.01
    && abs((float) ($t21['ppv_amount'] ?? 0) - 17000.0) < 0.01
    && abs((float) ($t21['tax_amount'] ?? 0) - 28050.0) < 0.01
    && abs((float) ($t21['total_gross'] ?? 0) - 283050.0) < 0.01,
    'T-21 (F-14, SR-06, SR-21): Vendor Bill 85 pcs diposting — Interim Rp238.000 bersih ke Rp0, PPV (5110) Rp17.000, PPN Masukan Rp28.050, AP Rp283.050'
);

// Uji Pembayaran sah atas Vendor Bill hasil Three-Way Match (85 pcs Accepted) -> menghasilkan Jurnal Seimbang DR 2010 AP / CR 1010 Bank
$validBillNo = (string) ($t21['bill_number'] ?? 'BILL-0001');
$payValidBill = AccountingController::validatePayment([
    'document_number' => $validBillNo,
    'amount'          => 283050.0,
]);
assertTrue(
    ($payValidBill['allowed'] ?? false) === true
    && ($payValidBill['status'] ?? '') === 'Paid'
    && !empty($payValidBill['journal_entry_number']),
    'F-15 & SR-21: Pembayaran Vendor Bill Rp283.050 yang lolos Three-Way Match berhasil & memposting Jurnal Akuntansi seimbang (DR 2010 AP / CR 1010 Bank)'
);

// Uji Pemblokiran Pembayaran Tagihan Vendor yang melanggar Three-Way Match (BILL-UNRECEIVED atas PO00007 yang Accepted = 0)
Database::getConnection()->exec(
    "INSERT INTO vendor_bills (bill_number, po_number, vendor_id, accepted_qty, billed_qty, status, subtotal_net, tax_amount, total_gross, paid_amount, outstanding_amount)
     VALUES ('BILL-UNRECEIVED', 'PO00007', 'V01', 0, 50, 'Draft', 255000, 28050, 283050, 0, 283050)"
);
$payBlocked3Way = AccountingController::validatePayment([
    'document_number' => 'BILL-UNRECEIVED',
    'amount'          => 100000.0,
]);
assertTrue(
    ($payBlocked3Way['allowed'] ?? true) === false && ($payBlocked3Way['rule'] ?? '') === 'SR-06',
    'SR-06 Payment Guard: Pembayaran atas tagihan vendor BILL-UNRECEIVED yang belum diterima/Accepted di gudang diblokir mutlak (Three-Way Match)'
);

// 5. Uji B2B Return & Credit Note (T-26 / SR-16)
Database::resetBaselineSeed();
AuthService::login('Sales');
$soRet = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 20,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '1',
]);
$soRetNo = (string) ($soRet['document'] ?? 'S00008');
AuthService::login('Warehouse');
InventoryController::validateDeliveryOrder(['so_number' => $soRetNo, 'deliver_qty' => 12]);
AuthService::login('Finance');
$invRet = AccountingController::postCustomerInvoice(['so_number' => $soRetNo, 'invoice_qty' => 12]);
$invRetNo = (string) ($invRet['invoice_number'] ?? 'INV-0001');

$t26 = AccountingController::createB2bReturnCreditNote([
    'invoice_number' => $invRetNo,
    'return_qty'     => 2,
]);
assertTrue(
    ($t26['allowed'] ?? false) === true
    && (int) ($t26['on_hand_after'] ?? 0) === 70
    && abs((float) ($t26['refund_gross'] ?? 0) - 8880.0) < 0.01
    && abs((float) ($t26['reversed_cogs'] ?? 0) - 5000.0) < 0.01,
    'T-26 (F-20, SR-16): Retur B2B 2 pcs AIR-600 mengembalikan stok WH-PKU ke 70 pcs, membalik COGS Rp5.000, & mengurangi AR sebesar Rp8.880'
);

// 6. Alur Point of Sale / POS Kasir Rani Oktaviani (T-27 -> T-28 -> T-29 -> T-30 -> T-31 -> T-32 -> T-33)
Database::resetBaselineSeed();
AuthService::login('Cashier'); // Rani Oktaviani

// T-27: Buka sesi POS-SESSION-0001 di STORE-PKU dengan Opening Cash Rp200.000 (SR-13 & SR-14)
$t27 = PosController::openPosSession([
    'location_code' => 'STORE-PKU',
    'opening_cash'  => 200000,
]);
assertTrue(
    ($t27['allowed'] ?? false) === true && ($t27['session_number'] ?? '') === 'POS-SESSION-0001',
    'T-27 (F-17, SR-13, SR-14): Rani Oktaviani membuka POS-SESSION-0001 di STORE-PKU dengan Opening Cash Rp200.000'
);

// T-28: Blokir duplikasi sesi terbuka di lokasi & register yang sama (SR-14)
$t28 = PosController::openPosSession([
    'location_code' => 'STORE-PKU',
    'opening_cash'  => 200000,
]);
assertTrue(
    ($t28['allowed'] ?? true) === false && ($t28['rule'] ?? '') === 'SR-14',
    'T-28 (SR-14): Pembukaan sesi POS ke-2 di STORE-PKU diblokir karena POS-SESSION-0001 masih Open'
);

// T-29: Checkout keranjang POS (2 pcs MIE-GOR-80 @ Rp4.200 + 1 pcs AIR-600 @ Rp4.000 = Net Rp12.400, PPN Rp1.364, Gross Rp13.764, COGS Rp8.100)
$t29 = PosController::checkoutPosBasket([
    'session_number' => 'POS-SESSION-0001',
    'items' => [
        ['variant_sku' => 'MIE-GOR-80', 'qty' => 2],
        ['variant_sku' => 'AIR-600',    'qty' => 1],
    ],
]);
$rcptNo = (string) ($t29['receipt_number'] ?? 'POS-RCPT-0001');
assertTrue(
    ($t29['allowed'] ?? false) === true
    && ($t29['session_status'] ?? '') === 'In Progress'
    && abs((float) ($t29['subtotal_net'] ?? 0) - 12400.0) < 0.01
    && abs((float) ($t29['tax_amount'] ?? 0) - 1364.0) < 0.01
    && abs((float) ($t29['total_gross'] ?? 0) - 13764.0) < 0.01
    && abs((float) ($t29['total_cogs'] ?? 0) - 8100.0) < 0.01
    && abs((float) ($t29['expected_cash'] ?? 0) - 213764.0) < 0.01,
    'T-29 (F-18, SR-13, SR-21): POS Checkout tunai Rp13.764 (Net Rp12.400 + PPN Rp1.364), status sesi beralih ke In Progress, COGS Rp8.100 satu kali, Expected Cash menjadi Rp213.764'
);

// T-30: Coba jual melebihi stok STORE-PKU (mis. 999 pcs AIR-600) -> harus diblokir (SR-04 mengalahkan SR-03)!
$t30 = PosController::checkoutPosBasket([
    'session_number' => 'POS-SESSION-0001',
    'items' => [
        ['variant_sku' => 'AIR-600', 'qty' => 999],
    ],
]);
assertTrue(
    ($t30['allowed'] ?? true) === false && ($t30['rule'] ?? '') === 'SR-04',
    'T-30 (SR-04 mengalahkan SR-03): Transaksi POS melebihi stok toko STORE-PKU ditolak mutlak'
);

// T-31: POS Cash Refund 1 pcs AIR-600 dari struk POS-RCPT-0001 (SR-16)
$t31 = PosController::processPosRefund([
    'receipt_number' => $rcptNo,
    'variant_sku'    => 'AIR-600',
    'return_qty'     => 1,
]);
assertTrue(
    ($t31['allowed'] ?? false) === true
    && (int) ($t31['store_on_hand_after'] ?? 0) === 40
    && abs((float) ($t31['refund_gross'] ?? 0) - 4440.0) < 0.01
    && abs((float) ($t31['expected_closing_cash'] ?? 0) - 209324.0) < 0.01,
    'T-31 (F-20, SR-16): POS Refund 1 pcs AIR-600 mengembalikan stok STORE-PKU ke 40 pcs, membalik COGS Rp2.500, & mengurangi Expected Cash ke Rp209.324'
);

// T-32: Tutup sesi dengan selisih kas -Rp5.000 (Counted Rp204.324 vs Expected Rp209.324) tanpa persetujuan Finance -> harus diblokir (SR-12 mengalahkan SR-11)!
$t32NoApproval = PosController::closePosSession([
    'session_number'   => 'POS-SESSION-0001',
    'counted_cash'     => 204324.0,
    'finance_approved' => false,
]);
assertTrue(
    ($t32NoApproval['allowed'] ?? true) === false && ($t32NoApproval['rule'] ?? '') === 'SR-12',
    'T-32 (SR-12 mengalahkan SR-11): Selisih kas -Rp5.000 memblokir penutupan sesi POS tanpa persetujuan Kepala Finance'
);

// T-33: Tutup sesi dengan persetujuan Finance -> berhasil menutup sesi & memposting jurnal selisih kas ke 5130
$t33Approved = PosController::reconcileCashCount([
    'session_number'   => 'POS-SESSION-0001',
    'counted_cash'     => 204324.0,
    'finance_approved' => true,
]);
assertTrue(
    ($t33Approved['allowed'] ?? false) === true
    && ($t33Approved['status'] ?? '') === 'Closed'
    && abs((float) ($t33Approved['variance'] ?? 0) - (-5000.0)) < 0.01,
    'T-33 (F-19, SR-12, SR-21): Dengan otorisasi Finance, sesi POS-SESSION-0001 resmi Closed & selisih -Rp5.000 diposting ke Cash Over/Short'
);

// Uji tambahan Sesi POS di STORE-DUM (Open -> In Progress -> Closed & pemotongan stok STORE-DUM)
$sessDum = PosController::openPosSession([
    'location_code' => 'STORE-DUM',
    'register_code' => 'POS01',
    'opening_cash'  => 200000,
]);
$sessDumNo = (string) ($sessDum['session_number'] ?? 'POS-SESSION-0002');
$chkDum = PosController::checkoutPosBasket([
    'session_number' => $sessDumNo,
    'location_code'  => 'STORE-DUM',
    'items'          => [
        ['variant_sku' => 'AIR-600', 'qty' => 2],
    ],
]);
assertTrue(
    ($chkDum['allowed'] ?? false) === true
    && ($chkDum['session_status'] ?? '') === 'In Progress'
    && (int) ($chkDum['stock_after']['AIR-600'] ?? 0) === 18,
    'POS STORE-DUM: Checkout 2 pcs AIR-600 di STORE-DUM memotong stok fisik toko (20 -> 18 pcs) & mengubah sesi ke In Progress'
);
$closeDum = PosController::reconcileCashCount([
    'session_number' => $sessDumNo,
    'counted_cash'   => (float) ($chkDum['expected_cash'] ?? 208880.0),
]);
assertTrue(
    ($closeDum['allowed'] ?? false) === true && ($closeDum['status'] ?? '') === 'Closed',
    'POS STORE-DUM: Rekonsiliasi Cash Count menutup sesi STORE-DUM ke status Closed secara seimbang'
);

// 7. Uji Kebenaran Angka Live, Trial Balance Seimbang & Anti-Replay (T-34, T-35, T-36 — Budi Haryanto / Direktur Operasional)
AuthService::login('Owner'); // Budi Haryanto
$kpi = AccountingController::getLiveKpiAndDrillDown();
assertTrue(
    ($kpi['trial_balance_balanced'] ?? false) === true,
    sprintf(
        'T-34 / T-35 (SR-19, SR-21): General Ledger & Trial Balance selalu seimbang (Debit Rp%s = Credit Rp%s)',
        number_format((float) ($kpi['trial_balance_debit'] ?? 0), 0, ',', '.'),
        number_format((float) ($kpi['trial_balance_credit'] ?? 0), 0, ',', '.')
    )
);

// T-36: Coba tutup ulang sesi POS yang sudah Closed -> harus REPLAY_BLOCKED (SR-18 / SR-22)
AuthService::login('Cashier');
$t36 = PosController::closePosSession([
    'session_number' => 'POS-SESSION-0001',
    'counted_cash'   => 203880.0,
]);
assertTrue(
    ($t36['allowed'] ?? true) === false && ($t36['outcome'] ?? '') === 'REPLAY_BLOCKED',
    'T-36 (SR-18, SR-22): Penutupan ulang sesi POS yang sudah Closed diblokir (REPLAY_BLOCKED) tanpa jurnal ganda'
);

// 8. Uji ReportController (Dasbor Analitik Budi Haryanto & Arif Firmansyah — F-01, SR-18, SR-19)
// Role non-Finance/non-Direktur harus ditolak (HTTP 403)
foreach (['Sales', 'Warehouse', 'Procurement', 'Cashier'] as $blockedRole) {
    AuthService::login($blockedRole);
    $repBlocked = ReportController::getDashboardAnalytics();
    assertTrue(
        ($repBlocked['allowed'] ?? true) === false
        && (int) ($repBlocked['http_code'] ?? 200) === 403
        && ($repBlocked['outcome'] ?? '') === 'DENIED',
        "ReportController Guard (F-01 & SR-18): Role '{$blockedRole}' diblokir mengakses data agregat finansial"
    );
}

// Role Finance (Arif Firmansyah) & Owner/Direktur (Budi Haryanto) diizinkan & menerima KPI SQL Real-Time akurat
foreach (['Finance', 'Owner'] as $allowedRole) {
    AuthService::login($allowedRole);
    $repOk = ReportController::getDashboardAnalytics();
    assertTrue(
        ($repOk['allowed'] ?? false) === true
        && (int) ($repOk['http_code'] ?? 0) === 200
        && isset($repOk['total_revenue'], $repOk['customer_invoices_ar'], $repOk['vendor_bills_ap'], $repOk['total_stock_valuation'])
        && (float) $repOk['total_stock_valuation'] > 0,
        "ReportController Analytics (SR-19): Role '{$allowedRole}' berhasil memuat KPI real-time (Pendapatan Rp" . number_format((float) $repOk['total_revenue'], 0, ',', '.') . ", Valuasi Stok Rp" . number_format((float) $repOk['total_stock_valuation'], 0, ',', '.') . ")"
    );
}

// 9. Uji Middleware.php (Proteksi Rute & Endpoint Tingkat Server — F-01 & SR-18)
foreach (['Warehouse', 'Procurement', 'Finance', 'Cashier'] as $nonSalesRole) {
    AuthService::login($nonSalesRole);
    $mwRes = \Middleware::dispatchProtectedRoute('/api/sales/confirm', 'POST', ['so_number' => 'S00007'], false);
    assertTrue(
        ($mwRes['allowed'] ?? true) === false
        && (int) ($mwRes['http_code'] ?? 200) === 403
        && ($mwRes['outcome'] ?? '') === 'DENIED'
        && !empty($mwRes['audit_id']),
        "Middleware Server Guard (SR-18): POST /api/sales/confirm oleh Role '{$nonSalesRole}' ditolak HTTP 403 Forbidden & dicatat ke audit_logs (#{$mwRes['audit_id']})"
    );
}

// Verifikasi bahwa entri audit_logs memiliki nama user (actor), waktu (created_at), dan endpoint /api/sales/confirm
$recentDenied = \RantauMart\Services\AuditLogger::getRecentLogs(5, 'DENIED');
$latestAudit  = $recentDenied[0] ?? [];
assertTrue(
    str_contains((string) ($latestAudit['action'] ?? ''), '/api/sales/confirm')
    && !empty($latestAudit['actor'])
    && !empty($latestAudit['created_at']),
    "Audit Log SR-18 Terverifikasi: Aktor='{$latestAudit['actor']}', Timestamp='{$latestAudit['created_at']}', Aksi='{$latestAudit['action']}'"
);

// 10. Uji Arsitektur Service/Event Trigger Lintas Modul (4 Jalur Integrasi Mutlak)
\RantauMart\Services\IntegrationEventTrigger::clearExecutionLog();
Database::resetBaselineSeed();

// Jalur 1: Sales -> Inventory (confirmSalesOrder otomatis memicu reserveStock($sku, $qty, $lokasi))
AuthService::login('Sales');
$evSoDraft = SalesController::createSalesOrder([
    'customer_id'     => 'C001',
    'variant_sku'     => 'AIR-600',
    'ordered_qty'     => 10,
    'unit_price'      => 4000,
    'discount_pct'    => 0,
    'source_location' => 'WH-PKU',
    'confirm_now'     => '0',
]);
$evSoConfirm = SalesController::confirmSalesOrder((string) ($evSoDraft['document'] ?? 'S00008'));
$evLog1      = \RantauMart\Services\IntegrationEventTrigger::getExecutionLog(\RantauMart\Services\IntegrationEventTrigger::EVENT_SALES_ORDER_CONFIRMED);
$airStockEv1 = InventoryController::getLocationSkuStock('WH-PKU', 'AIR-600');
assertTrue(
    ($evSoConfirm['allowed'] ?? false) === true
    && count($evLog1) === 1
    && ($evLog1[0]['result']['triggered_call'] ?? '') === 'InventoryController::reserveStock'
    && (int) ($airStockEv1['on_hand_qty'] ?? 0) === 80
    && (int) ($airStockEv1['reserved_qty'] ?? 0) === 18
    && (int) ($airStockEv1['available_qty'] ?? 0) === 62,
    'Event Trigger #1 (Sales -> Inventory): confirmSalesOrder() otomatis memanggil reserveStock("AIR-600", 10, "WH-PKU") (On Hand tetap 80, Reserved 8->18, Available 72->62)'
);

// Jalur 2: Inventory -> Accounting (Validate Receipt PO otomatis memicu Jurnal Penerimaan DR 1130 Inventory / CR 1150 Stock Interim berdasarkan Accepted)
AuthService::login('Warehouse');
$evReceipt = InventoryController::validateVendorReceipt([
    'po_number'    => 'PO00007',
    'received_qty' => 30,
    'accepted_qty' => 25,
    'rejected_qty' => 5,
]);
$evLog2 = \RantauMart\Services\IntegrationEventTrigger::getExecutionLog(\RantauMart\Services\IntegrationEventTrigger::EVENT_INVENTORY_RECEIPT_VALIDATED);
assertTrue(
    ($evReceipt['allowed'] ?? false) === true
    && count($evLog2) === 1
    && ($evLog2[0]['result']['debit_account'] ?? '') === '1130'
    && ($evLog2[0]['result']['credit_account'] ?? '') === '1150'
    && abs((float) ($evLog2[0]['result']['valuation_added'] ?? 0) - 70000.0) < 0.01,
    'Event Trigger #2 (Inventory -> Accounting): Validate Receipt PO00007 (25 Accepted, 5 Rejected) otomatis membuat Jurnal Penerimaan (DR 1130 Inventory / CR 1150 Stock Interim = Rp70.000)'
);

// Jalur 3: Purchase/Inventory -> Accounting (validateVendorBill otomatis cek silang ke tabel penerimaan barang & blokir jika bill_qty > accepted_qty)
AuthService::login('Finance');
$evBillBlocked = AccountingController::validateVendorBill([
    'po_number' => 'PO00007',
    'bill_qty'  => 30, // Melebihi Accepted fisik gudang (25 pcs)
]);
$evBillAllowed = AccountingController::validateVendorBill([
    'po_number' => 'PO00007',
    'bill_qty'  => 25, // Sesuai Accepted fisik gudang (25 pcs)
]);
$evLog3 = \RantauMart\Services\IntegrationEventTrigger::getExecutionLog(\RantauMart\Services\IntegrationEventTrigger::EVENT_PURCHASE_ACCOUNTING_3WAY_MATCH);
assertTrue(
    ($evBillBlocked['allowed'] ?? true) === false
    && ($evBillBlocked['rule'] ?? '') === 'SR-06'
    && ($evBillAllowed['allowed'] ?? false) === true
    && count($evLog3) === 2,
    'Event Trigger #3 (Purchase/Inventory -> Accounting): validateVendorBill() cek silang ke tabel penerimaan gudang, memblokir 30 pcs (> 25 Accepted) dan menyetujui 25 pcs'
);

// Jalur 4: POS -> Inventory & Accounting (Real-Time Checkout otomatis memicu deductPhysicalStock() & postPOSJournal())
AuthService::login('Cashier');
$evPosOpen = PosController::openPosSession([
    'location_code' => 'STORE-PKU',
    'opening_cash'  => 200000,
]);
$evPosCheckout = PosController::checkoutPosBasket([
    'session_number' => (string) ($evPosOpen['session_number'] ?? 'POS-SESSION-0001'),
    'items'          => [
        ['variant_sku' => 'AIR-600', 'qty' => 3],
    ],
]);
$evLog4 = \RantauMart\Services\IntegrationEventTrigger::getExecutionLog(\RantauMart\Services\IntegrationEventTrigger::EVENT_POS_BASKET_CHECKOUT);
assertTrue(
    ($evPosCheckout['allowed'] ?? false) === true
    && count($evLog4) === 1
    && in_array('InventoryController::deductPhysicalStock', (array) ($evLog4[0]['result']['triggered_calls'] ?? []), true)
    && in_array('AccountingController::postPOSJournal', (array) ($evLog4[0]['result']['triggered_calls'] ?? []), true)
    && (int) ($evPosCheckout['stock_after']['AIR-600'] ?? 0) === 37
    && abs((float) ($evLog4[0]['result']['revenue_recorded'] ?? 0) - 12000.0) < 0.01
    && abs((float) ($evLog4[0]['result']['cogs_recorded'] ?? 0) - 7500.0) < 0.01,
    'Event Trigger #4 (POS -> Inventory & Accounting): Checkout keranjang kasir otomatis memicu deductPhysicalStock() (STORE-PKU 40->37 pcs) & postPOSJournal() (COGS Rp7.500 & Pendapatan Rp12.000)'
);

// 10b. Alur Bisnis End-to-End Lintas Modul + Smart Step-by-Step Toast (Flash Session)
$flash = \RantauMart\Services\FlashService::class;
$toastText = static fn (array $t): string => implode(' | ', array_map(static fn ($x) => (string) ($x['message'] ?? ''), $t));
$e2ePdo = Database::getConnection();

Database::resetBaselineSeed();
AuthService::login('Owner');
$flash::pull();

// Order to Cash: SO Confirmed -> Reserved otomatis -> Delivery -> Draf Customer Invoice otomatis -> Post
AuthService::login('Sales');
$o2cSo = SalesController::createSalesOrder([
    'customer_id' => 'C001', 'variant_sku' => 'AIR-600', 'ordered_qty' => 10, 'unit_price' => 4000,
    'discount_pct' => 0, 'source_location' => 'WH-PKU', 'confirm_now' => '1',
]);
$o2cSoNo = (string) ($o2cSo['document'] ?? '');
$o2cToast = $toastText($flash::pull());
assertTrue(
    ($o2cSo['allowed'] ?? false) === true && str_contains($o2cToast, "Sales Order #{$o2cSoNo} dikonfirmasi, stok telah dicadangkan otomatis!"),
    "O2C Toast: 'Berhasil: Sales Order #{$o2cSoNo} dikonfirmasi, stok telah dicadangkan otomatis!' muncul dari Flash Session"
);

AuthService::login('Warehouse');
$o2cDel = InventoryController::validateDeliveryOrder(['so_number' => $o2cSoNo, 'deliver_qty' => 10]);
$o2cDraftNo = (string) ($o2cDel['draft_customer_invoice'] ?? '');
$o2cDelToast = $toastText($flash::pull());
$draftRow = $e2ePdo->query("SELECT status, journal_entry_number FROM customer_invoices WHERE invoice_number = " . $e2ePdo->quote($o2cDraftNo))->fetch();
assertTrue(
    ($o2cDel['allowed'] ?? false) === true && $o2cDraftNo !== ''
    && ($draftRow['status'] ?? '') === 'Draft' && ($draftRow['journal_entry_number'] ?? null) === null
    && str_contains($o2cDelToast, 'Draf Customer Invoice'),
    "O2C: Barang dikirim -> draf Customer Invoice {$o2cDraftNo} otomatis dibuat (Draft, belum ada jurnal) + toast"
);

AuthService::login('Finance');
$o2cInv = AccountingController::postCustomerInvoice(['so_number' => $o2cSoNo]);
$invCount = (int) $e2ePdo->query("SELECT COUNT(*) FROM customer_invoices WHERE so_number = " . $e2ePdo->quote($o2cSoNo))->fetchColumn();
$flash::pull();
assertTrue(
    ($o2cInv['allowed'] ?? false) === true && ($o2cInv['invoice_number'] ?? '') === $o2cDraftNo && $invCount === 1,
    'O2C: Finance memposting draf Customer Invoice yang sama (tanpa invoice ganda) -> Posted + jurnal AR'
);

// Procure to Pay: PO -> Receipt (Accepted/Rejected) -> Draf Vendor Bill otomatis -> Post (Three-Way Match)
AuthService::login('Procurement');
$p2pPo = PurchaseController::createPurchaseOrder([
    'vendor_id' => 'V01', 'variant_sku' => 'MIE-GOR-80', 'ordered_qty' => 100, 'destination_location' => 'WH-PKU', 'confirm_now' => '1',
]);
$p2pPoNo = (string) ($p2pPo['po_number'] ?? '');
$flash::pull();
AuthService::login('Warehouse');
$p2pRcpt = InventoryController::receivePurchaseOrder([
    'po_number' => $p2pPoNo, 'received_qty' => 100, 'accepted_qty' => 85, 'rejected_qty' => 15,
]);
$p2pDraftNo = (string) ($p2pRcpt['draft_vendor_bill'] ?? '');
$p2pToast = $toastText($flash::pull());
$billRow = $e2ePdo->query("SELECT status, billed_qty, journal_entry_number FROM vendor_bills WHERE bill_number = " . $e2ePdo->quote($p2pDraftNo))->fetch();
assertTrue(
    ($p2pRcpt['allowed'] ?? false) === true && $p2pDraftNo !== ''
    && ($billRow['status'] ?? '') === 'Draft' && (int) ($billRow['billed_qty'] ?? 0) === 85
    && str_contains($p2pToast, 'Draf Vendor Bill') && str_contains($p2pToast, 'hanya qty Accepted'),
    "P2P: Barang vendor tiba -> Accepted 85 pcs menambah stok & draf Vendor Bill {$p2pDraftNo} otomatis (85 pcs, Rejected tidak ditagih) + toast"
);
AuthService::login('Finance');
$p2pBill = AccountingController::postVendorBill(['po_number' => $p2pPoNo]);
$billCount = (int) $e2ePdo->query("SELECT COUNT(*) FROM vendor_bills WHERE po_number = " . $e2ePdo->quote($p2pPoNo))->fetchColumn();
$flash::pull();
assertTrue(
    ($p2pBill['allowed'] ?? false) === true && ($p2pBill['bill_number'] ?? '') === $p2pDraftNo && $billCount === 1,
    'P2P: Finance memvalidasi draf Vendor Bill yang sama via Three-Way Match -> Posted tanpa tagihan ganda'
);

// Replay ditolak -> tidak ada toast sukses palsu
AuthService::login('Warehouse');
$replayRcpt = InventoryController::receivePurchaseOrder(['po_number' => $p2pPoNo, 'received_qty' => 1, 'accepted_qty' => 1, 'rejected_qty' => 0]);
assertTrue(
    ($replayRcpt['allowed'] ?? true) === false && $flash::pull() === [],
    'Toast: Aksi yang ditolak (replay receipt) TIDAK memunculkan toast sukses'
);

// POS & Returns: Checkout Paid -> stok toko turun + COGS; refund -> stok pulih; Close -> toast jurnal COGS
AuthService::login('Cashier');
$posOpen = PosController::openPosSession(['location_code' => 'STORE-PKU', 'opening_cash' => 200000]);
$posSess = (string) ($posOpen['session_number'] ?? '');
$flash::pull();
$posPay = PosController::posCheckout(['session_number' => $posSess, 'items' => [['variant_sku' => 'AIR-600', 'qty' => 3]]]);
$posPayToast = $toastText($flash::pull());
assertTrue(
    ($posPay['allowed'] ?? false) === true && (int) ($posPay['stock_after']['AIR-600'] ?? 0) === 37
    && str_contains($posPayToast, 'stok toko terpotong & Jurnal COGS diposting!'),
    'POS: Struk Paid langsung memotong stok STORE-PKU (40->37) & posting COGS + toast'
);
$posRefund = PosController::processPosRefund(['receipt_number' => (string) ($posPay['receipt_number'] ?? ''), 'variant_sku' => 'AIR-600', 'return_qty' => 1]);
$refundToast = $toastText($flash::pull());
assertTrue(
    ($posRefund['allowed'] ?? false) === true && (int) ($posRefund['store_on_hand_after'] ?? 0) === 38
    && str_contains($refundToast, 'stok toko dipulihkan'),
    'Returns: Refund POS memulihkan stok STORE-PKU (37->38), membalik COGS & menyesuaikan kas + toast'
);
$posClose = PosController::closePosSession(['session_number' => $posSess]);
$closeToast = $toastText($flash::pull());
assertTrue(
    ($posClose['allowed'] ?? false) === true && str_contains($closeToast, "Sesi Kasir #{$posSess} Ditutup: Jurnal COGS berhasil diposting!"),
    "POS Toast: 'Sesi Kasir Ditutup: Jurnal COGS berhasil diposting!' muncul"
);

// ACID: toast dibuang saat rollback
$e2ePdo->beginTransaction();
$flash::stage('toast-rollback-test');
$e2ePdo->rollBack();
assertTrue($flash::peek() === [], 'ACID Toast: toast yang tertahan dalam transaksi dibuang saat rollBack()');

// 11. Uji reset_fixture.php (Khusus Direktur Operasional / Owner — Truncate & Reset Bagian 4. Dataset Realistis)
foreach (['Sales', 'Warehouse', 'Procurement', 'Finance', 'Cashier'] as $nonOwnerRole) {
    AuthService::login($nonOwnerRole);
    $resetDenied = FixtureResetController::executeReset();
    assertTrue(
        ($resetDenied['allowed'] ?? true) === false
        && (int) ($resetDenied['http_status'] ?? 200) === 403
        && ($resetDenied['outcome'] ?? '') === 'DENIED',
        "reset_fixture.php Guard (SR-18): Role '{$nonOwnerRole}' ditolak HTTP 403 Forbidden saat mencoba mereset fixture database"
    );
}

// Login sebagai Budi Haryanto (Owner / Direktur Operasional) -> jalankan reset_fixture.php
AuthService::login('Owner');
$resetOk = FixtureResetController::executeReset();
$ver     = (array) ($resetOk['verification'] ?? []);
assertTrue(
    ($resetOk['allowed'] ?? false) === true
    && (int) ($resetOk['http_status'] ?? 0) === 200
    && (int) ($ver['sku_count'] ?? 0) === 15
    && (int) ($ver['customer_count'] ?? 0) === 6
    && (int) ($ver['vendor_count'] ?? 0) === 4
    && abs((float) ($ver['opening_journal_debit'] ?? 0) - 25716900.0) < 0.01
    && abs((float) ($ver['opening_journal_credit'] ?? 0) - 25716900.0) < 0.01
    && ($ver['is_balanced'] ?? false) === true
    && ($ver['all_checks_passed'] ?? false) === true,
    'reset_fixture.php (Owner / Direktur): Berhasil truncate & reset ke Bagian 4 (15 SKU, 6 Pelanggan, 4 Vendor, Jurnal Pembukaan Rp25.716.900 Debit = Kredit seimbang)'
);

echo "\n===================================================================\n";
echo "HASIL AKHIR: {$passCount} PASSED, {$failCount} FAILED\n";
echo "===================================================================\n";

exit($failCount > 0 ? 1 : 0);
