<?php
declare(strict_types=1);

/**
 * Tampilan Beranda Utama Minimalis Odoo Web 17.0 Desktop (src/Views/layout.php)
 * Terintegrasi penuh dengan SalesController (Fitur F-05, F-06, F-07, F-08 & Aturan SR-07, SR-08).
 */

use RantauMart\Config\RolePermissions;

$vm             = $vm ?? [];
$company        = (string) ($vm['company'] ?? 'PT RantauMart Riau');
$screenId       = (string) ($vm['screen_id'] ?? 'S-01');
$screenTitle    = (string) ($vm['screen_title'] ?? 'Home Apps dan akses');
$activeModule   = (string) ($vm['active_module'] ?? 'Apps');
$activePage     = (string) ($vm['active_page'] ?? 'dashboard');

$getPageForModule = static function (string $mod, string $code): string {
    if ($code === 'S-18' || $code === 'CFG-GOV') return 'governance';
    if ($mod === 'Sales' || $mod === 'CRM') return 'sales';
    if ($mod === 'Purchase') return 'purchase';
    if ($mod === 'Inventory') return 'inventory';
    if ($mod === 'Accounting') return 'accounting';
    if ($mod === 'Point of Sale') return 'pos';
    return 'dashboard';
};
$formatIdr      = static fn (float $amount): string => 'Rp ' . number_format($amount, 2, ',', '.');
$activeView     = (string) ($vm['active_view'] ?? 'list');
$searchQuery    = (string) ($vm['search_query'] ?? '');
$activeFilter   = (string) ($vm['active_filter'] ?? 'all');
$activeGroup    = (string) ($vm['active_group'] ?? 'none');

$user           = (array) ($vm['current_user'] ?? []);
$userFullName   = (string) ($user['full_name'] ?? 'Putri Aulia');
$role           = (string) ($user['role'] ?? 'Sales');
$userLocation   = (string) ($user['assigned_location'] ?? 'WH-PKU');

$allUsers       = (array) ($vm['all_users'] ?? []);
$allowedModules = (array) ($vm['allowed_modules'] ?? []);
$sidebarMenu    = (array) ($vm['sidebar_menu'] ?? []);
$roleKpis       = (array) ($vm['role_kpis'] ?? []);
$docStates      = (array) ($vm['document_states'] ?? []);
$products       = (array) ($vm['products'] ?? []);
$customers      = (array) ($vm['customers'] ?? []);
$vendors        = (array) ($vm['vendors'] ?? []);
$pricelistMap   = (array) ($vm['pricelist_map'] ?? []);
$locations      = (array) ($vm['locations'] ?? []);
$coa            = (array) ($vm['coa'] ?? []);
$salesOrders    = (array) ($vm['sales_orders'] ?? []);
$crmLeads       = (array) ($vm['crm_leads'] ?? []);
$purchaseOrders = (array) ($vm['purchase_orders'] ?? []);
$deliveryOrders = (array) ($vm['delivery_orders'] ?? []);
$receipts         = (array) ($vm['purchase_receipts'] ?? []);
$transfers        = (array) ($vm['internal_transfers'] ?? []);
$companyVal       = (array) ($vm['company_valuation'] ?? []);
$stakeholders     = (array) ($vm['stakeholders'] ?? RolePermissions::STAKEHOLDERS);
$customerInvoices = (array) ($vm['customer_invoices'] ?? []);
$vendorBills      = (array) ($vm['vendor_bills'] ?? []);
$payments         = (array) ($vm['payments'] ?? []);
$returnsRefunds   = (array) ($vm['returns_refunds'] ?? []);
$journalEntries   = (array) ($vm['journal_entries'] ?? []);
$liveKpi          = (array) ($vm['live_kpi'] ?? []);
$reportAnalytics  = (array) ($vm['report_analytics'] ?? []);
$posSessions      = (array) ($vm['pos_sessions'] ?? []);
$posReceipts      = (array) ($vm['pos_receipts'] ?? []);
$storePkuStock    = (array) ($vm['store_pku_stock'] ?? []);
$auditLogs        = (array) ($vm['audit_logs'] ?? []);
$flash            = $vm['flash_result'] ?? null;
$activeStakeholder = (array) ($stakeholders[$role] ?? ['name' => $userFullName, 'title' => $role]);
$stakeholderTitle  = (string) ($activeStakeholder['title'] ?? $role);

$sidebarSections = [
    'Overview'           => (array) ($sidebarMenu['Overview'] ?? []),
    'Orders / Documents' => (array) ($sidebarMenu['Orders / Documents'] ?? []),
    'Reporting'          => (array) ($sidebarMenu['Reporting'] ?? []),
    'Configuration'      => (array) ($sidebarMenu['Configuration'] ?? []),
];

$getStatusBadgeClass = static function (string $status): string {
    $norm = strtolower(trim($status));
    if (
        str_contains($norm, 'confirmed') ||
        str_contains($norm, 'done') ||
        str_contains($norm, 'posted') ||
        str_contains($norm, 'paid') ||
        str_contains($norm, 'open') ||
        str_contains($norm, 'qualified')
    ) {
        return 'bg-success text-white';
    }
    if (
        str_contains($norm, 'draft') ||
        str_contains($norm, 'new') ||
        str_contains($norm, 'rfq') ||
        str_contains($norm, 'pending') ||
        str_contains($norm, 'waiting') ||
        str_contains($norm, 'in progress')
    ) {
        return 'bg-warning text-dark';
    }
    if (
        str_contains($norm, 'cancel') ||
        str_contains($norm, 'reject') ||
        str_contains($norm, 'denied') ||
        str_contains($norm, 'closed')
    ) {
        return 'bg-danger text-white';
    }
    return 'bg-secondary text-white';
};

$canConfirmSo       = RolePermissions::canPerformAction($role, 'sales.order.confirm');
$canValidateDeliver = RolePermissions::canPerformAction($role, 'inventory.delivery.validate');
$canPostVendorBill  = RolePermissions::canPerformAction($role, 'accounting.vendor_bill.post');
$canClosePosSession = RolePermissions::canPerformAction($role, 'pos.session.close');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($screenId . ' — ' . $screenTitle . ' | ' . $company) ?></title>
    <!-- Bootstrap 5.3 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Custom Odoo Web 17.0 Minimalist Stylesheet -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- =========================================================================
     FLOATING TOAST NOTIFICATIONS DI POJOK KANAN ATAS (UMPAN BALIK SR-07, SR-08, SR-18)
     ========================================================================= -->
<div class="toast-container o-toast-container position-fixed top-0 end-0 p-3" style="z-index:2000" aria-live="polite" aria-atomic="true">
    <?php
    // Flash Session Toast (Smart Step-by-Step Notifications): satu toast hijau per langkah dokumen yang berhasil.
    $stepToasts = isset($flashToasts) && is_array($flashToasts) ? $flashToasts : [];
    foreach ($stepToasts as $ti => $st): ?>
        <div class="toast align-items-center text-bg-success border-0 shadow mb-2 js-step-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="<?= 6000 + ((int) $ti * 800) ?>">
            <div class="d-flex">
                <div class="toast-body">
                    <strong class="d-block"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars((string) ($st['title'] ?? 'Berhasil')) ?></strong>
                    <?= htmlspecialchars((string) ($st['message'] ?? '')) ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    <?php endforeach; ?>
    <?php
    $flashIsSuccess = is_array($flash)
        && (string) ($flash['outcome'] ?? 'ALLOWED') === 'ALLOWED'
        && ((bool) ($flash['allowed'] ?? true)) !== false;
    ?>
    <?php if (is_array($flash) && !($flashIsSuccess && !empty($stepToasts))): ?>
        <?php
        $outcome    = (string) ($flash['outcome'] ?? 'ALLOWED');
        $httpCode   = (int) ($flash['http_code'] ?? 200);
        $toastMsg   = (string) ($flash['reason'] ?? $flash['message'] ?? '');
        $isDenied   = ($outcome === 'DENIED' || ((bool) ($flash['allowed'] ?? true)) === false);
        $isReplay   = ($outcome === 'REPLAY_BLOCKED');
        $toastBg    = $isDenied ? 'text-bg-danger' : ($isReplay ? 'text-bg-warning' : 'text-bg-success');
        $toastTitle = $isDenied ? 'Transaksi / Akses Diblokir' : ($isReplay ? 'Replay Diblokir (SR-18)' : 'Berhasil');
        ?>
        <div id="liveFeedbackToast" class="toast show align-items-center <?= $toastBg ?> border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <span class="badge <?= $isDenied ? 'bg-danger' : ($isReplay ? 'bg-warning text-dark' : 'bg-success') ?> me-2">
                    HTTP <?= $httpCode ?>
                </span>
                <strong class="me-auto"><?= htmlspecialchars($toastTitle) ?></strong>
                <small class="text-muted">Baru saja</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <?= htmlspecialchars($toastMsg) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- =========================================================================
     1. STICKY TOP NAVBAR: Ramping (bg-dark), App Switcher di kiri, Role Switcher di kanan
     ========================================================================= -->
<nav class="navbar navbar-expand navbar-dark bg-dark o-main-navbar o-sticky-header">
    <div class="container-fluid px-0 d-flex justify-content-between align-items-center">
        <!-- Ujung Kiri: Ikon App Switcher & Modul Aktif -->
        <div class="d-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="o-app-switcher-btn dropdown-toggle o-interactive" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Home App Switcher (Odoo Web 17.0)">
                    <span>▦</span>
                    <span>Apps</span>
                </button>
                <div class="dropdown-menu shadow-lg border-0 mt-2 p-3" style="min-width: 360px; max-width: 420px; border-radius: 1rem;">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <span class="text-uppercase small fw-bold text-muted" style="letter-spacing: 0.05em;">Home App Switcher</span>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($role) ?></span>
                    </div>
                    <div class="row g-2">
                        <?php foreach (RolePermissions::getAppTilesForRole($role) as $tile): ?>
                            <div class="col-6">
                                <a href="index.php?page=<?= urlencode((string) $tile['page']) ?>" class="o-app-tile p-2 text-center h-100 <?= $activePage === $tile['page'] ? 'border-primary' : '' ?>">
                                    <div class="o-app-tile-icon mx-auto mb-1" style="width: 42px; height: 42px; font-size: 1.25rem; background: <?= htmlspecialchars((string) $tile['bg']) ?>;">
                                        <i class="bi <?= htmlspecialchars((string) $tile['icon']) ?>"></i>
                                    </div>
                                    <span class="o-app-tile-name" style="font-size: 0.78rem;"><?= htmlspecialchars((string) $tile['name']) ?></span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <span class="o-navbar-brand"><?= htmlspecialchars($activeModule) ?></span>
            <span class="text-secondary small d-none d-md-inline">· <?= htmlspecialchars($company) ?></span>
        </div>

        <!-- Ujung Kanan: Role Switcher (Dropdown Profil 6 Pemangku Kepentingan) -->
        <div class="d-flex align-items-center gap-2 ms-auto">
            <div class="dropdown">
                <button class="btn o-profile-btn dropdown-toggle d-flex align-items-center gap-2 o-interactive"
                        type="button" id="roleSwitcherDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <span><?= htmlspecialchars($userFullName) ?> — <?= htmlspecialchars($stakeholderTitle) ?> (<?= htmlspecialchars($role) ?>)</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2 py-2" aria-labelledby="roleSwitcherDropdown" style="min-width: 320px;">
                    <li class="dropdown-header text-uppercase small fw-bold text-muted">Pilih Peran Pemangku Kepentingan (F-01)</li>
                    <?php foreach ($allUsers as $u): ?>
                        <?php
                        $uRole     = (string) ($u['role'] ?? '');
                        $uName     = (string) ($u['full_name'] ?? '');
                        $uTitle    = (string) ($stakeholders[$uRole]['title'] ?? $uRole);
                        $isCurrent = ($uRole === $role);
                        ?>
                        <li>
                            <form method="POST" action="index.php?view=<?= urlencode($activeView) ?>" class="m-0">
                                <input type="hidden" name="intent" value="login">
                                <input type="hidden" name="role" value="<?= htmlspecialchars($uRole) ?>">
                                <button type="submit" class="dropdown-item py-2 d-flex justify-content-between align-items-center o-interactive <?= $isCurrent ? 'fw-semibold bg-light' : '' ?>">
                                    <div>
                                        <div><?= htmlspecialchars($uName) ?> <span class="text-muted small">(<?= htmlspecialchars($uRole) ?>)</span></div>
                                        <div class="text-muted" style="font-size: 0.74rem;"><?= htmlspecialchars($uTitle) ?></div>
                                    </div>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge bg-success">Aktif</span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- =========================================================================
     WORKSPACE GRID: 2. LEFT SIDEBAR (bg-light) + 3. MAIN CONTENT (bg-white)
     ========================================================================= -->
