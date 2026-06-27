<?php
ob_start();
/**
 * PSB Kreditverwaltung - Schreiben bearbeiten (nur WRITTEN / TEMPLATE_BASED)
 */
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_URL . '/pages/documents/index.php');
    exit;
}

$doc = Database::fetchOne("
    SELECT d.*,
           b.first_name, b.last_name, b.customer_number,
           l.file_number
    FROM documents d
    LEFT JOIN borrowers b ON d.borrower_id = b.id
    LEFT JOIN loans l ON d.loan_id = l.id
    WHERE d.id = ?
", [$id]);

if (!$doc) {
    setFlash('error', 'Dokument nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/documents/index.php');
    exit;
}

if (!in_array($doc['doc_type'], ['WRITTEN', 'TEMPLATE_BASED'])) {
    setFlash('error', 'Hochgeladene Dateien können nicht bearbeitet werden.');
    header('Location: ' . APP_URL . '/pages/documents/view.php?id=' . $id);
    exit;
}

if ($doc['uploaded_by'] != Auth::userId() && !Auth::hasRole('director')) {
    setFlash('error', 'Keine Berechtigung zum Bearbeiten dieses Dokuments.');
    header('Location: ' . APP_URL . '/pages/documents/view.php?id=' . $id);
    exit;
}

$pageTitle = 'Schreiben bearbeiten';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $title       = trim($_POST['title'] ?? '');
    $content     = trim($_POST['content'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($title)) {
        $errors[] = 'Bitte Titel angeben.';
    }
    if (empty($content) || $content === '<p><br></p>') {
        $errors[] = 'Inhalt darf nicht leer sein.';
    }

    if (empty($errors)) {
        $oldValues = ['title' => $doc['title'], 'content' => mb_substr($doc['content'] ?? '', 0, 200)];
        $newValues = ['title' => $title,        'content' => mb_substr($content, 0, 200)];

        Database::update('documents', [
            'title'       => $title,
            'content'     => $content,
            'description' => $description ?: null,
        ], 'id = ?', [$id]);

        AuditLog::log('UPDATE', 'document', $id, $oldValues, $newValues);
        setFlash('success', 'Schreiben wurde aktualisiert.');
        header('Location: ' . APP_URL . '/pages/documents/view.php?id=' . $id);
        exit;
    }
}

$currentContent = $_POST['content'] ?? $doc['content'] ?? '';
$currentTitle   = $_POST['title']   ?? $doc['title']   ?? '';
$currentDesc    = $_POST['description'] ?? $doc['description'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $id ?>" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Ansicht
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-pencil me-2"></i>Schreiben bearbeiten
        </h4>
        <small class="text-muted"><?= e($doc['title'] ?: 'Ohne Titel') ?></small>
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

<form method="POST" id="edit-form">
    <?= csrfField() ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label">Titel <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title"
                   value="<?= e($currentTitle) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Beschreibung</label>
            <input type="text" class="form-control" name="description"
                   value="<?= e($currentDesc) ?>" placeholder="Kurze Beschreibung (optional)">
        </div>

        <?php if ($doc['borrower_id'] || $doc['loan_id']): ?>
        <div class="col-12">
            <small class="text-muted">
                <?php if ($doc['borrower_id']): ?>
                <i class="bi bi-person me-1"></i>
                Kreditnehmer: <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $doc['borrower_id'] ?>">
                    <?= e($doc['last_name'] . ', ' . $doc['first_name']) ?></a>
                <?php endif; ?>
                <?php if ($doc['loan_id']): ?>
                <i class="bi bi-file-earmark-text ms-3 me-1"></i>
                Kredit: <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $doc['loan_id'] ?>">
                    <?= e($doc['file_number']) ?></a>
                <?php endif; ?>
            </small>
        </div>
        <?php endif; ?>

        <div class="col-12">
            <label class="form-label">Inhalt <span class="text-danger">*</span></label>
            <!-- Quill-Editor (wird per JS initialisiert) -->
            <div id="edit-quill-container" style="display:none;">
                <div id="edit-editor" style="min-height:400px;"></div>
            </div>
            <!-- Textarea: Fallback und primäres Formularfeld -->
            <textarea name="content" id="edit-textarea"
                      class="form-control" style="min-height:400px; font-family:monospace;"><?= e($currentContent) ?></textarea>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-2"></i>Änderungen speichern
        </button>
        <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            Abbrechen
        </a>
    </div>
</form>

<!-- Quill JS -->
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
(function () {
    const TOOLBAR = [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        [{ 'align': [] }],
        ['clean']
    ];

    const container = document.getElementById('edit-quill-container');
    const textarea  = document.getElementById('edit-textarea');

    const quill = new Quill('#edit-editor', { theme: 'snow', modules: { toolbar: TOOLBAR } });

    // Bilder beim Einfügen entfernen
    const Delta = Quill.import('delta');
    quill.clipboard.addMatcher('img', function() { return new Delta(); });
    quill.clipboard.addMatcher(Node.ELEMENT_NODE, function(node, delta) {
        delta.ops = delta.ops.filter(function(op) {
            return !(op.insert && typeof op.insert === 'object' && op.insert.image);
        });
        return delta;
    });

    // Vorhandenen Inhalt laden
    if (textarea.value.trim()) {
        quill.root.innerHTML = textarea.value;
    }

    // Quill ↔ Textarea synchron halten
    quill.on('text-change', function () {
        textarea.value = quill.root.innerHTML;
    });

    container.style.display = 'block';
    textarea.style.display  = 'none';

    // Sicherheits-Sync beim Submit
    document.getElementById('edit-form').addEventListener('submit', function () {
        textarea.value = quill.root.innerHTML;
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
