-- ============================================================
-- Migration: DEALER_EMAIL zum communications.type ENUM hinzufügen
-- Datum: 2026-03-08
-- ============================================================

ALTER TABLE `communications`
    MODIFY COLUMN `type` ENUM(
        'REMINDER',
        'DUNNING_L1',
        'DUNNING_L2',
        'TERMINATION',
        'CONFIRMATION',
        'OFFER_BUSINESS',
        'CONTRACT_BUSINESS',
        'CONTRACT_VEHICLE',
        'DEALER_EMAIL',
        'OTHER'
    ) NOT NULL;
