<?php
declare(strict_types=1);

/**
 * PT RantauMart Riau — Skrip Reset Fixture Database (reset_fixture.php)
 *
 * Wewenang Khusus: Budi Haryanto (Direktur Operasional / Owner / Admin).
 * Tugas Utama:
 * 1. Memvalidasi role secara mutlak di tingkat server: HANYA role Direktur / Owner / Admin
 *    yang diizinkan mengakses skrip ini. Percobaan akses oleh role lain (Sales, Warehouse,
 *    Procurement, Finance, Cashier) WAJIB ditolak dengan HTTP 403 Forbidden dan dicatat
 *    ke dalam tabel `audit_logs` (Aturan SR-18).
 * 2. Menghapus (truncate) seluruh data transaksi uji coba dan mereset database kembali ke
 *    kondisi awal sesuai Bagian 4. Dataset Realistis (schema.sql + seed.sql).
 * 3. Memastikan dan memverifikasi integritas pasca-reset:
 *    - 15 SKU varian produk (Total 1.500 pcs; Valuasi Stok Rp9.628.900)
 *    - 6 Pelanggan (C001 s.d. C006; Saldo Awal AR C002 Rp888.000)
 *    - 4 Vendor (V01 s.d. V04; Saldo Awal AP V01 Rp555.000)
 *    - Saldo Jurnal Pembukaan (JE-OPEN-0001 & Chart of Accounts) persis di angka
 *      Rp25.716.900 (Total Debit = Rp25.716.900 & Total Kredit = Rp25.716.900, seimbang SR-21).
 */

namespace RantauMart\Controllers {

    use PDO;
    use RantauMart\Config\Database;
    use RantauMart\Config\RolePermissions;
    use RantauMart\Services\AuditLogger;
    use RantauMart\Services\AuthService;
    use Throwable;

    class FixtureResetController
    {
        /**
         * Daftar role tingkat tinggi yang diizinkan mengeksekusi reset_fixture.php.
         */
        public const ALLOWED_RESET_ROLES = [
            RolePermissions::ROLE_OWNER, // Budi Haryanto (Direktur Operasional)
            'Direktur',
            'Director',
            'Admin',
        ];

        /**
         * Daftar tabel transaksi dan master yang di-truncate/dibersihkan saat reset fixture.
         */
        public const TRUNCATE_TABLES = [
            'audit_logs',
            'document_sequences',
            'journal_entry_lines',
            'journal_entries',
            'returns_refunds',
            'payments',
            'pos_receipt_lines',
            'pos_receipts',
            'pos_sessions',
            'internal_transfers',
            'purchase_receipts',
            'delivery_orders',
            'vendor_bills',
            'customer_invoices',
            'purchase_order_lines',
            'purchase_orders',
            'sales_order_lines',
            'sales_orders',
            'crm_leads',
            'existing_documents',
            'stock_by_location',
            'opening_stock',
            'vendor_pricelist',
            'products',
            'contacts_customers',
            'contacts_vendors',
            'warehouses_locations',
            'chart_of_accounts',
            'tax_rates',
            'payment_terms',
            'app_users',
        ];

