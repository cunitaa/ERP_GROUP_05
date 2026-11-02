<?php
declare(strict_types=1);

/**
 * PT RantauMart Riau — Entry Point Beranda Utama (index.php)
 * Terintegrasi dengan controllers/SalesController.php untuk menangani modul CRM & Sales
 * (Fitur F-05, F-06, F-07, F-08 & Aturan SR-07, SR-08, SR-04, SR-09, SR-18).
 */

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

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Middleware.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IntegrationEventTrigger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'reset_fixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'SalesController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PurchaseController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'InventoryController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'AccountingController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PosController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'ReportController.php';

use RantauMart\Config\RolePermissions;
use RantauMart\Controllers\AccountingController;
use RantauMart\Controllers\AuthController;
use RantauMart\Controllers\DashboardController;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Controllers\InventoryController;
use RantauMart\Controllers\PosController;
use RantauMart\Controllers\PurchaseController;
use RantauMart\Controllers\ReportController;
use RantauMart\Controllers\SalesController;
use RantauMart\Middleware\Middleware;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;
use RantauMart\Services\FlashService;

AuthService::ensureSession();

$getParams   = $_GET ?? [];
$postParams  = $_POST ?? [];
$requestData = array_merge($getParams, $postParams);

$rawBody = file_get_contents('php://input') ?: '';
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $requestData = array_merge($requestData, $decoded);
    }
}

$currentUser = AuthService::getCurrentUser();
$currentRole = (string) ($currentUser['role'] ?? 'Sales');

// Parameter Navigasi Dinamis Modular: ?page=sales, ?page=purchase, ?page=inventory, dsb.
// Mendukung pengetikan ?view=accounting, ?view=sales, dsb.
$rawPage   = strtolower(trim((string) ($requestData['page'] ?? '')));
$rawModule = trim((string) ($requestData['module'] ?? ''));
$rawScreen = trim((string) ($requestData['screen'] ?? ''));
$rawView   = strtolower(trim((string) ($requestData['view'] ?? '')));

// Jika ?view=... berisi kata kunci modul (contoh: Kasir mengetik ?view=accounting), arahkan sebagai target page
if ($rawPage === '' && in_array($rawView, ['accounting', 'sales', 'purchase', 'inventory', 'pos', 'governance', 'finance', 'warehouse', 'procurement', 'crm'], true)) {
    $rawPage = $rawView;
}

$requestedTarget = null;
if ($rawPage !== '') {
    if (in_array($rawPage, ['sales', 'crm'], true)) {
        $requestedTarget = 'sales';
    } elseif (in_array($rawPage, ['purchase', 'procurement', 'rfq', 'po'], true)) {
        $requestedTarget = 'purchase';
    } elseif (in_array($rawPage, ['inventory', 'warehouse', 'stock'], true)) {
        $requestedTarget = 'inventory';
    } elseif (in_array($rawPage, ['accounting', 'finance', 'invoicing'], true)) {
        $requestedTarget = 'accounting';
    } elseif (in_array($rawPage, ['pos', 'cashier', 'point_of_sale'], true)) {
        $requestedTarget = 'pos';
    } elseif (in_array($rawPage, ['governance', 'executive', 'audit', 'report', 'reports'], true)) {
        $requestedTarget = 'governance';
    } elseif (in_array($rawPage, ['dashboard', 'home', 'overview', 'apps'], true)) {
        $requestedTarget = 'dashboard';
    }
} elseif ($rawModule !== '' && $rawModule !== 'Apps') {
    if ($rawModule === 'Sales' || $rawModule === 'CRM') {
        $requestedTarget = 'sales';
    } elseif ($rawModule === 'Purchase') {
        $requestedTarget = 'purchase';
    } elseif ($rawModule === 'Inventory') {
        $requestedTarget = 'inventory';
    } elseif ($rawModule === 'Point of Sale' || $rawModule === 'POS') {
        $requestedTarget = 'pos';
    } elseif ($rawModule === 'Accounting') {
        $requestedTarget = ($rawScreen === 'S-18' || $rawScreen === 'CFG-GOV') ? 'governance' : 'accounting';
    }
} elseif ($rawScreen !== '') {
    if (in_array($rawScreen, ['S-02', 'S-04', 'S-05', 'CFG-SALES'], true)) {
        $requestedTarget = 'sales';
    } elseif (in_array($rawScreen, ['S-08', 'CFG-PROC'], true)) {
        $requestedTarget = 'purchase';
    } elseif (in_array($rawScreen, ['S-03', 'S-06', 'S-07', 'S-09', 'S-17', 'CFG-WH'], true)) {
        $requestedTarget = 'inventory';
    } elseif (in_array($rawScreen, ['S-14', 'S-15', 'S-16', 'RPT-POS', 'CFG-POS'], true)) {
        $requestedTarget = 'pos';
    } elseif ($rawScreen === 'S-18' || $rawScreen === 'CFG-GOV') {
        $requestedTarget = 'governance';
    } elseif (in_array($rawScreen, ['S-10', 'S-11', 'S-12', 'S-13', 'CFG-FIN'], true)) {
        $requestedTarget = 'accounting';
    }
}

