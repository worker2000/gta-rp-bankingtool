<?php
ob_start();
/**
 * PSB Kreditverwaltung - Offene Transaktionen
 */
$pageTitle = 'Offene Transaktionen';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../classes/Matching.php';
Auth::requirePermission('import', 'match');

// Manuelle Zuordnung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
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
        setFlash('success', 'Transaktion erfolgreich dem Kredit zugeordnet.');

    } elseif ($txId && $matchType === 'account' && $accountId) {
        $tx = Database::fetchOne("SELECT * FROM bank_transactions WHERE id = ?", [$txId]);
        if ($tx) {
            // ausgehend von Bank = eingehend auf Konto
            $direction = ($tx['direction'] === 'ausgehend') ? 'IN' : 'OUT';

            $ref = strtolower($tx['reference'] ?? '');
            if (str_contains($ref, 'gehalt'))                          $feeType = 'SALARY';
            elseif (str_contains($ref, 'gebühr'))                      $feeType = 'WEEKLY';
            elseif (str_contains($ref, 'transfer') || str_contains($ref, 'überweisung')) $feeType = 'TRANSFER';
            elseif (str_contains($ref, 'einzahlung'))                  $feeType = 'DEPOSIT';
            elseif (str_contains($ref, 'auszahlung') && $direction === 'OUT') $feeType = 'WITHDRAWAL';
            else                                                        $feeType = 'OTHER';

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
            setFlash('success', 'Transaktion erfolgreich dem Konto zugeordnet.');
        }
    }

    header('Location: ' . APP_URL . '/pages/import/pending.php');
    exit;
}

$bankId = currentBankId();

// Alle offenen Transaktionen
$transactions = Database::fetchAll("
    SELECT bt.*, bsb.batch_date
    FROM bank_transactions bt
    JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
    WHERE bt.match_status IN ('UNMATCHED', 'AMBIGUOUS')
      AND bsb.bank_id = ?
    ORDER BY bt.transaction_date DESC
", [$bankId]);

// Aktive Kredite für Modal
$activeLoans = Database::fetchAll("
    SELECT l.id, l.file_number, l.weekly_rate, b.first_name, b.last_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
      AND l.bank_id = ?
    ORDER BY b.last_name, b.first_name
", [$bankId]);

// Aktive Konten für Modal
$activeAccounts = Database::fetchAll("
    SELECT id, account_number, account_name, owner_name, account_type_label
    FROM customer_accounts
    WHERE status = 'ACTIVE'
      AND bank_id = ?
    ORDER BY owner_name, account_number
", [$bankId]);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-question-circle me-2"></i>Offene Transaktionen (<?= count($transactions) ?>)</h4>
    <a href="<?= APP_URL ?>/pages/import/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<?php if (empty($transactions)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <i class="bi bi-check-circle text-success"></i>
            <p class="mb-0">Alle Transaktionen sind zugeordnet!</p>
        </div>
    </div>
</div>
<?php else: ?>

<!-- Tabellenfilter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="tableSearch"
                   placeholder="Filtern nach Datum, Betrag, Gegenpartei, Verwendungszweck…"
                   oninput="filterTable()">
            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('tableSearch').value=''; filterTable();">
                <i class="bi bi-x"></i>
            </button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="txTable">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Betrag</th>
                        <th>Gegenpartei</th>
                        <th>Verwendungszweck</th>
                        <th>Status</th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx):
                        // Relevante Gegenpartei je nach Richtung
                        $party = $tx['direction'] === 'ausgehend'
                            ? ($tx['empfaenger_party'] ?: $tx['sender_party'])
                            : ($tx['sender_party'] ?: $tx['sender_name']);
                        $dirIcon = $tx['direction'] === 'ausgehend'
                            ? '<i class="bi bi-arrow-up-right text-danger me-1" title="Ausgehend"></i>'
                            : '<i class="bi bi-arrow-down-left text-success me-1" title="Eingehend"></i>';
                    ?>
                    <tr data-search="<?= strtolower(e($tx['transaction_date'] . ' ' . $tx['amount'] . ' ' . $party . ' ' . $tx['reference'])) ?>">
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
                        <td><small class="text-muted"><?= e($tx['reference']) ?></small></td>
                        <td>
                            <span class="badge <?= $tx['match_status'] === 'AMBIGUOUS' ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                                <?= $tx['match_status'] === 'AMBIGUOUS' ? 'Mehrdeutig' : 'Offen' ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="openMatchModal(
                                    <?= $tx['id'] ?>,
                                    '<?= e(formatDate($tx['transaction_date'])) ?>',
                                    '<?= e(formatMoney($tx['amount'])) ?>',
                                    <?= htmlspecialchars(json_encode($party), ENT_QUOTES) ?>,
                                    <?= htmlspecialchars(json_encode($tx['reference']), ENT_QUOTES) ?>
                                )">
                                <i class="bi bi-link-45deg me-1"></i>Zuordnen
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
let matchModal = null;
let currentType = 'loan';

function openMatchModal(txId, date, amount, party, reference) {
    document.getElementById('modal_tx_id').value    = txId;
    document.getElementById('modal_loan_id').value  = '';
    document.getElementById('modal_account_id').value = '';
    document.getElementById('modal_submit').disabled = true;
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
    document.getElementById('btn_type_loan').className    = 'btn flex-fill ' + (type === 'loan'    ? 'btn-primary'         : 'btn-outline-primary');
    document.getElementById('btn_type_account').className = 'btn flex-fill ' + (type === 'account' ? 'btn-primary'         : 'btn-outline-primary');
    document.getElementById('modal_search').value = '';
    document.getElementById('modal_loan_id').value    = '';
    document.getElementById('modal_account_id').value = '';
    document.getElementById('modal_submit').disabled = true;
    filterResults();
}

function filterResults() {
    const q = document.getElementById('modal_search').value.toLowerCase().trim();
    const container = document.getElementById('modal_results');
    const items = currentType === 'loan' ? loans : accounts;

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
                        style="cursor:pointer; border-bottom:1px solid var(--bs-border-color);"
                        data-id="${item.id}" onclick="selectItem(${item.id})">
                <div>
                    <strong>${escHtml(item.file_number)}</strong>
                    &nbsp;–&nbsp; ${escHtml(item.last_name)}, ${escHtml(item.first_name)}
                </div>
                <span class="badge bg-secondary">${escHtml(item.weekly_rate)} $/Woche</span>
            </div>`;
        } else {
            return `<div class="result-item px-3 py-2 d-flex justify-content-between align-items-center"
                        style="cursor:pointer; border-bottom:1px solid var(--bs-border-color);"
                        data-id="${item.id}" onclick="selectItem(${item.id})">
                <div>
                    <strong>${escHtml(item.account_number)}</strong>
                    &nbsp;–&nbsp; ${escHtml(item.owner_name || '–')}
                </div>
                <span class="badge bg-info text-dark">${escHtml(item.account_type_label)}</span>
            </div>`;
        }
    }).join('');
}

function selectItem(id) {
    // Highlight
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

// Tabellen-Suche
function filterTable() {
    const q = document.getElementById('tableSearch').value.toLowerCase();
    document.querySelectorAll('#txTable tbody tr').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Modal beim Schließen resetten
document.getElementById('matchModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('modal_results').innerHTML = '';
    document.getElementById('modal_search').value = '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
