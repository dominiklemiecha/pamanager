<?php
/**
 * Eventi del calendario condivisi multi-utente.
 *
 * Modello:
 *  - events: proprietario singolo, scope tenant
 *  - event_participants: invitati (user_type+user_id) con status pending/accepted/declined
 */
class CalendarEvent
{
    public const VALID_TYPES = ['admin','admin_reparto','employee','accountant','consulente_lavoro'];

    /**
     * Crea un nuovo evento. $data:
     *   owner_type, owner_id, title, description, location, start_at, end_at, color, participants[]
     *   participants[] = [['user_type'=>..., 'user_id'=>...], ...]
     */
    public static function create(array $data): array
    {
        if (empty($data['title']) || empty($data['start_at']) || empty($data['end_at'])) {
            return ['success' => false, 'error' => 'Titolo, inizio e fine sono obbligatori'];
        }
        if (strtotime($data['start_at']) >= strtotime($data['end_at'])) {
            return ['success' => false, 'error' => 'L\'orario di fine deve essere successivo all\'inizio'];
        }
        if (empty($data['owner_type']) || !in_array($data['owner_type'], self::VALID_TYPES, true)) {
            return ['success' => false, 'error' => 'Tipo proprietario non valido'];
        }
        if (empty($data['owner_id'])) {
            return ['success' => false, 'error' => 'Proprietario obbligatorio'];
        }

        $companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;

        try {
            $id = Database::insert('calendar_events', [
                'company_id'  => (int) $companyId,
                'owner_type'  => $data['owner_type'],
                'owner_id'    => (int) $data['owner_id'],
                'title'       => trim($data['title']),
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'location'    => isset($data['location']) ? trim($data['location']) : null,
                'start_at'    => $data['start_at'],
                'end_at'      => $data['end_at'],
                'color'       => $data['color'] ?? null,
                'all_day'     => !empty($data['all_day']) ? 1 : 0,
            ]);

            // Partecipanti (escludi proprietario duplicato)
            if (!empty($data['participants']) && is_array($data['participants'])) {
                foreach ($data['participants'] as $p) {
                    $ut = $p['user_type'] ?? null;
                    $ui = (int) ($p['user_id'] ?? 0);
                    if (!$ut || !$ui || !in_array($ut, self::VALID_TYPES, true)) continue;
                    if ($ut === $data['owner_type'] && $ui === (int) $data['owner_id']) continue;
                    try {
                        Database::insert('calendar_event_participants', [
                            'event_id'  => $id,
                            'user_type' => $ut,
                            'user_id'   => $ui,
                            'status'    => 'pending',
                        ]);
                    } catch (Throwable $e) { /* ignora duplicati */ }
                }
            }

            return ['success' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('CalendarEvent::create failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore creazione evento'];
        }
    }

    /**
     * Aggiorna evento. Permesso solo al proprietario.
     */
    public static function update(int $id, string $callerType, int $callerId, array $data): array
    {
        $ev = self::getById($id);
        if (!$ev) return ['success' => false, 'error' => 'Evento non trovato'];
        if (!self::canManage($ev, $callerType, $callerId)) {
            return ['success' => false, 'error' => 'Non hai i permessi per modificare l\'evento'];
        }
        $update = [];
        foreach (['title','description','location','start_at','end_at','color','all_day'] as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }
        if (!empty($update['start_at']) && !empty($update['end_at']) && strtotime($update['start_at']) >= strtotime($update['end_at'])) {
            return ['success' => false, 'error' => 'Fine deve essere successiva all\'inizio'];
        }
        if (empty($update)) return ['success' => true];
        Database::update('calendar_events', $update, 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Elimina evento (solo proprietario). CASCADE elimina partecipanti.
     */
    public static function delete(int $id, string $callerType, int $callerId): array
    {
        $ev = self::getById($id);
        if (!$ev) return ['success' => false, 'error' => 'Evento non trovato'];
        if (!self::canManage($ev, $callerType, $callerId)) {
            return ['success' => false, 'error' => 'Non hai i permessi per eliminare l\'evento'];
        }
        Database::delete('calendar_events', 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Può gestire (modificare/eliminare) l'evento se è il creatore o è admin/admin_reparto della stessa tenant.
     */
    public static function canManage(array $event, string $callerType, int $callerId): bool
    {
        $isOwner = (strcasecmp((string)$event['owner_type'], $callerType) === 0)
                   && ((int)$event['owner_id'] === $callerId);
        if ($isOwner) return true;
        if (!in_array($callerType, ['admin', 'admin_reparto'], true)) return false;
        if (class_exists('Tenant')) {
            $cid = Tenant::currentCompanyId();
            return (int)$event['company_id'] === (int)$cid;
        }
        return true;
    }

    public static function getById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM calendar_events WHERE id = ?", [$id]) ?: null;
    }

    /**
     * Restituisce tutti gli eventi in cui l'utente e' proprietario o invitato,
     * con start in [from, to] (datetime range).
     */
    public static function getForUserInRange(string $userType, int $userId, string $from, string $to): array
    {
        $companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT e.*, 'owner' AS role_in_event, NULL AS my_status
                FROM calendar_events e
                WHERE e.company_id = ? AND e.owner_type = ? AND e.owner_id = ?
                  AND e.start_at < ? AND e.end_at > ?
                UNION
                SELECT e.*, 'participant' AS role_in_event, ep.status AS my_status
                FROM calendar_events e
                JOIN calendar_event_participants ep ON ep.event_id = e.id
                WHERE e.company_id = ?
                  AND ep.user_type = ? AND ep.user_id = ?
                  AND e.start_at < ? AND e.end_at > ?
                ORDER BY start_at ASC";
        return Database::fetchAll($sql, [
            $companyId, $userType, $userId, $to, $from,
            $companyId, $userType, $userId, $to, $from,
        ]);
    }

    /**
     * Sincronizza i partecipanti di un evento con la lista fornita.
     * - Elimina chi non è più nella lista
     * - Inserisce chi è nuovo (status pending)
     * - Non tocca chi è già presente (mantiene il loro status)
     * Solo owner o admin/admin_reparto della tenant possono farlo.
     */
    public static function syncParticipants(int $eventId, array $list, string $callerType, int $callerId): bool
    {
        $ev = self::getById($eventId);
        if (!$ev) return false;
        if (!self::canManage($ev, $callerType, $callerId)) return false;

        // Normalizza incoming: chiavi "type:id"
        $newKeys = [];
        foreach ($list as $p) {
            $ut = $p['user_type'] ?? null;
            $ui = (int) ($p['user_id'] ?? 0);
            if (!$ut || !$ui || !in_array($ut, self::VALID_TYPES, true)) continue;
            // Esclude l'owner come partecipante (è già "owner")
            if ($ut === $ev['owner_type'] && $ui === (int) $ev['owner_id']) continue;
            $newKeys[$ut . ':' . $ui] = ['user_type' => $ut, 'user_id' => $ui];
        }

        $existing = Database::fetchAll(
            "SELECT id, user_type, user_id FROM calendar_event_participants WHERE event_id = ?",
            [$eventId]
        );
        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row['user_type'] . ':' . $row['user_id']] = (int) $row['id'];
        }

        // Elimina quelli non più presenti
        foreach ($existingKeys as $key => $id) {
            if (!isset($newKeys[$key])) {
                Database::delete('calendar_event_participants', 'id = ?', [$id]);
            }
        }

        // Inserisci i nuovi
        foreach ($newKeys as $key => $p) {
            if (!isset($existingKeys[$key])) {
                try {
                    Database::insert('calendar_event_participants', [
                        'event_id'  => $eventId,
                        'user_type' => $p['user_type'],
                        'user_id'   => $p['user_id'],
                        'status'    => 'pending',
                    ]);
                } catch (Throwable $e) { /* ignora duplicati */ }
            }
        }

        return true;
    }

    /**
     * Partecipanti di un evento, con nome risolto dalla rispettiva tabella.
     */
    public static function getParticipants(int $eventId): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM calendar_event_participants WHERE event_id = ?",
            [$eventId]
        );
        foreach ($rows as &$r) {
            $r['name'] = self::resolveName($r['user_type'], (int) $r['user_id']);
            $r['photo_path'] = self::resolvePhoto($r['user_type'], (int) $r['user_id']);
        }
        return $rows;
    }

