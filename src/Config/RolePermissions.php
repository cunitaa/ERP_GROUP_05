<?php
declare(strict_types=1);

namespace RantauMart\Config;

/**
 * Konfigurasi RBAC (Role-Based Access Control) untuk 5 Role Wajib PT RantauMart Riau
 * Sesuai Fitur F-01 (Autentikasi dan hak akses), Aturan SR-18 (Role visibility & Audit),
 * dan Layar S-01 s.d. S-18.
 */
class RolePermissions
{
    public const ROLE_SALES       = 'Sales';
    public const ROLE_WAREHOUSE   = 'Warehouse';
    public const ROLE_PROCUREMENT = 'Procurement';
    public const ROLE_FINANCE     = 'Finance';
    public const ROLE_CASHIER     = 'Cashier';
    public const ROLE_OWNER       = 'Owner';

    public const MANDATORY_ROLES = [
        self::ROLE_SALES,
        self::ROLE_WAREHOUSE,
        self::ROLE_PROCUREMENT,
        self::ROLE_FINANCE,
        self::ROLE_CASHIER,
    ];

    public const ALL_STAKEHOLDER_ROLES = [
        self::ROLE_SALES,
        self::ROLE_WAREHOUSE,
        self::ROLE_PROCUREMENT,
        self::ROLE_FINANCE,
        self::ROLE_CASHIER,
        self::ROLE_OWNER,
    ];

    /**
     * Profil 6 Pemangku Kepentingan (Stakeholders) PT RantauMart Riau
     */
    public const STAKEHOLDERS = [
        self::ROLE_SALES => [
            'user_id'   => 'U01',
            'full_name' => 'Putri Aulia',
            'title'     => 'Manajer Sales',
            'module'    => 'Sales & CRM',
            'rules'     => ['SR-01', 'SR-07', 'SR-08', 'SR-20'],
            'focus'     => 'Lead, Opportunity, Quotation, Sales Order, batas diskon maks 10%, dan validasi Credit Limit pelanggan',
        ],
        self::ROLE_WAREHOUSE => [
            'user_id'   => 'U02',
            'full_name' => 'Deni Pratama',
            'title'     => 'Kepala Gudang',
            'module'    => 'Inventory & Warehouse',
            'rules'     => ['SR-04', 'SR-09', 'SR-10', 'SR-17'],
            'focus'     => 'Mutasi stok 3 lokasi (WH-PKU, STORE-PKU, STORE-DUM), anti-negative stock, reservasi stok, dan pemisahan Accepted/Rejected',
        ],
        self::ROLE_PROCUREMENT => [
            'user_id'   => 'U03',
            'full_name' => 'Mira Salsabila',
            'title'     => 'Manajer Procurement',
            'module'    => 'Purchase',
            'rules'     => ['SR-05', 'SR-20', 'SR-22'],
            'focus'     => 'Permintaan pembelian RFQ/PO dan harga beli acuan otomatis dari vendor_pricelist',
        ],
        self::ROLE_FINANCE => [
            'user_id'   => 'U04',
            'full_name' => 'Arif Firmansyah',
            'title'     => 'Kepala Finance',
            'module'    => 'Accounting & Invoicing',
            'rules'     => ['SR-02', 'SR-06', 'SR-08', 'SR-12', 'SR-15', 'SR-16', 'SR-21'],
            'focus'     => 'Customer Invoice atas Delivered Qty, Vendor Bill 3-way match & PPV, pembayaran bertahap, jurnal seimbang, dan otorisasi selisih kas',
        ],
        self::ROLE_CASHIER => [
            'user_id'   => 'U05',
            'full_name' => 'Rani Oktaviani',
            'title'     => 'Supervisor Kasir',
            'module'    => 'Point of Sale (POS)',
            'rules'     => ['SR-03', 'SR-04', 'SR-11', 'SR-13', 'SR-14', 'SR-16'],
            'focus'     => 'Sesi kasir & Opening Cash, keranjang belanja, retur/refund tunai, cash count, dan penutupan sesi POS',
        ],
        self::ROLE_OWNER => [
            'user_id'   => 'U06',
            'full_name' => 'Budi Haryanto',
            'title'     => 'Direktur Operasional',
            'module'    => 'Governance, KPI Dashboard & Audit Trace',
            'rules'     => ['SR-18', 'SR-19', 'SR-22'],
            'focus'     => 'Pengawas tingkat tinggi: penomoran dokumen unik, audit trail mutlak (SR-18), live KPI drill-down (SR-19), dan pencegahan jurnal ganda (SR-22)',
        ],
    ];

