<?php
ob_start();
/**
 * PSB Kreditverwaltung - Kundenkonto bearbeiten
 */
$pageTitle = 'Konto bearbeiten';
require_once __DIR__ . '/../../includes/header.php';
Auth::requireLogin();

$accountId = intval($_GET['id'] ?? 0);
if (!$accountId) {
    header('Location: ' . APP_URL . '/pages/accounts/index.php');
    exit;
}

$account = Database::fetchOne("SELECT * FROM customer_accounts WHERE id = ?", [$accountId]);
if (!$account) {
    setFlash('error', 'Konto nicht gefunden.');
    header('Location: ' . APP_URL . '/pages/accounts/index.php');
    exit;
}

$errors = [];
$data = $account;

// Kreditnehmer für Dropdown laden
$borrowers = Database::fetchAll("
    SELECT id, customer_number, first_name, last_name
    FROM borrowers
    WHERE is_active = 1
    ORDER BY last_name, first_name
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Ungültiges Sicherheitstoken.';
    } else {
        $data['borrower_id'] = $_POST['borrower_id'] ?? '';
        $fields = ['account_name', 'owner_name', 'owner_phone', 'owner_email', 'status', 'notes'];
        foreach ($fields as $field) {
            $data[$field] = trim($_POST[$field] ?? '');
        }

        if (!empty($data['owner_email']) && !filter_var($data['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse.';
        }
        if (!in_array($data['status'], ['ACTIVE', 'CLOSED'])) {
            $errors[] = 'Ungültiger Status.';
        }

        if (empty($errors)) {
            $borrowerId = intval($data['borrower_id'] ?? 0);
            $updateData = [
                'borrower_id' => $borrowerId > 0 ? $borrowerId : null,
                'account_name' => $data['account_name'] ?: null,
                'owner_name' => $data['owner_name'] ?: null,
                'owner_phone' => $data['owner_phone'] ?: null,
                'owner_email' => $data['owner_email'] ?: null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?: null
            ];

            Database::update('customer_accounts', $updateData, 'id = ?', [$accountId]);

            setFlash('success', 'Konto erfolgreich aktualisiert.');
            header('Location: ' . APP_URL . '/pages/accounts/view.php?id=' . $accountId);
            exit;
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= APP_URL ?>/pages/accounts/view.php?id=<?= $accountId ?>" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-2"></i>Zurück
        </a>
        <h4 class="mt-2 mb-0">
            <i class="bi bi-pencil me-2"></i>Konto bearbeiten
        </h4>
        <small class="text-muted">
            <code><?= e($account['account_number']) ?></code>
            <span class="badge <?= AccountManager::getTypeBadgeClass($account['account_type']) ?> ms-1">
                <?= AccountManager::translateAccountType($account['account_type']) ?>
            </span>
        </small>
    </div>
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
                <!-- Kontoinformationen (nur lesen) -->
                <div class="col-12">
                    <h6 class="text-muted mb-3">Kontoinformationen</h6>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Kontonummer</label>
                    <input type="text" class="form-control" value="<?= e($account['account_number']) ?>" disabled>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Kontotyp</label>
                    <input type="text" class="form-control" value="<?= e($account['account_type_label']) ?>" disabled>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Eröffnungsdatum</label>
                    <input type="text" class="form-control" value="<?= $account['opening_date'] ? formatDate($account['opening_date']) : '-' ?>" disabled>
                </div>

                <div class="col-md-6">
                    <label for="account_name" class="form-label">Kontobezeichnung</label>
                    <input type="text" class="form-control" id="account_name" name="account_name"
                           value="<?= e($data['account_name']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="ACTIVE" <?= $data['status'] === 'ACTIVE' ? 'selected' : '' ?>>Aktiv</option>
                        <option value="CLOSED" <?= $data['status'] === 'CLOSED' ? 'selected' : '' ?>>Geschlossen</option>
                    </select>
                </div>

                <!-- Kreditnehmer verknüpfen -->
                <div class="col-12 mt-4">
                    <h6 class="text-muted mb-3">Kreditnehmer-Verknüpfung</h6>
                </div>

                <div class="col-md-6">
                    <label for="borrower_id" class="form-label">Kreditnehmer</label>
                    <select class="form-select" id="borrower_id" name="borrower_id">
                        <option value="">-- Kein Kreditnehmer --</option>
                        <?php foreach ($borrowers as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= intval($data['borrower_id'] ?? 0) === intval($b['id']) ? 'selected' : '' ?>>
                            <?= e($b['customer_number'] . ' - ' . $b['last_name'] . ', ' . $b['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kundendaten -->
                <div class="col-12 mt-4">
                    <h6 class="text-muted mb-3">Kundendaten</h6>
                </div>

                <div class="col-md-4">
                    <label for="owner_name" class="form-label">Inhaber / Name</label>
                    <input type="text" class="form-control" id="owner_name" name="owner_name"
                           value="<?= e($data['owner_name']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="owner_phone" class="form-label">Telefon</label>
                    <input type="tel" class="form-control" id="owner_phone" name="owner_phone"
                           value="<?= e($data['owner_phone']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="owner_email" class="form-label">E-Mail</label>
                    <input type="email" class="form-control" id="owner_email" name="owner_email"
                           value="<?= e($data['owner_email']) ?>">
                </div>

                <!-- Notizen -->
                <div class="col-12 mt-4">
                    <label for="notes" class="form-label">Notizen</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($data['notes']) ?></textarea>
                </div>

                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Speichern
                    </button>
                    <a href="<?= APP_URL ?>/pages/accounts/view.php?id=<?= $accountId ?>" class="btn btn-outline-secondary">Abbrechen</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
