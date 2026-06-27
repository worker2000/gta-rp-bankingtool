<?php
/**
 * PSB Kreditverwaltung - Kundenkonten Übersicht
 */
$pageTitle = 'Kundenkonten';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

// Filter
$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$orderBy = $_GET['sort'] ?? 'account_number';
$order = $_GET['order'] ?? 'ASC';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;

$result = AccountManager::getAccounts(
    $search ?: null,
    $typeFilter ?: null,
    $statusFilter ?: null,
    $orderBy, $order,
    $perPage, ($page - 1) * $perPage
);

$accounts = $result['accounts'];
$totalAccounts = $result['total'];
$totalPages = ceil($totalAccounts / $perPage);

// Statistiken
$stats = AccountManager::getStats();
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h4><i class="bi bi-wallet2 me-2"></i>Kundenkonten</h4>
        <span class="text-muted"><?= $totalAccounts ?> Konten gesamt</span>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value text-primary"><?= $stats['active_accounts'] ?? 0 ?></div>
                <div class="kpi-label">Aktive Konten</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value text-success"><?= formatMoney($stats['total_revenue'] ?? 0) ?></div>
                <div class="kpi-label">Gesamteinnahmen</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($stats['total_transfer_revenue'] ?? 0) ?></div>
                <div class="kpi-label">Überweisungsgebühren</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body">
                <div class="kpi-value"><?= formatMoney($stats['expected_weekly_revenue'] ?? 0) ?></div>
                <div class="kpi-label">Erwartete Wochengebühren</div>
            </div>
        </div>
    </div>
</div>

<!-- Kontotyp-Verteilung -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-warning">
            <div class="card-body text-center py-2">
                <div class="fw-bold text-warning"><?= $stats['bronze_count'] ?? 0 ?></div>
                <small class="text-muted">Bronze</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-secondary">
            <div class="card-body text-center py-2">
                <div class="fw-bold"><?= $stats['silver_count'] ?? 0 ?></div>
                <small class="text-muted">Silver</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card" style="border-color: #ffc107;">
            <div class="card-body text-center py-2">
                <div class="fw-bold" style="color: #ffd700;"><?= $stats['gold_count'] ?? 0 ?></div>
                <small class="text-muted">Gold</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-primary">
            <div class="card-body text-center py-2">
                <div class="fw-bold text-primary"><?= $stats['business_count'] ?? 0 ?></div>
                <small class="text-muted">Business</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-info">
            <div class="card-body text-center py-2">
                <div class="fw-bold text-info"><?= $stats['startup_count'] ?? 0 ?></div>
                <small class="text-muted">Start Up</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-dark">
            <div class="card-body text-center py-2">
                <div class="fw-bold"><?= $stats['lohn_count'] ?? 0 ?></div>
                <small class="text-muted">Lohnkonto</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Suche</label>
                <input type="text" class="form-control" name="search" value="<?= e($search) ?>"
                       placeholder="Kontonummer oder Name...">
            </div>
            <div class="col-md-3">
                <label class="form-label">Kontotyp</label>
                <select class="form-select" name="type">
                    <option value="">Alle Typen</option>
                    <option value="BRONZE" <?= $typeFilter === 'BRONZE' ? 'selected' : '' ?>>Bronze (10$/W)</option>
                    <option value="SILVER" <?= $typeFilter === 'SILVER' ? 'selected' : '' ?>>Silver (25$/W)</option>
                    <option value="GOLD" <?= $typeFilter === 'GOLD' ? 'selected' : '' ?>>Gold (50$/W)</option>
                    <option value="BUSINESS" <?= $typeFilter === 'BUSINESS' ? 'selected' : '' ?>>Business (100$/W)</option>
                    <option value="STARTUP" <?= $typeFilter === 'STARTUP' ? 'selected' : '' ?>>Start Up (40$/W)</option>
                    <option value="LOHNKONTO" <?= $typeFilter === 'LOHNKONTO' ? 'selected' : '' ?>>Lohnkonto (0$/W)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">Alle</option>
                    <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>Aktiv</option>
                    <option value="CLOSED" <?= $statusFilter === 'CLOSED' ? 'selected' : '' ?>>Geschlossen</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filtern</button>
                <a href="<?= APP_URL ?>/pages/accounts/index.php" class="btn btn-outline-secondary">Zurücksetzen</a>
            </div>
        </form>
    </div>
</div>

<!-- Kontenliste -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
        <div class="empty-state py-5">
            <i class="bi bi-wallet2 text-muted" style="font-size: 3rem;"></i>
            <p class="mb-0 mt-2">Keine Konten gefunden</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <?php
                        $sortUrl = function($col) use ($orderBy, $order, $search, $typeFilter, $statusFilter) {
                            $newOrder = ($orderBy === $col && $order === 'ASC') ? 'DESC' : 'ASC';
                            $params = ['sort' => $col, 'order' => $newOrder];
                            if ($search) $params['search'] = $search;
                            if ($typeFilter) $params['type'] = $typeFilter;
                            if ($statusFilter) $params['status'] = $statusFilter;
                            return '?' . http_build_query($params);
                        };
                        $sortIcon = function($col) use ($orderBy, $order) {
                            if ($orderBy !== $col) return '';
                            return $order === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
                        };
                        ?>
                        <th><a href="<?= $sortUrl('account_number') ?>" class="text-decoration-none text-light">Kontonummer<?= $sortIcon('account_number') ?></a></th>
                        <th><a href="<?= $sortUrl('account_name') ?>" class="text-decoration-none text-light">Name<?= $sortIcon('account_name') ?></a></th>
                        <th>Typ</th>
                        <th>Wochengebühr</th>
                        <th><a href="<?= $sortUrl('total_fees_paid') ?>" class="text-decoration-none text-light">Gesamtgebühren<?= $sortIcon('total_fees_paid') ?></a></th>
                        <th>Überw.-Gebühren</th>
                        <th><a href="<?= $sortUrl('opening_date') ?>" class="text-decoration-none text-light">Eröffnung<?= $sortIcon('opening_date') ?></a></th>
                        <th>Score</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/pages/accounts/view.php?id=<?= $acc['id'] ?>">
                                <code><?= e($acc['account_number']) ?></code>
                            </a>
                        </td>
                        <td><?= e($acc['account_name']) ?></td>
                        <td>
                            <span class="badge <?= AccountManager::getTypeBadgeClass($acc['account_type']) ?>">
                                <?= AccountManager::translateAccountType($acc['account_type']) ?>
                            </span>
                        </td>
                        <td><?= formatMoney($acc['weekly_fee']) ?></td>
                        <td class="text-success"><?= formatMoney($acc['total_fees_paid']) ?></td>
                        <td><?= formatMoney($acc['total_transfer_fees']) ?></td>
                        <td><?= $acc['opening_date'] ? formatDate($acc['opening_date']) : '<span class="text-muted">-</span>' ?></td>
                        <?php $score = CreditScore::calculate($acc); ?>
                        <td>
                            <span class="fw-bold <?= CreditScore::getScoreClass($score['total']) ?>">
                                <?= $score['total'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $acc['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $acc['status'] === 'ACTIVE' ? 'Aktiv' : 'Geschlossen' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav>
                <ul class="pagination justify-content-center mb-0">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
