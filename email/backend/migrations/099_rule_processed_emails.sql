CREATE TABLE IF NOT EXISTS boardpro_rule_processed_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    email_uid INT NOT NULL,
    email_folder VARCHAR(255) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rule_email (rule_id, email_uid, email_folder),
    KEY idx_rule_id (rule_id),
    CONSTRAINT fk_processed_rule FOREIGN KEY (rule_id) REFERENCES boardpro_email_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
