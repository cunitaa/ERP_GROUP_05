<?php
declare(strict_types=1);

namespace RantauMart\Services;

use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\InventoryController;

/**
 * IntegrationEventTrigger — Arsitektur Service / Event Trigger Lintas Modul PT RantauMart Riau
 *
 * Menghubungkan aksi antar-modul secara otomatis dan sinkron tanpa intervensi manual
 * melalui 4 jalur integrasi mutlak:
 *
 * 1. Integrasi Sales -> Inventory (Otomatisasi Reservasi — SR-09):
 *    Saat `SalesController::confirmSalesOrder()` dieksekusi di Modul Sales, sistem otomatis
 *    memicu event `sales.order.confirmed` yang memanggil `InventoryController::reserveStock($sku, $qty, $lokasi)`
 *    pada saat yang bersamaan (menambah Reserved & mengurangi Available tanpa mengubah On Hand).
 *
 * 2. Integrasi Inventory -> Accounting (Jurnal Penerimaan — SR-10 & SR-21):
 *    Saat penerimaan barang PO di-klik 'Validate' di gudang (`InventoryController::receivePurchaseOrder()`),
 *    sistem otomatis memicu event `inventory.receipt.validated` yang memanggil Modul Accounting
 *    (`AccountingController::postReceiptInterimJournal()`) untuk membuat Jurnal seimbang
 *    (Debit: 1130 Stock Valuation / Inventory, Kredit: 1150 Stock Interim Received / Hutang Sementara)
 *    berdasarkan kuantitas `Accepted`.
 *
 * 3. Integrasi Purchase/Inventory -> Accounting (Three-Way Match — SR-06):
 *    Saat Modul Finance menjalankan `AccountingController::validateVendorBill()` / `postVendorBill()`,
 *    sistem otomatis memicu event `accounting.vendor_bill.three_way_match` untuk melakukan cek silang
 *    ke tabel penerimaan barang (`purchase_receipts` & `purchase_order_lines`) di Modul Inventory
 *    melalui `InventoryController::getAcceptedReceiptSummaryForPo()`. Memblokir pembuatan tagihan jika
 *    kuantitas tagihan lebih besar dari kuantitas fisik `Accepted` yang diterima gudang.
 *
 * 4. Integrasi POS -> Inventory & Accounting (Real-Time Checkout — SR-04, SR-13, SR-21):
 *    Saat transaksi keranjang kasir dibayar (`PosController::checkoutPosBasket()`), sistem otomatis
 *    memicu event `pos.basket.checkout` yang mengeksekusi dua aksi sekaligus:
 *    a) Memanggil `InventoryController::deductPhysicalStock($sku, $qty, $lokasi)` di toko terkait (`STORE-PKU` / `STORE-DUM`).
 *    b) Memanggil `AccountingController::postPOSJournal()` di Modul Accounting untuk mencatat
 *       Harga Pokok Penjualan (COGS: DR 5010 / CR 1130) serta Pendapatan POS (DR 1190 / CR 4020 + CR 2030).
 */
class IntegrationEventTrigger
{
    public const EVENT_SALES_ORDER_CONFIRMED            = 'sales.order.confirmed';
    public const EVENT_INVENTORY_RECEIPT_VALIDATED      = 'inventory.receipt.validated';
    public const EVENT_PURCHASE_ACCOUNTING_3WAY_MATCH   = 'accounting.vendor_bill.three_way_match';
    public const EVENT_POS_BASKET_CHECKOUT              = 'pos.basket.checkout';

    /**
     * Daftar listener kustom yang terdaftar per nama event.
     *
     * @var array<string, array<int, callable>>
     */
    private static array $listeners = [];

