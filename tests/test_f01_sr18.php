<?php
declare(strict_types=1);

/**
 * Automated Verification Test Suite for Fitur F-01 & Aturan SR-18 (T-01, T-02, T-04, Replay Guard)
 */

spl_autoload_register(function (string $class): void {
    $prefix  = 'RantauMart\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        require_once $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    }
});

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

echo "=== Memulai Verifikasi F-01 & SR-18 (PT RantauMart Riau) ===\n";

// 0. Reset Baseline Seed
Database::resetBaselineSeed();
assertTrue(count(AuthService::getAllUsers()) >= 5, 'Minimal lima role wajib tersedia di tabel app_users (Sales, Warehouse, Procurement, Finance, Cashier, plus Owner)');

// 1. Uji Dummy Login untuk 5 Role Wajib
foreach (RolePermissions::MANDATORY_ROLES as $roleName) {
    $logged = AuthService::login($roleName);
    assertTrue($logged !== null && $logged['role'] === $roleName, "Dummy login berhasil untuk role '{$roleName}' ({$logged['full_name']})");
}

// 2. Uji T-01 (Normal Flow): Login sebagai Sales dari seed; buka Home Apps
AuthService::login('Sales');
$salesModules = RolePermissions::getVisibleModulesForRole('Sales');
assertTrue($salesModules['CRM']['accessible'] === true, 'T-01: Menu CRM tersedia untuk Sales');
assertTrue($salesModules['Sales']['accessible'] === true, 'T-01: Menu Sales tersedia untuk Sales');
assertTrue($salesModules['Purchase']['accessible'] === false, 'T-01: Modul Procurement (Purchase) TIDAK diberikan ke Sales');
assertTrue($salesModules['Accounting']['accessible'] === false, 'T-01: Modul Accounting TIDAK diberikan ke Sales');
assertTrue($salesModules['Point of Sale']['accessible'] === false, 'T-01: Modul Point of Sale TIDAK diberikan ke Sales');

// 3. Uji T-02 (Rule / Edge Case): Sebagai Sales, coba Post Vendor Bill & Tutup Sesi POS
$resBill = DocumentStateGuard::attemptPostVendorBill('BILL-0001');
assertTrue($resBill['allowed'] === false && $resBill['outcome'] === 'DENIED', 'T-02: Aksi Post Vendor Bill oleh Sales ditolak oleh RbacMiddleware');
assertTrue($resBill['status_changed'] === false && $resBill['new_status'] === 'Draft', 'T-02 / SR-18: Status BILL-0001 dicegah berubah (tetap Draft)');

$resPos = DocumentStateGuard::attemptClosePosSession('POS-SESSION-0001');
assertTrue($resPos['allowed'] === false && $resPos['outcome'] === 'DENIED', 'T-02: Aksi Tutup Sesi POS oleh Sales ditolak oleh RbacMiddleware');
assertTrue($resPos['status_changed'] === false && $resPos['new_status'] === 'Open', 'T-02 / SR-18: Status POS-SESSION-0001 dicegah berubah (tetap Open)');

// Verifikasi dari database langsung bahwa dokumen tidak berubah
$snapshotAfterT02 = DocumentStateGuard::getDocumentStatesSnapshot();
assertTrue($snapshotAfterT02['BILL-0001']['status'] === 'Draft', 'DB Check: BILL-0001 di database masih berstatus Draft');
assertTrue($snapshotAfterT02['POS-SESSION-0001']['status'] === 'Open', 'DB Check: POS-SESSION-0001 di database masih berstatus Open');

// 4. Uji T-04 (Rule / Edge Case): Sebagai Warehouse, coba ubah Credit Limit C001
AuthService::login('Warehouse');
$resLimit = DocumentStateGuard::attemptUpdateCreditLimit('C001', 1500000);
assertTrue($resLimit['allowed'] === false && $resLimit['outcome'] === 'DENIED', 'T-04: Aksi ubah Credit Limit C001 oleh Warehouse ditolak oleh RbacMiddleware');
$snapshotAfterT04 = DocumentStateGuard::getDocumentStatesSnapshot();
assertTrue((float) $snapshotAfterT04['C001']['credit_limit'] === 500000.0, 'DB Check: Credit Limit C001 tetap Rp500.000');

// 5. Uji SR-18 Replay / Idempotency Guard oleh role yang berwenang (Finance Post Vendor Bill 2x)
AuthService::login('Finance');
$resBillAllowed = DocumentStateGuard::attemptPostVendorBill('BILL-0001');
assertTrue($resBillAllowed['allowed'] === true && $resBillAllowed['new_status'] === 'Posted', 'Finance berhasil Post Vendor Bill BILL-0001 pertama kali (Draft -> Posted)');

$resBillReplay = DocumentStateGuard::attemptPostVendorBill('BILL-0001');
assertTrue($resBillReplay['allowed'] === false && $resBillReplay['outcome'] === 'REPLAY_BLOCKED', 'SR-18: Replay Post Vendor Bill kedua kali pada BILL-0001 diblokir (REPLAY_BLOCKED)');

// 6. Verifikasi Bukti Audit Persisten di Tabel audit_logs
$deniedLogs = AuditLogger::getRecentLogs(20, 'DENIED');
assertTrue(count($deniedLogs) >= 3, 'SR-18: Minimal 3 kejadian DENIED tercatat secara persisten di tabel audit_logs');

foreach ($deniedLogs as $log) {
    assertTrue(
        !empty($log['actor']) && !empty($log['created_at']) && !empty($log['action']) && !empty($log['reason']),
        "Audit Log #{$log['id']} lengkap: Aktor='{$log['actor']}', Waktu='{$log['created_at']}', Aksi='{$log['action']}', Dok='{$log['document_number']}', Status='{$log['old_status']}->{$log['new_status']}'"
    );
}

// Kembalikan ke baseline seed bersih + login Sales (siap untuk T-01 di browser)
HomeAppsController::resetBaselineFixture();
echo "=== Seluruh Pengujian F-01 & SR-18 LULUS 100% ===\n";

