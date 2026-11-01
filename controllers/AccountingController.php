<?php
declare(strict_types=1);

/**
 * Entrypoint Controller Akuntansi, Penagihan & Governance: controllers/AccountingController.php
 * Memuat implementasi RantauMart\Controllers\AccountingController
 * (Fitur F-11, F-14, F-15, F-16, F-20, F-22, F-23 & Aturan SR-02, SR-06, SR-15, SR-16, SR-18, SR-19, SR-21, SR-22)
 * serta menyediakan alias class global `AccountingController`.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AccountingController.php';

if (!class_exists('AccountingController', false)) {
    class_alias(\RantauMart\Controllers\AccountingController::class, 'AccountingController');
}

