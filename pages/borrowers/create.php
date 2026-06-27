<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kreditnehmer anlegen
 */
$pageTitle = 'Neuer Kreditnehmer';
require_once __DIR__ . '/../../includes/header.php';
Auth::requirePermission('borrowers', 'create');

$errors = [];
$data = [
    'salutation' => '',
    'first_name' => '',
    'last_name' => '',
    'date_of_birth' => '',
    'phone' => '',
    'email' => '',
    'employer' => '',
    'company' => '',
    'weekly_income' => '',
    'total_assets' => '',
    'bank_account_iban' => '',
    'bank_account_holder' => '',
    'notes' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        // Daten sammeln
        foreach ($data as $key => $val) {
            $data[$key] = trim($_POST[$key] ?? '');
        }

        // Validierung
        if (empty($data['first_name'])) $errors[] = 'Vorname ist erforderlich.';
        if (empty($data['last_name'])) $errors[] = 'Nachname ist erforderlich.';
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse.';
        }
        if (!empty($data['weekly_income']) && !is_numeric($data['weekly_income'])) {
            $errors[] = 'Ungültiges Wocheneinkommen.';
        }

        if (empty($errors)) {
            $bankId = currentBankId();

            // Kundennummer generieren – bank-spezifisches Präfix verhindert Kollisionen
            $year      = date('Y');
            $prefix    = $bankId === 2 ? "FF-{$year}-" : "KN-{$year}-";
            $lastNumber = Database::fetchOne(
                "SELECT customer_number FROM borrowers WHERE customer_number LIKE ? AND bank_id = ? ORDER BY id DESC LIMIT 1",
                ["{$prefix}%", $bankId]
            );
            if ($lastNumber) {
                $num = intval(substr($lastNumber['customer_number'], -5)) + 1;
            } else {
                $num = 1;
            }
            $customerNumber = $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);

            // Speichern
            $insertData = [
                'bank_id'             => $bankId,
                'customer_number'     => $customerNumber,
                'salutation'          => $data['salutation'] ?: null,
                'first_name'          => $data['first_name'],
                'last_name'           => $data['last_name'],
                'date_of_birth'       => $data['date_of_birth'] ?: null,
                'phone'               => $data['phone'] ?: null,
                'email'               => $data['email'] ?: null,
                'employer'            => $data['employer'] ?: null,
                'company'             => $data['company'] ?: null,
                'weekly_income'       => $data['weekly_income'] ? floatval($data['weekly_income']) : null,
                'total_assets'        => $data['total_assets'] !== '' ? floatval($data['total_assets']) : null,
                'bank_account_iban'   => $data['bank_account_iban'] ?: null,
                'bank_account_holder' => $data['bank_account_holder'] ?: null,
                'notes'               => $data['notes'] ?: null,
                'created_by'          => Auth::userId()
            ];

            $id = Database::insert('borrowers', $insertData);
            AuditLog::log('CREATE', 'borrower', $id, null, $insertData);

            setFlash('success', 'Kreditnehmer erfolgreich angelegt.');
            header('Location: ' . APP_URL . '/pages/borrowers/view.php?id=' . $id);
            exit;
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-person-plus me-2"></i>Neuer Kreditnehmer</h4>
    <a href="<?= APP_URL ?>/pages/borrowers/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Zurück
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <?= csrfField() ?>

            <div class="row g-3">
                <!-- Persönliche Daten -->
                <div class="col-12">
                    <h6 class="text-muted mb-3">Persönliche Daten</h6>
                </div>

                <div class="col-md-2">
                    <label for="salutation" class="form-label">Anrede</label>
                    <select class="form-select" id="salutation" name="salutation">
                        <option value="">--</option>
                        <option value="Mr." <?= $data['salutation'] === 'Mr.' ? 'selected' : '' ?>>Mr.</option>
                        <option value="Ms." <?= $data['salutation'] === 'Ms.' ? 'selected' : '' ?>>Ms.</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="first_name" class="form-label">Vorname *</label>
                    <input type="text" class="form-control" id="first_name" name="first_name"
                           value="<?= e($data['first_name']) ?>" required>
                </div>

                <div class="col-md-3">
                    <label for="last_name" class="form-label">Nachname *</label>
                    <input type="text" class="form-control" id="last_name" name="last_name"
                           value="<?= e($data['last_name']) ?>" required>
                </div>

                <div class="col-md-4">
                    <label for="date_of_birth" class="form-label">Geburtsdatum</label>
                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                           value="<?= e($data['date_of_birth']) ?>">
                </div>

                <!-- Kontakt -->
                <div class="col-12 mt-4">
                    <h6 class="text-muted mb-3">Kontaktdaten</h6>
                </div>

                <div class="col-md-6">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="tel" class="form-control" id="phone" name="phone"
                           value="<?= e($data['phone']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="email" class="form-label">E-Mail</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= e($data['email']) ?>">
                </div>

                <!-- Arbeit & Einkommen -->
                <div class="col-12 mt-4">
                    <h6 class="text-muted mb-3">Arbeit & Einkommen</h6>
                </div>

                <div class="col-md-6">
                    <label for="employer" class="form-label">Arbeitgeber</label>
                    <input type="text" class="form-control" id="employer" name="employer"
                           value="<?= e($data['employer']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="company" class="form-label">Unternehmen</label>
                    <input type="text" class="form-control" id="company" name="company"
                           value="<?= e($data['company']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="weekly_income" class="form-label">Wocheneinkommen ($) *</label>
                    <input type="number" step="1" class="form-control" id="weekly_income" name="weekly_income"
                           value="<?= e($data['weekly_income']) ?>">
                    <div class="form-text">Auskunftspflicht: Nachweis erforderlich</div>
                </div>

                <div class="col-md-4">
                    <label for="total_assets" class="form-label">Gesamtvermögen ($) *</label>
                    <input type="number" step="1" class="form-control" id="total_assets" name="total_assets"
                           value="<?= e($data['total_assets']) ?>">
                    <div class="form-text">Auskunftspflicht: Nachweis erforderlich</div>
                </div>

                <!-- Bankverbindung -->
                <div class="col-12 mt-4">
                    <h6 class="text-muted mb-3">Bankverbindung</h6>
                </div>

                <div class="col-md-6">
                    <label for="bank_account_iban" class="form-label">IBAN</label>
                    <input type="text" class="form-control" id="bank_account_iban" name="bank_account_iban"
                           value="<?= e($data['bank_account_iban']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="bank_account_holder" class="form-label">Kontoinhaber</label>
                    <input type="text" class="form-control" id="bank_account_holder" name="bank_account_holder"
                           value="<?= e($data['bank_account_holder']) ?>">
                </div>

                <!-- Notizen -->
                <div class="col-12 mt-4">
                    <label for="notes" class="form-label">Notizen</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($data['notes']) ?></textarea>
                </div>

                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Kreditnehmer anlegen
                    </button>
                    <a href="<?= APP_URL ?>/pages/borrowers/index.php" class="btn btn-outline-secondary">Abbrechen</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
