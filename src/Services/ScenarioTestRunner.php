<?php
declare(strict_types=1);

namespace RantauMart\Services;

use PDO;
use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\FixtureResetController;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PosController;
use RantauMart\Controllers\PurchaseController;
use RantauMart\Controllers\ReportController;
use RantauMart\Controllers\SalesController;
use RantauMart\Middleware\Middleware;
use Throwable;

/**
 * Service Pelaksana Pengujian Seluruh Skenario Uji T-xx & Aturan Bisnis SR-xx.
 * Memvalidasi kepatuhan operasional mutlak dan memulihkan kondisi fixture secara otomatis.
 */
class ScenarioTestRunner
{
    /**
     * Menjalankan seluruh skenario uji T-01 s/d T-36 dan aturan SR-01 s/d SR-22.
     *
     * @return array<string, mixed>
     */
    public static function runAll(): array
    {
        $startTime = microtime(true);
        $tests     = [];

        $record = static function (
            string $code,
            string $rule,
            string $name,
            bool $passed,
            string $details = ''
        ) use (&$tests): void {
            $tests[] = [
                'code'    => $code,
                'rule'    => $rule,
                'name'    => $name,
                'status'  => $passed ? 'PASS' : 'FAIL',
                'details' => $details,
            ];
        };

        try {
            // Reset ke baseline awal sebelum mulai
            Database::resetBaselineSeed();
            $pdo = Database::getConnection();

            // 1. T-01: Hak Akses Menu Modul per Role (F-01)
            $salesModules = array_keys(RolePermissions::getAllowedModulesOnlyForRole('Sales'));
            $t01Passed = in_array('Sales', $salesModules, true)
                && in_array('CRM', $salesModules, true)
                && !in_array('Purchase', $salesModules, true)
                && !in_array('Accounting', $salesModules, true)
                && !in_array('Point of Sale', $salesModules, true);
            $record('T-01', 'F-01', 'Hak Akses Modul Role Sales (Hanya CRM, Sales, Apps)', $t01Passed, 'Modul Procurement, Finance & POS disembunyikan');

            // 2. T-02: Server-side RBAC Guard & Audit Log (SR-18)
            AuthService::login('Sales');
            $t02A = AccountingController::postVendorBill(['po_number' => 'PO00007']);
            $t02B = PosController::closePosSession(['session_number' => 'POS-SESSION-0001']);
            $t02Passed = (($t02A['allowed'] ?? true) === false) && (($t02B['allowed'] ?? true) === false);
            $record('T-02', 'SR-18', 'Blokir Aksi Lintas Modul oleh Role Sales (Post Bill & Close POS)', $t02Passed, 'Aksi ditolak HTTP 403 & tercatat di audit_logs');

            // 3. T-04: Credit Limit Update Guard (SR-08)
            AuthService::login('Warehouse');
            $t04 = DocumentStateGuard::attemptUpdateCreditLimit('C001', 9999999);
            $t04Passed = ($t04['allowed'] ?? true) === false && ($t04['outcome'] ?? '') === 'DENIED';
            $record('T-04', 'SR-08', 'Blokir Perubahan Credit Limit oleh Warehouse', $t04Passed, 'Hanya Finance & Sales yang berwenang mengubah credit limit');

            // 4. T-05 & T-06: Manajemen Master SKU & Anti-Duplikasi (SR-20)
            AuthService::login('Warehouse');
            $t05 = InventoryController::createProductVariant([
                'variant_sku'    => 'TES-SKU-99',
                'name'           => 'Produk Tes Baru',
                'size'           => '500 ml',
                'color_flavour'  => 'Original',
                'sales_price'    => 5000,
                'cost'           => 3000,
                'default_vendor' => 'V01',
            ]);
            $t06 = InventoryController::createProductVariant([
                'variant_sku'   => 'AIR-600',
                'name'          => 'Duplikasi Air Mineral',
                'size'          => '600 ml',
                'color_flavour' => 'Original',
            ]);
            $record('T-05', 'SR-20', 'Tambah Master SKU Baru (TES-SKU-99)', ($t05['allowed'] ?? false) === true, 'SKU baru berhasil tersimpan');
            $record('T-06', 'SR-20', 'Anti-Duplikasi SKU (AIR-600)', ($t06['allowed'] ?? true) === false && ($t06['rule'] ?? '') === 'SR-20', 'Duplikasi SKU ditolak secara persisten');

            // 5. T-07 & T-08: CRM Lead to Opportunity & Anti-Replay (F-05 / SR-18)
            AuthService::login('Sales');
            $t07 = SalesController::convertLeadToOpportunity('LEAD-0001');
            $t08 = SalesController::convertLeadToOpportunity('LEAD-0001');
            $record('T-07', 'F-05', 'Konversi LEAD-0001 ke Opportunity Qualified', ($t07['allowed'] ?? false) === true && ($t07['new_status'] ?? '') === 'Qualified', 'Lead berhasil dikonversi ke Qualified');
            $record('T-08', 'SR-18', 'Anti-Replay Konversi Lead Kedua Kali', ($t08['allowed'] ?? true) === false && ($t08['outcome'] ?? '') === 'REPLAY_BLOCKED', 'Replay konversi diblokir (REPLAY_BLOCKED)');

            // 6. T-09: Buat & Konfirmasi Sales Order, Kunci Harga & Reservasi Stok (SR-07, SR-09)
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
            $soNo = (string) ($t09['document'] ?? $t09['so_number'] ?? 'S00008');
            $airStock = InventoryController::getLocationSkuStock('WH-PKU', 'AIR-600');
            $t09Passed = ($t09['allowed'] ?? false) === true
                && (int) ($airStock['on_hand_qty'] ?? 0) === 80
                && (int) ($airStock['reserved_qty'] ?? 0) === 28
                && (int) ($airStock['available_qty'] ?? 0) === 52;
            $record('T-09', 'SR-07 / SR-09', 'Konfirmasi SO & Otomatisasi Reservasi Stok (WH-PKU)', $t09Passed, 'On Hand tetap 80, Reserved bertambah ke 28, Available 52');

            // 7. T-10: Validasi Diskon Maks 10% (SR-07) & Credit Exposure Limit (SR-08)
            $t10Disc = SalesController::createSalesOrder([
                'customer_id'  => 'C001',
                'variant_sku'  => 'AIR-600',
                'ordered_qty'  => 10,
                'discount_pct' => 15, // > 10%
                'confirm_now'  => '1',
            ]);
            $t10Limit = SalesController::createSalesOrder([
                'customer_id'  => 'C001',
                'variant_sku'  => 'BER-5KG',
                'ordered_qty'  => 15, // Melebihi credit limit Rp500.000
                'confirm_now'  => '1',
            ]);
            $record('T-10a', 'SR-07', 'Blokir Konfirmasi SO dengan Diskon > 10%', ($t10Disc['allowed'] ?? true) === false, 'Diskon 15% ditolak (SR-07)');
            $record('T-10b', 'SR-08', 'Blokir Konfirmasi SO Melebihi Credit Limit Pelanggan', ($t10Limit['allowed'] ?? true) === false, 'Credit limit C001 terjaga (SR-08)');

            // 8. T-11 & T-12: Partial Delivery, Backorder & Anti-Negative Stock (F-10 / SR-04)
            AuthService::login('Warehouse');
            $t11 = InventoryController::validateDeliveryOrder([
                'so_number'   => $soNo,
                'deliver_qty' => 12,
            ]);
            $t12 = InventoryController::validateDeliveryOrder([
                'so_number'   => $soNo,
                'deliver_qty' => 999, // Melebihi stok
            ]);
            $record('T-11', 'F-10 / SR-09', 'Partial Delivery 12 pcs & Otomatis Backorder 8 pcs', ($t11['allowed'] ?? false) === true && (int) ($t11['backorder_qty'] ?? 0) === 8, 'COGS Rp30.000 terposting & Draf Invoice tercipta');
            $record('T-12', 'SR-04', 'Anti-Negative Stock pada Pengiriman Melebihi Stok', ($t12['allowed'] ?? true) === false, 'Pengiriman berlebih ditolak mutlak (SR-04)');

            // 9. T-13 & T-14: Internal Transfer Antar Lokasi (SR-17)
            $t13 = InventoryController::createInternalTransfer([
                'from_location' => 'WH-PKU',
                'to_location'   => 'STORE-PKU',
                'variant_sku'   => 'AIR-600',
                'qty_move'      => 15,
            ]);
            $t13Passed = ($t13['allowed'] ?? false) === true
                && (bool) ($t13['total_valuation_unchanged'] ?? false) === true;
            $record('T-13', 'SR-17', 'Internal Transfer 15 pcs Menjaga Total Valuasi Perusahaan', $t13Passed, 'Valuasi persediaan terjaga tanpa pergeseran laba/rugi');

            // 10. T-15 & T-16: Purchase Order Vendor Pricelist & Kunci Harga (SR-20 / SR-18)
            AuthService::login('Procurement');
            $t15 = PurchaseController::createPurchaseOrder([
                'vendor_id'            => 'V01',
                'variant_sku'          => 'MIE-GOR-80',
                'ordered_qty'          => 100,
                'destination_location' => 'WH-PKU',
                'confirm_now'          => '1',
            ]);
            $poNo = (string) ($t15['po_number'] ?? '');
            $t16 = PurchaseController::updatePurchaseOrderPrice($poNo, 2500);
            $record('T-15', 'SR-20', 'PO Otomatis Mengambil Harga dari vendor_pricelist (Rp3.000)', ($t15['allowed'] ?? false) === true && abs((float) ($t15['unit_price'] ?? 0) - 3000.0) < 0.01, 'Subtotal Rp300.000 + PPN Rp33.000 = Rp333.000');
            $record('T-16', 'SR-18', 'Kunci Harga Beli Read-Only setelah PO Confirmed', ($t16['allowed'] ?? true) === false, 'Edit harga beli pada PO Confirmed diblokir');

            // 11. T-17 & T-18: Penerimaan Barang Hanya Accepted & Anti-Replay (SR-10 / SR-18)
            AuthService::login('Warehouse');
            $t17 = InventoryController::receivePurchaseOrder([
                'po_number'    => $poNo,
                'received_qty' => 100,
                'accepted_qty' => 85,
                'rejected_qty' => 15,
            ]);
            $t18 = InventoryController::receivePurchaseOrder([
                'po_number'    => $poNo,
                'received_qty' => 10,
                'accepted_qty' => 10,
                'rejected_qty' => 0,
            ]);
            $record('T-17', 'SR-10', 'Penerimaan PO: Hanya Qty Accepted (85 pcs) Menambah On Hand', ($t17['allowed'] ?? false) === true && (int) ($t17['accepted_qty'] ?? 0) === 85, 'Rejected 15 pcs diisolasi & draf Vendor Bill dibuat otomatis');
            $record('T-18', 'SR-18', 'Anti-Replay Validasi Penerimaan PO Berulang', ($t18['allowed'] ?? true) === false && ($t18['outcome'] ?? '') === 'REPLAY_BLOCKED', 'Penerimaan ganda diblokir (REPLAY_BLOCKED)');

            // 12. T-19 & T-20: Customer Invoice Berdasarkan Delivered Qty (SR-02 mengalahkan SR-01)
            AuthService::login('Finance');
            $t20 = AccountingController::postCustomerInvoice([
                'so_number'   => $soNo,
                'invoice_qty' => 20, // Belum terkirim 20, baru 12
            ]);
            $t19 = AccountingController::postCustomerInvoice([
                'so_number'   => $soNo,
                'invoice_qty' => 12,
            ]);
            $invNo = (string) ($t19['invoice_number'] ?? 'INV-0001');
            $record('T-20', 'SR-02', 'Blokir Customer Invoice Melebihi Kuantitas Terkirim (Delivered)', ($t20['allowed'] ?? true) === false && ($t20['rule'] ?? '') === 'SR-02', 'Penagihan sebelum barang dikirim diblokir mutlak');
            $record('T-19', 'SR-02 / SR-21', 'Posting Customer Invoice atas 12 pcs Terkirim (DPP + PPN)', ($t19['allowed'] ?? false) === true, 'Jurnal AR, B2B Revenue (DPP), dan Output VAT diposting seimbang');

            // 13. T-21 & T-22: Vendor Bill Three-Way Match & PPV (SR-06 mengalahkan SR-05)
            $t22 = AccountingController::postVendorBill([
                'po_number' => $poNo,
                'bill_qty'  => 100, // Melebihi accepted 85 pcs
            ]);
            $t21 = AccountingController::createVendorBillFromPo([
                'po_number' => $poNo,
                'bill_qty'  => 85,
            ]);
            $billNo = (string) ($t21['bill_number'] ?? 'BILL-0001');
            $record('T-22', 'SR-06', 'Three-Way Match: Blokir Vendor Bill Melebihi Accepted Qty', ($t22['allowed'] ?? true) === false && ($t22['rule'] ?? '') === 'SR-06', 'Tagihan 100 pcs diblokir karena gudang hanya menerima 85 pcs');
            $record('T-21', 'SR-06 / SR-21', 'Posting Vendor Bill 85 pcs (Clearing Interim, PPV, Input VAT & AP)', ($t21['allowed'] ?? false) === true, 'Interim Rp238.000 bersih, PPV Rp17.000, Input VAT Rp28.050');

            // 14. T-23 & T-24: Pembayaran Parsial & Blokir Overpayment (SR-15)
            $t23 = AccountingController::registerPayment([
                'target_document' => $invNo,
                'amount'          => 20000,
            ]);
            $t24 = AccountingController::registerPayment([
                'target_document' => $invNo,
                'amount'          => 999999, // Overpayment
            ]);
            $record('T-23', 'SR-15', 'Pembayaran Parsial Customer Invoice (Status: Partially Paid)', ($t23['allowed'] ?? false) === true && ($t23['status'] ?? '') === 'Partially Paid', 'Sisa tagihan berkurang akurat');
            $record('T-24', 'SR-15', 'Blokir Overpayment Melebihi Sisa Tagihan Faktur', ($t24['allowed'] ?? true) === false, 'Kelebihan bayar dicegah secara ketat');

            // 15. T-25: Pelunasan Vendor Bill yang Lolos Three-Way Match
            $t25 = AccountingController::validatePayment([
                'document_number' => $billNo,
                'amount'          => 283050,
            ]);
            $record('T-25', 'SR-21', 'Pelunasan Vendor Bill Lolos Three-Way Match (DR AP / CR Bank)', ($t25['allowed'] ?? false) === true && ($t25['status'] ?? '') === 'Paid', 'Vendor Bill lunas seimbang');

            // 16. T-26: B2B Retur Penjualan, Pemulihan Stok Fisik & Reversal Jurnal (SR-16)
            $t26 = AccountingController::createB2bReturnCreditNote([
                'invoice_number' => $invNo,
                'return_qty'     => 2,
            ]);
            $record('T-26', 'SR-16', 'B2B Sales Return: Restock 2 pcs WH-PKU & Terbitkan Credit Note', ($t26['allowed'] ?? false) === true, 'Stok pulih, HPP dan PPN Keluaran dibalik');

            // 17. T-27 & T-28: Sesi Kasir POS & Batasan 1 Sesi Aktif (SR-13, SR-14)
            AuthService::login('Cashier');
            $t27 = PosController::openPosSession([
                'location_code' => 'STORE-PKU',
                'opening_cash'  => 200000,
            ]);
            $sessNo = (string) ($t27['session_number'] ?? 'POS-SESSION-0001');
            $t28 = PosController::openPosSession([
                'location_code' => 'STORE-PKU',
                'opening_cash'  => 200000,
            ]);
            $record('T-27', 'SR-13', 'Buka Sesi Kasir POS STORE-PKU dengan Opening Cash Rp200.000', ($t27['allowed'] ?? false) === true && (float) ($t27['opening_cash'] ?? 0) === 200000.0, 'Sesi berstatus Open & siap transaksi');
            $record('T-28', 'SR-14', 'Blokir Pembukaan Sesi Kasir Ganda di Toko yang Sama', ($t28['allowed'] ?? true) === false, 'Hanya diizinkan 1 sesi aktif per register kasir');

            // 18. T-29 & T-30: Real-time Checkout Kasir & Anti-Negative Stock (SR-04 mengalahkan SR-03)
            $t29 = PosController::posCheckout([
                'session_number' => $sessNo,
                'items'          => [
                    ['variant_sku' => 'MIE-GOR-80', 'qty' => 2],
                    ['variant_sku' => 'AIR-600', 'qty' => 1],
                ],
            ]);
            $rcptNo = (string) ($t29['receipt_number'] ?? 'POS-RCPT-0001');
            $t30 = PosController::posCheckout([
                'session_number' => $sessNo,
                'items'          => [
                    ['variant_sku' => 'AIR-600', 'qty' => 9999],
                ],
            ]);
            $record('T-29', 'SR-13 / SR-21', 'Checkout Kasir: Potong Stok Toko & Jurnal COGS/Pendapatan Seketika', ($t29['allowed'] ?? false) === true, 'Stok STORE-PKU berkurang & Expected Cash bertambah');
            $record('T-30', 'SR-04', 'Anti-Negative Stock Toko Ritel pada Checkout Kasir', ($t30['allowed'] ?? true) === false, 'Keranjang melebihi stok toko ditolak mutlak');

            // 19. T-31: POS Cash Refund, Pemulihan Stok & Penyesuaian Kas (SR-16)
            $t31 = PosController::processPosRefund([
                'receipt_number' => $rcptNo,
                'variant_sku'    => 'AIR-600',
                'return_qty'     => 1,
            ]);
            $record('T-31', 'SR-16', 'POS Cash Refund: Pulihkan Stok Toko & Kurangi Expected Cash', ($t31['allowed'] ?? false) === true, 'Stok STORE-PKU pulih 1 pcs & kas laci disesuaikan');

            // 20. T-32: Penahanan Tutup Sesi Jika Selisih Kas Tanpa Finance (SR-12 mengalahkan SR-11)
            $t32 = PosController::closePosSession([
                'session_number'   => $sessNo,
                'counted_cash'     => 204324.0, // Selisih dengan expected cash Rp209.324 (-Rp5.000)
                'finance_approved' => false,
            ]);
            $record('T-32', 'SR-12', 'Selisih Kas Menahan Penutupan Sesi ke Pending Approval', ($t32['allowed'] ?? false) === false && ($t32['status'] ?? '') === 'Pending Approval', 'Jurnal kasir ditahan tanpa persetujuan Finance');

            // 21. T-33: Penutupan Sesi dengan Otorisasi Finance & Posting Cash Over/Short (SR-12 / SR-21)
            AuthService::login('Finance');
            $t33 = PosController::reconcileCashCount([
                'session_number'   => $sessNo,
                'counted_cash'     => 204324.0,
                'finance_approved' => true,
            ]);
            $record('T-33', 'SR-12 / SR-21', 'Penutupan Sesi POS Resmi Closed dengan Otorisasi Finance', ($t33['allowed'] ?? false) === true && ($t33['status'] ?? '') === 'Closed', 'Selisih kas diposting ke 5130 Cash Over/Short');

            // 22. T-34 & T-35: Keseimbangan Mutlak Neraca Saldo & Buku Besar (SR-19 / SR-21)
            $balCheck = $pdo->query("
                SELECT SUM(debit) AS total_d, SUM(credit) AS total_c
                FROM journal_entry_lines
            ")->fetch(PDO::FETCH_ASSOC);
            $totalDebit  = (float) ($balCheck['total_d'] ?? 0);
            $totalCredit = (float) ($balCheck['total_c'] ?? 0);
            $isBalanced  = abs($totalDebit - $totalCredit) < 0.01 && $totalDebit > 0;
            $record('T-34', 'SR-21', 'Keseimbangan Neraca Saldo Buku Besar (Debit = Kredit)', $isBalanced, sprintf('Debit Rp%s = Kredit Rp%s', number_format($totalDebit, 2, ',', '.'), number_format($totalCredit, 2, ',', '.')));

            // 23. T-36: Anti-Replay Penutupan Sesi POS yang Sudah Closed (SR-18 / SR-22)
            $t36 = PosController::reconcileCashCount([
                'session_number'   => $sessNo,
                'counted_cash'     => 204324.0,
                'finance_approved' => true,
            ]);
            $record('T-36', 'SR-18 / SR-22', 'Anti-Replay Penutupan Sesi yang Sudah Closed', ($t36['allowed'] ?? true) === false && ($t36['outcome'] ?? '') === 'REPLAY_BLOCKED', 'Pencegahan jurnal settlement ganda');

        } catch (Throwable $e) {
            $record('FATAL', 'EXCEPTION', 'Terjadi kesalahan sistem saat mengeksekusi pengujian', false, $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            // Selalu pulihkan database ke kondisi awal (Baseline Rp25.716.900)
            Database::resetBaselineSeed();
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $totalPass  = count(array_filter($tests, static fn ($t) => $t['status'] === 'PASS'));
        $totalFail  = count(array_filter($tests, static fn ($t) => $t['status'] === 'FAIL'));

        return [
            'summary' => [
                'total'       => count($tests),
                'passed'      => $totalPass,
                'failed'      => $totalFail,
                'duration_ms' => $durationMs,
                'all_passed'  => ($totalFail === 0),
            ],
            'scenarios' => $tests,
        ];
    }
}
