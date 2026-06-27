<?php
/**
 * Bulk-Import: Für alle ausstehenden Kredit-Referenzen automatisch
 * Kreditnehmer + Kredit anlegen und Zahlungen einbuchen.
 *
 * Fehlende Details (Laufzeit, Kaufpreis etc.) müssen danach
 * manuell in der Kreditverwaltung ergänzt werden.
 */
chdir(__DIR__);
require_once 'config/database.php';
require_once 'classes/Database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'classes/AuditLog.php';
require_once 'classes/Matching.php';

// CLI-Session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['bank_id'] = 2;

// Alle ausstehenden FF-Referenzen laden
$refs = Database::fetchAll(
    "SELECT * FROM pending_loan_refs
     WHERE bank_id = 2 AND status = 'PENDING'
     ORDER BY first_seen ASC, ref_number ASC"
);

if (empty($refs)) {
    echo "Keine ausstehenden Referenzen gefunden.\n";
    exit(0);
}

echo "Starte Bulk-Import für " . count($refs) . " Referenzen...\n";
echo str_repeat('─', 90) . "\n";

// Bekannte Konto-Typ-Labels die KEINE echten Namen sind
$accountTypePatterns = [
    '/^(Bronze|Silber|Gold|Platin)\s*$/i',
    '/^(Pacific Standard|PS)\s+(Bronze|Silber|Gold|Platin|Silver|Standard)/i',
    '/^(Standard|Privat|aPrivat|Neu|DAO|SAFRD)\s*$/i',
    '/^(Alltagskonto|Gehaltkonto|Mein Konto|Privatkonto|Konto)\s*$/i',
    '/^Fortis Finance/i',
];

function isAccountTypeLabel(string $name): bool {
    global $accountTypePatterns;
    foreach ($accountTypePatterns as $pattern) {
        if (preg_match($pattern, $name)) return true;
    }
    return false;
}

function parseName(string $senderName, string $ref): array {
    // Parenthetische Accountnummer entfernen: "Bronze (BRZ123)" → "Bronze"
    $clean = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $senderName));

    // Führendes "Konto " entfernen
    $clean = preg_replace('/^Konto\s+/i', '', $clean);

    if ($clean === '' || isAccountTypeLabel($clean)) {
        // Kein echter Name erkennbar – Ref-Nummer als Platzhalter
        return ['first_name' => 'Import', 'last_name' => $ref];
    }

    // Echten Namen aufteilen
    $parts = preg_split('/\s+/', $clean, 2);
    return [
        'first_name' => $parts[0],
        'last_name'  => $parts[1] ?? $ref,
    ];
}

