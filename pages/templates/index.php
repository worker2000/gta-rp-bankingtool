<?php
ob_start();
/**
 * Textverwaltung – Vorlagen (Templates)
 */
$pageTitle = 'Textvorlagen';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

if (!Auth::hasRole('director') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$isSuperAdmin  = Auth::isSuperAdmin();
$currentBankId = currentBankId();

$typeLabels = [
    'REMINDER'          => 'Zahlungserinnerung',
    'DUNNING_L1'        => 'Mahnung Stufe 1',
    'DUNNING_L2'        => 'Mahnung Stufe 2',
    'TERMINATION'       => 'Kündigung',
    'CONFIRMATION'      => 'Bestätigung',
    'OFFER_BUSINESS'    => 'Kreditangebot Unternehmen',
    'CONTRACT_BUSINESS' => 'Kreditvertrag Unternehmen',
    'CONTRACT_VEHICLE'  => 'Kreditvertrag Fahrzeug',
    'OTHER'             => 'Sonstige',
];
$typeIcons = [
    'REMINDER'          => 'bi-bell',
    'DUNNING_L1'        => 'bi-exclamation-triangle',
    'DUNNING_L2'        => 'bi-exclamation-octagon',
    'TERMINATION'       => 'bi-x-octagon',
    'CONFIRMATION'      => 'bi-check-circle',
    'OFFER_BUSINESS'    => 'bi-briefcase',
    'CONTRACT_BUSINESS' => 'bi-file-earmark-ruled',
    'CONTRACT_VEHICLE'  => 'bi-car-front',
    'OTHER'             => 'bi-file-text',
];

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name    = trim($_POST['name'] ?? '');
        $type    = $_POST['type'] ?? 'OTHER';
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $bankId  = $isSuperAdmin ? intval($_POST['bank_id'] ?? $currentBankId) : $currentBankId;

        if (!array_key_exists($type, $typeLabels)) $type = 'OTHER';

        // Platzhalter aus Body extrahieren
        preg_match_all('/\{([A-ZÄÖÜ_0-9]+)\}/', $body . ' ' . $subject, $m);
        $placeholders = array_values(array_unique($m[1]));

        if ($name && $subject && $body) {
            if ($action === 'create') {
                Database::insert('templates', [
                    'bank_id'      => $bankId,
                    'name'         => $name,
                    'type'         => $type,
                    'subject'      => $subject,
                    'body'         => $body,
                    'placeholders' => json_encode($placeholders),
                    'created_by'   => Auth::userId(),
                ]);
                setFlash('success', 'Vorlage "' . $name . '" angelegt.');
            } else {
                $tplId = intval($_POST['template_id'] ?? 0);
                $tpl   = Database::fetchOne(
                    "SELECT id, bank_id, version FROM templates WHERE id = ?", [$tplId]
                );
                if ($tpl && ($isSuperAdmin || $tpl['bank_id'] === $currentBankId)) {
                    Database::update('templates', [
                        'name'         => $name,
                        'type'         => $type,
                        'subject'      => $subject,
                        'body'         => $body,
                        'placeholders' => json_encode($placeholders),
                        'version'      => ($tpl['version'] ?? 1) + 1,
                    ], 'id = ?', [$tplId]);
                    setFlash('success', 'Vorlage aktualisiert.');
                }
            }
        } else {
            setFlash('error', 'Bitte Name, Betreff und Text ausfüllen.');
        }

    } elseif ($action === 'toggle') {
        $tplId = intval($_POST['template_id'] ?? 0);
        $tpl   = Database::fetchOne("SELECT id, bank_id, is_active, name FROM templates WHERE id = ?", [$tplId]);
        if ($tpl && ($isSuperAdmin || $tpl['bank_id'] === $currentBankId)) {
            Database::update('templates', ['is_active' => !$tpl['is_active']], 'id = ?', [$tplId]);
            setFlash('success', 'Vorlage "' . $tpl['name'] . '" ' . ($tpl['is_active'] ? 'deaktiviert' : 'aktiviert') . '.');
        }

    } elseif ($action === 'delete') {
        $tplId = intval($_POST['template_id'] ?? 0);
        $tpl   = Database::fetchOne("SELECT id, bank_id, name FROM templates WHERE id = ?", [$tplId]);
        if ($tpl && ($isSuperAdmin || $tpl['bank_id'] === $currentBankId)) {
            Database::query("DELETE FROM templates WHERE id = ?", [$tplId]);
            setFlash('success', 'Vorlage "' . $tpl['name'] . '" gelöscht.');
        }
    }

    header('Location: ' . APP_URL . '/pages/templates/index.php' . (isset($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit;
}

// Filter
$typeFilter = $_GET['type'] ?? '';
$bankFilter = $isSuperAdmin
    ? ($typeFilter ? "WHERE t.type = ?" : "WHERE 1=1")
    : ($typeFilter ? "WHERE t.bank_id = ? AND t.type = ?" : "WHERE t.bank_id = ?");

$params = $isSuperAdmin
    ? ($typeFilter ? [$typeFilter] : [])
    : ($typeFilter ? [$currentBankId, $typeFilter] : [$currentBankId]);

$templates = Database::fetchAll("
    SELECT t.*, b.short_code as bank_short, u.full_name as created_by_name
    FROM templates t
    LEFT JOIN banks b ON t.bank_id = b.id
    LEFT JOIN users u ON t.created_by = u.id
    {$bankFilter}
    ORDER BY t.type, t.name
", $params);

$banks = $isSuperAdmin ? Database::fetchAll("SELECT * FROM banks ORDER BY id") : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-file-text me-2"></i>Textvorlagen</h4>
        <p class="text-muted small mb-0">
            Vorlagen für Mahnungen, Bestätigungen und andere Schreiben
        </p>
    </div>
    <button class="btn btn-primary" onclick="openCreateModal()">
        <i class="bi bi-plus-circle me-2"></i>Neue Vorlage
    </button>
</div>

<!-- Typ-Filter -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="?<?= $isSuperAdmin && isset($_GET['bank']) ? 'bank=' . urlencode($_GET['bank']) . '&' : '' ?>"
       class="btn btn-sm <?= !$typeFilter ? 'btn-secondary' : 'btn-outline-secondary' ?>">Alle</a>
    <?php foreach ($typeLabels as $val => $label): ?>
    <a href="?type=<?= $val ?>"
       class="btn btn-sm <?= $typeFilter === $val ? 'btn-secondary' : 'btn-outline-secondary' ?>">
        <i class="bi <?= $typeIcons[$val] ?> me-1"></i><?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Vorlagen-Liste -->
<?php if (empty($templates)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-file-text fs-1 d-block mb-3 opacity-25"></i>
        <p class="mb-0">Keine Vorlagen gefunden.</p>
        <button class="btn btn-primary mt-3" onclick="openCreateModal()">
            <i class="bi bi-plus-circle me-2"></i>Erste Vorlage anlegen
        </button>
    </div>
</div>
<?php else: ?>

<?php
// Nach Typ gruppieren
$grouped = [];
foreach ($templates as $t) {
    $grouped[$t['type']][] = $t;
}
?>

<?php foreach ($grouped as $type => $items): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi <?= $typeIcons[$type] ?? 'bi-file-text' ?>"></i>
        <strong><?= $typeLabels[$type] ?? $type ?></strong>
        <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark opacity-75">
                    <tr>
                        <th>Name</th>
                        <th>Betreff</th>
                        <?php if ($isSuperAdmin): ?>
                        <th>Bank</th>
                        <?php endif; ?>
                        <th>Platzhalter</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $t): ?>
                    <tr class="<?= !$t['is_active'] ? 'opacity-50' : '' ?>">
                        <td>
                            <strong><?= e($t['name']) ?></strong>
                            <?php if ($t['created_by_name']): ?>
                            <br><small class="text-muted"><?= e($t['created_by_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($t['subject']) ?></td>
                        <?php if ($isSuperAdmin): ?>
                        <td>
                            <span class="badge <?= $t['bank_id'] == 1 ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                <?= e($t['bank_short'] ?? '?') ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php
                            $ph = json_decode($t['placeholders'] ?? '[]', true) ?? [];
                            foreach (array_slice($ph, 0, 4) as $p):
                            ?>
                            <code class="small">{<?= e($p) ?>}</code>
                            <?php endforeach; ?>
                            <?php if (count($ph) > 4): ?>
                            <span class="text-muted small">+<?= count($ph) - 4 ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">v<?= $t['version'] ?? 1 ?></td>
                        <td>
                            <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $t['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary btn-action"
                                    title="Bearbeiten"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                        'id'      => $t['id'],
                                        'name'    => $t['name'],
                                        'type'    => $t['type'],
                                        'subject' => $t['subject'],
                                        'body'    => $t['body'],
                                        'bank_id' => $t['bank_id'],
                                    ]), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary btn-action"
                                    title="Vorschau"
                                    onclick="openPreview(<?= htmlspecialchars(json_encode([
                                        'name'    => $t['name'],
                                        'subject' => $t['subject'],
                                        'body'    => $t['body'],
                                    ]), ENT_QUOTES) ?>)">
                                <i class="bi bi-eye"></i>
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-<?= $t['is_active'] ? 'warning' : 'success' ?> btn-action"
                                        title="<?= $t['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>">
                                    <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Vorlage „<?= e(addslashes($t['name'])) ?>" wirklich löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-action" title="Löschen">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Erstellen/Bearbeiten-Modal -->
