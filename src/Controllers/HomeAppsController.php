<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;
use RantauMart\Middleware\RbacMiddleware;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\DocumentStateGuard;

/**
 * Controller untuk Beranda Modular Odoo Web 17.0 (index.php) dan Halaman Debug Pengujian (debug.php).
 */
class HomeAppsController
{
    /**
     * Memeriksa akses ke modul yang dipilih melalui RbacMiddleware.
     */
    public static function accessModule(string $moduleKey): array
    {
        $docMap = [
            'CRM'           => ['doc' => 'LEAD-0001'],
            'Sales'         => ['doc' => 'S00007'],
            'Purchase'      => ['doc' => 'PO00007'],
            'Inventory'     => ['doc' => 'S00007'],
            'Accounting'    => ['doc' => 'BILL-0001'],
            'Point of Sale' => ['doc' => 'POS-SESSION-0001'],
        ];

        $snapshot = DocumentStateGuard::getDocumentStatesSnapshot();
        $docNo    = $docMap[$moduleKey]['doc'] ?? null;
        $status   = null;
        if ($docNo !== null && isset($snapshot[$docNo]['status'])) {
            $status = (string) ($snapshot[$docNo]['status'] ?? '');
        }

        return RbacMiddleware::authorizeModule($moduleKey, $docNo, $status);
    }

    /**
     * Menjalankan aksi dokumen yang dilindungi oleh RbacMiddleware, DocumentStateGuard, & SalesController.
     */
    public static function executeGuardedAction(string $actionCommand, array $requestData = []): array
    {
        return match ($actionCommand) {
            'post_vendor_bill'    => DocumentStateGuard::attemptPostVendorBill('BILL-0001'),
            'close_pos_session'   => DocumentStateGuard::attemptClosePosSession('POS-SESSION-0001'),
            'update_credit_limit' => DocumentStateGuard::attemptUpdateCreditLimit(
                (string) ($requestData['customer_id'] ?? 'C001'),
                (float) ($requestData['new_limit'] ?? 1500000)
            ),
            'convert_lead',
            'crm_convert_lead'    => SalesController::convertLeadToOpportunity(
                (string) ($requestData['lead_number'] ?? 'LEAD-0001')
            ),
            'sales_create_so'     => SalesController::createSalesOrder($requestData),
            'sales_confirm_so'    => SalesController::confirmSalesOrder(
                (string) ($requestData['so_number'] ?? 'S00007'),
                isset($requestData['discount_pct']) && $requestData['discount_pct'] !== ''
                    ? (float) $requestData['discount_pct']
                    : null
            ),
            'sales_update_price'  => SalesController::updateSalesOrderPrice(
                (string) ($requestData['so_number'] ?? 'S00007'),
                (float) ($requestData['unit_price'] ?? 4000),
                (float) ($requestData['discount_pct'] ?? 0)
            ),
            'purchase_create_po'  => PurchaseController::createPurchaseOrder($requestData),
            'purchase_confirm_po' => PurchaseController::confirmPurchaseOrder(
                (string) ($requestData['po_number'] ?? 'PO00007')
            ),
            'purchase_update_price' => PurchaseController::updatePurchaseOrderPrice(
                (string) ($requestData['po_number'] ?? 'PO00007'),
                (float) ($requestData['unit_price'] ?? 3000)
            ),
            'inventory_validate_delivery' => InventoryController::validateDeliveryOrder($requestData),
            'inventory_validate_receipt'  => InventoryController::receivePurchaseOrder($requestData),
            'inventory_internal_transfer' => InventoryController::createInternalTransfer($requestData),
            'inventory_create_variant'    => InventoryController::createProductVariant($requestData),
            'accounting_post_invoice'     => AccountingController::postCustomerInvoice($requestData),
            'accounting_post_bill'        => AccountingController::postVendorBill($requestData),
            'accounting_register_payment' => AccountingController::registerPayment($requestData),
            'accounting_b2b_return'       => AccountingController::createB2bReturnCreditNote($requestData),
            'pos_open_session'            => PosController::openPosSession($requestData),
            'pos_checkout_basket'         => PosController::checkoutPosBasket($requestData),
            'pos_close_session'           => PosController::closePosSession($requestData),
            'pos_refund_receipt'          => PosController::processPosRefund($requestData),
            'open_procurement'    => RbacMiddleware::guardDocumentAction(
                action: 'purchase.order.view',
                documentNumber: 'PO00007',
                currentDocumentStatus: 'Confirmed',
                targetNewStatus: 'Confirmed'
            ),
            'reset_seed' => self::resetBaselineFixture(),
            default => [
                'allowed'        => false,
                'http_code'      => 400,
                'outcome'        => 'DENIED',
                'status_changed' => false,
                'reason'         => "Aksi '{$actionCommand}' tidak dikenali.",
            ],
        };
    }

