<?php
declare(strict_types=1);

/**
 * PT RantauMart Riau — Entry Point / Alias Arsitektur Service & Event Trigger Lintas Modul
 * Menghubungkan 4 jalur integrasi mutlak (Sales->Inventory, Inventory->Accounting,
 * Purchase/Inventory->Accounting Three-Way Match, dan POS->Inventory & Accounting).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'IntegrationEventTrigger.php';

if (!class_exists('IntegrationEventTrigger', false)) {
    class_alias(\RantauMart\Services\IntegrationEventTrigger::class, 'IntegrationEventTrigger');
}

