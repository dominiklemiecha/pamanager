<?php
/**
 * Partial unificato per la chat (admin, accountant, consulente_lavoro, admin_reparto, employee).
 *
 * Variabili attese dal file chiamante:
 *   $userType (string) — tipo utente
 *   $userId (int)
 *   $user (array, opzionale) — solo per departmentId
 *   $departmentId (int|null, opzionale)
 */

if (!isset($userType, $userId)) {
    throw new RuntimeException('_chat-page.inc.php: $userType e $userId sono obbligatori');
}

$departmentId = $departmentId ?? ($user['department_id'] ?? null);

// ---- Download allegato (GET, prima di session_*) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_attachment'])) {
    $result = Chat::getAttachment((int)$_GET['download_attachment'], $userType, $userId);
    if (!$result['success']) {
        http_response_code(403);
        exit(htmlspecialchars($result['error']));
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $result['filename']);
    if (function_exists('setDownloadHeaders')) {
        setDownloadHeaders($safeName, $result['mime'], filesize($result['file_path']));
    } else {
        header('Content-Type: ' . $result['mime']);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($result['file_path']));
    }
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

// ---- POST AJAX ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'send':
                $convId = (int) ($_POST['conversation_id'] ?? 0);
                $msg    = $_POST['message'] ?? '';

                // Sicurezza: solo i partecipanti possono inviare nella conversazione
                if (!Chat::isParticipant($convId, $userType, $userId)) {
                    echo json_encode(['success' => false, 'error' => 'Accesso non autorizzato']);
                    exit;
                }

                $attachPath = null;
                $attachName = null;
                $attachSize = null;
                $attachMime = null;

                if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = Chat::handleAttachmentUpload($_FILES['attachment'], $convId);
                    if (!$upload['success']) {
                        echo json_encode(['success' => false, 'error' => $upload['error']]);
                        exit;
                    }
                    $attachPath = $upload['path'];
                    $attachName = $upload['name'];
                    $attachSize = $upload['size'];
                    $attachMime = $upload['mime'];
                }

                // Messaggio interno (solo staff admin/consulente, invisibile al dipendente)
                $isInternal = !empty($_POST['is_internal']) && in_array($userType, ['admin', 'consulente_lavoro'], true);
                $result = Chat::sendMessage($convId, $userType, $userId, $msg, $attachPath, $attachName, $attachSize, $attachMime, $isInternal);
                echo json_encode($result);
                exit;

            case 'get_messages':
                $convId = (int) ($_POST['conversation_id'] ?? 0);
                // Sicurezza: SOLO i partecipanti possono leggere la conversazione
                if (!Chat::isParticipant($convId, $userType, $userId)) {
                    echo json_encode(['success' => false, 'error' => 'Accesso non autorizzato']);
                    exit;
                }
                Chat::markAsRead($convId, $userType, $userId);
                // Il dipendente non riceve mai i messaggi interni dello staff
                $messages = Chat::getMessages($convId, 50, 0, $userType !== 'employee');
                $messages = array_reverse($messages);
                // Nome mittente per le chat a 3 (cache per non ripetere query)
                $__nameCache = [];
                foreach ($messages as &$__m) {
                    $__k = $__m['sender_type'] . ':' . $__m['sender_id'];
                    if (!isset($__nameCache[$__k])) {
                        $__nameCache[$__k] = Chat::getParticipantName($__m['sender_type'], (int)$__m['sender_id']);
                    }
                    $__m['sender_name'] = $__nameCache[$__k];
                }
                unset($__m);
                echo json_encode(['success' => true, 'messages' => $messages]);
                exit;

            case 'add_participant':
                $convId  = (int) ($_POST['conversation_id'] ?? 0);
                $newType = $_POST['new_type'] ?? '';
                $newId   = (int) ($_POST['new_id'] ?? 0);
                $result = Chat::addStaffParticipant($convId, $userType, $userId, $newType, $newId);
                echo json_encode($result);
                exit;

            case 'remove_participant':
                $convId  = (int) ($_POST['conversation_id'] ?? 0);
                $remType = $_POST['rem_type'] ?? '';
                $remId   = (int) ($_POST['rem_id'] ?? 0);
                $result = Chat::removeStaffParticipant($convId, $userType, $userId, $remType, $remId);
                echo json_encode($result);
                exit;

            case 'start_conversation':
                $otherType = $_POST['other_type'] ?? '';
                $otherId   = (int) ($_POST['other_id'] ?? 0);
                if (!Chat::canContact($userType, $userId, $departmentId, $otherType, $otherId)) {
                    echo json_encode(['success' => false, 'error' => 'Non puoi contattare questo utente']);
                    exit;
                }
                $result = Chat::getOrCreateConversation($userType, $userId, $otherType, $otherId);
                echo json_encode($result);
                exit;

            default:
                echo json_encode(['success' => false, 'error' => 'Azione non valida']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        exit;
    }
}

// ---- Avvia conversazione con admin via querystring (?with_admin=1) ----
if (!empty($_GET['with_admin']) && in_array($userType, ['employee','admin_reparto'], true)) {
    try {
        $__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $__admin = Database::fetchOne(
            "SELECT u.id FROM users u
             LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
             WHERE u.role = 'admin' AND u.is_active = 1
             ORDER BY (uc.user_id IS NOT NULL) DESC, u.id ASC
             LIMIT 1",
            [$__cid]
        );
        if ($__admin && Chat::canContact($userType, $userId, $departmentId, 'admin', (int)$__admin['id'])) {
            $__r = Chat::getOrCreateConversation($userType, $userId, 'admin', (int)$__admin['id']);
            if (!empty($__r['success']) && !empty($__r['conversation']['id'])) {
                header('Location: ?conv=' . (int)$__r['conversation']['id']);
                exit;
            }
        }
    } catch (Throwable $__e) {}
}

// ---- Render ----
$conversations    = Chat::getConversations($userType, $userId);
$contacts         = Chat::getAvailableContacts($userType, $userId, $departmentId);
$selectedConvId   = (int) ($_GET['conv'] ?? 0);
$selectedMessages = [];
$selectedConv     = null;
if ($selectedConvId) {
    // Sicurezza: SOLO i partecipanti possono caricare la conversazione
    if (!Chat::isParticipant($selectedConvId, $userType, $userId)) {
        $selectedConvId = 0;
    } else {
        Chat::markAsRead($selectedConvId, $userType, $userId);
        // Il dipendente non vede i messaggi interni dello staff
        $selectedMessages = array_reverse(Chat::getMessages($selectedConvId, 50, 0, $userType !== 'employee'));
        foreach ($conversations as $c) {
            if ((int)$c['id'] === $selectedConvId) { $selectedConv = $c; break; }
        }
    }
}

// ---- Chat di gruppo: contesto della conversazione selezionata ----
$__isStaff = in_array($userType, ['admin', 'consulente_lavoro'], true);
$__convHasEmployee = false;
$__extraParticipants = [];    // partecipanti oltre ai 2 originali, con nome
$__isThreeWay = false;        // true se la chat ha partecipanti extra
$__inviteCandidates = [];     // staff invitabile (admin + consulenti, esclusi i presenti)
if ($selectedConv) {
    $__parts = Chat::participantsOf($selectedConv);
    foreach ($__parts as $__p) {
        if ($__p['type'] === 'employee') $__convHasEmployee = true;
    }
    foreach (array_slice($__parts, 2) as $__p) {
        $__extraParticipants[] = $__p + ['name' => Chat::getParticipantName($__p['type'], $__p['id'])];
    }
    $__isThreeWay = !empty($__extraParticipants);
    if ($__isStaff && $__convHasEmployee) {
        $__present = [];
        foreach ($__parts as $__p) $__present[$__p['type'] . ':' . $__p['id']] = true;
        foreach (['admin', 'consulente_lavoro'] as $__g) {
            foreach (($contacts[$__g] ?? []) as $__u) {
                $__k = $__g . ':' . (int) $__u['id'];
                if (isset($__present[$__k])) continue;
                $__inviteCandidates[] = ['type' => $__g, 'id' => (int) $__u['id'], 'name' => $__u['name'] ?? ('Utente #' . $__u['id'])];
            }
        }
    }
}
// Nomi mittente per il render PHP iniziale (chat a 3)
$__senderNames = [];
foreach ($selectedMessages as $__m) {
    $__k = $__m['sender_type'] . ':' . $__m['sender_id'];
    if (!isset($__senderNames[$__k])) {
        $__senderNames[$__k] = Chat::getParticipantName($__m['sender_type'], (int) $__m['sender_id']);
    }
}

