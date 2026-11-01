<?php
declare(strict_types=1);

/**
 * Entry file controllers/SalesController.php
 * Memuat RantauMart\Controllers\SalesController dan menyediakan class_alias global SalesController.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'SalesController.php';

if (!class_exists('SalesController', false)) {
    class_alias(\RantauMart\Controllers\SalesController::class, 'SalesController');
}

