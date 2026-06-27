<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kundenkonto Detail
 */
$pageTitle = 'Kontodetails';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

require_once __DIR__ . '/../../classes/Matching.php';

$accountId = intval($_GET['id'] ?? 0);
if (!$accountId) {
    setFlash('error', 'Keine Konto-ID angegeben.');
    header('Location: ' . APP_URL . '/pages/accounts/index.php');
    exit;
}

$account = Database::fetchOne("SELECT * FROM customer_accounts WHERE id = ?", [$accountId]);
if (!$account) {
    setFlash('error', 'Konto nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/accounts/index.php');
    exit;
}

// Manuelle Kredit-Zuordnung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $bankTxId = intval($_POST['bank_transaction_id'] ?? 0);
    $loanId = intval($_POST['loan_id'] ?? 0);

    if ($bankTxId && $loanId) {
        $schedule = Database::fetchOne(
            "SELECT id FROM loan_schedule_items WHERE loan_id = ? AND status IN ('PENDING', 'PARTIAL', 'OVERDUE') ORDER BY due_date LIMIT 1",
            [$loanId]
        );

        Matching::applyMatch($bankTxId, $loanId, $schedule['id'] ?? null, 'MANUAL', 1.0);
        AuditLog::log('MANUAL_MATCH', 'bank_transaction', $bankTxId, null, ['loan_id' => $loanId]);

        setFlash('success', 'Zahlung erfolgreich dem Kredit zugeordnet.');
    }
    header('Location: ' . APP_URL . '/pages/accounts/view.php?id=' . $accountId);
    exit;
}

