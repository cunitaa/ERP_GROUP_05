<?php
declare(strict_types=1);

namespace RantauMart\Middleware;

use RantauMart\Config\RolePermissions;
use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\FixtureResetController;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PosController;
use RantauMart\Controllers\PurchaseController;
use RantauMart\Controllers\ReportController;
use RantauMart\Controllers\SalesController;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;

/**
 * Middleware — Proteksi Rute & Endpoint Tingkat Server (Fitur F-01 & Aturan SR-18)
 *
 * Menegakkan keamanan di tingkat server (bukan sekadar menyembunyikan menu UI):
 * 1. Memetakan URL endpoint REST/API (mis. POST /api/sales/confirm, POST /api/purchase/confirm,
 *    POST /api/accounting/bill/post, POST /api/pos/session/close, GET /api/reports/analytics)
 *    serta form intent ke aturan RBAC tiap pemangku kepentingan.
 * 2. Menolak paksa request yang berasal dari role tidak berwenang dengan status HTTP 403 Forbidden.
 * 3. Mencatat setiap penolakan secara otomatis ke tabel `audit_logs` beserta nama user (actor),
 *    role, waktu (created_at), endpoint/aksi, dan alasan penolakan sesuai aturan SR-18.
 */
class Middleware
{
    /**
     * Daftar rute endpoint API & intent beserta metode HTTP, modul, aksi RBAC, dan daftar role yang diizinkan.
     */
    public const ROUTE_POLICIES = [
        // ==================== MODUL CRM & SALES (Putri Aulia — Role: Sales) ====================
        '/api/crm/convert' => [
            'methods'       => ['POST'],
            'intent'        => 'crm_convert_lead',
            'module'        => 'CRM',
            'action'        => 'crm.lead.convert',
            'allowed_roles' => [RolePermissions::ROLE_SALES],
            'doc_param'     => 'lead_number',
            'default_doc'   => 'LEAD-0001',
        ],
        '/api/sales/create' => [
            'methods'       => ['POST'],
            'intent'        => 'sales_create_so',
            'module'        => 'Sales',
            'action'        => 'sales.order.create',
            'allowed_roles' => [RolePermissions::ROLE_SALES],
            'doc_param'     => 'so_number',
            'default_doc'   => 'DRAFT-SO',
        ],
        '/api/sales/confirm' => [
            'methods'       => ['POST'],
            'intent'        => 'sales_confirm_so',
            'module'        => 'Sales',
            'action'        => 'sales.order.confirm',
            'allowed_roles' => [RolePermissions::ROLE_SALES],
            'doc_param'     => 'so_number',
            'default_doc'   => 'S00007',
        ],
        '/api/sales/update-price' => [
            'methods'       => ['POST'],
            'intent'        => 'sales_update_price',
            'module'        => 'Sales',
            'action'        => 'sales.order.update_price',
            'allowed_roles' => [RolePermissions::ROLE_SALES],
            'doc_param'     => 'so_number',
            'default_doc'   => 'S00007',
        ],

        // ==================== MODUL PURCHASE (Mira Salsabila — Role: Procurement) ====================
        '/api/purchase/create' => [
            'methods'       => ['POST'],
            'intent'        => 'purchase_create_po',
            'module'        => 'Purchase',
            'action'        => 'purchase.order.create',
            'allowed_roles' => [RolePermissions::ROLE_PROCUREMENT],
            'doc_param'     => 'po_number',
            'default_doc'   => 'RFQ-NEW',
        ],
        '/api/purchase/confirm' => [
            'methods'       => ['POST'],
            'intent'        => 'purchase_confirm_po',
            'module'        => 'Purchase',
            'action'        => 'purchase.order.confirm',
            'allowed_roles' => [RolePermissions::ROLE_PROCUREMENT],
            'doc_param'     => 'po_number',
            'default_doc'   => 'PO00007',
        ],
        '/api/purchase/update-price' => [
            'methods'       => ['POST'],
            'intent'        => 'purchase_update_price',
            'module'        => 'Purchase',
            'action'        => 'purchase.order.update_price',
            'allowed_roles' => [RolePermissions::ROLE_PROCUREMENT],
            'doc_param'     => 'po_number',
            'default_doc'   => 'PO00007',
        ],

        // ==================== MODUL INVENTORY (Deni Pratama — Role: Warehouse) ====================
        '/api/inventory/delivery/validate' => [
            'methods'       => ['POST'],
            'intent'        => 'inventory_validate_delivery',
            'module'        => 'Inventory',
            'action'        => 'inventory.delivery.validate',
            'allowed_roles' => [RolePermissions::ROLE_WAREHOUSE],
            'doc_param'     => 'so_number',
            'default_doc'   => 'S00007',
        ],
        '/api/inventory/receipt/validate' => [
            'methods'       => ['POST'],
            'intent'        => 'inventory_validate_receipt',
            'module'        => 'Inventory',
            'action'        => 'inventory.receipt.validate',
            'allowed_roles' => [RolePermissions::ROLE_WAREHOUSE, RolePermissions::ROLE_PROCUREMENT],
            'doc_param'     => 'po_number',
            'default_doc'   => 'PO00007',
        ],
        '/api/inventory/transfer' => [
            'methods'       => ['POST'],
            'intent'        => 'inventory_internal_transfer',
            'module'        => 'Inventory',
            'action'        => 'inventory.transfer.validate',
            'allowed_roles' => [RolePermissions::ROLE_WAREHOUSE],
            'doc_param'     => 'variant_sku',
            'default_doc'   => 'WH-PKU->STORE-PKU',
        ],
        '/api/inventory/variant/create' => [
            'methods'       => ['POST'],
            'intent'        => 'inventory_create_variant',
            'module'        => 'Inventory',
            'action'        => 'inventory.products.create',
            'allowed_roles' => [RolePermissions::ROLE_WAREHOUSE, RolePermissions::ROLE_PROCUREMENT, RolePermissions::ROLE_SALES, RolePermissions::ROLE_OWNER],
            'doc_param'     => 'variant_sku',
            'default_doc'   => 'NEW-SKU',
        ],

        // ==================== MODUL ACCOUNTING (Arif Firmansyah — Role: Finance) ====================
        '/api/accounting/invoice/post' => [
            'methods'       => ['POST'],
            'intent'        => 'accounting_post_invoice',
            'module'        => 'Accounting',
            'action'        => 'accounting.customer_invoice.post',
            'allowed_roles' => [RolePermissions::ROLE_FINANCE],
            'doc_param'     => 'so_number',
            'default_doc'   => 'INV-DRAFT',
        ],
        '/api/accounting/bill/post' => [
            'methods'       => ['POST'],
            'intent'        => 'accounting_post_bill',
            'module'        => 'Accounting',
            'action'        => 'accounting.vendor_bill.post',
            'allowed_roles' => [RolePermissions::ROLE_FINANCE],
            'doc_param'     => 'po_number',
            'default_doc'   => 'BILL-0001',
        ],
        '/api/accounting/payment/register' => [
            'methods'       => ['POST'],
            'intent'        => 'accounting_register_payment',
            'module'        => 'Accounting',
            'action'        => 'accounting.payment.register',
            'allowed_roles' => [RolePermissions::ROLE_FINANCE],
            'doc_param'     => 'document_number',
            'default_doc'   => 'PAY-DRAFT',
        ],
        '/api/accounting/return' => [
            'methods'       => ['POST'],
            'intent'        => 'accounting_b2b_return',
            'module'        => 'Accounting',
            'action'        => 'accounting.return.credit_note',
            'allowed_roles' => [RolePermissions::ROLE_FINANCE, RolePermissions::ROLE_WAREHOUSE],
            'doc_param'     => 'invoice_number',
            'default_doc'   => 'INV-0001',
        ],

        // ==================== MODUL POINT OF SALE / POS (Rani Oktaviani — Role: Cashier) ====================
        '/api/pos/session/open' => [
            'methods'       => ['POST'],
            'intent'        => 'pos_open_session',
            'module'        => 'Point of Sale',
            'action'        => 'pos.session.open',
            'allowed_roles' => [RolePermissions::ROLE_CASHIER],
            'doc_param'     => 'location_code',
            'default_doc'   => 'STORE-PKU:POS01',
        ],
        '/api/pos/session/in-progress' => [
            'methods'       => ['POST'],
            'intent'        => 'pos_in_progress',
            'module'        => 'Point of Sale',
            'action'        => 'pos.session.open',
            'allowed_roles' => [RolePermissions::ROLE_CASHIER],
            'doc_param'     => 'session_number',
            'default_doc'   => 'POS-SESSION-0001',
        ],
        '/api/pos/basket/checkout' => [
            'methods'       => ['POST'],
            'intent'        => 'pos_checkout_basket',
            'module'        => 'Point of Sale',
            'action'        => 'pos.basket.checkout',
            'allowed_roles' => [RolePermissions::ROLE_CASHIER],
            'doc_param'     => 'session_number',
            'default_doc'   => 'POS-SESSION-0001',
        ],
        '/api/pos/session/close' => [
            'methods'       => ['POST'],
            'intent'        => 'pos_close_session',
            'module'        => 'Point of Sale',
            'action'        => 'pos.session.close',
            'allowed_roles' => [RolePermissions::ROLE_CASHIER, RolePermissions::ROLE_FINANCE],
            'doc_param'     => 'session_number',
            'default_doc'   => 'POS-SESSION-0001',
        ],
        '/api/pos/refund' => [
            'methods'       => ['POST'],
            'intent'        => 'pos_refund_receipt',
            'module'        => 'Point of Sale',
            'action'        => 'pos.return.refund',
            'allowed_roles' => [RolePermissions::ROLE_CASHIER, RolePermissions::ROLE_WAREHOUSE, RolePermissions::ROLE_FINANCE],
            'doc_param'     => 'receipt_number',
            'default_doc'   => 'POS-RCPT-0001',
        ],

        // ==================== MODUL REPORTING & ANALYTICS (Budi Haryanto & Arif Firmansyah) ====================
        '/api/reports/analytics' => [
            'methods'       => ['GET', 'POST'],
            'intent'        => 'report_analytics',
            'module'        => 'Reporting',
            'action'        => 'governance.audit.view',
            'allowed_roles' => [RolePermissions::ROLE_FINANCE, RolePermissions::ROLE_OWNER],
            'doc_param'     => 'document_number',
            'default_doc'   => 'KPI-FINANCIAL-DASHBOARD',
        ],

        // ==================== SISTEM / RESET FIXTURE (Budi Haryanto — Role: Owner / Direktur) ====================
        '/api/system/reset-fixture' => [
            'methods'       => ['POST', 'GET'],
            'intent'        => 'system_reset_fixture',
            'module'        => 'System',
            'action'        => 'system.fixture.reset',
            'allowed_roles' => [RolePermissions::ROLE_OWNER],
            'doc_param'     => 'document_number',
            'default_doc'   => 'BASELINE-FIXTURE',
        ],
    ];

