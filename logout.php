<?php
/**
 * PSB Kreditverwaltung - Logout
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/AuditLog.php';
require_once __DIR__ . '/includes/auth.php';

Auth::logout();

header('Location: ' . APP_URL . '/index.php');
exit;
