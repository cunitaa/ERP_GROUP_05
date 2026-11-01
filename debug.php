<?php
declare(strict_types=1);

/**
 * PT RantauMart Riau — Halaman Khusus Debug & Pengujian Skenario (debug.php)
 * Terpisah dari beranda utama (index.php) agar beranda utama bersih.
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

use RantauMart\Controllers\AuthController;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Services\AuthService;

AuthService::ensureSession();

$getParams   = $_GET ?? [];
$postParams  = $_POST ?? [];
$requestData = array_merge($getParams, $postParams);

$intent       = (string) ($requestData['intent'] ?? '');
$activeModule = (string) ($requestData['module'] ?? 'Apps');
$flashResult  = null;

if ($intent === 'login') {
    $flashResult = AuthController::handleLogin($requestData);
    $flashResult['outcome'] = ((bool) ($flashResult['success'] ?? false)) ? 'ALLOWED' : 'DENIED';
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Location: debug.php');
        exit;
    }
} elseif ($intent === 'open_module') {
    $targetModule = (string) ($requestData['module'] ?? 'Apps');
    $flashResult  = HomeAppsController::accessModule($targetModule);
} elseif ($intent === 'action') {
    $command     = (string) ($requestData['command'] ?? '');
    $flashResult = HomeAppsController::executeGuardedAction($command);
}

$vm = HomeAppsController::getDashboardViewModel($flashResult, $activeModule, $requestData);

if (is_array($flashResult) && isset($flashResult['http_code'])) {
    http_response_code((int) ($flashResult['http_code'] ?? 200));
}

require __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'debug_layout.php';

