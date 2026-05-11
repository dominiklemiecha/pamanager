<?php
/**
 * Classe AuditLog - Sistema di logging completo
 * PAManager - Comune
 *
 * Traccia tutte le attività sensibili:
 * - Login/Logout
 * - Tentativi falliti
 * - Download documenti
 * - Upload file
 * - Modifiche dati
 * - Accessi sospetti
 */

class AuditLog
{
    // Tipi di azione
    public const ACTION_LOGIN_SUCCESS = 'login_success';
    public const ACTION_LOGIN_FAILED = 'login_failed';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_PASSWORD_CHANGE = 'password_change';
    public const ACTION_PASSWORD_RESET = 'password_reset';
    public const ACTION_MFA_ENABLED = 'mfa_enabled';
    public const ACTION_MFA_DISABLED = 'mfa_disabled';
    public const ACTION_MFA_FAILED = 'mfa_failed';
    public const ACTION_SESSION_EXPIRED = 'session_expired';
    public const ACTION_SESSION_HIJACK_ATTEMPT = 'session_hijack_attempt';

    public const ACTION_DOCUMENT_DOWNLOAD = 'document_download';
    public const ACTION_DOCUMENT_UPLOAD = 'document_upload';
    public const ACTION_DOCUMENT_DELETE = 'document_delete';
    public const ACTION_DOCUMENT_VIEW = 'document_view';

    public const ACTION_EMPLOYEE_CREATE = 'employee_create';
    public const ACTION_EMPLOYEE_UPDATE = 'employee_update';
    public const ACTION_EMPLOYEE_DELETE = 'employee_delete';
    public const ACTION_EMPLOYEE_ACTIVATE = 'employee_activate';
    public const ACTION_EMPLOYEE_DEACTIVATE = 'employee_deactivate';

    public const ACTION_USER_CREATE = 'user_create';
    public const ACTION_USER_UPDATE = 'user_update';
    public const ACTION_USER_DELETE = 'user_delete';

    public const ACTION_COMMUNICATION_CREATE = 'communication_create';
    public const ACTION_COMMUNICATION_UPDATE = 'communication_update';
    public const ACTION_COMMUNICATION_DELETE = 'communication_delete';
    public const ACTION_COMMUNICATION_READ = 'communication_read';

    public const ACTION_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const ACTION_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    public const ACTION_UNAUTHORIZED_ACCESS = 'unauthorized_access';

