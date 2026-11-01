<?php
declare(strict_types=1);

namespace RantauMart\Services;

use RantauMart\Config\Database;

/**
 * AtomicRunner — pembungkus transaksi PDO ACID untuk fungsi lintas-modul.
 *
 * - Memulai transaksi bila belum ada (panggilan bersarang bergabung ke transaksi terluar).
 * - Commit bila selesai; penolakan validasi (audit log SR-18 / status 'Pending Approval') ikut ter-commit
 *   karena memang harus tercatat. Kegagalan di tengah rantai integrasi (exception, atau core memanggil
 *   rollBack() sendiri) membatalkan SELURUH operasi.
 */
class AtomicRunner
{
    public static function run(callable $fn): array
    {
        $pdo       = Database::getConnection();
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }

        try {
            $result = $fn();
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

