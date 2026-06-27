<?php
ob_start();
/**
 * Fortis Finance – Krankenversicherung: Neuer Vertrag
 */
$pageTitle = 'Neuer Versicherungsvertrag';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors = [];
$data = [
    'product_id'         => intval($_GET['product_id'] ?? 0),
    'borrower_id'        => intval($_GET['borrower_id'] ?? 0),
    'insured_first_name' => '',
    'insured_last_name'  => '',
    'insured_dob'        => '',
    'insured_gender'     => '',
    'insured_phone'      => '',
    'insured_email'      => '',
    'insured_address'    => '',
    'insured_iban'       => '',
    'start_date'         => date('Y-m-d'),
    'end_date'           => '',
    'payment_interval'   => 'MONTHLY',
    'premium_amount'     => '',
    'risk_surcharge_pct' => '0',
    'pre_existing_conds' => '',
    'notes'              => '',
];

$products = Database::fetchAll(
    "SELECT * FROM insurance_products WHERE bank_id = 2 AND is_active = 1 ORDER BY sort_order"
);

$borrowers = Database::fetchAll(
    "SELECT id, customer_number, first_name, last_name FROM borrowers WHERE bank_id = 2 AND is_active = 1 ORDER BY last_name, first_name"
);

// Wenn Borrower vorausgewählt, Felder vorbefüllen
if ($data['borrower_id']) {
    $prefillBorrower = Database::fetchOne(
        "SELECT * FROM borrowers WHERE id = ? AND bank_id = 2", [$data['borrower_id']]
    );
    if ($prefillBorrower) {
        $data['insured_first_name'] = $prefillBorrower['first_name'];
        $data['insured_last_name']  = $prefillBorrower['last_name'];
        $data['insured_phone']      = $prefillBorrower['phone'] ?? '';
        $data['insured_email']      = $prefillBorrower['email'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        // Felder einlesen
        $data['product_id']         = intval($_POST['product_id'] ?? 0);
        $data['borrower_id']        = intval($_POST['borrower_id'] ?? 0);
        $data['insured_first_name'] = trim($_POST['insured_first_name'] ?? '');
        $data['insured_last_name']  = trim($_POST['insured_last_name'] ?? '');
        $data['insured_dob']        = trim($_POST['insured_dob'] ?? '');
        $data['insured_gender']     = trim($_POST['insured_gender'] ?? '');
        $data['insured_phone']      = trim($_POST['insured_phone'] ?? '');
        $data['insured_email']      = trim($_POST['insured_email'] ?? '');
        $data['insured_address']    = trim($_POST['insured_address'] ?? '');
        $data['insured_iban']       = strtoupper(preg_replace('/\s+/', '', $_POST['insured_iban'] ?? ''));
        $data['start_date']         = trim($_POST['start_date'] ?? '');
        $data['end_date']           = trim($_POST['end_date'] ?? '');
        $data['payment_interval']   = trim($_POST['payment_interval'] ?? 'MONTHLY');
        $data['risk_surcharge_pct'] = floatval($_POST['risk_surcharge_pct'] ?? 0);
        $data['pre_existing_conds'] = trim($_POST['pre_existing_conds'] ?? '');
        $data['notes']              = trim($_POST['notes'] ?? '');

        // Validierung
        if (!$data['product_id'])         $errors[] = 'Tarif auswählen.';
        if (!$data['insured_first_name'])  $errors[] = 'Vorname der versicherten Person ist Pflichtfeld.';
        if (!$data['insured_last_name'])   $errors[] = 'Nachname der versicherten Person ist Pflichtfeld.';
        if (!$data['start_date'])          $errors[] = 'Vertragsbeginn ist Pflichtfeld.';
        if ($data['risk_surcharge_pct'] < 0 || $data['risk_surcharge_pct'] > 200) {
            $errors[] = 'Risikozuschlag muss zwischen 0 und 200 % liegen.';
        }
        if ($data['insured_email'] && !filter_var($data['insured_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse.';
        }
        if ($data['end_date'] && $data['end_date'] <= $data['start_date']) {
            $errors[] = 'Vertragsende muss nach dem Vertragsbeginn liegen.';
        }

        // Produkt laden & Prämie berechnen
        $product = null;
        if ($data['product_id']) {
            $product = Database::fetchOne(
                "SELECT * FROM insurance_products WHERE id = ? AND bank_id = 2", [$data['product_id']]
            );
            if (!$product) $errors[] = 'Ungültiger Tarif.';
        }

        if (empty($errors) && $product) {
            $basePremium   = floatval($product['monthly_base_premium']);
            $surchargedPremium = round($basePremium * (1 + $data['risk_surcharge_pct'] / 100), 2);

            // Vertragsnummer generieren
            $year = date('Y', strtotime($data['start_date']));
            $lastContract = Database::fetchOne(
                "SELECT contract_number FROM insurance_contracts WHERE bank_id = 2 ORDER BY id DESC LIMIT 1"
            );
            $nextSeq = 1;
            if ($lastContract) {
                preg_match('/(\d+)$/', $lastContract['contract_number'], $m);
                $nextSeq = intval($m[1] ?? 0) + 1;
            }
            $contractNumber = sprintf('FF-KV-%s-%05d', $year, $nextSeq);

            Database::beginTransaction();
            try {
                $contractId = Database::insert('insurance_contracts', [
                    'bank_id'            => 2,
                    'contract_number'    => $contractNumber,
                    'borrower_id'        => $data['borrower_id'] ?: null,
                    'product_id'         => $data['product_id'],
                    'insured_first_name' => $data['insured_first_name'],
                    'insured_last_name'  => $data['insured_last_name'],
                    'insured_dob'        => $data['insured_dob'] ?: null,
                    'insured_gender'     => $data['insured_gender'] ?: null,
                    'insured_phone'      => $data['insured_phone'] ?: null,
                    'insured_email'      => $data['insured_email'] ?: null,
                    'insured_address'    => $data['insured_address'] ?: null,
                    'insured_iban'       => $data['insured_iban'] ?: null,
                    'start_date'         => $data['start_date'],
                    'end_date'           => $data['end_date'] ?: null,
                    'payment_interval'   => $data['payment_interval'],
                    'premium_amount'     => $surchargedPremium,
                    'risk_surcharge_pct' => $data['risk_surcharge_pct'],
                    'pre_existing_conds' => $data['pre_existing_conds'] ?: null,
                    'notes'              => $data['notes'] ?: null,
                    'status'             => 'APPLIED',
                    'created_by'         => Auth::userId(),
                ]);

                // Beitragszahlungsplan für die ersten 12 Monate generieren
                $premiumScheduleRows = generatePremiumSchedule(
                    $contractId, $surchargedPremium,
                    $data['payment_interval'], $data['start_date'],
                    $data['end_date'], 12
                );
                foreach ($premiumScheduleRows as $row) {
                    Database::insert('insurance_premium_schedule', $row);
                }

                AuditLog::log('CREATE', 'insurance_contract', $contractId, null, [
                    'contract_number' => $contractNumber,
                    'product'         => $product['name'],
                ]);

                Database::commit();
                setFlash('success', "Versicherungsvertrag {$contractNumber} erfolgreich angelegt.");
                header('Location: ' . APP_URL . '/pages/insurance/view.php?id=' . $contractId);
                exit;

            } catch (Exception $e) {
                Database::rollback();
                $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }
    }
}

/**
 * Beitragszahlungsplan generieren
 */
function generatePremiumSchedule(int $contractId, float $amount, string $interval, string $startDate, ?string $endDate, int $months): array {
    $rows  = [];
    $start = new DateTime($startDate);
    $end   = $endDate ? new DateTime($endDate) : null;

    switch ($interval) {
        case 'QUARTERLY': $step = 3; $factor = 3.0; break;
        case 'ANNUALLY':  $step = 12; $factor = 12.0; break;
        default:          $step = 1;  $factor = 1.0; break;
    }

    $periods = (int) ceil($months / $step);
    $current = clone $start;

    for ($i = 0; $i < $periods; $i++) {
        $dueDate = clone $current;

        // Nicht über Vertragsende hinaus
        if ($end && $dueDate > $end) break;

        $label = match($interval) {
            'QUARTERLY' => 'Q' . ceil($dueDate->format('n') / 3) . ' ' . $dueDate->format('Y'),
            'ANNUALLY'  => $dueDate->format('Y'),
            default     => $dueDate->format('M Y'),
        };

        $rows[] = [
            'contract_id'  => $contractId,
            'period_label' => $label,
            'due_date'     => $dueDate->format('Y-m-d'),
            'amount_due'   => round($amount * $factor, 2),
            'status'       => 'PENDING',
        ];

        $current->modify("+{$step} months");
    }
    return $rows;
}

function translateInterval(string $i): string {
    return match($i) {
        'MONTHLY'   => 'Monatlich',
        'QUARTERLY' => 'Vierteljährlich',
        'ANNUALLY'  => 'Jährlich',
        default     => $i,
    };
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-plus-circle me-2"></i>Neuer Versicherungsvertrag</h4>
    <a href="<?= APP_URL ?>/pages/insurance/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" id="contractForm">
    <?= csrfField() ?>

    <div class="row g-4">
        <!-- Linke Spalte -->
        <div class="col-md-8">

            <!-- Tarifauswahl -->
            <div class="card mb-4">
                <div class="card-header">Tarifauswahl</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($products as $p): ?>
                        <div class="col-md-6">
                            <div class="product-card border rounded p-3 h-100 <?= $data['product_id'] == $p['id'] ? 'border-primary' : '' ?>"
                                 style="cursor:pointer;" onclick="selectProduct(<?= $p['id'] ?>, <?= $p['monthly_base_premium'] ?>)">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="product_id"
                                           id="product_<?= $p['id'] ?>" value="<?= $p['id'] ?>"
                                           <?= $data['product_id'] == $p['id'] ? 'checked' : '' ?> required>
                                    <label class="form-check-label w-100" for="product_<?= $p['id'] ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?= e($p['name']) ?></strong>
                                            <span class="text-success fw-bold"><?= formatMoney($p['monthly_base_premium']) ?>/Mo.</span>
                                        </div>
                                        <small class="text-muted d-block mt-1"><?= e($p['description']) ?></small>
                                        <?php if ($p['waiting_period_days']): ?>
                                        <small class="text-warning"><i class="bi bi-clock me-1"></i>Wartezeit: <?= $p['waiting_period_days'] ?> Tage</small>
                                        <?php endif; ?>
                                        <?php if ($p['deductible'] > 0): ?>
                                        <br><small class="text-info"><i class="bi bi-shield me-1"></i>SB: <?= formatMoney($p['deductible']) ?>/Jahr</small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Versicherter -->
            <div class="card mb-4">
                <div class="card-header">Versicherter</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Verknüpfung mit Kreditnehmer (optional)</label>
                            <select class="form-select" name="borrower_id" id="borrower_id" onchange="prefillFromBorrower(this)">
                                <option value="">– Kein Kreditnehmer verknüpft –</option>
                                <?php foreach ($borrowers as $b): ?>
                                <option value="<?= $b['id'] ?>"
                                        data-first="<?= e($b['first_name']) ?>"
                                        data-last="<?= e($b['last_name']) ?>"
                                        <?= $data['borrower_id'] == $b['id'] ? 'selected' : '' ?>>
                                    <?= e($b['customer_number'] . ' – ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Vorname *</label>
                            <input type="text" class="form-control" name="insured_first_name"
                                   id="insured_first_name" value="<?= e($data['insured_first_name']) ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nachname *</label>
                            <input type="text" class="form-control" name="insured_last_name"
                                   id="insured_last_name" value="<?= e($data['insured_last_name']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Geschlecht</label>
                            <select class="form-select" name="insured_gender">
                                <option value="">–</option>
                                <option value="M" <?= $data['insured_gender'] === 'M' ? 'selected' : '' ?>>Männlich</option>
                                <option value="F" <?= $data['insured_gender'] === 'F' ? 'selected' : '' ?>>Weiblich</option>
                                <option value="D" <?= $data['insured_gender'] === 'D' ? 'selected' : '' ?>>Divers</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Geburtsdatum</label>
                            <input type="date" class="form-control" name="insured_dob"
                                   value="<?= e($data['insured_dob']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="insured_phone"
                                   id="insured_phone" value="<?= e($data['insured_phone']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="insured_email"
                                   id="insured_email" value="<?= e($data['insured_email']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="insured_address"
                                   value="<?= e($data['insured_address']) ?>" placeholder="Straße, PLZ Ort">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IBAN (Lastschrift)</label>
                            <input type="text" class="form-control" name="insured_iban"
                                   value="<?= e($data['insured_iban']) ?>" placeholder="DE...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vertragsdaten -->
            <div class="card mb-4">
                <div class="card-header">Vertragsdaten</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Vertragsbeginn *</label>
                            <input type="date" class="form-control" name="start_date"
                                   value="<?= e($data['start_date']) ?>" required onchange="updatePreview()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Vertragsende</label>
                            <input type="date" class="form-control" name="end_date"
                                   value="<?= e($data['end_date']) ?>">
                            <div class="form-text">Leer = unbefristet</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Zahlungsintervall</label>
                            <select class="form-select" name="payment_interval" onchange="updatePreview()">
                                <option value="MONTHLY"   <?= $data['payment_interval'] === 'MONTHLY'   ? 'selected' : '' ?>>Monatlich</option>
                                <option value="QUARTERLY" <?= $data['payment_interval'] === 'QUARTERLY' ? 'selected' : '' ?>>Vierteljährlich</option>
                                <option value="ANNUALLY"  <?= $data['payment_interval'] === 'ANNUALLY'  ? 'selected' : '' ?>>Jährlich</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Risikozuschlag (%)</label>
                            <input type="number" step="0.5" min="0" max="200" class="form-control"
                                   name="risk_surcharge_pct" id="risk_surcharge_pct"
                                   value="<?= e($data['risk_surcharge_pct']) ?>" onchange="updatePreview()">
                            <div class="form-text">0 = kein Zuschlag</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Vorerkrankungen</label>
                            <textarea class="form-control" name="pre_existing_conds" rows="2"
                                      placeholder="Bekannte Vorerkrankungen, relevante Diagnosen..."><?= e($data['pre_existing_conds']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="2"><?= e($data['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte: Vorschau -->
        <div class="col-md-4">
            <div class="card sticky-top" style="top: 1rem;">
                <div class="card-header">Beitragsberechnung</div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tr>
                            <td class="text-muted">Grundbeitrag</td>
                            <td class="text-end" id="prev_base">–</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Risikozuschlag</td>
                            <td class="text-end" id="prev_surcharge">–</td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>Monatsbeitrag</strong></td>
                            <td class="text-end"><strong id="prev_monthly">–</strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Zahlungsintervall</td>
                            <td class="text-end" id="prev_interval">–</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Fälliger Betrag</td>
                            <td class="text-end"><strong id="prev_due">–</strong></td>
                        </tr>
                    </table>
                    <div id="prev_waiting" class="alert alert-warning py-2 small mb-3" style="display:none;">
                        <i class="bi bi-clock me-1"></i><span id="prev_waiting_text"></span>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-lg me-2"></i>Vertrag anlegen
                    </button>
                    <a href="<?= APP_URL ?>/pages/insurance/index.php" class="btn btn-outline-secondary w-100">
                        Abbrechen
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Produkt-Daten aus PHP
const products = {
    <?php foreach ($products as $p): ?>
    <?= $p['id'] ?>: {
        base: <?= $p['monthly_base_premium'] ?>,
        waiting: <?= $p['waiting_period_days'] ?>,
        name: '<?= e($p['name']) ?>'
    },
    <?php endforeach; ?>
};

let selectedProductId = <?= $data['product_id'] ?: 0 ?>;
let basePremium = 0;

function selectProduct(id, base) {
    selectedProductId = id;
    basePremium = base;
    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('border-primary'));
    const radio = document.getElementById('product_' + id);
    if (radio) {
        radio.checked = true;
        radio.closest('.product-card').classList.add('border-primary');
    }
    updatePreview();
}

function updatePreview() {
    if (!selectedProductId) return;
    const p = products[selectedProductId];
    if (!p) return;

    const surcharge = parseFloat(document.getElementById('risk_surcharge_pct').value) || 0;
    const monthly   = p.base * (1 + surcharge / 100);
    const interval  = document.querySelector('[name=payment_interval]').value;

    let factor = 1, intervalLabel = 'Monatlich';
    if (interval === 'QUARTERLY') { factor = 3; intervalLabel = 'Vierteljährlich'; }
    if (interval === 'ANNUALLY')  { factor = 12; intervalLabel = 'Jährlich'; }

    const fmt = v => new Intl.NumberFormat('de-DE', {style:'currency', currency:'USD', minimumFractionDigits:2}).format(v);

    document.getElementById('prev_base').textContent     = fmt(p.base);
    document.getElementById('prev_surcharge').textContent = surcharge > 0 ? '+' + surcharge + '%' : '–';
    document.getElementById('prev_monthly').textContent  = fmt(monthly);
    document.getElementById('prev_interval').textContent = intervalLabel;
    document.getElementById('prev_due').textContent      = fmt(monthly * factor);

    const waitingEl = document.getElementById('prev_waiting');
    if (p.waiting > 0) {
        const startInput = document.querySelector('[name=start_date]').value;
        let waitingEnd = '';
        if (startInput) {
            const d = new Date(startInput);
            d.setDate(d.getDate() + p.waiting);
            waitingEnd = d.toLocaleDateString('de-DE');
        }
        document.getElementById('prev_waiting_text').textContent =
            'Wartezeit: ' + p.waiting + ' Tage' + (waitingEnd ? ' (bis ' + waitingEnd + ')' : '');
        waitingEl.style.display = '';
    } else {
        waitingEl.style.display = 'none';
    }
}

function prefillFromBorrower(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('insured_first_name').value = opt.dataset.first || '';
        document.getElementById('insured_last_name').value  = opt.dataset.last  || '';
    }
}

// Initialisierung
document.querySelectorAll('[name=product_id]').forEach(r => {
    r.addEventListener('change', () => {
        const id = parseInt(r.value);
        if (products[id]) {
            selectedProductId = id;
            basePremium = products[id].base;
            document.querySelectorAll('.product-card').forEach(c => c.classList.remove('border-primary'));
            r.closest('.product-card').classList.add('border-primary');
            updatePreview();
        }
    });
});

<?php if ($data['product_id'] && isset($products)): ?>
selectProduct(<?= $data['product_id'] ?>, <?= array_values(array_filter($products, fn($p) => $p['id'] == $data['product_id']))[0]['monthly_base_premium'] ?? 0 ?>);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
