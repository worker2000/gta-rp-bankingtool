<?php
/**
 * PSB Kreditverwaltung - Kredit-Score Berechnung
 *
 * Bewertet die Kontoführung anhand von 5 Faktoren (0-100 Punkte):
 * - Gebühren-Treue (30 Pkt): Regelmäßigkeit der Wochengebühren
 * - Kontoaktivität (20 Pkt): Überweisungsvolumen und -häufigkeit
 * - Kontoalter (20 Pkt): Dauer der Kontobeziehung
 * - Kontotyp (20 Pkt): Finanzielles Engagement (Kontostufe)
 * - Gehaltseingang (10 Pkt): Regelmäßige Gehaltseingänge auf dem Konto
 */
class CreditScore {

    private const MAX_FEE_REGULARITY = 30;
    private const MAX_ACTIVITY = 20;
    private const MAX_ACCOUNT_AGE = 20;
    private const MAX_ACCOUNT_TYPE = 20;
    private const MAX_SALARY_INCOME = 10;
    private const AGE_FULL_SCORE_WEEKS = 12;

    private static array $typePoints = [
        'LOHNKONTO' => 2,
        'BRONZE'    => 6,
        'SILVER'    => 10,
        'STARTUP'   => 13,
        'GOLD'      => 16,
        'BUSINESS'  => 20,
    ];

    /**
     * Berechnet den Kredit-Score für ein Konto
     * @param array $account Zeile aus customer_accounts
     * @return array ['total', 'fee_regularity', 'activity', 'account_age', 'account_type', 'salary_income']
     */
    public static function calculate(array $account): array {
        $feeRegularity = self::calculateFeeRegularity($account);
        $activity = self::calculateActivity($account['id']);
        $accountAge = self::calculateAccountAge($account);
        $accountType = self::calculateAccountType($account['account_type']);
        $salaryIncome = self::calculateSalaryIncome($account);

        return [
            'total' => $feeRegularity + $activity + $accountAge + $accountType + $salaryIncome,
            'fee_regularity' => $feeRegularity,
            'activity' => $activity,
            'account_age' => $accountAge,
            'account_type' => $accountType,
            'salary_income' => $salaryIncome,
        ];
    }

    /**
     * Gebühren-Treue: Verhältnis gezahlte vs. erwartete Wochengebühren (0-30 Pkt)
     */
    private static function calculateFeeRegularity(array $account): int {
        // LOHNKONTO hat keine Gebührenpflicht → volle Punkte
        if ($account['weekly_fee'] <= 0) {
            return self::MAX_FEE_REGULARITY;
        }

        $openingDate = $account['opening_date'] ?? $account['created_at'] ?? null;
        if (!$openingDate) {
            return 0;
        }

        $start = new DateTime($openingDate);
        $now = new DateTime();
        $daysDiff = (int) $start->diff($now)->days;
        $expectedWeeks = (int) floor($daysDiff / 7);

        if ($expectedWeeks <= 0) {
            return 0;
        }

        $result = Database::fetchOne(
            "SELECT COUNT(*) as weekly_count FROM account_transactions WHERE account_id = ? AND fee_type = 'WEEKLY'",
            [$account['id']]
        );
        $actualWeeks = (int) ($result['weekly_count'] ?? 0);

        $ratio = min(1.0, $actualWeeks / $expectedWeeks);
        return (int) floor($ratio * self::MAX_FEE_REGULARITY);
    }

