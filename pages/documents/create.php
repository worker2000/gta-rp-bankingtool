<?php
ob_start();
/**
 * PSB Kreditverwaltung - Schreiben erstellen
 */
$pageTitle = 'Neues Schreiben';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

// Vorbefüllung aus URL
$preBorrowerId = intval($_GET['borrower_id'] ?? 0);
$preLoanId     = intval($_GET['loan_id'] ?? 0);

// Hilfsfunktion: Template-Platzhalter ersetzen
function renderTemplate(string $body, array $vars): string {
    foreach ($vars as $key => $value) {
        $body = str_replace('{' . $key . '}', htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $body);
    }
    return $body;
}

// Kreditnehmer-Liste für Dropdown
$borrowers = Database::fetchAll(
    "SELECT id, customer_number, first_name, last_name, phone, salutation FROM borrowers WHERE is_active = 1 AND bank_id = ? ORDER BY last_name, first_name",
    [currentBankId()]
);

// Kredit-Liste
$loans = Database::fetchAll("
    SELECT l.id, l.file_number, l.purchase_price, l.down_payment, l.loan_amount,
           l.interest_rate, l.total_interest, l.total_amount, l.term_weeks, l.weekly_rate,
           l.start_date, l.end_date, l.vehicle_model, l.created_at,
           b.first_name, b.last_name
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.bank_id = ? AND b.bank_id = ?
    ORDER BY l.file_number
", [currentBankId(), currentBankId()]);

// Erste Raten-Termine pro Kredit
$firstRateRows = Database::fetchAll("
    SELECT lsi.loan_id, MIN(lsi.due_date) as erste_rate
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    WHERE l.bank_id = ?
    GROUP BY lsi.loan_id
", [currentBankId()]);
$firstRateMap  = [];
foreach ($firstRateRows as $fr) {
    $firstRateMap[$fr['loan_id']] = $fr['erste_rate'];
}

// Aktive Templates
$templates = Database::fetchAll("SELECT id, name, type, subject, body FROM templates WHERE is_active = 1 ORDER BY name");

$errors = [];
$old = [];

// POST-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $docType     = $_POST['doc_type'] ?? '';
    $title       = trim($_POST['title'] ?? '');
    $type        = $_POST['type'] ?? 'OTHER';
    $borrowerId  = intval($_POST['borrower_id'] ?? 0) ?: null;
    $loanId      = intval($_POST['loan_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $content     = trim($_POST['content'] ?? '');
    $templateId  = intval($_POST['template_id'] ?? 0) ?: null;

    $old = $_POST;

    // Validierung
    if (!in_array($docType, ['UPLOAD', 'WRITTEN', 'TEMPLATE_BASED'])) {
        $errors[] = 'Ungültiger Dokumenttyp.';
    }
    if (!$borrowerId && !$loanId) {
        $errors[] = 'Bitte Kreditnehmer oder Kredit zuordnen.';
    }

    if ($docType === 'UPLOAD') {
        if (empty($_FILES['file']['name'])) {
            $errors[] = 'Bitte eine Datei auswählen.';
        } else {
            $allowed    = ['application/pdf', 'application/msword',
                           'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                           'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain'];
            $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];
            $maxSize    = 10 * 1024 * 1024;
            $uploadedMime = mime_content_type($_FILES['file']['tmp_name']);
            $ext          = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

            if ($_FILES['file']['size'] > $maxSize) {
                $errors[] = 'Datei zu groß (max. 10 MB).';
            } elseif (!in_array($uploadedMime, $allowed) && !in_array($ext, $allowedExt)) {
                $errors[] = 'Dateityp nicht erlaubt. Erlaubt: PDF, Word, JPG, PNG, TXT.';
            }
        }
    } elseif ($docType === 'WRITTEN') {
        if (empty($title)) {
            $errors[] = 'Bitte Titel angeben.';
        }
        if (empty($content) || $content === '<p><br></p>') {
            $errors[] = 'Bitte Inhalt eingeben.';
        }
    } elseif ($docType === 'TEMPLATE_BASED') {
        if (!$templateId) {
            $errors[] = 'Bitte Vorlage auswählen.';
        }
        if (empty($content) || $content === '<p><br></p>') {
            $errors[] = 'Inhalt darf nicht leer sein.';
        }
    }

    if (empty($errors)) {
        $data = [
            'doc_type'    => $docType,
            'title'       => $title ?: null,
            'type'        => in_array($type, ['CONTRACT','ID_DOCUMENT','INCOME_PROOF','COLLATERAL_DOC','CORRESPONDENCE','OTHER']) ? $type : 'OTHER',
            'borrower_id' => $borrowerId,
            'loan_id'     => $loanId,
            'description' => $description ?: null,
            'uploaded_by' => Auth::userId(),
        ];

        if ($docType === 'UPLOAD') {
            $originalName = $_FILES['file']['name'];
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $storedName   = uniqid('doc_', true) . '.' . $ext;
            $destPath     = UPLOAD_PATH . 'documents/' . $storedName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                $errors[] = 'Datei konnte nicht gespeichert werden.';
            } else {
                $data['filename']          = $storedName;
                $data['original_filename'] = $originalName;
                $data['file_path']         = $storedName;
                $data['file_size']         = $_FILES['file']['size'];
                $data['mime_type']         = mime_content_type($destPath);
                if (!$title) {
                    $data['title'] = pathinfo($originalName, PATHINFO_FILENAME);
                }
            }
        } elseif ($docType === 'WRITTEN') {
            $data['content'] = $content;
        } elseif ($docType === 'TEMPLATE_BASED') {
            $data['template_id'] = $templateId;
            $data['content']     = $content;
            if (!$title && $templateId) {
                $tpl = Database::fetchOne("SELECT name FROM templates WHERE id = ?", [$templateId]);
                if ($tpl) $data['title'] = $tpl['name'];
            }
        }

        if (empty($errors)) {
            $newId = Database::insert('documents', $data);
            AuditLog::log('CREATE', 'document', $newId, null, $data);
            setFlash('success', 'Schreiben wurde erfolgreich gespeichert.');
            header('Location: ' . APP_URL . '/pages/documents/view.php?id=' . $newId);
            exit;
        }
    }
}

// Aktiven Tab ermitteln
$activeTab = $old['doc_type'] ?? ($_GET['doc_type'] ?? 'UPLOAD');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/documents/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0"><i class="bi bi-file-earmark-plus me-2"></i>Neues Schreiben</h4>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Quill CSS -->
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">

<!-- Typ-Auswahl Tabs -->
<ul class="nav nav-tabs mb-0" id="docTypeTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'UPLOAD' ? 'active' : '' ?>"
                id="tab-upload" data-bs-toggle="tab" data-bs-target="#pane-upload" type="button">
            <i class="bi bi-cloud-upload me-1"></i>Datei hochladen
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'WRITTEN' ? 'active' : '' ?>"
                id="tab-written" data-bs-toggle="tab" data-bs-target="#pane-written" type="button">
            <i class="bi bi-pencil-square me-1"></i>Selbst verfassen
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'TEMPLATE_BASED' ? 'active' : '' ?>"
                id="tab-template" data-bs-toggle="tab" data-bs-target="#pane-template" type="button">
            <i class="bi bi-layout-text-window me-1"></i>Vorlage ausfüllen
        </button>
    </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-body p-4 mb-4">

    <!-- ================================================================ -->
    <!-- TAB 1: Datei hochladen -->
    <!-- ================================================================ -->
    <div class="tab-pane fade <?= $activeTab === 'UPLOAD' ? 'show active' : '' ?>" id="pane-upload">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="doc_type" value="UPLOAD">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Datei <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="file"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.txt">
                    <div class="form-text">PDF, Word, Bilder, TXT – max. 10 MB</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Titel <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" name="title"
                           value="<?= e($old['title'] ?? '') ?>" placeholder="Leer = Original-Dateiname">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Dokumentart</label>
                    <select class="form-select" name="type">
                        <?php foreach (['CONTRACT'=>'Vertrag','ID_DOCUMENT'=>'Ausweisdokument','INCOME_PROOF'=>'Einkommensnachweis','COLLATERAL_DOC'=>'Sicherheitendokument','CORRESPONDENCE'=>'Korrespondenz','OTHER'=>'Sonstiges'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($old['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kreditnehmer</label>
                    <select class="form-select" name="borrower_id">
                        <option value="">— kein —</option>
                        <?php foreach ($borrowers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= intval($old['borrower_id'] ?? $preBorrowerId) === $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['customer_number'] . ' – ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kredit</label>
                    <select class="form-select" name="loan_id">
                        <option value="">— kein —</option>
                        <?php foreach ($loans as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= intval($old['loan_id'] ?? $preLoanId) === $l['id'] ? 'selected' : '' ?>>
                            <?= e($l['file_number'] . ' – ' . $l['last_name'] . ', ' . $l['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Beschreibung</label>
                    <input type="text" class="form-control" name="description"
                           value="<?= e($old['description'] ?? '') ?>" placeholder="Kurze Beschreibung (optional)">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-cloud-upload me-2"></i>Hochladen & Speichern
                </button>
                <a href="<?= APP_URL ?>/pages/documents/index.php" class="btn btn-outline-secondary ms-2">Abbrechen</a>
            </div>
        </form>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 2: Selbst verfassen -->
    <!-- ================================================================ -->
    <div class="tab-pane fade <?= $activeTab === 'WRITTEN' ? 'show active' : '' ?>" id="pane-written">
        <form method="POST" id="form-written">
            <?= csrfField() ?>
            <input type="hidden" name="doc_type" value="WRITTEN">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Titel <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title"
                           value="<?= e($old['title'] ?? '') ?>" required placeholder="Titel des Dokuments">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kreditnehmer</label>
                    <select class="form-select" name="borrower_id">
                        <option value="">— kein —</option>
                        <?php foreach ($borrowers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= intval($old['borrower_id'] ?? $preBorrowerId) === $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['customer_number'] . ' – ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kredit</label>
                    <select class="form-select" name="loan_id">
                        <option value="">— kein —</option>
                        <?php foreach ($loans as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= intval($old['loan_id'] ?? $preLoanId) === $l['id'] ? 'selected' : '' ?>>
                            <?= e($l['file_number'] . ' – ' . $l['last_name'] . ', ' . $l['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Beschreibung</label>
                    <input type="text" class="form-control" name="description"
                           value="<?= e($old['description'] ?? '') ?>" placeholder="Kurze Beschreibung (optional)">
                </div>
                <div class="col-12">
                    <label class="form-label">Inhalt <span class="text-danger">*</span></label>
                    <!-- Quill-Editor (wird per JS initialisiert, sobald Tab sichtbar) -->
                    <div id="written-quill-container" style="display:none;">
                        <div id="written-editor" style="min-height:300px;"></div>
                    </div>
                    <!-- Fallback Textarea (sichtbar bis Quill geladen, danach synchronisiert) -->
                    <textarea name="content" id="written-textarea"
                              class="form-control" style="min-height:300px; font-family:monospace;"
                              placeholder="Inhalt hier eingeben..."><?= e($old['content'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Speichern
                </button>
                <a href="<?= APP_URL ?>/pages/documents/index.php" class="btn btn-outline-secondary ms-2">Abbrechen</a>
            </div>
        </form>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 3: Vorlage ausfüllen -->
    <!-- ================================================================ -->
    <div class="tab-pane fade <?= $activeTab === 'TEMPLATE_BASED' ? 'show active' : '' ?>" id="pane-template">
        <form method="POST" id="form-template">
            <?= csrfField() ?>
            <input type="hidden" name="doc_type" value="TEMPLATE_BASED">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Vorlage <span class="text-danger">*</span></label>
                    <select class="form-select" name="template_id" id="template-select">
                        <option value="">— Vorlage wählen —</option>
                        <?php foreach ($templates as $tpl): ?>
                        <option value="<?= $tpl['id'] ?>"
                                data-name="<?= e($tpl['name']) ?>"
                            <?= intval($old['template_id'] ?? 0) === $tpl['id'] ? 'selected' : '' ?>>
                            <?= e($tpl['name']) ?> (<?= e($tpl['type']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kreditnehmer</label>
                    <select class="form-select" name="borrower_id" id="tpl-borrower">
                        <option value="">— kein —</option>
                        <?php foreach ($borrowers as $b): ?>
                        <option value="<?= $b['id'] ?>"
                                data-name="<?= e($b['first_name'] . ' ' . $b['last_name']) ?>"
                                data-phone="<?= e($b['phone'] ?? '') ?>"
                            <?= intval($old['borrower_id'] ?? $preBorrowerId) === $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['customer_number'] . ' – ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kredit</label>
                    <select class="form-select" name="loan_id" id="tpl-loan">
                        <option value="">— kein —</option>
                        <?php foreach ($loans as $l): ?>
                        <option value="<?= $l['id'] ?>"
                                data-number="<?= e($l['file_number']) ?>"
                            <?= intval($old['loan_id'] ?? $preLoanId) === $l['id'] ? 'selected' : '' ?>>
                            <?= e($l['file_number'] . ' – ' . $l['last_name'] . ', ' . $l['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Titel</label>
                    <input type="text" class="form-control" name="title" id="tpl-title"
                           value="<?= e($old['title'] ?? '') ?>" placeholder="Wird aus Vorlage vorbelegt">
                </div>
                <div class="col-12">
                    <label class="form-label">Inhalt</label>
                    <div id="template-quill-container" style="display:none;">
                        <div id="template-editor" style="min-height:350px;"></div>
                    </div>
                    <textarea name="content" id="template-textarea"
                              class="form-control" style="min-height:350px; font-family:monospace;"
                              placeholder="Vorlage wählen oder direkt eingeben..."><?= e($old['content'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Speichern
                </button>
                <a href="<?= APP_URL ?>/pages/documents/index.php" class="btn btn-outline-secondary ms-2">Abbrechen</a>
            </div>
        </form>
    </div>
</div>

<!-- Template-Daten für JavaScript -->
<?php
ob_start();
$tplBodies = [];
foreach ($templates as $tpl) {
    $tplBodies[$tpl['id']] = ['name' => $tpl['name'], 'body' => $tpl['body']];
}
$borrowerMap = [];
foreach ($borrowers as $b) {
    $anrede = match($b['salutation'] ?? '') { 'Mr.' => 'Herr', 'Ms.' => 'Frau', default => '' };
    $borrowerMap[$b['id']] = [
        'name'    => $b['first_name'] . ' ' . $b['last_name'],
        'phone'   => $b['phone'] ?? '',
        'anrede'  => $anrede,
        'nachname'=> $b['last_name'],
        'vorname' => $b['first_name'],
    ];
}
$loanMap = [];
foreach ($loans as $l) {
    $fmtNum = fn($v, $dec=2) => number_format(floatval($v), $dec, '.', ',');
    $fmtDate = fn($d) => $d ? date('d.m.Y', strtotime($d)) : '';
    $loanMap[$l['id']] = [
        'file_number'        => $l['file_number'],
        'purchase_price'     => $fmtNum($l['purchase_price']),
        'down_payment'       => $fmtNum($l['down_payment']),
        'loan_amount'        => $fmtNum($l['loan_amount']),
        'interest_rate'      => $fmtNum($l['interest_rate'] * 100, 1),
        'total_interest'     => $fmtNum($l['total_interest']),
        'total_amount'       => $fmtNum($l['total_amount']),
        'term_weeks'         => (string)$l['term_weeks'],
        'weekly_rate'        => $fmtNum($l['weekly_rate']),
        'start_date'         => $fmtDate($l['start_date']),
        'end_date'           => $fmtDate($l['end_date']),
        'vehicle_model'      => $l['vehicle_model'] ?? '',
        'created_date'       => $fmtDate($l['created_at']),
        'erste_rate'         => $fmtDate($firstRateMap[$l['id']] ?? null),
        'rate_pct'           => floatval($l['total_amount']) > 0
            ? $fmtNum(floatval($l['weekly_rate']) / floatval($l['total_amount']) * 100, 1)
            : '0.0',
    ];
}
?>

<!-- Quill JS -->
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
const tplBodies   = <?= json_encode($tplBodies) ?>;
const borrowerMap = <?= json_encode($borrowerMap) ?>;
const loanMap     = <?= json_encode($loanMap) ?>;

const TOOLBAR = [
    [{ 'header': [1, 2, 3, false] }],
    ['bold', 'italic', 'underline'],
    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
    [{ 'align': [] }],
    ['clean']
];

// Bilder beim Einfügen entfernen (keine base64-Blobs in der DB)
const Delta = Quill.import('delta');
function stripImagesFromQuill(quill) {
    quill.clipboard.addMatcher('img', function() {
        return new Delta();
    });
    quill.clipboard.addMatcher(Node.ELEMENT_NODE, function(node, delta) {
        delta.ops = delta.ops.filter(function(op) {
            return !(op.insert && typeof op.insert === 'object' && op.insert.image);
        });
        return delta;
    });
}

let writtenQuill  = null;
let templateQuill = null;

// ── Quill für "Selbst verfassen" ──────────────────────────────────────────
function initWrittenQuill() {
    if (writtenQuill) return;
    const container = document.getElementById('written-quill-container');
    const textarea  = document.getElementById('written-textarea');

    writtenQuill = new Quill('#written-editor', { theme: 'snow', modules: { toolbar: TOOLBAR } });
    stripImagesFromQuill(writtenQuill);

    // Vorhandenen Inhalt aus Textarea übernehmen
    if (textarea.value.trim()) {
        writtenQuill.root.innerHTML = textarea.value;
    }

    // Quill ↔ Textarea synchron halten
    writtenQuill.on('text-change', function () {
        textarea.value = writtenQuill.root.innerHTML;
    });

    container.style.display = 'block';
    textarea.style.display  = 'none';
}

// ── Quill für "Vorlage ausfüllen" ─────────────────────────────────────────
function initTemplateQuill() {
    if (templateQuill) return;
    const container = document.getElementById('template-quill-container');
    const textarea  = document.getElementById('template-textarea');

    templateQuill = new Quill('#template-editor', { theme: 'snow', modules: { toolbar: TOOLBAR } });
    stripImagesFromQuill(templateQuill);

    if (textarea.value.trim()) {
        templateQuill.root.innerHTML = textarea.value;
    }

    templateQuill.on('text-change', function () {
        textarea.value = templateQuill.root.innerHTML;
    });

    container.style.display = 'block';
    textarea.style.display  = 'none';
}

// ── Tabs: Quill lazy initialisieren ──────────────────────────────────────
document.getElementById('tab-written').addEventListener('shown.bs.tab', initWrittenQuill);
document.getElementById('tab-template').addEventListener('shown.bs.tab', initTemplateQuill);

// Sofort initialisieren wenn Tab schon aktiv ist (z.B. nach Validierungsfehler)
if (document.getElementById('pane-written').classList.contains('active')) {
    initWrittenQuill();
}
if (document.getElementById('pane-template').classList.contains('active')) {
    initTemplateQuill();
}

// ── Sicherheits-Sync beim Submit ─────────────────────────────────────────
document.getElementById('form-written').addEventListener('submit', function () {
    if (writtenQuill) {
        document.getElementById('written-textarea').value = writtenQuill.root.innerHTML;
    }
});
document.getElementById('form-template').addEventListener('submit', function () {
    if (templateQuill) {
        document.getElementById('template-textarea').value = templateQuill.root.innerHTML;
    }
});

// ── Platzhalter-Ersetzung ─────────────────────────────────────────────────
function replacePlaceholders(body) {
    const bId = parseInt(document.getElementById('tpl-borrower').value) || 0;
    const lId = parseInt(document.getElementById('tpl-loan').value) || 0;
    const b   = (bId && borrowerMap[bId]) ? borrowerMap[bId] : {};
    const l   = (lId && loanMap[lId])     ? loanMap[lId]     : {};

    const vars = {
        // Abwärtskompatibel (Kleinschreibung)
        borrower_name:  b.name        || '',
        borrower_phone: b.phone       || '',
        loan_number:    l.file_number || '',
        date:           new Date().toLocaleDateString('de-DE'),

        // Kreditnehmer
        ANREDE:              b.anrede   || '',
        NACHNAME:            b.nachname || '',
        VORNAME:             b.vorname  || '',
        NAME:                b.name     || '',
        TELEFON:             b.phone    || '',

        // Kredit-Stammdaten
        AKTENZEICHEN:        l.file_number    || '',
        ANFRAGEDATUM:        l.created_date   || '',
        FAHRZEUGMODELL:      l.vehicle_model  || '',

        // Finanzdaten
        KAUFPREIS:           l.purchase_price     || '',
        EIGENKAPITAL:        l.down_payment       || '',
        FINANZIERUNGSBETRAG: l.loan_amount        || '',
        ZINSSATZ:            l.interest_rate      || '',
        ZINSBETRAG:          l.total_interest     || '',
        GESAMTKREDITSUMME:   l.total_amount       || '',
        LAUFZEIT:            l.term_weeks         || '',
        WOCHENRATE:          l.weekly_rate        || '',
        RATENPROZ:           l.rate_pct           || '',

        // Termine
        ERSTE_RATE:          l.erste_rate  || '',
        VERTRAGSBEGINN:      l.start_date  || '',
        VERTRAGSENDE:        l.end_date    || '',
        DATUM:               new Date().toLocaleDateString('de-DE'),
    };

    let result = body;
    for (const [k, v] of Object.entries(vars)) {
        result = result.replaceAll('{' + k + '}', v);
    }
    return result;
}

function loadTemplateIntoEditor() {
    const sel  = document.getElementById('template-select');
    const tplId = parseInt(sel.value) || 0;
    if (!tplId || !tplBodies[tplId]) return;

    const tplName = tplBodies[tplId].name;
    const body    = tplBodies[tplId].body.replace(/\n/g, '<br>');
    const rendered = replacePlaceholders(body);

    const titleInput = document.getElementById('tpl-title');
    if (!titleInput.value) titleInput.value = tplName;

    if (templateQuill) {
        templateQuill.clipboard.dangerouslyPasteHTML(rendered);
    } else {
        document.getElementById('template-textarea').value = rendered;
    }
}

document.getElementById('template-select').addEventListener('change', loadTemplateIntoEditor);
document.getElementById('tpl-borrower').addEventListener('change', function () {
    if (document.getElementById('template-select').value) loadTemplateIntoEditor();
});
document.getElementById('tpl-loan').addEventListener('change', function () {
    if (document.getElementById('template-select').value) loadTemplateIntoEditor();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