    /**
     * Daftar modul utama sesuai S-01 Home Apps switcher (Odoo Web 17.0 style)
     */
    public const MODULES = [
        'Apps' => [
            'name'        => 'Home Apps',
            'screen'      => 'S-01',
            'description' => 'Navigasi modul, role visibility, dan ringkasan dokumen aktif',
        ],
        'CRM' => [
            'name'        => 'CRM Pipeline',
            'screen'      => 'S-04',
            'description' => 'Konversi Lead ke Opportunity (LEAD-0001)',
        ],
        'Sales' => [
            'name'        => 'Sales',
            'screen'      => 'S-02, S-05',
            'description' => 'Contacts, Credit Limit, Quotation, dan Sales Order (S00007)',
        ],
        'Purchase' => [
            'name'        => 'Purchase',
            'screen'      => 'S-08',
            'description' => 'RFQ, Purchase Order (PO00007), dan Vendor Pricelist',
        ],
        'Inventory' => [
            'name'        => 'Inventory',
            'screen'      => 'S-03, S-06, S-07, S-09, S-17',
            'description' => 'Master SKU, Delivery Order, Internal Transfer, Receipt, dan Retur Fisik',
        ],
        'Accounting' => [
            'name'        => 'Accounting',
            'screen'      => 'S-10, S-11, S-12, S-13, S-18',
            'description' => 'Customer Invoice, Vendor Bill, Payment, Journal Ledger, dan KPI Dashboard',
        ],
        'Point of Sale' => [
            'name'        => 'Point of Sale',
            'screen'      => 'S-14, S-15, S-16',
            'description' => 'POS Session, Opening Cash, Basket Checkout, Cash Count, dan Closing',
        ],
    ];

    /**
     * Hak akses modul (Menu / App Switcher visibility) per Role sesuai F-01, S-01, dan T-01:
     * - Sales: CRM dan Sales tersedia (plus lihat katalog produk S-03); Procurement (Purchase),
     *   Accounting posting, dan POS closing TIDAK diberikan ke Sales.
     * - Warehouse: Inventory (Delivery, Transfer, Receipt, Return inspection).
     * - Procurement: Purchase (RFQ/PO) dan Inventory (Master Produk S-03 & Receipt S-09).
     * - Finance: Accounting (Invoice, Vendor Bill, Payment, Ledger, Dashboard), Sales Contacts/Credit Limit (S-02),
     *   dan POS Closing Approval (S-16).
     * - Cashier: Point of Sale (Session, Basket, Cash Count/Close) dan POS Refund (S-17).
     */
    public const ROLE_ALLOWED_MODULES = [
        self::ROLE_SALES => [
            'Apps',
            'CRM',
            'Sales',
        ],
        self::ROLE_WAREHOUSE => [
            'Apps',
            'Inventory',
        ],
        self::ROLE_PROCUREMENT => [
            'Apps',
            'Purchase',
        ],
        self::ROLE_FINANCE => [
            'Apps',
            'Accounting',
        ],
        self::ROLE_CASHIER => [
            'Apps',
            'Point of Sale',
        ],
        self::ROLE_OWNER => [
            'Apps',
            'Accounting',
            'Dashboard',
            'Governance',
        ],
    ];