        /**
         * Memeriksa apakah role pengguna saat ini memiliki hak akses Direktur/Owner/Admin.
         */
        public static function isAuthorizedRole(string $role): bool
        {
            foreach (self::ALLOWED_RESET_ROLES as $allowed) {
                if (strcasecmp(trim($role), $allowed) === 0) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Menjalankan truncate seluruh data transaksi uji coba, mereset ke baseline Bagian 4,
         * serta memverifikasi 15 SKU, 6 pelanggan, 4 vendor, dan Jurnal Pembukaan Rp25.716.900.
         *
         * @param array<string, mixed> $requestData
         * @return array<string, mixed>
         */
        public static function executeReset(array $requestData = []): array
        {
            AuthService::ensureSession();
            $requestedRole = trim((string) ($requestData['switch_role'] ?? $requestData['as_role'] ?? $requestData['role'] ?? ''));
            if ($requestedRole !== '') {
                AuthService::login($requestedRole);
            }
            $user      = AuthService::getCurrentUser();
            $role      = (string) ($user['role'] ?? '');
            $actorName = (string) ($user['full_name'] ?? $user['username'] ?? 'Unknown');
            $username  = (string) ($user['username'] ?? 'unknown');

            // 1. Proteksi Role Mutlak: Hanya Direktur Operasional (Owner) / Admin
            if (!self::isAuthorizedRole($role)) {
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(403);
                }

                $reason = sprintf(
                    'HTTP 403 Forbidden (SR-18): Akses reset_fixture.php ditolak untuk %s (%s — Role: %s). '
                    . 'Skrip reset fixture hanya dapat diakses oleh Direktur Operasional (Owner) / Admin.',
                    $actorName,
                    $username,
                    $role
                );

                $auditId = AuditLogger::logAccessDenied(
                    "{$actorName} ({$username})",
                    $role,
                    'System',
                    'system.fixture.reset',
                    'BASELINE-FIXTURE',
                    'Active',
                    $reason
                );

                return [
                    'allowed'     => false,
                    'http_status' => 403,
                    'status'      => '403 Forbidden',
                    'outcome'     => 'DENIED',
                    'module'      => 'System',
                    'action'      => 'system.fixture.reset',
                    'actor'       => "{$actorName} ({$username})",
                    'role'        => $role,
                    'audit_id'    => $auditId,
                    'reason'      => $reason,
                ];
            }

            try {
                $pdo    = Database::getConnection();
                $driver = Database::getActiveDriver();

                // 2. Truncate / hapus seluruh data transaksi uji coba secara eksplisit
                if ($driver === 'pgsql') {
                    $tableList = implode(', ', self::TRUNCATE_TABLES);
                    $pdo->exec("TRUNCATE TABLE {$tableList} RESTART IDENTITY CASCADE;");
                } else {
                    $pdo->exec('PRAGMA foreign_keys = OFF;');
                    foreach (self::TRUNCATE_TABLES as $table) {
                        try {
                            $pdo->exec("DELETE FROM {$table};");
                        } catch (Throwable $ignore) {
                            // Tabel akan dibuat ulang oleh resetBaselineSeed()
                        }
                    }
                    $pdo->exec('PRAGMA foreign_keys = ON;');
                }

                // 3. Pulihkan schema & seed ke kondisi awal (Bagian 4. Dataset Realistis)
                Database::resetBaselineSeed();

                // 4. Verifikasi ketat metrik Dataset Realistis (Bagian 4)
                $verification = self::verifyBaselineIntegrity($pdo);

                if (!$verification['all_checks_passed']) {
                    throw new \RuntimeException(
                        'Verifikasi pasca-reset gagal: ' . json_encode($verification, JSON_UNESCAPED_UNICODE)
                    );
                }

                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(200);
                }

                $reason = sprintf(
                    'Database berhasil di-truncate dan direset ke kondisi awal (Bagian 4. Dataset Realistis) oleh %s (%s): '
                    . '%d SKU (%s pcs · Valuasi Rp%s), %d Pelanggan, %d Vendor, dan Saldo Jurnal Pembukaan (JE-OPEN-0001) '
                    . 'seimbang persis di Rp%s (Debit Rp%s = Kredit Rp%s).',
                    $actorName,
                    $role,
                    $verification['sku_count'],
                    number_format($verification['total_stock_pcs'], 0, ',', '.'),
                    number_format($verification['total_stock_valuation'], 0, ',', '.'),
                    $verification['customer_count'],
                    $verification['vendor_count'],
                    number_format($verification['opening_journal_debit'], 0, ',', '.'),
                    number_format($verification['opening_journal_debit'], 0, ',', '.'),
                    number_format($verification['opening_journal_credit'], 0, ',', '.')
                );

                // Catat aktivitas reset fixture ke audit_logs setelah reset selesai
                $auditId = AuditLogger::log(
                    "{$actorName} ({$username})",
                    $role,
                    'System',
                    'system.fixture.reset',
                    'BASELINE-FIXTURE',
                    'Dirty / Test Data',
                    'Baseline Clean (Rp25.716.900)',
                    'ALLOWED',
                    $reason
                );

                return [
                    'allowed'      => true,
                    'http_status'  => 200,
                    'status'       => '200 OK',
                    'outcome'      => 'ALLOWED',
                    'module'       => 'System',
                    'action'       => 'system.fixture.reset',
                    'actor'        => "{$actorName} ({$username})",
                    'role'         => $role,
                    'audit_id'     => $auditId,
                    'reason'       => $reason,
                    'verification' => $verification,
                ];
            } catch (Throwable $e) {
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                }
                return [
                    'allowed'     => false,
                    'http_status' => 500,
                    'status'      => '500 Internal Server Error',
                    'outcome'     => 'DENIED',
                    'module'      => 'System',
                    'action'      => 'system.fixture.reset',
                    'actor'       => "{$actorName} ({$username})",
                    'role'        => $role,
                    'reason'      => 'Gagal mereset fixture database: ' . $e->getMessage(),
                ];
            }
        }

