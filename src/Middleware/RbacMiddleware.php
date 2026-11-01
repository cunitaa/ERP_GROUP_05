<?php
declare(strict_types=1);

namespace RantauMart\Middleware;

use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuditLogger;
use RantauMart\Services\AuthService;

/**
 * Middleware Pengatur Hak Akses (RBAC) & Penjaga Status Dokumen (F-01 & SR-18).
 *
 * Tanggung jawab utama:
 * 1. Memisahkan 5 role wajib (Sales, Warehouse, Procurement, Finance, Cashier).
 * 2. Memblokir akses ke modul atau aksi di luar wewenang role aktif.
 * 3. Mencegah perubahan status dokumen (state mutation) saat akses ditolak atau saat aksi di-replay (SR-18).
 * 4. Mencatat bukti audit secara persisten ke tabel `audit_logs` (aktor, waktu, aksi, old/new status, alasan).
 */
class RbacMiddleware
{
    /**
     * Memeriksa hak akses ke suatu modul pada tingkat navigasi / endpoint modul.
     */
    public static function authorizeModule(
        string $module,
        ?string $documentNumber = null,
        ?string $currentDocumentStatus = null
    ): array {
        $user  = AuthService::getCurrentUser();
        $role  = $user['role'];
        $actor = "{$user['full_name']} ({$user['username']})";

        if (!RolePermissions::canAccessModule($role, $module)) {
            $reason = sprintf(
                "F-01 & SR-18 Violation: Role '%s' tidak memiliki wewenang mengakses modul '%s'. Status dokumen tetap '%s' (tidak berubah).",
                $role,
                $module,
                $currentDocumentStatus ?? 'N/A'
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: $module,
                action: "module.access:{$module}",
                documentNumber: $documentNumber,
                oldStatus: $currentDocumentStatus,
                newStatus: $currentDocumentStatus, // Status dokumen tidak berubah
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'actor'          => $actor,
                'role'           => $role,
                'module'         => $module,
                'action'         => "module.access:{$module}",
                'document'       => $documentNumber,
                'old_status'     => $currentDocumentStatus,
                'new_status'     => $currentDocumentStatus,
                'status_changed' => false,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'actor'          => $actor,
            'role'           => $role,
            'module'         => $module,
            'action'         => "module.access:{$module}",
            'document'       => $documentNumber,
            'old_status'     => $currentDocumentStatus,
            'new_status'     => $currentDocumentStatus,
            'status_changed' => false,
            'reason'         => "Akses modul '{$module}' diizinkan untuk role '{$role}'.",
            'audit_id'       => null,
        ];
    }

    /**
     * Memeriksa hak akses untuk aksi spesifik pada dokumen dan mengeksekusi mutasi state
     * HANYA jika role berwenang dan aksi bukan replay duplikat (SR-18).
     *
     * @param string        $action                Kode aksi (mis. 'accounting.vendor_bill.post', 'pos.session.close')
     * @param string|null   $documentNumber        Nomor dokumen target (mis. 'BILL-0001', 'POS-SESSION-0001', 'C001')
     * @param string|null   $currentDocumentStatus Status dokumen saat ini sebelum aksi (mis. 'Draft', 'Open')
     * @param string|null   $targetNewStatus       Status dokumen yang diinginkan jika aksi diizinkan (mis. 'Posted', 'Closed')
     * @param callable|null $stateMutationCallback Callback yang mengubah database HANYA jika diizinkan
     */
    public static function guardDocumentAction(
        string $action,
        ?string $documentNumber = null,
        ?string $currentDocumentStatus = null,
        ?string $targetNewStatus = null,
        ?callable $stateMutationCallback = null
    ): array {
        $user       = AuthService::getCurrentUser();
        $role       = $user['role'];
        $actor      = "{$user['full_name']} ({$user['username']})";
        $actionMeta = RolePermissions::getActionMeta($action);
        $module     = $actionMeta['module'];
        $label      = $actionMeta['label'];
        $allowedFor = implode(', ', $actionMeta['allowed_roles']);

        // 1. Cek apakah role aktif memiliki izin atas aksi ini (F-01 & SR-18)
        if (!RolePermissions::canPerformAction($role, $action)) {
            $reason = sprintf(
                "F-01 & SR-18 Access Denied: Role '%s' diblokir saat mencoba aksi '%s' (%s) pada dokumen '%s'. Wewenang aksi ini hanya untuk role [%s]. Perubahan status (%s -> %s) dicegah; status tetap '%s'.",
                $role,
                $label,
                $action,
                $documentNumber ?? '-',
                $allowedFor ?: 'Tidak ada',
                $currentDocumentStatus ?? 'N/A',
                $targetNewStatus ?? 'N/A',
                $currentDocumentStatus ?? 'N/A'
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: $module,
                action: $action,
                documentNumber: $documentNumber,
                oldStatus: $currentDocumentStatus,
                newStatus: $currentDocumentStatus, // Cegah perubahan status dokumen!
                outcome: 'DENIED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 403,
                'outcome'        => 'DENIED',
                'actor'          => $actor,
                'role'           => $role,
                'module'         => $module,
                'action'         => $action,
                'action_label'   => $label,
                'document'       => $documentNumber,
                'old_status'     => $currentDocumentStatus,
                'new_status'     => $currentDocumentStatus,
                'status_changed' => false,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        // 2. Cek Idempotensi / Anti-Replay sesuai SR-18:
        // "replay Confirm/Post/Close tidak boleh menciptakan stock move atau journal entry tambahan."
        if (
            $currentDocumentStatus !== null &&
            $targetNewStatus !== null &&
            $currentDocumentStatus === $targetNewStatus
        ) {
            $reason = sprintf(
                "SR-18 Idempotency Guard: Replay aksi '%s' (%s) pada dokumen '%s' ditolak karena dokumen sudah berstatus '%s'. Tidak ada stock move atau journal entry tambahan yang dibuat.",
                $label,
                $action,
                $documentNumber ?? '-',
                $currentDocumentStatus
            );

            $auditId = AuditLogger::log(
                actor: $actor,
                role: $role,
                module: $module,
                action: $action,
                documentNumber: $documentNumber,
                oldStatus: $currentDocumentStatus,
                newStatus: $currentDocumentStatus,
                outcome: 'REPLAY_BLOCKED',
                reason: $reason
            );

            return [
                'allowed'        => false,
                'http_code'      => 409,
                'outcome'        => 'REPLAY_BLOCKED',
                'actor'          => $actor,
                'role'           => $role,
                'module'         => $module,
                'action'         => $action,
                'action_label'   => $label,
                'document'       => $documentNumber,
                'old_status'     => $currentDocumentStatus,
                'new_status'     => $currentDocumentStatus,
                'status_changed' => false,
                'reason'         => $reason,
                'audit_id'       => $auditId,
            ];
        }

        // 3. Eksekusi perubahan state dokumen karena role sah dan bukan replay
        $effectiveNewStatus = $targetNewStatus ?? $currentDocumentStatus;
        $callbackData = null;
        if ($stateMutationCallback !== null) {
            $callbackResult = $stateMutationCallback();
            if (is_array($callbackResult) && isset($callbackResult['new_status'])) {
                $effectiveNewStatus = $callbackResult['new_status'];
            }
            $callbackData = $callbackResult;
        }

        $reason = sprintf(
            "F-01 & SR-18 Authorized: Role '%s' berhasil menjalankan '%s' (%s) pada dokumen '%s' (%s -> %s).",
            $role,
            $label,
            $action,
            $documentNumber ?? '-',
            $currentDocumentStatus ?? 'N/A',
            $effectiveNewStatus ?? 'N/A'
        );

        $auditId = AuditLogger::log(
            actor: $actor,
            role: $role,
            module: $module,
            action: $action,
            documentNumber: $documentNumber,
            oldStatus: $currentDocumentStatus,
            newStatus: $effectiveNewStatus,
            outcome: 'ALLOWED',
            reason: $reason
        );

        return [
            'allowed'        => true,
            'http_code'      => 200,
            'outcome'        => 'ALLOWED',
            'actor'          => $actor,
            'role'           => $role,
            'module'         => $module,
            'action'         => $action,
            'action_label'   => $label,
            'document'       => $documentNumber,
            'old_status'     => $currentDocumentStatus,
            'new_status'     => $effectiveNewStatus,
            'status_changed' => ($currentDocumentStatus !== $effectiveNewStatus),
            'reason'         => $reason,
            'audit_id'       => $auditId,
            'data'           => $callbackData,
        ];
    }
}

