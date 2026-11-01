<?php
declare(strict_types=1);

/**
 * Halaman Debug & Pengujian Skenario Aturan Bisnis (src/Views/debug_layout.php)
 * Terpisah sepenuhnya dari beranda utama (index.php) agar beranda tetap bersih.
 * Menyediakan:
 * - Tombol pengujian skenario T-02, T-04, T-07/T-08, dan blokir modul F-01 / SR-18
 * - Tabel Status Dokumen Persisten
 * - Tabel Bukti Audit Persisten (audit_logs)
 * - Tombol Reset Baseline Seed (Fixture)
 */

$vm          = $vm ?? [];
$company     = (string) ($vm['company'] ?? 'PT RantauMart Riau');
$user        = (array) ($vm['current_user'] ?? []);
$userFullName= (string) ($user['full_name'] ?? 'Putri Aulia');
$role        = (string) ($user['role'] ?? 'Sales');
$allUsers    = (array) ($vm['all_users'] ?? []);
$modules     = (array) ($vm['modules'] ?? []);
$docStates   = (array) ($vm['document_states'] ?? []);
$auditLogs   = (array) ($vm['audit_logs'] ?? []);
$flash       = $vm['flash_result'] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug &amp; Uji Skenario SR-18 | <?= htmlspecialchars($company) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php if (is_array($flash)): ?>
    <?php
    $outcome    = (string) ($flash['outcome'] ?? 'INFO');
    $httpCode   = (int) ($flash['http_code'] ?? 200);
    $reasonText = (string) ($flash['reason'] ?? $flash['message'] ?? '');
    $isDenied   = ($outcome === 'DENIED' || ((bool) ($flash['allowed'] ?? true)) === false);
    $isReplay   = ($outcome === 'REPLAY_BLOCKED');
    $toastBg    = $isDenied ? 'text-bg-danger' : ($isReplay ? 'text-bg-warning' : 'text-bg-success');
    $toastTitle = $isDenied ? 'Akses Diblokir (SR-18 / F-01)' : ($isReplay ? 'Replay Diblokir (SR-18)' : 'Aksi Diizinkan');
    ?>
    <div class="toast-container o-toast-container" aria-live="polite" aria-atomic="true">
        <div id="debugFeedbackToast" class="toast show align-items-center <?= $toastBg ?> border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <span class="badge <?= $isDenied ? 'bg-danger' : ($isReplay ? 'bg-warning text-dark' : 'bg-success') ?> me-2">
                    HTTP <?= $httpCode ?>
                </span>
                <strong class="me-auto"><?= htmlspecialchars($toastTitle) ?></strong>
                <small class="text-muted">Audit SR-18</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <?= htmlspecialchars($reasonText) ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<nav class="navbar navbar-expand-lg navbar-dark o-main-navbar o-sticky-header">
    <div class="container-fluid px-3">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-light">← Kembali ke Beranda Utama</a>
            <span class="o-navbar-brand ms-2">🛠️ Debug Console &amp; Pengujian Skenario (F-01 / SR-18)</span>
        </div>

        <div class="d-flex align-items-center gap-2 ms-auto">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    👤 <strong><?= htmlspecialchars($userFullName) ?></strong> (<?= htmlspecialchars($role) ?>)
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li class="dropdown-header fw-bold text-uppercase small">Ganti Akun Uji</li>
                    <?php foreach ($allUsers as $u): ?>
                        <?php
                        $uRole = (string) ($u['role'] ?? '');
                        $uName = (string) ($u['full_name'] ?? '');
                        ?>
                        <li>
                            <form method="POST" action="debug.php" class="m-0">
                                <input type="hidden" name="intent" value="login">
                                <input type="hidden" name="role" value="<?= htmlspecialchars($uRole) ?>">
                                <button type="submit" class="dropdown-item <?= $uRole === $role ? 'active fw-bold' : '' ?>">
                                    <?= htmlspecialchars($uName) ?> (<?= htmlspecialchars($uRole) ?>)
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid p-4 d-flex flex-column gap-4">

    <?php if (is_array($flash)): ?>
        <?php
        $outcome    = (string) ($flash['outcome'] ?? 'INFO');
        $httpCode   = (int) ($flash['http_code'] ?? 200);
        $reasonText = (string) ($flash['reason'] ?? $flash['message'] ?? '');
        $docTarget  = (string) ($flash['document'] ?? '');
        $oldStat    = (string) ($flash['old_status'] ?? '-');
        $newStat    = (string) ($flash['new_status'] ?? '-');
        $changed    = (bool) ($flash['status_changed'] ?? false);

        $bsAlert = 'alert-success';
        if ($outcome === 'DENIED' || ((bool) ($flash['allowed'] ?? true)) === false) {
            $bsAlert = 'alert-danger';
        } elseif ($outcome === 'REPLAY_BLOCKED') {
            $bsAlert = 'alert-warning';
        }
        ?>
        <div class="alert <?= $bsAlert ?> shadow-sm mb-0">
            <div>
                <strong>[HTTP <?= $httpCode ?> · <?= htmlspecialchars($outcome) ?>]</strong>
                <?= htmlspecialchars($reasonText) ?>
            </div>
            <?php if ($docTarget !== ''): ?>
                <div class="mt-1 small border-top pt-1">
                    Dokumen: <code><?= htmlspecialchars($docTarget) ?></code> |
                    Status Sebelum: <code><?= htmlspecialchars($oldStat) ?></code> →
                    Status Sesudah: <code><?= htmlspecialchars($newStat) ?></code> |
                    Mutasi Database: <strong><?= $changed ? 'BERUBAH (SAH)' : 'DICEGAH OLEH MIDDLEWARE (TETAP)' ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Panel Aksi Uji Skenario T-01, T-02, T-04, T-08 & Reset Seed -->
    <div class="card border shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Simulator Pengujian Skenario (T-01, T-02, T-04, T-07, T-08 &amp; SR-18)</strong>
            <form method="POST" action="debug.php" class="m-0">
                <input type="hidden" name="intent" value="action">
                <input type="hidden" name="command" value="reset_seed">
                <button type="submit" class="btn btn-sm btn-outline-secondary fw-semibold">
                    ↺ Reset Baseline Seed (Fixture)
                </button>
            </form>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <form method="POST" action="debug.php" class="m-0">
                    <input type="hidden" name="intent" value="action">
                    <input type="hidden" name="command" value="post_vendor_bill">
                    <button type="submit" class="btn btn-sm btn-danger">
                        [Uji T-02] Post Vendor Bill (BILL-0001) — Hanya Finance
                    </button>
                </form>

                <form method="POST" action="debug.php" class="m-0">
                    <input type="hidden" name="intent" value="action">
                    <input type="hidden" name="command" value="close_pos_session">
                    <button type="submit" class="btn btn-sm btn-danger">
                        [Uji T-02] Tutup Sesi POS (POS-SESSION-0001) — Hanya Cashier/Finance
                    </button>
                </form>

                <form method="POST" action="debug.php" class="m-0">
                    <input type="hidden" name="intent" value="action">
                    <input type="hidden" name="command" value="update_credit_limit">
                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-semibold">
                        [Uji T-04] Ubah Credit Limit C001 — Hanya Sales/Finance
                    </button>
                </form>

                <form method="POST" action="debug.php" class="m-0">
                    <input type="hidden" name="intent" value="action">
                    <input type="hidden" name="command" value="convert_lead">
                    <button type="submit" class="btn btn-sm btn-dark">
                        [Uji T-07/T-08] Convert LEAD-0001 ke Opportunity — Hanya Sales
                    </button>
                </form>
            </div>

            <div class="border-top pt-3">
                <div class="small fw-bold text-secondary mb-2">Simulasi Akses Langsung ke Seluruh Modul (Termasuk yang Diblokir untuk Role <?= htmlspecialchars($role) ?>):</div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($modules as $modKey => $mod): ?>
                        <?php $accessible = (bool) ($mod['accessible'] ?? false); ?>
                        <form method="POST" action="debug.php" class="m-0">
                            <input type="hidden" name="intent" value="open_module">
                            <input type="hidden" name="module" value="<?= htmlspecialchars((string) $modKey) ?>">
                            <button type="submit" class="btn btn-sm <?= $accessible ? 'btn-outline-success' : 'btn-outline-danger' ?>">
                                <?= htmlspecialchars((string) ($mod['name'] ?? $modKey)) ?>
                                (<?= $accessible ? 'Berizin' : 'Uji Blokir 403' ?>)
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Status Dokumen Persisten -->
        <div class="col-12 col-xl-4">
            <div class="card border shadow-sm h-100">
                <div class="card-header bg-white fw-bold">Status Dokumen Persisten (Database)</div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Dokumen</th>
                                <th>Status Saat Ini</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>LEAD-0001</code></td>
                                <td><strong><?= htmlspecialchars((string) ($docStates['LEAD-0001']['status'] ?? '-')) ?></strong></td>
                            </tr>
                            <tr>
                                <td><code>S00007</code></td>
                                <td><strong><?= htmlspecialchars((string) ($docStates['S00007']['status'] ?? '-')) ?></strong></td>
                            </tr>
                            <tr>
                                <td><code>PO00007</code></td>
                                <td><strong><?= htmlspecialchars((string) ($docStates['PO00007']['status'] ?? '-')) ?></strong></td>
                            </tr>
                            <tr>
                                <td><code>BILL-0001</code></td>
                                <td><strong><?= htmlspecialchars((string) ($docStates['BILL-0001']['status'] ?? '-')) ?></strong></td>
                            </tr>
                            <tr>
                                <td><code>POS-SESSION-0001</code></td>
                                <td><strong><?= htmlspecialchars((string) ($docStates['POS-SESSION-0001']['status'] ?? '-')) ?></strong></td>
                            </tr>
                            <tr>
                                <td><code>C001 Credit Limit</code></td>
                                <td><strong>Rp<?= number_format((float) ($docStates['C001']['credit_limit'] ?? 500000), 0, ',', '.') ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tabel Bukti Audit Persisten (SR-18) -->
        <div class="col-12 col-xl-8">
            <div class="card border shadow-sm h-100">
                <div class="card-header bg-white fw-bold">Log Bukti Audit Persisten (SR-18 — audit_logs)</div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0" style="font-size: 0.78rem;">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>ID</th>
                                    <th>Waktu</th>
                                    <th>Aktor &amp; Role</th>
                                    <th>Aksi &amp; Dokumen</th>
                                    <th>Status</th>
                                    <th>Outcome</th>
                                    <th>Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                    <?php
                                    $out = (string) ($log['outcome'] ?? 'ALLOWED');
                                    $badgeCls = $out === 'DENIED' ? 'bg-danger' : ($out === 'REPLAY_BLOCKED' ? 'bg-warning text-dark' : 'bg-success');
                                    ?>
                                    <tr>
                                        <td><code>#<?= (int) ($log['id'] ?? 0) ?></code></td>
                                        <td class="text-nowrap"><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></td>
                                        <td>
                                            <?= htmlspecialchars((string) ($log['actor'] ?? '')) ?>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars((string) ($log['role'] ?? '')) ?></span>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars((string) ($log['action'] ?? '')) ?></code>
                                            <?php if (!empty($log['document_number'])): ?>
                                                <br><small class="text-muted">Dok: <?= htmlspecialchars((string) ($log['document_number'] ?? '')) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <code><?= htmlspecialchars((string) ($log['old_status'] ?? '-')) ?></code>
                                            →
                                            <code><?= htmlspecialchars((string) ($log['new_status'] ?? '-')) ?></code>
                                        </td>
                                        <td><span class="badge <?= $badgeCls ?>"><?= htmlspecialchars($out) ?></span></td>
                                        <td><?= htmlspecialchars((string) ($log['reason'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>

