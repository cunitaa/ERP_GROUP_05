<?php
declare(strict_types=1);

/**
 * Partial: Dasbor Eksekutif (SR-19) — hanya dirender untuk Direktur (Budi Haryanto) & Finance (Arif Firmansyah).
 * Variabel: $vm['exec_dashboard'] (DashboardController::buildViewModel), $activeView.
 */
$exec = $vm['exec_dashboard'] ?? null;
if (!is_array($exec)) {
    return;
}

$m        = (array) $exec['metrics'];
$drill    = $exec['drill'] ?? null;
$drillKey = $exec['drill_key'] ?? null;
$rp       = static fn (float $n): string => 'Rp' . number_format($n, 0, ',', '.');
$n        = static fn (int $n): string => number_format($n, 0, ',', '.');
$recon    = (array) ($m['reconciliation'] ?? []);

$execCards = [
    ['key' => 'stock',     'icon' => 'bi-boxes',               'tone' => 'primary', 'label' => 'Total Stok (On Hand)',
     'value' => $n((int) $m['stock']['on_hand']) . ' pcs',
     'sub'   => 'Reserved ' . $n((int) $m['stock']['reserved']) . ' · Available ' . $n((int) $m['stock']['available'])],
    ['key' => 'ar',        'icon' => 'bi-receipt-cutoff',      'tone' => 'warning', 'label' => 'Saldo AR (Piutang Aktif)',
     'value' => $rp((float) $m['ar']),   'sub' => 'Customer Invoice belum lunas'],
    ['key' => 'ap',        'icon' => 'bi-wallet2',             'tone' => 'danger',  'label' => 'Saldo AP (Utang Aktif)',
     'value' => $rp((float) $m['ap']),   'sub' => 'Vendor Bill belum lunas'],
    ['key' => 'bank',      'icon' => 'bi-bank',                'tone' => 'success', 'label' => 'Saldo Bank',
     'value' => $rp((float) $m['bank']), 'sub' => 'Akun 1010 (buku besar)'],
    ['key' => 'cash',      'icon' => 'bi-cash-stack',          'tone' => 'success', 'label' => 'Cash Drawer POS',
     'value' => $rp((float) $m['cash']), 'sub' => 'Uang fisik kasir · Akun 1020'],
    ['key' => 'pos_sales', 'icon' => 'bi-shop',                'tone' => 'info',    'label' => 'Pendapatan POS Sales',
     'value' => $rp((float) $m['pos_sales']), 'sub' => 'Akun 4020'],
    ['key' => 'cogs',      'icon' => 'bi-graph-down-arrow',    'tone' => 'secondary', 'label' => 'COGS',
     'value' => $rp((float) $m['cogs']), 'sub' => 'Harga Pokok Penjualan · Akun 5010'],
];
?>
<section class="o-section-box bg-white rounded-4 shadow-sm" id="exec-dashboard" data-testid="exec-dashboard">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
        <div>
            <span class="text-uppercase small fw-bold text-secondary d-block" style="letter-spacing: 0.06em;">
                Dasbor Eksekutif (SR-19)
            </span>
            <span class="text-muted small">Dihitung real-time dari tabel transaksi. Klik kartu untuk melihat rincian sumber angka.</span>
        </div>
        <span class="badge bg-dark rounded-pill px-3 py-2"><?= htmlspecialchars((string) ($role ?? '')) ?> · Akses Agregat Finansial</span>
    </div>

    <div class="row g-3">
        <?php foreach ($execCards as $c):
            $active = ($drillKey === $c['key']);
            $href   = 'index.php?drill=' . urlencode($c['key']) . '&view=' . urlencode((string) ($activeView ?? 'list')) . '#exec-drill';
        ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <a href="<?= htmlspecialchars($href) ?>" class="text-decoration-none d-block h-100" data-drill="<?= htmlspecialchars($c['key']) ?>">
                    <div class="card h-100 bg-white rounded-4 shadow-sm border-0 o-exec-card text-center <?= $active ? 'border border-2 border-' . $c['tone'] : '' ?>">
                        <div class="card-body py-4">
                            <div class="mx-auto mb-2 d-flex align-items-center justify-content-center rounded-circle bg-<?= $c['tone'] ?> bg-opacity-10 text-<?= $c['tone'] ?>" style="width:64px;height:64px;">
                                <i class="bi <?= $c['icon'] ?> fs-1"></i>
                            </div>
                            <div class="text-secondary small fw-semibold"><?= htmlspecialchars($c['label']) ?></div>
                            <div class="display-6 fw-bold text-dark my-1" style="font-size:1.75rem;"><?= htmlspecialchars($c['value']) ?></div>
                            <div class="text-muted" style="font-size:0.78rem;"><?= htmlspecialchars($c['sub']) ?></div>
                            <div class="mt-2 small text-<?= $c['tone'] ?> fw-semibold">Lihat rincian <i class="bi bi-arrow-right"></i></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (is_array($drill)): ?>
        <div id="exec-drill" class="mt-4 pt-3 border-top" data-testid="exec-drill">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h6 class="fw-bold mb-0"><?= htmlspecialchars((string) $drill['title']) ?></h6>
                    <span class="text-muted small"><?= htmlspecialchars((string) $drill['subtitle']) ?></span>
                </div>
                <a href="index.php?view=<?= urlencode((string) ($activeView ?? 'list')) ?>#exec-dashboard" class="btn btn-sm btn-outline-secondary rounded-pill">Tutup rincian</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><?php foreach ((array) $drill['columns'] as $col): ?><th><?= htmlspecialchars((string) $col) ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($drill['rows'])): ?>
                            <tr><td colspan="<?= count((array) $drill['columns']) ?>" class="text-center text-muted py-3">Belum ada transaksi.</td></tr>
                        <?php endif; ?>
                        <?php foreach ((array) $drill['rows'] as $row): ?>
                            <tr><?php foreach ((array) $row as $cell): ?><td><?= htmlspecialchars((string) $cell) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($drill['total'] !== null): ?>
                        <tfoot>
                            <tr class="fw-bold table-light">
                                <td colspan="<?= max(1, count((array) $drill['columns']) - 1) ?>" class="text-end"><?= htmlspecialchars((string) $drill['total_label']) ?></td>
                                <td><?= in_array($drillKey, ['stock'], true) ? $n((int) $drill['total']) . ' pcs' : $rp((float) $drill['total']) ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if (in_array($drillKey, ['ar', 'ap'], true)): ?>
                <?php
                $diff = (float) ($recon[$drillKey . '_difference'] ?? 0);
                ?>
                <div class="small mt-2 <?= abs($diff) < 0.5 ? 'text-success' : 'text-danger' ?>">
                    <?= abs($diff) < 0.5
                        ? 'Rekonsiliasi: total rincian dokumen = saldo buku besar (' . $rp((float) $recon[$drillKey . '_ledger']) . ').'
                        : 'Selisih dengan buku besar: ' . $rp($diff) . ' (periksa jurnal manual).' ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

