<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Services\AuthService;

/**
 * DashboardController — Dasbor Eksekutif Direktur Operasional (Budi Haryanto) & Kepala Finance (Arif Firmansyah) — SR-19.
 *
 * Seluruh angka dihitung LIVE lewat kueri SQL langsung ke tabel transaksi (bukan angka statis):
 *   - Stok         : stock_by_location (On Hand, Reserved, Available)
 *   - AR / AP      : buku besar akun 1100 / 2010 (journal_entry_lines) — dibuktikan oleh rincian
 *                    customer_invoices / vendor_bills yang belum lunas + saldo awal kontak
 *   - Bank / Kas   : buku besar akun 1010 / 1020
 *   - POS Sales    : buku besar akun 4020 ; COGS : akun 5010
 * Hanya Role Finance atau Owner (Direktur) yang dirender / diizinkan (audit_logs saat ditolak, SR-18).
 */
class DashboardController
{
    public const DRILL_KEYS = ['stock', 'ar', 'ap', 'bank', 'cash', 'pos_sales', 'cogs'];

    public static function canView(?string $role = null): bool
    {
        $role ??= (string) (AuthService::getCurrentUser()['role'] ?? '');
        return ReportController::isAuthorizedRole($role);
    }

    /**
     * Saldo bersih satu akun dari jurnal berstatus Posted (termasuk Jurnal Pembukaan).
     * @return array{debit: float, credit: float}
     */
    private static function ledgerTotals(string $accountCode): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT COALESCE(SUM(jel.debit), 0) AS d, COALESCE(SUM(jel.credit), 0) AS c
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted' AND jel.account_code = :acc
        ");
        $stmt->execute([':acc' => $accountCode]);
        $row = $stmt->fetch() ?: [];
        return ['debit' => (float) ($row['d'] ?? 0), 'credit' => (float) ($row['c'] ?? 0)];
    }

    /** Kueri SQL: Total Stok (On Hand, Reserved, Available) seluruh lokasi. */
    public static function queryStockTotals(): array
    {
        $row = Database::getConnection()->query("
            SELECT COALESCE(SUM(on_hand_qty), 0) AS on_hand,
                   COALESCE(SUM(reserved_qty), 0) AS reserved,
                   COALESCE(SUM(on_hand_qty - reserved_qty), 0) AS available,
                   COALESCE(SUM(on_hand_qty * standard_cost), 0) AS valuation
            FROM stock_by_location
        ")->fetch() ?: [];
        return [
            'on_hand'   => (int) ($row['on_hand'] ?? 0),
            'reserved'  => (int) ($row['reserved'] ?? 0),
            'available' => (int) ($row['available'] ?? 0),
            'valuation' => (float) ($row['valuation'] ?? 0),
        ];
    }

    /** Kueri SQL: Saldo AR aktif (akun 1100). */
    public static function queryArBalance(): float
    {
        $t = self::ledgerTotals('1100');
        return $t['debit'] - $t['credit'];
    }

    /** Kueri SQL: Saldo AP aktif (akun 2010). */
    public static function queryApBalance(): float
    {
        $t = self::ledgerTotals('2010');
        return $t['credit'] - $t['debit'];
    }

    public static function queryBankBalance(): float
    {
        $t = self::ledgerTotals('1010');
        return $t['debit'] - $t['credit'];
    }

    public static function queryCashDrawer(): float
    {
        $t = self::ledgerTotals('1020');
        return $t['debit'] - $t['credit'];
    }

    public static function queryPosSales(): float
    {
        $t = self::ledgerTotals('4020');
        return $t['credit'] - $t['debit'];
    }

    public static function queryCogs(): float
    {
        $t = self::ledgerTotals('5010');
        return $t['debit'] - $t['credit'];
    }

    /**
     * Seluruh metrik kartu + bukti rekonsiliasi AR/AP terhadap dokumen sumber.
     */
    public static function getMetrics(): array
    {
        $pdo   = Database::getConnection();
        $stock = self::queryStockTotals();

        $openAr = (float) $pdo->query('SELECT COALESCE(SUM(opening_ar), 0) FROM contacts_customers')->fetchColumn();
        $openAp = (float) $pdo->query('SELECT COALESCE(SUM(opening_ap), 0) FROM contacts_vendors')->fetchColumn();
        $docAr  = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_amount), 0) FROM customer_invoices WHERE status IN ('Posted', 'Partially Paid')")->fetchColumn();
        $docAp  = (float) $pdo->query("SELECT COALESCE(SUM(outstanding_amount), 0) FROM vendor_bills WHERE status IN ('Posted', 'Partially Paid')")->fetchColumn();

        $ar = self::queryArBalance();
        $ap = self::queryApBalance();

        return [
            'stock'     => $stock,
            'ar'        => $ar,
            'ap'        => $ap,
            'bank'      => self::queryBankBalance(),
            'cash'      => self::queryCashDrawer(),
            'pos_sales' => self::queryPosSales(),
            'cogs'      => self::queryCogs(),
            'reconciliation' => [
                'ar_opening' => $openAr, 'ar_documents' => $docAr, 'ar_ledger' => $ar,
                'ar_difference' => $ar - ($openAr + $docAr),
                'ap_opening' => $openAp, 'ap_documents' => $docAp, 'ap_ledger' => $ap,
                'ap_difference' => $ap - ($openAp + $docAp),
            ],
        ];
    }

    /**
     * Rincian drill-down: tabel transaksi pembentuk angka agregat kartu.
     * @return array{key: string, title: string, subtitle: string, columns: array<int,string>, rows: array<int,array<int,string>>, total_label: string, total: float|null}
     */
    public static function getDrillDown(string $key): array
    {
        $pdo = Database::getConnection();
        $fmt = static fn (float $n): string => 'Rp' . number_format($n, 0, ',', '.');

        switch ($key) {
            case 'ar':
                $rows = [];
                $total = 0.0;
                foreach ($pdo->query('SELECT id, name, opening_ar FROM contacts_customers WHERE opening_ar > 0 ORDER BY id')->fetchAll() ?: [] as $r) {
                    $rows[] = ['Saldo Awal', $r['id'] . ' - ' . $r['name'], '-', 'Opening AR', $fmt((float) $r['opening_ar']), $fmt((float) $r['opening_ar'])];
                    $total += (float) $r['opening_ar'];
                }
                $inv = $pdo->query("
                    SELECT ci.invoice_number, ci.so_number, ci.status, ci.total_gross, ci.outstanding_amount, c.name AS customer
                    FROM customer_invoices ci
                    LEFT JOIN contacts_customers c ON c.id = ci.customer_id
                    WHERE ci.status IN ('Posted', 'Partially Paid') AND ci.outstanding_amount > 0
                    ORDER BY ci.invoice_number
                ")->fetchAll() ?: [];
                foreach ($inv as $r) {
                    $rows[] = [$r['invoice_number'], (string) $r['customer'], $r['so_number'], $r['status'], $fmt((float) $r['total_gross']), $fmt((float) $r['outstanding_amount'])];
                    $total += (float) $r['outstanding_amount'];
                }
                return [
                    'key' => 'ar', 'title' => 'Rincian Total AR - Customer Invoice Belum Lunas',
                    'subtitle' => 'Saldo awal pelanggan + Customer Invoice berstatus Posted / Partially Paid dengan sisa tagihan.',
                    'columns' => ['Dokumen', 'Pelanggan', 'Sales Order', 'Status', 'Nilai Tagihan', 'Sisa Piutang'],
                    'rows' => $rows, 'total_label' => 'Total Piutang Aktif', 'total' => $total,
                ];

            case 'ap':
                $rows = [];
                $total = 0.0;
                foreach ($pdo->query('SELECT id, name, opening_ap FROM contacts_vendors WHERE opening_ap > 0 ORDER BY id')->fetchAll() ?: [] as $r) {
                    $rows[] = ['Saldo Awal', $r['id'] . ' - ' . $r['name'], '-', 'Opening AP', $fmt((float) $r['opening_ap']), $fmt((float) $r['opening_ap'])];
                    $total += (float) $r['opening_ap'];
                }
                $bills = $pdo->query("
                    SELECT vb.bill_number, vb.po_number, vb.status, vb.total_gross, vb.outstanding_amount, v.name AS vendor
                    FROM vendor_bills vb
                    LEFT JOIN contacts_vendors v ON v.id = vb.vendor_id
                    WHERE vb.status IN ('Posted', 'Partially Paid') AND vb.outstanding_amount > 0
                    ORDER BY vb.bill_number
                ")->fetchAll() ?: [];
                foreach ($bills as $r) {
                    $rows[] = [$r['bill_number'], (string) $r['vendor'], (string) $r['po_number'], $r['status'], $fmt((float) $r['total_gross']), $fmt((float) $r['outstanding_amount'])];
                    $total += (float) $r['outstanding_amount'];
                }
                return [
                    'key' => 'ap', 'title' => 'Rincian Total AP - Vendor Bill Belum Lunas',
                    'subtitle' => 'Saldo awal vendor + Vendor Bill berstatus Posted / Partially Paid dengan sisa utang.',
                    'columns' => ['Dokumen', 'Vendor', 'Purchase Order', 'Status', 'Nilai Tagihan', 'Sisa Utang'],
                    'rows' => $rows, 'total_label' => 'Total Utang Aktif', 'total' => $total,
                ];

            case 'stock':
                $rows = [];
                $stmt = $pdo->query("
                    SELECT s.location_code, s.variant_sku, p.name, s.on_hand_qty, s.reserved_qty,
                           (s.on_hand_qty - s.reserved_qty) AS available
                    FROM stock_by_location s
                    LEFT JOIN products p ON p.variant_sku = s.variant_sku
                    WHERE s.on_hand_qty > 0 OR s.reserved_qty > 0
                    ORDER BY s.location_code, s.variant_sku
                ");
                foreach ($stmt->fetchAll() ?: [] as $r) {
                    $rows[] = [$r['location_code'], $r['variant_sku'], (string) $r['name'], (string) $r['on_hand_qty'], (string) $r['reserved_qty'], (string) $r['available']];
                }
                return [
                    'key' => 'stock', 'title' => 'Rincian Total Stok per Lokasi & SKU',
                    'subtitle' => 'On Hand, Reserved (SO Confirmed), dan Available di WH-PKU, STORE-PKU, STORE-DUM.',
                    'columns' => ['Lokasi', 'SKU', 'Produk', 'On Hand', 'Reserved', 'Available'],
                    'rows' => $rows, 'total_label' => 'Total On Hand (pcs)', 'total' => (float) self::queryStockTotals()['on_hand'],
                ];

            case 'bank':
                return self::ledgerDrill('1010', 'bank', 'Rincian Saldo Bank (Akun 1010)', 'Seluruh jurnal Posted pada akun Bank.', 'Saldo Bank', true);
            case 'cash':
                return self::ledgerDrill('1020', 'cash', 'Rincian Cash Drawer POS (Akun 1020)', 'Jurnal Posted pada akun POS Cash Drawer (uang fisik kasir).', 'Saldo Cash Drawer', true);
            case 'pos_sales':
                return self::ledgerDrill('4020', 'pos_sales', 'Rincian Pendapatan POS Sales (Akun 4020)', 'Jurnal pendapatan dari checkout struk POS (Paid) dikurangi refund.', 'Total Pendapatan POS', false);
            case 'cogs':
                return self::ledgerDrill('5010', 'cogs', 'Rincian COGS (Akun 5010)', 'Harga Pokok Penjualan dari delivery B2B dan checkout POS, dikurangi retur layak jual.', 'Total COGS', true);
        }

        return ['key' => $key, 'title' => 'Tidak dikenal', 'subtitle' => '', 'columns' => [], 'rows' => [], 'total_label' => '', 'total' => null];
    }

    /** Rincian berbasis baris jurnal buku besar untuk satu akun. */
    private static function ledgerDrill(string $account, string $key, string $title, string $subtitle, string $totalLabel, bool $debitNormal): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT je.entry_number, je.entry_date, je.source_document, jel.memo, jel.debit, jel.credit
            FROM journal_entry_lines jel
            JOIN journal_entries je ON je.entry_number = jel.entry_number
            WHERE je.status = 'Posted' AND jel.account_code = :acc
            ORDER BY je.entry_number, jel.id
        ");
        $stmt->execute([':acc' => $account]);
        $fmt  = static fn (float $n): string => $n > 0 ? 'Rp' . number_format($n, 0, ',', '.') : '-';
        $rows = [];
        $sum  = 0.0;
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $rows[] = [(string) $r['entry_number'], (string) $r['entry_date'], (string) $r['source_document'], (string) ($r['memo'] ?? ''), $fmt((float) $r['debit']), $fmt((float) $r['credit'])];
            $sum += $debitNormal ? ((float) $r['debit'] - (float) $r['credit']) : ((float) $r['credit'] - (float) $r['debit']);
        }
        return [
            'key' => $key, 'title' => $title, 'subtitle' => $subtitle,
            'columns' => ['Jurnal', 'Tanggal', 'Sumber Dokumen', 'Memo', 'Debit', 'Kredit'],
            'rows' => $rows, 'total_label' => $totalLabel, 'total' => $sum,
        ];
    }

    /**
     * Membangun view model Dasbor Eksekutif untuk index.php.
     * Mengembalikan null bila role bukan Direktur / Finance (kartu tidak dirender sama sekali).
     * Mengakses data via URL (?drill=...) oleh role lain dicatat ke audit_logs oleh authorizeAnalyticsAccess().
     */
    public static function buildViewModel(?string $drillKey = null, bool $explicitRequest = false): ?array
    {
        $role = (string) (AuthService::getCurrentUser()['role'] ?? '');
        if (!self::canView($role)) {
            if ($explicitRequest) {
                ReportController::authorizeAnalyticsAccess('dashboard.executive.view');
            }
            return null;
        }

        $drillKey = in_array((string) $drillKey, self::DRILL_KEYS, true) ? (string) $drillKey : null;

        return [
            'metrics'   => self::getMetrics(),
            'drill_key' => $drillKey,
            'drill'     => $drillKey !== null ? self::getDrillDown($drillKey) : null,
        ];
    }
}