    /**
     * Jejak eksekusi event lintas modul untuk inspeksi/verifikasi audit & pengujian.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $executionLog = [];

    private static bool $defaultHandlersRegistered = false;

    /**
     * Mendaftarkan default handler untuk ke-4 jalur integrasi lintas modul.
     */
    public static function ensureDefaultHandlers(): void
    {
        if (self::$defaultHandlersRegistered) {
            return;
        }
        self::$defaultHandlersRegistered = true;

        // 1. Handler Sales -> Inventory: Otomatisasi Reservasi Stok
        self::register(self::EVENT_SALES_ORDER_CONFIRMED, static function (array $payload): array {
            $soNumber = (string) ($payload['so_number'] ?? '');
            $lokasi   = (string) ($payload['source_location'] ?? $payload['lokasi'] ?? 'WH-PKU');
            $lines    = (array) ($payload['lines'] ?? []);

            $mutations = [];
            foreach ($lines as $line) {
                $sku = (string) ($line['variant_sku'] ?? $line['sku'] ?? '');
                $qty = (int) ($line['ordered_qty'] ?? $line['qty'] ?? 0);
                if ($sku === '' || $qty <= 0) {
                    continue;
                }

                $res = InventoryController::reserveStock($sku, $qty, $lokasi, $soNumber);
                if (!($res['allowed'] ?? false)) {
                    return $res;
                }
                $mutations[] = $res['mutation'] ?? $res;
            }

            return [
                'allowed'         => true,
                'event'           => self::EVENT_SALES_ORDER_CONFIRMED,
                'source_module'   => 'Sales',
                'target_module'   => 'Inventory',
                'triggered_call'  => 'InventoryController::reserveStock',
                'so_number'       => $soNumber,
                'location_code'   => $lokasi,
                'stock_mutations' => $mutations,
            ];
        });

        // 2. Handler Inventory -> Accounting: Jurnal Penerimaan Barang PO (DR 1130 Inventory / CR 1150 Stock Interim)
        self::register(self::EVENT_INVENTORY_RECEIPT_VALIDATED, static function (array $payload): array {
            return AccountingController::postReceiptInterimJournal($payload);
        });

        // 3. Handler Purchase/Inventory -> Accounting: Validasi Three-Way Match
        self::register(self::EVENT_PURCHASE_ACCOUNTING_3WAY_MATCH, static function (array $payload): array {
            $poNumber     = (string) ($payload['po_number'] ?? '');
            $variantSku   = isset($payload['variant_sku']) ? (string) $payload['variant_sku'] : null;
            $requestedQty = isset($payload['bill_qty']) && $payload['bill_qty'] !== '' ? (int) $payload['bill_qty'] : null;

            return InventoryController::verifyThreeWayMatchForPo($poNumber, $variantSku, $requestedQty);
        });

        // 4. Handler POS -> Inventory & Accounting: Real-Time Deduct Physical Stock + Post POS Journal (COGS & Revenue)
        self::register(self::EVENT_POS_BASKET_CHECKOUT, static function (array $payload): array {
            $receiptNumber = (string) ($payload['receipt_number'] ?? '');
            $sessionNumber = (string) ($payload['session_number'] ?? 'POS-SESSION-0001');
            $locationCode  = (string) ($payload['location_code'] ?? 'STORE-PKU');
            $lines         = (array) ($payload['lines'] ?? []);

            // Aksi 1: Panggil deductPhysicalStock() di Modul Inventory untuk setiap item keranjang
            $stockAfterMap  = [];
            $stockMutations = [];
            foreach ($lines as $line) {
                $sku = (string) ($line['variant_sku'] ?? '');
                $qty = (int) ($line['qty'] ?? 0);
                if ($sku === '' || $qty <= 0) {
                    continue;
                }

                $deductRes = InventoryController::deductPhysicalStock($sku, $qty, $locationCode, $receiptNumber);
                if (!($deductRes['allowed'] ?? false)) {
                    return $deductRes;
                }
                $stockAfterMap[$sku] = (int) ($deductRes['on_hand_after'] ?? 0);
                $stockMutations[]    = $deductRes;
            }

            // Aksi 2: Panggil postPOSJournal() di Modul Accounting untuk mencatat COGS serta Pendapatan
            $journalRes = AccountingController::postPOSJournal([
                'receipt_number' => $receiptNumber,
                'session_number' => $sessionNumber,
                'location_code'  => $locationCode,
                'subtotal_net'   => (float) ($payload['subtotal_net'] ?? 0.0),
                'tax_amount'     => (float) ($payload['tax_amount'] ?? 0.0),
                'total_gross'    => (float) ($payload['total_gross'] ?? 0.0),
                'total_cogs'     => (float) ($payload['total_cogs'] ?? 0.0),
                'payment_method' => (string) ($payload['payment_method'] ?? 'Cash'),
                'partner_id'     => (string) ($payload['partner_id'] ?? 'C005'),
            ]);

            if (!($journalRes['allowed'] ?? false)) {
                return $journalRes;
            }

            return [
                'allowed'               => true,
                'event'                 => self::EVENT_POS_BASKET_CHECKOUT,
                'source_module'         => 'Point of Sale',
                'target_modules'        => ['Inventory', 'Accounting'],
                'triggered_calls'       => [
                    'InventoryController::deductPhysicalStock',
                    'AccountingController::postPOSJournal',
                ],
                'receipt_number'        => $receiptNumber,
                'session_number'        => $sessionNumber,
                'location_code'         => $locationCode,
                'stock_after'           => $stockAfterMap,
                'stock_mutations'       => $stockMutations,
                'journal_entry_number'  => (string) ($journalRes['journal_entry_number'] ?? ''),
                'cogs_je_number'        => (string) ($journalRes['journal_entry_number'] ?? ''),
                'revenue_recorded'      => (float) ($journalRes['subtotal_net'] ?? 0.0),
                'cogs_recorded'         => (float) ($journalRes['total_cogs'] ?? 0.0),
                'journal_detail'        => $journalRes,
            ];
        });
    }