    /**
     * Rispondi a un invito.
     */
    public static function respond(int $eventId, string $userType, int $userId, string $status): array
    {
        if (!in_array($status, ['pending','accepted','declined'], true)) {
            return ['success' => false, 'error' => 'Stato non valido'];
        }
        $r = Database::fetchOne(
            "SELECT * FROM calendar_event_participants WHERE event_id = ? AND user_type = ? AND user_id = ?",
            [$eventId, $userType, $userId]
        );
        if (!$r) return ['success' => false, 'error' => 'Non sei invitato a questo evento'];
        Database::update('calendar_event_participants',
            ['status' => $status, 'responded_at' => date('Y-m-d H:i:s')],
            'id = ?', [(int) $r['id']]
        );
        return ['success' => true];
    }

    /**
     * Conflitti per un partecipante candidato: ritorna eventi sovrapposti +
     * eventuali ferie/permessi/malattia approvati che cadono nello stesso intervallo.
     */
    public static function checkConflicts(array $participants, string $start, string $end, ?int $excludeEventId = null): array
    {
        $conflicts = [];
        foreach ($participants as $p) {
            $ut = $p['user_type'] ?? '';
            $ui = (int) ($p['user_id'] ?? 0);
            if (!$ut || !$ui) continue;

            // Eventi sovrapposti
            $sqlEv = "SELECT e.id, e.title, e.start_at, e.end_at
                      FROM calendar_events e
                      LEFT JOIN calendar_event_participants ep ON ep.event_id = e.id AND ep.user_type = ? AND ep.user_id = ?
                      WHERE (e.start_at < ? AND e.end_at > ?)
                        AND ((e.owner_type = ? AND e.owner_id = ?) OR ep.id IS NOT NULL)";
            $paramsEv = [$ut, $ui, $end, $start, $ut, $ui];
            if ($excludeEventId !== null) {
                $sqlEv .= " AND e.id != ?";
                $paramsEv[] = $excludeEventId;
            }
            $overlap = Database::fetchAll($sqlEv, $paramsEv);

            // Ferie/malattia approvate (solo employee)
            $leaves = [];
            if ($ut === 'employee') {
                $leaves = Database::fetchAll(
                    "SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date
                     FROM leave_requests lr
                     WHERE lr.employee_id = ? AND lr.status IN ('approved','pending')
                       AND DATE(?) <= lr.end_date AND DATE(?) >= lr.start_date",
                    [$ui, $end, $start]
                );
            }

            if (!empty($overlap) || !empty($leaves)) {
                $conflicts[] = [
                    'user_type' => $ut, 'user_id' => $ui,
                    'name' => self::resolveName($ut, $ui),
                    'calendar_events' => $overlap,
                    'leaves' => $leaves,
                ];
            }
        }
        return $conflicts;
    }

