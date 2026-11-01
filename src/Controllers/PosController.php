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
 * PosController — Modul Point of Sale (POS), Cash Count, Settlement & POS Refund
 * (Fitur F-17, F-18, F-19, F-20 & Aturan SR-03, SR-04, SR-11, SR-12, SR-13, SR-14, SR-16, SR-18, SR-21)
 *
 * Pemangku Kepentingan Utama:
 * - Rani Oktaviani (Supervisor Kasir):
 *   1. Buka sesi POS beridentitas kasir & register dengan Opening Cash Rp200.000 (F-17, SR-13, SR-14, T-27, T-28).
 *   2. POS Basket Checkout dengan pengurangan stok real-time dan jurnal COGS sekali per struk Paid (F-18, SR-04 mengalahkan SR-03, T-29, T-30).
 *   3. Rekonsiliasi Cash Count (Expected vs Counted Cash) & penutupan sesi POS (F-19, SR-11 vs SR-12, SR-14, T-31, T-32, T-36).
 *   4. POS Refund tunai yang mengembalikan stok layak jual, membalik COGS, Revenue, PPN, dan Cash Drawer (F-20, SR-16, T-33).
 */
class PosController
{
    /**
     * Ambil seluruh sesi POS.
     */
    public static function getSessions(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("SELECT *, expected_cash AS expected_closing_cash FROM pos_sessions ORDER BY session_number ASC");
        return $stmt->fetchAll() ?: [];
    }

    public static function getPosSessions(): array
    {
        return self::getSessions();
    }

    /**
     * Ambil seluruh struk POS beserta baris itemnya.
     */
    public static function getReceiptsWithLines(): array
    {
        $pdo      = Database::getConnection();
        $receipts = $pdo->query("SELECT * FROM pos_receipts ORDER BY receipt_number ASC")->fetchAll() ?: [];

        $lineStmt = $pdo->prepare("
            SELECT prl.*, p.name AS product_name
            FROM pos_receipt_lines prl
            LEFT JOIN products p ON p.variant_sku = prl.variant_sku
            WHERE prl.receipt_number = :rcpt
            ORDER BY prl.id ASC
        ");

        foreach ($receipts as &$rcpt) {
            $lineStmt->execute([':rcpt' => $rcpt['receipt_number'] ?? '']);
            $rcpt['lines'] = $lineStmt->fetchAll() ?: [];
        }
        unset($rcpt);

        return $receipts;
    }

    public static function getPosReceipts(): array
    {
        return self::getReceiptsWithLines();
    }

    /**
     * Fitur F-17 & Aturan SR-13, SR-14, SR-18 (Layar S-14, Uji T-27 & T-28):
     * Buka sesi POS baru dengan Opening Cash (default Rp200.000 pada STORE-PKU / Register POS01).
     * Menolak pembukaan sesi baru apabila masih terdapat sesi berstatus 'Open' pada lokasi & register yang sama (T-28).
     */
    public static function openPosSession(array $input = []): array
    {
        return \RantauMart\Services\AtomicRunner::run(static fn (): array => self::openPosSessionAtomic($input));
    }

    /** Isi inti openPosSession() - dijalankan di dalam transaksi PDO ACID (AtomicRunner). */
    private static function openPosSessionAtomic(array $input = []): array
    {
        $locationCode = trim((string) ($input['location_code'] ?? 'STORE-PKU')) ?: 'STORE-PKU';
        $registerCode = trim((string) ($input['register_code'] ?? 'POS01')) ?: 'POS01';
        $openingCash  = isset($input['opening_cash']) && $input['opening_cash'] !== '' ? (float) $input['opening_cash'] : 200000.0;

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'pos.session.open')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba membuka POS Session. Hanya Role 'Cashier' (Rani Oktaviani) yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.open',
                documentNumber: "{$locationCode}:{$registerCode}",
                oldStatus: 'Closed',
                newStatus: 'Closed',
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

        $pdo = Database::getConnection();

        // Cek apakah sudah ada sesi Open / In Progress pada lokasi dan register yang sama (SR-13 & SR-18, Uji T-28)
        $stmtOpen = $pdo->prepare("
            SELECT * FROM pos_sessions
            WHERE location_code = :loc AND register_code = :reg AND status IN ('Open', 'In Progress', 'Pending Approval')
            LIMIT 1
        ");
        $stmtOpen->execute([':loc' => $locationCode, ':reg' => $registerCode]);
        $existingOpen = $stmtOpen->fetch();

        if ($existingOpen) {
            $reason = sprintf(
                'SR-13 & SR-18 Violation (Sesi POS Kembar Ditolak): Register %s di %s masih memiliki sesi aktif %s (Status: %s, Kasir: %s). Buka sesi identik ditolak.',
                $registerCode,
                $locationCode,
                (string) $existingOpen['session_number'],
                (string) $existingOpen['status'],
                (string) $existingOpen['cashier_name']
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.open',
                documentNumber: (string) $existingOpen['session_number'],
                oldStatus: (string) $existingOpen['status'],
                newStatus: (string) $existingOpen['status'],
                outcome: 'REPLAY_BLOCKED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 409,
                'outcome'            => 'REPLAY_BLOCKED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-14',
                'status_changed'     => false,
                'document_preserved' => true,
                'session_number'     => (string) $existingOpen['session_number'],
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $sessionNumber = DocumentStateGuard::nextDocumentNumber('POS-SESSION-');
        $cashierName   = (string) ($user['full_name'] ?? 'Rani Oktaviani');

        $stmtIns = $pdo->prepare("
            INSERT INTO pos_sessions (
                session_number, location_code, register_code, cashier_name,
                status, opening_cash, cash_tenders, cash_refunds, expected_cash, counted_cash, variance
            ) VALUES (
                :sess_no, :loc, :reg, :cashier,
                'Open', :open_cash, 0, 0, :exp_cash, :cnt_cash, 0
            )
        ");
        $stmtIns->execute([
            ':sess_no'   => $sessionNumber,
            ':loc'       => $locationCode,
            ':reg'       => $registerCode,
            ':cashier'   => $cashierName,
            ':open_cash' => $openingCash,
            ':exp_cash'  => $openingCash,
            ':cnt_cash'  => $openingCash,
        ]);

        $kpiNow = AccountingController::getLiveKpiAndLedgerDashboard();

        $reason = sprintf(
            'POS Session %s berhasil dibuka (SR-13 & SR-14): Lokasi %s, Register %s, Kasir %s, Opening Cash Rp%s. Saldo buku besar POS Cash Drawer: Rp%s (belum ada sales posting).',
            $sessionNumber,
            $locationCode,
            $registerCode,
            $cashierName,
            number_format($openingCash, 0, ',', '.'),
            number_format((float) ($kpiNow['pos_cash_drawer'] ?? 200000), 0, ',', '.')
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Point of Sale',
            action: 'pos.session.open',
            documentNumber: $sessionNumber,
            oldStatus: 'Closed',
            newStatus: 'Open',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'            => true,
            'http_code'          => 200,
            'outcome'            => 'ALLOWED',
            'decision'           => 'ALLOWED',
            'rule'               => 'SR-13',
            'status_changed'     => true,
            'document_preserved' => false,
            'session_number'     => $sessionNumber,
            'location_code'      => $locationCode,
            'register_code'      => $registerCode,
            'cashier_name'       => $cashierName,
            'opening_cash'       => $openingCash,
            'expected_cash'      => $openingCash,
            'pos_cash_ledger'    => (float) ($kpiNow['pos_cash_drawer'] ?? 200000),
            'status'             => 'Open',
            'reason'             => $reason,
            'message'            => $reason,
            'audit_id'           => $auditId,
        ];
    }

    /**
     * Mengubah status sesi kasir dari 'Open' menjadi 'In Progress' saat kasir mulai melayani transaksi ritel.
     */
    public static function startSessionInProgress(string $sessionNumber = 'POS-SESSION-0001'): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'pos.session.open')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' tidak memiliki wewenang mengubah status sesi POS '{$sessionNumber}'.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.in_progress',
                documentNumber: $sessionNumber,
                oldStatus: 'Open',
                newStatus: 'Open',
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

        $pdo      = Database::getConnection();
        $stmtSess = $pdo->prepare('SELECT * FROM pos_sessions WHERE session_number = :sess LIMIT 1');
        $stmtSess->execute([':sess' => $sessionNumber]);
        $session = $stmtSess->fetch();

        if (!$session || !in_array((string) $session['status'], ['Open', 'In Progress'], true)) {
            $reason = sprintf(
                'Sesi POS "%s" tidak ditemukan atau sudah ditutup (Status: %s).',
                $sessionNumber,
                (string) ($session['status'] ?? 'Tidak Ada')
            );
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
            ];
        }

        $oldStatus = (string) $session['status'];
        $pdo->prepare("UPDATE pos_sessions SET status = 'In Progress' WHERE session_number = :sess")
            ->execute([':sess' => $sessionNumber]);

        $reason = "POS Session {$sessionNumber} beralih ke status 'In Progress' (kasir aktif melayani penjualan ritel).";
        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Point of Sale',
            action: 'pos.session.in_progress',
            documentNumber: $sessionNumber,
            oldStatus: $oldStatus,
            newStatus: 'In Progress',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'status_changed' => $oldStatus !== 'In Progress',
            'session_number' => $sessionNumber,
            'old_status'     => $oldStatus,
            'status'         => 'In Progress',
            'reason'         => $reason,
            'audit_id'       => $auditId,
        ];
    }

