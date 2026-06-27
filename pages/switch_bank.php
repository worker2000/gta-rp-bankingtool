<?php
/**
 * Bank wechseln (nur für Super-Admin)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::init();
Auth::requireLogin();

if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        http_response_code(403);
        die('Ungültiges Sicherheitstoken.');
    }

    $newBankId = (int)($_POST['bank_id'] ?? 1);

    $bank = Database::fetchOne("SELECT * FROM banks WHERE id = ? AND is_active = 1", [$newBankId]);
    if ($bank) {
        $_SESSION['bank_id'] = $newBankId;
        $_SESSION['bank']    = $bank;
        AuditLog::log('BANK_SWITCH', 'bank', $newBankId);
    }
}

// Open-Redirect verhindern: nur relative Pfade innerhalb APP_URL erlauben
$redirect = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if (!$redirect || !str_starts_with($redirect, APP_URL . '/')) {
    $redirect = APP_URL . '/pages/dashboard.php';
}
header('Location: ' . $redirect);
exit;
