<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kredit anlegen
 */
$pageTitle = 'Neuer Kredit';
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('loans', 'create');

$isDirector = Auth::hasRole('director');
$errors = [];
// Vorausfüllung aus ausstehender Kredit-Referenz
$pendingRefPrefill = null;
if (!empty($_GET['pending_ref'])) {
    $pendingRefPrefill = Database::fetchOne(
        "SELECT * FROM pending_loan_refs WHERE ref_number = ? AND bank_id = ?",
        [trim($_GET['pending_ref']), currentBankId()]
    );
}

// Policy-Defaults laden
$defaultRates = [
    'AUTO'     => floatval(getPolicy('INTEREST_RATE_AUTO',     0.10)) * 100,
    'PRIVATE'  => floatval(getPolicy('INTEREST_RATE_PRIVATE',  0.10)) * 100,
    'BUSINESS' => floatval(getPolicy('INTEREST_RATE_BUSINESS', 0.12)) * 100,
];

$data = [
    'borrower_id'    => $_GET['borrower_id'] ?? '',
    'product_type'   => 'AUTO',
    'purchase_price' => '',
    'down_payment'   => '',
    'interest_rate'  => $defaultRates['AUTO'],
    'term_weeks'     => intval(getPolicy('AUTO_MIN_TERM_WEEKS', 6)),
    'start_date'     => $pendingRefPrefill ? $pendingRefPrefill['first_seen'] : date('Y-m-d'),
    'custom_final_rate' => '',
    'vehicle_plate'  => '',
    'vehicle_model'  => '',
    'payment_account' => '',
    'notes'          => $pendingRefPrefill
        ? 'Ref: ' . $pendingRefPrefill['ref_number'] . ' | ' . $pendingRefPrefill['transaction_count'] . ' Zahlungen eingegangen, gesamt ' . formatMoney($pendingRefPrefill['total_received'])
        : '',
];

$bid = currentBankId();