    /**
     * Fitur F-18 & Aturan SR-04 vs SR-03, SR-13, SR-21 (Layar S-15, Uji T-29 & T-30):
     * Checkout Keranjang Belanja POS (mendukung single-item maupun multi-line basket).
     * - Menegakkan SR-04 (Deni Pratama) mengalahkan SR-03 (Rani Oktaviani):
     *   Jika kuantitas item di keranjang melebihi stok Available lokasi toko, transaksi ditolak mutlak (T-30).
     * - Mengurangi stok toko secara real-time dan memposting jurnal COGS sekali saat struk Paid (T-29).
     * - Menyimpan akumulasi Cash Tender pada sesi aktif untuk diselesaikan saat Close Session sah (SR-14).
     */
    public static function checkoutPosBasket(array $input): array
    {
        return \RantauMart\Services\AtomicRunner::run(static fn (): array => self::checkoutPosBasketAtomic($input));
    }

    /** Isi inti checkoutPosBasket() - dijalankan di dalam transaksi PDO ACID (AtomicRunner). */
    private static function checkoutPosBasketAtomic(array $input): array
    {
        $sessionNumber = trim((string) ($input['session_number'] ?? 'POS-SESSION-0001'));
        $paymentMethod = trim((string) ($input['payment_method'] ?? 'Cash')) ?: 'Cash';

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'pos.basket.checkout')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba melakukan POS Basket Checkout. Hanya Role 'Cashier' yang berwenang.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.basket.checkout',
                documentNumber: $sessionNumber,
                oldStatus: 'Open',
                newStatus: 'Open',
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

        $pdo      = Database::getConnection();
        $stmtSess = $pdo->prepare('SELECT * FROM pos_sessions WHERE session_number = :sess LIMIT 1');
        $stmtSess->execute([':sess' => $sessionNumber]);
        $session = $stmtSess->fetch();

