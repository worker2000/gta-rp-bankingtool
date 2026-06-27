<?php
/**
 * PSB / Fortis Finance – Dashboard
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
Auth::requireLogin();

$bid = currentBankId();

// KPIs – alle gefiltert nach bank_id
$stats = Database::fetchOne("
    SELECT
        (SELECT COUNT(*) FROM loans WHERE status = 'ACTIVE'                        AND bank_id = ?) as active_loans,
        (SELECT COUNT(*) FROM loans WHERE status IN ('DUNNING_L1','DUNNING_L2')    AND bank_id = ?) as dunning_loans,
        (SELECT COUNT(*) FROM loans WHERE status = 'TERMINATED'                    AND bank_id = ?) as terminated_loans,
        (SELECT COALESCE(SUM(outstanding_balance),0) FROM loans
             WHERE status = 'ACTIVE' AND bank_id = ?)                                               as total_outstanding,
        (SELECT COUNT(*) FROM bank_transactions bt
             JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
             WHERE bt.match_status = 'UNMATCHED' AND bsb.bank_id = ?)                              as unmatched_transactions,
        (SELECT COUNT(*) FROM bank_transactions bt
             JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
             WHERE bt.match_status = 'AMBIGUOUS' AND bsb.bank_id = ?)                              as ambiguous_transactions
", [$bid, $bid, $bid, $bid, $bid, $bid]);

// Heute fällige Raten (über loans.bank_id)
$dueTodayCount = Database::fetchOne("
    SELECT COUNT(*) as cnt
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    WHERE lsi.due_date = CURDATE()
      AND lsi.status IN ('PENDING','PARTIAL')
      AND l.bank_id = ?
", [$bid])['cnt'] ?? 0;

// Überfällige Raten
$overdueRates = Database::fetchAll("
    SELECT lsi.*, l.file_number, l.status as loan_status,
           b.first_name, b.last_name,
           DATEDIFF(CURDATE(), lsi.due_date) as days_overdue
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE lsi.due_date < CURDATE()
      AND lsi.status IN ('PENDING','PARTIAL','OVERDUE')
      AND l.bank_id = ?
    ORDER BY lsi.due_date ASC
    LIMIT 10
", [$bid]);

// Letzte Aktivitäten (bank-gefiltert)
$recentActivity = Database::fetchAll("
    SELECT al.*, u.full_name as user_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.bank_id = ?
    ORDER BY al.created_at DESC
    LIMIT 10
", [$bid]);

// Unzugeordnete Buchungen
$unmatchedTransactions = Database::fetchAll("
    SELECT bt.*, bsb.batch_date
    FROM bank_transactions bt
    JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
    WHERE bt.match_status IN ('UNMATCHED','AMBIGUOUS')
      AND bsb.bank_id = ?
    ORDER BY bt.transaction_date DESC
    LIMIT 5
", [$bid]);

// Schließfächer KPIs (beide Banken)
$sbKpis = Database::fetchOne("
    SELECT
        SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active_boxes,
        SUM(CASE WHEN status='ACTIVE' AND (last_payment_date IS NULL OR last_payment_date < DATE_SUB(CURDATE(),INTERVAL 14 DAY)) THEN 1 ELSE 0 END) as overdue_boxes
    FROM safeboxes WHERE bank_id=?
", [$bid]);

// Fortis Finance: Zusätzlich Versicherungs-KPIs
$ffInsKpis = null;
if ($bid === 2) {
    $ffInsKpis = Database::fetchOne("
        SELECT
            SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active_contracts,
            (SELECT COUNT(*) FROM insurance_claims WHERE bank_id=2 AND status IN ('SUBMITTED','IN_REVIEW')) as open_claims,
            (SELECT COUNT(*) FROM insurance_members im JOIN insurance_group_contracts gc ON im.group_contract_id=gc.id WHERE gc.bank_id=2 AND im.status='ACTIVE') as active_members
        FROM insurance_contracts WHERE bank_id=2
    ");
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h4><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $stats['active_loans'] ?></div>
                <div class="kpi-label">Aktive Kredite</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body">
                <div class="kpi-value text-warning"><?= $stats['dunning_loans'] ?></div>
                <div class="kpi-label">In Mahnung</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card danger">
            <div class="card-body">
                <div class="kpi-value text-danger"><?= $stats['terminated_loans'] ?></div>
                <div class="kpi-label">Gekündigt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($stats['total_outstanding']) ?></div>
                <div class="kpi-label">Offene Forderungen</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $dueTodayCount ?></div>
                <div class="kpi-label">Heute fällige Raten</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card <?= $stats['unmatched_transactions'] > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= $stats['unmatched_transactions'] > 0 ? 'text-warning' : '' ?>">
                    <?= $stats['unmatched_transactions'] ?>
                </div>
                <div class="kpi-label">Unzugeordnete Buchungen</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card <?= $stats['ambiguous_transactions'] > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= $stats['ambiguous_transactions'] > 0 ? 'text-warning' : '' ?>">
                    <?= $stats['ambiguous_transactions'] ?>
                </div>
                <div class="kpi-label">Mehrdeutige Buchungen</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Überfällige Raten -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle text-warning me-2"></i>Überfällige Raten</span>
                <?php if (Auth::can('dunning', 'view')): ?>
                <a href="<?= APP_URL ?>/pages/collections/index.php" class="btn btn-sm btn-outline-light">Alle anzeigen</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($overdueRates)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-check-circle text-success"></i>
                    <p class="mb-0">Keine überfälligen Raten</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Aktenzeichen</th>
                                <th>Kreditnehmer</th>
                                <th>Fällig</th>
                                <th>Betrag</th>
                                <th>Tage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueRates as $rate): ?>
                            <tr class="<?= $rate['days_overdue'] > 14 ? 'overdue-danger' : 'overdue-warning' ?>">
                                <td>
                                    <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $rate['loan_id'] ?>">
                                        <?= e($rate['file_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($rate['last_name'] . ', ' . $rate['first_name']) ?></td>
                                <td><?= formatDate($rate['due_date']) ?></td>
                                <td><?= formatMoney($rate['amount_outstanding']) ?></td>
                                <td>
                                    <span class="badge <?= $rate['days_overdue'] > 14 ? 'bg-danger' : 'bg-warning' ?>">
                                        <?= $rate['days_overdue'] ?> Tage
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Unzugeordnete Buchungen -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-question-circle text-info me-2"></i>Offene Buchungen</span>
                <?php if (Auth::can('import', 'match')): ?>
                <a href="<?= APP_URL ?>/pages/import/index.php" class="btn btn-sm btn-outline-light">Zum Import</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($unmatchedTransactions)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-check-circle text-success"></i>
                    <p class="mb-0">Alle Buchungen zugeordnet</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Absender</th>
                                <th>Betrag</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unmatchedTransactions as $tx): ?>
                            <tr>
                                <td><?= formatDate($tx['transaction_date']) ?></td>
                                <td><?= e(substr($tx['sender_name'] ?? 'Unbekannt', 0, 25)) ?></td>
                                <td><?= formatMoney($tx['amount']) ?></td>
                                <td>
                                    <span class="badge <?= $tx['match_status'] === 'AMBIGUOUS' ? 'bg-warning' : 'bg-secondary' ?>">
                                        <?= $tx['match_status'] === 'AMBIGUOUS' ? 'Mehrdeutig' : 'Offen' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Schließfächer + FF: KV KPIs -->
<?php if (($sbKpis['active_boxes'] ?? 0) > 0 || $ffInsKpis): ?>
<div class="row g-3 mb-4">
    <?php if ($ffInsKpis): ?>
    <div class="col-12">
        <h6 class="text-muted mb-3"><i class="bi bi-building me-2"></i>Fortis Finance – Zusatzprodukte</h6>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $ffInsKpis['active_contracts'] ?? 0 ?></div>
                <div class="kpi-label">KV Einzelverträge aktiv</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $ffInsKpis['active_members'] ?? 0 ?></div>
                <div class="kpi-label">KV Gruppen-Mitglieder</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card <?= ($ffInsKpis['open_claims'] ?? 0) > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= ($ffInsKpis['open_claims'] ?? 0) > 0 ? 'text-warning' : '' ?>">
                    <?= $ffInsKpis['open_claims'] ?? 0 ?>
                </div>
                <div class="kpi-label">Offene Leistungsanträge</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (($sbKpis['active_boxes'] ?? 0) > 0 || ($sbKpis['overdue_boxes'] ?? 0) > 0): ?>
    <div class="<?= $ffInsKpis ? 'col-md-3' : 'col-md-4' ?>">
        <div class="card kpi-card <?= ($sbKpis['overdue_boxes'] ?? 0) > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= ($sbKpis['overdue_boxes'] ?? 0) > 0 ? 'text-warning' : '' ?>">
                    <?= $sbKpis['active_boxes'] ?? 0 ?>
                    <?php if (($sbKpis['overdue_boxes'] ?? 0) > 0): ?>
                    <small class="fs-6 text-warning"> / <?= $sbKpis['overdue_boxes'] ?> überfällig</small>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">Schließfächer aktiv</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Schnellzugriff -->
<div class="row g-3 mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Schnellzugriff
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php if (Auth::can('loans', 'create')): ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/loans/create.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Neuer Kredit
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (Auth::can('borrowers', 'create')): ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/borrowers/create.php" class="btn btn-outline-primary">
                            <i class="bi bi-person-plus me-2"></i>Neuer Kreditnehmer
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (Auth::can('import', 'upload')): ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/import/upload.php" class="btn btn-outline-primary">
                            <i class="bi bi-upload me-2"></i>Kontoauszug importieren
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (Auth::can('loans', 'view')): ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/loans/index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-list me-2"></i>Alle Kredite
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/safeboxes/create.php" class="btn btn-outline-primary">
                            <i class="bi bi-safe me-2"></i>Neues Schließfach
                        </a>
                    </div>
                    <?php if ($bid === 2): ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/insurance/create.php" class="btn btn-outline-primary">
                            <i class="bi bi-heart-pulse me-2"></i>Neuer KV-Vertrag
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="col-auto">
                        <a href="<?= APP_URL ?>/pages/reports/index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-bar-chart-line me-2"></i>Berichte
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
