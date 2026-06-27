<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Übersicht
 */
$pageTitle = 'Arbeitgeber-Krankenversicherung';
require_once __DIR__ . '/../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = "WHERE ie.bank_id = 2";
$params = [];

if ($search) {
    $where  .= " AND (ie.company_name LIKE ? OR ie.contact_person LIKE ?)";
    $s = "%{$search}%";
    $params = [$s, $s];
}

$totalCount = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM insurance_employers ie {$where}", $params
)['cnt'] ?? 0;
$totalPages = max(1, ceil($totalCount / $perPage));

$employers = Database::fetchAll("
    SELECT ie.*,
           COUNT(DISTINCT igc.id) as contract_count,
           COUNT(DISTINCT CASE WHEN igc.status = 'ACTIVE' THEN igc.id END) as active_contracts,
           COUNT(DISTINCT CASE WHEN im.status = 'ACTIVE' THEN im.id END) as active_members,
           COALESCE(SUM(CASE WHEN im.status = 'ACTIVE' THEN im.premium_monthly END), 0) as monthly_premium
    FROM insurance_employers ie
    LEFT JOIN insurance_group_contracts igc ON ie.id = igc.employer_id AND igc.bank_id = 2
    LEFT JOIN insurance_members im ON igc.id = im.group_contract_id
    {$where}
    GROUP BY ie.id
    ORDER BY ie.company_name
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// KPIs
$kpis = Database::fetchOne("
    SELECT
        COUNT(DISTINCT ie.id) as total_employers,
        COUNT(DISTINCT CASE WHEN igc.status = 'ACTIVE' THEN igc.id END) as active_contracts,
        COUNT(DISTINCT CASE WHEN im.status = 'ACTIVE' THEN im.id END) as active_members,
        COALESCE(SUM(CASE WHEN im.status = 'ACTIVE' THEN im.premium_monthly END), 0) as monthly_premium
    FROM insurance_employers ie
    LEFT JOIN insurance_group_contracts igc ON ie.id = igc.employer_id AND igc.bank_id = 2
    LEFT JOIN insurance_members im ON igc.id = im.group_contract_id
    WHERE ie.bank_id = 2
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-building me-2"></i>Arbeitgeber-Krankenversicherung</h4>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/pages/insurance/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-person-vcard me-2"></i>Einzelverträge
        </a>
        <a href="<?= APP_URL ?>/pages/insurance/employers/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Neuer Arbeitgeber
        </a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $kpis['total_employers'] ?></div>
                <div class="kpi-label">Arbeitgeber</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $kpis['active_contracts'] ?></div>
                <div class="kpi-label">Aktive Verträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $kpis['active_members'] ?></div>
                <div class="kpi-label">Versicherte Mitglieder</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['monthly_premium']) ?></div>
                <div class="kpi-label">Monatsbeiträge gesamt</div>
            </div>
        </div>
    </div>
</div>

<!-- Suche -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <input type="text" class="form-control" name="search"
                       value="<?= e($search) ?>" placeholder="Firmenname, Ansprechpartner...">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Suchen</button>
                <?php if ($search): ?>
                <a href="<?= APP_URL ?>/pages/insurance/employers/index.php" class="btn btn-outline-secondary">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Arbeitgeber-Tabelle -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($employers)): ?>
        <div class="empty-state">
            <i class="bi bi-building"></i>
            <p>Keine Arbeitgeber gefunden</p>
            <a href="<?= APP_URL ?>/pages/insurance/employers/create.php" class="btn btn-primary">Ersten Arbeitgeber anlegen</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Unternehmen</th>
                        <th>Ansprechpartner</th>
                        <th class="text-center">Verträge</th>
                        <th class="text-center">Mitglieder</th>
                        <th>Monatsbeiträge</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employers as $emp): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $emp['id'] ?>">
                                <strong><?= e($emp['company_name']) ?></strong>
                            </a>
                            <?php if (!$emp['is_active']): ?>
                            <span class="badge bg-secondary ms-1">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $emp['contact_person'] ? e($emp['contact_person']) : '<span class="text-muted">–</span>' ?>
                            <?php if ($emp['email']): ?>
                            <br><small class="text-muted"><?= e($emp['email']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($emp['active_contracts'] > 0): ?>
                            <span class="badge bg-success"><?= $emp['active_contracts'] ?> aktiv</span>
                            <?php endif; ?>
                            <?php if ($emp['contract_count'] > $emp['active_contracts']): ?>
                            <span class="badge bg-secondary"><?= $emp['contract_count'] ?> ges.</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($emp['active_members'] > 0): ?>
                            <span class="badge bg-primary"><?= $emp['active_members'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatMoney($emp['monthly_premium']) ?></td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $emp['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Details">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="<?= APP_URL ?>/pages/insurance/group/create.php?employer_id=<?= $emp['id'] ?>"
                               class="btn btn-sm btn-outline-success btn-action" title="Gruppenvertrag anlegen">
                                <i class="bi bi-file-earmark-plus"></i>
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
                        <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">Zurück</a>
                    </li>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                    <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Weiter</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 text-muted small">Gesamt: <?= $totalCount ?> Arbeitgeber</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
