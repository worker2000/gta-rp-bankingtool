<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Gruppenvertrag-Detail
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

$gc = Database::fetchOne("
    SELECT igc.*,
           ie.company_name, ie.contact_person, ie.email as employer_email,
           ip.name as product_name, ip.type as product_type,
           u1.full_name as created_by_name,
           u2.full_name as approved_by_name
    FROM insurance_group_contracts igc
    JOIN insurance_employers ie ON igc.employer_id = ie.id
    JOIN insurance_products ip ON igc.product_id = ip.id
    LEFT JOIN users u1 ON igc.created_by = u1.id
    LEFT JOIN users u2 ON igc.approved_by = u2.id
    WHERE igc.id = ? AND igc.bank_id = 2
", [$id]);

if (!$gc) {
    setFlash('error', 'Gruppenvertrag nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

$pageTitle = $gc['contract_number'];

// POST-Aktionen (Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action    = $_POST['action'] ?? '';
    $oldStatus = $gc['status'];

    switch ($action) {
        case 'activate':
            if ($gc['status'] === 'APPLIED') {
                Database::update('insurance_group_contracts', [
                    'status'      => 'ACTIVE',
                    'approved_by' => Auth::userId(),
                    'approved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_group_contract', $id,
                    ['status' => $oldStatus], ['status' => 'ACTIVE']);
                setFlash('success', 'Gruppenvertrag aktiviert.');
            }
            break;

        case 'suspend':
            if ($gc['status'] === 'ACTIVE') {
                Database::update('insurance_group_contracts', ['status' => 'SUSPENDED'], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_group_contract', $id,
                    ['status' => $oldStatus], ['status' => 'SUSPENDED']);
                setFlash('success', 'Gruppenvertrag ruhend gestellt.');
            }
            break;

        case 'reactivate':
            if ($gc['status'] === 'SUSPENDED') {
                Database::update('insurance_group_contracts', ['status' => 'ACTIVE'], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_group_contract', $id,
                    ['status' => $oldStatus], ['status' => 'ACTIVE']);
                setFlash('success', 'Gruppenvertrag reaktiviert.');
            }
            break;

        case 'cancel':
            $reason = trim($_POST['cancellation_reason'] ?? '');
            if (in_array($gc['status'], ['APPLIED', 'ACTIVE', 'SUSPENDED'])) {
                Database::update('insurance_group_contracts', [
                    'status' => 'CANCELLED',
                    'notes'  => ($gc['notes'] ? $gc['notes'] . "\n\nKündigung: " : 'Kündigung: ') . $reason,
                ], 'id = ?', [$id]);
                AuditLog::log('STATUS_CHANGE', 'insurance_group_contract', $id,
                    ['status' => $oldStatus], ['status' => 'CANCELLED', 'reason' => $reason]);
                setFlash('success', 'Gruppenvertrag gekündigt.');
            }
            break;
    }

    header('Location: ' . APP_URL . '/pages/insurance/group/view.php?id=' . $id);
    exit;
}

// Mitglieder
$allMembers = Database::fetchAll("
    SELECT im.*, b.customer_number as borrower_number
    FROM insurance_members im
    LEFT JOIN borrowers b ON im.borrower_id = b.id
    WHERE im.group_contract_id = ?
    ORDER BY im.last_name, im.first_name
", [$id]);

$members         = array_values(array_filter($allMembers, fn($m) => $m['status'] !== 'INACTIVE'));
$inactiveMembers = array_values(array_filter($allMembers, fn($m) => $m['status'] === 'INACTIVE'));

// Leistungsanträge über member_id
$claims = Database::fetchAll("
    SELECT cl.*, im.first_name as member_first, im.last_name as member_last
    FROM insurance_claims cl
    JOIN insurance_members im ON cl.member_id = im.id
    WHERE im.group_contract_id = ?
    ORDER BY cl.created_at DESC
    LIMIT 50
", [$id]);

// KPIs
$kpis = [
    'total_members'    => count($members),
    'active_members'   => count(array_filter($members, fn($m) => $m['status'] === 'ACTIVE')),
    'inactive_members' => count($inactiveMembers),
    'monthly_premium'  => array_sum(array_map(fn($m) => $m['status'] === 'ACTIVE' ? $m['premium_monthly'] : 0, $members)),
    'open_claims'      => count(array_filter($claims, fn($cl) => in_array($cl['status'], ['SUBMITTED', 'IN_REVIEW']))),
];

$activeTab = $_GET['tab'] ?? 'members';

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
function claimStatusLabel(string $s): string {
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
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $gc['employer_id'] ?>">
                <?= e($gc['company_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active"><?= e($gc['contract_number']) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-file-earmark-text me-2"></i><?= e($gc['contract_number']) ?>
            <span class="badge <?= gcStatusBadge($gc['status']) ?> ms-2">
                <?= gcStatusLabel($gc['status']) ?>
            </span>
        </h4>
        <div class="text-muted">
            <?= e($gc['company_name']) ?> &middot; <?= e($gc['product_name']) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/pages/insurance/group/members/add.php?group_contract_id=<?= $id ?>"
           class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus me-1"></i>Mitglied hinzufügen
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
                <div class="kpi-value"><?= $kpis['total_members'] ?></div>
                <div class="kpi-label">Mitglieder gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['monthly_premium']) ?></div>
                <div class="kpi-label">Monatsbeiträge gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card <?= $kpis['open_claims'] > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="kpi-value <?= $kpis['open_claims'] > 0 ? 'text-warning' : '' ?>">
                    <?= $kpis['open_claims'] ?>
                </div>
                <div class="kpi-label">Offene Leistungsanträge</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Linke Spalte: Vertragsdaten + Aktionen -->
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">Vertragsdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Vertragsnr.</td><td><strong><?= e($gc['contract_number']) ?></strong></td></tr>
                    <tr><td class="text-muted">Arbeitgeber</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $gc['employer_id'] ?>">
                                <?= e($gc['company_name']) ?>
                            </a>
                        </td>
                    </tr>
                    <tr><td class="text-muted">Tarif</td><td><?= e($gc['product_name']) ?></td></tr>
                    <tr><td class="text-muted">Beginn</td><td><?= formatDate($gc['start_date']) ?></td></tr>
                    <tr>
                        <td class="text-muted">Ende</td>
                        <td><?= $gc['end_date'] ? formatDate($gc['end_date']) : '<span class="text-muted">unbefristet</span>' ?></td>
                    </tr>
                    <?php if ($gc['staff_initials']): ?>
                    <tr>
                        <td class="text-muted">Bearbeiter</td>
                        <td><span class="badge bg-secondary"><?= e($gc['staff_initials']) ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($gc['approved_by_name']): ?>
                    <tr><td class="text-muted">Genehmigt von</td><td><?= e($gc['approved_by_name']) ?></td></tr>
                    <tr><td class="text-muted">am</td><td><?= formatDate($gc['approved_at']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($gc['created_by_name']): ?>
                    <tr><td class="text-muted">Angelegt von</td><td><?= e($gc['created_by_name']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($gc['notes']): ?>
        <div class="card mb-3">
            <div class="card-header">Notizen</div>
            <div class="card-body">
                <p class="mb-0 small"><?= nl2br(e($gc['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status-Aktionen -->
        <div class="card">
            <div class="card-header">Aktionen</div>
            <div class="card-body d-grid gap-2">
                <?php if ($gc['status'] === 'APPLIED'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="activate">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check-circle me-2"></i>Vertrag aktivieren
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($gc['status'] === 'ACTIVE'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="suspend">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-pause-circle me-2"></i>Ruhend stellen
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($gc['status'] === 'SUSPENDED'): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reactivate">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-play-circle me-2"></i>Reaktivieren
                    </button>
                </form>
                <?php endif; ?>

                <?php if (in_array($gc['status'], ['APPLIED', 'ACTIVE', 'SUSPENDED'])): ?>
                <button type="button" class="btn btn-danger w-100"
                        data-bs-toggle="modal" data-bs-target="#cancelModal">
                    <i class="bi bi-x-circle me-2"></i>Kündigen
                </button>
                <?php endif; ?>

                <a href="<?= APP_URL ?>/pages/insurance/group/members/add.php?group_contract_id=<?= $id ?>"
                   class="btn btn-outline-primary w-100">
                    <i class="bi bi-person-plus me-2"></i>Mitglied hinzufügen
                </a>
            </div>
        </div>
    </div>

    <!-- Rechte Spalte: Tabs -->
    <div class="col-md-8">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'members' ? 'active' : '' ?>"
                   href="?id=<?= $id ?>&tab=members">
                    <i class="bi bi-people me-1"></i>Mitglieder
                    <span class="badge bg-secondary"><?= $kpis['total_members'] ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'history' ? 'active' : '' ?>"
                   href="?id=<?= $id ?>&tab=history">
                    <i class="bi bi-clock-history me-1"></i>Ausgetreten
                    <?php if ($kpis['inactive_members'] > 0): ?>
                    <span class="badge bg-secondary"><?= $kpis['inactive_members'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'claims' ? 'active' : '' ?>"
                   href="?id=<?= $id ?>&tab=claims">
                    <i class="bi bi-file-medical me-1"></i>Leistungsanträge
                    <?php if ($kpis['open_claims'] > 0): ?>
                    <span class="badge bg-warning"><?= $kpis['open_claims'] ?></span>
                    <?php else: ?>
                    <span class="badge bg-secondary"><?= count($claims) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'members'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted small"><?= $kpis['active_members'] ?> aktiv / <?= $kpis['total_members'] ?> gesamt</span>
            <a href="<?= APP_URL ?>/pages/insurance/group/members/add.php?group_contract_id=<?= $id ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus me-1"></i>Mitglied hinzufügen
            </a>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($members)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-people fs-2 d-block mb-2"></i>
                    Noch keine Mitglieder.
                    <a href="<?= APP_URL ?>/pages/insurance/group/members/add.php?group_contract_id=<?= $id ?>"
                       class="btn btn-primary btn-sm ms-2">Erstes Mitglied hinzufügen</a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Klasse</th>
                                <th>Monatsbeitrag</th>
                                <th>Region</th>
                                <th>Dabei seit</th>
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
                                    <span class="badge bg-secondary">Klasse <?= $m['insurance_class'] ?></span>
                                </td>
                                <td><strong><?= formatMoney($m['premium_monthly']) ?></strong></td>
                                <td><?= $m['region'] ? e($m['region']) : '<span class="text-muted">–</span>' ?></td>
                                <td><small><?= formatDate($m['start_date']) ?></small></td>
                                <td>
                                    <span class="badge <?= memberStatusBadge($m['status']) ?>">
                                        <?= memberStatusLabel($m['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>"
                                       class="btn btn-sm btn-outline-primary btn-action" title="Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?= APP_URL ?>/pages/insurance/claims/create.php?member_id=<?= $m['id'] ?>"
                                       class="btn btn-sm btn-outline-success btn-action" title="Leistungsantrag">
                                        <i class="bi bi-file-medical"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-active">
                                <td colspan="2"><strong>Gesamt (aktive Mitglieder)</strong></td>
                                <td><strong><?= formatMoney($kpis['monthly_premium']) ?>/Monat</strong></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'history'): ?>
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($inactiveMembers)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                    Noch keine ausgetretenen Mitglieder.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Klasse</th>
                                <th>Eintritt</th>
                                <th>Austritt</th>
                                <th>Grund / Notiz</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactiveMembers as $m):
                                // Austrittsgrund aus notes extrahieren (Format: "...Austritt: Grund")
                                $exitNote = '';
                                if ($m['notes'] && preg_match('/Austritt:\s*(.+)$/s', $m['notes'], $match)) {
                                    $exitNote = trim($match[1]);
                                }
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>">
                                        <strong><?= e($m['last_name'] . ', ' . $m['first_name']) ?></strong>
                                    </a>
                                    <?php if ($m['email']): ?>
                                    <br><small class="text-muted"><?= e($m['email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary">Klasse <?= $m['insurance_class'] ?></span></td>
                                <td><small><?= formatDate($m['start_date']) ?></small></td>
                                <td>
                                    <?php if ($m['end_date']): ?>
                                    <small class="text-danger"><?= formatDate($m['end_date']) ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($exitNote): ?>
                                    <small class="text-muted fst-italic"><?= e(mb_strimwidth($exitNote, 0, 60, '…')) ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary btn-action" title="Details">
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

        <?php elseif ($activeTab === 'claims'): ?>
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($claims)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-file-medical fs-2 d-block mb-2"></i>
                    Noch keine Leistungsanträge.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Antragsnr.</th>
                                <th>Mitglied</th>
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
                                <td><?= e($cl['member_last'] . ', ' . $cl['member_first']) ?></td>
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
        <?php endif; ?>
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
                    <p>Möchten Sie den Gruppenvertrag <strong><?= e($gc['contract_number']) ?></strong> wirklich kündigen?</p>
                    <p class="text-warning small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Alle <?= $kpis['active_members'] ?> aktiven Mitglieder verlieren den Versicherungsschutz.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Kündigungsgrund (optional)</label>
                        <textarea class="form-control" name="cancellation_reason" rows="3"
                                  placeholder="z.B. Arbeitgeber hat den Vertrag gekündigt..."></textarea>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
