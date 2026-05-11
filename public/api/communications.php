<?php
/**
 * API Comunicazioni
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

// Richiede autenticazione
$auth = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Lista comunicazioni
        if (isset($_GET['id'])) {
            // Singola comunicazione
            $commId = (int) $_GET['id'];
            $communication = Communication::getById($commId);

            if (!$communication) {
                apiError('Comunicazione non trovata', 404);
            }

            // Verifica visibilità
            $now = date('Y-m-d');
            if (!$communication['is_published'] ||
                $communication['publish_date'] > $now ||
                ($communication['expire_date'] && $communication['expire_date'] < $now)) {

                // Solo admin può vedere bozze/scadute
                if ($auth['user_type'] === 'employee') {
                    apiError('Comunicazione non disponibile', 404);
                }
            }

            // Segna come letta se dipendente
            if ($auth['user_type'] === 'employee') {
                Communication::markAsRead($commId, $auth['employee_id']);
            }

            apiResponse([
                'success' => true,
                'communication' => [
                    'id' => $communication['id'],
                    'title' => $communication['title'],
                    'content' => $communication['content'],
                    'priority' => $communication['priority'],
                    'priority_label' => Communication::PRIORITIES[$communication['priority']] ?? $communication['priority'],
                    'is_published' => (bool) $communication['is_published'],
                    'publish_date' => $communication['publish_date'],
                    'expire_date' => $communication['expire_date'],
                    'author' => $communication['author_name'],
                    'created_at' => $communication['created_at'],
                    'has_attachment' => !empty($communication['attachment_path'])
                ]
            ]);
        } else {
            // Lista comunicazioni
            if ($auth['user_type'] === 'employee') {
                // Dipendente: solo attive
                $communications = Communication::getActive($auth['employee_id']);
            } else {
                // Admin: tutte
                $includePast = isset($_GET['include_past']);
                $communications = Communication::getAll($includePast);
            }

            $result = array_map(function ($comm) use ($auth) {
                $item = [
                    'id' => $comm['id'],
                    'title' => $comm['title'],
                    'content_preview' => substr(strip_tags($comm['content']), 0, 200),
                    'priority' => $comm['priority'],
                    'priority_label' => Communication::PRIORITIES[$comm['priority']] ?? $comm['priority'],
                    'is_published' => (bool) $comm['is_published'],
                    'publish_date' => $comm['publish_date'],
                    'expire_date' => $comm['expire_date'],
                    'author' => $comm['author_name'],
                    'created_at' => $comm['created_at']
                ];

                if ($auth['user_type'] === 'employee') {
                    $item['is_read'] = !empty($comm['is_read']);
                } else {
                    $item['read_count'] = $comm['read_count'] ?? 0;
                    $item['total_employees'] = $comm['total_employees'] ?? 0;
                }

                return $item;
            }, $communications);

            // Conta non lette per dipendente
            $unreadCount = null;
            if ($auth['user_type'] === 'employee') {
                $unreadCount = Communication::countUnread($auth['employee_id']);
            }

            apiResponse([
                'success' => true,
                'count' => count($result),
                'unread_count' => $unreadCount,
                'communications' => $result
            ]);
        }
        break;

    case 'POST':
        // Crea comunicazione (solo admin)
        if ($auth['user_type'] !== 'user' || $auth['role'] !== 'admin') {
            apiError('Non autorizzato', 403);
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            apiError('Dati JSON non validi');
        }

        // Simula autenticazione
        Auth::init();
        if (!Auth::isUserLoggedIn()) {
            $_SESSION['auth_user'] = [
                'id' => $auth['user_id'],
                'role' => $auth['role'],
                'username' => $auth['username']
            ];
        }

        $result = Communication::create([
            'title' => $input['title'] ?? '',
            'content' => $input['content'] ?? '',
            'priority' => $input['priority'] ?? 'normal',
            'is_published' => $input['is_published'] ?? true,
            'publish_date' => $input['publish_date'] ?? date('Y-m-d'),
            'expire_date' => $input['expire_date'] ?? null
        ]);

        if ($result['success']) {
            apiResponse([
                'success' => true,
                'communication_id' => $result['id'],
                'message' => 'Comunicazione creata con successo'
            ], 201);
        } else {
            apiError($result['error']);
        }
        break;

    case 'PUT':
        // Aggiorna comunicazione (solo admin)
        if ($auth['user_type'] !== 'user' || $auth['role'] !== 'admin') {
            apiError('Non autorizzato', 403);
        }

        $commId = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if (!$commId) {
            apiError('ID comunicazione richiesto');
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            apiError('Dati JSON non validi');
        }

        $result = Communication::update($commId, $input);

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Comunicazione aggiornata']);
        } else {
            apiError($result['error']);
        }
        break;

    case 'DELETE':
        // Elimina comunicazione (solo admin)
        if ($auth['user_type'] !== 'user' || $auth['role'] !== 'admin') {
            apiError('Non autorizzato', 403);
        }

        $commId = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if (!$commId) {
            apiError('ID comunicazione richiesto');
        }

        $result = Communication::delete($commId);

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Comunicazione eliminata']);
        } else {
            apiError($result['error']);
        }
        break;

    default:
        apiError('Metodo non consentito', 405);
}