    /**
     * Normalisasi path URL (menghapus query string, trailing slash, dan prefix script jika ada).
     */
    public static function normalizeEndpointPath(string $rawUri): string
    {
        $path = (string) parse_url($rawUri, PHP_URL_PATH);
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }

        // Jika diakses melalui /index.php/api/sales/confirm, ambil mulai dari /api/...
        $apiPos = strpos($path, '/api/');
        if ($apiPos !== false) {
            $path = substr($path, $apiPos);
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Mencari kebijakan rute berdasarkan path URL (mis. '/api/sales/confirm') atau nama intent (mis. 'sales_confirm_so').
     */
    public static function findPolicy(string $endpointOrIntent): ?array
    {
        $normalized = self::normalizeEndpointPath($endpointOrIntent);
        if (isset(self::ROUTE_POLICIES[$normalized])) {
            return array_merge(self::ROUTE_POLICIES[$normalized], ['endpoint' => $normalized]);
        }

        // Cek pencocokan berdasarkan nama intent (mis. 'sales_confirm_so' atau 'pos_reconcile_cash')
        $intentSearch = trim($endpointOrIntent);
        if ($intentSearch === 'pos_reconcile_cash') {
            $intentSearch = 'pos_close_session';
        }

        foreach (self::ROUTE_POLICIES as $routePath => $policy) {
            if (($policy['intent'] ?? '') === $intentSearch) {
                return array_merge($policy, ['endpoint' => $routePath]);
            }
        }

        return null;
    }

    /**
     * Memeriksa dan menegakkan proteksi rute di tingkat server (Server-Level Endpoint Guard).
     *
     * Contoh: Jika pengguna mencoba POST ke `/api/sales/confirm` namun role aktifnya bukan `Sales`,
     * maka metode ini WAJIB menolak request dengan status HTTP 403 Forbidden dan mencatat
     * penolakan tersebut secara otomatis ke tabel `audit_logs` beserta nama user dan timestamp (SR-18).
     *
     * @param string $endpointOrIntent Path URL (mis. '/api/sales/confirm') atau intent (mis. 'sales_confirm_so')
     * @param string $httpMethod       Metode HTTP ('POST', 'GET', dll.)
     * @param array  $payload          Data request (untuk mengekstrak nomor dokumen target)
     * @param bool   $sendHttpHeader   Jika true dan dijalankan di web server, otomatis set http_response_code(403)
     */
    public static function enforceEndpoint(
        string $endpointOrIntent,
        string $httpMethod = 'POST',
        array $payload = [],
        bool $sendHttpHeader = true
    ): array {
        $user       = AuthService::getCurrentUser();
        $role       = (string) ($user['role'] ?? 'Unauthenticated');
        $fullName   = (string) ($user['full_name'] ?? 'Unknown');
        $username   = (string) ($user['username'] ?? 'unknown');
        $actor      = "{$fullName} ({$username})";
        $httpMethod = strtoupper(trim($httpMethod ?: 'POST'));

        $policy = self::findPolicy($endpointOrIntent);
        if ($policy === null) {
            return [
                'allowed'        => true,
                'http_code'      => 200,
                'outcome'        => 'ALLOWED',
                'status_changed' => false,
                'endpoint'       => $endpointOrIntent,
                'role'           => $role,
                'actor'          => $actor,
            ];
        }

        $endpoint     = (string) $policy['endpoint'];
        $module       = (string) $policy['module'];
        $action       = (string) $policy['action'];
        $allowedRoles = (array) $policy['allowed_roles'];
        $allowedMeths = (array) $policy['methods'];
        $docParam     = (string) ($policy['doc_param'] ?? 'document_number');
        $docNumber    = trim((string) ($payload[$docParam] ?? $payload['document_number'] ?? $policy['default_doc'] ?? '-'));

        // 1. Validasi Metode HTTP
        if (!in_array($httpMethod, $allowedMeths, true)) {
            $reason = sprintf(
                "HTTP Method Not Allowed: Endpoint '%s' hanya menerima metode [%s], tetapi diakses menggunakan '%s' oleh %s (Role: %s).",
                $endpoint,
                implode(', ', $allowedMeths),
                $httpMethod,
                $actor,
                $role
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: $module,
                action: "{$httpMethod} {$endpoint}",
                documentNumber: $docNumber,
                oldStatus: 'Preserved',
                newStatus: 'Preserved',
                outcome: 'DENIED',
                reason: $reason
            );

            if ($sendHttpHeader && PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(405);
            }

            return [
                'allowed'            => false,
                'http_code'          => 405,
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-18',
                'status_changed'     => false,
                'document_preserved' => true,
                'endpoint'           => $endpoint,
                'method'             => $httpMethod,
                'actor'              => $actor,
                'role'               => $role,
                'module'             => $module,
                'action'             => $action,
                'document'           => $docNumber,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        // 2. Validasi Role di Tingkat Server (F-01 & SR-18): Tolak dengan HTTP 403 Forbidden jika bukan role berwenang!
        if (!in_array($role, $allowedRoles, true)) {
            $reason = sprintf(
                "HTTP 403 Forbidden (F-01 & SR-18 Server Route Guard): Akses '%s %s' (%s) oleh %s [Role: '%s'] DITOLAK di tingkat server! Endpoint ini hanya diizinkan untuk role [%s]. Dokumen '%s' dijaga tetap utuh.",
                $httpMethod,
                $endpoint,
                $action,
                $actor,
                $role,
                implode(', ', $allowedRoles),
                $docNumber
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: $module,
                action: "{$httpMethod} {$endpoint} ({$action})",
                documentNumber: $docNumber,
                oldStatus: 'Protected',
                newStatus: 'Protected',
                outcome: 'DENIED',
                reason: $reason
            );

            if ($sendHttpHeader && PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(403);
            }

            return [
                'allowed'            => false,
                'http_code'          => 403,
                'status'             => '403 Forbidden',
                'outcome'            => 'DENIED',
                'decision'           => 'DENIED',
                'rule'               => 'SR-18',
                'status_changed'     => false,
                'document_preserved' => true,
                'endpoint'           => $endpoint,
                'method'             => $httpMethod,
                'intent'             => $policy['intent'],
                'actor'              => $actor,
                'role'               => $role,
                'allowed_roles'      => $allowedRoles,
                'module'             => $module,
                'action'             => $action,
                'document'           => $docNumber,
                'reason'             => $reason,
                'message'            => $reason,
                'audit_id'           => $auditId,
            ];
        }

        return [
            'allowed'       => true,
            'http_code'     => 200,
            'outcome'       => 'ALLOWED',
            'decision'      => 'ALLOWED',
            'endpoint'      => $endpoint,
            'method'        => $httpMethod,
            'intent'        => $policy['intent'],
            'actor'         => $actor,
            'role'          => $role,
            'allowed_roles' => $allowedRoles,
            'module'        => $module,
            'action'        => $action,
            'document'      => $docNumber,
        ];
    }

    /**
     * Menangani request endpoint API secara lengkap:
     * 1. Menjalankan pemeriksaan keamanan `enforceEndpoint()` di tingkat server.
     * 2. Jika role tidak sesuai -> mengembalikan HTTP 403 Forbidden + bukti `audit_id` tanpa memanggil Controller.
     * 3. Jika role sesuai -> meneruskan eksekusi ke Controller terkait.
     */
    public static function dispatchProtectedRoute(
        string $endpointOrIntent,
        string $httpMethod = 'POST',
        array $payload = [],
        bool $sendHttpHeader = true
    ): array {
        $guard = self::enforceEndpoint($endpointOrIntent, $httpMethod, $payload, $sendHttpHeader);
        if (!($guard['allowed'] ?? false)) {
            return $guard;
        }

        $intent = (string) ($guard['intent'] ?? $endpointOrIntent);

        return match ($intent) {
            'crm_convert_lead' => SalesController::convertLeadToOpportunity(
                (string) ($payload['lead_number'] ?? 'LEAD-0001')
            ),
            'sales_create_so'  => SalesController::createSalesOrder($payload),
            'sales_confirm_so' => SalesController::confirmSalesOrder(
                (string) ($payload['so_number'] ?? 'S00007'),
                isset($payload['discount_pct']) && $payload['discount_pct'] !== ''
                    ? (float) $payload['discount_pct']
                    : null
            ),
            'sales_update_price' => SalesController::updateSalesOrderPrice(
                (string) ($payload['so_number'] ?? 'S00007'),
                (float) ($payload['unit_price'] ?? 4000),
                (float) ($payload['discount_pct'] ?? 0)
            ),
            'purchase_create_po'  => PurchaseController::createPurchaseOrder($payload),
            'purchase_confirm_po' => PurchaseController::confirmPurchaseOrder(
                (string) ($payload['po_number'] ?? 'PO00007')
            ),
            'purchase_update_price' => PurchaseController::updatePurchaseOrderPrice(
                (string) ($payload['po_number'] ?? 'PO00007'),
                (float) ($payload['unit_price'] ?? 3000)
            ),
            'inventory_validate_delivery' => InventoryController::validateDeliveryOrder($payload),
            'inventory_validate_receipt'  => InventoryController::receivePurchaseOrder($payload),
            'inventory_internal_transfer' => InventoryController::createInternalTransfer($payload),
            'inventory_create_variant'    => InventoryController::createProductVariant($payload),
            'accounting_post_invoice'     => AccountingController::postCustomerInvoice($payload),
            'accounting_post_bill'        => AccountingController::postVendorBill($payload),
            'accounting_register_payment' => AccountingController::registerPayment($payload),
            'accounting_b2b_return'       => AccountingController::createB2bReturnCreditNote($payload),
            'pos_open_session'            => PosController::openPosSession($payload),
            'pos_in_progress'             => PosController::startSessionInProgress(
                (string) ($payload['session_number'] ?? 'POS-SESSION-0001')
            ),
            'pos_checkout_basket'         => PosController::checkoutPosBasket($payload),
            'pos_close_session',
            'pos_reconcile_cash'          => PosController::reconcileCashCount($payload),
            'pos_refund_receipt'          => PosController::processPosRefund($payload),
            'report_analytics'            => ReportController::getDashboardAnalytics(),
            'system_reset_fixture'        => FixtureResetController::executeReset($payload),
            default                       => $guard,
        };
    }
}

