<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Mitglied-Detail
 */
require_once __DIR__ . '/../../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

$member = Database::fetchOne("
    SELECT im.*,
           igc.contract_number, igc.status as contract_status, igc.employer_id, igc.start_date as contract_start,
           ie.company_name,
           b.customer_number as borrower_number, b.first_name as borrower_first, b.last_name as borrower_last
    FROM insurance_members im
    LEFT JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
    LEFT JOIN insurance_employers ie ON igc.employer_id = ie.id
    LEFT JOIN borrowers b ON im.borrower_id = b.id
    WHERE im.id = ? AND im.bank_id = 2
", [$id]);

if (!$member) {
    setFlash('error', 'Mitglied nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

$isFree = empty($member['group_contract_id']);

$pageTitle = $member['last_name'] . ', ' . $member['first_name'];

// Versicherungsklassen
const INSURANCE_CLASSES = [
    1 => ['label' => 'Klasse 1', 'monthly' => 150.00],
    2 => ['label' => 'Klasse 2', 'monthly' => 250.00],
    3 => ['label' => 'Klasse 3', 'monthly' => 400.00],
    4 => ['label' => 'Klasse 4', 'monthly' => 500.00],
];

$errors = [];

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'change_class':
            $newClass = intval($_POST['insurance_class'] ?? 0);
            if (!isset(INSURANCE_CLASSES[$newClass])) {
                $errors[] = 'Ungültige Versicherungsklasse.';
                break;
            }
            $oldClass  = $member['insurance_class'];
            $newWeekly = INSURANCE_CLASSES[$newClass]['monthly'];
            Database::update('insurance_members', [
                'insurance_class' => $newClass,
                'premium_monthly'  => $newWeekly,
            ], 'id = ?', [$id]);
            AuditLog::log('UPDATE', 'insurance_member', $id,
                ['insurance_class' => $oldClass, 'premium_monthly' => $member['premium_monthly']],
                ['insurance_class' => $newClass, 'premium_monthly' => $newWeekly]
            );
            setFlash('success', "Versicherungsklasse geändert: Klasse {$newClass} (\${$newWeekly}/Monat).");
            break;

        case 'change_status':
            $newStatus = $_POST['member_status'] ?? '';
            if (!in_array($newStatus, ['ACTIVE', 'INACTIVE', 'SUSPENDED'])) {
                $errors[] = 'Ungültiger Status.';
                break;
            }
            $oldStatus = $member['status'];
            Database::update('insurance_members', ['status' => $newStatus], 'id = ?', [$id]);
            AuditLog::log('STATUS_CHANGE', 'insurance_member', $id,
                ['status' => $oldStatus], ['status' => $newStatus]);
            setFlash('success', 'Status geändert.');
            break;

        case 'remove_member':
            if (!in_array($member['status'], ['ACTIVE', 'SUSPENDED'])) {
                $errors[] = 'Mitglied ist bereits ausgetreten.';
                break;
            }
            $exitDate   = trim($_POST['exit_date'] ?? '') ?: date('Y-m-d');
            $exitReason = trim($_POST['exit_reason'] ?? '');
            $newNotes   = $member['notes']
                ? $member['notes'] . "\n\nAustritt: " . $exitReason
                : ($exitReason ? "Austritt: " . $exitReason : null);
            Database::update('insurance_members', [
                'status'   => 'INACTIVE',
                'end_date' => $exitDate,
                'notes'    => $newNotes,
            ], 'id = ?', [$id]);
            AuditLog::log('STATUS_CHANGE', 'insurance_member', $id,
                ['status' => $member['status']],
                ['status' => 'INACTIVE', 'end_date' => $exitDate, 'reason' => $exitReason]);
            setFlash('success', $member['first_name'] . ' ' . $member['last_name'] . ' wurde zum ' . formatDate($exitDate) . ' ausgetragen.');
            break;

        case 'assign_contract':
            if (!$isFree) { $errors[] = 'Mitglied ist bereits einem Gruppenvertrag zugewiesen.'; break; }
            $contractId = intval($_POST['group_contract_id'] ?? 0);
            if (!$contractId) { $errors[] = 'Bitte einen Gruppenvertrag wählen.'; break; }
            $contract = Database::fetchOne(
                "SELECT igc.id, igc.contract_number, ie.company_name
                 FROM insurance_group_contracts igc
                 JOIN insurance_employers ie ON igc.employer_id = ie.id
                 WHERE igc.id = ? AND igc.bank_id = 2",
                [$contractId]
            );
            if (!$contract) { $errors[] = 'Gruppenvertrag nicht gefunden.'; break; }
            Database::update('insurance_members', ['group_contract_id' => $contractId], 'id = ?', [$id]);
            AuditLog::log('UPDATE', 'insurance_member', $id,
                ['group_contract_id' => null],
                ['group_contract_id' => $contractId, 'contract_number' => $contract['contract_number']]
            );
            setFlash('success', 'Mitglied dem Vertrag ' . $contract['contract_number'] . ' (' . $contract['company_name'] . ') zugewiesen.');
            break;

        case 'edit':
            $phone   = trim($_POST['phone']   ?? '');
            $email   = trim($_POST['email']   ?? '');
            $address = trim($_POST['address'] ?? '');
            $region  = trim($_POST['region']  ?? '');
            $iban    = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
            $notes   = trim($_POST['notes']   ?? '');
            $dob     = trim($_POST['dob']     ?? '');
            $gender  = trim($_POST['gender']  ?? '');
            $endDate = trim($_POST['end_date'] ?? '');

            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Ungültige E-Mail-Adresse.';
                break;
            }

            Database::update('insurance_members', [
                'phone'    => $phone   ?: null,
                'email'    => $email   ?: null,
                'address'  => $address ?: null,
                'region'   => $region  ?: null,
                'iban'     => $iban    ?: null,
                'notes'    => $notes   ?: null,
                'dob'      => $dob     ?: null,
                'gender'   => $gender  ?: null,
                'end_date' => $endDate ?: null,
            ], 'id = ?', [$id]);
            AuditLog::log('UPDATE', 'insurance_member', $id, null, ['updated_by' => Auth::userId()]);
            setFlash('success', 'Mitglied aktualisiert.');
            break;
    }

    if (empty($errors)) {
        header('Location: ' . APP_URL . '/pages/insurance/group/members/view.php?id=' . $id);
        exit;
    }

    // Neu laden nach Fehlern
    $member = Database::fetchOne("
        SELECT im.*,
               igc.contract_number, igc.status as contract_status, igc.employer_id, igc.start_date as contract_start,
               ie.company_name,
               b.customer_number as borrower_number, b.first_name as borrower_first, b.last_name as borrower_last
        FROM insurance_members im
        LEFT JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
        LEFT JOIN insurance_employers ie ON igc.employer_id = ie.id
        LEFT JOIN borrowers b ON im.borrower_id = b.id
        WHERE im.id = ? AND im.bank_id = 2
    ", [$id]);
    $isFree = empty($member['group_contract_id']);
}

// Leistungsanträge dieses Mitglieds
$claims = Database::fetchAll("
    SELECT cl.* FROM insurance_claims cl
    WHERE cl.member_id = ?
    ORDER BY cl.created_at DESC
", [$id]);

// Für freie Mitglieder: verfügbare Gruppenverträge laden
$availableContracts = [];
if ($isFree) {
    $availableContracts = Database::fetchAll("
        SELECT igc.id, igc.contract_number, igc.status, ie.company_name, ie.id as employer_id
        FROM insurance_group_contracts igc
        JOIN insurance_employers ie ON igc.employer_id = ie.id
        WHERE igc.bank_id = 2 AND igc.status IN ('ACTIVE','APPLIED')
        ORDER BY ie.company_name, igc.contract_number
    ");
}

function memberStatusBadge(string $s): string {
    return match($s) {
        'ACTIVE'    => 'bg-success',
        'INACTIVE'  => 'bg-secondary',
        'SUSPENDED' => 'bg-warning',
        default     => 'bg-secondary'
    };
}
function memberStatusLabel(string $s): string {
    return match($s) {
        'ACTIVE'    => 'Aktiv',
        'INACTIVE'  => 'Inaktiv',
        'SUSPENDED' => 'Ruhend',
        default     => $s
    };
}
function claimStatusBadge(string $s): string {
    return match($s) {
        'SUBMITTED' => 'bg-info', 'IN_REVIEW' => 'bg-warning',
        'APPROVED'  => 'bg-success', 'PARTIAL' => 'bg-warning',
        'REJECTED'  => 'bg-danger', 'PAID' => 'bg-primary',
        default     => 'bg-secondary',
    };
}
function claimStatusLabel(string $s): string {
    return match($s) {
        'SUBMITTED' => 'Eingereicht', 'IN_REVIEW' => 'In Prüfung',
        'APPROVED'  => 'Genehmigt',  'PARTIAL'   => 'Teilgenehmigt',
        'REJECTED'  => 'Abgelehnt',  'PAID'      => 'Ausgezahlt',
        default     => $s,
    };
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <?php if ($isFree): ?>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/members/unassigned.php">Freie Mitglieder</a>
        </li>
        <?php else: ?>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $member['employer_id'] ?>">
                <?= e($member['company_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $member['group_contract_id'] ?>">
                <?= e($member['contract_number']) ?>
            </a>
        </li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= e($member['last_name'] . ', ' . $member['first_name']) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-person-badge me-2"></i>
            <?= e($member['last_name'] . ', ' . $member['first_name']) ?>
            <span class="badge <?= memberStatusBadge($member['status']) ?> ms-2">
                <?= memberStatusLabel($member['status']) ?>
            </span>
            <?php if ($isFree): ?>
            <span class="badge bg-warning text-dark ms-1">Kein Vertrag</span>
            <?php endif; ?>
        </h4>
        <div class="text-muted">
            <?php if ($isFree): ?>
            <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Freies Mitglied – noch kein Gruppenvertrag zugewiesen</span> &middot;
            <?php else: ?>
            <?= e($member['company_name']) ?> &middot;
            <?= e($member['contract_number']) ?> &middot;
            <?php endif; ?>
            <span class="badge bg-secondary">Klasse <?= $member['insurance_class'] ?></span>
            $<?= number_format($member['premium_monthly'], 2) ?>/Monat
        </div>
    </div>
    <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?member_id=<?= $id ?>"
       class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-medical me-1"></i>Leistungsantrag stellen
    </a>
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
    <!-- Linke Spalte -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">Stammdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Name</td><td><strong><?= e($member['last_name'] . ', ' . $member['first_name']) ?></strong></td></tr>
                    <?php if ($member['dob']): ?>
                    <tr><td class="text-muted">Geburtsdatum</td><td><?= formatDate($member['dob']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['gender']): ?>
                    <tr><td class="text-muted">Geschlecht</td><td><?= match($member['gender']) { 'M' => 'Männlich', 'F' => 'Weiblich', 'D' => 'Divers', default => '–' } ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['phone']): ?>
                    <tr><td class="text-muted">Telefon</td><td><?= e($member['phone']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['email']): ?>
                    <tr><td class="text-muted">E-Mail</td><td><?= e($member['email']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['address']): ?>
                    <tr><td class="text-muted">Adresse</td><td><?= e($member['address']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['region']): ?>
                    <tr><td class="text-muted">Region</td><td><?= e($member['region']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($member['iban']): ?>
                    <tr><td class="text-muted">IBAN</td><td><code><?= e($member['iban']) ?></code></td></tr>
                    <?php endif; ?>
                    <?php if ($member['borrower_id']): ?>
                    <tr>
                        <td class="text-muted">Kreditnehmer</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $member['borrower_id'] ?>">
                                <?= e($member['borrower_last'] . ' ' . $member['borrower_first']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($member['borrower_number']) ?></small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Versicherung</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Gruppenvertrag</td>
                        <td>
                            <?php if ($isFree): ?>
                            <span class="badge bg-warning text-dark">Nicht zugewiesen</span>
                            <?php else: ?>
                            <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $member['group_contract_id'] ?>">
                                <?= e($member['contract_number']) ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($member['member_ref']): ?>
                    <tr><td class="text-muted">Vertragsnummer</td><td><code><?= e($member['member_ref']) ?></code></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted">Klasse</td><td><span class="badge bg-secondary">Klasse <?= $member['insurance_class'] ?></span></td></tr>
                    <tr><td class="text-muted">Monatsbeitrag</td><td><strong>$<?= number_format($member['premium_monthly'], 2) ?></strong></td></tr>
                    <tr><td class="text-muted">Jahresbeitrag</td><td>$<?= number_format($member['premium_monthly'] * 12, 2) ?></td></tr>
                    <tr><td class="text-muted">Eintrittsdatum</td><td><?= formatDate($member['start_date']) ?></td></tr>
                    <?php if ($member['end_date']): ?>
                    <tr><td class="text-muted">Austrittsdatum</td><td><?= formatDate($member['end_date']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!$isFree): ?>
                    <tr><td class="text-muted">Arbeitgeber</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $member['employer_id'] ?>">
                                <?= e($member['company_name']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($member['notes']): ?>
        <div class="card mb-3">
            <div class="card-header">Notizen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($member['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Aktionen -->
        <div class="card">
            <div class="card-header">Aktionen</div>
            <div class="card-body d-grid gap-2">

                <!-- Klasse ändern -->
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_class">
                    <div class="mb-2">
                        <label class="form-label small">Versicherungsklasse ändern</label>
                        <select class="form-select form-select-sm" name="insurance_class">
                            <?php foreach (INSURANCE_CLASSES as $cls => $info): ?>
                            <option value="<?= $cls ?>" <?= $member['insurance_class'] == $cls ? 'selected' : '' ?>>
                                <?= $info['label'] ?> – $<?= number_format($info['monthly'], 0) ?>/Monat
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Klasse ändern
                    </button>
                </form>

                <hr class="my-2">

                <!-- Status ändern -->
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <div class="mb-2">
                        <label class="form-label small">Status ändern</label>
                        <select class="form-select form-select-sm" name="member_status">
                            <option value="ACTIVE"    <?= $member['status'] === 'ACTIVE'    ? 'selected' : '' ?>>Aktiv</option>
                            <option value="SUSPENDED" <?= $member['status'] === 'SUSPENDED' ? 'selected' : '' ?>>Ruhend</option>
                            <option value="INACTIVE"  <?= $member['status'] === 'INACTIVE'  ? 'selected' : '' ?>>Inaktiv</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-toggle-on me-1"></i>Status ändern
                    </button>
                </form>

                <hr class="my-2">

                <?php if ($isFree): ?>
                <button type="button" class="btn btn-warning btn-sm w-100"
                        data-bs-toggle="modal" data-bs-target="#assignContractModal">
                    <i class="bi bi-person-check me-1"></i>Gruppenvertrag zuweisen
                </button>
                <hr class="my-2">
                <?php endif; ?>

                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-toggle="modal" data-bs-target="#editModal">
                    <i class="bi bi-pencil me-1"></i>Kontaktdaten bearbeiten
                </button>

                <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?member_id=<?= $id ?>"
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-medical me-1"></i>Leistungsantrag stellen
                </a>

                <?php if (in_array($member['status'], ['ACTIVE', 'SUSPENDED'])): ?>
                <hr class="my-2">
                <button type="button" class="btn btn-danger btn-sm w-100"
                        data-bs-toggle="modal" data-bs-target="#removeModal">
                    <i class="bi bi-person-x me-1"></i>Mitglied austragen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte: Leistungsanträge -->
    <div class="col-md-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="bi bi-file-medical me-2"></i>Leistungsanträge
                <span class="badge bg-secondary"><?= count($claims) ?></span>
            </h6>
            <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?member_id=<?= $id ?>"
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
                                    <span class="badge bg-secondary small"><?= e($cl['treatment_type']) ?></span>
                                    <?php if ($cl['provider_name']): ?>
                                    <br><small><?= e($cl['provider_name']) ?></small>
                                    <?php endif; ?>
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
                                        <?= claimStatusLabel($cl['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/claims/view.php?id=<?= $cl['id'] ?>"
                                       class="btn btn-sm btn-outline-primary btn-action">
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
    </div>
</div>

<?php if ($isFree): ?>
<!-- Gruppenvertrag-Zuweisungs-Modal -->
<div class="modal fade" id="assignContractModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_contract">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>Gruppenvertrag zuweisen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($availableContracts)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Keine aktiven Gruppenverträge gefunden.
                        <a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber anlegen</a>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-3">
                        Dieses Mitglied ist noch keinem Gruppenvertrag zugewiesen.
                        Bitte wählen Sie den Vertrag, dem es angehören soll.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Gruppenvertrag *</label>
                        <select class="form-select" name="group_contract_id" required>
                            <option value="">– Vertrag wählen –</option>
                            <?php
                            $lastEmployer = null;
                            foreach ($availableContracts as $c):
                                if ($c['company_name'] !== $lastEmployer):
                                    if ($lastEmployer !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . e($c['company_name']) . '">';
                                    $lastEmployer = $c['company_name'];
                                endif;
                            ?>
                            <option value="<?= $c['id'] ?>">
                                <?= e($c['contract_number']) ?> (<?= e($c['status']) ?>)
                            </option>
                            <?php endforeach; ?>
                            <?php if ($lastEmployer !== null) echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <?php if (!empty($availableContracts)): ?>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check2 me-1"></i>Zuweisen
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bearbeiten-Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Kontaktdaten bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Geburtsdatum</label>
                            <input type="date" class="form-control" name="dob"
                                   value="<?= e($member['dob'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Geschlecht</label>
                            <select class="form-select" name="gender">
                                <option value="">–</option>
                                <option value="M" <?= ($member['gender'] ?? '') === 'M' ? 'selected' : '' ?>>Männlich</option>
                                <option value="F" <?= ($member['gender'] ?? '') === 'F' ? 'selected' : '' ?>>Weiblich</option>
                                <option value="D" <?= ($member['gender'] ?? '') === 'D' ? 'selected' : '' ?>>Divers</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?= e($member['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= e($member['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($member['iban'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address"
                                   value="<?= e($member['address'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Region (Info)</label>
                            <input type="text" class="form-control" name="region"
                                   value="<?= e($member['region'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Austrittsdatum</label>
                            <input type="date" class="form-control" name="end_date"
                                   value="<?= e($member['end_date'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($member['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mitglied austragen Modal -->
<?php if (in_array($member['status'], ['ACTIVE', 'SUSPENDED'])): ?>
<div class="modal fade" id="removeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="remove_member">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-x me-2"></i>Mitglied austragen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>
                        <strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                        wird aus dem Gruppenvertrag
                        <strong><?= e($member['contract_number'] ?? '–') ?></strong>
                        ausgetragen und erhält den Status <em>Inaktiv</em>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Austrittsdatum *</label>
                        <input type="date" class="form-control" name="exit_date"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Austrittsgrund <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" name="exit_reason" rows="2"
                                  placeholder="z.B. Kündigung des Arbeitsverhältnisses, Eigenantrag..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-person-x me-2"></i>Austragen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../../includes/footer.php'; ?>
