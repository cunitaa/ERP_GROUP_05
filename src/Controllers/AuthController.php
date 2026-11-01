<?php
declare(strict_types=1);

namespace RantauMart\Controllers;

use RantauMart\Config\RolePermissions;
use RantauMart\Services\AuthService;

/**
 * Controller untuk Dummy Login & Pergantian Role (Fitur F-01).
 */
class AuthController
{
    public static function handleLogin(array $request): array
    {
        $roleOrUser = trim((string) ($request['role'] ?? $request['username'] ?? ''));
        $password   = isset($request['password']) ? (string) $request['password'] : null;

        $user = AuthService::login($roleOrUser, $password);

        if ($user === null) {
            return [
                'success'   => false,
                'http_code' => 401,
                'message'   => "Autentikasi gagal: Role/akun '{$roleOrUser}' tidak valid atau kredensial salah. Hanya role pemangku kepentingan terdaftar yang diizinkan (Sales, Warehouse, Procurement, Finance, Cashier, Owner).",
            ];
        }

        return [
            'success'         => true,
            'http_code'       => 200,
            'message'         => "Berhasil login sebagai {$user['role']} — {$user['full_name']} ({$user['username']}).",
            'user'            => $user,
            'visible_modules' => RolePermissions::getVisibleModulesForRole($user['role']),
        ];
    }

    public static function handleLogout(): array
    {
        AuthService::logout();
        return [
            'success'   => true,
            'http_code' => 200,
            'message'   => 'Berhasil keluar dari sesi.',
        ];
    }
}

