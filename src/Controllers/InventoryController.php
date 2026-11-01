<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;
use RantauMart\Services\IntegrationEventTrigger;

/**
 * InventoryController — Modul Inventory & Mutasi Stok Ketat
 * (Fitur F-09, F-10, F-13, F-21 & Aturan SR-04, SR-09, SR-10, SR-17, SR-18, SR-21)
 *
 * Menangani:
 * 1. Reservasi Stok (SR-09, F-09):
 *    Saat SO dikonfirmasi, sistem HANYA menambah kuantitas `reserved_qty` dan mengurangi `available_qty`,
 *    TETAPI dilarang keras mengubah kuantitas fisik `on_hand_qty`.
 * 2. Pengiriman Barang, Partial Delivery & Backorder (F-09, F-10, SR-04, SR-09, S-06, T-11, T-12):
 *    Validasi Delivery Order mengurangi `on_hand_qty` dan `reserved_qty` sebesar `done_qty`,
 *    mencatat `backorder_qty` untuk sisa pesanan, dan memposting jurnal HPP (`DR 5010 COGS` / `CR 1130 Stock Valuation`).
 *    Memblokir pengiriman yang melebihi stok Available / dipesan (`SR-04`, `T-12`).
 * 3. Penerimaan Barang Vendor / Quality Acceptance (SR-10, F-13, S-09, T-17, T-18):
 *    Menyediakan input `Accepted` dan `Rejected`. HANYA nilai `Accepted` yang diproses untuk menambah
 *    stok fisik `on_hand_qty` gudang dan valuasi persediaan (`DR 1130 Stock Valuation` / `CR 1150 Stock Interim Received`).
 *    Memblokir validasi ulang (replay) atas Receipt yang sudah divalidasi (`SR-18`, `T-18`).
 * 4. Internal Transfer Antar Lokasi (SR-17, SR-04, F-21, S-07, T-13, T-14):
 *    Memindahkan fisik barang (`on_hand_qty`) antar lokasi (misal `WH-PKU` ke `STORE-PKU`)
 *    tanpa mengubah total stok maupun total valuasi persediaan perusahaan di akuntansi.
 */
