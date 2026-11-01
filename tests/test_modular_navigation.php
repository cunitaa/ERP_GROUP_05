<?php
declare(strict_types=1);

/**
 * Test Suite: Arsitektur Modular & Navigasi Dinamis (Single Page Navigation & Fade-In)
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
use RantauMart\Services\AuthService;

$pass = 0;
$fail = 0;
function check(bool $ok, string $label): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
}

echo "=== PENGUJIAN ARSITEKTUR MODULAR & NAVIGASI DINAMIS ===\n\n";

Database::resetBaselineSeed();

// 1. Beranda Default (Landing Page): tanpa parameter (hanya index.php)
AuthService::login('Owner');
$_GET = [];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlOwnerHome = (string) ob_get_clean();

check(
    str_contains($htmlOwnerHome, 'data-testid="exec-dashboard"')
    && str_contains($htmlOwnerHome, 'Total Stok (On Hand)')
    && str_contains($htmlOwnerHome, 'Saldo AR (Piutang Aktif)')
    && str_contains($htmlOwnerHome, 'Saldo AP (Utang Aktif)')
    && str_contains($htmlOwnerHome, 'id="view-dashboard"')
    && !str_contains($htmlOwnerHome, 'id="view-sales"')
    && !str_contains($htmlOwnerHome, 'id="view-purchase"')
    && !str_contains($htmlOwnerHome, 'id="view-pos"'),
    'Landing Page (Owner/Direktur): Tanpa parameter URL wajib menampilkan Dashboard KPI Eksekutif (Card Grid) tanpa form modul lain'
);

// 2. Beranda Default untuk role Finance
AuthService::login('Finance');
$_GET = [];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlFinHome = (string) ob_get_clean();

check(
    str_contains($htmlFinHome, 'data-testid="exec-dashboard"')
    && str_contains($htmlFinHome, 'id="view-dashboard"')
    && !str_contains($htmlFinHome, 'id="view-sales"')
    && !str_contains($htmlFinHome, 'id="view-purchase"'),
    'Landing Page (Finance): Tanpa parameter URL wajib menampilkan Dashboard KPI Eksekutif (Card Grid)'
);

// 3. Beranda Default untuk role Sales (Landing Page Modul Card Grid)
AuthService::login('Sales');
$_GET = [];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlSalesHome = (string) ob_get_clean();

check(
    str_contains($htmlSalesHome, 'id="view-dashboard"')
    && !str_contains($htmlSalesHome, 'id="view-sales"')
    && !str_contains($htmlSalesHome, 'id="view-purchase"')
    && !str_contains($htmlSalesHome, 'id="view-inventory"'),
    'Landing Page (Sales): Tanpa parameter URL menampilkan Dashboard Card Grid ringkas; form Sales/CRM disembunyikan'
);

// 4. Navigasi Dinamis ke Modul Sales (?page=sales)
AuthService::login('Sales');
$_GET = ['page' => 'sales'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlSales = (string) ob_get_clean();

check(
    str_contains($htmlSales, 'id="view-sales"')
    && str_contains($htmlSales, 'CRM Pipeline')
    && str_contains($htmlSales, 'Buat Quotation / Sales Order Baru')
    && !str_contains($htmlSales, 'id="view-dashboard"')
    && !str_contains($htmlSales, 'id="view-purchase"')
    && !str_contains($htmlSales, 'id="view-inventory"')
    && !str_contains($htmlSales, 'id="view-accounting"')
    && !str_contains($htmlSales, 'id="view-pos"'),
    'Navigasi Modul (?page=sales): HANYA menampilkan Modul Sales & CRM; modul lain tidak dirender'
);

// 5. Navigasi Dinamis ke Modul Purchase (?page=purchase)
AuthService::login('Procurement');
$_GET = ['page' => 'purchase'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlProc = (string) ob_get_clean();

check(
    str_contains($htmlProc, 'id="view-purchase"')
    && str_contains($htmlProc, 'Form Pemesanan Vendor / Purchase Order')
    && !str_contains($htmlProc, 'id="view-dashboard"')
    && !str_contains($htmlProc, 'id="view-sales"')
    && !str_contains($htmlProc, 'id="view-inventory"'),
    'Navigasi Modul (?page=purchase): HANYA menampilkan Modul Purchase; modul lain tidak dirender'
);

// 6. Navigasi Dinamis ke Modul Inventory (?page=inventory)
AuthService::login('Warehouse');
$_GET = ['page' => 'inventory'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlWh = (string) ob_get_clean();

check(
    str_contains($htmlWh, 'id="view-inventory"')
    && str_contains($htmlWh, 'Delivery Order & Backorder')
    && str_contains($htmlWh, 'Internal Transfer Antar Gudang/Toko')
    && !str_contains($htmlWh, 'id="view-dashboard"')
    && !str_contains($htmlWh, 'id="view-sales"')
    && !str_contains($htmlWh, 'id="view-purchase"'),
    'Navigasi Modul (?page=inventory): HANYA menampilkan Modul Warehouse / Inventory; modul lain tidak dirender'
);

// 7. Navigasi Dinamis ke Modul Accounting (?page=accounting)
AuthService::login('Finance');
$_GET = ['page' => 'accounting'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlAcc = (string) ob_get_clean();

check(
    str_contains($htmlAcc, 'id="view-accounting"')
    && str_contains($htmlAcc, 'Customer Invoice')
    && str_contains($htmlAcc, 'Vendor Bill')
    && !str_contains($htmlAcc, 'id="view-dashboard"')
    && !str_contains($htmlAcc, 'id="view-sales"')
    && !str_contains($htmlAcc, 'id="view-pos"'),
    'Navigasi Modul (?page=accounting): HANYA menampilkan Modul Accounting; modul lain tidak dirender'
);

// 8. Navigasi Dinamis ke Modul Point of Sale (?page=pos)
AuthService::login('Cashier');
$_GET = ['page' => 'pos'];
$_POST = [];
ob_start();
require dirname(__DIR__) . '/index.php';
$htmlPos = (string) ob_get_clean();

check(
    str_contains($htmlPos, 'id="view-pos"')
    && str_contains($htmlPos, 'Keranjang Belanja POS')
    && str_contains($htmlPos, 'Rekonsiliasi Kas & Penutupan Sesi')
    && !str_contains($htmlPos, 'id="view-dashboard"')
    && !str_contains($htmlPos, 'id="view-sales"')
    && !str_contains($htmlPos, 'id="view-inventory"'),
    'Navigasi Modul (?page=pos): HANYA menampilkan Modul POS; modul lain tidak dirender'
);

// 9. Layout Induk Statis: Navbar, Role Switcher, dan Left Sidebar selalu ada di setiap modul
foreach ([$htmlOwnerHome, $htmlSales, $htmlProc, $htmlWh, $htmlAcc, $htmlPos] as $idx => $pageHtml) {
    check(
        str_contains($pageHtml, 'navbar')
        && str_contains($pageHtml, 'Role Switcher')
        && str_contains($pageHtml, 'o-sidebar')
        && str_contains($pageHtml, 'o-main-wrapper'),
        "Layout Induk Statis (Page #{$idx}): Navbar, Role Switcher, dan Left Sidebar tetap statis"
    );
}

// 10. Efek Fade-In Ringan SPA
check(
    str_contains($htmlSales, 'o-spa-fade-in')
    && str_contains($htmlSalesHome, 'o-spa-fade-in'),
    'Pembersihan & Efek Visual: Konten dilengkapi class animasi fade-in ringan bergaya SPA'
);

echo "\n=======================================================\n";
echo "HASIL AKHIR: {$pass} PASSED, {$fail} FAILED\n";
echo "=======================================================\n";

exit($fail > 0 ? 1 : 0);

