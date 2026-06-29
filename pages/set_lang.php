<?php
/**
 * PSB / Fortis Finance – Sprache wechseln
 *
 * Neue Sprachen hinzufügen: Einfach lang/XX.php anlegen (XX = ISO-639-1 Code).
 * Die Datei wird automatisch als gültige Option erkannt.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::init();

// Verfügbare Sprachen dynamisch aus lang/-Verzeichnis ermitteln
$langDir     = __DIR__ . '/../lang/';
$available   = [];
foreach (glob($langDir . '*.php') as $f) {
    $available[] = basename($f, '.php');
}
if (empty($available)) {
    $available = ['de'];
}

$lang = $_GET['lang'] ?? 'de';
if (!in_array($lang, $available, true)) {
    $lang = $available[0];
}
$_SESSION['lang'] = $lang;

$redirect = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/pages/dashboard.php';
// Nur lokale Weiterleitungen erlauben
if (!str_starts_with($redirect, APP_URL)) {
    $redirect = APP_URL . '/pages/dashboard.php';
}

header('Location: ' . $redirect);
exit;
