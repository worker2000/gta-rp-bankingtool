<?php
ob_start();
/**
 * PSB Kreditverwaltung - Mahnwesen Übersicht
 */
$pageTitle = 'Mahnwesen';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Dunning.php';
Auth::requirePermission('dunning', 'view');

$activeTab = $_GET['tab'] ?? 'loans';

// Verzug neu berechnen
if (isset($_GET['recalculate']) && Auth::can('dunning', 'create')) {
    $ls = Dunning::calculateOverdue();
    $is = Dunning::calculateInsuranceOverdue();
    setFlash('success', sprintf(
        'Kredite: %d aktualisiert, %d L1, %d L2, %d gekündigt. Versicherungen: %d aktualisiert, %d Mahnung 1, %d Mahnung 2, %d suspendiert.',
        $ls['updated'], $ls['l1_triggered'], $ls['l2_triggered'], $ls['terminated'],
        $is['updated'], $is['l1_triggered'], $is['l2_triggered'], $is['suspended']
    ));
    header('Location: ' . APP_URL . '/pages/collections/index.php?tab=' . $activeTab);
    exit;
}

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('dunning', 'create') && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    // Kredit-Mahnstufe zurücksetzen
    if ($action === 'reset_dunning') {
        $loanId = intval($_POST['loan_id'] ?? 0);
        $loan = Database::fetchOne("SELECT id, status, file_number FROM loans WHERE id = ?", [$loanId]);
        if ($loan && in_array($loan['status'], ['DUNNING_L1', 'DUNNING_L2', 'TERMINATED'])) {
            $oldStatus = $loan['status'];
            Database::update('loans', ['status' => 'ACTIVE', 'days_overdue' => 0, 'late_fees_accrued' => 0], 'id = ?', [$loanId]);
            Database::query("UPDATE loan_schedule_items SET status='PENDING', days_overdue=0, late_interest=0 WHERE loan_id=? AND status='OVERDUE'", [$loanId]);
            AuditLog::log('DUNNING_RESET', 'loan', $loanId, ['status' => $oldStatus], ['status' => 'ACTIVE']);
            setFlash('success', "Mahnstufe für {$loan['file_number']} zurückgesetzt.");
        }
        header('Location: ' . APP_URL . '/pages/collections/index.php?tab=loans');
        exit;
    }

    // Versicherungs-Mahnstufe zurücksetzen
    if ($action === 'reset_insurance_dunning') {
        $insId = intval($_POST['insurance_id'] ?? 0);
        $ins = Database::fetchOne("SELECT id, contract_number FROM insurance_contracts WHERE id = ?", [$insId]);
        if ($ins) {
            Database::update('insurance_contracts', ['dunning_level' => 0, 'days_overdue' => 0, 'status' => 'ACTIVE'], 'id = ?', [$insId]);
            Database::query("UPDATE insurance_schedule_items SET status='PENDING', days_overdue=0 WHERE insurance_contract_id=? AND status='OVERDUE'", [$insId]);
            AuditLog::log('INSURANCE_DUNNING_RESET', 'insurance_contract', $insId, null, ['dunning_level' => 0]);
            setFlash('success', "Mahnstufe für Vertrag {$ins['contract_number']} zurückgesetzt.");
        }
        header('Location: ' . APP_URL . '/pages/collections/index.php?tab=insurance');
        exit;
    }
}

// Kredite in Mahnstufe laden
$dunningLoans = Dunning::getDunningLoans();
$byStatus = ['DUNNING_L1' => [], 'DUNNING_L2' => [], 'TERMINATED' => []];
foreach ($dunningLoans as $loan) {
    $byStatus[$loan['status']][] = $loan;
}
$totalOverdue = array_sum(array_column($dunningLoans, 'open_amount'));
$totalFees    = array_sum(array_column($dunningLoans, 'late_fees_accrued'));

