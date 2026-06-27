<?php
/**
 * PSB Import-Skript - Alle Kontobewegungen
 * Importiert ALLE Transaktionen pro Kontonummer (Gebühren, Gehalt, Einzahlungen, etc.)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/AccountManager.php';

$isCli = php_sapi_name() === 'cli';
$nl = $isCli ? "\n" : "<br>\n";

echo "=== PSB Transaktions-Import (Alle Bewegungen) ==={$nl}{$nl}";

$rawData = file_get_contents(__DIR__ . '/import_data.tsv');
if (!$rawData) {
    die("Fehler: import_data.tsv nicht gefunden!{$nl}");
}

$lines = explode("\n", trim($rawData));
$stats = [
    'total' => 0,
    'opening' => 0, 'transfer' => 0, 'weekly' => 0,
    'salary' => 0, 'deposit' => 0, 'withdrawal' => 0, 'payment' => 0, 'other' => 0,
    'new_accounts' => 0, 'existing_accounts' => 0,
    'skipped' => 0, 'errors' => 0
];

// Bank-Hauptkonto (wird nicht als Kundenkonto importiert)
$bankAccount = 'PS2B61225563';

Database::beginTransaction();

try {
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $cols = explode("\t", $line);
        if (count($cols) < 6) {
            echo "SKIP Zeile {$lineNum}: Zu wenige Spalten{$nl}";
            $stats['skipped']++;
            continue;
        }

        $datumZeit = trim($cols[0]);
        $betrag = trim($cols[1]);
        $sender = trim($cols[2]);
        $empfaenger = trim($cols[3]);
        $nachricht = trim($cols[4]);
        $richtung = mb_strtolower(trim($cols[6] ?? $cols[5] ?? ''));

        // Datum und Zeit parsen
        $date = null;
        $time = null;
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})/', $datumZeit, $m)) {
            $date = DateTime::createFromFormat('d.m.Y', $m[1]);
            $date = $date ? $date->format('Y-m-d') : null;
            $time = $m[2] . ':00';
        }
        if (!$date) {
            $stats['skipped']++;
            continue;
        }

        // Betrag parsen (unterstützt "$ 1.193", "1.350,00", "12,00", "$ 50")
        $betrag = preg_replace('/^\$\s*/', '', $betrag);
        $betrag = str_replace('.', '', $betrag);
        $betrag = str_replace(',', '.', $betrag);
        $amount = floatval($betrag);
        if ($amount <= 0) {
            $stats['skipped']++;
            continue;
        }

        // Kontonummer aus Sender und Empfänger extrahieren
        $senderAccount = AccountManager::extractAccountFromParty($sender);
        $empfaengerAccount = AccountManager::extractAccountFromParty($empfaenger);

        // Kundenkonto bestimmen (nicht das Bank-Hauptkonto)
        $accountNumber = null;
        $accountParty = null;
        $direction = 'IN';

        if ($richtung === 'eingehend') {
            // Eingehend: Geld kommt auf das Konto des Empfängers
            if ($empfaengerAccount && $empfaengerAccount !== $bankAccount) {
                $accountNumber = $empfaengerAccount;
                $accountParty = $empfaenger;
                $direction = 'IN';
            } elseif ($senderAccount && $senderAccount !== $bankAccount) {
                // Fallback: Absender ist das Kundenkonto (z.B. bei Gebühren an die Bank)
                $accountNumber = $senderAccount;
                $accountParty = $sender;
                $direction = 'OUT';
            }
        } elseif ($richtung === 'ausgehend') {
            // Ausgehend: Geld geht vom Sender weg
            if ($senderAccount && $senderAccount !== $bankAccount) {
                $accountNumber = $senderAccount;
                $accountParty = $sender;
                $direction = 'OUT';
            } elseif ($empfaengerAccount && $empfaengerAccount !== $bankAccount) {
                $accountNumber = $empfaengerAccount;
                $accountParty = $empfaenger;
                $direction = 'IN';
            }
        }

        // Fallback: Kontonummer aus Nachricht extrahieren
        if (!$accountNumber) {
            $accountNumber = AccountManager::extractAccountNumber($nachricht);
        }

        if (!$accountNumber) {
            $stats['skipped']++;
            continue;
        }

        // Bank-Hauptkonto überspringen
        if ($accountNumber === $bankAccount) {
            $stats['skipped']++;
            continue;
        }

        // Kontotyp prüfen
        $typeConfig = AccountManager::detectAccountType($accountNumber);
        if (!$typeConfig) {
            $stats['skipped']++;
            continue;
        }

        // Transaktionstyp erkennen
        $txType = AccountManager::detectTransactionType($nachricht);

        // Kontoname aus Party extrahieren
        $accountName = null;
        if ($accountParty && $accountParty !== '-') {
            $name = AccountManager::extractNameFromParty($accountParty);
            if ($name && $name !== $typeConfig['label']) {
                $accountName = $name;
            }
        }

        // Konto erstellen/finden
        $existing = AccountManager::findByNumber($accountNumber);
        $isNew = ($existing === null);

        $accountId = AccountManager::ensureAccount(
            $accountNumber,
            $accountName,
            $txType === 'OPENING' ? $date : null
        );

        if ($txType === 'OPENING' && $existing && !$existing['opening_date']) {
            Database::update('customer_accounts',
                ['opening_date' => $date, 'opening_fee' => $amount],
                'id = ?', [$accountId]
            );
        }

        // Transaktion aufzeichnen
        AccountManager::recordTransaction(
            $accountId, $date, $amount, $txType, $nachricht, $time, null, $direction
        );

        $stats['total']++;
        $stats[strtolower($txType)]++;
        if ($isNew) {
            $stats['new_accounts']++;
        } else {
            $stats['existing_accounts']++;
        }
    }

    Database::commit();
    echo "ERFOLG! Import abgeschlossen.{$nl}{$nl}";
} catch (Exception $e) {
    Database::rollback();
    echo "FEHLER: " . $e->getMessage() . "{$nl}";
    $stats['errors']++;
}

echo "=== Statistik ==={$nl}";
echo "Gesamt verarbeitet: {$stats['total']}{$nl}";
echo "---{$nl}";
echo "Kontoeröffnungen:   {$stats['opening']}{$nl}";
echo "Überweisungsgeb.:   {$stats['transfer']}{$nl}";
echo "Kontoführungsgeb.:  {$stats['weekly']}{$nl}";
echo "Gehaltseingänge:    {$stats['salary']}{$nl}";
echo "Einzahlungen:       {$stats['deposit']}{$nl}";
echo "Abhebungen:         {$stats['withdrawal']}{$nl}";
echo "Überweisungen:      {$stats['payment']}{$nl}";
echo "Sonstige:           {$stats['other']}{$nl}";
echo "---{$nl}";
echo "Neue Konten:        {$stats['new_accounts']}{$nl}";
echo "Bestehende Konten:  {$stats['existing_accounts']}{$nl}";
echo "Übersprungen:       {$stats['skipped']}{$nl}";
echo "Fehler:             {$stats['errors']}{$nl}";
