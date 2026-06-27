-- ================================================================
-- Migration: Fortis Finance – Echte Vertragsdaten einfügen
-- Datum: 2026-03-03
-- Zweck: Bestehende Import-Platzhalter mit echten Namen/Beträgen
--        aktualisieren, Tippfehler beheben, 3 neue Kredite anlegen
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;
START TRANSACTION;

-- ================================================================
-- SCHRITT 1: Tippfehler beheben
-- 010202026LdM01 → 01022026LdM01 (Nate Caven King)
-- ================================================================

UPDATE loans
SET payment_reference = '01022026LdM01'
WHERE payment_reference = '010202026LdM01' AND bank_id = 2;

-- ================================================================
-- SCHRITT 2: Kreditnehmer-Namen + Kreditdaten aktualisieren
-- (für alle 33 per payment_reference gematchten Verträge)
-- ================================================================

-- ---- 20112025LdM02 – Willy Watermore (Kredit / Abgeschlossen) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Willy', b.last_name = 'Watermore'
WHERE l.payment_reference = '20112025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'PRIVATE', l.status = 'CLOSED',
    l.loan_amount = 10000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 10000.00, l.down_payment = 0.00,
    l.weekly_rate = 1000.00, l.custom_final_rate = 344.00,
    l.term_weeks = 11, l.interest_rate = 0.0060,
    l.total_interest = 344.00, l.total_amount = 10344.00,
    l.start_date = '2025-11-20',
    l.end_date = DATE_ADD('2025-11-20', INTERVAL 11 WEEK)
WHERE l.payment_reference = '20112025LdM02' AND l.bank_id = 2;

