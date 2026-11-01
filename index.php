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
$pageParam   = strtolower(trim((string) ($requestData['page'] ?? '')));
$moduleParam = trim((string) ($requestData['module'] ?? ''));
$screenParam = trim((string) ($requestData['screen'] ?? ''));

$activePage   = 'dashboard';
$activeModule = 'Apps';

if ($pageParam !== '') {
    if (in_array($pageParam, ['dashboard', 'home', 'overview', 'apps'], true)) {
        $activePage   = 'dashboard';
        $activeModule = 'Apps';
        if ($screenParam === '') {
            $screenParam = ($currentRole === 'Owner' || $currentRole === 'Finance') ? 'S-18' : 'S-01';
        }
    } elseif (in_array($pageParam, ['sales', 'crm'], true)) {
        $activePage   = 'sales';
        $activeModule = ($pageParam === 'crm' || $moduleParam === 'CRM') ? 'CRM' : 'Sales';
        if ($screenParam === '') {
            $screenParam = 'S-05';
        }
    } elseif (in_array($pageParam, ['purchase', 'procurement', 'rfq', 'po'], true)) {
        $activePage   = 'purchase';
        $activeModule = 'Purchase';
        if ($screenParam === '') {
            $screenParam = 'S-08';
        }
    } elseif (in_array($pageParam, ['inventory', 'warehouse', 'stock'], true)) {
        $activePage   = 'inventory';
        $activeModule = 'Inventory';
        if ($screenParam === '') {
            $screenParam = 'S-06';
        }
    } elseif (in_array($pageParam, ['accounting', 'finance', 'invoicing'], true)) {
        $activePage   = 'accounting';
        $activeModule = 'Accounting';
        if ($screenParam === '') {
            $screenParam = 'S-10';
        }
    } elseif (in_array($pageParam, ['pos', 'cashier', 'point_of_sale'], true)) {
        $activePage   = 'pos';
        $activeModule = 'Point of Sale';
        if ($screenParam === '') {
            $screenParam = 'S-15';
        }
    } elseif (in_array($pageParam, ['governance', 'executive', 'audit', 'report', 'reports'], true)) {
        $activePage   = 'governance';
        $activeModule = 'Accounting';
        $screenParam  = 'S-18';
    }
} elseif ($moduleParam !== '' && $moduleParam !== 'Apps') {
    $activeModule = $moduleParam;
    if ($moduleParam === 'Sales' || $moduleParam === 'CRM') {
        $activePage = 'sales';
    } elseif ($moduleParam === 'Purchase') {
        $activePage = 'purchase';
    } elseif ($moduleParam === 'Inventory') {
        $activePage = 'inventory';
    } elseif ($moduleParam === 'Point of Sale') {
        $activePage = 'pos';
    } elseif ($moduleParam === 'Accounting') {
        $activePage = ($screenParam === 'S-18' || $screenParam === 'CFG-GOV') ? 'governance' : 'accounting';
    }
} elseif ($screenParam !== '') {
    if (in_array($screenParam, ['S-02', 'S-04', 'S-05', 'CFG-SALES'], true)) {
        $activePage   = 'sales';
        $activeModule = 'Sales';
    } elseif (in_array($screenParam, ['S-08', 'CFG-PROC'], true)) {
        $activePage   = 'purchase';
        $activeModule = 'Purchase';
    } elseif (in_array($screenParam, ['S-03', 'S-06', 'S-07', 'S-09', 'S-17', 'CFG-WH'], true)) {
        $activePage   = 'inventory';
        $activeModule = 'Inventory';
    } elseif (in_array($screenParam, ['S-14', 'S-15', 'S-16', 'RPT-POS', 'CFG-POS'], true)) {
        $activePage   = 'pos';
        $activeModule = 'Point of Sale';
    } elseif ($screenParam === 'S-18' || $screenParam === 'CFG-GOV') {
        $activePage   = 'governance';
        $activeModule = 'Accounting';
    } elseif (in_array($screenParam, ['S-10', 'S-11', 'S-12', 'S-13', 'CFG-FIN'], true)) {
        $activePage   = 'accounting';
        $activeModule = 'Accounting';
    } else {
        $activePage   = 'dashboard';
        $activeModule = 'Apps';
    }
} else {
    // Default Beranda (Landing Page): tanpa parameter -> 'Dashboard KPI Eksekutif'
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

if (isset($_SESSION['flash_toast']) && is_array($_SESSION['flash_toast'])) {
    $flashResult = $_SESSION['flash_toast'];
    unset($_SESSION['flash_toast']);
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

if (is_array($flashResult) && isset($flashResult['http_code'])) {
    http_response_code((int) ($flashResult['http_code'] ?? 200));
}

require __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'layout.php';
