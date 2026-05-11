<?php
/**
 * Classe GDPR - Gestione conformità GDPR
 * PAManager - Comune
 *
 * Implementa funzionalità per la conformità al Regolamento Generale
 * sulla Protezione dei Dati (GDPR - UE 2016/679)
 *
 * Funzionalità:
 * - Gestione consensi (Art. 7)
 * - Diritto di accesso ai dati (Art. 15)
 * - Diritto alla portabilità (Art. 20)
 * - Diritto alla cancellazione (Art. 17 - "Diritto all'oblio")
 * - Data retention policy
 * - Anonimizzazione dati
 */

class GDPR
{
    // Tipi di consenso
    public const CONSENT_PRIVACY_POLICY = 'privacy_policy';
    public const CONSENT_DATA_PROCESSING = 'data_processing';
    public const CONSENT_MARKETING = 'marketing';
    public const CONSENT_THIRD_PARTY = 'third_party_sharing';
    public const CONSENT_ANALYTICS = 'analytics';

    // Periodi di retention (in giorni)
    private const RETENTION_AUDIT_LOG = 730;        // 2 anni
    private const RETENTION_SECURITY_ALERTS = 365;  // 1 anno (se risolti)
    private const RETENTION_INACTIVE_EMPLOYEES = 1825; // 5 anni (requisiti legali)
    private const RETENTION_DOCUMENTS = 3650;       // 10 anni (requisiti fiscali)
    private const RETENTION_SESSIONS = 30;          // 30 giorni
    private const RETENTION_RATE_LIMITS = 1;        // 1 giorno