-- ---- 22112025LdM01 – Poppy Parker (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Poppy', b.last_name = 'Parker'
WHERE l.payment_reference = '22112025LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 17400.00, l.outstanding_balance = 17400.00,
    l.purchase_price = 17400.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 1470.00,
    l.term_weeks = 12, l.interest_rate = 0.0050,
    l.total_interest = 570.00, l.total_amount = 17970.00,
    l.start_date = '2025-11-22',
    l.end_date = DATE_ADD('2025-11-22', INTERVAL 12 WEEK)
WHERE l.payment_reference = '22112025LdM01' AND l.bank_id = 2;

-- ---- 22112025LdM02 – Halvar Ragnarsson (Fahrzeugkredit / Gekündigt) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Halvar', b.last_name = 'Ragnarsson'
WHERE l.payment_reference = '22112025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'TERMINATED',
    l.loan_amount = 20000.00, l.outstanding_balance = 18000.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 1250.00,
    l.term_weeks = 14, l.interest_rate = 0.0050,
    l.total_interest = 750.00, l.total_amount = 20750.00,
    l.start_date = '2025-11-22',
    l.end_date = DATE_ADD('2025-11-22', INTERVAL 14 WEEK)
WHERE l.payment_reference = '22112025LdM02' AND l.bank_id = 2;

-- ---- 27112025JR01 – Lucas de Marino (Fahrzeugkredit / Abbezahlt) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Lucas', b.last_name = 'de Marino'
WHERE l.payment_reference = '27112025JR01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 30000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 30000.00, l.down_payment = 0.00,
    l.weekly_rate = 3000.00, l.custom_final_rate = 1582.00,
    l.term_weeks = 11, l.interest_rate = 0.0090,
    l.total_interest = 1582.00, l.total_amount = 31582.00,
    l.start_date = '2025-11-27',
    l.end_date = DATE_ADD('2025-11-27', INTERVAL 11 WEEK)
WHERE l.payment_reference = '27112025JR01' AND l.bank_id = 2;

-- ---- 27112025JR02 – Tariq Kings (Kredit / Abbezahlt) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Tariq', b.last_name = 'Kings'
WHERE l.payment_reference = '27112025JR02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'PRIVATE', l.status = 'CLOSED',
    l.loan_amount = 15000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 15000.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 427.00,
    l.term_weeks = 11, l.interest_rate = 0.0050,
    l.total_interest = 427.00, l.total_amount = 15427.00,
    l.start_date = '2025-11-27',
    l.end_date = DATE_ADD('2025-11-27', INTERVAL 11 WEEK)
WHERE l.payment_reference = '27112025JR02' AND l.bank_id = 2;

-- ---- 27112025LdM02 – Lacy King (Fahrzeugkredit / Abgeschlossen) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Lacy', b.last_name = 'King'
WHERE l.payment_reference = '27112025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 50000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 50000.00, l.down_payment = 0.00,
    l.weekly_rate = 2800.00, l.custom_final_rate = 1556.00,
    l.term_weeks = 20, l.interest_rate = 0.0090,
    l.total_interest = 4756.00, l.total_amount = 54756.00,
    l.start_date = '2025-11-28',
    l.end_date = DATE_ADD('2025-11-28', INTERVAL 20 WEEK)
WHERE l.payment_reference = '27112025LdM02' AND l.bank_id = 2;

-- ---- 02122025LdM01 – Dean Ryan (Fahrzeugkredit / Abbezahlt, 0% Zins) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Dean', b.last_name = 'Ryan'
WHERE l.payment_reference = '02122025LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 12000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 12000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = NULL,
    l.term_weeks = 6, l.interest_rate = 0.0000,
    l.total_interest = 0.00, l.total_amount = 12000.00,
    l.start_date = '2025-12-02',
    l.end_date = DATE_ADD('2025-12-02', INTERVAL 6 WEEK)
WHERE l.payment_reference = '02122025LdM01' AND l.bank_id = 2;

-- ---- 4122025EW01 – Julio Ramírez-González (Kredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Julio', b.last_name = 'Ramírez-González'
WHERE l.payment_reference = '4122025EW01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'PRIVATE', l.status = 'ACTIVE',
    l.loan_amount = 25000.00, l.outstanding_balance = 25000.00,
    l.purchase_price = 25000.00, l.down_payment = 0.00,
    l.weekly_rate = 1800.00, l.custom_final_rate = 776.00,
    l.term_weeks = 15, l.interest_rate = 0.0050,
    l.total_interest = 976.00, l.total_amount = 25976.00,
    l.start_date = '2025-12-04',
    l.end_date = DATE_ADD('2025-12-04', INTERVAL 15 WEEK)
WHERE l.payment_reference = '4122025EW01' AND l.bank_id = 2;

-- ---- 06122025JR01 – Christoph Miller (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Christoph', b.last_name = 'Miller'
WHERE l.payment_reference = '06122025JR01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 20000.00, l.outstanding_balance = 10000.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = 570.00,
    l.term_weeks = 11, l.interest_rate = 0.0050,
    l.total_interest = 570.00, l.total_amount = 20570.00,
    l.start_date = '2025-12-06',
    l.end_date = DATE_ADD('2025-12-06', INTERVAL 11 WEEK)
WHERE l.payment_reference = '06122025JR01' AND l.bank_id = 2;

-- ---- 09122025LdM02 – Kathleen Wolf (Fahrzeugkredit / Abbezahlt) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Kathleen', b.last_name = 'Wolf'
WHERE l.payment_reference = '09122025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 20000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = 570.00,
    l.term_weeks = 11, l.interest_rate = 0.0050,
    l.total_interest = 570.00, l.total_amount = 20570.00,
    l.start_date = '2025-12-14',
    l.end_date = DATE_ADD('2025-12-14', INTERVAL 11 WEEK)
WHERE l.payment_reference = '09122025LdM02' AND l.bank_id = 2;

-- ---- 13122025LdM02 – Ayane Tanaka (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Ayane', b.last_name = 'Tanaka'
WHERE l.payment_reference = '13122025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 12500.00, l.outstanding_balance = 6000.00,
    l.purchase_price = 12500.00, l.down_payment = 0.00,
    l.weekly_rate = 1300.00, l.custom_final_rate = 209.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 709.00, l.total_amount = 13209.00,
    l.start_date = '2025-12-17',
    l.end_date = DATE_ADD('2025-12-17', INTERVAL 11 WEEK)
WHERE l.payment_reference = '13122025LdM02' AND l.bank_id = 2;

-- ---- 13122025LdM03 – Haru Kazuma (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Haru', b.last_name = 'Kazuma'
WHERE l.payment_reference = '13122025LdM03' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 12500.00, l.outstanding_balance = 6000.00,
    l.purchase_price = 12500.00, l.down_payment = 0.00,
    l.weekly_rate = 1300.00, l.custom_final_rate = 209.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 709.00, l.total_amount = 13209.00,
    l.start_date = '2025-12-17',
    l.end_date = DATE_ADD('2025-12-17', INTERVAL 11 WEEK)
WHERE l.payment_reference = '13122025LdM03' AND l.bank_id = 2;

-- ---- 13122025LdM04 – Kenji Kurohane (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Kenji', b.last_name = 'Kurohane'
WHERE l.payment_reference = '13122025LdM04' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 12500.00, l.outstanding_balance = 8600.00,
    l.purchase_price = 12500.00, l.down_payment = 0.00,
    l.weekly_rate = 1300.00, l.custom_final_rate = 209.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 709.00, l.total_amount = 13209.00,
    l.start_date = '2025-12-17',
    l.end_date = DATE_ADD('2025-12-17', INTERVAL 11 WEEK)
WHERE l.payment_reference = '13122025LdM04' AND l.bank_id = 2;

-- ---- 17122025LdM01 – Dave Sander (Fahrzeugkredit / Abgeschlossen) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Dave', b.last_name = 'Sander'
WHERE l.payment_reference = '17122025LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 17000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 17000.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 1471.00,
    l.term_weeks = 13, l.interest_rate = 0.0200,
    l.total_interest = 2471.00, l.total_amount = 19471.00,
    l.start_date = '2025-12-17',
    l.end_date = DATE_ADD('2025-12-17', INTERVAL 13 WEEK)
WHERE l.payment_reference = '17122025LdM01' AND l.bank_id = 2;

-- ---- 17122025LdM02 – Luigi Ferroni (Fahrzeugkredit / Abgeschlossen) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Luigi', b.last_name = 'Ferroni'
WHERE l.payment_reference = '17122025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 8300.00, l.outstanding_balance = 0.00,
    l.purchase_price = 8300.00, l.down_payment = 0.00,
    l.weekly_rate = 1250.00, l.custom_final_rate = 1132.00,
    l.term_weeks = 7, l.interest_rate = 0.0100,
    l.total_interest = 332.00, l.total_amount = 8632.00,
    l.start_date = '2025-12-22',
    l.end_date = DATE_ADD('2025-12-22', INTERVAL 7 WEEK)
WHERE l.payment_reference = '17122025LdM02' AND l.bank_id = 2;

-- ---- 17122025LdM03 – Breana Steward (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Breana', b.last_name = 'Steward'
WHERE l.payment_reference = '17122025LdM03' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 20000.00, l.outstanding_balance = 6000.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = 1180.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 1180.00, l.total_amount = 21180.00,
    l.start_date = '2025-12-20',
    l.end_date = DATE_ADD('2025-12-20', INTERVAL 11 WEEK)
WHERE l.payment_reference = '17122025LdM03' AND l.bank_id = 2;

-- ---- 22122025LdM01 – Taylor Brandon (Fahrzeugkredit / Aktiv, 0% Zins) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Taylor', b.last_name = 'Brandon'
WHERE l.payment_reference = '22122025LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 20000.00, l.outstanding_balance = 12500.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 500.00,
    l.term_weeks = 14, l.interest_rate = 0.0000,
    l.total_interest = 0.00, l.total_amount = 20000.00,
    l.start_date = '2025-12-29',
    l.end_date = DATE_ADD('2025-12-29', INTERVAL 14 WEEK)
WHERE l.payment_reference = '22122025LdM01' AND l.bank_id = 2;

-- ---- 22122025LdM02 – Anthony Ravenmoor (Fahrzeugkredit / Abgeschlossen) ----
-- Tippfehler: 'Antony' → 'Anthony'
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Anthony', b.last_name = 'Ravenmoor'
WHERE l.payment_reference = '22122025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 10000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 10000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = 153.00,
    l.term_weeks = 6, l.interest_rate = 0.0050,
    l.total_interest = 153.00, l.total_amount = 10153.00,
    l.start_date = '2025-12-29',
    l.end_date = DATE_ADD('2025-12-29', INTERVAL 6 WEEK)
WHERE l.payment_reference = '22122025LdM02' AND l.bank_id = 2;

-- ---- 30122025LdM01 – Lincoln Brown (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Lincoln', b.last_name = 'Brown'
WHERE l.payment_reference = '30122025LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 27000.00, l.outstanding_balance = 17000.00,
    l.purchase_price = 27000.00, l.down_payment = 0.00,
    l.weekly_rate = 2500.00, l.custom_final_rate = 727.00,
    l.term_weeks = 13, l.interest_rate = 0.0200,
    l.total_interest = 3727.00, l.total_amount = 30727.00,
    l.start_date = '2025-12-30',
    l.end_date = DATE_ADD('2025-12-30', INTERVAL 13 WEEK)
WHERE l.payment_reference = '30122025LdM01' AND l.bank_id = 2;

-- ---- 30122025LdM02 – Mateo Rodriguez (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Mateo', b.last_name = 'Rodriguez'
WHERE l.payment_reference = '30122025LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 40000.00, l.outstanding_balance = 30000.00,
    l.purchase_price = 40000.00, l.down_payment = 0.00,
    l.weekly_rate = 2500.00, l.custom_final_rate = 1194.00,
    l.term_weeks = 20, l.interest_rate = 0.0200,
    l.total_interest = 8694.00, l.total_amount = 48694.00,
    l.start_date = '2025-12-30',
    l.end_date = DATE_ADD('2025-12-30', INTERVAL 20 WEEK)
WHERE l.payment_reference = '30122025LdM02' AND l.bank_id = 2;

-- ---- 30122025LdM03 – Jayden Tyrese McGray (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Jayden Tyrese', b.last_name = 'McGray'
WHERE l.payment_reference = '30122025LdM03' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 40000.00, l.outstanding_balance = 37500.00,
    l.purchase_price = 40000.00, l.down_payment = 0.00,
    l.weekly_rate = 2500.00, l.custom_final_rate = 1194.00,
    l.term_weeks = 20, l.interest_rate = 0.0200,
    l.total_interest = 8694.00, l.total_amount = 48694.00,
    l.start_date = '2025-12-30',
    l.end_date = DATE_ADD('2025-12-30', INTERVAL 20 WEEK)
WHERE l.payment_reference = '30122025LdM03' AND l.bank_id = 2;

-- ---- 30122025LdM04 – Max Calaway (Fahrzeugkredit / Abbezahlt, Sondertilgung) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Max', b.last_name = 'Calaway'
WHERE l.payment_reference = '30122025LdM04' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'CLOSED',
    l.loan_amount = 18000.00, l.outstanding_balance = 0.00,
    l.purchase_price = 18000.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = NULL,
    l.term_weeks = 12, l.interest_rate = 0.0100,
    l.total_interest = 0.00, l.total_amount = 18000.00,
    l.start_date = '2025-12-30',
    l.end_date = DATE_ADD('2025-12-30', INTERVAL 12 WEEK)
WHERE l.payment_reference = '30122025LdM04' AND l.bank_id = 2;

-- ---- 03012026LdM01 – Rob Itmanson (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Rob', b.last_name = 'Itmanson'
WHERE l.payment_reference = '03012026LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 55000.00, l.outstanding_balance = 45000.00,
    l.purchase_price = 55000.00, l.down_payment = 0.00,
    l.weekly_rate = 2500.00, l.custom_final_rate = 705.00,
    l.term_weeks = 30, l.interest_rate = 0.0200,
    l.total_interest = 18205.00, l.total_amount = 73205.00,
    l.start_date = '2026-01-05',
    l.end_date = DATE_ADD('2026-01-05', INTERVAL 30 WEEK)
WHERE l.payment_reference = '03012026LdM01' AND l.bank_id = 2;

-- ---- 03012026LdM02 – Darius Bends (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Darius', b.last_name = 'Bends'
WHERE l.payment_reference = '03012026LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 19000.00, l.outstanding_balance = 19000.00,
    l.purchase_price = 19000.00, l.down_payment = 0.00,
    l.weekly_rate = 1200.00, l.custom_final_rate = 389.00,
    l.term_weeks = 18, l.interest_rate = 0.0100,
    l.total_interest = 1789.00, l.total_amount = 20789.00,
    l.start_date = '2026-01-05',
    l.end_date = DATE_ADD('2026-01-05', INTERVAL 18 WEEK)
WHERE l.payment_reference = '03012026LdM02' AND l.bank_id = 2;

-- ---- 03012026LdM03 – Amaya Moreno (Kredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Amaya', b.last_name = 'Moreno'
WHERE l.payment_reference = '03012026LdM03' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'PRIVATE', l.status = 'ACTIVE',
    l.loan_amount = 20000.00, l.outstanding_balance = 12000.00,
    l.purchase_price = 20000.00, l.down_payment = 0.00,
    l.weekly_rate = 2000.00, l.custom_final_rate = 1180.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 1180.00, l.total_amount = 21180.00,
    l.start_date = '2026-01-05',
    l.end_date = DATE_ADD('2026-01-05', INTERVAL 11 WEEK)
WHERE l.payment_reference = '03012026LdM03' AND l.bank_id = 2;

-- ---- 07012026LdM01 – Mike Moor (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Mike', b.last_name = 'Moor'
WHERE l.payment_reference = '07012026LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 17000.00, l.outstanding_balance = 12000.00,
    l.purchase_price = 17000.00, l.down_payment = 0.00,
    l.weekly_rate = 1250.00, l.custom_final_rate = 865.00,
    l.term_weeks = 15, l.interest_rate = 0.0100,
    l.total_interest = 1365.00, l.total_amount = 18365.00,
    l.start_date = '2026-01-07',
    l.end_date = DATE_ADD('2026-01-07', INTERVAL 15 WEEK)
WHERE l.payment_reference = '07012026LdM01' AND l.bank_id = 2;

-- ---- 07012026LdM02 – Michael Reynolds (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Michael', b.last_name = 'Reynolds'
WHERE l.payment_reference = '07012026LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 60000.00, l.outstanding_balance = 60000.00,
    l.purchase_price = 60000.00, l.down_payment = 0.00,
    l.weekly_rate = 4000.00, l.custom_final_rate = 46.00,
    l.term_weeks = 19, l.interest_rate = 0.0200,
    l.total_interest = 12046.00, l.total_amount = 72046.00,
    l.start_date = '2026-01-07',
    l.end_date = DATE_ADD('2026-01-07', INTERVAL 19 WEEK)
WHERE l.payment_reference = '07012026LdM02' AND l.bank_id = 2;

-- ---- 10012026LdM02 – Matteo Suarez (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Matteo', b.last_name = 'Suarez'
WHERE l.payment_reference = '10012026LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 10000.00, l.outstanding_balance = 10000.00,
    l.purchase_price = 10000.00, l.down_payment = 0.00,
    l.weekly_rate = 1000.00, l.custom_final_rate = 590.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 590.00, l.total_amount = 10590.00,
    l.start_date = '2026-01-10',
    l.end_date = DATE_ADD('2026-01-10', INTERVAL 11 WEEK)
WHERE l.payment_reference = '10012026LdM02' AND l.bank_id = 2;

-- ---- 11012026LdM01 – Kailani Hale (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Kailani', b.last_name = 'Hale'
WHERE l.payment_reference = '11012026LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 8700.00, l.outstanding_balance = 6600.00,
    l.purchase_price = 8700.00, l.down_payment = 0.00,
    l.weekly_rate = 1050.00, l.custom_final_rate = 728.00,
    l.term_weeks = 9, l.interest_rate = 0.0100,
    l.total_interest = 428.00, l.total_amount = 9128.00,
    l.start_date = '2026-01-11',
    l.end_date = DATE_ADD('2026-01-11', INTERVAL 9 WEEK)
WHERE l.payment_reference = '11012026LdM01' AND l.bank_id = 2;

-- ---- 28012026LdM01 – Dave Sander (Kredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Dave', b.last_name = 'Sander'
WHERE l.payment_reference = '28012026LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'PRIVATE', l.status = 'ACTIVE',
    l.loan_amount = 40000.00, l.outstanding_balance = 40000.00,
    l.purchase_price = 40000.00, l.down_payment = 0.00,
    l.weekly_rate = 3000.00, l.custom_final_rate = 1994.00,
    l.term_weeks = 16, l.interest_rate = 0.0200,
    l.total_interest = 6994.00, l.total_amount = 46994.00,
    l.start_date = '2026-01-28',
    l.end_date = DATE_ADD('2026-01-28', INTERVAL 16 WEEK)
WHERE l.payment_reference = '28012026LdM01' AND l.bank_id = 2;

-- ---- 28012026LdM02 – Ana Cortes (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Ana', b.last_name = 'Cortes'
WHERE l.payment_reference = '28012026LdM02' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 27500.00, l.outstanding_balance = 27500.00,
    l.purchase_price = 27500.00, l.down_payment = 0.00,
    l.weekly_rate = 4000.00, l.custom_final_rate = 1889.00,
    l.term_weeks = 8, l.interest_rate = 0.0200,
    l.total_interest = 2389.00, l.total_amount = 29889.00,
    l.start_date = '2026-01-28',
    l.end_date = DATE_ADD('2026-01-28', INTERVAL 8 WEEK)
WHERE l.payment_reference = '28012026LdM02' AND l.bank_id = 2;

-- ---- 28012026LdM03 – Maeve O'Leary (Fahrzeugkredit / Aktiv) ----
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Maeve', b.last_name = 'O''Leary'
WHERE l.payment_reference = '28012026LdM03' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 15500.00, l.outstanding_balance = 15500.00,
    l.purchase_price = 15500.00, l.down_payment = 0.00,
    l.weekly_rate = 1500.00, l.custom_final_rate = 1443.00,
    l.term_weeks = 11, l.interest_rate = 0.0100,
    l.total_interest = 943.00, l.total_amount = 16443.00,
    l.start_date = '2026-01-28',
    l.end_date = DATE_ADD('2026-01-28', INTERVAL 11 WEEK)
WHERE l.payment_reference = '28012026LdM03' AND l.bank_id = 2;

-- ---- 01022026LdM01 – Nate Caven King (Fahrzeugkredit / Aktiv) ----
-- (ehem. Tippfehler 010202026LdM01, bereits in Schritt 1 korrigiert)
UPDATE borrowers b JOIN loans l ON b.id = l.borrower_id
SET b.first_name = 'Nate Caven', b.last_name = 'King'
WHERE l.payment_reference = '01022026LdM01' AND l.bank_id = 2;

UPDATE loans l JOIN borrowers b ON l.borrower_id = b.id
SET l.product_type = 'AUTO', l.status = 'ACTIVE',
    l.loan_amount = 30000.00, l.outstanding_balance = 30000.00,
    l.purchase_price = 30000.00, l.down_payment = 0.00,
    l.weekly_rate = 1750.00, l.custom_final_rate = 356.00,
    l.term_weeks = 22, l.interest_rate = 0.0200,
    l.total_interest = 7106.00, l.total_amount = 37106.00,
    l.start_date = '2026-02-01',
    l.end_date = DATE_ADD('2026-02-01', INTERVAL 22 WEEK)
WHERE l.payment_reference = '01022026LdM01' AND l.bank_id = 2;

-- ================================================================
-- SCHRITT 3: Neue Kreditnehmer + Kredite anlegen
-- (3 Verträge ohne bisherigen DB-Eintrag)
-- ================================================================

-- ---- 3a: Daniel Adams – 15112025LdM02 (Fahrzeugkredit / Abgeschlossen) ----
INSERT INTO borrowers
  (bank_id, customer_number, first_name, last_name, is_active, created_by, created_at)
VALUES
  (2, 'FF-2026-00040', 'Daniel', 'Adams', 1, 3, NOW());

INSERT INTO loans
  (bank_id, file_number, borrower_id, product_type, status,
   purchase_price, down_payment, loan_amount, interest_rate,
   total_interest, total_amount, term_weeks, weekly_rate, custom_final_rate,
   start_date, end_date, outstanding_balance, payment_reference, created_by)
VALUES (
  2, 'FF-PK-2026-00040',
  LAST_INSERT_ID(),
  'AUTO', 'CLOSED',
  20100.00, 0.00, 20100.00, 0.0050,
  646.00, 20646.00, 12, 1750.00, 1396.00,
  '2025-11-15', DATE_ADD('2025-11-15', INTERVAL 12 WEEK),
  0.00, '15112025LdM02', 3
);

-- ---- 3b: Butch Montgomery Chesterfield – 20112025LdM01 (Fahrzeugkredit / Abgeschlossen) ----
INSERT INTO borrowers
  (bank_id, customer_number, first_name, last_name, is_active, created_by, created_at)
VALUES
  (2, 'FF-2026-00041', 'Butch Montgomery', 'Chesterfield', 1, 3, NOW());

INSERT INTO loans
  (bank_id, file_number, borrower_id, product_type, status,
   purchase_price, down_payment, loan_amount, interest_rate,
   total_interest, total_amount, term_weeks, weekly_rate, custom_final_rate,
   start_date, end_date, outstanding_balance, payment_reference, created_by)
VALUES (
  2, 'FF-PK-2026-00041',
  LAST_INSERT_ID(),
  'AUTO', 'CLOSED',
  20000.00, 0.00, 20000.00, 0.0050,
  570.00, 20570.00, 11, 2000.00, 570.00,
  '2025-11-20', DATE_ADD('2025-11-20', INTERVAL 11 WEEK),
  0.00, '20112025LdM01', 3
);

-- ---- 3c: Frank Mccormick – 13122025LdM01 (Fahrzeugkredit / Aktiv) ----
INSERT INTO borrowers
  (bank_id, customer_number, first_name, last_name, is_active, created_by, created_at)
VALUES
  (2, 'FF-2026-00042', 'Frank', 'Mccormick', 1, 3, NOW());

INSERT INTO loans
  (bank_id, file_number, borrower_id, product_type, status,
   purchase_price, down_payment, loan_amount, interest_rate,
   total_interest, total_amount, term_weeks, weekly_rate, custom_final_rate,
   start_date, end_date, outstanding_balance, payment_reference, created_by)
VALUES (
  2, 'FF-PK-2026-00042',
  LAST_INSERT_ID(),
  'AUTO', 'ACTIVE',
  7400.00, 0.00, 7400.00, 0.0100,
  186.00, 7586.00, 4, 1900.00, 1886.00,
  '2025-12-17', DATE_ADD('2025-12-17', INTERVAL 4 WEEK),
  5500.00, '13122025LdM01', 3
);

COMMIT;

-- ================================================================
-- KONTROLLE
-- ================================================================
SELECT
  l.file_number,
  CONCAT(b.first_name, ' ', b.last_name) AS name,
  l.payment_reference,
  l.loan_amount, l.outstanding_balance,
  l.status, l.product_type,
  l.start_date, l.end_date,
  l.weekly_rate, l.interest_rate
FROM loans l
JOIN borrowers b ON l.borrower_id = b.id
WHERE l.bank_id = 2
ORDER BY l.start_date, l.payment_reference;
