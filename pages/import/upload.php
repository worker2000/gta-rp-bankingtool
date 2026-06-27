<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kontoauszug Upload
 */
$pageTitle = 'Kontoauszug importieren';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/BankImport.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('import', 'upload');

$errors = [];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $action = $_POST['action'] ?? 'preview';
        $content = trim($_POST['statement_content'] ?? '');
        $minAmount = floatval($_POST['min_amount'] ?? 0);

        if (empty($content)) {
            $errors[] = 'Bitte fügen Sie den Kontoauszug ein.';
        } else {
            $transactions = BankImport::parseStatement($content, $minAmount);

            if (empty($transactions)) {
                $errors[] = 'Keine gültigen Transaktionen gefunden. Bitte prüfen Sie das Format.';
            } else {
                // Gebühren und Duplikate markieren
                $transactions = BankImport::flagTransactions($transactions);

                if ($action === 'preview') {
                    $preview = $transactions;
                } elseif ($action === 'import') {
                    // Duplikate und Gebühren zählen für Meldung
                    $dupCount = count(array_filter($transactions, fn($t) => $t['is_duplicate']));
                    $feeCount = count(array_filter($transactions, fn($t) => !$t['is_duplicate'] && $t['is_fee']));

                    $batchId = BankImport::createBatch($transactions, 'Manueller Import');

                    if (!$batchId) {
                        $errors[] = 'Keine neuen Transaktionen zum Importieren (alle bereits vorhanden).';
                    } else {
                        // Matching nur für normale Transaktionen
                        $stats = Matching::processBatch($batchId);

                        AuditLog::log('IMPORT', 'bank_statement_batch', $batchId, null, [
                            'transactions' => count($transactions),
                            'duplicates_skipped' => $dupCount,
                            'fees' => $feeCount,
                            'matched' => $stats['matched'],
                            'ambiguous' => $stats['ambiguous'],
                            'unmatched' => $stats['unmatched']
                        ]);

                        $msg = sprintf('Import erfolgreich: %d zugeordnet, %d mehrdeutig, %d offen.',
                            $stats['matched'], $stats['ambiguous'], $stats['unmatched']);
                        if ($feeCount > 0) $msg .= sprintf(' %d Gebühren markiert.', $feeCount);
                        if ($dupCount > 0) $msg .= sprintf(' %d Duplikate übersprungen.', $dupCount);

                        setFlash('success', $msg);
                        header('Location: ' . APP_URL . '/pages/import/batch.php?id=' . $batchId);
                        exit;
                    }
                }
            }
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-upload me-2"></i>Kontoauszug importieren</h4>
    <a href="<?= APP_URL ?>/pages/import/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Kontoauszug einfügen</div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label for="statement_content" class="form-label">
                            Fügen Sie hier die Buchungen aus dem Kontoauszug ein:
                        </label>
                        <textarea class="form-control font-monospace" id="statement_content" name="statement_content"
                                  rows="15" placeholder="Daten aus dem PSB-Portal hier einfügen (Tab-Format oder CSV)..."><?= e($_POST['statement_content'] ?? '') ?></textarea>
                    </div>

                    <div class="row mb-3">
                        <div class="col-auto">
                            <label for="min_amount" class="form-label">Mindestbetrag ($)</label>
                            <input type="number" class="form-control" id="min_amount" name="min_amount"
                                   value="<?= e($_POST['min_amount'] ?? '0') ?>" min="0" step="1" style="width: 120px;">
                            <div class="form-text">Kleinere Beträge werden ignoriert.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="preview" class="btn btn-outline-primary">
                            <i class="bi bi-eye me-2"></i>Vorschau
                        </button>
                        <?php if ($preview):
                            $canImport = count(array_filter($preview, fn($t) => !$t['is_duplicate']));
                        ?>
                        <?php if ($canImport > 0): ?>
                        <button type="submit" name="action" value="import" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Importieren (<?= $canImport ?> Transaktionen)
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            Keine neuen Transaktionen
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($preview):
            $importCount = count(array_filter($preview, fn($t) => !$t['is_duplicate'] && !$t['is_fee']));
            $feeCount = count(array_filter($preview, fn($t) => !$t['is_duplicate'] && $t['is_fee']));
            $dupCount = count(array_filter($preview, fn($t) => $t['is_duplicate']));
        ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-eye me-2"></i>Vorschau (<?= count($preview) ?> Transaktionen)</span>
                <span>
                    <?php if ($importCount): ?><span class="badge bg-success"><?= $importCount ?> importierbar</span><?php endif; ?>
                    <?php if ($feeCount): ?><span class="badge bg-info"><?= $feeCount ?> Gebühren</span><?php endif; ?>
                    <?php if ($dupCount): ?><span class="badge bg-secondary"><?= $dupCount ?> Duplikate</span><?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Datum</th>
                                <th>Betrag</th>
                                <th>Name</th>
                                <th>Verwendungszweck</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview as $i => $tx): ?>
                            <tr class="<?= $tx['is_duplicate'] ? 'table-secondary text-decoration-line-through' : ($tx['is_fee'] ? 'table-info' : '') ?>">
                                <td><?= $i + 1 ?></td>
                                <td><?= formatDate($tx['transaction_date']) ?></td>
                                <td class="<?= $tx['is_duplicate'] ? '' : 'text-success' ?>"><?= formatMoney($tx['amount']) ?></td>
                                <td><?= e($tx['sender_name']) ?></td>
                                <td><small><?= e(substr($tx['reference'], 0, 50)) ?></small></td>
                                <td>
                                    <?php if ($tx['is_duplicate']): ?>
                                        <span class="badge bg-secondary">Duplikat</span>
                                    <?php elseif ($tx['is_fee']): ?>
                                        <span class="badge bg-info">Gebühr</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Format-Hinweise</div>
            <div class="card-body">
                <h6>PSB Tab-Format</h6>
                <p class="small text-muted">
                    Direkt aus dem Bankauszug kopieren (Tab-getrennt, mit oder ohne Header).
                </p>
                <p class="small text-muted">
                    Es werden alle nicht-ausstehenden Transaktionen (eingehend &amp; ausgehend) importiert und dem jeweiligen Kundenkonto zugeordnet.
                </p>

                <h6 class="mt-3">CSV-Format</h6>
                <p class="small text-muted">
                    Spalten durch Semikolon getrennt:
                </p>
                <pre class="bg-dark p-2 rounded small">Datum;Betrag;Name;IBAN;Verwendungszweck</pre>

                <h6 class="mt-3">Freitext-Format</h6>
                <p class="small text-muted">
                    Das System erkennt auch Freitext:
                </p>
                <pre class="bg-dark p-2 rounded small">14.02.2024 1500.00 Max Mustermann RATE-AK-2024-00001</pre>

                <h6 class="mt-3">Automatisches Matching</h6>
                <p class="small text-muted mb-0">
                    Das System ordnet Zahlungen automatisch zu anhand von:
                </p>
                <ol class="small text-muted mb-0">
                    <li>Aktenzeichen im Verwendungszweck</li>
                    <li>IBAN + exakter Ratenbetrag</li>
                    <li>Name + Betrag</li>
                    <li>Fuzzy-Matching (Vorschläge)</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
