<?php
/**
 * Probation - Gestione del periodo di prova dei dipendenti.
 *
 * Il periodo di prova vive su employees.probation_end_date (migration 053) e vale
 * per QUALSIASI dipendente (nuovo flusso assunzione, crea-diretto, storici).
 *
 * Flusso:
 *  - ALERT: 14 giorni prima di probation_end_date scatta un alert per l'admin
 *    (widget dashboard + notifica in-app + email). L'admin decide conferma/non conferma.
 *  - CONFERMA: registra "prova superata", chiude l'alert, notifica il consulente.
 *  - NON CONFERMA: registra "prova non superata", notifica il consulente; il dipendente
 *    NON viene disattivato subito ma ALLA data di fine prova (sweep in runChecks).
 *
 * Non c'e' un cron attivo: runChecks() gira all'apertura della dashboard admin
 * (stesso pattern dei badge notifiche). E' idempotente.
 */
class Probation
{
    /** Giorni di anticipo con cui scatta l'alert prima della fine prova. */
    public const ALERT_LEAD_DAYS = 14;

    /**
     * Motore idempotente chiamato al load della dashboard admin per l'azienda corrente.
     *  1) apre gli alert (notifica in-app + email agli admin, una sola volta);
     *  2) esegue le disattivazioni programmate (non conferma + fine prova raggiunta).
     */
    public static function runChecks(int $companyId): void
    {
        if ($companyId <= 0) return;
        try {
            self::openAlerts($companyId);
            self::runScheduledDeactivations($companyId);
        } catch (Throwable $e) {
            error_log('[Probation::runChecks] ' . $e->getMessage());
        }
    }

    /**
     * Dipendenti nella finestra di alert ancora senza decisione (per il widget dashboard).
     * Include gli scaduti non decisi (giorni negativi = in ritardo).
     * @return array<int,array> righe employee + 'days_left'
     */
    public static function pendingDecisions(int $companyId): array
    {
        $rows = Database::fetchAll(
            "SELECT id, first_name, last_name, photo_path, hire_date, probation_end_date,
                    DATEDIFF(probation_end_date, CURDATE()) AS days_left
             FROM employees
             WHERE company_id = ?
               AND is_active = 1
               AND probation_end_date IS NOT NULL
               AND probation_decision IS NULL
               AND probation_end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY probation_end_date ASC",
            [$companyId, self::ALERT_LEAD_DAYS]
        );
        return $rows ?: [];
    }