    // Livelli di severità
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Registra un evento nel log
     */
    public static function log(
        string $action,
        string $userType,
        int $userId,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $severity = self::SEVERITY_INFO
    ): bool {
        try {
            $data = [
                'user_type' => $userType,
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues ? json_encode(self::maskSensitiveData($oldValues)) : null,
                'new_values' => $newValues ? json_encode(self::maskSensitiveData($newValues)) : null,
                'ip_address' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'severity' => $severity,
                'session_id' => session_id() ?: null
            ];

            Database::insert('audit_log', $data);

            // Se severità critica, invia alert
            if ($severity === self::SEVERITY_CRITICAL) {
                self::sendSecurityAlert($action, $data);
            }

            // Log anche su file per backup
            self::logToFile($action, $data);

            return true;
        } catch (Exception $e) {
            error_log('AuditLog error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log login riuscito
     */
    public static function logLogin(string $userType, int $userId, array $extra = []): void
    {
        self::log(
            self::ACTION_LOGIN_SUCCESS,
            $userType,
            $userId,
            null,
            null,
            null,
            $extra,
            self::SEVERITY_INFO
        );
    }

    /**
     * Log login fallito
     */
    public static function logLoginFailed(string $userType, int $userId, string $reason, array $extra = []): void
    {
        $data = array_merge(['reason' => $reason], $extra);

        self::log(
            self::ACTION_LOGIN_FAILED,
            $userType,
            $userId,
            null,
            null,
            null,
            $data,
            self::SEVERITY_WARNING
        );

        // Verifica pattern sospetti
        self::checkSuspiciousLoginPattern($userId, $userType);
    }

    /**
     * Log logout
     */
    public static function logLogout(string $userType, int $userId): void
    {
        self::log(self::ACTION_LOGOUT, $userType, $userId);
    }

    /**
     * Log download documento
     */
    public static function logDocumentDownload(
        string $userType,
        int $userId,
        int $documentId,
        array $documentInfo = []
    ): void {
        self::log(
            self::ACTION_DOCUMENT_DOWNLOAD,
            $userType,
            $userId,
            'document',
            $documentId,
            null,
            $documentInfo,
            self::SEVERITY_INFO
        );

        // Verifica download massivi
        self::checkMassDownload($userId, $userType);
    }

    /**
     * Log upload documento
     */
    public static function logDocumentUpload(
        string $userType,
        int $userId,
        int $documentId,
        array $documentInfo = []
    ): void {
        self::log(
            self::ACTION_DOCUMENT_UPLOAD,
            $userType,
            $userId,
            'document',
            $documentId,
            null,
            $documentInfo,
            self::SEVERITY_INFO
        );
    }

    /**
     * Log cambio password
     */
    public static function logPasswordChange(string $userType, int $userId): void
    {
        self::log(
            self::ACTION_PASSWORD_CHANGE,
            $userType,
            $userId,
            null,
            null,
            null,
            null,
            self::SEVERITY_INFO
        );
    }

    /**
     * Log attività sospetta
     */
    public static function logSuspiciousActivity(
        string $userType,
        int $userId,
        string $description,
        array $context = []
    ): void {
        $data = array_merge(['description' => $description], $context);

        self::log(
            self::ACTION_SUSPICIOUS_ACTIVITY,
            $userType,
            $userId,
            null,
            null,
            null,
            $data,
            self::SEVERITY_CRITICAL
        );
    }

    /**
     * Log tentativo hijack sessione
     */
    public static function logSessionHijackAttempt(string $userType, int $userId, array $details = []): void
    {
        self::log(
            self::ACTION_SESSION_HIJACK_ATTEMPT,
            $userType,
            $userId,
            null,
            null,
            null,
            $details,
            self::SEVERITY_CRITICAL
        );
    }

    /**
     * Log accesso non autorizzato
     */
    public static function logUnauthorizedAccess(string $resource, array $details = []): void
    {
        $userId = 0;
        $userType = 'unknown';

        if (isset($_SESSION['auth_user'])) {
            $userId = $_SESSION['auth_user']['id'];
            $userType = $_SESSION['auth_user']['role'];
        } elseif (isset($_SESSION['auth_employee'])) {
            $userId = $_SESSION['auth_employee']['id'];
            $userType = 'employee';
        }

        $data = array_merge(['resource' => $resource], $details);

        self::log(
            self::ACTION_UNAUTHORIZED_ACCESS,
            $userType,
            $userId,
            null,
            null,
            null,
            $data,
            self::SEVERITY_CRITICAL
        );
    }

    /**
     * Log operazione CRUD su entità
     */
    public static function logEntityChange(
        string $action,
        string $userType,
        int $userId,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        self::log(
            $action,
            $userType,
            $userId,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            self::SEVERITY_INFO
        );
    }

    /**
     * Verifica pattern login sospetti
     */
    private static function checkSuspiciousLoginPattern(int $userId, string $userType): void
    {
        // Conta tentativi falliti negli ultimi 15 minuti
        $count = Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = ? AND user_id = ? AND user_type = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [self::ACTION_LOGIN_FAILED, $userId, $userType]
        );

        // Se più di 10 tentativi in 15 minuti, log sospetto
        if ($count > 10) {
            self::logSuspiciousActivity(
                $userType,
                $userId,
                'Multiple failed login attempts detected',
                ['failed_count' => $count, 'window' => '15 minutes']
            );
        }
    }

    /**
     * Verifica download massivi
     */
    private static function checkMassDownload(int $userId, string $userType): void
    {
        // Conta download nell'ultima ora
        $count = Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = ? AND user_id = ? AND user_type = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [self::ACTION_DOCUMENT_DOWNLOAD, $userId, $userType]
        );

        // Se più di 30 download in un'ora, log sospetto
        if ($count > 30) {
            self::logSuspiciousActivity(
                $userType,
                $userId,
                'Mass download detected',
                ['download_count' => $count, 'window' => '1 hour']
            );
        }
    }

    /**
     * Invia alert di sicurezza
     */
    private static function sendSecurityAlert(string $action, array $data): void
    {
        // Salva alert nel database
        try {
            Database::insert('security_alerts', [
                'alert_type' => $action,
                'user_type' => $data['user_type'],
                'user_id' => $data['user_id'],
                'details' => json_encode($data),
                'ip_address' => $data['ip_address'],
                'is_resolved' => false
            ]);
        } catch (Exception $e) {
            // Tabella potrebbe non esistere ancora
            error_log('Security alert insert failed: ' . $e->getMessage());
        }

        // Log su file separato per alert critici
        $alertLog = LOGS_PATH . '/security_alerts.log';
        $message = sprintf(
            "[%s] CRITICAL ALERT: %s | User: %s:%d | IP: %s | Details: %s\n",
            date('Y-m-d H:i:s'),
            $action,
            $data['user_type'],
            $data['user_id'],
            $data['ip_address'],
            json_encode($data)
        );

        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }

        file_put_contents($alertLog, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log su file
     */
    private static function logToFile(string $action, array $data): void
    {
        $logFile = LOGS_PATH . '/audit.log';

        $message = sprintf(
            "[%s] %s | User: %s:%d | IP: %s | Entity: %s:%s\n",
            date('Y-m-d H:i:s'),
            $action,
            $data['user_type'],
            $data['user_id'],
            $data['ip_address'],
            $data['entity_type'] ?? '-',
            $data['entity_id'] ?? '-'
        );

        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }

        file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Maschera dati sensibili
     */
    private static function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'password_hash', 'token', 'secret', 'mfa_secret', 'api_key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskSensitiveData($value);
            } elseif (in_array(strtolower($key), $sensitiveKeys)) {
                $data[$key] = '***MASKED***';
            }
        }