    /**
     * Pemetaan Halaman / URL yang diizinkan per Stakeholder (Strict RBAC):
     * - Budi Haryanto (Direktur / Owner): Akses HANYA ke Executive KPI Dashboard dan Halaman Audit/Laporan.
     * - Putri Aulia (Sales): Akses HANYA ke modul Sales & CRM (Lead, Opportunity, Quotation, Sales Order).
     * - Deni Pratama (Gudang / Warehouse): Akses HANYA ke modul Inventory (Delivery Order, Receipt, Reservasi, Mutasi Stok).
     * - Mira Salsabila (Purchase / Procurement): Akses HANYA ke modul Purchase (RFQ, Purchase Order, Data Vendor).
     * - Arif Firmansyah (Finance): Akses HANYA ke modul Accounting & Invoicing (Customer Invoice, Vendor Bill, Payment, Jurnal).
     * - Rani Oktaviani (Kasir / Cashier): Akses HANYA ke modul Point of Sale (POS Basket, Open/Close Session, Cash Count).
     */
    public const STAKEHOLDER_ALLOWED_PAGES = [
        self::ROLE_OWNER       => ['dashboard', 'governance', 'audit', 'reports'],
        self::ROLE_SALES       => ['sales', 'crm'],
        self::ROLE_WAREHOUSE   => ['inventory', 'warehouse', 'stock'],
        self::ROLE_PROCUREMENT => ['purchase', 'procurement', 'rfq', 'po'],
        self::ROLE_FINANCE     => ['accounting', 'finance', 'invoicing'],
        self::ROLE_CASHIER     => ['pos', 'cashier', 'point_of_sale'],
    ];

    public const STAKEHOLDER_HOME = [
        self::ROLE_OWNER       => ['page' => 'dashboard',  'module' => 'Dashboard',     'screen' => 'S-18', 'title' => 'Executive KPI Dashboard'],
        self::ROLE_SALES       => ['page' => 'sales',      'module' => 'Sales',         'screen' => 'S-05', 'title' => 'Sales & CRM'],
        self::ROLE_WAREHOUSE   => ['page' => 'inventory',  'module' => 'Inventory',     'screen' => 'S-06', 'title' => 'Inventory & Warehouse'],
        self::ROLE_PROCUREMENT => ['page' => 'purchase',   'module' => 'Purchase',      'screen' => 'S-08', 'title' => 'Purchase'],
        self::ROLE_FINANCE     => ['page' => 'accounting', 'module' => 'Accounting',    'screen' => 'S-10', 'title' => 'Accounting & Invoicing'],
        self::ROLE_CASHIER     => ['page' => 'pos',        'module' => 'Point of Sale', 'screen' => 'S-15', 'title' => 'Point of Sale (POS)'],
    ];

    public const STAKEHOLDER_APP_TILES = [
        self::ROLE_OWNER => [
            [
                'page'  => 'dashboard',
                'name'  => 'Executive KPI',
                'sub'   => 'Dasbor Direktur',
                'icon'  => 'bi-speedometer2',
                'bg'    => 'linear-gradient(135deg, #2C3E50 0%, #34495E 100%)',
            ],
            [
                'page'  => 'governance',
                'name'  => 'Audit & Laporan',
                'sub'   => 'Jejak Audit & Kontrol',
                'icon'  => 'bi-shield-check',
                'bg'    => 'linear-gradient(135deg, #4A148C 0%, #7B1FA2 100%)',
            ],
        ],
        self::ROLE_SALES => [
            [
                'page'  => 'sales',
                'name'  => 'Sales & CRM',
                'sub'   => 'Order to Cash',
                'icon'  => 'bi-graph-up-arrow',
                'bg'    => 'linear-gradient(135deg, #714B67 0%, #8E44AD 100%)',
            ],
        ],
        self::ROLE_WAREHOUSE => [
            [
                'page'  => 'inventory',
                'name'  => 'Inventory',
                'sub'   => 'Gudang & Stok',
                'icon'  => 'bi-boxes',
                'bg'    => 'linear-gradient(135deg, #F06050 0%, #E67E22 100%)',
            ],
        ],
        self::ROLE_PROCUREMENT => [
            [
                'page'  => 'purchase',
                'name'  => 'Purchase',
                'sub'   => 'Procure to Pay',
                'icon'  => 'bi-cart-check-fill',
                'bg'    => 'linear-gradient(135deg, #008784 0%, #27AE60 100%)',
            ],
        ],
        self::ROLE_FINANCE => [
            [
                'page'  => 'accounting',
                'name'  => 'Accounting & Invoicing',
                'sub'   => 'Ledger & Faktur',
                'icon'  => 'bi-journal-bookmark-fill',
                'bg'    => 'linear-gradient(135deg, #0E6EB8 0%, #2980B9 100%)',
            ],
        ],
        self::ROLE_CASHIER => [
            [
                'page'  => 'pos',
                'name'  => 'Point of Sale',
                'sub'   => 'Kasir Retail',
                'icon'  => 'bi-shop',
                'bg'    => 'linear-gradient(135deg, #00A09D 0%, #16A085 100%)',
            ],
        ],
    ];

