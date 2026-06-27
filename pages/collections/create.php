<?php
ob_start();
/**
 * PSB Kreditverwaltung - Mahnschreiben erstellen
 */
$pageTitle = 'Schreiben erstellen';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Dunning.php';
Auth::requirePermission('dunning', 'create');

$loanId = intval($_GET['loan_id'] ?? 0);
if (!$loanId) {
    header('Location: ' . APP_URL . '/pages/collections/index.php');
    exit;
}

$loan = Database::fetchOne("
    SELECT l.*, b.first_name, b.last_name, b.customer_number
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.id = ?
", [$loanId]);

if (!$loan) {
    setFlash('error', 'Kredit nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/collections/index.php');
    exit;
}

// Vorlagen laden
$templates = Database::fetchAll("SELECT * FROM templates WHERE is_active = 1 AND bank_id = ? ORDER BY type, name", [currentBankId()]);

$letter = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Ungültiges Sicherheitstoken.');
    } else {
        $action = $_POST['action'] ?? '';
        $templateType = $_POST['template_type'] ?? '';

        if ($action === 'generate' && $templateType) {
            try {
                $additionalCosts = floatval($_POST['additional_costs'] ?? 0);
                $offerValidUntil = $_POST['offer_valid_until'] ?? '';
                $extra = [
                    'bank_representative' => trim($_POST['bank_representative'] ?? ''),
                    'investment_1'        => trim($_POST['investment_1'] ?? ''),
                    'investment_2'        => trim($_POST['investment_2'] ?? ''),
                    'investment_3'        => trim($_POST['investment_3'] ?? ''),
                    'private_dob'         => $_POST['private_dob'] ?? '',
                ];
                $letter = Dunning::generateLetter($loanId, intval($templateType), $additionalCosts, $offerValidUntil, $extra);
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        } elseif ($action === 'save') {
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            $templateId = intval($_POST['template_id'] ?? 0);
            $type = $_POST['type'] ?? 'OTHER';

            if ($subject && $body) {
                try {
                    Dunning::saveCommunication($loanId, $templateId, $type, $subject, $body);
                    setFlash('success', 'Schreiben gespeichert.');
                    $saved = true;
                } catch (Exception $e) {
                    setFlash('error', 'Fehler beim Speichern: ' . $e->getMessage());
                }
            } else {
                setFlash('error', 'Betreff und Text dürfen nicht leer sein.');
            }
        }
    }
}

