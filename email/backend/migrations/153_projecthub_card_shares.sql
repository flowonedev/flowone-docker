-- Project Hub: client-facing deliverable shares (tokenized, multi-file)
CREATE TABLE IF NOT EXISTS projecthub_card_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  card_id INT NOT NULL,
  share_token VARCHAR(64) NOT NULL,
  created_by VARCHAR(255) NOT NULL,
  title VARCHAR(255) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  max_downloads INT DEFAULT NULL,
  download_count INT NOT NULL DEFAULT 0,
  access_count INT NOT NULL DEFAULT 0,
  failed_password_attempts INT NOT NULL DEFAULT 0,
  locked_until TIMESTAMP NULL DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ph_share_token (share_token),
  KEY idx_ph_share_card (card_id),
  CONSTRAINT fk_ph_share_card FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projecthub_card_share_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  share_id INT NOT NULL,
  drive_file_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  download_count INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ph_share_file (share_id, drive_file_id),
  KEY idx_ph_share_file_drive (drive_file_id),
  CONSTRAINT fk_ph_share_file_share FOREIGN KEY (share_id) REFERENCES projecthub_card_shares(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