<div class="modal fade" id="tplModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="tplForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="tpl_action" value="create">
                <input type="hidden" name="template_id" id="tpl_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="tplModalTitle">
                        <i class="bi bi-file-text me-2"></i>Neue Vorlage
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" id="tpl_name"
                                   placeholder="z.B. Mahnung Fahrzeugfinanzierung" required>
                        </div>
                        <div class="col-md-<?= $isSuperAdmin ? '3' : '6' ?>">
                            <label class="form-label">Typ *</label>
                            <select class="form-select" name="type" id="tpl_type" required>
                                <?php foreach ($typeLabels as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isSuperAdmin): ?>
                        <div class="col-md-3">
                            <label class="form-label">Bank *</label>
                            <select class="form-select" name="bank_id" id="tpl_bank_id" required>
                                <?php foreach ($banks as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['short_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Betreff *</label>
                            <input type="text" class="form-control" name="subject" id="tpl_subject"
                                   placeholder="z.B. Mahnung – Aktenzeichen {AKTENZEICHEN}" required
                                   oninput="detectPlaceholders()">
                        </div>
                        <div class="col-12">
                            <label class="form-label d-flex justify-content-between">
                                <span>Text *</span>
                                <span class="text-muted small">Platzhalter: <code>{NAME}</code>, <code>{DATUM}</code> usw.</span>
                            </label>
                            <textarea class="form-control font-monospace" name="body" id="tpl_body"
                                      rows="16" required oninput="detectPlaceholders()"
                                      placeholder="Sehr geehrte(r) {NAME},&#10;&#10;..."></textarea>
                        </div>
                        <div class="col-12">
                            <div id="placeholder_display" class="d-flex gap-1 flex-wrap" style="min-height:24px"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-floppy me-1"></i>Speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Vorschau-Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Vorschau</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small">Betreff:</div>
                <div class="fw-semibold mb-3" id="preview_subject"></div>
                <hr>
                <pre id="preview_body" class="mb-0" style="white-space:pre-wrap;font-family:inherit;font-size:.9rem"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<script>
let tplModal = null, previewModal = null;

function openCreateModal() {
    document.getElementById('tplModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Neue Vorlage';
    document.getElementById('tpl_action').value  = 'create';
    document.getElementById('tpl_id').value      = '';
    document.getElementById('tpl_name').value    = '';
    document.getElementById('tpl_type').value    = 'OTHER';
    document.getElementById('tpl_subject').value = '';
    document.getElementById('tpl_body').value    = '';
    <?php if ($isSuperAdmin): ?>
    document.getElementById('tpl_bank_id').value = '<?= $currentBankId ?>';
    <?php endif; ?>
    detectPlaceholders();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('tplModal')).show();
}

function openEditModal(t) {
    document.getElementById('tplModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Vorlage bearbeiten';
    document.getElementById('tpl_action').value  = 'update';
    document.getElementById('tpl_id').value      = t.id;
    document.getElementById('tpl_name').value    = t.name;
    document.getElementById('tpl_type').value    = t.type;
    document.getElementById('tpl_subject').value = t.subject;
    document.getElementById('tpl_body').value    = t.body;
    <?php if ($isSuperAdmin): ?>
    document.getElementById('tpl_bank_id').value = t.bank_id;
    <?php endif; ?>
    detectPlaceholders();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('tplModal')).show();
}

function openPreview(t) {
    document.getElementById('preview_subject').textContent = t.subject;
    document.getElementById('preview_body').textContent    = t.body;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
}

function detectPlaceholders() {
    const text = (document.getElementById('tpl_subject').value || '') +
                 ' ' +
                 (document.getElementById('tpl_body').value || '');
    const matches = [...new Set([...text.matchAll(/\{([A-ZÄÖÜ_0-9]+)\}/g)].map(m => m[1]))];
    const container = document.getElementById('placeholder_display');
    if (matches.length === 0) {
        container.innerHTML = '<span class="text-muted small">Keine Platzhalter erkannt</span>';
    } else {
        container.innerHTML = '<span class="text-muted small me-2">Erkannte Platzhalter:</span>' +
            matches.map(p => `<code class="badge bg-secondary text-white">{${p}}</code>`).join(' ');
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
