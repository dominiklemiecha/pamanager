<?php
/**
 * Classe SecurityMonitor - Sistema di monitoraggio sicurezza
 * PAManager - Comune
 *
 * Funzionalità:
 * - Dashboard sicurezza in tempo reale
 * - Gestione alert
 * - Rilevamento anomalie
 * - Report e statistiche
 * - Notifiche email per eventi critici
 */

class SecurityMonitor
{
    // Soglie per rilevamento anomalie
    private const THRESHOLD_FAILED_LOGINS = 10;     // in 15 minuti
    private const THRESHOLD_MASS_DOWNLOAD = 30;     // in 1 ora
    private const THRESHOLD_SUSPICIOUS_IPS = 5;     // IP diversi per stesso utente
    private const THRESHOLD_AFTER_HOURS_ACCESS = 3; // accessi fuori orario

    // Orari lavorativi (per rilevamento accessi anomali)
    private const WORK_HOURS_START = 7;  // 07:00
    private const WORK_HOURS_END = 20;   // 20:00

    /**
     * Ottiene dashboard sicurezza per admin
     *
     * @return array Dati dashboard
     */
    public static function getDashboard(): array
    {
        return [
            'summary' => self::getSummary(),
            'recent_alerts' => self::getRecentAlerts(10),
            'login_stats' => self::getLoginStats(7),
            'threat_level' => self::calculateThreatLevel(),
            'active_sessions' => self::getActiveSessionsCount(),
            'recent_critical_events' => self::getRecentCriticalEvents(5)
        ];
    }

    /**
     * Ottiene riepilogo sicurezza
     *
     * @return array Statistiche riepilogative
     */
    public static function getSummary(): array
    {
        $summary = [];

        // Alert non risolti
        try {
            $summary['unresolved_alerts'] = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM security_alerts WHERE is_resolved = FALSE"
            );
        } catch (Exception $e) {
            $summary['unresolved_alerts'] = 0;
        }