function nextCustomerNumber(): string {
    $year   = date('Y');
    $prefix = "FF-{$year}-";
    $last   = Database::fetchOne(
        "SELECT customer_number FROM borrowers
         WHERE customer_number LIKE ? AND bank_id = 2
         ORDER BY id DESC LIMIT 1",
        ["{$prefix}%"]
    );
    $num = $last ? (intval(substr($last['customer_number'], -5)) + 1) : 1;
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function nextFileNumber(): string {
    $year   = date('Y');
    $prefix = "FF-PK-{$year}-";
    // Eindeutige Nummer: höchste vorhandene + 1
    $last   = Database::fetchOne(
        "SELECT file_number FROM loans
         WHERE file_number LIKE ? AND bank_id = 2
         ORDER BY id DESC LIMIT 1",
        ["{$prefix}%"]
    );
    $num = $last ? (intval(substr($last['file_number'], -5)) + 1) : 1;
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

$created   = 0;
$skipped   = 0;
$errors    = 0;

foreach ($refs as $ref) {
    $refId     = (int)$ref['id'];
    $refNumber = $ref['ref_number'];
    $weekly    = (float)$ref['weekly_amount'];
    $total     = (float)$ref['total_received'];
    $txCount   = (int)$ref['transaction_count'];
    $firstSeen = $ref['first_seen'];

    try {
        Database::beginTransaction();

        // ── 1. Kreditnehmer anlegen (oder vorhandenen nutzen) ─────────────
        $borrowerId = (int)($ref['borrower_id'] ?? 0);

        if (!$borrowerId) {
            $name           = parseName($ref['sender_name'] ?? '', $refNumber);
            $customerNumber = nextCustomerNumber();

            $borrowerId = Database::insert('borrowers', [
                'bank_id'         => 2,
                'customer_number' => $customerNumber,
                'first_name'      => $name['first_name'],
                'last_name'       => $name['last_name'],
                'is_active'       => 1,
                'created_by'      => 1,
                'notes'           => 'Auto-Import. Sender: ' . ($ref['sender_name'] ?? '–'),
            ]);

            AuditLog::log('CREATE', 'borrower', $borrowerId, null, [
                'customer_number' => $customerNumber,
                'source'          => 'bulk_import_ref',
                'ref_number'      => $refNumber,
            ]);

            // borrower_id in pending_loan_refs direkt setzen
            Database::update('pending_loan_refs',
                ['borrower_id' => $borrowerId],
                'id = ?', [$refId]
            );
        }

        // ── 2. Kredit anlegen ─────────────────────────────────────────────
        $fileNumber = nextFileNumber();
        $termWeeks  = max(4, $txCount + 4);   // Mindest-Laufzeit: bisherige Zahlungen + 4 Wochen Puffer
        $endDate    = date('Y-m-d', strtotime($firstSeen . " + {$termWeeks} weeks"));

        // Loan-Amount = weekly_rate × term_weeks (Näherung)
        $loanAmount  = $weekly > 0 ? round($weekly * $termWeeks, 2) : $total;
        $loanAmount  = max($loanAmount, $total);  // Mindestens was schon bezahlt wurde

        $loanId = Database::insert('loans', [
            'bank_id'             => 2,
            'file_number'         => $fileNumber,
            'borrower_id'         => $borrowerId,
            'product_type'        => 'PRIVATE',
            'status'              => 'ACTIVE',
            'purchase_price'      => $loanAmount,
            'down_payment'        => 0.00,
            'loan_amount'         => $loanAmount,
            'interest_rate'       => 0.0000,
            'total_interest'      => 0.00,
            'total_amount'        => $loanAmount,
            'term_weeks'          => $termWeeks,
            'weekly_rate'         => $weekly > 0 ? $weekly : round($total / max(1, $txCount), 2),
            'start_date'          => $firstSeen,
            'end_date'            => $endDate,
            'payment_reference'   => $refNumber,
            'outstanding_balance' => max(0, $loanAmount - $total),
            'created_by'          => 1,
            'notes'               => '[Auto-Import] Ref: ' . $refNumber . ' | Bisher eingegangen: $' . number_format($total, 2) . ' | Bitte Kreditdaten prüfen und korrigieren.',
        ]);

        AuditLog::log('CREATE', 'loan', $loanId, null, [
            'file_number' => $fileNumber,
            'source'      => 'bulk_import_ref',
            'ref_number'  => $refNumber,
        ]);

        // ── 3. Transaktionen auf Kredit umbuchen ──────────────────────────
        $txs = Database::fetchAll(
            "SELECT id, amount FROM bank_transactions
             WHERE matched_pending_ref_id = ?
             ORDER BY transaction_date ASC, id ASC",
            [$refId]
        );

        $rematched = 0;
        foreach ($txs as $tx) {
            Database::update('bank_transactions', [
                'match_status'           => 'MATCHED',
                'matched_loan_id'        => $loanId,
                'matched_pending_ref_id' => null,
                'match_method'           => 'LOAN_REF',
                'match_confidence'       => 1.00,
                'matched_by'             => 1,
                'matched_at'             => date('Y-m-d H:i:s'),
            ], 'id = ?', [$tx['id']]);
            $rematched++;
        }

        // ── 4. Pending Ref auf CONVERTED setzen ───────────────────────────
        Database::update('pending_loan_refs', [
            'loan_id'     => $loanId,
            'borrower_id' => $borrowerId,
            'status'      => 'CONVERTED',
        ], 'id = ?', [$refId]);

        Database::commit();

        printf(
            "✓ %-22s → %-22s | Kreditnehmer #%d | Kredit %-20s | %2d Zahlungen ($%.2f)\n",
            $refNumber, $fileNumber, $borrowerId, $fileNumber, $rematched, $total
        );
        $created++;

    } catch (Exception $e) {
        Database::rollback();
        echo "✗ {$refNumber}: FEHLER – " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo str_repeat('─', 90) . "\n";
echo "Fertig: {$created} angelegt, {$skipped} übersprungen, {$errors} Fehler.\n\n";

// Übersicht
$summary = Database::fetchAll("
    SELECT plr.ref_number, l.file_number, b.first_name, b.last_name,
           plr.transaction_count, plr.total_received
    FROM pending_loan_refs plr
    JOIN loans l ON plr.loan_id = l.id
    JOIN borrowers b ON l.borrower_id = b.id
    WHERE plr.bank_id = 2 AND plr.status = 'CONVERTED'
    ORDER BY plr.ref_number
");
echo count($summary) . " Referenzen insgesamt verknüpft.\n";
