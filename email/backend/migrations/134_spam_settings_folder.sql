-- Add spam_folder column to webmail_spam_settings
-- Stores the IMAP-discovered spam folder name per user for Sieve rule generation
ALTER TABLE webmail_spam_settings
    ADD COLUMN IF NOT EXISTS spam_folder VARCHAR(255) DEFAULT NULL AFTER auto_training_enabled;