<div class="container-fluid o-workspace">
    <div class="row">

        <!-- =================================================================
             2. LEFT SIDEBAR: Latar abu-abu terang (bg-light), kontras dengan
                Main Content (bg-white), spasi lega & highlight aktif tegas
             ================================================================= -->
        <aside class="col-12 col-md-3 col-lg-2 bg-light o-sidebar d-flex flex-column justify-content-between">
            <div>
                <?php foreach ($sidebarSections as $sectionName => $items): ?>
                    <div class="o-sidebar-group">
                        <div class="o-sidebar-section-title"><?= htmlspecialchars($sectionName) ?></div>
                        <nav class="nav flex-column o-sidebar-nav">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemCode   = (string) ($item['code'] ?? 'S-01');
                                $itemLabel  = (string) ($item['label'] ?? '');
                                $itemModule = (string) ($item['module'] ?? 'Apps');
                                $itemPage   = $getPageForModule($itemModule, $itemCode);
                                $isActive   = ($itemCode === $screenId) || ($itemPage === $activePage && ($screenId === $itemCode || ($activePage === 'dashboard' && ($itemCode === 'S-01' || $itemCode === 'S-18'))));
                                ?>
                                <a class="nav-link o-interactive <?= $isActive ? 'active' : '' ?>"
                                   href="index.php?page=<?= urlencode($itemPage) ?>&module=<?= urlencode($itemModule) ?>&screen=<?= urlencode($itemCode) ?>&view=<?= urlencode($activeView) ?>"
                                   data-page="<?= htmlspecialchars($itemPage) ?>" data-code="<?= htmlspecialchars($itemCode) ?>">
                                    <span><?= htmlspecialchars($itemLabel) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pt-3 border-top">
                <a href="debug.php" class="btn btn-soft-gray w-100 d-flex justify-content-between align-items-center o-interactive">
                    <span>Halaman Uji &amp; Audit</span>
                    <span class="text-muted small">→</span>
                </a>
            </div>
        </aside>

        <!-- =================================================================
             3. MAIN CONTENT (bg-white) DENGAN STICKY CONTROL PANEL
             ================================================================= -->
        <main class="col-12 col-md-9 col-lg-10 p-0 bg-white o-main-wrapper">

            <!-- STICKY CONTROL PANEL: position: sticky; top: 0; z-index: 1020; -->
            <header class="o-control-panel o-sticky-header bg-white">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <!-- Kiri: Judul Layar & Pipeline Status Badge -->
                    <div class="d-flex align-items-center gap-3">
                        <h1 class="h6 fw-bold mb-0 text-dark">
                            <?= htmlspecialchars($screenId . ' · ' . $activeModule) ?>
                        </h1>
                        <span class="badge bg-success">Role: <?= htmlspecialchars($role) ?></span>
                    </div>

                    <!-- Kanan: Search, Filters, Group By & View Switcher (List, Kanban, Form) Sejajar Horizontal -->
                    <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
                        <form method="GET" action="index.php" class="d-flex flex-wrap align-items-center gap-2 m-0">
                            <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
                        <input type="hidden" name="module" value="<?= htmlspecialchars($activeModule) ?>">
                            <input type="hidden" name="screen" value="<?= htmlspecialchars($screenId) ?>">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($activeView) ?>">

                            <input type="search" name="q" class="form-control form-control-sm o-control-input"
                                   style="width: 190px;"
                                   placeholder="Search SKU / produk..."
                                   value="<?= htmlspecialchars($searchQuery) ?>">

                            <select name="filter" class="form-select form-select-sm o-control-input o-interactive"
                                    style="width: auto;" onchange="this.form.submit()" aria-label="Filters">
                                <option value="all" <?= $activeFilter === 'all' ? 'selected' : '' ?>>Filters: Semua</option>
                                <option value="V01" <?= $activeFilter === 'V01' ? 'selected' : '' ?>>Filters: Vendor V01</option>
                                <option value="V02" <?= $activeFilter === 'V02' ? 'selected' : '' ?>>Filters: Vendor V02</option>
                                <option value="V03" <?= $activeFilter === 'V03' ? 'selected' : '' ?>>Filters: Vendor V03</option>
                                <option value="V04" <?= $activeFilter === 'V04' ? 'selected' : '' ?>>Filters: Vendor V04</option>
                            </select>

                            <select name="group_by" class="form-select form-select-sm o-control-input o-interactive"
                                    style="width: auto;" onchange="this.form.submit()" aria-label="Group By">
                                <option value="none" <?= $activeGroup === 'none' ? 'selected' : '' ?>>Group By: None</option>
                                <option value="default_vendor" <?= $activeGroup === 'default_vendor' ? 'selected' : '' ?>>Group By: Vendor</option>
                                <option value="size" <?= $activeGroup === 'size' ? 'selected' : '' ?>>Group By: Ukuran</option>
                            </select>
                        </form>

                        <!-- View Switcher (List, Kanban, Form) -->
                        <div class="btn-group o-view-switcher ms-1" role="group" aria-label="View Switcher">
                            <a href="index.php?page=<?= urlencode($activePage) ?>&module=<?= urlencode($activeModule) ?>&screen=<?= urlencode($screenId) ?>&view=list&q=<?= urlencode($searchQuery) ?>&filter=<?= urlencode($activeFilter) ?>&group_by=<?= urlencode($activeGroup) ?>"
                               class="btn o-interactive <?= $activeView === 'list' ? 'active' : '' ?>">
                                List
                            </a>
                            <a href="index.php?page=<?= urlencode($activePage) ?>&module=<?= urlencode($activeModule) ?>&screen=<?= urlencode($screenId) ?>&view=kanban&q=<?= urlencode($searchQuery) ?>&filter=<?= urlencode($activeFilter) ?>&group_by=<?= urlencode($activeGroup) ?>"
                               class="btn o-interactive <?= $activeView === 'kanban' ? 'active' : '' ?>">
                                Kanban
                            </a>
                            <a href="index.php?page=<?= urlencode($activePage) ?>&module=<?= urlencode($activeModule) ?>&screen=<?= urlencode($screenId) ?>&view=form&q=<?= urlencode($searchQuery) ?>&filter=<?= urlencode($activeFilter) ?>&group_by=<?= urlencode($activeGroup) ?>"
                               class="btn o-interactive <?= $activeView === 'form' ? 'active' : '' ?>">
                                Form
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- AREA KONTEN UTAMA DENGAN PADDING LEGA & TATA LETAK CARD GRID TERPISAH PER BAGIAN -->
            <?php
            // Ringkasan status dokumen real-time untuk kartu modul & kotak dokumen aktif
            $leadDocStat  = (string) ($docStates['LEAD-0001']['stage'] ?? 'New');
            $soDocStat    = (string) ($docStates['S00007']['status'] ?? 'Confirmed');
            $poDocStat    = (string) ($docStates['PO00007']['status'] ?? 'Confirmed');
            $billDocStat  = (string) ($docStates['BILL-0001']['status'] ?? 'Draft');
            $posDocStat   = (string) ($docStates['POS-SESSION-0001']['status'] ?? 'Open');
            $whAirStock   = (array) ($docStates['STOCK-WH-PKU-AIR-600'] ?? []);
            $whAirOnHand  = (int) ($whAirStock['on_hand'] ?? 80);
            $whAirRes     = (int) ($whAirStock['reserved'] ?? 8);
            $whAirAvail   = (int) ($whAirStock['available'] ?? 72);

            // Definisi 5 Kartu Modul Utama (Sales & CRM, Purchase, Inventory, Accounting, Point of Sale)
            // Disaring secara ketat berdasarkan $allowedModules role aktif (Fitur F-01: kartu di luar wewenang disembunyikan total)
            $dashboardModuleCards = [];

            if (isset($allowedModules['Sales']) || isset($allowedModules['CRM'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Sales',
                    'icon'        => '📈',
                    'title'       => 'Sales & CRM',
                    'screen'      => 'S-02 · S-04 · S-05',
                    'status_text' => 'LEAD-0001: ' . $leadDocStat . ' · S00007: ' . $soDocStat,
                    'status_badge'=> $soDocStat,
                    'summary'     => 'Kelola konversi Lead ke Opportunity, Quotation, Sales Order (' . count($salesOrders) . ' dokumen), batas diskon maks 10% (SR-07), dan kontrol Credit Limit pelanggan (SR-08).',
                    'primary_url' => 'index.php?page=sales&module=Sales&screen=S-05&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Sales & CRM →',
                    'second_url'  => isset($allowedModules['CRM']) ? ('index.php?page=sales&module=CRM&screen=S-04&view=' . urlencode($activeView)) : null,
                    'second_lbl'  => 'CRM Pipeline',
                ];
            }

            if (isset($allowedModules['Purchase'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Purchase',
                    'icon'        => '🛒',
                    'title'       => 'Purchase',
                    'screen'      => 'S-08',
                    'status_text' => 'PO00007: ' . $poDocStat . ' (Rp99.900) · ' . count($purchaseOrders) . ' PO Aktif',
                    'status_badge'=> $poDocStat,
                    'summary'     => 'Kelola permintaan pembelian RFQ & Purchase Order kepada 4 vendor utama dengan pengambilan harga beli otomatis dari vendor_pricelist (SR-20).',
                    'primary_url' => 'index.php?page=purchase&module=Purchase&screen=S-08&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Modul Purchase →',
                    'second_url'  => null,
                    'second_lbl'  => null,
                ];
            }

            if (isset($allowedModules['Inventory'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Inventory',
                    'icon'        => '📦',
                    'title'       => 'Inventory & Warehouse',
                    'screen'      => 'S-03 · S-06 · S-07 · S-09',
                    'status_text' => 'WH-PKU AIR-600: ' . $whAirOnHand . ' On Hand · ' . $whAirRes . ' Reserved · ' . $whAirAvail . ' Avail',
                    'status_badge'=> 'Available',
                    'summary'     => 'Kontrol mutasi stok 15 SKU di 3 lokasi (WH-PKU, STORE-PKU, STORE-DUM), reservasi SO (SR-09), pemisahan barang Accepted/Rejected (SR-10), dan Internal Transfer (SR-17).',
                    'primary_url' => 'index.php?page=inventory&module=Inventory&screen=S-06&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Modul Inventory →',
                    'second_url'  => 'index.php?page=inventory&module=Inventory&screen=S-03&view=' . urlencode($activeView),
                    'second_lbl'  => 'Master SKU',
                ];
            }

            if (isset($allowedModules['Accounting'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Accounting',
                    'icon'        => '📊',
                    'title'       => 'Accounting & Invoicing',
                    'screen'      => 'S-10 · S-11 · S-12 · S-13 · S-18',
                    'status_text' => 'BILL-0001: ' . $billDocStat . ' · Trial Balance Rp25.716.900 (Seimbang)',
                    'status_badge'=> $billDocStat,
                    'summary'     => 'Penagihan pelanggan atas Delivered Qty (SR-02), Vendor Bill 3-Way Match & PPV (SR-06), pelunasan bertahap tanpa overpayment (SR-15), dan buku besar seimbang (SR-21).',
                    'primary_url' => 'index.php?page=accounting&module=Accounting&screen=S-10&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Modul Accounting →',
                    'second_url'  => 'index.php?page=accounting&module=Accounting&screen=S-13&view=' . urlencode($activeView),
                    'second_lbl'  => 'General Ledger',
                ];
            }

            if (isset($allowedModules['Point of Sale'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Point of Sale',
                    'icon'        => '🏪',
                    'title'       => 'Point of Sale (POS)',
                    'screen'      => 'S-14 · S-15 · S-16 · S-17',
                    'status_text' => 'POS-SESSION-0001: ' . $posDocStat . ' · Opening Cash Rp200.000',
                    'status_badge'=> $posDocStat,
                    'summary'     => 'Operasional kasir toko STORE-PKU, checkout keranjang tunai real-time (SR-04 & SR-13), refund tunai & restock (SR-16), serta rekonsiliasi uang kas (SR-12 & SR-14).',
                    'primary_url' => 'index.php?page=pos&module=Point+of+Sale&screen=S-15&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Point of Sale →',
                    'second_url'  => 'index.php?page=pos&module=Point+of+Sale&screen=S-16&view=' . urlencode($activeView),
                    'second_lbl'  => 'Cash Count',
                ];
            }

            if ($role === 'Owner' || isset($allowedModules['Governance'])) {
                $dashboardModuleCards[] = [
                    'key'         => 'Governance',
                    'icon'        => '🛡️',
                    'title'       => 'Governance & Audit Trail',
                    'screen'      => 'S-18 · SR-18 · SR-19 · SR-22',
                    'status_text' => 'Audit Trail Aktif · Pengawasan Eksekutif Anti-Double Journal',
                    'status_badge'=> 'Done',
                    'summary'     => 'Pemantauan real-time jejak audit dokumen, pencegahan duplikasi jurnal POS/SO, dan integritas ACID multi-lokasi.',
                    'primary_url' => 'index.php?page=governance&module=Accounting&screen=S-18&view=' . urlencode($activeView),
                    'primary_lbl' => 'Buka Laporan & Audit →',
                    'second_url'  => 'debug.php',
                    'second_lbl'  => 'Halaman Uji',
                ];
            }

            // Daftar Dokumen Aktif yang difilter sesuai wewenang role aktif
            $roleActiveDocuments = [];
            if ($role === 'Sales' || $role === 'Owner') {
                $roleActiveDocuments[] = [
                    'doc_no' => 'LEAD-0001',
                    'type'   => 'CRM Lead (S-04)',
                    'detail' => 'Pelanggan C001 (Warung Kopi Pasar) · Target 20 pcs AIR-600',
                    'status' => $leadDocStat,
                    'url'    => 'index.php?module=CRM&screen=S-04&view=' . urlencode($activeView),
                ];
                $roleActiveDocuments[] = [
                    'doc_no' => 'S00007',
                    'type'   => 'Sales Order (S-05)',
                    'detail' => 'Pelanggan C001 · 8 pcs AIR-600 @Rp4.000 · Gross Rp35.520 (Harga Terkunci)',
                    'status' => $soDocStat,
                    'url'    => 'index.php?module=Sales&screen=S-05&view=' . urlencode($activeView),
                ];
            }
            if ($role === 'Warehouse' || $role === 'Owner') {
                $roleActiveDocuments[] = [
                    'doc_no' => 'S00007 / DO',
                    'type'   => 'Delivery & Reservasi WH-PKU (S-06)',
                    'detail' => 'AIR-600: On Hand ' . $whAirOnHand . ' pcs · Reserved ' . $whAirRes . ' pcs · Available ' . $whAirAvail . ' pcs',
                    'status' => $soDocStat,
                    'url'    => 'index.php?module=Inventory&screen=S-06&view=' . urlencode($activeView),
                ];
                $roleActiveDocuments[] = [
                    'doc_no' => 'PO00007 / WH-IN',
                    'type'   => 'Incoming Receipt Gudang (S-09)',
                    'detail' => 'Vendor V01 · 30 pcs MIE-GOR-80 tujuan WH-PKU (Pemisahan Accepted/Rejected)',
                    'status' => $poDocStat,
                    'url'    => 'index.php?module=Inventory&screen=S-09&view=' . urlencode($activeView),
                ];
            }
            if ($role === 'Procurement' || $role === 'Owner') {
                $roleActiveDocuments[] = [
                    'doc_no' => 'PO00007',
                    'type'   => 'Purchase Order (S-08)',
                    'detail' => 'Vendor V01 (PT Pangan Pilihan) · 30 pcs MIE-GOR-80 @Rp3.000 · Gross Rp99.900',
                    'status' => $poDocStat,
                    'url'    => 'index.php?module=Purchase&screen=S-08&view=' . urlencode($activeView),
                ];
            }
            if ($role === 'Finance' || $role === 'Owner') {
                $roleActiveDocuments[] = [
                    'doc_no' => 'BILL-0001',
                    'type'   => 'Vendor Bill Three-Way Match (S-11)',
                    'detail' => 'Tagihan Vendor V01 · Verifikasi Accepted Qty & Purchase Price Variance (SR-06)',
                    'status' => $billDocStat,
                    'url'    => 'index.php?module=Accounting&screen=S-11&view=' . urlencode($activeView),
                ];
                $roleActiveDocuments[] = [
                    'doc_no' => 'JE-OPEN-0001',
                    'type'   => 'Opening Journal Entry (S-13)',
                    'detail' => 'Saldo Pembukaan Seimbang: Debit Rp25.716.900 = Credit Rp25.716.900',
                    'status' => 'Posted',
                    'url'    => 'index.php?module=Accounting&screen=S-13&view=' . urlencode($activeView),
                ];
            }
            if ($role === 'Cashier' || $role === 'Finance' || $role === 'Owner') {
                $roleActiveDocuments[] = [
                    'doc_no' => 'POS-SESSION-0001',
                    'type'   => 'POS Session STORE-PKU (S-14 / S-16)',
                    'detail' => 'Register POS01 · Kasir Rani Oktaviani · Opening Cash Rp200.000',
                    'status' => $posDocStat,
                    'url'    => 'index.php?module=Point+of+Sale&screen=S-16&view=' . urlencode($activeView),
                ];
            }

            // Daftar Pintasan Quick Actions sesuai role aktif
            $roleQuickActions = match ($role) {
                'Sales' => [
                    ['icon' => '🎯', 'label' => 'Konversi LEAD-0001 ke Opportunity', 'sub' => 'Fitur F-05 · Layar S-04', 'url' => 'index.php?page=sales&module=CRM&screen=S-04&view=' . urlencode($activeView)],
                    ['icon' => '📝', 'label' => 'Buat Quotation / Sales Order Baru', 'sub' => 'Fitur F-06 · Diskon ≤ 10% (SR-07)', 'url' => 'index.php?page=sales&module=Sales&screen=S-05&view=' . urlencode($activeView)],
                    ['icon' => '👥', 'label' => 'Cek Exposure & Credit Limit Pelanggan', 'sub' => 'Fitur F-04 · Validasi SR-08', 'url' => 'index.php?page=sales&module=Sales&screen=S-02&view=' . urlencode($activeView)],
                ],
                'Warehouse' => [
                    ['icon' => '🚚', 'label' => 'Validasi Delivery Order & Backorder', 'sub' => 'Fitur F-10 · Aturan SR-04 & SR-09', 'url' => 'index.php?page=inventory&module=Inventory&screen=S-06&view=' . urlencode($activeView)],
                    ['icon' => '📥', 'label' => 'Terima Barang PO (Accepted vs Rejected)', 'sub' => 'Fitur F-13 · Aturan SR-10', 'url' => 'index.php?page=inventory&module=Inventory&screen=S-09&view=' . urlencode($activeView)],
                    ['icon' => '⇄',  'label' => 'Transfer Stok Antar Lokasi Gudang/Toko', 'sub' => 'Fitur F-21 · Aturan SR-17', 'url' => 'index.php?page=inventory&module=Inventory&screen=S-07&view=' . urlencode($activeView)],
                ],
                'Procurement' => [
                    ['icon' => '🛒', 'label' => 'Buat RFQ / Purchase Order Vendor', 'sub' => 'Fitur F-12 · Auto Pricelist SR-20', 'url' => 'index.php?page=purchase&module=Purchase&screen=S-08&view=' . urlencode($activeView)],
                    ['icon' => '📥', 'label' => 'Validasi Penerimaan Barang Vendor', 'sub' => 'Fitur F-13 · Aturan SR-10', 'url' => 'index.php?module=Inventory&screen=S-09&view=' . urlencode($activeView)],
                    ['icon' => '🏷️', 'label' => 'Lihat Master SKU & Kontrak Harga', 'sub' => 'Fitur F-03 · 15 SKU & 7 Kontrak', 'url' => 'index.php?page=inventory&module=Inventory&screen=S-03&view=' . urlencode($activeView)],
                ],
                'Finance' => [
                    ['icon' => '🧾', 'label' => 'Terbitkan Customer Invoice (Delivered Qty)', 'sub' => 'Fitur F-11 · Aturan SR-02', 'url' => 'index.php?page=accounting&module=Accounting&screen=S-10&view=' . urlencode($activeView)],
                    ['icon' => '📑', 'label' => 'Post Vendor Bill 3-Way Match & PPV', 'sub' => 'Fitur F-14 · Aturan SR-06', 'url' => 'index.php?page=accounting&module=Accounting&screen=S-11&view=' . urlencode($activeView)],
                    ['icon' => '💳', 'label' => 'Register Partial Payment & Rekonsiliasi', 'sub' => 'Fitur F-15 · Aturan SR-15', 'url' => 'index.php?page=accounting&module=Accounting&screen=S-12&view=' . urlencode($activeView)],
                ],
                'Cashier' => [
                    ['icon' => '🛍️', 'label' => 'Checkout Keranjang Tunai STORE-PKU', 'sub' => 'Fitur F-18 · Aturan SR-04 & SR-13', 'url' => 'index.php?page=pos&module=Point+of+Sale&screen=S-15&view=' . urlencode($activeView)],
                    ['icon' => '↩️', 'label' => 'Proses POS Cash Refund & Restock', 'sub' => 'Fitur F-20 · Aturan SR-16', 'url' => 'index.php?page=pos&module=Point+of+Sale&screen=S-17&view=' . urlencode($activeView)],
                    ['icon' => '🔒', 'label' => 'Rekonsiliasi Cash Count & Tutup Sesi', 'sub' => 'Fitur F-19 · Aturan SR-12 & SR-14', 'url' => 'index.php?page=pos&module=Point+of+Sale&screen=S-16&view=' . urlencode($activeView)],
                ],
                default => [
                    ['icon' => '📊', 'label' => 'Executive KPI & Drill-Down Ledger', 'sub' => 'Fitur F-22 · Aturan SR-19', 'url' => 'index.php?page=governance&module=Accounting&screen=S-18&view=' . urlencode($activeView)],
                    ['icon' => '🛡️', 'label' => 'Audit Trail & Bukti Penolakan Akses', 'sub' => 'Fitur F-23 · Aturan SR-18 & SR-22', 'url' => 'debug.php'],
                ],
            };
            ?>

            <div class="o-main-content d-flex flex-column gap-4">

                <?php
                $is403AccessDenied = (isset($accessViolation) && $accessViolation)
                    || (is_array($flash) && (int) ($flash['http_code'] ?? 200) === 403)
                    || (isset($flashResult) && is_array($flashResult) && (int) ($flashResult['http_code'] ?? 200) === 403);
                $deniedMsg = (string) ($flashResult['reason'] ?? $flash['reason'] ?? $flashResult['message'] ?? $flash['message'] ?? 'Akses ke modul divisi lain ditolak (SR-18). Anda telah dialihkan kembali ke modul wewenang Anda.');
                ?>
                <?php if ($is403AccessDenied): ?>
                    <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 p-3 mb-0" role="alert" style="background-color: #F8D7DA; color: #842029;">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-3 text-danger"><i class="bi bi-shield-x"></i></div>
                            <div class="flex-grow-1">
                                <strong class="d-block mb-1">⛔ 403 Access Denied — Pembatasan Akses Antar Divisi (SR-18)</strong>
                                <span class="small"><?= htmlspecialchars($deniedMsg) ?></span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($activePage === 'dashboard'): ?>
                <!-- =========================================================================
                     1. BERANDA DEFAULT / LANDING PAGE: DASHBOARD KPI EKSEKUTIF & CARD GRID
                     ========================================================================= -->
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-dashboard">
                    <!-- =========================================================================
                         ODOO WEB 17.0 HOME APP SWITCHER GRID (NAVIGASI DASAR 6 MODUL BISNIS)
                         ========================================================================= -->
                    <section class="o-section-box bg-white rounded-4 shadow-sm p-4" id="home-apps-grid" data-testid="home-apps-grid">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
                            <div>
                                <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                                    Home App Switcher (Odoo Web 17.0)
                                </span>
                                <span class="text-muted small">Pilih modul aplikasi untuk beralih ruang kerja operasional secara instan.</span>
                            </div>
                            <span class="badge bg-light text-secondary border px-3 py-1 rounded-pill">
                                Hak Akses: <?= htmlspecialchars($stakeholderTitle) ?> (<?= htmlspecialchars($role) ?>)
                            </span>
                        </div>
                        <div class="row g-3 row-cols-2 row-cols-sm-3 row-cols-md-6 o-app-launcher-grid">
                            <?php foreach (RolePermissions::getAppTilesForRole($role) as $tile): ?>
                                <div class="col">
                                    <a href="index.php?page=<?= urlencode((string) $tile['page']) ?>" class="o-app-tile h-100 <?= $activePage === $tile['page'] ? 'border-primary' : '' ?>">
                                        <div class="o-app-tile-icon" style="background: <?= htmlspecialchars((string) $tile['bg']) ?>;">
                                            <i class="bi <?= htmlspecialchars((string) $tile['icon']) ?>"></i>
                                        </div>
                                        <span class="o-app-tile-name"><?= htmlspecialchars((string) $tile['name']) ?></span>
                                        <span class="o-app-tile-sub"><?= htmlspecialchars((string) $tile['sub']) ?></span>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'exec_dashboard.php'; // SR-19: Dasbor Eksekutif jika Direktur/Finance ?>

                    <!-- =========================================================
                         BAGIAN 1: DASHBOARD CARD GRID MODUL UTAMA
                     Hanya menampilkan kartu modul yang sesuai wewenang role aktif
                     ========================================================= -->
                <?php if (!empty($dashboardModuleCards)): ?>
                <section class="o-section-box bg-white rounded-4 shadow-sm">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
                        <div>
                            <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                                Dashboard Modul Utama — <?= htmlspecialchars($userFullName) ?> (<?= htmlspecialchars($stakeholderTitle) ?>)
                            </span>
                            <span class="text-muted small">
                                Menampilkan hanya modul yang diizinkan untuk peran <strong><?= htmlspecialchars($role) ?></strong> (Fitur F-01).
                            </span>
                        </div>
                        <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill">
                            📍 Lokasi Tugas: <?= htmlspecialchars($userLocation) ?>
                        </span>
                    </div>

                    <div class="row g-4">
                        <?php
                        $modCardCol = match(count($dashboardModuleCards)) {
                            1 => 'col-12',
                            2 => 'col-12 col-md-6',
                            default => 'col-12 col-md-6 col-lg-4',
                        };
                        ?>
                        <?php foreach ($dashboardModuleCards as $mCard): ?>
                            <?php
                            $isCurrentMod = ($mCard['key'] === $activeModule)
                                || ($mCard['key'] === 'Sales' && in_array($activeModule, ['Sales', 'CRM'], true));
                            ?>
                            <div class="<?= $modCardCol ?>">
                                <div class="card o-module-card bg-white rounded-4 shadow-sm h-100 d-flex flex-column justify-content-between <?= $isCurrentMod ? 'active-card' : '' ?>">
                                    <div>
                                        <!-- Header Kartu: Ikon Modul, Judul & Badge Layar -->
                                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                             <div class="d-flex align-items-center gap-3">
                                                <div class="o-module-icon" aria-hidden="true">
                                                    <?= $mCard['icon'] ?>
                                                </div>
                                                <div>
                                                    <h2 class="h6 fw-bold text-dark mb-1">
                                                        <?= htmlspecialchars((string) $mCard['title']) ?>
                                                    </h2>
                                                    <span class="badge bg-light text-secondary border">
                                                        <?= htmlspecialchars((string) $mCard['screen']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <span class="badge <?= $getStatusBadgeClass((string) $mCard['status_badge']) ?>">
                                                <?= htmlspecialchars((string) $mCard['status_badge']) ?>
                                            </span>
                                        </div>

                                        <!-- Ringkasan Status Modul -->
                                        <div class="p-2 px-3 mb-3 rounded-3 bg-light border small text-dark fw-medium">
                                            <?= htmlspecialchars((string) $mCard['status_text']) ?>
                                        </div>

                                        <p class="text-secondary small mb-3">
                                            <?= htmlspecialchars((string) $mCard['summary']) ?>
                                        </p>
                                    </div>

                                    <!-- Tombol Aksi Jelas -->
                                    <div class="d-flex gap-2 pt-2 border-top">
                                        <a href="<?= htmlspecialchars((string) $mCard['primary_url']) ?>"
                                           class="btn <?= $isCurrentMod ? 'btn-pastel' : 'btn-soft-gray' ?> o-interactive flex-grow-1">
                                            <?= htmlspecialchars((string) $mCard['primary_lbl']) ?>
                                        </a>
                                        <?php if (!empty($mCard['second_url'])): ?>
                                            <a href="<?= htmlspecialchars((string) $mCard['second_url']) ?>"
                                               class="btn btn-soft-gray o-interactive">
                                                <?= htmlspecialchars((string) $mCard['second_lbl']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                                <!-- =========================================================
                     BAGIAN 2: WADAH KOTAK TERPISAH — METRIK STOK & KINERJA PERAN
                     ========================================================= -->
                <section class="o-section-box bg-white rounded-4 shadow-sm">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
                        <div>
                            <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                                Metrik Stok &amp; Indikator Kinerja — <?= htmlspecialchars($role) ?>
                            </span>
                            <span class="text-muted small">
                                Ringkasan real-time stok multi-lokasi (WH-PKU, STORE-PKU, STORE-DUM) dan indikator finansial.
                            </span>
                        </div>
                        <span class="badge bg-success rounded-pill px-3 py-2">
                            Valuasi Perusahaan: Rp<?= number_format((float) ($companyVal['total_company_valuation'] ?? 9628900), 0, ',', '.') ?>
                        </span>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($roleKpis as $kpi): ?>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <div class="o-kpi-card bg-white rounded-4 shadow-sm h-100">
                                    <div class="text-secondary small mb-1">
                                        <?= htmlspecialchars((string) ($kpi['label'] ?? '')) ?>
                                    </div>
                                    <div class="h5 fw-bold text-dark mb-1">
                                        <?= htmlspecialchars((string) ($kpi['value'] ?? '')) ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.76rem;">
                                        <?= htmlspecialchars((string) ($kpi['sub'] ?? '')) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- =========================================================
                     BAGIAN 3 & 4: WADAH KOTAK TERPISAH — DAFTAR DOKUMEN AKTIF & QUICK ACTIONS
                     ========================================================= -->
                <section class="row g-4">
                    <!-- Kotak Kiri: Daftar Dokumen Aktif Sesuai Role -->
                    <div class="col-12 col-lg-7">
                        <div class="o-section-box bg-white rounded-4 shadow-sm h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                <div>
                                    <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                                        Daftar Dokumen Aktif (<?= htmlspecialchars($role) ?>)
                                    </span>
                                    <span class="text-muted small">Status terkini dokumen yang berada dalam wewenang peran Anda.</span>
                                </div>
                                <span class="badge bg-light text-dark border"><?= count($roleActiveDocuments) ?> Dokumen</span>
                            </div>

                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($roleActiveDocuments as $aDoc): ?>
                                    <div class="o-doc-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="fw-bold text-dark"><?= htmlspecialchars((string) $aDoc['doc_no']) ?></span>
                                                <span class="badge bg-light text-secondary border"><?= htmlspecialchars((string) $aDoc['type']) ?></span>
                                                <span class="badge <?= $getStatusBadgeClass((string) $aDoc['status']) ?>">
                                                    <?= htmlspecialchars((string) $aDoc['status']) ?>
                                                </span>
                                            </div>
                                            <div class="text-secondary small">
                                                <?= htmlspecialchars((string) $aDoc['detail']) ?>
                                            </div>
                                        </div>
                                        <a href="<?= htmlspecialchars((string) $aDoc['url']) ?>" class="btn btn-sm btn-soft-gray o-interactive">
                                            Lihat Dokumen →
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Kotak Kanan: Pintasan Quick Actions Sesuai Role -->
                    <div class="col-12 col-lg-5">
                        <div class="o-section-box bg-white rounded-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                    <div>
                                        <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                                            Pintasan Quick Actions
                                        </span>
                                        <span class="text-muted small">Akses cepat ke operasi utama peran <?= htmlspecialchars($role) ?>.</span>
                                    </div>
                                    <span class="badge bg-success">F-01 Verified</span>
                                </div>

                                <div class="d-flex flex-column gap-2 mb-3">
                                    <?php foreach ($roleQuickActions as $qa): ?>
                                        <a href="<?= htmlspecialchars((string) $qa['url']) ?>"
                                           class="o-quick-action-item text-decoration-none d-flex align-items-center justify-content-between gap-3 o-interactive">
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="fs-5"><?= $qa['icon'] ?></span>
                                                <div>
                                                    <div class="fw-semibold text-dark small"><?= htmlspecialchars((string) $qa['label']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.74rem;"><?= htmlspecialchars((string) $qa['sub']) ?></div>
                                                </div>
                                            </div>
                                            <span class="text-secondary fw-bold">→</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Indikator Matriks Otorisasi Cepat (Fitur F-01) -->
                            <div class="pt-3 border-top">
                                <div class="small text-muted mb-2">Status Otorisasi Aksi Lintas Modul (Fitur F-01):</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="<?= $canConfirmSo ? '' : 'o-blocked-wrapper' ?>"
                                          title="<?= $canConfirmSo ? 'Diizinkan untuk Sales' : 'Diblokir (F-01): Hanya untuk Role Sales' ?>">
                                        <button type="button" class="btn btn-sm btn-pastel <?= $canConfirmSo ? 'o-interactive' : 'o-action-disabled' ?>">
                                            Confirm SO
                                        </button>
                                    </span>

                                    <span class="<?= $canValidateDeliver ? '' : 'o-blocked-wrapper' ?>"
                                          title="<?= $canValidateDeliver ? 'Diizinkan untuk Warehouse' : 'Diblokir (F-01): Hanya untuk Role Warehouse' ?>">
                                        <button type="button" class="btn btn-sm btn-pastel <?= $canValidateDeliver ? 'o-interactive' : 'o-action-disabled' ?>">
                                            Validate Delivery
                                        </button>
                                    </span>

                                    <span class="<?= $canPostVendorBill ? '' : 'o-blocked-wrapper' ?>"
                                          title="<?= $canPostVendorBill ? 'Diizinkan untuk Finance' : 'Diblokir (F-01): Hanya untuk Role Finance' ?>">
                                        <button type="button" class="btn btn-sm btn-pastel <?= $canPostVendorBill ? 'o-interactive' : 'o-action-disabled' ?>">
                                            Post Vendor Bill
                                        </button>
                                    </span>

                                    <span class="<?= $canClosePosSession ? '' : 'o-blocked-wrapper' ?>"
                                          title="<?= $canClosePosSession ? 'Diizinkan untuk Cashier/Finance' : 'Diblokir (F-01): Hanya untuk Role Cashier/Finance' ?>">
                                        <button type="button" class="btn btn-sm btn-pastel <?= $canClosePosSession ? 'o-interactive' : 'o-action-disabled' ?>">
                                            Close POS Session
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2B. MODUL CRM & SALES WORKSPACE (Fitur F-05, F-06, F-07, F-08)
                     Hanya dirender saat Modul Sales & CRM aktif dipilih
                     ========================================================= -->
                <?php if ($activePage === 'sales'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-sales">
                    <section class="row g-4">
                        <!-- Kolom Kiri: CRM Pipeline (F-05) & Form Buat Quotation / Sales Order Baru (F-06..F-08) -->
                        <div class="col-12 col-lg-5 d-flex flex-column gap-4">
                            <!-- CRM Pipeline (S-04 / F-05) -->
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">CRM Pipeline (F-05 · S-04)</span>
                                    <span class="badge bg-light text-secondary border">Lead → Opportunity</span>
                                </div>
                                <?php foreach ($crmLeads as $lead): ?>
                                    <?php
                                    $leadNo  = (string) ($lead['lead_number'] ?? 'LEAD-0001');
                                    $lStage  = (string) ($lead['stage'] ?? 'New');
                                    $oppId   = (string) ($lead['converted_opportunity_id'] ?? '');
                                    ?>
                                    <div class="p-3 bg-light rounded border d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="fw-semibold"><?= htmlspecialchars($leadNo) ?></span>
                                                <span class="badge <?= $getStatusBadgeClass($lStage) ?>"><?= htmlspecialchars($lStage) ?></span>
                                                <?php if ($oppId !== ''): ?>
                                                    <span class="badge bg-dark"><?= htmlspecialchars($oppId) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-secondary">
                                                Pelanggan: <strong><?= htmlspecialchars((string) ($lead['customer_id'] ?? 'C001')) ?> — <?= htmlspecialchars((string) ($lead['customer_name'] ?? '')) ?></strong>
                                                · Target: <?= (int) ($lead['expected_qty'] ?? 20) ?> pcs <code><?= htmlspecialchars((string) ($lead['expected_sku'] ?? 'AIR-600')) ?></code>
                                            </div>
                                        </div>
                                        <form method="POST" action="index.php?module=CRM&screen=S-04&view=<?= urlencode($activeView) ?>" class="m-0">
                                            <input type="hidden" name="intent" value="crm_convert_lead">
                                            <input type="hidden" name="lead_number" value="<?= htmlspecialchars($leadNo) ?>">
                                            <button type="submit" class="btn btn-sm btn-pastel o-interactive">
                                                Convert
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Form Buat Quotation / Sales Order Baru (F-06, F-07, F-08) -->
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Buat Quotation / Sales Order Baru (F-06 · S-05)</span>
                                    <span class="badge bg-warning text-dark">Diskon Maks 10% (SR-07)</span>
                                </div>
                                <form method="POST" action="index.php?module=Sales&screen=S-05&view=<?= urlencode($activeView) ?>" class="row g-3">
                                    <input type="hidden" name="intent" value="sales_create_so">
                                    <input type="hidden" name="source_location" value="WH-PKU">
                                    <input type="hidden" name="source_lead" value="LEAD-0001">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Pelanggan &amp; Sisa Credit Limit (SR-08)</label>
                                        <select name="customer_id" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($customers as $c): ?>
                                                <?php
                                                $cId   = (string) ($c['id'] ?? '');
                                                $cName = (string) ($c['name'] ?? '');
                                                $cLim  = (float) ($c['credit_limit'] ?? 0);
                                                $cExp  = (float) ($c['exposure']['current_exposure'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($cId) ?>">
                                                    <?= htmlspecialchars($cId . ' — ' . $cName) ?>
                                                    (Limit: Rp<?= number_format($cLim, 0, ',', '.') ?> | Exposure Saat Ini: Rp<?= number_format($cExp, 0, ',', '.') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12 col-sm-7">
                                        <label class="form-label small fw-semibold mb-1">SKU Produk (WH-PKU)</label>
                                        <select name="variant_sku" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($products as $p): ?>
                                                <?php
                                                $pSku   = (string) ($p['variant_sku'] ?? '');
                                                $pName  = (string) ($p['name'] ?? '');
                                                $pPrice = (float) ($p['sales_price'] ?? 0);
                                                $pAvail = (int) ($p['wh_available'] ?? $p['wh_pku'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pSku) ?>">
                                                    <?= htmlspecialchars($pSku . ' — ' . $pName) ?> (@Rp<?= number_format($pPrice, 0, ',', '.') ?> · Avail: <?= $pAvail ?> pcs)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-sm-5">
                                        <label class="form-label small fw-semibold mb-1">Ordered Qty (pcs)</label>
                                        <input type="number" name="ordered_qty" class="form-control form-control-sm o-control-input" value="20" min="1" required>
                                    </div>

                                    <div class="col-6 col-sm-6">
                                        <label class="form-label small fw-semibold mb-1">Harga Satuan ( Kosong = Default )</label>
                                        <input type="number" name="unit_price" class="form-control form-control-sm o-control-input" placeholder="Otomatis dari SKU" min="0" step="100">
                                    </div>

                                    <div class="col-6 col-sm-6">
                                        <label class="form-label small fw-semibold mb-1">Diskon % (Maks 10% — SR-07)</label>
                                        <input type="number" name="discount_pct" class="form-control form-control-sm o-control-input" value="0" min="0" max="100" step="0.5">
                                    </div>

                                    <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                                        <button type="submit" name="confirm_now" value="1" class="btn btn-pastel o-interactive flex-grow-1">
                                            ✓ Confirm
                                        </button>
                                        <button type="submit" name="confirm_now" value="0" class="btn btn-soft-gray o-interactive">
                                            Draft
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Daftar Sales Order & Penguncian Harga Otomatis (SR-07 & SR-08) -->
                        <div class="col-12 col-lg-7">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <span class="fw-bold text-dark">Daftar Quotation &amp; Sales Order (F-06, F-07, F-08)</span>
                                        <div class="text-muted small">Harga produk otomatis terkunci (read-only) setelah status berubah menjadi Confirmed (SR-07).</div>
                                    </div>
                                    <span class="badge bg-success"><?= count($salesOrders) ?> Dokumen SO</span>
                                </div>

                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($salesOrders as $so): ?>
                                        <?php
                                        $soNo       = (string) ($so['so_number'] ?? '');
                                        $soStat     = (string) ($so['status'] ?? 'Draft');
                                        $isLocked   = (bool) ($so['price_locked'] ?? false) || $soStat === 'Confirmed' || $soStat === 'Done';
                                        $firstLine  = (array) ($so['lines'][0] ?? []);
                                        $lineSku    = (string) ($firstLine['variant_sku'] ?? 'AIR-600');
                                        $lineQty    = (int) ($firstLine['ordered_qty'] ?? 0);
                                        $linePrice  = (float) ($firstLine['unit_price'] ?? 0);
                                        $lineDisc   = (float) ($firstLine['discount_pct'] ?? 0);
                                        $expData    = (array) ($so['exposure'] ?? []);
                                        $custLim    = (float) ($expData['credit_limit'] ?? 500000);
                                        $custExp    = (float) ($expData['current_exposure'] ?? 0);
                                        ?>
                                        <div class="p-3 rounded border <?= $isLocked ? 'bg-light' : 'bg-white' ?>">
                                            <!-- Baris Header SO & Statusbar Pipeline -->
                                            <div class="d-flex flex-wrap justify-content-between align-items-center pb-2 mb-2 border-bottom gap-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold text-dark"><?= htmlspecialchars($soNo) ?></span>
                                                    <span class="badge <?= $getStatusBadgeClass($soStat) ?>"><?= htmlspecialchars($soStat) ?></span>
                                                    <?php if ($isLocked): ?>
                                                        <span class="badge bg-secondary" title="Aturan SR-07: Harga & Diskon Terkunci Read-Only">
                                                            🔒 Harga Terkunci (Read-Only)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="o-statusbar">
                                                    <span class="o-statusbar-step <?= $soStat === 'Draft' ? 'current' : '' ?>">Draft</span>
                                                    <span class="o-statusbar-step <?= $soStat === 'Confirmed' ? 'current' : '' ?>">Confirmed</span>
                                                    <span class="o-statusbar-step <?= $soStat === 'Done' ? 'current' : '' ?>">Done</span>
                                                </div>
                                            </div>

                                            <!-- Odoo 17.0 Smart Buttons (oe_button_box) -->
                                            <div class="oe_button_box">
                                                <a href="index.php?page=inventory&screen=S-06" class="oe_stat_button" title="Lihat Delivery Order di Modul Gudang">
                                                    <i class="bi bi-truck text-primary"></i>
                                                    <div>
                                                        <span class="stat-number"><?= (int) ($firstLine['delivered_qty'] ?? 0) ?> / <?= $lineQty ?> pcs</span>
                                                        <span class="stat-label">1 Delivery</span>
                                                    </div>
                                                </a>
                                                <a href="index.php?page=accounting&screen=S-10" class="oe_stat_button" title="Lihat Customer Invoice di Modul Invoicing">
                                                    <i class="bi bi-receipt text-success"></i>
                                                    <div>
                                                        <span class="stat-number"><?= (int) ($firstLine['invoiced_qty'] ?? 0) ?> / <?= $lineQty ?> pcs</span>
                                                        <span class="stat-label">1 Invoice</span>
                                                    </div>
                                                </a>
                                            </div>

                                            <!-- Garis Besar Dokumen / Header SO (Odoo 17.0) -->
                                            <div class="row g-2 align-items-center small mb-2">
                                                <div class="col-12 col-md-7 text-secondary">
                                                    Pelanggan: <strong class="text-dark"><?= htmlspecialchars((string) ($so['customer_id'] ?? '')) ?> — <?= htmlspecialchars((string) ($so['customer_name'] ?? '')) ?></strong><br>
                                                    Gudang Pengiriman: <code><?= htmlspecialchars((string) ($so['source_location'] ?? 'WH-PKU')) ?></code> ·
                                                    Termin: <strong><?= htmlspecialchars((string) ($so['payment_terms'] ?? 'Immediate Payment')) ?></strong>
                                                </div>
                                                <div class="col-12 col-md-5 text-md-end text-secondary">
                                                    Credit Limit: <strong><?= $formatIdr($custLim) ?></strong><br>
                                                    Status Pajak: <span class="badge bg-light text-dark border">PKP Nonmewah (PPN 11%)</span>
                                                </div>
                                            </div>

                                            <!-- Baris Produk di Bawah / Lines Detail (Odoo 17.0) -->
                                            <div class="table-responsive my-2">
                                                <table class="table table-sm table-striped table-hover align-middle mb-0" style="font-size: 0.78rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>SKU Produk</th>
                                                            <th class="text-center">Ordered</th>
                                                            <th class="text-center">Delivered</th>
                                                            <th class="text-center">Invoiced</th>
                                                            <th class="text-end">Harga Satuan</th>
                                                            <th class="text-center">Pajak</th>
                                                            <th class="text-end">Subtotal (DPP)</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td><code><?= htmlspecialchars($lineSku) ?></code></td>
                                                            <td class="text-center"><?= $lineQty ?> pcs</td>
                                                            <td class="text-center text-success"><?= (int) ($firstLine['delivered_qty'] ?? 0) ?> pcs</td>
                                                            <td class="text-center text-primary"><?= (int) ($firstLine['invoiced_qty'] ?? 0) ?> pcs</td>
                                                            <td class="text-end"><?= $formatIdr($linePrice) ?></td>
                                                            <td class="text-center"><span class="badge bg-light text-dark border">PPN 11%</span></td>
                                                            <td class="text-end fw-semibold"><?= $formatIdr((float) ($so['subtotal_net'] ?? 0)) ?></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Ringkasan Finansial: DPP, PPN Keluaran, & Total Gross -->
                                            <div class="d-flex justify-content-end text-end small mb-2">
                                                <div class="p-2 rounded bg-light border" style="min-width: 270px; font-size: 0.76rem;">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">DPP (Net):</span>
                                                        <span class="fw-semibold"><?= $formatIdr((float) ($so['subtotal_net'] ?? 0)) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">PPN Keluaran (11%):</span>
                                                        <span><?= $formatIdr((float) ($so['tax_amount'] ?? 0)) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between border-top pt-1 mt-1 text-dark fw-bold">
                                                        <span>Total Akhir (Gross):</span>
                                                        <span class="text-success"><?= $formatIdr((float) ($so['total_gross'] ?? 0)) ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Baris Kontrol Harga (Read-Only saat Confirmed) & Tombol Confirm -->
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                                                <form method="POST" action="index.php?module=Sales&screen=S-05&view=<?= urlencode($activeView) ?>" class="d-flex flex-wrap align-items-center gap-2 m-0">
                                                    <input type="hidden" name="intent" value="sales_update_price">
                                                    <input type="hidden" name="so_number" value="<?= htmlspecialchars($soNo) ?>">

                                                    <div class="input-group input-group-sm" style="width: 175px;">
                                                        <span class="input-group-text">Harga Rp</span>
                                                        <input type="number" name="unit_price"
                                                               class="form-control <?= $isLocked ? 'bg-light text-muted o-action-disabled' : '' ?>"
                                                               value="<?= htmlspecialchars((string) $linePrice) ?>"
                                                               <?= $isLocked ? 'readonly tabindex="-1"' : '' ?>>
                                                    </div>

                                                    <div class="input-group input-group-sm" style="width: 140px;">
                                                        <span class="input-group-text">Diskon %</span>
                                                        <input type="number" name="discount_pct" step="0.5"
                                                               class="form-control <?= $isLocked ? 'bg-light text-muted o-action-disabled' : '' ?>"
                                                               value="<?= htmlspecialchars((string) $lineDisc) ?>"
                                                               <?= $isLocked ? 'readonly tabindex="-1"' : '' ?>>
                                                    </div>

                                                    <span class="<?= $isLocked ? 'o-blocked-wrapper' : '' ?>"
                                                          title="<?= $isLocked ? 'SR-07: Harga telah terkunci otomatis setelah SO Confirmed' : 'Simpan perubahan harga/diskon Draft' ?>">
                                                        <button type="submit" class="btn btn-sm btn-soft-gray <?= $isLocked ? 'o-action-disabled' : 'o-interactive' ?>">
                                                            Update Harga
                                                        </button>
                                                    </span>
                                                </form>

                                                <!-- Tombol Confirm SO -->
                                                <form method="POST" action="index.php?module=Sales&screen=S-05&view=<?= urlencode($activeView) ?>" class="m-0">
                                                    <input type="hidden" name="intent" value="sales_confirm_so">
                                                    <input type="hidden" name="so_number" value="<?= htmlspecialchars($soNo) ?>">
                                                    <span class="<?= $isLocked ? 'o-blocked-wrapper' : '' ?>"
                                                          title="<?= $isLocked ? 'SO sudah berstatus Confirmed (Terkunci)' : 'Validasi SR-07 & SR-08 lalu Confirm SO' ?>">
                                                        <button type="submit" class="btn btn-sm btn-pastel <?= $isLocked ? 'o-action-disabled' : 'o-interactive' ?>">
                                                            <?= $isLocked ? '✓ Confirmed' : 'Confirm SO' ?>
                                                        </button>
                                                    </span>
                                                </form>
                                            </div>

                                            <!-- Odoo 17.0 Chatter / Log Notes Riwayat Dokumen -->
                                            <div class="o-chatter-box mt-3">
                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                    <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">
                                                        <i class="bi bi-clock-history me-1"></i> Log Notes &amp; Riwayat Dokumen
                                                    </span>
                                                    <span class="badge bg-white text-muted border">Chatter</span>
                                                </div>
                                                <div class="o-chatter-item">
                                                    <div class="o-chatter-avatar"><i class="bi bi-person-fill"></i></div>
                                                    <div class="o-chatter-content">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <span class="fw-semibold small text-dark"><?= htmlspecialchars((string) ($so['created_by'] ?? 'Putri Aulia (Sales)')) ?></span>
                                                            <span class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars((string) ($so['order_date'] ?? date('Y-m-d H:i'))) ?></span>
                                                        </div>
                                                        <div class="small text-secondary">
                                                            Dokumen <strong><?= htmlspecialchars($soNo) ?></strong> dibuat.
                                                            <?php if ($soStat === 'Confirmed'): ?>
                                                                Status diubah menjadi <span class="badge bg-success">Confirmed</span>. Stok <code><?= htmlspecialchars($lineSku) ?></code> (<?= $lineQty ?> pcs) dicadangkan otomatis di <code>WH-PKU</code> (SR-08/SR-09). Harga dan diskon terkunci read-only (SR-07).
                                                            <?php else: ?>
                                                                Status dokumen saat ini <span class="badge bg-secondary">Draft</span>. Menunggu konfirmasi pesanan.
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2C. MODUL PROCUREMENT WORKSPACE (Fitur F-12, F-13 & Aturan SR-20, SR-10)
                     Hanya dirender saat Modul Purchase aktif dipilih
                     ========================================================= -->
                <?php if ($activePage === 'purchase'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-purchase">
                    <section class="row g-4">
                        <!-- Kolom Kiri: Form Pemesanan Vendor / Purchase Order (F-12, SR-20) & Form Penerimaan Barang (F-13, SR-10) -->
                        <div class="col-12 col-lg-5 d-flex flex-column gap-4">
                            <!-- Form Purchase Order (S-08 / F-12 / SR-20) -->
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Buat Purchase Order (F-12 · S-08)</span>
                                    <span class="badge bg-success">Auto Pricelist (SR-20)</span>
                                </div>
                                <form method="POST" action="index.php?module=Purchase&screen=S-08&view=<?= urlencode($activeView) ?>" class="row g-3" id="poCreateForm">
                                    <input type="hidden" name="intent" value="purchase_create_po">
                                    <input type="hidden" name="destination_location" value="WH-PKU">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Pilih Vendor (contacts_vendors)</label>
                                        <select name="vendor_id" id="poVendorSelect" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($vendors as $v): ?>
                                                <?php
                                                $vId   = (string) ($v['id'] ?? '');
                                                $vName = (string) ($v['name'] ?? '');
                                                $vTerm = (string) ($v['payment_terms'] ?? '');
                                                ?>
                                                <option value="<?= htmlspecialchars($vId) ?>">
                                                    <?= htmlspecialchars($vId . ' — ' . $vName . ' (' . $vTerm . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12 col-sm-7">
                                        <label class="form-label small fw-semibold mb-1">Pilih SKU Produk</label>
                                        <select name="variant_sku" id="poSkuSelect" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($products as $p): ?>
                                                <?php
                                                $pSku  = (string) ($p['variant_sku'] ?? '');
                                                $pName = (string) ($p['name'] ?? '');
                                                $pCost = (float) ($p['cost'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pSku) ?>" <?= $pSku === 'MIE-GOR-80' ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($pSku . ' — ' . $pName) ?> (Std Cost Rp<?= number_format($pCost, 0, ',', '.') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-sm-5">
                                        <label class="form-label small fw-semibold mb-1">Ordered Qty (pcs)</label>
                                        <input type="number" name="ordered_qty" class="form-control form-control-sm o-control-input" value="100" min="1" required>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">
                                            Harga Beli Acuan Otomatis dari <code>vendor_pricelist</code> (Aturan SR-20)
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">Rp</span>
                                            <input type="number" name="unit_price" id="poUnitPriceInput"
                                                   class="form-control o-control-input bg-light fw-semibold"
                                                   value="3000" readonly>
                                            <span class="input-group-text text-secondary" id="poPriceSourceBadge">vendor_pricelist</span>
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                                        <button type="submit" name="confirm_now" value="1" class="btn btn-pastel o-interactive flex-grow-1">
                                            ✓ Confirm PO
                                        </button>
                                        <button type="submit" name="confirm_now" value="0" class="btn btn-soft-gray o-interactive">
                                            Draft RFQ
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Form Penerimaan Barang / Quality Acceptance (F-13, SR-10, S-09) -->
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Penerimaan Barang Vendor (F-13 · S-09)</span>
                                    <span class="badge bg-warning text-dark">Hanya Accepted → On Hand (SR-10)</span>
                                </div>
                                <form method="POST" action="index.php?module=Inventory&screen=S-09&view=<?= urlencode($activeView) ?>" class="row g-3">
                                    <input type="hidden" name="intent" value="inventory_validate_receipt">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Pilih Purchase Order (Confirmed)</label>
                                        <select name="po_number" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($purchaseOrders as $po): ?>
                                                <?php
                                                $pNo   = (string) ($po['po_number'] ?? '');
                                                $pStat = (string) ($po['status'] ?? '');
                                                $pLine = (array) ($po['lines'][0] ?? []);
                                                $pSku  = (string) ($pLine['variant_sku'] ?? '');
                                                $pOrd  = (int) ($pLine['ordered_qty'] ?? 0);
                                                $pRec  = (int) ($pLine['received_qty'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pNo) ?>">
                                                    <?= htmlspecialchars($pNo . ' — ' . ($po['vendor_id'] ?? '') . ' (' . $pOrd . ' pcs ' . $pSku . ' · Status: ' . $pStat . ' · Received: ' . $pRec . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1 text-success">Accepted Qty (pcs)</label>
                                        <input type="number" name="accepted_qty" class="form-control form-control-sm o-control-input" value="85" min="1" required>
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1 text-danger">Rejected Qty (pcs)</label>
                                        <input type="number" name="rejected_qty" class="form-control form-control-sm o-control-input" value="15" min="0" required>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-pastel o-interactive w-100">
                                            ✓ Receive
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Daftar Purchase Order & Kunci Harga Otomatis (SR-20 & SR-18) -->
                        <div class="col-12 col-lg-7">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <span class="fw-bold text-dark">Daftar RFQ &amp; Purchase Order (F-12 · SR-20)</span>
                                        <div class="text-muted small">Harga beli diambil otomatis dari <code>vendor_pricelist</code> dan dikunci (read-only) saat Confirmed.</div>
                                    </div>
                                    <span class="badge bg-success"><?= count($purchaseOrders) ?> Dokumen PO</span>
                                </div>

                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($purchaseOrders as $po): ?>
                                        <?php
                                        $poNo      = (string) ($po['po_number'] ?? '');
                                        $poStat    = (string) ($po['status'] ?? 'RFQ');
                                        $poLocked  = (bool) ($po['price_locked'] ?? false) || $poStat !== 'RFQ';
                                        $poLine    = (array) ($po['lines'][0] ?? []);
                                        $poSku     = (string) ($poLine['variant_sku'] ?? '');
                                        $poOrdQty  = (int) ($poLine['ordered_qty'] ?? 0);
                                        $poAccQty  = (int) ($poLine['accepted_qty'] ?? 0);
                                        $poRejQty  = (int) ($poLine['rejected_qty'] ?? 0);
                                        $poPrice   = (float) ($poLine['unit_price'] ?? 0);
                                        ?>
                                        <div class="p-3 rounded border <?= $poLocked ? 'bg-light' : 'bg-white' ?>">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center pb-2 mb-2 border-bottom gap-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold text-dark"><?= htmlspecialchars($poNo) ?></span>
                                                    <span class="badge <?= $getStatusBadgeClass($poStat) ?>"><?= htmlspecialchars($poStat) ?></span>
                                                    <?php if ($poLocked): ?>
                                                        <span class="badge bg-secondary">🔒 Harga Terkunci (Read-Only)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="o-statusbar">
                                                    <span class="o-statusbar-step <?= $poStat === 'RFQ' ? 'current' : '' ?>">RFQ</span>
                                                    <span class="o-statusbar-step <?= $poStat === 'Confirmed' ? 'current' : '' ?>">Confirmed</span>
                                                    <span class="o-statusbar-step <?= $poStat === 'Received' ? 'current' : '' ?>">Received</span>
                                                </div>
                                            </div>

                                            <!-- Odoo 17.0 Smart Buttons (oe_button_box) -->
                                            <div class="oe_button_box">
                                                <a href="index.php?page=inventory&screen=S-07" class="oe_stat_button" title="Penerimaan Barang Gudang">
                                                    <i class="bi bi-box-seam text-warning"></i>
                                                    <div>
                                                        <span class="stat-number"><?= $poAccQty > 0 ? "{$poAccQty} pcs Accepted" : 'Pending' ?></span>
                                                        <span class="stat-label">1 Receipt</span>
                                                    </div>
                                                </a>
                                                <a href="index.php?page=accounting&screen=S-12" class="oe_stat_button" title="Tagihan Pemasok (Vendor Bill)">
                                                    <i class="bi bi-file-earmark-text text-danger"></i>
                                                    <div>
                                                        <span class="stat-number">Three-Way Match</span>
                                                        <span class="stat-label">1 Vendor Bill</span>
                                                    </div>
                                                </a>
                                            </div>

                                            <!-- Garis Besar Dokumen / Header PO (Odoo 17.0) -->
                                            <div class="row g-2 align-items-center small mb-2">
                                                <div class="col-12 col-md-7 text-secondary">
                                                    Vendor: <strong class="text-dark"><?= htmlspecialchars((string) ($po['vendor_id'] ?? '')) ?> — <?= htmlspecialchars((string) ($po['vendor_name'] ?? '')) ?></strong><br>
                                                    Gudang Tujuan: <code><?= htmlspecialchars((string) ($po['destination_location'] ?? 'WH-PKU')) ?></code> ·
                                                    Pricelist: <span class="badge bg-light text-dark border">vendor_pricelist</span>
                                                </div>
                                                <div class="col-12 col-md-5 text-md-end text-secondary">
                                                    Status Penerimaan:
                                                    <?php if ($poAccQty > 0 || $poRejQty > 0): ?>
                                                        <span class="text-success fw-semibold">Accepted: <?= $poAccQty ?> pcs</span> · <span class="text-danger">Rejected: <?= $poRejQty ?> pcs</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Menunggu Penerimaan</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Baris Produk di Bawah / Lines Detail (Odoo 17.0) -->
                                            <div class="table-responsive my-2">
                                                <table class="table table-sm table-striped table-hover align-middle mb-0" style="font-size: 0.78rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>SKU Produk</th>
                                                            <th class="text-center">Ordered</th>
                                                            <th class="text-center">Accepted</th>
                                                            <th class="text-center">Billed</th>
                                                            <th class="text-end">Harga Beli</th>
                                                            <th class="text-center">Pajak</th>
                                                            <th class="text-end">Subtotal (DPP)</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td><code><?= htmlspecialchars($poSku) ?></code></td>
                                                            <td class="text-center"><?= $poOrdQty ?> pcs</td>
                                                            <td class="text-center text-success"><?= $poAccQty ?> pcs</td>
                                                            <td class="text-center text-primary"><?= (int) ($poLine['billed_qty'] ?? 0) ?> pcs</td>
                                                            <td class="text-end"><?= $formatIdr($poPrice) ?></td>
                                                            <td class="text-center"><span class="badge bg-light text-dark border">PPN 11%</span></td>
                                                            <td class="text-end fw-semibold"><?= $formatIdr((float) ($po['subtotal_net'] ?? 0)) ?></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Ringkasan Finansial: DPP, PPN Masukan, & Total Gross -->
                                            <div class="d-flex justify-content-end text-end small mb-2">
                                                <div class="p-2 rounded bg-light border" style="min-width: 270px; font-size: 0.76rem;">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">DPP Pembelian:</span>
                                                        <span class="fw-semibold"><?= $formatIdr((float) ($po['subtotal_net'] ?? 0)) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">PPN Masukan (11%):</span>
                                                        <span><?= $formatIdr((float) ($po['tax_amount'] ?? 0)) ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between border-top pt-1 mt-1 text-dark fw-bold">
                                                        <span>Total Tagihan (Gross):</span>
                                                        <span class="text-success"><?= $formatIdr((float) ($po['total_gross'] ?? 0)) ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                                                <form method="POST" action="index.php?module=Purchase&screen=S-08&view=<?= urlencode($activeView) ?>" class="d-flex flex-wrap align-items-center gap-2 m-0">
                                                    <input type="hidden" name="intent" value="purchase_update_price">
                                                    <input type="hidden" name="po_number" value="<?= htmlspecialchars($poNo) ?>">

                                                    <div class="input-group input-group-sm" style="width: 185px;">
                                                        <span class="input-group-text">Harga Rp</span>
                                                        <input type="number" name="unit_price"
                                                               class="form-control <?= $poLocked ? 'bg-light text-muted o-action-disabled' : '' ?>"
                                                               value="<?= htmlspecialchars((string) $poPrice) ?>"
                                                               <?= $poLocked ? 'readonly tabindex="-1"' : '' ?>>
                                                    </div>

                                                    <span class="<?= $poLocked ? 'o-blocked-wrapper' : '' ?>"
                                                          title="<?= $poLocked ? 'SR-18: Harga beli terkunci setelah PO Confirmed' : 'Ubah harga RFQ' ?>">
                                                        <button type="submit" class="btn btn-sm btn-soft-gray <?= $poLocked ? 'o-action-disabled' : 'o-interactive' ?>">
                                                            Update Harga
                                                        </button>
                                                    </span>
                                                </form>

                                                <form method="POST" action="index.php?module=Purchase&screen=S-08&view=<?= urlencode($activeView) ?>" class="m-0">
                                                    <input type="hidden" name="intent" value="purchase_confirm_po">
                                                    <input type="hidden" name="po_number" value="<?= htmlspecialchars($poNo) ?>">
                                                    <span class="<?= $poLocked ? 'o-blocked-wrapper' : '' ?>">
                                                        <button type="submit" class="btn btn-sm btn-pastel <?= $poLocked ? 'o-action-disabled' : 'o-interactive' ?>">
                                                            <?= $poLocked ? '✓ ' . htmlspecialchars($poStat) : 'Confirm PO' ?>
                                                        </button>
                                                    </span>
                                                </form>
                                            </div>

                                            <!-- Odoo 17.0 Chatter / Log Notes Riwayat Dokumen -->
                                            <div class="o-chatter-box mt-3">
                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                    <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">
                                                        <i class="bi bi-clock-history me-1"></i> Log Notes &amp; Riwayat Dokumen
                                                    </span>
                                                    <span class="badge bg-white text-muted border">Chatter</span>
                                                </div>
                                                <div class="o-chatter-item">
                                                    <div class="o-chatter-avatar"><i class="bi bi-person-fill"></i></div>
                                                    <div class="o-chatter-content">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <span class="fw-semibold small text-dark"><?= htmlspecialchars((string) ($po['created_by'] ?? 'Mira Salsabila (Procurement)')) ?></span>
                                                            <span class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars((string) ($po['order_date'] ?? date('Y-m-d H:i'))) ?></span>
                                                        </div>
                                                        <div class="small text-secondary">
                                                            Dokumen <strong><?= htmlspecialchars($poNo) ?></strong> dibuat.
                                                            <?php if ($poStat === 'Confirmed'): ?>
                                                                Status diubah menjadi <span class="badge bg-success">Confirmed</span>. Harga beli dikunci sebesar Rp<?= number_format($poPrice, 0, ',', '.') ?> dari <code>vendor_pricelist</code> (SR-20). Menunggu barang tiba di <code>WH-PKU</code>.
                                                            <?php else: ?>
                                                                Status dokumen saat ini <span class="badge bg-secondary"><?= htmlspecialchars($poStat) ?></span>.
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2D. MODUL WAREHOUSE / INVENTORY WORKSPACE (Fitur F-09, F-10, F-13, F-21)
                     Hanya dirender saat Modul Inventory aktif dipilih
                     ========================================================= -->
                <?php if ($activePage === 'inventory'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-inventory">
                    <section class="row g-4">
                        <!-- 1. Form Delivery Order & Backorder (F-09, F-10, SR-09, S-06) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Delivery Order &amp; Backorder (F-10 · S-06)</span>
                                    <span class="badge bg-success">SR-04 &amp; SR-09</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Mengurangi <code>On Hand</code> &amp; <code>Reserved</code> sebesar kuantitas kirim serta otomatis membuat Backorder untuk sisa pesanan.
                                </p>
                                <form method="POST" action="index.php?module=Inventory&screen=S-06&view=<?= urlencode($activeView) ?>" class="row g-3">
                                    <input type="hidden" name="intent" value="inventory_validate_delivery">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Pilih Sales Order (Confirmed)</label>
                                        <select name="so_number" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($salesOrders as $so): ?>
                                                <?php
                                                $sNo   = (string) ($so['so_number'] ?? '');
                                                $sStat = (string) ($so['status'] ?? '');
                                                $sLine = (array) ($so['lines'][0] ?? []);
                                                $sSku  = (string) ($sLine['variant_sku'] ?? 'AIR-600');
                                                $sOrd  = (int) ($sLine['ordered_qty'] ?? 0);
                                                $sDel  = (int) ($sLine['delivered_qty'] ?? 0);
                                                $sRem  = max(0, $sOrd - $sDel);
                                                ?>
                                                <option value="<?= htmlspecialchars($sNo) ?>">
                                                    <?= htmlspecialchars($sNo . ' — ' . ($so['customer_id'] ?? '') . ' (' . $sSku . ' · Sisa: ' . $sRem . '/' . $sOrd . ' pcs · ' . $sStat . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Done / Deliver Qty (pcs)</label>
                                        <input type="number" name="deliver_qty" class="form-control form-control-sm o-control-input" value="12" min="1" required>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-pastel o-interactive w-100">
                                            ✓ Deliver
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 2. Form Penerimaan Barang / Quality Acceptance (F-13, SR-10, S-09) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Receipt &amp; Quality Acceptance (F-13 · S-09)</span>
                                    <span class="badge bg-warning text-dark">SR-10</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Input kuantitas <strong>Accepted</strong> dan <strong>Rejected</strong>. Hanya nilai <strong>Accepted</strong> yang menambah stok <code>On Hand</code> gudang.
                                </p>
                                <form method="POST" action="index.php?module=Inventory&screen=S-09&view=<?= urlencode($activeView) ?>" class="row g-3">
                                    <input type="hidden" name="intent" value="inventory_validate_receipt">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Pilih Purchase Order</label>
                                        <select name="po_number" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($purchaseOrders as $po): ?>
                                                <?php
                                                $pNo   = (string) ($po['po_number'] ?? '');
                                                $pStat = (string) ($po['status'] ?? '');
                                                $pLine = (array) ($po['lines'][0] ?? []);
                                                $pSku  = (string) ($pLine['variant_sku'] ?? '');
                                                $pOrd  = (int) ($pLine['ordered_qty'] ?? 0);
                                                $pRec  = (int) ($pLine['received_qty'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pNo) ?>">
                                                    <?= htmlspecialchars($pNo . ' (' . $pOrd . ' pcs ' . $pSku . ' · ' . $pStat . ' · Received: ' . $pRec . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1 text-success">Accepted (pcs)</label>
                                        <input type="number" name="accepted_qty" class="form-control form-control-sm o-control-input" value="85" min="1" required>
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1 text-danger">Rejected (pcs)</label>
                                        <input type="number" name="rejected_qty" class="form-control form-control-sm o-control-input" value="15" min="0" required>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-pastel o-interactive w-100">
                                            ✓ Receive
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 3. Form Internal Transfer Antar Gudang/Toko (F-21, SR-17, S-07) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Internal Transfer (F-21 · S-07)</span>
                                    <span class="badge bg-info text-dark">SR-17</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Memindahkan fisik barang antar lokasi tanpa mengubah total valuasi persediaan perusahaan
                                    (Saat ini: <strong>Rp<?= number_format((float) ($companyVal['total_company_valuation'] ?? 9628900), 0, ',', '.') ?></strong>).
                                </p>
                                <form method="POST" action="index.php?module=Inventory&screen=S-07&view=<?= urlencode($activeView) ?>" class="row g-3">
                                    <input type="hidden" name="intent" value="inventory_internal_transfer">

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1">Dari Lokasi</label>
                                        <select name="from_location" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($locations as $loc): ?>
                                                <?php $lCode = (string) ($loc['location_code'] ?? ''); ?>
                                                <option value="<?= htmlspecialchars($lCode) ?>" <?= $lCode === 'WH-PKU' ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($lCode) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label small fw-semibold mb-1">Ke Lokasi</label>
                                        <select name="to_location" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($locations as $loc): ?>
                                                <?php $lCode = (string) ($loc['location_code'] ?? ''); ?>
                                                <option value="<?= htmlspecialchars($lCode) ?>" <?= $lCode === 'STORE-PKU' ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($lCode) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-7">
                                        <label class="form-label small fw-semibold mb-1">SKU Produk</label>
                                        <select name="variant_sku" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($products as $p): ?>
                                                <?php
                                                $pSku   = (string) ($p['variant_sku'] ?? '');
                                                $pAvail = (int) ($p['wh_available'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pSku) ?>">
                                                    <?= htmlspecialchars($pSku) ?> (WH Avail: <?= $pAvail ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-5">
                                        <label class="form-label small fw-semibold mb-1">Qty Move</label>
                                        <input type="number" name="qty_move" class="form-control form-control-sm o-control-input" value="15" min="1" required>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-pastel o-interactive w-100">
                                            ⇄ Transfer
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2E. MODUL ACCOUNTING & INVOICING WORKSPACE (Arif Firmansyah — Kepala Finance)
                     Fitur F-11, F-14, F-15, F-16, F-20, F-22 & Aturan SR-02, SR-06, SR-12, SR-15, SR-16, SR-21
                     ========================================================= -->
                <?php if ($activePage === 'accounting'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-accounting">
                    <section class="row g-4">
                        <!-- 1. Customer Invoice atas Delivered Qty (F-11 · SR-02 vs SR-01 · S-10) -->
                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Customer Invoice — Penagihan B2B (F-11 · S-10)</span>
                                    <span class="badge bg-success">SR-02 (Delivered Qty)</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Menolak penagihan atas barang yang belum dikirim (<code>Delivered Qty - Invoiced Qty</code>) dan memposting jurnal seimbang (<code>DR 1100 AR</code> / <code>CR 4010 Sales Revenue</code> + <code>CR 2110 Output VAT</code>).
                                </p>
                                <form method="POST" action="index.php?module=Accounting&screen=S-10&view=<?= urlencode($activeView) ?>" class="row g-2 mb-3">
                                    <input type="hidden" name="intent" value="accounting_post_invoice">
                                    <div class="col-12 col-md-7">
                                        <label class="form-label small fw-semibold mb-1">Pilih Sales Order</label>
                                        <select name="so_number" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($salesOrders as $so): ?>
                                                <?php
                                                $sNo   = (string) ($so['so_number'] ?? '');
                                                $sLine = (array) ($so['lines'][0] ?? []);
                                                $sOrd  = (int) ($sLine['ordered_qty'] ?? 0);
                                                $sDel  = (int) ($sLine['delivered_qty'] ?? 0);
                                                $sInv  = (int) ($sLine['invoiced_qty'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($sNo) ?>">
                                                    <?= htmlspecialchars($sNo . ' — ' . ($so['customer_id'] ?? '') . ' (Ordered: ' . $sOrd . ' · Delivered: ' . $sDel . ' · Invoiced: ' . $sInv . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small fw-semibold mb-1">Invoice Qty</label>
                                        <input type="number" name="invoice_qty" class="form-control form-control-sm o-control-input" value="12" min="1" required>
                                    </div>
                                    <div class="col-6 col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">✓ Post Invoice</button>
                                    </div>
                                </form>

                                <?php if (!empty($customerInvoices)): ?>
                                    <div class="border-top pt-2 small">
                                        <div class="fw-semibold text-secondary mb-1">Daftar Customer Invoice Aktif:</div>
                                        <?php foreach ($customerInvoices as $inv): ?>
                                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                                <span>
                                                    <strong><?= htmlspecialchars((string) ($inv['invoice_number'] ?? '')) ?></strong>
                                                    (SO: <code><?= htmlspecialchars((string) ($inv['so_number'] ?? '')) ?></code> · <?= (int) ($inv['invoiced_qty'] ?? 0) ?> pcs <code><?= htmlspecialchars((string) ($inv['variant_sku'] ?? '')) ?></code>)
                                                </span>
                                                <span>
                                                    Sisa: <strong>Rp<?= number_format((float) ($inv['outstanding_amount'] ?? 0), 0, ',', '.') ?></strong>
                                                    <span class="badge <?= $getStatusBadgeClass((string) ($inv['status'] ?? 'Posted')) ?> ms-1"><?= htmlspecialchars((string) ($inv['status'] ?? 'Posted')) ?></span>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. Vendor Bill 3-Way Match & Purchase Price Variance (F-14 · SR-06 vs SR-05 · S-11) -->
                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Vendor Bill — Three-Way Match &amp; PPV (F-14 · S-11)</span>
                                    <span class="badge bg-warning text-dark">SR-06 (Accepted Qty)</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Hanya mengizinkan tagihan sebesar <code>Accepted Qty - Billed Qty</code>, mengosongkan <code>1150 Stock Interim Received</code>, dan mencatat selisih harga ke <code>5110 Purchase Price Variance (PPV)</code>.
                                </p>
                                <form method="POST" action="index.php?module=Accounting&screen=S-11&view=<?= urlencode($activeView) ?>" class="row g-2 mb-3">
                                    <input type="hidden" name="intent" value="accounting_post_bill">
                                    <div class="col-12 col-md-7">
                                        <label class="form-label small fw-semibold mb-1">Pilih Purchase Order (Received)</label>
                                        <select name="po_number" class="form-select form-select-sm o-control-input o-interactive" required>
                                            <?php foreach ($purchaseOrders as $po): ?>
                                                <?php
                                                $pNo   = (string) ($po['po_number'] ?? '');
                                                $pLine = (array) ($po['lines'][0] ?? []);
                                                $pOrd  = (int) ($pLine['ordered_qty'] ?? 0);
                                                $pAcc  = (int) ($pLine['accepted_qty'] ?? 0);
                                                $pBill = (int) ($pLine['billed_qty'] ?? 0);
                                                ?>
                                                <option value="<?= htmlspecialchars($pNo) ?>">
                                                    <?= htmlspecialchars($pNo . ' — ' . ($po['vendor_id'] ?? '') . ' (Ordered: ' . $pOrd . ' · Accepted: ' . $pAcc . ' · Billed: ' . $pBill . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small fw-semibold mb-1">Bill Qty</label>
                                        <input type="number" name="bill_qty" class="form-control form-control-sm o-control-input" value="85" min="1" required>
                                    </div>
                                    <div class="col-6 col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">✓ Post Bill</button>
                                    </div>
                                </form>

                                <?php if (!empty($vendorBills)): ?>
                                    <div class="border-top pt-2 small">
                                        <div class="fw-semibold text-secondary mb-1">Daftar Vendor Bill Aktif:</div>
                                        <?php foreach ($vendorBills as $vb): ?>
                                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                                <span>
                                                    <strong><?= htmlspecialchars((string) ($vb['bill_number'] ?? '')) ?></strong>
                                                    (PO: <code><?= htmlspecialchars((string) ($vb['po_number'] ?? '')) ?></code> · Accepted: <?= (int) ($vb['accepted_qty'] ?? 0) ?> pcs · PPV: Rp<?= number_format((float) ($vb['ppv_amount'] ?? 0), 0, ',', '.') ?>)
                                                </span>
                                                <span>
                                                    Sisa: <strong>Rp<?= number_format((float) ($vb['outstanding_amount'] ?? $vb['total_gross'] ?? 0), 0, ',', '.') ?></strong>
                                                    <span class="badge <?= $getStatusBadgeClass((string) ($vb['status'] ?? 'Draft')) ?> ms-1"><?= htmlspecialchars((string) ($vb['status'] ?? 'Draft')) ?></span>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 3. Register Partial Payment & Reconciliation (F-15 · SR-15 · S-12) -->
                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Register Partial Payment &amp; Reconciliation (F-15 · S-12)</span>
                                    <span class="badge bg-info text-dark">SR-15 (No Overpayment)</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Mencatat pelunasan bertahap atas Customer Invoice (<code>AR 1100</code>) atau Vendor Bill (<code>AP 2010</code>) melalui <code>1010 Bank BCA</code> dan memblokir overpayment.
                                </p>
                                <form method="POST" action="index.php?module=Accounting&screen=S-12&view=<?= urlencode($activeView) ?>" class="row g-2">
                                    <input type="hidden" name="intent" value="accounting_register_payment">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small fw-semibold mb-1">Nomor Invoice / Vendor Bill</label>
                                        <input type="text" name="target_document" class="form-control form-control-sm o-control-input" value="<?= htmlspecialchars((string) ($customerInvoices[0]['invoice_number'] ?? 'INV-0001')) ?>" placeholder="Mis. INV-0001 atau BILL-0002" required>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label small fw-semibold mb-1">Nominal Bayar (Rp)</label>
                                        <input type="number" step="0.01" name="amount" class="form-control form-control-sm o-control-input" value="25000" min="1" required>
                                    </div>
                                    <div class="col-6 col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">💳 Pay</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 4. B2B Return & Credit Note (F-20 · SR-16 · S-17) -->
                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">B2B Sales Return &amp; Credit Note (F-20 · S-17)</span>
                                    <span class="badge bg-secondary">SR-16 (Restock + Reverse COGS/AR)</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Mengembalikan stok fisik ke <code>WH-PKU</code>, membalik HPP (<code>DR 1130</code> / <code>CR 5010</code>), dan menerbitkan Credit Note yang mengurangi saldo <code>1100 AR</code>.
                                </p>
                                <form method="POST" action="index.php?module=Accounting&screen=S-17&view=<?= urlencode($activeView) ?>" class="row g-2">
                                    <input type="hidden" name="intent" value="accounting_b2b_return">
                                    <div class="col-6 col-md-5">
                                        <label class="form-label small fw-semibold mb-1">Nomor Invoice Asal</label>
                                        <input type="text" name="invoice_number" class="form-control form-control-sm o-control-input" value="<?= htmlspecialchars((string) ($customerInvoices[0]['invoice_number'] ?? 'INV-0001')) ?>" required>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-semibold mb-1">Return Qty (pcs)</label>
                                        <input type="number" name="return_qty" class="form-control form-control-sm o-control-input" value="2" min="1" required>
                                    </div>
                                    <div class="col-12 col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">↩ Credit Note</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2F. MODUL POINT OF SALE / POS WORKSPACE (Rani Oktaviani — Supervisor Kasir)
                     Fitur F-17, F-18, F-19, F-20 & Aturan SR-03, SR-04, SR-11, SR-12, SR-13, SR-14, SR-16
                     ========================================================= -->
                <?php if ($activePage === 'pos'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-pos">
                    <?php
                    $activePosSession = (array) ($posSessions[0] ?? []);
                    $posSessStatus    = (string) ($activePosSession['status'] ?? 'Open');
                    $posSessLoc       = (string) ($activePosSession['location_code'] ?? 'STORE-PKU');
                    ?>
                    <section class="row g-4">
                        <!-- Baris Status Sesi Kasir Ritel (Open -> In Progress -> Closed) & Pembukaan Sesi Toko -->
                        <div class="col-12">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="fw-bold text-dark">Sistem Sesi Kasir Ritel POS (F-17 · SR-13 &amp; SR-14 · S-14)</span>
                                            <span class="badge <?= $getStatusBadgeClass($posSessStatus) ?>"><?= htmlspecialchars($posSessStatus) ?></span>
                                        </div>
                                        <div class="text-muted small">
                                            Sesi Aktif: <code><?= htmlspecialchars((string) ($activePosSession['session_number'] ?? 'POS-SESSION-0001')) ?></code> ·
                                            Lokasi Toko: <code><?= htmlspecialchars($posSessLoc) ?></code> ·
                                            Kasir: <strong><?= htmlspecialchars((string) ($activePosSession['cashier_name'] ?? 'Rani Oktaviani')) ?></strong> ·
                                            Opening Cash: <strong>Rp<?= number_format((float) ($activePosSession['opening_cash'] ?? 200000), 0, ',', '.') ?></strong>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-3">
                                        <div class="o-statusbar">
                                            <span class="o-statusbar-step <?= $posSessStatus === 'Open' ? 'current' : '' ?>">Open</span>
                                            <span class="o-statusbar-step <?= $posSessStatus === 'In Progress' ? 'current' : '' ?>">In Progress</span>
                                            <span class="o-statusbar-step <?= $posSessStatus === 'Closed' ? 'current' : '' ?>">Closed</span>
                                        </div>

                                        <form method="POST" action="index.php?module=Point+of+Sale&screen=S-14&view=<?= urlencode($activeView) ?>" class="d-flex align-items-center gap-2 m-0">
                                            <input type="hidden" name="intent" value="pos_open_session">
                                            <select name="location_code" class="form-select form-select-sm o-control-input o-interactive" style="width: 140px;">
                                                <option value="STORE-PKU" <?= $posSessLoc === 'STORE-PKU' ? 'selected' : '' ?>>STORE-PKU</option>
                                                <option value="STORE-DUM" <?= $posSessLoc === 'STORE-DUM' ? 'selected' : '' ?>>STORE-DUM</option>
                                            </select>
                                            <input type="hidden" name="opening_cash" value="200000">
                                            <button type="submit" class="btn btn-sm btn-pastel o-interactive">
                                                + Open Sesi
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 1. Keranjang Belanja POS STORE-PKU / STORE-DUM (F-18 · SR-04 & SR-13 · S-15) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Keranjang Belanja POS Toko (F-18 · S-15)</span>
                                    <span class="badge bg-success">SR-04 &amp; SR-13</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Checkout tunai langsung memotong stok fisik di <code>STORE-PKU</code> atau <code>STORE-DUM</code> secara real-time tanpa stok negatif (<code>SR-04</code>), mengubah sesi ke <code>In Progress</code>, dan memposting HPP satu kali.
                                </p>
                                <form method="POST" action="index.php?module=Point+of+Sale&screen=S-15&view=<?= urlencode($activeView) ?>" class="row g-2">
                                    <input type="hidden" name="intent" value="pos_checkout_basket">
                                    <input type="hidden" name="session_number" value="<?= htmlspecialchars((string) ($activePosSession['session_number'] ?? 'POS-SESSION-0001')) ?>">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Lokasi Toko Ritel</label>
                                        <select name="location_code" class="form-select form-select-sm o-control-input o-interactive">
                                            <option value="STORE-PKU" <?= $posSessLoc === 'STORE-PKU' ? 'selected' : '' ?>>STORE-PKU — Toko Ritel Pekanbaru</option>
                                            <option value="STORE-DUM" <?= $posSessLoc === 'STORE-DUM' ? 'selected' : '' ?>>STORE-DUM — Toko Ritel Dumai</option>
                                        </select>
                                    </div>

                                    <div class="col-8">
                                        <label class="form-label small fw-semibold mb-1">Item 1 (SKU Toko)</label>
                                        <input type="text" name="sku_1" class="form-control form-control-sm o-control-input" value="MIE-GOR-80" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold mb-1">Qty 1</label>
                                        <input type="number" name="qty_1" class="form-control form-control-sm o-control-input" value="2" min="1" required>
                                    </div>

                                    <div class="col-8">
                                        <label class="form-label small fw-semibold mb-1">Item 2 (Opsional)</label>
                                        <input type="text" name="sku_2" class="form-control form-control-sm o-control-input" value="AIR-600">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold mb-1">Qty 2</label>
                                        <input type="number" name="qty_2" class="form-control form-control-sm o-control-input" value="1" min="0">
                                    </div>

                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">
                                            🛒 Pay
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 2. Retur & Cash Refund POS (F-20 · SR-16 · S-17) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">POS Cash Refund &amp; Restock (F-20 · S-17)</span>
                                    <span class="badge bg-warning text-dark">SR-16</span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Mengembalikan barang ke stok <code>STORE-PKU</code>, membalik COGS &amp; pendapatan, serta mengurangi <code>Expected Cash</code> laci kasir.
                                </p>
                                <form method="POST" action="index.php?module=Point+of+Sale&screen=S-17&view=<?= urlencode($activeView) ?>" class="row g-2">
                                    <input type="hidden" name="intent" value="pos_refund_receipt">
                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Nomor Struk POS</label>
                                        <input type="text" name="receipt_number" class="form-control form-control-sm o-control-input" value="<?= htmlspecialchars((string) ($posReceipts[0]['receipt_number'] ?? 'POS-RCPT-0001')) ?>" required>
                                    </div>
                                    <div class="col-7">
                                        <label class="form-label small fw-semibold mb-1">SKU Retur</label>
                                        <input type="text" name="variant_sku" class="form-control form-control-sm o-control-input" value="AIR-600" required>
                                    </div>
                                    <div class="col-5">
                                        <label class="form-label small fw-semibold mb-1">Qty Retur</label>
                                        <input type="number" name="return_qty" class="form-control form-control-sm o-control-input" value="1" min="1" required>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">
                                            ↩ Refund
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 3. Rekonsiliasi Kas & Penutupan Sesi POS (F-19 · SR-11 vs SR-12 & SR-14 · S-16) -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Rekonsiliasi Kas &amp; Tutup Sesi (F-19 · S-16)</span>
                                    <span class="badge bg-info text-dark">SR-12 &amp; SR-14</span>
                                </div>
                                <p class="text-muted small mb-2">
                                    Expected Cash: <strong>Rp<?= number_format((float) ($activePosSession['expected_closing_cash'] ?? 200000), 0, ',', '.') ?></strong>
                                    (Opening Rp200.000 + Tunai Rp<?= number_format((float) ($activePosSession['cash_tenders'] ?? 0), 0, ',', '.') ?> − Refund Rp<?= number_format((float) ($activePosSession['cash_refunds'] ?? 0), 0, ',', '.') ?>).
                                </p>
                                <form method="POST" action="index.php?module=Point+of+Sale&screen=S-16&view=<?= urlencode($activeView) ?>" class="row g-2">
                                    <input type="hidden" name="intent" value="pos_close_session">
                                    <input type="hidden" name="session_number" value="<?= htmlspecialchars((string) ($activePosSession['session_number'] ?? 'POS-SESSION-0001')) ?>">

                                    <div class="col-12">
                                        <label class="form-label small fw-semibold mb-1">Counted Cash Fisik (Rp)</label>
                                        <input type="number" step="0.01" name="counted_cash" class="form-control form-control-sm o-control-input" value="<?= htmlspecialchars((string) ($activePosSession['expected_closing_cash'] ?? 200000)) ?>" required>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check small">
                                            <input class="form-check-input" type="checkbox" name="finance_approved" value="1" id="finApproveCheck">
                                            <label class="form-check-label text-secondary" for="finApproveCheck">
                                                Sertakan Otorisasi Kepala Finance (Wajib sesuai <code>SR-12</code> jika terdapat selisih kas)
                                            </label>
                                        </div>
                                    </div>

                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-sm btn-pastel o-interactive w-100">
                                            🔒 Close Sesi
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- =========================================================
                     2G. MODUL DIREKTUR OPERASIONAL / GOVERNANCE WORKSPACE (Budi Haryanto — Owner)
                     Fitur F-22, F-23 & Aturan SR-18, SR-19, SR-21, SR-22 (S-18)
                     ========================================================= -->
                <?php if ($activePage === 'governance'): ?>
                <div class="o-module-view o-spa-fade-in d-flex flex-column gap-4" id="view-governance">
                    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'exec_dashboard.php'; ?>
                    <section class="row g-4">
                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Dasbor Analitik Real-Time ReportController (F-22 · SR-19 · S-18)</span>
                                    <span class="badge bg-success">Khusus Finance &amp; Direktur</span>
                                </div>
                                <div class="row g-2 small">
                                    <div class="col-6 p-2 border rounded bg-light">
                                        <div class="text-muted">Total Pendapatan Net (SQL Live)</div>
                                        <div class="fw-bold text-dark">Rp<?= number_format((float) ($reportAnalytics['total_revenue'] ?? $liveKpi['net_revenue'] ?? 0), 0, ',', '.') ?></div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Gross: Rp<?= number_format((float) ($reportAnalytics['total_revenue_gross'] ?? 0), 0, ',', '.') ?></div>
                                    </div>
                                    <div class="col-6 p-2 border rounded bg-light">
                                        <div class="text-muted">Total Valuasi Stok Gudang</div>
                                        <div class="fw-bold text-dark">Rp<?= number_format((float) ($reportAnalytics['total_stock_valuation'] ?? $liveKpi['stock_valuation_1130'] ?? 9628900), 0, ',', '.') ?></div>
                                        <div class="text-muted" style="font-size: 0.72rem;">WH-PKU: Rp<?= number_format((float) ($reportAnalytics['wh_pku_stock_valuation'] ?? 4997000), 0, ',', '.') ?></div>
                                    </div>
                                    <div class="col-6 p-2 border rounded bg-light">
                                        <div class="text-muted">Total Piutang (AR) Invoice</div>
                                        <div class="fw-bold text-dark">Rp<?= number_format((float) ($reportAnalytics['customer_invoices_ar'] ?? 0), 0, ',', '.') ?></div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Total AR (1100): Rp<?= number_format((float) ($reportAnalytics['total_ar'] ?? $liveKpi['ar_1100'] ?? 888000), 0, ',', '.') ?></div>
                                    </div>
                                    <div class="col-6 p-2 border rounded bg-light">
                                        <div class="text-muted">Total Utang (AP) Vendor Bill</div>
                                        <div class="fw-bold text-dark">Rp<?= number_format((float) ($reportAnalytics['vendor_bills_ap'] ?? 0), 0, ',', '.') ?></div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Total AP (2010): Rp<?= number_format((float) ($reportAnalytics['total_ap'] ?? $liveKpi['ap_2010'] ?? 555000), 0, ',', '.') ?></div>
                                    </div>
                                </div>
                                <div class="mt-3 pt-3 border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <span class="small text-muted">
                                        Reset 15 SKU, 6 Pelanggan, 4 Vendor &amp; Jurnal Pembukaan <strong>Rp25.716.900</strong>:
                                    </span>
                                    <div class="d-flex gap-2">
                                        <form method="POST" action="reset_fixture.php?redirect=1" class="m-0">
                                            <button type="submit" class="btn btn-sm btn-danger rounded-3 o-interactive">
                                                🔄 Reset Database
                                            </button>
                                        </form>
                                        <a href="reset_fixture.php" class="btn btn-sm btn-outline-secondary rounded-3 o-interactive">
                                            Detail Verifikasi
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="o-surface-card bg-white rounded-4 shadow-sm p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold text-dark">Audit Trail Mutlak &amp; Pencegahan Jurnal Ganda (F-23 · SR-18 · SR-22)</span>
                                    <span class="badge bg-dark"><?= count($auditLogs) ?> Log Terbaru</span>
                                </div>
                                <div class="table-responsive" style="max-height: 190px; overflow-y: auto;">
                                    <table class="table table-sm table-striped small mb-0">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>Aktor / Role</th>
                                                <th>Aksi &amp; Dokumen</th>
                                                <th>Hasil</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($auditLogs, 0, 8) as $log): ?>
                                                <tr>
                                                    <td class="text-nowrap"><?= htmlspecialchars(substr((string) ($log['created_at'] ?? ''), 11, 8)) ?></td>
                                                    <td><?= htmlspecialchars((string) ($log['role'] ?? '')) ?></td>
                                                    <td><code><?= htmlspecialchars((string) ($log['action'] ?? '')) ?></code> <?= htmlspecialchars((string) ($log['document_number'] ?? '')) ?></td>
                                                    <td>
                                                        <span class="badge <?= ($log['outcome'] ?? '') === 'ALLOWED' ? 'bg-success' : 'bg-danger' ?>">
                                                            <?= htmlspecialchars((string) ($log['outcome'] ?? '')) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <?php if ($activePage !== 'dashboard'): ?>
                <!-- 3. Tampilan Data Interaktif (List View Zebra Striping / Kanban / Form dengan Statusbar & Indikator Akses) -->
                <section class="o-surface-card bg-white rounded-4 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold text-dark">
                                <?php if ($role === 'Finance'): ?>
                                    Bagan Akun &amp; Saldo Pembukaan (Rp25.716.900)
                                <?php elseif ($role === 'Procurement'): ?>
                                    Katalog Pengadaan &amp; SKU Varian (<?= count($products) ?> SKU)
                                <?php elseif ($role === 'Sales'): ?>
                                    Katalog Penjualan B2B &amp; Stok Tersedia (<?= count($products) ?> SKU)
                                <?php elseif ($role === 'Cashier'): ?>
                                    Katalog Kasir POS STORE-PKU (<?= count($products) ?> SKU)
                                <?php else: ?>
                                    Daftar Stok Gudang &amp; Toko (<?= count($products) ?> SKU)
                                <?php endif; ?>
                            </span>
                            <span class="badge bg-success">Baseline Aktif</span>
                        </div>

                        <!-- Indikator Akses Aksi Dokumen (Fitur F-01) -->
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="small text-muted me-1">Otorisasi Aksi Role <?= htmlspecialchars($role) ?>:</span>

                            <span class="<?= $canConfirmSo ? '' : 'o-blocked-wrapper' ?>"
                                  title="<?= $canConfirmSo ? 'Diizinkan untuk Sales' : 'Diblokir (F-01): Hanya untuk Role Sales' ?>">
                                <button type="button" class="btn btn-sm btn-pastel <?= $canConfirmSo ? 'o-interactive' : 'o-action-disabled' ?>">
                                    Confirm SO
                                </button>
                            </span>

                            <span class="<?= $canValidateDeliver ? '' : 'o-blocked-wrapper' ?>"
                                  title="<?= $canValidateDeliver ? 'Diizinkan untuk Warehouse' : 'Diblokir (F-01): Hanya untuk Role Warehouse' ?>">
                                <button type="button" class="btn btn-sm btn-pastel <?= $canValidateDeliver ? 'o-interactive' : 'o-action-disabled' ?>">
                                    Validate Delivery
                                </button>
                            </span>

                            <span class="<?= $canPostVendorBill ? '' : 'o-blocked-wrapper' ?>"
                                  title="<?= $canPostVendorBill ? 'Diizinkan untuk Finance' : 'Diblokir (F-01): Hanya untuk Role Finance' ?>">
                                <button type="button" class="btn btn-sm btn-pastel <?= $canPostVendorBill ? 'o-interactive' : 'o-action-disabled' ?>">
                                    Post Vendor Bill
                                </button>
                            </span>

                            <span class="<?= $canClosePosSession ? '' : 'o-blocked-wrapper' ?>"
                                  title="<?= $canClosePosSession ? 'Diizinkan untuk Cashier/Finance' : 'Diblokir (F-01): Hanya untuk Role Cashier/Finance' ?>">
                                <button type="button" class="btn btn-sm btn-pastel <?= $canClosePosSession ? 'o-interactive' : 'o-action-disabled' ?>">
                                    Close POS Session
                                </button>
                            </span>
                        </div>
                    </div>

                    <?php if ($activeView === 'list'): ?>
                        <!-- ==================== LIST VIEW (Zebra Striping: table-striped table-hover) ==================== -->
                        <?php if ($role === 'Finance'): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle o-modern-table">
                                    <thead>
                                        <tr>
                                            <th>Kode</th>
                                            <th>Nama Akun</th>
                                            <th>Kategori</th>
                                            <th>Status</th>
                                            <th class="text-end">Opening Debit</th>
                                            <th class="text-end">Opening Credit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sumDebit  = 0.0;
                                        $sumCredit = 0.0;
                                        foreach ($coa as $acc):
                                            $d = (float) ($acc['opening_debit'] ?? 0);
                                            $c = (float) ($acc['opening_credit'] ?? 0);
                                            $sumDebit  += $d;
                                            $sumCredit += $c;
                                            $hasBalance = ($d > 0 || $c > 0);
                                        ?>
                                            <tr>
                                                <td class="text-secondary fw-semibold"><?= htmlspecialchars((string) ($acc['account_code'] ?? '')) ?></td>
                                                <td class="fw-medium"><?= htmlspecialchars((string) ($acc['account_name'] ?? '')) ?></td>
                                                <td class="text-secondary"><?= htmlspecialchars((string) ($acc['type'] ?? '')) ?></td>
                                                <td>
                                                    <span class="badge <?= $hasBalance ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                        <?= $hasBalance ? 'Posted' : 'Zero Baseline' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?= $d > 0 ? 'Rp' . number_format($d, 0, ',', '.') : '—' ?></td>
                                                <td class="text-end"><?= $c > 0 ? 'Rp' . number_format($c, 0, ',', '.') : '—' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="border-top bg-light fw-semibold">
                                        <tr>
                                            <td colspan="4" class="px-4 py-3 text-end">Total Jurnal Pembukaan Seimbang:</td>
                                            <td class="px-4 py-3 text-end text-success">Rp<?= number_format($sumDebit, 0, ',', '.') ?></td>
                                            <td class="px-4 py-3 text-end text-success">Rp<?= number_format($sumCredit, 0, ',', '.') ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle o-modern-table">
                                    <thead>
                                        <tr>
                                            <th>SKU</th>
                                            <th>Produk</th>
                                            <th>Ukuran</th>
                                            <th>Varian</th>
                                            <th>Status Stok</th>
                                            <th class="text-end">Harga Jual</th>
                                            <?php if ($role !== 'Cashier'): ?>
                                                <th class="text-end">Cost</th>
                                                <th>Vendor</th>
                                            <?php endif; ?>
                                            <?php if ($role === 'Cashier'): ?>
                                                <th class="text-end">Stok STORE-PKU</th>
                                            <?php else: ?>
                                                <th class="text-end">WH-PKU (Avail)</th>
                                                <th class="text-end">STORE-PKU</th>
                                                <th class="text-end">STORE-DUM</th>
                                                <th class="text-end">Total</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">
                                                    Tidak ada data yang cocok dengan filter pencarian.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php
                                            $lastGroupValue = null;
                                            foreach ($products as $p):
                                                $skuCode  = (string) ($p['variant_sku'] ?? '');
                                                $whRes    = (int) ($p['wh_reserved'] ?? 0);
                                                $whAvail  = (int) ($p['wh_available'] ?? $p['wh_pku'] ?? 0);
                                                $groupVal = ($activeGroup !== 'none') ? (string) ($p[$activeGroup] ?? '-') : null;
                                                if ($groupVal !== null && $groupVal !== $lastGroupValue):
                                                    $lastGroupValue = $groupVal;
                                            ?>
                                                <tr class="table-secondary">
                                                    <td colspan="12" class="fw-semibold text-dark py-2">
                                                        Kelompok <?= htmlspecialchars($activeGroup) ?>: <?= htmlspecialchars($groupVal) ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td class="text-secondary fw-semibold"><?= htmlspecialchars($skuCode) ?></td>
                                                <td class="fw-medium"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></td>
                                                <td class="text-secondary"><?= htmlspecialchars((string) ($p['size'] ?? '')) ?></td>
                                                <td class="text-secondary"><?= htmlspecialchars((string) ($p['color_flavour'] ?? '')) ?></td>
                                                <td>
                                                    <?php if ($whRes > 0): ?>
                                                        <span class="badge bg-warning text-dark">Reserved <?= $whRes ?> pcs</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Available</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">Rp<?= number_format((float) ($p['sales_price'] ?? 0), 0, ',', '.') ?></td>
                                                <?php if ($role !== 'Cashier'): ?>
                                                    <td class="text-end text-secondary">Rp<?= number_format((float) ($p['cost'] ?? 0), 0, ',', '.') ?></td>
                                                    <td class="text-secondary"><?= htmlspecialchars((string) ($p['default_vendor'] ?? '')) ?></td>
                                                <?php endif; ?>
                                                <?php if ($role === 'Cashier'): ?>
                                                    <td class="text-end fw-semibold"><?= (int) ($p['store_pku'] ?? 0) ?> pcs</td>
                                                <?php else: ?>
                                                    <td class="text-end"><?= (int) ($p['wh_pku'] ?? 0) ?> <small class="text-muted">(<?= $whAvail ?>)</small></td>
                                                    <td class="text-end"><?= (int) ($p['store_pku'] ?? 0) ?></td>
                                                    <td class="text-end"><?= (int) ($p['store_dum'] ?? 0) ?></td>
                                                    <td class="text-end fw-semibold"><?= (int) ($p['company_total_pcs'] ?? 0) ?> pcs</td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($activeView === 'kanban'): ?>
                        <!-- ==================== KANBAN VIEW ==================== -->
                        <div class="p-4">
                            <div class="row g-3">
                                <?php foreach ($products as $p): ?>
                                    <?php
                                    $skuCode = (string) ($p['variant_sku'] ?? '');
                                    $whRes   = (int) ($p['wh_reserved'] ?? 0);
                                    ?>
                                    <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                                        <div class="o-kanban-card o-interactive d-flex flex-column justify-content-between">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="text-secondary small fw-semibold"><?= htmlspecialchars($skuCode) ?></span>
                                                    <span class="badge <?= $whRes > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
                                                        <?= $whRes > 0 ? 'Reserved ' . $whRes : 'Available' ?>
                                                    </span>
                                                </div>
                                                <div class="fw-semibold text-dark mb-1">
                                                    <?= htmlspecialchars((string) ($p['name'] ?? '')) ?>
                                                </div>
                                                <div class="text-secondary small mb-3">
                                                    <?= htmlspecialchars((string) ($p['size'] ?? '')) ?> · <?= htmlspecialchars((string) ($p['color_flavour'] ?? '')) ?>
                                                </div>
                                            </div>
                                            <div class="pt-2 border-top d-flex justify-content-between align-items-center small">
                                                <span class="fw-medium">Rp<?= number_format((float) ($p['sales_price'] ?? 0), 0, ',', '.') ?></span>
                                                <span class="text-secondary">
                                                    <?= $role === 'Cashier' ? ((int) ($p['store_pku'] ?? 0)) . ' pcs (Toko)' : ((int) ($p['company_total_pcs'] ?? 0)) . ' pcs' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ==================== FORM VIEW ODOO WEB 17.0 (STATUSBAR, SMART BUTTONS, SHEET & LOG NOTES) ==================== -->
                        <div class="p-4">
                            <div class="o-form-sheet bg-white rounded-4 shadow-sm border p-4">
                                <?php if ($role === 'Procurement'): ?>
                                    <?php
                                    $poDoc    = (array) ($docStates['PO00007'] ?? []);
                                    $poStatus = (string) ($poDoc['status'] ?? 'Confirmed');
                                    ?>
                                    <!-- 1. Form Header Actions & Statusbar -->
                                    <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button type="button" class="btn btn-sm btn-pastel o-interactive">✓ Confirm Order</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive">Print RFQ</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive text-danger">Cancel</button>
                                        </div>
                                        <div class="o-statusbar">
                                            <span class="o-statusbar-step <?= $poStatus === 'RFQ' ? 'current' : '' ?>">RFQ</span>
                                            <span class="o-statusbar-step <?= $poStatus === 'Confirmed' ? 'current' : '' ?>">Confirmed</span>
                                            <span class="o-statusbar-step <?= in_array($poStatus, ['Received', 'Done'], true) ? 'current' : '' ?>">Done</span>
                                        </div>
                                    </div>

                                    <!-- 2. Smart Buttons Box (oe_button_box) -->
                                    <div class="oe_button_box mb-3">
                                        <a href="index.php?page=inventory&screen=S-07" class="oe_stat_button" title="Penerimaan Barang Gudang">
                                            <i class="bi bi-box-seam text-warning"></i>
                                            <div>
                                                <span class="stat-number">1 Receipt</span>
                                                <span class="stat-label">Accepted 25 pcs</span>
                                            </div>
                                        </a>
                                        <a href="index.php?page=accounting&screen=S-12" class="oe_stat_button" title="Tagihan Pemasok / Vendor Bill">
                                            <i class="bi bi-file-earmark-text text-danger"></i>
                                            <div>
                                                <span class="stat-number">1 Vendor Bill</span>
                                                <span class="stat-label">Three-Way Match</span>
                                            </div>
                                        </a>
                                    </div>

                                    <!-- 3. Form Document Sheet (Header-Detail) -->
                                    <div class="mb-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge <?= $getStatusBadgeClass($poStatus) ?>"><?= htmlspecialchars($poStatus) ?></span>
                                            <h2 class="h4 fw-bold mb-0 text-dark"><?= htmlspecialchars((string) ($poDoc['po_number'] ?? 'PO00007')) ?></h2>
                                            <span class="badge bg-secondary">🔒 Harga Terkunci (Pricelist · SR-20)</span>
                                        </div>
                                        <div class="row g-3 py-2">
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:120px;">Vendor:</td>
                                                        <td><strong class="text-dark">V01 — PT Pangan Pilihan</strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Lokasi Tujuan:</td>
                                                        <td><code>WH-PKU</code> (Gudang Pusat Pekanbaru)</td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:120px;">Termin Bayar:</td>
                                                        <td><strong class="text-dark">NET 30</strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Tanggal Dokumen:</td>
                                                        <td><?= date('Y-m-d') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Product Lines Table -->
                                        <div class="table-responsive my-3 border rounded-3 overflow-hidden">
                                            <table class="table table-sm table-striped align-middle mb-0 small">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Produk / SKU</th>
                                                        <th class="text-center">Ordered</th>
                                                        <th class="text-center">Received</th>
                                                        <th class="text-end">Harga Beli</th>
                                                        <th class="text-center">Pajak</th>
                                                        <th class="text-end">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><code>MIE-GOR-80</code> — Indomie Goreng 80g</td>
                                                        <td class="text-center">30 pcs</td>
                                                        <td class="text-center text-success">25 Accepted (5 Rejected)</td>
                                                        <td class="text-end"><?= $formatIdr(3000) ?></td>
                                                        <td class="text-center"><span class="badge bg-light text-dark border">PPN 11%</span></td>
                                                        <td class="text-end fw-semibold"><?= $formatIdr(90000) ?></td>
                                                    </tr>
                                                </tbody>
                                                <tfoot class="table-light">
                                                    <tr>
                                                        <td colspan="5" class="text-end text-muted">DPP:</td>
                                                        <td class="text-end fw-semibold"><?= $formatIdr(90000) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td colspan="5" class="text-end text-muted">PPN Masukan (11%):</td>
                                                        <td class="text-end"><?= $formatIdr(9900) ?></td>
                                                    </tr>
                                                    <tr class="fw-bold">
                                                        <td colspan="5" class="text-end">Total Gross:</td>
                                                        <td class="text-end text-success"><?= $formatIdr(99900) ?></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- 4. Log Notes / Chatter -->
                                    <div class="o-chatter-box">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">
                                                <i class="bi bi-clock-history me-1"></i> Log Notes &amp; Riwayat Dokumen
                                            </span>
                                            <span class="badge bg-white text-muted border">Odoo Chatter</span>
                                        </div>
                                        <div class="o-chatter-item">
                                            <div class="o-chatter-avatar"><i class="bi bi-person-fill"></i></div>
                                            <div class="o-chatter-content">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="fw-semibold small text-dark">Mira Salsabila (Procurement)</span>
                                                    <span class="text-muted" style="font-size: 0.72rem;"><?= date('Y-m-d H:i') ?></span>
                                                </div>
                                                <div class="small text-secondary">
                                                    Purchase Order <strong><?= htmlspecialchars((string) ($poDoc['po_number'] ?? 'PO00007')) ?></strong> dikonfirmasi. Harga beli dikunci sebesar Rp3.000/pcs dari <code>vendor_pricelist</code> (SR-20). Menunggu barang diterima staf Gudang.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                <?php elseif ($role === 'Cashier'): ?>
                                    <?php
                                    $posDoc    = (array) ($docStates['POS-SESSION-0001'] ?? []);
                                    $posStatus = (string) ($posDoc['status'] ?? 'Open');
                                    ?>
                                    <!-- 1. Form Header Actions & Statusbar -->
                                    <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button type="button" class="btn btn-sm btn-pastel o-interactive">Lanjutkan Penjualan</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive text-danger">Tutup Sesi (Close Session)</button>
                                        </div>
                                        <div class="o-statusbar">
                                            <span class="o-statusbar-step <?= $posStatus === 'Open' ? 'current' : '' ?>">Open</span>
                                            <span class="o-statusbar-step <?= $posStatus === 'Pending Approval' ? 'current' : '' ?>">Pending Approval</span>
                                            <span class="o-statusbar-step <?= $posStatus === 'Closed' ? 'current' : '' ?>">Closed</span>
                                        </div>
                                    </div>

                                    <!-- 2. Smart Buttons Box -->
                                    <div class="oe_button_box mb-3">
                                        <a href="index.php?page=pos&screen=S-15" class="oe_stat_button" title="Daftar Struk Kasir">
                                            <i class="bi bi-receipt text-primary"></i>
                                            <div>
                                                <span class="stat-number">1 Struk Terbayar</span>
                                                <span class="stat-label">Paid Receipts</span>
                                            </div>
                                        </a>
                                        <a href="index.php?page=pos&screen=S-16" class="oe_stat_button" title="Laci Uang Kasir">
                                            <i class="bi bi-cash-stack text-success"></i>
                                            <div>
                                                <span class="stat-number">Rp200.000</span>
                                                <span class="stat-label">Opening Drawer</span>
                                            </div>
                                        </a>
                                    </div>

                                    <!-- 3. Form Document Sheet -->
                                    <div class="mb-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge <?= $getStatusBadgeClass($posStatus) ?>"><?= htmlspecialchars($posStatus) ?></span>
                                            <h2 class="h4 fw-bold mb-0 text-dark"><?= htmlspecialchars((string) ($posDoc['session_number'] ?? 'POS-SESSION-0001')) ?></h2>
                                        </div>
                                        <div class="row g-3 py-2">
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:140px;">Lokasi Kasir:</td>
                                                        <td><strong class="text-dark">STORE-PKU</strong> (Toko Retail Pekanbaru)</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Nama Kasir:</td>
                                                        <td>Rani Oktaviani</td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:140px;">Opening Cash:</td>
                                                        <td><strong class="text-dark"><?= $formatIdr(200000) ?></strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Waktu Dibuka:</td>
                                                        <td><?= date('Y-m-d H:i') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 4. Log Notes / Chatter -->
                                    <div class="o-chatter-box">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">
                                                <i class="bi bi-clock-history me-1"></i> Log Notes &amp; Riwayat Dokumen
                                            </span>
                                            <span class="badge bg-white text-muted border">Odoo Chatter</span>
                                        </div>
                                        <div class="o-chatter-item">
                                            <div class="o-chatter-avatar"><i class="bi bi-person-fill"></i></div>
                                            <div class="o-chatter-content">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="fw-semibold small text-dark">Rani Oktaviani (Cashier)</span>
                                                    <span class="text-muted" style="font-size: 0.72rem;"><?= date('Y-m-d H:i') ?></span>
                                                </div>
                                                <div class="small text-secondary">
                                                    Sesi kasir <strong>POS-SESSION-0001</strong> dibuka dengan saldo modal awal Rp200.000. Pengurangan stok fisik toko dilakukan secara real-time pada saat struk berstatus Paid.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <?php
                                    $soDoc    = (array) ($docStates['S00007'] ?? []);
                                    $custDoc  = (array) ($docStates['C001'] ?? []);
                                    $soStatus = (string) ($soDoc['status'] ?? 'Confirmed');
                                    $isLocked = (bool) ($soDoc['price_locked'] ?? true) || $soStatus === 'Confirmed';
                                    ?>
                                    <!-- 1. Form Header Actions & Statusbar -->
                                    <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button type="button" class="btn btn-sm btn-pastel o-interactive">✓ Confirm Order</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive">Create Invoice</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive">Send by Email</button>
                                            <button type="button" class="btn btn-sm btn-soft-gray o-interactive text-danger">Cancel</button>
                                        </div>
                                        <div class="o-statusbar">
                                            <span class="o-statusbar-step <?= $soStatus === 'Draft' ? 'current' : '' ?>">Draft</span>
                                            <span class="o-statusbar-step <?= $soStatus === 'Confirmed' ? 'current' : '' ?>">Confirmed</span>
                                            <span class="o-statusbar-step <?= in_array($soStatus, ['Done', 'Delivered'], true) ? 'current' : '' ?>">Done</span>
                                        </div>
                                    </div>

                                    <!-- 2. Smart Buttons Box (oe_button_box) -->
                                    <div class="oe_button_box mb-3">
                                        <a href="index.php?page=inventory&screen=S-06" class="oe_stat_button" title="Lihat Delivery Order di Modul Gudang">
                                            <i class="bi bi-truck text-primary"></i>
                                            <div>
                                                <span class="stat-number">1 Delivery</span>
                                                <span class="stat-label">Reserved 8 pcs</span>
                                            </div>
                                        </a>
                                        <a href="index.php?page=accounting&screen=S-10" class="oe_stat_button" title="Lihat Customer Invoice di Modul Invoicing">
                                            <i class="bi bi-receipt text-success"></i>
                                            <div>
                                                <span class="stat-number">1 Invoice</span>
                                                <span class="stat-label">To Invoice</span>
                                            </div>
                                        </a>
                                    </div>

                                    <!-- 3. Form Document Sheet (Header-Detail) -->
                                    <div class="mb-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge <?= $getStatusBadgeClass($soStatus) ?>"><?= htmlspecialchars($soStatus) ?></span>
                                            <h2 class="h4 fw-bold mb-0 text-dark"><?= htmlspecialchars((string) ($soDoc['so_number'] ?? 'S00007')) ?></h2>
                                            <?php if ($isLocked): ?>
                                                <span class="badge bg-secondary">🔒 Harga Terkunci (Read-Only · SR-07)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="row g-3 py-2">
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:130px;">Pelanggan:</td>
                                                        <td><strong class="text-dark"><?= htmlspecialchars((string) ($custDoc['id'] ?? 'C001')) ?> — <?= htmlspecialchars((string) ($custDoc['name'] ?? 'Warung Kopi Pasar')) ?></strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Gudang Sumber:</td>
                                                        <td><code>WH-PKU</code> (Gudang Distribusi Pekanbaru)</td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <table class="table table-sm table-borderless small mb-0">
                                                    <tr>
                                                        <td class="text-secondary" style="width:130px;">Termin Bayar:</td>
                                                        <td><strong class="text-dark">Immediate Payment</strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-secondary">Credit Limit:</td>
                                                        <td><?= $formatIdr((float) ($custDoc['credit_limit'] ?? 500000)) ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Product Lines Table -->
                                        <div class="table-responsive my-3 border rounded-3 overflow-hidden">
                                            <table class="table table-sm table-striped align-middle mb-0 small">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Produk / SKU</th>
                                                        <th class="text-center">Ordered</th>
                                                        <th class="text-center">Delivered</th>
                                                        <th class="text-center">Invoiced</th>
                                                        <th class="text-end">Harga Satuan</th>
                                                        <th class="text-center">Pajak</th>
                                                        <th class="text-end">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><code>AIR-600</code> — Air Mineral 600ml</td>
                                                        <td class="text-center">8 pcs</td>
                                                        <td class="text-center text-success">8 pcs (Reserved)</td>
                                                        <td class="text-center text-muted">0 pcs</td>
                                                        <td class="text-end"><?= $formatIdr(4000) ?></td>
                                                        <td class="text-center"><span class="badge bg-light text-dark border">PPN 11%</span></td>
                                                        <td class="text-end fw-semibold"><?= $formatIdr(32000) ?></td>
                                                    </tr>
                                                </tbody>
                                                <tfoot class="table-light">
                                                    <tr>
                                                        <td colspan="5" class="text-end text-muted">DPP Penjualan:</td>
                                                        <td class="text-end fw-semibold"><?= $formatIdr(32000) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td colspan="5" class="text-end text-muted">PPN Keluaran (11%):</td>
                                                        <td class="text-end"><?= $formatIdr(3520) ?></td>
                                                    </tr>
                                                    <tr class="fw-bold">
                                                        <td colspan="5" class="text-end">Total Akhir (Gross):</td>
                                                        <td class="text-end text-success"><?= $formatIdr(35520) ?></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- 4. Log Notes / Chatter -->
                                    <div class="o-chatter-box">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">
                                                <i class="bi bi-clock-history me-1"></i> Log Notes &amp; Riwayat Dokumen
                                            </span>
                                            <span class="badge bg-white text-muted border">Odoo Chatter</span>
                                        </div>
                                        <div class="o-chatter-item">
                                            <div class="o-chatter-avatar"><i class="bi bi-person-fill"></i></div>
                                            <div class="o-chatter-content">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="fw-semibold small text-dark">Putri Aulia (Sales)</span>
                                                    <span class="text-muted" style="font-size: 0.72rem;"><?= date('Y-m-d H:i') ?></span>
                                                </div>
                                                <div class="small text-secondary">
                                                    Sales Order <strong>S00007</strong> berhasil dikonfirmasi. Stok <code>AIR-600</code> (8 pcs) dicadangkan otomatis di gudang <code>WH-PKU</code>. Harga dan diskon terkunci read-only (SR-07).
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<!-- Bootstrap 5.3 Bundle JS CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toastEl = document.getElementById('liveFeedbackToast');
    if (toastEl && typeof bootstrap !== 'undefined') {
        var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
    }
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('.js-step-toast').forEach(function (el) {
            new bootstrap.Toast(el).show();
        });
    }

    // Aturan SR-20: Otomatis mengambil harga beli acuan dari vendor_pricelist saat Vendor & SKU dipilih
    var pricelistMap = <?= json_encode($pricelistMap, JSON_UNESCAPED_UNICODE) ?>;
    var vendorSelect = document.getElementById('poVendorSelect');
    var skuSelect    = document.getElementById('poSkuSelect');
    var priceInput   = document.getElementById('poUnitPriceInput');
    var sourceBadge  = document.getElementById('poPriceSourceBadge');

    function syncVendorPricelist() {
        if (!vendorSelect || !skuSelect || !priceInput) {
            return;
        }
        var vId = vendorSelect.value;
        var sku = skuSelect.value;
        if (pricelistMap[vId] && pricelistMap[vId][sku]) {
            priceInput.value = pricelistMap[vId][sku].price;
            if (sourceBadge) {
                sourceBadge.textContent = pricelistMap[vId][sku].source;
            }
        }
    }

    if (vendorSelect && skuSelect) {
        vendorSelect.addEventListener('change', syncVendorPricelist);
        skuSelect.addEventListener('change', syncVendorPricelist);
        syncVendorPricelist();
    }
});
</script>
</body>
</html>