        if (!$session || !in_array((string) $session['status'], ['Open', 'In Progress'], true)) {
            $reason = sprintf(
                'SR-13 Violation: Sesi POS "%s" tidak ditemukan atau tidak berstatus Open / In Progress (Status: %s). Keranjang POS hanya bisa dibayar pada sesi kasir yang aktif.',
                $sessionNumber,
                (string) ($session['status'] ?? 'Tidak Ada')
            );
            return [
                'allowed'        => false,
                'http_code'      => 422,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-13',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $locationCode = !empty($input['location_code'])
            ? trim((string) $input['location_code'])
            : (string) ($session['location_code'] ?? 'STORE-PKU');
        if (!in_array($locationCode, ['STORE-PKU', 'STORE-DUM'], true)) {
            $locationCode = (string) ($session['location_code'] ?? 'STORE-PKU');
        }
        $registerCode = (string) ($session['register_code'] ?? 'POS01');
        $cashierName  = (string) ($session['cashier_name'] ?? 'Rani Oktaviani');

        // Normalisasi daftar item keranjang (mendukung array 'items' atau form input sku_1/qty_1, sku_2/qty_2)
        $basketItems = [];
        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $it) {
                $sku = trim((string) ($it['variant_sku'] ?? ''));
                $qty = (int) ($it['qty'] ?? 0);
                if ($sku !== '' && $qty > 0) {
                    $basketItems[] = ['variant_sku' => $sku, 'qty' => $qty];
                }
            }
        } else {
            $sku1 = trim((string) ($input['variant_sku'] ?? $input['sku_1'] ?? ''));
            $qty1 = (int) ($input['qty'] ?? $input['qty_1'] ?? 0);
            if ($sku1 !== '' && $qty1 > 0) {
                $basketItems[] = ['variant_sku' => $sku1, 'qty' => $qty1];
            }
            $sku2 = trim((string) ($input['sku_2'] ?? ''));
            $qty2 = (int) ($input['qty_2'] ?? 0);
            if ($sku2 !== '' && $qty2 > 0) {
                $basketItems[] = ['variant_sku' => $sku2, 'qty' => $qty2];
            }
        }