    /** Conteggio dei dipendenti in attesa di decisione (per il badge menu). */
    public static function pendingCount(int $companyId): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM employees
             WHERE company_id = ?
               AND is_active = 1
               AND probation_end_date IS NOT NULL
               AND probation_decision IS NULL
               AND probation_end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)",
            [$companyId, self::ALERT_LEAD_DAYS]
        );
    }

    /**
     * L'admin decide l'esito del periodo di prova.
     * @param string $decision 'confirmed' | 'not_confirmed'
     * @return array ['success','error']
     */
    public static function decide(int $employeeId, string $decision, int $adminId): array
    {
        if (!in_array($decision, ['confirmed', 'not_confirmed'], true)) {
            return ['success' => false, 'error' => 'Decisione non valida'];
        }
        $emp = Employee::getById($employeeId);
        if (!$emp) return ['success' => false, 'error' => 'Dipendente non trovato'];

        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : (int)$emp['company_id'];
        if ((int)$emp['company_id'] !== (int)$cid) {
            return ['success' => false, 'error' => 'Dipendente non appartiene all\'azienda corrente'];
        }
        if (empty($emp['probation_end_date'])) {
            return ['success' => false, 'error' => 'Nessun periodo di prova impostato per questo dipendente'];
        }

        // Claim atomico: solo la prima decisione vince (due admin in contemporanea).
        $claimed = Database::update('employees', [
            'probation_decision'          => $decision,
            'probation_decided_at'        => date('Y-m-d H:i:s'),
            'probation_decided_by_user_id' => $adminId,
        ], 'id = ? AND probation_decision IS NULL', [$employeeId]);
        if ($claimed < 1) {
            return ['success' => false, 'error' => 'Decisione gia registrata'];
        }

        // Non conferma: se la fine prova e' gia' passata, disattiva subito;
        // altrimenti la disattivazione avverra' a fine prova (runScheduledDeactivations).
        $deactivatedNow = false;
        if ($decision === 'not_confirmed' && $emp['probation_end_date'] <= date('Y-m-d')) {
            $done = Database::update('employees', [
                'is_active'                => 0,
                'probation_deactivated_at' => date('Y-m-d H:i:s'),
            ], 'id = ? AND is_active = 1 AND probation_deactivated_at IS NULL', [$employeeId]);
            $deactivatedNow = ($done >= 1);
        }

        self::notifyConsulenteDecision($emp, $decision, $cid);

        return ['success' => true, 'deactivated_now' => $deactivatedNow];
    }

    /**
     * Imposta la data di fine prova suggerita dal contratto SOLO se non gia' presente,
     * e notifica gli admin di verificarla. Chiamato dal flusso assunzione (assist auto).
     * Non sovrascrive mai un valore gia' impostato o una decisione gia' presa.
     */
    public static function applyContractSuggestion(int $employeeId, ?string $suggestedEndDate, int $companyId): void
    {
        if (empty($suggestedEndDate)) return;
        $emp = Employee::getById($employeeId);
        if (!$emp) return;
        if (!empty($emp['probation_end_date']) || !empty($emp['probation_decision'])) return;

        $set = Database::update('employees',
            ['probation_end_date' => $suggestedEndDate],
            'id = ? AND probation_end_date IS NULL AND probation_decision IS NULL',
            [$employeeId]
        );
        if ($set < 1) return;

        $who = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        $when = self::fmtDate($suggestedEndDate);
        foreach (self::companyAdmins($companyId) as $a) {
            try {
                Notification::create([
                    'recipient_type' => 'admin',
                    'recipient_id'   => (int)$a['id'],
                    'type'           => 'probation_detected',
                    'title'          => 'Periodo di prova rilevato dal contratto',
                    'message'        => 'Per ' . $who . ' e\' stata rilevata una fine periodo di prova il ' . $when . '. Verifica la data nella scheda del dipendente.',
                    'link'           => '/admin/employees.php?id=' . $employeeId,
                ]);
            } catch (Throwable $e) {}
        }
    }

    // ===================== interni =====================

    /** Apre gli alert per i dipendenti entrati nella finestra dei 14 giorni (una sola volta). */
    private static function openAlerts(int $companyId): void
    {
        $due = Database::fetchAll(
            "SELECT id, first_name, last_name, probation_end_date,
                    DATEDIFF(probation_end_date, CURDATE()) AS days_left
             FROM employees
             WHERE company_id = ?
               AND is_active = 1
               AND probation_end_date IS NOT NULL
               AND probation_decision IS NULL
               AND probation_alert_notified_at IS NULL
               AND probation_end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)",
            [$companyId, self::ALERT_LEAD_DAYS]
        );
        if (empty($due)) return;

        $admins = self::companyAdmins($companyId);

        foreach ($due as $emp) {
            // Claim atomico: evita doppie notifiche se due admin caricano insieme.
            $claimed = Database::update('employees',
                ['probation_alert_notified_at' => date('Y-m-d H:i:s')],
                'id = ? AND probation_alert_notified_at IS NULL',
                [(int)$emp['id']]
            );
            if ($claimed < 1) continue;

            $who  = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
            $when = self::fmtDate($emp['probation_end_date']);
            $days = (int)$emp['days_left'];
            $msg  = $days >= 0
                ? 'Il periodo di prova di ' . $who . ' termina il ' . $when . ' (tra ' . $days . ' ' . ($days === 1 ? 'giorno' : 'giorni') . '). Conferma o non confermare l\'assunzione.'
                : 'Il periodo di prova di ' . $who . ' e\' terminato il ' . $when . ' (in ritardo). Conferma o non confermare l\'assunzione.';
            $link = '/admin/index.php';

            foreach ($admins as $a) {
                try {
                    Notification::create([
                        'recipient_type' => 'admin',
                        'recipient_id'   => (int)$a['id'],
                        'type'           => 'probation_alert',
                        'title'          => 'Periodo di prova in scadenza',
                        'message'        => $msg,
                        'link'           => $link,
                    ]);
                } catch (Throwable $e) {}
                self::emailAdminAlert($a, $who, $when, $days);
            }
        }
    }

    /** Disattiva i dipendenti "non confermati" la cui fine prova e' arrivata. */
    private static function runScheduledDeactivations(int $companyId): void
    {
        $rows = Database::fetchAll(
            "SELECT id, first_name, last_name, probation_end_date
             FROM employees
             WHERE company_id = ?
               AND is_active = 1
               AND probation_decision = 'not_confirmed'
               AND probation_deactivated_at IS NULL
               AND probation_end_date <= CURDATE()",
            [$companyId]
        );
        if (empty($rows)) return;

        foreach ($rows as $emp) {
            $done = Database::update('employees', [
                'is_active'                => 0,
                'probation_deactivated_at' => date('Y-m-d H:i:s'),
            ], 'id = ? AND is_active = 1 AND probation_deactivated_at IS NULL', [(int)$emp['id']]);
            if ($done < 1) continue;

            $who = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
            foreach (self::companyAdmins($companyId) as $a) {
                try {
                    Notification::create([
                        'recipient_type' => 'admin',
                        'recipient_id'   => (int)$a['id'],
                        'type'           => 'probation_deactivated',
                        'title'          => 'Dipendente disattivato a fine prova',
                        'message'        => $who . ' e\' stato disattivato: periodo di prova non superato (fine prova ' . self::fmtDate($emp['probation_end_date']) . ').',
                        'link'           => '/admin/employees.php?id=' . (int)$emp['id'],
                    ]);
                } catch (Throwable $e) {}
            }
        }
    }

    /** Notifica il consulente dell'azienda dell'esito (in-app + email), in entrambi i casi. */
    private static function notifyConsulenteDecision(array $emp, string $decision, int $companyId): void
    {
        $consId = class_exists('HireRequest') ? HireRequest::findConsulenteForCompany($companyId) : null;
        if (!$consId) return;
        $cons = Database::fetchOne("SELECT id, name, email FROM users WHERE id = ?", [$consId]);
        if (!$cons) return;

        $who   = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        $when  = self::fmtDate($emp['probation_end_date']);
        $esito = $decision === 'confirmed' ? 'superato' : 'NON superato';
        $title = $decision === 'confirmed' ? 'Periodo di prova superato' : 'Periodo di prova non superato';
        $extra = $decision === 'not_confirmed'
            ? ' Il dipendente verra\' disattivato alla data di fine prova (' . $when . ').'
            : '';

        try {
            Notification::create([
                'recipient_type' => 'consulente_lavoro',
                'recipient_id'   => (int)$cons['id'],
                'type'           => 'probation_decision',
                'title'          => $title,
                'message'        => 'L\'azienda ha comunicato l\'esito del periodo di prova di ' . $who . ': ' . $esito . '.' . $extra,
                'link'           => '/consulente-lavoro/index.php',
            ]);
        } catch (Throwable $e) {}

        if (empty($cons['email'])) return;
        if (!class_exists('Mailer') || !Mailer::isConfigured()) return;
        try {
            $whoSafe = htmlspecialchars($who);
            $html = '<p>Ciao ' . htmlspecialchars($cons['name']) . ',</p>'
                  . '<p>L\'azienda ha comunicato l\'esito del <strong>periodo di prova</strong> di <strong>' . $whoSafe . '</strong>:</p>'
                  . '<p style="font-size:16px;"><strong>' . ($decision === 'confirmed' ? 'Prova superata ✅' : 'Prova NON superata ⛔') . '</strong></p>'
                  . ($decision === 'not_confirmed'
                        ? '<p>Il dipendente verra\' disattivato dal gestionale alla data di fine prova (' . htmlspecialchars($when) . '). Procedi con le pratiche di cessazione.</p>'
                        : '<p>Nessuna azione richiesta: il rapporto prosegue regolarmente.</p>');
            $text = 'Esito periodo di prova di ' . $who . ': ' . ($decision === 'confirmed' ? 'SUPERATA' : 'NON SUPERATA') . '.'
                  . ($decision === 'not_confirmed' ? ' Disattivazione prevista il ' . $when . '. Procedi con la cessazione.' : '');
            Mailer::send($cons['email'], $cons['name'], 'Esito periodo di prova: ' . $who, $html, $text);
        } catch (Throwable $e) {
            error_log('[Probation] email consulente fallita: ' . $e->getMessage());
        }
    }

    /** Email all'admin quando un dipendente entra nella finestra di alert. */
    private static function emailAdminAlert(array $admin, string $who, string $when, int $days): void
    {
        if (empty($admin['email'])) return;
        if (!class_exists('Mailer') || !Mailer::isConfigured()) return;
        try {
            $link = function_exists('buildPublicUrl') ? buildPublicUrl('/admin/index.php') : '/admin/index.php';
            $whoSafe = htmlspecialchars($who);
            $windowTxt = $days >= 0
                ? 'termina il <strong>' . htmlspecialchars($when) . '</strong> (tra ' . $days . ' ' . ($days === 1 ? 'giorno' : 'giorni') . ')'
                : 'e\' terminato il <strong>' . htmlspecialchars($when) . '</strong> (in ritardo)';
            $html = '<p>Ciao ' . htmlspecialchars($admin['name'] ?? '') . ',</p>'
                  . '<p>Il <strong>periodo di prova</strong> di <strong>' . $whoSafe . '</strong> ' . $windowTxt . '.</p>'
                  . '<p>Accedi al gestionale per decidere se confermare o non confermare l\'assunzione.</p>'
                  . '<p><a href="' . htmlspecialchars($link) . '">Apri la dashboard</a></p>';
            $text = 'Periodo di prova di ' . $who . ': fine prova ' . $when . '. '
                  . 'Accedi al gestionale per confermare o non confermare l\'assunzione: ' . $link;
            Mailer::send($admin['email'], $admin['name'] ?? '', 'Periodo di prova in scadenza: ' . $who, $html, $text);
        } catch (Throwable $e) {
            error_log('[Probation] email admin fallita: ' . $e->getMessage());
        }
    }

    /** Admin dell'azienda (inclusi eventuali admin globali company_id NULL). */
    private static function companyAdmins(int $companyId): array
    {
        return Database::fetchAll(
            "SELECT id, name, email FROM users
             WHERE role = 'admin' AND is_active = 1 AND (company_id = ? OR company_id IS NULL)",
            [$companyId]
        ) ?: [];
    }

    private static function fmtDate(?string $d): string
    {
        if (empty($d)) return '';
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : (string)$d;
    }
}
