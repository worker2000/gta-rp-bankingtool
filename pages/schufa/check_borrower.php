<?php
/**
 * AJAX-Endpoint: Kreditauskunft für einen Kreditnehmer
 * GET ?borrower_id=X  → JSON mit Kredithistorie über alle Banken
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$borrowerId = intval($_GET['borrower_id'] ?? 0);
if (!$borrowerId) {
    echo json_encode(['found' => false]);
    exit;
}

// Eigenen Kreditnehmer laden (bank-check nicht nötig, nur Lesezugriff)
$borrower = Database::fetchOne(
    "SELECT id, bank_id, first_name, last_name, date_of_birth, phone FROM borrowers WHERE id = ?",
    [$borrowerId]
);
if (!$borrower) {
    echo json_encode(['found' => false]);
    exit;
}

$myBankId = currentBankId();
$allBanks = Database::fetchAll("SELECT id, name, short_code, primary_color FROM banks WHERE is_active = 1");
$bankMap  = [];
foreach ($allBanks as $bk) { $bankMap[$bk['id']] = $bk; }

// Gleiche Person in ALLEN Banken suchen – mehrstufiges Matching
// Stufe 1: Nachname exakt + Vorname exakt
// Stufe 2: Nachname exakt + erste 3 Zeichen des Vornamens (fängt Schreibvarianten wie Antony/Anthony)
// Stufe 3: Nachname exakt + Geburtsdatum (falls vorhanden)
$lastName  = $borrower['last_name'];
$firstName = $borrower['first_name'];
$dob       = $borrower['date_of_birth'];
$firstPfx  = mb_substr($firstName, 0, 3);

$dobCondition = $dob
    ? "OR (b.last_name = ? AND b.date_of_birth = ?)"
    : "";
$dobParams = $dob ? [$lastName, $dob] : [];

$matchedBorrowers = Database::fetchAll(
    "SELECT b.id, b.bank_id, b.customer_number, b.first_name, b.last_name, b.date_of_birth
     FROM borrowers b
     WHERE (b.last_name = ? AND b.first_name = ?)
        OR (b.last_name = ? AND LEFT(b.first_name, 3) = LEFT(?, 3))
        $dobCondition
     ORDER BY b.bank_id",
    array_merge([$lastName, $firstName, $lastName, $firstName], $dobParams)
);

if (empty($matchedBorrowers)) {
    $matchedBorrowers = [['id' => $borrower['id'], 'bank_id' => $borrower['bank_id'],
                          'customer_number' => '', 'first_name' => $borrower['first_name'],
                          'last_name' => $borrower['last_name'], 'date_of_birth' => $borrower['date_of_birth']]];
}

$statusLabels = [
    'APPLICATION_RECEIVED' => ['l' => 'Antrag',         'c' => 'secondary'],
    'IN_REVIEW'            => ['l' => 'Prüfung',         'c' => 'info'],
    'APPROVED'             => ['l' => 'Bewilligt',       'c' => 'info'],
    'REJECTED'             => ['l' => 'Abgelehnt',       'c' => 'danger'],
    'CONTRACT_CREATED'     => ['l' => 'Vertrag',         'c' => 'primary'],
    'ACTIVE'               => ['l' => 'Aktiv',           'c' => 'success'],
    'DUNNING_L1'           => ['l' => 'Mahnung 1',       'c' => 'warning'],
    'DUNNING_L2'           => ['l' => 'Mahnung 2',       'c' => 'danger'],
    'TERMINATED'           => ['l' => 'Gekündigt',       'c' => 'danger'],
    'REPOSSESSION'         => ['l' => 'Sicherstellung',  'c' => 'danger'],
    'CLOSED'               => ['l' => 'Abgeschlossen',   'c' => 'secondary'],
    'WITHDRAWN'            => ['l' => 'Widerruf',        'c' => 'secondary'],
];
$productLabels = ['AUTO' => 'Fahrzeug', 'PRIVATE' => 'Privat', 'BUSINESS' => 'Unternehmen', 'INSURANCE' => 'Versicherung'];

$allLoans      = [];
$totalActive   = 0;
$totalNegative = 0;
$totalWithdrawn = 0;
$totalOutstanding = 0.0;
$highestRisk   = 'green'; // green | yellow | red
$bankSections  = [];

foreach ($matchedBorrowers as $mb) {
    $loans = Database::fetchAll(
        "SELECT id, file_number, product_type, status, loan_amount, outstanding_balance, days_overdue, vehicle_model
         FROM loans WHERE borrower_id = ? ORDER BY created_at DESC",
        [$mb['id']]
    );

    $isOwn = ($mb['bank_id'] == $myBankId);
    $bkInfo = $bankMap[$mb['bank_id']] ?? ['name' => 'Unbekannt', 'short_code' => '?', 'primary_color' => '#666'];

    $sectionLoans = [];
    foreach ($loans as $l) {
        $sl    = $statusLabels[$l['status']] ?? ['l' => $l['status'], 'c' => 'secondary'];
        $pl    = $productLabels[$l['product_type']] ?? $l['product_type'];
        $ob    = floatval($l['outstanding_balance']);
        $daysO = intval($l['days_overdue'] ?? 0);

        $isActive    = in_array($l['status'], ['ACTIVE','CONTRACT_CREATED','DUNNING_L1','DUNNING_L2','REPOSSESSION']);
        $isNegative  = in_array($l['status'], ['TERMINATED','REPOSSESSION']);
        $isWithdrawn = ($l['status'] === 'WITHDRAWN');

        if ($isActive)    $totalActive++;
        if ($isNegative)  $totalNegative++;
        if ($isWithdrawn) $totalWithdrawn++;
        if ($isActive)    $totalOutstanding += $ob;

        // Risiko hochstufen
        if (in_array($l['status'], ['DUNNING_L2','REPOSSESSION']) || $totalNegative >= 2) {
            $highestRisk = 'red';
        } elseif ($highestRisk !== 'red' && (in_array($l['status'], ['DUNNING_L1','TERMINATED']) || $daysO > 14)) {
            $highestRisk = 'yellow';
        }

        $sectionLoans[] = [
            'id'          => $l['id'],
            'file_number' => $l['file_number'],
            'product'     => $pl,
            'status_l'    => $sl['l'],
            'status_c'    => $sl['c'],
            'loan_amount' => $l['loan_amount'],
            'outstanding' => $ob,
            'days_overdue'=> $daysO,
            'vehicle'     => $l['vehicle_model'],
            'is_own'      => $isOwn,
        ];
    }

    if (!empty($sectionLoans)) {
        $bankSections[] = [
            'bank_name'   => $bkInfo['name'],
            'short_code'  => $bkInfo['short_code'],
            'color'       => $bkInfo['primary_color'],
            'is_own'      => $isOwn,
            'loans'       => $sectionLoans,
        ];
    }
}

echo json_encode([
    'found'           => true,
    'name'            => $borrower['first_name'] . ' ' . $borrower['last_name'],
    'risk'            => $highestRisk,
    'total_active'    => $totalActive,
    'total_negative'  => $totalNegative,
    'total_withdrawn' => $totalWithdrawn,
    'total_outstanding' => $totalOutstanding,
    'bank_sections'   => $bankSections,
    'status_labels'   => $statusLabels,
], JSON_UNESCAPED_UNICODE);
