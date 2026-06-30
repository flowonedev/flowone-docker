-- OnlyOffice integration: per-file editor keys + guest share links
--
-- office_editor_keys: OnlyOffice identifies an editing session by a document
-- "key". Everyone opening the same Drive file must receive the same key to
-- join the same co-editing session. The key is rotated after a final save
-- (callback status 2) or when the file changes outside the editor.
--
-- office_guest_tokens: opaque guest share links (same pattern as
-- guest_call_tokens) letting external people view/edit a Drive office file
-- without a FlowOne account.

CREATE TABLE IF NOT EXISTS office_editor_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    doc_key VARCHAR(64) NOT NULL,
    file_version INT NOT NULL DEFAULT 1,
    file_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_file (file_id),
    KEY idx_doc_key (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_guest_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL,
    file_id INT NOT NULL,
    role ENUM('viewer', 'editor') NOT NULL DEFAULT 'viewer',
    label VARCHAR(255) NULL,
    created_by VARCHAR(255) NOT NULL,
    expires_at DATETIME NULL,
    use_count INT NOT NULL DEFAULT 0,
    max_uses INT NOT NULL DEFAULT 0,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY uniq_token (token),
    KEY idx_file (file_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
