-- Migration 014: aggiunge tipo 'chiusura' (chiusura aziendale) all'ENUM leave_type
-- e introduce un metadato per identificare richieste create direttamente dall'admin.

-- Estende ENUM (MySQL: serve riscrivere l'intera definizione)
ALTER TABLE leave_requests
    MODIFY COLUMN leave_type
    ENUM('ferie','permesso','malattia','permesso_104','congedo_parentale','altro','chiusura')
    NOT NULL;

-- Flag opzionale: identifica richieste create direttamente dall'admin (bypassano workflow)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'created_by_admin');
SET @sql = IF(@c=0, "ALTER TABLE leave_requests ADD COLUMN created_by_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