    /**
     * Kontoaktivität: Überweisungshäufigkeit (0-12) + Volumen (0-8) = 0-20 Pkt
     */
    private static function calculateActivity(int $accountId): int {
        $result = Database::fetchOne(
            "SELECT COUNT(*) as transfer_count, COALESCE(SUM(amount), 0) as transfer_total
             FROM account_transactions WHERE account_id = ? AND fee_type = 'TRANSFER'",
            [$accountId]
        );

        $count = (int) ($result['transfer_count'] ?? 0);
        $total = (float) ($result['transfer_total'] ?? 0);

        // Häufigkeit (0-12 Pkt)
        if ($count >= 16) {
            $countScore = 12;
        } elseif ($count >= 6) {
            $countScore = 8;
        } elseif ($count >= 1) {
            $countScore = 4;
        } else {
            $countScore = 0;
        }

        // Volumen (0-8 Pkt)
        if ($total >= 200) {
            $volumeScore = 8;
        } elseif ($total >= 50) {
            $volumeScore = 5;
        } elseif ($total > 0) {
            $volumeScore = 2;
        } else {
            $volumeScore = 0;
        }

        return $countScore + $volumeScore;
    }

    /**
     * Kontoalter: Linear bis 12 Wochen, dann volle Punkte (0-20 Pkt)
     */
    private static function calculateAccountAge(array $account): int {
        $openingDate = $account['opening_date'] ?? $account['created_at'] ?? null;
        if (!$openingDate) {
            return 0;
        }

        $start = new DateTime($openingDate);
        $now = new DateTime();
        $daysDiff = (int) $start->diff($now)->days;
        $ageWeeks = (int) floor($daysDiff / 7);

        $score = (int) floor($ageWeeks * (self::MAX_ACCOUNT_AGE / self::AGE_FULL_SCORE_WEEKS));
        return min(self::MAX_ACCOUNT_AGE, $score);
    }

    /**
     * Gehaltseingang: Regelmäßige Gehaltsbuchungen auf dem Konto (0-10 Pkt)
     *
     * Bewertung nach Häufigkeit der SALARY-Transaktionen in den letzten 28 Tagen:
     * - 3+ aktuelle Gehaltseingänge: 10 Pkt (sehr regelmäßig)
     * - 1-2 aktuelle Gehaltseingänge: 7 Pkt (regelmäßig)
     * - Gehaltshistorie vorhanden, aber nichts Aktuelles: 3 Pkt
     * - LOHNKONTO ohne bisherige Buchungen: 2 Pkt (als Gehaltskonto registriert)
     * - Kein Gehaltseingang: 0 Pkt
     */
    private static function calculateSalaryIncome(array $account): int {
        $recentResult = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM account_transactions
             WHERE account_id = ? AND fee_type = 'SALARY'
               AND transaction_date >= DATE_SUB(NOW(), INTERVAL 28 DAY)",
            [$account['id']]
        );
        $recentCount = (int) ($recentResult['cnt'] ?? 0);

        if ($recentCount >= 3) return 10;
        if ($recentCount >= 1) return 7;

        $totalResult = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM account_transactions
             WHERE account_id = ? AND fee_type = 'SALARY'",
            [$account['id']]
        );
        $totalCount = (int) ($totalResult['cnt'] ?? 0);

        if ($totalCount >= 1) return 3;
        if ($account['account_type'] === 'LOHNKONTO') return 2;

        return 0;
    }

    /**
     * Kontotyp: Feste Punktzuordnung (0-20 Pkt)
     */
    private static function calculateAccountType(string $type): int {
        return self::$typePoints[$type] ?? 0;
    }

    /**
     * Bootstrap-Farbklasse für den Score
     */
    public static function getScoreClass(int $score): string {
        if ($score >= 81) return 'text-success';
        if ($score >= 61) return 'text-info';
        if ($score >= 31) return 'text-warning';
        return 'text-danger';
    }

    /**
     * Bootstrap-Hintergrundklasse für Progress-Bar
     */
    public static function getScoreBgClass(int $score): string {
        if ($score >= 81) return 'bg-success';
        if ($score >= 61) return 'bg-info';
        if ($score >= 31) return 'bg-warning';
        return 'bg-danger';
    }

    /**
     * Textuelle Bewertung
     */
    public static function getScoreLabel(int $score): string {
        if ($score >= 81) return 'Sehr gut';
        if ($score >= 61) return 'Gut';
        if ($score >= 31) return 'Ausreichend';
        return 'Schlecht';
    }
}
