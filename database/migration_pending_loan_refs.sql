-- ============================================================
-- Migration: Ausstehende Kredit-Referenzen (Import-Erkennung)
-- Erstellt: 2026-03-02
-- ============================================================

-- Staging-Tabelle für aus Bankimporten erkannte Kredit-Referenzen.
-- Jeder Datensatz repräsentiert eine eindeutige Referenznummer (z.B. 28012026LdM03),
-- die im Verwendungszweck von Kontobewegungen gefunden wurde.
-- Sobald Kreditnehmer und Kredit angelegt werden, wird der Datensatz verknüpft.
CREATE TABLE IF NOT EXISTS `pending_loan_refs` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `bank_id`           INT UNSIGNED  NOT NULL DEFAULT 2,
    `ref_number`        VARCHAR(40)   NOT NULL COMMENT 'Erkannte Referenznummer (z.B. 28012026LdM03)',
    `first_seen`        DATE          NOT NULL COMMENT 'Datum der ersten Transaktion',
    `last_seen`         DATE          NOT NULL COMMENT 'Datum der letzten Transaktion',
    `transaction_count` INT UNSIGNED  NOT NULL DEFAULT 1,
    `total_received`    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Summe aller zugeordneten Zahlungen',
    `weekly_amount`     DECIMAL(12,2) NULL COMMENT 'Vermutete Wochenrate (häufigster Betrag)',
    `sender_name`       VARCHAR(200)  NULL COMMENT 'Name aus erster Transaktion',
    `borrower_id`       INT UNSIGNED  NULL COMMENT 'Gesetzt sobald Kreditnehmer angelegt',
    `loan_id`           INT UNSIGNED  NULL COMMENT 'Gesetzt sobald Kredit angelegt',
    `status`            ENUM('PENDING','CONVERTED','IGNORED') NOT NULL DEFAULT 'PENDING',
    `notes`             TEXT          NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ref_bank`  (`ref_number`, `bank_id`),
    KEY `idx_status`            (`status`),
    KEY `idx_borrower`          (`borrower_id`),
    KEY `idx_loan`              (`loan_id`),
    CONSTRAINT `fk_plr_borrower` FOREIGN KEY (`borrower_id`)
        REFERENCES `borrowers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_plr_loan`     FOREIGN KEY (`loan_id`)
        REFERENCES `loans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spalte in bank_transactions für die Zuordnung zu pending_loan_refs
ALTER TABLE `bank_transactions`
    ADD COLUMN `matched_pending_ref_id` INT UNSIGNED NULL AFTER `matched_member_id`,
    ADD KEY `idx_matched_pending_ref` (`matched_pending_ref_id`),
    ADD CONSTRAINT `fk_bt_pending_ref` FOREIGN KEY (`matched_pending_ref_id`)
        REFERENCES `pending_loan_refs` (`id`) ON DELETE SET NULL;
