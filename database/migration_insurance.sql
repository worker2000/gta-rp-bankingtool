-- ============================================================
-- Migration: Krankenversicherungsmodul (Phase 3)
-- Nur für Fortis Finance (bank_id = 2)
-- Erstellt: 2026-03-02
--
-- ANLEITUNG:
--   mysql -u psbbank -p psbbank < database/migration_insurance.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. VERSICHERUNGSPRODUKTE (Tarife)
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_products` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id`             INT UNSIGNED NOT NULL DEFAULT 2,
    `name`                VARCHAR(100) NOT NULL,
    `type`                ENUM('PKV','GKV_ZUSATZ','ZAHN','VISION','PFLEGE','UNFALL') NOT NULL,
    `description`         TEXT,
    `monthly_base_premium` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `waiting_period_days` INT DEFAULT 0 COMMENT 'Wartezeit in Tagen',
    `max_insured_sum`     DECIMAL(12,2) DEFAULT NULL COMMENT 'Maximale Versicherungssumme',
    `deductible`          DECIMAL(10,2) DEFAULT 0 COMMENT 'Selbstbeteiligung pro Jahr',
    `is_active`           TINYINT(1) DEFAULT 1,
    `sort_order`          INT DEFAULT 0,
    `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_id` (`bank_id`),
    INDEX `idx_type`    (`type`),
    INDEX `idx_active`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Tarife für Fortis Finance
INSERT INTO `insurance_products`
    (`bank_id`, `name`, `type`, `description`, `monthly_base_premium`, `waiting_period_days`, `max_insured_sum`, `deductible`, `sort_order`)
VALUES
(2, 'FF Basic',    'PKV', 'Grundversorgung: Allgemeinärzte, Notaufnahme, Medikamente (Generika)',  89.99,  30,  50000.00,  500.00, 1),
(2, 'FF Komfort',  'PKV', 'Erweiterter Schutz: Fachärzte, Zahnersatz (80%), Sehhilfen bis 300$',  149.99, 14, 150000.00,  250.00, 2),
(2, 'FF Premium',  'PKV', 'Vollschutz: Alle Ärzte, Zahnersatz (100%), Einzel-/Chefarzt, Ausland', 249.99,  0, 500000.00,    0.00, 3),
(2, 'FF Zahn',     'ZAHN','Zahnzusatz: Zahnersatz bis 80%, Implantate, Prophylaxe',                39.99, 90,  20000.00,    0.00, 4),
(2, 'FF Vision',   'VISION','Sehkraft-Zusatz: Brillen/Linsen bis 400$/Jahr, Laserkorrektur 50%',  19.99,  0,   5000.00,    0.00, 5),
(2, 'FF Pflege',   'PFLEGE','Pflegezusatz: Ergänzung zur gesetzlichen Pflegeversicherung',          29.99, 60, 100000.00,    0.00, 6);

