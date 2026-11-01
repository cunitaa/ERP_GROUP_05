<?php
declare(strict_types=1);

/**
 * Entrypoint Controller Dasbor Analitik & Laporan Eksekutif: controllers/ReportController.php
 * Memuat implementasi RantauMart\Controllers\ReportController
 * untuk menangani metrik KPI real-time Budi Haryanto (Direktur Operasional) & Arif Firmansyah (Finance)
 * serta menyediakan alias class global `ReportController`.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ReportController.php';

if (!class_exists('ReportController', false)) {
    class_alias(\RantauMart\Controllers\ReportController::class, 'ReportController');
}

