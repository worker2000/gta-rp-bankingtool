<?php
ob_start();
/**
 * Fortis Finance – Freie KV-Mitglieder (ohne Gruppenvertrag)
 */
$pageTitle = 'Freie KV-Mitglieder';
require_once __DIR__ . '/../../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors = [];

// Gruppenvertrag zuweisen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $memberId    = intval($_POST['member_id']          ?? 0);
    $contractId  = intval($_POST['group_contract_id']  ?? 0);

    if (!$memberId || !$contractId) {
        $errors[] = 'Mitglied und Gruppenvertrag sind Pflichtfelder.';
    } else {
        // Sicherheitsprüfung: Mitglied muss frei und bank_id=2 sein
        $member = Database::fetchOne(
            "SELECT id, first_name, last_name FROM insurance_members
             WHERE id = ? AND bank_id = 2 AND group_contract_id IS NULL",
            [$memberId]
        );
        // Gruppenvertrag muss zu bank_id=2 gehören
        $contract = Database::fetchOne(
            "SELECT igc.id, igc.contract_number, ie.company_name
             FROM insurance_group_contracts igc
             JOIN insurance_employers ie ON igc.employer_id = ie.id
             WHERE igc.id = ? AND igc.bank_id = 2",
            [$contractId]
        );

        if (!$member) {
            $errors[] = 'Mitglied nicht gefunden oder bereits zugewiesen.';
        } elseif (!$contract) {
            $errors[] = 'Gruppenvertrag nicht gefunden.';
        } else {
            Database::update('insurance_members',
                ['group_contract_id' => $contractId],
                'id = ?', [$memberId]
            );
            AuditLog::log('UPDATE', 'insurance_member', $memberId,
                ['group_contract_id' => null],
                ['group_contract_id' => $contractId, 'contract_number' => $contract['contract_number']]
            );
            setFlash('success',
                $member['last_name'] . ', ' . $member['first_name'] .
                ' wurde dem Vertrag ' . $contract['contract_number'] .
                ' (' . $contract['company_name'] . ') zugewiesen.'
            );
            header('Location: ' . APP_URL . '/pages/insurance/group/members/unassigned.php');
            exit;
        }
    }
}