// Standard-Typ basierend auf Produkttyp und Status
if ($loan['product_type'] === 'BUSINESS') {
    $defaultType = match($loan['status']) {
        'CONTRACT_CREATED', 'ACTIVE' => 'CONTRACT_BUSINESS',
        'APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED' => 'OFFER_BUSINESS',
        'DUNNING_L1' => 'DUNNING_L1',
        'DUNNING_L2' => 'DUNNING_L2',
        'TERMINATED' => 'TERMINATION',
        default => 'OFFER_BUSINESS'
    };
} else {
    $defaultType = match($loan['status']) {
        'APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED' => 'OTHER',
        'ACTIVE' => 'REMINDER',
        'DUNNING_L1' => 'DUNNING_L1',
        'DUNNING_L2' => 'DUNNING_L2',
        'TERMINATED' => 'TERMINATION',
        default => 'OTHER'
    };
}
// Geburtsdatum für Formular vorausfüllen
$borrowerDob = Database::fetchOne("SELECT date_of_birth FROM borrowers b JOIN loans l ON l.borrower_id=b.id WHERE l.id=?", [$loanId])['date_of_birth'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loanId ?>&tab=communications" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zum Kredit
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-envelope me-2"></i>Schreiben erstellen
        </h4>
        <small class="text-muted">
            <?= e($loan['file_number']) ?> - <?= e($loan['last_name'] . ', ' . $loan['first_name']) ?>
        </small>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <?php if (!$letter && !$saved): ?>
        <!-- Vorlage auswählen -->
        <div class="card">
            <div class="card-header">Vorlage auswählen</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="generate">

                    <div class="mb-3">
                        <label for="template_type" class="form-label">Art des Schreibens</label>
                        <select class="form-select" id="template_type" name="template_type" required>
                            <option value="">-- Vorlage wählen --</option>
                            <?php foreach ($templates as $t): ?>
                            <option value="<?= $t['id'] ?>"
                                    data-type="<?= $t['type'] ?>"
                                    <?= $t['type'] === $defaultType ? 'selected' : '' ?>>
                                <?= e($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="additional_costs" class="form-label">Weitere Kosten ($)</label>
                        <input type="number" step="1" min="0" class="form-control" id="additional_costs" name="additional_costs"
                               value="0" placeholder="0">
                        <div class="form-text">z.B. Inkassokosten, Bearbeitungsgebühren etc.</div>
                    </div>

                    <div class="mb-3" id="offer-valid-field">
                        <label for="offer_valid_until" class="form-label">Angebot gültig bis</label>
                        <input type="date" class="form-control" id="offer_valid_until" name="offer_valid_until"
                               value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
                        <div class="form-text">Platzhalter {ANGEBOTSGUELTIG}</div>
                    </div>

                    <div id="contract-business-fields">
                        <hr>
                        <p class="text-muted small mb-3"><i class="bi bi-info-circle me-1"></i>Felder für den Kreditvertrag</p>

                        <div class="mb-3">
                            <label for="bank_representative" class="form-label">Bankvertreter (Name) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bank_representative" name="bank_representative"
                                   placeholder="z.B. Michael Torino">
                        </div>

                        <div class="mb-3">
                            <label for="private_dob" class="form-label">Geburtsdatum (priv. Schuldner)</label>
                            <input type="date" class="form-control" id="private_dob" name="private_dob"
                                   value="<?= e($borrowerDob) ?>">
                            <div class="form-text">Platzhalter {GEBURTSDATUM}<?= $borrowerDob ? ' – aus Kundenstamm vorausgefüllt' : ' – nicht im Kundenstamm hinterlegt' ?></div>
                        </div>

                        <div class="mb-3">
                            <label for="investment_1" class="form-label">Investitionszweck 1 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="investment_1" name="investment_1"
                                   placeholder="z.B. Anschaffung von Fahrzeugen">
                        </div>

                        <div class="mb-3">
                            <label for="investment_2" class="form-label">Investitionszweck 2 <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="investment_2" name="investment_2"
                                   placeholder="z.B. Betriebsmittelfinanzierung">
                        </div>

                        <div class="mb-3">
                            <label for="investment_3" class="form-label">Investitionszweck 3 <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="investment_3" name="investment_3"
                                   placeholder="z.B. Expansion des Unternehmens">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-text me-2"></i>Schreiben generieren
                    </button>
                </form>
            </div>
        </div>

        <?php elseif ($letter): ?>
        <!-- Generiertes Schreiben -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= e($letter['subject']) ?></span>
                <span class="badge bg-secondary"><?= $letter['type'] ?></span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="template_id" value="<?= $letter['template_id'] ?>">
                    <input type="hidden" name="type" value="<?= $letter['type'] ?>">

                    <div class="mb-3">
                        <label for="subject" class="form-label">Betreff</label>
                        <input type="text" class="form-control" id="subject" name="subject"
                               value="<?= e($letter['subject']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="body" class="form-label">Text</label>
                        <textarea class="form-control font-monospace" id="body" name="body" rows="15"><?= e($letter['body']) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success copy-btn" data-copy-target="body">
                            <i class="bi bi-clipboard me-2"></i>In Zwischenablage kopieren
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Schreiben speichern
                        </button>
                        <a href="<?= APP_URL ?>/pages/collections/create.php?loan_id=<?= $loanId ?>" class="btn btn-outline-secondary">
                            Andere Vorlage
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($saved): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                <h4 class="mt-3">Schreiben gespeichert</h4>
                <p class="text-muted">Das Schreiben wurde in der Kommunikationshistorie gespeichert.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loanId ?>&tab=communications" class="btn btn-primary">
                        Zur Kommunikation
                    </a>
                    <a href="<?= APP_URL ?>/pages/collections/create.php?loan_id=<?= $loanId ?>" class="btn btn-outline-primary">
                        Weiteres Schreiben
                    </a>
                    <a href="<?= APP_URL ?>/pages/collections/index.php" class="btn btn-outline-secondary">
                        Zum Mahnwesen
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <!-- Kredit-Info -->
        <div class="card">
            <div class="card-header">Kredit-Info</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Status</td>
                        <td>
                            <span class="badge <?= getStatusBadgeClass($loan['status']) ?>">
                                <?= translateLoanStatus($loan['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Verzugstage</td>
                        <td>
                            <span class="badge <?= $loan['days_overdue'] > 14 ? 'bg-danger' : 'bg-warning' ?>">
                                <?= $loan['days_overdue'] ?> Tage
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Restschuld</td>
                        <td><?= formatMoney($loan['outstanding_balance'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Verzugszins</td>
                        <td class="text-danger"><?= formatMoney($loan['late_fees_accrued'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wochenrate</td>
                        <td><?= formatMoney($loan['weekly_rate']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Zahlungsref.</td>
                        <td><code><?= e($loan['payment_reference']) ?></code></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Bisherige Kommunikation -->
        <?php
        $recentComms = Database::fetchAll("
            SELECT * FROM communications WHERE loan_id = ? ORDER BY created_at DESC LIMIT 5
        ", [$loanId]);
        ?>
        <?php if (!empty($recentComms)): ?>
        <div class="card mt-3">
            <div class="card-header">Letzte Schreiben</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentComms as $comm): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <small><?= e($comm['type']) ?></small>
                            <small class="text-muted"><?= formatDate($comm['created_at']) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var sel = document.getElementById('template_type');
    var offerField = document.getElementById('offer-valid-field');
    var contractFields = document.getElementById('contract-business-fields');
    var costsField = document.getElementById('additional_costs') ? document.getElementById('additional_costs').closest('.mb-3') : null;

    function toggle() {
        var opt = sel.options[sel.selectedIndex];
        var t = opt ? (opt.getAttribute('data-type') || '') : '';
        var isOffer = (t === 'OTHER' || t === 'OFFER_BUSINESS');
        var isContract = (t === 'CONTRACT_BUSINESS');
        var isDunning = !isOffer && !isContract;

        if (offerField)    offerField.style.display    = isOffer ? '' : 'none';
        if (contractFields) contractFields.style.display = isContract ? '' : 'none';
        if (costsField)    costsField.style.display    = isDunning ? '' : 'none';
    }
    if (sel) {
        toggle();
        sel.addEventListener('change', toggle);
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
