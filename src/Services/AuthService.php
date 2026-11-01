<?php
declare(strict_types=1);

namespace RantauMart\Services;

use RantauMart\Config\Database;
use RantauMart\Config\RolePermissions;

/**
 * Sistem Autentikasi Sederhana (Dummy Login) untuk 5 Role Wajib (F-01 & SR-18):
 * 1. Sales       (Putri Aulia)
 * 2. Warehouse   (Deni Pratama)
 * 3. Procurement (Mira Salsabila)
 * 4. Finance     (Arif Firmansyah)
 * 5. Cashier     (Rani Oktaviani)
 */
class AuthService
{
    private static ?array $cliOverrideUser = null;

    public static function ensureSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    /**
     * Mengambil semua akun uji (5 role wajib) dari tabel app_users.
     */
    public static function getAllUsers(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            'SELECT user_id, username, password_plain, full_name, role, assigned_location
             FROM app_users
             ORDER BY user_id ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Dummy Login menggunakan username (mis. "sales") atau nama role (mis. "Sales").
     */
    public static function login(string $identifier, ?string $password = null): ?array
    {
        self::ensureSession();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT user_id, username, password_plain, full_name, role, assigned_location
             FROM app_users
             WHERE LOWER(username) = LOWER(:id) OR LOWER(role) = LOWER(:id)
             LIMIT 1'
        );
        $stmt->execute([':id' => trim($identifier)]);
        $user = $stmt->fetch();

        if (!$user || !RolePermissions::isValidRole($user['role'])) {
            AuditLogger::log(
                actor: $identifier !== '' ? $identifier : 'anonymous',
                role: 'Unauthenticated',
                module: 'Apps',
                action: 'auth.login',
                documentNumber: null,
                oldStatus: 'LoggedOut',
                newStatus: 'LoggedOut',
                outcome: 'DENIED',
                reason: "F-01 / SR-18: Gagal login — role atau akun '{$identifier}' tidak terdaftar dalam 5 role wajib."
            );
            return null;
        }

        if ($password !== null && $password !== '' && $password !== $user['password_plain']) {
            AuditLogger::log(
                actor: "{$user['full_name']} ({$user['username']})",
                role: $user['role'],
                module: 'Apps',
                action: 'auth.login',
                documentNumber: null,
                oldStatus: 'LoggedOut',
                newStatus: 'LoggedOut',
                outcome: 'DENIED',
                reason: "F-01 / SR-18: Kredensial password salah untuk akun '{$user['username']}'."
            );
            return null;
        }

        if (PHP_SAPI === 'cli') {
            self::$cliOverrideUser = $user;
        } else {
            $_SESSION['auth_user'] = $user;
        }

        AuditLogger::log(
            actor: "{$user['full_name']} ({$user['username']})",
            role: $user['role'],
            module: 'Apps',
            action: 'auth.login',
            documentNumber: null,
            oldStatus: 'LoggedOut',
            newStatus: 'Authenticated:' . $user['role'],
            outcome: 'ALLOWED',
            reason: "F-01 / SR-18: Dummy login berhasil sebagai role '{$user['role']}' ({$user['full_name']})."
        );

        return $user;
    }

    /**
     * Mengambil pengguna yang sedang aktif. Default ke akun Sales dari seed (sesuai T-01) jika belum dipilih.
     */
    public static function getCurrentUser(): array
    {
        self::ensureSession();

        if (PHP_SAPI === 'cli' && self::$cliOverrideUser !== null) {
            return self::$cliOverrideUser;
        }

        if (!empty($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
            return $_SESSION['auth_user'];
        }

        // Default baseline: Sales dari seed (T-01: Login sebagai Sales dari seed; buka Home Apps)
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT user_id, username, password_plain, full_name, role, assigned_location
             FROM app_users
             WHERE role = 'Sales'
             LIMIT 1"
        );
        $stmt->execute();
        $defaultUser = $stmt->fetch();

        if ($defaultUser) {
            $_SESSION['auth_user'] = $defaultUser;
            return $defaultUser;
        }

        return [
            'user_id'           => 'U01',
            'username'          => 'sales',
            'full_name'         => 'Putri Aulia',
            'role'              => 'Sales',
            'assigned_location' => 'WH-PKU',
        ];
    }

    public static function logout(): void
    {
        self::ensureSession();
        $user = self::getCurrentUser();
        AuditLogger::log(
            actor: "{$user['full_name']} ({$user['username']})",
            role: $user['role'],
            module: 'Apps',
            action: 'auth.logout',
            documentNumber: null,
            oldStatus: 'Authenticated:' . $user['role'],
            newStatus: 'LoggedOut',
            outcome: 'ALLOWED',
            reason: "F-01: Pengguna keluar dari sesi role '{$user['role']}'."
        );
        self::$cliOverrideUser = null;
        unset($_SESSION['auth_user']);
    }
}

