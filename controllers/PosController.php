<?php
declare(strict_types=1);

/**
 * Entrypoint Controller Point of Sale (POS): controllers/PosController.php
 * Memuat implementasi RantauMart\Controllers\PosController
 * (Fitur F-17, F-18, F-19, F-20 & Aturan SR-03, SR-04, SR-11, SR-12, SR-13, SR-14, SR-16, SR-18, SR-21)
 * serta menyediakan alias class global `PosController`.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'PosController.php';

if (!class_exists('PosController', false)) {
    class_alias(\RantauMart\Controllers\PosController::class, 'PosController');
}

