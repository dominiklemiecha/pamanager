-- Migration 026: allegati multipli per comunicazioni.
-- Idempotente.

CREATE TABLE IF NOT EXISTS communication_attachments (
    id INT NOT NULL AUTO_INCREMENT,
    communication_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL DEFAULT 0,
    is_inline_image TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comm (communication_id),
    CONSTRAINT fk_cattach_comm FOREIGN KEY (communication_id)
        REFERENCES communications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
