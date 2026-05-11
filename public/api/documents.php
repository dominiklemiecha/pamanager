<?php
/**
 * API Documenti
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

// Richiede autenticazione
$auth = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Lista documenti
        if ($auth['user_type'] === 'employee') {
            // Dipendente: solo i propri documenti
            $employeeId = $auth['employee_id'];
        } else {
            // Admin/Commercialista: tutti o per dipendente specifico
            $employeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;
        }

        // Filtri opzionali
        $year = isset($_GET['year']) ? (int) $_GET['year'] : null;
        $month = isset($_GET['month']) ? (int) $_GET['month'] : null;
        $type = $_GET['type'] ?? null;

        // Validazione tipo
        if ($type && !isset(Document::TYPES[$type])) {
            $type = null;
        }

        // Fetch documenti
        if ($employeeId) {
            $documents = Document::getByEmployee($employeeId, $year, $month, $type);
        } else {
            $documents = Document::getAll($year, $month, $type);
        }

        // Formatta risposta
        $result = array_map(function ($doc) {
            return [
                'id' => $doc['id'],
                'employee_id' => $doc['employee_id'],
                'employee_name' => $doc['employee_name'] ?? null,
                'type' => $doc['type'],
                'type_label' => Document::TYPES[$doc['type']] ?? $doc['type'],
                'title' => $doc['title'],
                'description' => $doc['description'],
                'file_name' => $doc['original_name'],
                'file_size' => $doc['file_size'],
                'file_size_formatted' => formatFileSize($doc['file_size']),
                'mime_type' => $doc['mime_type'],
                'month' => $doc['month'],
                'month_name' => getMonthName($doc['month']),
                'year' => $doc['year'],
                'created_at' => $doc['created_at'],
                'download_url' => PUBLIC_URL . '/api/documents.php?download=' . $doc['id']
            ];
        }, $documents);

        apiResponse([
            'success' => true,
            'count' => count($result),
            'documents' => $result
        ]);
        break;

    case 'POST':
        // Upload documento (solo admin/commercialista)
        if ($auth['user_type'] !== 'user') {
            apiError('Non autorizzato', 403);
        }

        // Verifica file
        if (!isset($_FILES['document'])) {
            apiError('File mancante');
        }

        // Dati richiesti
        $employeeId = $_POST['employee_id'] ?? null;
        $type = $_POST['type'] ?? null;
        $month = $_POST['month'] ?? null;
        $year = $_POST['year'] ?? null;

        if (!$employeeId || !$type || !$month || !$year) {
            apiError('Parametri mancanti: employee_id, type, month, year');
        }

        // Simula autenticazione per la classe Document
        Auth::init();
        if (!Auth::isUserLoggedIn()) {
            // Imposta sessione temporanea
            $_SESSION['auth_user'] = [
                'id' => $auth['user_id'],
                'role' => $auth['role'],
                'username' => $auth['username']
            ];
        }

        $result = Document::upload($_FILES['document'], [
            'employee_id' => $employeeId,
            'type' => $type,
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'month' => (int) $month,
            'year' => (int) $year
        ]);

        if ($result['success']) {
            apiResponse([
                'success' => true,
                'document_id' => $result['id'],
                'message' => 'Documento caricato con successo'
            ], 201);
        } else {
            apiError($result['error']);
        }
        break;

    case 'DELETE':
        // Elimina documento (solo admin/commercialista)
        if ($auth['user_type'] !== 'user') {
            apiError('Non autorizzato', 403);
        }

        $docId = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if (!$docId) {
            apiError('ID documento richiesto');
        }

        $result = Document::delete($docId);

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Documento eliminato']);
        } else {
            apiError($result['error']);
        }
        break;

    default:
        apiError('Metodo non consentito', 405);
}

// Gestione download
if (isset($_GET['download'])) {
    $docId = (int) $_GET['download'];

    // Verifica accesso
    $doc = Document::getById($docId);

    if (!$doc) {
        apiError('Documento non trovato', 404);
    }

    // Dipendente può scaricare solo i propri
    if ($auth['user_type'] === 'employee' && $doc['employee_id'] !== $auth['employee_id']) {
        apiError('Non autorizzato', 403);
    }

    if (!file_exists($doc['file_path'])) {
        apiError('File non trovato', 404);
    }

    // Sanitizza nome file per header (previene header injection)
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name']);

    // Invia file con headers sicuri
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($doc['file_path']));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    readfile($doc['file_path']);
    exit;
}
