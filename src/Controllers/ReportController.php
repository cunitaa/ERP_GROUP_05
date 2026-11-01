<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;

/**
 * ReportController — Dasbor Analitik & KPI Real-Time Direktur Operasional (Budi Haryanto) & Kepala Finance (Arif Firmansyah)
 * (Fitur F-01, F-16, F-22, F-23 & Aturan SR-18, SR-19, SR-21)
 *
 * Menangani:
 * 1. Pembatasan Akses Ketat (F-01 & SR-18):
 *    Hanya pengguna dengan role 'Finance' (Arif Firmansyah) atau 'Owner' / 'Direktur' (Budi Haryanto)
 *    yang diizinkan mengakses data agregat finansial ini. Role lain diblokir (HTTP 403) dan dicatat di audit_logs.
 * 2. Perhitungan Metrik KPI Real-Time berbasis SQL (SR-19):
 *    - Total Pendapatan (dari customer_invoices, pos_receipts, returns_refunds, dan Buku Besar 4010/4020/4030)
 *    - Total Piutang / AR dari tabel customer_invoices (serta rekonsiliasi dengan opening_ar & akun 1100)
 *    - Total Utang / AP dari tabel vendor_bills (serta rekonsiliasi dengan opening_ap & akun 2010)
 *    - Total Valuasi Stok Gudang dari tabel stock_by_location (WH-PKU, STORE-PKU, STORE-DUM & akun 1130)
 */
class ReportController
{
    /**
     * Daftar role yang diizinkan mengakses dasbor analitik finansial agregat.
     */
    public const ALLOWED_ANALYTICS_ROLES = [
        RolePermissions::ROLE_FINANCE,
        RolePermissions::ROLE_OWNER,
        'Direktur',
        'Director',
    ];

    /**
     * Memeriksa apakah role aktif berwenang mengakses data agregat finansial (Finance atau Direktur/Owner).
     */
    public static function isAuthorizedRole(string $role): bool
    {
        return in_array(trim($role), self::ALLOWED_ANALYTICS_ROLES, true);
    }

    /**
     * Guard otorisasi untuk akses laporan analitik finansial.
     * Mencatat ke audit_logs apabila akses ditolak (SR-18).
     */
    public static function authorizeAnalyticsAccess(string $action = 'report.financial_kpi.view'): array
    {
        $user  = AuthService::getCurrentUser();
        $role  = (string) ($user['role'] ?? 'Unauthenticated');
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!self::isAuthorizedRole($role)) {
            $reason = sprintf(
                "F-01 & SR-18 Access Denied: Role '%s' (%s) diblokir saat mencoba mengakses Dasbor Analitik Finansial Agregat. Hanya Role 'Finance' (Arif Firmansyah) atau 'Direktur / Owner' (Budi Haryanto) yang diizinkan.",
                $role,
                (string) ($user['full_name'] ?? 'Unknown')
            );

            $auditId = AuditLogger::logAccessDenied(
                actor: $actor,
                role: $role,
                module: 'Reporting',
                action: $action,
                documentNumber: 'KPI-FINANCIAL-DASHBOARD',
                currentStatus: 'Protected',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'decision'       => 'DENIED',
                'rule'           => 'SR-18',
                'status_changed' => false,
                'role'           => $role,
                'actor'          => $actor,
                'reason'         => $reason,
                'message'        => $reason,
                'audit_id'       => $auditId,
            ];
        }

