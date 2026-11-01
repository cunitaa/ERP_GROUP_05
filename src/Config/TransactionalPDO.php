<?php
declare(strict_types=1);

namespace RantauMart\Config;

use PDO;
use RantauMart\Services\FlashService;

/**
 * PDO dengan hook Flash Toast: toast ditahan selama transaksi dan baru terbit
 * saat commit(); dibuang bila rollBack() sehingga tidak ada notifikasi palsu.
 */
class TransactionalPDO extends PDO
{
    public function commit(): bool
    {
        $ok = parent::commit();
        if ($ok) {
            FlashService::flushStaged();
        }
        return $ok;
    }

    public function rollBack(): bool
    {
        FlashService::discardStaged();
        return parent::rollBack();
    }
}

