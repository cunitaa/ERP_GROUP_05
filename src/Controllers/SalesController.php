<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Middleware\RbacMiddleware;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;
use RantauMart\Services\IntegrationEventTrigger;

/**
 * Controller untuk Modul CRM & Sales (Fitur F-05, F-06, F-07, F-08, F-09)
 * Menegakkan aturan bisnis secara mutlak di sisi backend:
 * - SR-07: Blokir jika diskon > 10%; kunci harga & diskon (read-only) otomatis setelah SO Confirmed.
 * - SR-08: Blokir konfirmasi SO jika Credit Exposure (Unpaid AR + SO Confirmed belum diinvois + SO ini) > Credit Limit.
 * - SR-04 & SR-09: Blokir konfirmasi SO jika Ordered Qty > Available Stock lokasi sumber; buat reservasi stok saat Confirmed.
 * - SR-18 & SR-22: Penomoran unik, tautan Lead -> Opportunity -> SO, dan anti-replay.
 */
class SalesController
{
    /**
     * Menghitung nilai baris pesanan sesuai aturan PPN efektif 11% (PMK 131/2024, pembulatan half-up per baris).
     */
    public static function calculateLineAmounts(int $qty, float $unitPrice, float $discountPct = 0.0): array
    {
        $grossBeforeDiscount = $qty * $unitPrice;
        $discountAmount      = round($grossBeforeDiscount * ($discountPct / 100.0), 0, PHP_ROUND_HALF_UP);
        $subtotalNet         = $grossBeforeDiscount - $discountAmount;
        $taxAmount           = round($subtotalNet * 0.11, 0, PHP_ROUND_HALF_UP);
        $totalGross          = $subtotalNet + $taxAmount;

        return [
            'subtotal_net' => $subtotalNet,
            'tax_amount'   => $taxAmount,
            'total_gross'  => $totalGross,
        ];
    }

    /**
     * Menghitung Credit Exposure pelanggan sesuai SR-08:
     * Credit Exposure = Unpaid AR + Gross Confirmed Sales Orders yang belum diinvois.
     */
    public static function getCustomerCreditExposure(string $customerId, ?string $excludeSoNumber = null): array
    {
        $pdo = Database::getConnection();

        $stmtCust = $pdo->prepare(
            'SELECT id, name, type, city, credit_limit, payment_terms, opening_ar
             FROM contacts_customers
             WHERE id = :id'
        );
        $stmtCust->execute([':id' => $customerId]);
        $customer = $stmtCust->fetch();

        if (!$customer) {
            return [
                'found'                        => false,
                'customer_id'                  => $customerId,
                'customer_name'                => 'Unknown',
                'credit_limit'                 => 0.0,
                'unpaid_ar'                    => 0.0,
                'confirmed_uninvoiced_so_gross'=> 0.0,
                'current_exposure'             => 0.0,
                'remaining_credit'             => 0.0,
            ];
        }

        $creditLimit = (float) ($customer['credit_limit'] ?? 0);
        $unpaidAr    = (float) ($customer['opening_ar'] ?? 0);

        if ($excludeSoNumber !== null && $excludeSoNumber !== '') {
            $stmtSo = $pdo->prepare(
                "SELECT COALESCE(SUM(total_gross), 0)
                 FROM sales_orders
                 WHERE customer_id = :cid
                   AND status = 'Confirmed'
                   AND so_number <> :excl"
            );
            $stmtSo->execute([':cid' => $customerId, ':excl' => $excludeSoNumber]);
        } else {
            $stmtSo = $pdo->prepare(
                "SELECT COALESCE(SUM(total_gross), 0)
                 FROM sales_orders
                 WHERE customer_id = :cid
                   AND status = 'Confirmed'"
            );
            $stmtSo->execute([':cid' => $customerId]);
        }

        $confirmedSoGross = (float) $stmtSo->fetchColumn();
        $currentExposure  = $unpaidAr + $confirmedSoGross;

        return [
            'found'                         => true,
            'customer_id'                   => (string) $customer['id'],
            'customer_name'                 => (string) $customer['name'],
            'payment_terms'                 => (string) $customer['payment_terms'],
            'credit_limit'                  => $creditLimit,
            'unpaid_ar'                     => $unpaidAr,
            'confirmed_uninvoiced_so_gross' => $confirmedSoGross,
            'current_exposure'              => $currentExposure,
            'remaining_credit'              => $creditLimit - $currentExposure,
        ];
    }

