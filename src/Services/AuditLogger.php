<?php
declare(strict_types=1);

namespace RantauMart\Services;

use RantauMart\Config\Database;

/**
 * Layanan Pencatatan Bukti Audit Persisten sesuai Aturan SR-18 & Fitur F-01 / F-23.
 * Menyimpan: aktor, waktu (created_at), role, modul, aksi, nomor dokumen,
 * old_status, new_status, outcome (ALLOWED / DENIED / REPLAY_BLOCKED), dan alasan.
 */
class AuditLogger
{
    public static function log(
        string $actor,
        string $role,
        string $module,
        string $action,
        ?string $documentNumber,
        ?string $oldStatus,
        ?string $newStatus,
        string $outcome,
        string $reason
    ): int {
        $pdo = Database::getConnection();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:sP');

        $currentUser = AuthService::getCurrentUser();
        $userId      = isset($currentUser['user_id']) && $currentUser['user_id'] !== ''
            ? (string) $currentUser['user_id']
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (
                created_at, user_id, actor, role, module, action,
                document_number, old_status, new_status, outcome, reason
            ) VALUES (
                :created_at, :user_id, :actor, :role, :module, :action,
                :document_number, :old_status, :new_status, :outcome, :reason
            )'
        );

        $stmt->execute([
            ':created_at'      => $timestamp,
            ':user_id'         => $userId,
            ':actor'           => $actor,
            ':role'            => $role,
            ':module'          => $module,
            ':action'          => $action,
            ':document_number' => $documentNumber,
            ':old_status'      => $oldStatus,
            ':new_status'      => $newStatus,
            ':outcome'         => $outcome,
            ':reason'          => $reason,
        ]);

        $auditId = (int) $pdo->lastInsertId();

        // Smart Step-by-Step Notification: setiap langkah dokumen yang berhasil (ALLOWED) memunculkan Toast hijau.
        // Di dalam transaksi PDO toast ditahan hingga commit() dan dibuang bila rollBack().
        if ($outcome === 'ALLOWED') {
            $toast = FlashService::messageForAction($action, $documentNumber);
            if ($toast !== null) {
                if ($pdo->inTransaction()) {
                    FlashService::stage($toast['message'], 'success', $toast['title']);
                } else {
                    FlashService::push($toast['message'], 'success', $toast['title']);
                }
            }
        }

        return $auditId;
    }

    /**
     * Ambil daftar riwayat audit terbaru secara persisten dari database.
     */
    public static function getRecentLogs(int $limit = 50, ?string $outcomeFilter = null): array
    {
        $pdo = Database::getConnection();
        if ($outcomeFilter !== null && $outcomeFilter !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, created_at, actor, role, module, action,
                        document_number, old_status, new_status, outcome, reason
                 FROM audit_logs
                 WHERE outcome = :outcome
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':outcome', $outcomeFilter);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare(
            'SELECT id, created_at, actor, role, module, action,
                    document_number, old_status, new_status, outcome, reason
             FROM audit_logs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Mencatat perubahan status dokumen penting (mis. Draft -> Confirmed / Posted / Closed) sesuai SR-18.
     */
    public static function logStatusTransition(
        string $actor,
        string $role,
        string $module,
        string $action,
        string $documentNumber,
        string $oldStatus,
        string $newStatus,
        string $reason
    ): int {
        return self::log($actor, $role, $module, $action, $documentNumber, $oldStatus, $newStatus, 'ALLOWED', $reason);
    }

    /**
     * Mencatat percobaan akses modul atau aksi yang diblokir oleh peran (SR-18 & F-01).
     */
    public static function logAccessDenied(
        string $actor,
        string $role,
        string $module,
        string $action,
        ?string $documentNumber,
        ?string $currentStatus,
        string $reason
    ): int {
        return self::log($actor, $role, $module, $action, $documentNumber, $currentStatus, $currentStatus, 'DENIED', $reason);
    }
}

