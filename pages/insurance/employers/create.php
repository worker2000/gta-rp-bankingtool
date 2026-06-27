<?php
ob_start();
/**
 * Fortis Finance – Arbeitgeber-KV: Neuer Arbeitgeber
 */
$pageTitle = 'Neuer Arbeitgeber';
require_once __DIR__ . '/../../../includes/header.php';
Auth::requireLogin();

if (currentBankId() !== 2) {
    setFlash('error', 'Krankenversicherung ist nur bei Fortis Finance verfügbar.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors = [];
$data = [
    'company_name'   => '',
    'contact_person' => '',
    'phone'          => '',
    'email'          => '',
    'address'        => '',
    'iban'           => '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $data['company_name']   = trim($_POST['company_name']   ?? '');
        $data['contact_person'] = trim($_POST['contact_person'] ?? '');
        $data['phone']          = trim($_POST['phone']          ?? '');
        $data['email']          = trim($_POST['email']          ?? '');
        $data['address']        = trim($_POST['address']        ?? '');
        $data['iban']           = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $data['notes']          = trim($_POST['notes']          ?? '');

        if (!$data['company_name']) $errors[] = 'Firmenname ist Pflichtfeld.';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse.';
        }

        if (empty($errors)) {
            try {
                $employerId = Database::insert('insurance_employers', [
                    'bank_id'        => 2,
                    'company_name'   => $data['company_name'],
                    'contact_person' => $data['contact_person'] ?: null,
                    'phone'          => $data['phone']          ?: null,
                    'email'          => $data['email']          ?: null,
                    'address'        => $data['address']        ?: null,
                    'iban'           => $data['iban']           ?: null,
                    'notes'          => $data['notes']          ?: null,
                    'is_active'      => 1,
                    'created_by'     => Auth::userId(),
                ]);

                AuditLog::log('CREATE', 'insurance_employer', $employerId, null, [
                    'company_name' => $data['company_name'],
                ]);

                setFlash('success', 'Arbeitgeber "' . $data['company_name'] . '" angelegt. Bitte jetzt einen Gruppenvertrag anlegen.');
                header('Location: ' . APP_URL . '/pages/insurance/employers/view.php?id=' . $employerId);
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
        <li class="breadcrumb-item active">Neuer Arbeitgeber</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-building me-2"></i>Neuer Arbeitgeber</h4>
    <a href="<?= APP_URL ?>/pages/insurance/employers/index.php" class="btn btn-outline-secondary">
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

<div class="row g-4">
    <div class="col-md-8">
        <form method="POST">
            <?= csrfField() ?>

            <div class="card mb-4">
                <div class="card-header">Firmendaten</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Firmenname *</label>
                            <input type="text" class="form-control" name="company_name"
                                   value="<?= e($data['company_name']) ?>" required
                                   placeholder="z.B. Musterfirma GmbH">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ansprechpartner</label>
                            <input type="text" class="form-control" name="contact_person"
                                   value="<?= e($data['contact_person']) ?>"
                                   placeholder="Name der zuständigen Person">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?= e($data['phone']) ?>"
                                   placeholder="+1 555 123 4567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-Mail</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= e($data['email']) ?>"
                                   placeholder="hr@musterfirma.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN (Beitragszahlung)</label>
                            <input type="text" class="form-control" name="iban"
                                   value="<?= e($data['iban']) ?>"
                                   placeholder="DE...">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address"
                                   value="<?= e($data['address']) ?>"
                                   placeholder="Straße, PLZ Ort">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notizen</label>
                            <textarea class="form-control" name="notes" rows="3"
                                      placeholder="Interne Notizen..."><?= e($data['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-2"></i>Arbeitgeber anlegen
                </button>
                <a href="<?= APP_URL ?>/pages/insurance/employers/index.php" class="btn btn-outline-secondary">
                    Abbrechen
                </a>
            </div>
        </form>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Hinweis</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    <i class="bi bi-info-circle me-1 text-info"></i>
                    Nach dem Anlegen des Arbeitgebers werden Sie direkt zur Detailseite weitergeleitet,
                    wo Sie einen Gruppenvertrag anlegen können.
                </p>
                <p class="small text-muted mb-0">
                    <i class="bi bi-people me-1 text-info"></i>
                    Mitarbeiter werden über den Gruppenvertrag hinzugefügt.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
