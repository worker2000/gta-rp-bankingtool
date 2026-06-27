<?php
/**
 * Fortis Finance – Krankenversicherung: Leistungsantrag stellen
 * Unterstützt: contract_id (Einzelvertrag) oder member_id (Gruppenvertrag)
 */
ob_start();
require_once __DIR__ . '/../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$contractId = intval($_GET['contract_id'] ?? 0);
$memberId   = intval($_GET['member_id']   ?? 0);

// Modus: Mitglied (Gruppenvertrag) oder Einzelvertrag
$member   = null;
$contract = null;
$isMemberMode = false;

if ($memberId) {
    $member = Database::fetchOne("
        SELECT im.*,
               igc.contract_number as gc_contract_number, igc.status as gc_status,
               igc.employer_id, ie.company_name
        FROM insurance_members im
        JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
        JOIN insurance_employers ie ON igc.employer_id = ie.id
        WHERE im.id = ? AND im.bank_id = 2
    ", [$memberId]);

    if (!$member) {
        setFlash('error', 'Mitglied nicht gefunden.');
        header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
        exit;
    }

    if (!in_array($member['status'], ['ACTIVE', 'SUSPENDED'])) {
        setFlash('error', 'Leistungsanträge nur für aktive oder ruhende Mitglieder möglich.');
        header('Location: ' . APP_URL . '/pages/insurance/group/members/view.php?id=' . $memberId);
        exit;
    }

    $isMemberMode = true;

} elseif ($contractId) {
    $contract = Database::fetchOne("
        SELECT ic.*, ip.name as product_name, ip.type as product_type,
               ip.deductible, ip.max_insured_sum
        FROM insurance_contracts ic
        JOIN insurance_products ip ON ic.product_id = ip.id
        WHERE ic.id = ? AND ic.bank_id = 2
    ", [$contractId]);

    if (!$contract) {
        setFlash('error', 'Vertrag nicht gefunden oder nicht zugänglich.');
        header('Location: ' . APP_URL . '/pages/insurance/index.php');
        exit;
    }

    if (!in_array($contract['status'], ['ACTIVE', 'SUSPENDED'])) {
        setFlash('error', 'Leistungsanträge können nur für aktive oder ruhende Verträge gestellt werden.');
        header('Location: ' . APP_URL . '/pages/insurance/view.php?id=' . $contractId . '&tab=claims');
        exit;
    }

} else {
    setFlash('error', 'Kein Vertrag oder Mitglied angegeben.');
    header('Location: ' . APP_URL . '/pages/insurance/index.php');
    exit;
}

$pageTitle = $isMemberMode
    ? 'Leistungsantrag – ' . $member['last_name'] . ', ' . $member['first_name']
    : 'Leistungsantrag – ' . $contract['contract_number'];
$errors = [];
$data = [
    'treatment_date'   => date('Y-m-d'),
    'treatment_end'    => '',
    'treatment_type'   => 'DOCTOR',
    'diagnosis'        => '',
    'provider_name'    => '',
    'provider_address' => '',
    'billed_amount'    => '',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $data['treatment_date']   = trim($_POST['treatment_date'] ?? '');
        $data['treatment_end']    = trim($_POST['treatment_end'] ?? '');
        $data['treatment_type']   = trim($_POST['treatment_type'] ?? 'DOCTOR');
        $data['diagnosis']        = trim($_POST['diagnosis'] ?? '');
        $data['provider_name']    = trim($_POST['provider_name'] ?? '');
        $data['provider_address'] = trim($_POST['provider_address'] ?? '');
        $data['billed_amount']    = $_POST['billed_amount'] ?? '';
        $data['notes']            = trim($_POST['notes'] ?? '');

        $billedAmount = floatval($data['billed_amount']);

        if (!$data['treatment_date'])  $errors[] = 'Behandlungsdatum ist Pflichtfeld.';
        if (!$data['treatment_type'])  $errors[] = 'Behandlungsart ist Pflichtfeld.';
        if (!$data['provider_name'])   $errors[] = 'Behandler / Einrichtung ist Pflichtfeld.';
        if ($billedAmount <= 0)        $errors[] = 'Rechnungsbetrag muss größer als 0 sein.';
        if ($data['treatment_end'] && $data['treatment_end'] < $data['treatment_date']) {
            $errors[] = 'Behandlungsende darf nicht vor dem Behandlungsbeginn liegen.';
        }

        // Vertragsbeginn-Check (nur für Einzelverträge mit Wartezeit)
        if (!$isMemberMode) {
            if ($data['treatment_date'] < $contract['start_date']) {
                $errors[] = 'Behandlungsdatum liegt vor dem Vertragsbeginn.';
            }

            $waitingPeriod = intval(Database::fetchOne(
                "SELECT waiting_period_days FROM insurance_products WHERE id = ?",
                [$contract['product_id']]
            )['waiting_period_days'] ?? 0);

            if ($waitingPeriod > 0) {
                $coverageStart = date('Y-m-d', strtotime($contract['start_date'] . ' +' . $waitingPeriod . ' days'));
                if ($data['treatment_date'] < $coverageStart) {
                    $errors[] = sprintf(
                        'Behandlung liegt innerhalb der Wartezeit. Leistungen ab %s erstattungsfähig.',
                        formatDate($coverageStart)
                    );
                }
            }
        } else {
            // Member-Modus: Eintrittsdatum prüfen
            if ($data['treatment_date'] < $member['start_date']) {
                $errors[] = 'Behandlungsdatum liegt vor dem Eintrittsdatum des Mitglieds.';
            }
        }

        if (empty($errors)) {
            // Antragsnummer generieren
            $year = date('Y', strtotime($data['treatment_date']));
            $lastClaim = Database::fetchOne(
                "SELECT claim_number FROM insurance_claims WHERE bank_id = 2 ORDER BY id DESC LIMIT 1"
            );
            $nextSeq = 1;
            if ($lastClaim) {
                preg_match('/(\d+)$/', $lastClaim['claim_number'], $m);
                $nextSeq = intval($m[1] ?? 0) + 1;
            }
            $claimNumber = sprintf('FF-LS-%s-%05d', $year, $nextSeq);

            try {
                $claimId = Database::insert('insurance_claims', [
                    'bank_id'          => 2,
                    'contract_id'      => $isMemberMode ? null : $contractId,
                    'member_id'        => $isMemberMode ? $memberId : null,
                    'claim_number'     => $claimNumber,
                    'treatment_date'   => $data['treatment_date'],
                    'treatment_end'    => $data['treatment_end'] ?: null,
                    'treatment_type'   => $data['treatment_type'],
                    'diagnosis'        => $data['diagnosis'] ?: null,
                    'provider_name'    => $data['provider_name'],
                    'provider_address' => $data['provider_address'] ?: null,
                    'billed_amount'    => $billedAmount,
                    'status'           => 'SUBMITTED',
                    'submitted_by'     => Auth::userId(),
                ]);

                AuditLog::log('CREATE', 'insurance_claim', $claimId, null, [
                    'claim_number'  => $claimNumber,
                    'context'       => $isMemberMode
                        ? 'member:' . $member['last_name'] . '/' . $member['gc_contract_number']
                        : 'contract:' . $contract['contract_number'],
                    'billed_amount' => $billedAmount,
                ]);

                setFlash('success', "Leistungsantrag {$claimNumber} erfolgreich eingereicht.");
                header('Location: ' . APP_URL . '/pages/insurance/claims/view.php?id=' . $claimId);
                exit;

            } catch (Exception $e) {
                $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }
    }
}

$treatmentTypes = [
    'DOCTOR'     => 'Hausarzt',
    'SPECIALIST' => 'Facharzt',
    'HOSPITAL'   => 'Krankenhaus',
    'DENTAL'     => 'Zahnarzt',
    'VISION'     => 'Optiker/Sehhilfe',
    'MEDICATION' => 'Medikamente',
    'THERAPY'    => 'Therapie/Reha',
    'OTHER'      => 'Sonstiges',
];
?>

<?php if ($isMemberMode): ?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $member['employer_id'] ?>">
                <?= e($member['company_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $member['group_contract_id'] ?>">
                <?= e($member['gc_contract_number']) ?>
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $memberId ?>">
                <?= e($member['last_name'] . ', ' . $member['first_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active">Leistungsantrag</li>
    </ol>
</nav>
<?php else: ?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $contractId ?>"><?= e($contract['contract_number']) ?></a></li>
        <li class="breadcrumb-item active">Leistungsantrag</li>
    </ol>
</nav>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-file-medical me-2"></i>Leistungsantrag stellen</h4>
    <?php if ($isMemberMode): ?>
    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $memberId ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $contractId ?>&tab=claims"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
    <?php endif; ?>
</div>

<!-- Kontext-Info -->
<?php if ($isMemberMode): ?>
<div class="alert alert-info mb-4">
    <div class="row">
        <div class="col-md-3"><strong>Mitglied:</strong> <?= e($member['last_name'] . ', ' . $member['first_name']) ?></div>
        <div class="col-md-3"><strong>Arbeitgeber:</strong> <?= e($member['company_name']) ?></div>
        <div class="col-md-3"><strong>Gruppenvertrag:</strong> <?= e($member['gc_contract_number']) ?></div>
        <div class="col-md-3"><strong>Klasse:</strong> <?= $member['insurance_class'] ?> ($<?= number_format($member['premium_monthly'], 0) ?>/Monat)</div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info mb-4">
    <div class="row">
        <div class="col-md-3"><strong>Vertrag:</strong> <?= e($contract['contract_number']) ?></div>
        <div class="col-md-3"><strong>Versicherter:</strong> <?= e($contract['insured_last_name'] . ', ' . $contract['insured_first_name']) ?></div>
        <div class="col-md-3"><strong>Tarif:</strong> <?= e($contract['product_name']) ?></div>
        <div class="col-md-3"><strong>SB/Jahr:</strong> <?= $contract['deductible'] > 0 ? formatMoney($contract['deductible']) : '–' ?></div>
    </div>
</div>
<?php endif; ?>

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
            <div class="card mb-4">
                <div class="card-header">Behandlungsdetails</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Behandlungsdatum *</label>
                            <input type="date" class="form-control" name="treatment_date"
                                   value="<?= e($data['treatment_date']) ?>" required
                                   max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Behandlungsende</label>
                            <input type="date" class="form-control" name="treatment_end"
                                   value="<?= e($data['treatment_end']) ?>">
                            <div class="form-text">Bei stationären Aufenthalten</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Behandlungsart *</label>
                            <select class="form-select" name="treatment_type" required>
                                <?php foreach ($treatmentTypes as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $data['treatment_type'] === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Diagnose / Behandlungsgrund</label>
                            <input type="text" class="form-control" name="diagnosis"
                                   value="<?= e($data['diagnosis']) ?>"
                                   placeholder="z.B. ICD-10 Code oder Freitext">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Behandler / Einrichtung</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name / Einrichtung *</label>
                            <input type="text" class="form-control" name="provider_name"
                                   value="<?= e($data['provider_name']) ?>"
                                   placeholder="z.B. Dr. Schmidt, Sandy Shore Hospital" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="provider_address"
                                   value="<?= e($data['provider_address']) ?>"
                                   placeholder="Straße, PLZ Ort">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Rechnung</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Rechnungsbetrag ($) *</label>
                            <input type="number" step="0.01" min="0.01" class="form-control"
                                   name="billed_amount" id="billed_amount"
                                   value="<?= e($data['billed_amount']) ?>" required
                                   onchange="updateEstimate()">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Hinweise zur Rechnung</label>
                            <textarea class="form-control" name="notes" rows="2"
                                      placeholder="z.B. Rechnung liegt im Original vor..."><?= e($data['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card sticky-top mb-3" style="top: 1rem;">
                <div class="card-header">Erstattungsvorschau</div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tr>
                            <td class="text-muted">Rechnungsbetrag</td>
                            <td class="text-end" id="est_billed">–</td>
                        </tr>
                        <?php if (!$isMemberMode && $contract['deductible'] > 0): ?>
                        <tr>
                            <td class="text-muted">Selbstbeteiligung</td>
                            <td class="text-end text-warning"><?= formatMoney($contract['deductible']) ?>/Jahr</td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!$isMemberMode && $contract['max_insured_sum']): ?>
                        <tr>
                            <td class="text-muted">Max. VS-Summe</td>
                            <td class="text-end"><?= formatMoney($contract['max_insured_sum']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($isMemberMode): ?>
                        <tr>
                            <td class="text-muted">Klasse</td>
                            <td class="text-end">Klasse <?= $member['insurance_class'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Monatsbeitrag</td>
                            <td class="text-end">$<?= number_format($member['premium_monthly'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <div class="alert alert-secondary small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Die genaue Erstattungshöhe wird durch den Sachbearbeiter nach Prüfung festgelegt.
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-send me-2"></i>Antrag einreichen
                    </button>
                    <?php if ($isMemberMode): ?>
                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $memberId ?>"
                       class="btn btn-outline-secondary w-100">Abbrechen</a>
                    <?php else: ?>
                    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $contractId ?>&tab=claims"
                       class="btn btn-outline-secondary w-100">Abbrechen</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function updateEstimate() {
    const billed = parseFloat(document.getElementById('billed_amount').value) || 0;
    const fmt = v => new Intl.NumberFormat('de-DE', {style:'currency', currency:'USD', minimumFractionDigits:2}).format(v);
    document.getElementById('est_billed').textContent = billed > 0 ? fmt(billed) : '–';
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
