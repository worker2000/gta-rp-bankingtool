-- ============================================================
-- Migration: $250 Bearbeitungsgebühren korrekt klassifizieren
-- Datum: 2026-03-10
-- Beschreibung: Bei Fortis Finance ist jede Zahlung von exakt
--   $250 eine Vertragsbearbeitungsgebühr, keine Kreditzahlung.
--   Diese Migration:
--   1. Setzt match_status = 'FEE' in bank_transactions
--   2. Setzt matched_schedule_id = NULL (kein Ratenplan-Eintrag)
--   3. Löscht die fälschlich gebuchten $250 aus loan_schedule_items
--   4. Setzt Status der betroffenen Raten auf OVERDUE/PENDING
-- ============================================================

START TRANSACTION;

-- ============================================================
-- SCHRITT 1: bank_transactions – $250 als FEE markieren
-- ============================================================
UPDATE bank_transactions bt
JOIN loans l ON bt.matched_loan_id = l.id
SET
    bt.match_status        = 'FEE',
    bt.matched_schedule_id = NULL
WHERE l.bank_id   = 2
  AND bt.amount   = 250.00
  AND bt.direction = 'eingehend'
  AND bt.match_status = 'MATCHED';

-- Ergebnis prüfen (sollte 38 Zeilen betreffen)
SELECT ROW_COUNT() AS bank_transactions_updated;

-- ============================================================
-- SCHRITT 2: loan_schedule_items – $250 zurücksetzen
-- ============================================================
UPDATE loan_schedule_items si
JOIN loans l ON si.loan_id = l.id
SET
    si.amount_paid        = 0.00,
    si.amount_outstanding = si.amount_due,
    si.paid_at            = NULL,
    si.status = CASE
        WHEN si.due_date < CURDATE() THEN 'OVERDUE'
        ELSE 'PENDING'
    END
WHERE l.bank_id        = 2
  AND si.amount_paid   = 250.00;

-- Ergebnis prüfen (sollte 14 Zeilen betreffen)
SELECT ROW_COUNT() AS schedule_items_updated;

-- ============================================================
-- SCHRITT 3: outstanding_balance in loans-Tabelle aktualisieren
-- (Wird normalerweise dynamisch berechnet, aber zur Sicherheit)
-- ============================================================
UPDATE loans l
SET l.outstanding_balance = (
    SELECT COALESCE(l.total_amount - SUM(bt2.amount), l.total_amount)
    FROM bank_transactions bt2
    WHERE bt2.matched_loan_id = l.id
      AND bt2.direction = 'eingehend'
      AND bt2.match_status = 'MATCHED'
)
WHERE l.bank_id = 2;

-- ============================================================
-- SCHRITT 4: Kontrolle – betroffene Verträge anzeigen
-- ============================================================
SELECT
    l.file_number,
    l.total_amount,
    l.outstanding_balance,
    COALESCE(SUM(si.amount_paid), 0) AS schedule_paid,
    COUNT(CASE WHEN si.status = 'OVERDUE' THEN 1 END) AS overdue_installments
FROM loans l
LEFT JOIN loan_schedule_items si ON si.loan_id = l.id
WHERE l.bank_id = 2
  AND l.id IN (
    SELECT DISTINCT matched_loan_id
    FROM bank_transactions
    WHERE match_status = 'FEE' AND amount = 250
  )
GROUP BY l.id
ORDER BY l.file_number;

COMMIT;