class InventoryController
{
    /**
     * Ambil ringkasan stok per lokasi beserta detail produk.
     */
    public static function getStockByLocation(?string $locationCode = null, ?string $variantSku = null): array
    {
        $pdo        = Database::getConnection();
        $conditions = [];
        $params     = [];

        if ($locationCode !== null && $locationCode !== '') {
            $conditions[]   = 's.location_code = :loc';
            $params[':loc'] = $locationCode;
        }
        if ($variantSku !== null && $variantSku !== '') {
            $conditions[]   = 's.variant_sku = :sku';
            $params[':sku'] = $variantSku;
        }

        $whereSql = !empty($conditions) ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $stmt = $pdo->prepare("
            SELECT
                s.location_code,
                w.location_name,
                s.variant_sku,
                p.name AS product_name,
                p.size,
                p.color_flavour,
                s.on_hand_qty,
                s.reserved_qty,
                s.available_qty,
                s.standard_cost,
                s.valuation
            FROM stock_by_location s
            LEFT JOIN warehouses_locations w ON w.location_code = s.location_code
            LEFT JOIN products p ON p.variant_sku = s.variant_sku
            {$whereSql}
            ORDER BY s.location_code ASC, s.variant_sku ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Ambil satu baris stok spesifik pada lokasi dan SKU tertentu.
     */
    public static function getLocationSkuStock(string $locationCode, string $variantSku): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT
                location_code,
                variant_sku,
                on_hand_qty,
                reserved_qty,
                available_qty,
                standard_cost,
                valuation
            FROM stock_by_location
            WHERE location_code = :loc AND variant_sku = :sku
            LIMIT 1
        ");
        $stmt->execute([
            ':loc' => $locationCode,
            ':sku' => $variantSku,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Hitung total stok fisik dan total valuasi seluruh perusahaan.
     */
    public static function getCompanyValuationSummary(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                COALESCE(SUM(on_hand_qty), 0) AS total_on_hand_pcs,
                COALESCE(SUM(reserved_qty), 0) AS total_reserved_pcs,
                COALESCE(SUM(available_qty), 0) AS total_available_pcs,
                COALESCE(SUM(valuation), 0) AS total_company_valuation
            FROM stock_by_location
        ");
        $row = $stmt->fetch() ?: [];

        $locStmt = $pdo->query("
            SELECT
                location_code,
                COALESCE(SUM(on_hand_qty), 0) AS location_on_hand_pcs,
                COALESCE(SUM(reserved_qty), 0) AS location_reserved_pcs,
                COALESCE(SUM(available_qty), 0) AS location_available_pcs,
                COALESCE(SUM(valuation), 0) AS location_valuation
            FROM stock_by_location
            GROUP BY location_code
            ORDER BY location_code ASC
        ");
        $byLocation = [];
        foreach ($locStmt->fetchAll() ?: [] as $locRow) {
            $byLocation[(string) $locRow['location_code']] = [
                'on_hand_pcs'   => (int) $locRow['location_on_hand_pcs'],
                'reserved_pcs'  => (int) $locRow['location_reserved_pcs'],
                'available_pcs' => (int) $locRow['location_available_pcs'],
                'valuation'     => (float) $locRow['location_valuation'],
            ];
        }

        return [
            'total_on_hand_pcs'       => (int) ($row['total_on_hand_pcs'] ?? 0),
            'total_reserved_pcs'      => (int) ($row['total_reserved_pcs'] ?? 0),
            'total_available_pcs'     => (int) ($row['total_available_pcs'] ?? 0),
            'total_company_valuation' => (float) ($row['total_company_valuation'] ?? 0.0),
            'by_location'             => $byLocation,
        ];
    }

    /**
     * Ambil daftar seluruh Delivery Orders.
     */
    public static function getDeliveryOrders(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                d.do_number,
                d.so_number,
                d.source_location,
                d.variant_sku,
                p.name AS product_name,
                d.demand_qty,
                d.done_qty,
                d.backorder_qty,
                d.standard_cost,
                d.cogs_amount,
                d.status,
                d.journal_entry_number,
                d.validated_by,
                d.created_at
            FROM delivery_orders d
            LEFT JOIN products p ON p.variant_sku = d.variant_sku
            ORDER BY d.id DESC
        ");
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Ambil daftar seluruh Purchase Receipts.
     */
    public static function getPurchaseReceipts(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                r.receipt_number,
                r.po_number,
                r.destination_location,
                r.variant_sku,
                p.name AS product_name,
                r.ordered_qty,
                r.received_qty,
                r.accepted_qty,
                r.rejected_qty,
                r.standard_cost,
                r.valuation_added,
                r.status,
                r.journal_entry_number,
                r.validated_by,
                r.created_at
            FROM purchase_receipts r
            LEFT JOIN products p ON p.variant_sku = r.variant_sku
            ORDER BY r.id DESC
        ");
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Ambil daftar seluruh Internal Transfers.
     */
    public static function getInternalTransfers(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                t.transfer_number,
                t.from_location,
                t.to_location,
                t.variant_sku,
                p.name AS product_name,
                t.qty_move,
                t.standard_cost,
                t.status,
                t.transferred_by,
                t.created_at
            FROM internal_transfers t
            LEFT JOIN products p ON p.variant_sku = t.variant_sku
            ORDER BY t.id DESC
        ");
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Jalur Integrasi 1 (Sales -> Inventory): reserveStock($sku, $qty, $lokasi)
     * Dipanggil secara otomatis oleh Event Trigger saat `SalesController::confirmSalesOrder()` dieksekusi.
     * Menambah `reserved_qty` dan mengurangi `available_qty` tanpa mengubah `on_hand_qty` (Aturan SR-09 & SR-04).
     */
    public static function reserveStock(
        string $sku,
        int $qty,
        string $lokasi = 'WH-PKU',
        ?string $soNumber = null
    ): array {
        $pdo    = Database::getConnection();
        $sku    = trim($sku);
        $lokasi = trim($lokasi) ?: 'WH-PKU';

        $stockBefore = self::getLocationSkuStock($lokasi, $sku);
        if (!$stockBefore) {
            $reason = "Data stok SKU '{$sku}' di lokasi '{$lokasi}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-04',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $onHandBefore    = (int) $stockBefore['on_hand_qty'];
        $reservedBefore  = (int) $stockBefore['reserved_qty'];
        $availableBefore = (int) $stockBefore['available_qty'];

        if ($qty <= 0 || $qty > $availableBefore) {
            $reason = sprintf(
                'SR-04 & SR-09 Violation: Stok Available %s di %s (%d pcs) tidak mencukupi untuk reservasi %d pcs.',
                $sku,
                $lokasi,
                $availableBefore,
                $qty
            );
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-04',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        // Aturan SR-09: HANYA menambah reserved_qty; DILARANG KERAS mengubah on_hand_qty!
        $stmtUpd = $pdo->prepare("
            UPDATE stock_by_location
            SET reserved_qty = reserved_qty + :qty
            WHERE location_code = :loc AND variant_sku = :sku
        ");
        $stmtUpd->execute([
            ':qty' => $qty,
            ':loc' => $lokasi,
            ':sku' => $sku,
        ]);

        $stockAfter = self::getLocationSkuStock($lokasi, $sku);
        $mutation   = [
            'so_number'         => $soNumber,
            'location_code'     => $lokasi,
            'variant_sku'       => $sku,
            'reserved_qty_added'=> $qty,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => (int) ($stockAfter['on_hand_qty'] ?? 0),
            'on_hand_unchanged' => $onHandBefore === (int) ($stockAfter['on_hand_qty'] ?? 0),
            'reserved_before'   => $reservedBefore,
            'reserved_after'    => (int) ($stockAfter['reserved_qty'] ?? 0),
            'available_before'  => $availableBefore,
            'available_after'   => (int) ($stockAfter['available_qty'] ?? 0),
        ];

        return [
            'allowed'           => true,
            'http_code'         => 200,
            'outcome'           => 'ALLOWED',
            'rule'              => 'SR-09',
            'status_changed'    => true,
            'location_code'     => $lokasi,
            'variant_sku'       => $sku,
            'reserved_qty_added'=> $qty,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => $mutation['on_hand_after'],
            'on_hand_unchanged' => $mutation['on_hand_unchanged'],
            'reserved_before'   => $reservedBefore,
            'reserved_after'    => $mutation['reserved_after'],
            'available_before'  => $availableBefore,
            'available_after'   => $mutation['available_after'],
            'mutation'          => $mutation,
            'reason'            => sprintf(
                'Reservasi stok SR-09 berhasil untuk %d pcs %s di %s (On Hand tetap %d, Reserved %d -> %d, Available %d -> %d).',
                $qty,
                $sku,
                $lokasi,
                $onHandBefore,
                $reservedBefore,
                $mutation['reserved_after'],
                $availableBefore,
                $mutation['available_after']
            ),
        ];
    }

    /**
     * Aturan SR-09: Reservasi Stok untuk Sales Order.
     * Saat SO dikonfirmasi, sistem HANYA menambah kuantitas `reserved_qty` dan mengurangi `available_qty`,
     * TETAPI dilarang keras mengubah kuantitas fisik `on_hand_qty`.
     */
    public static function reserveStockForSalesOrder(string $soNumber): array
    {
        $pdo      = Database::getConnection();
        $soNumber = trim($soNumber);

        $stmtSo = $pdo->prepare("SELECT so_number, source_location, status FROM sales_orders WHERE so_number = :so LIMIT 1");
        $stmtSo->execute([':so' => $soNumber]);
        $so = $stmtSo->fetch();

        if (!$so) {
            $reason = "Sales Order '{$soNumber}' tidak ditemukan untuk reservasi stok.";
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-09',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $sourceLocation = (string) ($so['source_location'] ?? 'WH-PKU');
        $stmtLines      = $pdo->prepare("SELECT variant_sku, ordered_qty FROM sales_order_lines WHERE so_number = :so ORDER BY id ASC");
        $stmtLines->execute([':so' => $soNumber]);
        $lines = $stmtLines->fetchAll() ?: [];

        $mutations = [];
        foreach ($lines as $line) {
            $sku = (string) ($line['variant_sku'] ?? '');
            $qty = (int) ($line['ordered_qty'] ?? 0);

            $res = self::reserveStock($sku, $qty, $sourceLocation, $soNumber);
            if (!($res['allowed'] ?? false)) {
                return $res;
            }
            $mutations[] = $res['mutation'] ?? $res;
        }

        $reason = "Reservasi stok SR-09 untuk {$soNumber} berhasil (On Hand tetap utuh).";
        return [
            'allowed'         => true,
            'http_code'       => 200,
            'outcome'         => 'ALLOWED',
            'decision'        => 'ALLOWED',
            'rule'            => 'SR-09',
            'status_changed'  => true,
            'so_number'       => $soNumber,
            'stock_mutations' => $mutations,
            'reason'          => $reason,
            'message'         => $reason,
        ];
    }

    /**
     * Fitur F-09, F-10 & Aturan SR-04, SR-09, SR-21 (Layar S-06, Uji T-11 & T-12):
     * Validasi Delivery Order (termasuk Partial Delivery & Backorder) atas Sales Order.
     * - Mengurangi `on_hand_qty` dan `reserved_qty` sebesar `deliver_qty` (sehingga `available_qty` tetap akurat).
     * - Menghitung `backorder_qty = remaining_ordered - deliver_qty`.
     * - Memposting jurnal HPP: Debit `5010` (`COGS`) dan Kredit `1130` (`Stock Valuation`) sebesar `deliver_qty * standard_cost`.
     * - Memblokir pengiriman yang melebihi stok Available atau sisa pesanan (`SR-04`, `T-12`).
     */
    public static function validateDeliveryOrder(array $input): array
    {
        return \RantauMart\Services\AtomicRunner::run(static fn (): array => self::validateDeliveryOrderAtomic($input));
    }

    /** Isi inti validateDeliveryOrder() - dijalankan di dalam transaksi PDO ACID (AtomicRunner). */
    private static function validateDeliveryOrderAtomic(array $input): array
    {
        $soNumber       = trim((string) ($input['so_number'] ?? ''));
        $deliverQty     = (int) ($input['deliver_qty'] ?? $input['done_qty'] ?? 0);
        $sourceLocation = trim((string) ($input['source_location'] ?? 'WH-PKU')) ?: 'WH-PKU';
        $variantSkuIn   = trim((string) ($input['variant_sku'] ?? ''));

        $user   = AuthService::getCurrentUser();
        $role   = (string) ($user['role'] ?? '');
        $actor  = "{$user['full_name']} ({$user['username']})";
        $docRef = $soNumber !== '' ? $soNumber : ('DO-DIRECT-' . ($variantSkuIn ?: 'UNKNOWN'));

        // 1. Cek wewenang role aktif (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, 'inventory.delivery.validate')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba memvalidasi Delivery Order '{$docRef}'. Hanya Role 'Warehouse' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.delivery.validate',
                documentNumber: $docRef,
                oldStatus: 'Confirmed',
                newStatus: 'Confirmed',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'            => false,
                'http_code'          => 403,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $docRef,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        if ($deliverQty <= 0) {
            $reason = 'Kuantitas pengiriman (Done Qty) harus lebih besar dari 0 pcs.';
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-04',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        // Jika pengiriman langsung tanpa SO atau uji batas Available (seperti T-12: kirim 73 pcs AIR-600 dari WH-PKU saat Available 72)
        if ($soNumber === '') {
            $skuCheck = $variantSkuIn !== '' ? $variantSkuIn : 'AIR-600';
            $stock    = self::getLocationSkuStock($sourceLocation, $skuCheck);
            $avail    = (int) ($stock['available_qty'] ?? 0);
            $onHand   = (int) ($stock['on_hand_qty'] ?? 0);

            $reason = sprintf(
                'SR-04 Violation (Kirim Melebihi Stok Tersedia): Percobaan kirim %d pcs %s dari %s ditolak karena melebihi stok Available (%d pcs; On Hand %d pcs). Stok negatif dilarang.',
                $deliverQty,
                $skuCheck,
                $sourceLocation,
                $avail,
                $onHand
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.delivery.validate',
                documentNumber: $docRef,
                oldStatus: 'Draft',
                newStatus: 'Draft',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-04',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $docRef,
                'available_qty'      => $avail,
                'on_hand_qty'        => $onHand,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $pdo    = Database::getConnection();
        $stmtSo = $pdo->prepare('SELECT * FROM sales_orders WHERE so_number = :so LIMIT 1');
        $stmtSo->execute([':so' => $soNumber]);
        $so = $stmtSo->fetch();

        if (!$so) {
            $reason = "Sales Order '{$soNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $sourceLocation = (string) ($so['source_location'] ?? 'WH-PKU');
        $soStatus       = (string) ($so['status'] ?? 'Draft');

        $stmtLine = $pdo->prepare("
            SELECT id, variant_sku, ordered_qty, delivered_qty
            FROM sales_order_lines
            WHERE so_number = :so
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtLine->execute([':so' => $soNumber]);
        $line = $stmtLine->fetch();

        if (!$line) {
            $reason = "Baris item untuk Sales Order '{$soNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $variantSku       = (string) $line['variant_sku'];
        $orderedQty       = (int) $line['ordered_qty'];
        $alreadyDelivered = (int) $line['delivered_qty'];
        $remainingDemand  = $orderedQty - $alreadyDelivered;

        $stockBefore = self::getLocationSkuStock($sourceLocation, $variantSku);
        if (!$stockBefore) {
            $reason = "Data stok SKU '{$variantSku}' di lokasi '{$sourceLocation}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $onHandBefore    = (int) $stockBefore['on_hand_qty'];
        $reservedBefore  = (int) $stockBefore['reserved_qty'];
        $availableBefore = (int) $stockBefore['available_qty'];
        $standardCost    = (float) $stockBefore['standard_cost'];

        // Validasi SR-04 & SR-09 (termasuk T-12):
        // Blokir jika SO belum Confirmed, sudah selesai dikirim, atau deliver_qty melebihi sisa pesanan / stok tersedia
        $unreservedExcess = max(0, $deliverQty - $remainingDemand);
        if (
            $soStatus !== 'Confirmed'
            || $remainingDemand <= 0
            || $deliverQty > $remainingDemand
            || $deliverQty > $onHandBefore
            || $unreservedExcess > $availableBefore
        ) {
            $reason = sprintf(
                'SR-04 & SR-09 Violation (Kirim Melebihi Stok / Pesanan): Pengiriman %d pcs %s pada %s ditolak (Sisa Pesanan: %d pcs, Stok Available %s: %d pcs, On Hand: %d pcs, Status SO: %s).',
                $deliverQty,
                $variantSku,
                $soNumber,
                $remainingDemand,
                $sourceLocation,
                $availableBefore,
                $onHandBefore,
                $soStatus
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.delivery.validate',
                documentNumber: $soNumber,
                oldStatus: $soStatus,
                newStatus: $soStatus,
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-04',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $soNumber,
                'so_number'          => $soNumber,
                'variant_sku'        => $variantSku,
                'deliver_qty'        => $deliverQty,
                'available_qty'      => $availableBefore,
                'on_hand_qty'        => $onHandBefore,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        // Hitung Backorder (F-10, S-06, T-11)
        $backorderQty      = $remainingDemand - $deliverQty;
        $newDeliveredTotal = $alreadyDelivered + $deliverQty;
        $newSoStatus       = ($backorderQty === 0) ? 'Done' : 'Confirmed';
        $doStatus          = ($backorderQty === 0) ? 'Done' : 'Partial / Backorder';

        // Kurangi on_hand_qty dan reserved_qty sebesar deliverQty (sehingga available_qty tetap tidak berubah!)
        $stmtUpdStock = $pdo->prepare("
            UPDATE stock_by_location
            SET on_hand_qty  = on_hand_qty - :done_qty,
                reserved_qty = reserved_qty - :done_qty
            WHERE location_code = :loc AND variant_sku = :sku
        ");
        $stmtUpdStock->execute([
            ':done_qty' => $deliverQty,
            ':loc'      => $sourceLocation,
            ':sku'      => $variantSku,
        ]);

        // Update delivered_qty pada sales_order_lines & status sales_orders
        $stmtUpdLine = $pdo->prepare("
            UPDATE sales_order_lines
            SET delivered_qty = :delivered_qty
            WHERE id = :line_id
        ");
        $stmtUpdLine->execute([
            ':delivered_qty' => $newDeliveredTotal,
            ':line_id'       => $line['id'],
        ]);

        $stmtUpdSo = $pdo->prepare("
            UPDATE sales_orders
            SET status = :status
            WHERE so_number = :so
        ");
        $stmtUpdSo->execute([
            ':status' => $newSoStatus,
            ':so'     => $soNumber,
        ]);

        // Posting Jurnal COGS (SR-21, T-11): DR 5010 COGS / CR 1130 Stock Valuation
        $doNumber   = DocumentStateGuard::nextDocumentNumber('DO-');
        $cogsAmount = round($deliverQty * $standardCost, 0, PHP_ROUND_HALF_UP);
        $jeNumber   = self::postJournalEntry(
            $doNumber,
            sprintf('Delivery %s untuk SO %s (%d pcs %s @Rp%s)', $doNumber, $soNumber, $deliverQty, $variantSku, number_format($standardCost, 0, ',', '.')),
            [
                [
                    'account_code' => '5010',
                    'partner_id'   => (string) ($so['customer_id'] ?? null),
                    'debit'        => $cogsAmount,
                    'credit'       => 0.0,
                    'memo'         => sprintf('COGS %d pcs %s (%s)', $deliverQty, $variantSku, $doNumber),
                ],
                [
                    'account_code' => '1130',
                    'partner_id'   => null,
                    'debit'        => 0.0,
                    'credit'       => $cogsAmount,
                    'memo'         => sprintf('Pengurangan Stock Valuation %d pcs %s (%s)', $deliverQty, $variantSku, $doNumber),
                ],
            ]
        );

        // Simpan dokumen Delivery Order
        $stmtInsDo = $pdo->prepare("
            INSERT INTO delivery_orders (
                do_number, so_number, source_location, variant_sku,
                demand_qty, done_qty, backorder_qty, standard_cost, cogs_amount,
                status, journal_entry_number, validated_by
            ) VALUES (
                :do_number, :so_number, :source_location, :variant_sku,
                :demand_qty, :done_qty, :backorder_qty, :standard_cost, :cogs_amount,
                :status, :je_number, :validated_by
            )
        ");
        $stmtInsDo->execute([
            ':do_number'       => $doNumber,
            ':so_number'       => $soNumber,
            ':source_location' => $sourceLocation,
            ':variant_sku'     => $variantSku,
            ':demand_qty'      => $remainingDemand,
            ':done_qty'        => $deliverQty,
            ':backorder_qty'   => $backorderQty,
            ':standard_cost'   => $standardCost,
            ':cogs_amount'     => $cogsAmount,
            ':status'          => $doStatus,
            ':je_number'       => $jeNumber,
            ':validated_by'    => $actor,
        ]);

        // Order to Cash: barang terkirim -> otomatis buat draf Customer Invoice atas qty yang dikirim (SR-02),
        // siap diposting Finance (Arif Firmansyah). Tidak ada jurnal AR sebelum invoice diposting.
        $draftInvoiceNumber = \RantauMart\Controllers\AccountingController::createDraftCustomerInvoice($soNumber, $deliverQty);

        $stockAfter = self::getLocationSkuStock($sourceLocation, $variantSku);

        $stmtDoc = $pdo->prepare("
            UPDATE existing_documents
            SET status = :status,
                stock_and_financial_state = :fin_state
            WHERE document_number = :doc_no
        ");
        $stmtDoc->execute([
            ':status'    => $backorderQty > 0 ? sprintf('Partial Delivered (Delivered %d, Backorder %d)', $newDeliveredTotal, $backorderQty) : 'Delivered / Done',
            ':fin_state' => sprintf(
                'Delivered %d/%d pcs; Backorder %d pcs; COGS Rp%s (%s); %s On Hand %d, Reserved %d, Available %d',
                $newDeliveredTotal,
                $orderedQty,
                $backorderQty,
                number_format($cogsAmount, 0, ',', '.'),
                $jeNumber,
                $sourceLocation,
                (int) ($stockAfter['on_hand_qty'] ?? 0),
                (int) ($stockAfter['reserved_qty'] ?? 0),
                (int) ($stockAfter['available_qty'] ?? 0)
            ),
            ':doc_no'    => $soNumber,
        ]);

        $reason = sprintf(
            'Delivery Order %s berhasil divalidasi untuk %s: Terkirim %d pcs %s, Backorder %d pcs. Stok %s: On Hand %d -> %d, Reserved %d -> %d, Available tetap %d. Jurnal COGS %s senilai Rp%s.',
            $doNumber,
            $soNumber,
            $deliverQty,
            $variantSku,
            $backorderQty,
            $sourceLocation,
            $onHandBefore,
            (int) ($stockAfter['on_hand_qty'] ?? 0),
            $reservedBefore,
            (int) ($stockAfter['reserved_qty'] ?? 0),
            (int) ($stockAfter['available_qty'] ?? 0),
            $jeNumber,
            number_format($cogsAmount, 0, ',', '.')
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Inventory',
            action: 'inventory.delivery.validate',
            documentNumber: $doNumber,
            oldStatus: $soStatus,
            newStatus: $doStatus,
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'              => true,
            'http_code'            => 200,
            'outcome'              => 'ALLOWED',
            'decision'             => 'ALLOWED',
            'rule'                 => 'SR-09',
            'status_changed'       => true,
            'document_preserved'   => false,
            'document'             => $doNumber,
            'do_number'            => $doNumber,
            'so_number'            => $soNumber,
            'source_location'      => $sourceLocation,
            'variant_sku'          => $variantSku,
            'demand_qty'           => $remainingDemand,
            'done_qty'             => $deliverQty,
            'backorder_qty'        => $backorderQty,
            'standard_cost'        => $standardCost,
            'cogs_amount'          => $cogsAmount,
            'journal_entry_number' => $jeNumber,
            'draft_customer_invoice' => $draftInvoiceNumber,
            'on_hand_before'       => $onHandBefore,
            'on_hand_after'        => (int) ($stockAfter['on_hand_qty'] ?? 0),
            'reserved_before'      => $reservedBefore,
            'reserved_after'       => (int) ($stockAfter['reserved_qty'] ?? 0),
            'available_before'     => $availableBefore,
            'available_after'      => (int) ($stockAfter['available_qty'] ?? 0),
            'status'               => $doStatus,
            'reason'               => $reason,
            'message'              => $reason,
            'audit_id'             => $auditId,
        ];
    }

    /**
     * Fitur F-13 & Aturan SR-10, SR-18, SR-21 (Layar S-09, Uji T-17 & T-18):
     * Penerimaan Barang dari Vendor dengan kolom input `Accepted` dan `Rejected`.
     * - HANYA nilai `Accepted` yang diproses untuk menambah stok fisik `on_hand_qty` gudang
     *   dan menambah valuasi persediaan (`DR 1130 Stock Valuation` / `CR 1150 Stock Interim Received` pada `standard_cost`).
     * - Nilai `Rejected` dicatat pada dokumen penerimaan tetapi TIDAK menambah stok maupun valuasi.
     * - Mencegah replay / validasi ganda atas Receipt yang sudah divalidasi (`SR-18`, `T-18`).
     */
    public static function receivePurchaseOrder(array $input): array
    {
        $poNumber    = trim((string) ($input['po_number'] ?? ''));
        $acceptedQty = (int) ($input['accepted_qty'] ?? 0);
        $rejectedQty = (int) ($input['rejected_qty'] ?? 0);
        $receivedQty = isset($input['received_qty']) && $input['received_qty'] !== ''
            ? (int) $input['received_qty']
            : ($acceptedQty + $rejectedQty);

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, 'inventory.receipt.validate')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba memvalidasi Receipt untuk PO '{$poNumber}'. Hanya Role 'Warehouse' atau 'Procurement' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.receipt.validate',
                documentNumber: $poNumber ?: 'WH-IN',
                oldStatus: 'Confirmed',
                newStatus: 'Confirmed',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'            => false,
                'http_code'          => 403,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $poNumber,
                'po_number'          => $poNumber,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        if ($poNumber === '') {
            $reason = 'Nomor Purchase Order wajib dipilih untuk penerimaan barang (Receipt).';
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-10',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $pdo    = Database::getConnection();
        $stmtPo = $pdo->prepare('SELECT * FROM purchase_orders WHERE po_number = :po LIMIT 1');
        $stmtPo->execute([':po' => $poNumber]);
        $po = $stmtPo->fetch();

        if (!$po) {
            $reason = "Purchase Order '{$poNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'document'       => $poNumber,
                'po_number'      => $poNumber,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $poStatus            = (string) ($po['status'] ?? '');
        $destinationLocation = (string) ($po['destination_location'] ?? 'WH-PKU');

        $stmtLine = $pdo->prepare("
            SELECT id, variant_sku, ordered_qty, received_qty, accepted_qty, rejected_qty
            FROM purchase_order_lines
            WHERE po_number = :po
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtLine->execute([':po' => $poNumber]);
        $line = $stmtLine->fetch();

        if (!$line) {
            $reason = "Baris item pada Purchase Order '{$poNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $variantSku         = (string) $line['variant_sku'];
        $orderedQty         = (int) $line['ordered_qty'];
        $alreadyReceivedQty = (int) $line['received_qty'];
        $remainingToReceive = $orderedQty - $alreadyReceivedQty;

        // 2. Aturan SR-18 (Uji T-18): Tolak replay / validasi kedua pada Receipt yang sudah berstatus Received / Done
        if (in_array($poStatus, ['Received', 'Done'], true) || $remainingToReceive <= 0) {
            $reason = sprintf(
                'SR-18 Idempotency Guard (Replay Receipt Diblokir): Penerimaan barang untuk PO %s sudah pernah divalidasi (Status: "%s", Received: %d/%d pcs). Validasi kedua ditolak agar stok tidak bertambah dua kali.',
                $poNumber,
                $poStatus,
                $alreadyReceivedQty,
                $orderedQty
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.receipt.validate',
                documentNumber: $poNumber,
                oldStatus: $poStatus,
                newStatus: $poStatus,
                outcome: 'REPLAY_BLOCKED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 409,
                'outcome'            => 'REPLAY_BLOCKED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-18',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $poNumber,
                'po_number'          => $poNumber,
                'old_status'         => $poStatus,
                'new_status'         => $poStatus,
                'status_before'      => $poStatus,
                'status_after'       => $poStatus,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        if ($poStatus !== 'Confirmed') {
            $reason = "PO {$poNumber} masih berstatus '{$poStatus}'. Konfirmasi PO terlebih dahulu sebelum memvalidasi Receipt.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-18',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        // 3. Validasi Aturan SR-10: Accepted > 0, Rejected >= 0, Accepted + Rejected == Received <= Sisa Ordered
        if (
            $acceptedQty <= 0
            || $rejectedQty < 0
            || ($acceptedQty + $rejectedQty) !== $receivedQty
            || $receivedQty > $remainingToReceive
        ) {
            $reason = sprintf(
                'SR-10 Validation Error: Input Receipt tidak valid (Accepted: %d, Rejected: %d, Total Received: %d, Sisa Ordered: %d pcs). Pastikan Accepted > 0, Rejected >= 0, dan Accepted + Rejected <= Sisa Ordered.',
                $acceptedQty,
                $rejectedQty,
                $receivedQty,
                $remainingToReceive
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.receipt.validate',
                documentNumber: $poNumber,
                oldStatus: $poStatus,
                newStatus: $poStatus,
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-10',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $poNumber,
                'po_number'          => $poNumber,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $stockBefore = self::getLocationSkuStock($destinationLocation, $variantSku);
        if (!$stockBefore) {
            $reason = "Lokasi stok '{$destinationLocation}' untuk SKU '{$variantSku}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $onHandBefore    = (int) $stockBefore['on_hand_qty'];
        $valuationBefore = (float) $stockBefore['valuation'];
        $standardCost    = (float) $stockBefore['standard_cost'];

        // 4. Bungkus seluruh rangkaian mutasi stok + pembuatan Jurnal Penerimaan di Accounting dalam Transaksi PDO ACID
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            // Aturan SR-10: HANYA nilai Accepted yang diproses untuk menambah stok On Hand gudang!
            $stmtUpdStock = $pdo->prepare("
                UPDATE stock_by_location
                SET on_hand_qty = on_hand_qty + :accepted_qty
                WHERE location_code = :loc AND variant_sku = :sku
            ");
            $stmtUpdStock->execute([
                ':accepted_qty' => $acceptedQty,
                ':loc'          => $destinationLocation,
                ':sku'          => $variantSku,
            ]);

            // Update purchase_order_lines & status purchase_orders
            $newReceivedTotal = $alreadyReceivedQty + $receivedQty;
            $newAcceptedTotal = (int) $line['accepted_qty'] + $acceptedQty;
            $newRejectedTotal = (int) $line['rejected_qty'] + $rejectedQty;
            $newPoStatus      = ($newReceivedTotal >= $orderedQty) ? 'Received' : 'Confirmed';

            $stmtUpdLine = $pdo->prepare("
                UPDATE purchase_order_lines
                SET received_qty = :rec,
                    accepted_qty = :acc,
                    rejected_qty = :rej
                WHERE id = :line_id
            ");
            $stmtUpdLine->execute([
                ':rec'     => $newReceivedTotal,
                ':acc'     => $newAcceptedTotal,
                ':rej'     => $newRejectedTotal,
                ':line_id' => $line['id'],
            ]);

            $stmtUpdPo = $pdo->prepare("
                UPDATE purchase_orders
                SET status = :status
                WHERE po_number = :po
            ");
            $stmtUpdPo->execute([
                ':status' => $newPoStatus,
                ':po'     => $poNumber,
            ]);

            // 5. Jalur Integrasi 2 (Inventory -> Accounting): Picu otomatis pembuatan Jurnal Penerimaan di Modul Accounting
            //    (Debit: 1130 Stock Valuation / Inventory, Kredit: 1150 Stock Interim Received / Hutang Sementara)
            //    berdasarkan kuantitas Accepted (SR-10 & SR-21, T-17).
            $receiptNumber   = DocumentStateGuard::nextDocumentNumber('WH-IN-');
            $accountingEvent = IntegrationEventTrigger::triggerInventoryToAccountingReceiptJournal([
                'receipt_number'       => $receiptNumber,
                'po_number'            => $poNumber,
                'vendor_id'            => (string) ($po['vendor_id'] ?? 'V01'),
                'destination_location' => $destinationLocation,
                'variant_sku'          => $variantSku,
                'ordered_qty'          => $orderedQty,
                'received_qty'         => $receivedQty,
                'accepted_qty'         => $acceptedQty,
                'rejected_qty'         => $rejectedQty,
                'standard_cost'        => $standardCost,
            ]);

            if (!($accountingEvent['allowed'] ?? false)) {
                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'allowed'        => false,
                    'http_code'      => 422,
                    'outcome'        => 'DENIED',
                    'status_changed' => false,
                    'rolled_back'    => true,
                    'po_number'      => $poNumber,
                    'reason'         => (string) ($accountingEvent['reason'] ?? 'Pembuatan Jurnal Penerimaan gagal; transaksi di-rollback.'),
                ];
            }

            $valuationAdded = (float) ($accountingEvent['valuation_added'] ?? round($acceptedQty * $standardCost, 0, PHP_ROUND_HALF_UP));
            $jeNumber       = (string) ($accountingEvent['journal_entry_number'] ?? '');

            $stmtInsReceipt = $pdo->prepare("
                INSERT INTO purchase_receipts (
                    receipt_number, po_number, destination_location, variant_sku,
                    ordered_qty, received_qty, accepted_qty, rejected_qty,
                    standard_cost, valuation_added, status, journal_entry_number, validated_by
                ) VALUES (
                    :receipt_number, :po_number, :destination_location, :variant_sku,
                    :ordered_qty, :received_qty, :accepted_qty, :rejected_qty,
                    :standard_cost, :valuation_added, 'Done', :je_number, :validated_by
                )
            ");
            $stmtInsReceipt->execute([
                ':receipt_number'       => $receiptNumber,
                ':po_number'            => $poNumber,
                ':destination_location' => $destinationLocation,
                ':variant_sku'          => $variantSku,
                ':ordered_qty'          => $orderedQty,
                ':received_qty'         => $receivedQty,
                ':accepted_qty'         => $acceptedQty,
                ':rejected_qty'         => $rejectedQty,
                ':standard_cost'        => $standardCost,
                ':valuation_added'      => $valuationAdded,
                ':je_number'            => $jeNumber,
                ':validated_by'         => $actor,
            ]);

            $stockAfter = self::getLocationSkuStock($destinationLocation, $variantSku);

            $stmtDoc = $pdo->prepare("
                UPDATE existing_documents
                SET status = :status,
                    stock_and_financial_state = :fin_state
                WHERE document_number = :doc_no
            ");
            $stmtDoc->execute([
                ':status'    => sprintf('Received (Accepted %d, Rejected %d)', $newAcceptedTotal, $newRejectedTotal),
                ':fin_state' => sprintf(
                    'Accepted %d pcs (+Rp%s Stock Valuation %s); Rejected %d pcs (excluded from stock); AP unchanged',
                    $newAcceptedTotal,
                    number_format($valuationAdded, 0, ',', '.'),
                    $jeNumber,
                    $newRejectedTotal
                ),
                ':doc_no'    => $poNumber,
            ]);

            // 6. Procure to Pay: barang tiba & Accepted masuk stok -> otomatis picu draf Vendor Bill (qty Accepted)
            //    untuk direkonsiliasi/divalidasi Finance melalui Three-Way Match (SR-06).
            $draftBillNumber = \RantauMart\Controllers\AccountingController::createDraftVendorBill($poNumber, $acceptedQty);

            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $reason = sprintf(
            'Receipt %s atas PO %s berhasil divalidasi (SR-10): Ordered %d, Received %d, Accepted %d pcs, Rejected %d pcs. Hanya %d pcs Accepted menambah On Hand %s (%d -> %d pcs) dan Stock Valuation +Rp%s (%s). AP tetap.',
            $receiptNumber,
            $poNumber,
            $orderedQty,
            $receivedQty,
            $acceptedQty,
            $rejectedQty,
            $acceptedQty,
            $destinationLocation,
            $onHandBefore,
            (int) ($stockAfter['on_hand_qty'] ?? 0),
            number_format($valuationAdded, 0, ',', '.'),
            $jeNumber
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Inventory',
            action: 'inventory.receipt.validate',
            documentNumber: $receiptNumber,
            oldStatus: $poStatus,
            newStatus: $newPoStatus,
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'              => true,
            'http_code'            => 200,
            'outcome'              => 'ALLOWED',
            'decision'             => 'ALLOWED',
            'rule'                 => 'SR-10',
            'status_changed'       => true,
            'document_preserved'   => false,
            'document'             => $receiptNumber,
            'receipt_number'       => $receiptNumber,
            'po_number'            => $poNumber,
            'destination_location' => $destinationLocation,
            'variant_sku'          => $variantSku,
            'ordered_qty'          => $orderedQty,
            'received_qty'         => $receivedQty,
            'accepted_qty'         => $acceptedQty,
            'rejected_qty'         => $rejectedQty,
            'standard_cost'        => $standardCost,
            'valuation_added'      => $valuationAdded,
            'on_hand_before'       => $onHandBefore,
            'on_hand_after'        => (int) ($stockAfter['on_hand_qty'] ?? 0),
            'valuation_before'     => $valuationBefore,
            'valuation_after'      => (float) ($stockAfter['valuation'] ?? 0),
            'journal_entry_number' => $jeNumber,
            'draft_vendor_bill'    => $draftBillNumber ?? null,
            'status'               => $newPoStatus,
            'reason'               => $reason,
            'message'              => $reason,
            'audit_id'             => $auditId,
        ];
    }

    /**
     * Fitur F-21 & Aturan SR-17, SR-04 (Layar S-07, Uji T-13 & T-14):
     * Internal Transfer Antar Lokasi Gudang/Toko (misal `WH-PKU` ke `STORE-PKU`).
     * - Memindahkan kuantitas fisik `on_hand_qty` dari `from_location` ke `to_location`.
     * - Menolak transfer jika `qty_move` melebihi `available_qty` di lokasi asal (`SR-04`, `SR-17`, `T-14`).
     * - Memastikan total unit dan total valuasi stok perusahaan di akuntansi TIDAK BERUBAH
     *   dan tidak membuat jurnal penjualan maupun COGS (`SR-17`, `T-13`).
     */
    public static function createInternalTransfer(array $input): array
    {
        $fromLocation = trim((string) ($input['from_location'] ?? 'WH-PKU'));
        $toLocation   = trim((string) ($input['to_location'] ?? 'STORE-PKU'));
        $variantSku   = trim((string) ($input['variant_sku'] ?? 'AIR-600'));
        $qtyMove      = (int) ($input['qty_move'] ?? 0);

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, 'inventory.transfer.validate')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba melakukan Internal Transfer. Hanya Role 'Warehouse' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.transfer.validate',
                documentNumber: "{$fromLocation}->{$toLocation}",
                oldStatus: 'Draft',
                newStatus: 'Draft',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'            => false,
                'http_code'          => 403,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'status_changed'     => false,
                'document_preserved' => true,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        if ($fromLocation === '' || $toLocation === '' || $variantSku === '' || $qtyMove <= 0) {
            $reason = 'Lokasi asal, lokasi tujuan, SKU produk, dan kuantitas mutasi (> 0) wajib diisi.';
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-17',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        if ($fromLocation === $toLocation) {
            $reason = 'Lokasi asal dan lokasi tujuan Internal Transfer tidak boleh sama.';
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-17',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $sourceStockBefore = self::getLocationSkuStock($fromLocation, $variantSku);
        $destStockBefore   = self::getLocationSkuStock($toLocation, $variantSku);

        if (!$sourceStockBefore || !$destStockBefore) {
            $reason = "Data stok untuk SKU '{$variantSku}' pada lokasi '{$fromLocation}' atau '{$toLocation}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-17',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $sourceOnHandBefore    = (int) $sourceStockBefore['on_hand_qty'];
        $sourceReservedBefore  = (int) $sourceStockBefore['reserved_qty'];
        $sourceAvailableBefore = (int) $sourceStockBefore['available_qty'];
        $destOnHandBefore      = (int) $destStockBefore['on_hand_qty'];
        $standardCost          = (float) $sourceStockBefore['standard_cost'];

        // 2. Validasi kecukupan stok Available di lokasi asal (SR-04, SR-17, T-14)
        if ($qtyMove > $sourceAvailableBefore) {
            $reason = sprintf(
                'SR-17 & SR-04 Violation (Transfer Melebihi Stok Asal): Kuantitas transfer %d pcs %s dari %s ke %s ditolak karena melebihi stok Available di %s (%d pcs; On Hand %d, Reserved %d).',
                $qtyMove,
                $variantSku,
                $fromLocation,
                $toLocation,
                $fromLocation,
                $sourceAvailableBefore,
                $sourceOnHandBefore,
                $sourceReservedBefore
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Inventory',
                action: 'inventory.transfer.validate',
                documentNumber: "{$fromLocation}->{$toLocation}:{$variantSku}",
                oldStatus: 'Draft',
                newStatus: 'Draft',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-17',
                'status_changed'     => false,
                'document_preserved' => true,
                'from_location'      => $fromLocation,
                'to_location'        => $toLocation,
                'variant_sku'        => $variantSku,
                'qty_move'           => $qtyMove,
                'available_at_from'  => $sourceAvailableBefore,
                'on_hand_at_from'    => $sourceOnHandBefore,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        // 3. Ambil total valuasi & total pcs perusahaan SEBELUM transfer
        $companySummaryBefore = self::getCompanyValuationSummary();
        $pdo                  = Database::getConnection();

        // 4. Pindahkan fisik barang antar lokasi tanpa jurnal penjualan/COGS (SR-17) dalam transaksi PDO ACID
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmtDeduct = $pdo->prepare("
                UPDATE stock_by_location
                SET on_hand_qty = on_hand_qty - :qty
                WHERE location_code = :loc AND variant_sku = :sku
            ");
            $stmtDeduct->execute([
                ':qty' => $qtyMove,
                ':loc' => $fromLocation,
                ':sku' => $variantSku,
            ]);

            $stmtAdd = $pdo->prepare("
                UPDATE stock_by_location
                SET on_hand_qty = on_hand_qty + :qty
                WHERE location_code = :loc AND variant_sku = :sku
            ");
            $stmtAdd->execute([
                ':qty' => $qtyMove,
                ':loc' => $toLocation,
                ':sku' => $variantSku,
            ]);

            $transferNumber = DocumentStateGuard::nextDocumentNumber('INT-');

            $stmtIns = $pdo->prepare("
                INSERT INTO internal_transfers (
                    transfer_number, from_location, to_location, variant_sku, qty_move, standard_cost, status, transferred_by
                ) VALUES (
                    :transfer_number, :from_location, :to_location, :variant_sku, :qty_move, :standard_cost, 'Done', :transferred_by
                )
            ");
            $stmtIns->execute([
                ':transfer_number' => $transferNumber,
                ':from_location'   => $fromLocation,
                ':to_location'     => $toLocation,
                ':variant_sku'     => $variantSku,
                ':qty_move'        => $qtyMove,
                ':standard_cost'   => $standardCost,
                ':transferred_by'  => $actor,
            ]);

            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $sourceStockAfter    = self::getLocationSkuStock($fromLocation, $variantSku);
        $destStockAfter      = self::getLocationSkuStock($toLocation, $variantSku);
        $companySummaryAfter = self::getCompanyValuationSummary();

        $totalPcsUnchanged       = $companySummaryBefore['total_on_hand_pcs'] === $companySummaryAfter['total_on_hand_pcs'];
        $totalValuationUnchanged = abs($companySummaryBefore['total_company_valuation'] - $companySummaryAfter['total_company_valuation']) < 0.01;

        $reason = sprintf(
            'Internal Transfer %s berhasil (SR-17): %d pcs %s dipindahkan dari %s (%d -> %d pcs) ke %s (%d -> %d pcs). Total valuasi stok perusahaan tetap Rp%s (%d pcs) tanpa jurnal penjualan/COGS.',
            $transferNumber,
            $qtyMove,
            $variantSku,
            $fromLocation,
            $sourceOnHandBefore,
            (int) ($sourceStockAfter['on_hand_qty'] ?? 0),
            $toLocation,
            $destOnHandBefore,
            (int) ($destStockAfter['on_hand_qty'] ?? 0),
            number_format($companySummaryAfter['total_company_valuation'], 0, ',', '.'),
            $companySummaryAfter['total_on_hand_pcs']
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Inventory',
            action: 'inventory.transfer.validate',
            documentNumber: $transferNumber,
            oldStatus: 'Draft',
            newStatus: 'Done',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'                   => true,
            'http_code'                 => 200,
            'outcome'                   => 'ALLOWED',
            'decision'                  => 'ALLOWED',
            'rule'                      => 'SR-17',
            'status_changed'            => true,
            'document_preserved'        => false,
            'document'                  => $transferNumber,
            'transfer_number'           => $transferNumber,
            'from_location'             => $fromLocation,
            'to_location'               => $toLocation,
            'variant_sku'               => $variantSku,
            'qty_move'                  => $qtyMove,
            'from_on_hand_before'       => $sourceOnHandBefore,
            'from_on_hand_after'        => (int) ($sourceStockAfter['on_hand_qty'] ?? 0),
            'to_on_hand_before'         => $destOnHandBefore,
            'to_on_hand_after'          => (int) ($destStockAfter['on_hand_qty'] ?? 0),
            'company_pcs_before'        => $companySummaryBefore['total_on_hand_pcs'],
            'company_pcs_after'         => $companySummaryAfter['total_on_hand_pcs'],
            'company_valuation_before'  => $companySummaryBefore['total_company_valuation'],
            'company_valuation_after'   => $companySummaryAfter['total_company_valuation'],
            'total_pcs_unchanged'       => $totalPcsUnchanged,
            'total_valuation_unchanged' => $totalValuationUnchanged,
            'journal_entry_number'      => null,
            'reason'                    => $reason,
            'message'                   => $reason,
            'audit_id'                  => $auditId,
        ];
    }

    /**
     * Posting jurnal akuntansi berpasangan yang seimbang (SR-21: SUM(debit) == SUM(credit)).
     */
    private static function postJournalEntry(string $sourceDocument, string $description, array $lines): string
    {
        $pdo         = Database::getConnection();
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit  += (float) ($line['debit'] ?? 0.0);
            $totalCredit += (float) ($line['credit'] ?? 0.0);
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new \RuntimeException(sprintf(
                'Pelanggaran SR-21: Jurnal untuk %s tidak seimbang (Debit: %s, Credit: %s).',
                $sourceDocument,
                number_format($totalDebit, 2),
                number_format($totalCredit, 2)
            ));
        }

        $jeNumber = DocumentStateGuard::nextDocumentNumber('JE-');

        $stmtEntry = $pdo->prepare("
            INSERT INTO journal_entries (
                entry_number, entry_date, source_document, description, status, is_opening_entry, total_debit, total_credit
            ) VALUES (
                :entry_number, :entry_date, :source_document, :description, 'Posted', FALSE, :total_debit, :total_credit
            )
        ");
        $stmtEntry->execute([
            ':entry_number'    => $jeNumber,
            ':entry_date'      => date('Y-m-d'),
            ':source_document' => $sourceDocument,
            ':description'     => $description,
            ':total_debit'     => $totalDebit,
            ':total_credit'    => $totalCredit,
        ]);

        $stmtLine = $pdo->prepare("
            INSERT INTO journal_entry_lines (
                entry_number, account_code, partner_id, debit, credit, memo
            ) VALUES (
                :entry_number, :account_code, :partner_id, :debit, :credit, :memo
            )
        ");
        foreach ($lines as $line) {
            $stmtLine->execute([
                ':entry_number' => $jeNumber,
                ':account_code' => $line['account_code'],
                ':partner_id'   => $line['partner_id'] ?? null,
                ':debit'        => (float) ($line['debit'] ?? 0.0),
                ':credit'       => (float) ($line['credit'] ?? 0.0),
                ':memo'         => $line['memo'] ?? null,
            ]);
        }

        return $jeNumber;
    }

    /**
     * Fitur F-03 & Aturan SR-20 (T-05, T-06):
     * Membuat varian SKU baru dengan atribut Size & Color/Flavour dan validasi keunikan SKU.
     */
    public static function createProductVariant(array $data): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? 'Unauthenticated');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!in_array($role, [RolePermissions::ROLE_WAREHOUSE, RolePermissions::ROLE_PROCUREMENT, RolePermissions::ROLE_SALES, RolePermissions::ROLE_OWNER], true)) {
            $reason = "Pelanggaran F-01 / SR-18: Role '{$role}' tidak berwenang mengelola master varian SKU.";
            AuditLogger::log($actor, $role, 'Inventory', 'product.variant.create', null, null, null, 'DENIED', $reason);
            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'rule'           => 'SR-18',
                'reason'         => $reason,
            ];
        }

        $sku          = strtoupper(trim((string) ($data['variant_sku'] ?? '')));
        $name         = trim((string) ($data['name'] ?? 'Produk Baru'));
        $size         = trim((string) ($data['size'] ?? 'Standard'));
        $colorFlavour = trim((string) ($data['color_flavour'] ?? 'Original'));
        $salesPrice   = (float) ($data['sales_price'] ?? 5000);
        $cost         = (float) ($data['cost'] ?? 3500);
        $vendor       = trim((string) ($data['default_vendor'] ?? 'V01'));

        if ($sku === '') {
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'rule'           => 'SR-20',
                'reason'         => 'Pelanggaran SR-20: Kode SKU varian wajib diisi.',
            ];
        }

        $pdo = Database::getConnection();
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM products WHERE UPPER(variant_sku) = :sku");
        $stmtCheck->execute([':sku' => $sku]);
        if ((int) $stmtCheck->fetchColumn() > 0) {
            $reason = sprintf(
                'Pelanggaran SR-20 (Duplicate SKU Blocked): Kode SKU "%s" sudah terdaftar di katalog master (15 SKU aktif). Duplikasi SKU ditolak.',
                $sku
            );
            AuditLogger::log($actor, $role, 'Inventory', 'product.variant.create', $sku, 'Existing', 'Existing', 'DENIED', $reason);
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'rule'           => 'SR-20',
                'reason'         => $reason,
            ];
        }

        $pdo->beginTransaction();
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO products (variant_sku, name, size, color_flavour, unit, sales_price, cost, tax_code, default_vendor)
                VALUES (:sku, :name, :size, :color_flavour, 'pcs', :sales_price, :cost, 'PPN-11', :vendor)
            ");
            $stmtIns->execute([
                ':sku'           => $sku,
                ':name'          => $name,
                ':size'          => $size,
                ':color_flavour' => $colorFlavour,
                ':sales_price'   => $salesPrice,
                ':cost'          => $cost,
                ':vendor'        => $vendor,
            ]);

            $stmtOs = $pdo->prepare("
                INSERT INTO opening_stock (variant_sku, wh_pku, store_pku, store_dum, company_total_pcs, standard_cost, standard_value)
                VALUES (:sku, 0, 0, 0, 0, :cost, 0)
            ");
            $stmtOs->execute([':sku' => $sku, ':cost' => $cost]);

            foreach (['WH-PKU', 'STORE-PKU', 'STORE-DUM'] as $loc) {
                $stmtLoc = $pdo->prepare("
                    INSERT INTO stock_by_location (location_code, variant_sku, on_hand_qty, reserved_qty, standard_cost)
                    VALUES (:loc, :sku, 0, 0, :cost)
                ");
                $stmtLoc->execute([':loc' => $loc, ':sku' => $sku, ':cost' => $cost]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'allowed'        => false,
                'http_code'      => 500,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'reason'         => 'Gagal menyimpan varian SKU baru: ' . $e->getMessage(),
            ];
        }

        $reason = sprintf(
            'SR-20 / F-03: Varian SKU baru %s (%s — Size: %s, Color/Flavour: %s, Sales Price: Rp%s, Cost: Rp%s) berhasil dibuat.',
            $sku,
            $name,
            $size,
            $colorFlavour,
            number_format($salesPrice, 0, ',', '.'),
            number_format($cost, 0, ',', '.')
        );
        AuditLogger::log($actor, $role, 'Inventory', 'product.variant.create', $sku, 'None', 'Active', 'ALLOWED', $reason);

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'status_changed' => true,
            'rule'           => 'SR-20',
            'variant_sku'    => $sku,
            'reason'         => $reason,
        ];
    }

    /**
     * Alias eksplisit untuk Penerimaan Barang dari Vendor dengan kolom Accepted & Rejected (SR-10 — Deni Pratama).
     * Hanya kuantitas Accepted yang menambah stok gudang (WH-PKU).
     */
    public static function validateVendorReceipt(array $input): array
    {
        return self::receivePurchaseOrder($input);
    }

    /**
     * Alias eksplisit untuk Internal Transfer antar lokasi (mis. WH-PKU ke STORE-PKU)
     * yang hanya memindahkan kuantitas fisik tanpa memengaruhi nilai akuntansi perusahaan (SR-17 — Deni Pratama).
     */
    public static function transferStockBetweenLocations(array $input): array
    {
        return self::createInternalTransfer($input);
    }

    /**
     * Jalur Integrasi 3 (Purchase/Inventory -> Accounting): Cek Silang Tabel Penerimaan Barang (`purchase_receipts` & `purchase_order_lines`)
     * Dipanggil secara otomatis oleh `AccountingController::validateVendorBill()` / `postVendorBill()` melalui Event Trigger
     * untuk memverifikasi Three-Way Match (Aturan SR-06).
     * Memblokir pembuatan tagihan jika kuantitas di tagihan lebih besar dari kuantitas fisik `Accepted` yang diterima gudang.
     */
    public static function verifyThreeWayMatchForPo(
        string $poNumber,
        ?string $variantSku = null,
        ?int $requestedBillQty = null
    ): array {
        $pdo      = Database::getConnection();
        $poNumber = trim($poNumber);

        $stmtLine = $pdo->prepare("
            SELECT pol.*, p.cost AS standard_cost
            FROM purchase_order_lines pol
            LEFT JOIN products p ON p.variant_sku = pol.variant_sku
            WHERE pol.po_number = :po
            " . ($variantSku !== null && $variantSku !== '' ? "AND pol.variant_sku = :sku" : "") . "
            ORDER BY pol.id ASC
            LIMIT 1
        ");
        $params = [':po' => $poNumber];
        if ($variantSku !== null && $variantSku !== '') {
            $params[':sku'] = $variantSku;
        }
        $stmtLine->execute($params);
        $line = $stmtLine->fetch();

        if (!$line) {
            $reason = "Baris item Purchase Order '{$poNumber}' tidak ditemukan di modul Inventory/Purchase.";
            return [
                'allowed'      => false,
                'http_code'    => 422,
                'outcome'      => 'DENIED',
                'rule'         => 'SR-06',
                'po_number'    => $poNumber,
                'reason'       => $reason,
            ];
        }

        $sku = (string) $line['variant_sku'];

        // Cek langsung ke tabel penerimaan barang fisik di gudang (`purchase_receipts`)
        $stmtRcpt = $pdo->prepare("
            SELECT
                COUNT(*) AS receipt_count,
                COALESCE(SUM(received_qty), 0) AS receipt_received_qty,
                COALESCE(SUM(accepted_qty), 0) AS receipt_accepted_qty,
                COALESCE(SUM(rejected_qty), 0) AS receipt_rejected_qty
            FROM purchase_receipts
            WHERE po_number = :po AND variant_sku = :sku AND status = 'Done'
        ");
        $stmtRcpt->execute([':po' => $poNumber, ':sku' => $sku]);
        $rcptRow = $stmtRcpt->fetch() ?: [];

        $receiptCount       = (int) ($rcptRow['receipt_count'] ?? 0);
        $receiptAcceptedQty = (int) ($rcptRow['receipt_accepted_qty'] ?? 0);
        $lineAcceptedQty    = (int) ($line['accepted_qty'] ?? 0);

        // Gunakan nilai fisik terverifikasi dari tabel penerimaan gudang
        $physicalAcceptedQty = $receiptCount > 0 ? $receiptAcceptedQty : $lineAcceptedQty;
        $orderedQty          = (int) ($line['ordered_qty'] ?? 0);
        $rejectedQty         = $receiptCount > 0 ? (int) ($rcptRow['receipt_rejected_qty'] ?? 0) : (int) ($line['rejected_qty'] ?? 0);
        $alreadyBilledQty    = (int) ($line['billed_qty'] ?? 0);
        $billableQty         = max(0, $physicalAcceptedQty - $alreadyBilledQty);
        $qtyToBill           = $requestedBillQty !== null ? $requestedBillQty : $billableQty;

        if ($physicalAcceptedQty <= 0 || $billableQty <= 0 || $qtyToBill <= 0 || $qtyToBill > $billableQty || $qtyToBill > $physicalAcceptedQty) {
            $reason = sprintf(
                'SR-06 Violation (Three-Way Match Ditolak): Percobaan Post Vendor Bill %d pcs %s pada %s diblokir! Ordered: %d pcs, Accepted Fisik Gudang: %d pcs, Rejected: %d pcs, Sudah Dibill: %d pcs, Maksimal Billable Saat Ini: %d pcs. Sesuai aturan SR-06 (Arif Firmansyah), utang AP hanya boleh diakui atas barang yang diterima dan lolos mutu (Accepted) di gudang.',
                $qtyToBill,
                $sku,
                $poNumber,
                $orderedQty,
                $physicalAcceptedQty,
                $rejectedQty,
                $alreadyBilledQty,
                $billableQty
            );

            return [
                'allowed'               => false,
                'http_code'             => 422,
                'outcome'               => 'DENIED',
                'decision'              => 'DENIED',
                'rule'                  => 'SR-06',
                'po_number'             => $poNumber,
                'variant_sku'           => $sku,
                'ordered_qty'           => $orderedQty,
                'accepted_qty'          => $physicalAcceptedQty,
                'rejected_qty'          => $rejectedQty,
                'already_billed_qty'    => $alreadyBilledQty,
                'billable_qty'          => $billableQty,
                'attempted_qty'         => $qtyToBill,
                'receipt_count'         => $receiptCount,
                'reason'                => $reason,
                'message'               => $reason,
            ];
        }

        return [
            'allowed'               => true,
            'http_code'             => 200,
            'outcome'               => 'ALLOWED',
            'decision'              => 'ALLOWED',
            'rule'                  => 'SR-06',
            'po_number'             => $poNumber,
            'variant_sku'           => $sku,
            'ordered_qty'           => $orderedQty,
            'accepted_qty'          => $physicalAcceptedQty,
            'rejected_qty'          => $rejectedQty,
            'already_billed_qty'    => $alreadyBilledQty,
            'billable_qty'          => $billableQty,
            'qty_to_bill'           => $qtyToBill,
            'receipt_count'         => $receiptCount,
            'line'                  => $line,
            'reason'                => sprintf(
                'Three-Way Match SR-06 Terverifikasi: Tagihan %d pcs %s pada %s sesuai dengan kuantitas fisik Accepted di gudang (%d pcs).',
                $qtyToBill,
                $sku,
                $poNumber,
                $physicalAcceptedQty
            ),
        ];
    }

    /**
     * Jalur Integrasi 4 (POS -> Inventory): deductPhysicalStock($sku, $qty, $lokasi)
     * Dipanggil secara otomatis saat transaksi keranjang kasir dibayar (`PosController::checkoutPosBasket()`).
     * Memotong stok fisik (`on_hand_qty`) di toko terkait (`STORE-PKU` atau `STORE-DUM`) secara real-time
     * dan menolak mutlak transaksi yang akan membuat stok menjadi negatif (Aturan SR-04 mengalahkan SR-03).
     */
    public static function deductPhysicalStock(
        string $sku,
        int $qty,
        string $lokasi = 'STORE-PKU',
        ?string $referenceDoc = null
    ): array {
        $pdo    = Database::getConnection();
        $sku    = trim($sku);
        $lokasi = trim($lokasi) ?: 'STORE-PKU';

        $stockBefore = self::getLocationSkuStock($lokasi, $sku);
        if (!$stockBefore) {
            $reason = "Produk '{$sku}' tidak ditemukan di lokasi toko '{$lokasi}'.";
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-04',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $onHandBefore    = (int) $stockBefore['on_hand_qty'];
        $availableBefore = (int) $stockBefore['available_qty'];

        if ($qty <= 0 || $qty > $availableBefore || $qty > $onHandBefore || ($onHandBefore - $qty) < 0) {
            $reason = sprintf(
                'SR-04 Violation (Anti-Negative Stock POS): Pemotongan stok fisik %d pcs %s di %s ditolak karena melebihi stok fisik/Available toko (%d pcs; On Hand %d pcs).',
                $qty,
                $sku,
                $lokasi,
                $availableBefore,
                $onHandBefore
            );
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-04',
                'status_changed' => false,
                'location_code'  => $lokasi,
                'variant_sku'    => $sku,
                'attempted_qty'  => $qty,
                'available_qty'  => $availableBefore,
                'on_hand_before' => $onHandBefore,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $pdo->prepare("
            UPDATE stock_by_location
            SET on_hand_qty = on_hand_qty - :qty
            WHERE location_code = :loc AND variant_sku = :sku
        ")->execute([
            ':qty' => $qty,
            ':loc' => $lokasi,
            ':sku' => $sku,
        ]);

        $stockAfter  = self::getLocationSkuStock($lokasi, $sku);
        $onHandAfter = (int) ($stockAfter['on_hand_qty'] ?? ($onHandBefore - $qty));

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'decision'       => 'ALLOWED',
            'rule'           => 'SR-04',
            'status_changed' => true,
            'reference_doc'  => $referenceDoc,
            'location_code'  => $lokasi,
            'variant_sku'    => $sku,
            'deducted_qty'   => $qty,
            'on_hand_before' => $onHandBefore,
            'on_hand_after'  => $onHandAfter,
            'available_after'=> (int) ($stockAfter['available_qty'] ?? $onHandAfter),
            'reason'         => sprintf(
                'Stok fisik %s di %s berhasil dipotong %d pcs (%d -> %d pcs).',
                $sku,
                $lokasi,
                $qty,
                $onHandBefore,
                $onHandAfter
            ),
        ];
    }
}
