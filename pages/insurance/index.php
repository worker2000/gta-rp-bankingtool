<?php
ob_start();
/**
 * Fortis Finance – Krankenversicherung Übersicht
 */
$pageTitle = 'Krankenversicherung';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

// Filter
$search      = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$productFilter = intval($_GET['product_id'] ?? 0);
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = "WHERE ic.bank_id = 2";
$params = [];

if ($search) {
    $where  .= " AND (ic.contract_number LIKE ? OR ic.insured_last_name LIKE ? OR ic.insured_first_name LIKE ? OR ic.insured_iban LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($statusFilter) {
    $where  .= " AND ic.status = ?";
    $params[] = $statusFilter;
}
if ($productFilter) {
    $where  .= " AND ic.product_id = ?";
    $params[] = $productFilter;
}

$totalCount = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM insurance_contracts ic {$where}", $params
)['cnt'] ?? 0;
$totalPages = max(1, ceil($totalCount / $perPage));

$contracts = Database::fetchAll("
    SELECT ic.*, ip.name as product_name, ip.type as product_type,
           b.first_name as borrower_first, b.last_name as borrower_last,
           (SELECT COUNT(*) FROM insurance_claims cl WHERE cl.contract_id = ic.id) as claims_count,
           (SELECT COUNT(*) FROM insurance_claims cl WHERE cl.contract_id = ic.id AND cl.status IN ('SUBMITTED','IN_REVIEW')) as open_claims,
           (SELECT COUNT(*) FROM insurance_premium_schedule ps WHERE ps.contract_id = ic.id AND ps.status = 'OVERDUE') as overdue_premiums
    FROM insurance_contracts ic
    JOIN insurance_products ip ON ic.product_id = ip.id
    LEFT JOIN borrowers b ON ic.borrower_id = b.id
    {$where}
    ORDER BY ic.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// KPIs
$kpis = Database::fetchOne("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ACTIVE'    THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'APPLIED'   THEN 1 ELSE 0 END) as applied,
        SUM(CASE WHEN status = 'SUSPENDED' THEN 1 ELSE 0 END) as suspended,
        COALESCE(SUM(CASE WHEN status = 'ACTIVE' THEN premium_amount END), 0) as monthly_revenue
    FROM insurance_contracts WHERE bank_id = 2
");

$products = Database::fetchAll("SELECT id, name FROM insurance_products WHERE bank_id = 2 AND is_active = 1 ORDER BY sort_order");

function translateContractStatus(string $s): string {
    return match($s) {
        'APPLIED'   => 'Antrag',
        'ACTIVE'    => 'Aktiv',
        'SUSPENDED' => 'Ruhend',
        'CANCELLED' => 'Gekündigt',
        'EXPIRED'   => 'Abgelaufen',
        default     => $s
    };
}
function contractStatusBadge(string $s): string {
    return match($s) {
        'APPLIED'   => 'bg-info',
        'ACTIVE'    => 'bg-success',
        'SUSPENDED' => 'bg-warning',
        'CANCELLED' => 'bg-danger',
        'EXPIRED'   => 'bg-secondary',
        default     => 'bg-secondary'
    };
}
function translateInsuranceType(string $t): string {
    return match($t) {
        'PKV'        => 'Private KV',
        'GKV_ZUSATZ' => 'GKV-Zusatz',
        'ZAHN'       => 'Zahnzusatz',
        'VISION'     => 'Sehhilfe',
        'PFLEGE'     => 'Pflegezusatz',
        'UNFALL'     => 'Unfall',
        default      => $t
    };
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-person-vcard me-2"></i>Einzelverträge</h4>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/pages/insurance/employers/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-building me-2"></i>Arbeitgeber-Verträge
        </a>
        <a href="<?= APP_URL ?>/pages/insurance/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Neuer Vertrag
        </a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $kpis['active'] ?></div>
                <div class="kpi-label">Aktive Verträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value text-info"><?= $kpis['applied'] ?></div>
                <div class="kpi-label">Anträge offen</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body">
                <div class="kpi-value text-warning"><?= $kpis['suspended'] ?></div>
                <div class="kpi-label">Ruhende Verträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['monthly_revenue']) ?></div>
                <div class="kpi-label">Monatsbeiträge</div>
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
                       value="<?= e($search) ?>" placeholder="Vertragsnr., Name, IBAN...">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">Alle Status</option>
                    <option value="APPLIED"   <?= $statusFilter === 'APPLIED'   ? 'selected' : '' ?>>Antrag</option>
                    <option value="ACTIVE"    <?= $statusFilter === 'ACTIVE'    ? 'selected' : '' ?>>Aktiv</option>
                    <option value="SUSPENDED" <?= $statusFilter === 'SUSPENDED' ? 'selected' : '' ?>>Ruhend</option>
                    <option value="CANCELLED" <?= $statusFilter === 'CANCELLED' ? 'selected' : '' ?>>Gekündigt</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="product_id">
                    <option value="">Alle Tarife</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $productFilter === $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filtern</button>
                <?php if ($search || $statusFilter || $productFilter): ?>
                <a href="<?= APP_URL ?>/pages/insurance/index.php" class="btn btn-outline-secondary">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Vertragsübersicht -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($contracts)): ?>
        <div class="empty-state">
            <i class="bi bi-heart-pulse"></i>
            <p>Keine Versicherungsverträge gefunden</p>
            <a href="<?= APP_URL ?>/pages/insurance/create.php" class="btn btn-primary">Ersten Vertrag anlegen</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Vertragsnr.</th>
                        <th>Versicherter</th>
                        <th>Tarif</th>
                        <th>Monatsbeitrag</th>
                        <th>Laufzeit</th>
                        <th>Status</th>
                        <th>Leistungen</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contracts as $c): ?>
                    <tr class="<?= $c['overdue_premiums'] > 0 ? 'overdue-warning' : '' ?>">
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $c['id'] ?>">
                                <strong><?= e($c['contract_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <?= e($c['insured_last_name'] . ', ' . $c['insured_first_name']) ?>
                            <?php if ($c['borrower_id']): ?>
                            <br><small class="text-muted"><i class="bi bi-link-45deg"></i>
                                <?= e($c['borrower_last'] . ' ' . $c['borrower_first']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= translateInsuranceType($c['product_type']) ?></span>
                            <br><small><?= e($c['product_name']) ?></small>
                        </td>
                        <td><?= formatMoney($c['premium_amount']) ?></td>
                        <td>
                            <small><?= formatDate($c['start_date']) ?></small>
                            <?php if ($c['end_date']): ?>
                            <br><small class="text-muted">bis <?= formatDate($c['end_date']) ?></small>
                            <?php else: ?>
                            <br><small class="text-muted">unbefristet</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= contractStatusBadge($c['status']) ?>">
                                <?= translateContractStatus($c['status']) ?>
                            </span>
                            <?php if ($c['overdue_premiums'] > 0): ?>
                            <br><span class="badge bg-danger mt-1"><?= $c['overdue_premiums'] ?> Beitrag überfällig</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['open_claims'] > 0): ?>
                            <span class="badge bg-warning"><?= $c['open_claims'] ?> offen</span>
                            <?php endif; ?>
                            <?php if ($c['claims_count'] > 0): ?>
                            <small class="text-muted d-block"><?= $c['claims_count'] ?> gesamt</small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $c['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Anzeigen">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="<?= APP_URL ?>/pages/insurance/edit.php?id=<?= $c['id'] ?>"
                               class="btn btn-sm btn-outline-secondary btn-action" title="Bearbeiten">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?contract_id=<?= $c['id'] ?>"
                               class="btn btn-sm btn-outline-success btn-action" title="Leistungsantrag">
                                <i class="bi bi-file-medical"></i>
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
                        <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&product_id=<?= $productFilter ?>">Zurück</a>
                    </li>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                    <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&product_id=<?= $productFilter ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&product_id=<?= $productFilter ?>">Weiter</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 text-muted small">Gesamt: <?= $totalCount ?> Verträge</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