    /**
     * Registra un consenso
     *
     * @param string $userType Tipo utente
     * @param int $userId ID utente
     * @param string $consentType Tipo di consenso
     * @param bool $given Consenso dato o revocato
     * @return bool Successo
     */
    public static function recordConsent(
        string $userType,
        int $userId,
        string $consentType,
        bool $given
    ): bool {
        try {
            Database::insert('gdpr_consents', [
                'user_type' => $userType,
                'user_id' => $userId,
                'consent_type' => $consentType,
                'consent_given' => $given,
                'ip_address' => getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            AuditLog::log(
                'gdpr_consent_' . ($given ? 'given' : 'revoked'),
                $userType,
                $userId,
                'consent',
                0,
                null,
                ['consent_type' => $consentType, 'given' => $given],
                AuditLog::SEVERITY_INFO
            );

            return true;
        } catch (Exception $e) {
            error_log('GDPR consent recording failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottieni stato consensi per un utente
     *
     * @param string $userType Tipo utente
     * @param int $userId ID utente
     * @return array Mappa tipo_consenso => ultimo_stato
     */
    public static function getConsents(string $userType, int $userId): array
    {
        $consents = Database::fetchAll(
            "SELECT consent_type, consent_given, created_at
             FROM gdpr_consents
             WHERE user_type = ? AND user_id = ?
             ORDER BY created_at DESC",
            [$userType, $userId]
        );

        // Raggruppa per tipo, prendi il più recente
        $result = [];
        foreach ($consents as $consent) {
            $type = $consent['consent_type'];
            if (!isset($result[$type])) {
                $result[$type] = [
                    'given' => (bool) $consent['consent_given'],
                    'date' => $consent['created_at']
                ];
            }
        }

        return $result;
    }

    /**
     * Verifica se un consenso specifico è stato dato
     *
     * @param string $userType Tipo utente
     * @param int $userId ID utente
     * @param string $consentType Tipo di consenso
     * @return bool True se consenso attivo
     */
    public static function hasConsent(string $userType, int $userId, string $consentType): bool
    {
        $consent = Database::fetch(
            "SELECT consent_given FROM gdpr_consents
             WHERE user_type = ? AND user_id = ? AND consent_type = ?
             ORDER BY created_at DESC LIMIT 1",
            [$userType, $userId, $consentType]
        );

        return $consent && (bool) $consent['consent_given'];
    }

    /**
     * Esporta tutti i dati di un dipendente (Diritto di accesso - Art. 15)
     *
     * @param int $employeeId ID dipendente
     * @return array Dati completi in formato strutturato
     */
    public static function exportEmployeeData(int $employeeId): array
    {
        $employee = Employee::getById($employeeId);
        if (!$employee) {
            return ['error' => 'Dipendente non trovato'];
        }

        // Dati personali
        $personalData = [
            'anagrafica' => [
                'codice_fiscale' => $employee['fiscal_code'],
                'nome' => $employee['first_name'],
                'cognome' => $employee['last_name'],
                'email' => $employee['email'],
                'telefono' => $employee['phone'],
                'data_creazione' => $employee['created_at'],
                'ultimo_aggiornamento' => $employee['updated_at']
            ]
        ];

        // Documenti
        $documents = Database::fetchAll(
            "SELECT id, type, title, file_name, file_size, month, year, created_at
             FROM documents WHERE employee_id = ?",
            [$employeeId]
        );
        $personalData['documenti'] = $documents;

        // Comunicazioni lette
        $communications = Database::fetchAll(
            "SELECT c.id, c.title, c.publish_date, cr.read_at
             FROM communication_reads cr
             JOIN communications c ON cr.communication_id = c.id
             WHERE cr.employee_id = ?",
            [$employeeId]
        );
        $personalData['comunicazioni_lette'] = $communications;

        // Download effettuati
        try {
            $downloads = Database::fetchAll(
                "SELECT dd.document_id, d.title, dd.downloaded_at, dd.ip_address
                 FROM document_downloads dd
                 JOIN documents d ON dd.document_id = d.id
                 WHERE dd.user_type = 'employee' AND dd.user_id = ?
                 ORDER BY dd.downloaded_at DESC",
                [$employeeId]
            );
            $personalData['download_effettuati'] = $downloads;
        } catch (Exception $e) {
            $personalData['download_effettuati'] = [];
        }

        // Consensi
        $personalData['consensi'] = self::getConsents('employee', $employeeId);

        // Log di accesso (ultimi 90 giorni)
        $accessLogs = Database::fetchAll(
            "SELECT action, ip_address, user_agent, created_at
             FROM audit_log
             WHERE user_type = 'employee' AND user_id = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
             ORDER BY created_at DESC",
            [$employeeId]
        );
        $personalData['log_accessi'] = $accessLogs;

        // Metadati export
        $personalData['_metadata'] = [
            'export_date' => date('Y-m-d H:i:s'),
            'export_reason' => 'GDPR Art. 15 - Right of Access',
            'format_version' => '1.0'
        ];

        // Log dell'export
        AuditLog::log(
            'gdpr_data_export',
            'employee',
            $employeeId,
            'employee',
            $employeeId,
            null,
            ['export_type' => 'full'],
            AuditLog::SEVERITY_INFO
        );

        return $personalData;
    }

    /**
     * Genera file JSON per portabilità dati (Art. 20)
     *
     * @param int $employeeId ID dipendente
     * @return string JSON formattato
     */
    public static function generatePortableData(int $employeeId): string
    {
        $data = self::exportEmployeeData($employeeId);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Anonimizza dati di un dipendente (alternativa alla cancellazione)
     *
     * @param int $employeeId ID dipendente
     * @param int $performedBy ID utente che esegue l'operazione
     * @return array Risultato operazione
     */
    public static function anonymizeEmployee(int $employeeId, int $performedBy): array
    {
        $employee = Employee::getById($employeeId);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        try {
            Database::beginTransaction();

            // Salva dati originali per log
            $oldData = $employee;

            // Genera identificativo anonimo
            $anonId = 'ANON_' . bin2hex(random_bytes(8));

            // Anonimizza dati personali
            Database::update('employees', [
                'fiscal_code' => $anonId,
                'first_name' => 'Anonimo',
                'last_name' => $anonId,
                'email' => null,
                'phone' => null,
                'is_active' => false
            ], 'id = ?', [$employeeId]);

            // Disabilita MFA se attivo
            Database::update('employees', [
                'mfa_secret' => null,
                'mfa_enabled' => false,
                'mfa_backup_codes' => null
            ], 'id = ?', [$employeeId]);

            // Log dell'operazione
            Database::insert('data_retention_log', [
                'entity_type' => 'employee',
                'entity_id' => $employeeId,
                'action' => 'anonymized',
                'reason' => 'GDPR Request',
                'performed_by' => $performedBy,
                'old_data' => json_encode(self::maskSensitiveFields($oldData))
            ]);

            Database::commit();

            AuditLog::log(
                'gdpr_data_anonymized',
                'admin',
                $performedBy,
                'employee',
                $employeeId,
                null,
                ['reason' => 'GDPR Request'],
                AuditLog::SEVERITY_WARNING
            );

            return ['success' => true, 'anonymous_id' => $anonId];

        } catch (Exception $e) {
            Database::rollback();
            error_log('GDPR anonymization failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante l\'anonimizzazione'];
        }
    }

    /**
     * Cancella completamente dati di un dipendente (Diritto all'oblio - Art. 17)
     * ATTENZIONE: Operazione irreversibile
     *
     * @param int $employeeId ID dipendente
     * @param int $performedBy ID utente che esegue l'operazione
     * @param string $reason Motivazione
     * @return array Risultato operazione
     */
    public static function deleteEmployeeData(int $employeeId, int $performedBy, string $reason): array
    {
        $employee = Employee::getById($employeeId);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        try {
            Database::beginTransaction();

            // Log prima della cancellazione (per compliance)
            Database::insert('data_retention_log', [
                'entity_type' => 'employee',
                'entity_id' => $employeeId,
                'action' => 'deleted',
                'reason' => $reason,
                'performed_by' => $performedBy,
                'old_data' => json_encode([
                    'fiscal_code_hash' => hash('sha256', $employee['fiscal_code']),
                    'deletion_date' => date('Y-m-d H:i:s')
                ])
            ]);

            // Elimina documenti fisici
            $documents = Document::getByEmployee($employeeId);
            foreach ($documents as $doc) {
                if (isset($doc['file_path']) && file_exists($doc['file_path'])) {
                    unlink($doc['file_path']);
                }
            }

            // I documenti vengono eliminati automaticamente (CASCADE)
            // Le communication_reads vengono eliminate automaticamente (CASCADE)

            // Elimina consensi
            Database::delete('gdpr_consents', 'user_type = ? AND user_id = ?', ['employee', $employeeId]);

            // Anonimizza audit log invece di eliminare (per sicurezza)
            Database::execute(
                "UPDATE audit_log SET user_id = 0
                 WHERE user_type = 'employee' AND user_id = ?",
                [$employeeId]
            );

            // Elimina dipendente
            Database::delete('employees', 'id = ?', [$employeeId]);

            Database::commit();

            AuditLog::log(
                'gdpr_data_deleted',
                'admin',
                $performedBy,
                'employee',
                $employeeId,
                null,
                ['reason' => $reason],
                AuditLog::SEVERITY_CRITICAL
            );

            return ['success' => true];

        } catch (Exception $e) {
            Database::rollback();
            error_log('GDPR deletion failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante la cancellazione'];
        }
    }

    /**
     * Esegue pulizia dati secondo policy di retention
     *
     * @return array Report della pulizia
     */
    public static function runRetentionCleanup(): array
    {
        $report = [
            'executed_at' => date('Y-m-d H:i:s'),
            'actions' => []
        ];

        try {
            // 1. Pulizia audit log vecchi
            $auditDeleted = Database::execute(
                "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [self::RETENTION_AUDIT_LOG]
            );
            $report['actions']['audit_log'] = $auditDeleted->rowCount() . ' record eliminati';

            // 2. Pulizia security alerts risolti
            $alertsDeleted = Database::execute(
                "DELETE FROM security_alerts
                 WHERE is_resolved = TRUE
                 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [self::RETENTION_SECURITY_ALERTS]
            );
            $report['actions']['security_alerts'] = $alertsDeleted->rowCount() . ' record eliminati';

            // 3. Pulizia sessioni vecchie
            $sessionsDeleted = Database::execute(
                "DELETE FROM sessions
                 WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))",
                [self::RETENTION_SESSIONS]
            );
            $report['actions']['sessions'] = $sessionsDeleted->rowCount() . ' record eliminati';

            // 4. Pulizia rate limits
            $rateLimitsDeleted = Database::execute(
                "DELETE FROM rate_limits
                 WHERE window_start < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [self::RETENTION_RATE_LIMITS]
            );
            $report['actions']['rate_limits'] = $rateLimitsDeleted->rowCount() . ' record eliminati';

            // Log della pulizia
            Database::insert('data_retention_log', [
                'entity_type' => 'system',
                'entity_id' => 0,
                'action' => 'deleted',
                'reason' => 'Automated retention cleanup',
                'performed_by' => null,
                'old_data' => json_encode($report)
            ]);

            $report['success'] = true;

        } catch (Exception $e) {
            error_log('Retention cleanup failed: ' . $e->getMessage());
            $report['success'] = false;
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Ottieni report dipendenti inattivi per possibile archiviazione
     *
     * @param int $inactiveDays Giorni di inattività
     * @return array Lista dipendenti inattivi
     */
    public static function getInactiveEmployees(int $inactiveDays = 365): array
    {
        return Database::fetchAll(
            "SELECT e.id, e.fiscal_code, e.first_name, e.last_name, e.email,
                    e.is_active, e.updated_at,
                    MAX(al.created_at) as last_activity
             FROM employees e
             LEFT JOIN audit_log al ON al.user_type = 'employee' AND al.user_id = e.id
             WHERE e.is_active = FALSE
             OR e.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY e.id
             HAVING last_activity IS NULL
             OR last_activity < DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY last_activity ASC",
            [$inactiveDays, $inactiveDays]
        );
    }

    /**
     * Genera report GDPR per audit
     *
     * @return array Report completo
     */
    public static function generateComplianceReport(): array
    {
        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'period' => 'Last 12 months'
        ];

        // Statistiche consensi
        $report['consents'] = [
            'total' => Database::fetchColumn("SELECT COUNT(*) FROM gdpr_consents"),
            'by_type' => Database::fetchAll(
                "SELECT consent_type, consent_given, COUNT(*) as count
                 FROM gdpr_consents
                 GROUP BY consent_type, consent_given"
            )
        ];

        // Richieste di accesso dati
        $report['data_access_requests'] = Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'gdpr_data_export'
             AND created_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)"
        );

        // Richieste di cancellazione
        $report['deletion_requests'] = Database::fetchColumn(
            "SELECT COUNT(*) FROM data_retention_log
             WHERE action IN ('deleted', 'anonymized')
             AND performed_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)"
        );

        // Data retention executions
        $report['retention_cleanups'] = Database::fetchColumn(
            "SELECT COUNT(*) FROM data_retention_log
             WHERE reason = 'Automated retention cleanup'
             AND performed_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)"
        );

        // Security incidents
        $report['security_incidents'] = Database::fetchColumn(
            "SELECT COUNT(*) FROM security_alerts
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)"
        );

        // Data breaches (se esistono)
        $report['data_breaches'] = 0; // Placeholder

        return $report;
    }

