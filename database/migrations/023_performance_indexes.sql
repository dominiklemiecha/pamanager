-- Migration 023: indici di performance.
-- Pattern idempotente senza stored procedure (compatibile con migrate.php).

-- documents
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND INDEX_NAME='idx_docs_emp_period');
SET @sql = IF(@c=0, "ALTER TABLE documents ADD INDEX idx_docs_emp_period (employee_id, year, month)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND INDEX_NAME='idx_docs_uploader_created');
SET @sql = IF(@c=0, "ALTER TABLE documents ADD INDEX idx_docs_uploader_created (uploaded_by, created_at)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- leave_requests
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leave_requests' AND INDEX_NAME='idx_leave_emp_status');
SET @sql = IF(@c=0, "ALTER TABLE leave_requests ADD INDEX idx_leave_emp_status (employee_id, status, start_date)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- chat_messages
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND INDEX_NAME='idx_chat_msg_conv_created');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD INDEX idx_chat_msg_conv_created (conversation_id, created_at)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- chat_conversations
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='idx_chat_conv_p1');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD INDEX idx_chat_conv_p1 (participant1_type, participant1_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='idx_chat_conv_p2');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD INDEX idx_chat_conv_p2 (participant2_type, participant2_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- employees
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND INDEX_NAME='idx_emp_company_active');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD INDEX idx_emp_company_active (company_id, is_active)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND INDEX_NAME='idx_emp_dept_active');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD INDEX idx_emp_dept_active (department_id, is_active)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- communications
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communications' AND INDEX_NAME='idx_comm_company_created');
SET @sql = IF(@c=0, "ALTER TABLE communications ADD INDEX idx_comm_company_created (company_id, created_at)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_log
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_log' AND INDEX_NAME='idx_audit_entity');
SET @sql = IF(@c=0, "ALTER TABLE audit_log ADD INDEX idx_audit_entity (entity_type, entity_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
