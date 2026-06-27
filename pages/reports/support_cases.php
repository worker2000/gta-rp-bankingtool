<?php
ob_start();
/**
 * PSB – Support-Fälle (Klärung mit Support)
 * Nur für Super-Admin
 */
$pageTitle = 'Support-Fälle';
require_once __DIR__ . '/../../includes/header.php';

if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

// Kredite mit dunning_hold
$loanCases = Database::fetchAll("
    SELECT l.id, l.file_number, l.product_type, l.status, l.dunning_hold_reason,
           l.days_overdue, l.outstanding_balance, l.late_fees_accrued, l.bank_id,
           b.first_name, b.last_name, b.customer_number, b.phone, b.email,
           bk.name as bank_name, bk.short_code,
           al.created_at as hold_set_at
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    JOIN banks bk ON l.bank_id = bk.id
    LEFT JOIN audit_log al ON al.entity_type = 'loan'
        AND al.entity_id = l.id
        AND al.action = 'DUNNING_HOLD'
        AND al.id = (SELECT MAX(id) FROM audit_log WHERE entity_type='loan' AND entity_id=l.id AND action='DUNNING_HOLD')
    WHERE l.dunning_hold = 1
    ORDER BY l.days_overdue DESC
");

// Versicherungsverträge mit dunning_hold
$insuranceCases = Database::fetchAll("
    SELECT ic.id, ic.contract_number, ic.status, ic.dunning_hold_reason,
           ic.days_overdue, ic.premium_amount, ic.dunning_level, ic.bank_id,
           ic.insured_first_name, ic.insured_last_name, ic.insured_phone, ic.insured_email,
           b.customer_number,
           bk.name as bank_name, bk.short_code,
           ip.name as product_name,
           al.created_at as hold_set_at
    FROM insurance_contracts ic
    LEFT JOIN borrowers b ON ic.borrower_id = b.id
    JOIN banks bk ON ic.bank_id = bk.id
    LEFT JOIN insurance_products ip ON ic.product_id = ip.id
    LEFT JOIN audit_log al ON al.entity_type = 'insurance_contract'
        AND al.entity_id = ic.id
        AND al.action = 'DUNNING_HOLD'
        AND al.id = (SELECT MAX(id) FROM audit_log WHERE entity_type='insurance_contract' AND entity_id=ic.id AND action='DUNNING_HOLD')
    WHERE ic.dunning_hold = 1
    ORDER BY ic.days_overdue DESC
");

$totalCases = count($loanCases) + count($insuranceCases);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4><i class="bi bi-headset me-2"></i>Support-Fälle</h4>
        <p class="text-muted mb-0">Alle Verträge mit aktiver Klärung mit Support</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-warning text-dark fs-6 align-self-center">
            <?= $totalCases ?> offene Fälle
        </span>
        <button class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Drucken
        </button>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body text-center">
                <div class="kpi-value text-warning"><?= $totalCases ?></div>
                <div class="kpi-label">Support-Fälle gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= count($loanCases) ?></div>
                <div class="kpi-label">Kredite</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= count($insuranceCases) ?></div>
                <div class="kpi-label">Krankenversicherungen</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card danger">
            <div class="card-body text-center">
                <div class="kpi-value text-danger">
                    <?= formatMoney(array_sum(array_column($loanCases, 'outstanding_balance'))) ?>
                </div>
                <div class="kpi-label">Offene Kreditsummen</div>
            </div>
        </div>
    </div>
</div>

<!-- Kredit-Supportfälle -->
<?php if (!empty($loanCases)): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-text"></i>
        <strong>Kredite (<?= count($loanCases) ?>)</strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Bank</th>
                        <th>Aktenzeichen</th>
                        <th>Kreditnehmer</th>
                        <th>Kontakt</th>
                        <th>Status</th>
                        <th>Verzugstage</th>
                        <th>Restschuld</th>
                        <th>Notiz</th>
                        <th>Markiert am</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loanCases as $c): ?>
                    <tr>
                        <td>
                            <span class="badge <?= $c['bank_id'] == 1 ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                <?= e($c['short_code']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $c['id'] ?>">
                                <strong><?= e($c['file_number']) ?></strong>
                            </a>
                            <?php if ($c['product_type'] === 'INSURANCE'): ?>
                            <br><small class="text-danger"><i class="bi bi-heart-pulse me-1"></i>Krankenvers.</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($c['last_name'] . ', ' . $c['first_name']) ?>
                            <br><small class="text-muted"><?= e($c['customer_number']) ?></small>
                        </td>
                        <td class="small">
                            <?php if ($c['phone']): ?><div><i class="bi bi-telephone me-1"></i><?= e($c['phone']) ?></div><?php endif; ?>
                            <?php if ($c['email']): ?><div><i class="bi bi-envelope me-1"></i><?= e($c['email']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= getStatusBadgeClass($c['status']) ?>">
                                <?= translateLoanStatus($c['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-danger"><?= $c['days_overdue'] ?> Tage</span>
                        </td>
                        <td><?= formatMoney($c['outstanding_balance']) ?></td>
                        <td>
                            <?php if ($c['dunning_hold_reason']): ?>
                            <span class="text-warning" title="<?= e($c['dunning_hold_reason']) ?>">
                                <i class="bi bi-chat-left-text me-1"></i><?= e($c['dunning_hold_reason']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $c['hold_set_at'] ? formatDateTime($c['hold_set_at']) : '–' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Versicherungs-Supportfälle -->
<?php if (!empty($insuranceCases)): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-heart-pulse text-danger"></i>
        <strong>Krankenversicherungen (<?= count($insuranceCases) ?>)</strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Bank</th>
                        <th>Vertragsnr.</th>
                        <th>Versicherter</th>
                        <th>Kontakt</th>
                        <th>Tarif</th>
                        <th>Status</th>
                        <th>Verzugstage</th>
                        <th>Notiz</th>
                        <th>Markiert am</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($insuranceCases as $c): ?>
                    <tr>
                        <td>
                            <span class="badge <?= $c['bank_id'] == 1 ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                <?= e($c['short_code']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $c['id'] ?>">
                                <strong><?= e($c['contract_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <?= e($c['insured_last_name'] . ', ' . $c['insured_first_name']) ?>
                            <?php if ($c['customer_number']): ?>
                            <br><small class="text-muted"><?= e($c['customer_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($c['insured_phone']): ?><div><i class="bi bi-telephone me-1"></i><?= e($c['insured_phone']) ?></div><?php endif; ?>
                            <?php if ($c['insured_email']): ?><div><i class="bi bi-envelope me-1"></i><?= e($c['insured_email']) ?></div><?php endif; ?>
                        </td>
                        <td><small><?= e($c['product_name'] ?? '–') ?></small></td>
                        <td>
                            <span class="badge <?= $c['status'] === 'SUSPENDED' ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                <?= $c['status'] === 'SUSPENDED' ? 'Suspendiert' : 'Aktiv' ?>
                            </span>
                            <?php if ($c['dunning_level'] > 0): ?>
                            <br><small class="text-warning">Mahnstufe <?= $c['dunning_level'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-danger"><?= $c['days_overdue'] ?> Tage</span>
                        </td>
                        <td>
                            <?php if ($c['dunning_hold_reason']): ?>
                            <span class="text-warning" title="<?= e($c['dunning_hold_reason']) ?>">
                                <i class="bi bi-chat-left-text me-1"></i><?= e($c['dunning_hold_reason']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $c['hold_set_at'] ? formatDateTime($c['hold_set_at']) : '–' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($totalCases === 0): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="bi bi-check-circle text-success"></i>
            <p class="mb-0">Keine offenen Support-Fälle</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