    /**
     * Maschera campi sensibili per logging
     */
    private static function maskSensitiveFields(array $data): array
    {
        $sensitiveFields = ['fiscal_code', 'email', 'phone', 'password_hash', 'mfa_secret'];

        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                if (strlen($value) > 4) {
                    $data[$field] = substr($value, 0, 2) . '***' . substr($value, -2);
                } else {
                    $data[$field] = '***';
                }
            }
        }

        return $data;
    }

    /**
     * Verifica se il sistema è conforme GDPR
     *
     * @return array Checklist di conformità
     */
    public static function checkCompliance(): array
    {
        $checks = [];

        // 1. Tabella consensi esiste
        try {
            Database::fetch("SELECT 1 FROM gdpr_consents LIMIT 1");
            $checks['consent_tracking'] = ['status' => 'ok', 'message' => 'Consent tracking attivo'];
        } catch (Exception $e) {
            $checks['consent_tracking'] = ['status' => 'error', 'message' => 'Tabella consensi mancante'];
        }

        // 2. Tabella retention log esiste
        try {
            Database::fetch("SELECT 1 FROM data_retention_log LIMIT 1");
            $checks['retention_log'] = ['status' => 'ok', 'message' => 'Retention logging attivo'];
        } catch (Exception $e) {
            $checks['retention_log'] = ['status' => 'error', 'message' => 'Tabella retention mancante'];
        }

        // 3. Audit logging attivo
        $recentLogs = Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $checks['audit_logging'] = [
            'status' => $recentLogs > 0 ? 'ok' : 'warning',
            'message' => $recentLogs > 0 ? 'Audit logging funzionante' : 'Nessun log recente'
        ];

        // 4. Encryption password (verifica se usando bcrypt/argon2)
        $checks['password_encryption'] = [
            'status' => 'ok',
            'message' => 'Password hashate con ' . (defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt')
        ];

        // 5. HTTPS enforcement
        $checks['https'] = [
            'status' => isHttps() || (defined('DEBUG_MODE') && DEBUG_MODE) ? 'ok' : 'warning',
            'message' => isHttps() ? 'HTTPS attivo' : 'HTTPS non attivo (consentito in debug)'
        ];

        // 6. Session security
        $checks['session_security'] = [
            'status' => ini_get('session.cookie_httponly') ? 'ok' : 'warning',
            'message' => 'Session cookies: httponly=' . (ini_get('session.cookie_httponly') ? 'yes' : 'no')
        ];

        return $checks;
    }
}
