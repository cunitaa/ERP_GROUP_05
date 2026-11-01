<?php
declare(strict_types=1);

/**
 * Entrypoint Server-Level Route & Endpoint Security Guard: Middleware.php
 * Memuat implementasi RantauMart\Middleware\Middleware & RantauMart\Middleware\RbacMiddleware
 * (Fitur F-01 & Aturan SR-18: HTTP 403 Forbidden + Pencatatan Otomatis ke audit_logs)
 * serta menyediakan alias class global `Middleware` dan `RbacMiddleware`.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR . 'RbacMiddleware.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR . 'Middleware.php';

if (!class_exists('Middleware', false)) {
    class_alias(\RantauMart\Middleware\Middleware::class, 'Middleware');
}

if (!class_exists('RbacMiddleware', false)) {
    class_alias(\RantauMart\Middleware\RbacMiddleware::class, 'RbacMiddleware');
}

