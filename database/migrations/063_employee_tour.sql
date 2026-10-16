-- Migration 063: stato della guida interattiva del dipendente.
-- tour_status NULL = guida mai conclusa (viene proposta in automatico sulla Home),
-- 'completed' = arrivato in fondo, 'skipped' = saltata o chiusa.
-- Rivederla dal Profilo non modifica questi campi.
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='tour_status');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN tour_status ENUM('completed','skipped') NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='tour_status_at');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN tour_status_at DATETIME NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