// Helper per label gruppo (plurali per le sezioni della sidebar)
$groupLabels = [
    'admin'             => 'Amministratori',
    'admin_reparto'     => 'Admin reparto',
    'accountant'        => 'Commercialisti',
    'consulente_lavoro' => 'Consulenti del lavoro',
    'employee'          => 'Dipendenti',
];
// Label singolari (header conversazione, info panel)
$singularLabels = [
    'admin'             => 'Amministratore',
    'admin_reparto'     => 'Admin reparto',
    'accountant'        => 'Commercialista',
    'consulente_lavoro' => 'Consulente del lavoro',
    'employee'          => 'Dipendente',
];

// Set ID dei partecipanti gia' in conversazione (per evidenziare nella lista contatti)
$activeContactIds = [];
foreach ($conversations as $c) {
    $activeContactIds[$c['other_type'] . ':' . $c['other_id']] = true;
}

$pageTitle = $pageTitle ?? 'Chat';
$__chatLayout = $userType === 'employee' ? 'employee' : 'admin';
if ($__chatLayout === 'admin' && $userType === 'admin_reparto') {
    $__chatLayout = 'admin-reparto';
}

// File condivisi nella conversazione selezionata (per il pannello info)
$sharedFiles = [];
if ($selectedConvId) {
    try {
        // Gli allegati dei messaggi interni non compaiono al dipendente
        $__sfInternal = $userType === 'employee' ? ' AND is_internal = 0' : '';
        $sharedFiles = Database::fetchAll(
            "SELECT id, attachment_name, attachment_size, attachment_mime, created_at
             FROM chat_messages
             WHERE conversation_id = ? AND attachment_path IS NOT NULL AND attachment_path <> ''$__sfInternal
             ORDER BY created_at DESC LIMIT 20",
            [$selectedConvId]
        );
    } catch (Exception $e) { $sharedFiles = []; }
}

include __DIR__ . '/header-' . $__chatLayout . '.php';
?>

<style>
/* ============ CHAT SHELL (mockup design) ============ */
:root {
    --chat-primary: #0b3aa4;
    --chat-primary-dark: #0b3aa4;
    --chat-primary-50: rgba(11,58,164,0.06);
    --chat-primary-100: rgba(11,58,164,0.10);
    --chat-ink: #0f172a;
    --chat-ink-2: #475569;
    --chat-muted: #94a3b8;
    --chat-border: #e2e8f0;
    --chat-bg: #f8fafc;
    --chat-success: #16a34a;
}

.chat-shell {
    display: grid;
    grid-template-columns: 320px 1fr 280px;
    gap: 16px;
    height: calc(100vh - 180px);
    min-height: 500px;
}
@media (max-width: 1180px) {
    .chat-shell { grid-template-columns: 300px 1fr; }
    .chat-info-panel { display: none; }
}
@media (max-width: 820px) {
    .chat-shell { grid-template-columns: minmax(0, 1fr); gap: 0; height: calc(100dvh - 140px); }
    .chat-shell:not(.has-active) .chat-thread { display: none; }
    .chat-shell.has-active .chat-sidebar-panel { display: none; }

    /* ===== Modalita' "WhatsApp-style" per chat con conversazione attiva =====
       - app-content senza padding/margini
       - bottom-nav nascosta (la chat occupa fino al fondo)
       - chat-shell ha altezza 100dvh meno header: dvh si adatta alla tastiera */
    body:has(.chat-shell.has-active) .app-content { padding: 0 !important; max-width: none !important; }
    body:has(.chat-shell.has-active) .bottom-nav,
    body:has(.chat-shell.has-active) .powered { display: none !important; }

    /* Lock totale dello scroll body/html sotto la chat attiva: la pagina non scrolla,
       solo .thread-messages scorre verticalmente.
       Uso 100svh (small viewport height) invece di 100dvh: svh non cambia quando si apre/chiude
       la tastiera, quindi la pagina NON si ridimensiona. iOS gestisce il keyboard via visual
       viewport (i fixed restano relativi al layout viewport, niente shift della pagina). */
    html:has(.chat-shell.has-active),
    body:has(.chat-shell.has-active) {
        height: 100svh;
        max-height: 100svh;
        overflow: hidden !important;
        overscroll-behavior: contain;
        position: relative;
    }
    body:has(.chat-shell.has-active) .app,
    body:has(.chat-shell.has-active) .app-main {
        height: 100svh;
        max-height: 100svh;
        overflow: hidden;
        padding-bottom: 0 !important;
    }
    body.employee-body:has(.chat-shell.has-active) .app-main {
        padding-bottom: 0 !important;
    }

    /* App-header e thread fissati al layout viewport: NON si muovono quando la tastiera
       apre/chiude (a differenza dei layout flow-based che fluttuano con dvh) */
    body:has(.chat-shell.has-active) .app-header {
        position: fixed !important;
        top: 0; left: 0; right: 0;
        z-index: 100;
    }

    .chat-shell.has-active {
        position: fixed;
        top: var(--header-h, 60px);
        left: 0; right: 0; bottom: 0;
        height: auto;
        margin: 0;
        border-radius: 0;
        overflow: hidden;
    }
    .chat-shell.has-active .chat-thread {
        height: 100%;
    }
    .chat-shell.has-active .chat-panel {
        border-radius: 0;
        border-left: none;
        border-right: none;
        border-bottom: none;
        height: 100%;
    }
}

.chat-panel {
    background: white;
    border: 1px solid var(--chat-border);
    border-radius: 14px;
    display: flex; flex-direction: column;
    overflow: hidden;
}

/* ============ SIDEBAR ============ */
.chat-sidebar-panel .chat-search {
    padding: 12px;
    border-bottom: 1px solid var(--chat-border);
    position: relative;
    flex-shrink: 0;
}
.chat-search svg {
    position: absolute; left: 22px; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--chat-muted);
    pointer-events: none;
}
.chat-search input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    background: var(--chat-bg);
    border: 1px solid var(--chat-border);
    border-radius: 8px;
    font-family: inherit; font-size: 13px;
    color: var(--chat-ink);
    transition: all .12s ease;
}
.chat-search input:focus {
    outline: none; background: white;
    border-color: var(--chat-primary);
    box-shadow: 0 0 0 3px var(--chat-primary-100);
}
.chat-list { flex: 1; overflow-y: auto; }
.chat-group-title {
    font-size: 10px; color: var(--chat-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    padding: 14px 16px 6px;
    font-weight: 700;
    background: white;
    position: sticky; top: 0; z-index: 1;
    display: flex; justify-content: space-between; align-items: center;
}
.chat-group-title .count { color: var(--chat-muted); font-weight: 600; }
.contact-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background .12s ease;
    text-decoration: none; color: inherit;
}
.contact-item:hover { background: var(--chat-bg); }
.contact-item.active {
    background: var(--chat-primary-50);
    border-left: 3px solid var(--chat-primary);
    padding-left: 13px;
}
.contact-item .av {
    position: relative;
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--chat-primary), var(--chat-primary-dark));
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 13px;
    flex-shrink: 0;
    text-transform: uppercase;
    overflow: hidden;
}
.contact-item .av img { width: 100%; height: 100%; object-fit: cover; }
.contact-item .av .online-dot {
    width: 10px; height: 10px;
    background: var(--chat-success);
    border: 2px solid white;
    border-radius: 50%;
    position: absolute; right: -2px; bottom: -2px;
}
.contact-info { flex: 1; min-width: 0; }
.contact-info .n {
    font-size: 13px; font-weight: 600; color: var(--chat-ink);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.contact-info .p {
    font-size: 12px; color: var(--chat-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-top: 2px;
}
.contact-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.contact-meta .t { font-size: 10px; color: var(--chat-muted); }
.contact-meta .u {
    background: var(--chat-primary); color: white;
    font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 999px;
    min-width: 18px; text-align: center;
}

/* ============ THREAD ============ */
.chat-thread { display: flex; flex-direction: column; }
.thread-header {
    padding: 12px 18px;
    border-bottom: 1px solid var(--chat-border);
    display: flex; align-items: center; gap: 12px;
    flex-shrink: 0;
}
.thread-header .back-btn {
    display: none;
    align-items: center; justify-content: center;
    gap: 4px;
    min-width: 40px; height: 40px;
    padding: 0 8px;
    border: none;
    background: rgba(11,58,164,0.08);
    color: #0b3aa4 !important;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none !important;
    font-size: 13px; font-weight: 600;
}
.thread-header .back-btn:hover,
.thread-header .back-btn:active {
    background: rgba(11,58,164,0.15);
    color: #0b3aa4 !important;
}
.thread-header .back-btn svg {
    width: 22px; height: 22px;
    color: inherit; stroke: currentColor;
    flex-shrink: 0;
}
.thread-header .av {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--chat-primary), var(--chat-primary-dark));
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 14px;
    flex-shrink: 0; text-transform: uppercase;
    overflow: hidden;
}
.thread-header .av img { width: 100%; height: 100%; object-fit: cover; }
.thread-header .info { flex: 1; min-width: 0; }
.thread-header .info .n {
    font-family: 'Host Grotesk','Inter',sans-serif;
    font-size: 15px; font-weight: 700;
    color: var(--chat-ink); letter-spacing: -0.01em;
}
.thread-header .info .s {
    font-size: 11px; color: var(--chat-success);
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 2px;
}
.thread-header .info .s::before {
    content: ""; width: 7px; height: 7px;
    background: var(--chat-success); border-radius: 50%;
}
.thread-header .info .s.offline { color: var(--chat-muted); }
.thread-header .info .s.offline::before { background: var(--chat-muted); }
.thread-header .acts { display: flex; gap: 4px; }
.thread-header .acts button {
    width: 34px; height: 34px;
    border-radius: 8px;
    background: transparent; border: 1px solid transparent;
    color: var(--chat-ink-2); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .12s ease;
}
.thread-header .acts button:hover {
    background: var(--chat-primary-50);
    color: var(--chat-primary);
}
.thread-header .acts button svg { width: 16px; height: 16px; }

