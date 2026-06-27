<?php
/**
 * PSB Kreditverwaltung - Kundenkonten-Verwaltung
 */

class AccountManager {

    /**
     * Kontotyp-Konfiguration: Prefix => [type, label, weekly_fee, opening_fee]
     */
    private static array $accountTypes = [
        'PS2B'   => ['type' => 'BUSINESS',   'label' => 'Pacific Standard Business',   'weekly' => 100.00, 'opening' => 500.00],
        'PS2STU' => ['type' => 'STARTUP',    'label' => 'Pacific Standard Start Up',    'weekly' =>  40.00, 'opening' => 200.00],
        'PS2L'   => ['type' => 'LOHNKONTO',  'label' => 'Pacific Standard Lohnkonto',   'weekly' =>   0.00, 'opening' =>   0.00],
        'PSG'    => ['type' => 'GOLD',       'label' => 'Pacific Standard Gold',         'weekly' =>  50.00, 'opening' => 200.00],
        'PSS'    => ['type' => 'SILVER',     'label' => 'Pacific Standard Silver',       'weekly' =>  25.00, 'opening' => 100.00],
        'PSB'    => ['type' => 'BRONZE',     'label' => 'Pacific Standard Bronze',       'weekly' =>  10.00, 'opening' =>  50.00],
    ];

