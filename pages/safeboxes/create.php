<?php
ob_start();
/**
 * PSB / Fortis Finance – Schließfach anlegen
 */
$pageTitle = 'Schließfach anlegen';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

$bid = currentBankId();

$errors = [];
$form   = [
    'box_number'      => '',
    'box_size'        => 'KLEIN',
    'borrower_id'     => intval($_GET['borrower_id'] ?? 0),
    'iban'            => '',
    'weekly_fee'      => '',
    'last_payment_date' => '',
    'staff_initials'  => '',
    'notes'           => '',
];

// Borrower vorausfüllen
$preselectedBorrower = null;
if ($form['borrower_id']) {
    $preselectedBorrower = Database::fetchOne(
        "SELECT id, customer_number, first_name, last_name FROM borrowers WHERE id = ? AND bank_id = ?",
        [$form['borrower_id'], $bid]
    );
    if (!$preselectedBorrower) $form['borrower_id'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Formular-Token.';
    } else {
        $form['box_number']        = trim($_POST['box_number']        ?? '');
        $form['box_size']          = $_POST['box_size']               ?? 'KLEIN';
        $form['borrower_id']       = intval($_POST['borrower_id']     ?? 0);
        $form['iban']              = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $form['weekly_fee']        = trim($_POST['weekly_fee']        ?? '');
        $form['last_payment_date'] = trim($_POST['last_payment_date'] ?? '');
        $form['staff_initials']    = trim($_POST['staff_initials']    ?? '');
        $form['notes']             = trim($_POST['notes']             ?? '');

        if (!$form['box_number']) $errors[] = 'Fach-Nummer ist Pflichtfeld.';
        if (!in_array($form['box_size'], ['KLEIN','MITTEL','GROSS'])) $errors[] = 'Ungültige Größe.';
        if ($form['weekly_fee'] === '' || !is_numeric($form['weekly_fee']) || floatval($form['weekly_fee']) < 0)
            $errors[] = 'Wochengebühr muss eine Zahl >= 0 sein.';
        if (!$form['borrower_id']) $errors[] = 'Bitte einen Mieter auswählen.';

        // Doppelte Fach-Nummer
        if (!$errors) {
            $exists = Database::fetchOne(
                "SELECT id FROM safeboxes WHERE box_number = ? AND bank_id = ?",
                [$form['box_number'], $bid]
            );
            if ($exists) $errors[] = 'Fach-Nummer "' . $form['box_number'] . '" existiert bereits.';
        }

        if (!$errors) {
            $newId = Database::insert('safeboxes', [
                'bank_id'           => $bid,
                'borrower_id'       => $form['borrower_id'] ?: null,
                'box_number'        => $form['box_number'],
                'box_size'          => $form['box_size'],
                'iban'              => $form['iban'] ?: null,
                'weekly_fee'        => floatval($form['weekly_fee']),
                'last_payment_date' => $form['last_payment_date'] ?: null,
                'status'            => 'ACTIVE',
                'staff_initials'    => $form['staff_initials'] ?: null,
                'notes'             => $form['notes'] ?: null,
            ]);

            AuditLog::log('CREATE', 'safebox', $newId, null, [
                'box_number' => $form['box_number'],
                'box_size'   => $form['box_size'],
                'weekly_fee' => $form['weekly_fee'],
            ]);

            setFlash('success', 'Schließfach "' . $form['box_number'] . '" erfolgreich angelegt.');
            header('Location: ' . APP_URL . '/pages/safeboxes/view.php?id=' . $newId);
            exit;
        }
    }
}

// Kreditnehmer für Dropdown
$borrowers = Database::fetchAll(
    "SELECT id, customer_number, first_name, last_name FROM borrowers WHERE bank_id = ? AND is_active = 1 ORDER BY last_name, first_name",
    [$bid]
);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/safeboxes/index.php" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück zur Übersicht
        </a>
        <h4 class="mt-2 mb-0"><i class="bi bi-safe me-2"></i>Schließfach anlegen</h4>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
        <li><?= e($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-safe me-2"></i>Fach-Daten
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fach-Nummer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="box_number"
                                   value="<?= e($form['box_number']) ?>" placeholder="z.B. Klein 2, Mittel 3, Groß 1"
                                   required>
                            <div class="form-text">Eindeutige Bezeichnung des Schließfachs</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Größe <span class="text-danger">*</span></label>
                            <select class="form-select" name="box_size" required>
                                <option value="KLEIN"  <?= $form['box_size'] === 'KLEIN'  ? 'selected' : '' ?>>Klein</option>
                                <option value="MITTEL" <?= $form['box_size'] === 'MITTEL' ? 'selected' : '' ?>>Mittel</option>
                                <option value="GROSS"  <?= $form['box_size'] === 'GROSS'  ? 'selected' : '' ?>>Groß</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Mieter (Kreditnehmer) <span class="text-danger">*</span></label>
                            <select class="form-select" name="borrower_id" required>
                                <option value="">– Bitte auswählen –</option>
                                <?php foreach ($borrowers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $form['borrower_id'] == $b['id'] ? 'selected' : '' ?>>
                                    <?= e($b['customer_number']) ?> – <?= e($b['last_name'] . ', ' . $b['first_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Wochengebühr ($) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="weekly_fee" step="0.01" min="0"
                                   value="<?= e($form['weekly_fee']) ?>" placeholder="z.B. 2500" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN des Mieters</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($form['iban']) ?>" placeholder="z.B. PSB12345678">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Letzte Zahlung</label>
                            <input type="date" class="form-control" name="last_payment_date"
                                   value="<?= e($form['last_payment_date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mitarbeiter-Initialen</label>
                            <input type="text" class="form-control" name="staff_initials" maxlength="10"
                                   value="<?= e($form['staff_initials']) ?>" placeholder="z.B. LdM">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"><?= e($form['notes']) ?></textarea>
                        </div>
                    </div>

                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Schließfach anlegen
                        </button>
                        <a href="<?= APP_URL ?>/pages/safeboxes/index.php" class="btn btn-outline-secondary">Abbrechen</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
