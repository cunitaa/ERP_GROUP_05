<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use PDO;
use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Services\AtomicRunner;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;
use RuntimeException;

/**
 * Controller 4 Alur Bisnis Utama PT RantauMart Riau (BusinessFlowController)
 * Mengimplementasikan 4 Business Flows secara simpel, elegan, dan terintegrasi end-to-end:
 * 1. Flow 1 (Order to Cash): Lead -> Opportunity -> Quotation -> SO -> DO -> Invoice -> Payment
 * 2. Flow 2 (Procure to Pay): RFQ -> PO -> Receipt (Accepted only) -> Vendor Bill -> Payment
 * 3. Flow 3 (POS & Closing): Open Session -> Real-time Stock Move Checkout -> Validated Ledger Closing
 * 4. Flow 4 (Returns): DO / POS Receipt Return -> Inspection -> Restock -> Credit Note / Refund -> Journal Reversal
 */
class BusinessFlowController
{
    // =========================================================================
    // FLOW 1: ORDER TO CASH (O2C)
    // =========================================================================

    /**
     * Langkah 1.1: Ubah Lead menjadi Opportunity (stage: Qualified)
     */
    public static function convertLead(string $leadNumber): array
    {
        return SalesController::convertLeadToOpportunity($leadNumber);
    }

    /**
     * Langkah 1.2: Buat Penawaran Harga / Quotation (Status: Draft)
     */
    public static function createQuotation(array $data): array
    {
        $data['confirm_now'] = '0';
        return SalesController::createSalesOrder($data);
    }

    /**
     * Langkah 1.3: Konfirmasi Sales Order (Kunci Harga & Otomatis Reservasi Stok Gudang)
     */
    public static function confirmSalesOrder(string $soNumber): array
    {
        return SalesController::confirmSalesOrder($soNumber);
    }

    /**
     * Langkah 1.4: Pengiriman Barang (Delivery Order) -> Kurangi Stok Fisik & Catat COGS
     */
    public static function deliverOrder(string $soNumber, int $deliverQty): array
    {
        return InventoryController::validateDeliveryOrder([
            'so_number'   => $soNumber,
            'deliver_qty' => $deliverQty,
        ]);
    }

    /**
     * Langkah 1.5: Terbitkan Customer Invoice (Hanya atas Delivered Qty -> Menambah AR & PPN)
     */
    public static function createCustomerInvoice(string $soNumber, int $invoiceQty): array
    {
        return AccountingController::postCustomerInvoice([
            'so_number'   => $soNumber,
            'invoice_qty' => $invoiceQty,
        ]);
    }

    /**
     * Langkah 1.6: Eksekusi Pembayaran Faktur Pelanggan -> Memotong Piutang (AR) & Menambah Bank
     */
    public static function payCustomerInvoice(string $invoiceNumber, float $amount): array
    {
        return AccountingController::registerPayment([
            'target_document' => $invoiceNumber,
            'amount'          => $amount,
        ]);
    }

    // =========================================================================
    // FLOW 2: PROCURE TO PAY (P2P)
    // =========================================================================

    /**
     * Langkah 2.1: Buat Permintaan Penawaran Pemasok / RFQ (Status: RFQ)
     */
    public static function createRfq(array $data): array
    {
        $data['confirm_now'] = '0';
        return PurchaseController::createPurchaseOrder($data);
    }

    /**
     * Langkah 2.2: Konfirmasi Purchase Order (Kunci Harga Beli dari vendor_pricelist)
     */
    public static function confirmPurchaseOrder(string $poNumber): array
    {
        $res = PurchaseController::confirmPurchaseOrder($poNumber);
        if ($res['allowed'] ?? false) {
            $po = PurchaseController::getPurchaseOrder($poNumber);
            $res['unit_price'] = (float) ($po['lines'][0]['unit_price'] ?? 0);
        }
        return $res;
    }