        // Login falliti ultime 24 ore
        $summary['failed_logins_24h'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'login_failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // Login riusciti ultime 24 ore
        $summary['successful_logins_24h'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'login_success'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // Documenti scaricati oggi
        try {
            $summary['downloads_today'] = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM document_downloads
                 WHERE DATE(downloaded_at) = CURDATE()"
            );
        } catch (Exception $e) {
            $summary['downloads_today'] = 0;
        }

        // Utenti attivi (ultimo accesso < 24 ore)
        $summary['active_users'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM users
             WHERE last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // Eventi critici ultime 24 ore
        $summary['critical_events_24h'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE severity = 'critical'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        return $summary;
    }

    /**
     * Ottiene alert recenti
     *
     * @param int $limit Numero massimo di alert
     * @return array Lista alert
     */
    public static function getRecentAlerts(int $limit = 20): array
    {
        try {
            return Database::fetchAll(
                "SELECT sa.*,
                        CASE
                            WHEN sa.user_type = 'employee' THEN CONCAT(e.last_name, ' ', e.first_name)
                            WHEN sa.user_type IN ('admin', 'accountant') THEN u.name
                            ELSE 'Sistema'
                        END as user_name
                 FROM security_alerts sa
                 LEFT JOIN employees e ON sa.user_type = 'employee' AND sa.user_id = e.id
                 LEFT JOIN users u ON sa.user_type IN ('admin', 'accountant') AND sa.user_id = u.id
                 ORDER BY sa.created_at DESC
                 LIMIT ?",
                [$limit]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Ottiene statistiche login
     *
     * @param int $days Numero di giorni
     * @return array Statistiche per giorno
     */
    public static function getLoginStats(int $days = 7): array
    {
        $stats = Database::fetchAll(
            "SELECT
                DATE(created_at) as date,
                SUM(CASE WHEN action = 'login_success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN action = 'login_failed' THEN 1 ELSE 0 END) as failed
             FROM audit_log
             WHERE action IN ('login_success', 'login_failed')
             AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        );

        // Completa giorni mancanti con zeri
        $result = [];
        $startDate = new DateTime("-{$days} days");
        $endDate = new DateTime();

        $statsMap = [];
        foreach ($stats as $stat) {
            $statsMap[$stat['date']] = $stat;
        }

        while ($startDate <= $endDate) {
            $dateStr = $startDate->format('Y-m-d');
            $result[] = [
                'date' => $dateStr,
                'successful' => (int) ($statsMap[$dateStr]['successful'] ?? 0),
                'failed' => (int) ($statsMap[$dateStr]['failed'] ?? 0)
            ];
            $startDate->modify('+1 day');
        }

        return $result;
    }

    /**
     * Calcola livello di minaccia corrente
     *
     * @return array Livello e dettagli
     */
    public static function calculateThreatLevel(): array
    {
        $score = 0;
        $factors = [];

        // Fattore 1: Alert non risolti
        try {
            $unresolvedAlerts = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM security_alerts WHERE is_resolved = FALSE"
            );
            if ($unresolvedAlerts > 10) {
                $score += 30;
                $factors[] = 'Molti alert non risolti';
            } elseif ($unresolvedAlerts > 5) {
                $score += 15;
                $factors[] = 'Alcuni alert non risolti';
            }
        } catch (Exception $e) {
            // Tabella non esiste
        }

        // Fattore 2: Login falliti recenti
        $failedLogins = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'login_failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        if ($failedLogins > 20) {
            $score += 25;
            $factors[] = 'Picco di login falliti';
        } elseif ($failedLogins > 10) {
            $score += 10;
            $factors[] = 'Login falliti elevati';
        }

        // Fattore 3: Eventi critici recenti
        $criticalEvents = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log
             WHERE severity = 'critical'
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        if ($criticalEvents > 0) {
            $score += 20 * min($criticalEvents, 3);
            $factors[] = 'Eventi critici rilevati';
        }

        // Fattore 4: Accessi fuori orario
        $currentHour = (int) date('H');
        if ($currentHour < self::WORK_HOURS_START || $currentHour >= self::WORK_HOURS_END) {
            $afterHoursLogins = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM audit_log
                 WHERE action = 'login_success'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            if ($afterHoursLogins > self::THRESHOLD_AFTER_HOURS_ACCESS) {
                $score += 15;
                $factors[] = 'Accessi fuori orario lavorativo';
            }
        }

        // Determina livello
        if ($score >= 60) {
            $level = 'critical';
            $color = '#dc3545';
        } elseif ($score >= 40) {
            $level = 'high';
            $color = '#fd7e14';
        } elseif ($score >= 20) {
            $level = 'medium';
            $color = '#ffc107';
        } else {
            $level = 'low';
            $color = '#28a745';
        }

        return [
            'level' => $level,
            'score' => $score,
            'color' => $color,
            'factors' => $factors
        ];
    }

    /**
     * Conta sessioni attive
     *
     * @return int Numero sessioni
     */
    public static function getActiveSessionsCount(): int
    {
        $timeout = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800;
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM sessions
             WHERE last_activity > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$timeout]
        );
    }

    /**
     * Ottiene eventi critici recenti
     *
     * @param int $limit Numero massimo
     * @return array Lista eventi
     */
    public static function getRecentCriticalEvents(int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT al.*,
                    CASE
                        WHEN al.user_type = 'employee' THEN CONCAT(e.last_name, ' ', e.first_name)
                        WHEN al.user_type IN ('admin', 'accountant') THEN u.name
                        ELSE 'Sistema'
                    END as user_name
             FROM audit_log al
             LEFT JOIN employees e ON al.user_type = 'employee' AND al.user_id = e.id
             LEFT JOIN users u ON al.user_type IN ('admin', 'accountant') AND al.user_id = u.id
             WHERE al.severity = 'critical'
             ORDER BY al.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Risolvi un alert
     *
     * @param int $alertId ID alert
     * @param int $resolvedBy ID utente che risolve
     * @param string $notes Note di risoluzione
     * @return bool Successo
     */
    public static function resolveAlert(int $alertId, int $resolvedBy, string $notes = ''): bool
    {
        try {
            Database::update('security_alerts', [
                'is_resolved' => true,
                'resolved_by' => $resolvedBy,
                'resolved_at' => date('Y-m-d H:i:s'),
                'resolution_notes' => $notes
            ], 'id = ?', [$alertId]);

            AuditLog::log(
                'security_alert_resolved',
                'admin',
                $resolvedBy,
                'security_alert',
                $alertId,
                null,
                ['notes' => $notes],
                AuditLog::SEVERITY_INFO
            );

            return true;
        } catch (Exception $e) {
            error_log('Alert resolution failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea alert manuale
     *
     * @param string $type Tipo alert
     * @param string $userType Tipo utente coinvolto
     * @param int $userId ID utente coinvolto
     * @param array $details Dettagli
     * @return int|false ID alert o false
     */
    public static function createAlert(string $type, string $userType, int $userId, array $details = [])
    {
        try {
            return Database::insert('security_alerts', [
                'alert_type' => $type,
                'user_type' => $userType,
                'user_id' => $userId,
                'details' => json_encode($details),
                'ip_address' => getClientIp(),
                'is_resolved' => false
            ]);
        } catch (Exception $e) {
            error_log('Alert creation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Analizza pattern sospetti
     *
     * @return array Anomalie rilevate
     */
    public static function detectAnomalies(): array
    {
        $anomalies = [];

        // 1. Utenti con troppi login falliti
        $failedLoginUsers = Database::fetchAll(
            "SELECT user_type, user_id, COUNT(*) as failed_count
             FROM audit_log
             WHERE action = 'login_failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
             GROUP BY user_type, user_id
             HAVING failed_count > ?",
            [self::THRESHOLD_FAILED_LOGINS]
        );

        foreach ($failedLoginUsers as $user) {
            $anomalies[] = [
                'type' => 'brute_force_attempt',
                'severity' => 'high',
                'user_type' => $user['user_type'],
                'user_id' => $user['user_id'],
                'details' => "{$user['failed_count']} tentativi falliti in 15 minuti"
            ];
        }

        // 2. Download massivi
        try {
            $massDownloaders = Database::fetchAll(
                "SELECT user_type, user_id, COUNT(*) as download_count
                 FROM document_downloads
                 WHERE downloaded_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 GROUP BY user_type, user_id
                 HAVING download_count > ?",
                [self::THRESHOLD_MASS_DOWNLOAD]
            );

            foreach ($massDownloaders as $user) {
                $anomalies[] = [
                    'type' => 'mass_download',
                    'severity' => 'medium',
                    'user_type' => $user['user_type'],
                    'user_id' => $user['user_id'],
                    'details' => "{$user['download_count']} download in 1 ora"
                ];
            }
        } catch (Exception $e) {
            // Tabella potrebbe non esistere
        }

        // 3. Stesso utente da IP multipli
        $multiIpUsers = Database::fetchAll(
            "SELECT user_type, user_id, COUNT(DISTINCT ip_address) as ip_count
             FROM audit_log
             WHERE action = 'login_success'
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY user_type, user_id
             HAVING ip_count > ?",
            [self::THRESHOLD_SUSPICIOUS_IPS]
        );

        foreach ($multiIpUsers as $user) {
            $anomalies[] = [
                'type' => 'suspicious_ip_pattern',
                'severity' => 'high',
                'user_type' => $user['user_type'],
                'user_id' => $user['user_id'],
                'details' => "Accessi da {$user['ip_count']} IP diversi in 1 ora"
            ];
        }

        // 4. Accessi admin fuori orario
        $currentHour = (int) date('H');
        if ($currentHour < self::WORK_HOURS_START || $currentHour >= self::WORK_HOURS_END) {
            $afterHoursAdmins = Database::fetchAll(
                "SELECT DISTINCT user_id
                 FROM audit_log
                 WHERE action = 'login_success'
                 AND user_type IN ('admin', 'accountant')
                 AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );

            foreach ($afterHoursAdmins as $user) {
                $anomalies[] = [
                    'type' => 'after_hours_admin_access',
                    'severity' => 'low',
                    'user_type' => 'admin',
                    'user_id' => $user['user_id'],
                    'details' => "Accesso admin fuori orario lavorativo"
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Esegue scansione sicurezza completa
     *
     * @return array Report scansione
     */
    public static function runSecurityScan(): array
    {
        $report = [
            'scan_time' => date('Y-m-d H:i:s'),
            'anomalies' => self::detectAnomalies(),
            'alerts_created' => 0
        ];

        // Crea alert per anomalie gravi
        foreach ($report['anomalies'] as $anomaly) {
            if (in_array($anomaly['severity'], ['high', 'critical'])) {
                $alertId = self::createAlert(
                    $anomaly['type'],
                    $anomaly['user_type'],
                    $anomaly['user_id'],
                    ['details' => $anomaly['details'], 'auto_detected' => true]
                );

                if ($alertId) {
                    $report['alerts_created']++;
                }
            }
        }

        // Statistiche generali
        $report['statistics'] = [
            'total_users' => (int) Database::fetchColumn("SELECT COUNT(*) FROM users WHERE is_active = 1"),
            'total_employees' => (int) Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE is_active = 1"),
            'total_documents' => (int) Database::fetchColumn("SELECT COUNT(*) FROM documents"),
            'locked_accounts' => (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM users WHERE locked_until > NOW()"
            )
        ];

        return $report;
    }

    /**
     * Ottiene IP con più tentativi falliti
     *
     * @param int $hours Ore da considerare
     * @param int $limit Numero massimo
     * @return array Lista IP sospetti
     */
    public static function getSuspiciousIPs(int $hours = 24, int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT ip_address, COUNT(*) as attempt_count,
                    MAX(created_at) as last_attempt
             FROM audit_log
             WHERE action = 'login_failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY ip_address
             HAVING attempt_count > 3
             ORDER BY attempt_count DESC
             LIMIT ?",
            [$hours, $limit]
        );
    }

    /**
     * Ottiene attività per utente
     *
     * @param string $userType Tipo utente
     * @param int $userId ID utente
     * @param int $limit Numero massimo eventi
     * @return array Attività recenti
     */
    public static function getUserActivity(string $userType, int $userId, int $limit = 50): array
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
     * Genera report sicurezza periodico
     *
     * @param string $period 'daily', 'weekly', 'monthly'
     * @return array Report
     */
    public static function generatePeriodicReport(string $period = 'daily'): array
    {
        $days = match($period) {
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30,
            default => 1
        };

        return [
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s'),
            'date_range' => [
                'from' => date('Y-m-d', strtotime("-{$days} days")),
                'to' => date('Y-m-d')
            ],
            'summary' => [
                'total_logins' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM audit_log
                     WHERE action = 'login_success'
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                ),
                'failed_logins' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM audit_log
                     WHERE action = 'login_failed'
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                ),
                'documents_uploaded' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM documents
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                ),
                'documents_downloaded' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM document_downloads
                     WHERE downloaded_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                ) ?: 0,
                'security_alerts' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM security_alerts
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                ) ?: 0,
                'critical_events' => (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM audit_log
                     WHERE severity = 'critical'
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                )
            ],
            'top_events' => Database::fetchAll(
                "SELECT action, COUNT(*) as count
                 FROM audit_log
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY action
                 ORDER BY count DESC
                 LIMIT 10",
                [$days]
            ),
            'suspicious_ips' => self::getSuspiciousIPs($days * 24, 10)
        ];
    }
}
