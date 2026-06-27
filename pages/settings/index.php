<?php
ob_start();
$pageTitle = 'Einstellungen';
require_once __DIR__ . '/../../includes/header.php';

if (!Auth::hasRole('director')) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $key   = $_POST['policy_key']   ?? '';
    $value = $_POST['policy_value'] ?? '';
    $bid   = currentBankId();

    if ($key && $value !== '') {
        Database::query(
            "UPDATE loan_policies SET valid_until = CURDATE() WHERE policy_key = ? AND bank_id = ? AND valid_until IS NULL",
            [$key, $bid]
        );
        Database::insert('loan_policies', [
            'bank_id'      => $bid,
            'policy_key'   => $key,
            'policy_value' => $value,
            'valid_from'   => date('Y-m-d')
        ]);
        AuditLog::log('UPDATE_POLICY', 'loan_policy', 0, null, ['key' => $key, 'value' => $value]);
        setFlash('success', 'Einstellung gespeichert.');
    }
    header('Location: ' . APP_URL . '/pages/settings/index.php');
    exit;
}

$bid      = currentBankId();
$policies = Database::fetchAll(
    "SELECT * FROM loan_policies WHERE bank_id = ? AND valid_until IS NULL ORDER BY policy_key",
    [$bid]
);
// Policy-Map für schnellen Zugriff
$pMap = [];
foreach ($policies as $p) { $pMap[$p['policy_key']] = $p; }

// Gruppen-Definition: [key => [label, type, hint]]
// type: percent (0.10 → 10%), money ($), number, percent_direct (direkt %)
$groups = [
    'Zinssätze' => [
        'icon' => 'bi-percent',
        'color' => 'primary',
        'policies' => [
            'INTEREST_RATE_AUTO'     => ['label' => 'Zinssatz Autokredit',      'type' => 'percent', 'hint' => 'Vorschlagswert im Kreditformular'],
            'INTEREST_RATE_PRIVATE'  => ['label' => 'Zinssatz Privatkredit',    'type' => 'percent', 'hint' => 'Vorschlagswert im Kreditformular'],
            'INTEREST_RATE_BUSINESS' => ['label' => 'Zinssatz Geschäftskredit', 'type' => 'percent', 'hint' => 'Vorschlagswert im Kreditformular'],
            'PROCESSING_FEE_RATE'    => ['label' => 'Bearbeitungsgebühr',       'type' => 'percent', 'hint' => 'In % der Kreditsumme, fällig bei Abschluss'],
        ],
    ],
    'Laufzeiten' => [
        'icon' => 'bi-calendar-week',
        'color' => 'info',
        'policies' => [
            'AUTO_MIN_TERM_WEEKS'      => ['label' => 'Auto: Min. Laufzeit (Wochen)',       'type' => 'number', 'hint' => ''],
            'AUTO_MAX_TERM_WEEKS'      => ['label' => 'Auto: Max. Laufzeit (Wochen)',       'type' => 'number', 'hint' => ''],
            'PRIVATE_MIN_TERM_WEEKS'   => ['label' => 'Privat: Min. Laufzeit (Wochen)',     'type' => 'number', 'hint' => ''],
            'PRIVATE_MAX_TERM_WEEKS'   => ['label' => 'Privat: Max. Laufzeit (Wochen)',     'type' => 'number', 'hint' => ''],
            'BUSINESS_MIN_TERM_WEEKS'  => ['label' => 'Geschäft: Min. Laufzeit (Wochen)',   'type' => 'number', 'hint' => ''],
            'BUSINESS_MAX_TERM_WEEKS'  => ['label' => 'Geschäft: Max. Laufzeit (Wochen)',   'type' => 'number', 'hint' => ''],
        ],
    ],
    'Kreditgrenzen & Eigenkapital' => [
        'icon' => 'bi-bank',
        'color' => 'success',
        'policies' => [
            'MIN_LOAN_AMOUNT'              => ['label' => 'Minimaler Kreditbetrag ($)',         'type' => 'money',  'hint' => ''],
            'MAX_LOAN_AMOUNT'              => ['label' => 'Maximaler Kreditbetrag ($)',         'type' => 'money',  'hint' => 'Obergrenze für alle Kredittypen'],
            'MAX_SMALL_LOAN_AMOUNT'        => ['label' => 'Kompetenzgrenze Sachbearbeiter ($)', 'type' => 'money',  'hint' => 'Darüber ist Direktions-Genehmigung nötig'],
            'MAX_ACTIVE_LOANS_PER_CUSTOMER'=> ['label' => 'Max. aktive Kredite pro Kunde',     'type' => 'number', 'hint' => '0 = unbegrenzt'],
            'AUTO_MIN_DOWNPAY_RATIO'       => ['label' => 'Auto: Min. Eigenkapital (%)',        'type' => 'percent','hint' => 'Pflichtanteil am Kaufpreis'],
            'AUTO_MAX_DOWNPAY_RATIO'       => ['label' => 'Auto: Max. Eigenkapital (%)',        'type' => 'percent','hint' => 'Obergrenze Eigenkapitalanteil'],
            'MAX_RATE_INCOME_RATIO'        => ['label' => 'Max. Rate / Einkommen (%)',          'type' => 'percent','hint' => 'Wochenrate darf Einkommen nicht überschreiten'],
        ],
    ],
    'Mahnwesen' => [
        'icon' => 'bi-exclamation-triangle',
        'color' => 'warning',
        'policies' => [
            'DUNNING_L1_DAYS'        => ['label' => 'Tage bis Mahnstufe 1',       'type' => 'number', 'hint' => 'Nach Fälligkeitsdatum'],
            'DUNNING_L2_DAYS'        => ['label' => 'Tage bis Mahnstufe 2',       'type' => 'number', 'hint' => ''],
            'TERMINATION_DAYS'       => ['label' => 'Tage bis Kündigung',         'type' => 'number', 'hint' => ''],
            'DEFAULT_LATE_WEEKLY_RATE'=> ['label' => 'Verzugszins pro Woche (%)', 'type' => 'percent','hint' => 'Auf offene Raten'],
            'DUNNING_FEE_L1'         => ['label' => 'Mahngebühr Stufe 1 ($)',     'type' => 'money',  'hint' => 'Fixgebühr zusätzlich zum Verzugszins'],
            'DUNNING_FEE_L2'         => ['label' => 'Mahngebühr Stufe 2 ($)',     'type' => 'money',  'hint' => ''],
            'REMINDER_DAYS_BEFORE'   => ['label' => 'Erinnerung (Tage vorher)',   'type' => 'number', 'hint' => 'Zahlungserinnerung vor Fälligkeit'],
        ],
    ],
];