// Freie Mitglieder laden
$members = Database::fetchAll("
    SELECT im.*
    FROM insurance_members im
    WHERE im.bank_id = 2 AND im.group_contract_id IS NULL
    ORDER BY im.created_at DESC
");

// Arbeitgeber mit aktiven Gruppenverträgen für Zuweisungs-Modal
$employers = Database::fetchAll("
    SELECT ie.id as employer_id, ie.company_name,
           igc.id as contract_id, igc.contract_number, igc.status as contract_status
    FROM insurance_employers ie
    JOIN insurance_group_contracts igc ON igc.employer_id = ie.id
    WHERE ie.bank_id = 2 AND ie.is_active = 1
      AND igc.status IN ('ACTIVE', 'APPLIED')
    ORDER BY ie.company_name, igc.contract_number
");

// Employer-Gruppen für JS
$employerMap = [];
foreach ($employers as $e) {
    $employerMap[$e['employer_id']][] = [
        'id'              => $e['contract_id'],
        'contract_number' => $e['contract_number'],
        'status'          => $e['contract_status'],
    ];
}
$employerList = [];
foreach ($employers as $e) {
    if (!isset($employerList[$e['employer_id']])) {
        $employerList[$e['employer_id']] = $e['company_name'];
    }
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <li class="breadcrumb-item active">Freie Mitglieder</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4><i class="bi bi-person-dash me-2"></i>Freie KV-Mitglieder</h4>
        <p class="text-muted mb-0 small">
            Automatisch aus Bankimporten erkannte Mitglieder ohne zugewiesenen Gruppenvertrag.
        </p>
    </div>
    <span class="badge bg-warning text-dark fs-6"><?= count($members) ?> offen</span>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($members)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-check-circle text-success fs-1 d-block mb-3"></i>
        <h5>Keine freien Mitglieder</h5>
        <p class="text-muted">Alle importierten Mitglieder sind bereits einem Gruppenvertrag zugewiesen.</p>
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
                        <th>Referenz</th>
                        <th>Klasse / Prämie</th>
                        <th>Importiert am</th>
                        <th>Beiträge</th>
                        <th class="text-end">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m):
                        $premiumCount = Database::fetchOne(
                            "SELECT COUNT(*) as cnt, SUM(amount) as total FROM insurance_member_premiums WHERE member_id = ?",
                            [$m['id']]
                        );
                    ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $m['id'] ?>">
                                <strong><?= e($m['last_name'] . ', ' . $m['first_name']) ?></strong>
                            </a>
                            <?php if ($m['status'] !== 'ACTIVE'): ?>
                            <br><span class="badge bg-warning text-dark"><?= e($m['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['member_ref']): ?>
                            <code><?= e($m['member_ref']) ?></code>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary">Klasse <?= $m['insurance_class'] ?></span>
                            <br><small>$<?= number_format($m['premium_monthly'], 0) ?>/Monat</small>
                        </td>
                        <td>
                            <small><?= formatDate($m['created_at']) ?></small>
                            <?php
                            $days = (int)floor((time() - strtotime($m['created_at'])) / 86400);
                            if ($days > 7): ?>
                            <br><small class="text-danger">seit <?= $days ?> Tagen</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($premiumCount['cnt'] > 0): ?>
                            <span class="badge bg-success"><?= $premiumCount['cnt'] ?> Zahlung(en)</span>
                            <br><small><?= formatMoney($premiumCount['total']) ?></small>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-primary"
                                    onclick="openAssignModal(<?= $m['id'] ?>, <?= htmlspecialchars(json_encode($m['last_name'] . ', ' . $m['first_name']), ENT_QUOTES) ?>)">
                                <i class="bi bi-person-check me-1"></i>Zuweisen
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Zuweisungs-Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="assignForm">
                <?= csrfField() ?>
                <input type="hidden" name="member_id" id="modal_member_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>Gruppenvertrag zuweisen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Mitglied: <strong id="modal_member_name"></strong>
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Arbeitgeber *</label>
                        <select class="form-select" id="modal_employer" onchange="loadContracts()" required>
                            <option value="">– Arbeitgeber wählen –</option>
                            <?php foreach ($employerList as $eid => $ename): ?>
                            <option value="<?= $eid ?>"><?= e($ename) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="contract_group" style="display:none">
                        <label class="form-label">Gruppenvertrag *</label>
                        <select class="form-select" name="group_contract_id" id="modal_contract" required>
                            <option value="">– Vertrag wählen –</option>
                        </select>
                    </div>

                    <?php if (empty($employers)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Keine aktiven Gruppenverträge vorhanden.
                        <a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber anlegen</a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="modal_submit" disabled>
                        <i class="bi bi-check2 me-1"></i>Zuweisen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const employerContracts = <?= json_encode($employerMap) ?>;
let assignModal = null;

function openAssignModal(memberId, memberName) {
    document.getElementById('modal_member_id').value  = memberId;
    document.getElementById('modal_member_name').textContent = memberName;
    document.getElementById('modal_employer').value   = '';
    document.getElementById('modal_contract').innerHTML = '<option value="">– Vertrag wählen –</option>';
    document.getElementById('contract_group').style.display = 'none';
    document.getElementById('modal_submit').disabled  = true;

    if (!assignModal) assignModal = new bootstrap.Modal(document.getElementById('assignModal'));
    assignModal.show();
}

function loadContracts() {
    const eid = document.getElementById('modal_employer').value;
    const sel = document.getElementById('modal_contract');
    const grp = document.getElementById('contract_group');

    sel.innerHTML = '<option value="">– Vertrag wählen –</option>';
    document.getElementById('modal_submit').disabled = true;

    if (!eid || !employerContracts[eid]) {
        grp.style.display = 'none';
        return;
    }

    grp.style.display = '';
    employerContracts[eid].forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.contract_number + ' (' + c.status + ')';
        sel.appendChild(opt);
    });
}

document.getElementById('modal_contract').addEventListener('change', function() {
    document.getElementById('modal_submit').disabled = !this.value;
});
</script>

<?php require_once __DIR__ . '/../../../../includes/footer.php'; ?>
