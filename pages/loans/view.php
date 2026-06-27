<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kredit Details
 */
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('loans', 'view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/loans/index.php');
    exit;
}

$loan = Database::fetchOne("
    SELECT l.*, b.first_name, b.last_name, b.customer_number, b.phone, b.email,
           u1.full_name as assigned_name,
           u2.full_name as approved_name,
           u3.full_name as created_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    LEFT JOIN users u1 ON l.assigned_to = u1.id
    LEFT JOIN users u2 ON l.approved_by = u2.id
    LEFT JOIN users u3 ON l.created_by = u3.id
    WHERE l.id = ? AND l.bank_id = ?
", [$id, currentBankId()]);

if (!$loan) {
    setFlash('error', 'Kredit nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/loans/index.php');
    exit;
}

$pageTitle = $loan['file_number'];

// Tatsächliche Restsumme berechnen – Summe aller gematchten Eingänge aus bank_transactions
$paymentSums = Database::fetchOne("
    SELECT COALESCE(SUM(amount), 0) as total_paid
    FROM bank_transactions
    WHERE matched_loan_id = ? AND direction = 'eingehend' AND match_status = 'MATCHED'
", [$id]);
$totalPaid = floatval($paymentSums['total_paid']);
$realOutstanding = round($loan['total_amount'] - $totalPaid);

// outstanding_balance in DB aktualisieren falls abweichend
if (abs($realOutstanding - floatval($loan['outstanding_balance'])) > 0.01) {
    Database::update('loans', ['outstanding_balance' => max(0, $realOutstanding)], 'id = ?', [$id]);
    $loan['outstanding_balance'] = max(0, $realOutstanding);
}

// Prüfen ob Kredit möglicherweise abgeschlossen ist (Restsumme <= 200$)
$possiblyCompleted = $loan['status'] !== 'CLOSED' && $realOutstanding <= 200 && $totalPaid > 0;

// Ratenplan
$scheduleItems = Database::fetchAll("
    SELECT * FROM loan_schedule_items
    WHERE loan_id = ?
    ORDER BY installment_number
", [$id]);

// Zahlungen
$payments = Database::fetchAll("
    SELECT bt.*, bsb.batch_date
    FROM bank_transactions bt
    JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
    WHERE bt.matched_loan_id = ?
    ORDER BY bt.transaction_date DESC
", [$id]);

// Kommunikation
$communications = Database::fetchAll("
    SELECT c.*, u.full_name as created_name
    FROM communications c
    LEFT JOIN users u ON c.created_by = u.id
    WHERE c.loan_id = ?
    ORDER BY c.created_at DESC
", [$id]);

// Sicherheiten
$collaterals = Database::fetchAll("SELECT * FROM collaterals WHERE loan_id = ?", [$id]);

// Audit Log
$auditLog = AuditLog::getForEntity('loan', $id);

// Tab
$activeTab = $_GET['tab'] ?? 'schedule';

// Status-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('loans', 'edit')) {
    if (verifyCsrf()) {
        $action = $_POST['action'] ?? '';
        $oldStatus = $loan['status'];

        switch ($action) {
            case 'activate':
                if (in_array($loan['status'], ['CONTRACT_CREATED', 'APPROVED'])) {
                    Database::update('loans', ['status' => 'ACTIVE'], 'id = ?', [$id]);
                    AuditLog::log('STATUS_CHANGE', 'loan', $id, ['status' => $oldStatus], ['status' => 'ACTIVE']);
                    setFlash('success', 'Kredit aktiviert.');
                }
                break;

            case 'approve':
                if (Auth::can('loans', 'approve') && $loan['status'] === 'IN_REVIEW') {
                    Database::update('loans', [
                        'status' => 'APPROVED',
                        'approved_by' => Auth::userId(),
                        'approved_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$id]);
                    AuditLog::log('APPROVE', 'loan', $id);
                    setFlash('success', 'Kredit genehmigt.');
                }
                break;

            case 'recalculate_payments':
                if (Auth::can('loans', 'edit')) {
                    $result = Matching::recalculatePayments($id);
                    AuditLog::log('RECALCULATE', 'loan', $id, null, $result);
                    setFlash('success', sprintf(
                        'Verrechnung neu berechnet: %d Zahlungen, %s angerechnet, Restschuld: %s',
                        $result['payments_count'],
                        formatMoney($result['total_applied']),
                        formatMoney($result['outstanding'])
                    ));
                }
                break;

            case 'close_settled':
                if (Auth::hasRole('director')) {
                    Database::update('loans', [
                        'status' => 'CLOSED',
                        'outstanding_balance' => 0,
                        'approved_by' => Auth::userId(),
                        'approved_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$id]);

                    // Alle offenen Raten als bezahlt markieren
                    Database::query("
                        UPDATE loan_schedule_items
                        SET status = 'PAID', amount_outstanding = 0
                        WHERE loan_id = ? AND status != 'PAID'
                    ", [$id]);

                    AuditLog::log('STATUS_CHANGE', 'loan', $id,
                        ['status' => $oldStatus],
                        ['status' => 'CLOSED', 'reason' => 'Direktion: Kredit quittiert/abgeschlossen']
                    );
                    setFlash('success', 'Kredit als abgeschlossen quittiert.');
                }
                break;

            case 'dunning_hold':
                if (Auth::can('loans', 'edit') && in_array($loan['status'], ['ACTIVE', 'DUNNING_L1', 'DUNNING_L2', 'TERMINATED'])) {
                    $reason = trim($_POST['dunning_hold_reason'] ?? '');
                    Database::update('loans', [
                        'dunning_hold'        => 1,
                        'dunning_hold_reason' => $reason ?: null,
                    ], 'id = ?', [$id]);
                    AuditLog::log('DUNNING_HOLD', 'loan', $id, null, ['reason' => $reason]);
                    setFlash('success', 'Mahnung ausgesetzt – Klärung mit Support läuft.');
                }
                break;

            case 'dunning_resume':
                if (Auth::can('loans', 'edit')) {
                    Database::update('loans', [
                        'dunning_hold'        => 0,
                        'dunning_hold_reason' => null,
                    ], 'id = ?', [$id]);
                    AuditLog::log('DUNNING_RESUME', 'loan', $id);
                    setFlash('success', 'Mahnverfahren wieder aktiv.');
                }
                break;

            case 'dealer_email_log':
                if ($loan['product_type'] === 'AUTO') {
                    $dealerEmail = trim($_POST['dealer_email'] ?? '');
                    $emailBody   = trim($_POST['email_body'] ?? '');
                    $subject     = 'Fahrzeugfinanzierung – Anfrage Verkaufsunterlagen – ' . $loan['file_number'];
                    Database::insert('communications', [
                        'bank_id'    => currentBankId(),
                        'loan_id'    => $id,
                        'type'       => 'DEALER_EMAIL',
                        'subject'    => $subject,
                        'body'       => $emailBody ?: '(kein Text)',
                        'sent_via'   => 'EMAIL',
                        'created_by' => Auth::userId(),
                    ]);
                    AuditLog::log('DEALER_EMAIL', 'loan', $id, null, ['dealer_email' => $dealerEmail]);
                    setFlash('success', 'Händler-E-Mail protokolliert.');
                }
                break;

            case 'withdraw':
                if (in_array($loan['status'], ['APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED', 'CONTRACT_CREATED'])) {
                    $withdrawalReason = trim($_POST['withdrawal_reason'] ?? '');
                    $noteEntry = '[' . date('d.m.Y') . '] Widerruf durch KD' . ($withdrawalReason ? ': ' . $withdrawalReason : '.');
                    $newNotes = ($loan['notes'] ? rtrim($loan['notes']) . "\n" : '') . $noteEntry;
                    Database::update('loans', [
                        'status' => 'WITHDRAWN',
                        'notes'  => $newNotes,
                    ], 'id = ?', [$id]);
                    AuditLog::log('WIDERRUF', 'loan', $id,
                        ['status' => $oldStatus],
                        ['status' => 'WITHDRAWN', 'reason' => $withdrawalReason]
                    );
                    setFlash('success', 'Kreditantrag wurde widerrufen. Kein negativer Schufa-Eintrag.');
                }
                break;

            case 'delete':
                if (Auth::hasRole('director')) {
                    $fileNumber = $loan['file_number'];

                    // Verknüpfte Bank-Transaktionen lösen
                    Database::query(
                        "UPDATE bank_transactions SET matched_loan_id = NULL, matched_schedule_id = NULL, match_status = 'UNMATCHED', match_method = NULL, match_confidence = NULL, matched_by = NULL, matched_at = NULL WHERE matched_loan_id = ?",
                        [$id]
                    );

                    // Transaction-Matches entfernen
                    Database::query("DELETE FROM transaction_matches WHERE loan_id = ?", [$id]);

                    // Kommunikation entfernen
                    Database::query("DELETE FROM communications WHERE loan_id = ?", [$id]);

                    // Sicherheiten entfernen
                    Database::query("DELETE FROM collaterals WHERE loan_id = ?", [$id]);

                    // Ratenplan entfernen (CASCADE würde auch greifen)
                    Database::query("DELETE FROM loan_schedule_items WHERE loan_id = ?", [$id]);

                    // Kredit löschen
                    Database::query("DELETE FROM loans WHERE id = ?", [$id]);

                    AuditLog::log('DELETE', 'loan', $id, ['file_number' => $fileNumber], null);

                    setFlash('success', "Kredit {$fileNumber} wurde gelöscht.");
                    header('Location: ' . APP_URL . '/pages/loans/index.php');
                    exit;
                }
                break;
        }

        header('Location: ' . APP_URL . '/pages/loans/view.php?id=' . $id);
        exit;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/loans/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0">
            <?php if ($loan['product_type'] === 'INSURANCE'): ?>
            <i class="bi bi-heart-pulse me-2 text-danger"></i>
            <?php else: ?>
            <i class="bi bi-file-earmark-text me-2"></i>
            <?php endif; ?>
            <?= e($loan['file_number']) ?>
            <span class="badge <?= getStatusBadgeClass($loan['status']) ?> ms-2">
                <?= translateLoanStatus($loan['status']) ?>
            </span>
            <?php if ($loan['product_type'] === 'INSURANCE'): ?>
            <span class="badge bg-danger ms-1"><i class="bi bi-heart-pulse me-1"></i>Krankenversicherung</span>
            <?php endif; ?>
        </h4>
        <small class="text-muted">
            <?= translateProductType($loan['product_type']) ?> -
            <?= e($loan['last_name'] . ', ' . $loan['first_name']) ?>
        </small>
    </div>
    <div>
        <?php if (Auth::can('loans', 'edit')): ?>
            <?php if (in_array($loan['status'], ['CONTRACT_CREATED', 'APPROVED'])): ?>
            <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="activate">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-play-fill me-2"></i>Aktivieren
                </button>
            </form>
            <?php endif; ?>
        <a href="<?= APP_URL ?>/pages/loans/edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-2"></i>Bearbeiten
        </a>
        <?php endif; ?>
        <?php if (Auth::can('dunning', 'create') && in_array($loan['status'], ['ACTIVE', 'DUNNING_L1', 'DUNNING_L2'])): ?>
        <a href="<?= APP_URL ?>/pages/collections/create.php?loan_id=<?= $id ?>" class="btn btn-outline-warning">
            <i class="bi bi-envelope me-2"></i>Schreiben erstellen
        </a>
        <?php endif; ?>
        <?php if ($loan['product_type'] === 'AUTO' && Auth::can('loans', 'edit')): ?>
        <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#dealerEmailModal">
            <i class="bi bi-shop me-2"></i>Händler-E-Mail
        </button>
        <?php endif; ?>
        <?php if (Auth::can('loans', 'edit') && in_array($loan['status'], ['APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED', 'CONTRACT_CREATED'])): ?>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#withdrawModal">
            <i class="bi bi-x-circle me-2"></i>Widerruf
        </button>
        <?php endif; ?>
        <?php if (Auth::can('loans', 'edit') && !$loan['dunning_hold'] && in_array($loan['status'], ['ACTIVE', 'DUNNING_L1', 'DUNNING_L2', 'TERMINATED'])): ?>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dunningHoldModal">
            <i class="bi bi-pause-circle me-2"></i>Klärung mit Support
        </button>
        <?php endif; ?>
        <?php if (Auth::hasRole('director')): ?>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteLoanModal">
            <i class="bi bi-trash me-2"></i>Löschen
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($loan['dunning_hold']): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3" role="alert">
    <i class="bi bi-pause-circle-fill fs-4"></i>
    <div class="flex-grow-1">
        <strong>Mahnung ausgesetzt – Klärung mit Support</strong>
        <?php if ($loan['dunning_hold_reason']): ?>
        <br><span class="small"><?= e($loan['dunning_hold_reason']) ?></span>
        <?php endif; ?>
    </div>
    <?php if (Auth::can('loans', 'edit')): ?>
    <form method="POST" class="d-inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="dunning_resume">
        <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-play-fill me-1"></i>Mahnung fortsetzen
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Übersichtskarten -->
<?php $paidPercent = $loan['total_amount'] > 0 ? min(100, round($totalPaid / $loan['total_amount'] * 100)) : 0; ?>
<div class="row g-3 mb-4">
    <div class="col-md col-6">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($loan['loan_amount']) ?></div>
                <div class="kpi-label">Kreditsumme</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= formatMoney($totalPaid) ?></div>
                <div class="kpi-label">Bereits bezahlt</div>
                <?php if ($paidPercent > 0): ?>
                <div class="progress mt-2" style="height:6px">
                    <div class="progress-bar bg-success" style="width:<?= $paidPercent ?>%"></div>
                </div>
                <small class="text-muted"><?= $paidPercent ?>%</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card kpi-card <?= $possiblyCompleted ? 'border-success' : '' ?>">
            <div class="card-body text-center">
                <div class="kpi-value <?= $realOutstanding <= 0 ? 'text-success' : '' ?>"><?= formatMoney(max(0, $realOutstanding)) ?></div>
                <div class="kpi-label">Restschuld</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($loan['weekly_rate']) ?></div>
                <div class="kpi-label">Wochenrate</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card kpi-card <?= $loan['days_overdue'] > 0 ? 'danger' : 'success' ?>">
            <div class="card-body text-center">
                <div class="kpi-value <?= $loan['days_overdue'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $loan['days_overdue'] ?> Tage
                </div>
                <div class="kpi-label">Verzug</div>
            </div>
        </div>
    </div>
</div>

<?php if ($possiblyCompleted): ?>
<!-- Möglicherweise abgeschlossen -->
<div class="alert alert-success d-flex justify-content-between align-items-center mb-4">
    <div>
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <strong>Möglicherweise abgeschlossen</strong> -
        Bezahlt: <?= formatMoney($totalPaid) ?> von <?= formatMoney($loan['total_amount']) ?>
        <?php if ($realOutstanding > 0): ?>
            (Differenz: <?= formatMoney($realOutstanding) ?>)
        <?php else: ?>
            (vollständig ausgeglichen)
        <?php endif; ?>
    </div>
    <?php if (Auth::hasRole('director')): ?>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#closeLoanModal">
        <i class="bi bi-check2-all me-2"></i>Kredit quittieren
    </button>
    <?php else: ?>
    <span class="badge bg-warning text-dark">Direktor-Freigabe erforderlich</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($loan['status'] === 'CLOSED'): ?>
<div class="alert alert-dark d-flex align-items-center mb-4">
    <i class="bi bi-lock-fill me-2 fs-5"></i>
    <strong>Kredit abgeschlossen</strong>
    <?php if ($loan['approved_name']): ?>
     - Quittiert von <?= e($loan['approved_name']) ?> am <?= formatDateTime($loan['approved_at']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($loan['status'] === 'WITHDRAWN'): ?>
<div class="alert alert-secondary d-flex align-items-center mb-4">
    <i class="bi bi-x-circle-fill me-2 fs-4 text-secondary"></i>
    <div>
        <strong>Kreditantrag widerrufen</strong> – Auf Wunsch des Kreditnehmers zurückgezogen.
        <small class="text-muted d-block">Neutraler Vermerk – kein negativer Eintrag in der Kreditauskunft.</small>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'schedule' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=schedule">
            <i class="bi bi-calendar3 me-1"></i>Ratenplan
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'payments' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=payments">
            <i class="bi bi-cash me-1"></i>Zahlungen
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'communications' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=communications">
            <i class="bi bi-envelope me-1"></i>Kommunikation
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=details">
            <i class="bi bi-info-circle me-1"></i>Details
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'documents' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=documents">
            <i class="bi bi-folder2-open me-1"></i>Schreiben
        </a>
    </li>
    <?php if (Auth::can('audit', 'view')): ?>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'audit' ? 'active' : '' ?>"
           href="?id=<?= $id ?>&tab=audit">
            <i class="bi bi-clock-history me-1"></i>Historie
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- Tab Content -->
<div class="tab-content">
    <?php if ($activeTab === 'schedule'): ?>
    <!-- Ratenplan -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Ratenplan (<?= count($scheduleItems) ?> Raten)</span>
            <span class="text-muted">
                <?= formatDate($loan['start_date']) ?> bis <?= formatDate($loan['end_date']) ?>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nr.</th>
                            <th>Fällig am</th>
                            <th>Sollbetrag</th>
                            <th>Bezahlt</th>
                            <th>Offen</th>
                            <th>Status</th>
                            <th>Verzug</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduleItems as $item): ?>
                        <?php
                            $isOverdue = $item['status'] !== 'PAID' && strtotime($item['due_date']) < time();
                            $daysOver = $isOverdue ? floor((time() - strtotime($item['due_date'])) / 86400) : 0;
                        ?>
                        <tr class="<?= $daysOver > 14 ? 'overdue-danger' : ($daysOver > 0 ? 'overdue-warning' : '') ?>">
                            <td><?= $item['installment_number'] ?></td>
                            <td><?= formatDate($item['due_date']) ?></td>
                            <td><?= formatMoney($item['amount_due']) ?></td>
                            <td><?= formatMoney($item['amount_paid']) ?></td>
                            <td><?= formatMoney($item['amount_outstanding']) ?></td>
                            <td>
                                <?php
                                $statusClass = match($item['status']) {
                                    'PAID' => 'bg-success',
                                    'PARTIAL' => 'bg-info',
                                    'OVERDUE' => 'bg-danger',
                                    default => 'bg-secondary'
                                };
                                $statusText = match($item['status']) {
                                    'PAID' => 'Bezahlt',
                                    'PARTIAL' => 'Teilweise',
                                    'OVERDUE' => 'Überfällig',
                                    default => 'Offen'
                                };
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                            </td>
                            <td>
                                <?php if ($daysOver > 0): ?>
                                <span class="badge <?= $daysOver > 14 ? 'bg-danger' : 'bg-warning' ?>">
                                    <?= $daysOver ?> Tage
                                </span>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($activeTab === 'payments'): ?>
    <!-- Zahlungen -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Buchungen</span>
            <?php if (!empty($payments) && Auth::can('loans', 'edit')): ?>
            <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="recalculate_payments">
                <button type="submit" class="btn btn-sm btn-outline-primary" title="Alle Zahlungen neu auf Raten verrechnen">
                    <i class="bi bi-arrow-repeat me-1"></i>Verrechnung neu berechnen
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($payments)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-cash"></i>
                <p class="mb-0">Noch keine Buchungen vorhanden</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Betrag</th>
                            <th>Art</th>
                            <th>Absender / Empfänger</th>
                            <th>Verwendungszweck</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <?php $isOutgoing = ($p['direction'] === 'ausgehend'); ?>
                        <tr class="<?= $p['match_status'] === 'FEE' ? 'table-secondary' : '' ?>">
                            <td><?= formatDate($p['transaction_date']) ?></td>
                            <td class="<?= $p['match_status'] === 'FEE' ? 'text-muted' : ($isOutgoing ? 'text-primary' : 'text-success') ?>">
                                <?= $isOutgoing ? '−' : '+' ?><?= formatMoney($p['amount']) ?>
                            </td>
                            <td>
                                <?php if ($p['match_status'] === 'FEE'): ?>
                                <span class="badge bg-secondary"><i class="bi bi-receipt me-1"></i>Bearbeitungsgebühr</span>
                                <?php elseif ($isOutgoing): ?>
                                <span class="badge bg-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Auszahlung</span>
                                <?php else: ?>
                                <span class="badge bg-success"><i class="bi bi-box-arrow-in-down-left me-1"></i>Zahlung</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($isOutgoing ? ($p['empfaenger_party'] ?? $p['sender_name']) : $p['sender_name']) ?></td>
                            <td><small><?= e(substr($p['reference'] ?? '', 0, 50)) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($activeTab === 'communications'): ?>
    <!-- Kommunikation -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Kommunikation</span>
            <?php if (Auth::can('dunning', 'create')): ?>
            <a href="<?= APP_URL ?>/pages/collections/create.php?loan_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus me-1"></i>Neues Schreiben
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($communications)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-envelope"></i>
                <p class="mb-0">Keine Schreiben vorhanden</p>
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($communications as $comm): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?= e($comm['subject']) ?></h6>
                            <small class="text-muted">
                                <?= formatDateTime($comm['created_at']) ?> von <?= e($comm['created_name']) ?>
                            </small>
                        </div>
                        <?php
                        $commBadge = match($comm['type']) {
                            'DEALER_EMAIL' => ['bg-info text-white', '<i class="bi bi-shop me-1"></i>Händler-E-Mail'],
                            'REMINDER'     => ['bg-warning text-dark', 'Zahlungserinnerung'],
                            'DUNNING_L1'   => ['bg-warning text-dark', 'Mahnung Stufe 1'],
                            'DUNNING_L2'   => ['bg-danger', 'Mahnung Stufe 2'],
                            'TERMINATION'  => ['bg-danger', 'Kündigung'],
                            default        => ['bg-secondary', e($comm['type'])],
                        };
                        ?>
                        <span class="badge <?= $commBadge[0] ?>"><?= $commBadge[1] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($activeTab === 'details'): ?>
    <!-- Details -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Kreditdaten</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><td class="text-muted">Aktenzeichen</td><td><strong><?= e($loan['file_number']) ?></strong></td></tr>
                        <tr><td class="text-muted">Produkttyp</td><td><?= translateProductType($loan['product_type']) ?></td></tr>
                        <tr><td class="text-muted">Kaufpreis</td><td><?= formatMoney($loan['purchase_price']) ?></td></tr>
                        <tr><td class="text-muted">Eigenkapital</td><td><?= formatMoney($loan['down_payment']) ?></td></tr>
                        <tr><td class="text-muted">Kreditsumme</td><td><?= formatMoney($loan['loan_amount']) ?></td></tr>
                        <tr><td class="text-muted">Zinssatz</td><td><?= number_format($loan['interest_rate'] * 100, 1) ?>%</td></tr>
                        <tr><td class="text-muted">Gesamtzins</td><td><?= formatMoney($loan['total_interest']) ?></td></tr>
                        <tr><td class="text-muted">Gesamtsumme</td><td><?= formatMoney($loan['total_amount']) ?></td></tr>
                        <tr><td class="text-muted">Laufzeit</td><td><?= $loan['term_weeks'] ?> Wochen</td></tr>
                        <tr><td class="text-muted">Wochenrate</td><td><?= formatMoney($loan['weekly_rate']) ?></td></tr>
                        <?php if ($loan['custom_final_rate']): ?>
                        <tr><td class="text-muted">Variable Restrate</td><td class="text-primary fw-bold"><?= formatMoney($loan['custom_final_rate']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="text-muted">Vertragsbeginn</td><td><?= formatDate($loan['start_date']) ?></td></tr>
                        <tr><td class="text-muted">Vertragsende</td><td><?= formatDate($loan['end_date']) ?></td></tr>
                        <?php if ($loan['product_type'] === 'AUTO'): ?>
                        <tr>
                            <td class="text-muted"><i class="bi bi-car-front me-1" style="color:#fd7e14"></i>Fahrzeug</td>
                            <td><?= $loan['vehicle_model'] ? e($loan['vehicle_model']) : '<span class="text-muted">–</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="bi bi-sign-stop me-1" style="color:#fd7e14"></i>Kennzeichen</td>
                            <td>
                                <?php if ($loan['vehicle_plate']): ?>
                                <code class="fs-6"><?= e($loan['vehicle_plate']) ?></code>
                                <?php else: ?>
                                <a href="<?= APP_URL ?>/pages/loans/edit.php?id=<?= $id ?>" class="badge bg-warning text-dark text-decoration-none">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Nicht eingetragen – Jetzt ergänzen
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php elseif ($loan['vehicle_model']): ?>
                        <tr><td class="text-muted">Fahrzeugmodell</td><td><?= e($loan['vehicle_model']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Zahlungsinformationen</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td class="text-muted">Zahlungskonto</td>
                            <td><code><?= e($loan['payment_account']) ?: '-' ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Verwendungszweck</td>
                            <td>
                                <code id="paymentRef"><?= e($loan['payment_reference']) ?></code>
                                <button class="btn btn-sm btn-outline-secondary ms-2 copy-btn" data-copy-target="paymentRef">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Kreditnehmer</div>
                <div class="card-body">
                    <p class="mb-1">
                        <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $loan['borrower_id'] ?>">
                            <strong><?= e($loan['first_name'] . ' ' . $loan['last_name']) ?></strong>
                        </a>
                    </p>
                    <p class="text-muted mb-0"><?= e($loan['customer_number']) ?></p>
                    <?php if ($loan['phone']): ?>
                    <p class="mb-0"><i class="bi bi-telephone me-2"></i><?= e($loan['phone']) ?></p>
                    <?php endif; ?>
                    <?php if ($loan['email']): ?>
                    <p class="mb-0"><i class="bi bi-envelope me-2"></i><?= e($loan['email']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Bearbeitung</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Erstellt von</td><td><?= e($loan['created_name'] ?? '-') ?></td></tr>
                        <tr><td class="text-muted">Erstellt am</td><td><?= formatDateTime($loan['created_at']) ?></td></tr>
                        <tr><td class="text-muted">Zugewiesen an</td><td><?= e($loan['assigned_name'] ?? '-') ?></td></tr>
                        <?php if ($loan['approved_by']): ?>
                        <tr><td class="text-muted">Genehmigt von</td><td><?= e($loan['approved_name']) ?></td></tr>
                        <tr><td class="text-muted">Genehmigt am</td><td><?= formatDateTime($loan['approved_at']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($activeTab === 'documents'): ?>
    <!-- Schreiben -->
    <?php
    $loanDocs = Database::fetchAll("
        SELECT d.*, b.first_name, b.last_name, u.full_name as creator_name
        FROM documents d
        LEFT JOIN borrowers b ON d.borrower_id = b.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.loan_id = ?
        ORDER BY d.created_at DESC
    ", [$id]);
    ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Schreiben (<?= count($loanDocs) ?>)</span>
            <a href="<?= APP_URL ?>/pages/documents/create.php?loan_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus me-1"></i>Neues Schreiben
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($loanDocs)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-folder2-open"></i>
                <p class="mb-0">Keine Schreiben vorhanden</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Typ</th>
                            <th>Kreditnehmer</th>
                            <th>Erstellt von</th>
                            <th>Datum</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loanDocs as $ldoc): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $ldoc['id'] ?>">
                                    <?= e($ldoc['title'] ?: $ldoc['original_filename'] ?: 'Ohne Titel') ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $ldc = match($ldoc['doc_type']) { 'UPLOAD'=>'bg-secondary','WRITTEN'=>'bg-primary','TEMPLATE_BASED'=>'bg-info text-dark',default=>'bg-secondary' };
                                $ldl = match($ldoc['doc_type']) { 'UPLOAD'=>'Upload','WRITTEN'=>'Verfasst','TEMPLATE_BASED'=>'Vorlage',default=>$ldoc['doc_type'] };
                                ?>
                                <span class="badge <?= $ldc ?>"><?= $ldl ?></span>
                            </td>
                            <td>
                                <?php if ($ldoc['borrower_id']): ?>
                                <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $ldoc['borrower_id'] ?>">
                                    <?= e($ldoc['last_name'] . ', ' . $ldoc['first_name']) ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($ldoc['creator_name'] ?? '-') ?></td>
                            <td><?= formatDate($ldoc['created_at']) ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $ldoc['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($activeTab === 'audit' && Auth::can('audit', 'view')): ?>
    <!-- Audit Log -->
    <div class="card">
        <div class="card-header">Änderungshistorie</div>
        <div class="card-body">
            <?php if (empty($auditLog)): ?>
            <div class="empty-state py-4">
                <i class="bi bi-clock-history"></i>
                <p class="mb-0">Keine Einträge</p>
            </div>
            <?php else: ?>
            <div class="timeline">
                <?php foreach ($auditLog as $entry): ?>
                <div class="timeline-item">
                    <div class="d-flex justify-content-between">
                        <strong><?= e($entry['action']) ?></strong>
                        <small class="text-muted"><?= formatDateTime($entry['created_at']) ?></small>
                    </div>
                    <div class="text-muted small">
                        von <?= e($entry['full_name'] ?? 'System') ?>
                        <?php if ($entry['ip_address']): ?>
                        (<?= e($entry['ip_address']) ?>)
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (Auth::hasRole('director') && $possiblyCompleted): ?>
<!-- Kredit quittieren -->
<div class="modal fade" id="closeLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check2-all me-2"></i>Kredit quittieren</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Soll der Kredit <strong><?= e($loan['file_number']) ?></strong> als abgeschlossen quittiert werden?</p>
                <table class="table table-sm">
                    <tr><td class="text-muted">Gesamtsumme</td><td><?= formatMoney($loan['total_amount']) ?></td></tr>
                    <tr><td class="text-muted">Bezahlt</td><td class="text-success"><?= formatMoney($totalPaid) ?></td></tr>
                    <tr><td class="text-muted">Differenz</td><td><?= $realOutstanding > 0 ? formatMoney($realOutstanding) : '<span class="text-success">Ausgeglichen</span>' ?></td></tr>
                </table>
                <?php if ($realOutstanding > 0): ?>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Es besteht eine Restdifferenz von <?= formatMoney($realOutstanding) ?>. Mit der Quittierung wird diese als beglichen verbucht.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="close_settled">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-all me-2"></i>Kredit quittieren
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Auth::hasRole('director')): ?>
<!-- Löschen-Bestätigung -->
<div class="modal fade" id="deleteLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Kredit löschen</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Soll der Kredit <strong><?= e($loan['file_number']) ?></strong> wirklich gelöscht werden?</p>
                <ul class="text-muted small">
                    <li>Kreditnehmer: <?= e($loan['first_name'] . ' ' . $loan['last_name']) ?></li>
                    <li>Kreditsumme: <?= formatMoney($loan['loan_amount']) ?></li>
                    <li>Status: <?= translateLoanStatus($loan['status']) ?></li>
                </ul>
                <div class="alert alert-danger small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Ratenplan, Sicherheiten und Kommunikation werden entfernt. Verknüpfte Zahlungen werden wieder als offen markiert. Diese Aktion kann nicht rückgängig gemacht werden.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Endgültig löschen
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Auth::can('loans', 'edit') && in_array($loan['status'], ['APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED', 'CONTRACT_CREATED'])): ?>
<!-- Widerruf Modal -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="withdraw">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Kreditantrag widerrufen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Der Kreditantrag <strong><?= e($loan['file_number']) ?></strong> wird auf Wunsch des Kreditnehmers zurückgezogen.</p>
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Ein Widerruf wird als <strong>neutraler Vermerk</strong> in der Kreditauskunft geführt – <strong>kein negativer Eintrag</strong>. Er zeigt jedoch an, dass der Kreditnehmer Anträge auch zurückzieht.
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Grund des Widerrufs <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control" name="withdrawal_reason"
                               placeholder="z.B. Kauf nicht zustande gekommen, anderweitig finanziert, …"
                               maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-2"></i>Antrag widerrufen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Klärung mit Support Modal -->
<div class="modal fade" id="dunningHoldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="dunning_hold">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pause-circle me-2"></i>Klärung mit Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Die Mahnung für Kredit <strong><?= e($loan['file_number']) ?></strong> wird ausgesetzt, bis die Klärung abgeschlossen ist.</p>
                    <div class="mb-3">
                        <label class="form-label">Grund / Notiz <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control" name="dunning_hold_reason"
                               placeholder="z.B. Zahlungsstreit, Datenabgleich, ..."
                               maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-pause-circle me-2"></i>Mahnung aussetzen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($loan['product_type'] === 'AUTO' && Auth::can('loans', 'edit')): ?>
<?php
$bankName      = Auth::bank()['name'] ?? 'Pacific Standard Bank';
$currentUser   = Auth::user();
$preKreditnehmer = trim($loan['first_name'] . ' ' . $loan['last_name']);
$preFahrzeug     = $loan['vehicle_model'] ?? '';
$preVertrag      = $loan['file_number'];
$preMitarbeiter  = $currentUser['full_name'] ?? '';
$preEmail        = $currentUser['email'] ?? '';
?>
<!-- Händler-E-Mail Modal -->
<div class="modal fade" id="dealerEmailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-shop me-2"></i>Händler-E-Mail – <?= e($preVertrag) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">An (Händler-E-Mail)</label>
                        <input type="email" class="form-control" id="de_dealer_email" placeholder="haendler@autohaus.de">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Kreditnehmer</label>
                        <input type="text" class="form-control de-field" data-key="NAME_KREDITNEHMER"
                               value="<?= e($preKreditnehmer) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fahrzeugmodell</label>
                        <input type="text" class="form-control de-field" data-key="FAHRZEUGMODELL"
                               value="<?= e($preFahrzeug) ?>" placeholder="z.B. Toyota Corolla 2023">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Vertragsnummer</label>
                        <input type="text" class="form-control de-field" data-key="VERTRAGSNUMMER"
                               value="<?= e($preVertrag) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Name Bankmitarbeiter</label>
                        <input type="text" class="form-control de-field" data-key="NAME_BANKMITARBEITER"
                               value="<?= e($preMitarbeiter) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Position</label>
                        <input type="text" class="form-control de-field" data-key="POSITION"
                               placeholder="z.B. Kreditberater">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Telefon</label>
                        <input type="text" class="form-control de-field" data-key="TELEFON"
                               placeholder="+1 555 000">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">E-Mail (Absender)</label>
                        <input type="text" class="form-control de-field" data-key="EMAIL"
                               value="<?= e($preEmail) ?>">
                    </div>
                </div>

                <label class="form-label fw-semibold">E-Mail-Text (bearbeitbar)</label>
                <textarea class="form-control font-monospace" id="de_email_body" rows="22" style="font-size:.82rem"></textarea>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" onclick="deCopyText()">
                    <i class="bi bi-clipboard me-1"></i>Text kopieren
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    <a href="#" id="de_mailto_btn" class="btn btn-info text-white">
                        <i class="bi bi-envelope-arrow-up me-1"></i>In E-Mail-Client öffnen
                    </a>
                    <form method="POST" id="de_log_form" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="dealer_email_log">
                        <input type="hidden" name="dealer_email" id="de_log_dealer_email">
                        <input type="hidden" name="email_body" id="de_log_body">
                        <button type="submit" class="btn btn-success" onclick="dePopulateLog()">
                            <i class="bi bi-check2 me-1"></i>Protokollieren
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var bankName = <?= json_encode($bankName) ?>;
    var tpl = "Sehr geehrte Damen und Herren,\n\n" +
        "im Zusammenhang mit einer durch die " + bankName + " begleiteten Fahrzeugfinanzierung bitten wir Sie um die Übermittlung der entsprechenden Verkaufsunterlagen.\n\n" +
        "Kreditnehmer:\n{NAME_KREDITNEHMER}\n\n" +
        "Fahrzeug:\n{FAHRZEUGMODELL}\n\n" +
        "Vertragsnummer der Finanzierung:\n{VERTRAGSNUMMER}\n\n" +
        "Nach unseren Unterlagen wurde das vereinbarte Eigenkapital bereits durch den Kreditnehmer an Ihr Autohaus entrichtet. Die verbleibende Finanzierungssumme wird durch die " + bankName + " übernommen und nach Eingang der entsprechenden Rechnung umgehend durch uns beglichen.\n\n" +
        "Wir bitten Sie daher, uns folgende Unterlagen und Informationen zukommen zu lassen:\n\n" +
        "• die Rechnung über den verbleibenden Kaufbetrag unter Angabe der oben genannten Vertragsnummer\n" +
        "• das zugeteilte Kennzeichen des Fahrzeugs\n" +
        "• eine kurze Bestätigung über die Fahrzeugzulassung / Übergabebereitschaft\n\n" +
        "Nach Abschluss der Zahlung werden wir ein autorisiertes Unternehmen damit beauftragen, das Fahrzeug auf ein Schlüsselkartensystem umzustellen. Das Fahrzeug verbleibt bis zur vollständigen Tilgung der Finanzierung im Eigentum der " + bankName + ".\n\n" +
        "Bitte senden Sie die Rechnung sowie die Fahrzeugdaten an diese E-Mail-Adresse oder setzen Sie sich bei Rückfragen gerne direkt mit uns in Verbindung.\n\n" +
        "Wir danken Ihnen für die Zusammenarbeit.\n\n" +
        "Mit freundlichen Grüßen\n\n" +
        "{NAME_BANKMITARBEITER}\n{POSITION}\n" + bankName + "\n\n" +
        "📞 {TELEFON}\n✉️ {EMAIL}";

    function deRender() {
        var text = tpl;
        document.querySelectorAll('.de-field').forEach(function (inp) {
            text = text.split('{' + inp.dataset.key + '}').join(inp.value || ('{' + inp.dataset.key + '}'));
        });
        document.getElementById('de_email_body').value = text;
        deUpdateMailto();
    }

    function deUpdateMailto() {
        var dealerEmail = document.getElementById('de_dealer_email').value;
        var subject     = 'Fahrzeugfinanzierung – Anfrage Verkaufsunterlagen – ' +
                          document.querySelector('[data-key="VERTRAGSNUMMER"]').value;
        var body        = document.getElementById('de_email_body').value;
        var btn         = document.getElementById('de_mailto_btn');
        btn.href = 'mailto:' + encodeURIComponent(dealerEmail) +
                   '?subject=' + encodeURIComponent(subject) +
                   '&body=' + encodeURIComponent(body);
    }

    document.querySelectorAll('.de-field').forEach(function (inp) {
        inp.addEventListener('input', deRender);
    });
    document.getElementById('de_dealer_email').addEventListener('input', deUpdateMailto);
    document.getElementById('de_email_body').addEventListener('input', deUpdateMailto);

    document.getElementById('dealerEmailModal').addEventListener('show.bs.modal', deRender);

    window.deCopyText = function () {
        var ta = document.getElementById('de_email_body');
        ta.select();
        document.execCommand('copy');
        var btn = document.querySelector('[onclick="deCopyText()"]');
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Kopiert!';
        setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Text kopieren'; }, 2000);
    };

    window.dePopulateLog = function () {
        document.getElementById('de_log_dealer_email').value = document.getElementById('de_dealer_email').value;
        document.getElementById('de_log_body').value = document.getElementById('de_email_body').value;
        return true;
    };
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
