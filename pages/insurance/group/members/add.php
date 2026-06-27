<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Mitglied hinzufügen
 */
$pageTitle = 'Mitglied hinzufügen';
require_once __DIR__ . '/../../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$gcId = intval($_GET['group_contract_id'] ?? 0);

$gc = null;
if ($gcId) {
    $gc = Database::fetchOne("
        SELECT igc.*, ie.company_name
        FROM insurance_group_contracts igc
        JOIN insurance_employers ie ON igc.employer_id = ie.id
        WHERE igc.id = ? AND igc.bank_id = 2
    ", [$gcId]);
}

if (!$gc) {
    setFlash('error', 'Gruppenvertrag nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/insurance/employers/index.php');
    exit;
}

// Versicherungsklassen
const INSURANCE_CLASSES = [
    1 => ['label' => 'Klasse 1', 'monthly' => 150.00],
    2 => ['label' => 'Klasse 2', 'monthly' => 250.00],
    3 => ['label' => 'Klasse 3', 'monthly' => 400.00],
    4 => ['label' => 'Klasse 4', 'monthly' => 500.00],
];

$borrowers = Database::fetchAll(
    "SELECT id, customer_number, first_name, last_name FROM borrowers WHERE bank_id = 2 AND is_active = 1 ORDER BY last_name, first_name"
);

$errors = [];
$data = [
    'first_name'       => '',
    'last_name'        => '',
    'dob'              => '',
    'gender'           => '',
    'phone'            => '',
    'email'            => '',
    'address'          => '',
    'region'           => '',
    'iban'             => '',
    'insurance_class'  => 1,
    'borrower_id'      => 0,
    'start_date'       => $gc['start_date'],
    'end_date'         => '',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $data['first_name']      = trim($_POST['first_name']      ?? '');
        $data['last_name']       = trim($_POST['last_name']       ?? '');
        $data['dob']             = trim($_POST['dob']             ?? '');
        $data['gender']          = trim($_POST['gender']          ?? '');
        $data['phone']           = trim($_POST['phone']           ?? '');
        $data['email']           = trim($_POST['email']           ?? '');
        $data['address']         = trim($_POST['address']         ?? '');
        $data['region']          = trim($_POST['region']          ?? '');
        $data['iban']            = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $data['insurance_class'] = intval($_POST['insurance_class'] ?? 1);
        $data['borrower_id']     = intval($_POST['borrower_id']     ?? 0);
        $data['start_date']      = trim($_POST['start_date']        ?? '');
        $data['end_date']        = trim($_POST['end_date']          ?? '');
        $data['notes']           = trim($_POST['notes']             ?? '');

        if (!$data['first_name'])     $errors[] = 'Vorname ist Pflichtfeld.';
        if (!$data['last_name'])      $errors[] = 'Nachname ist Pflichtfeld.';
        if (!$data['start_date'])     $errors[] = 'Eintritt-Datum ist Pflichtfeld.';
        if (!isset(INSURANCE_CLASSES[$data['insurance_class']])) {
            $errors[] = 'Ungültige Versicherungsklasse.';
        }
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse.';
        }
        if ($data['end_date'] && $data['end_date'] <= $data['start_date']) {
            $errors[] = 'Austrittsdatum muss nach dem Eintrittsdatum liegen.';
        }

        if (empty($errors)) {
            $weeklyPremium = INSURANCE_CLASSES[$data['insurance_class']]['monthly'];

            try {
                $memberId = Database::insert('insurance_members', [
                    'group_contract_id' => $gcId,
                    'bank_id'           => 2,
                    'first_name'        => $data['first_name'],
                    'last_name'         => $data['last_name'],
                    'dob'               => $data['dob']     ?: null,
                    'gender'            => $data['gender']  ?: null,
                    'phone'             => $data['phone']   ?: null,
                    'email'             => $data['email']   ?: null,
                    'address'           => $data['address'] ?: null,
                    'region'            => $data['region']  ?: null,
                    'iban'              => $data['iban']    ?: null,
                    'insurance_class'   => $data['insurance_class'],
                    'premium_monthly'    => $weeklyPremium,
                    'borrower_id'       => $data['borrower_id'] ?: null,
                    'status'            => 'ACTIVE',
                    'start_date'        => $data['start_date'],
                    'end_date'          => $data['end_date'] ?: null,
                    'notes'             => $data['notes']   ?: null,
                ]);

                AuditLog::log('CREATE', 'insurance_member', $memberId, null, [
                    'name'             => $data['first_name'] . ' ' . $data['last_name'],
                    'group_contract'   => $gc['contract_number'],
                    'insurance_class'  => $data['insurance_class'],
                    'premium_monthly'   => $weeklyPremium,
                ]);

                setFlash('success', "{$data['first_name']} {$data['last_name']} als Mitglied hinzugefügt (Klasse {$data['insurance_class']}, \${$weeklyPremium}/Monat).");
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
            <a href="<?= APP_URL ?>/pages/insurance/employers/view.php?id=<?= $gc['employer_id'] ?>">
                <?= e($gc['company_name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $gcId ?>">
                <?= e($gc['contract_number']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active">Mitglied hinzufügen</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-person-plus me-2"></i>Mitglied hinzufügen</h4>
    <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $gcId ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<!-- Gruppenvertrag-Info -->
<div class="alert alert-info mb-4">
    <div class="row">
        <div class="col-md-4">
            <strong>Gruppenvertrag:</strong> <?= e($gc['contract_number']) ?>
        </div>
        <div class="col-md-4">
            <strong>Arbeitgeber:</strong> <?= e($gc['company_name']) ?>
        </div>
        <div class="col-md-4">
            <strong>Vertragsbeginn:</strong> <?= formatDate($gc['start_date']) ?>
        </div>
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

<form method="POST" id="memberForm">
    <?= csrfField() ?>

    <div class="row g-4">
        <div class="col-md-8">
            <!-- Versicherungsklasse -->
            <div class="card mb-4">
                <div class="card-header">Versicherungsklasse *</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach (INSURANCE_CLASSES as $cls => $info): ?>
                        <div class="col-md-3">
                            <div class="class-card border rounded p-3 text-center <?= $data['insurance_class'] == $cls ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                                 style="cursor:pointer;" onclick="selectClass(<?= $cls ?>)">
                                <input type="radio" name="insurance_class" value="<?= $cls ?>"
                                       id="class_<?= $cls ?>"
                                       <?= $data['insurance_class'] == $cls ? 'checked' : '' ?> required
                                       class="d-none">
                                <div class="fw-bold fs-5 mb-1"><?= $info['label'] ?></div>
                                <div class="text-success fw-bold fs-6">$<?= number_format($info['monthly'], 0) ?></div>
                                <small class="text-muted">pro Monat</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 alert alert-secondary py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Gewählte Klasse: <strong id="class_label">Klasse <?= $data['insurance_class'] ?></strong>
                        — Monatsbeitrag: <strong id="class_weekly">$<?= number_format(INSURANCE_CLASSES[$data['insurance_class']]['monthly'], 0) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Persönliche Daten -->
            <div class="card mb-4">
                <div class="card-header">Persönliche Daten</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Verknüpfung mit Kreditnehmer (optional)</label>
                            <select class="form-select" name="borrower_id" id="borrower_id"
                                    onchange="prefillFromBorrower(this)">
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
                            <input type="text" class="form-control" name="first_name"
                                   id="first_name" value="<?= e($data['first_name']) ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nachname *</label>
                            <input type="text" class="form-control" name="last_name"
                                   id="last_name" value="<?= e($data['last_name']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Geschlecht</label>
                            <select class="form-select" name="gender">
                                <option value="">–</option>
                                <option value="M" <?= $data['gender'] === 'M' ? 'selected' : '' ?>>M</option>
                                <option value="F" <?= $data['gender'] === 'F' ? 'selected' : '' ?>>W</option>
                                <option value="D" <?= $data['gender'] === 'D' ? 'selected' : '' ?>>D</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Geburtsdatum</label>
                            <input type="date" class="form-control" name="dob"
                                   value="<?= e($data['dob']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?= e($data['phone']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= e($data['email']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address"
                                   value="<?= e($data['address']) ?>" placeholder="Straße, PLZ Ort">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Region <span class="text-muted">(Info)</span></label>
                            <input type="text" class="form-control" name="region"
                                   value="<?= e($data['region']) ?>" placeholder="z.B. Nord, Süd, Ost...">
                            <div class="form-text">Nur Informationsfeld</div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">IBAN (Auszahlung)</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($data['iban']) ?>" placeholder="DE...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Versicherungszeitraum -->
            <div class="card">
                <div class="card-header">Versicherungszeitraum & Notizen</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Eintrittsdatum *</label>
                            <input type="date" class="form-control" name="start_date"
                                   value="<?= e($data['start_date']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Austrittsdatum</label>
                            <input type="date" class="form-control" name="end_date"
                                   value="<?= e($data['end_date']) ?>">
                            <div class="form-text">Leer = aktiv</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="2"><?= e($data['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte: Zusammenfassung -->
        <div class="col-md-4">
            <div class="card sticky-top" style="top: 1rem;">
                <div class="card-header">Beitragsvorschau</div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tr>
                            <td class="text-muted">Versicherungsklasse</td>
                            <td id="sum_class">Klasse <?= $data['insurance_class'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Monatsbeitrag</td>
                            <td><strong id="sum_weekly">$<?= number_format(INSURANCE_CLASSES[$data['insurance_class']]['monthly'], 2, ',', '.') ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Jahresbeitrag</td>
                            <td id="sum_annual">$<?= number_format(INSURANCE_CLASSES[$data['insurance_class']]['monthly'] * 12, 2, ',', '.') ?></td>
                        </tr>
                    </table>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-person-check me-2"></i>Mitglied hinzufügen
                    </button>
                    <a href="<?= APP_URL ?>/pages/insurance/group/view.php?id=<?= $gcId ?>"
                       class="btn btn-outline-secondary w-100">Abbrechen</a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const classData = {
    <?php foreach (INSURANCE_CLASSES as $cls => $info): ?>
    <?= $cls ?>: { label: '<?= $info['label'] ?>', weekly: <?= $info['monthly'] ?> },
    <?php endforeach; ?>
};

function selectClass(cls) {
    document.querySelectorAll('.class-card').forEach(c => {
        c.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
    });
    const card = document.getElementById('class_' + cls);
    if (card) {
        card.checked = true;
        card.closest('.class-card').classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
    }
    const info = classData[cls];
    if (!info) return;

    document.getElementById('class_label').textContent = info.label;
    document.getElementById('class_weekly').textContent = '$' + info.weekly.toFixed(0);

    const fmt = v => '$' + v.toFixed(2).replace('.', ',');
    document.getElementById('sum_class').textContent  = info.label;
    document.getElementById('sum_weekly').textContent = fmt(info.weekly);
    document.getElementById('sum_annual').textContent  = fmt(info.weekly * 12);
}

function prefillFromBorrower(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('first_name').value = opt.dataset.first || '';
        document.getElementById('last_name').value  = opt.dataset.last  || '';
    }
}

// Klick auf Label
document.querySelectorAll('[name=insurance_class]').forEach(r => {
    r.addEventListener('change', () => selectClass(parseInt(r.value)));
});
</script>

<?php require_once __DIR__ . '/../../../../includes/footer.php'; ?>
