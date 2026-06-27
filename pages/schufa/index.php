<?php
ob_start();
$pageTitle = 'Kreditauskunft';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

if (!Auth::can('loans', 'view')) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$myBankId = currentBankId();

// Alle Banken laden für Anzeige
$allBanks = Database::fetchAll("SELECT id, name, short_code, primary_color FROM banks WHERE is_active = 1 ORDER BY id");
$bankMap  = [];
foreach ($allBanks as $bk) {
    $bankMap[$bk['id']] = $bk;
}

// ──────────────────────────────────────────────────────────────
// Suche
// ──────────────────────────────────────────────────────────────
$searchName  = trim($_GET['name']  ?? '');
$searchDob   = trim($_GET['dob']   ?? '');
$searchPhone = trim($_GET['phone'] ?? '');
$searched    = false;
$results     = [];

if (!empty($searchName) || !empty($searchDob) || !empty($searchPhone)) {
    $searched = true;

    $nameParts = preg_split('/\s+/', $searchName, 2);
    $part1     = $nameParts[0] ?? '';
    $part2     = $nameParts[1] ?? '';

    $conditions = [];
    $params     = [];

    if ($part2) {
        // "Vorname Nachname" oder "Nachname Vorname" – LIKE + LEFT()-Prefix für Schreibvarianten
        $pfx1 = mb_substr($part1, 0, 3);
        $pfx2 = mb_substr($part2, 0, 3);
        $conditions[] = "(
            (b.first_name LIKE ? AND b.last_name LIKE ?)
            OR (b.first_name LIKE ? AND b.last_name LIKE ?)
            OR (LEFT(b.first_name,3) = ? AND b.last_name LIKE ?)
            OR (b.first_name LIKE ? AND LEFT(b.last_name,3) = ?)
        )";
        $params[] = "%$part1%"; $params[] = "%$part2%";
        $params[] = "%$part2%"; $params[] = "%$part1%";
        $params[] = $pfx1;      $params[] = "%$part2%";
        $params[] = "%$part1%"; $params[] = $pfx2;
    } elseif ($part1) {
        $conditions[] = "(b.first_name LIKE ? OR b.last_name LIKE ?)";
        $params[] = "%$part1%"; $params[] = "%$part1%";
    }

    if ($searchDob) {
        $conditions[] = "b.date_of_birth = ?";
        $params[] = $searchDob;
    }

    if ($searchPhone) {
        $conditions[] = "b.phone LIKE ?";
        $params[] = "%$searchPhone%";
    }

    if (empty($conditions)) {
        $searched = false;
    } else {
        $where     = implode(' AND ', $conditions);
        $borrowers = Database::fetchAll("
            SELECT b.*
            FROM borrowers b
            WHERE $where
            ORDER BY b.last_name, b.first_name, b.bank_id
        ", $params);

        foreach ($borrowers as &$row) {
            $loans = Database::fetchAll("
                SELECT id, file_number, product_type, status,
                       loan_amount, outstanding_balance, days_overdue,
                       start_date, end_date, vehicle_model
                FROM loans
                WHERE borrower_id = ?
                ORDER BY created_at DESC
            ", [$row['id']]);

            $row['loans']      = $loans;
            $row['is_own']     = ($row['bank_id'] == $myBankId);
            $row['bank_info']  = $bankMap[$row['bank_id']] ?? [];

            // ── Statistiken ────────────────────────────────────
            $activeSet   = ['ACTIVE','CONTRACT_CREATED','DUNNING_L1','DUNNING_L2','REPOSSESSION'];
            $problemSet  = ['TERMINATED','REPOSSESSION'];

            $activeLoans    = array_values(array_filter($loans, fn($l) => in_array($l['status'], $activeSet)));
            $negLoans       = array_values(array_filter($loans, fn($l) => in_array($l['status'], $problemSet)));
            $closedLoans    = array_values(array_filter($loans, fn($l) => $l['status'] === 'CLOSED'));
            $d1Loans        = array_values(array_filter($loans, fn($l) => $l['status'] === 'DUNNING_L1'));
            $d2Loans        = array_values(array_filter($loans, fn($l) => $l['status'] === 'DUNNING_L2'));
            $repoLoans      = array_values(array_filter($loans, fn($l) => $l['status'] === 'REPOSSESSION'));
            $overdueLoans   = array_values(array_filter($loans, fn($l) => ($l['days_overdue'] ?? 0) > 14));

            $totalOutstanding = array_sum(array_column($activeLoans, 'outstanding_balance'));

            $row['stats'] = [
                'total'       => count($loans),
                'active'      => count($activeLoans),
                'closed'      => count($closedLoans),
                'negative'    => count($negLoans),
                'dunning1'    => count($d1Loans),
                'dunning2'    => count($d2Loans),
                'repo'        => count($repoLoans),
                'overdue'     => count($overdueLoans),
                'outstanding' => $totalOutstanding,
            ];

            // ── Risiko-Ampel ───────────────────────────────────
            if (count($repoLoans) > 0 || count($d2Loans) > 0 || count($negLoans) >= 2) {
                $row['risk'] = 'red';
            } elseif (count($negLoans) > 0 || count($d1Loans) > 0 || count($overdueLoans) > 0) {
                $row['risk'] = 'yellow';
            } else {
                $row['risk'] = 'green';
            }
        }
        unset($row);

        $results = $borrowers;

        // Audit
        AuditLog::log('SEARCH', 'credit_inquiry', 0, null, [
            'query_name'  => $searchName,
            'query_dob'   => $searchDob,
            'query_phone' => $searchPhone,
            'hits'        => count($results),
        ]);
    }
}

// ──────────────────────────────────────────────────────────────
// Status-Labels & -Farben
// ──────────────────────────────────────────────────────────────
$statusLabels = [
    'APPLICATION_RECEIVED' => ['label' => 'Antrag', 'class' => 'bg-secondary'],
    'IN_REVIEW'            => ['label' => 'Prüfung', 'class' => 'bg-info text-dark'],
    'APPROVED'             => ['label' => 'Bewilligt', 'class' => 'bg-info text-dark'],
    'REJECTED'             => ['label' => 'Abgelehnt', 'class' => 'bg-danger'],
    'CONTRACT_CREATED'     => ['label' => 'Vertrag', 'class' => 'bg-primary'],
    'ACTIVE'               => ['label' => 'Aktiv', 'class' => 'bg-success'],
    'DUNNING_L1'           => ['label' => 'Mahnung 1', 'class' => 'bg-warning text-dark'],
    'DUNNING_L2'           => ['label' => 'Mahnung 2', 'class' => 'bg-orange text-white'],
    'TERMINATED'           => ['label' => 'Gekündigt', 'class' => 'bg-danger'],
    'REPOSSESSION'         => ['label' => 'Sicherstellung', 'class' => 'bg-danger'],
    'CLOSED'               => ['label' => 'Abgeschlossen', 'class' => 'bg-secondary'],
    'WITHDRAWN'            => ['label' => 'Widerruf',      'class' => 'bg-light text-secondary border'],
];
$productLabels = [
    'AUTO'      => 'Fahrzeugkredit',
    'PRIVATE'   => 'Privatkredit',
    'BUSINESS'  => 'Unternehmenskredit',
    'INSURANCE' => 'Versicherungskredit',
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>Interbanken-Kreditauskunft</h4>
        <small class="text-muted">Kredithistorie bankübergreifend prüfen</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php foreach ($allBanks as $bk): ?>
        <span class="badge" style="background:<?= e($bk['primary_color']) ?>; font-size:.8rem;">
            <?= e($bk['short_code']) ?>
        </span>
        <?php endforeach; ?>
        <span class="text-muted small ms-1">verbundene Banken</span>
    </div>
</div>

<!-- ── Suchformular ───────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-search me-2"></i>Person suchen</div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name"
                       value="<?= e($searchName) ?>"
                       placeholder="Vorname Nachname oder Nachname"
                       autofocus>
                <div class="form-text">Mindestens Nachname angeben</div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Geburtsdatum</label>
                <input type="date" class="form-control" name="dob"
                       value="<?= e($searchDob) ?>">
                <div class="form-text">Erhöht Treffsicherheit</div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Telefon</label>
                <input type="text" class="form-control" name="phone"
                       value="<?= e($searchPhone) ?>"
                       placeholder="Teilnummer möglich">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i>Auskunft
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<!-- ── Ergebnisse ─────────────────────────────────────────────── -->

<?php if (empty($results)): ?>
<div class="alert alert-info d-flex align-items-center gap-3">
    <i class="bi bi-info-circle fs-4"></i>
    <div>
        <strong>Keine Treffer gefunden.</strong><br>
        <span class="text-muted small">Keine Person mit diesen Angaben in beiden Banken registriert.</span>
    </div>
</div>

<?php else: ?>

<!-- Risiko-Übersicht oben -->
<?php
$redCount    = count(array_filter($results, fn($r) => $r['risk'] === 'red'));
$yellowCount = count(array_filter($results, fn($r) => $r['risk'] === 'yellow'));
$greenCount  = count(array_filter($results, fn($r) => $r['risk'] === 'green'));
$ownCount    = count(array_filter($results, fn($r) => $r['is_own']));
$crossCount  = count(array_filter($results, fn($r) => !$r['is_own']));
?>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold"><?= count($results) ?></div>
                <div class="text-muted small">Treffer gesamt</div>
                <div class="mt-1">
                    <span class="badge bg-primary"><?= $ownCount ?> eigene Bank</span>
                    <?php if ($crossCount > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $crossCount ?> Fremdbank</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100 <?= $redCount > 0 ? 'border-danger' : '' ?>">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-danger"><?= $redCount ?></div>
                <div class="text-muted small">Hohes Risiko</div>
                <div class="mt-1 small text-danger">Mahnung 2 / Sicherstellung / Kündigung</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100 <?= $yellowCount > 0 ? 'border-warning' : '' ?>">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-warning"><?= $yellowCount ?></div>
                <div class="text-muted small">Mittleres Risiko</div>
                <div class="mt-1 small text-warning">Verzug / Mahnung 1 / Kündigung (hist.)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100 <?= $greenCount > 0 ? 'border-success' : '' ?>">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-success"><?= $greenCount ?></div>
                <div class="text-muted small">Unauffällig</div>
                <div class="mt-1 small text-success">Keine negativen Einträge</div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($results as $r):
    $stats   = $r['stats'];
    $bkInfo  = $r['bank_info'];
    $riskColor = match($r['risk']) {
        'red'    => '#dc3545',
        'yellow' => '#ffc107',
        default  => '#198754',
    };
    $riskLabel = match($r['risk']) {
        'red'    => 'Hohes Risiko',
        'yellow' => 'Mittleres Risiko',
        default  => 'Unauffällig',
    };
    $riskIcon = match($r['risk']) {
        'red'    => 'bi-x-octagon-fill',
        'yellow' => 'bi-exclamation-triangle-fill',
        default  => 'bi-check-circle-fill',
    };
    $primaryColor = $bkInfo['primary_color'] ?? '#6c757d';
?>
<div class="card mb-3 <?= $r['risk'] === 'red' ? 'border-danger' : ($r['risk'] === 'yellow' ? 'border-warning' : '') ?>">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <!-- Links: Ampel + Name -->
        <div class="d-flex align-items-center gap-3">
            <!-- Ampel -->
            <div style="width:42px;height:42px;border-radius:50%;background:<?= $riskColor ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;
                        box-shadow:0 0 10px <?= $riskColor ?>88;">
                <i class="bi <?= $riskIcon ?> text-white fs-5"></i>
            </div>
            <div>
                <div class="fw-bold fs-5">
                    <?= e($r['last_name']) ?>, <?= e($r['first_name']) ?>
                </div>
                <div class="text-muted small">
                    <?php if ($r['date_of_birth']): ?>
                    <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y', strtotime($r['date_of_birth'])) ?>
                    <?php endif; ?>
                    <?php if ($r['phone']): ?>
                    <span class="ms-2"><i class="bi bi-telephone me-1"></i><?= e($r['phone']) ?></span>
                    <?php endif; ?>
                    <?php if ($r['email']): ?>
                    <span class="ms-2"><i class="bi bi-envelope me-1"></i><?= e($r['email']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="small mt-1">
                    <span style="color:<?= $riskColor ?>;font-weight:600;">
                        <i class="bi <?= $riskIcon ?> me-1"></i><?= $riskLabel ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Rechts: Bank-Badge + Link (nur eigene Bank) -->
        <div class="d-flex align-items-center gap-3">
            <div class="text-end">
                <div>
                    <span class="badge fs-6 px-3 py-2"
                          style="background:<?= e($primaryColor) ?>;">
                        <?= e($bkInfo['short_code'] ?? '') ?>
                    </span>
                    <?php if ($r['is_own']): ?>
                    <span class="badge bg-primary ms-1">Eigene Bank</span>
                    <?php else: ?>
                    <span class="badge bg-secondary ms-1">Fremdbank</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-1"><?= e($bkInfo['name'] ?? '') ?></div>
            </div>
            <?php if ($r['is_own']): ?>
            <a href="<?= APP_URL ?>/pages/borrowers/view.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-person-lines-fill me-1"></i>Kundenprofil
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">
        <!-- Statistik-Zeile -->
        <div class="row g-3 mb-3">
            <div class="col-auto">
                <div class="text-muted small">Kredite gesamt</div>
                <div class="fw-bold"><?= $stats['total'] ?></div>
            </div>
            <div class="col-auto">
                <div class="text-muted small">Aktiv</div>
                <div class="fw-bold text-info"><?= $stats['active'] ?></div>
            </div>
            <div class="col-auto">
                <div class="text-muted small">Abgeschlossen</div>
                <div class="fw-bold text-success"><?= $stats['closed'] ?></div>
            </div>
            <div class="col-auto">
                <div class="text-muted small">Negative Einträge</div>
                <div class="fw-bold <?= $stats['negative'] > 0 ? 'text-danger' : 'text-muted' ?>">
                    <?= $stats['negative'] ?>
                </div>
            </div>
            <?php if ($stats['dunning1'] > 0 || $stats['dunning2'] > 0): ?>
            <div class="col-auto">
                <div class="text-muted small">Mahnwesen</div>
                <div class="fw-bold text-warning">
                    <?php if ($stats['dunning1'] > 0): ?>
                    <span class="me-1">M1: <?= $stats['dunning1'] ?></span>
                    <?php endif; ?>
                    <?php if ($stats['dunning2'] > 0): ?>
                    <span>M2: <?= $stats['dunning2'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($stats['repo'] > 0): ?>
            <div class="col-auto">
                <div class="text-muted small">Sicherstellung</div>
                <div class="fw-bold text-danger"><?= $stats['repo'] ?></div>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <div class="text-muted small">Offene Schuld</div>
                <div class="fw-bold <?= $stats['outstanding'] > 0 ? 'text-warning' : 'text-muted' ?>">
                    <?= $stats['outstanding'] > 0 ? formatMoney($stats['outstanding']) : '—' ?>
                </div>
            </div>
        </div>

        <?php if (empty($r['loans'])): ?>
        <div class="text-muted small fst-italic">Keine Kredite in dieser Bank erfasst.</div>

        <?php elseif ($r['is_own']): ?>
        <!-- ── EIGENE BANK: Vollständige Kredit-Tabelle ── -->
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Aktenzeichen</th>
                        <th>Art</th>
                        <th>Status</th>
                        <th>Kreditsumme</th>
                        <th>Ausstehend</th>
                        <th>Verzug (Tage)</th>
                        <th>Fahrzeug</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['loans'] as $loan):
                        $sl = $statusLabels[$loan['status']] ?? ['label' => $loan['status'], 'class' => 'bg-secondary'];
                    ?>
                    <tr>
                        <td><code><?= e($loan['file_number']) ?></code></td>
                        <td><?= e($productLabels[$loan['product_type']] ?? $loan['product_type']) ?></td>
                        <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
                        <td><?= formatMoney($loan['loan_amount']) ?></td>
                        <td><?= $loan['outstanding_balance'] > 0 ? formatMoney($loan['outstanding_balance']) : '<span class="text-success">—</span>' ?></td>
                        <td>
                            <?php if (($loan['days_overdue'] ?? 0) > 0): ?>
                            <span class="badge bg-<?= $loan['days_overdue'] > 14 ? 'danger' : 'warning text-dark' ?>">
                                <?= $loan['days_overdue'] ?>d
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $loan['vehicle_model'] ? e($loan['vehicle_model']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/pages/loans/view.php?id=<?= $loan['id'] ?>"
                               class="btn btn-xs btn-outline-secondary" title="Kredit öffnen">
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <!-- ── FREMDBANK: Zusammenfassung (kein Detail) ── -->
        <div class="alert alert-secondary py-2 mb-0 d-flex align-items-center gap-3">
            <i class="bi bi-eye-slash fs-4 text-muted"></i>
            <div class="small">
                <strong>Detailansicht nicht verfügbar</strong> –
                Daten stammen aus einer anderen Bank.
                Angezeigte Informationen sind auf das Risikoprofil beschränkt.
                <div class="mt-2 d-flex flex-wrap gap-3">
                    <?php foreach ($r['loans'] as $loan):
                        $sl = $statusLabels[$loan['status']] ?? ['label' => $loan['status'], 'class' => 'bg-secondary'];
                    ?>
                    <div>
                        <span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span>
                        <span class="ms-1"><?= e($productLabels[$loan['product_type']] ?? '') ?></span>
                        <?php if ($loan['vehicle_model']): ?>
                        <span class="text-muted ms-1">(<?= e($loan['vehicle_model']) ?>)</span>
                        <?php endif; ?>
                        <?php if (($loan['days_overdue'] ?? 0) > 0): ?>
                        <span class="badge bg-<?= $loan['days_overdue'] > 14 ? 'danger' : 'warning text-dark' ?> ms-1">
                            <?= $loan['days_overdue'] ?>d Verzug
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php if (!$searched): ?>
<!-- Startseite / Hinweise -->
<div class="row g-4 mt-1">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Wie funktioniert die Auskunft?</div>
            <div class="card-body">
                <ul class="mb-0 ps-3">
                    <li>Suche nach <strong>Name + Geburtsdatum</strong> für präzise Treffer</li>
                    <li>Alle Banken im System werden durchsucht (<?= implode(', ', array_column($allBanks, 'short_code')) ?>)</li>
                    <li>Eigene Bankdaten: <strong>vollständige Kredit-Details</strong></li>
                    <li>Fremdbank-Daten: <strong>Risiko-Zusammenfassung</strong> (keine Kontodaten)</li>
                    <li>Jede Abfrage wird im Audit-Log protokolliert</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-traffic-light me-2"></i>Risiko-Ampel</div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:36px;height:36px;border-radius:50%;background:#198754;flex-shrink:0;
                                    box-shadow:0 0 8px #19875488;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-check-circle-fill text-white"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-success">Unauffällig</div>
                            <div class="small text-muted">Keine Mahnungen, keine Kündigungen, kein Verzug</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:36px;height:36px;border-radius:50%;background:#ffc107;flex-shrink:0;
                                    box-shadow:0 0 8px #ffc10788;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-exclamation-triangle-fill text-dark"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-warning">Mittleres Risiko</div>
                            <div class="small text-muted">Mahnung Stufe 1, Verzug &gt;14 Tage, oder 1 Kündigung in der Vergangenheit</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:36px;height:36px;border-radius:50%;background:#dc3545;flex-shrink:0;
                                    box-shadow:0 0 8px #dc354588;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-x-octagon-fill text-white"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-danger">Hohes Risiko</div>
                            <div class="small text-muted">Mahnung Stufe 2, Sicherstellung, oder mehrfache Kündigungen</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.btn-xs {
    padding: .1rem .35rem;
    font-size: .75rem;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