    /**
     * Mendaftarkan listener pada sebuah event.
     */
    public static function register(string $eventName, callable $listener): void
    {
        self::$listeners[$eventName][] = $listener;
    }

    /**
     * Memicu (dispatch) event lintas modul dan mencatat jejak eksekusinya.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function dispatch(string $eventName, array $payload = []): array
    {
        self::ensureDefaultHandlers();

        $handlers = self::$listeners[$eventName] ?? [];
        $result   = [
            'allowed' => true,
            'event'   => $eventName,
        ];

        foreach ($handlers as $handler) {
            $res = $handler($payload);
            if (is_array($res)) {
                $result = array_merge($result, $res);
                if (isset($res['allowed']) && $res['allowed'] === false) {
                    break;
                }
            }
        }

        self::$executionLog[] = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:sP'),
            'event'     => $eventName,
            'allowed'   => (bool) ($result['allowed'] ?? false),
            'result'    => $result,
        ];

        return $result;
    }

    /**
     * Jalur Integrasi 1: Sales -> Inventory (Otomatisasi Reservasi saat confirmSalesOrder).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function triggerSalesToInventoryReservation(
        string $soNumber,
        array $lines,
        string $sourceLocation = 'WH-PKU'
    ): array {
        return self::dispatch(self::EVENT_SALES_ORDER_CONFIRMED, [
            'so_number'       => $soNumber,
            'lines'           => $lines,
            'source_location' => $sourceLocation,
        ]);
    }

    /**
     * Jalur Integrasi 2: Inventory -> Accounting (Jurnal Penerimaan saat PO Receipt di-klik Validate).
     *
     * @param array<string, mixed> $receiptPayload
     * @return array<string, mixed>
     */
    public static function triggerInventoryToAccountingReceiptJournal(array $receiptPayload): array
    {
        return self::dispatch(self::EVENT_INVENTORY_RECEIPT_VALIDATED, $receiptPayload);
    }

    /**
     * Jalur Integrasi 3: Purchase/Inventory -> Accounting (Cek Silang Three-Way Match saat validateVendorBill).
     *
     * @return array<string, mixed>
     */
    public static function triggerPurchaseToAccountingThreeWayMatch(
        string $poNumber,
        ?string $variantSku = null,
        ?int $requestedBillQty = null
    ): array {
        return self::dispatch(self::EVENT_PURCHASE_ACCOUNTING_3WAY_MATCH, [
            'po_number'   => $poNumber,
            'variant_sku' => $variantSku,
            'bill_qty'    => $requestedBillQty,
        ]);
    }

    /**
     * Jalur Integrasi 4: POS -> Inventory & Accounting (Deduct Physical Stock + Post POS Journal saat Checkout).
     *
     * @param array<string, mixed> $checkoutPayload
     * @return array<string, mixed>
     */
    public static function triggerPosToInventoryAndAccountingCheckout(array $checkoutPayload): array
    {
        return self::dispatch(self::EVENT_POS_BASKET_CHECKOUT, $checkoutPayload);
    }

    /**
     * Mengambil riwayat eksekusi event trigger lintas modul.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getExecutionLog(?string $eventFilter = null): array
    {
        if ($eventFilter === null || $eventFilter === '') {
            return self::$executionLog;
        }
        return array_values(array_filter(
            self::$executionLog,
            static fn(array $entry): bool => ($entry['event'] ?? '') === $eventFilter
        ));
    }

    /**
     * Mengosongkan riwayat eksekusi event trigger di memori.
     */
    public static function clearExecutionLog(): void
    {
        self::$executionLog = [];
    }
}

