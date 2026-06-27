-- PSB Kreditverwaltungs-System - Datenbankschema
-- Erstellt: 2026-02-14
-- Charset: utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. ROLLEN
-- ============================================
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255),
    `permissions` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standardrollen einfügen
INSERT INTO `roles` (`name`, `description`, `permissions`) VALUES
('director', 'Direktor - Vollzugriff', '{"all": true}'),
('senior_loan_officer', 'Senior Kreditbearbeiter - Kredite erstellen & freigeben', '{"loans": ["create", "approve", "edit", "view"], "borrowers": ["create", "edit", "view"], "import": ["upload", "match"], "dunning": ["view", "create"], "reports": ["view"]}'),
('loan_officer', 'Kreditbearbeiter - Bearbeitung & Uploads', '{"loans": ["create", "edit", "view"], "borrowers": ["create", "edit", "view"], "import": ["upload", "match"], "dunning": ["view"]}'),
('collections', 'Inkasso - Mahnungen & Kündigungen', '{"loans": ["view"], "borrowers": ["view"], "dunning": ["create", "edit", "view", "terminate"], "communications": ["create", "view"]}'),
('auditor', 'Prüfer - Nur Lesen & Reports', '{"loans": ["view"], "borrowers": ["view"], "dunning": ["view"], "reports": ["view"], "audit": ["view"]}'),
('support', 'Support - Statusauskunft', '{"loans": ["view"], "borrowers": ["view"], "dunning": ["view"]}');