        if (empty($basketItems)) {
            $reason = 'Keranjang POS kosong. Tambahkan minimal 1 produk dengan kuantitas > 0.';
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

        // Validasi Mutlak SR-04 vs SR-03 (Uji T-30): Cek kecukupan stok Available di toko untuk SELURUH item sebelum memproses!
        $preparedLines = [];
        $subtotalNet   = 0.0;
        $totalTax      = 0.0;
        $totalGross    = 0.0;
        $totalCogs     = 0.0;

        foreach ($basketItems as $item) {
            $sku = $item['variant_sku'];
            $qty = $item['qty'];

            $stmtProd = $pdo->prepare('SELECT * FROM products WHERE variant_sku = :sku LIMIT 1');
            $stmtProd->execute([':sku' => $sku]);
            $prod = $stmtProd->fetch();

            $stock = InventoryController::getLocationSkuStock($locationCode, $sku);
            if (!$prod || !$stock) {
                $reason = "Produk '{$sku}' tidak ditemukan di lokasi toko '{$locationCode}'.";
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

            $availQty  = (int) $stock['available_qty'];
            $onHandQty = (int) $stock['on_hand_qty'];

            if ($qty > $availQty || $qty > $onHandQty || ($onHandQty - $qty) < 0) {
                $reason = sprintf(
                    'SR-04 Violation (Anti-Negative Stock POS): Checkout keranjang %d pcs %s di %s ditolak karena melebihi stok fisik/Available toko (%d pcs; On Hand %d pcs). Sesuai keputusan SR-04 mengalahkan SR-03, kasir dilarang menciptakan stok negatif.',
                    $qty,
                    $sku,
                    $locationCode,
                    $availQty,
                    $onHandQty
                );

                $auditId = AuditLogger::log(
                    actor: $actor,
                    role: $role,
                    module: 'Point of Sale',
                    action: 'pos.basket.checkout',
                    documentNumber: $sessionNumber,
                    oldStatus: 'Open',
                    newStatus: 'Open',
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
                    'session_number'     => $sessionNumber,
                    'location_code'      => $locationCode,
                    'variant_sku'        => $sku,
                    'attempted_qty'      => $qty,
                    'available_qty'      => $availQty,
                    'reason'             => $reason,
                    'message'            => $reason,
                    'audit_id'           => $auditId,
                ];
            }

            $unitPrice    = (float) $prod['sales_price'];
            $standardCost = (float) $prod['cost'];
            $lineNet      = round($qty * $unitPrice, 0, PHP_ROUND_HALF_UP);
            $lineTax      = round($lineNet * 0.11, 0, PHP_ROUND_HALF_UP);
            $lineGross    = $lineNet + $lineTax;
            $lineCogs     = round($qty * $standardCost, 0, PHP_ROUND_HALF_UP);

            $subtotalNet += $lineNet;
            $totalTax    += $lineTax;
            $totalGross  += $lineGross;
            $totalCogs   += $lineCogs;

            $preparedLines[] = [
                'variant_sku'    => $sku,
                'qty'            => $qty,
                'unit_price'     => $unitPrice,
                'standard_cost'  => $standardCost,
                'line_subtotal'  => $lineNet,
                'line_tax'       => $lineTax,
                'line_gross'     => $lineGross,
                'line_cogs'      => $lineCogs,
                'on_hand_before' => $onHandQty,
            ];
        }

        // Semua validasi lulus -> Picu Jalur Integrasi 4 (POS -> Inventory & Accounting) melalui IntegrationEventTrigger:
        // 1. Panggil InventoryController::deductPhysicalStock() di toko terkait (STORE-PKU / STORE-DUM)
        // 2. Panggil AccountingController::postPOSJournal() untuk mencatat COGS (5010/1130) & Pendapatan POS (4020+2030/1190) (SR-13, SR-21, T-29)
        $receiptNumber = DocumentStateGuard::nextDocumentNumber('POS-RCPT-');
        $checkoutEvent = IntegrationEventTrigger::triggerPosToInventoryAndAccountingCheckout([
            'receipt_number' => $receiptNumber,
            'session_number' => $sessionNumber,
            'location_code'  => $locationCode,
            'lines'          => $preparedLines,
            'subtotal_net'   => $subtotalNet,
            'tax_amount'     => $totalTax,
            'total_gross'    => $totalGross,
            'total_cogs'     => $totalCogs,
            'payment_method' => $paymentMethod,
            'partner_id'     => 'C005',
        ]);

        if (!($checkoutEvent['allowed'] ?? false)) {
            // ACID: satu langkah integrasi gagal -> rollback seluruh operasi (stok & jurnal tidak setengah masuk).
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $checkoutEvent['rolled_back'] = true;
            return $checkoutEvent;
        }

        $stockAfterMap = (array) ($checkoutEvent['stock_after'] ?? []);
        $cogsJeNumber  = (string) ($checkoutEvent['journal_entry_number'] ?? '');

        $pdo->prepare("
            INSERT INTO pos_receipts (
                receipt_number, session_number, location_code, register_code, cashier_name,
                payment_method, subtotal_net, tax_amount, total_gross, total_cogs, status, cogs_je_number
            ) VALUES (
                :rcpt, :sess, :loc, :reg, :cashier,
                :pm, :net, :tax, :gross, :cogs, 'Paid', :je
            )
        ")->execute([
            ':rcpt'    => $receiptNumber,
            ':sess'    => $sessionNumber,
            ':loc'     => $locationCode,
            ':reg'     => $registerCode,
            ':cashier' => $cashierName,
            ':pm'      => $paymentMethod,
            ':net'     => $subtotalNet,
            ':tax'     => $totalTax,
            ':gross'   => $totalGross,
            ':cogs'    => $totalCogs,
            ':je'      => $cogsJeNumber,
        ]);

        $stmtLineIns = $pdo->prepare("
            INSERT INTO pos_receipt_lines (
                receipt_number, variant_sku, qty, returned_qty, unit_price, standard_cost,
                line_subtotal, line_tax, line_gross, line_cogs
            ) VALUES (
                :rcpt, :sku, :qty, 0, :price, :cost,
                :net, :tax, :gross, :cogs
            )
        ");
        foreach ($preparedLines as $pl) {
            $stmtLineIns->execute([
                ':rcpt'  => $receiptNumber,
                ':sku'   => $pl['variant_sku'],
                ':qty'   => $pl['qty'],
                ':price' => $pl['unit_price'],
                ':cost'  => $pl['standard_cost'],
                ':net'   => $pl['line_subtotal'],
                ':tax'   => $pl['line_tax'],
                ':gross' => $pl['line_gross'],
                ':cogs'  => $pl['line_cogs'],
            ]);
        }

        // Update akumulasi sesi POS (Expected Cash = Opening Cash + Cash Tenders - Cash Refunds)
        $newCashTenders = (float) $session['cash_tenders'] + ($paymentMethod === 'Cash' ? $totalGross : 0.0);
        $newExpected    = (float) $session['opening_cash'] + $newCashTenders - (float) $session['cash_refunds'];
        $newNetSales    = (float) $session['net_sales_revenue'] + $subtotalNet;
        $newOutputVat   = (float) $session['output_vat'] + $totalTax;
        $newTotalCogs   = (float) $session['total_cogs'] + $totalCogs;

        $pdo->prepare("
            UPDATE pos_sessions
            SET status = 'In Progress',
                cash_tenders = :tenders,
                expected_cash = :exp,
                counted_cash = :cnt,
                net_sales_revenue = :rev,
                output_vat = :vat,
                total_cogs = :cogs
            WHERE session_number = :sess
        ")->execute([
            ':tenders' => $newCashTenders,
            ':exp'     => $newExpected,
            ':cnt'     => $newExpected,
            ':rev'     => $newNetSales,
            ':vat'     => $newOutputVat,
            ':cogs'    => $newTotalCogs,
            ':sess'    => $sessionNumber,
        ]);

        $reason = sprintf(
            'POS Receipt %s Paid (SR-04 & SR-13): Net Rp%s, PPN 11%% Rp%s, Paid Cash Rp%s, COGS Rp%s (%s). Sesi %s berstatus In Progress; Expected Cash menjadi Rp%s; settlement revenue menunggu Close Session.',
            $receiptNumber,
            number_format($subtotalNet, 0, ',', '.'),
            number_format($totalTax, 0, ',', '.'),
            number_format($totalGross, 0, ',', '.'),
            number_format($totalCogs, 0, ',', '.'),
            $cogsJeNumber,
            $sessionNumber,
            number_format($newExpected, 0, ',', '.')
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Point of Sale',
            action: 'pos.basket.checkout',
            documentNumber: $receiptNumber,
            oldStatus: 'New',
            newStatus: 'Paid',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'            => true,
            'http_code'          => 200,
            'outcome'            => 'ALLOWED',
            'decision'           => 'ALLOWED',
            'rule'               => 'SR-13',
            'status_changed'     => true,
            'document_preserved' => false,
            'receipt_number'     => $receiptNumber,
            'session_number'     => $sessionNumber,
            'session_status'     => 'In Progress',
            'location_code'      => $locationCode,
            'subtotal_net'       => $subtotalNet,
            'tax_amount'         => $totalTax,
            'total_gross'        => $totalGross,
            'total_cogs'         => $totalCogs,
            'expected_cash'      => $newExpected,
            'cogs_je_number'     => $cogsJeNumber,
            'stock_after'        => $stockAfterMap,
            'status'             => 'Paid',
            'receipt'            => [
                'receipt_number'        => $receiptNumber,
                'session_number'        => $sessionNumber,
                'session_status'        => 'In Progress',
                'location_code'         => $locationCode,
                'subtotal_net'          => $subtotalNet,
                'tax_amount'            => $totalTax,
                'total_gross'           => $totalGross,
                'total_cogs'            => $totalCogs,
                'expected_closing_cash' => $newExpected,
                'cogs_je_number'        => $cogsJeNumber,
                'status'                => 'Paid',
            ],
            'reason'             => $reason,
            'message'            => $reason,
            'audit_id'           => $auditId,
        ];
    }