        return $data;
    }

    /**
     * Ottiene IP client
     */
    private static function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Ottiene log per utente
     */
    public static function getByUser(string $userType, int $userId, int $limit = 100): array
    {
        return Database::fetchAll(
            "SELECT * FROM audit_log
             WHERE user_type = ? AND user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userType, $userId, $limit]
        );
    }

    /**
     * Ottiene log per azione
     */
    public static function getByAction(string $action, int $limit = 100): array
    {
        return Database::fetchAll(
            "SELECT * FROM audit_log
             WHERE action = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$action, $limit]
        );
    }

    /**
     * Ottiene alert di sicurezza non risolti
     */
    public static function getUnresolvedAlerts(): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM security_alerts
                 WHERE is_resolved = FALSE
                 ORDER BY created_at DESC"
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Conta alert critici nelle ultime 24 ore
     */
    public static function countRecentCriticalAlerts(): int
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM audit_log
                 WHERE severity = 'critical'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Statistiche login per dashboard
     */
    public static function getLoginStats(int $days = 7): array
    {
        try {
            $successful = Database::fetchAll(
                "SELECT DATE(created_at) as date, COUNT(*) as count
                 FROM audit_log
                 WHERE action = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)",
                [self::ACTION_LOGIN_SUCCESS, $days]
            );

            $failed = Database::fetchAll(
                "SELECT DATE(created_at) as date, COUNT(*) as count
                 FROM audit_log
                 WHERE action = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)",
                [self::ACTION_LOGIN_FAILED, $days]
            );

            return [
                'successful' => $successful,
                'failed' => $failed
            ];
        } catch (Exception $e) {
            return ['successful' => [], 'failed' => []];
        }
    }
}
