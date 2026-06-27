<?php
/**
 * PSB Kreditverwaltung – Datenbank-Konfiguration
 *
 * Kopiere diese Datei nach config/database.php und trage deine Werte ein.
 * Die Datei config/database.php wird nicht ins Repository eingecheckt.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'psbbank');
define('DB_USER', getenv('DB_USER') ?: 'psbbank');
define('DB_PASS', getenv('DB_PASS') ?: 'DEIN_DATENBANKPASSWORT');
define('DB_CHARSET', 'utf8mb4');

// Session-Konfiguration
define('SESSION_NAME', 'PSB_SESSION');
define('SESSION_LIFETIME', 28800); // 8 Stunden

// Anwendungs-Konfiguration
define('APP_NAME', 'PSB Kreditverwaltung');
define('APP_VERSION', '1.0.0');
define('APP_URL', '/psb'); // Pfad unter dem das Tool erreichbar ist, z.B. /psb oder /

// Dateipfade
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('LOG_PATH', __DIR__ . '/../logs/');

// Bank-Hauptkonto (wird beim Kontoauszug-Import als Gegenpartei ignoriert)
define('BANK_MAIN_ACCOUNT', 'DEIN_BANKKONTONUMMER');

// Zeitzone
date_default_timezone_set('Europe/Berlin');

// ── Superadmin-Reset ──────────────────────────────────────────────────────
// Auf true setzen um das Superadmin-Passwort auf "gta-banking" zurückzusetzen.
// Nach dem Reset unbedingt wieder auf false setzen!
define('RESET_SUPERADMIN_PASSWORD', false);

// ── Lizenzierung ──────────────────────────────────────────────────────────
// Lizenzschlüssel wird über das Admin-Panel eingetragen (Admin → Lizenz).
// Kostenlose Registrierung: https://flessinglabs.com
