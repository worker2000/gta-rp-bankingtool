<?php
ob_start();
/**
 * PSB Kreditverwaltung - Schreiben / Dokument anzeigen
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
           l.file_number,
           u.full_name as creator_name,
           t.name as template_name
    FROM documents d
    LEFT JOIN borrowers b ON d.borrower_id = b.id
    LEFT JOIN loans l ON d.loan_id = l.id
    LEFT JOIN users u ON d.uploaded_by = u.id
    LEFT JOIN templates t ON d.template_id = t.id
    WHERE d.id = ?
", [$id]);

if (!$doc) {
    setFlash('error', 'Dokument nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/documents/index.php');
    exit;
}

$pageTitle = $doc['title'] ?: ($doc['original_filename'] ?: 'Dokument #' . $id);

// POST: Löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        // Datei vom Server entfernen falls vorhanden
        if ($doc['file_path']) {
            $filePath = UPLOAD_PATH . 'documents/' . basename($doc['file_path']);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        Database::query("DELETE FROM documents WHERE id = ?", [$id]);
        AuditLog::log('DELETE', 'document', $id, ['title' => $doc['title'], 'doc_type' => $doc['doc_type']], null);
        setFlash('success', 'Schreiben wurde gelöscht.');
        header('Location: ' . APP_URL . '/pages/documents/index.php');
        exit;
    }
}

$canEdit = in_array($doc['doc_type'], ['WRITTEN', 'TEMPLATE_BASED'])
        && ($doc['uploaded_by'] == Auth::userId() || Auth::hasRole('director'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/documents/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-file-earmark-text me-2"></i><?= e($pageTitle) ?>
            <?php
            $badgeClass = match($doc['doc_type']) {
                'UPLOAD'         => 'bg-secondary',
                'WRITTEN'        => 'bg-primary',
                'TEMPLATE_BASED' => 'bg-info text-dark',
                default          => 'bg-secondary',
            };
            $badgeLabel = match($doc['doc_type']) {
                'UPLOAD'         => 'Upload',
                'WRITTEN'        => 'Verfasst',
                'TEMPLATE_BASED' => 'Vorlage',
                default          => $doc['doc_type'],
            };
            ?>
            <span class="badge <?= $badgeClass ?> ms-2"><?= $badgeLabel ?></span>
        </h4>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
        <a href="<?= APP_URL ?>/pages/documents/edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-2"></i>Bearbeiten
        </a>
        <?php endif; ?>
        <?php if ($doc['doc_type'] === 'UPLOAD' && $doc['file_path']): ?>
        <a href="<?= APP_URL ?>/pages/documents/serve.php?id=<?= $id ?>" class="btn btn-outline-success" target="_blank">
            <i class="bi bi-download me-2"></i>Download
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-danger"
                data-bs-toggle="modal" data-bs-target="#deleteModal">
            <i class="bi bi-trash me-2"></i>Löschen
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Meta-Informationen -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Details</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted" style="width:40%">Typ</td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                    </tr>
                    <?php if ($doc['type']): ?>
                    <tr>
                        <td class="text-muted">Kategorie</td>
                        <td><?php
                            $cat = ['CONTRACT'=>'Vertrag','ID_DOCUMENT'=>'Ausweis','INCOME_PROOF'=>'Einkommensnachweis','COLLATERAL_DOC'=>'Sicherheit','CORRESPONDENCE'=>'Korrespondenz','OTHER'=>'Sonstiges'];
                            echo e($cat[$doc['type']] ?? $doc['type']);
                        ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['borrower_id']): ?>
                    <tr>
                        <td class="text-muted">Kreditnehmer</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $doc['borrower_id'] ?>">
                                <?= e($doc['last_name'] . ', ' . $doc['first_name']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($doc['customer_number']) ?></small>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['loan_id']): ?>
                    <tr>
                        <td class="text-muted">Kredit</td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $doc['loan_id'] ?>">
                                <?= e($doc['file_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['template_name']): ?>
                    <tr>
                        <td class="text-muted">Vorlage</td>
                        <td><?= e($doc['template_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['description']): ?>
                    <tr>
                        <td class="text-muted">Beschreibung</td>
                        <td><?= e($doc['description']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Erstellt von</td>
                        <td><?= e($doc['creator_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Erstellt am</td>
                        <td><?= formatDateTime($doc['created_at']) ?></td>
                    </tr>
                    <?php if ($doc['doc_type'] === 'UPLOAD' && $doc['file_size']): ?>
                    <tr>
                        <td class="text-muted">Dateigröße</td>
                        <td><?= number_format($doc['file_size'] / 1024, 1) ?> KB</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Inhalt -->
    <div class="col-md-8">
        <?php if ($doc['doc_type'] === 'UPLOAD' && $doc['file_path']): ?>
            <?php
            $mime = $doc['mime_type'] ?: '';
            $serveUrl = APP_URL . '/pages/documents/serve.php?id=' . $id;
            ?>
            <?php if ($mime === 'application/pdf'): ?>
            <!-- PDF einbetten -->
            <div class="card">
                <div class="card-header"><i class="bi bi-file-pdf me-2"></i><?= e($doc['original_filename']) ?></div>
                <div class="card-body p-0">
                    <iframe src="<?= $serveUrl ?>" style="width:100%; height:600px; border:0;"
                            title="<?= e($doc['title'] ?: $doc['original_filename']) ?>">
                        <p>Ihr Browser unterstützt keine PDF-Vorschau.
                           <a href="<?= $serveUrl ?>">Datei herunterladen</a></p>
                    </iframe>
                </div>
            </div>
            <?php elseif (str_starts_with($mime, 'image/')): ?>
            <!-- Bild anzeigen -->
            <div class="card">
                <div class="card-header"><i class="bi bi-image me-2"></i><?= e($doc['original_filename']) ?></div>
                <div class="card-body text-center">
                    <img src="<?= $serveUrl ?>" class="img-fluid rounded"
                         alt="<?= e($doc['title'] ?: $doc['original_filename']) ?>"
                         style="max-height:600px;">
                </div>
            </div>
            <?php else: ?>
            <!-- Download-Link für sonstige Dateien -->
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-file-earmark fs-1 text-muted d-block mb-3"></i>
                    <p class="mb-3"><?= e($doc['original_filename']) ?></p>
                    <a href="<?= $serveUrl ?>" class="btn btn-primary">
                        <i class="bi bi-download me-2"></i>Datei herunterladen
                    </a>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif (in_array($doc['doc_type'], ['WRITTEN', 'TEMPLATE_BASED']) && $doc['content']): ?>
        <!-- Schreiben anzeigen -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-text me-2"></i>Inhalt</span>
                <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy-target="doc-content-text">
                    <i class="bi bi-clipboard me-1"></i>Kopieren
                </button>
            </div>
            <div class="card-body p-0">
                <div class="document-letter-wrap">
                    <div class="document-letter" id="doc-content-text"><?= $doc['content'] ?></div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-exclamation-circle text-muted fs-1 d-block mb-3"></i>
                <p class="text-muted">Kein Inhalt verfügbar</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Löschen-Bestätigungs-Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Schreiben löschen</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Soll das Schreiben <strong><?= e($pageTitle) ?></strong> wirklich gelöscht werden?</p>
                <?php if ($doc['doc_type'] === 'UPLOAD'): ?>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Die hochgeladene Datei wird ebenfalls vom Server gelöscht.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Endgültig löschen
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.document-letter-wrap {
    background: #f8f9fa;
    padding: 2rem;
}
.document-letter {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 3rem 3.5rem;
    max-width: 780px;
    margin: 0 auto;
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 0.92rem;
    line-height: 1.75;
    color: #212529;
    word-break: break-word;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
