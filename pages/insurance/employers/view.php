<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Arbeitgeber-Detail
 */
require_once __DIR__ . '/../../../includes/header.php';
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

$employer = Database::fetchOne(
    "SELECT ie.*, u.full_name as created_by_name
     FROM insurance_employers ie
     LEFT JOIN users u ON ie.created_by = u.id
     WHERE ie.id = ? AND ie.bank_id = 2",
    [$id]
);

if (!$employer) {
    setFlash('error', 'Arbeitgeber nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

$pageTitle = $employer['company_name'];

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        $newState = $employer['is_active'] ? 0 : 1;
        Database::update('insurance_employers', ['is_active' => $newState], 'id = ?', [$id]);
        AuditLog::log('UPDATE', 'insurance_employer', $id,
            ['is_active' => $employer['is_active']], ['is_active' => $newState]);
        $label = $newState ? 'aktiviert' : 'deaktiviert';
        setFlash('success', "Arbeitgeber {$label}.");
    } elseif ($action === 'edit') {
        $companyName   = trim($_POST['company_name']   ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone         = trim($_POST['phone']          ?? '');
        $email         = trim($_POST['email']          ?? '');
        $address       = trim($_POST['address']        ?? '');
        $iban          = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $notes         = trim($_POST['notes']          ?? '');
        $editErrors    = [];

        if (!$companyName) $editErrors[] = 'Firmenname ist Pflichtfeld.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $editErrors[] = 'Ungültige E-Mail.';

        if (empty($editErrors)) {
            Database::update('insurance_employers', [
                'company_name'   => $companyName,
                'contact_person' => $contactPerson ?: null,
                'phone'          => $phone         ?: null,
                'email'          => $email         ?: null,
                'address'        => $address       ?: null,
                'iban'           => $iban          ?: null,
                'notes'          => $notes         ?: null,
            ], 'id = ?', [$id]);
            AuditLog::log('UPDATE', 'insurance_employer', $id, null, ['company_name' => $companyName]);
            setFlash('success', 'Arbeitgeber aktualisiert.');
        } else {
            setFlash('error', implode(' ', $editErrors));
        }
    }

    header('Location: ' . APP_URL . '/pages/insurance/employers/view.php?id=' . $id);
    exit;
}

// Gruppenverträge
$groupContracts = Database::fetchAll("
    SELECT igc.*,
           ip.name as product_name,
           u.full_name as approved_by_name,
           COUNT(im.id) as total_members,
           COUNT(CASE WHEN im.status = 'ACTIVE' THEN im.id END) as active_members,
           COALESCE(SUM(CASE WHEN im.status = 'ACTIVE' THEN im.premium_monthly END), 0) as monthly_premium
    FROM insurance_group_contracts igc
    JOIN insurance_products ip ON igc.product_id = ip.id
    LEFT JOIN users u ON igc.approved_by = u.id
    LEFT JOIN insurance_members im ON im.group_contract_id = igc.id
    WHERE igc.employer_id = ? AND igc.bank_id = 2
    GROUP BY igc.id
    ORDER BY igc.created_at DESC
", [$id]);

// Alle Mitglieder dieses Arbeitgebers
$members = Database::fetchAll("
    SELECT im.*,
           igc.contract_number,
           b.customer_number as borrower_number
    FROM insurance_members im
    JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
    LEFT JOIN borrowers b ON im.borrower_id = b.id
    WHERE igc.employer_id = ? AND im.bank_id = 2
    ORDER BY im.last_name, im.first_name
", [$id]);

// KPIs
$kpis = [
    'total_members'   => count($members),
    'active_members'  => count(array_filter($members, fn($m) => $m['status'] === 'ACTIVE')),
    'monthly_premium'  => array_sum(array_map(fn($m) => $m['status'] === 'ACTIVE' ? $m['premium_monthly'] : 0, $members)),
    'open_claims'     => Database::fetchOne("
        SELECT COUNT(*) as cnt FROM insurance_claims cl
        JOIN insurance_members im ON cl.member_id = im.id
        JOIN insurance_group_contracts igc ON im.group_contract_id = igc.id
        WHERE igc.employer_id = ? AND cl.status IN ('SUBMITTED','IN_REVIEW')
    ", [$id])['cnt'] ?? 0,
];

function gcStatusBadge(string $s): string {
    return match($s) {
        'APPLIED'   => 'bg-info',
        'ACTIVE'    => 'bg-success',
        'SUSPENDED' => 'bg-warning',
        'CANCELLED' => 'bg-danger',
        default     => 'bg-secondary'
    };
}
function gcStatusLabel(string $s): string {
    return match($s) {
        'APPLIED'   => 'Antrag',
        'ACTIVE'    => 'Aktiv',
        'SUSPENDED' => 'Ruhend',
        'CANCELLED' => 'Gekündigt',
        default     => $s
    };
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
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <li class="breadcrumb-item active"><?= e($employer['company_name']) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-building me-2"></i><?= e($employer['company_name']) ?>
            <?php if (!$employer['is_active']): ?>
            <span class="badge bg-secondary ms-2">Inaktiv</span>
            <?php endif; ?>
        </h4>
        <?php if ($employer['contact_person']): ?>
        <div class="text-muted">Ansprechpartner: <?= e($employer['contact_person']) ?></div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-toggle="modal" data-bs-target="#editModal">
            <i class="bi bi-pencil me-1"></i>Bearbeiten
        </button>
        <a href="<?= APP_URL ?>/pages/insurance/group/create.php?employer_id=<?= $id ?>"
           class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>Gruppenvertrag anlegen
        </a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $kpis['active_members'] ?></div>
                <div class="kpi-label">Aktive Mitglieder</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= count($groupContracts) ?></div>
                <div class="kpi-label">Gruppenverträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['monthly_premium']) ?></div>
                <div class="kpi-label">Monatsbeiträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card <?= $kpis['open_claims'] > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= $kpis['open_claims'] > 0 ? 'text-warning' : '' ?>"><?= $kpis['open_claims'] ?></div>
                <div class="kpi-label">Offene Leistungsanträge</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Linke Spalte: Stammdaten -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">Stammdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Firma</td><td><strong><?= e($employer['company_name']) ?></strong></td></tr>
                    <?php if ($employer['contact_person']): ?>
                    <tr><td class="text-muted">Ansprechpartner</td><td><?= e($employer['contact_person']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($employer['phone']): ?>
                    <tr><td class="text-muted">Telefon</td><td><?= e($employer['phone']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($employer['email']): ?>
                    <tr><td class="text-muted">E-Mail</td><td><?= e($employer['email']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($employer['address']): ?>
                    <tr><td class="text-muted">Adresse</td><td><?= e($employer['address']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($employer['iban']): ?>
                    <tr><td class="text-muted">IBAN</td><td><code><?= e($employer['iban']) ?></code></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted">Angelegt</td><td><?= formatDate($employer['created_at']) ?></td></tr>
                    <?php if ($employer['created_by_name']): ?>
                    <tr><td class="text-muted">durch</td><td><?= e($employer['created_by_name']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($employer['notes']): ?>
        <div class="card mb-3">
            <div class="card-header">Notizen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($employer['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Aktionen</div>
            <div class="card-body d-grid gap-2">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <button type="submit" class="btn w-100 <?= $employer['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                        <?php if ($employer['is_active']): ?>
                        <i class="bi bi-pause-circle me-2"></i>Arbeitgeber deaktivieren
                        <?php else: ?>
                        <i class="bi bi-play-circle me-2"></i>Arbeitgeber aktivieren
                        <?php endif; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte -->
    <div class="col-md-8">
        <!-- Gruppenverträge -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Gruppenverträge</h6>
            <a href="<?= APP_URL ?>/pages/insurance/group/create.php?employer_id=<?= $id ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus me-1"></i>Gruppenvertrag anlegen
            </a>
        </div>

        <?php if (empty($groupContracts)): ?>
        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-2"></i>
            Noch kein Gruppenvertrag angelegt.
            <a href="<?= APP_URL ?>/pages/insurance/group/create.php?employer_id=<?= $id ?>" class="alert-link">
                Jetzt anlegen →
            </a>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Vertragsnr.</th>
                                <th>Tarif</th>
                                <th>Laufzeit</th>
                                <th>Mitglieder</th>
                                <th>Monatsbeitrag</th>
                                <th>Status</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupContracts as $gc): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $gc['id'] ?>">
                                        <strong><?= e($gc['contract_number']) ?></strong>
                                    </a>
                                </td>
                                <td><?= e($gc['product_name']) ?></td>
                                <td>
                                    <small><?= formatDate($gc['start_date']) ?></small>
                                    <?php if ($gc['end_date']): ?>
                                    <br><small class="text-muted">bis <?= formatDate($gc['end_date']) ?></small>
                                    <?php else: ?>
                                    <br><small class="text-muted">unbefristet</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold"><?= $gc['active_members'] ?></span>
                                    <?php if ($gc['total_members'] > $gc['active_members']): ?>
                                    <span class="text-muted small"> / <?= $gc['total_members'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatMoney($gc['monthly_premium']) ?></td>
                                <td>
                                    <span class="badge <?= gcStatusBadge($gc['status']) ?>">
                                        <?= gcStatusLabel($gc['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $gc['id'] ?>"
                                       class="btn btn-sm btn-outline-primary btn-action">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Mitgliederliste -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="bi bi-people me-2"></i>Alle Mitglieder (<?= count($members) ?>)</h6>
        </div>

        <?php if (empty($members)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-4">
                <i class="bi bi-people fs-2 d-block mb-2"></i>
                Noch keine Mitglieder. Erst Gruppenvertrag anlegen, dann Mitglieder hinzufügen.
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Vertrag</th>
                                <th>Klasse</th>
                                <th>Monatsbeitrag</th>
                                <th>Region</th>
                                <th>Status</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>">
                                        <strong><?= e($m['last_name'] . ', ' . $m['first_name']) ?></strong>
                                    </a>
                                    <?php if ($m['email']): ?>
                                    <br><small class="text-muted"><?= e($m['email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $m['group_contract_id'] ?>" class="text-muted small">
                                        <?= e($m['contract_number']) ?>
                                    </a>
                                </td>
                                <td><span class="badge bg-secondary">Klasse <?= $m['insurance_class'] ?></span></td>
                                <td><?= formatMoney($m['premium_monthly']) ?></td>
                                <td><?= $m['region'] ? e($m['region']) : '<span class="text-muted">–</span>' ?></td>
                                <td>
                                    <span class="badge <?= memberStatusBadge($m['status']) ?>">
                                        <?= memberStatusLabel($m['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>"
                                       class="btn btn-sm btn-outline-primary btn-action">
                                        <i class="bi bi-eye"></i>
                                    </a>
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
</div>

<!-- Bearbeiten-Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Arbeitgeber bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Firmenname *</label>
                            <input type="text" class="form-control" name="company_name"
                                   value="<?= e($employer['company_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ansprechpartner</label>
                            <input type="text" class="form-control" name="contact_person"
                                   value="<?= e($employer['contact_person'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?= e($employer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= e($employer['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($employer['iban'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address"
                                   value="<?= e($employer['address'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($employer['notes'] ?? '') ?></textarea>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