    /**
     * Reset ke kondisi Baseline Seed sesuai aturan Fixture T-xx.
     */
    public static function resetBaselineFixture(): array
    {
        Database::resetBaselineSeed();
        AuthService::login('Sales');
        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'status_changed' => true,
            'reason'         => 'Baseline seed berhasil dipulihkan dan login diatur ke role Sales (kondisi awal T-01).',
        ];
    }

    /**
     * Menghasilkan metrik KPI Dashboard yang spesifik dan relevan untuk role yang sedang aktif.
     */
    public static function getRoleSpecificKpis(string $role, array $docStates): array
    {
        $pdo = Database::getConnection();
        $leadStatus = (string) ($docStates['LEAD-0001']['stage'] ?? 'New');
        $soStatus   = (string) ($docStates['S00007']['status'] ?? 'Confirmed');
        $poStatus   = (string) ($docStates['PO00007']['status'] ?? 'Confirmed');
        $posStatus  = (string) ($docStates['POS-SESSION-0001']['status'] ?? 'Open');
        $c01Exposure= SalesController::getCustomerCreditExposure('C001');
        $airStock   = $pdo->query("SELECT on_hand_qty, reserved_qty, available_qty FROM stock_by_location WHERE location_code = 'WH-PKU' AND variant_sku = 'AIR-600'")->fetch() ?: [];

        $airOnHand  = (int) ($airStock['on_hand_qty'] ?? 80);
        $airRes     = (int) ($airStock['reserved_qty'] ?? 8);
        $airAvail   = (int) ($airStock['available_qty'] ?? 72);
        $liveKpi    = AccountingController::getLiveKpiAndDrillDown();

        return match ($role) {
            RolePermissions::ROLE_SALES => [
                ['label' => 'Active CRM Lead',      'value' => 'LEAD-0001 (' . $leadStatus . ')', 'sub' => 'Pelanggan: C001 · Target 20 pcs AIR-600'],
                ['label' => 'Exposure C001 (SR-08)','value' => 'Rp' . number_format((float) ($c01Exposure['current_exposure'] ?? 35520), 0, ',', '.'), 'sub' => 'Credit Limit C001: Rp' . number_format((float) ($c01Exposure['credit_limit'] ?? 500000), 0, ',', '.')],
                ['label' => 'Kebijakan Diskon (SR-07)', 'value' => 'Maks. 10%',                   'sub' => 'Harga terkunci otomatis saat SO Confirmed'],
                ['label' => 'Available WH-PKU (AIR-600)', 'value' => $airAvail . ' pcs Available', 'sub' => 'On Hand: ' . $airOnHand . ' pcs · Reserved: ' . $airRes . ' pcs'],
            ],
            RolePermissions::ROLE_WAREHOUSE => [
                ['label' => 'Company On Hand',      'value' => number_format((int) (($liveKpi['location_summary']['WH-PKU']['total_on_hand'] ?? 0) + ($liveKpi['location_summary']['STORE-PKU']['total_on_hand'] ?? 0) + ($liveKpi['location_summary']['STORE-DUM']['total_on_hand'] ?? 0)), 0, ',', '.') . ' pcs', 'sub' => 'Total SKU Varian di 3 Lokasi (WH-PKU, STORE-PKU, STORE-DUM)'],
                ['label' => 'Reserved WH-PKU (SR-09)', 'value' => $airRes . ' pcs (AIR-600)', 'sub' => 'On Hand: ' . $airOnHand . ' · Available: ' . $airAvail . ' pcs'],
                ['label' => 'Company Available',    'value' => number_format((int) (($liveKpi['location_summary']['WH-PKU']['total_available'] ?? 0) + ($liveKpi['location_summary']['STORE-PKU']['total_available'] ?? 0) + ($liveKpi['location_summary']['STORE-DUM']['total_available'] ?? 0)), 0, ',', '.') . ' pcs', 'sub' => 'Stok bebas siap kirim / jual (SR-04 Anti-Negative)'],
                ['label' => 'Valuasi Stok Gudang',  'value' => 'Rp' . number_format((float) ($liveKpi['location_summary']['WH-PKU']['total_valuation'] ?? 4997000), 0, ',', '.'), 'sub' => 'WH-PKU (Total Perusahaan: Rp' . number_format((float) ($liveKpi['stock_valuation_1130'] ?? 9628900), 0, ',', '.') . ')'],
            ],
            RolePermissions::ROLE_PROCUREMENT => [
                ['label' => 'Active Purchase Order','value' => 'PO00007 (Rp99.900)', 'sub' => 'Status: ' . $poStatus . ' · 30 pcs MIE-GOR-80'],
                ['label' => 'Master Vendor',        'value' => '4 Pemasok (V01–V04)','sub' => 'Pekanbaru, Medan, Batam'],
                ['label' => 'Vendor Pricelist (SR-20)', 'value' => '7 Kontrak Harga', 'sub' => 'Auto-lookup harga beli dari vendor_pricelist'],
                ['label' => 'Master SKU Varian',    'value' => '15 SKU Aktif',       'sub' => 'Unit pcs dengan profil PPN-11'],
            ],
            RolePermissions::ROLE_FINANCE => [
                ['label' => 'Stock Valuation (1130)', 'value' => 'Rp' . number_format((float) ($liveKpi['stock_valuation_1130'] ?? 9628900), 0, ',', '.'), 'sub' => 'Interim (1150): Rp' . number_format((float) ($liveKpi['stock_interim_1150'] ?? 0), 0, ',', '.') . ' · PPV (5110): Rp' . number_format((float) ($liveKpi['ppv_5110'] ?? 0), 0, ',', '.')],
                ['label' => 'AR (1100) / AP (2010)',  'value' => 'Rp' . number_format((float) ($liveKpi['ar_1100'] ?? 888000), 0, ',', '.') . ' / Rp' . number_format((float) ($liveKpi['ap_2010'] ?? 555000), 0, ',', '.'), 'sub' => '3-Way Match (SR-06) & Delivered Qty Invoicing (SR-02)'],
                ['label' => 'Bank (1010) & POS Cash', 'value' => 'Rp' . number_format((float) (($liveKpi['bank_1010'] ?? 15000000) + ($liveKpi['pos_cash_1020'] ?? 200000)), 0, ',', '.'), 'sub' => 'Bank: Rp' . number_format((float) ($liveKpi['bank_1010'] ?? 15000000), 0, ',', '.') . ' · POS Cash: Rp' . number_format((float) ($liveKpi['pos_cash_1020'] ?? 200000), 0, ',', '.')],
                ['label' => 'General Ledger Balance', 'value' => ($liveKpi['trial_balance_balanced'] ?? true) ? 'SEIMBANG (SR-21)' : 'TIDAK SEIMBANG', 'sub' => 'Total Debit = Credit: Rp' . number_format((float) ($liveKpi['trial_balance_debit'] ?? 25716900), 0, ',', '.')],
            ],
            RolePermissions::ROLE_CASHIER => [
                ['label' => 'Lokasi Kasir Aktif',   'value' => 'STORE-PKU',                 'sub' => 'RantauMart Pekanbaru · Register POS01'],
                ['label' => 'Status Sesi POS',      'value' => 'POS-SESSION-0001 (' . $posStatus . ')', 'sub' => 'Kasir: Rani Oktaviani (SR-13 / SR-14)'],
                ['label' => 'Opening Cash Drawer',  'value' => 'Rp' . number_format((float) ($liveKpi['pos_cash_1020'] ?? 200000), 0, ',', '.'), 'sub' => 'Akun 1020 POS Cash Drawer'],
                ['label' => 'Stok Toko STORE-PKU',  'value' => 'Rp' . number_format((float) ($liveKpi['location_summary']['STORE-PKU']['total_valuation'] ?? 2979100), 0, ',', '.'), 'sub' => 'Tersedia di toko: ' . number_format((int) ($liveKpi['location_summary']['STORE-PKU']['total_available'] ?? 438), 0, ',', '.') . ' pcs (SR-04)'],
            ],
            RolePermissions::ROLE_OWNER => [
                ['label' => 'Net Revenue (SR-19)',    'value' => 'Rp' . number_format((float) ($liveKpi['net_revenue'] ?? 0), 0, ',', '.'), 'sub' => 'Gross Profit: Rp' . number_format((float) ($liveKpi['gross_profit'] ?? 0), 0, ',', '.') . ' · COGS: Rp' . number_format((float) ($liveKpi['cogs_5010'] ?? 0), 0, ',', '.')],
                ['label' => 'Valuation (1130) Live',  'value' => 'Rp' . number_format((float) ($liveKpi['stock_valuation_1130'] ?? 9628900), 0, ',', '.'), 'sub' => 'WH-PKU, STORE-PKU, STORE-DUM terintegrasi real-time'],
                ['label' => 'Trial Balance (SR-21)',  'value' => 'Rp' . number_format((float) ($liveKpi['trial_balance_debit'] ?? 25716900), 0, ',', '.'), 'sub' => 'Anti-Double-Posting & Balanced Ledger (SR-21 / SR-22)'],
                ['label' => 'Audit Trail (SR-18)',    'value' => count(AuditLogger::getRecentLogs(100)) . ' Bukti Audit', 'sub' => 'Pencatatan persisten aktor, waktu, aksi & alasan'],
            ],
            default => [],
        };
    }

    /**
     * Mengumpulkan seluruh data untuk tampilan Odoo Web 17.0 (F-01, F-02, F-05..F-08, Bagian 7).
     */
    public static function getDashboardViewModel(
        ?array $flashResult = null,
        string $activeModule = 'Apps',
        array $queryParams = []
    ): array {
        $pdo            = Database::getConnection();
        $currentUser    = AuthService::getCurrentUser();
        $role           = (string) ($currentUser['role'] ?? 'Sales');
        $allUsers       = AuthService::getAllUsers();
        $visibleModules = RolePermissions::getVisibleModulesForRole($role);
        $allowedModules = RolePermissions::getAllowedModulesOnlyForRole($role);
        $sidebarMenu    = RolePermissions::getSidebarMenuForRole($role);
        $docStates      = DocumentStateGuard::getDocumentStatesSnapshot();
        $auditLogs      = AuditLogger::getRecentLogs(30);
        $roleKpis       = self::getRoleSpecificKpis($role, $docStates);

        if (!isset($allowedModules[$activeModule])) {
            $activeModule = 'Apps';
        }

        $activeView = strtolower(trim((string) ($queryParams['view'] ?? 'list')));
        if (!in_array($activeView, ['list', 'kanban', 'form'], true)) {
            $activeView = 'list';
        }

        $searchQuery  = trim((string) ($queryParams['q'] ?? ''));
        $activeFilter = trim((string) ($queryParams['filter'] ?? 'all'));
        $activeGroup  = trim((string) ($queryParams['group_by'] ?? 'none'));
        $activeScreen = trim((string) ($queryParams['screen'] ?? 'S-01'));

        $productsSql = "
            SELECT p.variant_sku, p.name, p.size, p.color_flavour, p.unit,
                   p.sales_price, p.cost, p.default_vendor,
                   COALESCE(s_wh.on_hand_qty, os.wh_pku) AS wh_pku,
                   COALESCE(s_sp.on_hand_qty, os.store_pku) AS store_pku,
                   COALESCE(s_sd.on_hand_qty, os.store_dum) AS store_dum,
                   (COALESCE(s_wh.on_hand_qty, os.wh_pku) + COALESCE(s_sp.on_hand_qty, os.store_pku) + COALESCE(s_sd.on_hand_qty, os.store_dum)) AS company_total_pcs,
                   os.standard_value,
                   s_wh.on_hand_qty AS wh_on_hand, s_wh.reserved_qty AS wh_reserved, s_wh.available_qty AS wh_available
            FROM products p
            JOIN opening_stock os ON os.variant_sku = p.variant_sku
            LEFT JOIN stock_by_location s_wh ON s_wh.variant_sku = p.variant_sku AND s_wh.location_code = 'WH-PKU'
            LEFT JOIN stock_by_location s_sp ON s_sp.variant_sku = p.variant_sku AND s_sp.location_code = 'STORE-PKU'
            LEFT JOIN stock_by_location s_sd ON s_sd.variant_sku = p.variant_sku AND s_sd.location_code = 'STORE-DUM'
            ORDER BY p.variant_sku ASC
        ";
        $products = $pdo->query($productsSql)->fetchAll() ?: [];

        if ($searchQuery !== '') {
            $qLower = strtolower($searchQuery);
            $products = array_values(array_filter($products, static function (array $row) use ($qLower): bool {
                $haystack = strtolower(
                    ($row['variant_sku'] ?? '') . ' ' .
                    ($row['name'] ?? '') . ' ' .
                    ($row['color_flavour'] ?? '') . ' ' .
                    ($row['default_vendor'] ?? '')
                );
                return str_contains($haystack, $qLower);
            }));
        }

        if (in_array($activeFilter, ['V01', 'V02', 'V03', 'V04'], true)) {
            $products = array_values(array_filter($products, static function (array $row) use ($activeFilter): bool {
                return ($row['default_vendor'] ?? '') === $activeFilter;
            }));
        }

        $customers = $pdo->query("SELECT * FROM contacts_customers ORDER BY id ASC")->fetchAll() ?: [];
        foreach ($customers as &$cust) {
            $cust['exposure'] = SalesController::getCustomerCreditExposure((string) $cust['id']);
        }
        unset($cust);

        $vendors            = $pdo->query("SELECT * FROM contacts_vendors ORDER BY id ASC")->fetchAll() ?: [];
        $pricelists         = $pdo->query("SELECT * FROM vendor_pricelist ORDER BY id ASC")->fetchAll() ?: [];
        $pricelistMap       = PurchaseController::getPricelistLookupMap();
        $coa                = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code ASC")->fetchAll() ?: [];
        $locations          = $pdo->query("SELECT * FROM warehouses_locations ORDER BY location_code ASC")->fetchAll() ?: [];
        $salesOrders        = SalesController::getAllSalesOrdersWithLines();
        $crmLeads           = SalesController::getAllLeads();
        $purchaseOrders     = PurchaseController::getAllPurchaseOrdersWithLines();
        $deliveryOrders     = InventoryController::getDeliveryOrders();
        $purchaseReceipts   = InventoryController::getPurchaseReceipts();
        $internalTransfers  = InventoryController::getInternalTransfers();
        $companyValSummary  = InventoryController::getCompanyValuationSummary();
        $customerInvoices   = AccountingController::getCustomerInvoices();
        $vendorBills        = AccountingController::getVendorBills();
        $payments           = AccountingController::getPayments();
        $returnsRefunds     = AccountingController::getReturnsAndRefunds();
        $journalEntries     = AccountingController::getJournalEntriesWithLines();
        $liveKpi            = AccountingController::getLiveKpiAndDrillDown();
        $posSessions        = PosController::getPosSessions();
        $posReceipts        = PosController::getPosReceipts();
        $storePkuStock      = InventoryController::getStockByLocation('STORE-PKU');
        $reportAnalytics    = ReportController::isAuthorizedRole($role) ? ReportController::getDashboardAnalytics() : null;

        return [
            'company'           => 'PT RantauMart Riau',
            'screen_id'         => $activeScreen !== '' ? $activeScreen : 'S-01',
            'screen_title'      => 'Home Apps dan akses',
            'active_module'     => $activeModule,
            'active_view'       => $activeView,
            'search_query'      => $searchQuery,
            'active_filter'     => $activeFilter,
            'active_group'      => $activeGroup,
            'db_driver'         => Database::getActiveDriver(),
            'current_user'      => $currentUser,
            'all_users'         => $allUsers,
            'stakeholders'      => RolePermissions::STAKEHOLDERS,
            'modules'           => $visibleModules,
            'allowed_modules'   => $allowedModules,
            'sidebar_menu'      => $sidebarMenu,
            'role_kpis'         => $roleKpis,
            'action_policies'   => RolePermissions::ACTION_POLICIES,
            'document_states'   => $docStates,
            'products'          => $products,
            'customers'         => $customers,
            'vendors'           => $vendors,
            'vendor_pricelist'  => $pricelists,
            'pricelist_map'     => $pricelistMap,
            'coa'               => $coa,
            'locations'         => $locations,
            'sales_orders'      => $salesOrders,
            'crm_leads'         => $crmLeads,
            'purchase_orders'   => $purchaseOrders,
            'delivery_orders'   => $deliveryOrders,
            'purchase_receipts' => $purchaseReceipts,
            'internal_transfers'=> $internalTransfers,
            'company_valuation' => $companyValSummary,
            'customer_invoices' => $customerInvoices,
            'vendor_bills'      => $vendorBills,
            'payments'          => $payments,
            'returns_refunds'   => $returnsRefunds,
            'journal_entries'   => $journalEntries,
            'live_kpi'          => $liveKpi,
            'report_analytics'  => $reportAnalytics,
            'pos_sessions'      => $posSessions,
            'pos_receipts'      => $posReceipts,
            'store_pku_stock'   => $storePkuStock,
            'audit_logs'        => $auditLogs,
            'flash_result'      => $flashResult,
        ];
    }
}