    /**
     * Fitur F-19 & Aturan SR-12 vs SR-11, SR-14, SR-18, SR-21 (Layar S-16, Uji T-31, T-32, T-36):
     * Penutupan Sesi POS, Rekonsiliasi Cash Count & Settlement Revenue/Cash Drawer.
     * - Menegakkan SR-12 (Arif Firmansyah) mengalahkan SR-11 (Rani Oktaviani):
     *   Jika Counted Cash != Expected Cash (ada selisih kas sekecil apa pun, mis. -Rp5.000 pada T-32) dan tanpa otorisasi Finance,
     *   status sesi berubah menjadi 'Pending Approval', penutupan & jurnal settlement ditahan!
     * - Ketika Counted == Expected (T-31) atau disetujui Finance, sesi menjadi 'Closed' dan memposting jurnal settlement sekali:
     *   DR 1020 POS Cash Drawer / CR 4020 POS Sales Revenue + CR 2030 Output VAT.
     * - Menolak replay Close/Post pada sesi yang sudah 'Closed' (SR-18, T-36).
     */
    public static function closePosSession(array $input): array
    {
        return \RantauMart\Services\AtomicRunner::run(static fn (): array => self::closePosSessionAtomic($input));
    }

    /** Isi inti closePosSession() - dijalankan di dalam transaksi PDO ACID (AtomicRunner). */
    private static function closePosSessionAtomic(array $input): array
    {
        $sessionNumber   = trim((string) ($input['session_number'] ?? 'POS-SESSION-0001'));
        $financeApproval = ((string) ($input['finance_approval'] ?? $input['finance_approved'] ?? '0')) === '1'
            || ($input['finance_approval'] ?? $input['finance_approved'] ?? false) === true;

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'pos.session.close')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba menutup POS Session '{$sessionNumber}'.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.close',
                documentNumber: $sessionNumber,
                oldStatus: 'Open',
                newStatus: 'Open',
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

        $pdo      = Database::getConnection();
        $stmtSess = $pdo->prepare('SELECT * FROM pos_sessions WHERE session_number = :sess LIMIT 1');
        $stmtSess->execute([':sess' => $sessionNumber]);
        $session = $stmtSess->fetch();

