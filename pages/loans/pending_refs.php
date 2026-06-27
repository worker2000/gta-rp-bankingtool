<?php
ob_start();
/**
 * Ausstehende Kredit-Referenzen (aus Bankimport erkannt)
 */
$pageTitle = 'Ausstehende Kredite';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('import', 'upload');

$errors = [];

// Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $refId  = intval($_POST['ref_id'] ?? 0);

    if ($refId) {
        $ref = Database::fetchOne(
            "SELECT * FROM pending_loan_refs WHERE id = ? AND bank_id = ?",
            [$refId, currentBankId()]
        );

        if (!$ref) {
            $errors[] = 'Referenz nicht gefunden.';

        } elseif ($action === 'ignore') {
            Database::update('pending_loan_refs', ['status' => 'IGNORED'], 'id = ?', [$refId]);
            AuditLog::log('UPDATE', 'pending_loan_ref', $refId, ['status' => 'PENDING'], ['status' => 'IGNORED']);
            setFlash('success', 'Referenz ' . $ref['ref_number'] . ' als ignoriert markiert.');
            header('Location: ' . APP_URL . '/pages/loans/pending_refs.php');
            exit;

        } elseif ($action === 'restore') {
            Database::update('pending_loan_refs', ['status' => 'PENDING'], 'id = ?', [$refId]);
            AuditLog::log('UPDATE', 'pending_loan_ref', $refId, ['status' => 'IGNORED'], ['status' => 'PENDING']);
            setFlash('success', 'Referenz ' . $ref['ref_number'] . ' wiederhergestellt.');
            header('Location: ' . APP_URL . '/pages/loans/pending_refs.php');
            exit;

        } elseif ($action === 'link_borrower') {
            $borrowerId = intval($_POST['borrower_id'] ?? 0);
            if (!$borrowerId) { $errors[] = 'Kein Kreditnehmer ausgewählt.'; }
            else {
                $borrower = Database::fetchOne(
                    "SELECT id, first_name, last_name FROM borrowers WHERE id = ? AND bank_id = ?",
                    [$borrowerId, currentBankId()]
                );
                if (!$borrower) { $errors[] = 'Kreditnehmer nicht gefunden.'; }
                else {
                    Database::update('pending_loan_refs', ['borrower_id' => $borrowerId], 'id = ?', [$refId]);
                    AuditLog::log('UPDATE', 'pending_loan_ref', $refId, null, [
                        'borrower_id' => $borrowerId,
                        'name'        => $borrower['last_name'] . ', ' . $borrower['first_name'],
                    ]);
                    setFlash('success',
                        'Kreditnehmer ' . $borrower['last_name'] . ', ' . $borrower['first_name'] .
                        ' mit Referenz ' . $ref['ref_number'] . ' verknüpft.'
                    );
                    header('Location: ' . APP_URL . '/pages/loans/pending_refs.php');
                    exit;
                }
            }

        } elseif ($action === 'link_loan') {
            $loanId = intval($_POST['loan_id'] ?? 0);
            if (!$loanId) { $errors[] = 'Kein Kredit ausgewählt.'; }
            else {
                $loan = Database::fetchOne(
                    "SELECT id, file_number FROM loans WHERE id = ? AND bank_id = ?",
                    [$loanId, currentBankId()]
                );
                if (!$loan) { $errors[] = 'Kredit nicht gefunden.'; }
                else {
                    // Pending ref auf CONVERTED setzen
                    Database::update('pending_loan_refs', [
                        'loan_id' => $loanId,
                        'status'  => 'CONVERTED',
                    ], 'id = ?', [$refId]);

                    // Alle Transaktionen dieser Referenz chronologisch auf den Kredit buchen
                    $txs = Database::fetchAll(
                        "SELECT id, amount FROM bank_transactions
                         WHERE matched_pending_ref_id = ?
                         ORDER BY transaction_date ASC, id ASC",
                        [$refId]
                    );
                    $rematched = 0;
                    foreach ($txs as $tx) {
                        $schedule = Matching::findOpenScheduleItem($loanId, (float)$tx['amount']);
                        Matching::applyMatch(
                            $tx['id'], $loanId,
                            $schedule ? $schedule['id'] : null,
                            'LOAN_REF', 1.0
                        );
                        // matched_pending_ref_id leeren (nun über matched_loan_id verknüpft)
                        Database::update('bank_transactions',
                            ['matched_pending_ref_id' => null],
                            'id = ?', [$tx['id']]
                        );
                        $rematched++;
                    }

                    AuditLog::log('UPDATE', 'pending_loan_ref', $refId, null, [
                        'loan_id'     => $loanId,
                        'file_number' => $loan['file_number'],
                        'status'      => 'CONVERTED',
                        'rematched'   => $rematched,
                    ]);
                    setFlash('success',
                        'Referenz ' . $ref['ref_number'] . ' mit Kredit ' . $loan['file_number'] .
                        ' verknüpft. ' . $rematched . ' Zahlung(en) dem Kredit zugeordnet.'
                    );
                    header('Location: ' . APP_URL . '/pages/loans/view.php?id=' . $loanId);
                    exit;
                }
            }

        } elseif ($action === 'save_notes') {
            $notes = trim($_POST['notes'] ?? '');
            Database::update('pending_loan_refs', ['notes' => $notes ?: null], 'id = ?', [$refId]);
            setFlash('success', 'Notiz gespeichert.');
            header('Location: ' . APP_URL . '/pages/loans/pending_refs.php');
            exit;
        }
    }
}

