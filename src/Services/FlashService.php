<?php
declare(strict_types=1);

namespace RantauMart\Services;

/**
 * FlashService — Antrean notifikasi Toast (Flash Session PHP) untuk Bootstrap 5.
 *
 * - `stage()`  : menampung toast sementara selama transaksi PDO berjalan.
 * - `flushStaged()` : dipanggil otomatis saat `commit()` (lihat TransactionalPDO) -> toast dipublikasikan ke $_SESSION.
 * - `discardStaged()` : dipanggil otomatis saat `rollBack()` -> toast dibuang (tidak ada notifikasi palsu).
 * - `pull()`   : diambil layout.php untuk dirender sebagai Toast hijau di pojok kanan atas, lalu dikosongkan.
 */
class FlashService
{
    public const SESSION_KEY = 'flash_toasts';

    /** @var array<int, array<string, string>> */
    private static array $staged = [];

    /** Publikasikan langsung ke Flash Session. */
    public static function push(string $message, string $type = 'success', string $title = 'Berhasil'): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION[self::SESSION_KEY][] = [
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
        ];
    }

    /** Tampung dulu; terbit setelah commit, hilang saat rollback. */
    public static function stage(string $message, string $type = 'success', string $title = 'Berhasil'): void
    {
        self::$staged[] = ['type' => $type, 'title' => $title, 'message' => $message];
    }

    public static function flushStaged(): void
    {
        foreach (self::$staged as $t) {
            self::push($t['message'], $t['type'], $t['title']);
        }
        self::$staged = [];
    }

    public static function discardStaged(): void
    {
        self::$staged = [];
    }

    /** Ambil & kosongkan seluruh toast tertunda. */
    public static function pull(): array
    {
        $items = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return is_array($items) ? $items : [];
    }

    /** Lihat tanpa mengosongkan (untuk pengujian). */
    public static function peek(): array
    {
        return is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
    }

    /**
     * Pesan toast wajib per aksi dokumen (hanya untuk langkah yang berhasil / ALLOWED).
     */
    public static function messageForAction(string $action, ?string $doc): ?array
    {
        $d = ltrim((string) $doc, '#');
        $map = [
            'sales.order.create'          => ["Sales Order #{$d} dibuat (Draft).", 'Berhasil'],
            'sales.order.confirm'         => ["Berhasil: Sales Order #{$d} dikonfirmasi, stok telah dicadangkan otomatis!", 'Sales Order Dikonfirmasi'],
            'inventory.delivery.validate' => ["Berhasil: Delivery Order #{$d} divalidasi, stok fisik berkurang & Jurnal COGS diposting!", 'Pengiriman Divalidasi'],
            'inventory.receipt.validate'  => ["Berhasil: Penerimaan #{$d} divalidasi, hanya qty Accepted menambah stok gudang & jurnal Stock Interim diposting!", 'Penerimaan Barang Divalidasi'],
            'inventory.transfer.validate' => ["Berhasil: Internal Transfer #{$d} selesai, stok fisik dipindahkan tanpa mengubah nilai akuntansi!", 'Transfer Selesai'],
            'purchase.order.create'       => ["RFQ #{$d} dibuat.", 'Berhasil'],
            'purchase.order.confirm'      => ["Berhasil: RFQ dikonfirmasi menjadi Purchase Order #{$d}!", 'Purchase Order Dikonfirmasi'],
            'accounting.customer_invoice.draft' => ["Draf Customer Invoice #{$d} dibuat otomatis dari pengiriman, siap diposting Finance!", 'Draf Invoice Dibuat'],
            'accounting.vendor_bill.draft' => ["Draf Vendor Bill #{$d} dibuat otomatis dari qty Accepted, siap direkonsiliasi Finance!", 'Draf Vendor Bill Dibuat'],
            'accounting.customer_invoice.post' => ["Berhasil: Customer Invoice #{$d} diposting, jurnal Piutang & Pendapatan seimbang!", 'Invoice Diposting'],
            'accounting.vendor_bill.post' => ["Berhasil: Vendor Bill #{$d} lolos Three-Way Match & diposting, jurnal Utang seimbang!", 'Vendor Bill Diposting'],
            'accounting.payment.register' => ["Berhasil: Pembayaran #{$d} direkonsiliasi, jurnal Bank seimbang!", 'Pembayaran Tercatat'],
            'accounting.return.credit_note' => ["Berhasil: Credit Note retur #{$d} diterbitkan, stok & jurnal pengembalian disesuaikan otomatis!", 'Retur Diproses'],
            'pos.session.open'            => ["Sesi Kasir #{$d} dibuka.", 'Sesi Kasir Dibuka'],
            'pos.basket.checkout'         => ["Berhasil: Struk POS #{$d} Paid, stok toko terpotong & Jurnal COGS diposting!", 'Transaksi POS Paid'],
            'pos.session.close'           => ["Sesi Kasir #{$d} Ditutup: Jurnal COGS berhasil diposting!", 'Sesi Kasir Ditutup'],
            'pos.return.refund'           => ["Berhasil: Refund POS #{$d} diproses, stok toko dipulihkan & jurnal pengembalian dana disesuaikan!", 'Refund POS'],
        ];
        if (!isset($map[$action])) {
            return null;
        }
        return ['message' => $map[$action][0], 'title' => $map[$action][1]];
    }
}
