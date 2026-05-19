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
            $id = Database::insert('events', [
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
                        Database::insert('event_participants', [
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
        if ($ev['owner_type'] !== $callerType || (int) $ev['owner_id'] !== $callerId) {
            return ['success' => false, 'error' => 'Solo il creatore puo modificare l\'evento'];
        }
        $update = [];
        foreach (['title','description','location','start_at','end_at','color','all_day'] as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }
        if (!empty($update['start_at']) && !empty($update['end_at']) && strtotime($update['start_at']) >= strtotime($update['end_at'])) {
            return ['success' => false, 'error' => 'Fine deve essere successiva all\'inizio'];
        }
        if (empty($update)) return ['success' => true];
        Database::update('events', $update, 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Elimina evento (solo proprietario). CASCADE elimina partecipanti.
     */
    public static function delete(int $id, string $callerType, int $callerId): array
    {
        $ev = self::getById($id);
        if (!$ev) return ['success' => false, 'error' => 'Evento non trovato'];
        if ($ev['owner_type'] !== $callerType || (int) $ev['owner_id'] !== $callerId) {
            return ['success' => false, 'error' => 'Solo il creatore puo eliminare l\'evento'];
        }
        Database::delete('events', 'id = ?', [$id]);
        return ['success' => true];
    }

    public static function getById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM events WHERE id = ?", [$id]) ?: null;
    }

    /**
     * Restituisce tutti gli eventi in cui l'utente e' proprietario o invitato,
     * con start in [from, to] (datetime range).
     */
    public static function getForUserInRange(string $userType, int $userId, string $from, string $to): array
    {
        $companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT e.*, 'owner' AS role_in_event, NULL AS my_status
                FROM events e
                WHERE e.company_id = ? AND e.owner_type = ? AND e.owner_id = ?
                  AND e.start_at < ? AND e.end_at > ?
                UNION
                SELECT e.*, 'participant' AS role_in_event, ep.status AS my_status
                FROM events e
                JOIN event_participants ep ON ep.event_id = e.id
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
     * Partecipanti di un evento, con nome risolto dalla rispettiva tabella.
     */
    public static function getParticipants(int $eventId): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM event_participants WHERE event_id = ?",
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
            "SELECT * FROM event_participants WHERE event_id = ? AND user_type = ? AND user_id = ?",
            [$eventId, $userType, $userId]
        );
        if (!$r) return ['success' => false, 'error' => 'Non sei invitato a questo evento'];
        Database::update('event_participants',
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
                      FROM events e
                      LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.user_type = ? AND ep.user_id = ?
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
                    'events' => $overlap,
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
}