        /**
         * Memverifikasi secara matematis bahwa kondisi database pasca-reset sesuai persis
         * dengan Bagian 4. Dataset Realistis (15 SKU, 6 pelanggan, 4 vendor, Jurnal Pembukaan Rp25.716.900).
         *
         * @return array<string, mixed>
         */
        public static function verifyBaselineIntegrity(?PDO $pdo = null): array
        {
            $pdo = $pdo ?? Database::getConnection();

            $skuCount      = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
            $customerCount = (int) $pdo->query("SELECT COUNT(*) FROM contacts_customers")->fetchColumn();
            $vendorCount   = (int) $pdo->query("SELECT COUNT(*) FROM contacts_vendors")->fetchColumn();
            $locationCount = (int) $pdo->query("SELECT COUNT(*) FROM warehouses_locations")->fetchColumn();

            $stockStmt = $pdo->query("
                SELECT
                    COALESCE(SUM(company_total_pcs), 0) AS total_pcs,
                    COALESCE(SUM(standard_value), 0)    AS total_val
                FROM opening_stock
            ");
            $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalStockPcs       = (int) ($stockRow['total_pcs'] ?? 0);
            $totalStockValuation = (float) ($stockRow['total_val'] ?? 0.0);

            // Jurnal Pembukaan (JE-OPEN-0001)
            $jeStmt = $pdo->prepare("
                SELECT entry_number, status, total_debit, total_credit
                FROM journal_entries
                WHERE entry_number = 'JE-OPEN-0001'
                LIMIT 1
            ");
            $jeStmt->execute();
            $jeRow = $jeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $jeDebit  = (float) ($jeRow['total_debit'] ?? 0.0);
            $jeCredit = (float) ($jeRow['total_credit'] ?? 0.0);

            // Baris Jurnal Pembukaan (journal_entry_lines)
            $jeLinesStmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(debit), 0)  AS sum_debit,
                    COALESCE(SUM(credit), 0) AS sum_credit,
                    COUNT(*)                 AS line_count
                FROM journal_entry_lines
                WHERE entry_number = 'JE-OPEN-0001'
            ");
            $jeLinesStmt->execute();
            $jeLinesRow = $jeLinesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $linesDebit  = (float) ($jeLinesRow['sum_debit'] ?? 0.0);
            $linesCredit = (float) ($jeLinesRow['sum_credit'] ?? 0.0);
            $linesCount  = (int) ($jeLinesRow['line_count'] ?? 0);

            // Saldo Pembukaan Chart of Accounts
            $coaStmt = $pdo->query("
                SELECT
                    COALESCE(SUM(opening_debit), 0)  AS coa_debit,
                    COALESCE(SUM(opening_credit), 0) AS coa_credit,
                    COUNT(*)                         AS account_count
                FROM chart_of_accounts
            ");
            $coaRow = $coaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $coaDebit  = (float) ($coaRow['coa_debit'] ?? 0.0);
            $coaCredit = (float) ($coaRow['coa_credit'] ?? 0.0);
            $coaCount  = (int) ($coaRow['account_count'] ?? 0);

            // Pastikan tabel transaksi uji coba bersih (0 invoice, 0 vendor bill, 0 payment, 0 POS session)
            $invoiceCount    = (int) $pdo->query("SELECT COUNT(*) FROM customer_invoices")->fetchColumn();
            $billCount       = (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills")->fetchColumn();
            $paymentCount    = (int) $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
            $posSessionCount = (int) $pdo->query("SELECT COUNT(*) FROM pos_sessions")->fetchColumn();
            $nonOpeningJe    = (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE is_opening_entry = FALSE OR entry_number <> 'JE-OPEN-0001'")->fetchColumn();

            $expectedBalance = 25716900.0;
            $isBalanced = (abs($jeDebit - $expectedBalance) < 0.01)
                && (abs($jeCredit - $expectedBalance) < 0.01)
                && (abs($linesDebit - $expectedBalance) < 0.01)
                && (abs($linesCredit - $expectedBalance) < 0.01)
                && (abs($coaDebit - $expectedBalance) < 0.01)
                && (abs($coaCredit - $expectedBalance) < 0.01);

            $allPassed = ($skuCount === 15)
                && ($customerCount === 6)
                && ($vendorCount === 4)
                && ($locationCount === 3)
                && ($totalStockPcs === 1500)
                && (abs($totalStockValuation - 9628900.0) < 0.01)
                && $isBalanced
                && ($invoiceCount === 0)
                && ($billCount === 0)
                && ($paymentCount === 0)
                && ($posSessionCount === 0)
                && ($nonOpeningJe === 0);

            return [
                'sku_count'              => $skuCount,
                'expected_sku_count'     => 15,
                'customer_count'         => $customerCount,
                'expected_customer_count'=> 6,
                'vendor_count'           => $vendorCount,
                'expected_vendor_count'  => 4,
                'location_count'         => $locationCount,
                'total_stock_pcs'        => $totalStockPcs,
                'total_stock_valuation'  => $totalStockValuation,
                'opening_entry_number'   => (string) ($jeRow['entry_number'] ?? 'JE-OPEN-0001'),
                'opening_journal_debit'  => $jeDebit,
                'opening_journal_credit' => $jeCredit,
                'opening_lines_debit'    => $linesDebit,
                'opening_lines_credit'   => $linesCredit,
                'opening_lines_count'    => $linesCount,
                'coa_opening_debit'      => $coaDebit,
                'coa_opening_credit'     => $coaCredit,
                'coa_account_count'      => $coaCount,
                'expected_opening_total' => $expectedBalance,
                'is_balanced'            => $isBalanced,
                'clean_transactions'     => [
                    'customer_invoices'    => $invoiceCount,
                    'vendor_bills'         => $billCount,
                    'payments'             => $paymentCount,
                    'pos_sessions'         => $posSessionCount,
                    'test_journal_entries' => $nonOpeningJe,
                ],
                'all_checks_passed'      => $allPassed,
            ];
        }
    }
}

namespace {

    use RantauMart\Controllers\FixtureResetController;
    use RantauMart\Services\AuthService;

    spl_autoload_register(function (string $class): void {
        $prefix  = 'RantauMart\\';
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    // Jika file ini dieksekusi langsung sebagai endpoint HTTP / skrip utama (bukan sekadar di-include)
    if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ && !defined('RANTAUMART_SKIP_RESET_AUTORUN')) {
        AuthService::ensureSession();

        $requestData = array_merge($_GET ?? [], $_POST ?? []);
        $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $formatParam  = (string) ($requestData['format'] ?? '');
        $wantsJson    = ($formatParam === 'json') || str_contains($acceptHeader, 'application/json');
        $shouldRedirect = isset($requestData['redirect']) && (string) $requestData['redirect'] === '1';

        $result = FixtureResetController::executeReset($requestData);

        if ($shouldRedirect && PHP_SAPI !== 'cli' && !headers_sent()) {
            $_SESSION['flash_toast'] = $result;
            $targetModule = ((bool) ($result['allowed'] ?? false)) ? 'Reporting' : 'Apps';
            header('Location: index.php?module=' . urlencode($targetModule));
            exit;
        }

        if ($wantsJson) {
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $httpStatus   = (int) ($result['http_status'] ?? 200);
        $allowed      = (bool) ($result['allowed'] ?? false);
        $verification = (array) ($result['verification'] ?? []);
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Fixture Database — PT RantauMart Riau</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-5">
<div class="container" style="max-width: 820px;">
    <div class="card border-0 rounded-4 shadow-sm overflow-hidden">
        <div class="card-header py-3 px-4 <?= $allowed ? 'bg-success text-white' : 'bg-danger text-white' ?> d-flex justify-content-between align-items-center">
            <h1 class="h5 fw-bold mb-0">
                <?= $allowed ? '✓ Reset Fixture Database Berhasil (Bagian 4. Dataset Realistis)' : '⛔ HTTP 403 Forbidden — Akses Reset Fixture Ditolak (SR-18)' ?>
            </h1>
            <span class="badge bg-white text-dark"><?= htmlspecialchars((string) ($result['status'] ?? $httpStatus)) ?></span>
        </div>
        <div class="card-body p-4">
            <p class="mb-3">
                <strong>Aktor Aktif:</strong> <?= htmlspecialchars((string) ($result['actor'] ?? '-')) ?>
                · <strong>Role:</strong> <span class="badge bg-secondary"><?= htmlspecialchars((string) ($result['role'] ?? '-')) ?></span>
            </p>
            <div class="alert <?= $allowed ? 'alert-success' : 'alert-danger' ?> rounded-3">
                <?= htmlspecialchars((string) ($result['reason'] ?? '')) ?>
            </div>

            <?php if ($allowed && !empty($verification)): ?>
                <h2 class="h6 fw-bold mt-4 mb-3">Ringkasan Verifikasi Integritas Dataset Realistis (Bagian 4)</h2>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded-3 bg-light text-center">
                            <div class="text-muted small">Varian Produk</div>
                            <div class="h4 fw-bold text-success mb-0"><?= (int) ($verification['sku_count'] ?? 0) ?> SKU</div>
                            <div class="small text-secondary"><?= number_format((int) ($verification['total_stock_pcs'] ?? 0), 0, ',', '.') ?> pcs</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded-3 bg-light text-center">
                            <div class="text-muted small">Pelanggan</div>
                            <div class="h4 fw-bold text-success mb-0"><?= (int) ($verification['customer_count'] ?? 0) ?> Mitra</div>
                            <div class="small text-secondary">C001 – C006</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded-3 bg-light text-center">
                            <div class="text-muted small">Vendor</div>
                            <div class="h4 fw-bold text-success mb-0"><?= (int) ($verification['vendor_count'] ?? 0) ?> Pemasok</div>
                            <div class="small text-secondary">V01 – V04</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded-3 bg-light text-center">
                            <div class="text-muted small">Jurnal Pembukaan</div>
                            <div class="h6 fw-bold text-success mb-0">Rp<?= number_format((float) ($verification['opening_journal_debit'] ?? 0), 0, ',', '.') ?></div>
                            <div class="small text-secondary">Debit = Kredit (Seimbang)</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                <a href="index.php" class="btn btn-dark rounded-3 px-4">← Kembali ke Dashboard Utama</a>
                <a href="reset_fixture.php?format=json" class="btn btn-outline-secondary rounded-3">Lihat Output JSON</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
        <?php
    }
}