-- ============================================
-- 2. BENUTZER
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100),
    `full_name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. BENUTZER-ROLLEN ZUORDNUNG
-- ============================================
CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_role` (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. POLICIES (Konfigurierbare Regeln)
-- ============================================
CREATE TABLE IF NOT EXISTS `loan_policies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_key` VARCHAR(50) NOT NULL,
    `policy_value` VARCHAR(255) NOT NULL,
    `description` VARCHAR(255),
    `valid_from` DATE NOT NULL,
    `valid_until` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_policy_key` (`policy_key`),
    INDEX `idx_valid_dates` (`valid_from`, `valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Policies einfügen
INSERT INTO `loan_policies` (`policy_key`, `policy_value`, `description`, `valid_from`) VALUES
('AUTO_MIN_DOWNPAY_RATIO', '0.30', 'Mindest-Eigenkapital für Autokredite (30%)', '2024-01-01'),
('AUTO_MAX_TERM_WEEKS', '8', 'Maximale Laufzeit für Autokredite in Wochen', '2024-01-01'),
('DUNNING_L1_DAYS', '7', 'Tage bis Mahnstufe 1', '2024-01-01'),
('DUNNING_L2_DAYS', '14', 'Tage bis Mahnstufe 2', '2024-01-01'),
('TERMINATION_DAYS', '21', 'Tage bis Kündigung', '2024-01-01'),
('DEFAULT_LATE_WEEKLY_RATE', '0.10', 'Verzugszins pro Woche (10%)', '2024-01-01'),
('MAX_RATE_INCOME_RATIO', '0.40', 'Maximaler Anteil der Rate am Einkommen (40%)', '2024-01-01'),
('REMINDER_DAYS_BEFORE', '3', 'Erinnerung X Tage vor Fälligkeit', '2024-01-01');

-- ============================================
-- 5. KREDITNEHMER
-- ============================================
CREATE TABLE IF NOT EXISTS `borrowers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_number` VARCHAR(20) NOT NULL UNIQUE,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `date_of_birth` DATE,
    `phone` VARCHAR(30),
    `email` VARCHAR(100),
    `address_street` VARCHAR(100),
    `address_city` VARCHAR(50),
    `address_zip` VARCHAR(10),
    `employer` VARCHAR(100),
    `company` VARCHAR(100) DEFAULT NULL,
    `weekly_income` DECIMAL(12,2),
    `total_assets` DECIMAL(12,2) DEFAULT NULL,
    `bank_account_iban` VARCHAR(34),
    `bank_account_holder` VARCHAR(100),
    `notes` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_customer_number` (`customer_number`),
    INDEX `idx_name` (`last_name`, `first_name`),
    INDEX `idx_iban` (`bank_account_iban`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. KREDITE
-- ============================================
CREATE TABLE IF NOT EXISTS `loans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_number` VARCHAR(20) NOT NULL UNIQUE COMMENT 'Aktenzeichen',
    `borrower_id` INT UNSIGNED NOT NULL,
    `product_type` ENUM('PRIVATE', 'BUSINESS', 'AUTO') NOT NULL,
    `status` ENUM(
        'APPLICATION_RECEIVED',
        'IN_REVIEW',
        'APPROVED',
        'REJECTED',
        'CONTRACT_CREATED',
        'ACTIVE',
        'DUNNING_L1',
        'DUNNING_L2',
        'TERMINATED',
        'REPOSSESSION',
        'CLOSED'
    ) DEFAULT 'APPLICATION_RECEIVED',
    `purchase_price` DECIMAL(12,2) NOT NULL COMMENT 'Kaufpreis',
    `down_payment` DECIMAL(12,2) NOT NULL COMMENT 'Eigenkapital',
    `loan_amount` DECIMAL(12,2) NOT NULL COMMENT 'Kreditsumme',
    `interest_rate` DECIMAL(5,4) NOT NULL COMMENT 'Zinssatz (z.B. 0.1000 = 10%)',
    `total_interest` DECIMAL(12,2) NOT NULL COMMENT 'Gesamtzins',
    `total_amount` DECIMAL(12,2) NOT NULL COMMENT 'Gesamtsumme (Kredit + Zins)',
    `term_weeks` INT NOT NULL COMMENT 'Laufzeit in Wochen',
    `weekly_rate` DECIMAL(12,2) NOT NULL COMMENT 'Wochenrate',
    `custom_final_rate` DECIMAL(12,2) DEFAULT NULL COMMENT 'Variable Restrate (Schlussrate)',
    `start_date` DATE NOT NULL COMMENT 'Vertragsbeginn',
    `end_date` DATE NOT NULL COMMENT 'Vertragsende',
    `payment_account` VARCHAR(34) COMMENT 'PSB Zahlungskonto IBAN',
    `payment_reference` VARCHAR(50) COMMENT 'Zahlungsreferenz/Verwendungszweck',
    `outstanding_balance` DECIMAL(12,2) COMMENT 'Aktuelle Restschuld',
    `days_overdue` INT DEFAULT 0 COMMENT 'Aktuelle Verzugstage',
    `late_fees_accrued` DECIMAL(12,2) DEFAULT 0 COMMENT 'Aufgelaufene Verzugszinsen',
    `assigned_to` INT UNSIGNED COMMENT 'Zugewiesener Sachbearbeiter',
    `approved_by` INT UNSIGNED COMMENT 'Genehmigt von',
    `approved_at` TIMESTAMP NULL,
    `notes` TEXT,
    `vehicle_plate` VARCHAR(20) DEFAULT NULL COMMENT 'Nummernschild des Fahrzeugs',
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`borrower_id`) REFERENCES `borrowers`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_file_number` (`file_number`),
    INDEX `idx_borrower` (`borrower_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_product_type` (`product_type`),
    INDEX `idx_payment_reference` (`payment_reference`),
    INDEX `idx_days_overdue` (`days_overdue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. RATENPLAN
-- ============================================
CREATE TABLE IF NOT EXISTS `loan_schedule_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `installment_number` INT NOT NULL COMMENT 'Ratennummer',
    `due_date` DATE NOT NULL COMMENT 'Fälligkeitsdatum',
    `amount_due` DECIMAL(12,2) NOT NULL COMMENT 'Sollbetrag',
    `amount_paid` DECIMAL(12,2) DEFAULT 0 COMMENT 'Bereits bezahlt',
    `amount_outstanding` DECIMAL(12,2) NOT NULL COMMENT 'Offener Betrag',
    `status` ENUM('PENDING', 'PARTIAL', 'PAID', 'OVERDUE') DEFAULT 'PENDING',
    `days_overdue` INT DEFAULT 0,
    `late_interest` DECIMAL(12,2) DEFAULT 0 COMMENT 'Verzugszins für diese Rate',
    `paid_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    INDEX `idx_loan_id` (`loan_id`),
    INDEX `idx_due_date` (`due_date`),
    INDEX `idx_status` (`status`),
    UNIQUE KEY `unique_loan_installment` (`loan_id`, `installment_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. SICHERHEITEN
-- ============================================
CREATE TABLE IF NOT EXISTS `collaterals` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `type` ENUM('VEHICLE', 'PROPERTY', 'OTHER') NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `identifier` VARCHAR(100) COMMENT 'z.B. Kennzeichen, Fahrgestellnummer',
    `estimated_value` DECIMAL(12,2),
    `status` ENUM('ACTIVE', 'RELEASED', 'REPOSSESSED') DEFAULT 'ACTIVE',
    `repossessed_at` TIMESTAMP NULL,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    INDEX `idx_loan_id` (`loan_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. KONTOAUSZUG-BATCHES
-- ============================================
CREATE TABLE IF NOT EXISTS `bank_statement_batches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_date` DATE NOT NULL,
    `filename` VARCHAR(255),
    `total_transactions` INT DEFAULT 0,
    `matched_count` INT DEFAULT 0,
    `ambiguous_count` INT DEFAULT 0,
    `unmatched_count` INT DEFAULT 0,
    `status` ENUM('PENDING', 'PROCESSING', 'COMPLETED', 'ERROR') DEFAULT 'PENDING',
    `imported_by` INT UNSIGNED,
    `processed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`imported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_batch_date` (`batch_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. BANK-TRANSAKTIONEN
-- ============================================
CREATE TABLE IF NOT EXISTS `bank_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `transaction_time` TIME NULL,
    `booking_date` DATE,
    `amount` DECIMAL(12,2) NOT NULL,
    `sender_name` VARCHAR(100),
    `sender_iban` VARCHAR(34),
    `sender_party` VARCHAR(200) NULL COMMENT 'Voller Sender-String aus Import',
    `empfaenger_party` VARCHAR(200) NULL COMMENT 'Voller Empfänger-String aus Import',
    `direction` VARCHAR(20) NULL COMMENT 'eingehend/ausgehend aus Import',
    `reference` TEXT COMMENT 'Verwendungszweck',
    `match_status` ENUM('MATCHED', 'AMBIGUOUS', 'UNMATCHED', 'FEE') DEFAULT 'UNMATCHED',
    `matched_loan_id` INT UNSIGNED NULL,
    `matched_schedule_id` INT UNSIGNED NULL,
    `match_confidence` DECIMAL(3,2) COMMENT 'Matching-Konfidenz 0.00-1.00',
    `match_method` VARCHAR(50) COMMENT 'Wie wurde gematcht',
    `matched_by` INT UNSIGNED NULL,
    `matched_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`batch_id`) REFERENCES `bank_statement_batches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`matched_loan_id`) REFERENCES `loans`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`matched_schedule_id`) REFERENCES `loan_schedule_items`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`matched_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_batch_id` (`batch_id`),
    INDEX `idx_transaction_date` (`transaction_date`),
    INDEX `idx_match_status` (`match_status`),
    INDEX `idx_sender_iban` (`sender_iban`),
    INDEX `idx_amount` (`amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. TRANSACTION MATCHES (für AMBIGUOUS)
-- ============================================
CREATE TABLE IF NOT EXISTS `transaction_matches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT UNSIGNED NOT NULL,
    `loan_id` INT UNSIGNED NOT NULL,
    `schedule_item_id` INT UNSIGNED NULL,
    `confidence` DECIMAL(3,2) NOT NULL,
    `match_reason` VARCHAR(255),
    `is_selected` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`transaction_id`) REFERENCES `bank_transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`schedule_item_id`) REFERENCES `loan_schedule_items`(`id`) ON DELETE SET NULL,
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_loan_id` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. TEXTVORLAGEN
-- ============================================
CREATE TABLE IF NOT EXISTS `templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('REMINDER', 'DUNNING_L1', 'DUNNING_L2', 'TERMINATION', 'CONFIRMATION', 'OTHER') NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `placeholders` JSON COMMENT 'Liste der verfügbaren Platzhalter',
    `is_active` TINYINT(1) DEFAULT 1,
    `version` INT DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_type` (`type`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Vorlagen einfügen
INSERT INTO `templates` (`name`, `type`, `subject`, `body`, `placeholders`) VALUES
('Zahlungserinnerung', 'REMINDER', 'Zahlungserinnerung - Aktenzeichen {AKTENZEICHEN}',
'Sehr geehrte(r) {NAME},

wir möchten Sie freundlich daran erinnern, dass die Rate für Ihren Kredit (Aktenzeichen: {AKTENZEICHEN}) in Höhe von {OFFENE_RATE} am {FÄLLIGKEITSDATUM} fällig wird.

Bitte überweisen Sie den Betrag rechtzeitig auf folgendes Konto:
IBAN: {KONTO}
Verwendungszweck: {VERWENDUNGSZWECK}

Mit freundlichen Grüßen
Pacific State Bank',
'["NAME", "AKTENZEICHEN", "OFFENE_RATE", "FÄLLIGKEITSDATUM", "KONTO", "VERWENDUNGSZWECK"]'),

('Mahnung Stufe 1', 'DUNNING_L1', 'Erste Mahnung - Aktenzeichen {AKTENZEICHEN}',
'Sehr geehrte(r) {NAME},

trotz Fälligkeit am {FÄLLIGKEITSDATUM} konnten wir keinen Zahlungseingang für die Rate in Höhe von {OFFENE_RATE} feststellen.

Die Zahlung ist seit {VERZUGSTAGE} Tagen überfällig. Bitte begleichen Sie den offenen Betrag zuzüglich Verzugszinsen von {VERZUGSZINS} unverzüglich.

Gesamtbetrag: {GESAMTBETRAG}
IBAN: {KONTO}
Verwendungszweck: {VERWENDUNGSZWECK}

Mit freundlichen Grüßen
Pacific State Bank',
'["NAME", "AKTENZEICHEN", "OFFENE_RATE", "FÄLLIGKEITSDATUM", "VERZUGSTAGE", "VERZUGSZINS", "GESAMTBETRAG", "KONTO", "VERWENDUNGSZWECK"]'),

('Mahnung Stufe 2', 'DUNNING_L2', 'Zweite Mahnung - Aktenzeichen {AKTENZEICHEN}',
'Sehr geehrte(r) {NAME},

dies ist unsere zweite Mahnung bezüglich Ihres Kredits (Aktenzeichen: {AKTENZEICHEN}).

Der offene Betrag von {OFFENE_RATE} zzgl. Verzugszinsen von {VERZUGSZINS} ist seit {VERZUGSTAGE} Tagen überfällig.

Sollten wir innerhalb von 7 Tagen keinen Zahlungseingang verzeichnen, sehen wir uns gezwungen, den Kredit zu kündigen und ggf. hinterlegte Sicherheiten zu verwerten.

Gesamtbetrag: {GESAMTBETRAG}
IBAN: {KONTO}
Verwendungszweck: {VERWENDUNGSZWECK}
Frist: {FRISTDATUM}

Mit freundlichen Grüßen
Pacific State Bank',
'["NAME", "AKTENZEICHEN", "OFFENE_RATE", "VERZUGSTAGE", "VERZUGSZINS", "GESAMTBETRAG", "KONTO", "VERWENDUNGSZWECK", "FRISTDATUM"]'),

('Kündigung', 'TERMINATION', 'Kündigung des Kreditvertrags - Aktenzeichen {AKTENZEICHEN}',
'Sehr geehrte(r) {NAME},

hiermit kündigen wir den Kreditvertrag mit dem Aktenzeichen {AKTENZEICHEN} fristlos.

Trotz mehrfacher Mahnung haben Sie Ihre Zahlungsverpflichtungen nicht erfüllt. Der gesamte Restbetrag in Höhe von {RESTSCHULD} zzgl. aufgelaufener Verzugszinsen von {VERZUGSZINS} wird sofort zur Zahlung fällig.

Gesamtforderung: {GESAMTFORDERUNG}

Sollte innerhalb von 7 Tagen kein vollständiger Zahlungseingang erfolgen, werden wir rechtliche Schritte einleiten und hinterlegte Sicherheiten verwerten.

Mit freundlichen Grüßen
Pacific State Bank',
'["NAME", "AKTENZEICHEN", "RESTSCHULD", "VERZUGSZINS", "GESAMTFORDERUNG"]');

-- ============================================
-- 13. KOMMUNIKATION (Gesendete Schreiben)
-- ============================================
CREATE TABLE IF NOT EXISTS `communications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED,
    `type` ENUM('REMINDER', 'DUNNING_L1', 'DUNNING_L2', 'TERMINATION', 'CONFIRMATION', 'OTHER') NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL COMMENT 'Finaler Text ohne Platzhalter',
    `template_version` INT,
    `sent_via` ENUM('COPY_PASTE', 'EMAIL', 'LETTER') DEFAULT 'COPY_PASTE',
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`template_id`) REFERENCES `templates`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_loan_id` (`loan_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. DOKUMENTE
-- ============================================
CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `doc_type` ENUM('UPLOAD','WRITTEN','TEMPLATE_BASED') NOT NULL DEFAULT 'UPLOAD',
    `title` VARCHAR(255) NULL,
    `loan_id` INT UNSIGNED,
    `borrower_id` INT UNSIGNED,
    `type` ENUM('CONTRACT', 'ID_DOCUMENT', 'INCOME_PROOF', 'COLLATERAL_DOC', 'CORRESPONDENCE', 'OTHER') NOT NULL DEFAULT 'OTHER',
    `filename` VARCHAR(255) NULL,
    `original_filename` VARCHAR(255) NULL,
    `file_path` VARCHAR(500) NULL,
    `file_size` INT,
    `mime_type` VARCHAR(100),
    `description` VARCHAR(255),
    `content` LONGTEXT NULL,
    `template_id` INT UNSIGNED NULL,
    `uploaded_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`borrower_id`) REFERENCES `borrowers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`template_id`) REFERENCES `templates`(`id`) ON DELETE SET NULL,
    INDEX `idx_loan_id` (`loan_id`),
    INDEX `idx_borrower_id` (`borrower_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_doc_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 15. AUDIT LOG
-- ============================================
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'loan, borrower, user, etc.',
    `entity_id` INT UNSIGNED NOT NULL,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADMIN-BENUTZER ERSTELLEN
-- ============================================
-- Passwort: admin123 (bitte nach erstem Login ändern!)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `email`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@psbbank.local');

INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id FROM `users` u, `roles` r WHERE u.username = 'admin' AND r.name = 'director';

-- ============================================
-- 16. KUNDENKONTEN (Bankkonten der Kunden)
-- ============================================
CREATE TABLE IF NOT EXISTS `customer_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `borrower_id` INT UNSIGNED NULL COMMENT 'Verknüpfter Kreditnehmer',
    `account_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'z.B. PSG85714318, PS2B61225563',
    `account_name` VARCHAR(100) COMMENT 'Benutzerdefinierter Name z.B. Al Dente Firmenkonto',
    `owner_name` VARCHAR(100) NULL COMMENT 'Name des Kontoinhabers',
    `owner_phone` VARCHAR(30) NULL COMMENT 'Telefon des Kontoinhabers',
    `owner_email` VARCHAR(100) NULL COMMENT 'E-Mail des Kontoinhabers',
    `account_type` ENUM('BRONZE', 'SILVER', 'GOLD', 'BUSINESS', 'STARTUP', 'LOHNKONTO') NOT NULL,
    `account_type_label` VARCHAR(50) NOT NULL COMMENT 'z.B. Pacific Standard Gold',
    `weekly_fee` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Wöchentliche Kontoführungsgebühr',
    `opening_fee` DECIMAL(12,2) DEFAULT 0 COMMENT 'Kontoeröffnungsgebühr',
    `opening_date` DATE COMMENT 'Datum der Kontoeröffnung',
    `status` ENUM('ACTIVE', 'CLOSED') DEFAULT 'ACTIVE',
    `total_fees_paid` DECIMAL(12,2) DEFAULT 0 COMMENT 'Gesamte gezahlte Gebühren',
    `total_transfer_fees` DECIMAL(12,2) DEFAULT 0 COMMENT 'Gesamte Überweisungsgebühren',
    `total_weekly_fees` DECIMAL(12,2) DEFAULT 0 COMMENT 'Gesamte Kontoführungsgebühren',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_account_number` (`account_number`),
    INDEX `idx_account_type` (`account_type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_borrower_id` (`borrower_id`),
    FOREIGN KEY (`borrower_id`) REFERENCES `borrowers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 17. KONTO-TRANSAKTIONEN (Gebühren pro Konto)
-- ============================================
CREATE TABLE IF NOT EXISTS `account_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_id` INT UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `transaction_time` TIME NULL COMMENT 'Uhrzeit der Transaktion',
    `amount` DECIMAL(12,2) NOT NULL,
    `fee_type` ENUM('OPENING', 'TRANSFER', 'WEEKLY', 'OTHER') NOT NULL COMMENT 'Art der Gebühr',
    `description` VARCHAR(255) COMMENT 'Originale Beschreibung',
    `bank_transaction_id` INT UNSIGNED NULL COMMENT 'Verknüpfung zu bank_transactions',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`account_id`) REFERENCES `customer_accounts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions`(`id`) ON DELETE SET NULL,
    INDEX `idx_account_id` (`account_id`),
    INDEX `idx_transaction_date` (`transaction_date`),
    INDEX `idx_fee_type` (`fee_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