// Filter
$statusFilter = $_GET['status'] ?? 'PENDING';
$highlight    = intval($_GET['highlight'] ?? 0);

$where  = "WHERE plr.bank_id = ?";
$params = [currentBankId()];
if ($statusFilter && $statusFilter !== 'all') {
    $where  .= " AND plr.status = ?";
    $params[] = $statusFilter;
}

$refs = Database::fetchAll("
    SELECT plr.*,
           l.file_number, l.status as loan_status,
           lb.first_name  as loan_borrower_first,  lb.last_name  as loan_borrower_last,
           pb.id          as pre_borrower_id,
           pb.first_name  as pre_borrower_first,   pb.last_name  as pre_borrower_last,
           pb.customer_number as pre_borrower_number,
           COUNT(bt.id) as tx_count_real
    FROM pending_loan_refs plr
    LEFT JOIN loans l       ON plr.loan_id = l.id
    LEFT JOIN borrowers lb  ON l.borrower_id = lb.id
    LEFT JOIN borrowers pb  ON plr.borrower_id = pb.id
    LEFT JOIN bank_transactions bt ON bt.matched_pending_ref_id = plr.id
    {$where}
    GROUP BY plr.id
    ORDER BY plr.status ASC, plr.last_seen DESC
", $params);

// KPIs
$kpis = Database::fetchOne("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'PENDING'   THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'CONVERTED' THEN 1 ELSE 0 END) as converted,
        SUM(CASE WHEN status = 'IGNORED'   THEN 1 ELSE 0 END) as ignored,
        COALESCE(SUM(CASE WHEN status = 'PENDING' THEN total_received END), 0) as pending_total
    FROM pending_loan_refs WHERE bank_id = ?
", [currentBankId()]);

// Aktive Kredite für Verknüpfungs-Modal
$activeLoans = Database::fetchAll("
    SELECT l.id, l.file_number, l.status, b.first_name, b.last_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.bank_id = ? AND l.status NOT IN ('CLOSED','REJECTED')
    ORDER BY b.last_name, b.first_name
", [currentBankId()]);

// Alle Kreditnehmer (für Kreditnehmer-Verknüpfungs-Modal)
$allBorrowers = Database::fetchAll("
    SELECT id, customer_number, first_name, last_name
    FROM borrowers
    WHERE bank_id = ? AND is_active = 1
    ORDER BY last_name, first_name
", [currentBankId()]);
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/loans/index.php">Kredite</a></li>
        <li class="breadcrumb-item active">Ausstehende Kredit-Referenzen</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4><i class="bi bi-hourglass-split me-2"></i>Ausstehende Kredit-Referenzen</h4>
        <p class="text-muted small mb-0">
            Aus Bankimporten erkannte Referenznummern ohne zugeordneten Kredit.
        </p>
    </div>
    <a href="<?= APP_URL ?>/pages/import/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-2"></i>Zum Import
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body">
                <div class="kpi-value text-warning"><?= $kpis['pending'] ?></div>
                <div class="kpi-label">Ausstehend</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body">
                <div class="kpi-value text-success"><?= $kpis['converted'] ?></div>
                <div class="kpi-label">Verknüpft</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= $kpis['ignored'] ?></div>
                <div class="kpi-label">Ignoriert</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($kpis['pending_total']) ?></div>
                <div class="kpi-label">Eingegangen (ausstehend)</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="btn-group mb-3">
    <a href="?status=PENDING"   class="btn btn-outline-warning   <?= $statusFilter === 'PENDING'   ? 'active' : '' ?>">Ausstehend (<?= $kpis['pending'] ?>)</a>
    <a href="?status=CONVERTED" class="btn btn-outline-success   <?= $statusFilter === 'CONVERTED' ? 'active' : '' ?>">Verknüpft (<?= $kpis['converted'] ?>)</a>
    <a href="?status=IGNORED"   class="btn btn-outline-secondary <?= $statusFilter === 'IGNORED'   ? 'active' : '' ?>">Ignoriert (<?= $kpis['ignored'] ?>)</a>
    <a href="?status=all"       class="btn btn-outline-secondary <?= $statusFilter === 'all'       ? 'active' : '' ?>">Alle (<?= $kpis['total'] ?>)</a>
</div>

<?php if (empty($refs)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-check-circle text-success fs-1 d-block mb-3"></i>
        <h5>Keine Einträge</h5>
        <p class="text-muted">Es gibt keine ausstehenden Kredit-Referenzen.</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="refsTable">
                <thead>
                    <tr>
                        <th>Referenznummer</th>
                        <th>Kreditnehmer</th>
                        <th>Zahlungen</th>
                        <th>Gesamt eingegangen</th>
                        <th>Wochenrate (ca.)</th>
                        <th>Zeitraum</th>
                        <th>Status / Kredit</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refs as $r): ?>
                    <tr id="ref-<?= $r['id'] ?>"
                        class="<?= $r['id'] === $highlight ? 'table-warning' : '' ?>
                               <?= $r['status'] === 'IGNORED' ? 'text-muted' : '' ?>">
                        <td>
                            <code class="<?= $r['status'] === 'IGNORED' ? 'text-muted' : 'text-primary fw-bold' ?>">
                                <?= e($r['ref_number']) ?>
                            </code>
                            <?php if ($r['sender_name']): ?>
                            <br><small class="text-muted"><?= e($r['sender_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'CONVERTED' && $r['loan_borrower_last']): ?>
                                <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $r['loan_id'] ?>">
                                    <?= e($r['loan_borrower_last'] . ', ' . $r['loan_borrower_first']) ?>
                                </a>
                            <?php elseif ($r['pre_borrower_id']): ?>
                                <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $r['pre_borrower_id'] ?>"
                                   class="text-success fw-semibold">
                                    <i class="bi bi-person-check me-1"></i><?= e($r['pre_borrower_last'] . ', ' . $r['pre_borrower_first']) ?>
                                </a>
                                <br><small class="text-muted"><?= e($r['pre_borrower_number']) ?></small>
                                <br><span class="badge bg-warning text-dark" style="font-size:.7em">Kein Kredit</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="openBorrowerModal(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r['ref_number']), ENT_QUOTES) ?>)">
                                    <i class="bi bi-person-plus me-1"></i>Zuweisen
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $r['transaction_count'] ?></span>
                        </td>
                        <td><strong><?= formatMoney($r['total_received']) ?></strong></td>
                        <td>
                            <?php if ($r['weekly_amount']): ?>
                            <?= formatMoney($r['weekly_amount']) ?>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small><?= formatDate($r['first_seen']) ?></small>
                            <?php if ($r['first_seen'] !== $r['last_seen']): ?>
                            <br><small class="text-muted">bis <?= formatDate($r['last_seen']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'CONVERTED' && $r['loan_id']): ?>
                            <span class="badge bg-success">Verknüpft</span>
                            <br>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $r['loan_id'] ?>" class="small">
                                <?= e($r['file_number']) ?>
                            </a>
                            <?php elseif ($r['status'] === 'IGNORED'): ?>
                            <span class="badge bg-secondary">Ignoriert</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Ausstehend</span>
                            <?php endif; ?>
                            <?php if ($r['notes']): ?>
                            <br><small class="text-muted fst-italic"><?= e(substr($r['notes'], 0, 40)) ?><?= strlen($r['notes']) > 40 ? '…' : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['status'] === 'PENDING'): ?>

                            <?php if ($r['pre_borrower_id']): ?>
                            <!-- Kreditnehmer bekannt: Kredit anlegen -->
                            <a href="<?= APP_URL ?>/pages/loans/create.php?borrower_id=<?= $r['pre_borrower_id'] ?>&pending_ref=<?= urlencode($r['ref_number']) ?>"
                               class="btn btn-sm btn-primary btn-action" title="Kredit anlegen">
                                <i class="bi bi-file-earmark-plus"></i>
                            </a>
                            <?php else: ?>
                            <!-- Kein Kreditnehmer: erst anlegen -->
                            <a href="<?= APP_URL ?>/pages/borrowers/create.php?name=<?= urlencode($r['sender_name'] ?? '') ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Kreditnehmer anlegen">
                                <i class="bi bi-person-plus"></i>
                            </a>
                            <?php endif; ?>

                            <!-- Mit bestehendem Kredit verknüpfen -->
                            <button type="button" class="btn btn-sm btn-outline-success btn-action"
                                    title="Mit bestehendem Kredit verknüpfen"
                                    onclick="openLinkModal(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r['ref_number']), ENT_QUOTES) ?>)">
                                <i class="bi bi-link-45deg"></i>
                            </button>

                            <!-- Ignorieren -->
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="ignore">
                                <input type="hidden" name="ref_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary btn-action"
                                        title="Ignorieren"
                                        onclick="return confirm('Referenz <?= e($r['ref_number']) ?> ignorieren?')">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>

                            <?php elseif ($r['status'] === 'IGNORED'): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="ref_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning btn-action" title="Wiederherstellen">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </form>
                            <?php elseif ($r['status'] === 'CONVERTED'): ?>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $r['loan_id'] ?>"
                               class="btn btn-sm btn-outline-success btn-action" title="Kredit anzeigen">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Kreditnehmer-Verknüpfungs-Modal -->
<div class="modal fade" id="borrowerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="borrowerForm">
                <?= csrfField() ?>
                <input type="hidden" name="action"      value="link_borrower">
                <input type="hidden" name="ref_id"      id="bm_ref_id">
                <input type="hidden" name="borrower_id" id="bm_borrower_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>Kreditnehmer zuweisen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Referenz: <strong id="bm_ref_display"></strong>
                    </p>
                    <div class="d-flex gap-2 mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="borrower_search"
                                   placeholder="Name, Kundennummer…"
                                   oninput="filterBorrowers()" autocomplete="off">
                        </div>
                        <a href="<?= APP_URL ?>/pages/borrowers/create.php"
                           class="btn btn-outline-primary text-nowrap" target="_blank">
                            <i class="bi bi-person-plus me-1"></i>Neu anlegen
                        </a>
                    </div>
                    <div id="borrower_results"
                         style="max-height:300px;overflow-y:auto;border:1px solid var(--bs-border-color);border-radius:var(--bs-border-radius)">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="bm_submit" disabled>
                        <i class="bi bi-check2 me-1"></i>Zuweisen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Kredit-Verknüpfungs-Modal -->
<div class="modal fade" id="linkLoanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="linkLoanForm">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="link_loan">
                <input type="hidden" name="ref_id"  id="link_ref_id">
                <input type="hidden" name="loan_id" id="link_loan_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Mit bestehendem Kredit verknüpfen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Referenz: <strong id="link_ref_display"></strong>
                    </p>
                    <div class="alert alert-info small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Alle Zahlungen dieser Referenz werden automatisch dem gewählten Kredit gutgeschrieben.
                    </div>
                    <div class="input-group mb-2">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="loan_search"
                               placeholder="Name, Aktenzeichen…"
                               oninput="filterLoans()" autocomplete="off">
                    </div>
                    <div id="loan_results"
                         style="max-height:300px;overflow-y:auto;border:1px solid var(--bs-border-color);border-radius:var(--bs-border-radius)">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success" id="link_submit" disabled>
                        <i class="bi bi-check2 me-1"></i>Verknüpfen & Zahlungen buchen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($highlight): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('ref-<?= $highlight ?>');
    if (el) el.scrollIntoView({behavior: 'smooth', block: 'center'});
});
</script>
<?php endif; ?>

<script>
const allLoans     = <?= json_encode(array_values($activeLoans)) ?>;
const allBorrowers = <?= json_encode(array_values($allBorrowers)) ?>;
let linkModal = null, borrowerModal = null;

// ── Kreditnehmer-Modal ────────────────────────────────────────────
function openBorrowerModal(refId, refNumber) {
    document.getElementById('bm_ref_id').value         = refId;
    document.getElementById('bm_borrower_id').value    = '';
    document.getElementById('bm_ref_display').textContent = refNumber;
    document.getElementById('bm_submit').disabled      = true;
    document.getElementById('borrower_search').value   = '';
    document.getElementById('borrower_results').innerHTML = '';

    if (!borrowerModal) borrowerModal = new bootstrap.Modal(document.getElementById('borrowerModal'));
    borrowerModal.show();
    setTimeout(() => { document.getElementById('borrower_search').focus(); filterBorrowers(); }, 300);
}

function filterBorrowers() {
    const q = document.getElementById('borrower_search').value.toLowerCase().trim();
    const filtered = q.length === 0 ? allBorrowers : allBorrowers.filter(b =>
        (b.customer_number + ' ' + b.last_name + ' ' + b.first_name).toLowerCase().includes(q)
    );
    const container = document.getElementById('borrower_results');
    if (filtered.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3 mb-0">Keine Ergebnisse</p>';
        return;
    }
    container.innerHTML = filtered.slice(0, 80).map(b =>
        `<div class="result-item px-3 py-2 d-flex justify-content-between align-items-center"
              style="cursor:pointer;border-bottom:1px solid var(--bs-border-color);"
              data-id="${b.id}" onclick="selectBorrower(${b.id})">
            <div>
                <strong>${escHtml(b.last_name)}, ${escHtml(b.first_name)}</strong>
            </div>
            <span class="badge bg-secondary">${escHtml(b.customer_number)}</span>
        </div>`
    ).join('');
}

function selectBorrower(id) {
    document.querySelectorAll('#borrower_results .result-item').forEach(el => {
        const active = parseInt(el.dataset.id) === id;
        el.style.background = active ? 'var(--bs-primary)' : '';
        el.style.color      = active ? '#fff' : '';
    });
    document.getElementById('bm_borrower_id').value = id;
    document.getElementById('bm_submit').disabled   = false;
}

// ── Kredit-Modal ──────────────────────────────────────────────────
function openLinkModal(refId, refNumber) {
    document.getElementById('link_ref_id').value       = refId;
    document.getElementById('link_loan_id').value      = '';
    document.getElementById('link_ref_display').textContent = refNumber;
    document.getElementById('link_submit').disabled    = true;
    document.getElementById('loan_search').value       = '';
    document.getElementById('loan_results').innerHTML  = '';

    if (!linkModal) linkModal = new bootstrap.Modal(document.getElementById('linkLoanModal'));
    linkModal.show();
    setTimeout(() => { document.getElementById('loan_search').focus(); filterLoans(); }, 300);
}

function filterLoans() {
    const q = document.getElementById('loan_search').value.toLowerCase().trim();
    const filtered = q.length === 0 ? allLoans : allLoans.filter(l =>
        (l.file_number + ' ' + l.last_name + ' ' + l.first_name).toLowerCase().includes(q)
    );
    const container = document.getElementById('loan_results');
    if (filtered.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3 mb-0">Keine Ergebnisse</p>';
        return;
    }
    container.innerHTML = filtered.slice(0, 80).map(l =>
        `<div class="result-item px-3 py-2 d-flex justify-content-between align-items-center"
              style="cursor:pointer;border-bottom:1px solid var(--bs-border-color);"
              data-id="${l.id}" onclick="selectLoan(${l.id})">
            <div>
                <strong>${escHtml(l.file_number)}</strong>
                &nbsp;–&nbsp;${escHtml(l.last_name)}, ${escHtml(l.first_name)}
            </div>
            <span class="badge bg-secondary">${escHtml(l.status)}</span>
        </div>`
    ).join('');
}

function selectLoan(id) {
    document.querySelectorAll('#loan_results .result-item').forEach(el => {
        const active = parseInt(el.dataset.id) === id;
        el.style.background = active ? 'var(--bs-primary)' : '';
        el.style.color      = active ? '#fff' : '';
    });
    document.getElementById('link_loan_id').value   = id;
    document.getElementById('link_submit').disabled = false;
}

function escHtml(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
