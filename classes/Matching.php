<?php
/**
 * PSB Kreditverwaltung - Matching Klasse
 */

class Matching {

    /**
     * Führt das Matching für einen Batch durch
     */
    public static function processBatch(int $batchId): array {
        $stats = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];

        $transactions = Database::fetchAll(
            "SELECT * FROM bank_transactions WHERE batch_id = ? AND match_status = 'UNMATCHED'",
            [$batchId]
        );

        // Batch-Bank für KV-Matching ermitteln
        $batch = Database::fetchOne("SELECT bank_id FROM bank_statement_batches WHERE id = ?", [$batchId]);
        $batchBankId = (int)($batch['bank_id'] ?? 0);

        foreach ($transactions as $tx) {
            $tx['_bank_id'] = $batchBankId;
            $result = self::matchTransaction($tx);

            if ($result['status'] === 'PENDING_REF_MATCHED') {
                self::applyPendingRefMatch($tx['id'], $result['pending_ref_id'], $result['method'], $result['confidence']);
                $stats['matched']++;
            } elseif ($result['status'] === 'MEMBER_MATCHED') {
                self::applyMemberMatch($tx['id'], $result['member_id'], $result['method'], $result['confidence']);
                $stats['matched']++;
            } elseif ($result['status'] === 'MATCHED') {
                self::applyMatch($tx['id'], $result['loan_id'], $result['schedule_id'], $result['method'], $result['confidence'],
                    $result['pending_ref_id'] ?? null);
                $stats['matched']++;
            } elseif ($result['status'] === 'AMBIGUOUS') {
                self::markAmbiguous($tx['id'], $result['candidates']);
                $stats['ambiguous']++;
            } else {
                $stats['unmatched']++;
            }
        }

