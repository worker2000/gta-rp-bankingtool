-- ============================================================
-- Migration: Multi-Bank-Erweiterung (Phase 1)
-- PSB Kreditverwaltung → PSB + Fortis Finance
-- Erstellt: 2026-03-02
--
-- ANLEITUNG:
--   mysql -u psbbank -p psbbank < database/migration_banks.sql
--
-- Alle bestehenden Daten werden PSB (bank_id=1) zugeordnet.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. BANKEN-TABELLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `banks` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(100) NOT NULL,
    `short_code`    VARCHAR(10)  NOT NULL,
    `main_account`  VARCHAR(50)  DEFAULT NULL COMMENT 'Haupt-IBAN der Bank',
    `primary_color` VARCHAR(7)   DEFAULT '#0d6efd' COMMENT 'Primärfarbe hex',
    `logo_url`      VARCHAR(500) DEFAULT NULL,
    `is_active`     TINYINT(1)   DEFAULT 1,
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `banks` (`id`, `name`, `short_code`, `main_account`, `primary_color`, `logo_url`) VALUES
(1, 'Pacific State Bank', 'PSB', 'PS2B61225563', '#0d6efd',
   'https://static.wikia.nocookie.net/degta/images/5/5f/Pacificstandard.png/revision/latest/thumbnail/width/360/height/360?cb=20160911200440'),
(2, 'Fortis Finance',     'FF',  'FF2B00000001', '#c9a227', NULL)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ============================================================
-- 2. BENUTZER: bank_id hinzufügen
-- ============================================================
ALTER TABLE `users`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 3. KREDITNEHMER: bank_id + Unique-Constraint anpassen
-- ============================================================
ALTER TABLE `borrowers`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

ALTER TABLE `borrowers` DROP INDEX `customer_number`;
ALTER TABLE `borrowers` ADD UNIQUE KEY `unique_customer_per_bank` (`bank_id`, `customer_number`);

-- ============================================================
-- 4. KREDITE: bank_id hinzufügen
-- ============================================================
ALTER TABLE `loans`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 5. KUNDENKONTEN: bank_id + Unique-Constraint anpassen
-- ============================================================
ALTER TABLE `customer_accounts`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

ALTER TABLE `customer_accounts` DROP INDEX `account_number`;
ALTER TABLE `customer_accounts` ADD UNIQUE KEY `unique_account_per_bank` (`bank_id`, `account_number`);

-- ============================================================
-- 6. POLICIES: bank_id + Duplikat für Fortis Finance
-- ============================================================
ALTER TABLE `loan_policies`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- Fortis Finance bekommt dieselben Start-Policies wie PSB
INSERT INTO `loan_policies` (`bank_id`, `policy_key`, `policy_value`, `description`, `valid_from`)
SELECT 2, `policy_key`, `policy_value`, `description`, `valid_from`
FROM `loan_policies` WHERE `bank_id` = 1;

-- ============================================================
-- 7. KONTOAUSZUG-BATCHES: bank_id hinzufügen
-- ============================================================
ALTER TABLE `bank_statement_batches`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 8. VORLAGEN: bank_id + Duplikat für Fortis Finance
-- ============================================================
ALTER TABLE `templates`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- FF bekommt eigene Kopien mit angepasstem Absender
INSERT INTO `templates` (`bank_id`, `name`, `type`, `subject`, `body`, `placeholders`, `is_active`)
SELECT 2, `name`, `type`, `subject`,
       REPLACE(`body`, 'Pacific State Bank', 'Fortis Finance'),
       `placeholders`, `is_active`
FROM `templates` WHERE `bank_id` = 1;

-- ============================================================
-- 9. KOMMUNIKATION: bank_id hinzufügen
-- ============================================================
ALTER TABLE `communications`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 10. DOKUMENTE: bank_id hinzufügen
-- ============================================================
ALTER TABLE `documents`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 11. AUDIT-LOG: bank_id hinzufügen
-- ============================================================
ALTER TABLE `audit_log`
    ADD COLUMN `bank_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
    ADD INDEX `idx_bank_id` (`bank_id`);

-- ============================================================
-- 12. SUPER-ADMIN-ROLLE (bank-übergreifend)
-- ============================================================
INSERT INTO `roles` (`name`, `description`, `permissions`)
VALUES ('super_admin', 'Super Administrator – Zugriff auf alle Banken', '{"all": true, "cross_bank": true}')
ON DUPLICATE KEY UPDATE `permissions` = '{"all": true, "cross_bank": true}';

-- Bestehenden admin-Benutzer zum Super-Admin machen
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id
FROM `users` u
JOIN `roles` r ON r.name = 'super_admin'
WHERE u.username = 'admin';

-- ============================================================
-- 13. FORTIS FINANCE ADMIN-BENUTZER
-- Passwort: admin123 (nach erstem Login bitte ändern!)
-- ============================================================
INSERT INTO `users` (`bank_id`, `username`, `password_hash`, `full_name`, `email`) VALUES
(2, 'ff_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'FF Administrator', 'admin@fortisfinance.local');

INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id
FROM `users` u
JOIN `roles` r ON r.name = 'director'
WHERE u.username = 'ff_admin';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FERTIG
-- Benutzer nach Migration:
--   admin    (bank_id=1, PSB)  + super_admin-Rolle → Zugang zu beiden Banken
--   ff_admin (bank_id=2, FF)   + director-Rolle    → Nur Fortis Finance
-- Passwort beide: admin123
-- ============================================================