    /**
     * Fitur F-05 (CRM Pipeline): Konversi Lead ke Opportunity dengan jejak audit (T-07, T-08, SR-18, SR-22).
     */
    public static function convertLeadToOpportunity(string $leadNumber = 'LEAD-0001'): array
    {
        return DocumentStateGuard::attemptConvertLead($leadNumber);
    }

    /**
     * Fitur F-06, F-07, F-08: Membuat Quotation / Sales Order baru (dan opsional langsung Confirm).
     */
    public static function createSalesOrder(array $input): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01)
        if (!RolePermissions::canPerformAction($role, 'sales.order.create')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' tidak memiliki wewenang membuat Quotation / Sales Order. Hanya Role 'Sales' yang diizinkan.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.create',
                documentNumber: null,
                oldStatus: 'None',
                newStatus: 'None',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        $pdo            = Database::getConnection();
        $customerId     = trim((string) ($input['customer_id'] ?? 'C001'));
        $variantSku     = trim((string) ($input['variant_sku'] ?? 'AIR-600'));
        $orderedQty     = max(1, (int) ($input['ordered_qty'] ?? 1));
        $discountPct    = (float) ($input['discount_pct'] ?? 0.0);
        $sourceLocation = trim((string) ($input['source_location'] ?? 'WH-PKU'));
        $sourceLead     = !empty($input['source_lead']) ? trim((string) $input['source_lead']) : null;
        $confirmNow     = ((string) ($input['confirm_now'] ?? '1')) === '1';

        // Ambil harga jual standar produk jika unit_price tidak dikirim
        $stmtProd = $pdo->prepare('SELECT variant_sku, name, sales_price FROM products WHERE variant_sku = :sku');
        $stmtProd->execute([':sku' => $variantSku]);
        $product = $stmtProd->fetch();
        if (!$product) {
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'reason'         => "Produk dengan SKU '{$variantSku}' tidak ditemukan pada master produk.",
            ];
        }

        $unitPrice = isset($input['unit_price']) && $input['unit_price'] !== ''
            ? (float) $input['unit_price']
            : (float) $product['sales_price'];

        // 2. Validasi Mutlak SR-07: Diskon > 10% ditolak!
        if ($discountPct > 10.0 || $discountPct < 0.0) {
            $reason = sprintf(
                "SR-07 Violation (Diskon Ditolak): Diskon sebesar %s%% pada SKU %s melebihi batas maksimum 10%% yang diizinkan kebijakan perusahaan. Transaksi Sales Order diblokir.",
                number_format($discountPct, 2, ',', '.'),
                $variantSku
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.discount_check',
                documentNumber: 'DRAFT-REJECTED',
                oldStatus: 'Draft',
                newStatus: 'Draft',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => 'DRAFT-REJECTED',
                'old_status'     => 'Draft',
                'new_status'     => 'Draft',
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        $amounts   = self::calculateLineAmounts($orderedQty, $unitPrice, $discountPct);
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $soNumber = DocumentStateGuard::nextDocumentNumber('S');

            // Simpan sebagai Draft terlebih dahulu
            $stmtInsertSo = $pdo->prepare(
                "INSERT INTO sales_orders (
                    so_number, customer_id, source_location, source_lead,
                    status, subtotal_net, tax_amount, total_gross, price_locked
                 ) VALUES (
                    :so_number, :customer_id, :source_location, :source_lead,
                    'Draft', :subtotal_net, :tax_amount, :total_gross, FALSE
                 )"
            );
            $stmtInsertSo->execute([
                ':so_number'       => $soNumber,
                ':customer_id'     => $customerId,
                ':source_location' => $sourceLocation,
                ':source_lead'     => $sourceLead,
                ':subtotal_net'    => $amounts['subtotal_net'],
                ':tax_amount'      => $amounts['tax_amount'],
                ':total_gross'     => $amounts['total_gross'],
            ]);

            $stmtInsertLine = $pdo->prepare(
                "INSERT INTO sales_order_lines (
                    so_number, variant_sku, ordered_qty, delivered_qty, invoiced_qty,
                    unit_price, discount_pct, tax_code, line_subtotal, line_tax, line_gross
                 ) VALUES (
                    :so_number, :variant_sku, :ordered_qty, 0, 0,
                    :unit_price, :discount_pct, 'PPN-11', :line_subtotal, :line_tax, :line_gross
                 )"
            );
            $stmtInsertLine->execute([
                ':so_number'     => $soNumber,
                ':variant_sku'   => $variantSku,
                ':ordered_qty'   => $orderedQty,
                ':unit_price'    => $unitPrice,
                ':discount_pct'  => $discountPct,
                ':line_subtotal' => $amounts['subtotal_net'],
                ':line_tax'      => $amounts['tax_amount'],
                ':line_gross'    => $amounts['total_gross'],
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
            module: 'Sales',
            action: 'sales.order.create',
            documentNumber: $soNumber,
            oldStatus: 'None',
            newStatus: 'Draft',
            outcome: 'ALLOWED',
            reason: sprintf(
                "Quotation %s dibuat untuk pelanggan %s (%d pcs %s @Rp%s, Diskon %s%%, Gross Rp%s).",
                $soNumber,
                $customerId,
                $orderedQty,
                $variantSku,
                number_format($unitPrice, 0, ',', '.'),
                number_format($discountPct, 1, ',', '.'),
                number_format($amounts['total_gross'], 0, ',', '.')
            )
        );

        if ($confirmNow) {
            return self::confirmSalesOrder($soNumber);
        }

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'status_changed' => true,
            'document'       => $soNumber,
            'old_status'     => 'None',
            'new_status'     => 'Draft',
            'reason'         => "Quotation {$soNumber} berhasil disimpan dengan status Draft (Gross Rp" . number_format($amounts['total_gross'], 0, ',', '.') . ").",
        ];
    }

    /**
     * Fitur F-06, F-07, F-08, F-09: Konfirmasi Sales Order (Draft -> Confirmed)
     * Menjalankan validasi backend mutlak saat tombol 'Confirm' ditekan:
     * 1. Blokir jika diskon > 10% (SR-07)
     * 2. Blokir jika Credit Exposure > Credit Limit pelanggan (SR-08)
     * 3. Blokir jika Qty > Available Stock lokasi sumber (SR-04 / SR-09)
     * 4. Kunci harga produk secara otomatis (price_locked = TRUE / read-only) setelah Confirmed (SR-07)
     */
    public static function confirmSalesOrder(string $soNumber, ?float $overrideDiscountPct = null): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        // 1. Cek wewenang role aktif (F-01)
        if (!RolePermissions::canPerformAction($role, 'sales.order.confirm')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba Confirm Sales Order '{$soNumber}'. Hanya Role 'Sales' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.confirm',
                documentNumber: $soNumber,
                oldStatus: 'Draft',
                newStatus: 'Draft',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'old_status'     => 'Draft',
                'new_status'     => 'Draft',
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        $pdo = Database::getConnection();
        $stmtSo = $pdo->prepare('SELECT * FROM sales_orders WHERE so_number = :so');
        $stmtSo->execute([':so' => $soNumber]);
        $so = $stmtSo->fetch();

        if (!$so) {
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'reason'         => "Sales Order '{$soNumber}' tidak ditemukan.",
            ];
        }

        $currentStatus = (string) $so['status'];

        // 2. Cek Idempotensi / Anti-Replay (SR-18)
        if ($currentStatus === 'Confirmed' || $currentStatus === 'Done') {
            $reason = "SR-18 Idempotency Guard: Sales Order '{$soNumber}' sudah berstatus '{$currentStatus}'. Replay Confirm ditolak agar tidak menggandakan reservasi stok.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.confirm',
                documentNumber: $soNumber,
                oldStatus: $currentStatus,
                newStatus: $currentStatus,
                outcome: 'REPLAY_BLOCKED',
                reason: $reason
            );
            return [
                'allowed'        => false,
                'http_code'      => 409,
                'outcome'        => 'REPLAY_BLOCKED',
                'status_changed' => false,
                'document'       => $soNumber,
                'old_status'     => $currentStatus,
                'new_status'     => $currentStatus,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        $stmtLines = $pdo->prepare('SELECT * FROM sales_order_lines WHERE so_number = :so ORDER BY id ASC');
        $stmtLines->execute([':so' => $soNumber]);
        $lines = $stmtLines->fetchAll();

        // 3. VALIDASI MUTLAK 1 — Aturan SR-07: Blokir jika Diskon > 10%
        foreach ($lines as $line) {
            $lineDiscount = $overrideDiscountPct !== null ? $overrideDiscountPct : (float) ($line['discount_pct'] ?? 0.0);
            if ($lineDiscount > 10.0 || $lineDiscount < 0.0) {
                $reason = sprintf(
                    "SR-07 Violation (Diskon > 10%% Ditolak): Konfirmasi Sales Order %s diblokir karena diskon %s%% pada SKU %s melebihi batas maksimum 10%%. Status SO tetap '%s'.",
                    $soNumber,
                    number_format($lineDiscount, 2, ',', '.'),
                    (string) $line['variant_sku'],
                    $currentStatus
                );

                $auditId = AuditLogger::log(
                    actor: $actor,
                    role: $role,
                    module: 'Sales',
                    action: 'sales.order.confirm',
                    documentNumber: $soNumber,
                    oldStatus: $currentStatus,
                    newStatus: $currentStatus,
                    outcome: 'DENIED',
                    reason: $reason
                );

                return [
                    'allowed'        => false,
                    'http_code'      => 422,
                    'outcome'        => 'DENIED',
                    'status_changed' => false,
                    'document'       => $soNumber,
                    'old_status'     => $currentStatus,
                    'new_status'     => $currentStatus,
                    'reason'         => $reason,
                    'audit_id'       => $auditId,
                ];
            }
        }

        // 4. VALIDASI MUTLAK 2 — Aturan SR-08: Blokir jika Credit Exposure > Credit Limit Pelanggan
        $customerId     = (string) $so['customer_id'];
        $thisSoGross    = (float) $so['total_gross'];
        $exposureInfo   = self::getCustomerCreditExposure($customerId, $soNumber);
        $creditLimit    = (float) $exposureInfo['credit_limit'];
        $unpaidAr       = (float) $exposureInfo['unpaid_ar'];
        $existingSoGross= (float) $exposureInfo['confirmed_uninvoiced_so_gross'];
        $totalExposure  = $unpaidAr + $existingSoGross + $thisSoGross;

        if ($totalExposure > $creditLimit) {
            $reason = sprintf(
                "SR-08 Violation (Credit Limit Terlampaui): Konfirmasi Sales Order %s milik pelanggan %s (%s) ditolak! Total Credit Exposure menjadi Rp%s (Unpaid AR Rp%s + SO Confirmed Belum Diinvois Rp%s + Nilai SO Ini Rp%s), melebihi batas Credit Limit Rp%s. Status SO tetap '%s'.",
                $soNumber,
                $customerId,
                $exposureInfo['customer_name'],
                number_format($totalExposure, 0, ',', '.'),
                number_format($unpaidAr, 0, ',', '.'),
                number_format($existingSoGross, 0, ',', '.'),
                number_format($thisSoGross, 0, ',', '.'),
                number_format($creditLimit, 0, ',', '.'),
                $currentStatus
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.confirm',
                documentNumber: $soNumber,
                oldStatus: $currentStatus,
                newStatus: $currentStatus,
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'old_status'     => $currentStatus,
                'new_status'     => $currentStatus,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        // 5. VALIDASI MUTLAK 3 — Aturan SR-04 & SR-09: Cek Available Stock di Lokasi Sumber sebelum Reservasi
        $sourceLocation = (string) $so['source_location'];
        foreach ($lines as $line) {
            $sku = (string) $line['variant_sku'];
            $qty = (int) $line['ordered_qty'];

            $stmtStock = $pdo->prepare(
                'SELECT on_hand_qty, reserved_qty, available_qty
                 FROM stock_by_location
                 WHERE location_code = :loc AND variant_sku = :sku'
            );
            $stmtStock->execute([':loc' => $sourceLocation, ':sku' => $sku]);
            $stockRow = $stmtStock->fetch();

            $availableQty = $stockRow ? (int) $stockRow['available_qty'] : 0;
            if ($qty > $availableQty) {
                $reason = sprintf(
                    "SR-04 & SR-09 Violation (Stok Available Tidak Cukup): Konfirmasi Sales Order %s ditolak karena pesanan %d pcs %s melebihi stok Available di %s (%d pcs). Status SO tetap '%s'.",
                    $soNumber,
                    $qty,
                    $sku,
                    $sourceLocation,
                    $availableQty,
                    $currentStatus
                );

                $auditId = AuditLogger::log(
                    actor: $actor,
                    role: $role,
                    module: 'Sales',
                    action: 'sales.order.confirm',
                    documentNumber: $soNumber,
                    oldStatus: $currentStatus,
                    newStatus: $currentStatus,
                    outcome: 'DENIED',
                    reason: $reason
                );

                return [
                    'allowed'        => false,
                    'http_code'      => 422,
                    'outcome'        => 'DENIED',
                    'status_changed' => false,
                    'document'       => $soNumber,
                    'old_status'     => $currentStatus,
                    'new_status'     => $currentStatus,
                    'reason'         => $reason,
                    'audit_id'       => $auditId,
                ];
            }
        }

        // 6. Semua Validasi Lulus -> Bungkus dalam Transaksi PDO ACID ($pdo->beginTransaction / commit / rollBack):
        //    Ubah Status ke 'Confirmed', Kunci Harga (price_locked = TRUE), dan Picu Event Trigger Otomatis
        //    ke Modul Inventory: reserveStock($sku, $qty, $lokasi) (SR-09)!
        //    Jika satu langkah gagal, seluruh operasi di-rollback agar tidak ada data setengah masuk.
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->prepare(
                "UPDATE sales_orders
                 SET status = 'Confirmed',
                     price_locked = TRUE
                 WHERE so_number = :so"
            )->execute([':so' => $soNumber]);

            $reservationRes = IntegrationEventTrigger::triggerSalesToInventoryReservation(
                $soNumber,
                $lines,
                $sourceLocation
            );

            if (!($reservationRes['allowed'] ?? false)) {
                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'allowed'        => false,
                    'http_code'      => (int) ($reservationRes['http_code'] ?? 422),
                    'outcome'        => 'DENIED',
                    'status_changed' => false,
                    'rolled_back'    => true,
                    'document'       => $soNumber,
                    'old_status'     => $currentStatus,
                    'new_status'     => $currentStatus,
                    'reason'         => (string) ($reservationRes['reason'] ?? 'Reservasi stok gagal; transaksi di-rollback.'),
                ];
            }

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
            "Sales Order %s berhasil dikonfirmasi (Draft -> Confirmed). Subtotal Rp%s, PPN Rp%s, Gross Rp%s. Harga produk dikunci otomatis (read-only / SR-07), Credit Exposure pelanggan %s = Rp%s / Rp%s (SR-08), dan stok direservasi di %s tanpa mengubah On Hand (SR-09).",
            $soNumber,
            number_format((float) $so['subtotal_net'], 0, ',', '.'),
            number_format((float) $so['tax_amount'], 0, ',', '.'),
            number_format($thisSoGross, 0, ',', '.'),
            $customerId,
            number_format($totalExposure, 0, ',', '.'),
            number_format($creditLimit, 0, ',', '.'),
            $sourceLocation
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Sales',
            action: 'sales.order.confirm',
            documentNumber: $soNumber,
            oldStatus: $currentStatus,
            newStatus: 'Confirmed',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'         => true,
            'http_code'       => 200,
            'outcome'         => 'ALLOWED',
            'status_changed'  => true,
            'document'        => $soNumber,
            'old_status'      => $currentStatus,
            'new_status'      => 'Confirmed',
            'price_locked'    => true,
            'stock_mutations' => $reservationRes['stock_mutations'] ?? [],
            'reason'          => $reason,
            'audit_id'        => $auditId,
        ];
    }

    /**
     * Alias eksplisit untuk konfirmasi Quotation menjadi Sales Order (Draft -> Confirmed)
     * sesuai wewenang Putri Aulia (Manajer Sales).
     */
    public static function confirmQuotationToSalesOrder(string $soNumber, ?float $overrideDiscountPct = null): array
    {
        return self::confirmSalesOrder($soNumber, $overrideDiscountPct);
    }

    /**
     * Fitur F-07 (Aturan SR-07 Price Lock & Discount Cap):
     * Mencoba mengubah harga satuan atau diskon pada Sales Order.
     * Ditolak secara mutlak jika status SO sudah 'Confirmed' (price_locked = TRUE) atau jika diskon > 10%.
     */
    public static function updateSalesOrderPrice(string $soNumber, float $newUnitPrice, float $newDiscountPct = 0.0): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'sales.order.update_price')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' tidak memiliki izin mengubah harga Sales Order '{$soNumber}'.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.update_price',
                documentNumber: $soNumber,
                oldStatus: 'Locked',
                newStatus: 'Locked',
                outcome: 'DENIED',
                reason: $reason
            );
            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        $pdo = Database::getConnection();
        $stmtSo = $pdo->prepare('SELECT * FROM sales_orders WHERE so_number = :so');
        $stmtSo->execute([':so' => $soNumber]);
        $so = $stmtSo->fetch();

        if (!$so) {
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'reason'         => "Sales Order '{$soNumber}' tidak ditemukan.",
            ];
        }

        $status      = (string) $so['status'];
        $priceLocked = (bool) $so['price_locked'] || $status === 'Confirmed' || $status === 'Done';

        // 1. Validasi Mutlak SR-07 Price Lock: Harga & Diskon Terkunci (Read-Only) setelah Confirmed!
        if ($priceLocked) {
            $reason = sprintf(
                "SR-07 Price Lock Violation: Perubahan harga menjadi Rp%s atau diskon %s%% pada Sales Order %s ditolak! Harga produk dan diskon telah terkunci otomatis (read-only) karena status dokumen sudah '%s'.",
                number_format($newUnitPrice, 0, ',', '.'),
                number_format($newDiscountPct, 2, ',', '.'),
                $soNumber,
                $status
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.update_price',
                documentNumber: $soNumber,
                oldStatus: $status . ' (PriceLocked)',
                newStatus: $status . ' (PriceLocked)',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'old_status'     => $status,
                'new_status'     => $status,
                'price_locked'   => true,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        // 2. Validasi Mutlak SR-07 Diskon > 10% pada Draft SO
        if ($newDiscountPct > 10.0 || $newDiscountPct < 0.0) {
            $reason = sprintf(
                "SR-07 Violation (Diskon > 10%% Ditolak): Perubahan diskon menjadi %s%% pada Sales Order %s ditolak karena melebihi batas maksimum 10%%.",
                number_format($newDiscountPct, 2, ',', '.'),
                $soNumber
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Sales',
                action: 'sales.order.update_price',
                documentNumber: $soNumber,
                oldStatus: $status,
                newStatus: $status,
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'document'       => $soNumber,
                'old_status'     => $status,
                'new_status'     => $status,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        // Update harga pada Draft SO
        $stmtLine = $pdo->prepare('SELECT * FROM sales_order_lines WHERE so_number = :so ORDER BY id ASC LIMIT 1');
        $stmtLine->execute([':so' => $soNumber]);
        $line = $stmtLine->fetch();

        $qty     = $line ? (int) $line['ordered_qty'] : 1;
        $amounts = self::calculateLineAmounts($qty, $newUnitPrice, $newDiscountPct);

        if ($line) {
            $pdo->prepare(
                'UPDATE sales_order_lines
                 SET unit_price = :price,
                     discount_pct = :disc,
                     line_subtotal = :sub,
                     line_tax = :tax,
                     line_gross = :gross
                 WHERE id = :id'
            )->execute([
                ':price' => $newUnitPrice,
                ':disc'  => $newDiscountPct,
                ':sub'   => $amounts['subtotal_net'],
                ':tax'   => $amounts['tax_amount'],
                ':gross' => $amounts['total_gross'],
                ':id'    => $line['id'],
            ]);
        }

        $pdo->prepare(
            'UPDATE sales_orders
             SET subtotal_net = :sub,
                 tax_amount = :tax,
                 total_gross = :gross
             WHERE so_number = :so'
        )->execute([
            ':sub'   => $amounts['subtotal_net'],
            ':tax'   => $amounts['tax_amount'],
            ':gross' => $amounts['total_gross'],
            ':so'    => $soNumber,
        ]);

        $reason = sprintf(
            "Harga Draft Quotation %s berhasil diperbarui menjadi Rp%s (Diskon %s%%, Gross Rp%s).",
            $soNumber,
            number_format($newUnitPrice, 0, ',', '.'),
            number_format($newDiscountPct, 1, ',', '.'),
            number_format($amounts['total_gross'], 0, ',', '.')
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Sales',
            action: 'sales.order.update_price',
            documentNumber: $soNumber,
            oldStatus: 'Draft',
            newStatus: 'Draft (Updated)',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'status_changed' => true,
            'document'       => $soNumber,
            'old_status'     => 'Draft',
            'new_status'     => 'Draft',
            'reason'         => $reason,
            'audit_id'       => $auditId,
        ];
    }

    /**
     * Mengambil seluruh daftar Sales Orders beserta detail baris, pelanggan, dan status price_locked.
     */
    public static function getAllSalesOrdersWithLines(): array
    {
        $pdo = Database::getConnection();
        $orders = $pdo->query(
            'SELECT so.*, c.name AS customer_name, c.credit_limit, c.payment_terms, c.opening_ar
             FROM sales_orders so
             JOIN contacts_customers c ON c.id = so.customer_id
             ORDER BY so.so_number DESC'
        )->fetchAll() ?: [];

        foreach ($orders as &$order) {
            $stmtLines = $pdo->prepare(
                'SELECT sol.*, p.name AS product_name, p.size, p.color_flavour
                 FROM sales_order_lines sol
                 JOIN products p ON p.variant_sku = sol.variant_sku
                 WHERE sol.so_number = :so
                 ORDER BY sol.id ASC'
            );
            $stmtLines->execute([':so' => $order['so_number']]);
            $order['lines'] = $stmtLines->fetchAll() ?: [];
            $order['exposure'] = self::getCustomerCreditExposure((string) $order['customer_id'], (string) $order['so_number']);
        }
        unset($order);

        return $orders;
    }

    /**
     * Mengambil semua data CRM Leads beserta detail pelanggan.
     */
    public static function getAllLeads(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT l.*, c.name AS customer_name, c.credit_limit, c.payment_terms
             FROM crm_leads l
             JOIN contacts_customers c ON c.id = l.customer_id
             ORDER BY l.lead_number ASC'
        )->fetchAll() ?: [];
    }
}

