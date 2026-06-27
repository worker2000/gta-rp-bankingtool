<?php
ob_start();
/**
 * PSB Kreditverwaltung - Batch Details
 */
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('import', 'upload');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/import/index.php');
    exit;
}

$batch = Database::fetchOne("
    SELECT bsb.*, u.full_name as imported_name
    FROM bank_statement_batches bsb
    LEFT JOIN users u ON bsb.imported_by = u.id
    WHERE bsb.id = ?
", [$id]);

if (!$batch) {
    setFlash('error', 'Batch nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/import/index.php');
    exit;
}

$pageTitle = 'Import ' . formatDate($batch['batch_date']);

// Manuelle Zuordnung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('import', 'match') && verifyCsrf()) {
    $txId      = intval($_POST['transaction_id'] ?? 0);
    $matchType = $_POST['match_type'] ?? 'loan';
    $loanId    = intval($_POST['loan_id'] ?? 0);
    $accountId = intval($_POST['account_id'] ?? 0);

    if ($txId && $matchType === 'loan' && $loanId) {
        $schedule = Database::fetchOne(
            "SELECT id FROM loan_schedule_items WHERE loan_id = ? AND status IN ('PENDING','PARTIAL','OVERDUE') ORDER BY due_date LIMIT 1",
            [$loanId]
        );
        Matching::applyMatch($txId, $loanId, $schedule['id'] ?? null, 'MANUAL', 1.0);
        AuditLog::log('MANUAL_MATCH', 'bank_transaction', $txId, null, ['loan_id' => $loanId]);
        Database::query(
            "UPDATE bank_statement_batches SET matched_count = matched_count + 1, unmatched_count = GREATEST(0, unmatched_count - 1), ambiguous_count = GREATEST(0, ambiguous_count - 1) WHERE id = ?",
            [$id]
        );
        setFlash('success', 'Transaktion erfolgreich dem Kredit zugeordnet.');

    } elseif ($txId && $matchType === 'account' && $accountId) {
        $tx = Database::fetchOne("SELECT * FROM bank_transactions WHERE id = ?", [$txId]);
        if ($tx) {
            $direction = ($tx['direction'] === 'ausgehend') ? 'IN' : 'OUT';

            $ref = strtolower($tx['reference'] ?? '');
            if (str_contains($ref, 'gehalt'))                                              $feeType = 'SALARY';
            elseif (str_contains($ref, 'gebühr'))                                          $feeType = 'WEEKLY';
            elseif (str_contains($ref, 'transfer') || str_contains($ref, 'überweisung'))   $feeType = 'TRANSFER';
            elseif (str_contains($ref, 'einzahlung'))                                      $feeType = 'DEPOSIT';
            elseif (str_contains($ref, 'auszahlung') && $direction === 'OUT')              $feeType = 'WITHDRAWAL';
            else                                                                            $feeType = 'OTHER';

            Database::insert('account_transactions', [
                'account_id'          => $accountId,
                'transaction_date'    => $tx['transaction_date'],
                'transaction_time'    => $tx['transaction_time'],
                'amount'              => $tx['amount'],
                'fee_type'            => $feeType,
                'direction'           => $direction,
                'description'         => $tx['reference'],
                'bank_transaction_id' => $txId,
            ]);
            Database::update('bank_transactions', [
                'match_status'     => 'MATCHED',
                'match_method'     => 'MANUAL',
                'match_confidence' => 1.0,
                'matched_by'       => Auth::userId(),
                'matched_at'       => date('Y-m-d H:i:s'),
            ], 'id = ?', [$txId]);
            AuditLog::log('MANUAL_MATCH', 'bank_transaction', $txId, null, ['account_id' => $accountId]);
            Database::query(
                "UPDATE bank_statement_batches SET matched_count = matched_count + 1, unmatched_count = GREATEST(0, unmatched_count - 1), ambiguous_count = GREATEST(0, ambiguous_count - 1) WHERE id = ?",
                [$id]
            );
            setFlash('success', 'Transaktion erfolgreich dem Konto zugeordnet.');
        }
    }

    header('Location: ' . APP_URL . '/pages/import/batch.php?id=' . $id . '&filter=' . ($_GET['filter'] ?? 'all'));
    exit;
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$where  = "WHERE bt.batch_id = ?";
$params = [$id];

if ($filter === 'matched')        $where .= " AND bt.match_status = 'MATCHED'";
elseif ($filter === 'ambiguous')  $where .= " AND bt.match_status = 'AMBIGUOUS'";
elseif ($filter === 'unmatched')  $where .= " AND bt.match_status = 'UNMATCHED'";
elseif ($filter === 'fee')        $where .= " AND bt.match_status = 'FEE'";

$transactions = Database::fetchAll("
    SELECT bt.*,
           l.file_number, b.first_name, b.last_name,
           ca.account_number as matched_account_number,
           ca.owner_name     as matched_account_owner,
           ca.account_type_label as matched_account_type,
           ca.id             as matched_account_id,
           im.first_name as member_first, im.last_name as member_last, im.member_ref,
           plr.ref_number as pending_ref_number, plr.sender_name as pending_ref_sender,
           plr.status as pending_ref_status
    FROM bank_transactions bt
    LEFT JOIN loans l               ON bt.matched_loan_id = l.id
    LEFT JOIN borrowers b           ON l.borrower_id = b.id
    LEFT JOIN account_transactions at ON at.bank_transaction_id = bt.id
    LEFT JOIN customer_accounts ca  ON at.account_id = ca.id
    LEFT JOIN insurance_members im  ON bt.matched_member_id = im.id
    LEFT JOIN pending_loan_refs plr ON bt.matched_pending_ref_id = plr.id
    {$where}
    ORDER BY bt.id
", $params);

// Kredite & Konten für Modal
$activeLoans = Database::fetchAll("
    SELECT l.id, l.file_number, l.weekly_rate, b.first_name, b.last_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
    ORDER BY b.last_name, b.first_name
");

$activeAccounts = Database::fetchAll("
    SELECT id, account_number, account_name, owner_name, account_type_label
    FROM customer_accounts
    WHERE status = 'ACTIVE'
    ORDER BY owner_name, account_number
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/import/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>Import vom <?= formatDate($batch['batch_date']) ?>
        </h4>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="h3 mb-0"><?= $batch['total_transactions'] ?></div>
                <small class="text-muted">Transaktionen</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="h3 mb-0 text-success"><?= $batch['matched_count'] ?></div>
                <small class="text-muted">Zugeordnet</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="h3 mb-0 text-warning"><?= $batch['ambiguous_count'] ?></div>
                <small class="text-muted">Mehrdeutig</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="h3 mb-0 text-secondary"><?= $batch['unmatched_count'] ?></div>
                <small class="text-muted">Offen</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter-Tabs -->
<div class="btn-group mb-3">
    <a href="?id=<?= $id ?>&filter=all"       class="btn btn-outline-secondary <?= $filter === 'all'       ? 'active' : '' ?>">Alle</a>
    <a href="?id=<?= $id ?>&filter=matched"   class="btn btn-outline-success  <?= $filter === 'matched'   ? 'active' : '' ?>">Zugeordnet</a>
    <a href="?id=<?= $id ?>&filter=ambiguous" class="btn btn-outline-warning  <?= $filter === 'ambiguous' ? 'active' : '' ?>">Mehrdeutig</a>
    <a href="?id=<?= $id ?>&filter=unmatched" class="btn btn-outline-secondary <?= $filter === 'unmatched' ? 'active' : '' ?>">Offen</a>
    <a href="?id=<?= $id ?>&filter=fee"       class="btn btn-outline-info     <?= $filter === 'fee'       ? 'active' : '' ?>">Gebühren</a>
</div>

<!-- Transaktionen -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Betrag</th>
                        <th>Gegenpartei</th>
                        <th>Verwendungszweck</th>
                        <th>Status</th>
                        <th>Zugeordnet zu</th>
                        <th class="text-end">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx):
                        $party = $tx['direction'] === 'ausgehend'
                            ? ($tx['empfaenger_party'] ?: $tx['sender_party'] ?: $tx['sender_name'])
                            : ($tx['sender_party'] ?: $tx['sender_name']);
                        $dirIcon = $tx['direction'] === 'ausgehend'
                            ? '<i class="bi bi-arrow-up-right text-danger" title="Ausgehend"></i>'
                            : '<i class="bi bi-arrow-down-left text-success" title="Eingehend"></i>';
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= formatDate($tx['transaction_date']) ?></td>
                        <td class="text-nowrap">
                            <?= $dirIcon ?>
                            <strong class="<?= $tx['direction'] === 'ausgehend' ? 'text-danger' : 'text-success' ?>">
                                <?= formatMoney($tx['amount']) ?>
                            </strong>
                        </td>
                        <td>
                            <small><?= e($party) ?></small>
                            <?php if ($tx['sender_iban']): ?>
                            <br><small class="text-muted"><code><?= e($tx['sender_iban']) ?></code></small>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= e(substr($tx['reference'] ?? '', 0, 60)) ?></small></td>
                        <td>
                            <?php
                            $statusClass = match($tx['match_status']) {
                                'MATCHED'   => 'bg-success',
                                'AMBIGUOUS' => 'bg-warning text-dark',
                                'FEE'       => 'bg-info text-dark',
                                default     => 'bg-secondary'
                            };
                            $statusText = match($tx['match_status']) {
                                'MATCHED'   => 'Zugeordnet',
                                'AMBIGUOUS' => 'Mehrdeutig',
                                'FEE'       => 'Gebühr',
                                default     => 'Offen'
                            };
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                        <td>
                            <?php if ($tx['matched_loan_id']): ?>
                                <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $tx['matched_loan_id'] ?>">
                                    <?= e($tx['file_number']) ?>
                                </a>
                                <br><small class="text-muted"><?= e($tx['last_name'] . ', ' . $tx['first_name']) ?></small>
                            <?php elseif ($tx['matched_account_id']): ?>
                                <a href="<?= APP_URL ?>/pages/accounts/view.php?id=<?= $tx['matched_account_id'] ?>">
                                    <?= e($tx['matched_account_number']) ?>
                                </a>
                                <br><small class="text-muted"><?= e($tx['matched_account_owner']) ?> · <span class="badge bg-info text-dark" style="font-size:.7em"><?= e($tx['matched_account_type']) ?></span></small>
                            <?php elseif ($tx['matched_pending_ref_id']): ?>
                                <a href="<?= APP_URL ?>/pages/loans/pending_refs.php?highlight=<?= $tx['matched_pending_ref_id'] ?>">
                                    <code><?= e($tx['pending_ref_number']) ?></code>
                                </a>
                                <?php if ($tx['pending_ref_sender']): ?>
                                <br><small class="text-muted"><?= e($tx['pending_ref_sender']) ?></small>
                                <?php endif; ?>
                                <br><span class="badge bg-warning text-dark" style="font-size:.7em">Ausstehender Kredit</span>
                            <?php elseif ($tx['matched_member_id']): ?>
                                <a href="<?= APP_URL ?>/pages/insurance/group/members/view.php?id=<?= $tx['matched_member_id'] ?>">
                                    <?= e($tx['member_last'] . ', ' . $tx['member_first']) ?>
                                </a>
                                <br><small class="text-muted">
                                    <?php if ($tx['member_ref']): ?>
                                    <code><?= e($tx['member_ref']) ?></code> ·
                                    <?php endif; ?>
                                    <span class="badge bg-info text-dark" style="font-size:.7em">KV-Mitglied</span>
                                </small>
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!in_array($tx['match_status'], ['MATCHED', 'FEE']) && Auth::can('import', 'match')): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="openMatchModal(
                                        <?= $tx['id'] ?>,
                                        '<?= e(formatDate($tx['transaction_date'])) ?>',
                                        '<?= e(formatMoney($tx['amount'])) ?>',
                                        <?= json_encode($party) ?>,
                                        <?= json_encode($tx['reference'] ?? '') ?>
                                    )">
                                <i class="bi bi-link-45deg me-1"></i>Zuordnen
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($tx['match_status'] === 'AMBIGUOUS'): ?>
                    <?php $candidates = Database::fetchAll("
                        SELECT tm.*, l.file_number, l.weekly_rate, b.first_name, b.last_name
                        FROM transaction_matches tm
                        JOIN loans l ON tm.loan_id = l.id
                        JOIN borrowers b ON l.borrower_id = b.id
                        WHERE tm.transaction_id = ?
                        ORDER BY tm.confidence DESC
                    ", [$tx['id']]); ?>
                    <tr class="table-warning">
                        <td colspan="7" class="ps-5">
                            <small class="text-muted">Mögliche Zuordnungen:</small>
                            <div class="mt-1">
                                <?php foreach ($candidates as $c): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="transaction_id" value="<?= $tx['id'] ?>">
                                    <input type="hidden" name="match_type"     value="loan">
                                    <input type="hidden" name="loan_id"        value="<?= $c['loan_id'] ?>">
                                    <input type="hidden" name="account_id"     value="">
                                    <button type="submit" class="btn btn-sm btn-outline-success me-2 mb-1">
                                        <i class="bi bi-check me-1"></i>
                                        <?= e($c['file_number']) ?> –
                                        <?= e($c['last_name']) ?>, <?= e($c['first_name']) ?>
                                        (<?= formatMoney($c['weekly_rate']) ?>, <?= round($c['confidence'] * 100) ?>%)
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Zuordnungs-Modal -->
<div class="modal fade" id="matchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Transaktion zuordnen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="matchForm">
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="transaction_id" id="modal_tx_id">
                    <input type="hidden" name="match_type"     id="modal_match_type" value="loan">
                    <input type="hidden" name="loan_id"        id="modal_loan_id"    value="">
                    <input type="hidden" name="account_id"     id="modal_account_id" value="">

                    <!-- Transaktions-Info -->
                    <div class="alert alert-secondary py-2 mb-3 small" id="modal_tx_info"></div>

                    <!-- Typ-Umschalter -->
                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-primary flex-fill" id="btn_type_loan"
                                onclick="setMatchType('loan')">
                            <i class="bi bi-file-text me-1"></i>Kredit
                        </button>
                        <button type="button" class="btn btn-outline-primary flex-fill" id="btn_type_account"
                                onclick="setMatchType('account')">
                            <i class="bi bi-bank me-1"></i>Konto
                        </button>
                    </div>

                    <!-- Suche -->
                    <div class="input-group mb-2">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="modal_search"
                               placeholder="Name, Kontonummer, Aktenzeichen…"
                               oninput="filterResults()" autocomplete="off">
                    </div>

                    <!-- Ergebnisliste -->
                    <div id="modal_results"
                         style="max-height:320px; overflow-y:auto; border:1px solid var(--bs-border-color); border-radius:var(--bs-border-radius);">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success" id="modal_submit" disabled>
                        <i class="bi bi-check2 me-1"></i>Zuordnen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const loans    = <?= json_encode(array_values($activeLoans)) ?>;
const accounts = <?= json_encode(array_values($activeAccounts)) ?>;
let matchModal  = null;
let currentType = 'loan';

function openMatchModal(txId, date, amount, party, reference) {
    document.getElementById('modal_tx_id').value      = txId;
    document.getElementById('modal_loan_id').value    = '';
    document.getElementById('modal_account_id').value = '';
    document.getElementById('modal_submit').disabled  = true;
    document.getElementById('modal_tx_info').innerHTML =
        `<strong>${date}</strong> &nbsp;·&nbsp; <strong>${amount}</strong> &nbsp;·&nbsp; ${escHtml(party)}` +
        (reference ? `<br><span class="text-muted">${escHtml(reference)}</span>` : '');

    setMatchType('loan');

    if (!matchModal) matchModal = new bootstrap.Modal(document.getElementById('matchModal'));
    matchModal.show();
    setTimeout(() => document.getElementById('modal_search').focus(), 300);
}

function setMatchType(type) {
    currentType = type;
    document.getElementById('modal_match_type').value = type;
    document.getElementById('btn_type_loan').className    = 'btn flex-fill ' + (type === 'loan'    ? 'btn-primary' : 'btn-outline-primary');
    document.getElementById('btn_type_account').className = 'btn flex-fill ' + (type === 'account' ? 'btn-primary' : 'btn-outline-primary');
    document.getElementById('modal_search').value     = '';
    document.getElementById('modal_loan_id').value    = '';
    document.getElementById('modal_account_id').value = '';
    document.getElementById('modal_submit').disabled  = true;
    filterResults();
}

function filterResults() {
    const q         = document.getElementById('modal_search').value.toLowerCase().trim();
    const container = document.getElementById('modal_results');
    const items     = currentType === 'loan' ? loans : accounts;

    const filtered = q.length === 0 ? items : items.filter(item => {
        if (currentType === 'loan') {
            return (item.file_number + ' ' + item.last_name + ' ' + item.first_name).toLowerCase().includes(q);
        } else {
            return (item.account_number + ' ' + (item.owner_name||'') + ' ' + (item.account_name||'')).toLowerCase().includes(q);
        }
    });

    if (filtered.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3 mb-0"><i class="bi bi-search me-2"></i>Keine Ergebnisse</p>';
        return;
    }

    container.innerHTML = filtered.slice(0, 100).map(item => {
        if (currentType === 'loan') {
            return `<div class="result-item px-3 py-2 d-flex justify-content-between align-items-center"
                        style="cursor:pointer;border-bottom:1px solid var(--bs-border-color);"
                        data-id="${item.id}" onclick="selectItem(${item.id})">
                <div>
                    <strong>${escHtml(item.file_number)}</strong>
                    &nbsp;–&nbsp;${escHtml(item.last_name)}, ${escHtml(item.first_name)}
                </div>
                <span class="badge bg-secondary">${escHtml(String(item.weekly_rate))} $/Woche</span>
            </div>`;
        } else {
            return `<div class="result-item px-3 py-2 d-flex justify-content-between align-items-center"
                        style="cursor:pointer;border-bottom:1px solid var(--bs-border-color);"
                        data-id="${item.id}" onclick="selectItem(${item.id})">
                <div>
                    <strong>${escHtml(item.account_number)}</strong>
                    &nbsp;–&nbsp;${escHtml(item.owner_name || '–')}
                </div>
                <span class="badge bg-info text-dark">${escHtml(item.account_type_label)}</span>
            </div>`;
        }
    }).join('');
}

function selectItem(id) {
    document.querySelectorAll('#modal_results .result-item').forEach(el => {
        const active = parseInt(el.dataset.id) === id;
        el.style.background = active ? 'var(--bs-primary)' : '';
        el.style.color      = active ? '#fff' : '';
    });

    if (currentType === 'loan') {
        document.getElementById('modal_loan_id').value    = id;
        document.getElementById('modal_account_id').value = '';
    } else {
        document.getElementById('modal_account_id').value = id;
        document.getElementById('modal_loan_id').value    = '';
    }
    document.getElementById('modal_submit').disabled = false;
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('matchModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('modal_results').innerHTML = '';
    document.getElementById('modal_search').value = '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