    public static function isPageAllowedForRole(string $role, string $page): bool
    {
        $page = strtolower(trim($page));
        $allowed = self::STAKEHOLDER_ALLOWED_PAGES[$role] ?? [];
        return in_array($page, $allowed, true);
    }

    public static function getHomeForRole(string $role): array
    {
        return self::STAKEHOLDER_HOME[$role] ?? self::STAKEHOLDER_HOME[self::ROLE_SALES];
    }

    public static function getAppTilesForRole(string $role): array
    {
        return self::STAKEHOLDER_APP_TILES[$role] ?? self::STAKEHOLDER_APP_TILES[self::ROLE_SALES];
    }

    /**
     * Definisi matriks aksi granular beserta modul dan role yang diizinkan.
     */
    public const ACTION_POLICIES = [
        // Modul Apps (S-01)
        'apps.view' => [
            'module'        => 'Apps',
            'label'         => 'Buka Home Apps & Akses',
            'allowed_roles' => [self::ROLE_SALES, self::ROLE_WAREHOUSE, self::ROLE_PROCUREMENT, self::ROLE_FINANCE, self::ROLE_CASHIER, self::ROLE_OWNER],
        ],

        // Modul CRM (S-04)
        'crm.pipeline.view' => [
            'module'        => 'CRM',
            'label'         => 'Lihat CRM Pipeline',
            'allowed_roles' => [self::ROLE_SALES],
        ],
        'crm.lead.convert' => [
            'module'        => 'CRM',
            'label'         => 'Convert Lead ke Opportunity',
            'allowed_roles' => [self::ROLE_SALES],
        ],

        // Modul Sales (S-02, S-05)
        'sales.contacts.view' => [
            'module'        => 'Sales',
            'label'         => 'Lihat Master Contacts & Credit Terms',
            'allowed_roles' => [self::ROLE_SALES, self::ROLE_FINANCE],
        ],
        'sales.contacts.update_credit_limit' => [
            'module'        => 'Sales',
            'label'         => 'Ubah Credit Limit Pelanggan',
            'allowed_roles' => [self::ROLE_SALES, self::ROLE_FINANCE],
        ],
        'sales.order.create' => [
            'module'        => 'Sales',
            'label'         => 'Buat Quotation / Sales Order',
            'allowed_roles' => [self::ROLE_SALES],
        ],
        'sales.order.confirm' => [
            'module'        => 'Sales',
            'label'         => 'Confirm Sales Order',
            'allowed_roles' => [self::ROLE_SALES],
        ],
        'sales.order.update_price' => [
            'module'        => 'Sales',
            'label'         => 'Edit Harga / Diskon Sales Order',
            'allowed_roles' => [self::ROLE_SALES],
        ],

        // Modul Purchase (S-08)
        'purchase.order.view' => [
            'module'        => 'Purchase',
            'label'         => 'Buka Modul Procurement / Purchase Order',
            'allowed_roles' => [self::ROLE_PROCUREMENT],
        ],
        'purchase.order.create' => [
            'module'        => 'Purchase',
            'label'         => 'Buat RFQ / Purchase Order',
            'allowed_roles' => [self::ROLE_PROCUREMENT],
        ],
        'purchase.order.confirm' => [
            'module'        => 'Purchase',
            'label'         => 'Confirm Purchase Order',
            'allowed_roles' => [self::ROLE_PROCUREMENT],
        ],
        'purchase.order.update_price' => [
            'module'        => 'Purchase',
            'label'         => 'Edit Harga Beli Purchase Order',
            'allowed_roles' => [self::ROLE_PROCUREMENT],
        ],

        // Modul Inventory (S-03, S-06, S-07, S-09, S-17)
        'inventory.products.view' => [
            'module'        => 'Inventory',
            'label'         => 'Lihat Master Produk & SKU Varian',
            'allowed_roles' => [self::ROLE_SALES, self::ROLE_PROCUREMENT, self::ROLE_WAREHOUSE],
        ],
        'inventory.products.create' => [
            'module'        => 'Inventory',
            'label'         => 'Tambah / Ubah Master SKU Varian',
            'allowed_roles' => [self::ROLE_SALES, self::ROLE_PROCUREMENT],
        ],
        'inventory.delivery.validate' => [
            'module'        => 'Inventory',
            'label'         => 'Validasi Delivery Order & Backorder',
            'allowed_roles' => [self::ROLE_WAREHOUSE],
        ],
        'inventory.transfer.validate' => [
            'module'        => 'Inventory',
            'label'         => 'Validasi Internal Transfer Antar Lokasi',
            'allowed_roles' => [self::ROLE_WAREHOUSE],
        ],
        'inventory.receipt.validate' => [
            'module'        => 'Inventory',
            'label'         => 'Validasi Receipt & Quality Acceptance',
            'allowed_roles' => [self::ROLE_WAREHOUSE, self::ROLE_PROCUREMENT],
        ],

        // Modul Accounting (S-10, S-11, S-12, S-13, S-18)
        'accounting.customer_invoice.post' => [
            'module'        => 'Accounting',
            'label'         => 'Post Customer Invoice',
            'allowed_roles' => [self::ROLE_FINANCE],
        ],
        'accounting.vendor_bill.post' => [
            'module'        => 'Accounting',
            'label'         => 'Post Vendor Bill',
            'allowed_roles' => [self::ROLE_FINANCE],
        ],
        'accounting.payment.register' => [
            'module'        => 'Accounting',
            'label'         => 'Register & Reconcile Payment',
            'allowed_roles' => [self::ROLE_FINANCE],
        ],
        'accounting.journal.view' => [
            'module'        => 'Accounting',
            'label'         => 'Lihat Journal Entries / Ledger & Dashboard',
            'allowed_roles' => [self::ROLE_FINANCE, self::ROLE_OWNER],
        ],
        'accounting.return.credit_note' => [
            'module'        => 'Accounting',
            'label'         => 'Terbitkan Credit Note & Reversal Retur B2B',
            'allowed_roles' => [self::ROLE_FINANCE, self::ROLE_WAREHOUSE],
        ],
        'governance.audit.view' => [
            'module'        => 'Accounting',
            'label'         => 'Pengawasan Audit Trail, Penomoran Unik & Pencegahan Jurnal Ganda (SR-18/SR-19/SR-22)',
            'allowed_roles' => [self::ROLE_OWNER, self::ROLE_FINANCE],
        ],

        // Modul Point of Sale (S-14, S-15, S-16, S-17)
        'pos.session.open' => [
            'module'        => 'Point of Sale',
            'label'         => 'Buka POS Session & Opening Cash',
            'allowed_roles' => [self::ROLE_CASHIER],
        ],
        'pos.basket.checkout' => [
            'module'        => 'Point of Sale',
            'label'         => 'POS Basket Checkout & Paid Receipt',
            'allowed_roles' => [self::ROLE_CASHIER],
        ],
        'pos.session.close' => [
            'module'        => 'Point of Sale',
            'label'         => 'Tutup Sesi POS (Close & Post Session)',
            'allowed_roles' => [self::ROLE_CASHIER, self::ROLE_FINANCE],
        ],
        'pos.session.approve_variance' => [
            'module'        => 'Point of Sale',
            'label'         => 'Otorisasi Selisih Kas (Cash Variance Approval)',
            'allowed_roles' => [self::ROLE_FINANCE],
        ],
        'pos.return.refund' => [
            'module'        => 'Point of Sale',
            'label'         => 'Proses POS Cash Refund & Restock',
            'allowed_roles' => [self::ROLE_CASHIER, self::ROLE_WAREHOUSE, self::ROLE_FINANCE],
        ],
    ];

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ALL_STAKEHOLDER_ROLES, true);
    }

    public static function canAccessModule(string $role, string $module): bool
    {
        $allowed = self::ROLE_ALLOWED_MODULES[$role] ?? [];
        return in_array($module, $allowed, true);
    }

    public static function canPerformAction(string $role, string $action): bool
    {
        if (!isset(self::ACTION_POLICIES[$action])) {
            return false;
        }
        return in_array($role, self::ACTION_POLICIES[$action]['allowed_roles'], true);
    }

    public static function getVisibleModulesForRole(string $role): array
    {
        $allowedNames = self::ROLE_ALLOWED_MODULES[$role] ?? [];
        $result = [];
        foreach (self::MODULES as $moduleKey => $meta) {
            $result[$moduleKey] = array_merge($meta, [
                'key'        => $moduleKey,
                'accessible' => in_array($moduleKey, $allowedNames, true),
            ]);
        }
        return $result;
    }

    /**
     * Mengembalikan HANYA modul yang diizinkan untuk role aktif (F-01).
     * Modul di luar wewenang disembunyikan sepenuhnya dari navigasi & beranda.
     */
    public static function getAllowedModulesOnlyForRole(string $role): array
    {
        $allowedNames = self::ROLE_ALLOWED_MODULES[$role] ?? [];
        $result = [];
        foreach ($allowedNames as $moduleKey) {
            if (isset(self::MODULES[$moduleKey])) {
                $result[$moduleKey] = array_merge(self::MODULES[$moduleKey], [
                    'key'        => $moduleKey,
                    'accessible' => true,
                ]);
            }
        }
        return $result;
    }

    public static function getActionMeta(string $action): array
    {
        return self::ACTION_POLICIES[$action] ?? [
            'module'        => 'Unknown',
            'label'         => $action,
            'allowed_roles' => [],
        ];
    }

    /**
     * Menghasilkan struktur menu Left Sidebar (Overview, Orders / Documents, Reporting, Configuration)
     * yang menyesuaikan secara dinamis dengan role yang sedang aktif (F-01, F-02, T-01).
     */
    public static function getSidebarMenuForRole(string $role): array
    {
        $menus = [
            self::ROLE_SALES => [
                'Overview' => [
                    ['code' => 'S-01', 'label' => 'Dashboard', 'module' => 'Apps'],
                    ['code' => 'S-04', 'label' => 'CRM Pipeline', 'module' => 'CRM'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-05', 'label' => 'Sales Order', 'module' => 'Sales'],
                    ['code' => 'S-02', 'label' => 'Pelanggan', 'module' => 'Sales'],
                ],
                'Reporting' => [
                    ['code' => 'S-03', 'label' => 'Katalog Produk', 'module' => 'Sales'],
                ],
                'Configuration' => [
                    ['code' => 'CFG-SALES', 'label' => 'Aturan Sales', 'module' => 'Sales'],
                ],
            ],
            self::ROLE_WAREHOUSE => [
                'Overview' => [
                    ['code' => 'S-01', 'label' => 'Dashboard', 'module' => 'Apps'],
                    ['code' => 'S-07', 'label' => 'Transfer Stok', 'module' => 'Inventory'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-06', 'label' => 'Pengiriman (DO)', 'module' => 'Inventory'],
                    ['code' => 'S-09', 'label' => 'Penerimaan Barang', 'module' => 'Inventory'],
                    ['code' => 'S-17', 'label' => 'Retur Gudang', 'module' => 'Inventory'],
                ],
                'Reporting' => [
                    ['code' => 'S-03', 'label' => 'Stok Lokasi', 'module' => 'Inventory'],
                ],
                'Configuration' => [
                    ['code' => 'CFG-WH', 'label' => 'Konfigurasi Gudang', 'module' => 'Inventory'],
                ],
            ],
            self::ROLE_PROCUREMENT => [
                'Overview' => [
                    ['code' => 'S-08', 'label' => 'Dashboard Purchase', 'module' => 'Purchase'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-08', 'label' => 'Purchase Order (PO)', 'module' => 'Purchase'],
                    ['code' => 'S-02', 'label' => 'Data Rekanan Vendor', 'module' => 'Purchase'],
                ],
                'Reporting' => [
                    ['code' => 'S-03', 'label' => 'Vendor Pricelist', 'module' => 'Purchase'],
                ],
                'Configuration' => [
                    ['code' => 'CFG-PROC', 'label' => 'Master Vendor', 'module' => 'Purchase'],
                ],
            ],
            self::ROLE_FINANCE => [
                'Overview' => [
                    ['code' => 'S-01', 'label' => 'Dashboard', 'module' => 'Apps'],
                    ['code' => 'S-18', 'label' => 'Dashboard Finance', 'module' => 'Accounting'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-10', 'label' => 'Customer Invoice', 'module' => 'Accounting'],
                    ['code' => 'S-11', 'label' => 'Vendor Bill', 'module' => 'Accounting'],
                    ['code' => 'S-12', 'label' => 'Pembayaran', 'module' => 'Accounting'],
                    ['code' => 'S-16', 'label' => 'Rekonsiliasi POS', 'module' => 'Accounting'],
                    ['code' => 'S-17', 'label' => 'Credit Note', 'module' => 'Accounting'],
                ],
                'Reporting' => [
                    ['code' => 'S-13', 'label' => 'Buku Besar (GL)', 'module' => 'Accounting'],
                ],
                'Configuration' => [
                    ['code' => 'S-02', 'label' => 'Bagan Akun (COA)', 'module' => 'Accounting'],
                ],
            ],
            self::ROLE_CASHIER => [
                'Overview' => [
                    ['code' => 'S-01', 'label' => 'Dashboard', 'module' => 'Apps'],
                    ['code' => 'S-14', 'label' => 'Sesi Kasir', 'module' => 'Point of Sale'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-15', 'label' => 'Kasir (POS)', 'module' => 'Point of Sale'],
                    ['code' => 'S-16', 'label' => 'Tutup Kasir', 'module' => 'Point of Sale'],
                    ['code' => 'S-17', 'label' => 'Refund Kasir', 'module' => 'Point of Sale'],
                ],
                'Reporting' => [
                    ['code' => 'RPT-POS', 'label' => 'Laporan Struk', 'module' => 'Point of Sale'],
                ],
                'Configuration' => [
                    ['code' => 'CFG-POS', 'label' => 'Register POS', 'module' => 'Point of Sale'],
                ],
            ],
            self::ROLE_OWNER => [
                'Overview' => [
                    ['code' => 'S-18', 'label' => 'Dashboard Eksekutif', 'module' => 'Accounting'],
                ],
                'Orders / Documents' => [
                    ['code' => 'S-18', 'label' => 'Jejak Audit Transaksi', 'module' => 'Accounting'],
                ],
                'Reporting' => [
                    ['code' => 'S-18', 'label' => 'Laporan Eksekutif', 'module' => 'Accounting'],
                    ['code' => 'S-13', 'label' => 'Buku Besar & Neraca', 'module' => 'Accounting'],
                ],
                'Configuration' => [
                    ['code' => 'CFG-GOV', 'label' => 'Tata Kelola (SR-18)', 'module' => 'Accounting'],
                ],
            ],
        ];

        return $menus[$role] ?? $menus[self::ROLE_SALES];
    }
}