/* Messages */
.thread-messages {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior-x: contain;
    touch-action: pan-y;
    padding: 18px;
    background: var(--chat-bg);
    display: flex; flex-direction: column; gap: 10px;
    min-width: 0;
}
.day-divider {
    text-align: center; font-size: 11px;
    color: var(--chat-muted); padding: 8px 0;
    position: relative; margin: 4px 0;
}
.day-divider::before, .day-divider::after {
    content: ""; position: absolute; top: 50%;
    width: 30%; height: 1px; background: var(--chat-border);
}
.day-divider::before { left: 5%; }
.day-divider::after { right: 5%; }
.day-divider span {
    background: var(--chat-bg); padding: 0 12px;
    position: relative; z-index: 1;
    font-weight: 600;
}

.msg-group {
    display: flex; flex-direction: column; gap: 4px;
    max-width: 70%;
}
.msg-group.sent { align-self: flex-end; align-items: flex-end; }
.msg-group.received { align-self: flex-start; align-items: flex-start; }
.msg {
    padding: 9px 14px;
    border-radius: 14px;
    font-size: 13.5px;
    line-height: 1.5;
    word-wrap: break-word;
}
.msg-group.sent .msg {
    background: var(--chat-primary); color: white;
    border-bottom-right-radius: 4px;
}
.msg-group.received .msg {
    background: white; color: var(--chat-ink);
    border: 1px solid var(--chat-border);
    border-bottom-left-radius: 4px;
}
.msg-time {
    font-size: 10px; color: var(--chat-muted);
    padding: 0 8px;
}

/* Attachments inline in messages
   max-width cappato al minore tra 320px e (viewport - 100px) per evitare
   che il filename in nowrap spinga il parent oltre il viewport mobile */
.msg-attach {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr);
    align-items: center;
    gap: 10px;
    padding: 10px 12px; margin-top: 4px;
    background: rgba(255,255,255,0.18);
    border-radius: 10px;
    color: inherit; text-decoration: none;
    font-size: 13px;
    transition: filter .12s ease;
    max-width: min(320px, calc(100vw - 100px));
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}
.msg-group.received .msg-attach {
    background: var(--chat-primary-50);
    color: var(--chat-primary-dark);
}
.msg-attach:hover { filter: brightness(1.08); text-decoration: none; }
.msg-attach .file-ic {
    width: 36px; height: 36px;
    background: rgba(255,255,255,0.25);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.msg-group.received .msg-attach .file-ic {
    background: white; color: var(--chat-primary);
}
.msg-attach .file-ic svg { width: 18px; height: 18px; }
.msg-attach .file-meta { min-width: 0; flex: 1; }
.msg-attach .file-meta .name {
    font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: block;
}
.msg-attach .file-meta .size {
    font-size: 11px; opacity: 0.8; margin-top: 2px;
    display: block;
}

/* Composer */
.composer {
    padding: 12px 16px;
    border-top: 1px solid var(--chat-border);
    display: flex; align-items: flex-end; gap: 8px;
    flex-shrink: 0;
    background: white;
}
.composer .input-wrap {
    flex: 1;
    background: var(--chat-bg);
    border: 1px solid var(--chat-border);
    border-radius: 14px;
    padding: 8px 12px;
    transition: all .12s ease;
    display: flex; flex-direction: column; gap: 4px;
}
.composer .input-wrap:focus-within {
    background: white;
    border-color: var(--chat-primary);
    box-shadow: 0 0 0 3px var(--chat-primary-100);
}
.composer textarea {
    width: 100%;
    border: none; background: transparent;
    resize: none;
    font-family: inherit; font-size: 13.5px;
    color: var(--chat-ink); line-height: 1.5;
    min-height: 22px; max-height: 140px;
    outline: none;
}
.composer .tools {
    display: flex; gap: 2px;
}
.tool-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    background: transparent; border: none;
    color: var(--chat-muted);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .12s ease;
}
.tool-btn:hover {
    background: var(--chat-primary-100);
    color: var(--chat-primary);
}
.tool-btn svg { width: 16px; height: 16px; }
.send-btn {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: var(--chat-primary); color: white;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(11,58,164,0.30);
    transition: all .12s ease;
    flex-shrink: 0;
}
.send-btn:hover {
    background: var(--chat-primary-dark);
    transform: scale(1.05);
}
.send-btn:disabled {
    background: var(--chat-muted); box-shadow: none;
    transform: none; cursor: not-allowed;
}
.send-btn svg { width: 18px; height: 18px; }

.attach-preview {
    display: none;
    margin: 0 16px 8px;
    padding: 8px 12px;
    background: var(--chat-primary-50);
    border: 1px solid var(--chat-primary-100);
    border-radius: 8px;
    font-size: 12px;
    align-items: center; gap: 8px;
    color: var(--chat-primary-dark);
}
.attach-preview.show { display: flex; }
.attach-preview button {
    margin-left: auto; background: none; border: none;
    cursor: pointer; color: #f75c6c;
    font-size: 18px; line-height: 1;
}

/* Empty state */
.chat-empty {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: var(--chat-muted); text-align: center; padding: 32px;
}
.chat-empty svg { width: 56px; height: 56px; margin-bottom: 16px; opacity: 0.4; }
.chat-empty p { margin: 0; font-size: 13px; max-width: 320px; }

