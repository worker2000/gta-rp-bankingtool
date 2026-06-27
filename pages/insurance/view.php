<?php
ob_start();
/**
 * Fortis Finance – Krankenversicherung: Vertragsdetails
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
    SELECT ic.*, ip.name as product_name, ip.type as product_type,
           ip.monthly_base_premium, ip.waiting_period_days, ip.max_insured_sum, ip.deductible,
           b.first_name as borrower_first, b.last_name as borrower_last, b.customer_number,
           u1.full_name as created_by_name,
           u2.full_name as approved_by_name
    FROM insurance_contracts ic
    JOIN insurance_products ip ON ic.product_id = ip.id
    LEFT JOIN borrowers b ON ic.borrower_id = b.id
    LEFT JOIN users u1 ON ic.created_by = u1.id
    LEFT JOIN users u2 ON ic.approved_by = u2.id
    WHERE ic.id = ? AND ic.bank_id = 2
", [$id]);

if (!$contract) {
    setFlash('error', 'Vertrag nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/index.php');
    exit;
}

$pageTitle = $contract['contract_number'];

// Status-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $oldStatus = $contract['status'];

    switch ($action) {
        case 'activate':
            if ($contract['status'] === 'APPLIED') {
                Database::update('insurance_contracts', [
                    'status'      => 'ACTIVE',
                    'approved_by' => Auth::userId(),
                    'approved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_contract', $id,
                    ['status' => $oldStatus], ['status' => 'ACTIVE']);
                setFlash('success', 'Vertrag aktiviert.');
            }
            break;

        case 'suspend':
            if ($contract['status'] === 'ACTIVE') {
                Database::update('insurance_contracts', ['status' => 'SUSPENDED'], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_contract', $id,
                    ['status' => $oldStatus], ['status' => 'SUSPENDED']);
                setFlash('success', 'Vertrag ruhend gestellt.');
            }
            break;

        case 'reactivate':
            if ($contract['status'] === 'SUSPENDED') {
                Database::update('insurance_contracts', ['status' => 'ACTIVE'], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_contract', $id,
                    ['status' => $oldStatus], ['status' => 'ACTIVE']);
                setFlash('success', 'Vertrag reaktiviert.');
            }
            break;

        case 'dunning_hold':
            if ($contract['dunning_level'] > 0 || in_array($contract['status'], ['ACTIVE', 'SUSPENDED'])) {
                $reason = trim($_POST['dunning_hold_reason'] ?? '');
                Database::update('insurance_contracts', [
                    'dunning_hold'        => 1,
                    'dunning_hold_reason' => $reason ?: null,
                ], 'id = ?', [$id]);
                AuditLog::log('DUNNING_HOLD', 'insurance_contract', $id, null, ['reason' => $reason]);
                setFlash('success', 'Mahnung ausgesetzt – Klärung mit Support läuft.');
            }
            break;

        case 'dunning_resume':
            Database::update('insurance_contracts', [
                'dunning_hold'        => 0,
                'dunning_hold_reason' => null,
            ], 'id = ?', [$id]);
            AuditLog::log('DUNNING_RESUME', 'insurance_contract', $id);
            setFlash('success', 'Mahnverfahren wieder aktiv.');
            break;

        case 'cancel':
            $reason = trim($_POST['cancellation_reason'] ?? '');
            if (in_array($contract['status'], ['APPLIED', 'ACTIVE', 'SUSPENDED'])) {
                Database::update('insurance_contracts', [
                    'status'               => 'CANCELLED',
                    'cancellation_reason'  => $reason ?: null,
                    'cancelled_at'         => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_contract', $id,
                    ['status' => $oldStatus], ['status' => 'CANCELLED', 'reason' => $reason]);
                setFlash('success', 'Vertrag gekündigt.');
            }
            break;

        case 'pay_premium':
            $premiumId = intval($_POST['premium_id'] ?? 0);
            if ($premiumId) {
                $premium = Database::fetchOne(
                    "SELECT * FROM insurance_premium_schedule WHERE id = ? AND contract_id = ?",
                    [$premiumId, $id]
                );
                if ($premium && $premium['status'] !== 'PAID') {
                    Database::update('insurance_premium_schedule', [
                        'status'      => 'PAID',
                        'amount_paid' => $premium['amount_due'],
                        'paid_at'     => date('Y-m-d H:i:s'),
                        'payment_ref' => trim($_POST['payment_ref'] ?? '') ?: null,
                    ], 'id = ?', [$premiumId]);
                    AuditLog::log('PAYMENT', 'insurance_premium', $premiumId, null,
                        ['amount' => $premium['amount_due'], 'period' => $premium['period_label']]);
                    setFlash('success', 'Beitrag als bezahlt markiert.');
                }
            }
            break;
    }

    header('Location: ' . APP_URL . '/pages/insurance/view.php?id=' . $id);
    exit;
}

// Beitragszahlungsplan
$premiums = Database::fetchAll(
    "SELECT * FROM insurance_premium_schedule WHERE contract_id = ? ORDER BY due_date",
    [$id]
);

// Leistungsanträge
$claims = Database::fetchAll("
    SELECT cl.*, u.full_name as reviewer_name
    FROM insurance_claims cl
    LEFT JOIN users u ON cl.reviewed_by = u.id
    WHERE cl.contract_id = ?
    ORDER BY cl.created_at DESC
", [$id]);

// KPIs für diesen Vertrag
$premiumStats = Database::fetchOne("
    SELECT
        SUM(amount_due) as total_due,
        SUM(CASE WHEN status = 'PAID' THEN amount_paid ELSE 0 END) as total_paid,
        COUNT(CASE WHEN status = 'OVERDUE' THEN 1 END) as overdue_count
    FROM insurance_premium_schedule WHERE contract_id = ?
", [$id]);

$claimStats = Database::fetchOne("
    SELECT
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'PAID' THEN payout_amount ELSE 0 END), 0) as total_paid_out
    FROM insurance_claims WHERE contract_id = ?
", [$id]);

$activeTab = $_GET['tab'] ?? 'premiums';

function translateContractStatus(string $s): string {
    return match($s) {
        'APPLIED'   => 'Antrag',
        'ACTIVE'    => 'Aktiv',
        'SUSPENDED' => 'Ruhend',
        'CANCELLED' => 'Gekündigt',
        'EXPIRED'   => 'Abgelaufen',
        default     => $s
    };
}
function contractStatusBadge(string $s): string {
    return match($s) {
        'APPLIED'   => 'bg-info',
        'ACTIVE'    => 'bg-success',
        'SUSPENDED' => 'bg-warning',
        'CANCELLED' => 'bg-danger',
        'EXPIRED'   => 'bg-secondary',
        default     => 'bg-secondary'
    };
}
function translatePremiumStatus(string $s): string {
    return match($s) {
        'PENDING' => 'Ausstehend',
        'PAID'    => 'Bezahlt',
        'PARTIAL' => 'Teilzahlung',
        'OVERDUE' => 'Überfällig',
        'WAIVED'  => 'Erlassen',
        default   => $s,
    };
}
function premiumStatusBadge(string $s): string {
    return match($s) {
        'PENDING' => 'bg-secondary',
        'PAID'    => 'bg-success',
        'PARTIAL' => 'bg-warning',
        'OVERDUE' => 'bg-danger',
        'WAIVED'  => 'bg-info',
        default   => 'bg-secondary',
    };
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
        'VISION'     => 'Optiker',
        'MEDICATION' => 'Medikamente',
        'THERAPY'    => 'Therapie',
        'OTHER'      => 'Sonstiges',
        default      => $t,
    };
}
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item active"><?= e($contract['contract_number']) ?></li>
    </ol>
</nav>

<!-- Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-heart-pulse me-2"></i><?= e($contract['contract_number']) ?>
            <span class="badge <?= contractStatusBadge($contract['status']) ?> ms-2">
                <?= translateContractStatus($contract['status']) ?>
            </span>
        </h4>
        <div class="text-muted">
            <?= e($contract['insured_last_name'] . ', ' . $contract['insured_first_name']) ?> &middot;
            <?= e($contract['product_name']) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/pages/insurance/edit.php?id=<?= $id ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil me-1"></i>Bearbeiten
        </a>
        <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?contract_id=<?= $id ?>"
           class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-medical me-1"></i>Leistungsantrag
        </a>
        <?php if (!$contract['dunning_hold'] && ($contract['dunning_level'] > 0 || in_array($contract['status'], ['ACTIVE', 'SUSPENDED']))): ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#dunningHoldModal">
            <i class="bi bi-pause-circle me-1"></i>Klärung mit Support
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($contract['dunning_hold']): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3" role="alert">
    <i class="bi bi-pause-circle-fill fs-4"></i>
    <div class="flex-grow-1">
        <strong>Mahnung ausgesetzt – Klärung mit Support</strong>
        <?php if ($contract['dunning_hold_reason']): ?>
        <br><span class="small"><?= e($contract['dunning_hold_reason']) ?></span>
        <?php endif; ?>
    </div>
    <form method="POST" class="d-inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="dunning_resume">
        <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-play-fill me-1"></i>Mahnung fortsetzen
        </button>
    </form>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($contract['premium_amount']) ?></div>
                <div class="kpi-label">Monatsbeitrag</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card <?= ($premiumStats['overdue_count'] ?? 0) > 0 ? 'danger' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= ($premiumStats['overdue_count'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                    <?= formatMoney($premiumStats['total_paid'] ?? 0) ?>
                </div>
                <div class="kpi-label">
                    Eingezahlte Beiträge
                    <?php if (($premiumStats['overdue_count'] ?? 0) > 0): ?>
                    <span class="badge bg-danger"><?= $premiumStats['overdue_count'] ?> überfällig</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $claimStats['total'] ?></div>
                <div class="kpi-label">Leistungsanträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($claimStats['total_paid_out'] ?? 0) ?></div>
                <div class="kpi-label">Ausgezahlte Leistungen</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Linke Spalte: Stammdaten -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">Versicherter</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Name</td><td><strong><?= e($contract['insured_last_name'] . ', ' . $contract['insured_first_name']) ?></strong></td></tr>
                    <?php if ($contract['insured_dob']): ?>
                    <tr><td class="text-muted">Geburtsdatum</td><td><?= formatDate($contract['insured_dob']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['insured_gender']): ?>
                    <tr><td class="text-muted">Geschlecht</td><td><?= match($contract['insured_gender']) { 'M' => 'Männlich', 'F' => 'Weiblich', 'D' => 'Divers', default => '–' } ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['insured_phone']): ?>
                    <tr><td class="text-muted">Telefon</td><td><?= e($contract['insured_phone']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['insured_email']): ?>
                    <tr><td class="text-muted">E-Mail</td><td><?= e($contract['insured_email']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['insured_address']): ?>
                    <tr><td class="text-muted">Adresse</td><td><?= e($contract['insured_address']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['insured_iban']): ?>
                    <tr><td class="text-muted">IBAN</td><td><code><?= e($contract['insured_iban']) ?></code></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['borrower_id']): ?>
                    <tr>
                        <td class="text-muted">Kreditnehmer</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $contract['borrower_id'] ?>">
                                <?= e($contract['borrower_last'] . ' ' . $contract['borrower_first']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($contract['customer_number']) ?></small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Vertragsdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Vertragsnr.</td><td><strong><?= e($contract['contract_number']) ?></strong></td></tr>
                    <tr><td class="text-muted">Tarif</td><td><?= e($contract['product_name']) ?></td></tr>
                    <tr><td class="text-muted">Beginn</td><td><?= formatDate($contract['start_date']) ?></td></tr>
                    <tr>
                        <td class="text-muted">Ende</td>
                        <td><?= $contract['end_date'] ? formatDate($contract['end_date']) : '<span class="text-muted">unbefristet</span>' ?></td>
                    </tr>
                    <tr><td class="text-muted">Zahlungsinterv.</td><td>
                        <?= match($contract['payment_interval']) {
                            'MONTHLY' => 'Monatlich', 'QUARTERLY' => 'Vierteljährlich', 'ANNUALLY' => 'Jährlich', default => $contract['payment_interval']
                        } ?>
                    </td></tr>
                    <tr><td class="text-muted">Monatsbeitrag</td><td><strong><?= formatMoney($contract['premium_amount']) ?></strong></td></tr>
                    <?php if ($contract['risk_surcharge_pct'] > 0): ?>
                    <tr><td class="text-muted">Risikozuschlag</td><td><?= $contract['risk_surcharge_pct'] ?>%</td></tr>
                    <?php endif; ?>
                    <?php if ($contract['deductible'] > 0): ?>
                    <tr><td class="text-muted">Selbstbeteiligung</td><td><?= formatMoney($contract['deductible']) ?>/Jahr</td></tr>
                    <?php endif; ?>
                    <?php if ($contract['max_insured_sum']): ?>
                    <tr><td class="text-muted">Max. VS-Summe</td><td><?= formatMoney($contract['max_insured_sum']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['waiting_period_days']): ?>
                    <tr><td class="text-muted">Wartezeit</td><td><?= $contract['waiting_period_days'] ?> Tage</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($contract['pre_existing_conds']): ?>
        <div class="card mb-3">
            <div class="card-header">Vorerkrankungen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($contract['pre_existing_conds'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($contract['notes']): ?>
        <div class="card mb-3">
            <div class="card-header">Notizen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($contract['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status-Aktionen -->
        <div class="card">
            <div class="card-header">Aktionen</div>
            <div class="card-body d-grid gap-2">
                <?php if ($contract['status'] === 'APPLIED'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="activate">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check-circle me-2"></i>Vertrag aktivieren
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($contract['status'] === 'ACTIVE'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="suspend">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-pause-circle me-2"></i>Vertrag ruhend stellen
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($contract['status'] === 'SUSPENDED'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reactivate">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-play-circle me-2"></i>Vertrag reaktivieren
                    </button>
                </form>
                <?php endif; ?>

                <?php if (in_array($contract['status'], ['APPLIED', 'ACTIVE', 'SUSPENDED'])): ?>
                <button type="button" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#cancelModal">
                    <i class="bi bi-x-circle me-2"></i>Vertrag kündigen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte: Tabs -->
    <div class="col-md-8">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'premiums' ? 'active' : '' ?>"
                   href="?id=<?= $id ?>&tab=premiums">
                    <i class="bi bi-calendar-check me-1"></i>Beitragszahlungen
                    <?php if (($premiumStats['overdue_count'] ?? 0) > 0): ?>
                    <span class="badge bg-danger"><?= $premiumStats['overdue_count'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'claims' ? 'active' : '' ?>"
                   href="?id=<?= $id ?>&tab=claims">
                    <i class="bi bi-file-medical me-1"></i>Leistungsanträge
                    <span class="badge bg-secondary"><?= count($claims) ?></span>
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'premiums'): ?>
        <!-- Beitragszahlungsplan -->
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($premiums)): ?>
                <div class="p-4 text-center text-muted">Kein Beitragszahlungsplan vorhanden.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Zeitraum</th>
                                <th>Fälligkeit</th>
                                <th>Betrag</th>
                                <th>Bezahlt</th>
                                <th>Status</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($premiums as $pm): ?>
                            <tr class="<?= $pm['status'] === 'OVERDUE' ? 'table-danger' : '' ?>">
                                <td><strong><?= e($pm['period_label']) ?></strong></td>
                                <td><?= formatDate($pm['due_date']) ?></td>
                                <td><?= formatMoney($pm['amount_due']) ?></td>
                                <td>
                                    <?php if ($pm['amount_paid'] > 0): ?>
                                    <?= formatMoney($pm['amount_paid']) ?>
                                    <?php if ($pm['paid_at']): ?>
                                    <br><small class="text-muted"><?= formatDate($pm['paid_at']) ?></small>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= premiumStatusBadge($pm['status']) ?>">
                                        <?= translatePremiumStatus($pm['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if ($pm['status'] !== 'PAID' && $pm['status'] !== 'WAIVED'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success"
                                            onclick="markPaid(<?= $pm['id'] ?>, '<?= e($pm['period_label']) ?>', <?= $pm['amount_due'] ?>)"
                                            title="Als bezahlt markieren">
                                        <i class="bi bi-check"></i>
                                    </button>
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

        <?php elseif ($activeTab === 'claims'): ?>
        <!-- Leistungsanträge -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Leistungsanträge</h6>
            <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?contract_id=<?= $id ?>"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-plus me-1"></i>Neuer Antrag
            </a>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($claims)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-file-medical fs-2 d-block mb-2"></i>
                    Noch keine Leistungsanträge vorhanden.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Antragsnr.</th>
                                <th>Behandlung</th>
                                <th>Rechnungsbetrag</th>
                                <th>Auszahlung</th>
                                <th>Status</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($claims as $cl): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/claims/view.php?id=<?= $cl['id'] ?>">
                                        <strong><?= e($cl['claim_number']) ?></strong>
                                    </a>
                                    <br><small class="text-muted"><?= formatDate($cl['treatment_date']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= translateTreatmentType($cl['treatment_type']) ?></span>
                                    <br><small><?= e($cl['provider_name']) ?></small>
                                </td>
                                <td><?= formatMoney($cl['billed_amount']) ?></td>
                                <td>
                                    <?php if ($cl['payout_amount'] !== null): ?>
                                    <strong><?= formatMoney($cl['payout_amount']) ?></strong>
                                    <?php else: ?>
                                    <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= claimStatusBadge($cl['status']) ?>">
                                        <?= translateClaimStatus($cl['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/claims/view.php?id=<?= $cl['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Beitrag-Zahlung Modal -->
<div class="modal fade" id="payPremiumModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="payPremiumForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="pay_premium">
                <input type="hidden" name="premium_id" id="pay_premium_id">
                <div class="modal-header">
                    <h5 class="modal-title">Beitrag buchen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Zeitraum: <strong id="pay_period_label"></strong></p>
                    <p>Betrag: <strong id="pay_amount_display"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Zahlungsreferenz (optional)</label>
                        <input type="text" class="form-control" name="payment_ref" placeholder="z.B. SEPA-Referenz">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Als bezahlt markieren</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Kündigung Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Vertrag kündigen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Möchten Sie den Vertrag <strong><?= e($contract['contract_number']) ?></strong> wirklich kündigen?</p>
                    <div class="mb-3">
                        <label class="form-label">Kündigungsgrund (optional)</label>
                        <textarea class="form-control" name="cancellation_reason" rows="3"
                                  placeholder="z.B. Antrag zurückgezogen, Versicherungsnehmer verzogen..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Vertrag kündigen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function markPaid(id, label, amount) {
    document.getElementById('pay_premium_id').value = id;
    document.getElementById('pay_period_label').textContent = label;
    document.getElementById('pay_amount_display').textContent =
        new Intl.NumberFormat('de-DE', {style:'currency', currency:'USD'}).format(amount);
    new bootstrap.Modal(document.getElementById('payPremiumModal')).show();
}
</script>

<!-- Klärung mit Support Modal -->
<div class="modal fade" id="dunningHoldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="dunning_hold">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pause-circle me-2"></i>Klärung mit Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Die Mahnung für Vertrag <strong><?= e($contract['contract_number']) ?></strong> wird ausgesetzt, bis die Klärung abgeschlossen ist.</p>
                    <div class="mb-3">
                        <label class="form-label">Grund / Notiz <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control" name="dunning_hold_reason"
                               placeholder="z.B. Zahlungsstreit, Datenabgleich, ..."
                               maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-pause-circle me-2"></i>Mahnung aussetzen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