    /**
     * Erkennt den Kontotyp anhand der Kontonummer
     */
    public static function detectAccountType(string $accountNumber): ?array {
        // Sortiert nach Prefix-Länge (längster zuerst), damit PS2B vor PSB matcht
        foreach (self::$accountTypes as $prefix => $config) {
            if (str_starts_with($accountNumber, $prefix)) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Extrahiert die Kontonummer aus einem Referenztext
     * z.B. "Überweisungsgebühren von PSG87866956" => "PSG87866956"
     * z.B. "Kontoeröffnungsgebühr von PSB89338396" => "PSB89338396"
     * z.B. "Wöchentliche Kontoführungsgebühr von PS2B38393160" => "PS2B38393160"
     */
    public static function extractAccountNumber(string $reference): ?string {
        // Pattern: "von " gefolgt von Kontonummer (Prefix + Ziffern)
        if (preg_match('/von\s+(PS2STU|PS2B|PS2L|PSG|PSS|PSB|BRZ)(\d+)/i', $reference, $matches)) {
            return strtoupper($matches[1]) . $matches[2];
        }
        return null;
    }

    /**
     * Erkennt den Gebührentyp aus dem Referenztext
     */
    public static function detectFeeType(string $reference): ?string {
        $ref = mb_strtolower($reference);

        if (mb_strpos($ref, 'kontoeröffnungsgebühr') !== false) {
            return 'OPENING';
        }
        if (mb_strpos($ref, 'überweisungsgebühr') !== false) {
            return 'TRANSFER';
        }
        if (mb_strpos($ref, 'kontoführungsgebühr') !== false) {
            return 'WEEKLY';
        }
        return null;
    }

    /**
     * Aktuelle Bank-ID aus Session (Fallback: 1)
     */
    private static function bankId(): int {
        return (int)($_SESSION['bank_id'] ?? 1);
    }

    /**
     * Findet ein Konto anhand der Kontonummer – bank-gefiltert
     */
    public static function findByNumber(string $accountNumber): ?array {
        return Database::fetchOne(
            "SELECT * FROM customer_accounts WHERE account_number = ? AND bank_id = ?",
            [$accountNumber, self::bankId()]
        );
    }

    /**
     * Erstellt ein neues Kundenkonto (bank_id wird automatisch gesetzt)
     */
    public static function createAccount(
        string $accountNumber,
        ?string $accountName = null,
        ?string $openingDate = null,
        ?float $openingFee = null
    ): int {
        $typeConfig = self::detectAccountType($accountNumber);
        if (!$typeConfig) {
            throw new Exception("Unbekannter Kontotyp für: {$accountNumber}");
        }

        $effectiveOpeningFee = $openingFee ?? $typeConfig['opening'];

        return Database::insert('customer_accounts', [
            'bank_id'           => self::bankId(),
            'account_number'    => $accountNumber,
            'account_name'      => $accountName ?? $typeConfig['label'],
            'account_type'      => $typeConfig['type'],
            'account_type_label'=> $typeConfig['label'],
            'weekly_fee'        => $typeConfig['weekly'],
            'opening_fee'       => $effectiveOpeningFee,
            'opening_date'      => $openingDate,
            'total_fees_paid'   => $effectiveOpeningFee,
            'status'            => 'ACTIVE'
        ]);
    }

    /**
     * Erstellt ein Konto falls es nicht existiert, gibt die Account-ID zurück
     */
    public static function ensureAccount(
        string $accountNumber,
        ?string $accountName = null,
        ?string $openingDate = null
    ): int {
        $existing = self::findByNumber($accountNumber);
        if ($existing) {
            // Name aktualisieren wenn ein besserer Name übergeben wird
            if ($accountName && $existing['account_name'] !== $accountName
                && $accountName !== ($existing['account_type_label'] ?? '')) {
                Database::update('customer_accounts',
                    ['account_name' => $accountName],
                    'id = ?', [$existing['id']]
                );
            }
            return $existing['id'];
        }
        return self::createAccount($accountNumber, $accountName, $openingDate);
    }

    /**
     * Zeichnet eine Transaktion für ein Konto auf
     */
    public static function recordTransaction(
        int $accountId,
        string $date,
        float $amount,
        string $feeType,
        string $description = '',
        ?string $time = null,
        ?int $bankTransactionId = null,
        string $direction = 'IN'
    ): int {
        $txId = Database::insert('account_transactions', [
            'account_id' => $accountId,
            'transaction_date' => $date,
            'transaction_time' => $time,
            'amount' => $amount,
            'fee_type' => $feeType,
            'direction' => $direction,
            'description' => $description,
            'bank_transaction_id' => $bankTransactionId
        ]);

        // Kontosummen nur bei Gebühren aktualisieren
        if (in_array($feeType, ['OPENING', 'TRANSFER', 'WEEKLY'])) {
            $updateField = match($feeType) {
                'TRANSFER' => 'total_transfer_fees',
                'WEEKLY' => 'total_weekly_fees',
                default => 'total_fees_paid'
            };

            if ($feeType === 'TRANSFER' || $feeType === 'WEEKLY') {
                Database::query(
                    "UPDATE customer_accounts SET
                        {$updateField} = {$updateField} + ?,
                        total_fees_paid = total_fees_paid + ?
                    WHERE id = ?",
                    [$amount, $amount, $accountId]
                );
            } else {
                Database::query(
                    "UPDATE customer_accounts SET total_fees_paid = total_fees_paid + ? WHERE id = ?",
                    [$amount, $accountId]
                );
            }
        }

        return $txId;
    }

    /**
     * Erkennt den Transaktionstyp aus dem Referenztext (erweitert)
     */
    public static function detectTransactionType(string $reference): string {
        $ref = mb_strtolower($reference);

        if (mb_strpos($ref, 'kontoeröffnungsgebühr') !== false) return 'OPENING';
        if (mb_strpos($ref, 'überweisungsgebühr') !== false) return 'TRANSFER';
        if (mb_strpos($ref, 'kontoführungsgebühr') !== false) return 'WEEKLY';
        if (mb_strpos($ref, 'gehaltsauszahlung') !== false) return 'SALARY';
        if (mb_strpos($ref, 'einzahlung') !== false) return 'DEPOSIT';
        if (mb_strpos($ref, 'abhebung') !== false || mb_strpos($ref, 'auszahlung') !== false) return 'WITHDRAWAL';
        if (mb_strpos($ref, 'überweisung') !== false) return 'PAYMENT';

        return 'OTHER';
    }

    /**
     * Extrahiert eine Kontonummer aus einem Sender/Empfänger-String
     * z.B. "Pacific Standard Gold (PSG83488088)" => "PSG83488088"
     */
    public static function extractAccountFromParty(string $party): ?string {
        if (preg_match('/\((PS2STU|PS2B|PS2L|PSG|PSS|PSB)(\d+)\)/i', $party, $m)) {
            return strtoupper($m[1]) . $m[2];
        }
        return null;
    }

    /**
     * Extrahiert den Anzeigenamen aus einem Sender/Empfänger-String
     * z.B. "Pacific Standard Gold (PSG83488088)" => "Pacific Standard Gold"
     */
    public static function extractNameFromParty(string $party): ?string {
        if (preg_match('/^(.+?)\s*\(/', $party, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Verarbeitet eine Gebühren-Transaktion komplett:
     * - Konto erkennen / anlegen
     * - Transaktion aufzeichnen
     *
     * Gibt [account_id, transaction_id, is_new_account, fee_type] zurück
     */
    public static function processFeeTransaction(
        string $reference,
        string $date,
        float $amount,
        ?string $senderName = null,
        ?string $time = null,
        ?int $bankTransactionId = null
    ): ?array {
        $accountNumber = self::extractAccountNumber($reference);
        if (!$accountNumber) {
            return null;
        }

        $feeType = self::detectFeeType($reference);
        if (!$feeType) {
            return null;
        }

        // Prüfen ob Kontotyp bekannt ist
        $typeConfig = self::detectAccountType($accountNumber);
        if (!$typeConfig) {
            return null;
        }

        $existing = self::findByNumber($accountNumber);
        $isNew = ($existing === null);

        // Kontoname aus Sendername ableiten (falls vorhanden und nicht generisch)
        $accountName = null;
        if ($senderName && $senderName !== '-' && $senderName !== 'Unbekannt') {
            // Konto-ID aus Klammern extrahieren und entfernen für besseren Namen
            if (preg_match('/^(.+?)\s*\(' . preg_quote($accountNumber, '/') . '\)\s*$/', $senderName, $m)) {
                $cleanName = trim($m[1]);
                if ($cleanName && $cleanName !== $typeConfig['label']) {
                    $accountName = $cleanName;
                }
            }
        }

        // Konto sicherstellen (erstellen falls nötig)
        $accountId = self::ensureAccount($accountNumber, $accountName,
            $feeType === 'OPENING' ? $date : null
        );

        // Bei Kontoeröffnung: Eröffnungsdatum setzen falls noch nicht gesetzt
        if ($feeType === 'OPENING' && $existing && !$existing['opening_date']) {
            Database::update('customer_accounts',
                ['opening_date' => $date, 'opening_fee' => $amount],
                'id = ?', [$accountId]
            );
        }

        // Transaktion aufzeichnen
        $txId = self::recordTransaction(
            $accountId, $date, $amount, $feeType, $reference, $time, $bankTransactionId
        );

        return [
            'account_id' => $accountId,
            'transaction_id' => $txId,
            'is_new_account' => $isNew,
            'fee_type' => $feeType,
            'account_number' => $accountNumber
        ];
    }

    /**
     * Holt alle Konten mit optionalen Filtern – immer bank-gefiltert
     */
    public static function getAccounts(
        ?string $search = null,
        ?string $typeFilter = null,
        ?string $statusFilter = null,
        string $orderBy = 'account_number',
        string $order = 'ASC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $where  = ["bank_id = ?"];
        $params = [self::bankId()];

        if ($search) {
            $where[] = "(account_number LIKE ? OR account_name LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($typeFilter) {
            $where[] = "account_type = ?";
            $params[] = $typeFilter;
        }
        if ($statusFilter) {
            $where[] = "status = ?";
            $params[] = $statusFilter;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $allowedOrderBy = ['account_number', 'account_name', 'account_type', 'total_fees_paid', 'opening_date', 'created_at'];
        if (!in_array($orderBy, $allowedOrderBy)) $orderBy = 'account_number';
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $accounts = Database::fetchAll(
            "SELECT * FROM customer_accounts {$whereClause}
             ORDER BY {$orderBy} {$order} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $total = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM customer_accounts {$whereClause}",
            $params
        )['cnt'] ?? 0;

        return ['accounts' => $accounts, 'total' => $total];
    }

    /**
     * Holt Transaktionen für ein Konto
     */
    public static function getAccountTransactions(int $accountId, int $limit = 100, int $offset = 0): array {
        return Database::fetchAll(
            "SELECT * FROM account_transactions
             WHERE account_id = ?
             ORDER BY transaction_date DESC, transaction_time DESC
             LIMIT ? OFFSET ?",
            [$accountId, $limit, $offset]
        );
    }

    /**
     * Gibt die Kontotyp-Konfiguration zurück
     */
    public static function getAccountTypes(): array {
        return self::$accountTypes;
    }

    /**
     * Statistiken für Dashboard – bank-gefiltert
     */
    public static function getStats(): array {
        return Database::fetchOne("
            SELECT
                COUNT(*) as total_accounts,
                SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active_accounts,
                SUM(CASE WHEN account_type = 'BRONZE' THEN 1 ELSE 0 END) as bronze_count,
                SUM(CASE WHEN account_type = 'SILVER' THEN 1 ELSE 0 END) as silver_count,
                SUM(CASE WHEN account_type = 'GOLD' THEN 1 ELSE 0 END) as gold_count,
                SUM(CASE WHEN account_type = 'BUSINESS' THEN 1 ELSE 0 END) as business_count,
                SUM(CASE WHEN account_type = 'STARTUP' THEN 1 ELSE 0 END) as startup_count,
                SUM(CASE WHEN account_type = 'LOHNKONTO' THEN 1 ELSE 0 END) as lohn_count,
                COALESCE(SUM(total_fees_paid), 0) as total_revenue,
                COALESCE(SUM(total_transfer_fees), 0) as total_transfer_revenue,
                COALESCE(SUM(total_weekly_fees), 0) as total_weekly_revenue,
                COALESCE(SUM(weekly_fee), 0) as expected_weekly_revenue
            FROM customer_accounts
            WHERE bank_id = ?
        ", [self::bankId()]) ?: [
            'total_accounts' => 0, 'active_accounts' => 0,
            'bronze_count' => 0, 'silver_count' => 0, 'gold_count' => 0,
            'business_count' => 0, 'startup_count' => 0, 'lohn_count' => 0,
            'total_revenue' => 0, 'total_transfer_revenue' => 0,
            'total_weekly_revenue' => 0, 'expected_weekly_revenue' => 0
        ];
    }

    /**
     * Übersetzt den Kontotyp
     */
    public static function translateAccountType(string $type): string {
        return match($type) {
            'BRONZE' => 'Bronze',
            'SILVER' => 'Silver',
            'GOLD' => 'Gold',
            'BUSINESS' => 'Business',
            'STARTUP' => 'Start Up',
            'LOHNKONTO' => 'Lohnkonto',
            default => $type
        };
    }

    /**
     * Gibt die Badge-Klasse für den Kontotyp zurück
     */
    public static function getTypeBadgeClass(string $type): string {
        return match($type) {
            'BRONZE' => 'bg-warning text-dark',
            'SILVER' => 'bg-secondary',
            'GOLD' => 'bg-gold',
            'BUSINESS' => 'bg-primary',
            'STARTUP' => 'bg-info',
            'LOHNKONTO' => 'bg-dark',
            default => 'bg-secondary'
        };
    }

    /**
     * Übersetzt den Gebührentyp
     */
    public static function translateFeeType(string $feeType): string {
        return match($feeType) {
            'OPENING' => 'Kontoeröffnung',
            'TRANSFER' => 'Überweisungsgebühr',
            'WEEKLY' => 'Kontoführungsgebühr',
            'SALARY' => 'Gehalt',
            'DEPOSIT' => 'Einzahlung',
            'WITHDRAWAL' => 'Abhebung',
            'PAYMENT' => 'Überweisung',
            'OTHER' => 'Sonstige',
            default => $feeType
        };
    }
}
