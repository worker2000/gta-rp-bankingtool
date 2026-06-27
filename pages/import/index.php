<?php
/**
 * PSB Kreditverwaltung - Import Übersicht
 */
$pageTitle = 'Kontoauszug Import';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/BankImport.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('import', 'upload');

// Neuverarbeitung der Kontobuchungen
$reprocessResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reprocess') {
    if (verifyCsrf()) {
        $reprocessResult = BankImport::reprocessAccountTransactions();
        AuditLog::log('REPROCESS', 'account_transactions', null, null, $reprocessResult);
    }
}

$bid = currentBankId();

// Letzte Batches – bank-gefiltert
$batches = Database::fetchAll("
    SELECT bsb.*, u.full_name as imported_name
    FROM bank_statement_batches bsb
    LEFT JOIN users u ON bsb.imported_by = u.id
    WHERE bsb.bank_id = ?
    ORDER BY bsb.created_at DESC
    LIMIT 20
", [$bid]);

// Offene Transaktionen
$pendingCount = Database::fetchOne("
    SELECT COUNT(*) as cnt
    FROM bank_transactions bt
    JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
    WHERE bt.match_status IN ('UNMATCHED', 'AMBIGUOUS') AND bsb.bank_id = ?
", [$bid])['cnt'] ?? 0;

// Statistiken für Reprocess-Button
$bankTxCount    = Database::fetchOne("SELECT COUNT(*) as cnt FROM bank_transactions bt JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id WHERE bsb.bank_id = ?", [$bid])['cnt'] ?? 0;
$accountTxCount = Database::fetchOne("SELECT COUNT(*) as cnt FROM account_transactions at JOIN customer_accounts ca ON at.account_id = ca.id WHERE ca.bank_id = ?", [$bid])['cnt'] ?? 0;
$accountCount   = Database::fetchOne("SELECT COUNT(*) as cnt FROM customer_accounts WHERE bank_id = ?", [$bid])['cnt'] ?? 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-upload me-2"></i>Kontoauszug Import</h4>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/pages/import/upload.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Neuer Import
        </a>
    </div>
</div>

<?php if ($reprocessResult): ?>
<div class="alert alert-<?= $reprocessResult['errors'] > 0 ? 'warning' : 'success' ?> alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <strong>Neuverarbeitung abgeschlossen!</strong>
    <?= $reprocessResult['bank_transactions'] ?> Bank-Transaktionen geprüft,
    <?= $reprocessResult['account_transactions_created'] ?> Kontobuchungen erstellt,
    <?= $reprocessResult['accounts_created'] ?> Konten angelegt,
    <?= $reprocessResult['skipped'] ?> übersprungen<?php if ($reprocessResult['errors'] > 0): ?>,
    <span class="text-danger"><?= $reprocessResult['errors'] ?> Fehler</span><?php endif; ?>.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong><?= $pendingCount ?></strong> Transaktionen warten auf Zuordnung.
    <a href="<?= APP_URL ?>/pages/import/pending.php" class="alert-link">Jetzt bearbeiten</a>
</div>
<?php endif; ?>

<?php if ($bankTxCount > 0): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-arrow-repeat me-2"></i>Kontobuchungen neu verarbeiten
    </div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <p class="mb-1">
                    Liest alle <strong><?= number_format($bankTxCount, 0, ',', '.') ?></strong> Bank-Transaktionen neu ein
                    und erstellt die Kontobuchungen + Kundenkonten komplett neu.
                </p>
                <p class="text-muted small mb-0">
                    Aktuell: <?= number_format($accountCount, 0, ',', '.') ?> Konten,
                    <?= number_format($accountTxCount, 0, ',', '.') ?> Kontobuchungen.
                    Nützlich wenn sich die Import-Logik geändert hat oder Zuordnungen fehlen.
                </p>
            </div>
            <div class="col-md-4 text-end">
                <form method="POST" action="" onsubmit="return confirm('Alle Kontobuchungen und Kundenkonten werden gelöscht und neu erstellt. Fortfahren?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reprocess">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-repeat me-2"></i>Jetzt neu verarbeiten
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Import-Historie</div>
    <div class="card-body p-0">
        <?php if (empty($batches)): ?>
        <div class="empty-state">
            <i class="bi bi-upload"></i>
            <p>Noch keine Importe durchgeführt</p>
            <a href="<?= APP_URL ?>/pages/import/upload.php" class="btn btn-primary">Ersten Import starten</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Datei</th>
                        <th>Transaktionen</th>
                        <th>Zugeordnet</th>
                        <th>Mehrdeutig</th>
                        <th>Offen</th>
                        <th>Status</th>
                        <th>Importiert von</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/import/batch.php?id=<?= $batch['id'] ?>">
                                <?= formatDate($batch['batch_date']) ?>
                            </a>
                        </td>
                        <td><?= e($batch['filename'] ?? '-') ?></td>
                        <td><?= $batch['total_transactions'] ?></td>
                        <td><span class="text-success"><?= $batch['matched_count'] ?></span></td>
                        <td>
                            <?php if ($batch['ambiguous_count'] > 0): ?>
                            <span class="badge bg-warning"><?= $batch['ambiguous_count'] ?></span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($batch['unmatched_count'] > 0): ?>
                            <span class="badge bg-secondary"><?= $batch['unmatched_count'] ?></span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusClass = match($batch['status']) {
                                'COMPLETED' => 'bg-success',
                                'PROCESSING' => 'bg-info',
                                'ERROR' => 'bg-danger',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $batch['status'] ?></span>
                        </td>
                        <td><?= e($batch['imported_name'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