// Transaktionen laden (mit Bank-Transaktions-Info für Zuordnung)
$transactions = Database::fetchAll("
    SELECT at.*,
           bt.id as bank_tx_id,
           bt.match_status,
           bt.matched_loan_id,
           bt.amount as bank_amount,
           bt.sender_name,
           bt.reference as bank_reference,
           bt.direction as bank_direction,
           l.file_number as matched_file_number
    FROM account_transactions at
    LEFT JOIN bank_transactions bt ON at.bank_transaction_id = bt.id
    LEFT JOIN loans l ON bt.matched_loan_id = l.id
    WHERE at.account_id = ?
    ORDER BY at.transaction_date DESC, at.transaction_time DESC
    LIMIT 200
", [$accountId]);

// Aktive Kredite für manuelle Zuordnung laden
$activeLoans = Database::fetchAll("
    SELECT l.id, l.file_number, l.weekly_rate, l.loan_amount, l.status, b.first_name, b.last_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.status IN ('ACTIVE', 'CONTRACT_CREATED', 'DUNNING_L1', 'DUNNING_L2')
    ORDER BY b.last_name, b.first_name
");

// Zusammenfassungen nach Typ
$summaryByType = Database::fetchAll("
    SELECT fee_type,
           COUNT(*) as count,
           SUM(amount) as total
    FROM account_transactions
    WHERE account_id = ?
    GROUP BY fee_type
    ORDER BY fee_type
", [$accountId]);

// Monatliche Zusammenfassung
$monthlyStats = Database::fetchAll("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month,
           SUM(CASE WHEN fee_type = 'TRANSFER' THEN amount ELSE 0 END) as transfer_fees,
           SUM(CASE WHEN fee_type = 'WEEKLY' THEN amount ELSE 0 END) as weekly_fees,
           SUM(amount) as total,
           COUNT(*) as tx_count
    FROM account_transactions
    WHERE account_id = ?
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY month DESC
", [$accountId]);

// Kredit-Score berechnen
$creditScore = CreditScore::calculate($account);

// Verknüpfter Kreditnehmer + Kredite
$borrower = null;
$borrowerLoans = [];
if (!empty($account['borrower_id'])) {
    $borrower = Database::fetchOne("SELECT * FROM borrowers WHERE id = ?", [$account['borrower_id']]);
    if ($borrower) {
        $borrowerLoans = Database::fetchAll("
            SELECT l.*,
                   (SELECT SUM(amount_outstanding) FROM loan_schedule_items WHERE loan_id = l.id AND status != 'PAID') as open_amount
            FROM loans l
            WHERE l.borrower_id = ?
            ORDER BY l.created_at DESC
        ", [$borrower['id']]);
    }
}
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/accounts/index.php">Kundenkonten</a></li>
                <li class="breadcrumb-item active"><?= e($account['account_number']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h4>
            <i class="bi bi-wallet2 me-2"></i>
            <code><?= e($account['account_number']) ?></code>
            <span class="badge <?= AccountManager::getTypeBadgeClass($account['account_type']) ?> ms-2">
                <?= AccountManager::translateAccountType($account['account_type']) ?>
            </span>
            <span class="badge <?= $account['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?> ms-1">
                <?= $account['status'] === 'ACTIVE' ? 'Aktiv' : 'Geschlossen' ?>
            </span>
        </h4>
        <a href="<?= APP_URL ?>/pages/accounts/edit.php?id=<?= $accountId ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-2"></i>Bearbeiten
        </a>
    </div>
</div>

<!-- Kontoinformationen -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Kontoinformationen</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Kontonummer</td>
                        <td><code><?= e($account['account_number']) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Bezeichnung</td>
                        <td><?= e($account['account_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kontotyp</td>
                        <td><?= e($account['account_type_label']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wochengebühr</td>
                        <td><?= formatMoney($account['weekly_fee']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Eröffnungsgebühr</td>
                        <td><?= formatMoney($account['opening_fee']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Eröffnungsdatum</td>
                        <td><?= $account['opening_date'] ? formatDate($account['opening_date']) : '<span class="text-muted">Unbekannt</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Inhaber</td>
                        <td><?= e($account['owner_name']) ?: '<span class="text-muted">-</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Telefon</td>
                        <td><?= e($account['owner_phone']) ?: '<span class="text-muted">-</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">E-Mail</td>
                        <td><?= e($account['owner_email']) ?: '<span class="text-muted">-</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Erstellt</td>
                        <td><?= formatDateTime($account['created_at']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-stack me-2"></i>Gebühren-Übersicht</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Gesamtgebühren</td>
                        <td class="text-success fw-bold"><?= formatMoney($account['total_fees_paid']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Überweisungsgebühren</td>
                        <td><?= formatMoney($account['total_transfer_fees']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kontoführungsgebühren</td>
                        <td><?= formatMoney($account['total_weekly_fees']) ?></td>
                    </tr>
                    <?php foreach ($summaryByType as $sum): ?>
                    <tr>
                        <td class="text-muted"><?= AccountManager::translateFeeType($sum['fee_type']) ?> (Anz.)</td>
                        <td><?= $sum['count'] ?>x = <?= formatMoney($sum['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-calendar3 me-2"></i>Monatliche Übersicht</div>
            <div class="card-body p-0">
                <?php if (empty($monthlyStats)): ?>
                <div class="text-center py-3 text-muted">Keine Daten vorhanden</div>
                <?php else: ?>
                <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Monat</th>
                                <th>Überw.</th>
                                <th>Kontof.</th>
                                <th>Gesamt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyStats as $ms): ?>
                            <tr>
                                <td><?= date('m/Y', strtotime($ms['month'] . '-01')) ?></td>
                                <td><?= formatMoney($ms['transfer_fees']) ?></td>
                                <td><?= formatMoney($ms['weekly_fees']) ?></td>
                                <td class="fw-bold"><?= formatMoney($ms['total']) ?></td>
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

<!-- Kredit-Score -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-speedometer2 me-2"></i>Kredit-Score
    </div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-3 text-center">
                <div class="display-4 fw-bold <?= CreditScore::getScoreClass($creditScore['total']) ?>">
                    <?= $creditScore['total'] ?>
                </div>
                <div class="text-muted">von 100 Punkten</div>
                <span class="badge <?= CreditScore::getScoreBgClass($creditScore['total']) ?> mt-1">
                    <?= CreditScore::getScoreLabel($creditScore['total']) ?>
                </span>
            </div>
            <div class="col-md-9">
                <div class="mb-3">
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar <?= CreditScore::getScoreBgClass($creditScore['total']) ?>"
                             style="width: <?= $creditScore['total'] ?>%">
                            <?= $creditScore['total'] ?>%
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Gebühren-Treue</small>
                            <small class="fw-bold"><?= $creditScore['fee_regularity'] ?> / 30</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?= round($creditScore['fee_regularity'] / 30 * 100) ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Kontoaktivität</small>
                            <small class="fw-bold"><?= $creditScore['activity'] ?> / 20</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?= round($creditScore['activity'] / 20 * 100) ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Kontoalter</small>
                            <small class="fw-bold"><?= $creditScore['account_age'] ?> / 20</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?= round($creditScore['account_age'] / 20 * 100) ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Kontotyp</small>
                            <small class="fw-bold"><?= $creditScore['account_type'] ?> / 20</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?= round($creditScore['account_type'] / 20 * 100) ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Gehaltseingang</small>
                            <small class="fw-bold"><?= $creditScore['salary_income'] ?> / 10</small>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?= round($creditScore['salary_income'] / 10 * 100) ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($borrower): ?>
<!-- Verknüpfter Kreditnehmer -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-person-badge me-2"></i>Verknüpfter Kreditnehmer
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted" style="width:40%">Kundennummer</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $borrower['id'] ?>">
                                <strong><?= e($borrower['customer_number']) ?></strong>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Name</td>
                        <td><?= e($borrower['first_name'] . ' ' . $borrower['last_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Telefon</td>
                        <td><?= e($borrower['phone']) ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">E-Mail</td>
                        <td><?= e($borrower['email']) ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wocheneinkommen</td>
                        <td><?= $borrower['weekly_income'] ? formatMoney($borrower['weekly_income']) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-file-earmark-text me-2"></i>Kredite (<?= count($borrowerLoans) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($borrowerLoans)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-file-earmark-x d-block mb-2" style="font-size: 1.5rem;"></i>
                    Keine Kredite vorhanden
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Aktenzeichen</th>
                                <th>Kreditsumme</th>
                                <th>Offen</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowerLoans as $loan): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>">
                                        <?= e($loan['file_number']) ?>
                                    </a>
                                </td>
                                <td><?= formatMoney($loan['loan_amount']) ?></td>
                                <td><?= formatMoney($loan['open_amount'] ?? 0) ?></td>
                                <td>
                                    <span class="badge <?= getStatusBadgeClass($loan['status']) ?>">
                                        <?= translateLoanStatus($loan['status']) ?>
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
<?php endif; ?>

<!-- Transaktionshistorie -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>Transaktionshistorie (<?= count($transactions) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($transactions)): ?>
        <div class="empty-state py-4">
            <i class="bi bi-receipt text-muted"></i>
            <p class="mb-0">Keine Transaktionen vorhanden</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Uhrzeit</th>
                        <th>Typ</th>
                        <th>Betrag</th>
                        <th>Beschreibung</th>
                        <th>Kredit-Zuordnung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <?php
                    $typeBadge = match($tx['fee_type']) {
                        'OPENING' => 'bg-info',
                        'TRANSFER' => 'bg-primary',
                        'WEEKLY' => 'bg-warning text-dark',
                        'SALARY' => 'bg-success',
                        'DEPOSIT' => 'bg-success',
                        'WITHDRAWAL' => 'bg-danger',
                        'PAYMENT' => 'bg-primary',
                        default => 'bg-secondary'
                    };
                    $isOut = ($tx['direction'] ?? 'IN') === 'OUT';
                    $hasBank = !empty($tx['bank_tx_id']);
                    $isMatched = $hasBank && $tx['match_status'] === 'MATCHED';
                    $isUnmatched = $hasBank && in_array($tx['match_status'], ['UNMATCHED', 'AMBIGUOUS']);
                    ?>
                    <tr class="<?= $isUnmatched ? 'table-warning' : '' ?>">
                        <td><?= formatDate($tx['transaction_date']) ?></td>
                        <td><?= $tx['transaction_time'] ? substr($tx['transaction_time'], 0, 5) : '-' ?></td>
                        <td>
                            <span class="badge <?= $typeBadge ?>">
                                <?= AccountManager::translateFeeType($tx['fee_type']) ?>
                            </span>
                        </td>
                        <td class="<?= $isOut ? 'text-danger' : 'text-success' ?>">
                            <?= $isOut ? '-' : '+' ?><?= formatMoney($tx['amount']) ?>
                        </td>
                        <td><small class="text-muted"><?= e($tx['description']) ?></small></td>
                        <td>
                            <?php if ($isMatched): ?>
                                <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $tx['matched_loan_id'] ?>" class="badge bg-success text-decoration-none">
                                    <i class="bi bi-check-circle me-1"></i><?= e($tx['matched_file_number']) ?>
                                </a>
                            <?php elseif ($isUnmatched): ?>
                                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal"
                                        data-bs-target="#matchModal"
                                        data-tx-id="<?= $tx['bank_tx_id'] ?>"
                                        data-tx-amount="<?= formatMoney($tx['amount']) ?>"
                                        data-tx-date="<?= formatDate($tx['transaction_date']) ?>"
                                        data-tx-desc="<?= e($tx['description']) ?>">
                                    <i class="bi bi-link-45deg me-1"></i>Zuordnen
                                </button>
                            <?php else: ?>
                                <small class="text-muted">-</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Zuordnungs-Modal -->
<div class="modal fade" id="matchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="bank_transaction_id" id="match_tx_id">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Zahlung einem Kredit zuordnen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3">
                        <table class="table table-sm mb-0">
                            <tr>
                                <td class="text-muted" style="width:35%">Datum</td>
                                <td id="match_tx_date" class="fw-bold"></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Betrag</td>
                                <td id="match_tx_amount" class="fw-bold text-success"></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Beschreibung</td>
                                <td id="match_tx_desc"></td>
                            </tr>
                        </table>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Kredit auswählen *</label>
                        <select class="form-select" name="loan_id" id="match_loan_id" required>
                            <option value="">-- Kredit wählen --</option>
                            <?php foreach ($activeLoans as $loan): ?>
                            <option value="<?= $loan['id'] ?>">
                                <?= e($loan['file_number']) ?> - <?= e($loan['last_name']) ?>, <?= e($loan['first_name']) ?>
                                (Rate: <?= formatMoney($loan['weekly_rate']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-2"></i>Zuordnen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('matchModal')?.addEventListener('show.bs.modal', function(event) {
    const btn = event.relatedTarget;
    document.getElementById('match_tx_id').value = btn.dataset.txId;
    document.getElementById('match_tx_date').textContent = btn.dataset.txDate;
    document.getElementById('match_tx_amount').textContent = btn.dataset.txAmount;
    document.getElementById('match_tx_desc').textContent = btn.dataset.txDesc;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
