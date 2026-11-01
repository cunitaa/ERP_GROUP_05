<?php
declare(strict_types=1);

/**
 * Entrypoint Controller Pemesanan Vendor (Procurement): controllers/PurchaseController.php
 * Memuat implementasi RantauMart\Controllers\PurchaseController (Fitur F-12 & Aturan SR-20, SR-18, SR-22)
 * serta menyediakan alias class global `PurchaseController`.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'PurchaseController.php';

if (!class_exists('PurchaseController', false)) {
    class_alias(\RantauMart\Controllers\PurchaseController::class, 'PurchaseController');
}
