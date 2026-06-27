<?php
/**
 * PSB Kreditverwaltung - Bank Import Klasse
 */

class BankImport {

    /**
     * Parst Kontoauszugsdaten (CSV oder Text)
     */
    public static function parseStatement(string $content, float $minAmount = 0): array {
        $transactions = [];
        $lines = explode("\n", trim($content));

        // Tab-getrenntes Format erkennen (mit oder ohne Header)
        if (count($lines) >= 1 && self::isPSBTabFormat($lines[0])) {
            $transactions = self::parsePSBTabFormat($lines, $minAmount);
            return self::deduplicateTransactions($transactions);
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // CSV-Format versuchen (Semikolon oder Komma getrennt)
            $parts = str_getcsv($line, ';');
            if (count($parts) < 3) {
                $parts = str_getcsv($line, ',');
            }

            if (count($parts) >= 3) {
                // Erwartetes Format: Datum;Betrag;Name;IBAN;Verwendungszweck
                $tx = self::parseCSVLine($parts);
                if ($tx) {
                    $transactions[] = $tx;
                    continue;
                }
            }

            // Freitext-Format versuchen
            $tx = self::parseTextLine($line);
            if ($tx) {
                $transactions[] = $tx;
            }
        }

        return self::deduplicateTransactions($transactions);
    }

    /**
     * Entfernt doppelte Einträge (z.B. durch Copy-Paste-Fehler)
     * Gleicher Tag + Uhrzeit + Betrag + Referenz = Duplikat
     */
    private static function deduplicateTransactions(array $transactions): array {
        $seen = [];
        $unique = [];

        foreach ($transactions as $tx) {
            $key = implode('|', [
                $tx['transaction_date'] ?? '',
                $tx['transaction_time'] ?? '',
                number_format($tx['amount'] ?? 0, 2, '.', ''),
                $tx['reference'] ?? '',
                $tx['direction'] ?? '',
            ]);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $tx;
            }
        }

