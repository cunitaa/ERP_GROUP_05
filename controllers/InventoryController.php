<?php
declare(strict_types=1);

/**
 * Entrypoint Controller Mutasi Stok & Gudang (Inventory): controllers/InventoryController.php
 * Memuat implementasi RantauMart\Controllers\InventoryController
 * (Fitur F-09, F-10, F-13, F-21 & Aturan SR-04, SR-09, SR-10, SR-17, SR-18, SR-21)
 * serta menyediakan alias class global `InventoryController`.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'InventoryController.php';

if (!class_exists('InventoryController', false)) {
    class_alias(\RantauMart\Controllers\InventoryController::class, 'InventoryController');
}