-- ============================================================
-- 2. VERSICHERUNGSVERTRÄGE
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_contracts` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id`             INT UNSIGNED NOT NULL DEFAULT 2,
    `contract_number`     VARCHAR(30) NOT NULL UNIQUE COMMENT 'z.B. FF-KV-2026-00001',
    `borrower_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Optionale Verknüpfung zu Kreditnehmer',
    `product_id`          INT UNSIGNED NOT NULL,
    -- Versicherungsnehmer
    `insured_first_name`  VARCHAR(50)  NOT NULL,
    `insured_last_name`   VARCHAR(50)  NOT NULL,
    `insured_dob`         DATE         DEFAULT NULL,
    `insured_gender`      ENUM('M','F','D') DEFAULT NULL,
    `insured_phone`       VARCHAR(30)  DEFAULT NULL,
    `insured_email`       VARCHAR(100) DEFAULT NULL,
    `insured_address`     VARCHAR(200) DEFAULT NULL,
    `insured_iban`        VARCHAR(34)  DEFAULT NULL,
    -- Vertragsdaten
    `start_date`          DATE         NOT NULL,
    `end_date`            DATE         DEFAULT NULL COMMENT 'NULL = unbefristet',
    `payment_interval`    ENUM('MONTHLY','QUARTERLY','ANNUALLY') NOT NULL DEFAULT 'MONTHLY',
    `premium_amount`      DECIMAL(10,2) NOT NULL COMMENT 'Aktueller Monatsbeitrag',
    -- Status
    `status`              ENUM('APPLIED','ACTIVE','SUSPENDED','CANCELLED','EXPIRED') DEFAULT 'APPLIED',
    `cancellation_reason` TEXT DEFAULT NULL,
    `cancelled_at`        TIMESTAMP NULL,
    -- Risikoprüfung
    `risk_surcharge_pct`  DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Risikozuschlag in %',
    `pre_existing_conds`  TEXT DEFAULT NULL COMMENT 'Vorerkrankungen',
    `notes`               TEXT DEFAULT NULL,
    -- Tracking
    `created_by`          INT UNSIGNED DEFAULT NULL,
    `approved_by`         INT UNSIGNED DEFAULT NULL,
    `approved_at`         TIMESTAMP NULL,
    `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`)    REFERENCES `insurance_products`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`borrower_id`)   REFERENCES `borrowers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`approved_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_bank_id`         (`bank_id`),
    INDEX `idx_contract_number` (`contract_number`),
    INDEX `idx_status`          (`status`),
    INDEX `idx_borrower_id`     (`borrower_id`),
    INDEX `idx_product_id`      (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. BEITRAGSZAHLUNGSPLAN
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_premium_schedule` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `contract_id`  INT UNSIGNED NOT NULL,
    `period_label` VARCHAR(30)  NOT NULL COMMENT 'z.B. "Jan 2026", "Q1 2026"',
    `due_date`     DATE         NOT NULL,
    `amount_due`   DECIMAL(10,2) NOT NULL,
    `amount_paid`  DECIMAL(10,2) DEFAULT 0.00,
    `status`       ENUM('PENDING','PAID','PARTIAL','OVERDUE','WAIVED') DEFAULT 'PENDING',
    `paid_at`      TIMESTAMP NULL,
    `payment_ref`  VARCHAR(100) DEFAULT NULL,
    `notes`        VARCHAR(255) DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`contract_id`) REFERENCES `insurance_contracts`(`id`) ON DELETE CASCADE,
    INDEX `idx_contract_id` (`contract_id`),
    INDEX `idx_due_date`    (`due_date`),
    INDEX `idx_status`      (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. LEISTUNGSANTRÄGE (Claims)
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_claims` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id`          INT UNSIGNED NOT NULL DEFAULT 2,
    `contract_id`      INT UNSIGNED NOT NULL,
    `claim_number`     VARCHAR(30) NOT NULL UNIQUE COMMENT 'z.B. FF-LS-2026-00001',
    -- Leistungsfall
    `treatment_date`   DATE NOT NULL,
    `treatment_end`    DATE DEFAULT NULL,
    `treatment_type`   ENUM('DOCTOR','SPECIALIST','HOSPITAL','DENTAL','VISION','MEDICATION','THERAPY','OTHER') NOT NULL,
    `diagnosis`        VARCHAR(255) DEFAULT NULL,
    `provider_name`    VARCHAR(100) NOT NULL,
    `provider_address` VARCHAR(200) DEFAULT NULL,
    -- Beträge
    `billed_amount`    DECIMAL(10,2) NOT NULL COMMENT 'Rechnungsbetrag',
    `covered_amount`   DECIMAL(10,2) DEFAULT NULL COMMENT 'Anerkannter Betrag',
    `deductible_applied` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Angerechnete Selbstbeteiligung',
    `payout_amount`    DECIMAL(10,2) DEFAULT NULL COMMENT 'Auszahlungsbetrag',
    -- Dokumente & Status
    `status`           ENUM('SUBMITTED','IN_REVIEW','APPROVED','PARTIAL','REJECTED','PAID') DEFAULT 'SUBMITTED',
    `rejection_reason` TEXT DEFAULT NULL,
    `reviewer_notes`   TEXT DEFAULT NULL,
    -- Tracking
    `submitted_by`     INT UNSIGNED DEFAULT NULL,
    `reviewed_by`      INT UNSIGNED DEFAULT NULL,
    `reviewed_at`      TIMESTAMP NULL,
    `paid_at`          TIMESTAMP NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`contract_id`)  REFERENCES `insurance_contracts`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`reviewed_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_bank_id`      (`bank_id`),
    INDEX `idx_contract_id`  (`contract_id`),
    INDEX `idx_claim_number` (`claim_number`),
    INDEX `idx_status`       (`status`),
    INDEX `idx_treatment`    (`treatment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FERTIG
-- Neue Tabellen: insurance_products, insurance_contracts,
--               insurance_premium_schedule, insurance_claims
-- 6 Standardtarife für Fortis Finance angelegt.
-- ============================================================
