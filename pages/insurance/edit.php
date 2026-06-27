<?php
ob_start();
/**
 * Fortis Finance – Krankenversicherung: Vertrag bearbeiten
 */
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/insurance/index.php');
    exit;
}

$contract = Database::fetchOne("
    SELECT ic.*, ip.name as product_name, ip.monthly_base_premium
    FROM insurance_contracts ic
    JOIN insurance_products ip ON ic.product_id = ip.id
    WHERE ic.id = ? AND ic.bank_id = 2
", [$id]);

if (!$contract) {
    setFlash('error', 'Vertrag nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/index.php');
    exit;
}

$pageTitle = 'Vertrag bearbeiten – ' . $contract['contract_number'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $insuredFirstName  = trim($_POST['insured_first_name'] ?? '');
    $insuredLastName   = trim($_POST['insured_last_name'] ?? '');
    $insuredDob        = trim($_POST['insured_dob'] ?? '');
    $insuredGender     = trim($_POST['insured_gender'] ?? '');
    $insuredPhone      = trim($_POST['insured_phone'] ?? '');
    $insuredEmail      = trim($_POST['insured_email'] ?? '');
    $insuredAddress    = trim($_POST['insured_address'] ?? '');
    $insuredIban       = strtoupper(preg_replace('/\s+/', '', $_POST['insured_iban'] ?? ''));
    $endDate           = trim($_POST['end_date'] ?? '');
    $riskSurchargePct  = floatval($_POST['risk_surcharge_pct'] ?? 0);
    $preExistingConds  = trim($_POST['pre_existing_conds'] ?? '');
    $notes             = trim($_POST['notes'] ?? '');

    if (!$insuredFirstName) $errors[] = 'Vorname ist Pflichtfeld.';
    if (!$insuredLastName)  $errors[] = 'Nachname ist Pflichtfeld.';
    if ($insuredEmail && !filter_var($insuredEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ungültige E-Mail-Adresse.';
    }
    if ($riskSurchargePct < 0 || $riskSurchargePct > 200) {
        $errors[] = 'Risikozuschlag muss zwischen 0 und 200 % liegen.';
    }
    if ($endDate && $endDate <= $contract['start_date']) {
        $errors[] = 'Vertragsende muss nach dem Vertragsbeginn liegen.';
    }

    if (empty($errors)) {
        // Neue Prämie berechnen falls Risikozuschlag geändert
        $newPremium = round(
            floatval($contract['monthly_base_premium']) * (1 + $riskSurchargePct / 100),
            2
        );

        $oldData = [
            'insured_first_name' => $contract['insured_first_name'],
            'insured_last_name'  => $contract['insured_last_name'],
            'risk_surcharge_pct' => $contract['risk_surcharge_pct'],
            'premium_amount'     => $contract['premium_amount'],
        ];

        Database::update('insurance_contracts', [
            'insured_first_name' => $insuredFirstName,
            'insured_last_name'  => $insuredLastName,
            'insured_dob'        => $insuredDob ?: null,
            'insured_gender'     => $insuredGender ?: null,
            'insured_phone'      => $insuredPhone ?: null,
            'insured_email'      => $insuredEmail ?: null,
            'insured_address'    => $insuredAddress ?: null,
            'insured_iban'       => $insuredIban ?: null,
            'end_date'           => $endDate ?: null,
            'risk_surcharge_pct' => $riskSurchargePct,
            'premium_amount'     => $newPremium,
            'pre_existing_conds' => $preExistingConds ?: null,
            'notes'              => $notes ?: null,
        ], 'id = ?', [$id]);

        AuditLog::log('UPDATE', 'insurance_contract', $id, $oldData, [
            'insured_first_name' => $insuredFirstName,
            'insured_last_name'  => $insuredLastName,
            'risk_surcharge_pct' => $riskSurchargePct,
            'premium_amount'     => $newPremium,
        ]);

        setFlash('success', 'Vertrag aktualisiert.');
        header('Location: ' . APP_URL . '/pages/insurance/view.php?id=' . $id);
        exit;
    }
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $id ?>"><?= e($contract['contract_number']) ?></a></li>
        <li class="breadcrumb-item active">Bearbeiten</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-pencil me-2"></i>Vertrag bearbeiten</h4>
    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <strong><?= e($contract['contract_number']) ?></strong> &middot;
    <?= e($contract['product_name']) ?> &middot;
    Vertragsbeginn: <?= formatDate($contract['start_date']) ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <div class="row g-4">
        <div class="col-md-8">
            <!-- Versicherter -->
            <div class="card mb-4">
                <div class="card-header">Versicherter</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Vorname *</label>
                            <input type="text" class="form-control" name="insured_first_name"
                                   value="<?= e($_POST['insured_first_name'] ?? $contract['insured_first_name']) ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nachname *</label>
                            <input type="text" class="form-control" name="insured_last_name"
                                   value="<?= e($_POST['insured_last_name'] ?? $contract['insured_last_name']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Geschlecht</label>
                            <select class="form-select" name="insured_gender">
                                <option value="">–</option>
                                <option value="M" <?= ($_POST['insured_gender'] ?? $contract['insured_gender']) === 'M' ? 'selected' : '' ?>>M</option>
                                <option value="F" <?= ($_POST['insured_gender'] ?? $contract['insured_gender']) === 'F' ? 'selected' : '' ?>>W</option>
                                <option value="D" <?= ($_POST['insured_gender'] ?? $contract['insured_gender']) === 'D' ? 'selected' : '' ?>>D</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Geburtsdatum</label>
                            <input type="date" class="form-control" name="insured_dob"
                                   value="<?= e($_POST['insured_dob'] ?? $contract['insured_dob']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="insured_phone"
                                   value="<?= e($_POST['insured_phone'] ?? $contract['insured_phone']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="insured_email"
                                   value="<?= e($_POST['insured_email'] ?? $contract['insured_email']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="insured_address"
                                   value="<?= e($_POST['insured_address'] ?? $contract['insured_address']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IBAN (Lastschrift)</label>
                            <input type="text" class="form-control" name="insured_iban"
                                   value="<?= e($_POST['insured_iban'] ?? $contract['insured_iban']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vertragsdaten (editierbare Felder) -->
            <div class="card mb-4">
                <div class="card-header">Vertragsdaten</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Vertragsende</label>
                            <input type="date" class="form-control" name="end_date"
                                   value="<?= e($_POST['end_date'] ?? $contract['end_date']) ?>">
                            <div class="form-text">Leer = unbefristet</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Risikozuschlag (%)</label>
                            <input type="number" step="0.5" min="0" max="200" class="form-control"
                                   name="risk_surcharge_pct" id="risk_surcharge_pct"
                                   value="<?= e($_POST['risk_surcharge_pct'] ?? $contract['risk_surcharge_pct']) ?>"
                                   onchange="updateNewPremium()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Neuer Monatsbeitrag</label>
                            <input type="text" class="form-control" id="new_premium_display" readonly
                                   value="<?= formatMoney($contract['premium_amount']) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Vorerkrankungen</label>
                            <textarea class="form-control" name="pre_existing_conds" rows="3"><?= e($_POST['pre_existing_conds'] ?? $contract['pre_existing_conds']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($_POST['notes'] ?? $contract['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card sticky-top" style="top: 1rem;">
                <div class="card-header">Speichern</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Tarif und Vertragsbeginn können nicht geändert werden. Für Tarifwechsel bitte neuen Vertrag anlegen.
                    </p>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-lg me-2"></i>Änderungen speichern
                    </button>
                    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $id ?>"
                       class="btn btn-outline-secondary w-100">Abbrechen</a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const basePremium = <?= floatval($contract['monthly_base_premium']) ?>;

function updateNewPremium() {
    const surcharge = parseFloat(document.getElementById('risk_surcharge_pct').value) || 0;
    const newPremium = basePremium * (1 + surcharge / 100);
    document.getElementById('new_premium_display').value =
        new Intl.NumberFormat('de-DE', {style:'currency', currency:'USD', minimumFractionDigits:2}).format(newPremium);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