// Kreditnehmer laden – nur der aktuellen Bank
$borrowers = Database::fetchAll("
    SELECT id, customer_number, first_name, last_name, weekly_income
    FROM borrowers
    WHERE is_active = 1 AND bank_id = ?
    ORDER BY last_name, first_name
", [$bid]);

// Vorausgewählter Kreditnehmer
$selectedBorrower = null;
if ($data['borrower_id']) {
    $selectedBorrower = Database::fetchOne("SELECT * FROM borrowers WHERE id = ? AND bank_id = ?", [$data['borrower_id'], $bid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        // Daten sammeln
        $data['borrower_id'] = intval($_POST['borrower_id'] ?? 0);
        $data['product_type'] = $_POST['product_type'] ?? 'AUTO';
        $data['purchase_price'] = floatval($_POST['purchase_price'] ?? 0);
        $data['down_payment'] = floatval($_POST['down_payment'] ?? 0);
        $data['interest_rate'] = floatval($_POST['interest_rate'] ?? 0);
        $data['term_weeks'] = intval($_POST['term_weeks'] ?? 6);
        $data['start_date'] = $_POST['start_date'] ?? date('Y-m-d');
        $data['custom_final_rate'] = $_POST['custom_final_rate'] !== '' ? floatval($_POST['custom_final_rate']) : null;
        $data['vehicle_plate'] = trim($_POST['vehicle_plate'] ?? '');
        $data['vehicle_model'] = trim($_POST['vehicle_model'] ?? '');
        $data['payment_account'] = trim($_POST['payment_account'] ?? '');
        $data['notes'] = trim($_POST['notes'] ?? '');

        // Validierung
        if (!$data['borrower_id']) $errors[] = 'Kreditnehmer auswählen.';
        if ($data['purchase_price'] <= 0) $errors[] = 'Kaufpreis muss größer als 0 sein.';
        if ($data['down_payment'] < 0)    $errors[] = 'Eigenkapital darf nicht negativ sein.';
        if ($data['down_payment'] >= $data['purchase_price']) $errors[] = 'Eigenkapital muss kleiner als Kaufpreis sein.';

        $loanAmountCheck = $data['purchase_price'] - $data['down_payment'];

        // Kreditbetrag-Grenzen
        $minLoan = floatval(getPolicy('MIN_LOAN_AMOUNT', 0));
        $maxLoan = floatval(getPolicy('MAX_LOAN_AMOUNT', PHP_INT_MAX));
        if ($minLoan > 0 && $loanAmountCheck < $minLoan) {
            $errors[] = 'Minimaler Kreditbetrag: ' . formatMoney($minLoan) . '.';
        }
        if ($maxLoan > 0 && $loanAmountCheck > $maxLoan) {
            $errors[] = 'Maximaler Kreditbetrag: ' . formatMoney($maxLoan) . '.';
        }

        // Kleinkreditgrenze: Sachbearbeiter-Kompetenz
        $maxSmallLoan = floatval(getPolicy('MAX_SMALL_LOAN_AMOUNT', 25000));
        if ($loanAmountCheck > $maxSmallLoan && !Auth::hasRole('director')) {
            $errors[] = sprintf('Kreditsumme überschreitet Sachbearbeiter-Kompetenz (max. %s). Genehmigung durch Direktion erforderlich.',
                formatMoney($maxSmallLoan));
        }

        // Max. aktive Kredite pro Kreditnehmer
        $maxActive = intval(getPolicy('MAX_ACTIVE_LOANS_PER_CUSTOMER', 0));
        if ($maxActive > 0 && $data['borrower_id']) {
            $activeCount = intval(Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM loans
                 WHERE borrower_id = ? AND bank_id = ?
                 AND status NOT IN ('CLOSED','REJECTED','TERMINATED','REPOSSESSION')",
                [$data['borrower_id'], $bid]
            )['cnt'] ?? 0);
            if ($activeCount >= $maxActive) {
                $errors[] = "Dieser Kreditnehmer hat bereits {$activeCount} aktive Kredit(e) (Max.: {$maxActive}).";
            }
        }

        // Auskunftspflicht
        if (empty($_POST['confirm_income'])) {
            $errors[] = 'Bitte bestätigen Sie, dass der Nachweis über den wöchentlichen Verdienst vorliegt (Auskunftspflicht).';
        }
        if (empty($_POST['confirm_assets'])) {
            $errors[] = 'Bitte bestätigen Sie, dass der Nachweis über das Gesamtvermögen vorliegt (Auskunftspflicht).';
        }

        // Typ-spezifische Validierung
        $directorOverride = $isDirector && !empty($_POST['director_extended_term']);

        if ($data['product_type'] === 'AUTO') {
            $minDownPayRatio = floatval(getPolicy('AUTO_MIN_DOWNPAY_RATIO', 0.30));
            $actualRatio     = $data['purchase_price'] > 0 ? $data['down_payment'] / $data['purchase_price'] : 0;
            if (!$directorOverride && $minDownPayRatio > 0 && $actualRatio < $minDownPayRatio) {
                $errors[] = sprintf('Mindest-Eigenkapital für Autokredit: %.0f%% (aktuell: %.0f%%)',
                    $minDownPayRatio * 100, $actualRatio * 100);
            }
            if ($directorOverride) {
                if ($data['term_weeks'] < 1 || $data['term_weeks'] > 520) {
                    $errors[] = 'Laufzeit ungültig (1–520 Wochen).';
                }
            } else {
                $minW = intval(getPolicy('AUTO_MIN_TERM_WEEKS', 6));
                $maxW = intval(getPolicy('AUTO_MAX_TERM_WEEKS', 8));
                if ($data['term_weeks'] < $minW) $errors[] = "Minimale Laufzeit Autokredit: {$minW} Wochen.";
                if ($data['term_weeks'] > $maxW) $errors[] = "Maximale Laufzeit Autokredit: {$maxW} Wochen.";
            }
        } elseif ($data['product_type'] === 'PRIVATE') {
            if (!$isDirector) {
                $minW = intval(getPolicy('PRIVATE_MIN_TERM_WEEKS', 1));
                $maxW = intval(getPolicy('PRIVATE_MAX_TERM_WEEKS', 12));
                if ($data['term_weeks'] < $minW) $errors[] = "Minimale Laufzeit Privatkredit: {$minW} Wochen.";
                if ($data['term_weeks'] > $maxW) $errors[] = "Maximale Laufzeit Privatkredit: {$maxW} Wochen.";
            }
        } elseif ($data['product_type'] === 'BUSINESS') {
            if (!$isDirector) {
                $minW = intval(getPolicy('BUSINESS_MIN_TERM_WEEKS', 1));
                $maxW = intval(getPolicy('BUSINESS_MAX_TERM_WEEKS', 16));
                if ($data['term_weeks'] < $minW) $errors[] = "Minimale Laufzeit Geschäftskredit: {$minW} Wochen.";
                if ($data['term_weeks'] > $maxW) $errors[] = "Maximale Laufzeit Geschäftskredit: {$maxW} Wochen.";
            }
        }

        if (empty($errors)) {
            $loanAmount = $data['purchase_price'] - $data['down_payment'];
            $interestDecimal = $data['interest_rate'] / 100;

            // Ratenplan berechnen (gleichmäßige Raten für alle Produkttypen)
            $finalRate = $data['custom_final_rate'];

            // Wenn Director einen individuellen Ratenprozentsatz gesetzt hat,
            // daraus die Schlussrate berechnen (damit PHP = JS-Preview)
            $directorCustomRatePct = floatval($_POST['director_custom_rate'] ?? 0);
            if ($directorOverride && $directorCustomRatePct > 0 && $data['term_weeks'] > 1) {
                $totalAmountPreview = round($loanAmount + round($loanAmount * $interestDecimal));
                $weeklyFromPct      = round($totalAmountPreview * ($directorCustomRatePct / 100));
                $finalRate          = $totalAmountPreview - $weeklyFromPct * ($data['term_weeks'] - 1);
            }

            $schedule = calculateSchedule($loanAmount, $interestDecimal, $data['term_weeks'], $data['start_date'], $finalRate);

            // Aktenzeichen und Zahlungsreferenz generieren
            $fileNumber = generateFileNumber($data['product_type']);
            $paymentReference = generatePaymentReference($fileNumber);

            Database::beginTransaction();

            try {
                // Kredit speichern
                $loanId = Database::insert('loans', [
                    'bank_id'     => $bid,
                    'file_number' => $fileNumber,
                    'borrower_id' => $data['borrower_id'],
                    'product_type' => $data['product_type'],
                    'status' => 'CONTRACT_CREATED',
                    'purchase_price' => $data['purchase_price'],
                    'down_payment' => $data['down_payment'],
                    'loan_amount' => $loanAmount,
                    'interest_rate' => $interestDecimal,
                    'total_interest' => $schedule['total_interest'],
                    'total_amount' => $schedule['total_amount'],
                    'term_weeks' => $data['term_weeks'],
                    'weekly_rate' => $schedule['weekly_rate'],
                    'custom_final_rate' => $data['custom_final_rate'],
                    'start_date' => $data['start_date'],
                    'end_date' => $schedule['end_date'],
                    'payment_account' => $data['payment_account'] ?: null,
                    'payment_reference' => $paymentReference,
                    'outstanding_balance' => $schedule['total_amount'],
                    'notes' => $data['notes'] ?: null,
                    'vehicle_plate' => $data['vehicle_plate'] ?: null,
                    'vehicle_model' => $data['vehicle_model'] ?: null,
                    'assigned_to' => Auth::userId(),
                    'created_by' => Auth::userId()
                ]);

                // Ratenplan speichern
                foreach ($schedule['items'] as $item) {
                    Database::insert('loan_schedule_items', [
                        'loan_id' => $loanId,
                        'installment_number' => $item['installment_number'],
                        'due_date' => $item['due_date'],
                        'amount_due' => $item['amount_due'],
                        'amount_outstanding' => $item['amount_outstanding'],
                        'status' => 'PENDING'
                    ]);
                }

                AuditLog::log('CREATE', 'loan', $loanId, null, ['file_number' => $fileNumber]);

                Database::commit();

                // Ausstehende Referenz automatisch verknüpfen und Zahlungen buchen
                if ($pendingRefPrefill && $pendingRefPrefill['status'] === 'PENDING') {
                    require_once __DIR__ . '/../../classes/Matching.php';
                    $pendingRefId = (int)$pendingRefPrefill['id'];
                    Database::update('pending_loan_refs', [
                        'loan_id' => $loanId,
                        'status'  => 'CONVERTED',
                    ], 'id = ?', [$pendingRefId]);

                    $txs = Database::fetchAll(
                        "SELECT id, amount FROM bank_transactions
                         WHERE matched_pending_ref_id = ?
                         ORDER BY transaction_date ASC, id ASC",
                        [$pendingRefId]
                    );
                    $rematched = 0;
                    foreach ($txs as $rTx) {
                        $sched = Matching::findOpenScheduleItem($loanId, (float)$rTx['amount']);
                        Matching::applyMatch($rTx['id'], $loanId, $sched ? $sched['id'] : null, 'LOAN_REF', 1.0);
                        Database::update('bank_transactions', ['matched_pending_ref_id' => null], 'id = ?', [$rTx['id']]);
                        $rematched++;
                    }
                    AuditLog::log('UPDATE', 'pending_loan_ref', $pendingRefId, null, [
                        'loan_id' => $loanId, 'rematched' => $rematched, 'auto_linked' => true,
                    ]);
                    setFlash('success', "Kredit {$fileNumber} angelegt. {$rematched} Zahlung(en) aus Referenz {$pendingRefPrefill['ref_number']} automatisch gebucht.");
                } else {
                    setFlash('success', "Kredit {$fileNumber} erfolgreich angelegt.");
                }

                header('Location: ' . APP_URL . '/pages/loans/view.php?id=' . $loanId);
                exit;

            } catch (Exception $e) {
                Database::rollback();
                $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-plus-circle me-2"></i>Neuer Kredit</h4>
    <a href="<?= APP_URL ?>/pages/loans/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<?php if ($pendingRefPrefill): ?>
<div class="alert alert-info">
    <i class="bi bi-hourglass-split me-2"></i>
    <strong>Referenz <?= e($pendingRefPrefill['ref_number']) ?>:</strong>
    <?= $pendingRefPrefill['transaction_count'] ?> Zahlung(en) eingegangen,
    gesamt <strong><?= formatMoney($pendingRefPrefill['total_received']) ?></strong>
    (ca. <?= formatMoney($pendingRefPrefill['weekly_amount']) ?>/Woche).
    Nach dem Anlegen des Kredits die Referenz auf der
    <a href="<?= APP_URL ?>/pages/loans/pending_refs.php">Ausstehende Kredite</a>-Seite verknüpfen.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" id="loanForm">
    <?= csrfField() ?>

    <div class="row g-4">
        <!-- Linke Spalte: Eingaben -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Kreditdaten</div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Kreditnehmer -->
                        <div class="col-12">
                            <label for="borrower_id" class="form-label">Kreditnehmer *</label>
                            <select class="form-select" id="borrower_id" name="borrower_id" required>
                                <option value="">-- Kreditnehmer auswählen --</option>
                                <?php foreach ($borrowers as $b): ?>
                                <option value="<?= $b['id'] ?>"
                                        data-income="<?= $b['weekly_income'] ?>"
                                        <?= $data['borrower_id'] == $b['id'] ? 'selected' : '' ?>>
                                    <?= e($b['customer_number'] . ' - ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                                    <?php if ($b['weekly_income']): ?>
                                    (<?= formatMoney($b['weekly_income']) ?>/Woche)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Interbanken-Kreditauskunft -->
                        <div class="col-12" id="credit-check-wrap" style="display:none;">
                            <div id="credit-check-box"></div>
                        </div>

                        <!-- Auskunftspflicht -->
                        <div class="col-12">
                            <div class="card border-warning">
                                <div class="card-header bg-warning bg-opacity-10">
                                    <i class="bi bi-shield-check me-1"></i><strong>Auskunftspflicht</strong>
                                    <small class="text-muted">- Nachweise müssen vor Kreditvergabe vorliegen</small>
                                </div>
                                <div class="card-body py-2">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="confirm_income" name="confirm_income" value="1"
                                               <?= !empty($_POST['confirm_income']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="confirm_income">
                                            Nachweis über <strong>wöchentlichen Verdienst</strong> liegt vor
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="confirm_assets" name="confirm_assets" value="1"
                                               <?= !empty($_POST['confirm_assets']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="confirm_assets">
                                            Nachweis über <strong>Gesamtvermögen</strong> liegt vor
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Produkttyp -->
                        <div class="col-md-6">
                            <label for="product_type" class="form-label">Produkttyp *</label>
                            <select class="form-select" id="product_type" name="product_type" required>
                                <option value="AUTO" <?= $data['product_type'] === 'AUTO' ? 'selected' : '' ?>>Autokredit</option>
                                <option value="PRIVATE" <?= $data['product_type'] === 'PRIVATE' ? 'selected' : '' ?>>Privatkredit</option>
                                <option value="BUSINESS" <?= $data['product_type'] === 'BUSINESS' ? 'selected' : '' ?>>Geschäftskredit</option>
                            </select>
                        </div>

                        <!-- Vertragsbeginn -->
                        <div class="col-md-6">
                            <label for="start_date" class="form-label">Vertragsbeginn *</label>
                            <input type="date" class="form-control" id="start_date" name="start_date"
                                   value="<?= e($data['start_date']) ?>" required>
                        </div>

                        <div class="col-12"><hr></div>

                        <!-- Kaufpreis -->
                        <div class="col-md-4">
                            <label for="purchase_price" class="form-label">Kaufpreis ($) *</label>
                            <input type="number" step="1" class="form-control" id="purchase_price" name="purchase_price"
                                   value="<?= e($data['purchase_price']) ?>" required>
                        </div>

                        <!-- Eigenkapital -->
                        <div class="col-md-4">
                            <label for="down_payment" class="form-label">Eigenkapital ($) *</label>
                            <input type="number" step="1" class="form-control" id="down_payment" name="down_payment"
                                   value="<?= e($data['down_payment']) ?>" required>
                            <div class="form-text" id="downpayment_hint">
                                <?php if ($bid !== 2): ?>
                                <?= floatval(getPolicy('AUTO_MIN_DOWNPAY_RATIO', 0.30)) * 100 ?>% - <?= floatval(getPolicy('AUTO_MAX_DOWNPAY_RATIO', 0.40)) * 100 ?>% bei Autokredit
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Kreditsumme (berechnet) -->
                        <div class="col-md-4">
                            <label for="loan_amount" class="form-label">Kreditsumme ($)</label>
                            <input type="text" class="form-control" id="loan_amount" readonly>
                        </div>

                        <!-- Zinssatz -->
                        <div class="col-md-4">
                            <label for="interest_rate" class="form-label">Zinssatz (%) *</label>
                            <?php if ($bid === 2): ?>
                            <?php $ffRates = [0, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20]; ?>
                            <select class="form-select" id="interest_rate" name="interest_rate" required>
                                <?php foreach ($ffRates as $r): ?>
                                <option value="<?= $r ?>" <?= floatval($data['interest_rate']) == $r ? 'selected' : '' ?>>
                                    <?= number_format($r, 2, ',', '.') ?> %
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="number" step="0.1" min="0" class="form-control" id="interest_rate" name="interest_rate"
                                   value="<?= e($data['interest_rate']) ?>" required>
                            <?php endif; ?>
                        </div>

                        <!-- Laufzeit -->
                        <div class="col-md-4">
                            <label for="term_weeks" class="form-label">Laufzeit (Wochen) *</label>
                            <?php if ($isDirector): ?>
                            <input type="number" class="form-control" id="term_weeks" name="term_weeks"
                                   min="1" max="520" step="1" value="<?= e($data['term_weeks']) ?>" required>
                            <?php else: ?>
                            <select class="form-select" id="term_weeks" name="term_weeks" required>
                                <?php for ($w = 1; $w <= 12; $w++): ?>
                                <option value="<?= $w ?>" <?= $data['term_weeks'] == $w ? 'selected' : '' ?>><?= $w ?> Wochen</option>
                                <?php endfor; ?>
                            </select>
                            <?php endif; ?>
                            <div class="form-text" id="term_hint"></div>
                        </div>

                        <!-- Wochenrate (berechnet) -->
                        <div class="col-md-4">
                            <label for="weekly_rate" class="form-label">Wochenrate ($)</label>
                            <input type="text" class="form-control" id="weekly_rate" readonly>
                        </div>

                        <!-- Variable Restrate -->
                        <div class="col-md-4">
                            <label for="custom_final_rate" class="form-label">Variable Restrate ($)</label>
                            <input type="number" step="1" min="0" class="form-control" id="custom_final_rate" name="custom_final_rate"
                                   value="<?= e($data['custom_final_rate']) ?>" placeholder="Optional">
                            <div class="form-text">Schlussrate (leer = automatisch)</div>
                        </div>

                        <?php if ($isDirector): ?>
                        <!-- Director: Erweiterte Laufzeit -->
                        <div class="col-12" id="director_section">
                            <div class="card border-danger">
                                <div class="card-header bg-danger bg-opacity-10">
                                    <i class="bi bi-key me-1"></i><strong>Direktion: Sonderkonditionen</strong>
                                </div>
                                <div class="card-body py-2">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="director_extended_term" name="director_extended_term" value="1"
                                               <?= !empty($_POST['director_extended_term']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="director_extended_term">
                                            <strong>Erweiterte Laufzeit aktivieren</strong>
                                            <small class="text-muted d-block">Hebt die Laufzeitbeschränkung (6-8 Wochen) für Autokredite auf. Laufzeit bis 52 Wochen mit flexiblem Zinssatz und gleichmäßigen Raten.</small>
                                        </label>
                                    </div>
                                    <div id="director_custom_rate_row" class="row g-3" style="display: none;">
                                        <div class="col-md-6">
                                            <label for="director_custom_rate" class="form-label">Individueller Ratenprozentsatz (%)</label>
                                            <input type="number" step="0.1" min="0" max="50" class="form-control" id="director_custom_rate" name="director_custom_rate"
                                                   value="<?= e($_POST['director_custom_rate'] ?? '') ?>" placeholder="z.B. 5">
                                            <div class="form-text">Wöchentliche Rate in % der Gesamtsumme (optional, sonst gleichmäßig)</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Berechnungsart</label>
                                            <div class="form-control-plaintext">
                                                <span class="badge bg-danger" id="director_calc_type">Gleichmäßige Raten</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12"><hr></div>

                        <!-- Zahlungskonto -->
                        <div class="col-md-6">
                            <label for="payment_account" class="form-label">PSB Zahlungskonto (IBAN)</label>
                            <input type="text" class="form-control" id="payment_account" name="payment_account"
                                   value="<?= e($data['payment_account']) ?>" placeholder="DE...">
                        </div>

                        <!-- Fahrzeug (Autokredit) -->
                        <div class="col-md-6" id="vehicle_model_row">
                            <label for="vehicle_model" class="form-label">Fahrzeugmodell</label>
                            <input type="text" class="form-control" id="vehicle_model" name="vehicle_model"
                                   value="<?= e($data['vehicle_model']) ?>" placeholder="z.B. Bravado Buffalo">
                        </div>

                        <div class="col-md-6" id="vehicle_plate_row">
                            <label for="vehicle_plate" class="form-label">Nummernschild</label>
                            <input type="text" class="form-control" id="vehicle_plate" name="vehicle_plate"
                                   value="<?= e($data['vehicle_plate']) ?>" placeholder="z.B. B-AB 1234">
                            <div class="form-text">Kann auch später nachgetragen werden</div>
                        </div>

                        <!-- Notizen -->
                        <div class="col-12">
                            <label for="notes" class="form-label">Notizen</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($data['notes']) ?></textarea>
                        </div>

                        <!-- Autokredit Hinweise -->
                        <div class="col-12" id="auto_hints" style="display: none;">
                            <div class="alert alert-info mb-0">
                                <h6 class="alert-heading"><i class="bi bi-info-circle me-1"></i>Hinweise Autokredit</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Eigentum:</strong> Das Fahrzeug läuft über die PSB. Der Kreditnehmer erhält nur Schlüsselkarten.</li>
                                    <li><strong>DPA-Anmeldung:</strong> Das Fahrzeug muss durch den Sachbearbeiter beim DPA angemeldet werden.</li>
                                    <li><strong>Ratenstaffelung:</strong> Je länger die Laufzeit, desto niedriger die wöchentliche Rate (degressiv von Restschuld).</li>
                                    <li><strong>Auskunftspflicht:</strong> Gesamtvermögen und wöchentlicher Verdienst müssen nachgewiesen sein.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte: Berechnung -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Kreditberechnung</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td class="text-muted">Kaufpreis</td>
                            <td id="calc_purchase_price" class="text-end">-</td>
                        </tr>
                        <tr>
                            <td class="text-muted">- Eigenkapital</td>
                            <td id="calc_down_payment" class="text-end">-</td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>= Kreditsumme</strong></td>
                            <td id="calc_loan_amount" class="text-end"><strong>-</strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">+ Zinsen (<span id="calc_rate_pct">0</span>%)</td>
                            <td id="calc_interest" class="text-end">-</td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>= Gesamtsumme</strong></td>
                            <td id="calc_total" class="text-end"><strong>-</strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Laufzeit</td>
                            <td id="calc_term" class="text-end">- Wochen</td>
                        </tr>
                        <tr class="table-success">
                            <td><strong>Wochenrate</strong></td>
                            <td id="calc_weekly" class="text-end"><strong>-</strong></td>
                        </tr>
                    </table>

                    <input type="hidden" id="total_interest" name="total_interest">
                    <input type="hidden" id="total_amount" name="total_amount">
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-lg me-2"></i>Kredit anlegen
                    </button>
                    <a href="<?= APP_URL ?>/pages/loans/index.php" class="btn btn-outline-secondary w-100">
                        Abbrechen
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Policy-Werte aus PHP
const POLICIES = {
    autoMin:     <?= intval(getPolicy('AUTO_MIN_TERM_WEEKS',      6)) ?>,
    autoMax:     <?= intval(getPolicy('AUTO_MAX_TERM_WEEKS',      8)) ?>,
    privateMin:  <?= intval(getPolicy('PRIVATE_MIN_TERM_WEEKS',   1)) ?>,
    privateMax:  <?= intval(getPolicy('PRIVATE_MAX_TERM_WEEKS',  12)) ?>,
    businessMin: <?= intval(getPolicy('BUSINESS_MIN_TERM_WEEKS',  1)) ?>,
    businessMax: <?= intval(getPolicy('BUSINESS_MAX_TERM_WEEKS', 16)) ?>,
    rateAuto:     <?= floatval(getPolicy('INTEREST_RATE_AUTO',     0.10)) * 100 ?>,
    ratePrivate:  <?= floatval(getPolicy('INTEREST_RATE_PRIVATE',  0.10)) * 100 ?>,
    rateBusiness: <?= floatval(getPolicy('INTEREST_RATE_BUSINESS', 0.12)) * 100 ?>,
    minLoan:     <?= floatval(getPolicy('MIN_LOAN_AMOUNT',   0)) ?>,
    maxLoan:     <?= floatval(getPolicy('MAX_LOAN_AMOUNT',   0)) ?>,
};

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('loanForm');
    const purchasePrice = document.getElementById('purchase_price');
    const downPayment = document.getElementById('down_payment');
    const interestRate = document.getElementById('interest_rate');
    const termWeeks = document.getElementById('term_weeks');
    const productType = document.getElementById('product_type');
    const autoHints = document.getElementById('auto_hints');
    const customFinalRate = document.getElementById('custom_final_rate');
    const vehiclePlateRow = document.getElementById('vehicle_plate_row');
    const vehicleModelRow = document.getElementById('vehicle_model_row');

    // Director-Elemente (nur wenn vorhanden)
    const directorCheckbox = document.getElementById('director_extended_term');
    const directorCustomRateRow = document.getElementById('director_custom_rate_row');
    const directorCustomRate = document.getElementById('director_custom_rate');
    const directorCalcType = document.getElementById('director_calc_type');
    const isDirector = !!directorCheckbox;

    function isDirectorOverride() {
        return isDirector && directorCheckbox.checked;
    }

    // Ratenstaffelung: Wöchentlicher Prozentsatz nach Laufzeit
    function getAutoWeeklyPercent(weeks) {
        switch(weeks) {
            case 6: return 0.20;
            case 7: return 0.15;
            case 8: return 0.10;
            default: return 0.15;
        }
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Math.round(amount));
    }

    function getTermLimits() {
        const type = productType.value;
        const override = isDirectorOverride();
        if (override || isDirector) return { min: 1, max: 520 };
        if (type === 'AUTO')     return { min: POLICIES.autoMin,     max: POLICIES.autoMax };
        if (type === 'PRIVATE')  return { min: POLICIES.privateMin,  max: POLICIES.privateMax };
        if (type === 'BUSINESS') return { min: POLICIES.businessMin, max: POLICIES.businessMax };
        return { min: 1, max: 52 };
    }

    function updateProductUI() {
        const type     = productType.value;
        const isAuto   = type === 'AUTO';
        const override = isDirectorOverride();
        const isSelect = termWeeks.tagName === 'SELECT';
        const limits   = getTermLimits();

        // Fahrzeug-Felder
        autoHints.style.display     = isAuto ? '' : 'none';
        vehicleModelRow.style.display = isAuto ? '' : 'none';
        vehiclePlateRow.style.display = isAuto ? '' : 'none';

        // Director-Sektion
        if (isDirector) {
            document.getElementById('director_section').style.display = isAuto ? '' : 'none';
            directorCustomRateRow.style.display = override ? '' : 'none';
        }

        // Zinssatz-Vorschlag aus Policy setzen (nur wenn User noch nichts geändert hat)
        const rateMap = { AUTO: POLICIES.rateAuto, PRIVATE: POLICIES.ratePrivate, BUSINESS: POLICIES.rateBusiness };
        if (interestRate.tagName === 'INPUT' && !interestRate.dataset.userChanged) {
            interestRate.value = rateMap[type] ?? POLICIES.rateAuto;
        }

        // Laufzeit-Options
        if (isSelect) {
            Array.from(termWeeks.options).forEach(opt => {
                const w = parseInt(opt.value);
                const visible = w >= limits.min && w <= limits.max;
                opt.style.display = visible ? '' : 'none';
                // Autokredit-Beschriftungen
                if (isAuto && !override) {
                    if (w === 6) opt.textContent = '6 Wochen (20% Rate)';
                    else if (w === 7) opt.textContent = '7 Wochen (15% Rate)';
                    else if (w === 8) opt.textContent = '8 Wochen (10% Rate)';
                    else opt.textContent = w + ' Wochen';
                } else {
                    opt.textContent = w + ' Wochen';
                }
            });
            const tw = parseInt(termWeeks.value);
            if (tw < limits.min || tw > limits.max) termWeeks.value = limits.min;
        } else {
            termWeeks.min = limits.min;
            termWeeks.max = limits.max;
            const tw = parseInt(termWeeks.value) || limits.min;
            if (tw < limits.min || tw > limits.max) termWeeks.value = limits.min;
        }

        // Hint-Text
        const termHint = document.getElementById('term_hint');
        if (isDirector && (override || !isAuto)) {
            termHint.innerHTML = '<span class="text-danger"><i class="bi bi-key me-1"></i>Direktion: freie Laufzeit</span>';
        } else {
            termHint.textContent = limits.min + '–' + limits.max + ' Wochen';
        }

        calculate();
    }

    function calculate() {
        const pp = parseFloat(purchasePrice.value) || 0;
        const dp = parseFloat(downPayment.value) || 0;
        const ir = parseFloat(interestRate.value) || 0;
        const tw = parseInt(termWeeks.value) || 1;
        const isAuto = productType.value === 'AUTO';
        const override = isDirectorOverride();
        const finalRate = parseFloat(customFinalRate.value) || 0;

        const loanAmount = pp - dp;
        const interest = loanAmount * (ir / 100);
        const total = loanAmount + interest;

        let weekly;
        let calcLabel = '';

        if (isAuto && !override) {
            // Standard-Autokredit: degressive Rate
            const pct = getAutoWeeklyPercent(tw);
            weekly = total * pct;
            calcLabel = '<br><small class="text-muted">(' + (pct*100) + '% degressiv)</small>';
        } else if (isAuto && override && directorCustomRate && directorCustomRate.value !== '' && parseFloat(directorCustomRate.value) > 0) {
            // Director mit individuellem Prozentsatz
            const customPct = parseFloat(directorCustomRate.value) / 100;
            weekly = total * customPct;
            calcLabel = '<br><small class="text-danger">(' + (customPct*100) + '% individuell)</small>';
            if (directorCalcType) directorCalcType.textContent = 'Individueller Prozentsatz';
        } else {
            // Gleichmäßige Raten mit optionaler Restrate
            if (finalRate > 0 && tw > 1) {
                weekly = (total - finalRate) / (tw - 1);
                calcLabel = '<br><small class="text-primary">Restrate: ' + formatMoney(finalRate) + '</small>';
            } else {
                weekly = total / tw;
            }
            if (override) {
                calcLabel += '<br><small class="text-danger">gleichmäßige Raten</small>';
                if (directorCalcType) directorCalcType.textContent = 'Gleichmäßige Raten';
            }
        }

        document.getElementById('loan_amount').value = loanAmount.toFixed(0);
        document.getElementById('weekly_rate').value = weekly.toFixed(0);
        document.getElementById('total_interest').value = interest.toFixed(0);
        document.getElementById('total_amount').value = total.toFixed(0);

        document.getElementById('calc_purchase_price').textContent = formatMoney(pp);
        document.getElementById('calc_down_payment').textContent = formatMoney(dp);
        document.getElementById('calc_loan_amount').innerHTML = '<strong>' + formatMoney(loanAmount) + '</strong>';
        document.getElementById('calc_rate_pct').textContent = ir;
        document.getElementById('calc_interest').textContent = formatMoney(interest);
        document.getElementById('calc_total').innerHTML = '<strong>' + formatMoney(total) + '</strong>';
        document.getElementById('calc_term').textContent = tw + ' Wochen';
        document.getElementById('calc_weekly').innerHTML = '<strong>' + formatMoney(weekly) + '</strong>' + calcLabel;
    }

    [purchasePrice, downPayment, interestRate, termWeeks, customFinalRate].forEach(el => {
        el.addEventListener('input', calculate);
        el.addEventListener('change', calculate);
    });
    interestRate.addEventListener('change', () => { interestRate.dataset.userChanged = '1'; });

    productType.addEventListener('change', updateProductUI);

    // Director-Events
    if (directorCheckbox) {
        directorCheckbox.addEventListener('change', updateProductUI);
    }
    if (directorCustomRate) {
        directorCustomRate.addEventListener('input', calculate);
    }

    // Initial
    updateProductUI();
});
</script>

<script>
(function() {
    const CHECK_URL = '<?= APP_URL ?>/pages/schufa/check_borrower.php';
    const borrowerSel = document.getElementById('borrower_id');
    const wrap = document.getElementById('credit-check-wrap');
    const box  = document.getElementById('credit-check-box');

    function fmt(n) {
        return new Intl.NumberFormat('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(n) + ' $';
    }

    function renderCheck(d) {
        if (!d.found || (!d.bank_sections || d.bank_sections.length === 0)) {
            wrap.style.display = 'none';
            return;
        }

        const riskConfig = {
            green:  { color: '#198754', icon: 'bi-check-circle-fill',        label: 'Unauffällig',      cls: 'success' },
            yellow: { color: '#ffc107', icon: 'bi-exclamation-triangle-fill', label: 'Mittleres Risiko', cls: 'warning' },
            red:    { color: '#dc3545', icon: 'bi-x-octagon-fill',            label: 'Hohes Risiko',     cls: 'danger'  },
        };
        const rc = riskConfig[d.risk] || riskConfig.green;

        let html = `
        <div class="card border-${rc.cls}">
            <div class="card-header d-flex align-items-center gap-2 py-2"
                 style="border-color:${rc.color}20;background:${rc.color}12;">
                <div style="width:30px;height:30px;border-radius:50%;background:${rc.color};
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi ${rc.icon} text-white small"></i>
                </div>
                <div class="flex-grow-1">
                    <span class="fw-semibold">Kreditauskunft</span>
                    <span class="badge bg-${rc.cls} ms-2 ${d.risk === 'yellow' ? 'text-dark' : ''}">${rc.label}</span>
                    <a href="${CHECK_URL.replace('check_borrower.php', 'index.php')}?name=${encodeURIComponent(d.name)}"
                       target="_blank" class="btn btn-xs btn-outline-secondary ms-2" title="Vollständige Auskunft">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </div>
                <div class="d-flex gap-3 small text-muted">
                    <span><strong class="text-${d.total_active > 0 ? 'warning' : 'muted'}">${d.total_active}</strong> aktiv</span>
                    <span><strong class="text-${d.total_negative > 0 ? 'danger' : 'muted'}">${d.total_negative}</strong> negativ</span>
                    ${d.total_outstanding > 0 ? `<span>Offene Schuld: <strong class="text-warning">${fmt(d.total_outstanding)}</strong></span>` : ''}
                    ${(d.total_withdrawn > 0) ? `<span title="KD hat Kreditanfragen zurückgezogen (neutraler Vermerk)"><i class="bi bi-x-circle me-1"></i>${d.total_withdrawn} Widerruf${d.total_withdrawn > 1 ? 'e' : ''}</span>` : ''}
                </div>
            </div>
            <div class="card-body p-0">`;

        d.bank_sections.forEach(section => {
            html += `
                <div class="px-3 pt-2 pb-1 border-bottom">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge px-2" style="background:${section.color};">${section.short_code}</span>
                        <span class="small text-muted">${section.bank_name}</span>
                        ${section.is_own ? '<span class="badge bg-primary small">Eigene Bank</span>' : '<span class="badge bg-secondary small">Fremdbank</span>'}
                    </div>
                    <div class="d-flex flex-wrap gap-2 pb-2">`;

            section.loans.forEach(l => {
                const isProb = ['danger','warning'].includes(l.status_c);
                html += `<div class="border rounded px-2 py-1 small ${isProb ? 'border-' + l.status_c : ''}"
                              style="background:rgba(255,255,255,0.03);">
                    <span class="badge bg-${l.status_c} ${l.status_c === 'warning' ? 'text-dark' : ''} me-1">${l.status_l}</span>
                    ${l.product}`;
                if (l.vehicle) html += ` <span class="text-muted">(${l.vehicle})</span>`;
                if (section.is_own && l.file_number) {
                    html += ` <code class="ms-1 small">${l.file_number}</code>`;
                }
                if (l.outstanding > 0) html += ` <span class="text-warning ms-1">${fmt(l.outstanding)}</span>`;
                if (l.days_overdue > 0) html += ` <span class="badge bg-${l.days_overdue > 14 ? 'danger' : 'warning text-dark'} ms-1">${l.days_overdue}d Verzug</span>`;
                html += `</div>`;
            });

            html += `</div></div>`;
        });

        html += `</div></div>`;

        box.innerHTML = html;
        wrap.style.display = '';
    }

    function loadCheck(borrowerId) {
        if (!borrowerId) { wrap.style.display = 'none'; return; }
        box.innerHTML = '<div class="text-muted small p-2"><i class="bi bi-hourglass-split me-1"></i>Kreditauskunft wird geladen…</div>';
        wrap.style.display = '';
        fetch(CHECK_URL + '?borrower_id=' + borrowerId)
            .then(r => r.json())
            .then(renderCheck)
            .catch(() => { wrap.style.display = 'none'; });
    }

    borrowerSel.addEventListener('change', function() { loadCheck(this.value); });

    // Sofort laden wenn Kreditnehmer bereits vorausgewählt
    if (borrowerSel.value) loadCheck(borrowerSel.value);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