    /**
     * Langkah 2.3: Penerimaan Barang -> Tambah Stok Fisik HANYA Qty Accepted (Tolak Rusak)
     */
    public static function receiveGoods(string $poNumber, int $acceptedQty, int $rejectedQty): array
    {
        $receivedQty = $acceptedQty + $rejectedQty;
        return InventoryController::receivePurchaseOrder([
            'po_number'    => $poNumber,
            'received_qty' => $receivedQty,
            'accepted_qty' => $acceptedQty,
            'rejected_qty' => $rejectedQty,
        ]);
    }

    /**
     * Langkah 2.4: Buat Tagihan Pemasok (Vendor Bill) Sesuai Qty Accepted (Three-Way Match)
     */
    public static function createVendorBill(string $poNumber, int $billQty): array
    {
        return AccountingController::createVendorBillFromPo([
            'po_number' => $poNumber,
            'bill_qty'  => $billQty,
        ]);
    }

    /**
     * Langkah 2.5: Pembayaran Tagihan Vendor -> Memotong Saldo Bank & Hutang (AP)
     */
    public static function payVendorBill(string $billNumber, float $amount): array
    {
        return AccountingController::validatePayment([
            'document_number' => $billNumber,
            'amount'          => $amount,
        ]);
    }

    // =========================================================================
    // FLOW 3: POINT OF SALE & CLOSING (POS)
    // =========================================================================

    /**
     * Langkah 3.1: Buka Sesi Kasir Baru (Open Session)
     */
    public static function openPosSession(string $locationCode = 'STORE-PKU', float $openingCash = 200000.0): array
    {
        return PosController::openPosSession([
            'location_code' => $locationCode,
            'opening_cash'  => $openingCash,
        ]);
    }

    /**
     * Langkah 3.2: Checkout Kasir Tunai & Struk Paid -> Eksekusi Stock Move Real-time di Toko
     * (Pencatatan jurnal buku besar ditahan hingga Close Session divalidasi)
     */
    public static function posCashCheckout(string $sessionNumber, array $items, float $cashPaid = 0.0): array
    {
        return PosController::posCheckout([
            'session_number' => $sessionNumber,
            'items'          => $items,
            'cash_paid'      => $cashPaid,
        ]);
    }

    /**
     * Langkah 3.3: Tutup Sesi & Posting Kasir -> Validasi Selisih Kas & Posting Revenue/Cash ke Ledger
     */
    public static function closePosSession(string $sessionNumber, float $countedCash, bool $financeApproved = false): array
    {
        $res = PosController::reconcileCashCount([
            'session_number'   => $sessionNumber,
            'counted_cash'     => $countedCash,
            'finance_approved' => $financeApproved,
        ]);
        if (isset($res['settlement_je_number'])) {
            $res['journal_entry_number'] = $res['settlement_je_number'];
        }
        return $res;
    }

    // =========================================================================
    // FLOW 4: RETURNS & RESTOCK
    // =========================================================================

    /**
     * Langkah 4.1: Retur Barang Penjualan B2B (Delivery Order / Customer Invoice)
     * Inspeksi: Jika layak jual -> restock ke gudang, terbitkan Credit Note & balikkan jurnal akuntansi
     */
    public static function returnDeliveryOrder(string $invoiceNumber, int $returnQty, bool $isSellable = true): array
    {
        return AccountingController::createB2bReturnCreditNote([
            'invoice_number' => $invoiceNumber,
            'return_qty'     => $returnQty,
            'is_sellable'    => $isSellable,
        ]);
    }

    /**
     * Langkah 4.2: Retur Struk Kasir POS (POS Cash Refund)
     * Inspeksi: Jika layak jual -> pulihkan stok toko ritel, kembalikan uang kas & balikkan jurnal
     */
    public static function returnPosReceipt(string $receiptNumber, string $variantSku, int $returnQty, bool $isSellable = true): array
    {
        return PosController::processPosRefund([
            'receipt_number' => $receiptNumber,
            'variant_sku'    => $variantSku,
            'return_qty'     => $returnQty,
            'is_sellable'    => $isSellable,
        ]);
    }
}
