<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;

/**
 * PurchaseController — Modul Procurement (Fitur F-12 & Aturan SR-20, SR-18, SR-22)
 *
 * Menangani:
 * 1. Pemesanan Vendor & Harga Acuan Otomatis (F-12, SR-20, S-08, T-15):
 *    Saat SKU dan Vendor dipilih pada form Purchase Order (PO), sistem otomatis
 *    mengambil harga beli acuan (`vendor_unit_price`) dari tabel `vendor_pricelist`
 *    (fallback ke `products.cost` jika pasangan tidak ada di `vendor_pricelist`).
 * 2. Konfirmasi PO & Kunci Harga Otomatis (F-12, SR-18, SR-22, T-15, T-16):
 *    Saat PO dikonfirmasi (`RFQ -> Confirmed`), `price_locked` dikunci menjadi TRUE (`read-only`).
 *    Segala percobaan mengubah harga beli setelah status `Confirmed` diblokir dan dicatat ke `audit_logs`.
 *    Konfirmasi PO tidak mengubah saldo AP maupun stok fisik/valuasi gudang.
 */
class PurchaseController
{
    /**
     * Ambil daftar seluruh vendor dari contacts_vendors.
     */
    public static function getVendors(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                id,
                id AS vendor_id,
                name,
                name AS vendor_name,
                city,
                goods,
                goods AS category_focus,
                payment_terms,
                payment_terms AS payment_term,
                opening_ap
            FROM contacts_vendors
            ORDER BY id ASC
        ");
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Ambil baris tabel vendor_pricelist beserta nama vendor dan produk.
     */
    public static function getVendorPricelistRows(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                vp.id,
                vp.vendor_id,
                v.name AS vendor_name,
                vp.variant_sku,
                p.name AS product_name,
                p.cost AS standard_cost,
                vp.vendor_unit_price,
                vp.minimum_qty,
                vp.currency
            FROM vendor_pricelist vp
            LEFT JOIN contacts_vendors v ON v.id = vp.vendor_id
            LEFT JOIN products p ON p.variant_sku = vp.variant_sku
            ORDER BY vp.vendor_id ASC, vp.variant_sku ASC
        ");
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Aturan SR-20: Ambil harga beli acuan otomatis berdasarkan kombinasi Vendor dan SKU.
     * Mencari terlebih dahulu di tabel `vendor_pricelist`. Jika tidak ditemukan untuk pasangan
     * (vendor_id, variant_sku) tersebut, fallback ke `products.cost` (standard cost).
     */
    public static function getVendorUnitPrice(string $vendorId, string $variantSku): array
    {
        $pdo        = Database::getConnection();
        $vendorId   = trim($vendorId);
        $variantSku = trim($variantSku);

        $stmt = $pdo->prepare("
            SELECT vendor_unit_price, currency
            FROM vendor_pricelist
            WHERE vendor_id = :vendor_id AND variant_sku = :variant_sku
            LIMIT 1
        ");
        $stmt->execute([
            ':vendor_id'   => $vendorId,
            ':variant_sku' => $variantSku,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            return [
                'found'             => true,
                'source'            => 'vendor_pricelist',
                'vendor_id'         => $vendorId,
                'variant_sku'       => $variantSku,
                'vendor_unit_price' => (float) ($row['vendor_unit_price'] ?? 0),
                'currency'          => (string) ($row['currency'] ?? 'IDR'),
            ];
        }

        // Fallback ke standard cost di tabel products jika pasangan tidak ada di vendor_pricelist
        $stmtProd = $pdo->prepare("SELECT cost FROM products WHERE variant_sku = :sku LIMIT 1");
        $stmtProd->execute([':sku' => $variantSku]);
        $prod = $stmtProd->fetch();

        if ($prod) {
            return [
                'found'             => true,
                'source'            => 'products.cost',
                'vendor_id'         => $vendorId,
                'variant_sku'       => $variantSku,
                'vendor_unit_price' => (float) ($prod['cost'] ?? 0),
                'currency'          => 'IDR',
            ];
        }

        return [
            'found'             => false,
            'source'            => 'none',
            'vendor_id'         => $vendorId,
            'variant_sku'       => $variantSku,
            'vendor_unit_price' => 0.0,
            'currency'          => 'IDR',
        ];
    }

    /**
     * Buat peta harga beli (vendor_id => [variant_sku => ['price' => ..., 'source' => ...]])
     * untuk digunakan oleh form Purchase Order di frontend agar otomatis mengisi harga
     * saat Vendor & SKU dipilih (SR-20).
     */
    public static function getPricelistLookupMap(): array
    {
        $pdo      = Database::getConnection();
        $vendors  = self::getVendors();
        $products = $pdo->query("SELECT variant_sku, cost FROM products ORDER BY variant_sku ASC")->fetchAll() ?: [];

        $map = [];
        foreach ($vendors as $v) {
            $vid = (string) ($v['id'] ?? '');
            $map[$vid] = [];
            foreach ($products as $p) {
                $sku = (string) ($p['variant_sku'] ?? '');
                $map[$vid][$sku] = [
                    'price'  => (float) ($p['cost'] ?? 0),
                    'source' => 'products.cost',
                ];
            }
        }

        $stmtVp = $pdo->query("SELECT vendor_id, variant_sku, vendor_unit_price FROM vendor_pricelist");
        foreach ($stmtVp->fetchAll() ?: [] as $vp) {
            $vid = (string) ($vp['vendor_id'] ?? '');
            $sku = (string) ($vp['variant_sku'] ?? '');
            $map[$vid][$sku] = [
                'price'  => (float) ($vp['vendor_unit_price'] ?? 0),
                'source' => 'vendor_pricelist',
            ];
        }

        return $map;
    }

    /**
     * Ambil seluruh Purchase Orders beserta baris item dan detail vendor.
     */
    public static function getAllPurchaseOrdersWithLines(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT
                po.po_number,
                po.vendor_id,
                v.name AS vendor_name,
                v.payment_terms,
                po.destination_location,
                po.status,
                po.subtotal_net,
                po.tax_amount,
                po.total_gross,
                po.price_locked
            FROM purchase_orders po
            LEFT JOIN contacts_vendors v ON v.id = po.vendor_id
            ORDER BY po.po_number ASC
        ");
        $orders = $stmt->fetchAll() ?: [];

        $lineStmt = $pdo->prepare("
            SELECT
                pol.id,
                pol.po_number,
                pol.variant_sku,
                p.name AS product_name,
                p.cost AS standard_cost,
                pol.ordered_qty,
                pol.received_qty,
                pol.accepted_qty,
                pol.rejected_qty,
                pol.billed_qty,
                pol.unit_price,
                pol.tax_code,
                pol.line_subtotal,
                pol.line_tax,
                pol.line_gross
            FROM purchase_order_lines pol
            LEFT JOIN products p ON p.variant_sku = pol.variant_sku
            WHERE pol.po_number = :po_number
            ORDER BY pol.id ASC
        ");

        foreach ($orders as &$order) {
            $order['price_locked'] = (bool) ($order['price_locked'] ?? false);
            $lineStmt->execute([':po_number' => $order['po_number'] ?? '']);
            $order['lines'] = $lineStmt->fetchAll() ?: [];
        }
        unset($order);

        return $orders;
    }

    /**
     * Ambil satu Purchase Order beserta baris itemnya.
     */
    public static function getPurchaseOrder(string $poNumber): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT
                po.po_number,
                po.vendor_id,
                v.name AS vendor_name,
                v.payment_terms,
                po.destination_location,
                po.status,
                po.subtotal_net,
                po.tax_amount,
                po.total_gross,
                po.price_locked
            FROM purchase_orders po
            LEFT JOIN contacts_vendors v ON v.id = po.vendor_id
            WHERE po.po_number = :po_number
            LIMIT 1
        ");
        $stmt->execute([':po_number' => $poNumber]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $order['price_locked'] = (bool) ($order['price_locked'] ?? false);

        $lineStmt = $pdo->prepare("
            SELECT
                pol.id,
                pol.po_number,
                pol.variant_sku,
                p.name AS product_name,
                p.cost AS standard_cost,
                pol.ordered_qty,
                pol.received_qty,
                pol.accepted_qty,
                pol.rejected_qty,
                pol.billed_qty,
                pol.unit_price,
                pol.tax_code,
                pol.line_subtotal,
                pol.line_tax,
                pol.line_gross
            FROM purchase_order_lines pol
            LEFT JOIN products p ON p.variant_sku = pol.variant_sku
            WHERE pol.po_number = :po_number
            ORDER BY pol.id ASC
        ");
        $lineStmt->execute([':po_number' => $poNumber]);
        $order['lines'] = $lineStmt->fetchAll() ?: [];

        return $order;
    }

    /**
     * Fitur F-12 & Aturan SR-20 (S-08, T-15):
     * Buat RFQ / Purchase Order baru dengan harga beli acuan otomatis dari tabel `vendor_pricelist`.
     */
    public static function createPurchaseOrder(array $input): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, 'purchase.order.create')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' tidak memiliki wewenang membuat RFQ / Purchase Order. Hanya Role 'Procurement' yang diizinkan.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Purchase',
                action: 'purchase.order.create',
                documentNumber: null,
                oldStatus: 'None',
                newStatus: 'None',
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

        $pdo                 = Database::getConnection();
        $vendorId            = trim((string) ($input['vendor_id'] ?? 'V01'));
        $variantSku          = trim((string) ($input['variant_sku'] ?? 'MIE-GOR-80'));
        $orderedQty          = (int) ($input['ordered_qty'] ?? 0);
        $destinationLocation = trim((string) ($input['destination_location'] ?? 'WH-PKU')) ?: 'WH-PKU';
        $confirmNow          = ((string) ($input['confirm_now'] ?? $input['auto_confirm'] ?? '1')) === '1'
            || ($input['auto_confirm'] ?? false) === true;

        if ($vendorId === '' || $variantSku === '' || $orderedQty <= 0) {
            $reason = 'SR-20 Validation Error: Vendor, SKU produk, dan kuantitas pesan (> 0) wajib diisi pada Purchase Order.';
            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-20',
                'status_changed'     => false,
                'document_preserved' => true,
                'reason'             => $reason,
                'message'            => $reason,
            ];
        }

        $stmtVend = $pdo->prepare('SELECT id, name FROM contacts_vendors WHERE id = :id LIMIT 1');
        $stmtVend->execute([':id' => $vendorId]);
        $vendor = $stmtVend->fetch();
        if (!$vendor) {
            $reason = "Vendor '{$vendorId}' tidak ditemukan di master contacts_vendors.";
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

        $stmtProd = $pdo->prepare('SELECT variant_sku, name, cost, tax_code FROM products WHERE variant_sku = :sku LIMIT 1');
        $stmtProd->execute([':sku' => $variantSku]);
        $product = $stmtProd->fetch();
        if (!$product) {
            $reason = "Produk dengan SKU '{$variantSku}' tidak ditemukan di master products.";
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

        // 2. Aturan SR-20: Otomatis mengambil harga beli acuan dari tabel vendor_pricelist saat SKU & Vendor dipilih!
        $pricelistLookup = self::getVendorUnitPrice($vendorId, $variantSku);
        $referencePrice  = (float) ($pricelistLookup['vendor_unit_price'] ?? 0.0);
        $priceSource     = (string) ($pricelistLookup['source'] ?? 'vendor_pricelist');

        $useCustomPrice = ((string) ($input['use_custom_price'] ?? '0')) === '1' || ($input['use_custom_price'] ?? false) === true;
        $inputUnitPrice = isset($input['unit_price']) && $input['unit_price'] !== '' ? (float) $input['unit_price'] : 0.0;
        $unitPrice      = ($useCustomPrice && $inputUnitPrice > 0) ? $inputUnitPrice : $referencePrice;

        $taxCode      = (string) ($product['tax_code'] ?? 'PPN-11');
        $lineSubtotal = round($orderedQty * $unitPrice, 0, PHP_ROUND_HALF_UP);
        $lineTax      = ($taxCode === 'PPN-11') ? round($lineSubtotal * 0.11, 0, PHP_ROUND_HALF_UP) : 0.0;
        $lineGross    = $lineSubtotal + $lineTax;

        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $poNumber = DocumentStateGuard::nextDocumentNumber('PO');

            $stmtInsertPo = $pdo->prepare("
                INSERT INTO purchase_orders (
                    po_number, vendor_id, destination_location, status, subtotal_net, tax_amount, total_gross, price_locked
                ) VALUES (
                    :po_number, :vendor_id, :destination_location, 'RFQ', :subtotal_net, :tax_amount, :total_gross, FALSE
                )
            ");
            $stmtInsertPo->execute([
                ':po_number'            => $poNumber,
                ':vendor_id'            => $vendorId,
                ':destination_location' => $destinationLocation,
                ':subtotal_net'         => $lineSubtotal,
                ':tax_amount'           => $lineTax,
                ':total_gross'          => $lineGross,
            ]);

            $stmtInsertLine = $pdo->prepare("
                INSERT INTO purchase_order_lines (
                    po_number, variant_sku, ordered_qty, received_qty, accepted_qty, rejected_qty, billed_qty,
                    unit_price, tax_code, line_subtotal, line_tax, line_gross
                ) VALUES (
                    :po_number, :variant_sku, :ordered_qty, 0, 0, 0, 0,
                    :unit_price, :tax_code, :line_subtotal, :line_tax, :line_gross
                )
            ");
            $stmtInsertLine->execute([
                ':po_number'     => $poNumber,
                ':variant_sku'   => $variantSku,
                ':ordered_qty'   => $orderedQty,
                ':unit_price'    => $unitPrice,
                ':tax_code'      => $taxCode,
                ':line_subtotal' => $lineSubtotal,
                ':line_tax'      => $lineTax,
                ':line_gross'    => $lineGross,
            ]);

            $stmtDoc = $pdo->prepare("
                INSERT INTO existing_documents (document_number, document_type, partner_lines, status, stock_and_financial_state)
                VALUES (:doc_no, 'Purchase Order', :partner_lines, 'RFQ / Draft', :fin_state)
            ");
            $stmtDoc->execute([
                ':doc_no'        => $poNumber,
                ':partner_lines' => sprintf('%s; %d %s @%s', $vendorId, $orderedQty, $variantSku, number_format($unitPrice, 0, ',', '.')),
                ':fin_state'     => sprintf('Gross Rp%s; price source %s; no AP or stock yet', number_format($lineGross, 0, ',', '.'), $priceSource),
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

        AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Purchase',
            action: 'purchase.order.create',
            documentNumber: $poNumber,
            oldStatus: 'None',
            newStatus: 'RFQ',
            outcome: 'ALLOWED',
            reason: sprintf(
                'RFQ %s dibuat untuk vendor %s (%d pcs %s @Rp%s dari %s, Subtotal Rp%s, PPN Rp%s, Gross Rp%s).',
                $poNumber,
                $vendorId,
                $orderedQty,
                $variantSku,
                number_format($unitPrice, 0, ',', '.'),
                $priceSource,
                number_format($lineSubtotal, 0, ',', '.'),
                number_format($lineTax, 0, ',', '.'),
                number_format($lineGross, 0, ',', '.')
            )
        );

        if ($confirmNow) {
            $confirmRes = self::confirmPurchaseOrder($poNumber);
            $confirmRes['price_source'] = $priceSource;
            $confirmRes['unit_price']   = $unitPrice;
            $confirmRes['ordered_qty']  = $orderedQty;
            $confirmRes['variant_sku']  = $variantSku;
            return $confirmRes;
        }

        $reason = sprintf(
            'RFQ %s berhasil dibuat untuk %s (%d pcs %s @Rp%s otomatis dari %s, Gross Rp%s).',
            $poNumber,
            $vendor['name'] ?? $vendorId,
            $orderedQty,
            $variantSku,
            number_format($unitPrice, 0, ',', '.'),
            $priceSource,
            number_format($lineGross, 0, ',', '.')
        );

        return [
            'allowed'            => true,
            'http_code'          => 200,
            'outcome'            => 'ALLOWED',
            'decision'           => 'ALLOWED',
            'rule'               => 'SR-20',
            'status_changed'     => true,
            'document'           => $poNumber,
            'po_number'          => $poNumber,
            'vendor_id'          => $vendorId,
            'variant_sku'        => $variantSku,
            'ordered_qty'        => $orderedQty,
            'unit_price'         => $unitPrice,
            'price_source'       => $priceSource,
            'subtotal_net'       => $lineSubtotal,
            'tax_amount'         => $lineTax,
            'total_gross'        => $lineGross,
            'old_status'         => 'None',
            'new_status'         => 'RFQ',
            'status'             => 'RFQ',
            'price_locked'       => false,
            'document_preserved' => false,
            'reason'             => $reason,
            'message'            => $reason,
        ];
    }

    /**
     * Fitur F-12 & Aturan SR-20, SR-18 (S-08, T-15):
     * Konfirmasi Purchase Order (`RFQ -> Confirmed`).
     * Mengunci harga (`price_locked = TRUE`) dan memastikan tidak ada mutasi AP atau Stok pada tahap PO.
     */
    public static function confirmPurchaseOrder(string $poNumber): array
    {
        $poNumber = trim($poNumber);
        $user     = AuthService::getCurrentUser();
        $role     = (string) ($user['role'] ?? '');
        $actor    = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, 'purchase.order.confirm')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba Confirm Purchase Order '{$poNumber}'. Hanya Role 'Procurement' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Purchase',
                action: 'purchase.order.confirm',
                documentNumber: $poNumber,
                oldStatus: 'RFQ',
                newStatus: 'RFQ',
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
                'old_status'         => 'RFQ',
                'new_status'         => 'RFQ',
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $pdo = Database::getConnection();
        $po  = self::getPurchaseOrder($poNumber);
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

        $currentStatus = (string) ($po['status'] ?? 'RFQ');

        // 2. Cek Idempotensi / Anti-Replay (SR-18)
        if ($currentStatus !== 'RFQ') {
            $reason = "SR-18 Idempotency Guard: Purchase Order '{$poNumber}' sudah berstatus '{$currentStatus}'. Replay Confirm ditolak.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Purchase',
                action: 'purchase.order.confirm',
                documentNumber: $poNumber,
                oldStatus: $currentStatus,
                newStatus: $currentStatus,
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
                'old_status'         => $currentStatus,
                'new_status'         => $currentStatus,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        // 3. Kunci harga produk secara otomatis (price_locked = TRUE) saat PO berubah menjadi Confirmed dalam transaksi PDO ACID
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmtUpd = $pdo->prepare("
                UPDATE purchase_orders
                SET status = 'Confirmed', price_locked = TRUE
                WHERE po_number = :po_number
            ");
            $stmtUpd->execute([':po_number' => $poNumber]);

            $stmtDoc = $pdo->prepare("
                UPDATE existing_documents
                SET status = 'Confirmed / not received',
                    stock_and_financial_state = :fin_state
                WHERE document_number = :doc_no
            ");
            $stmtDoc->execute([
                ':fin_state' => sprintf('Gross Rp%s; no AP or stock yet', number_format((float) ($po['total_gross'] ?? 0), 0, ',', '.')),
                ':doc_no'    => $poNumber,
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

        $reason = sprintf(
            'Purchase Order %s berhasil dikonfirmasi (RFQ -> Confirmed). Subtotal Rp%s, PPN 11%% Rp%s, Total Gross Rp%s. Harga beli dikunci otomatis (read-only / SR-20 & SR-18). Tidak ada perubahan AP atau stok fisik.',
            $poNumber,
            number_format((float) ($po['subtotal_net'] ?? 0), 0, ',', '.'),
            number_format((float) ($po['tax_amount'] ?? 0), 0, ',', '.'),
            number_format((float) ($po['total_gross'] ?? 0), 0, ',', '.')
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Purchase',
            action: 'purchase.order.confirm',
            documentNumber: $poNumber,
            oldStatus: $currentStatus,
            newStatus: 'Confirmed',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'            => true,
            'http_code'          => 200,
            'outcome'            => 'ALLOWED',
            'decision'           => 'ALLOWED',
            'rule'               => 'SR-20',
            'status_changed'     => true,
            'document_preserved' => false,
            'document'           => $poNumber,
            'po_number'          => $poNumber,
            'vendor_id'          => $po['vendor_id'] ?? '',
            'old_status'         => $currentStatus,
            'new_status'         => 'Confirmed',
            'status_before'      => $currentStatus,
            'status_after'       => 'Confirmed',
            'status'             => 'Confirmed',
            'price_locked'       => true,
            'subtotal_net'       => (float) ($po['subtotal_net'] ?? 0),
            'tax_amount'         => (float) ($po['tax_amount'] ?? 0),
            'total_gross'        => (float) ($po['total_gross'] ?? 0),
            'reason'             => $reason,
            'message'            => $reason,
            'audit_id'           => $auditId,
        ];
    }

    /**
     * Aturan SR-18 & SR-22 (Uji T-16):
     * Kunci harga produk pada Purchase Order yang sudah berstatus Confirmed (`price_locked = TRUE`).
     * Jika pengguna mencoba mengubah `unit_price` setelah PO Confirmed, blokir dan catat ke `audit_logs`.
     */
    public static function updatePurchaseOrderPrice(string $poNumber, float $newUnitPrice): array
    {
        $poNumber = trim($poNumber);
        $user     = AuthService::getCurrentUser();
        $role     = (string) ($user['role'] ?? '');
        $actor    = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'purchase.order.update_price')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba mengubah harga Purchase Order '{$poNumber}'. Hanya Role 'Procurement' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Purchase',
                action: 'purchase.order.update_price',
                documentNumber: $poNumber,
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

        $pdo = Database::getConnection();
        $po  = self::getPurchaseOrder($poNumber);
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

        $status      = (string) ($po['status'] ?? '');
        $priceLocked = (bool) ($po['price_locked'] ?? false);

        if ($priceLocked || $status !== 'RFQ') {
            $reason = sprintf(
                'SR-18 & SR-22 Violation (Harga PO Terkunci): Harga beli pada PO %s sudah terkunci otomatis (read-only) karena status dokumen telah "%s". Percobaan mengubah harga menjadi Rp%s diblokir.',
                $poNumber,
                $status,
                number_format($newUnitPrice, 0, ',', '.')
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Purchase',
                action: 'purchase.order.update_price',
                documentNumber: $poNumber,
                oldStatus: $status,
                newStatus: $status,
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-18',
                'status_changed'     => false,
                'document_preserved' => true,
                'document'           => $poNumber,
                'po_number'          => $poNumber,
                'old_status'         => $status,
                'new_status'         => $status,
                'status_before'      => $status,
                'status_after'       => $status,
                'price_locked'       => true,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        if ($newUnitPrice <= 0) {
            $reason = 'Harga beli baru harus lebih besar dari Rp0.';
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

        $firstLine   = $po['lines'][0] ?? [];
        $orderedQty  = (int) ($firstLine['ordered_qty'] ?? 0);
        $taxCode     = (string) ($firstLine['tax_code'] ?? 'PPN-11');
        $newSubtotal = round($orderedQty * $newUnitPrice, 0, PHP_ROUND_HALF_UP);
        $newTax      = ($taxCode === 'PPN-11') ? round($newSubtotal * 0.11, 0, PHP_ROUND_HALF_UP) : 0.0;
        $newGross    = $newSubtotal + $newTax;

        $stmtLine = $pdo->prepare("
            UPDATE purchase_order_lines
            SET unit_price = :price, line_subtotal = :sub, line_tax = :tax, line_gross = :gross
            WHERE po_number = :po
        ");
        $stmtLine->execute([
            ':price' => $newUnitPrice,
            ':sub'   => $newSubtotal,
            ':tax'   => $newTax,
            ':gross' => $newGross,
            ':po'    => $poNumber,
        ]);

        $stmtPo = $pdo->prepare("
            UPDATE purchase_orders
            SET subtotal_net = :sub, tax_amount = :tax, total_gross = :gross
            WHERE po_number = :po
        ");
        $stmtPo->execute([
            ':sub'   => $newSubtotal,
            ':tax'   => $newTax,
            ':gross' => $newGross,
            ':po'    => $poNumber,
        ]);

        $reason = sprintf(
            'Harga beli pada RFQ %s berhasil diperbarui menjadi Rp%s (Gross Rp%s).',
            $poNumber,
            number_format($newUnitPrice, 0, ',', '.'),
            number_format($newGross, 0, ',', '.')
        );

        AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Purchase',
            action: 'purchase.order.update_price',
            documentNumber: $poNumber,
            oldStatus: 'RFQ',
            newStatus: 'RFQ',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'            => true,
            'http_code'          => 200,
            'outcome'            => 'ALLOWED',
            'decision'           => 'ALLOWED',
            'status_changed'     => true,
            'document_preserved' => false,
            'document'           => $poNumber,
            'po_number'          => $poNumber,
            'unit_price'         => $newUnitPrice,
            'subtotal_net'       => $newSubtotal,
            'tax_amount'         => $newTax,
            'total_gross'        => $newGross,
            'price_locked'       => false,
            'reason'             => $reason,
            'message'            => $reason,
        ];
    }

    /**
     * Alias eksplisit untuk mengambil harga default otomatis dari vendor_pricelist
     * saat kombinasi Vendor dan SKU dipilih (Aturan SR-20 — Mira Salsabila).
     */
    public static function getVendorPricelistPrice(string $vendorId, string $variantSku): array
    {
        return self::getVendorUnitPrice($vendorId, $variantSku);
    }

    /**
     * Alias eksplisit untuk membuat Request for Quotation (RFQ) dengan harga default otomatis dari vendor_pricelist.
     */
    public static function createRfq(array $input): array
    {
        $input['confirm_now'] = $input['confirm_now'] ?? '0';
        return self::createPurchaseOrder($input);
    }

    /**
     * Alias eksplisit untuk mengonfirmasi RFQ menjadi Purchase Order (RFQ -> Confirmed)
     * sesuai wewenang Mira Salsabila (Manajer Procurement).
     */
    public static function confirmRfqToPurchaseOrder(string $poNumber): array
    {
        return self::confirmPurchaseOrder($poNumber);
    }
}