/* ============ INFO PANEL ============ */
.chat-info-panel .info-block {
    padding: 18px;
    border-bottom: 1px solid var(--chat-border);
}
.chat-info-panel .info-block:last-child { border-bottom: none; }
.info-profile { text-align: center; }
.info-profile .av-xl {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--chat-primary), var(--chat-primary-dark));
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 26px;
    margin: 0 auto 12px;
    text-transform: uppercase;
    overflow: hidden;
}
.info-profile .av-xl img { width: 100%; height: 100%; object-fit: cover; }
.info-profile h3 {
    margin: 0 0 4px;
    font-family: 'Host Grotesk','Inter',sans-serif;
    font-size: 15px; font-weight: 700;
    color: var(--chat-ink); letter-spacing: -0.01em;
}
.info-profile .role { color: var(--chat-muted); font-size: 12px; }
.info-block h4 {
    margin: 0 0 10px;
    font-size: 10px;
    color: var(--chat-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
}
.info-row {
    display: flex; gap: 10px; align-items: center;
    padding: 6px 0; font-size: 12.5px;
    color: var(--chat-ink-2);
}
.info-row svg {
    width: 15px; height: 15px;
    color: var(--chat-muted); flex-shrink: 0;
}
.info-row span, .info-row a {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    color: var(--chat-ink-2); text-decoration: none;
}
.info-row a:hover { color: var(--chat-primary); }

.shared-list { display: flex; flex-direction: column; gap: 4px; }
.shared-list .file-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px;
    border-radius: 8px;
    text-decoration: none;
    transition: background .12s ease;
}
.shared-list .file-item:hover { background: var(--chat-bg); }
.shared-list .file-item .ic {
    width: 32px; height: 32px; border-radius: 7px;
    background: var(--chat-primary-100);
    color: var(--chat-primary);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.shared-list .file-item .ic svg { width: 16px; height: 16px; }
.shared-list .file-item .meta { flex: 1; min-width: 0; }
.shared-list .file-item .meta .n {
    font-size: 11.5px;
    color: var(--chat-ink);
    font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.shared-list .file-item .meta .s {
    font-size: 10px; color: var(--chat-muted);
}
.shared-empty { font-size: 12px; color: var(--chat-muted); text-align: center; padding: 12px 0; }

@media (max-width: 820px) {
    .thread-header {
        padding: 10px 12px;
        gap: 8px;
    }
    .thread-header .back-btn { display: inline-flex !important; flex-shrink: 0; }
    .thread-header .av { width: 36px; height: 36px; }
    .thread-header .info { flex: 1; min-width: 0; overflow: hidden; }
    .thread-header .info .n {
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        text-transform: capitalize;
    }
    .thread-header .info .s {
        font-size: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .thread-header .acts { gap: 2px; flex-shrink: 0; }
    .thread-header .acts button { width: 32px; height: 32px; }
    .msg-group { max-width: 86%; }

    /* ---- No-zoom su focus (iOS richiede font-size >= 16px) ---- */
    .chat-search input,
    .thread-search input,
    .composer textarea {
        font-size: 16px;
    }

    /* ---- Stop horizontal scroll ---- */
    .app-main,
    .app-content,
    .chat-shell,
    .chat-panel,
    .chat-thread,
    .thread-messages,
    .chat-sidebar-panel,
    .chat-info-panel,
    .thread-header,
    .composer {
        max-width: 100%;
        overflow-x: hidden;
        min-width: 0;
    }
    .thread-messages { overflow-x: hidden; }
    .msg {
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .msg-attach { max-width: 100%; min-width: 0; }
    .msg-attach .file-meta { min-width: 0; }
    .msg-attach .file-meta .name { min-width: 0; }
    .composer .input-wrap { min-width: 0; max-width: 100%; }
    .composer textarea { max-width: 100%; box-sizing: border-box; }
    .thread-search input { min-width: 0; max-width: 100%; }
}
@media (max-width: 820px) {
    /* Page-level: nessuno scroll orizzontale quando si è nella chat */
    body:has(.chat-shell), html:has(.chat-shell) { overflow-x: hidden; }

    /* Lock totale dentro la thread su mobile */
    .thread-messages {
        overflow-x: hidden !important;
        padding: 14px 10px !important;
    }
    .thread-messages > * { max-width: 100%; }
    .msg-attach { max-width: 100% !important; }
    .msg-attach .file-meta { min-width: 0; overflow: hidden; }
    .msg-attach .file-meta .name {
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        max-width: 100%;
    }
    .day-divider { width: 100%; }
}

/* hidden attribute must beat display:flex */
.thread-search[hidden], .emoji-picker[hidden] { display: none !important; }

/* Thread search bar */
.thread-search {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px;
    border-bottom: 1px solid var(--chat-border);
    background: var(--chat-bg);
    flex-shrink: 0;
}
.thread-search svg { width: 15px; height: 15px; color: var(--chat-muted); flex-shrink: 0; }
.thread-search input {
    flex: 1; min-width: 0;
    border: none; background: transparent;
    font-family: inherit; font-size: 13px;
    color: var(--chat-ink); outline: none;
}
.thread-search .ts-count {
    font-size: 11px; color: var(--chat-muted);
    font-variant-numeric: tabular-nums;
}
.thread-search button {
    width: 24px; height: 24px;
    border: none; background: transparent;
    color: var(--chat-muted); cursor: pointer;
    font-size: 18px; line-height: 1;
    border-radius: 6px;
}
.thread-search button:hover { background: white; color: var(--chat-ink); }
.thread-header .acts button.active {
    background: var(--chat-primary-100);
    color: var(--chat-primary);
}
.msg-group.search-hide { display: none; }
.msg mark {
    background: rgba(250,204,21,0.45);
    color: inherit; padding: 0 2px; border-radius: 3px;
}

/* Emoji picker */
.emoji-wrap { position: relative; display: inline-flex; }
.emoji-picker {
    position: fixed;
    width: 280px;
    background: white;
    border: 1px solid var(--chat-border);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.18);
    padding: 8px;
    z-index: 1000;
}
.emoji-picker[hidden] { display: none; }
.emoji-cat-tabs {
    display: flex; gap: 2px;
    border-bottom: 1px solid var(--chat-border);
    padding-bottom: 6px; margin-bottom: 6px;
}
.emoji-cat-tabs button {
    flex: 1;
    border: none; background: transparent;
    padding: 5px 0; font-size: 16px;
    border-radius: 6px; cursor: pointer;
    color: var(--chat-muted);
    transition: all .1s ease;
}
.emoji-cat-tabs button.active { background: var(--chat-primary-50); }
.emoji-cat-tabs button:hover { background: var(--chat-bg); }
.emoji-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 2px;
    max-height: 200px;
    overflow-y: auto;
}
.emoji-grid button {
    width: 32px; height: 32px;
    border: none; background: transparent;
    font-size: 18px; cursor: pointer;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    transition: all .1s ease;
}
.emoji-grid button:hover { background: var(--chat-primary-50); transform: scale(1.15); }

/* Info panel visible toggle on tablet */
.chat-shell.info-open .chat-info-panel { display: flex; }
@media (max-width: 1180px) {
    .chat-shell.info-open {
        grid-template-columns: 1fr 280px;
    }
    .chat-shell.info-open .chat-sidebar-panel { display: none; }
}

/* Back-button per info panel (visibile solo mobile) */
.info-back-btn {
    display: none;
    align-items: center; gap: 8px;
    padding: 12px 14px;
    border: none; background: transparent;
    color: var(--chat-primary);
    font-size: 13px; font-weight: 600;
    cursor: pointer; text-align: left;
    border-bottom: 1px solid var(--chat-border);
    width: 100%;
    flex-shrink: 0;
}
.info-back-btn:hover { background: var(--chat-bg); }
.info-back-btn svg { width: 16px; height: 16px; }

/* Mobile: info panel diventa una "pagina" che sostituisce il thread */
@media (max-width: 820px) {
    .chat-info-panel { display: none !important; }
    .chat-shell.info-open .chat-info-panel { display: flex !important; }
    .chat-shell.info-open .chat-thread { display: none; }
    .chat-shell.info-open .chat-sidebar-panel { display: none; }
    .chat-shell.info-open { grid-template-columns: 1fr; }
    .info-back-btn { display: inline-flex; }
}

/* ============ CHAT A 3: contatti fissati (dipendente) ============ */
.pinned-item { background: var(--chat-primary-50); }
.pinned-item .pin-ic { color: var(--chat-primary); flex-shrink: 0; opacity: 0.7; }

/* ============ CHAT A 3: messaggi interni staff ============ */
.msg-group.internal .msg {
    background: #fef3c7;
    color: #78350f;
    border: 1px dashed #f59e0b;
}
.msg-group.internal.sent .msg { background: #fef3c7; color: #78350f; }
.msg-internal-tag {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700;
    color: #b45309;
    text-transform: uppercase; letter-spacing: 0.03em;
    margin-bottom: 2px;
}
.msg-group.internal .msg-attach {
    background: #fef3c7;
    border: 1px dashed #f59e0b;
}
.msg-sender {
    font-size: 10.5px; font-weight: 700;
    color: var(--chat-primary);
    margin-bottom: 1px;
}

/* Toggle "Interno" nel composer */
.internal-toggle {
    width: auto !important;
    padding: 0 8px;
    gap: 4px;
    display: inline-flex; align-items: center;
    border-radius: 8px;
}
.internal-toggle .it-label { font-size: 11px; font-weight: 700; }
.internal-toggle.active-internal {
    background: #fef3c7 !important;
    color: #b45309 !important;
}
.composer.internal-mode .input-wrap {
    border-color: #f59e0b;
    background: #fffbeb;
}
.composer.internal-mode textarea { background: transparent; }

/* Menu "Fai intervenire" — position:fixed + append al body (come l'emoji picker)
   per non essere clippato dagli overflow:hidden dei pannelli chat */
.invite-wrap { position: relative; display: inline-flex; }
.invite-menu {
    position: fixed;
    width: min(280px, calc(100vw - 16px));
    max-height: min(60vh, 380px);
    overflow-y: auto;
    background: white;
    border: 1px solid var(--chat-border);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.18);
    padding: 8px;
    z-index: 1000;
}
.invite-menu[hidden] { display: none !important; }
.invite-menu-title {
    font-size: 10px; font-weight: 700;
    color: var(--chat-muted);
    text-transform: uppercase; letter-spacing: 0.05em;
    padding: 4px 8px 6px;
}
.invite-menu > button {
    display: flex; align-items: center; gap: 10px;
    width: 100%;
    border: none; background: transparent;
    padding: 8px; border-radius: 8px;
    cursor: pointer; text-align: left;
    font-family: inherit;
}
.invite-menu > button:hover { background: var(--chat-primary-50); }
.invite-menu .im-av {
    width: 30px; height: 30px; border-radius: 50%;
    background: var(--chat-primary); color: white;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.invite-menu .im-info { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.invite-menu .im-n { font-size: 12.5px; font-weight: 600; color: var(--chat-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.invite-menu .im-r { font-size: 10.5px; color: var(--chat-muted); }
.invite-menu .im-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px; border-radius: 8px;
}
.invite-menu .im-av-in { background: #64748b; }
.invite-menu .im-remove {
    border: 1px solid #fecaca; background: #fef2f2;
    color: #dc2626; cursor: pointer;
    border-radius: 7px; flex-shrink: 0;
    width: 24px; height: 24px;
    font-size: 15px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    font-family: inherit;
}
.invite-menu .im-remove.im-leave {
    width: auto; padding: 0 9px; height: 24px;
    font-size: 11px; font-weight: 700;
}
.invite-menu .im-remove:hover { background: #dc2626; color: white; border-color: #dc2626; }
.invite-menu-note {
    font-size: 10.5px; color: var(--chat-muted);
    padding: 6px 8px 2px;
    border-top: 1px solid var(--chat-border);
    margin-top: 6px;
}
</style>

<div class="chat-shell <?= $selectedConvId ? 'has-active' : '' ?>">
    <!-- Sidebar: Conversazioni + Contatti -->
    <div class="chat-panel chat-sidebar-panel">
        <div class="chat-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="chatSearch" placeholder="Cerca persone o messaggi…">
        </div>

        <div class="chat-list" id="chatSidebarList">
            <?php if ($userType === 'employee'):
                // Contatti FISSI in cima per il dipendente: admin + consulente lavoro.
                // Se esiste già una conversazione, il click la apre; altrimenti la crea.
                $__convByOther = [];
                foreach ($conversations as $__c) {
                    $__convByOther[$__c['other_type'] . ':' . $__c['other_id']] = (int) $__c['id'];
                }
                $__pinned = [];
                foreach (['admin' => 'Amministrazione', 'consulente_lavoro' => 'Consulente del lavoro'] as $__pt => $__plabel) {
                    foreach (($contacts[$__pt] ?? []) as $__pu) {
                        $__pinned[] = ['type' => $__pt, 'id' => (int) $__pu['id'], 'name' => $__pu['name'] ?? ('Utente #' . $__pu['id']), 'label' => $__plabel];
                        break; // un contatto per figura
                    }
                }
            ?>
                <?php if (!empty($__pinned)): ?>
                <div class="chat-sidebar-section" data-section="pinned">
                    <div class="chat-group-title"><span>In evidenza</span></div>
                    <?php foreach ($__pinned as $__pc):
                        $__pcConv = $__convByOther[$__pc['type'] . ':' . $__pc['id']] ?? null;
                        $__pcInit = strtoupper(mb_substr($__pc['name'], 0, 2));
                    ?>
                        <?php if ($__pcConv): ?>
                            <a href="?conv=<?= $__pcConv ?>" class="contact-item pinned-item <?= $selectedConvId == $__pcConv ? 'active' : '' ?>"
                               data-search="<?= e(strtolower($__pc['name'] . ' ' . $__pc['label'])) ?>">
                        <?php else: ?>
                            <div class="contact-item pinned-item" role="button" tabindex="0"
                                 data-search="<?= e(strtolower($__pc['name'] . ' ' . $__pc['label'])) ?>"
                                 onclick="startConversation('<?= e($__pc['type']) ?>', <?= $__pc['id'] ?>)">
                        <?php endif; ?>
                                <div class="av"><?= e($__pcInit) ?></div>
                                <div class="contact-info">
                                    <div class="n"><?= e($__pc['name']) ?></div>
                                    <div class="p"><?= e($__pc['label']) ?></div>
                                </div>
                                <svg class="pin-ic" viewBox="0 0 24 24" fill="currentColor" width="13" height="13" aria-hidden="true"><path d="M16 4v5l2 3v2h-5v6l-1 2-1-2v-6H6v-2l2-3V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/></svg>
                        <?php if ($__pcConv): ?></a><?php else: ?></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($conversations)): ?>
                <div class="chat-sidebar-section" data-section="conv">
                    <div class="chat-group-title">
                        <span>Recenti</span>
                        <span class="count"><?= count($conversations) ?></span>
                    </div>
                    <?php foreach ($conversations as $conv):
                        $name = $conv['other_name'] ?? '';
                        $initials = strtoupper(mb_substr($name, 0, 2));
                        $preview = mb_substr($conv['last_message'] ?? '', 0, 40);
                    ?>
                        <a href="?conv=<?= (int)$conv['id'] ?>"
                           class="contact-item <?= $selectedConvId == $conv['id'] ? 'active' : '' ?>"
                           data-search="<?= e(strtolower($name . ' ' . ($conv['last_message'] ?? ''))) ?>">
                            <div class="av">
                                <?php if (!empty($conv['other_photo'])): ?>
                                    <img src="<?= e(PUBLIC_URL . '/' . ltrim($conv['other_photo'], '/')) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="contact-info">
                                <div class="n"><?= e($name) ?></div>
                                <div class="p"><?= e($preview) ?></div>
                            </div>
                            <div class="contact-meta">
                                <?php if (!empty($conv['last_message_time'])): ?>
                                    <span class="t"><?= formatDateTime($conv['last_message_time'], 'H:i') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($conv['unread_count']) && $conv['unread_count'] > 0): ?>
                                    <span class="u"><?= (int)$conv['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            foreach ($contacts as $groupKey => $list) {
                if (empty($list)) continue;
                $filtered = array_values(array_filter($list, function($c) use ($groupKey, $activeContactIds, $userType, $userId) {
                    $cid = (int)($c['id'] ?? 0);
                    if (!$cid) return false;
                    if ($groupKey === $userType && $cid === $userId) return false;
                    return empty($activeContactIds[$groupKey . ':' . $cid]);
                }));
                if (empty($filtered)) continue;
                $label = $groupLabels[$groupKey] ?? $groupKey;
            ?>
                <div class="chat-sidebar-section" data-section="<?= e($groupKey) ?>">
                    <div class="chat-group-title">
                        <span><?= e($label) ?></span>
                        <span class="count"><?= count($filtered) ?></span>
                    </div>
                    <?php foreach ($filtered as $c):
                        $cId = (int)$c['id'];
                        $cName = ($groupKey === 'employee')
                            ? trim(($c['last_name'] ?? '') . ' ' . ($c['first_name'] ?? ''))
                            : ($c['name'] ?? 'Utente #' . $cId);
                        $initials = strtoupper(mb_substr($cName, 0, 2));
                        $photo = $c['photo_path'] ?? null;
                        $sub = '';
                        if ($groupKey === 'employee' && !empty($c['department_name'])) {
                            $sub = $c['department_name'];
                        } elseif (!empty($c['email'])) {
                            $sub = $c['email'];
                        }
                    ?>
                        <div class="contact-item"
                             role="button" tabindex="0"
                             data-other-type="<?= e($groupKey) ?>" data-other-id="<?= $cId ?>"
                             data-search="<?= e(strtolower($cName)) ?>"
                             onclick="startConversation('<?= e($groupKey) ?>', <?= $cId ?>)">
                            <div class="av">
                                <?php if ($photo): ?>
                                    <img src="<?= e(PUBLIC_URL . '/' . ltrim($photo, '/')) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="contact-info">
                                <div class="n"><?= e($cName) ?></div>
                                <?php if ($sub): ?><div class="p"><?= e($sub) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- Thread -->
    <div class="chat-panel chat-thread">
        <?php if ($selectedConvId && $selectedConv):
            $hName  = $selectedConv['other_name'] ?? '';
            $hPhoto = $selectedConv['other_photo'] ?? null;
            $hType  = $selectedConv['other_type'] ?? '';
            $hInit  = strtoupper(mb_substr($hName, 0, 2));
        ?>
            <div class="thread-header">
                <a href="?" class="back-btn" aria-label="Torna alla lista chat" title="Indietro">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
                <div class="av">
                    <?php if ($hPhoto): ?>
                        <img src="<?= e(PUBLIC_URL . '/' . ltrim($hPhoto, '/')) ?>" alt="" loading="lazy" decoding="async">
                    <?php else: ?>
                        <?= e($hInit) ?>
                    <?php endif; ?>
                </div>
                <div class="info">
                    <div class="n"><?= e($hName) ?></div>
                    <div class="s">
                        <?= e($singularLabels[$hType] ?? '') ?><?php if ($__extraParticipants): ?> · con <?= e(implode(', ', array_map(fn($p) => $p['name'], $__extraParticipants))) ?><?php endif; ?>
                    </div>
                </div>
                <div class="acts">
                    <?php if ($__isStaff && $__convHasEmployee && (!empty($__inviteCandidates) || !empty($__extraParticipants))): ?>
                        <div class="invite-wrap">
                            <button type="button" id="btnInvite" title="Gestisci partecipanti (aggiungi o rimuovi admin/consulente)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                            </button>
                            <div class="invite-menu" id="inviteMenu" hidden>
                                <?php if (!empty($__inviteCandidates)): ?>
                                    <div class="invite-menu-title">Fai intervenire</div>
                                    <?php foreach ($__inviteCandidates as $__ic): ?>
                                        <button type="button" onclick="addParticipant('<?= e($__ic['type']) ?>', <?= $__ic['id'] ?>)">
                                            <span class="im-av"><?= e(strtoupper(mb_substr($__ic['name'], 0, 2))) ?></span>
                                            <span class="im-info">
                                                <span class="im-n"><?= e($__ic['name']) ?></span>
                                                <span class="im-r"><?= e($singularLabels[$__ic['type']] ?? '') ?></span>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($__extraParticipants)): ?>
                                    <div class="invite-menu-title">Nella conversazione</div>
                                    <?php foreach ($__extraParticipants as $__ep):
                                        $__epIsMe = ($__ep['type'] === $userType && (int)$__ep['id'] === $userId);
                                    ?>
                                        <div class="im-row">
                                            <span class="im-av im-av-in"><?= e(strtoupper(mb_substr($__ep['name'], 0, 2))) ?></span>
                                            <span class="im-info">
                                                <span class="im-n"><?= e($__ep['name']) ?><?= $__epIsMe ? ' (tu)' : '' ?></span>
                                                <span class="im-r"><?= e($singularLabels[$__ep['type']] ?? '') ?></span>
                                            </span>
                                            <button type="button" class="im-remove <?= $__epIsMe ? 'im-leave' : '' ?>"
                                                    title="<?= $__epIsMe ? 'Esci dalla conversazione: non la vedrai più e non riceverai notifiche' : 'Rimuovi dalla conversazione: non vedrà più la chat né riceverà notifiche' ?>"
                                                    onclick="removeParticipant('<?= e($__ep['type']) ?>', <?= (int)$__ep['id'] ?>, <?= $__epIsMe ? 'true' : 'false' ?>)">
                                                <?= $__epIsMe ? 'Esci' : '&times;' ?>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="invite-menu-note">Chi viene aggiunto vede tutta la cronologia; chi esce non riceve più notifiche. Le note interne restano invisibili al dipendente.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <button type="button" id="btnThreadSearch" title="Cerca nella conversazione">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                    <button type="button" id="btnThreadInfo" title="Info conversazione">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    </button>
                </div>
            </div>

            <div class="thread-search" id="threadSearchBar" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="threadSearchInput" placeholder="Cerca nei messaggi…" autocomplete="off">
                <span class="ts-count" id="threadSearchCount"></span>
            </div>

            <div class="thread-messages" id="chatMessages">
                <?php
                $lastDay = null;
                foreach ($selectedMessages as $msg):
                    $isSent = ($msg['sender_type'] === $userType && (int)$msg['sender_id'] === $userId);
                    $msgDay = date('Y-m-d', strtotime($msg['created_at']));
                    if ($msgDay !== $lastDay) {
                        $lastDay = $msgDay;
                        $today   = date('Y-m-d');
                        $yest    = date('Y-m-d', strtotime('-1 day'));
                        if ($msgDay === $today)      $lbl = 'Oggi';
                        elseif ($msgDay === $yest)   $lbl = 'Ieri';
                        else                          $lbl = date('d M Y', strtotime($msg['created_at']));
                        echo '<div class="day-divider"><span>' . e($lbl) . '</span></div>';
                    }
                ?>
                    <?php $__msgInternal = !empty($msg['is_internal']); ?>
                    <div class="msg-group <?= $isSent ? 'sent' : 'received' ?><?= $__msgInternal ? ' internal' : '' ?>">
                        <?php if ($__isThreeWay && !$isSent): ?>
                            <span class="msg-sender"><?= e($__senderNames[$msg['sender_type'] . ':' . $msg['sender_id']] ?? '') ?></span>
                        <?php endif; ?>
                        <?php if ($__msgInternal): ?>
                            <span class="msg-internal-tag">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Interno — invisibile al dipendente
                            </span>
                        <?php endif; ?>
                        <?php if (!empty(trim($msg['message']))): ?>
                            <div class="msg"><?= nl2br(e($msg['message'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($msg['attachment_path'])):
                            $sz = !empty($msg['attachment_size']) ? round($msg['attachment_size']/1024) . ' KB' : '';
                            $ext = strtoupper(pathinfo($msg['attachment_name'] ?? '', PATHINFO_EXTENSION));
                        ?>
                            <a class="msg-attach" href="?download_attachment=<?= (int)$msg['id'] ?>" target="_blank">
                                <div class="file-ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="file-meta">
                                    <span class="name"><?= e($msg['attachment_name'] ?? 'Allegato') ?></span>
                                    <span class="size"><?= $sz ?><?= $sz && $ext ? ' · ' : '' ?><?= e($ext) ?></span>
                                </div>
                            </a>
                        <?php endif; ?>
                        <span class="msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="attach-preview" id="attachPreview">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span id="attachPreviewName"></span>
                <button type="button" onclick="clearAttachment()" title="Rimuovi">&times;</button>
            </div>

            <?php $__showInternalToggle = $__isStaff && $__convHasEmployee; ?>
            <form class="composer" id="chatForm" enctype="multipart/form-data" onsubmit="sendMessage(event)">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="conversation_id" value="<?= $selectedConvId ?>">
                <input type="hidden" name="is_internal" id="isInternalInput" value="">
                <input type="file" id="attachInput" name="attachment" style="display:none;" onchange="onAttachChange()">
                <div class="input-wrap">
                    <textarea name="message" id="messageInput"
                              placeholder="Scrivi un messaggio…" rows="1"
                              onkeydown="handleKeyDown(event)"></textarea>
                    <div class="tools">
                        <?php if ($__showInternalToggle): ?>
                            <button type="button" class="tool-btn internal-toggle" id="btnInternal"
                                    title="Nota interna: visibile solo ad admin e consulente, il dipendente non la vede"
                                    onclick="toggleInternal()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span class="it-label">Interno</span>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="tool-btn" title="Allega file" onclick="document.getElementById('attachInput').click()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        </button>
                        <div class="emoji-wrap">
                            <button type="button" class="tool-btn" id="btnEmoji" title="Emoji">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                            </button>
                            <div class="emoji-picker" id="emojiPicker" hidden></div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="send-btn" title="Invia">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </form>
        <?php else: ?>
            <div class="chat-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <p>Seleziona una conversazione o un contatto a sinistra per iniziare a chattare.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info Panel (only when active conversation) -->
    <?php if ($selectedConvId && $selectedConv):
        $iName  = $selectedConv['other_name'] ?? '';
        $iPhoto = $selectedConv['other_photo'] ?? null;
        $iType  = $selectedConv['other_type'] ?? '';
        $iInit  = strtoupper(mb_substr($iName, 0, 2));
        $iSub   = $singularLabels[$iType] ?? '';
    ?>
        <aside class="chat-panel chat-info-panel">
            <button type="button" class="info-back-btn" id="infoBackBtn" aria-label="Torna alla chat">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Torna alla chat
            </button>
            <div class="info-block info-profile">
                <div class="av-xl">
                    <?php if ($iPhoto): ?>
                        <img src="<?= e(PUBLIC_URL . '/' . ltrim($iPhoto, '/')) ?>" alt="" loading="lazy" decoding="async">
                    <?php else: ?>
                        <?= e($iInit) ?>
                    <?php endif; ?>
                </div>
                <h3><?= e($iName) ?></h3>
                <div class="role"><?= e($iSub) ?></div>
            </div>

            <div class="info-block">
                <h4>File condivisi (<?= count($sharedFiles) ?>)</h4>
                <?php if (empty($sharedFiles)): ?>
                    <div class="shared-empty">Nessun file condiviso</div>
                <?php else: ?>
                    <div class="shared-list">
                        <?php foreach ($sharedFiles as $f): ?>
                            <a class="file-item" href="?download_attachment=<?= (int)$f['id'] ?>" target="_blank">
                                <div class="ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="meta">
                                    <div class="n"><?= e($f['attachment_name'] ?? 'Allegato') ?></div>
                                    <div class="s">
                                        <?= !empty($f['attachment_size']) ? round($f['attachment_size']/1024) . ' KB · ' : '' ?><?= date('d/m/y', strtotime($f['created_at'])) ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    <?php endif; ?>
</div>

<script>
const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
const csrfToken = csrfMeta ? csrfMeta.content : '';
const _userType = <?= json_encode($userType) ?>;
const _userId   = <?= (int)$userId ?>;
const _isThreeWay = <?= json_encode($__isThreeWay) ?>;

/* ====== Nota interna (staff) ====== */
function toggleInternal() {
    const inp = document.getElementById('isInternalInput');
    const btn = document.getElementById('btnInternal');
    const form = document.getElementById('chatForm');
    const msgInput = document.getElementById('messageInput');
    if (!inp || !btn) return;
    const on = inp.value !== '1';
    inp.value = on ? '1' : '';
    btn.classList.toggle('active-internal', on);
    form.classList.toggle('internal-mode', on);
    if (msgInput) msgInput.placeholder = on ? 'Nota interna — il dipendente non la vedrà…' : 'Scrivi un messaggio…';
}

/* ====== Aggiungi / rimuovi partecipanti ====== */
function addParticipant(type, id) {
    const convId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!convId) return;
    const fd = new FormData();
    fd.append('action', 'add_participant');
    fd.append('conversation_id', convId);
    fd.append('new_type', type);
    fd.append('new_id', id);
    fd.append('csrf_token', csrfToken);
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) window.location.reload();
            else alert(data.error || 'Errore');
        })
        .catch(err => { console.error(err); alert('Errore di connessione'); });
}

function removeParticipant(type, id, isSelf) {
    const convId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!convId) return;
    const msg = isSelf
        ? 'Vuoi uscire da questa conversazione? Non la vedrai più e non riceverai notifiche.'
        : 'Rimuovere questo partecipante? Non vedrà più la chat né riceverà notifiche.';
    if (!confirm(msg)) return;
    const fd = new FormData();
    fd.append('action', 'remove_participant');
    fd.append('conversation_id', convId);
    fd.append('rem_type', type);
    fd.append('rem_id', id);
    fd.append('csrf_token', csrfToken);
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) window.location.href = data.left ? '?' : window.location.href;
            else alert(data.error || 'Errore');
            if (data.success && !data.left) window.location.reload();
        })
        .catch(err => { console.error(err); alert('Errore di connessione'); });
}