        if (!$session) {
            $reason = "POS Session '{$sessionNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $oldStatus = (string) $session['status'];

        // 1. Aturan SR-14 & SR-18 (Uji T-36): Tolak replay Close/Post pada sesi yang sudah Closed!
        if ($oldStatus === 'Closed') {
            $reason = sprintf(
                'SR-14 & SR-18 Idempotency Guard (Replay Close Session Ditolak): POS Session %s sudah berstatus "Closed" dan telah diposting pada jurnal %s. Penutupan ulang diblokir agar tidak menggandakan pendapatan maupun kas.',
                $sessionNumber,
                (string) ($session['settlement_je_number'] ?? '-')
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.close',
                documentNumber: $sessionNumber,
                oldStatus: 'Closed',
                newStatus: 'Closed',
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
                'session_number'     => $sessionNumber,
                'status'             => 'Closed',
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $openingCash  = (float) $session['opening_cash'];
        $cashTenders  = (float) $session['cash_tenders'];
        $cashRefunds  = (float) $session['cash_refunds'];
        $expectedCash = $openingCash + $cashTenders - $cashRefunds;
        $countedCash  = isset($input['counted_cash']) && $input['counted_cash'] !== ''
            ? (float) $input['counted_cash']
            : $expectedCash;
        $variance     = $countedCash - $expectedCash;

        // 2. Validasi Mutlak SR-12 vs SR-11 (Uji T-32):
        // Selisih kas sekecil apa pun wajib memperoleh persetujuan Finance sebelum jurnal settlement diposting!
        $hasFinanceAuth = ($role === RolePermissions::ROLE_FINANCE) || $financeApproval;
        if (abs($variance) > 0.001 && !$hasFinanceAuth) {
            $pdo->prepare("
                UPDATE pos_sessions
                SET counted_cash = :cnt, variance = :var, status = 'Pending Approval'
                WHERE session_number = :sess
            ")->execute([
                ':cnt'  => $countedCash,
                ':var'  => $variance,
                ':sess' => $sessionNumber,
            ]);

            $reason = sprintf(
                'SR-12 Violation (Selisih Kas Butuh Persetujuan Finance): Penutupan %s ditahan ke status "Pending Approval" karena terdapat selisih kas sebesar Rp%s (Expected Rp%s vs Counted Rp%s). Tanpa otorisasi Kepala Finance (Arif Firmansyah), jurnal settlement tidak diposting.',
                $sessionNumber,
                number_format($variance, 0, ',', '.'),
                number_format($expectedCash, 0, ',', '.'),
                number_format($countedCash, 0, ',', '.')
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.session.close',
                documentNumber: $sessionNumber,
                oldStatus: $oldStatus,
                newStatus: 'Pending Approval',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-12',
                'status_changed'     => true,
                'document_preserved' => false,
                'session_number'     => $sessionNumber,
                'expected_cash'      => $expectedCash,
                'counted_cash'       => $countedCash,
                'variance'           => $variance,
                'status'             => 'Pending Approval',
                'settlement_posted'  => false,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        // 3. Posting Jurnal Settlement POS sekali saat sesi ditutup sah (SR-14, SR-21, T-31):
        //    Karena postPOSJournal() saat checkout telah mencatat Pendapatan POS (4020) & PPN (2030) terhadap
        //    akun perantara 1190 (POS Settlement Clearing), maka saat Close Session akun 1190 diselesaikan
        //    ke 1020 (POS Cash Drawer) beserta selisih kas 5130 (Cash Over/Short) jika ada.
        $cashDelta = $cashTenders + $variance;

        $journalLines = [];
        if ($cashDelta > 0) {
            $journalLines[] = [
                'account_code' => '1020',
                'partner_id'   => null,
                'debit'        => $cashDelta,
                'credit'       => 0.0,
                'memo'         => sprintf('Penambahan Kas POS Cash Drawer dari Sesi %s', $sessionNumber),
            ];
        } elseif ($cashDelta < 0) {
            $journalLines[] = [
                'account_code' => '1020',
                'partner_id'   => null,
                'debit'        => 0.0,
                'credit'       => abs($cashDelta),
                'memo'         => sprintf('Penyesuaian Kas POS Cash Drawer dari Sesi %s', $sessionNumber),
            ];
        }
        if ($variance < 0) {
            $journalLines[] = [
                'account_code' => '5130',
                'partner_id'   => null,
                'debit'        => abs($variance),
                'credit'       => 0.0,
                'memo'         => sprintf('Cash Short (Selisih Kurang Kas) Sesi %s', $sessionNumber),
            ];
        } elseif ($variance > 0) {
            $journalLines[] = [
                'account_code' => '5130',
                'partner_id'   => null,
                'debit'        => 0.0,
                'credit'       => $variance,
                'memo'         => sprintf('Cash Over (Selisih Lebih Kas) Sesi %s', $sessionNumber),
            ];
        }
        if ($cashTenders > 0) {
            $journalLines[] = [
                'account_code' => '1190',
                'partner_id'   => null,
                'debit'        => 0.0,
                'credit'       => $cashTenders,
                'memo'         => sprintf('Clearing POS Settlement Sesi %s', $sessionNumber),
            ];
        }

        $jeNumber = null;
        if (!empty($journalLines)) {
            $jeNumber = AccountingController::postBalancedJournal(
                $sessionNumber,
                sprintf('POS Session Close & Settlement %s (Expected Rp%s, Counted Rp%s, Variance Rp%s)', $sessionNumber, number_format($expectedCash, 0, ',', '.'), number_format($countedCash, 0, ',', '.'), number_format($variance, 0, ',', '.')),
                $journalLines
            );
        }

        $pdo->prepare("
            UPDATE pos_sessions
            SET counted_cash = :cnt,
                variance = :var,
                status = 'Closed',
                approved_by_finance = :app,
                settlement_je_number = :je
            WHERE session_number = :sess
        ")->execute([
            ':cnt'  => $countedCash,
            ':var'  => $variance,
            ':app'  => $hasFinanceAuth ? 1 : 0,
            ':je'   => $jeNumber,
            ':sess' => $sessionNumber,
        ]);

        $kpiNow = AccountingController::getLiveKpiAndLedgerDashboard();

        $reason = sprintf(
            'POS Session %s berhasil ditutup & diposting (SR-14 & SR-21): Expected=Counted Rp%s (Variance Rp%s). POS Cash Drawer Ledger: Rp%s, POS Sales Revenue: Rp%s, Output VAT: Rp%s, COGS: Rp%s (%s).',
            $sessionNumber,
            number_format($countedCash, 0, ',', '.'),
            number_format($variance, 0, ',', '.'),
            number_format((float) ($kpiNow['pos_cash_drawer'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['pos_sales_revenue'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['output_vat'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['cogs_balance'] ?? 0), 0, ',', '.'),
            $jeNumber ?? 'TANPA-JURNAL'
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Point of Sale',
            action: 'pos.session.close',
            documentNumber: $sessionNumber,
            oldStatus: $oldStatus,
            newStatus: 'Closed',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'              => true,
            'http_code'            => 200,
            'outcome'              => 'ALLOWED',
            'decision'             => 'ALLOWED',
            'rule'                 => 'SR-14',
            'status_changed'       => true,
            'document_preserved'   => false,
            'session_number'       => $sessionNumber,
            'expected_cash'        => $expectedCash,
            'counted_cash'         => $countedCash,
            'variance'             => $variance,
            'pos_cash_drawer'      => (float) ($kpiNow['pos_cash_drawer'] ?? 0),
            'pos_sales_revenue'    => (float) ($kpiNow['pos_sales_revenue'] ?? 0),
            'output_vat'           => (float) ($kpiNow['output_vat'] ?? 0),
            'cogs_balance'         => (float) ($kpiNow['cogs_balance'] ?? 0),
            'settlement_je_number' => $jeNumber,
            'status'               => 'Closed',
            'session'              => [
                'session_number'       => $sessionNumber,
                'expected_cash'        => $expectedCash,
                'counted_cash'         => $countedCash,
                'variance'             => $variance,
                'settlement_je_number' => $jeNumber,
                'status'               => 'Closed',
            ],
            'reason'               => $reason,
            'message'              => $reason,
            'audit_id'             => $auditId,
        ];
    }

    /**
     * Alias eksplisit untuk Rekonsiliasi Kasir (Cash Count) dan Penutupan Sesi POS di akhir sesi
     * sesuai wewenang Rani Oktaviani (Supervisor Kasir).
     */
    public static function reconcileCashCount(array $input): array
    {
        return self::closePosSession($input);
    }

    /**
     * Alias eksplisit posCheckout(): Checkout keranjang kasir (stok toko terpotong + jurnal COGS & Pendapatan) dalam satu transaksi ACID.
     */
    public static function posCheckout(array $input): array
    {
        return self::checkoutPosBasket($input);
    }

    /**
     * Fitur F-20 & Aturan SR-16, SR-21 (Layar S-17, Uji T-33):
     * POS Cash Refund & Restock atas struk POS yang sudah Paid.
     * - Memvalidasi return_qty <= (qty_sold - returned_qty).
     * - Jika Resalable, menambah stok On Hand lokasi toko dan membalik COGS (DR 1130 / CR 5010).
     * - Membalik POS Sales Revenue (DR 4020), Output VAT (DR 2030), dan mengurangi POS Cash Drawer (CR 1020).
     */
    public static function processPosRefund(array $input): array
    {
        return \RantauMart\Services\AtomicRunner::run(static fn (): array => self::processPosRefundAtomic($input));
    }

    /** Isi inti processPosRefund() - dijalankan di dalam transaksi PDO ACID (AtomicRunner). */
    private static function processPosRefundAtomic(array $input): array
    {
        $receiptNumber = trim((string) ($input['receipt_number'] ?? 'POS-RCPT-0001'));
        $variantSku    = trim((string) ($input['variant_sku'] ?? 'AIR-600'));
        $returnQty     = (int) ($input['return_qty'] ?? 1);
        $condition     = trim((string) ($input['condition'] ?? 'Resalable')) ?: 'Resalable';

        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? '');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canPerformAction($role, 'pos.return.refund')) {
            $reason = "F-01 & SR-18 Access Denied: Role '{$role}' diblokir saat mencoba memproses POS Cash Refund.";
            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.return.refund',
                documentNumber: $receiptNumber,
                oldStatus: 'Paid',
                newStatus: 'Paid',
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

        $pdo      = Database::getConnection();
        $stmtRcpt = $pdo->prepare('SELECT * FROM pos_receipts WHERE receipt_number = :rcpt LIMIT 1');
        $stmtRcpt->execute([':rcpt' => $receiptNumber]);
        $rcpt = $stmtRcpt->fetch();

        if (!$rcpt) {
            $reason = "Struk POS '{$receiptNumber}' tidak ditemukan.";
            return [
                'allowed'        => false,
                'http_code'      => 404,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'status_changed' => false,
                'reason'         => $reason,
                'message'        => $reason,
            ];
        }

        $stmtLine = $pdo->prepare("
            SELECT * FROM pos_receipt_lines
            WHERE receipt_number = :rcpt AND variant_sku = :sku
            LIMIT 1
        ");
        $stmtLine->execute([':rcpt' => $receiptNumber, ':sku' => $variantSku]);
        $line = $stmtLine->fetch();

        if (!$line) {
            $reason = "SKU '{$variantSku}' tidak ditemukan pada struk POS '{$receiptNumber}'.";
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

        $soldQty         = (int) $line['qty'];
        $alreadyReturned = (int) $line['returned_qty'];
        $maxReturnable   = max(0, $soldQty - $alreadyReturned);

        if ($returnQty <= 0 || $returnQty > $maxReturnable) {
            $reason = sprintf(
                'SR-16 Violation (POS Refund Melebihi Qty Struk): Percobaan refund %d pcs %s dari %s ditolak (Sold: %d, Sudah Diretur: %d, Sisa Bisa Diretur: %d pcs).',
                $returnQty,
                $variantSku,
                $receiptNumber,
                $soldQty,
                $alreadyReturned,
                $maxReturnable
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: 'Point of Sale',
                action: 'pos.return.refund',
                documentNumber: $receiptNumber,
                oldStatus: 'Paid',
                newStatus: 'Paid',
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'            => false,
                'http_code'          => 422,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-16',
                'status_changed'     => false,
                'document_preserved' => true,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        $locationCode = (string) ($rcpt['location_code'] ?? 'STORE-PKU');
        $unitPrice    = (float) $line['unit_price'];
        $standardCost = (float) $line['standard_cost'];

        $refundNet    = round($returnQty * $unitPrice, 0, PHP_ROUND_HALF_UP);
        $refundTax    = round($refundNet * 0.11, 0, PHP_ROUND_HALF_UP);
        $refundGross  = $refundNet + $refundTax;
        $reversedCogs = round($returnQty * $standardCost, 0, PHP_ROUND_HALF_UP);
        $isResalable  = ($condition === 'Resalable');

        if ($isResalable) {
            $pdo->prepare('UPDATE stock_by_location SET on_hand_qty = on_hand_qty + :qty WHERE location_code = :loc AND variant_sku = :sku')
                ->execute([':qty' => $returnQty, ':loc' => $locationCode, ':sku' => $variantSku]);
        }

        $returnNumber = DocumentStateGuard::nextDocumentNumber('RET-');

        $journalLines = [
            [
                'account_code' => '4020',
                'partner_id'   => 'C005',
                'debit'        => $refundNet,
                'credit'       => 0.0,
                'memo'         => sprintf('Reversal POS Sales Revenue %d pcs %s (%s)', $returnQty, $variantSku, $returnNumber),
            ],
            [
                'account_code' => '2030',
                'partner_id'   => 'C005',
                'debit'        => $refundTax,
                'credit'       => 0.0,
                'memo'         => sprintf('Reversal Output VAT POS (%s)', $returnNumber),
            ],
            [
                'account_code' => '1020',
                'partner_id'   => 'C005',
                'debit'        => 0.0,
                'credit'       => $refundGross,
                'memo'         => sprintf('Pengeluaran Kas Refund POS %s (%s)', $returnNumber, $receiptNumber),
            ],
        ];

        if ($isResalable) {
            $journalLines[] = [
                'account_code' => '1130',
                'partner_id'   => null,
                'debit'        => $reversedCogs,
                'credit'       => 0.0,
                'memo'         => sprintf('Restock Toko %s +%d pcs %s (%s)', $locationCode, $returnQty, $variantSku, $returnNumber),
            ];
            $journalLines[] = [
                'account_code' => '5010',
                'partner_id'   => 'C005',
                'debit'        => 0.0,
                'credit'       => $reversedCogs,
                'memo'         => sprintf('Reversal COGS POS %d pcs %s (%s)', $returnQty, $variantSku, $returnNumber),
            ];
        }

        $jeNumber = AccountingController::postBalancedJournal(
            $returnNumber,
            sprintf('POS Cash Refund %s atas %s (%d pcs %s %s)', $returnNumber, $receiptNumber, $returnQty, $variantSku, $condition),
            $journalLines
        );

        $pdo->prepare('UPDATE pos_receipt_lines SET returned_qty = returned_qty + :qty WHERE id = :id')
            ->execute([':qty' => $returnQty, ':id' => $line['id']]);

        // Update sesi POS tempat struk berasal agar expected_cash & cash_refunds terakumulasi akurat
        $rcptSessionNo = (string) ($rcpt['session_number'] ?? 'POS-SESSION-0001');
        $stmtSessUpd = $pdo->prepare("
            UPDATE pos_sessions
            SET cash_refunds  = cash_refunds + :ref_gross,
                expected_cash = opening_cash + cash_tenders - (cash_refunds + :ref_gross)
            WHERE session_number = :sess
        ");
        $stmtSessUpd->execute([
            ':ref_gross' => $refundGross,
            ':sess'      => $rcptSessionNo,
        ]);
        $stmtSessNow = $pdo->prepare("SELECT expected_cash FROM pos_sessions WHERE session_number = :sess LIMIT 1");
        $stmtSessNow->execute([':sess' => $rcptSessionNo]);
        $expectedClosingCashNow = (float) ($stmtSessNow->fetchColumn() ?: 200000.0);

        $pdo->prepare("
            INSERT INTO returns_refunds (
                return_number, return_type, source_document, location_code, variant_sku,
                return_qty, condition, restocked, refund_net, refund_tax, refund_gross,
                reversed_cogs, status, journal_entry_number
            ) VALUES (
                :ret_no, 'POS_REFUND', :src, :loc, :sku,
                :qty, :cond, :restocked, :net, :tax, :gross,
                :cogs, 'Done', :je_no
            )
        ")->execute([
            ':ret_no'    => $returnNumber,
            ':src'       => $receiptNumber,
            ':loc'       => $locationCode,
            ':sku'       => $variantSku,
            ':qty'       => $returnQty,
            ':cond'      => $condition,
            ':restocked' => $isResalable ? 1 : 0,
            ':net'       => $refundNet,
            ':tax'       => $refundTax,
            ':gross'     => $refundGross,
            ':cogs'      => $isResalable ? $reversedCogs : 0.0,
            ':je_no'     => $jeNumber,
        ]);

        $stockAfter = InventoryController::getLocationSkuStock($locationCode, $variantSku);
        $kpiNow     = AccountingController::getLiveKpiAndLedgerDashboard();

        $reason = sprintf(
            'POS Cash Refund %s atas %s berhasil (SR-16 & SR-21): %d pcs %s (%s) -> Refund Tunai Rp%s (Net Rp%s + PPN Rp%s), Restock %s menjadi %d pcs, Reversal COGS Rp%s. Cash Drawer: Rp%s, Net POS Revenue: Rp%s, Output VAT: Rp%s, COGS: Rp%s (%s).',
            $returnNumber,
            $receiptNumber,
            $returnQty,
            $variantSku,
            $condition,
            number_format($refundGross, 0, ',', '.'),
            number_format($refundNet, 0, ',', '.'),
            number_format($refundTax, 0, ',', '.'),
            $locationCode,
            (int) ($stockAfter['on_hand_qty'] ?? 0),
            number_format($reversedCogs, 0, ',', '.'),
            number_format((float) ($kpiNow['pos_cash_drawer'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['pos_sales_revenue'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['output_vat'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpiNow['cogs_balance'] ?? 0), 0, ',', '.'),
            $jeNumber
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: 'Point of Sale',
            action: 'pos.return.refund',
            documentNumber: $returnNumber,
            oldStatus: 'Paid',
            newStatus: 'Done',
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'              => true,
            'http_code'            => 200,
            'outcome'              => 'ALLOWED',
            'decision'             => 'ALLOWED',
            'rule'                 => 'SR-16',
            'status_changed'       => true,
            'document_preserved'   => false,
            'return_number'        => $returnNumber,
            'receipt_number'       => $receiptNumber,
            'location_code'        => $locationCode,
            'variant_sku'          => $variantSku,
            'return_qty'           => $returnQty,
            'store_on_hand_after'  => (int) ($stockAfter['on_hand_qty'] ?? 0),
            'refund_net'           => $refundNet,
            'refund_tax'           => $refundTax,
            'refund_gross'         => $refundGross,
            'reversed_cogs'        => $reversedCogs,
            'expected_closing_cash'=> $expectedClosingCashNow,
            'pos_cash_drawer'      => (float) ($kpiNow['pos_cash_drawer'] ?? 0),
            'pos_sales_revenue'    => (float) ($kpiNow['pos_sales_revenue'] ?? 0),
            'output_vat'           => (float) ($kpiNow['output_vat'] ?? 0),
            'cogs_balance'         => (float) ($kpiNow['cogs_balance'] ?? 0),
            'journal_entry_number' => $jeNumber,
            'refund'               => [
                'return_number'         => $returnNumber,
                'receipt_number'        => $receiptNumber,
                'location_code'         => $locationCode,
                'variant_sku'           => $variantSku,
                'return_qty'            => $returnQty,
                'new_store_on_hand'     => (int) ($stockAfter['on_hand_qty'] ?? 0),
                'net_refund'            => $refundNet,
                'tax_refund'            => $refundTax,
                'gross_refund'          => $refundGross,
                'cogs_reversed'         => $reversedCogs,
                'expected_closing_cash' => $expectedClosingCashNow,
                'journal_entry_number'  => $jeNumber,
            ],
            'reason'               => $reason,
            'message'              => $reason,
            'audit_id'             => $auditId,
        ];
    }
}
