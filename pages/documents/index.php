<?php
/**
 * PSB Kreditverwaltung - Schreiben / Dokumente Übersicht
 */
$pageTitle = 'Schreiben';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

// Filter
$search     = trim($_GET['search'] ?? '');
$filterType = $_GET['doc_type'] ?? '';
$filterBorrower = intval($_GET['borrower_id'] ?? 0);
$filterLoan     = intval($_GET['loan_id'] ?? 0);
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// WHERE aufbauen – immer bank-gefiltert
$where  = "WHERE d.bank_id = ?";
$params = [currentBankId()];

if ($search) {
    $where .= " AND (d.title LIKE ? OR d.original_filename LIKE ? OR d.description LIKE ?)";
    $s = "%{$search}%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($filterType) {
    $where .= " AND d.doc_type = ?";
    $params[] = $filterType;
}
if ($filterBorrower) {
    $where .= " AND d.borrower_id = ?";
    $params[] = $filterBorrower;
}
if ($filterLoan) {
    $where .= " AND d.loan_id = ?";
    $params[] = $filterLoan;
}

$totalCount = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM documents d {$where}", $params
)['cnt'];
$totalPages = max(1, ceil($totalCount / $perPage));

$docs = Database::fetchAll("
    SELECT d.*,
           b.first_name, b.last_name, b.customer_number,
           l.file_number,
           u.full_name as creator_name
    FROM documents d
    LEFT JOIN borrowers b ON d.borrower_id = b.id
    LEFT JOIN loans l ON d.loan_id = l.id
    LEFT JOIN users u ON d.uploaded_by = u.id
    {$where}
    ORDER BY d.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-folder2-open me-2"></i>Schreiben</h4>
    <a href="<?= APP_URL ?>/pages/documents/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle me-2"></i>Neues Schreiben
    </a>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search"
                           value="<?= e($search) ?>"
                           placeholder="Suche nach Titel, Dateiname, Beschreibung...">
                </div>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="doc_type">
                    <option value="">Alle Typen</option>
                    <option value="UPLOAD" <?= $filterType === 'UPLOAD' ? 'selected' : '' ?>>Upload</option>
                    <option value="WRITTEN" <?= $filterType === 'WRITTEN' ? 'selected' : '' ?>>Verfasst</option>
                    <option value="TEMPLATE_BASED" <?= $filterType === 'TEMPLATE_BASED' ? 'selected' : '' ?>>Vorlage</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">Suchen</button>
                    <?php if ($search || $filterType || $filterBorrower || $filterLoan): ?>
                    <a href="<?= APP_URL ?>/pages/documents/index.php" class="btn btn-outline-secondary">Zurücksetzen</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($docs)): ?>
        <div class="empty-state py-5">
            <i class="bi bi-folder2-open fs-1 text-muted d-block mb-3"></i>
            <p class="text-muted mb-3">Keine Schreiben gefunden</p>
            <a href="<?= APP_URL ?>/pages/documents/create.php" class="btn btn-primary">
                Erstes Schreiben erstellen
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Titel / Datei</th>
                        <th>Typ</th>
                        <th>Kreditnehmer</th>
                        <th>Kredit</th>
                        <th>Erstellt von</th>
                        <th>Datum</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $doc['id'] ?>">
                                <?php
                                $title = $doc['title'] ?: $doc['original_filename'] ?: 'Ohne Titel';
                                echo e($title);
                                ?>
                            </a>
                            <?php if ($doc['description']): ?>
                            <br><small class="text-muted"><?= e(mb_substr($doc['description'], 0, 60)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
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
                            <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                        </td>
                        <td>
                            <?php if ($doc['borrower_id']): ?>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $doc['borrower_id'] ?>">
                                <?= e($doc['last_name'] . ', ' . $doc['first_name']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($doc['loan_id']): ?>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $doc['loan_id'] ?>">
                                <?= e($doc['file_number']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($doc['creator_name'] ?? '-') ?></td>
                        <td><?= formatDate($doc['created_at']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/documents/view.php?id=<?= $doc['id'] ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted">
                <?= $totalCount ?> Einträge gesamt
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            &laquo;
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            &raquo;
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