function startConversation(type, id) {
    const fd = new FormData();
    fd.append('action', 'start_conversation');
    fd.append('other_type', type);
    fd.append('other_id', id);
    fd.append('csrf_token', csrfToken);

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '?conv=' + data.conversation.id;
            } else {
                alert(data.error || 'Errore');
            }
        })
        .catch(err => { console.error(err); alert('Errore di connessione'); });
}

function onAttachChange() {
    const inp = document.getElementById('attachInput');
    const prev = document.getElementById('attachPreview');
    const name = document.getElementById('attachPreviewName');
    if (inp.files && inp.files[0]) {
        name.textContent = inp.files[0].name + ' (' + Math.round(inp.files[0].size / 1024) + ' KB)';
        prev.classList.add('show');
    } else {
        prev.classList.remove('show');
    }
}
function clearAttachment() {
    document.getElementById('attachInput').value = '';
    document.getElementById('attachPreview').classList.remove('show');
}

function sendMessage(e) {
    e.preventDefault();
    const form = document.getElementById('chatForm');
    const input = document.getElementById('messageInput');
    const btn = form.querySelector('button[type="submit"]');
    const fileInput = document.getElementById('attachInput');

    const hasText = input.value.trim().length > 0;
    const hasFile = fileInput.files && fileInput.files[0];
    if (!hasText && !hasFile) return;

    btn.disabled = true;
    const fd = new FormData(form);

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                input.style.height = ''; // reset altezza inline lasciata dall'auto-resize
                clearAttachment();
                loadMessages();
            } else {
                alert(data.error || 'Errore nell\'invio');
            }
        })
        .catch(err => { console.error(err); alert('Errore di connessione'); })
        .finally(() => { btn.disabled = false; input.focus(); });
}

