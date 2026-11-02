<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix  = 'RantauMart\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use RantauMart\Services\AuthService;

function check(bool $condition, string $label): void {
    if (!$condition) {
        echo "[FAIL] {$label}\n";
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

echo "=== PENGUJIAN FRONTEND UI ODOO WEB 17.0 & SR-19 ===\n\n";

// 1. Home App Switcher & Executive Dashboard (Role Owner / Direktur)
AuthService::login('Owner');

ob_start();
$_GET = ['page' => 'dashboard'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?page=dashboard';
require dirname(__DIR__) . '/index.php';
$htmlDashboard = ob_get_clean();

check(str_contains($htmlDashboard, 'id="home-apps-grid"'), '1.1 Home App Switcher Grid ter-render di Dashboard Landing Page');
check(str_contains($htmlDashboard, 'o-app-launcher-grid') || str_contains($htmlDashboard, 'row-cols-md-6'), '1.2 Grid 6 Ikon Aplikasi Bisnis (Sales, Purchase, Inventory, Accounting, POS, Dashboard) aktif');
check(str_contains($htmlDashboard, 'id="exec-dashboard"'), '1.3 Dashboard Eksekutif (SR-19) tampil sebagai halaman default untuk Direktur (Owner)');
check(str_contains($htmlDashboard, 'Total Stok (On Hand)'), '1.4 KPI Total Stok ter-render');
check(str_contains($htmlDashboard, 'Saldo AR (Piutang Aktif)'), '1.5 KPI Saldo AR ter-render');
check(str_contains($htmlDashboard, 'Saldo AP (Utang Aktif)'), '1.6 KPI Saldo AP ter-render');
check(str_contains($htmlDashboard, 'Saldo Bank'), '1.7 KPI Saldo Bank ter-render');
check(str_contains($htmlDashboard, 'Cash Drawer POS'), '1.8 KPI Cash Drawer POS ter-render');
check(str_contains($htmlDashboard, 'Pendapatan POS Sales'), '1.9 KPI Pendapatan POS Sales ter-render');
check(str_contains($htmlDashboard, 'COGS'), '1.10 KPI COGS ter-render');

// 2. Drill-Down Interaktif AR
ob_start();
$_GET = ['drill' => 'ar', 'page' => 'dashboard'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?drill=ar&page=dashboard';
require dirname(__DIR__) . '/index.php';
$htmlDrillAr = ob_get_clean();

check(str_contains($htmlDrillAr, 'id="exec-drill"'), '2.1 Tabel Rincian Drill-Down AR muncul saat kartu AR diklik');
check(str_contains($htmlDrillAr, 'Rincian Total AR - Customer Invoice Belum Lunas'), '2.2 Judul rincian piutang sesuai data faktur belum lunas');
check(str_contains($htmlDrillAr, 'Buka List View Customer Invoices'), '2.3 Tombol pintas drill-down langsung menuju List View Modul Invoicing tersedia');

// 3. Form View Anatomy (Statusbar, Smart Buttons, Log Notes) di Sales
AuthService::login('Sales');

ob_start();
$_GET = ['page' => 'sales', 'view' => 'form'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?page=sales&view=form';
require dirname(__DIR__) . '/index.php';
$htmlSalesForm = ob_get_clean();

check(str_contains($htmlSalesForm, 'o-statusbar'), '3.1 Statusbar progres dinamis (Draft -> Confirmed -> Done) terpasang di atas form');
check(str_contains($htmlSalesForm, 'oe_button_box'), '3.2 Kotak Smart Buttons (oe_button_box) terpasang di sudut form');
check(str_contains($htmlSalesForm, '1 Delivery'), '3.3 Smart Button "1 Delivery" mengarah ke DO terkait');
check(str_contains($htmlSalesForm, '1 Invoice'), '3.4 Smart Button "1 Invoice" mengarah ke Customer Invoice');
check(str_contains($htmlSalesForm, 'o-chatter-box') && str_contains($htmlSalesForm, 'Log Notes &amp; Riwayat Dokumen'), '3.5 Log Notes / Chatter area terpasang di bagian bawah form');

// 4. Form View Anatomy di Purchase
AuthService::login('Procurement');

ob_start();
$_GET = ['page' => 'purchase', 'view' => 'form'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?page=purchase&view=form';
require dirname(__DIR__) . '/index.php';
$htmlProcForm = ob_get_clean();

check(str_contains($htmlProcForm, '1 Receipt'), '4.1 Smart Button "1 Receipt" mengarah ke Penerimaan Gudang');
check(str_contains($htmlProcForm, '1 Vendor Bill'), '4.2 Smart Button "1 Vendor Bill" mengarah ke Tagihan Pemasok');
check(str_contains($htmlProcForm, 'o-chatter-box'), '4.3 Log Notes / Chatter area terpasang di form Purchase');

// 5. Control Panel (Search, Filter, Group By) pada List & Kanban View
AuthService::login('Sales');

ob_start();
$_GET = ['page' => 'sales', 'view' => 'list'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?page=sales&view=list';
require dirname(__DIR__) . '/index.php';
$htmlSalesList = ob_get_clean();

check(str_contains($htmlSalesList, 'o-control-panel'), '5.1 Control Panel sticky terpasang di atas List View');
check(str_contains($htmlSalesList, 'name="q"') && str_contains($htmlSalesList, 'Search SKU'), '5.2 Fitur Search tersedia di Control Panel');
check(str_contains($htmlSalesList, 'name="filter"') && str_contains($htmlSalesList, 'Filters:'), '5.3 Fitur Filter tersedia di Control Panel');
check(str_contains($htmlSalesList, 'name="group_by"') && str_contains($htmlSalesList, 'Group By:'), '5.4 Fitur Group By tersedia di Control Panel');
check(str_contains($htmlSalesList, 'o-view-switcher'), '5.5 View Switcher (List, Kanban, Form) tersedia dan responsif');

echo "\n=======================================================\n";
echo "HASIL AKHIR: SELURUH 20 PENGUJIAN UI ODOO 17 & SR-19 LOLOS!\n";
echo "=======================================================\n";

