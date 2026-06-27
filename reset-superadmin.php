<?php
/**
 * CLI-Skript: Reset-Link für einen Benutzer generieren.
 *
 * Aufruf:
 *   php reset-superadmin.php
 *   php reset-superadmin.php --user=admin
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI aufrufbar.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';

// Benutzername aus Argument lesen (Standard: superadmin)
$targetUsername = 'superadmin';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user=')) {
        $targetUsername = substr($arg, 7);
    }
}

$user = Database::fetchOne("SELECT * FROM users WHERE username = ?", [$targetUsername]);

if (!$user) {
    echo "FEHLER: Benutzer '{$targetUsername}' nicht gefunden.\n";
    echo "Vorhandene Benutzer:\n";
    $all = Database::fetchAll("SELECT username, full_name FROM users ORDER BY id");
    foreach ($all as $u) {
        echo "  - {$u['username']} ({$u['full_name']})\n";
    }
    exit(1);
}

// Token generieren (gültig 2 Stunden)
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+2 hours'));

Database::update('users', [
    'reset_token'     => $token,
    'reset_token_exp' => $expires,
], 'id = ?', [$user['id']]);

// APP_URL aus config ableiten (Fallback)
$appUrl = defined('APP_URL') ? APP_URL : '/psb';
// Für CLI brauchen wir den vollen Hostnamen
$host = gethostname();

echo "\n";
echo "══════════════════════════════════════════════\n";
echo "  Passwort-Reset für: {$user['full_name']} ({$user['username']})\n";
echo "══════════════════════════════════════════════\n";
echo "\n";
echo "  Gültig bis: {$expires}\n";
echo "\n";
echo "  Reset-Link (lokales Netzwerk):\n";
echo "  http://{$host}{$appUrl}/reset-password.php?token={$token}\n";
echo "\n";
echo "  Reset-Link (localhost):\n";
echo "  http://localhost{$appUrl}/reset-password.php?token={$token}\n";
echo "\n";
echo "  Hinweis: Link ist 2 Stunden gültig.\n";
echo "\n";
