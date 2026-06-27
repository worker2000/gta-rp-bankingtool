<?php
/**
 * PSB Kreditverwaltung - Authentifizierter Datei-Proxy
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::init();
Auth::requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$doc = Database::fetchOne("SELECT * FROM documents WHERE id = ?", [$id]);
if (!$doc) {
    http_response_code(404);
    exit('Dokument nicht gefunden.');
}

if (empty($doc['file_path'])) {
    http_response_code(400);
    exit('Kein Datei-Pfad hinterlegt.');
}

// Absoluten Pfad sicherstellen
$filePath = UPLOAD_PATH . 'documents/' . basename($doc['file_path']);

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

// Path Traversal verhindern
$realPath = realpath($filePath);
$allowedBase = realpath(UPLOAD_PATH . 'documents');
if ($realPath === false || strpos($realPath, $allowedBase) !== 0) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$mimeType = $doc['mime_type'] ?: mime_content_type($filePath) ?: 'application/octet-stream';
$originalFilename = $doc['original_filename'] ?: basename($doc['file_path']);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');

// PDFs und Bilder direkt anzeigen, Rest als Download
if (in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
    header('Content-Disposition: inline; filename="' . rawurlencode($originalFilename) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . rawurlencode($originalFilename) . '"');
}

readfile($filePath);
exit;