function loadMessages() {
    const convId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!convId) return;
    const fd = new FormData();
    fd.append('action', 'get_messages');
    fd.append('conversation_id', convId);
    fd.append('csrf_token', csrfToken);

    // Pausa il refresh mentre l'utente sta cercando
    const searchBar = document.getElementById('threadSearchBar');
    if (searchBar && !searchBar.hidden) return;

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const container = document.getElementById('chatMessages');
            const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const fmtDay = ymd => {
                const today = new Date(); const t = today.toISOString().slice(0,10);
                const y = new Date(today.getTime()-86400000).toISOString().slice(0,10);
                if (ymd === t) return 'Oggi';
                if (ymd === y) return 'Ieri';
                const d = new Date(ymd);
                return d.toLocaleDateString('it-IT', { day:'2-digit', month:'short', year:'numeric' });
            };
            let html = '';
            let lastDay = null;
            for (const msg of data.messages) {
                const isSent = msg.sender_type === _userType && parseInt(msg.sender_id) === _userId;
                const d = new Date(msg.created_at);
                const ymd = d.toISOString().slice(0,10);
                if (ymd !== lastDay) {
                    lastDay = ymd;
                    html += `<div class="day-divider"><span>${esc(fmtDay(ymd))}</span></div>`;
                }
                const isInternal = parseInt(msg.is_internal || 0) === 1;
                html += `<div class="msg-group ${isSent ? 'sent' : 'received'}${isInternal ? ' internal' : ''}">`;
                if (_isThreeWay && !isSent && msg.sender_name) {
                    html += `<span class="msg-sender">${esc(msg.sender_name)}</span>`;
                }
                if (isInternal) {
                    html += `<span class="msg-internal-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Interno — invisibile al dipendente</span>`;
                }
                if (msg.message && msg.message.trim()) {
                    html += `<div class="msg">${esc(msg.message).replace(/\n/g,'<br>')}</div>`;
                }
                if (msg.attachment_path) {
                    const name = msg.attachment_name || 'Allegato';
                    const sz = msg.attachment_size ? Math.round(msg.attachment_size/1024) + ' KB' : '';
                    const ext = (name.split('.').pop() || '').toUpperCase();
                    html += `<a class="msg-attach" href="?download_attachment=${msg.id}" target="_blank">
                        <div class="file-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                        <div class="file-meta"><span class="name">${esc(name)}</span><span class="size">${esc(sz)}${sz && ext ? ' · ' : ''}${esc(ext)}</span></div>
                    </a>`;
                }
                const t = d.toLocaleTimeString('it-IT', { hour:'2-digit', minute:'2-digit' });
                html += `<span class="msg-time">${t}</span></div>`;
            }
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        })
        .catch(err => console.error(err));
}

