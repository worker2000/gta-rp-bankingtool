<?php
/**
 * PSB Kreditverwaltung - Kreditnehmer Übersicht
 */
$pageTitle = 'Kreditnehmer';
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('borrowers', 'view');

// Suche und Filter
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Query bauen – immer auf aktuelle Bank filtern
$bid = currentBankId();
$where = "WHERE bank_id = ? AND is_active = 1";
$params = [$bid];

if ($search) {
    $where .= " AND (customer_number LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR legacy_customer_number LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = array_merge($params, array_fill(0, 6, $searchTerm));
}

// Gesamtanzahl
$totalCount = Database::fetchOne("SELECT COUNT(*) as cnt FROM borrowers {$where}", $params)['cnt'];
$totalPages = ceil($totalCount / $perPage);

// Kreditnehmer laden
$borrowers = Database::fetchAll("
    SELECT b.*,
           (SELECT COUNT(*) FROM loans WHERE borrower_id = b.id) as loan_count,
           (SELECT COUNT(*) FROM loans WHERE borrower_id = b.id AND status = 'ACTIVE') as active_loans
    FROM borrowers b
    {$where}
    ORDER BY b.last_name, b.first_name
    LIMIT {$perPage} OFFSET {$offset}
", $params);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-people me-2"></i><?= t('borrowers.title') ?></h4>
    <?php if (Auth::can('borrowers', 'create')): ?>
    <a href="<?= APP_URL ?>/pages/borrowers/create.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i><?= t('borrowers.new') ?>
    </a>
    <?php endif; ?>
</div>

<!-- Suche -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-8">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search"
                           value="<?= e($search) ?>"
                           placeholder="<?= t('borrowers.search_placeholder') ?>">
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary me-2"><?= t('borrowers.search') ?></button>
                <?php if ($search): ?>
                <a href="<?= APP_URL ?>/pages/borrowers/index.php" class="btn btn-outline-secondary"><?= t('borrowers.reset') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($borrowers)): ?>
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <p><?= t('borrowers.none_found') ?></p>
            <?php if (Auth::can('borrowers', 'create')): ?>
            <a href="<?= APP_URL ?>/pages/borrowers/create.php" class="btn btn-primary"><?= t('borrowers.create_first') ?></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="borrowersTable">
                <thead>
                    <tr>
                        <th><?= t('borrowers.customer_number') ?></th>
                        <th><?= t('borrowers.name') ?></th>
                        <th><?= t('borrowers.contact') ?></th>
                        <th><?= t('borrowers.weekly_income') ?></th>
                        <th><?= t('borrowers.loans') ?></th>
                        <th class="text-end"><?= t('borrowers.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($borrowers as $borrower): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $borrower['id'] ?>">
                                <?= e($borrower['customer_number']) ?>
                            </a>
                            <?php if (!empty($borrower['legacy_customer_number'])): ?>
                            <br><small class="text-muted" title="<?= t('borrowers.old_number') ?>">
                                <i class="bi bi-clock-history me-1"></i><?= e($borrower['legacy_customer_number']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= e($borrower['last_name']) ?></strong>, <?= e($borrower['first_name']) ?>
                        </td>
                        <td>
                            <?php if ($borrower['phone']): ?>
                            <div><i class="bi bi-telephone me-1"></i><?= e($borrower['phone']) ?></div>
                            <?php endif; ?>
                            <?php if ($borrower['email']): ?>
                            <div><i class="bi bi-envelope me-1"></i><?= e($borrower['email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $borrower['weekly_income'] ? formatMoney($borrower['weekly_income']) : '-' ?></td>
                        <td>
                            <?php if ($borrower['active_loans'] > 0): ?>
                            <span class="badge bg-success"><?= $borrower['active_loans'] ?> <?= t('borrowers.active') ?></span>
                            <?php endif; ?>
                            <?php if ($borrower['loan_count'] > $borrower['active_loans']): ?>
                            <span class="badge bg-secondary"><?= $borrower['loan_count'] - $borrower['active_loans'] ?> <?= t('borrowers.other') ?></span>
                            <?php endif; ?>
                            <?php if ($borrower['loan_count'] === 0): ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $borrower['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Anzeigen">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (Auth::can('borrowers', 'edit')): ?>
                            <a href="<?= APP_URL ?>/pages/borrowers/edit.php?id=<?= $borrower['id'] ?>"
                               class="btn btn-sm btn-outline-secondary btn-action" title="Bearbeiten">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (Auth::can('loans', 'create')): ?>
                            <a href="<?= APP_URL ?>/pages/loans/create.php?borrower_id=<?= $borrower['id'] ?>"
                               class="btn btn-sm btn-outline-success btn-action" title="Neuer Kredit">
                                <i class="bi bi-plus-circle"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"><?= t('pagination.back') ?></a>
                    </li>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"><?= t('pagination.next') ?></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 text-muted small">
    <?= t('borrowers.total') ?>: <?= $totalCount ?> <?= t('borrowers.title') ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
