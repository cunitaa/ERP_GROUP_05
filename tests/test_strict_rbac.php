<?php
declare(strict_types=1);

/**
 * Test Suite: Pembatasan Akses (RBAC) Ketat per Stakeholder (SR-18 & F-01)
 * Menguji Home App Switcher, Left Sidebar, dan Sistem Routing/Controller PHP
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'RantauMart\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
foreach (['Middleware.php', 'IntegrationEventTrigger.php', 'reset_fixture.php'] as $f) {
    require_once dirname(__DIR__) . '/' . $f;
}
foreach (['Sales', 'Purchase', 'Inventory', 'Accounting', 'Pos', 'Report'] as $c) {
    require_once dirname(__DIR__) . "/controllers/{$c}Controller.php";
}

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;

$passed = 0;
$failed = 0;
function assertTest(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

echo "=== PENGUJIAN STRICT RBAC & PEMBATASAN AKSES PER STAKEHOLDER ===\n\n";

Database::resetBaselineSeed();

// -----------------------------------------------------------------------------
// 1. PENGUJIAN HOME APP SWITCHER TILES PER ROLE
// -----------------------------------------------------------------------------
echo "--- 1. Verifikasi Visual Kartu Modul di Home App Switcher ---\n";

$ownerTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_OWNER);
$ownerPages = array_column($ownerTiles, 'page');
assertTest(
    count($ownerTiles) === 2 && in_array('dashboard', $ownerPages, true) && in_array('governance', $ownerPages, true)
    && !in_array('sales', $ownerPages, true) && !in_array('inventory', $ownerPages, true),
    '1.1 Budi Haryanto (Direktur): App Switcher HANYA berisi Executive KPI dan Audit/Laporan'
);

$salesTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_SALES);
$salesPages = array_column($salesTiles, 'page');
assertTest(
    count($salesTiles) === 1 && $salesPages[0] === 'sales',
    '1.2 Putri Aulia (Sales): App Switcher HANYA berisi modul Sales & CRM'
);

$whTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_WAREHOUSE);
$whPages = array_column($whTiles, 'page');
assertTest(
    count($whTiles) === 1 && $whPages[0] === 'inventory',
    '1.3 Deni Pratama (Gudang): App Switcher HANYA berisi modul Inventory'
);

$procTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_PROCUREMENT);
$procPages = array_column($procTiles, 'page');
assertTest(
    count($procTiles) === 1 && $procPages[0] === 'purchase',
    '1.4 Mira Salsabila (Purchase): App Switcher HANYA berisi modul Purchase'
);

$finTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_FINANCE);
$finPages = array_column($finTiles, 'page');
assertTest(
    count($finTiles) === 1 && $finPages[0] === 'accounting',
    '1.5 Arif Firmansyah (Finance): App Switcher HANYA berisi modul Accounting & Invoicing'
);

$posTiles = RolePermissions::getAppTilesForRole(RolePermissions::ROLE_CASHIER);
$posPages = array_column($posTiles, 'page');
assertTest(
    count($posTiles) === 1 && $posPages[0] === 'pos',
    '1.6 Rani Oktaviani (Kasir): App Switcher HANYA berisi modul Point of Sale'
);

// -----------------------------------------------------------------------------
// 2. PENGUJIAN LEFT SIDEBAR PER ROLE
// -----------------------------------------------------------------------------
echo "\n--- 2. Verifikasi Menu Left Sidebar per Stakeholder ---\n";

$ownerMenu = RolePermissions::getSidebarMenuForRole(RolePermissions::ROLE_OWNER);
$ownerModules = [];
foreach ($ownerMenu as $sec => $items) {
    foreach ($items as $it) {
        $ownerModules[] = $it['module'];
    }
}
assertTest(
    !in_array('Sales', $ownerModules, true) && !in_array('Purchase', $ownerModules, true) && !in_array('Point of Sale', $ownerModules, true),
    '2.1 Budi Haryanto (Direktur): Left Sidebar bebas dari form operasional Sales, Purchase, dan POS'
);

$salesMenu = RolePermissions::getSidebarMenuForRole(RolePermissions::ROLE_SALES);
$salesModules = [];
foreach ($salesMenu as $sec => $items) {
    foreach ($items as $it) {
        $salesModules[] = $it['module'];
    }
}
assertTest(
    !in_array('Inventory', $salesModules, true) && !in_array('Purchase', $salesModules, true) && !in_array('Accounting', $salesModules, true),
    '2.2 Putri Aulia (Sales): Left Sidebar HANYA untuk aktivitas Sales & CRM'
);

$procMenu = RolePermissions::getSidebarMenuForRole(RolePermissions::ROLE_PROCUREMENT);
$procModules = [];
foreach ($procMenu as $sec => $items) {
    foreach ($items as $it) {
        $procModules[] = $it['module'];
    }
}
assertTest(
    !in_array('Inventory', $procModules, true) && !in_array('Sales', $procModules, true),
    '2.3 Mira Salsabila (Purchase): Left Sidebar HANYA untuk aktivitas Purchase'
);

// -----------------------------------------------------------------------------
// 3. PENGUJIAN ROUTING & CONTROLLER URL GUARDS (HTTP 403 & AUTO REDIRECT)
// -----------------------------------------------------------------------------
echo "\n--- 3. Verifikasi Blokir URL Lintas Divisi (403 Access Denied) ---\n";

// Kasir memaksa masuk ke ?view=accounting
AuthService::login('Cashier');
$_GET = ['view' => 'accounting'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlCashierAccounting = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.1 Kasir mengetik ?view=accounting menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlCashierAccounting, '403 Access Denied')
    && str_contains($htmlCashierAccounting, 'Pembatasan Akses Antar Divisi'),
    '3.2 Banner peringatan 403 Access Denied tampil di layar'
);
assertTest(
    str_contains($htmlCashierAccounting, 'id="view-pos"')
    && !str_contains($htmlCashierAccounting, 'id="view-accounting"'),
    '3.3 Kasir otomatis dikembalikan ke modul wewenangnya (Point of Sale)'
);

// Sales memaksa masuk ke ?page=inventory
AuthService::login('Sales');
$_GET = ['page' => 'inventory'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlSalesInventory = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.4 Sales mengetik ?page=inventory menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlSalesInventory, 'id="view-sales"')
    && !str_contains($htmlSalesInventory, 'id="view-inventory"'),
    '3.5 Sales otomatis dikembalikan ke modul wewenangnya (Sales & CRM)'
);

// Warehouse memaksa masuk ke ?page=purchase
AuthService::login('Warehouse');
$_GET = ['page' => 'purchase'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlWhPurchase = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.6 Gudang mengetik ?page=purchase menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlWhPurchase, 'id="view-inventory"')
    && !str_contains($htmlWhPurchase, 'id="view-purchase"'),
    '3.7 Gudang otomatis dikembalikan ke modul wewenangnya (Inventory)'
);

// Procurement memaksa masuk ke ?page=accounting
AuthService::login('Procurement');
$_GET = ['page' => 'accounting'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlProcAcc = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.8 Purchase mengetik ?page=accounting menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlProcAcc, 'id="view-purchase"')
    && !str_contains($htmlProcAcc, 'id="view-accounting"'),
    '3.9 Purchase otomatis dikembalikan ke modul wewenangnya (Purchase)'
);

// Finance memaksa masuk ke ?page=pos
AuthService::login('Finance');
$_GET = ['page' => 'pos'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlFinPos = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.10 Finance mengetik ?page=pos menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlFinPos, 'id="view-accounting"')
    && !str_contains($htmlFinPos, 'id="view-pos"'),
    '3.11 Finance otomatis dikembalikan ke modul wewenangnya (Accounting)'
);

// Direktur (Owner) memaksa masuk ke ?page=sales
AuthService::login('Owner');
$_GET = ['page' => 'sales'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlOwnerSales = (string) ob_get_clean();

assertTest(
    http_response_code() === 403,
    '3.12 Direktur mengetik ?page=sales menghasilkan HTTP 403 Forbidden'
);
assertTest(
    str_contains($htmlOwnerSales, 'id="view-dashboard"')
    && !str_contains($htmlOwnerSales, 'id="view-sales"'),
    '3.13 Direktur otomatis dikembalikan ke Executive KPI Dashboard'
);

// Akses yang sah harus tetap 200 OK
AuthService::login('Sales');
$_GET = ['page' => 'sales'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlSalesValid = (string) ob_get_clean();

assertTest(
    http_response_code() === 200
    && str_contains($htmlSalesValid, 'id="view-sales"'),
    '3.14 Akses sah Sales ke ?page=sales diizinkan (HTTP 200 OK)'
);

// Verifikasi Audit Logs mencatat percobaan akses terlarang
$deniedLogs = AuditLogger::getRecentLogs(10, 'DENIED');
assertTest(
    !empty($deniedLogs) && count($deniedLogs) >= 6,
    '3.15 Seluruh percobaan pembobolan akses tercatat persisten di tabel audit_logs (SR-18)'
);

echo "\n=======================================================\n";
echo "HASIL AKHIR: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}

