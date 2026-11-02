<?php
declare(strict_types=1);

/**
 * PT RantauMart Riau — Halaman Khusus Debug & Pengujian Skenario (debug.php)
 * Terpisah dari beranda utama (index.php) agar beranda utama bersih.
 * Dilengkapi Runner Otomatis Skenario T-01 s/d T-36 & Audit Log Viewer.
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

require_once __DIR__ . DIRECTORY_SEPARATOR . 'IntegrationEventTrigger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Middleware.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'reset_fixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'SalesController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PurchaseController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'InventoryController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'AccountingController.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PosController.php';

use RantauMart\Controllers\AuthController;
use RantauMart\Controllers\HomeAppsController;
use RantauMart\Services\AuthService;
use RantauMart\Services\ScenarioTestRunner;

AuthService::ensureSession();

$getParams   = $_GET ?? [];
$postParams  = $_POST ?? [];
$requestData = array_merge($getParams, $postParams);

$intent       = (string) ($requestData['intent'] ?? '');
$activeModule = (string) ($requestData['module'] ?? 'Apps');
$flashResult  = null;

if (isset($_SESSION['debug_flash_result']) && is_array($_SESSION['debug_flash_result'])) {
    $flashResult = $_SESSION['debug_flash_result'];
    unset($_SESSION['debug_flash_result']);
}

$testSuiteResult = $_SESSION['test_suite_result'] ?? null;

if ($intent === 'login') {
    $flashResult = AuthController::handleLogin($requestData);
    $flashResult['outcome'] = ((bool) ($flashResult['success'] ?? false)) ? 'ALLOWED' : 'DENIED';
} elseif ($intent === 'open_module') {
    $targetModule = (string) ($requestData['module'] ?? 'Apps');
    $flashResult  = HomeAppsController::accessModule($targetModule);
} elseif ($intent === 'action') {
    $command = (string) ($requestData['command'] ?? '');
    if ($command === 'run_all_tests') {
        $testSuiteResult = ScenarioTestRunner::runAll();
        $_SESSION['test_suite_result'] = $testSuiteResult;
        $allOk = (bool) ($testSuiteResult['summary']['all_passed'] ?? false);
        $flashResult = [
            'allowed'     => true,
            'outcome'     => $allOk ? 'ALLOWED' : 'DENIED',
            'http_code'   => 200,
            'reason'      => sprintf(
                'Eksekusi Skenario Uji Selesai: %d Passed, %d Failed dari %d Skenario (Durasi: %s ms). Database telah dipulihkan otomatis ke kondisi awal (Baseline Rp25.716.900).',
                $testSuiteResult['summary']['passed'],
                $testSuiteResult['summary']['failed'],
                $testSuiteResult['summary']['total'],
                $testSuiteResult['summary']['duration_ms']
            ),
        ];
    } elseif ($command === 'clear_test_results') {
        unset($_SESSION['test_suite_result']);
        $testSuiteResult = null;
        $flashResult = [
            'allowed'   => true,
            'outcome'   => 'ALLOWED',
            'http_code' => 200,
            'reason'    => 'Hasil riwayat pengujian berhasil dibersihkan.',
        ];
    } else {
        $flashResult = HomeAppsController::executeGuardedAction($command);
    }
}

// PRG (Post/Redirect/Get) untuk debug.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && PHP_SAPI !== 'cli' && !headers_sent() && $intent !== '') {
    $_SESSION['debug_flash_result'] = $flashResult;
    header('Location: debug.php');
    exit;
}

$vm = HomeAppsController::getDashboardViewModel($flashResult, $activeModule, $requestData);
$vm['test_suite_result'] = $testSuiteResult;

if (is_array($flashResult) && isset($flashResult['http_code'])) {
    http_response_code((int) ($flashResult['http_code'] ?? 200));
}

require __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'debug_layout.php';