        return [
            'allowed'   => true,
            'http_code' => 200,
            'outcome'   => 'ALLOWED',
            'decision'  => 'ALLOWED',
            'role'      => $role,
            'actor'     => $actor,
        ];
    }

    /**
     * Kueri SQL Real-Time 1: Menghitung Total Pendapatan (B2B Customer Invoices + POS Receipts - Returns).
     */
    public static function queryRevenueMetrics(): array
    {
        $pdo = Database::getConnection();

        // 1. Pendapatan B2B dari customer_invoices yang sudah Posted / Partially Paid / Paid
        $stmtB2b = $pdo->query("
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(subtotal_net), 0) AS b2b_revenue_net,
                COALESCE(SUM(tax_amount), 0) AS b2b_vat,
                COALESCE(SUM(total_gross), 0) AS b2b_gross
            FROM customer_invoices
            WHERE status IN ('Posted', 'Partially Paid', 'Paid')
        ");
        $b2bRow = $stmtB2b->fetch() ?: [];

        // 2. Pendapatan Ritel dari pos_receipts yang berstatus Paid
        $stmtPos = $pdo->query("
            SELECT
                COUNT(*) AS receipt_count,
                COALESCE(SUM(subtotal_net), 0) AS pos_revenue_net,
                COALESCE(SUM(tax_amount), 0) AS pos_vat,
                COALESCE(SUM(total_gross), 0) AS pos_gross
            FROM pos_receipts
            WHERE status = 'Paid'
        ");
        $posRow = $stmtPos->fetch() ?: [];

        // 3. Pengurang Pendapatan dari returns_refunds (Credit Note B2B & Refund POS)
        $stmtRet = $pdo->query("
            SELECT
                COALESCE(SUM(refund_net), 0) AS total_return_net,
                COALESCE(SUM(refund_tax), 0) AS total_return_tax,
                COALESCE(SUM(refund_gross), 0) AS total_return_gross
            FROM returns_refunds
            WHERE status = 'Done'
        ");
        $retRow = $stmtRet->fetch() ?: [];

        // 4. Saldo Pendapatan di General Ledger (Akun 4010, 4020, 4030)
        $stmtGlRev = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN jel.account_code IN ('4010', '4020') THEN (jel.credit - jel.debit) ELSE 0 END), 0) AS gl_gross_revenue,
                COALESCE(SUM(CASE WHEN jel.account_code = '4030' THEN (jel.debit - jel.credit) ELSE 0 END), 0) AS gl_sales_returns
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted'
              AND jel.account_code IN ('4010', '4020', '4030')
        ");
        $glRow = $stmtGlRev->fetch() ?: [];

        $b2bNet          = (float) ($b2bRow['b2b_revenue_net'] ?? 0.0);
        $posNet          = (float) ($posRow['pos_revenue_net'] ?? 0.0);
        $returnNet       = (float) ($retRow['total_return_net'] ?? 0.0);
        $grossRevenueNet = $b2bNet + $posNet;
        $netRevenue      = $grossRevenueNet - $returnNet;

        $b2bGross        = (float) ($b2bRow['b2b_gross'] ?? 0.0);
        $posGross        = (float) ($posRow['pos_gross'] ?? 0.0);
        $returnGross     = (float) ($retRow['total_return_gross'] ?? 0.0);
        $totalGrossSales = ($b2bGross + $posGross) - $returnGross;

        $glNetRevenue    = (float) ($glRow['gl_gross_revenue'] ?? 0.0) - (float) ($glRow['gl_sales_returns'] ?? 0.0);

        return [
            'invoice_count'       => (int) ($b2bRow['invoice_count'] ?? 0),
            'pos_receipt_count'   => (int) ($posRow['receipt_count'] ?? 0),
            'b2b_revenue_net'     => $b2bNet,
            'pos_revenue_net'     => $posNet,
            'sales_returns_net'   => $returnNet,
            'total_revenue_net'   => $netRevenue,
            'total_revenue_gross' => $totalGrossSales,
            'ledger_net_revenue'  => $glNetRevenue,
        ];
    }

    /**
     * Kueri SQL Real-Time 2: Menghitung Total Piutang (AR) dari tabel customer_invoices
     * beserta saldo pembukaan piutang (opening_ar) dan buku besar akun 1100.
     */
    public static function queryAccountsReceivableMetrics(): array
    {
        $pdo = Database::getConnection();

        // Kueri agregat langsung dari tabel customer_invoices
        $stmtInv = $pdo->query("
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(subtotal_net), 0) AS total_invoiced_net,
                COALESCE(SUM(tax_amount), 0) AS total_invoiced_tax,
                COALESCE(SUM(total_gross), 0) AS total_invoiced_gross,
                COALESCE(SUM(paid_amount), 0) AS total_paid_amount,
                COALESCE(SUM(outstanding_amount), 0) AS customer_invoices_ar
            FROM customer_invoices
            WHERE status IN ('Posted', 'Partially Paid', 'Paid')
        ");
        $invRow = $stmtInv->fetch() ?: [];

        // Piutang pembukaan dari contacts_customers (C002 = Rp888.000 pada seed)
        $openingAr = (float) $pdo->query("
            SELECT COALESCE(SUM(opening_ar), 0) FROM contacts_customers
        ")->fetchColumn();

        // Saldo Piutang di General Ledger (Akun 1100 Trade Receivables)
        $ledgerAr = (float) $pdo->query("
            SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted' AND jel.account_code = '1100'
        ")->fetchColumn();

        // Daftar detail customer_invoices untuk drill-down (SR-19)
        $invoices = $pdo->query("
            SELECT
                ci.invoice_number,
                ci.so_number,
                ci.customer_id,
                c.name AS customer_name,
                ci.variant_sku,
                ci.invoiced_qty,
                ci.subtotal_net,
                ci.tax_amount,
                ci.total_gross,
                ci.paid_amount,
                ci.outstanding_amount,
                ci.status,
                ci.journal_entry_number
            FROM customer_invoices ci
            LEFT JOIN contacts_customers c ON c.id = ci.customer_id
            ORDER BY ci.invoice_number ASC
        ")->fetchAll() ?: [];

        $invoicesAr = (float) ($invRow['customer_invoices_ar'] ?? 0.0);

        return [
            'invoice_count'        => (int) ($invRow['invoice_count'] ?? 0),
            'total_invoiced_net'   => (float) ($invRow['total_invoiced_net'] ?? 0.0),
            'total_invoiced_tax'   => (float) ($invRow['total_invoiced_tax'] ?? 0.0),
            'total_invoiced_gross' => (float) ($invRow['total_invoiced_gross'] ?? 0.0),
            'total_paid_amount'    => (float) ($invRow['total_paid_amount'] ?? 0.0),
            'customer_invoices_ar' => $invoicesAr,
            'opening_ar'           => $openingAr,
            'total_ar'             => $openingAr + $invoicesAr,
            'ledger_ar_1100'       => $ledgerAr,
            'invoices'             => $invoices,
        ];
    }

    /**
     * Kueri SQL Real-Time 3: Menghitung Total Utang (AP) dari tabel vendor_bills
     * beserta saldo pembukaan utang (opening_ap) dan buku besar akun 2010.
     */
    public static function queryAccountsPayableMetrics(): array
    {
        $pdo = Database::getConnection();

        // Kueri agregat langsung dari tabel vendor_bills
        $stmtBill = $pdo->query("
            SELECT
                COUNT(*) AS bill_count,
                COALESCE(SUM(subtotal_net), 0) AS total_billed_net,
                COALESCE(SUM(tax_amount), 0) AS total_billed_tax,
                COALESCE(SUM(total_gross), 0) AS total_billed_gross,
                COALESCE(SUM(paid_amount), 0) AS total_paid_amount,
                COALESCE(SUM(outstanding_amount), 0) AS vendor_bills_ap,
                COALESCE(SUM(ppv_amount), 0) AS total_ppv_amount
            FROM vendor_bills
            WHERE status IN ('Posted', 'Partially Paid', 'Paid')
        ");
        $billRow = $stmtBill->fetch() ?: [];

        // Utang pembukaan dari contacts_vendors (V02 = Rp555.000 pada seed)
        $openingAp = (float) $pdo->query("
            SELECT COALESCE(SUM(opening_ap), 0) FROM contacts_vendors
        ")->fetchColumn();

        // Saldo Utang di General Ledger (Akun 2010 Accounts Payable)
        $ledgerAp = (float) $pdo->query("
            SELECT COALESCE(SUM(jel.credit - jel.debit), 0)
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted' AND jel.account_code = '2010'
        ")->fetchColumn();

        // Daftar detail vendor_bills untuk drill-down (SR-19)
        $bills = $pdo->query("
            SELECT
                vb.bill_number,
                vb.po_number,
                vb.vendor_id,
                v.name AS vendor_name,
                vb.variant_sku,
                vb.accepted_qty,
                vb.billed_qty,
                vb.subtotal_net,
                vb.tax_amount,
                vb.total_gross,
                vb.ppv_amount,
                vb.paid_amount,
                vb.outstanding_amount,
                vb.status,
                vb.journal_entry_number
            FROM vendor_bills vb
            LEFT JOIN contacts_vendors v ON v.id = vb.vendor_id
            ORDER BY vb.bill_number ASC
        ")->fetchAll() ?: [];

        $billsAp = (float) ($billRow['vendor_bills_ap'] ?? 0.0);

        return [
            'bill_count'         => (int) ($billRow['bill_count'] ?? 0),
            'total_billed_net'   => (float) ($billRow['total_billed_net'] ?? 0.0),
            'total_billed_tax'   => (float) ($billRow['total_billed_tax'] ?? 0.0),
            'total_billed_gross' => (float) ($billRow['total_billed_gross'] ?? 0.0),
            'total_paid_amount'  => (float) ($billRow['total_paid_amount'] ?? 0.0),
            'vendor_bills_ap'    => $billsAp,
            'opening_ap'         => $openingAp,
            'total_ap'           => $openingAp + $billsAp,
            'ledger_ap_2010'     => $ledgerAp,
            'total_ppv_amount'   => (float) ($billRow['total_ppv_amount'] ?? 0.0),
            'bills'              => $bills,
        ];
    }

    /**
     * Kueri SQL Real-Time 4: Menghitung Total Valuasi Stok Gudang dari tabel stock_by_location
     * (total seluruh lokasi maupun per gudang/toko: WH-PKU, STORE-PKU, STORE-DUM).
     */
    public static function queryWarehouseStockValuation(): array
    {
        $pdo = Database::getConnection();

        // Kueri total valuasi stok seluruh lokasi gudang & toko
        $stmtTotal = $pdo->query("
            SELECT
                COALESCE(SUM(on_hand_qty), 0) AS total_on_hand_qty,
                COALESCE(SUM(reserved_qty), 0) AS total_reserved_qty,
                COALESCE(SUM(available_qty), 0) AS total_available_qty,
                COALESCE(SUM(on_hand_qty * standard_cost), 0) AS total_stock_valuation
            FROM stock_by_location
        ");
        $totRow = $stmtTotal->fetch() ?: [];

        // Kueri rincian valuasi per lokasi (WH-PKU, STORE-PKU, STORE-DUM)
        $stmtByLoc = $pdo->query("
            SELECT
                s.location_code,
                w.location_name,
                COALESCE(SUM(s.on_hand_qty), 0) AS on_hand_qty,
                COALESCE(SUM(s.reserved_qty), 0) AS reserved_qty,
                COALESCE(SUM(s.available_qty), 0) AS available_qty,
                COALESCE(SUM(s.on_hand_qty * s.standard_cost), 0) AS stock_valuation
            FROM stock_by_location s
            LEFT JOIN warehouses_locations w ON w.location_code = s.location_code
            GROUP BY s.location_code, w.location_name
            ORDER BY s.location_code ASC
        ");
        $byLocation = [];
        foreach ($stmtByLoc->fetchAll() ?: [] as $loc) {
            $code = (string) $loc['location_code'];
            $byLocation[$code] = [
                'location_code'   => $code,
                'location_name'   => (string) ($loc['location_name'] ?? $code),
                'on_hand_qty'     => (int) $loc['on_hand_qty'],
                'reserved_qty'    => (int) $loc['reserved_qty'],
                'available_qty'   => (int) $loc['available_qty'],
                'stock_valuation' => (float) $loc['stock_valuation'],
            ];
        }

        // Saldo akun 1130 Stock Valuation di General Ledger
        $ledgerStockVal = (float) $pdo->query("
            SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted' AND jel.account_code = '1130'
        ")->fetchColumn();

        return [
            'total_on_hand_qty'       => (int) ($totRow['total_on_hand_qty'] ?? 0),
            'total_reserved_qty'      => (int) ($totRow['total_reserved_qty'] ?? 0),
            'total_available_qty'     => (int) ($totRow['total_available_qty'] ?? 0),
            'total_stock_valuation'   => (float) ($totRow['total_stock_valuation'] ?? 0.0),
            'wh_pku_valuation'        => (float) ($byLocation['WH-PKU']['stock_valuation'] ?? 0.0),
            'store_pku_valuation'     => (float) ($byLocation['STORE-PKU']['stock_valuation'] ?? 0.0),
            'store_dum_valuation'     => (float) ($byLocation['STORE-DUM']['stock_valuation'] ?? 0.0),
            'ledger_valuation_1130'   => $ledgerStockVal,
            'by_location'             => $byLocation,
        ];
    }

    /**
     * Endpoint utama Dasbor Analitik Budi Haryanto (Direktur Operasional) & Arif Firmansyah (Finance).
     * Memblokir akses jika role pengguna bukan Finance atau Direktur/Owner (F-01 & SR-18).
     */
    public static function getDashboardAnalytics(): array
    {
        $auth = self::authorizeAnalyticsAccess('report.dashboard_analytics.view');
        if (!($auth['allowed'] ?? false)) {
            return $auth;
        }

        $revenue = self::queryRevenueMetrics();
        $ar      = self::queryAccountsReceivableMetrics();
        $ap      = self::queryAccountsPayableMetrics();
        $stock   = self::queryWarehouseStockValuation();

        return [
            'allowed'                  => true,
            'http_code'                => 200,
            'outcome'                  => 'ALLOWED',
            'decision'                 => 'ALLOWED',
            'rule'                     => 'SR-19',
            'role'                     => $auth['role'],
            'actor'                    => $auth['actor'],
            // Ringkasan 4 KPI Utama Real-Time
            'total_revenue'            => $revenue['total_revenue_net'],
            'total_revenue_gross'      => $revenue['total_revenue_gross'],
            'customer_invoices_ar'     => $ar['customer_invoices_ar'],
            'total_ar'                 => $ar['total_ar'],
            'vendor_bills_ap'          => $ap['vendor_bills_ap'],
            'total_ap'                 => $ap['total_ap'],
            'total_stock_valuation'    => $stock['total_stock_valuation'],
            'wh_pku_stock_valuation'   => $stock['wh_pku_valuation'],
            // Rincian & Drill-Down per Kategori
            'revenue_metrics'          => $revenue,
            'ar_metrics'               => $ar,
            'ap_metrics'               => $ap,
            'stock_valuation_metrics'  => $stock,
            'reason'                   => sprintf(
                'Dasbor Analitik Real-Time berhasil dimuat untuk %s (%s): Total Pendapatan Net Rp%s, Piutang AR Invoice Rp%s (Total AR Rp%s), Utang AP Bill Rp%s (Total AP Rp%s), Valuasi Stok Rp%s.',
                $auth['actor'],
                $auth['role'],
                number_format($revenue['total_revenue_net'], 0, ',', '.'),
                number_format($ar['customer_invoices_ar'], 0, ',', '.'),
                number_format($ar['total_ar'], 0, ',', '.'),
                number_format($ap['vendor_bills_ap'], 0, ',', '.'),
                number_format($ap['total_ap'], 0, ',', '.'),
                number_format($stock['total_stock_valuation'], 0, ',', '.')
            ),
        ];
    }

    /**
     * Alias eksplisit untuk mengambil metrik KPI real-time.
     */
    public static function getRealTimeKpiMetrics(): array
    {
        return self::getDashboardAnalytics();
    }
}