        // Batch-Statistiken aktualisieren
        Database::update('bank_statement_batches', [
            'matched_count' => $stats['matched'],
            'ambiguous_count' => $stats['ambiguous'],
            'unmatched_count' => $stats['unmatched'],
            'status' => 'COMPLETED',
            'processed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$batchId]);

        return $stats;
    }

    /**
     * Versucht eine Transaktion zuzuordnen
     */
    public static function matchTransaction(array $tx): array {
        $candidates = [];

        // Stufe 0: Kredit-Referenznummer im Verwendungszweck (z.B. 28012026LdM03)
        $pendingRefId = self::matchByLoanRef($tx);
        if ($pendingRefId !== null) {
            // Wenn die Referenz bereits einem Kredit zugeordnet wurde, direkt matchen
            $pendingRef = Database::fetchOne(
                "SELECT loan_id, status FROM pending_loan_refs WHERE id = ?",
                [$pendingRefId]
            );
            if ($pendingRef && $pendingRef['status'] === 'CONVERTED' && $pendingRef['loan_id']) {
                $loanId   = (int)$pendingRef['loan_id'];
                $schedule = self::findOpenScheduleItem($loanId, (float)$tx['amount']);
                return ['status' => 'MATCHED', 'loan_id' => $loanId,
                        'schedule_id' => $schedule['id'] ?? null,
                        'pending_ref_id' => $pendingRefId,
                        'method' => 'LOAN_REF', 'confidence' => 1.0];
            }
            return ['status' => 'PENDING_REF_MATCHED', 'pending_ref_id' => $pendingRefId,
                    'method' => 'LOAN_REF', 'confidence' => 1.0];
        }

        // Stufe 1: Aktenzeichen im Verwendungszweck
        $result = self::matchByFileNumber($tx);
        if ($result) {
            return ['status' => 'MATCHED', 'loan_id' => $result['loan_id'],
                    'schedule_id' => $result['schedule_id'], 'method' => 'FILE_NUMBER', 'confidence' => 1.0];
        }

        // Stufe 2: IBAN + exakter Ratenbetrag
        $result = self::matchByIBANAndAmount($tx);
        if ($result) {
            if (count($result) === 1) {
                return ['status' => 'MATCHED', 'loan_id' => $result[0]['loan_id'],
                        'schedule_id' => $result[0]['schedule_id'], 'method' => 'IBAN_AMOUNT', 'confidence' => 0.95];
            } else {
                $candidates = array_merge($candidates, $result);
            }
        }

        // Stufe 3: Name + Betrag
        $result = self::matchByNameAndAmount($tx);
        if ($result) {
            if (count($result) === 1) {
                return ['status' => 'MATCHED', 'loan_id' => $result[0]['loan_id'],
                        'schedule_id' => $result[0]['schedule_id'], 'method' => 'NAME_AMOUNT', 'confidence' => 0.85];
            } else {
                $candidates = array_merge($candidates, $result);
            }
        }

        // Stufe 4: Fuzzy-Matching (nur Vorschlag)
        $result = self::fuzzyMatch($tx);
        if ($result) {
            $candidates = array_merge($candidates, $result);
        }

        // Ergebnis auswerten
        if (!empty($candidates)) {
            // Deduplizieren nach loan_id
            $unique = [];
            foreach ($candidates as $c) {
                $key = $c['loan_id'] . '-' . ($c['schedule_id'] ?? 0);
                if (!isset($unique[$key]) || $c['confidence'] > $unique[$key]['confidence']) {
                    $unique[$key] = $c;
                }
            }
            $candidates = array_values($unique);

            if (count($candidates) === 1 && $candidates[0]['confidence'] >= 0.8) {
                return ['status' => 'MATCHED', 'loan_id' => $candidates[0]['loan_id'],
                        'schedule_id' => $candidates[0]['schedule_id'], 'method' => 'FUZZY', 'confidence' => $candidates[0]['confidence']];
            }

            return ['status' => 'AMBIGUOUS', 'candidates' => $candidates];
        }

        return ['status' => 'UNMATCHED'];
    }

    /**
     * Stufe 0: Kredit-Referenznummer aus Verwendungszweck erkennen.
     * Muster wie 28012026LdM03 werden erkannt, ein pending_loan_refs-Datensatz
     * wird angelegt oder aktualisiert.
     * @return int|null pending_loan_ref_id oder null wenn kein Treffer
     */
    private static function matchByLoanRef(array $tx): ?int {
        $reference = $tx['reference'] ?? '';

        // Muster: 6–12 Ziffern + 2–8 Buchstaben + 1–4 Ziffern (z.B. 28012026LdM03)
        if (!preg_match('/\b(\d{6,12}[A-Za-z]{2,8}\d{1,4})\b/', $reference, $matches)) {
            return null;
        }
        $ref     = $matches[1];
        $bankId  = (int)($tx['_bank_id'] ?? 2);
        $amount  = (float)$tx['amount'];
        $txDate  = $tx['transaction_date'];
        $sender  = trim($tx['sender_name'] ?? '');

        // Bereits bekannte Referenz? → Zähler und Summe aktualisieren
        $existing = Database::fetchOne(
            "SELECT * FROM pending_loan_refs WHERE ref_number = ? AND bank_id = ?",
            [$ref, $bankId]
        );

        if ($existing) {
            // Zähler und Summe immer aktualisieren (auch bei CONVERTED)
            if ($existing['status'] !== 'IGNORED') {
                Database::update('pending_loan_refs', [
                    'last_seen'         => $txDate > $existing['last_seen'] ? $txDate : $existing['last_seen'],
                    'transaction_count' => $existing['transaction_count'] + 1,
                    'total_received'    => $existing['total_received'] + $amount,
                ], 'id = ?', [$existing['id']]);
            }
            return (int)$existing['id'];
        }

        // Neue Referenz anlegen
        $pendingRefId = Database::insert('pending_loan_refs', [
            'bank_id'           => $bankId,
            'ref_number'        => $ref,
            'first_seen'        => $txDate,
            'last_seen'         => $txDate,
            'transaction_count' => 1,
            'total_received'    => $amount,
            'weekly_amount'     => $amount,
            'sender_name'       => $sender ?: null,
        ]);

        AuditLog::log('CREATE', 'pending_loan_ref', $pendingRefId, null, [
            'ref_number' => $ref,
            'source'     => 'bank_import_auto',
        ]);

        return $pendingRefId;
    }

    /**
     * Wendet ein Kredit-Referenz-Match an (setzt matched_pending_ref_id)
     */
    public static function applyPendingRefMatch(int $txId, int $pendingRefId, string $method, float $confidence): void {
        Database::update('bank_transactions', [
            'match_status'           => 'MATCHED',
            'matched_pending_ref_id' => $pendingRefId,
            'match_method'           => $method,
            'match_confidence'       => $confidence,
            'matched_by'             => Auth::userId(),
            'matched_at'             => date('Y-m-d H:i:s'),
        ], 'id = ?', [$txId]);
    }

    /**
     * Wendet ein KV-Mitglieds-Match an und erstellt Beitragseingang-Eintrag
     */
    public static function applyMemberMatch(int $txId, int $memberId, string $method, float $confidence): void {
        Database::update('bank_transactions', [
            'match_status'      => 'MATCHED',
            'matched_member_id' => $memberId,
            'match_method'      => $method,
            'match_confidence'  => $confidence,
            'matched_by'        => Auth::userId(),
            'matched_at'        => date('Y-m-d H:i:s'),
        ], 'id = ?', [$txId]);

        $tx = Database::fetchOne("SELECT amount, transaction_date FROM bank_transactions WHERE id = ?", [$txId]);
        Database::insert('insurance_member_premiums', [
            'member_id'           => $memberId,
            'bank_transaction_id' => $txId,
            'amount'              => $tx['amount'],
            'payment_date'        => $tx['transaction_date'],
            'notes'               => 'Automatisch zugeordnet via ' . $method,
        ]);
    }

    /**
     * Stufe 1: Match per Aktenzeichen
     */
    private static function matchByFileNumber(array $tx): ?array {
        $reference = $tx['reference'] ?? '';

        // Suche nach Aktenzeichen-Muster
        if (preg_match('/(AK|PK|BK)-\d{4}-\d{5}/', $reference, $matches)) {
            $fileNumber = $matches[0];
            $loan = Database::fetchOne(
                "SELECT id FROM loans WHERE file_number = ? AND status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')",
                [$fileNumber]
            );

            if ($loan) {
                $schedule = self::findOpenScheduleItem($loan['id'], $tx['amount']);
                return ['loan_id' => $loan['id'], 'schedule_id' => $schedule['id'] ?? null];
            }
        }

        // Suche nach Zahlungsreferenz
        if (preg_match('/RATE-[A-Z]{2}-?\d{4}-?\d{5}/', $reference, $matches)) {
            $ref = str_replace('-', '', $matches[0]);
            $loan = Database::fetchOne(
                "SELECT id FROM loans WHERE REPLACE(payment_reference, '-', '') = ? AND status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')",
                [$ref]
            );

            if ($loan) {
                $schedule = self::findOpenScheduleItem($loan['id'], $tx['amount']);
                return ['loan_id' => $loan['id'], 'schedule_id' => $schedule['id'] ?? null];
            }
        }

        return null;
    }

    /**
     * Stufe 2: Match per IBAN + Betrag
     */
    private static function matchByIBANAndAmount(array $tx): ?array {
        if (empty($tx['sender_iban'])) return null;

        $loans = Database::fetchAll("
            SELECT l.id as loan_id, l.weekly_rate, lsi.id as schedule_id, lsi.amount_outstanding
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            LEFT JOIN loan_schedule_items lsi ON l.id = lsi.loan_id AND lsi.status IN ('PENDING', 'PARTIAL', 'OVERDUE')
            WHERE b.bank_account_iban = ?
            AND l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
            AND ABS(lsi.amount_outstanding - ?) < 0.02
            ORDER BY lsi.due_date ASC
        ", [$tx['sender_iban'], $tx['amount']]);

        if (empty($loans)) return null;

        return array_map(function($l) {
            return ['loan_id' => $l['loan_id'], 'schedule_id' => $l['schedule_id'], 'confidence' => 0.95];
        }, $loans);
    }

    /**
     * Stufe 3: Match per Name + Betrag
     */
    private static function matchByNameAndAmount(array $tx): ?array {
        $name = $tx['sender_name'];
        $nameParts = preg_split('/\s+/', trim($name));

        if (count($nameParts) < 2) return null;

        // Versuche Vor- und Nachname zu extrahieren
        $conditions = [];
        $params = [];

        foreach ($nameParts as $part) {
            if (strlen($part) > 2) {
                $conditions[] = "(b.first_name LIKE ? OR b.last_name LIKE ?)";
                $params[] = "%{$part}%";
                $params[] = "%{$part}%";
            }
        }

        if (empty($conditions)) return null;

        $params[] = $tx['amount'];

        $loans = Database::fetchAll("
            SELECT DISTINCT l.id as loan_id, l.weekly_rate, lsi.id as schedule_id
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            LEFT JOIN loan_schedule_items lsi ON l.id = lsi.loan_id AND lsi.status IN ('PENDING', 'PARTIAL', 'OVERDUE')
            WHERE (" . implode(' AND ', $conditions) . ")
            AND l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
            AND ABS(l.weekly_rate - ?) < 1
            ORDER BY lsi.due_date ASC
        ", $params);

        if (empty($loans)) return null;

        return array_map(function($l) {
            return ['loan_id' => $l['loan_id'], 'schedule_id' => $l['schedule_id'], 'confidence' => 0.85];
        }, $loans);
    }

    /**
     * Stufe 4: Fuzzy Matching
     */
    private static function fuzzyMatch(array $tx): ?array {
        // Suche nach ähnlichen Beträgen
        $loans = Database::fetchAll("
            SELECT l.id as loan_id, l.weekly_rate, l.file_number, b.first_name, b.last_name,
                   lsi.id as schedule_id, lsi.amount_outstanding
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            LEFT JOIN loan_schedule_items lsi ON l.id = lsi.loan_id AND lsi.status IN ('PENDING', 'PARTIAL', 'OVERDUE')
            WHERE l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
            AND ABS(l.weekly_rate - ?) < ?
            ORDER BY ABS(l.weekly_rate - ?) ASC
            LIMIT 5
        ", [$tx['amount'], $tx['amount'] * 0.1, $tx['amount']]);

        if (empty($loans)) return null;

        $results = [];
        foreach ($loans as $loan) {
            $confidence = 1 - abs($loan['weekly_rate'] - $tx['amount']) / $tx['amount'];

            // Bonus für Namensübereinstimmung
            $name = strtolower($tx['sender_name']);
            if (stripos($name, strtolower($loan['first_name'])) !== false ||
                stripos($name, strtolower($loan['last_name'])) !== false) {
                $confidence = min(1, $confidence + 0.2);
            }

            if ($confidence >= 0.5) {
                $results[] = [
                    'loan_id' => $loan['loan_id'],
                    'schedule_id' => $loan['schedule_id'],
                    'confidence' => round($confidence, 2),
                    'reason' => "Rate {$loan['weekly_rate']} ~ {$tx['amount']}, {$loan['first_name']} {$loan['last_name']}"
                ];
            }
        }

        return empty($results) ? null : $results;
    }

    /**
     * Findet die passende offene Rate
     */
    public static function findOpenScheduleItem(int $loanId, float $amount): ?array {
        // Exakte Übereinstimmung
        $item = Database::fetchOne("
            SELECT * FROM loan_schedule_items
            WHERE loan_id = ? AND status IN ('PENDING', 'PARTIAL', 'OVERDUE')
            AND ABS(amount_outstanding - ?) < 0.02
            ORDER BY due_date ASC LIMIT 1
        ", [$loanId, $amount]);

        if ($item) return $item;

        // Nächste offene Rate
        return Database::fetchOne("
            SELECT * FROM loan_schedule_items
            WHERE loan_id = ? AND status IN ('PENDING', 'PARTIAL', 'OVERDUE')
            ORDER BY due_date ASC LIMIT 1
        ", [$loanId]);
    }

    /**
     * Wendet ein Match an
     */
    public static function applyMatch(int $txId, int $loanId, ?int $scheduleId, string $method, float $confidence, ?int $pendingRefId = null): void {
        $fields = [
            'match_status' => 'MATCHED',
            'matched_loan_id' => $loanId,
            'matched_schedule_id' => $scheduleId,
            'match_method' => $method,
            'match_confidence' => $confidence,
            'matched_by' => Auth::userId(),
            'matched_at' => date('Y-m-d H:i:s')
        ];
        if ($pendingRefId !== null) {
            $fields['matched_pending_ref_id'] = $pendingRefId;
        }
        Database::update('bank_transactions', $fields, 'id = ?', [$txId]);

        // Zahlung auf Rate anwenden (nur eingehende Transaktionen = Rückzahlungen)
        if ($scheduleId) {
            $tx = Database::fetchOne("SELECT amount, direction FROM bank_transactions WHERE id = ?", [$txId]);
            if ($tx['direction'] !== 'ausgehend') {
                self::applyPaymentToSchedule($scheduleId, $tx['amount'], $loanId);
            }
        }
    }

    /**
     * Markiert eine Transaktion als mehrdeutig
     */
    private static function markAmbiguous(int $txId, array $candidates): void {
        Database::update('bank_transactions', ['match_status' => 'AMBIGUOUS'], 'id = ?', [$txId]);

        foreach ($candidates as $c) {
            Database::insert('transaction_matches', [
                'transaction_id' => $txId,
                'loan_id' => $c['loan_id'],
                'schedule_item_id' => $c['schedule_id'],
                'confidence' => $c['confidence'],
                'match_reason' => $c['reason'] ?? null
            ]);
        }
    }

    /**
     * Wendet eine Zahlung auf eine Rate an.
     * Überschüsse werden automatisch auf die nächsten offenen Raten verteilt.
     */
    public static function applyPaymentToSchedule(int $scheduleId, float $amount, int $loanId): void {
        $item = Database::fetchOne("SELECT * FROM loan_schedule_items WHERE id = ?", [$scheduleId]);
        if (!$item) return;

        $remaining = $amount;

        // Erste Rate bedienen
        $canApply = min($remaining, $item['amount_outstanding']);
        $newPaid = $item['amount_paid'] + $canApply;
        $newOutstanding = max(0, $item['amount_due'] - $newPaid);
        $newStatus = $newOutstanding < 1 ? 'PAID' : ($newPaid > 0 ? 'PARTIAL' : $item['status']);

        Database::update('loan_schedule_items', [
            'amount_paid' => $newPaid,
            'amount_outstanding' => $newOutstanding,
            'status' => $newStatus,
            'paid_at' => $newStatus === 'PAID' ? date('Y-m-d H:i:s') : null
        ], 'id = ?', [$scheduleId]);

        $remaining -= $canApply;

        // Überschuss auf folgende offene Raten verteilen
        if ($remaining >= 1) {
            $nextItems = Database::fetchAll("
                SELECT * FROM loan_schedule_items
                WHERE loan_id = ? AND id != ? AND status IN ('PENDING', 'PARTIAL', 'OVERDUE')
                AND amount_outstanding > 0
                ORDER BY installment_number ASC
            ", [$loanId, $scheduleId]);

            foreach ($nextItems as $next) {
                if ($remaining < 1) break;

                $canApply = min($remaining, $next['amount_outstanding']);
                $nPaid = $next['amount_paid'] + $canApply;
                $nOutstanding = max(0, $next['amount_due'] - $nPaid);
                $nStatus = $nOutstanding < 1 ? 'PAID' : ($nPaid > 0 ? 'PARTIAL' : $next['status']);

                Database::update('loan_schedule_items', [
                    'amount_paid' => $nPaid,
                    'amount_outstanding' => $nOutstanding,
                    'status' => $nStatus,
                    'paid_at' => $nStatus === 'PAID' ? date('Y-m-d H:i:s') : null
                ], 'id = ?', [$next['id']]);

                $remaining -= $canApply;
            }
        }

        self::updateLoanBalance($loanId);
    }

    /**
     * Aktualisiert die Restschuld eines Kredits
     */
    public static function updateLoanBalance(int $loanId): void {
        $totalOutstanding = Database::fetchOne(
            "SELECT SUM(amount_outstanding) as total FROM loan_schedule_items WHERE loan_id = ?",
            [$loanId]
        )['total'] ?? 0;

        Database::update('loans', ['outstanding_balance' => $totalOutstanding], 'id = ?', [$loanId]);

        if ($totalOutstanding < 1) {
            Database::update('loans', ['status' => 'CLOSED'], 'id = ?', [$loanId]);
            AuditLog::log('CLOSE', 'loan', $loanId, null, ['reason' => 'Vollständig bezahlt']);
        }
    }

    /**
     * Verrechnet alle Zahlungen eines Kredits neu (Reset + Neuberechnung)
     */
    public static function recalculatePayments(int $loanId): array {
        // Alle Raten zurücksetzen
        Database::query("
            UPDATE loan_schedule_items
            SET amount_paid = 0, amount_outstanding = amount_due, status = 'PENDING', paid_at = NULL
            WHERE loan_id = ?
        ", [$loanId]);

        // Alle zugeordneten eingehenden Zahlungen chronologisch holen (keine Auszahlungen)
        $payments = Database::fetchAll("
            SELECT id, amount, transaction_date
            FROM bank_transactions
            WHERE matched_loan_id = ? AND match_status = 'MATCHED' AND direction = 'eingehend'
            ORDER BY transaction_date ASC, id ASC
        ", [$loanId]);

        $totalApplied = 0;

        foreach ($payments as $payment) {
            // Nächste offene Rate finden
            $nextItem = Database::fetchOne("
                SELECT * FROM loan_schedule_items
                WHERE loan_id = ? AND amount_outstanding > 0
                ORDER BY installment_number ASC LIMIT 1
            ", [$loanId]);

            if (!$nextItem) break;

            // Zahlung anwenden (mit Überlauf auf Folgeraten)
            self::applyPaymentToSchedule($nextItem['id'], $payment['amount'], $loanId);
            $totalApplied += $payment['amount'];
        }

        self::updateLoanBalance($loanId);

        // Überfällige Raten markieren
        Database::query("
            UPDATE loan_schedule_items
            SET status = 'OVERDUE'
            WHERE loan_id = ? AND status = 'PENDING' AND due_date < CURDATE() AND amount_outstanding > 0
        ", [$loanId]);

        $loan = Database::fetchOne("SELECT total_amount, outstanding_balance FROM loans WHERE id = ?", [$loanId]);

        return [
            'payments_count' => count($payments),
            'total_applied' => $totalApplied,
            'total_amount' => $loan['total_amount'],
            'outstanding' => $loan['outstanding_balance']
        ];
    }
}