        return $unique;
    }

    /**
     * Parst eine CSV-Zeile
     */
    private static function parseCSVLine(array $parts): ?array {
        // Minimum: Datum, Betrag, Name
        if (count($parts) < 3) return null;

        $date = self::parseDate(trim($parts[0]));
        $amount = self::parseAmount(trim($parts[1]));
        $name = trim($parts[2] ?? '');
        $iban = isset($parts[3]) ? self::cleanIBAN(trim($parts[3])) : null;
        $reference = trim($parts[4] ?? '');

        if (!$date || !$amount || !$name) return null;

        return [
            'transaction_date' => $date,
            'amount' => $amount,
            'sender_name' => $name,
            'sender_iban' => $iban,
            'reference' => $reference
        ];
    }

    /**
     * Parst eine Freitext-Zeile
     */
    private static function parseTextLine(string $line): ?array {
        // Versuche Muster wie: "14.02.2024 1500.00 Max Mustermann RATE-AK-2024-00001"
        $pattern = '/(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})\s+([\d.,]+)\s+(.+)/';

        if (preg_match($pattern, $line, $matches)) {
            $date = self::parseDate($matches[1]);
            $amount = self::parseAmount($matches[2]);
            $rest = trim($matches[3]);

            // Versuche IBAN zu extrahieren
            $iban = null;
            if (preg_match('/([A-Z]{2}\d{2}[A-Z0-9]{4,30})/', $rest, $ibanMatch)) {
                $iban = $ibanMatch[1];
                $rest = str_replace($ibanMatch[0], '', $rest);
            }

            // Versuche Zahlungsreferenz zu extrahieren
            $reference = '';
            if (preg_match('/(RATE-[A-Z]{2}-\d{4}-\d{5}|[A-Z]{2}-\d{4}-\d{5})/', $rest, $refMatch)) {
                $reference = $refMatch[1];
            }

            $name = trim(preg_replace('/\s+/', ' ', $rest));

            if ($date && $amount) {
                return [
                    'transaction_date' => $date,
                    'amount' => $amount,
                    'sender_name' => $name ?: 'Unbekannt',
                    'sender_iban' => $iban,
                    'reference' => $reference ?: $rest
                ];
            }
        }

        return null;
    }

    /**
     * Prüft ob die Daten dem PSB Tab-Format entsprechen (mit oder ohne Header)
     */
    private static function isPSBTabFormat(string $firstLine): bool {
        $firstLine = trim($firstLine);
        $cols = preg_split('/\t+/', $firstLine);
        if (count($cols) < 5) return false;

        // Mit Header: Spaltenüberschriften erkennen
        $normalized = array_map(function($c) { return mb_strtolower(trim($c)); }, $cols);
        if (in_array('datum', $normalized) && in_array('betrag', $normalized)) {
            return true;
        }

        // Ohne Header: Datenzeile erkennen (Datum am Anfang, Eingehend/Ausgehend am Ende)
        $firstCol = trim($cols[0]);
        $lastCol = mb_strtolower(trim($cols[count($cols) - 1]));
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}/', $firstCol)
            && in_array($lastCol, ['eingehend', 'ausgehend'])) {
            return true;
        }

        return false;
    }

    /**
     * Parst das PSB Tab-Format (mit oder ohne Header)
     * Spalten: Datum | Betrag | Sender | Empfänger | Nachricht | Ausstehend | Richtung
     */
    private static function parsePSBTabFormat(array $lines, float $minAmount = 0): array {
        $transactions = [];
        $startLine = 0;

        // Prüfen ob erste Zeile ein Header ist
        $firstCols = preg_split('/\t+/', trim($lines[0]));
        $normalized = array_map(function($c) { return mb_strtolower(trim($c)); }, $firstCols);

        if (in_array('datum', $normalized) && in_array('betrag', $normalized)) {
            // Header vorhanden - Spalten dynamisch mappen
            $colMap = [];
            foreach ($firstCols as $i => $col) {
                $colMap[mb_strtolower(trim($col))] = $i;
            }
            $startLine = 1;
        } else {
            // Kein Header - feste Spaltenreihenfolge annehmen
            $colMap = [
                'datum' => 0, 'betrag' => 1, 'sender' => 2,
                'empfänger' => 3, 'nachricht' => 4, 'ausstehend' => 5, 'richtung' => 6
            ];
            $startLine = 0;
        }

        $iDatum      = $colMap['datum'] ?? null;
        $iBetrag     = $colMap['betrag'] ?? null;
        $iSender     = $colMap['sender'] ?? null;
        $iEmpfaenger = $colMap['empfänger'] ?? null;
        $iNachricht  = $colMap['nachricht'] ?? null;
        $iAusstehend = $colMap['ausstehend'] ?? null;
        $iRichtung   = $colMap['richtung'] ?? null;

        if ($iDatum === null || $iBetrag === null) return [];

        for ($i = $startLine; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $cols = preg_split('/\t+/', $line);

            // Richtung bestimmen
            $richtung = 'eingehend';
            if ($iRichtung !== null && isset($cols[$iRichtung])) {
                $richtung = mb_strtolower(trim($cols[$iRichtung]));
            }

            // Ausstehende Transaktionen überspringen
            if ($iAusstehend !== null && isset($cols[$iAusstehend])) {
                $ausstehend = mb_strtolower(trim($cols[$iAusstehend]));
                if ($ausstehend === 'ja') continue;
            }

            // Datum parsen (kann Uhrzeit enthalten: "24.10.2025 22:18")
            $datumStr = trim($cols[$iDatum] ?? '');
            $date = self::parseDate(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $datumStr));
            if (!$date) continue;

            // Betrag parsen
            $amount = self::parseAmount(trim($cols[$iBetrag] ?? ''));
            if (!$amount) continue;

            // Mindestbetrag-Filter
            if ($minAmount > 0 && $amount < $minAmount) continue;

            // Sender und Empfänger lesen
            $senderCol = trim($cols[$iSender] ?? '');
            $empfaengerCol = ($iEmpfaenger !== null) ? trim($cols[$iEmpfaenger] ?? '') : '';

            // Sender-Name: bevorzugt aus Sender-Spalte
            $senderName = '';
            if ($senderCol && $senderCol !== '-') {
                $senderName = $senderCol;
            }

            // Nachricht als Referenz
            $nachricht = trim($cols[$iNachricht] ?? '');
            $reference = $nachricht;

            // Fallback: Sender-Kennung aus Nachricht extrahieren
            if (empty($senderName) && preg_match('/\b([A-Z]{2,4}\d{5,})\b/', $nachricht, $idMatch)) {
                $senderName = $idMatch[1];
            }

            // Letzter Fallback: Empfänger-Spalte
            if (empty($senderName) && $empfaengerCol && $empfaengerCol !== '-') {
                $senderName = $empfaengerCol;
            }

            if (empty($senderName)) {
                $senderName = 'Unbekannt';
            }

            // Zeit aus Datum extrahieren
            $time = null;
            if (preg_match('/\s+(\d{1,2}:\d{2})(:\d{2})?$/', $datumStr, $timeMatch)) {
                $time = $timeMatch[1] . ':00';
            }

            $transactions[] = [
                'transaction_date' => $date,
                'transaction_time' => $time,
                'amount' => $amount,
                'sender_name' => $senderName,
                'sender_iban' => null,
                'reference' => $reference,
                'direction' => $richtung,
                'sender_party' => $senderCol,
                'empfaenger_party' => $empfaengerCol,
            ];
        }

        return $transactions;
    }

    /**
     * Parst ein Datum
     */
    private static function parseDate(string $str): ?string {
        $formats = ['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.y', 'd/m/y'];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $str);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        // Versuche strtotime
        $ts = strtotime($str);
        if ($ts) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * Parst einen Betrag
     */
    private static function parseAmount(string $str): ?float {
        // Entferne Währungssymbole und Leerzeichen
        $str = preg_replace('/[€$\s]/', '', $str);

        // Deutsche Format mit Dezimale: 1.234,56 -> 1234.56
        if (preg_match('/^\d{1,3}(\.\d{3})*,\d{2}$/', $str)) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        }
        // Nur Tausender-Punkt ohne Dezimale: 1.700 -> 1700
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $str)) {
            $str = str_replace('.', '', $str);
        }
        // Tausender-Komma: 1,234.56 -> 1234.56
        elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d{2}$/', $str)) {
            $str = str_replace(',', '', $str);
        }
        // Einfaches Komma als Dezimaltrenner
        else {
            $str = str_replace(',', '.', $str);
        }

        $amount = floatval($str);
        return $amount > 0 ? $amount : null;
    }

    /**
     * Bereinigt IBAN
     */
    private static function cleanIBAN(?string $iban): ?string {
        if (!$iban) return null;
        $iban = strtoupper(preg_replace('/\s/', '', $iban));
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $iban) ? $iban : null;
    }

    /**
     * Prüft ob eine Transaktion eine Gebühr ist
     */
    public static function isFeeTransaction(array $tx): bool {
        $ref = mb_strtolower($tx['reference'] ?? '');
        $feeKeywords = [
            'überweisungsgebühr',
            'kontoeröffnungsgebühr',
            'kontoführungsgebühr',
            'kontogebühr',
            'gebühren',
        ];
        foreach ($feeKeywords as $keyword) {
            if (mb_strpos($ref, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Klassifiziert eine Gebührentransaktion und gibt Details zurück
     */
    public static function classifyFeeTransaction(array $tx): array {
        $reference = $tx['reference'] ?? '';
        $feeType = AccountManager::detectFeeType($reference);
        $accountNumber = AccountManager::extractAccountNumber($reference);
        $accountExists = $accountNumber ? (AccountManager::findByNumber($accountNumber) !== null) : false;

        return [
            'fee_type' => $feeType,
            'account_number' => $accountNumber,
            'account_exists' => $accountExists,
            'fee_type_label' => $feeType ? AccountManager::translateFeeType($feeType) : 'Unbekannt'
        ];
    }

    /**
     * Prüft ob eine Transaktion bereits importiert wurde
     */
    public static function isDuplicate(array $tx): bool {
        $existing = Database::fetchOne(
            "SELECT id FROM bank_transactions
             WHERE transaction_date = ? AND ABS(amount - ?) < 0.01 AND reference = ?
             LIMIT 1",
            [$tx['transaction_date'], $tx['amount'], $tx['reference']]
        );
        return $existing !== null && $existing !== false;
    }

    /**
     * Markiert Transaktionen mit Flags (is_fee, is_duplicate) für die Vorschau
     */
    public static function flagTransactions(array $transactions): array {
        foreach ($transactions as &$tx) {
            $tx['is_fee'] = self::isFeeTransaction($tx);
            $tx['is_duplicate'] = self::isDuplicate($tx);
        }
        return $transactions;
    }

    /**
     * Erstellt einen Import-Batch (überspringt Duplikate)
     * Speichert ALLE Transaktionen sowohl in bank_transactions als auch in account_transactions
     */
    public static function createBatch(array $transactions, ?string $filename = null): int {
        $toInsert = [];
        $skippedDuplicates = 0;

        foreach ($transactions as $tx) {
            if (self::isDuplicate($tx)) {
                $skippedDuplicates++;
                continue;
            }
            $toInsert[] = $tx;
        }

        if (count($toInsert) === 0) return 0;

        // Bank-Hauptkonto aus Konfiguration (wird bei Kontozuordnung übersprungen)
        $bankMainAccount = defined('BANK_MAIN_ACCOUNT') ? BANK_MAIN_ACCOUNT : null;

        $batchId = Database::insert('bank_statement_batches', [
            'bank_id'            => (int)($_SESSION['bank_id'] ?? 1),
            'batch_date'         => date('Y-m-d'),
            'filename'           => $filename,
            'total_transactions' => count($toInsert),
            'status'             => 'PROCESSING',
            'imported_by'        => Auth::userId()
        ]);

        foreach ($toInsert as $tx) {
            $isFee = self::isFeeTransaction($tx);

            // In bank_transactions einfügen
            $bankTxId = Database::insert('bank_transactions', [
                'batch_id' => $batchId,
                'transaction_date' => $tx['transaction_date'],
                'transaction_time' => $tx['transaction_time'] ?? null,
                'amount' => $tx['amount'],
                'sender_name' => $tx['sender_name'],
                'sender_iban' => $tx['sender_iban'] ?? null,
                'sender_party' => $tx['sender_party'] ?? null,
                'empfaenger_party' => $tx['empfaenger_party'] ?? null,
                'direction' => $tx['direction'] ?? null,
                'reference' => $tx['reference'],
                'match_status' => $isFee ? 'FEE' : 'UNMATCHED'
            ]);

            // Kundenkonten aus Sender und Empfänger extrahieren
            $senderAccount = AccountManager::extractAccountFromParty($tx['sender_party'] ?? '');
            $empfaengerAccount = AccountManager::extractAccountFromParty($tx['empfaenger_party'] ?? '');

            $accountsToProcess = [];
            if ($senderAccount && $senderAccount !== $bankMainAccount) {
                $accountsToProcess[] = [
                    'number' => $senderAccount,
                    'direction' => 'OUT',
                    'party' => $tx['sender_party'] ?? ''
                ];
            }
            if ($empfaengerAccount && $empfaengerAccount !== $bankMainAccount) {
                $accountsToProcess[] = [
                    'number' => $empfaengerAccount,
                    'direction' => 'IN',
                    'party' => $tx['empfaenger_party'] ?? ''
                ];
            }

            // Fallback: Kontonummer aus Referenz
            if (empty($accountsToProcess)) {
                $fromRef = AccountManager::extractAccountNumber($tx['reference'] ?? '');
                if ($fromRef && $fromRef !== $bankMainAccount) {
                    $accountsToProcess[] = [
                        'number' => $fromRef,
                        'direction' => 'OUT',
                        'party' => ''
                    ];
                }
            }

            // Für jedes identifizierte Kundenkonto aufzeichnen
            foreach ($accountsToProcess as $acct) {
                $accountNumber = $acct['number'];
                $typeConfig = AccountManager::detectAccountType($accountNumber);
                if (!$typeConfig) continue;

                try {
                    $txType = AccountManager::detectTransactionType($tx['reference']);
                    $time = $tx['transaction_time'] ?? null;

                    // Kontoname aus Party extrahieren
                    $accountName = null;
                    if ($acct['party'] && $acct['party'] !== '-') {
                        $name = AccountManager::extractNameFromParty($acct['party']);
                        if ($name && $name !== $typeConfig['label']) {
                            $accountName = $name;
                        }
                    }

                    $existing = AccountManager::findByNumber($accountNumber);
                    $accountId = AccountManager::ensureAccount(
                        $accountNumber, $accountName,
                        $txType === 'OPENING' ? $tx['transaction_date'] : null
                    );

                    // Eröffnungsdatum setzen falls nötig
                    if ($txType === 'OPENING' && $existing && !$existing['opening_date']) {
                        Database::update('customer_accounts',
                            ['opening_date' => $tx['transaction_date'], 'opening_fee' => $tx['amount']],
                            'id = ?', [$accountId]
                        );
                    }

                    AccountManager::recordTransaction(
                        $accountId, $tx['transaction_date'], $tx['amount'],
                        $txType, $tx['reference'], $time, $bankTxId, $acct['direction']
                    );
                } catch (Exception $e) {
                    error_log("Account transaction failed for tx {$bankTxId}: " . $e->getMessage());
                }
            }
        }

        return $batchId;
    }

    /**
     * Verarbeitet alle bank_transactions neu und erstellt account_transactions
     * Löscht bestehende account_transactions und customer_accounts und baut alles neu auf
     */
    public static function reprocessAccountTransactions(): array {
        $bankMainAccount = defined('BANK_MAIN_ACCOUNT') ? BANK_MAIN_ACCOUNT : null;
        $bankId = (int)($_SESSION['bank_id'] ?? 1);

        $stats = [
            'bank_transactions' => 0,
            'account_transactions_created' => 0,
            'accounts_created' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Nur account_transactions und customer_accounts der aktuellen Bank löschen
        Database::query("
            DELETE at FROM account_transactions at
            JOIN customer_accounts ca ON at.account_id = ca.id
            WHERE ca.bank_id = ?
        ", [$bankId]);
        Database::query("DELETE FROM customer_accounts WHERE bank_id = ?", [$bankId]);

        // Nur bank_transactions der aktuellen Bank laden
        $bankTxs = Database::fetchAll("
            SELECT bt.* FROM bank_transactions bt
            JOIN bank_statement_batches bsb ON bt.batch_id = bsb.id
            WHERE bsb.bank_id = ?
            ORDER BY bt.transaction_date ASC, bt.transaction_time ASC
        ", [$bankId]);

        $stats['bank_transactions'] = count($bankTxs);
        $knownAccounts = []; // Cache für bereits angelegte Konten

        foreach ($bankTxs as $bt) {
            $senderAccount = AccountManager::extractAccountFromParty($bt['sender_party'] ?? '');
            $empfaengerAccount = AccountManager::extractAccountFromParty($bt['empfaenger_party'] ?? '');

            $accountsToProcess = [];
            if ($senderAccount && $senderAccount !== $bankMainAccount) {
                $accountsToProcess[] = [
                    'number' => $senderAccount,
                    'direction' => 'OUT',
                    'party' => $bt['sender_party'] ?? ''
                ];
            }
            if ($empfaengerAccount && $empfaengerAccount !== $bankMainAccount) {
                $accountsToProcess[] = [
                    'number' => $empfaengerAccount,
                    'direction' => 'IN',
                    'party' => $bt['empfaenger_party'] ?? ''
                ];
            }

            // Fallback: Kontonummer aus Referenz
            if (empty($accountsToProcess)) {
                $fromRef = AccountManager::extractAccountNumber($bt['reference'] ?? '');
                if ($fromRef && $fromRef !== $bankMainAccount) {
                    $accountsToProcess[] = [
                        'number' => $fromRef,
                        'direction' => 'OUT',
                        'party' => $bt['sender_name'] ?? ''
                    ];
                }
            }

            if (empty($accountsToProcess)) {
                $stats['skipped']++;
                continue;
            }

            foreach ($accountsToProcess as $acct) {
                $accountNumber = $acct['number'];
                $typeConfig = AccountManager::detectAccountType($accountNumber);
                if (!$typeConfig) continue;

                try {
                    $txType = AccountManager::detectTransactionType($bt['reference'] ?? '');
                    $time = $bt['transaction_time'] ?? null;

                    // Kontoname aus Party extrahieren
                    $accountName = null;
                    if ($acct['party'] && $acct['party'] !== '-') {
                        $name = AccountManager::extractNameFromParty($acct['party']);
                        if ($name && $name !== $typeConfig['label']) {
                            $accountName = $name;
                        }
                    }

                    $isNew = !isset($knownAccounts[$accountNumber]);
                    $existing = AccountManager::findByNumber($accountNumber);
                    $accountId = AccountManager::ensureAccount(
                        $accountNumber, $accountName,
                        $txType === 'OPENING' ? $bt['transaction_date'] : null
                    );

                    if ($isNew && !$existing) {
                        $knownAccounts[$accountNumber] = $accountId;
                        $stats['accounts_created']++;
                    }
                    $knownAccounts[$accountNumber] = $accountId;

                    if ($txType === 'OPENING' && $existing && !$existing['opening_date']) {
                        Database::update('customer_accounts',
                            ['opening_date' => $bt['transaction_date'], 'opening_fee' => $bt['amount']],
                            'id = ?', [$accountId]
                        );
                    }

                    AccountManager::recordTransaction(
                        $accountId, $bt['transaction_date'], floatval($bt['amount']),
                        $txType, $bt['reference'] ?? '', $time, $bt['id'], $acct['direction']
                    );
                    $stats['account_transactions_created']++;
                } catch (Exception $e) {
                    $stats['errors']++;
                    error_log("Reprocess failed for bt {$bt['id']}: " . $e->getMessage());
                }
            }
        }

        return $stats;
    }
}
