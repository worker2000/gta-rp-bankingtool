<?php
/**
 * PSB Kreditverwaltung - Mahnwesen Klasse
 */

class Dunning {

    /**
     * Aktuelle Bank-ID aus Session
     */
    private static function bankId(): int {
        return (int)($_SESSION['bank_id'] ?? 1);
    }

    /**
     * Berechnet Verzugstage und -zinsen für alle aktiven Kredite der aktuellen Bank
     */
    public static function calculateOverdue(): array {
        $stats = ['updated' => 0, 'l1_triggered' => 0, 'l2_triggered' => 0, 'terminated' => 0];

        // Policies laden
        $dunningL1Days   = intval(getPolicy('DUNNING_L1_DAYS', 7));
        $dunningL2Days   = intval(getPolicy('DUNNING_L2_DAYS', 14));
        $terminationDays = intval(getPolicy('TERMINATION_DAYS', 21));
        $lateWeeklyRate  = floatval(getPolicy('DEFAULT_LATE_WEEKLY_RATE', 0.10));
        $bankId          = self::bankId();

        // Alle aktiven Kredite der aktuellen Bank mit offenen Raten
        $loans = Database::fetchAll("
            SELECT l.id, l.status, l.weekly_rate,
                   MIN(lsi.due_date) as earliest_due,
                   MAX(DATEDIFF(CURDATE(), lsi.due_date)) as max_days_overdue,
                   SUM(lsi.amount_outstanding) as total_outstanding
            FROM loans l
            JOIN loan_schedule_items lsi ON l.id = lsi.loan_id
            WHERE l.status IN ('ACTIVE', 'DUNNING_L1', 'DUNNING_L2')
              AND l.dunning_hold = 0
              AND l.product_type != 'INSURANCE'
              AND l.bank_id = ?
              AND lsi.status IN ('PENDING', 'PARTIAL', 'OVERDUE')
              AND lsi.due_date < CURDATE()
            GROUP BY l.id
        ", [$bankId]);

        foreach ($loans as $loan) {
            $daysOverdue = max(0, $loan['max_days_overdue']);
            $oldStatus = $loan['status'];
            $newStatus = $oldStatus;

            // Status-Übergänge
            if ($daysOverdue >= $terminationDays && $oldStatus !== 'TERMINATED') {
                $newStatus = 'TERMINATED';
                $stats['terminated']++;
            } elseif ($daysOverdue >= $dunningL2Days && !in_array($oldStatus, ['DUNNING_L2', 'TERMINATED'])) {
                $newStatus = 'DUNNING_L2';
                $stats['l2_triggered']++;
            } elseif ($daysOverdue >= $dunningL1Days && !in_array($oldStatus, ['DUNNING_L1', 'DUNNING_L2', 'TERMINATED'])) {
                $newStatus = 'DUNNING_L1';
                $stats['l1_triggered']++;
            }

            // Verzugszins berechnen (wöchentlich auf offene Raten)
            $weeksOverdue = floor($daysOverdue / 7);
            $lateFees = $loan['total_outstanding'] * $lateWeeklyRate * $weeksOverdue;

            // Kredit aktualisieren
            Database::update('loans', [
                'days_overdue' => $daysOverdue,
                'late_fees_accrued' => $lateFees,
                'status' => $newStatus
            ], 'id = ?', [$loan['id']]);

            if ($oldStatus !== $newStatus) {
                AuditLog::log('DUNNING_STATUS_CHANGE', 'loan', $loan['id'],
                    ['status' => $oldStatus, 'days_overdue' => $daysOverdue],
                    ['status' => $newStatus, 'days_overdue' => $daysOverdue]
                );
            }

            // Einzelne Raten aktualisieren
            Database::query("
                UPDATE loan_schedule_items
                SET status = 'OVERDUE',
                    days_overdue = DATEDIFF(CURDATE(), due_date),
                    late_interest = amount_outstanding * ? * FLOOR(DATEDIFF(CURDATE(), due_date) / 7)
                WHERE loan_id = ?
                AND due_date < CURDATE()
                AND status IN ('PENDING', 'PARTIAL')
            ", [$lateWeeklyRate, $loan['id']]);

            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Holt alle Kredite in Mahnstufe – bank-gefiltert
     */
    public static function getDunningLoans(): array {
        return Database::fetchAll("
            SELECT l.*, b.first_name, b.last_name, b.customer_number, b.phone, b.email,
                   (SELECT COUNT(*) FROM loan_schedule_items WHERE loan_id = l.id AND status = 'OVERDUE') as overdue_count,
                   (SELECT SUM(amount_outstanding) FROM loan_schedule_items WHERE loan_id = l.id AND status IN ('PENDING', 'PARTIAL', 'OVERDUE')) as open_amount
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            WHERE l.status IN ('DUNNING_L1', 'DUNNING_L2', 'TERMINATED')
              AND l.dunning_hold = 0
              AND l.product_type != 'INSURANCE'
              AND l.bank_id = ?
            ORDER BY l.days_overdue DESC, l.status
        ", [self::bankId()]);
    }

    /**
     * Berechnet Verzug für alle aktiven Krankenversicherungsverträge
     */
    public static function calculateInsuranceOverdue(): array {
        $stats = ['updated' => 0, 'l1_triggered' => 0, 'l2_triggered' => 0, 'suspended' => 0];
        $bankId = self::bankId();

        // Überfällige Schedule-Items aktualisieren
        Database::query("
            UPDATE insurance_schedule_items isi
            JOIN insurance_contracts ic ON isi.insurance_contract_id = ic.id
            SET isi.status = 'OVERDUE',
                isi.days_overdue = DATEDIFF(CURDATE(), isi.due_date)
            WHERE isi.due_date < CURDATE()
              AND isi.status IN ('PENDING', 'PARTIAL')
              AND ic.bank_id = ?
              AND ic.dunning_hold = 0
        ", [$bankId]);

        // Verträge mit überfälligen Raten laden
        $contracts = Database::fetchAll("
            SELECT ic.id, ic.status, ic.dunning_level,
                   MAX(isi.days_overdue) as max_days_overdue,
                   SUM(CASE WHEN isi.status='OVERDUE' THEN isi.amount ELSE 0 END) as total_overdue
            FROM insurance_contracts ic
            JOIN insurance_schedule_items isi ON ic.id = isi.insurance_contract_id
            WHERE ic.status IN ('ACTIVE', 'SUSPENDED')
              AND ic.dunning_hold = 0
              AND ic.bank_id = ?
              AND isi.status = 'OVERDUE'
            GROUP BY ic.id
        ", [$bankId]);

        foreach ($contracts as $c) {
            $days       = (int)$c['max_days_overdue'];
            $oldLevel   = (int)$c['dunning_level'];
            $newLevel   = $oldLevel;
            $newStatus  = $c['status'];

            if ($days >= 90) {
                $newLevel  = 3;
                $newStatus = 'SUSPENDED';
                if ($c['status'] !== 'SUSPENDED') $stats['suspended']++;
            } elseif ($days >= 60 && $oldLevel < 2) {
                $newLevel = 2;
                $stats['l2_triggered']++;
            } elseif ($days >= 30 && $oldLevel < 1) {
                $newLevel = 1;
                $stats['l1_triggered']++;
            }

            Database::update('insurance_contracts', [
                'days_overdue'  => $days,
                'dunning_level' => $newLevel,
                'status'        => $newStatus,
            ], 'id = ?', [$c['id']]);

            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Holt alle Versicherungsverträge mit Mahnstufe
     */
    public static function getInsuranceDunning(): array {
        return Database::fetchAll("
            SELECT ic.*,
                   b.first_name, b.last_name, b.customer_number, b.phone, b.email,
                   ip.name as product_name,
                   (SELECT COUNT(*) FROM insurance_schedule_items WHERE insurance_contract_id=ic.id AND status='OVERDUE') as overdue_count,
                   (SELECT SUM(amount) FROM insurance_schedule_items WHERE insurance_contract_id=ic.id AND status='OVERDUE') as open_amount
            FROM insurance_contracts ic
            LEFT JOIN borrowers b ON ic.borrower_id = b.id
            LEFT JOIN insurance_products ip ON ic.product_id = ip.id
            WHERE ic.dunning_level > 0
              AND ic.dunning_hold = 0
              AND ic.bank_id = ?
            ORDER BY ic.days_overdue DESC, ic.dunning_level DESC
        ", [self::bankId()]);
    }

    /**
     * Generiert ein Mahnschreiben
     */
    public static function generateLetter(int $loanId, int $templateId, float $additionalCosts = 0, string $offerValidUntil = '', array $extra = []): array {
        $loan = Database::fetchOne("
            SELECT l.*, b.salutation, b.first_name, b.last_name, b.customer_number, b.address_street,
                   b.address_zip, b.address_city, b.date_of_birth, b.phone, b.email,
                   (SELECT due_date FROM loan_schedule_items WHERE loan_id = l.id ORDER BY due_date ASC LIMIT 1) as first_due_date
            FROM loans l
            JOIN borrowers b ON l.borrower_id = b.id
            WHERE l.id = ?
        ", [$loanId]);

        if (!$loan) {
            throw new Exception('Kredit nicht gefunden.');
        }

        $template = Database::fetchOne("
            SELECT * FROM templates WHERE id = ? AND bank_id = ? AND is_active = 1
        ", [$templateId, self::bankId()]);

        if (!$template) {
            throw new Exception('Vorlage nicht gefunden.');
        }

        // Offene Raten berechnen
        $overdueInfo = Database::fetchOne("
            SELECT SUM(amount_outstanding) as total_overdue,
                   MIN(due_date) as first_due_date,
                   MAX(DATEDIFF(CURDATE(), due_date)) as max_days
            FROM loan_schedule_items
            WHERE loan_id = ? AND status IN ('PENDING', 'PARTIAL', 'OVERDUE') AND due_date < CURDATE()
        ", [$loanId]);

        // Anrede aufbauen
        $salutation = $loan['salutation'] ?? '';
        $anredeVoll = match($salutation) {
            'Mr.' => 'Mr. ' . $loan['last_name'],
            'Ms.' => 'Ms. ' . $loan['last_name'],
            default => $loan['first_name'] . ' ' . $loan['last_name']
        };
        $anredePrefix = match($salutation) {
            'Mr.' => 'Mr.',
            'Ms.' => 'Ms.',
            default => ''
        };

        // Gesamtforderung inkl. Verzugszins und weiterer Kosten
        $totalClaim = ($loan['outstanding_balance'] ?? 0) + ($loan['late_fees_accrued'] ?? 0) + $additionalCosts;

        // Zinssatz als Prozentwert (z.B. 0.1000 → 10.00)
        $zinssatzProzent = number_format(($loan['interest_rate'] ?? 0) * 100, 2, '.', '');
        // Individueller Ratenprozentsatz: Wochenrate / Kreditsumme × 100
        $ratenprozent = ($loan['loan_amount'] ?? 0) > 0
            ? number_format(($loan['weekly_rate'] / $loan['loan_amount']) * 100, 2, '.', '')
            : '0.00';
        // Angebotsgültig-Datum formatieren
        $angebotGueltig = $offerValidUntil ? date('d.m.Y', strtotime($offerValidUntil)) : '';
        // Geburtsdatum: DB-Wert bevorzugen, sonst Eingabefeld
        $dobRaw = $loan['date_of_birth'] ?: ($extra['private_dob'] ?? '');
        $geburtsdatum = $dobRaw ? date('d.m.Y', strtotime($dobRaw)) : '-';
        // Kontakt: Telefon + E-Mail kombinieren
        $kontakt = implode(' / ', array_filter([$loan['phone'] ?? '', $loan['email'] ?? ''])) ?: '-';

        // Platzhalter
        $placeholders = [
            '{NAME}' => $loan['first_name'] . ' ' . $loan['last_name'],
            '{VORNAME}' => $loan['first_name'],
            '{NACHNAME}' => $loan['last_name'],
            '{ANREDE}' => $anredeVoll,
            '{ANREDE_PREFIX}' => $anredePrefix,
            '{AKTENZEICHEN}' => $loan['file_number'],
            '{KUNDENNUMMER}' => $loan['customer_number'],
            '{ADRESSE}' => trim(($loan['address_street'] ?? '') . ', ' . ($loan['address_zip'] ?? '') . ' ' . ($loan['address_city'] ?? ''), ', '),
            '{OFFENE_RATE}' => formatMoney($overdueInfo['total_overdue'] ?? 0),
            '{RESTSCHULD}' => formatMoney($loan['outstanding_balance'] ?? 0),
            '{VERZUGSTAGE}' => $overdueInfo['max_days'] ?? 0,
            '{VERZUGSZINS}' => formatMoney($loan['late_fees_accrued'] ?? 0),
            '{WEITERE_KOSTEN}' => formatMoney($additionalCosts),
            '{GESAMTBETRAG}' => formatMoney(($overdueInfo['total_overdue'] ?? 0) + ($loan['late_fees_accrued'] ?? 0)),
            '{GESAMTFORDERUNG}' => formatMoney($totalClaim),
            '{FÄLLIGKEITSDATUM}' => formatDate($overdueInfo['first_due_date']),
            '{FRISTDATUM}' => date('d.m.Y', strtotime('+7 days')),
            '{KONTO}' => $loan['payment_account'] ?? 'PS2B61225563',
            '{VERWENDUNGSZWECK}' => $loan['payment_reference'],
            '{WOCHENRATE}' => formatMoney($loan['weekly_rate']),
            '{VERTRAGSDATUM}' => formatDate($loan['start_date']),
            '{FAHRZEUGMODELL}' => $loan['vehicle_model'] ?? 'k.A.',
            '{NUMMERNSCHILD}' => $loan['vehicle_plate'] ?? 'k.A.',
            '{DATUM}' => date('d.m.Y'),
            // Kreditangebot-spezifische Platzhalter
            '{ANFRAGEDATUM}' => date('d.m.Y', strtotime($loan['created_at'])),
            '{KAUFPREIS}' => number_format($loan['purchase_price'] ?? 0, 2, '.', ','),
            '{EIGENKAPITAL}' => number_format($loan['down_payment'] ?? 0, 2, '.', ','),
            '{FINANZIERUNGSBETRAG}' => number_format($loan['loan_amount'] ?? 0, 2, '.', ','),
            '{ZINSSATZ}' => $zinssatzProzent,
            '{RATENPROZ}' => $ratenprozent,
            '{ZINSBETRAG}' => number_format($loan['total_interest'] ?? 0, 2, '.', ','),
            '{GESAMTKREDITSUMME}' => number_format($loan['total_amount'] ?? 0, 2, '.', ','),
            '{LAUFZEIT}' => $loan['term_weeks'] ?? '',
            '{ERSTE_RATE}' => $loan['first_due_date'] ? date('d.m.Y', strtotime($loan['first_due_date'])) : '',
            '{VERTRAGSENDE}' => $loan['end_date'] ? date('d.m.Y', strtotime($loan['end_date'])) : '',
            '{ANGEBOTSGUELTIG}' => $angebotGueltig,
            // Unternehmenskredit-Platzhalter
            '{BANKVERTRETER}'        => $extra['bank_representative'] ?? '',
            '{UNTERNEHMENSNAME}'     => $loan['first_name'],
            '{FIRMENID}'             => $loan['customer_number'],
            '{CEO_INHABER}'          => $loan['last_name'],
            '{NAME_PRIVATSCHULDNER}' => $loan['last_name'],
            '{GEBURTSDATUM}'         => $geburtsdatum,
            '{KONTAKT}'              => $kontakt,
            '{INVESTITIONSZWECK1}'   => $extra['investment_1'] ?? '',
            '{INVESTITIONSZWECK2}'   => $extra['investment_2'] ?? '',
            '{INVESTITIONSZWECK3}'   => $extra['investment_3'] ?? '',
            '{KREDITBETRAG}'         => number_format($loan['loan_amount'] ?? 0, 2, '.', ','),
            '{GESAMTSUMME}'          => number_format($loan['total_amount'] ?? 0, 2, '.', ','),
            // Auskunftsbogen-spezifische Platzhalter
            '{BETRAG}'               => number_format($loan['loan_amount'] ?? 0, 2, '.', ','),
            '{TELEFON}'              => $loan['phone'] ?? '',
            '{EMAIL}'                => $loan['email'] ?? '',
            '{INVESTITION_1}'        => $extra['investment_1'] ?? '',
            '{INVESTITION_2}'        => $extra['investment_2'] ?? '',
            '{INVESTITION_3}'        => $extra['investment_3'] ?? '',
        ];

        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template['subject']);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $template['body']);

        return [
            'template_id' => $template['id'],
            'template_version' => $template['version'],
            'type' => $template['type'],
            'subject' => $subject,
            'body' => $body,
            'loan' => $loan,
            'overdue_info' => $overdueInfo
        ];
    }

    /**
     * Speichert ein generiertes Schreiben (communications + documents)
     */
    public static function saveCommunication(int $loanId, int $templateId, string $type, string $subject, string $body): int {
        $bankId = self::bankId();
        $userId = Auth::userId();

        $id = Database::insert('communications', [
            'bank_id'     => $bankId,
            'loan_id'     => $loanId,
            'template_id' => $templateId,
            'type'        => $type,
            'subject'     => $subject,
            'body'        => $body,
            'sent_via'    => 'COPY_PASTE',
            'created_by'  => $userId
        ]);

        // Borrower-ID für Dokument-Verlinkung holen
        $loan = Database::fetchOne("SELECT borrower_id FROM loans WHERE id = ?", [$loanId]);

        // Parallel in documents speichern (sichtbar in Schreiben-Übersicht)
        Database::insert('documents', [
            'bank_id'     => $bankId,
            'doc_type'    => 'TEMPLATE_BASED',
            'title'       => $subject,
            'loan_id'     => $loanId,
            'borrower_id' => $loan['borrower_id'] ?? null,
            'type'        => 'CORRESPONDENCE',
            'content'     => $body,
            'template_id' => $templateId ?: null,
            'uploaded_by' => $userId,
        ]);

        AuditLog::log('CREATE_COMMUNICATION', 'communication', $id, null, [
            'loan_id' => $loanId,
            'type' => $type
        ]);

        return $id;
    }
}
