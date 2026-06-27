<?php
/**
 * PSB – Administration Login (Super-Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../classes/LicenseManager.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

LicenseManager::check();
Auth::init();

// Bereits als Super-Admin eingeloggt → direkt weiterleiten
if (Auth::check() && Auth::isSuperAdmin()) {
    header('Location: ' . APP_URL . '/pages/admin/index.php');
    exit;
}
// Als normaler User eingeloggt → abmelden und neu anmelden lassen
if (Auth::check() && !Auth::isSuperAdmin()) {
    Auth::logout();
}

// ── Superadmin-Reset via Config-Flag ────────────────────────────────────────
$resetDone = false;
if (defined('RESET_SUPERADMIN_PASSWORD') && RESET_SUPERADMIN_PASSWORD === true) {
    $superadmin = Database::fetchOne("SELECT id FROM users WHERE username = 'superadmin'");
    if ($superadmin) {
        Database::update('users', [
            'password_hash'        => password_hash('gta-banking', PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'is_active'            => 1,
            'reset_token'          => null,
            'reset_token_exp'      => null,
        ], "username = 'superadmin'", []);
    }
    $resetDone = true;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';

    if (!$username || !$password) {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } else {
        // Super-Admin hat keine feste Bank — wir loggen ihn mit Bank 1 ein,
        // er kann danach im Panel wechseln.
        $user = Database::fetchOne(
            "SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.name) as roles,
                    GROUP_CONCAT(r.permissions ORDER BY r.name) as all_permissions
             FROM users u
             LEFT JOIN user_roles ur ON u.id = ur.user_id
             LEFT JOIN roles r ON ur.role_id = r.id
             WHERE u.username = ? AND u.is_active = 1
             GROUP BY u.id",
            [$username]
        );

        $roles        = $user ? array_filter(explode(',', $user['roles'] ?? '')) : [];
        $isSuperAdmin = in_array('super_admin', $roles);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Ungültiger Benutzername oder Passwort.';
        } elseif (!$isSuperAdmin) {
            $error = 'Zugriff verweigert — kein Super-Admin-Konto.';
        } else {
            $bank = Database::fetchOne("SELECT * FROM banks WHERE id = ? AND is_active = 1", [(int)$user['bank_id']]);
            if (!$bank) {
                $bank = Database::fetchOne("SELECT * FROM banks WHERE is_active = 1 ORDER BY id LIMIT 1");
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['bank_id'] = $bank['id'] ?? 1;
            $_SESSION['bank']    = $bank ?? ['id' => 1, 'name' => 'System', 'short_code' => 'SYS', 'primary_color' => '#0d6efd'];
            $_SESSION['user']    = [
                'id'                  => $user['id'],
                'username'            => $user['username'],
                'full_name'           => $user['full_name'],
                'email'               => $user['email'],
                'roles'               => array_values($roles),
                'permissions'         => [],
                'is_super_admin'      => true,
                'must_change_password'=> (bool)($user['must_change_password'] ?? false),
            ];
            Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
            AuditLog::log('LOGIN', 'user', $user['id']);

            if (!empty($user['must_change_password'])) {
                header('Location: ' . APP_URL . '/pages/change-password.php');
            } else {
                header('Location: ' . APP_URL . '/pages/admin/index.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration – Anmeldung</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #0d1117; }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: radial-gradient(ellipse at 50% 40%, rgba(220,53,69,0.06) 0%, transparent 60%),
                        linear-gradient(160deg, #0d1117 0%, #161b22 100%);
        }
        .admin-card {
            width: 100%;
            max-width: 380px;
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 14px;
            padding: 2.25rem 2rem;
        }
        .icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px; height: 56px;
            border-radius: 14px;
            background: rgba(220,53,69,0.1);
            border: 1px solid rgba(220,53,69,0.3);
            margin-bottom: 0.9rem;
        }
        .form-control {
            background: #0d1117 !important;
            border-color: #30363d !important;
            color: #e6edf3 !important;
            border-radius: 8px;
        }
        .form-control:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 3px rgba(220,53,69,0.2) !important;
        }
        .input-group-text {
            background: #0d1117 !important;
            border-color: #30363d !important;
            color: #484f58 !important;
        }
        .form-label { color: #8b949e; font-size: 0.82rem; }
        .btn-admin {
            width: 100%; padding: 0.6rem; font-weight: 600;
            border-radius: 8px; border: none;
            background: #dc3545; color: #fff;
            transition: background 0.15s;
        }
        .btn-admin:hover { background: #b02a37; color: #fff; }
        .back-link { text-align: center; margin-top: 1.2rem; font-size: 0.78rem; }
        .back-link a { color: #484f58; text-decoration: none; transition: color .15s; }
        .back-link a:hover { color: #8b949e; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="admin-card">
        <div class="text-center mb-4">
            <div class="icon-wrap">
                <i class="bi bi-shield-lock-fill" style="font-size:1.5rem;color:#dc3545;"></i>
            </div>
            <h5 class="mb-0 fw-bold">Administration</h5>
            <p class="text-muted small mt-1 mb-0">Nur für Super-Admins</p>
        </div>

        <?php if ($resetDone): ?>
        <div class="alert alert-warning mb-3" style="font-size:.82rem;border-radius:8px;">
            <div class="fw-semibold mb-1"><i class="bi bi-arrow-counterclockwise me-1"></i>Passwort wurde zurückgesetzt</div>
            Benutzername: <code>superadmin</code><br>
            Passwort: <code>gta-banking</code><br>
            <hr class="my-2" style="border-color:rgba(255,193,7,0.3);">
            <span class="text-warning-emphasis">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Jetzt <code>RESET_SUPERADMIN_PASSWORD</code> in <code>config/database.php</code> wieder auf <code>false</code> setzen!
            </span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 mb-3" style="font-size:.875rem;border-radius:8px;">
            <i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Benutzername</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>"
                           required autocomplete="username" autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Passwort</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" name="password"
                           required autocomplete="current-password">
                </div>
            </div>
            <button type="submit" class="btn-admin">
                <i class="bi bi-shield-check me-2"></i>Anmelden
            </button>
        </form>

        <div class="back-link">
            <a href="<?= APP_URL ?>/index.php"><i class="bi bi-arrow-left me-1"></i>Zurück zum Login</a>
        </div>
    </div>
</div>
</body>
</html>
