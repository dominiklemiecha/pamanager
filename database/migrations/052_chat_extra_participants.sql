-- Migration 052: partecipanti extra ILLIMITATI nelle chat con dipendente.
-- Sostituisce lo slot singolo participant3 (051) con una tabella: admin/consulenti
-- possono far intervenire piu' persone nella stessa conversazione.

CREATE TABLE IF NOT EXISTS chat_extra_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    participant_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL,
    participant_id INT NOT NULL,
    added_by_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NULL,
    added_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conv_participant (conversation_id, participant_type, participant_id),
    KEY idx_participant (participant_type, participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migra gli eventuali participant3 esistenti (idempotente via INSERT IGNORE)
INSERT IGNORE INTO chat_extra_participants (conversation_id, participant_type, participant_id)
SELECT id, participant3_type, participant3_id
FROM chat_conversations
WHERE participant3_id IS NOT NULL AND participant3_type IS NOT NULL;
