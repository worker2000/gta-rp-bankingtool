<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Gruppenvertrag anlegen
 */
$pageTitle = 'Gruppenvertrag anlegen';
require_once __DIR__ . '/../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$employerId = intval($_GET['employer_id'] ?? 0);

$employer = null;
if ($employerId) {
    $employer = Database::fetchOne(
        "SELECT * FROM insurance_employers WHERE id = ? AND bank_id = 2",
        [$employerId]
    );
}

if (!$employer) {
    setFlash('error', 'Arbeitgeber nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

$products = Database::fetchAll(
    "SELECT * FROM insurance_products WHERE bank_id = 2 AND is_active = 1 ORDER BY sort_order"
);

$errors = [];
$data = [
    'product_id'     => 0,
    'start_date'     => date('Y-m-d'),
    'end_date'       => '',
    'staff_initials' => '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $data['product_id']     = intval($_POST['product_id'] ?? 0);
        $data['start_date']     = trim($_POST['start_date'] ?? '');
        $data['end_date']       = trim($_POST['end_date']   ?? '');
        $data['staff_initials'] = strtoupper(trim($_POST['staff_initials'] ?? ''));
        $data['notes']          = trim($_POST['notes']      ?? '');

        if (!$data['product_id'])  $errors[] = 'Tarif auswählen.';
        if (!$data['start_date'])  $errors[] = 'Vertragsbeginn ist Pflichtfeld.';
        if ($data['end_date'] && $data['end_date'] <= $data['start_date']) {
            $errors[] = 'Vertragsende muss nach dem Vertragsbeginn liegen.';
        }

        $product = null;
        if ($data['product_id']) {
            $product = Database::fetchOne(
                "SELECT * FROM insurance_products WHERE id = ? AND bank_id = 2", [$data['product_id']]
            );
            if (!$product) $errors[] = 'Ungültiger Tarif.';
        }

        if (empty($errors) && $product) {
            // Vertragsnummer generieren: FF-GV-YYYY-NNNNN
            $year = date('Y', strtotime($data['start_date']));
            $lastGC = Database::fetchOne(
                "SELECT contract_number FROM insurance_group_contracts WHERE bank_id = 2 ORDER BY id DESC LIMIT 1"
            );
            $nextSeq = 1;
            if ($lastGC) {
                preg_match('/(\d+)$/', $lastGC['contract_number'], $m);
                $nextSeq = intval($m[1] ?? 0) + 1;
            }
            $contractNumber = sprintf('FF-GV-%s-%05d', $year, $nextSeq);

            try {
                $gcId = Database::insert('insurance_group_contracts', [
                    'bank_id'         => 2,
                    'employer_id'     => $employerId,
                    'contract_number' => $contractNumber,
                    'product_id'      => $data['product_id'],
                    'start_date'      => $data['start_date'],
                    'end_date'        => $data['end_date'] ?: null,
                    'status'          => 'APPLIED',
                    'notes'           => $data['notes'] ?: null,
                    'created_by'      => Auth::userId(),
                    'staff_initials'  => $data['staff_initials'] ?: null,
                ]);

                AuditLog::log('CREATE', 'insurance_group_contract', $gcId, null, [
                    'contract_number' => $contractNumber,
                    'employer'        => $employer['company_name'],
                    'product'         => $product['name'],
                ]);

                setFlash('success', "Gruppenvertrag {$contractNumber} angelegt. Jetzt Mitglieder hinzufügen.");
                header('Location: ' . APP_URL . '/pages/insurance/group/view.php?id=' . $gcId);
                exit;

            } catch (Exception $e) {
                $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }
    }
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/index.php">Krankenversicherung</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/insurance/employers/index.php">Arbeitgeber</a></li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $employerId ?>">
                <?= e($employer['company_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active">Gruppenvertrag anlegen</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-file-earmark-plus me-2"></i>Gruppenvertrag anlegen</h4>
    <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $employerId ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<!-- Arbeitgeber-Info -->
<div class="alert alert-info mb-4">
    <div class="row">
        <div class="col-md-4">
            <strong>Arbeitgeber:</strong> <?= e($employer['company_name']) ?>
        </div>
        <?php if ($employer['contact_person']): ?>
        <div class="col-md-4">
            <strong>Ansprechpartner:</strong> <?= e($employer['contact_person']) ?>
        </div>
        <?php endif; ?>
        <?php if ($employer['email']): ?>
        <div class="col-md-4">
            <strong>E-Mail:</strong> <?= e($employer['email']) ?>
        </div>
        <?php endif; ?>
    </div>
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

<form method="POST" id="gcForm">
    <?= csrfField() ?>

    <div class="row g-4">
        <div class="col-md-8">
            <!-- Tarifauswahl -->
            <div class="card mb-4">
                <div class="card-header">Tarifauswahl *</div>
                <div class="card-body">
                    <?php if (empty($products)): ?>
                    <div class="alert alert-warning mb-0">Keine Tarife verfügbar. Bitte zuerst Tarife anlegen.</div>
                    <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($products as $p): ?>
                        <div class="col-md-6">
                            <div class="product-card border rounded p-3 h-100 <?= $data['product_id'] == $p['id'] ? 'border-primary' : '' ?>"
                                 style="cursor:pointer;"
                                 onclick="selectProduct(<?= $p['id'] ?>)">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="product_id"
                                           id="product_<?= $p['id'] ?>" value="<?= $p['id'] ?>"
                                           <?= $data['product_id'] == $p['id'] ? 'checked' : '' ?> required>
                                    <label class="form-check-label w-100" for="product_<?= $p['id'] ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?= e($p['name']) ?></strong>
                                            <span class="text-success fw-bold"><?= formatMoney($p['monthly_base_premium']) ?>/Mo.</span>
                                        </div>
                                        <?php if ($p['description']): ?>
                                        <small class="text-muted d-block mt-1"><?= e($p['description']) ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Laufzeit -->
            <div class="card">
                <div class="card-header">Laufzeit & Notizen</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Vertragsbeginn *</label>
                            <input type="date" class="form-control" name="start_date"
                                   value="<?= e($data['start_date']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Vertragsende</label>
                            <input type="date" class="form-control" name="end_date"
                                   value="<?= e($data['end_date']) ?>">
                            <div class="form-text">Leer = unbefristet</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bearbeiter-Kürzel</label>
                            <input type="text" class="form-control" name="staff_initials"
                                   value="<?= e($data['staff_initials']) ?>" maxlength="10"
                                   placeholder="z.B. LdM, RW">
                            <div class="form-text">Initialen des zuständigen Mitarbeiters</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($data['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card sticky-top" style="top: 1rem;">
                <div class="card-header">Zusammenfassung</div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tr>
                            <td class="text-muted">Arbeitgeber</td>
                            <td><strong><?= e($employer['company_name']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Vertragsnr.</td>
                            <td><span class="text-muted small">FF-GV-<?= date('Y') ?>-NNNNN</span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tarif</td>
                            <td id="sum_product"><span class="text-muted">– wählen –</span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Versicherungsklassen</td>
                            <td>
                                <small>Klasse 1: $100/Monat<br>
                                Klasse 2: $200/Monat<br>
                                Klasse 3: $300/Monat<br>
                                Klasse 4: $400/Monat</small>
                            </td>
                        </tr>
                    </table>
                    <div class="alert alert-secondary small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Wochenbeiträge werden pro Mitglied nach Versicherungsklasse berechnet.
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-check-lg me-2"></i>Gruppenvertrag anlegen
                    </button>
                    <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $employerId ?>"
                       class="btn btn-outline-secondary w-100">Abbrechen</a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const productNames = {
    <?php foreach ($products as $p): ?>
    <?= $p['id'] ?>: '<?= e(addslashes($p['name'])) ?>',
    <?php endforeach; ?>
};

function selectProduct(id) {
    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('border-primary'));
    const radio = document.getElementById('product_' + id);
    if (radio) {
        radio.checked = true;
        radio.closest('.product-card').classList.add('border-primary');
    }
    document.getElementById('sum_product').textContent = productNames[id] || '–';
}

document.querySelectorAll('[name=product_id]').forEach(r => {
    r.addEventListener('change', () => selectProduct(parseInt(r.value)));
});

<?php if ($data['product_id']): ?>
selectProduct(<?= $data['product_id'] ?>);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
