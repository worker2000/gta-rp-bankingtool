-- ================================================================
-- Migration: Legacy-Felder für Fortis Finance Kunden
-- Datum: 2026-03-06
-- Zweck: Alte Kundennummern (FF-XXXX), Anlagedatum und Mitarbeiter
--        aus dem Original-Excel-System in die DB übernehmen
-- ================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- ================================================================
-- SCHRITT 1: Neue Spalten hinzufügen
-- ================================================================

ALTER TABLE borrowers
    ADD COLUMN IF NOT EXISTS legacy_customer_number VARCHAR(20)  NULL DEFAULT NULL
        COMMENT 'Alte FF-Kundennummer aus dem Vorsystem (z.B. FF-0001)'
        AFTER notes,
    ADD COLUMN IF NOT EXISTS legacy_created_at      DATETIME     NULL DEFAULT NULL
        COMMENT 'Ursprüngliches Anlagedatum aus dem Vorsystem (Excel)'
        AFTER legacy_customer_number,
    ADD COLUMN IF NOT EXISTS legacy_created_by      VARCHAR(20)  NULL DEFAULT NULL
        COMMENT 'Mitarbeiter-Initialen aus dem Vorsystem (z.B. LdM, JR)'
        AFTER legacy_created_at,
    ADD COLUMN IF NOT EXISTS customer_type          VARCHAR(50)  NULL DEFAULT NULL
        COMMENT 'Kundentyp aus dem Vorsystem (Privatperson, Unternehmen, Behörde)'
        AFTER legacy_created_by;

-- Index für schnelle Suche nach alter Nummer
CREATE INDEX IF NOT EXISTS idx_borrowers_legacy_cn ON borrowers (legacy_customer_number);

-- ================================================================
-- SCHRITT 2: Legacy-Daten einpflegen
-- Mapping: DB-ID → Excel-Daten (Angelegt am, Kundennummer, Initialen, Typ)
-- ================================================================

-- FF-0001 – Lucas de Marino (Privatperson VIP, LdM, 31.10.2025)
UPDATE borrowers SET legacy_customer_number='FF-0001', legacy_created_at='2025-10-31 18:15:57', legacy_created_by='LdM', customer_type='Privatperson VIP'  WHERE id=17 AND bank_id=2;

-- FF-0003 – Fortis Finance (Unternehmen, LdM, 31.10.2025)
UPDATE borrowers SET legacy_customer_number='FF-0003', legacy_created_at='2025-10-31 18:18:46', legacy_created_by='LdM', customer_type='Unternehmen'        WHERE id=66 AND bank_id=2;

