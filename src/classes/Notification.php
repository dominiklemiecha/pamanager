<?php
/**
 * Classe Notification - Gestione notifiche
 * PAManager - Comune
 */

class Notification
{
    /**
     * Crea una nuova notifica
     */
    public static function create(array $data): array
    {
        if (empty($data['recipient_type']) || empty($data['recipient_id'])) {
            return ['success' => false, 'error' => 'Destinatario obbligatorio'];
        }

        if (empty($data['type'])) {
            return ['success' => false, 'error' => 'Tipo notifica obbligatorio'];
        }

        if (empty($data['title'])) {
            return ['success' => false, 'error' => 'Titolo obbligatorio'];
        }

        try {
            $id = Database::insert('notifications', [
                'company_id'     => class_exists('Tenant') ? Tenant::currentCompanyId() : 1,
                'recipient_type' => $data['recipient_type'],
                'recipient_id' => (int) $data['recipient_id'],
                'type' => $data['type'],
                'title' => $data['title'],
                'message' => $data['message'] ?? '',
                'link' => $data['link'] ?? null,
                // int 0/1, NON boolean: PDO in strict mode converte false in '' e MySQL la rifiuta
                'is_read' => 0
            ]);

            // Invia notifica push se la classe esiste
            if (class_exists('PushNotification')) {
                try {
                    PushNotification::sendToUser(
                        $data['recipient_type'],
                        (int) $data['recipient_id'],
                        [
                            'title' => $data['title'],
                            'body' => $data['message'] ?? '',
                            'url' => $data['link'] ?? '/',
                            'tag' => 'notification-' . $id
                        ]
                    );
                } catch (Exception $e) {
                    error_log('Push notification failed: ' . $e->getMessage());
                }
            }

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            error_log('Notification creation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore nella creazione della notifica'];
        }
    }

    /**
     * Ottiene notifiche per un utente
     */
    public static function getByUser(string $userType, int $userId, bool $unreadOnly = false, int $limit = 20): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT * FROM notifications
                WHERE company_id = ? AND recipient_type = ? AND recipient_id = ?";
        $params = [$cid, $userType, $userId];

        if ($unreadOnly) {
            $sql .= " AND is_read = FALSE";
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        return Database::fetchAll($sql, $params);
    }

    /**
     * Conta notifiche non lette
     */
    public static function countUnread(string $userType, int $userId): int
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM notifications
             WHERE company_id = ? AND recipient_type = ? AND recipient_id = ? AND is_read = FALSE",
            [$cid, $userType, $userId]
        );
    }

    /**
     * Marca una notifica come letta
     */
    public static function markAsRead(int $id): bool
    {
        try {
            Database::update('notifications', ['is_read' => 1], 'id = ?', [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Marca tutte le notifiche come lette
     */
    public static function markAllAsRead(string $userType, int $userId): bool
    {
        try {
            Database::query(
                "UPDATE notifications SET is_read = TRUE
                 WHERE recipient_type = ? AND recipient_id = ? AND is_read = FALSE",
                [$userType, $userId]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Elimina una notifica
     */
    public static function delete(int $id): bool
    {
        try {
            Database::delete('notifications', 'id = ?', [$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Elimina notifiche vecchie
     */
    public static function deleteOld(int $daysOld = 30): int
    {
        try {
            return Database::query(
                "DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$daysOld]
            )->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Invia notifica a tutti i dipendenti di un reparto
     */
    public static function notifyDepartment(int $departmentId, string $type, string $title, string $message, ?string $link = null): int
    {
        $employees = Department::getEmployees($departmentId, true);
        $count = 0;

        foreach ($employees as $emp) {
            $result = self::create([
                'recipient_type' => 'employee',
                'recipient_id' => $emp['id'],
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);

            if ($result['success']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Invia notifica a tutti i dipendenti
     */
    public static function notifyAllEmployees(string $type, string $title, string $message, ?string $link = null): int
    {
        $employees = Employee::getAll(true);
        $count = 0;

        foreach ($employees as $emp) {
            $result = self::create([
                'recipient_type' => 'employee',
                'recipient_id' => $emp['id'],
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);

            if ($result['success']) {
                $count++;
            }
        }

        return $count;
    }
}
