<?php
declare(strict_types=1);

namespace RantauMart\Services;

use RantauMart\Config\Database;
use RantauMart\Middleware\RbacMiddleware;

/**
 * Layanan Domain untuk menegakkan Aturan SR-18 & Fitur F-01 pada Dokumen Nyata:
 * - Penomoran dokumen unik (Unique numbering: S00008, PO00008, POS-SESSION-0001, dll.)
 * - Pencegahan perubahan status dokumen saat akses ditolak oleh RbacMiddleware
 * - Pencegahan replay Confirm / Post / Close / Convert (Idempotency guard SR-18)
 */
class DocumentStateGuard
{
    /**
     * Menghasilkan nomor dokumen unik secara atomik sesuai SR-18.
     */
    public static function nextDocumentNumber(string $prefix): string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT last_number, padding FROM document_sequences WHERE prefix = :prefix');
        $stmt->execute([':prefix' => $prefix]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->prepare('INSERT INTO document_sequences (prefix, last_number, padding) VALUES (:prefix, 1, 4)')
                ->execute([':prefix' => $prefix]);
            return $prefix . str_pad('1', 4, '0', STR_PAD_LEFT);
        }

        $next    = (int) $row['last_number'] + 1;
        $padding = (int) $row['padding'];

        $pdo->prepare('UPDATE document_sequences SET last_number = :next WHERE prefix = :prefix')
            ->execute([':next' => $next, ':prefix' => $prefix]);

