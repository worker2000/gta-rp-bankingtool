<?php
ob_start();
/**
 * PSB / Fortis Finance – Berichte & Auswertungen
 */
$pageTitle = 'Berichte';
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('loans', 'view');

$bid    = currentBankId();
$isFF   = $bid === 2;
$today  = date('Y-m-d');
$month  = $_GET['month'] ?? date('Y-m');

// Monat aufteilen
[$mYear, $mMonth] = explode('-', $month);
$mStart = "{$mYear}-{$mMonth}-01";
$mEnd   = date('Y-m-t', strtotime($mStart));

// ── Kreditportfolio ──────────────────────────────────────────
$portfolio = Database::fetchOne("
    SELECT
        COUNT(*) as total_loans,
        SUM(CASE WHEN status = 'ACTIVE'                     THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'CLOSED'                     THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN status = 'TERMINATED'                 THEN 1 ELSE 0 END) as terminated_count,
        SUM(CASE WHEN status IN ('DUNNING_L1','DUNNING_L2') THEN 1 ELSE 0 END) as dunning,
        COALESCE(SUM(CASE WHEN status = 'ACTIVE' THEN outstanding_balance END), 0) as total_outstanding,
        COALESCE(SUM(loan_amount), 0) as total_volume,
        COALESCE(SUM(total_interest), 0) as total_interest_all,
        COALESCE(AVG(CASE WHEN status = 'ACTIVE' THEN loan_amount END), 0) as avg_loan_active
    FROM loans WHERE bank_id = ?
", [$bid]);

// Aufschlüsselung nach Produkttyp
$byProduct = Database::fetchAll("
    SELECT product_type,
           COUNT(*) as cnt,
           SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active,
           COALESCE(SUM(CASE WHEN status='ACTIVE' THEN outstanding_balance END),0) as outstanding,
           COALESCE(SUM(loan_amount),0) as volume
    FROM loans WHERE bank_id = ?
    GROUP BY product_type ORDER BY cnt DESC
", [$bid]);

// ── Zahlungseingang im gewählten Monat ───────────────────────
$monthPayments = Database::fetchOne("
    SELECT
        COUNT(*) as payment_count,
        COALESCE(SUM(amount_paid), 0) as total_paid
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    WHERE l.bank_id = ?
      AND DATE(lsi.paid_at) BETWEEN ? AND ?
      AND lsi.status = 'PAID'
", [$bid, $mStart, $mEnd]);

// Zahlungsverlauf letzte 6 Monate
$paymentHistory = Database::fetchAll("
    SELECT
        DATE_FORMAT(lsi.paid_at, '%Y-%m') as month_key,
        DATE_FORMAT(lsi.paid_at, '%b %Y') as month_label,
        COUNT(*) as payments,
        COALESCE(SUM(lsi.amount_paid), 0) as total
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    WHERE l.bank_id = ?
      AND lsi.paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      AND lsi.status = 'PAID'
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
", [$bid]);

// Überfällige Raten
$overdueStats = Database::fetchOne("
    SELECT
        COUNT(*) as overdue_count,
        COALESCE(SUM(lsi.amount_outstanding), 0) as overdue_amount
    FROM loan_schedule_items lsi
    JOIN loans l ON lsi.loan_id = l.id
    WHERE l.bank_id = ?
      AND lsi.due_date < CURDATE()
      AND lsi.status IN ('PENDING','PARTIAL','OVERDUE')
", [$bid]);

// ── Neu vergebene Kredite im gewählten Monat ──────────────────
$newLoansMonth = Database::fetchOne("
    SELECT COUNT(*) as cnt, COALESCE(SUM(loan_amount),0) as vol
    FROM loans WHERE bank_id = ? AND DATE_FORMAT(start_date,'%Y-%m') = ?
", [$bid, $month]);

// Top 10 Kreditnehmer nach Ausstehend
$topBorrowers = Database::fetchAll("
    SELECT b.customer_number, b.first_name, b.last_name,
           COUNT(l.id) as loan_count,
           COALESCE(SUM(l.outstanding_balance),0) as total_outstanding
    FROM loans l JOIN borrowers b ON l.borrower_id = b.id
    WHERE l.bank_id = ? AND l.status = 'ACTIVE'
    GROUP BY b.id ORDER BY total_outstanding DESC LIMIT 10
", [$bid]);

// ── Fortis Finance: Versicherung ─────────────────────────────
$insKpis = null;
$insProducts = [];
$sbKpis = null;
if ($isFF) {
    $insKpis = Database::fetchOne("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(CASE WHEN status='ACTIVE' THEN premium_amount END),0) as monthly_premiums,
            (SELECT COUNT(*) FROM insurance_claims WHERE bank_id=2 AND status IN ('SUBMITTED','IN_REVIEW')) as open_claims,
            (SELECT COALESCE(SUM(payout_amount),0) FROM insurance_claims WHERE bank_id=2 AND status='PAID') as total_paid_claims
        FROM insurance_contracts WHERE bank_id=2
    ");

    $insProducts = Database::fetchAll("
        SELECT ip.name, ip.type,
               COUNT(ic.id) as contracts,
               COALESCE(SUM(CASE WHEN ic.status='ACTIVE' THEN ic.premium_amount END),0) as monthly
        FROM insurance_products ip
        LEFT JOIN insurance_contracts ic ON ip.id = ic.product_id
        WHERE ip.bank_id = 2
        GROUP BY ip.id ORDER BY contracts DESC
    ");

    // Arbeitgeber-KV
    $groupKpis = Database::fetchOne("
        SELECT
            (SELECT COUNT(*) FROM insurance_employers WHERE bank_id=2 AND is_active=1) as employers,
            (SELECT COUNT(*) FROM insurance_group_contracts WHERE bank_id=2 AND status='ACTIVE') as active_groups,
            (SELECT COUNT(*) FROM insurance_members im JOIN insurance_group_contracts gc ON im.group_contract_id=gc.id WHERE gc.bank_id=2 AND im.status='ACTIVE') as active_members,
            (SELECT COALESCE(SUM(im.premium_monthly),0) FROM insurance_members im JOIN insurance_group_contracts gc ON im.group_contract_id=gc.id WHERE gc.bank_id=2 AND im.status='ACTIVE') as monthly_group_fees
    ");

    $sbKpis = Database::fetchOne("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(CASE WHEN status='ACTIVE' THEN weekly_fee END),0) as weekly_revenue,
            SUM(CASE WHEN status='ACTIVE' AND (last_payment_date IS NULL OR last_payment_date < DATE_SUB(CURDATE(),INTERVAL 14 DAY)) THEN 1 ELSE 0 END) as overdue_boxes
        FROM safeboxes WHERE bank_id=2
    ");
}

function pct(float $a, float $b): string {
    if ($b <= 0) return '0%';
    return round($a / $b * 100, 1) . '%';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-bar-chart-line me-2"></i>Berichte & Auswertungen</h4>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted small me-1">Monat:</label>
        <input type="month" class="form-control form-control-sm" name="month"
               value="<?= e($month) ?>" style="width:160px">
        <button type="submit" class="btn btn-sm btn-primary">Anzeigen</button>
    </form>
</div>

<!-- Kreditportfolio Übersicht -->
<h5 class="mb-3 text-muted"><i class="bi bi-file-earmark-text me-2"></i>Kreditportfolio</h5>
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= $portfolio['total_loans'] ?></div>
                <div class="kpi-label">Kredite gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= $portfolio['active'] ?></div>
                <div class="kpi-label">Aktiv</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card kpi-card warning">
            <div class="card-body text-center">
                <div class="kpi-value text-warning"><?= $portfolio['dunning'] ?></div>
                <div class="kpi-label">In Mahnung</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card kpi-card danger">
            <div class="card-body text-center">
                <div class="kpi-value text-danger"><?= $portfolio['terminated_count'] ?></div>
                <div class="kpi-label">Gekündigt</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value" style="font-size:1.3rem"><?= formatMoney($portfolio['total_outstanding']) ?></div>
                <div class="kpi-label">Offene Forderungen (aktive Kredite)</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Nach Produkttyp -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Aufschlüsselung nach Produkt</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Produkt</th>
                            <th class="text-center">Gesamt</th>
                            <th class="text-center">Aktiv</th>
                            <th class="text-end">Volumen</th>
                            <th class="text-end">Offen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byProduct as $row):
                            $label = match($row['product_type']) {
                                'AUTO'     => 'Fahrzeugkredit',
                                'PRIVATE'  => 'Privatkredit',
                                'BUSINESS' => 'Unternehmenskredit',
                                default    => $row['product_type'],
                            };
                        ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td class="text-center"><?= $row['cnt'] ?></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?= $row['active'] ?></span>
                            </td>
                            <td class="text-end"><?= formatMoney($row['volume']) ?></td>
                            <td class="text-end"><?= formatMoney($row['outstanding']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-active">
                        <tr>
                            <td><strong>Gesamt</strong></td>
                            <td class="text-center"><strong><?= $portfolio['total_loans'] ?></strong></td>
                            <td class="text-center"><strong><?= $portfolio['active'] ?></strong></td>
                            <td class="text-end"><strong><?= formatMoney($portfolio['total_volume']) ?></strong></td>
                            <td class="text-end"><strong><?= formatMoney($portfolio['total_outstanding']) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Überfällige + Neukredite -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Überfällige Raten</div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <div class="display-6 text-danger"><?= $overdueStats['overdue_count'] ?></div>
                        <div class="text-muted small">Überfällige Raten</div>
                    </div>
                    <div class="col-6">
                        <div class="display-6 text-danger"><?= formatMoney($overdueStats['overdue_amount']) ?></div>
                        <div class="text-muted small">Offener Betrag</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Neukredite <?= date('F Y', strtotime($mStart)) ?></div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <div class="display-6"><?= $newLoansMonth['cnt'] ?></div>
                        <div class="text-muted small">Neue Kredite</div>
                    </div>
                    <div class="col-6">
                        <div class="display-6"><?= formatMoney($newLoansMonth['vol']) ?></div>
                        <div class="text-muted small">Neues Kreditvolumen</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Zahlungseingang -->
<h5 class="mb-3 text-muted"><i class="bi bi-cash-stack me-2"></i>Zahlungseingang</h5>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= formatMoney($monthPayments['total_paid']) ?></div>
                <div class="kpi-label">Zahlungseingang <?= date('F Y', strtotime($mStart)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= $monthPayments['payment_count'] ?></div>
                <div class="kpi-label">Buchungen im Monat</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value" style="font-size:1.1rem"><?= formatMoney($portfolio['total_interest_all']) ?></div>
                <div class="kpi-label">Zinsen (gesamt, alle Kredite)</div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($paymentHistory)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up me-2"></i>Zahlungsverlauf (letzte 6 Monate)</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Monat</th>
                    <th class="text-center">Anzahl Buchungen</th>
                    <th class="text-end">Zahlungseingang</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paymentHistory as $ph): ?>
                <tr>
                    <td><?= e($ph['month_label']) ?></td>
                    <td class="text-center"><?= $ph['payments'] ?></td>
                    <td class="text-end text-success"><?= formatMoney($ph['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Top Kreditnehmer -->
<?php if (!empty($topBorrowers)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-people me-2"></i>Top Kreditnehmer nach offenen Forderungen</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kreditnehmer</th>
                    <th class="text-center">Aktive Kredite</th>
                    <th class="text-end">Offene Forderung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topBorrowers as $i => $brow): ?>
                <tr>
                    <td class="text-muted"><?= $i+1 ?></td>
                    <td>
                        <strong><?= e($brow['last_name'] . ', ' . $brow['first_name']) ?></strong>
                        <br><small class="text-muted"><?= e($brow['customer_number']) ?></small>
                    </td>
                    <td class="text-center"><?= $brow['loan_count'] ?></td>
                    <td class="text-end text-warning"><?= formatMoney($brow['total_outstanding']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($isFF && $insKpis): ?>
<!-- Versicherung (nur FF) -->
<h5 class="mb-3 text-muted"><i class="bi bi-heart-pulse me-2"></i>Krankenversicherung</h5>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= $insKpis['active'] ?></div>
                <div class="kpi-label">Aktive Einzelverträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($insKpis['monthly_premiums']) ?></div>
                <div class="kpi-label">Monatsbeiträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card warning">
            <div class="card-body text-center">
                <div class="kpi-value text-warning"><?= $insKpis['open_claims'] ?></div>
                <div class="kpi-label">Offene Anträge</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($insKpis['total_paid_claims']) ?></div>
                <div class="kpi-label">Ausgezahlte Leistungen</div>
            </div>
        </div>
    </div>
</div>

<!-- Arbeitgeber-KV -->
<?php if ($groupKpis): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= $groupKpis['employers'] ?></div>
                <div class="kpi-label">Aktive Arbeitgeber</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= $groupKpis['active_members'] ?></div>
                <div class="kpi-label">Versicherte Mitglieder</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= $groupKpis['active_groups'] ?></div>
                <div class="kpi-label">Gruppenverträge aktiv</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($groupKpis['monthly_group_fees']) ?></div>
                <div class="kpi-label">Monatsbeiträge Gruppen</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($insProducts)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-heart-pulse me-2"></i>Versicherungsprodukte – Übersicht</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Tarif</th>
                    <th>Typ</th>
                    <th class="text-center">Verträge</th>
                    <th class="text-end">Monatsbeiträge</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($insProducts as $ip): ?>
                <tr>
                    <td><?= e($ip['name']) ?></td>
                    <td>
                        <span class="badge bg-secondary">
                            <?= match($ip['type']) {
                                'PKV'=>'Private KV','GKV_ZUSATZ'=>'GKV-Zusatz',
                                'ZAHN'=>'Zahn','VISION'=>'Sehhilfe',
                                'PFLEGE'=>'Pflege','UNFALL'=>'Unfall',default=>$ip['type']
                            } ?>
                        </span>
                    </td>
                    <td class="text-center"><?= $ip['contracts'] ?></td>
                    <td class="text-end"><?= $ip['contracts'] > 0 ? formatMoney($ip['monthly']) : '–' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Schließfächer -->
<?php if ($sbKpis): ?>
<h5 class="mb-3 text-muted"><i class="bi bi-safe me-2"></i>Schließfächer</h5>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card kpi-card success">
            <div class="card-body text-center">
                <div class="kpi-value text-success"><?= $sbKpis['active'] ?></div>
                <div class="kpi-label">Aktive Schließfächer</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= $sbKpis['total'] ?></div>
                <div class="kpi-label">Gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card">
            <div class="card-body text-center">
                <div class="kpi-value"><?= formatMoney($sbKpis['weekly_revenue']) ?></div>
                <div class="kpi-label">Wochengebühren</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card kpi-card <?= $sbKpis['overdue_boxes'] > 0 ? 'warning' : '' ?>">
            <div class="card-body text-center">
                <div class="kpi-value <?= $sbKpis['overdue_boxes'] > 0 ? 'text-warning' : '' ?>">
                    <?= $sbKpis['overdue_boxes'] ?>
                </div>
                <div class="kpi-label">Fächer überfällig</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
