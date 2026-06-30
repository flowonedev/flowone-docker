CREATE TABLE IF NOT EXISTS automation_hub_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_email VARCHAR(255) NOT NULL,
  provider VARCHAR(50) NOT NULL,
  access_token_encrypted TEXT,
  refresh_token_encrypted TEXT,
  token_expires_at DATETIME DEFAULT NULL,
  api_key_encrypted TEXT DEFAULT NULL,
  meta JSON DEFAULT NULL,
  connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_provider (user_email, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
