-- ============================================================
-- Migration: Arbeitgeber-Krankenversicherung (Gruppenverträge)
-- Erstellt: 2026-03-02
-- ============================================================

-- Tabelle: Arbeitgeber
CREATE TABLE IF NOT EXISTS `insurance_employers` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `bank_id`        TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `company_name`   VARCHAR(200)     NOT NULL,
    `contact_person` VARCHAR(150)     NULL,
    `phone`          VARCHAR(50)      NULL,
    `email`          VARCHAR(150)     NULL,
    `address`        TEXT             NULL,
    `iban`           VARCHAR(34)      NULL,
    `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
    `notes`          TEXT             NULL,
    `created_by`     INT UNSIGNED     NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_id` (`bank_id`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: Gruppenverträge
CREATE TABLE IF NOT EXISTS `insurance_group_contracts` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `bank_id`         TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `employer_id`     INT UNSIGNED     NOT NULL,
    `contract_number` VARCHAR(30)      NOT NULL UNIQUE,
    `product_id`      INT UNSIGNED     NOT NULL,
    `start_date`      DATE             NOT NULL,
    `end_date`        DATE             NULL COMMENT 'NULL = unbefristet',
    `status`          ENUM('APPLIED','ACTIVE','SUSPENDED','CANCELLED') NOT NULL DEFAULT 'APPLIED',
    `notes`           TEXT             NULL,
    `created_by`      INT UNSIGNED     NULL,
    `approved_by`     INT UNSIGNED     NULL,
    `approved_at`     DATETIME         NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_id`     (`bank_id`),
    KEY `idx_employer_id` (`employer_id`),
    KEY `idx_status`      (`status`),
    CONSTRAINT `fk_gc_employer`  FOREIGN KEY (`employer_id`) REFERENCES `insurance_employers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_gc_product`   FOREIGN KEY (`product_id`)  REFERENCES `insurance_products`  (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: Mitglieder
CREATE TABLE IF NOT EXISTS `insurance_members` (
    `id`                 INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `group_contract_id`  INT UNSIGNED     NOT NULL,
    `bank_id`            TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `first_name`         VARCHAR(100)     NOT NULL,
    `last_name`          VARCHAR(100)     NOT NULL,
    `dob`                DATE             NULL,
    `gender`             ENUM('M','F','D') NULL,
    `phone`              VARCHAR(50)      NULL,
    `email`              VARCHAR(150)     NULL,
    `address`            TEXT             NULL,
    `region`             VARCHAR(100)     NULL COMMENT 'Informationsfeld, beeinflusst keine Beiträge',
    `iban`               VARCHAR(34)      NULL,
    `insurance_class`    TINYINT UNSIGNED NOT NULL COMMENT '1=100/Woche, 2=200, 3=300, 4=400',
    `premium_weekly`     DECIMAL(8,2)     NOT NULL COMMENT 'Wochenbeitrag: 100/200/300/400',
    `borrower_id`        INT UNSIGNED     NULL COMMENT 'Optionale Verknüpfung mit Kreditnehmer',
    `status`             ENUM('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
    `start_date`         DATE             NOT NULL,
    `end_date`           DATE             NULL,
    `notes`              TEXT             NULL,
    `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_group_contract_id` (`group_contract_id`),
    KEY `idx_bank_id`           (`bank_id`),
    KEY `idx_status`            (`status`),
    KEY `idx_borrower_id`       (`borrower_id`),
    CONSTRAINT `fk_member_gc`       FOREIGN KEY (`group_contract_id`) REFERENCES `insurance_group_contracts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_borrower` FOREIGN KEY (`borrower_id`)        REFERENCES `borrowers`               (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ALTER insurance_claims: member_id hinzufügen, contract_id nullable machen
ALTER TABLE `insurance_claims`
    ADD COLUMN `member_id` INT UNSIGNED NULL AFTER `contract_id`,
    ADD KEY `idx_member_id` (`member_id`),
    ADD CONSTRAINT `fk_claim_member` FOREIGN KEY (`member_id`) REFERENCES `insurance_members` (`id`) ON DELETE SET NULL;

-- contract_id nullable machen (für reine Member-Claims)
ALTER TABLE `insurance_claims`
    MODIFY COLUMN `contract_id` INT UNSIGNED NULL;