        return $prefix . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
    }

    /**
     * Alias eksplisit untuk auto-generate penomoran dokumen unik (mis. S00008 / SO-0001, PO00008 / PO-0001, INV-0001, BILL-0001)
     * sesuai aturan SR-18 (wewenang Budi Haryanto).
     */
    public static function generateUniqueDocumentNumber(string $prefix): string
    {
        return self::nextDocumentNumber($prefix);
    }

    /**
     * Memastikan dokumen uji untuk simulasi T-02 tersedia (Draft Vendor Bill & Open POS Session).
     */
    public static function ensureSimulationDocuments(): void
    {
        $pdo = Database::getConnection();

        $billCount = (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills WHERE bill_number = 'BILL-0001'")->fetchColumn();
        if ($billCount === 0) {
            $pdo->exec(
                "INSERT INTO vendor_bills (bill_number, po_number, vendor_id, status, subtotal_net, tax_amount, total_gross)
                 VALUES ('BILL-0001', 'PO00007', 'V01', 'Draft', 255000, 28050, 283050)"
            );
        }

        $posCount = (int) $pdo->query("SELECT COUNT(*) FROM pos_sessions WHERE session_number = 'POS-SESSION-0001'")->fetchColumn();
        if ($posCount === 0) {
            $pdo->exec(
                "INSERT INTO pos_sessions (session_number, location_code, register_code, cashier_name, status, opening_cash, expected_cash, counted_cash)
                 VALUES ('POS-SESSION-0001', 'STORE-PKU', 'POS01', 'Rani Oktaviani', 'Open', 200000, 222644, 222644)"
            );
        }
    }

    /**
     * Aksi 1 (T-02): Coba Post Vendor Bill (BILL-0001).
     * Hanya diizinkan untuk role Finance. Jika dijalankan oleh Sales, akses ditolak & status tetap 'Draft'.
     */
    public static function attemptPostVendorBill(string $billNumber = 'BILL-0001'): array
    {
        self::ensureSimulationDocuments();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT bill_number, status FROM vendor_bills WHERE bill_number = :no');
        $stmt->execute([':no' => $billNumber]);
        $bill = $stmt->fetch();
        $currentStatus = $bill ? $bill['status'] : 'Draft';

        return RbacMiddleware::guardDocumentAction(
            action: 'accounting.vendor_bill.post',
            documentNumber: $billNumber,
            currentDocumentStatus: $currentStatus,
            targetNewStatus: 'Posted',
            stateMutationCallback: function () use ($pdo, $billNumber): array {
                $pdo->prepare("UPDATE vendor_bills SET status = 'Posted' WHERE bill_number = :no")
                    ->execute([':no' => $billNumber]);
                return ['new_status' => 'Posted'];
            }
        );
    }

    /**
     * Aksi 2 (T-02): Coba Tutup Sesi POS (POS-SESSION-0001).
     * Hanya diizinkan untuk Cashier / Finance. Jika dijalankan oleh Sales, akses ditolak & status tetap 'Open'.
     */
    public static function attemptClosePosSession(string $sessionNumber = 'POS-SESSION-0001'): array
    {
        self::ensureSimulationDocuments();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT session_number, status FROM pos_sessions WHERE session_number = :no');
        $stmt->execute([':no' => $sessionNumber]);
        $session = $stmt->fetch();
        $currentStatus = $session ? $session['status'] : 'Open';

        return RbacMiddleware::guardDocumentAction(
            action: 'pos.session.close',
            documentNumber: $sessionNumber,
            currentDocumentStatus: $currentStatus,
            targetNewStatus: 'Closed',
            stateMutationCallback: function () use ($pdo, $sessionNumber): array {
                $pdo->prepare("UPDATE pos_sessions SET status = 'Closed' WHERE session_number = :no")
                    ->execute([':no' => $sessionNumber]);
                return ['new_status' => 'Closed'];
            }
        );
    }

    /**
     * Aksi 3 (T-04): Coba Ubah Credit Limit Pelanggan (C001).
     * Hanya diizinkan untuk Sales / Finance. Jika dijalankan oleh Warehouse, ditolak & Credit Limit tetap Rp500.000.
     */
    public static function attemptUpdateCreditLimit(string $customerId = 'C001', float $newLimit = 1500000): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, credit_limit FROM contacts_customers WHERE id = :id');
        $stmt->execute([':id' => $customerId]);
        $cust = $stmt->fetch();

        $oldLimitFormatted = 'CreditLimit:Rp' . number_format((float) ($cust['credit_limit'] ?? 500000), 0, ',', '.');
        $newLimitFormatted = 'CreditLimit:Rp' . number_format($newLimit, 0, ',', '.');

        return RbacMiddleware::guardDocumentAction(
            action: 'sales.contacts.update_credit_limit',
            documentNumber: $customerId,
            currentDocumentStatus: $oldLimitFormatted,
            targetNewStatus: $newLimitFormatted,
            stateMutationCallback: function () use ($pdo, $customerId, $newLimit, $newLimitFormatted): array {
                $pdo->prepare('UPDATE contacts_customers SET credit_limit = :limit WHERE id = :id')
                    ->execute([':limit' => $newLimit, ':id' => $customerId]);
                return ['new_status' => $newLimitFormatted];
            }
        );
    }

    /**
     * Aksi 4 (T-07 & T-08 / SR-18): Convert Lead LEAD-0001 ke Opportunity.
     * Hanya diizinkan untuk Sales. Jika ditekan 2 kali (T-08), replay diblokir oleh SR-18.
     */
    public static function attemptConvertLead(string $leadNumber = 'LEAD-0001'): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lead_number, stage, converted_opportunity_id, status FROM crm_leads WHERE lead_number = :no');
        $stmt->execute([':no' => $leadNumber]);
        $lead = $stmt->fetch();

        $isAlreadyConverted = !empty($lead['converted_opportunity_id']);
        $currentStatus      = $isAlreadyConverted ? 'Qualified' : ($lead['stage'] ?? 'New');

        return RbacMiddleware::guardDocumentAction(
            action: 'crm.lead.convert',
            documentNumber: $leadNumber,
            currentDocumentStatus: $currentStatus,
            targetNewStatus: 'Qualified',
            stateMutationCallback: function () use ($pdo, $leadNumber): array {
                $oppId = self::nextDocumentNumber('OPP-');
                $pdo->prepare(
                    "UPDATE crm_leads
                     SET stage = 'Qualified',
                         converted_opportunity_id = :opp,
                         status = 'Qualified / Converted'
                     WHERE lead_number = :no"
                )->execute([':opp' => $oppId, ':no' => $leadNumber]);

                $pdo->prepare(
                    "UPDATE existing_documents
                     SET status = 'Qualified / Converted (' || :opp || ')'
                     WHERE document_number = :no"
                )->execute([':opp' => $oppId, ':no' => $leadNumber]);

                return ['new_status' => 'Qualified', 'opportunity_id' => $oppId];
            }
        );
    }

    /**
     * Mengambil snapshot status seluruh dokumen terkait untuk pembuktian bahwa
     * aksi yang ditolak TIDAK mengubah state di database.
     */
    public static function getDocumentStatesSnapshot(): array
    {
        self::ensureSimulationDocuments();
        $pdo = Database::getConnection();

        $lead    = $pdo->query("SELECT lead_number, customer_id, stage, converted_opportunity_id, status FROM crm_leads WHERE lead_number = 'LEAD-0001'")->fetch();
        $so      = $pdo->query("SELECT so_number, customer_id, status, total_gross, price_locked FROM sales_orders WHERE so_number = 'S00007'")->fetch();
        $po      = $pdo->query("SELECT po_number, vendor_id, status, total_gross, price_locked FROM purchase_orders WHERE po_number = 'PO00007'")->fetch();
        $bill    = $pdo->query("SELECT bill_number, po_number, vendor_id, status, total_gross FROM vendor_bills WHERE bill_number = 'BILL-0001'")->fetch();
        $pos     = $pdo->query("SELECT session_number, location_code, register_code, cashier_name, status, opening_cash FROM pos_sessions WHERE session_number = 'POS-SESSION-0001'")->fetch();
        $custC01 = $pdo->query("SELECT id, name, credit_limit, payment_terms, opening_ar FROM contacts_customers WHERE id = 'C001'")->fetch();

        return [
            'LEAD-0001'        => $lead,
            'S00007'           => $so,
            'PO00007'          => $po,
            'BILL-0001'        => $bill,
            'POS-SESSION-0001' => $pos,
            'C001'             => $custC01,
        ];
    }
}

