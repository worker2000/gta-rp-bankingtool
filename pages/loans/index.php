<?php
/**
 * PSB Kreditverwaltung - Kredit Übersicht
 */
$pageTitle = 'Kredite';
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('loans', 'view');

// Filter
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$product = $_GET['product'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Query bauen – immer auf aktuelle Bank filtern
$bid = currentBankId();
$where = "WHERE l.bank_id = ?";
$params = [$bid];

if ($search) {
    $where .= " AND (l.file_number LIKE ? OR l.payment_reference LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ? OR b.customer_number LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = array_merge($params, array_fill(0, 5, $searchTerm));
}

if ($status) {
    $where .= " AND l.status = ?";
    $params[] = $status;
}

if ($product) {
    $where .= " AND l.product_type = ?";
    $params[] = $product;
}

// Gesamtanzahl
$totalCount = Database::fetchOne("
    SELECT COUNT(*) as cnt
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    {$where}
", $params)['cnt'];
$totalPages = ceil($totalCount / $perPage);

// Kredite laden
$loans = Database::fetchAll("
    SELECT l.*, b.first_name, b.last_name, b.customer_number,
           u.full_name as assigned_name,
           COALESCE((SELECT SUM(si.amount_paid) FROM loan_schedule_items si WHERE si.loan_id = l.id), 0) as total_paid
    FROM loans l
    JOIN borrowers b ON l.borrower_id = b.id
    LEFT JOIN users u ON l.assigned_to = u.id
    {$where}
    ORDER BY l.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Status-Liste für Filter (sprachbewusst via translateLoanStatus)
$statuses = [
    'APPLICATION_RECEIVED', 'IN_REVIEW', 'APPROVED', 'REJECTED',
    'CONTRACT_CREATED', 'ACTIVE', 'DUNNING_L1', 'DUNNING_L2',
    'TERMINATED', 'REPOSSESSION', 'CLOSED', 'WITHDRAWN'
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-file-earmark-text me-2"></i><?= t('loans.title') ?></h4>
    <?php if (Auth::can('loans', 'create')): ?>
    <a href="<?= APP_URL ?>/pages/loans/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle me-2"></i><?= t('loans.new') ?>
    </a>
    <?php endif; ?>
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
                           placeholder="<?= t('loans.search_placeholder') ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value=""><?= t('loans.all_statuses') ?></option>
                    <?php foreach ($statuses as $key): ?>
                    <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= translateLoanStatus($key) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="product">
                    <option value=""><?= t('loans.all_products') ?></option>
                    <option value="AUTO" <?= $product === 'AUTO' ? 'selected' : '' ?>><?= t('product.AUTO', 'Autokredit') ?></option>
                    <option value="PRIVATE" <?= $product === 'PRIVATE' ? 'selected' : '' ?>><?= t('product.PRIVATE', 'Privatkredit') ?></option>
                    <option value="BUSINESS" <?= $product === 'BUSINESS' ? 'selected' : '' ?>><?= t('product.BUSINESS', 'Geschäftskredit') ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary me-2"><?= t('loans.filter') ?></button>
                <?php if ($search || $status || $product): ?>
                <a href="<?= APP_URL ?>/pages/loans/index.php" class="btn btn-outline-secondary"><?= t('loans.reset') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($loans)): ?>
        <div class="empty-state">
            <i class="bi bi-file-earmark-x"></i>
            <p><?= t('loans.none_found') ?></p>
            <?php if (Auth::can('loans', 'create')): ?>
            <a href="<?= APP_URL ?>/pages/loans/create.php" class="btn btn-primary"><?= t('loans.create_first') ?></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th><?= t('loans.file_number') ?></th>
                        <th><?= t('loans.borrower') ?></th>
                        <th><?= t('loans.product_type') ?></th>
                        <th><?= t('loans.loan_amount') ?></th>
                        <th><?= t('loans.outstanding') ?></th>
                        <th><?= t('loans.status') ?></th>
                        <th><?= t('loans.overdue') ?></th>
                        <th class="text-end"><?= t('loans.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loans as $loan): ?>
                    <tr class="<?= $loan['days_overdue'] > 14 ? 'overdue-danger' : ($loan['days_overdue'] > 0 ? 'overdue-warning' : '') ?>">
                        <td>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>">
                                <strong><?= e($loan['file_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $loan['borrower_id'] ?>">
                                <?= e($loan['last_name'] . ', ' . $loan['first_name']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($loan['customer_number']) ?></small>
                        </td>
                        <td>
                            <?php if ($loan['product_type'] === 'INSURANCE'): ?>
                            <span class="text-danger"><i class="bi bi-heart-pulse me-1"></i><?= t('product.INSURANCE', 'Krankenversicherung') ?></span>
                            <?php else: ?>
                            <?= translateProductType($loan['product_type']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= formatMoney($loan['loan_amount']) ?></td>
                        <?php $loanRealOutstandingDisplay = max(0, round($loan['total_amount'] - $loan['total_paid'])); ?>
                        <td><?= formatMoney($loanRealOutstandingDisplay) ?></td>
                        <td>
                            <span class="badge <?= getStatusBadgeClass($loan['status']) ?>">
                                <?= translateLoanStatus($loan['status']) ?>
                            </span>
                            <?php
                                $loanRealOutstanding = round($loan['total_amount'] - $loan['total_paid']);
                                if ($loan['status'] !== 'CLOSED' && $loanRealOutstanding <= 200 && $loan['total_paid'] > 0):
                            ?>
                            <br><span class="badge bg-success mt-1"><i class="bi bi-check-circle me-1"></i><?= t('loans.possibly_closed') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($loan['days_overdue'] > 0): ?>
                            <span class="badge <?= $loan['days_overdue'] > 14 ? 'bg-danger' : 'bg-warning' ?>">
                                <?= $loan['days_overdue'] ?> <?= t('loans.days') ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>"
                               class="btn btn-sm btn-outline-primary btn-action" title="Anzeigen">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (Auth::can('loans', 'edit')): ?>
                            <a href="<?= APP_URL ?>/pages/loans/edit.php?id=<?= $loan['id'] ?>"
                               class="btn btn-sm btn-outline-secondary btn-action" title="Bearbeiten">
                                <i class="bi bi-pencil"></i>
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
                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&product=<?= $product ?>"><?= t('pagination.back') ?></a>
                    </li>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&product=<?= $product ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&product=<?= $product ?>"><?= t('pagination.next') ?></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 text-muted small">
    <?= t('loans.total') ?>: <?= $totalCount ?> <?= t('loans.title') ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