    /**
     * Risolve il nome leggibile di un utente.
     */
    public static function resolveName(string $type, int $id): string
    {
        if ($type === 'employee') {
            $e = Database::fetchOne("SELECT first_name, last_name FROM employees WHERE id = ?", [$id]);
            return $e ? trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) : 'Dipendente #' . $id;
        }
        $u = Database::fetchOne("SELECT name FROM users WHERE id = ? AND role = ?", [$id, $type]);
        return $u ? $u['name'] : ucfirst($type) . ' #' . $id;
    }

    public static function resolvePhoto(string $type, int $id): ?string
    {
        if ($type === 'employee') {
            $e = Database::fetchOne("SELECT photo_path FROM employees WHERE id = ?", [$id]);
            return $e['photo_path'] ?? null;
        }
        $u = Database::fetchOne("SELECT photo_path FROM users WHERE id = ?", [$id]);
        return $u['photo_path'] ?? null;
    }

    /**
     * Restituisce tutti i contatti invitabili per il creatore (utenti della stessa company).
     * Stesso scope di Chat::getAvailableContacts.
     */
    public static function listInvitableContacts(string $callerType, int $callerId, ?int $departmentId = null): array
    {
        if (class_exists('Chat')) {
            return Chat::getAvailableContacts($callerType, $callerId, $departmentId);
        }
        return [];
    }

    /**
     * Conta inviti pendenti (eventi futuri in cui l'utente è invitato con status=pending).
     * Usato per il badge in sidebar.
     */
    public static function countPendingInvitations(string $userType, int $userId): int
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*)
                 FROM calendar_event_participants ep
                 JOIN calendar_events e ON e.id = ep.event_id
                 WHERE ep.user_type = ? AND ep.user_id = ?
                   AND ep.status = 'pending'
                   AND e.end_at >= NOW()",
                [$userType, $userId]
            );
        } catch (Throwable $err) {
            return 0;
        }
    }
}