$homeConfig      = RolePermissions::getHomeForRole($currentRole);
$accessViolation = false;
$deniedReason    = '';
$flashResult     = null;

if ($requestedTarget !== null) {
    if ($requestedTarget === 'dashboard') {
        // Beranda Dashboard Landing Page diizinkan
        $activePage   = 'dashboard';
        $activeModule = 'Apps';
        $screenParam  = ($currentRole === 'Owner' || $currentRole === 'Finance') ? 'S-18' : 'S-01';
    } elseif (!RolePermissions::isPageAllowedForRole($currentRole, $requestedTarget)) {
        // Pelanggaran Strict RBAC (F-01 & SR-18): Pengguna mencoba mengakses modul divisi lain!
        $accessViolation = true;
        $targetTitle     = ucfirst($requestedTarget);
        $deniedReason    = sprintf(
            "403 Access Denied (Strict RBAC): Role '%s' (%s) dilarang mengakses modul '%s'. Sistem mengembalikan Anda secara otomatis ke ruang kerja '%s'.",
            $currentRole,
            (string) ($currentUser['full_name'] ?? $currentRole),
            $targetTitle,
            $homeConfig['title']
        );

        // Catat insiden ke tabel audit_logs
        AuditLogger::log(
            actor: "{$currentUser['full_name']} ({$currentUser['username']})",
            role: $currentRole,
            module: $targetTitle,
            action: 'module.access',
            documentNumber: 'ACCESS-DENIED-' . strtoupper($requestedTarget),
            oldStatus: 'Active',
            newStatus: 'Denied',
            outcome: 'DENIED',
            reason: $deniedReason
        );

        // Flash Toast Notification merah di pojok kanan atas
        FlashService::error($deniedReason);

        $flashResult = [
            'allowed'            => false,
            'http_code'          => 403,
            'outcome'            => 'DENIED',
            'decision'           => 'DENIED',
            'rule'               => 'SR-18',
            'status_changed'     => false,
            'document_preserved' => true,
            'target_module'      => $targetTitle,
            'redirected_to'      => $homeConfig['page'],
            'reason'             => $deniedReason,
            'message'            => $deniedReason,
        ];

        // Otomatis kembalikan ke modul asal stakeholder!
        $activePage   = $homeConfig['page'];
        $activeModule = $homeConfig['module'];
        $screenParam  = $homeConfig['screen'];
    } else {
        // Akses diizinkan
        $activePage = $requestedTarget;
        if ($activePage === 'sales') {
            $activeModule = ($rawPage === 'crm' || $rawModule === 'CRM' || $rawScreen === 'S-04') ? 'CRM' : 'Sales';
            $screenParam  = $rawScreen !== '' ? $rawScreen : 'S-05';
        } elseif ($activePage === 'purchase') {
            $activeModule = 'Purchase';
            $screenParam  = $rawScreen !== '' ? $rawScreen : 'S-08';
        } elseif ($activePage === 'inventory') {
            $activeModule = 'Inventory';
            $screenParam  = $rawScreen !== '' ? $rawScreen : 'S-06';
        } elseif ($activePage === 'accounting') {
            $activeModule = 'Accounting';
            $screenParam  = $rawScreen !== '' ? $rawScreen : 'S-10';
        } elseif ($activePage === 'pos') {
            $activeModule = 'Point of Sale';
            $screenParam  = $rawScreen !== '' ? $rawScreen : 'S-15';
        } elseif ($activePage === 'governance') {
            $activeModule = 'Accounting';
            $screenParam  = 'S-18';
        }
    }
} else {
    // Default Beranda (Landing Page): tanpa parameter -> 'Dashboard'
    $activePage   = 'dashboard';
    $activeModule = 'Apps';
    $screenParam  = ($currentRole === 'Owner' || $currentRole === 'Finance') ? 'S-18' : 'S-01';
}

$requestData['page']   = $activePage;
$requestData['module'] = $activeModule;
if ($screenParam !== '') {
    $requestData['screen'] = $screenParam;
}

$intent       = (string) ($requestData['intent'] ?? '');
$flashResult  = null;

$requestUri    = (string) ($requestData['endpoint'] ?? $_SERVER['REQUEST_URI'] ?? '');
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ($intent !== '' ? 'POST' : 'GET')));
$normalizedUri = Middleware::normalizeEndpointPath($requestUri);