-- FF-0004 – San Andreas Fire & Rescue Department (Behörde, LdM, 08.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0004', legacy_created_at='2025-11-08 16:58:15', legacy_created_by='LdM', customer_type='Behörde'            WHERE id=67 AND bank_id=2;

-- FF-0005 – Tatum Madrazo (Privatperson, LdM, 09.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0005', legacy_created_at='2025-11-09 18:21:42', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=50 AND bank_id=2;

-- FF-0006 – Halvar Ragnarsson (Privatperson, LdM, 09.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0006', legacy_created_at='2025-11-09 18:23:55', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=12 AND bank_id=2;

-- FF-0007 – District Attorneys Office (Behörde, LdM, 09.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0007', legacy_created_at='2025-11-09 19:38:59', legacy_created_by='LdM', customer_type='Behörde'            WHERE id=68 AND bank_id=2;

-- FF-0008 – Daniel Adams (Privatperson, LdM, 13.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0008', legacy_created_at='2025-11-13 21:50:04', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=47 AND bank_id=2;

-- FF-0009 – Butch Montgomery Chesterfield (Privatperson, LdM, 20.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0009', legacy_created_at='2025-11-20 18:40:58', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=48 AND bank_id=2;

-- FF-0010 – Willy Watermore (Privatperson, LdM, 20.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0010', legacy_created_at='2025-11-20 19:34:32', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=15 AND bank_id=2;

-- FF-0011 – Poppy Parker (Privatperson, LdM, 22.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0011', legacy_created_at='2025-11-22 17:54:00', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=10 AND bank_id=2;

-- FF-0012 – Department of Public Administration (Behörde, LdM, 22.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0012', legacy_created_at='2025-11-22 19:59:54', legacy_created_by='LdM', customer_type='Behörde'            WHERE id=69 AND bank_id=2;

-- FF-0013 – Lacy King (Privatperson, RW, 26.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0013', legacy_created_at='2025-11-26 21:42:56', legacy_created_by='RW',  customer_type='Privatperson'        WHERE id=29 AND bank_id=2;

-- FF-0014 – Julio Ramírez-González (Privatperson, JR, 27.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0014', legacy_created_at='2025-11-27 18:48:39', legacy_created_by='JR',  customer_type='Privatperson'        WHERE id=13 AND bank_id=2;

-- FF-0015 – Tariq Kings (Privatperson, JR, 27.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0015', legacy_created_at='2025-11-27 20:10:41', legacy_created_by='JR',  customer_type='Privatperson'        WHERE id=16 AND bank_id=2;

-- FF-0016 – Devone Banks (Privatperson, LdM, 28.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0016', legacy_created_at='2025-11-28 21:18:16', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=51 AND bank_id=2;

-- FF-0017 – Renji Takahaski (Privatperson, LdM, 30.11.2025)
UPDATE borrowers SET legacy_customer_number='FF-0017', legacy_created_at='2025-11-30 20:44:32', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=52 AND bank_id=2;

-- FF-0018 – Dean Ryan (Privatperson, LdM, 02.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0018', legacy_created_at='2025-12-02 17:41:34', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=20 AND bank_id=2;

-- FF-0019 – Gruppe Sechs (Unternehmen, JR, 03.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0019', legacy_created_at='2025-12-03 18:39:54', legacy_created_by='JR',  customer_type='Unternehmen'         WHERE id=70 AND bank_id=2;

-- FF-0020 – Nikita De Jong (Privatperson, EJW, 04.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0020', legacy_created_at='2025-12-04 21:34:08', legacy_created_by='EJW', customer_type='Privatperson'        WHERE id=53 AND bank_id=2;

-- FF-0021 – Christoph Miller (Privatperson, JR, 06.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0021', legacy_created_at='2025-12-06 20:50:57', legacy_created_by='JR',  customer_type='Privatperson'        WHERE id=26 AND bank_id=2;

-- FF-0022 – Daniel Branston (Privatperson, LdM, 07.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0022', legacy_created_at='2025-12-07 20:06:33', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=54 AND bank_id=2;

-- FF-0023 – Gabs Store (Unternehmen, LdM, 08.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0023', legacy_created_at='2025-12-08 16:25:47', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=71 AND bank_id=2;

-- FF-0024 – Tori Julyn (Privatperson, LdM, 08.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0024', legacy_created_at='2025-12-08 16:31:17', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=55 AND bank_id=2;

-- FF-0025 – Sarah Tyler (Privatperson, LdM, 08.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0025', legacy_created_at='2025-12-08 16:32:41', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=56 AND bank_id=2;

-- FF-0026 – Helena Rowley (Privatperson, LdM, 09.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0026', legacy_created_at='2025-12-09 13:08:54', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=57 AND bank_id=2;

-- FF-0027 – Kathleen Wolf (Privatperson, LdM, 09.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0027', legacy_created_at='2025-12-09 14:24:49', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=19 AND bank_id=2;

-- FF-0028 – Ayane Tanaka (Privatperson, LdM, 09.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0028', legacy_created_at='2025-12-09 17:45:53', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=42 AND bank_id=2;

-- FF-0029 – Kenji Kurohane (Privatperson, LdM, 09.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0029', legacy_created_at='2025-12-09 17:47:44', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=22 AND bank_id=2;

-- FF-0030 – Haru Kazuma (Privatperson, LdM, 09.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0030', legacy_created_at='2025-12-09 17:49:53', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=43 AND bank_id=2;

-- FF-0031 – Frank Mccormick (Privatperson, LdM, 11.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0031', legacy_created_at='2025-12-11 18:29:12', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=49 AND bank_id=2;

-- FF-0032 – Mireia Vidal (Privatperson, LdM, 11.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0032', legacy_created_at='2025-12-11 18:39:50', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=58 AND bank_id=2;

-- FF-0033 – Tiffany Misty Conner (Privatperson, LdM, 11.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0033', legacy_created_at='2025-12-11 19:39:18', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=59 AND bank_id=2;

-- FF-0034 – Aden Conner (Privatperson, RW, 11.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0034', legacy_created_at='2025-12-11 19:40:57', legacy_created_by='RW',  customer_type='Privatperson'        WHERE id=60 AND bank_id=2;

-- FF-0035 – Dave Sander (Privatperson, LdM, 17.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0035', legacy_created_at='2025-12-17 17:26:28', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=27 AND bank_id=2;

-- FF-0036 – Luigi Ferroni (Privatperson, LdM, 17.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0036', legacy_created_at='2025-12-17 17:45:55', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=32 AND bank_id=2;

-- FF-0037 – Pacific Standard Bank (Unternehmen, LdM, 17.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0037', legacy_created_at='2025-12-17 17:53:12', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=72 AND bank_id=2;

-- FF-0038 – Al Dente (Unternehmen, LdM, 19.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0038', legacy_created_at='2025-12-19 13:31:01', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=73 AND bank_id=2;

-- FF-0039 – Breana Steward (Privatperson, LdM, 20.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0039', legacy_created_at='2025-12-20 20:57:27', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=36 AND bank_id=2;

-- FF-0040 – Taylor Brandon (Privatperson, LdM, 22.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0040', legacy_created_at='2025-12-22 20:37:16', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=45 AND bank_id=2;

-- FF-0041 – Anthony Ravenmoor (Privatperson, LdM, 22.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0041', legacy_created_at='2025-12-22 21:54:11', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=8  AND bank_id=2;

-- FF-0042 – Los Santos Transit (Unternehmen, LdM, 22.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0042', legacy_created_at='2025-12-22 22:18:37', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=74 AND bank_id=2;

-- FF-0043 – Max Calaway (Privatperson, LdM, 23.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0043', legacy_created_at='2025-12-23 22:46:33', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=28 AND bank_id=2;

-- FF-0044 – Lincoln Brown (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0044', legacy_created_at='2025-12-30 18:11:07', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=31 AND bank_id=2;

-- FF-0045 – Jayden Tyrese McGray (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0045', legacy_created_at='2025-12-30 18:12:31', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=25 AND bank_id=2;

-- FF-0046 – Mateo Rodriguez (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0046', legacy_created_at='2025-12-30 18:15:34', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=41 AND bank_id=2;

-- FF-0047 – Victoria Samantha Walker (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0047', legacy_created_at='2025-12-30 18:17:14', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=61 AND bank_id=2;

-- FF-0048 – Charles McBride (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0048', legacy_created_at='2025-12-30 18:30:32', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=62 AND bank_id=2;

-- FF-0049 – Ajuna Luana Madrazo (Privatperson, LdM, 30.12.2025)
UPDATE borrowers SET legacy_customer_number='FF-0049', legacy_created_at='2025-12-30 19:41:20', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=63 AND bank_id=2;

-- FF-0050 – Casa del Fuego (Unternehmen, LdM, 02.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0050', legacy_created_at='2026-01-02 14:19:23', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=75 AND bank_id=2;

-- FF-0051 – Mirrors Drive (Unternehmen, LdM, 02.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0051', legacy_created_at='2026-01-02 16:27:37', legacy_created_by='LdM', customer_type='Unternehmen'         WHERE id=76 AND bank_id=2;

-- FF-0052 – Rob Itmanson (Privatperson, LdM, 03.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0052', legacy_created_at='2026-01-03 20:17:57', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=38 AND bank_id=2;

-- FF-0053 – Darius Bends (Privatperson, LdM, 03.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0053', legacy_created_at='2026-01-03 20:28:28', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=18 AND bank_id=2;

-- FF-0054 – Amaya Moreno (Privatperson, LdM, 03.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0054', legacy_created_at='2026-01-03 21:00:26', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=34 AND bank_id=2;

-- FF-0055 – Reno Moreau (Privatperson, LdM, 05.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0055', legacy_created_at='2026-01-05 19:11:28', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=64 AND bank_id=2;

-- FF-0056 – Mike Moor (Privatperson, LdM, 07.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0056', legacy_created_at='2026-01-07 20:33:40', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=40 AND bank_id=2;

-- FF-0057 – Michael Reynolds (Privatperson, LdM, 07.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0057', legacy_created_at='2026-01-07 20:56:43', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=21 AND bank_id=2;

-- FF-0058 – Ethan Carter (Privatperson, LdM, 10.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0058', legacy_created_at='2026-01-10 18:28:46', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=65 AND bank_id=2;

-- FF-0059 – Matteo Suarez (Privatperson, LdM, 10.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0059', legacy_created_at='2026-01-10 18:47:45', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=23 AND bank_id=2;

-- FF-0060 – Kailani Hale (Privatperson, LdM, 11.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0060', legacy_created_at='2026-01-11 17:10:23', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=39 AND bank_id=2;

-- FF-0061 – Ana Cortes (Privatperson, LdM, 28.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0061', legacy_created_at='2026-01-28 21:11:18', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=37 AND bank_id=2;

-- FF-0062 – Maeve O'Leary (Privatperson, LdM, 28.01.2026)
UPDATE borrowers SET legacy_customer_number='FF-0062', legacy_created_at='2026-01-28 21:37:58', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=46 AND bank_id=2;

-- FF-0063 – Nate Caven King (Privatperson, LdM, 01.02.2026)
UPDATE borrowers SET legacy_customer_number='FF-0063', legacy_created_at='2026-02-01 20:27:52', legacy_created_by='LdM', customer_type='Privatperson'        WHERE id=44 AND bank_id=2;

COMMIT;

-- ================================================================
-- KONTROLLE
-- ================================================================
SELECT
    id,
    customer_number,
    CONCAT(first_name, ' ', last_name) AS name,
    legacy_customer_number,
    DATE_FORMAT(legacy_created_at, '%d.%m.%Y %H:%i') AS angelegt_am,
    legacy_created_by AS von,
    customer_type AS typ
FROM borrowers
WHERE bank_id = 2
  AND legacy_customer_number IS NOT NULL
ORDER BY legacy_customer_number;
