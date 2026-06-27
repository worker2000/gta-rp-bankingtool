<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kredit bearbeiten
 */
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('loans', 'edit');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/loans/index.php');
    exit;
}

$loan = Database::fetchOne("SELECT * FROM loans WHERE id = ?", [$id]);
if (!$loan) {
    setFlash('error', 'Kredit nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/loans/index.php');
    exit;
}

$pageTitle = 'Bearbeiten: ' . $loan['file_number'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $oldData = $loan;

        $status = $_POST['status'] ?? $loan['status'];
        $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
        $paymentAccount = trim($_POST['payment_account'] ?? '');
        $vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
        $vehicleModel = trim($_POST['vehicle_model'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Status-Validierung
        $allowedStatuses = [
            'APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED', 'REJECTED',
            'CONTRACT_CREATED', 'ACTIVE', 'DUNNING_L1', 'DUNNING_L2',
            'TERMINATED', 'REPOSSESSION', 'CLOSED'
        ];
        if (!in_array($status, $allowedStatuses)) {
            $errors[] = 'Ungültiger Status.';
        }

        // Finanzdaten (nur Director)
        $rebuildSchedule = false;
        if (Auth::hasRole('director') && isset($_POST['loan_amount'])) {
            $loanAmount   = floatval($_POST['loan_amount']);
            $totalAmount  = floatval($_POST['total_amount']);
            $weeklyRate   = floatval($_POST['weekly_rate']);
            $termWeeks    = intval($_POST['term_weeks']);
            $interestRate = floatval($_POST['interest_rate']) / 100;
            $startDate    = trim($_POST['start_date'] ?? $loan['start_date']);

            if ($loanAmount <= 0 || $totalAmount <= 0 || $weeklyRate <= 0 || $termWeeks <= 0) {
                $errors[] = 'Kreditdaten: Alle Beträge müssen größer als 0 sein.';
            }
            if ($weeklyRate * ($termWeeks - 1) > $totalAmount) {
                $errors[] = 'Wochenrate × (Laufzeit−1) übersteigt den Gesamtbetrag.';
            }
            $rebuildSchedule = isset($_POST['rebuild_schedule']);
        }

        if (empty($errors)) {
            $updateData = [
                'status'          => $status,
                'assigned_to'     => $assignedTo,
                'payment_account' => $paymentAccount ?: null,
                'vehicle_plate'   => $vehiclePlate ?: null,
                'vehicle_model'   => $vehicleModel ?: null,
                'notes'           => $notes ?: null,
            ];

            if (Auth::hasRole('director') && isset($_POST['loan_amount'])) {
                // Bezahlte Summe aus bank_transactions ermitteln
                $paidSum = floatval(Database::fetchOne(
                    "SELECT COALESCE(SUM(amount),0) as s FROM bank_transactions WHERE matched_loan_id=? AND direction='eingehend' AND match_status='MATCHED'",
                    [$id]
                )['s'] ?? 0);

                $updateData['loan_amount']    = $loanAmount;
                $updateData['total_amount']   = $totalAmount;
                $updateData['weekly_rate']    = $weeklyRate;
                $updateData['term_weeks']     = $termWeeks;
                $updateData['interest_rate']  = $interestRate;
                $updateData['start_date']     = $startDate;
                $updateData['outstanding_balance'] = max(0, $totalAmount - $paidSum);

                if ($rebuildSchedule) {
                    // Unbezahlte Raten löschen
                    Database::query("DELETE FROM loan_schedule_items WHERE loan_id=? AND status != 'PAID'", [$id]);

                    // Wieviele Raten bereits bezahlt?
                    $paidCount = (int)(Database::fetchOne(
                        "SELECT COUNT(*) as c FROM loan_schedule_items WHERE loan_id=? AND status='PAID'", [$id]
                    )['c'] ?? 0);

                    // Neue Raten ab (paidCount+1) anlegen
                    $lastRate = $totalAmount - $weeklyRate * ($termWeeks - 1);
                    for ($n = $paidCount + 1; $n <= $termWeeks; $n++) {
                        $dueDate = date('Y-m-d', strtotime($startDate . ' +' . (($n - 1) * 7) . ' days'));
                        $amount  = ($n === $termWeeks) ? round($lastRate, 2) : $weeklyRate;
                        $isPast  = $dueDate < date('Y-m-d');
                        Database::insert('loan_schedule_items', [
                            'loan_id'            => $id,
                            'installment_number' => $n,
                            'due_date'           => $dueDate,
                            'amount_due'         => $amount,
                            'amount_outstanding' => $amount,
                            'status'             => $isPast ? 'OVERDUE' : 'PENDING',
                            'days_overdue'       => $isPast ? max(0, (int)((strtotime('now') - strtotime($dueDate)) / 86400)) : 0,
                        ]);
                    }
                }
            }

            Database::update('loans', $updateData, 'id = ?', [$id]);
            AuditLog::log('UPDATE', 'loan', $id, $oldData, $updateData);

            setFlash('success', 'Kredit aktualisiert.' . ($rebuildSchedule ? ' Ratenplan neu berechnet.' : ''));
            header('Location: ' . APP_URL . '/pages/loans/view.php?id=' . $id);
            exit;
        }
    }
}

// Benutzer für Zuweisung
$users = Database::fetchAll("
    SELECT u.id, u.full_name, GROUP_CONCAT(r.name) as roles
    FROM users u
    JOIN user_roles ur ON u.id = ur.user_id
    JOIN roles r ON ur.role_id = r.id
    WHERE u.is_active = 1
    GROUP BY u.id
    ORDER BY u.full_name
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $id ?>" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-pencil me-2"></i>Kredit bearbeiten
        </h4>
        <small class="text-muted"><?= e($loan['file_number']) ?></small>
    </div>
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
            <div class="card-body">
                <form method="POST" action="" id="editForm">
                    <?= csrfField() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="APPLICATION_RECEIVED" <?= $loan['status'] === 'APPLICATION_RECEIVED' ? 'selected' : '' ?>>Antrag eingegangen</option>
                                <option value="IN_REVIEW" <?= $loan['status'] === 'IN_REVIEW' ? 'selected' : '' ?>>In Prüfung</option>
                                <option value="APPROVED" <?= $loan['status'] === 'APPROVED' ? 'selected' : '' ?>>Genehmigt</option>
                                <option value="REJECTED" <?= $loan['status'] === 'REJECTED' ? 'selected' : '' ?>>Abgelehnt</option>
                                <option value="CONTRACT_CREATED" <?= $loan['status'] === 'CONTRACT_CREATED' ? 'selected' : '' ?>>Vertrag erstellt</option>
                                <option value="ACTIVE" <?= $loan['status'] === 'ACTIVE' ? 'selected' : '' ?>>Aktiv</option>
                                <option value="DUNNING_L1" <?= $loan['status'] === 'DUNNING_L1' ? 'selected' : '' ?>>Mahnung Stufe 1</option>
                                <option value="DUNNING_L2" <?= $loan['status'] === 'DUNNING_L2' ? 'selected' : '' ?>>Mahnung Stufe 2</option>
                                <option value="TERMINATED" <?= $loan['status'] === 'TERMINATED' ? 'selected' : '' ?>>Gekündigt</option>
                                <option value="REPOSSESSION" <?= $loan['status'] === 'REPOSSESSION' ? 'selected' : '' ?>>Sicherstellung</option>
                                <option value="CLOSED" <?= $loan['status'] === 'CLOSED' ? 'selected' : '' ?>>Abgeschlossen</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="assigned_to" class="form-label">Zugewiesen an</label>
                            <select class="form-select" id="assigned_to" name="assigned_to">
                                <option value="">-- Nicht zugewiesen --</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $loan['assigned_to'] == $u['id'] ? 'selected' : '' ?>>
                                    <?= e($u['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_account" class="form-label">PSB Zahlungskonto (IBAN)</label>
                            <input type="text" class="form-control" id="payment_account" name="payment_account"
                                   value="<?= e($loan['payment_account']) ?>">
                        </div>

                        <?php if ($loan['product_type'] === 'AUTO'): ?>
                        <div class="col-12">
                            <div class="border rounded p-3" style="border-color:#fd7e14!important;background:rgba(253,126,20,.05)">
                                <div class="fw-semibold mb-2" style="color:#fd7e14">
                                    <i class="bi bi-car-front me-2"></i>Fahrzeugdaten
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Fahrzeugmodell</label>
                                        <input type="text" class="form-control" name="vehicle_model"
                                               value="<?= e($loan['vehicle_model']) ?>" placeholder="z.B. Bravado Buffalo">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Nummernschild
                                            <?php if (!$loan['vehicle_plate']): ?>
                                            <span class="badge bg-warning text-dark ms-1">Fehlt</span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="text" class="form-control <?= !$loan['vehicle_plate'] ? 'border-warning' : '' ?>"
                                               name="vehicle_plate"
                                               value="<?= e($loan['vehicle_plate']) ?>"
                                               placeholder="z.B. 12AB34">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label for="notes" class="form-label">Notizen</label>
                            <textarea class="form-control" id="notes" name="notes" rows="4"><?= e($loan['notes']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>Speichern
                            </button>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                                Abbrechen
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <?php if (Auth::hasRole('director')): ?>
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-cash-coin me-2"></i>Kreditkonditionen
                <small class="d-block fw-normal mt-1">Nur für Bankdirektoren</small>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Startdatum</label>
                    <input type="date" class="form-control" name="start_date"
                           value="<?= e($loan['start_date']) ?>" form="editForm">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kreditsumme ($)</label>
                    <input type="number" class="form-control" name="loan_amount" step="0.01" min="1"
                           value="<?= $loan['loan_amount'] ?>" form="editForm" id="inp_loan_amount">
                </div>
                <div class="mb-3">
                    <label class="form-label">Gesamtbetrag ($) <small class="text-muted">(inkl. Zinsen)</small></label>
                    <input type="number" class="form-control" name="total_amount" step="0.01" min="1"
                           value="<?= $loan['total_amount'] ?>" form="editForm" id="inp_total_amount">
                </div>
                <div class="mb-3">
                    <label class="form-label">Wochenrate ($)</label>
                    <input type="number" class="form-control" name="weekly_rate" step="0.01" min="1"
                           value="<?= $loan['weekly_rate'] ?>" form="editForm" id="inp_weekly_rate"
                           oninput="calcLastRate()">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label">Laufzeit (Wochen)</label>
                        <input type="number" class="form-control" name="term_weeks" step="1" min="1"
                               value="<?= $loan['term_weeks'] ?>" form="editForm" id="inp_term_weeks"
                               oninput="calcLastRate()">
                    </div>
                    <div class="col-5">
                        <label class="form-label">Zinssatz (%/W)</label>
                        <input type="number" class="form-control" name="interest_rate" step="0.1" min="0"
                               value="<?= number_format($loan['interest_rate'] * 100, 2, '.', '') ?>"
                               form="editForm">
                    </div>
                </div>
                <div class="alert alert-secondary small p-2 mb-3" id="lastRateInfo">
                    Letzte Rate: <strong id="lastRateVal"><?= formatMoney($loan['total_amount'] - $loan['weekly_rate'] * ($loan['term_weeks'] - 1)) ?></strong>
                </div>
                <div class="form-check border rounded p-3 bg-dark">
                    <input class="form-check-input" type="checkbox" name="rebuild_schedule" id="rebuild_schedule" form="editForm">
                    <label class="form-check-label" for="rebuild_schedule">
                        <strong>Ratenplan neu berechnen</strong>
                        <div class="text-muted small">Offene Raten werden gelöscht und neu angelegt. Bezahlte Raten bleiben erhalten.</div>
                    </label>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header">Kreditdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Kreditsumme</td><td><?= formatMoney($loan['loan_amount']) ?></td></tr>
                    <tr><td class="text-muted">Gesamtbetrag</td><td><?= formatMoney($loan['total_amount']) ?></td></tr>
                    <tr><td class="text-muted">Zinssatz</td><td><?= number_format($loan['interest_rate'] * 100, 2) ?>% p.W.</td></tr>
                    <tr><td class="text-muted">Laufzeit</td><td><?= $loan['term_weeks'] ?> Wochen</td></tr>
                    <tr><td class="text-muted">Wochenrate</td><td><?= formatMoney($loan['weekly_rate']) ?></td></tr>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::hasRole('director')): ?>
<script>
function calcLastRate() {
    const total  = parseFloat(document.getElementById('inp_total_amount').value) || 0;
    const weekly = parseFloat(document.getElementById('inp_weekly_rate').value)  || 0;
    const weeks  = parseInt(document.getElementById('inp_term_weeks').value)     || 0;
    if (total > 0 && weekly > 0 && weeks > 1) {
        const last = total - weekly * (weeks - 1);
        document.getElementById('lastRateVal').textContent =
            last > 0 ? '$' + last.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) : '⚠ Ungültig';
    }
}
document.getElementById('inp_total_amount').addEventListener('input', calcLastRate);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
