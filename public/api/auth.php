<?php
/**
 * API Autenticazione
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metodo non consentito', 405);
}

// Ottieni dati JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    apiError('Dati JSON non validi');
}

$action = $input['action'] ?? 'login';

switch ($action) {
    case 'login':
        // Login standard (admin/commercialista)
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            apiError('Username e password richiesti');
        }

        // Rate limiting
        $rateLimitKey = 'api_login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!checkRateLimit($rateLimitKey, 5, 300)) {
            apiError('Troppi tentativi', 429);
        }

        $user = Database::fetchOne(
            "SELECT * FROM users WHERE username = ? AND is_active = TRUE",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            apiError('Credenziali non valide', 401);
        }

        // Verifica blocco
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            apiError('Account bloccato temporaneamente', 403);
        }

        // Aggiorna ultimo login
        Database::update('users', ['last_login' => date('Y-m-d H:i:s'), 'failed_attempts' => 0], 'id = ?', [$user['id']]);

        // Genera token
        $token = JWT::generate([
            'user_id' => $user['id'],
            'user_type' => 'user',
            'role' => $user['role'],
            'username' => $user['username']
        ]);

        apiResponse([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'role' => $user['role']
            ]
        ]);
        break;

    case 'login_employee':
        // Login dipendente con username/password
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            apiError('Username e password richiesti');
        }

        // Rate limiting
        $rateLimitKey = 'api_login_employee_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!checkRateLimit($rateLimitKey, 5, 300)) {
            apiError('Troppi tentativi', 429);
        }

        $employee = Database::fetchOne(
            "SELECT * FROM employees WHERE username = ? AND is_active = TRUE",
            [$username]
        );

        if (!$employee) {
            apiError('Credenziali non valide', 401);
        }

        // Verifica blocco
        if ($employee['locked_until'] && strtotime($employee['locked_until']) > time()) {
            apiError('Account bloccato temporaneamente', 403);
        }

        // Verifica password
        if (!password_verify($password, $employee['password_hash'])) {
            // Incrementa tentativi falliti
            $attempts = ($employee['failed_attempts'] ?? 0) + 1;
            $updateData = ['failed_attempts' => $attempts];
            if ($attempts >= 5) {
                $updateData['locked_until'] = date('Y-m-d H:i:s', time() + 900);
            }
            Database::update('employees', $updateData, 'id = ?', [$employee['id']]);
            apiError('Credenziali non valide', 401);
        }

        // Aggiorna ultimo login e reset tentativi
        Database::update('employees', [
            'last_login' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
            'locked_until' => null
        ], 'id = ?', [$employee['id']]);

        // Genera token
        $token = JWT::generate([
            'employee_id' => $employee['id'],
            'user_type' => 'employee',
            'username' => $employee['username'],
            'fiscal_code' => $employee['fiscal_code']
        ]);

        apiResponse([
            'success' => true,
            'token' => $token,
            'employee' => [
                'id' => $employee['id'],
                'username' => $employee['username'],
                'fiscal_code' => $employee['fiscal_code'],
                'first_name' => $employee['first_name'],
                'last_name' => $employee['last_name'],
                'email' => $employee['email']
            ]
        ]);
        break;

    case 'verify':
        // Verifica token
        $token = $input['token'] ?? JWT::getTokenFromHeader();

        if (!$token) {
            apiError('Token mancante');
        }

        $payload = JWT::verify($token);

        if (!$payload) {
            apiError('Token non valido o scaduto', 401);
        }

        apiResponse([
            'valid' => true,
            'payload' => $payload,
            'expires_in' => $payload['exp'] - time()
        ]);
        break;

    case 'refresh':
        // Rinnova token
        $auth = requireAuth();

        $newToken = JWT::generate([
            'user_id' => $auth['user_id'] ?? null,
            'employee_id' => $auth['employee_id'] ?? null,
            'user_type' => $auth['user_type'],
            'role' => $auth['role'] ?? null,
            'username' => $auth['username'] ?? null,
            'fiscal_code' => $auth['fiscal_code'] ?? null
        ]);

        apiResponse([
            'success' => true,
            'token' => $newToken
        ]);
        break;

    default:
        apiError('Azione non valida');
}