// Jika request menuju rute /api/... (contoh: POST /api/sales/confirm), petakan otomatis melalui Middleware
if ($intent === '' && str_starts_with($normalizedUri, '/api/')) {
    $matchedPolicy = Middleware::findPolicy($normalizedUri);
    if ($matchedPolicy !== null) {
        $intent       = (string) ($matchedPolicy['intent'] ?? '');
        $activeModule = (string) ($matchedPolicy['module'] ?? $activeModule);
    }
}

if (!$accessViolation) {
    if (isset($_SESSION['flash_result']) && is_array($_SESSION['flash_result'])) {
        $flashResult = $_SESSION['flash_result'];
        unset($_SESSION['flash_result']);
    }

    if (isset($_SESSION['flash_toast']) && is_array($_SESSION['flash_toast'])) {
        $flashResult = $_SESSION['flash_toast'];
        unset($_SESSION['flash_toast']);
    }
}

$acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
$formatParam  = (string) ($getParams['format'] ?? '');
$wantsJson    = ($formatParam === 'json') || str_contains($acceptHeader, 'application/json');

if ($intent === 'login') {
    $flashResult = AuthController::handleLogin($requestData);
    $flashResult['outcome'] = ((bool) ($flashResult['success'] ?? false)) ? 'ALLOWED' : 'DENIED';

    if (PHP_SAPI !== 'cli' && !$wantsJson && !headers_sent()) {
        $_SESSION['flash_toast'] = $flashResult;
        $redirectView = urlencode((string) ($getParams['view'] ?? 'list'));
        header("Location: index.php?view={$redirectView}");
        exit;
    }
} elseif ($intent === 'logout') {
    $flashResult = AuthController::handleLogout();
    $flashResult['outcome'] = 'ALLOWED';
    if (PHP_SAPI !== 'cli' && !$wantsJson && !headers_sent()) {
        $_SESSION['flash_toast'] = $flashResult;
        header('Location: index.php');
        exit;
    }
} elseif ($intent === 'open_module') {
    $targetModule = (string) ($requestData['module'] ?? 'Apps');
    $flashResult  = HomeAppsController::accessModule($targetModule);
    if ((bool) ($flashResult['allowed'] ?? false)) {
        $activeModule = $targetModule;
    } else {
        $activeModule = 'Apps';
    }
} elseif (Middleware::findPolicy($normalizedUri !== '/' ? $normalizedUri : $intent) !== null) {
    $routeTarget  = str_starts_with($normalizedUri, '/api/') ? $normalizedUri : $intent;
    $policyMeta   = Middleware::findPolicy($routeTarget);
    $activeModule = (string) ($policyMeta['module'] ?? $activeModule);
    $flashResult  = Middleware::dispatchProtectedRoute($routeTarget, $requestMethod, $requestData, true);
} elseif ($intent === 'action') {
    $command     = (string) ($requestData['command'] ?? '');
    $flashResult = HomeAppsController::executeGuardedAction($command, $requestData);
}

// PRG (Post/Redirect/Get): Cegah pengiriman formulir ganda & duplikasi transaksi saat browser di-refresh (F5)
if ($requestMethod === 'POST' && PHP_SAPI !== 'cli' && !$wantsJson && !headers_sent() && $intent !== '' && $intent !== 'login' && $intent !== 'logout') {
    $_SESSION['flash_result'] = $flashResult;
    $targetPage   = urlencode($activePage);
    $targetModule = urlencode($activeModule);
    $targetScreen = urlencode($screenParam);
    $targetView   = urlencode((string) ($getParams['view'] ?? $requestData['view'] ?? 'list'));
    header("Location: index.php?page={$targetPage}&module={$targetModule}&screen={$targetScreen}&view={$targetView}");
    exit;
}

$vm = HomeAppsController::getDashboardViewModel($flashResult, $activeModule, $requestData);
$vm['active_page'] = $activePage;

// Flash Session Toast: ambil & kosongkan antrean toast langkah dokumen yang berhasil.
$flashToasts = FlashService::pull();

// SR-19: Dasbor Eksekutif (query real-time) hanya dibangun untuk Direktur (Owner) & Finance; role lain -> null.
$vm['exec_dashboard'] = DashboardController::buildViewModel(
    isset($requestData['drill']) ? (string) $requestData['drill'] : null,
    isset($requestData['drill'])
);
$vm['flash_toasts'] = $flashToasts;

if ($wantsJson) {
    $httpStatus = (int) ($flashResult['http_code'] ?? 200);
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($vm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accessViolation) {
    http_response_code(403);
} elseif (is_array($flashResult) && isset($flashResult['http_code'])) {
    http_response_code((int) ($flashResult['http_code'] ?? 200));
} else {
    http_response_code(200);
}

require __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'layout.php';
