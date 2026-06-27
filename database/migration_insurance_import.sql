-- ============================================================
-- Migration: Versicherungs-Import (freie Mitglieder)
-- Erstellt: 2026-03-02
-- ============================================================

-- insurance_members: group_contract_id nullable machen (freie Mitglieder ohne Zuordnung)
ALTER TABLE `insurance_members`
    MODIFY COLUMN `group_contract_id` INT UNSIGNED NULL;

-- insurance_members: member_ref für externe Vertragsnummer (z.B. 010202026LdM01)
ALTER TABLE `insurance_members`
    ADD COLUMN `member_ref` VARCHAR(40) NULL COMMENT 'Externe Vertragsnummer aus Banküberweisung' AFTER `notes`,
    ADD UNIQUE KEY `idx_member_ref` (`member_ref`);

-- bank_transactions: matched_member_id für KV-Mitglieder
ALTER TABLE `bank_transactions`
    ADD COLUMN `matched_member_id` INT UNSIGNED NULL AFTER `matched_loan_id`,
    ADD KEY `idx_matched_member_id` (`matched_member_id`),
    ADD CONSTRAINT `fk_bt_member` FOREIGN KEY (`matched_member_id`)
        REFERENCES `insurance_members` (`id`) ON DELETE SET NULL;

-- Beitragseingang-Tracking für KV-Mitglieder
CREATE TABLE IF NOT EXISTS `insurance_member_premiums` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id`           INT UNSIGNED NOT NULL,
    `bank_transaction_id` INT UNSIGNED NULL,
    `amount`              DECIMAL(8,2) NOT NULL,
    `payment_date`        DATE         NOT NULL,
    `notes`               TEXT         NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_member_id` (`member_id`),
    KEY `idx_bank_tx` (`bank_transaction_id`),
    CONSTRAINT `fk_imp_member` FOREIGN KEY (`member_id`)
        REFERENCES `insurance_members` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_imp_tx` FOREIGN KEY (`bank_transaction_id`)
        REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
