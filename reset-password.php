<?php
/**
 * Passwort-Reset via Token (generiert durch reset-superadmin.php oder Admin-Panel)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::init();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;

if (!$token) {
    http_response_code(400);
    die('Kein Token angegeben.');
}

$user = Database::fetchOne(
    "SELECT * FROM users WHERE reset_token = ? AND reset_token_exp > NOW() AND is_active = 1",
    [$token]
);

if (!$user) {
    $expired = true;
} else {
    $expired = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (strlen($newPw) < 8) {
            $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            Database::update('users', [
                'password_hash'        => password_hash($newPw, PASSWORD_DEFAULT),
                'must_change_password' => 0,
                'reset_token'          => null,
                'reset_token_exp'      => null,
            ], 'id = ?', [$user['id']]);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort zurücksetzen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #0d1117; }
        .reset-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
    </style>
</head>
<body>
<div class="reset-wrap">
    <div style="width:100%;max-width:420px;">

        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center mb-3"
                 style="width:56px;height:56px;border-radius:14px;background:rgba(13,110,253,0.1);border:1px solid rgba(13,110,253,0.25);">
                <i class="bi bi-shield-lock-fill" style="font-size:1.5rem;color:#58a6ff;"></i>
            </div>
            <h5 class="mb-1">Passwort zurücksetzen</h5>
            <?php if (!$expired && !$done): ?>
            <p class="text-muted small">Benutzer: <strong><?= e($user['full_name']) ?></strong> (<?= e($user['username']) ?>)</p>
            <?php endif; ?>
        </div>

        <?php if ($expired): ?>
        <div class="alert alert-danger text-center">
            <i class="bi bi-clock-history me-2"></i>
            Dieser Reset-Link ist ungültig oder abgelaufen.<br>
            <small class="text-muted">Bitte einen neuen Link generieren.</small>
        </div>
        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/index.php" class="btn btn-outline-secondary btn-sm">Zurück zum Login</a>
        </div>

        <?php elseif ($done): ?>
        <div class="alert alert-success text-center">
            <i class="bi bi-check-circle me-2"></i>Passwort erfolgreich geändert.<br>
            <a href="<?= APP_URL ?>/index.php" class="btn btn-success btn-sm mt-2">Zum Login</a>
        </div>

        <?php else: ?>
        <?php if ($error): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label">Neues Passwort *</label>
                        <input type="password" class="form-control" name="new_password"
                               required minlength="8" autocomplete="new-password" autofocus>
                        <div class="form-text">Mindestens 8 Zeichen.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Passwort wiederholen *</label>
                        <input type="password" class="form-control" name="confirm_password"
                               required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="bi bi-check-lg me-2"></i>Passwort speichern
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
