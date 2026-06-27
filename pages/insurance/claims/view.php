<?php
/**
 * Fortis Finance – Krankenversicherung: Leistungsantrag prüfen / anzeigen
 */
ob_start();
require_once __DIR__ . '/../../../includes/header.php';
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

// Unterstützt contract_id (Einzelvertrag) und member_id (Gruppenvertrag)
$claim = Database::fetchOne("
    SELECT cl.*,
           ic.contract_number, ic.insured_first_name, ic.insured_last_name,
           ic.insured_iban, ic.product_id,
           ip.name as product_name, ip.deductible as product_deductible,
           im.first_name as member_first_name, im.last_name as member_last_name,
           im.iban as member_iban, im.insurance_class, im.premium_monthly,
           igc.contract_number as gc_contract_number, igc.employer_id,
           ie.company_name,
           u1.full_name as submitted_by_name,
           u2.full_name as reviewed_by_name
    FROM insurance_claims cl
    LEFT JOIN insurance_contracts ic ON cl.contract_id = ic.id
    LEFT JOIN insurance_products ip ON ic.product_id = ip.id
    LEFT JOIN insurance_members im ON cl.member_id = im.id
    LEFT JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
    LEFT JOIN insurance_employers ie ON igc.employer_id = ie.id
    LEFT JOIN users u1 ON cl.submitted_by = u1.id
    LEFT JOIN users u2 ON cl.reviewed_by = u2.id
    WHERE cl.id = ? AND cl.bank_id = 2
", [$id]);

if (!$claim) {
    setFlash('error', 'Leistungsantrag nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/index.php');
    exit;
}

$isMemberClaim = !empty($claim['member_id']);

$pageTitle = 'Leistungsantrag ' . $claim['claim_number'];
$errors = [];

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $oldStatus = $claim['status'];

    switch ($action) {
        case 'start_review':
            if ($claim['status'] === 'SUBMITTED') {
                Database::update('insurance_claims', [
                    'status'      => 'IN_REVIEW',
                    'reviewed_by' => Auth::userId(),
                ], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_claim', $id,
                    ['status' => $oldStatus], ['status' => 'IN_REVIEW']);
                setFlash('success', 'Antrag wird geprüft.');
            }
            break;

        case 'approve':
            if (in_array($claim['status'], ['SUBMITTED', 'IN_REVIEW'])) {
                $coveredAmount    = floatval($_POST['covered_amount'] ?? 0);
                $deductibleApplied = floatval($_POST['deductible_applied'] ?? 0);
                $payoutAmount     = max(0, $coveredAmount - $deductibleApplied);
                $reviewerNotes    = trim($_POST['reviewer_notes'] ?? '');

                if ($coveredAmount <= 0) {
                    $errors[] = 'Anerkannter Betrag muss größer als 0 sein.';
                } elseif ($coveredAmount > $claim['billed_amount']) {
                    $errors[] = 'Anerkannter Betrag kann den Rechnungsbetrag nicht überschreiten.';
                } else {
                    $newStatus = $coveredAmount < $claim['billed_amount'] ? 'PARTIAL' : 'APPROVED';
                    Database::update('insurance_claims', [
                        'status'             => $newStatus,
                        'covered_amount'     => $coveredAmount,
                        'deductible_applied' => $deductibleApplied,
                        'payout_amount'      => $payoutAmount,
                        'reviewer_notes'     => $reviewerNotes ?: null,
                        'reviewed_by'        => Auth::userId(),
                        'reviewed_at'        => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$id]);
                    AuditLog::log('STATUS_CHANGE', 'insurance_claim', $id,
                        ['status' => $oldStatus],
                        ['status' => $newStatus, 'covered' => $coveredAmount, 'payout' => $payoutAmount]);
                    setFlash('success', 'Leistungsantrag genehmigt. Auszahlungsbetrag: ' . formatMoney($payoutAmount));
                }
            }
            break;

        case 'reject':
            if (in_array($claim['status'], ['SUBMITTED', 'IN_REVIEW'])) {
                $rejectionReason = trim($_POST['rejection_reason'] ?? '');
                if (!$rejectionReason) {
                    $errors[] = 'Ablehnungsgrund ist Pflichtfeld.';
                } else {
                    Database::update('insurance_claims', [
                        'status'           => 'REJECTED',
                        'rejection_reason' => $rejectionReason,
                        'reviewer_notes'   => trim($_POST['reviewer_notes'] ?? '') ?: null,
                        'reviewed_by'      => Auth::userId(),
                        'reviewed_at'      => date('Y-m-d H:i:s'),
                        'payout_amount'    => 0,
                    ], 'id = ?', [$id]);
                    AuditLog::log('STATUS_CHANGE', 'insurance_claim', $id,
                        ['status' => $oldStatus], ['status' => 'REJECTED', 'reason' => $rejectionReason]);
                    setFlash('success', 'Leistungsantrag abgelehnt.');
                }
            }
            break;

        case 'mark_paid':
            if (in_array($claim['status'], ['APPROVED', 'PARTIAL'])) {
                Database::update('insurance_claims', [
                    'status'  => 'PAID',
                    'paid_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                AuditLog::log('PAYMENT', 'insurance_claim', $id, null,
                    ['payout_amount' => $claim['payout_amount']]);
                setFlash('success', 'Leistung als ausgezahlt markiert.');
            }
            break;
    }

    if (empty($errors)) {
        header('Location: ' . APP_URL . '/pages/insurance/claims/view.php?id=' . $id);
        exit;
    }

    // Antrag neu laden nach Fehlern
    $claim = Database::fetchOne("
        SELECT cl.*,
               ic.contract_number, ic.insured_first_name, ic.insured_last_name,
               ic.insured_iban, ic.product_id, ic.deductible_applied,
               ip.name as product_name, ip.deductible as product_deductible,
               im.first_name as member_first_name, im.last_name as member_last_name,
               im.iban as member_iban, im.insurance_class, im.premium_monthly,
               igc.contract_number as gc_contract_number, igc.employer_id,
               ie.company_name,
               u1.full_name as submitted_by_name,
               u2.full_name as reviewed_by_name
        FROM insurance_claims cl
        LEFT JOIN insurance_contracts ic ON cl.contract_id = ic.id
        LEFT JOIN insurance_products ip ON ic.product_id = ip.id
        LEFT JOIN insurance_members im ON cl.member_id = im.id
        LEFT JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
        LEFT JOIN insurance_employers ie ON igc.employer_id = ie.id
        LEFT JOIN users u1 ON cl.submitted_by = u1.id
        LEFT JOIN users u2 ON cl.reviewed_by = u2.id
        WHERE cl.id = ? AND cl.bank_id = 2
    ", [$id]);
    $isMemberClaim = !empty($claim['member_id']);
}

function translateClaimStatus(string $s): string {
    return match($s) {
        'SUBMITTED'  => 'Eingereicht',
        'IN_REVIEW'  => 'In Prüfung',
        'APPROVED'   => 'Genehmigt',
        'PARTIAL'    => 'Teilgenehmigt',
        'REJECTED'   => 'Abgelehnt',
        'PAID'       => 'Ausgezahlt',
        default      => $s,
    };
}
function claimStatusBadge(string $s): string {
    return match($s) {
        'SUBMITTED'  => 'bg-info',
        'IN_REVIEW'  => 'bg-warning',
        'APPROVED'   => 'bg-success',
        'PARTIAL'    => 'bg-warning',
        'REJECTED'   => 'bg-danger',
        'PAID'       => 'bg-primary',
        default      => 'bg-secondary',
    };
}
function translateTreatmentType(string $t): string {
    return match($t) {
        'DOCTOR'     => 'Hausarzt',
        'SPECIALIST' => 'Facharzt',
        'HOSPITAL'   => 'Krankenhaus',
        'DENTAL'     => 'Zahnarzt',
        'VISION'     => 'Optiker/Sehhilfe',
        'MEDICATION' => 'Medikamente',
        'THERAPY'    => 'Therapie/Reha',
        'OTHER'      => 'Sonstiges',
        default      => $t,
    };
}

$canReview = in_array($claim['status'], ['SUBMITTED', 'IN_REVIEW']);
?>

<?php if ($isMemberClaim): ?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <?php if ($claim['employer_id']): ?>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $claim['employer_id'] ?>">
                <?= e($claim['company_name']) ?>
            </a>
        </li>
        <?php endif; ?>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $claim['member_id'] ?>">
                <?= e($claim['member_last_name'] . ', ' . $claim['member_first_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active"><?= e($claim['claim_number']) ?></li>
    </ol>
</nav>
<?php else: ?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $claim['contract_id'] ?>">
                <?= e($claim['contract_number']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active"><?= e($claim['claim_number']) ?></li>
    </ol>
</nav>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-file-medical me-2"></i><?= e($claim['claim_number']) ?>
            <span class="badge <?= claimStatusBadge($claim['status']) ?> ms-2">
                <?= translateClaimStatus($claim['status']) ?>
            </span>
        </h4>
        <div class="text-muted">
            <?php if ($isMemberClaim): ?>
            <?= e($claim['gc_contract_number'] ?? '–') ?> &middot;
            <?= e($claim['member_last_name'] . ', ' . $claim['member_first_name']) ?>
            <?php if ($claim['company_name']): ?>
            &middot; <?= e($claim['company_name']) ?>
            <?php endif; ?>
            <?php else: ?>
            <?= e($claim['contract_number']) ?> &middot;
            <?= e($claim['insured_last_name'] . ', ' . $claim['insured_first_name']) ?> &middot;
            <?= e($claim['product_name']) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isMemberClaim): ?>
    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $claim['member_id'] ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-2"></i>Zurück zum Mitglied
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/pages/insurance/view.php?id=<?= $claim['contract_id'] ?>&tab=claims"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-2"></i>Zurück zum Vertrag
    </a>
    <?php endif; ?>
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

<div class="row g-4">
    <!-- Linke Spalte: Details -->
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header">Behandlung</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Art</td>
                        <td><span class="badge bg-secondary"><?= translateTreatmentType($claim['treatment_type']) ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Datum</td>
                        <td>
                            <?= formatDate($claim['treatment_date']) ?>
                            <?php if ($claim['treatment_end']): ?>
                            – <?= formatDate($claim['treatment_end']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($claim['diagnosis']): ?>
                    <tr>
                        <td class="text-muted">Diagnose</td>
                        <td><?= e($claim['diagnosis']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Behandler</td>
                        <td>
                            <strong><?= e($claim['provider_name']) ?></strong>
                            <?php if ($claim['provider_address']): ?>
                            <br><small class="text-muted"><?= e($claim['provider_address']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Beträge</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Rechnungsbetrag</td>
                        <td class="text-end"><strong><?= formatMoney($claim['billed_amount']) ?></strong></td>
                    </tr>
                    <?php if ($claim['covered_amount'] !== null): ?>
                    <tr>
                        <td class="text-muted">Anerkannter Betrag</td>
                        <td class="text-end"><?= formatMoney($claim['covered_amount']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($claim['deductible_applied'] > 0): ?>
                    <tr>
                        <td class="text-muted">Selbstbeteiligung</td>
                        <td class="text-end text-warning">–<?= formatMoney($claim['deductible_applied']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($claim['payout_amount'] !== null): ?>
                    <tr class="table-success">
                        <td><strong>Auszahlungsbetrag</strong></td>
                        <td class="text-end"><strong><?= formatMoney($claim['payout_amount']) ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($claim['product_deductible'] > 0): ?>
                    <tr>
                        <td class="text-muted small">SB-Rahmen/Jahr</td>
                        <td class="text-end small"><?= formatMoney($claim['product_deductible']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($claim['rejection_reason']): ?>
        <div class="card mb-3 border-danger">
            <div class="card-header bg-danger bg-opacity-10">Ablehnungsgrund</div>
            <div class="card-body">
                <p class="mb-0"><?= nl2br(e($claim['rejection_reason'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($claim['reviewer_notes']): ?>
        <div class="card mb-3">
            <div class="card-header">Prüfnotizen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($claim['reviewer_notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Tracking</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Eingereicht</td>
                        <td><?= formatDateTime($claim['created_at']) ?></td>
                    </tr>
                    <?php if ($claim['submitted_by_name']): ?>
                    <tr>
                        <td class="text-muted">durch</td>
                        <td><?= e($claim['submitted_by_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($claim['reviewed_at']): ?>
                    <tr>
                        <td class="text-muted">Geprüft</td>
                        <td><?= formatDateTime($claim['reviewed_at']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">durch</td>
                        <td><?= e($claim['reviewed_by_name'] ?? '–') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($claim['paid_at']): ?>
                    <tr>
                        <td class="text-muted">Ausgezahlt</td>
                        <td><?= formatDateTime($claim['paid_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php
                    $displayIban = $isMemberClaim ? ($claim['member_iban'] ?? null) : ($claim['insured_iban'] ?? null);
                    if ($displayIban): ?>
                    <tr>
                        <td class="text-muted">Ziel-IBAN</td>
                        <td><code><?= e($displayIban) ?></code></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte: Prüfung / Aktionen -->
    <div class="col-md-7">
        <?php if ($claim['status'] === 'SUBMITTED'): ?>
        <!-- Prüfung starten -->
        <div class="card mb-4">
            <div class="card-header">Antrag annehmen</div>
            <div class="card-body">
                <p class="text-muted small">Der Antrag wurde noch nicht angenommen. Starten Sie die Prüfung, um den Antrag zu bearbeiten.</p>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="start_review">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-search me-2"></i>Prüfung starten
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canReview): ?>
        <!-- Genehmigung -->
        <div class="card mb-4">
            <div class="card-header bg-success bg-opacity-10">
                <i class="bi bi-check-circle me-2 text-success"></i>Leistung genehmigen
            </div>
            <div class="card-body">
                <form method="POST" id="approveForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Anerkannter Betrag ($) *</label>
                            <input type="number" step="0.01" min="0.01"
                                   max="<?= $claim['billed_amount'] ?>"
                                   class="form-control" name="covered_amount" id="covered_amount"
                                   value="<?= $claim['billed_amount'] ?>"
                                   required onchange="updatePayout()">
                            <div class="form-text">Max: <?= formatMoney($claim['billed_amount']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Selbstbeteiligung ($)</label>
                            <input type="number" step="0.01" min="0" class="form-control"
                                   name="deductible_applied" id="deductible_applied"
                                   value="<?= $claim['product_deductible'] > 0 ? min($claim['product_deductible'], $claim['billed_amount']) : 0 ?>"
                                   onchange="updatePayout()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Auszahlungsbetrag</label>
                            <input type="text" class="form-control" id="payout_display" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Prüfnotizen</label>
                            <textarea class="form-control" name="reviewer_notes" rows="2"
                                      placeholder="Interne Notizen zur Prüfentscheidung..."></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success me-2">
                            <i class="bi bi-check-lg me-2"></i>Genehmigen
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ablehnung -->
        <div class="card">
            <div class="card-header bg-danger bg-opacity-10">
                <i class="bi bi-x-circle me-2 text-danger"></i>Leistung ablehnen
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <div class="mb-3">
                        <label class="form-label">Ablehnungsgrund *</label>
                        <textarea class="form-control" name="rejection_reason" rows="3" required
                                  placeholder="z.B. Nicht erstattungsfähige Behandlung, Wartezeit nicht abgelaufen..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prüfnotizen</label>
                        <textarea class="form-control" name="reviewer_notes" rows="2"
                                  placeholder="Interne Notizen..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-2"></i>Ablehnen
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array($claim['status'], ['APPROVED', 'PARTIAL'])): ?>
        <!-- Auszahlung -->
        <div class="card">
            <div class="card-header bg-primary bg-opacity-10">
                <i class="bi bi-cash me-2 text-primary"></i>Leistung auszahlen
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <p>Genehmigter Auszahlungsbetrag: <strong><?= formatMoney($claim['payout_amount']) ?></strong></p>
                    <?php
                    $payoutIban = $isMemberClaim ? ($claim['member_iban'] ?? null) : ($claim['insured_iban'] ?? null);
                    if ($payoutIban): ?>
                    <p class="text-muted small">Ziel-IBAN: <code><?= e($payoutIban) ?></code></p>
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="mark_paid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-2"></i>Als ausgezahlt markieren
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($claim['status'] === 'PAID'): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            Leistung wurde am <?= formatDateTime($claim['paid_at']) ?> in Höhe von
            <strong><?= formatMoney($claim['payout_amount']) ?></strong> ausgezahlt.
        </div>
        <?php endif; ?>

        <?php if ($claim['status'] === 'REJECTED'): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle me-2"></i>
            Dieser Leistungsantrag wurde abgelehnt.
            <?php if ($claim['reviewed_at']): ?>
            (<?= formatDateTime($claim['reviewed_at']) ?>)
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function updatePayout() {
    const covered    = parseFloat(document.getElementById('covered_amount').value) || 0;
    const deductible = parseFloat(document.getElementById('deductible_applied').value) || 0;
    const payout     = Math.max(0, covered - deductible);
    const fmt = v => new Intl.NumberFormat('de-DE', {style:'currency', currency:'USD', minimumFractionDigits:2}).format(v);
    document.getElementById('payout_display').value = fmt(payout);
}

document.addEventListener('DOMContentLoaded', updatePayout);
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