/* ====== Emoji picker ====== */
const EMOJI_CATS = {
    '😀': ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','😐','😑','😶','😏','😒','🙄','😬','😌','😔','😪','😴','😷','🤒','🤕','🤧','🥵','🥶','😵','🤯','🤠','🥳','😎','🤓','🧐','😕','😟','🙁','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬'],
    '👍': ['👍','👎','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤝','🙏','💪','🦾','👏','🙌','👐','🤲','🤛','🤜','✊','👊','🫶','💅','✍️','🤳','💋','👀','👁️','👅','👂','👃','🧠','🫀','🫁','🦷','🦴'],
    '❤️': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','💌','💯','💢','💥','💫','💦','💨','🕳️','💣','💬','👁️‍🗨️','🗨️','🗯️','💭','💤'],
    '🎉': ['🎉','🎊','🎁','🎈','🎂','🎀','🪄','🎃','🎄','🎆','🎇','🧨','✨','🎋','🎍','🎎','🎏','🎐','🎑','🧧','🪔','🎟️','🎫','🏆','🏅','🥇','🥈','🥉','⚽','🏀','🏈','⚾','🎾','🏐','🏉','🎱','🏓','🏸','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂'],
    '✅': ['✅','❌','⭕','🚫','⛔','📛','💢','♨️','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','#️⃣','*️⃣','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','🔼','🔽','⬆️','⬇️','⬅️','➡️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','🔀','🔁','🔂','🔄','🔃','🎵','🎶','➕','➖','➗','✖️','♾️','💲','💱'],
    '📅': ['📅','📆','🗓️','📇','📈','📉','📊','📋','📌','📍','📎','🖇️','📏','📐','✂️','🗃️','🗄️','🗑️','🔒','🔓','🔏','🔐','🔑','🗝️','🔨','🪓','⛏️','⚒️','🛠️','🗡️','⚔️','🔫','🪃','🏹','🛡️','🪚','🔧','🪛','🔩','⚙️','🗜️','⚖️','🦯','🔗','⛓️','🧰','🧲','🪜','📞','📱','💻','⌨️','🖥️','🖨️','🖱️','🖲️','💾','💿','📀','📼','📷','📸','📹','🎥','🎞️','📽️','📡','💡','🔦','🕯️','🪔','📔','📕','📗','📘','📙','📚','📓','📒','📃','📜','📄','📰','🗞️','📑','🔖','🏷️','💰','🪙','💴','💵','💶','💷','💸','💳','🧾','✉️','📧','📨','📩','📤','📥','📦','📫','📪','📬','📭','📮','🗳️','✏️','✒️','🖋️','🖊️','🖌️','🖍️','📝']
};
const EMOJI_DEFAULT_CAT = '😀';