// Versicherungen in Mahnstufe laden
$dunningInsurance = Dunning::getInsuranceDunning();
$byInsLevel = [1 => [], 2 => [], 3 => []];
foreach ($dunningInsurance as $ins) {
    $level = min(3, max(1, (int)$ins['dunning_level']));
    $byInsLevel[$level][] = $ins;
}
$totalInsOverdue = array_sum(array_column($dunningInsurance, 'open_amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-exclamation-triangle me-2"></i>Mahnwesen</h4>
    <div>
        <?php if (Auth::can('dunning', 'create')): ?>
        <a href="?recalculate=1&tab=<?= $activeTab ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-clockwise me-2"></i>Verzug berechnen
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'loans' ? 'active' : '' ?>"
           href="?tab=loans">
            <i class="bi bi-file-earmark-text me-1"></i>Kredite
            <?php if (count($dunningLoans) > 0): ?>
            <span class="badge bg-danger ms-1"><?= count($dunningLoans) ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'insurance' ? 'active' : '' ?>"
           href="?tab=insurance">
            <i class="bi bi-heart-pulse me-1"></i>Krankenversicherungen
            <?php if (count($dunningInsurance) > 0): ?>
            <span class="badge bg-danger ms-1"><?= count($dunningInsurance) ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($activeTab === 'loans'): ?>
<!-- KPIs Kredite -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body text-center">
                <div class="kpi-value text-warning"><?= count($byStatus['DUNNING_L1']) ?></div>
                <div class="kpi-label">Mahnung Stufe 1</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card" style="border-left-color: #fd7e14;">
            <div class="card-body text-center">
                <div class="kpi-value" style="color: #fd7e14;"><?= count($byStatus['DUNNING_L2']) ?></div>
                <div class="kpi-label">Mahnung Stufe 2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card danger">
            <div class="card-body text-center">
                <div class="kpi-value text-danger"><?= count($byStatus['TERMINATED']) ?></div>
                <div class="kpi-label">Gekündigt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($totalOverdue + $totalFees) ?></div>
                <div class="kpi-label">Offene Forderungen</div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($byStatus['DUNNING_L1'])): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-exclamation-circle me-2"></i>Mahnung Stufe 1 (<?= count($byStatus['DUNNING_L1']) ?>)
    </div>
    <div class="card-body p-0"><?= renderDunningTable($byStatus['DUNNING_L1']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($byStatus['DUNNING_L2'])): ?>
<div class="card mb-4">
    <div class="card-header" style="background-color:#fd7e14;color:white;">
        <i class="bi bi-exclamation-triangle me-2"></i>Mahnung Stufe 2 (<?= count($byStatus['DUNNING_L2']) ?>)
    </div>
    <div class="card-body p-0"><?= renderDunningTable($byStatus['DUNNING_L2']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($byStatus['TERMINATED'])): ?>
<div class="card mb-4">
    <div class="card-header bg-danger text-white">
        <i class="bi bi-x-circle me-2"></i>Gekündigt (<?= count($byStatus['TERMINATED']) ?>)
    </div>
    <div class="card-body p-0"><?= renderDunningTable($byStatus['TERMINATED']) ?></div>
</div>
<?php endif; ?>

<?php if (empty($dunningLoans)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state"><i class="bi bi-check-circle text-success"></i><p class="mb-0">Keine Kredite im Mahnwesen</p></div>
</div></div>
<?php endif; ?>

<?php else: /* tab=insurance */ ?>
<!-- KPIs Versicherungen -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body text-center">
                <div class="kpi-value text-warning"><?= count($byInsLevel[1]) ?></div>
                <div class="kpi-label">Mahnung Stufe 1</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card" style="border-left-color:#fd7e14;">
            <div class="card-body text-center">
                <div class="kpi-value" style="color:#fd7e14;"><?= count($byInsLevel[2]) ?></div>
                <div class="kpi-label">Mahnung Stufe 2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card danger">
            <div class="card-body text-center">
                <div class="kpi-value text-danger"><?= count($byInsLevel[3]) ?></div>
                <div class="kpi-label">Suspendiert</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($totalInsOverdue) ?></div>
                <div class="kpi-label">Offene Beiträge</div>
            </div>
        </div>
    </div>
</div>

<?php
$insLevelLabels = [
    1 => ['label' => 'Mahnung Stufe 1', 'header_class' => 'bg-warning text-dark', 'icon' => 'exclamation-circle'],
    2 => ['label' => 'Mahnung Stufe 2', 'header_style' => 'background-color:#fd7e14;color:white;', 'icon' => 'exclamation-triangle'],
    3 => ['label' => 'Suspendiert',     'header_class' => 'bg-danger text-white',  'icon' => 'x-circle'],
];
foreach ([1,2,3] as $lvl):
    if (empty($byInsLevel[$lvl])) continue;
    $cfg = $insLevelLabels[$lvl];
?>
<div class="card mb-4">
    <div class="card-header <?= $cfg['header_class'] ?? '' ?>" <?= isset($cfg['header_style']) ? 'style="'.$cfg['header_style'].'"' : '' ?>>
        <i class="bi bi-<?= $cfg['icon'] ?> me-2"></i><?= $cfg['label'] ?> (<?= count($byInsLevel[$lvl]) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Vertragsnr.</th>
                <th>Versicherter</th>
                <th>Kontakt</th>
                <th>Tarif</th>
                <th>Offene Raten</th>
                <th>Offener Betrag</th>
                <th>Verzugstage</th>
                <th class="text-end">Aktionen</th>
            </tr></thead>
            <tbody>
            <?php foreach ($byInsLevel[$lvl] as $ins): ?>
            <tr>
                <td>
                    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $ins['id'] ?>">
                        <strong><?= e($ins['contract_number']) ?></strong>
                    </a>
                </td>
                <td>
                    <?= e($ins['insured_last_name'] . ', ' . $ins['insured_first_name']) ?>
                    <?php if ($ins['customer_number']): ?>
                    <br><small class="text-muted"><?= e($ins['customer_number']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($ins['insured_phone']): ?>
                    <div><i class="bi bi-telephone me-1"></i><?= e($ins['insured_phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($ins['insured_email']): ?>
                    <div><i class="bi bi-envelope me-1"></i><?= e($ins['insured_email']) ?></div>
                    <?php endif; ?>
                </td>
                <td><small><?= e($ins['product_name'] ?? '–') ?></small></td>
                <td><span class="badge bg-secondary"><?= $ins['overdue_count'] ?></span></td>
                <td class="text-danger fw-semibold"><?= formatMoney((float)($ins['open_amount'] ?? 0)) ?></td>
                <td>
                    <span class="badge <?= $ins['days_overdue'] > 60 ? 'bg-danger' : 'bg-warning' ?>">
                        <?= $ins['days_overdue'] ?> Tage
                    </span>
                </td>
                <td class="text-end">
                    <?php if (Auth::can('dunning', 'create')): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Mahnstufe zurücksetzen?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reset_insurance_dunning">
                        <input type="hidden" name="insurance_id" value="<?= $ins['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Zurück auf Aktiv">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($dunningInsurance)): ?>
<div class="card"><div class="card-body">
    <div class="empty-state"><i class="bi bi-check-circle text-success"></i><p class="mb-0">Keine Versicherungsverträge im Mahnwesen</p></div>
</div></div>
<?php endif; ?>

<?php endif; /* end tab */?>

<?php
function renderDunningTable(array $loans): string {
    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Aktenzeichen</th>
                    <th>Kreditnehmer</th>
                    <th>Kontakt</th>
                    <th>Offene Raten</th>
                    <th>Offener Betrag</th>
                    <th>Verzugszins</th>
                    <th>Tage</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loans as $loan): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>">
                            <strong><?= e($loan['file_number']) ?></strong>
                        </a>
                    </td>
                    <td>
                        <?= e($loan['last_name'] . ', ' . $loan['first_name']) ?>
                        <br><small class="text-muted"><?= e($loan['customer_number']) ?></small>
                    </td>
                    <td>
                        <?php if ($loan['phone']): ?>
                        <div><i class="bi bi-telephone me-1"></i><?= e($loan['phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($loan['email']): ?>
                        <div><i class="bi bi-envelope me-1"></i><?= e($loan['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= $loan['overdue_count'] ?></span></td>
                    <td><?= formatMoney((float)($loan['open_amount'] ?? 0)) ?></td>
                    <td class="text-danger"><?= formatMoney((float)($loan['late_fees_accrued'] ?? 0)) ?></td>
                    <td>
                        <span class="badge <?= $loan['days_overdue'] > 14 ? 'bg-danger' : 'bg-warning' ?>">
                            <?= $loan['days_overdue'] ?> Tage
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if (Auth::can('dunning', 'create')): ?>
                        <a href="<?= APP_URL ?>/pages/collections/create.php?loan_id=<?= $loan['id'] ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-envelope me-1"></i>Schreiben
                        </a>
                        <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Mahnstufe für <?= e($loan['file_number']) ?> wirklich zurücksetzen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reset_dunning">
                            <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Zurück auf Aktiv">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
