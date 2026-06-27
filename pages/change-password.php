<?php
ob_start();
$pageTitle = 'Passwort ändern';
require_once __DIR__ . '/../includes/header.php';

Auth::requireLogin();

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $newPw     = $_POST['new_password']     ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($newPw !== $confirmPw) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $userId = Auth::userId();
        Database::update('users', [
            'password_hash'       => password_hash($newPw, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], 'id = ?', [$userId]);

        // Session-Flag löschen
        $_SESSION['user']['must_change_password'] = false;

        AuditLog::log('CHANGE_PASSWORD', 'user', $userId);
        $success = true;
    }
}
?>

<div class="d-flex align-items-center justify-content-center" style="min-height: 60vh;">
    <div style="width:100%;max-width:420px;">

        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center mb-3"
                 style="width:56px;height:56px;border-radius:14px;background:rgba(255,193,7,0.1);border:1px solid rgba(255,193,7,0.3);">
                <i class="bi bi-key-fill" style="font-size:1.5rem;color:#ffc107;"></i>
            </div>
            <h5 class="mb-1">Passwort festlegen</h5>
            <p class="text-muted small mb-0">Bitte setze ein persönliches Passwort bevor du fortfährst.</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success text-center">
            <i class="bi bi-check-circle me-2"></i>Passwort erfolgreich geändert.
            <div class="mt-2">
                <a href="<?= APP_URL ?>/pages/dashboard.php" class="btn btn-success btn-sm">
                    Zum Dashboard
                </a>
            </div>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Neues Passwort *</label>
                        <input type="password" class="form-control" name="new_password"
                               required minlength="8" autocomplete="new-password">
                        <div class="form-text">Mindestens 8 Zeichen.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Passwort wiederholen *</label>
                        <input type="password" class="form-control" name="confirm_password"
                               required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-warning w-100 fw-semibold">
                        <i class="bi bi-check-lg me-2"></i>Passwort speichern
                    </button>
                </form>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