function buildEmojiPicker() {
    const picker = document.getElementById('emojiPicker');
    if (!picker || picker.dataset.built) return;
    picker.dataset.built = '1';

    const tabs = document.createElement('div');
    tabs.className = 'emoji-cat-tabs';
    const grid = document.createElement('div');
    grid.className = 'emoji-grid';

    const renderCat = (key) => {
        grid.innerHTML = '';
        (EMOJI_CATS[key] || []).forEach(e => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = e;
            b.addEventListener('click', () => insertEmojiChar(e));
            grid.appendChild(b);
        });
        tabs.querySelectorAll('button').forEach(t => t.classList.toggle('active', t.dataset.cat === key));
    };

    Object.keys(EMOJI_CATS).forEach(cat => {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.dataset.cat = cat;
        tab.textContent = cat;
        tab.addEventListener('click', () => renderCat(cat));
        tabs.appendChild(tab);
    });

    picker.appendChild(tabs);
    picker.appendChild(grid);
    renderCat(EMOJI_DEFAULT_CAT);
}

function insertEmojiChar(em) {
    const input = document.getElementById('messageInput');
    if (!input) return;
    const start = input.selectionStart ?? input.value.length;
    const end   = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + em + input.value.slice(end);
    input.focus();
    input.selectionStart = input.selectionEnd = start + em.length;
}

document.addEventListener('DOMContentLoaded', () => {
    /* Menu "Fai intervenire" — spostato sul body e posizionato fixed sotto il
       bottone, così resta SEMPRE visibile (niente clipping da overflow:hidden) */
    const btnInvite = document.getElementById('btnInvite');
    const inviteMenu = document.getElementById('inviteMenu');
    if (btnInvite && inviteMenu) {
        if (inviteMenu.parentElement !== document.body) document.body.appendChild(inviteMenu);
        const positionInviteMenu = () => {
            const r = btnInvite.getBoundingClientRect();
            const mw = Math.min(280, window.innerWidth - 16);
            // allinea il bordo destro del menu al bordo destro del bottone
            let left = r.right - mw;
            if (left < 8) left = 8;
            if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
            let top = r.bottom + 6;
            // se non c'è spazio sotto, aprilo sopra il bottone
            const mh = Math.min(inviteMenu.scrollHeight || 300, Math.min(window.innerHeight * 0.6, 380));
            if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
            inviteMenu.style.left = left + 'px';
            inviteMenu.style.top = top + 'px';
        };
        btnInvite.addEventListener('click', (e) => {
            e.stopPropagation();
            const opening = inviteMenu.hidden;
            inviteMenu.hidden = !opening ? true : false;
            if (opening) positionInviteMenu();
        });
        window.addEventListener('resize', () => { if (!inviteMenu.hidden) positionInviteMenu(); });
        window.addEventListener('scroll', () => { if (!inviteMenu.hidden) positionInviteMenu(); }, true);
        document.addEventListener('click', (e) => {
            if (!inviteMenu.hidden && !inviteMenu.contains(e.target) && !btnInvite.contains(e.target)) {
                inviteMenu.hidden = true;
            }
        });
    }

    /* Emoji */
    const btnEmoji = document.getElementById('btnEmoji');
    const picker = document.getElementById('emojiPicker');
    if (btnEmoji && picker) {
        // Move to body so it isn't clipped by overflow:hidden parents
        if (picker.parentElement !== document.body) document.body.appendChild(picker);
        const positionPicker = () => {
            const r = btnEmoji.getBoundingClientRect();
            const pw = 280, ph = 250;
            // anchor LEFT edge of picker to LEFT edge of button so it opens toward the chat area
            let left = r.left;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            if (left < 8) left = 8;
            let top = r.top - ph - 8;
            if (top < 8) top = r.bottom + 8;
            picker.style.left = left + 'px';
            picker.style.top  = top  + 'px';
        };
        btnEmoji.addEventListener('click', (e) => {
            e.stopPropagation();
            buildEmojiPicker();
            if (picker.hidden) { picker.hidden = false; positionPicker(); }
            else picker.hidden = true;
        });
        document.addEventListener('click', (e) => {
            if (picker.hidden) return;
            if (!picker.contains(e.target) && e.target !== btnEmoji && !btnEmoji.contains(e.target)) picker.hidden = true;
        });
        window.addEventListener('resize', () => { if (!picker.hidden) positionPicker(); });
        window.addEventListener('scroll', () => { if (!picker.hidden) positionPicker(); }, true);
    }

    /* Thread search */
    const sBtn = document.getElementById('btnThreadSearch');
    const sBar = document.getElementById('threadSearchBar');
    const sInp = document.getElementById('threadSearchInput');
    const sCnt = document.getElementById('threadSearchCount');
    const applySearch = () => {
        const q = (sInp.value || '').trim().toLowerCase();
        const groups = document.querySelectorAll('#chatMessages .msg-group');
        let matches = 0;
        groups.forEach(g => {
            const msgEl = g.querySelector('.msg');
            const text = (msgEl ? msgEl.textContent : '') + ' ' + (g.querySelector('.file-meta .name')?.textContent || '');
            const tLow = text.toLowerCase();
            const hit = !q || tLow.includes(q);
            g.classList.toggle('search-hide', !hit);
            if (msgEl) {
                msgEl.innerHTML = msgEl.dataset.orig || msgEl.innerHTML;
                if (!msgEl.dataset.orig) msgEl.dataset.orig = msgEl.innerHTML;
                if (q && hit) {
                    const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi');
                    msgEl.innerHTML = msgEl.dataset.orig.replace(re, '<mark>$1</mark>');
                }
            }
            if (hit && q) matches++;
        });
        sCnt.textContent = q ? (matches + ' risultat' + (matches === 1 ? 'o' : 'i')) : '';
    };
    if (sBtn && sBar) {
        sBtn.addEventListener('click', () => {
            const open = !sBar.hidden;
            sBar.hidden = open;
            sBtn.classList.toggle('active', !open);
            if (!open) { sInp.focus(); } else { sInp.value = ''; applySearch(); }
        });
        sInp.addEventListener('input', applySearch);
    }

    /* Info panel toggle */
    const iBtn = document.getElementById('btnThreadInfo');
    const shell = document.querySelector('.chat-shell');
    if (iBtn && shell) {
        iBtn.addEventListener('click', () => {
            shell.classList.toggle('info-open');
            iBtn.classList.toggle('active', shell.classList.contains('info-open'));
        });
    }
    /* Info panel back button (mobile) */
    const iBack = document.getElementById('infoBackBtn');
    if (iBack && shell) {
        iBack.addEventListener('click', () => {
            shell.classList.remove('info-open');
            iBtn?.classList.remove('active');
        });
    }
});

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const form = document.getElementById('chatForm');
        if (form) form.requestSubmit();
    }
}

// Search filter
const searchInput = document.getElementById('chatSearch');
if (searchInput) {
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        document.querySelectorAll('#chatSidebarList .contact-item').forEach(item => {
            const hay = item.getAttribute('data-search') || '';
            item.style.display = (!q || hay.includes(q)) ? '' : 'none';
        });
        document.querySelectorAll('#chatSidebarList .chat-sidebar-section').forEach(sec => {
            const visible = Array.from(sec.querySelectorAll('.contact-item')).some(i => i.style.display !== 'none');
            sec.style.display = visible ? '' : 'none';
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const cm = document.getElementById('chatMessages');
    if (cm) cm.scrollTop = cm.scrollHeight;
    const ti = document.getElementById('messageInput');
    if (ti) {
        ti.addEventListener('input', () => {
            ti.style.height = 'auto';
            ti.style.height = Math.min(ti.scrollHeight, 140) + 'px';
        });
    }
});

<?php if ($selectedConvId): ?>
setInterval(loadMessages, 5000);
<?php endif; ?>
</script>

<?php
$__chatFooter = $userType === 'employee' ? 'footer-employee.php' : 'footer-admin.php';
include __DIR__ . '/' . $__chatFooter;
?>
