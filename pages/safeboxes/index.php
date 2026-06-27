<?php
/**
 * PSB / Fortis Finance – Schließfächer Übersicht
 */
$pageTitle = 'Schließfächer';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

$bid = currentBankId();

$search      = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sizeFilter  = $_GET['size'] ?? '';
$page        = max(1, intval($_GET['page'] ?? 1));
$perPage     = 25;
$offset      = ($page - 1) * $perPage;

$where  = "WHERE s.bank_id = ?";
$params = [$bid];

if ($search) {
    $where  .= " AND (s.box_number LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ? OR b.customer_number LIKE ?)";
    $sv = "%{$search}%";
    $params = array_merge($params, [$sv, $sv, $sv, $sv]);
}
if ($statusFilter) {
    $where .= " AND s.status = ?";
    $params[] = $statusFilter;
}
if ($sizeFilter) {
    $where .= " AND s.box_size = ?";
    $params[] = $sizeFilter;
}

$totalCount = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM safeboxes s LEFT JOIN borrowers b ON s.borrower_id = b.id {$where}",
    $params
)['cnt'] ?? 0;
$totalPages = max(1, ceil($totalCount / $perPage));

$boxes = Database::fetchAll("
    SELECT s.*, b.first_name, b.last_name, b.customer_number, b.id as bid
    FROM safeboxes s
    LEFT JOIN borrowers b ON s.borrower_id = b.id
    {$where}
    ORDER BY s.status ASC, s.box_number ASC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// KPIs
$kpis = Database::fetchOne("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ACTIVE'   THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'RELEASED' THEN 1 ELSE 0 END) as released,
        COALESCE(SUM(CASE WHEN status = 'ACTIVE' THEN weekly_fee ELSE 0 END), 0) as weekly_revenue,
        SUM(CASE WHEN box_size = 'KLEIN'  AND status = 'ACTIVE' THEN 1 ELSE 0 END) as active_klein,
        SUM(CASE WHEN box_size = 'MITTEL' AND status = 'ACTIVE' THEN 1 ELSE 0 END) as active_mittel,
        SUM(CASE WHEN box_size = 'GROSS'  AND status = 'ACTIVE' THEN 1 ELSE 0 END) as active_gross
    FROM safeboxes WHERE bank_id = ?
", [$bid]);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-safe me-2"></i>Schließfächer</h4>
    <a href="<?= APP_URL ?>/pages/safeboxes/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle me-2"></i>Neues Schließfach
    </a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $kpis['active'] ?></div>
                <div class="kpi-label">Aktive Schließfächer</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value text-secondary"><?= $kpis['released'] ?></div>
                <div class="kpi-label">Freigegeben</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['weekly_revenue']) ?></div>
                <div class="kpi-label">Wochengebühren (aktiv)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value" style="font-size:1.1rem;">
                    K:<?= $kpis['active_klein'] ?> &nbsp;
                    M:<?= $kpis['active_mittel'] ?> &nbsp;
                    G:<?= $kpis['active_gross'] ?>
                </div>
                <div class="kpi-label">Klein / Mittel / Groß</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search"
                       value="<?= e($search) ?>" placeholder="Fachnr., Name, Kundennr...">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">Alle Status</option>
                    <option value="ACTIVE"   <?= $statusFilter === 'ACTIVE'   ? 'selected' : '' ?>>Aktiv</option>
                    <option value="RELEASED" <?= $statusFilter === 'RELEASED' ? 'selected' : '' ?>>Freigegeben</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="size">
                    <option value="">Alle Größen</option>
                    <option value="KLEIN"  <?= $sizeFilter === 'KLEIN'  ? 'selected' : '' ?>>Klein</option>
                    <option value="MITTEL" <?= $sizeFilter === 'MITTEL' ? 'selected' : '' ?>>Mittel</option>
                    <option value="GROSS"  <?= $sizeFilter === 'GROSS'  ? 'selected' : '' ?>>Groß</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filtern</button>
                <?php if ($search || $statusFilter || $sizeFilter): ?>
                <a href="<?= APP_URL ?>/pages/safeboxes/index.php" class="btn btn-outline-secondary">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabelle -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($boxes)): ?>
        <div class="empty-state">
            <i class="bi bi-safe"></i>
            <p>Keine Schließfächer gefunden</p>
            <a href="<?= APP_URL ?>/pages/safeboxes/create.php" class="btn btn-primary">Erstes Schließfach anlegen</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Fach-Nr.</th>
                        <th>Größe</th>
                        <th>Mieter</th>
                        <th>IBAN</th>
                        <th>Wochengebühr</th>
                        <th>Letzte Zahlung</th>
                        <th>Initialen</th>
                        <th>Status</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boxes as $box): ?>
                    <?php
                        $daysSince = $box['last_payment_date']
                            ? (int)((time() - strtotime($box['last_payment_date'])) / 86400)
                            : null;
                        $paymentWarning = $box['status'] === 'ACTIVE' && ($daysSince === null || $daysSince > 14);
                    ?>
                    <tr class="<?= $paymentWarning ? 'overdue-warning' : '' ?>">
                        <td>
                            <a href="<?= APP_URL ?>/pages/safeboxes/view.php?id=<?= $box['id'] ?>">
                                <strong><?= e($box['box_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <?php
                            $sizeBadge = match($box['box_size']) {
                                'KLEIN'  => ['bg-secondary', 'Klein'],
                                'MITTEL' => ['bg-info text-dark', 'Mittel'],
                                'GROSS'  => ['bg-primary', 'Groß'],
                                default  => ['bg-secondary', $box['box_size']],
                            };
                            ?>
                            <span class="badge <?= $sizeBadge[0] ?>"><?= $sizeBadge[1] ?></span>
                        </td>
                        <td>
                            <?php if ($box['bid']): ?>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $box['bid'] ?>">
                                <?= e($box['last_name'] . ', ' . $box['first_name']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($box['customer_number']) ?></small>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($box['iban'] ?: '–') ?></code></td>
                        <td><?= formatMoney($box['weekly_fee']) ?></td>
                        <td>
                            <?php if ($box['last_payment_date']): ?>
                            <?= formatDate($box['last_payment_date']) ?>
                            <?php if ($paymentWarning && $box['status'] === 'ACTIVE'): ?>
                            <br><span class="badge bg-warning text-dark"><?= $daysSince ?>d</span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php if ($paymentWarning && $box['status'] === 'ACTIVE'): ?>
                            <br><span class="badge bg-danger">Keine Zahlung</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($box['staff_initials'] ?: '–') ?></td>
                        <td>
                            <?php if ($box['status'] === 'ACTIVE'): ?>
                            <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Freigegeben</span>
                            <?php if ($box['released_at']): ?>
                            <br><small class="text-muted"><?= formatDate($box['released_at']) ?></small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/pages/safeboxes/view.php?id=<?= $box['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Details">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&size=<?= $sizeFilter ?>">Zurück</a>
                    </li>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                    <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&size=<?= $sizeFilter ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&size=<?= $sizeFilter ?>">Weiter</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 text-muted small">Gesamt: <?= $totalCount ?> Schließfächer</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
