-- Portal Document Signature Zones
-- Stores placement zones for signatures and stamps on PDF documents.
-- Coordinates are stored as percentages of page dimensions for resolution independence.

CREATE TABLE IF NOT EXISTS portal_document_zones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    signer_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to portal_document_signers, nullable for assign-later',
    signer_email VARCHAR(255) DEFAULT NULL COMMENT 'Fallback for zone-to-signer mapping',
    zone_type ENUM('signature', 'stamp', 'signature_and_stamp') NOT NULL DEFAULT 'signature',
    page_number INT NOT NULL DEFAULT 1 COMMENT '1-based page index',
    x_percent DECIMAL(7,4) NOT NULL COMMENT 'X position as % of page width',
    y_percent DECIMAL(7,4) NOT NULL COMMENT 'Y position as % of page height',
    width_percent DECIMAL(7,4) NOT NULL COMMENT 'Zone width as % of page width',
    height_percent DECIMAL(7,4) NOT NULL COMMENT 'Zone height as % of page height',
    label VARCHAR(255) DEFAULT NULL COMMENT 'Optional label shown on the zone',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_document (document_id),
    INDEX idx_signer (signer_id),
    INDEX idx_page (document_id, page_number),
    FOREIGN KEY (document_id) REFERENCES portal_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (signer_id) REFERENCES portal_document_signers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add stamp storage to signers
ALTER TABLE portal_document_signers
    ADD COLUMN stamp_data TEXT DEFAULT NULL COMMENT 'Base64 PNG of uploaded company stamp' AFTER signature_data,
    ADD COLUMN stamp_file_path VARCHAR(1000) DEFAULT NULL COMMENT 'Disk path if stamp uploaded as file' AFTER stamp_data;

-- Add signed PDF output path to documents
ALTER TABLE portal_documents
    ADD COLUMN signed_file_path VARCHAR(1000) DEFAULT NULL COMMENT 'Path to final merged signed PDF' AFTER file_path,
    ADD COLUMN signed_filename VARCHAR(500) DEFAULT NULL COMMENT 'Filename of the signed PDF' AFTER signed_file_path;
