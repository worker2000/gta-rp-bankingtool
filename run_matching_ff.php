<?php
/**
 * Einmaliges Script: Matching für alle Fortis Finance Batches durchlaufen lassen
 */
chdir(__DIR__);
require_once 'config/database.php';
require_once 'classes/Database.php';
require_once 'includes/auth.php';
require_once 'classes/AuditLog.php';
require_once 'classes/Matching.php';

// CLI-Session simulieren (System-User)
if (php_sapi_name() === 'cli') {
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['bank_id'] = 2;
}

$batchIds = Database::fetchAll(
    "SELECT id FROM bank_statement_batches WHERE bank_id = 2 ORDER BY id"
);

$totalStats = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];

foreach ($batchIds as $b) {
    $stats = Matching::processBatch($b['id']);
    echo "Batch {$b['id']}: matched={$stats['matched']}  ambiguous={$stats['ambiguous']}  unmatched={$stats['unmatched']}\n";
    $totalStats['matched']   += $stats['matched'];
    $totalStats['ambiguous'] += $stats['ambiguous'];
    $totalStats['unmatched'] += $stats['unmatched'];
}

echo "\nGESAMT: matched={$totalStats['matched']}  ambiguous={$totalStats['ambiguous']}  unmatched={$totalStats['unmatched']}\n";

// Erkannte Kredit-Referenzen ausgeben
$refs = Database::fetchAll(
    "SELECT ref_number, sender_name, transaction_count, total_received, weekly_amount, first_seen
     FROM pending_loan_refs ORDER BY ref_number"
);

echo "\nErkannte Kredit-Referenzen (" . count($refs) . "):\n";
echo str_pad('Referenz', 22) . str_pad('Absender', 30) . str_pad('Zahlungen', 11) . str_pad('Gesamt', 12) . str_pad('Wochenrate', 12) . "Seit\n";
echo str_repeat('-', 95) . "\n";
foreach ($refs as $r) {
    echo str_pad($r['ref_number'], 22)
       . str_pad($r['sender_name'] ?? '–', 30)
       . str_pad($r['transaction_count'], 11)
       . str_pad('$' . number_format($r['total_received'], 2), 12)
       . str_pad('$' . number_format($r['weekly_amount'] ?? 0, 2), 12)
       . $r['first_seen'] . "\n";
}
