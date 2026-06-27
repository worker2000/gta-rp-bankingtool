<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kreditnehmer Details
 */
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('borrowers', 'view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/borrowers/index.php');
    exit;
}

$borrower = Database::fetchOne("SELECT * FROM borrowers WHERE id = ? AND bank_id = ?", [$id, currentBankId()]);
if (!$borrower) {
    setFlash('error', 'Kreditnehmer nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/borrowers/index.php');
    exit;
}

$pageTitle = $borrower['last_name'] . ', ' . $borrower['first_name'];

// Kredite des Kreditnehmers
$loans = Database::fetchAll("
    SELECT l.*,
           (SELECT SUM(amount_outstanding) FROM loan_schedule_items WHERE loan_id = l.id AND status != 'PAID') as open_amount
    FROM loans l
    WHERE l.borrower_id = ? AND l.bank_id = ?
    ORDER BY l.created_at DESC
", [$id, currentBankId()]);

// Verknüpfte Bankkonten
$linkedAccounts = Database::fetchAll("
    SELECT * FROM customer_accounts WHERE borrower_id = ? ORDER BY account_number
", [$id]);

// Audit-Log
$auditLog = AuditLog::getForEntity('borrower', $id);

// Schließfächer (beide Banken) + KV-Verträge (nur FF)
$borrowerSafeboxes = Database::fetchAll(
    "SELECT * FROM safeboxes WHERE borrower_id = ? ORDER BY status ASC, box_number ASC",
    [$id]
);
$borrowerInsurance = [];
if (currentBankId() === 2) {
    $borrowerInsurance = Database::fetchAll("
        SELECT ic.*, ip.name as product_name
        FROM insurance_contracts ic
        JOIN insurance_products ip ON ic.product_id = ip.id
        WHERE ic.borrower_id = ?
        ORDER BY ic.created_at DESC
    ", [$id]);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/borrowers/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-person me-2"></i><?= e($borrower['last_name']) ?>, <?= e($borrower['first_name']) ?>
        </h4>
        <small class="text-muted"><?= e($borrower['customer_number']) ?></small>
        <?php if (!empty($borrower['legacy_customer_number'])): ?>
        <small class="text-muted ms-2" title="Alte Kundennummer (Vorsystem)">
            · <i class="bi bi-clock-history me-1"></i><?= e($borrower['legacy_customer_number']) ?>
        </small>
        <?php endif; ?>
    </div>
    <div>
        <?php if (Auth::can('borrowers', 'edit')): ?>
        <a href="<?= APP_URL ?>/pages/borrowers/edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-2"></i>Bearbeiten
        </a>
        <?php endif; ?>
        <?php if (Auth::can('loans', 'create')): ?>
        <a href="<?= APP_URL ?>/pages/loans/create.php?borrower_id=<?= $id ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Neuer Kredit
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Stammdaten -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-person-badge me-2"></i>Stammdaten
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td class="text-muted" style="width:40%">Kundennummer</td>
                        <td><strong><?= e($borrower['customer_number']) ?></strong></td>
                    </tr>
                    <?php if (!empty($borrower['legacy_customer_number'])): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.08)">
                        <td class="text-muted">
                            <i class="bi bi-clock-history me-1"></i>Alte Kundennr.
                        </td>
                        <td>
                            <code class="text-warning"><?= e($borrower['legacy_customer_number']) ?></code>
                            <span class="badge bg-secondary ms-1">Vorsystem</span>
                        </td>
                    </tr>
                    <?php if (!empty($borrower['legacy_created_at'])): ?>
                    <tr>
                        <td class="text-muted"><i class="bi bi-clock-history me-1"></i>Angelegt am</td>
                        <td><?= formatDateTime($borrower['legacy_created_at']) ?>
                            <?php if (!empty($borrower['legacy_created_by'])): ?>
                            <span class="text-muted ms-1">von <strong><?= e($borrower['legacy_created_by']) ?></strong></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($borrower['customer_type'])): ?>
                    <tr style="border-bottom: 2px solid rgba(255,255,255,0.12)">
                        <td class="text-muted"><i class="bi bi-person-badge me-1"></i>Kundentyp</td>
                        <td><?= e($borrower['customer_type']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Anrede</td>
                        <td><?= e($borrower['salutation'] ?? '') ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Name</td>
                        <td><?= e($borrower['first_name'] . ' ' . $borrower['last_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Geburtsdatum</td>
                        <td><?= formatDate($borrower['date_of_birth']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Telefon</td>
                        <td><?= e($borrower['phone']) ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">E-Mail</td>
                        <td><?= e($borrower['email']) ?: '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Finanzen -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-cash-stack me-2"></i>Finanzen & Arbeit
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td class="text-muted" style="width:40%">Arbeitgeber</td>
                        <td><?= e($borrower['employer']) ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Unternehmen</td>
                        <td><?= e($borrower['company']) ?: '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wocheneinkommen</td>
                        <td><?= $borrower['weekly_income'] ? formatMoney($borrower['weekly_income']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">IBAN</td>
                        <td><code><?= e($borrower['bank_account_iban']) ?: '-' ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kontoinhaber</td>
                        <td><?= e($borrower['bank_account_holder']) ?: '-' ?></td>
                    </tr>
                </table>

                <?php if ($borrower['notes']): ?>
                <hr>
                <h6 class="text-muted">Notizen</h6>
                <p class="mb-0"><?= nl2br(e($borrower['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Kredite -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-file-earmark-text me-2"></i>Kredite (<?= count($loans) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($loans)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-file-earmark-x"></i>
                    <p class="mb-0">Keine Kredite vorhanden</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Aktenzeichen</th>
                                <th>Produkt</th>
                                <th>Kreditsumme</th>
                                <th>Offen</th>
                                <th>Status</th>
                                <th>Erstellt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>">
                                        <?= e($loan['file_number']) ?>
                                    </a>
                                </td>
                                <td><?= translateProductType($loan['product_type']) ?></td>
                                <td><?= formatMoney($loan['loan_amount']) ?></td>
                                <td><?= formatMoney($loan['open_amount'] ?? 0) ?></td>
                                <td>
                                    <span class="badge <?= getStatusBadgeClass($loan['status']) ?>">
                                        <?= translateLoanStatus($loan['status']) ?>
                                    </span>
                                </td>
                                <td><?= formatDate($loan['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Verknüpfte Bankkonten -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-wallet2 me-2"></i>Bankkonten (<?= count($linkedAccounts) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($linkedAccounts)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-wallet2 text-muted"></i>
                    <p class="mb-0">Keine Konten verknüpft</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Kontonummer</th>
                                <th>Bezeichnung</th>
                                <th>Typ</th>
                                <th>Gesamtgebühren</th>
                                <th>Score</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linkedAccounts as $acc): ?>
                            <?php $accScore = CreditScore::calculate($acc); ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/accounts/view.php?id=<?= $acc['id'] ?>">
                                        <code><?= e($acc['account_number']) ?></code>
                                    </a>
                                </td>
                                <td><?= e($acc['account_name']) ?></td>
                                <td>
                                    <span class="badge <?= AccountManager::getTypeBadgeClass($acc['account_type']) ?>">
                                        <?= AccountManager::translateAccountType($acc['account_type']) ?>
                                    </span>
                                </td>
                                <td class="text-success"><?= formatMoney($acc['total_fees_paid']) ?></td>
                                <td>
                                    <span class="fw-bold <?= CreditScore::getScoreClass($accScore['total']) ?>">
                                        <?= $accScore['total'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $acc['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= $acc['status'] === 'ACTIVE' ? 'Aktiv' : 'Geschlossen' ?>
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

    <!-- Schreiben -->
    <?php
    $borrowerDocs = Database::fetchAll("
        SELECT d.*, l.file_number, u.full_name as creator_name
        FROM documents d
        LEFT JOIN loans l ON d.loan_id = l.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.borrower_id = ?
        ORDER BY d.created_at DESC
        LIMIT 20
    ", [$id]);
    ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-folder2-open me-2"></i>Schreiben (<?= count($borrowerDocs) ?>)</span>
                <a href="<?= APP_URL ?>/pages/documents/create.php?borrower_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus me-1"></i>Neues Schreiben
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($borrowerDocs)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-folder2-open text-muted"></i>
                    <p class="mb-0">Keine Schreiben vorhanden</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Titel</th>
                                <th>Typ</th>
                                <th>Kredit-Bezug</th>
                                <th>Erstellt von</th>
                                <th>Datum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowerDocs as $bdoc): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $bdoc['id'] ?>">
                                        <?= e($bdoc['title'] ?: $bdoc['original_filename'] ?: 'Ohne Titel') ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $bc = match($bdoc['doc_type']) { 'UPLOAD'=>'bg-secondary','WRITTEN'=>'bg-primary','TEMPLATE_BASED'=>'bg-info text-dark',default=>'bg-secondary' };
                                    $bl = match($bdoc['doc_type']) { 'UPLOAD'=>'Upload','WRITTEN'=>'Verfasst','TEMPLATE_BASED'=>'Vorlage',default=>$bdoc['doc_type'] };
                                    ?>
                                    <span class="badge <?= $bc ?>"><?= $bl ?></span>
                                </td>
                                <td>
                                    <?php if ($bdoc['loan_id']): ?>
                                    <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $bdoc['loan_id'] ?>">
                                        <?= e($bdoc['file_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($bdoc['creator_name'] ?? '-') ?></td>
                                <td><?= formatDate($bdoc['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Schließfächer -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-safe me-2"></i>Schließfächer (<?= count($borrowerSafeboxes) ?>)</span>
                <a href="<?= APP_URL ?>/pages/safeboxes/create.php?borrower_id=<?= $id ?>"
                   class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus me-1"></i>Neues Schließfach
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($borrowerSafeboxes)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-safe text-muted"></i>
                    <p class="mb-0">Keine Schließfächer vorhanden</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Fach-Nr.</th>
                                <th>Größe</th>
                                <th>Wochengebühr</th>
                                <th>Letzte Zahlung</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowerSafeboxes as $sb):
                                $sizeBadge = match($sb['box_size']) {
                                    'KLEIN'  => ['bg-secondary', 'Klein'],
                                    'MITTEL' => ['bg-info text-dark', 'Mittel'],
                                    'GROSS'  => ['bg-primary', 'Groß'],
                                    default  => ['bg-secondary', $sb['box_size']],
                                };
                                $daysSince = $sb['last_payment_date']
                                    ? (int)((time() - strtotime($sb['last_payment_date'])) / 86400)
                                    : null;
                                $payWarn = $sb['status'] === 'ACTIVE' && ($daysSince === null || $daysSince > 14);
                            ?>
                            <tr class="<?= $payWarn ? 'overdue-warning' : '' ?>">
                                <td>
                                    <a href="<?= APP_URL ?>/pages/safeboxes/view.php?id=<?= $sb['id'] ?>">
                                        <strong><?= e($sb['box_number']) ?></strong>
                                    </a>
                                </td>
                                <td><span class="badge <?= $sizeBadge[0] ?>"><?= $sizeBadge[1] ?></span></td>
                                <td><?= formatMoney($sb['weekly_fee']) ?></td>
                                <td>
                                    <?= $sb['last_payment_date'] ? formatDate($sb['last_payment_date']) : '–' ?>
                                    <?php if ($payWarn): ?>
                                    <span class="badge bg-danger ms-1">Überfällig</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $sb['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= $sb['status'] === 'ACTIVE' ? 'Aktiv' : 'Freigegeben' ?>
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

    <!-- Krankenversicherung (nur FF) -->
    <?php if (currentBankId() === 2): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-heart-pulse me-2"></i>KV-Einzelverträge (<?= count($borrowerInsurance) ?>)</span>
                <a href="<?= APP_URL ?>/pages/insurance/create.php?borrower_id=<?= $id ?>"
                   class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus me-1"></i>Neuer KV-Vertrag
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($borrowerInsurance)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-heart-pulse text-muted"></i>
                    <p class="mb-0">Keine KV-Einzelverträge verknüpft</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Vertragsnr.</th>
                                <th>Tarif</th>
                                <th>Monatsbeitrag</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowerInsurance as $ins):
                                $insBadge = match($ins['status']) {
                                    'ACTIVE'    => 'bg-success',
                                    'APPLIED'   => 'bg-info',
                                    'SUSPENDED' => 'bg-warning',
                                    default     => 'bg-secondary',
                                };
                                $insLabel = match($ins['status']) {
                                    'ACTIVE'    => 'Aktiv',
                                    'APPLIED'   => 'Antrag',
                                    'SUSPENDED' => 'Ruhend',
                                    'CANCELLED' => 'Gekündigt',
                                    default     => $ins['status'],
                                };
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $ins['id'] ?>">
                                        <?= e($ins['contract_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($ins['product_name']) ?></td>
                                <td><?= formatMoney($ins['premium_amount']) ?></td>
                                <td><span class="badge <?= $insBadge ?>"><?= $insLabel ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit Log -->
    <?php if (Auth::can('audit', 'view') && !empty($auditLog)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Änderungshistorie
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php foreach (array_slice($auditLog, 0, 10) as $entry): ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between">
                            <strong><?= e($entry['action']) ?></strong>
                            <small class="text-muted"><?= formatDateTime($entry['created_at']) ?></small>
                        </div>
                        <div class="text-muted small">
                            von <?= e($entry['full_name'] ?? 'System') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
