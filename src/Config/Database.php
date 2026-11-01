<?php
declare(strict_types=1);

namespace RantauMart\Config;

use PDO;
use PDOException;

/**
 * Koneksi Database PDO (PostgreSQL utama + fallback SQLite persisten otomatis untuk pengujian lokal).
 */
class Database
{
    private static ?PDO $instance = null;
    private static string $activeDriver = 'pgsql';

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = getenv('DB_DRIVER') ?: 'pgsql';
        $host   = getenv('DB_HOST') ?: '127.0.0.1';
        $port   = getenv('DB_PORT') ?: '5432';
        $dbname = getenv('DB_NAME') ?: 'rantaumart';
        $user   = getenv('DB_USER') ?: 'postgres';
        $pass   = getenv('DB_PASS') ?: '';

        if ($driver === 'pgsql' && extension_loaded('pdo_pgsql')) {
            try {
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                self::$instance = new TransactionalPDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$activeDriver = 'pgsql';
                self::ensureInitialized();
                return self::$instance;
            } catch (PDOException $e) {
                // Fallback ke penyimpanan persisten lokal jika server PostgreSQL belum menyala
            }
        }

        $storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $sqlitePath = $storageDir . DIRECTORY_SEPARATOR . 'rantaumart.sqlite';
        self::$instance = new TransactionalPDO("sqlite:{$sqlitePath}", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$instance->exec('PRAGMA foreign_keys = ON;');
        self::$activeDriver = 'sqlite';
        self::ensureInitialized();

        return self::$instance;
    }

    public static function getActiveDriver(): string
    {
        self::getConnection();
        return self::$activeDriver;
    }

    private static function ensureInitialized(): void
    {
        $pdo = self::$instance;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM app_users");
            if ((int) $stmt->fetchColumn() >= 5) {
                return;
            }
        } catch (PDOException $e) {
            // Tabel belum ada, jalankan inisialisasi schema & seed
        }

        self::resetBaselineSeed();
    }

    /**
     * Memulihkan kondisi awal (Baseline Seed) sesuai aturan Fixture T-xx.
     */
    public static function resetBaselineSeed(): void
    {
        $pdo = self::getConnection();
        $rootDir = dirname(__DIR__, 2);
        $schemaSql = file_get_contents($rootDir . DIRECTORY_SEPARATOR . 'schema.sql');
        $seedSql   = file_get_contents($rootDir . DIRECTORY_SEPARATOR . 'seed.sql');

        if (self::$activeDriver === 'pgsql') {
            $pdo->exec($schemaSql);
            $pdo->exec($seedSql);
            return;
        }

        // Adaptasi otomatis DDL & DML PostgreSQL ke SQLite untuk fallback persisten lokal
        $pdo->exec('PRAGMA foreign_keys = OFF;');

        $sqliteSchema = preg_replace('/DROP TABLE IF EXISTS ([a-z_]+) CASCADE;/i', 'DROP TABLE IF EXISTS $1;', $schemaSql);
        $sqliteSchema = preg_replace('/\bSERIAL PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSchema);
        $sqliteSchema = preg_replace('/\sTIMESTAMPTZ\b/i', ' TEXT', $sqliteSchema);
        $sqliteSchema = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sqliteSchema);
        $pdo->exec($sqliteSchema);

        // Hapus blok DO $$ ... $$ dan TRUNCATE CASCADE khusus PostgreSQL saat dieksekusi di SQLite
        $sqliteSeed = preg_replace('/TRUNCATE TABLE.*?RESTART IDENTITY CASCADE;/is', '', $seedSql);
        $sqliteSeed = preg_replace('/DO \$\$.*?\$\$;/is', '', $sqliteSeed);
        $pdo->exec($sqliteSeed);

        $pdo->exec('PRAGMA foreign_keys = ON;');
    }
}