function displayValue(string $type, string $raw): string {
    return match($type) {
        'percent' => number_format(floatval($raw) * 100, 1, ',', '.') . ' %',
        'money'   => number_format(floatval($raw), 0, ',', '.') . ' $',
        default   => $raw,
    };
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-gear me-2"></i>Einstellungen</h4>
    <span class="badge bg-secondary"><?= e(Auth::bank()['name'] ?? '') ?></span>
</div>

<div class="row g-4">
    <!-- Linke Spalte: Policy-Gruppen -->
    <div class="col-lg-8">
        <?php foreach ($groups as $groupName => $group): ?>
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi <?= $group['icon'] ?> text-<?= $group['color'] ?>"></i>
                <strong><?= e($groupName) ?></strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:45%">Einstellung</th>
                            <th>Aktueller Wert</th>
                            <th>Gültig seit</th>
                            <th class="text-end">Ändern</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['policies'] as $key => $info):
                            $p = $pMap[$key] ?? null;
                        ?>
                        <tr class="<?= !$p ? 'table-warning bg-opacity-10' : '' ?>">
                            <td>
                                <div class="fw-semibold"><?= e($info['label']) ?></div>
                                <small class="text-muted font-monospace"><?= e($key) ?></small>
                                <?php if ($info['hint']): ?>
                                <br><small class="text-muted"><?= e($info['hint']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p): ?>
                                <span class="fw-bold text-<?= $group['color'] ?>">
                                    <?= displayValue($info['type'], $p['policy_value']) ?>
                                </span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Nicht gesetzt</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?= $p ? formatDate($p['valid_from']) : '–' ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editModal"
                                        data-key="<?= e($key) ?>"
                                        data-value="<?= e($p['policy_value'] ?? '') ?>"
                                        data-label="<?= e($info['label']) ?>"
                                        data-type="<?= e($info['type']) ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Rechte Spalte: Info + Links -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">System-Info</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted ps-3">Version</td>
                        <td><?= APP_VERSION ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">PHP</td>
                        <td><?= PHP_VERSION ?></td>
                    </tr>
                    <?php
                    $stats = Database::fetchOne("
                        SELECT
                            (SELECT COUNT(*) FROM loans WHERE bank_id = ?) as loans,
                            (SELECT COUNT(*) FROM borrowers WHERE bank_id = ?) as borrowers,
                            (SELECT COUNT(*) FROM users WHERE bank_id = ? AND is_active = 1) as users,
                            (SELECT COUNT(*) FROM customer_accounts WHERE bank_id = ?) as accounts
                    ", [$bid, $bid, $bid, $bid]);
                    ?>
                    <tr><td class="text-muted ps-3">Kredite</td><td><?= $stats['loans'] ?></td></tr>
                    <tr><td class="text-muted ps-3">Kreditnehmer</td><td><?= $stats['borrowers'] ?></td></tr>
                    <tr><td class="text-muted ps-3">Konten</td><td><?= $stats['accounts'] ?></td></tr>
                    <tr><td class="text-muted ps-3">Benutzer</td><td><?= $stats['users'] ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Schnelllinks</div>
            <div class="card-body d-grid gap-2">
                <a href="<?= APP_URL ?>/pages/users/index.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-people-fill me-2"></i>Benutzerverwaltung
                </a>
                <a href="<?= APP_URL ?>/pages/templates/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-text me-2"></i>Textvorlagen
                </a>
                <?php if (Auth::isSuperAdmin()): ?>
                <a href="<?= APP_URL ?>/pages/admin/index.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-shield-lock me-2"></i>Administration
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-warning">
            <div class="card-header text-warning"><i class="bi bi-info-circle me-1"></i>Hinweise</div>
            <div class="card-body small text-muted">
                <p class="mb-2">Alle Einstellungen gelten <strong>nur für diese Bank</strong>. Jede Bank hat eigene Policies.</p>
                <p class="mb-2">Prozentwerte werden als Dezimalzahl gespeichert (0.10 = 10%).</p>
                <p class="mb-0">Änderungen werden mit Datum protokolliert und sind rückwirkend einsehbar.</p>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="policy_key" id="modal_key">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal_label"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-1">
                        <label class="form-label">Neuer Wert</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="policy_value" id="modal_value" required>
                            <span class="input-group-text" id="modal_suffix"></span>
                        </div>
                        <div class="form-text" id="modal_hint"></div>
                    </div>
                    <div id="modal_preview" class="mt-3 p-2 rounded text-center" style="background:rgba(255,255,255,0.05);display:none;">
                        <small class="text-muted">Vorschau: </small>
                        <strong id="modal_preview_val"></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    const btn     = e.relatedTarget;
    const type    = btn.dataset.type;
    const val     = btn.dataset.value;

    document.getElementById('modal_key').value   = btn.dataset.key;
    document.getElementById('modal_label').textContent = btn.dataset.label;
    document.getElementById('modal_value').value = val;

    const suffix  = document.getElementById('modal_suffix');
    const hint    = document.getElementById('modal_hint');
    const preview = document.getElementById('modal_preview');
    const preVal  = document.getElementById('modal_preview_val');

    if (type === 'percent') {
        suffix.textContent = '= ' + (parseFloat(val||0)*100).toFixed(1) + ' %';
        hint.textContent   = 'Eingabe als Dezimalzahl: 0.10 = 10 %, 0.30 = 30 %';
        preview.style.display = '';
    } else if (type === 'money') {
        suffix.textContent = '$';
        hint.textContent   = '';
        preview.style.display = 'none';
    } else {
        suffix.textContent = '';
        hint.textContent   = '';
        preview.style.display = 'none';
    }

    document.getElementById('modal_value').addEventListener('input', function() {
        if (type === 'percent') {
            const v = parseFloat(this.value) || 0;
            suffix.textContent = '= ' + (v * 100).toFixed(1) + ' %';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
