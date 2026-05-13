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

                $result = Chat::sendMessage($convId, $userType, $userId, $msg, $attachPath, $attachName, $attachSize, $attachMime);
                echo json_encode($result);
                exit;

            case 'get_messages':
                $convId = (int) ($_POST['conversation_id'] ?? 0);
                Chat::markAsRead($convId, $userType, $userId);
                $messages = Chat::getMessages($convId);
                echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
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

// ---- Render ----
$conversations    = Chat::getConversations($userType, $userId);
$contacts         = Chat::getAvailableContacts($userType, $userId, $departmentId);
$selectedConvId   = (int) ($_GET['conv'] ?? 0);
$selectedMessages = [];
$selectedConv     = null;
if ($selectedConvId) {
    Chat::markAsRead($selectedConvId, $userType, $userId);
    $selectedMessages = array_reverse(Chat::getMessages($selectedConvId));
    foreach ($conversations as $c) {
        if ((int)$c['id'] === $selectedConvId) { $selectedConv = $c; break; }
    }
}

// Helper per label gruppo
$groupLabels = [
    'admin'             => 'Amministratori',
    'admin_reparto'     => 'Admin reparto',
    'accountant'        => 'Commercialisti',
    'consulente_lavoro' => 'Consulenti del lavoro',
    'employee'          => 'Dipendenti',
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
include __DIR__ . '/header-' . $__chatLayout . '.php';
?>

<style>
.chat-container {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 1rem;
    height: calc(100vh - 180px);
    min-height: 500px;
}
.chat-sidebar {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.chat-sidebar-section { border-bottom: 1px solid #edf2f7; }
.chat-sidebar-section:last-child { border-bottom: none; }
.chat-sidebar-title {
    padding: .75rem 1rem .5rem;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #718096;
    background: #f7fafc;
    display: flex; justify-content: space-between; align-items: center;
}
.chat-sidebar-title .count { font-weight: 600; color: #a0aec0; font-size: .7rem; }
.chat-sidebar-search {
    padding: .5rem 1rem;
    border-bottom: 1px solid #edf2f7;
}
.chat-sidebar-search input {
    width: 100%;
    padding: .45rem .65rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: .82rem;
}
.chat-sidebar-search input:focus { outline: none; border-color: #3182ce; }

.chat-sidebar-list { flex: 1; overflow-y: auto; }
.chat-item {
    display: flex; align-items: center;
    gap: .65rem;
    padding: .6rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f7fafc;
    text-decoration: none;
    color: inherit;
    transition: background .15s;
}
.chat-item:hover { background: #f7fafc; }
.chat-item.active { background: #ebf8ff; border-left: 3px solid #3182ce; }
.chat-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #3182ce;
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: .82rem;
    flex-shrink: 0;
    text-transform: uppercase;
    overflow: hidden;
}
.chat-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chat-info { flex: 1; min-width: 0; }
.chat-info .chat-name {
    font-weight: 500; font-size: .85rem; color: #2d3748;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.chat-info .chat-preview,
.chat-info .chat-sub {
    font-size: .7rem; color: #a0aec0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.chat-meta { display: flex; flex-direction: column; align-items: flex-end; gap: .2rem; }
.chat-time { font-size: .6rem; color: #a0aec0; }
.chat-unread {
    background: #3182ce; color: white;
    font-size: .6rem; padding: .1rem .45rem;
    border-radius: 10px; font-weight: 700;
}
.chat-contact-role {
    font-size: .58rem; letter-spacing: .5px; text-transform: uppercase;
    padding: 1px 6px; border-radius: 4px;
    background: #edf2f7; color: #4a5568;
}

/* Main */
.chat-main {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.chat-header {
    padding: 1rem;
    border-bottom: 1px solid #edf2f7;
    display: flex; align-items: center; gap: .75rem;
}
.chat-header-name { font-weight: 600; font-size: 1rem; color: #2d3748; }
.chat-header-status { font-size: .72rem; color: #a0aec0; }

.chat-messages {
    flex: 1; padding: 1rem;
    overflow-y: auto;
    display: flex; flex-direction: column;
    gap: .65rem;
    background: #f7fafc;
}
.message {
    max-width: 70%;
    padding: .6rem .85rem;
    border-radius: 12px;
    font-size: .88rem;
    line-height: 1.4;
    word-wrap: break-word;
}
.message.sent {
    align-self: flex-end;
    background: #3182ce;
    color: white;
    border-bottom-right-radius: 4px;
}
.message.received {
    align-self: flex-start;
    background: white;
    color: #2d3748;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.message-time { font-size: .62rem; margin-top: .25rem; opacity: .7; }
.message-attach {
    margin-top: .35rem;
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .35rem .6rem;
    background: rgba(255,255,255,0.2);
    border-radius: 6px;
    color: inherit; text-decoration: none;
    font-size: .78rem;
}
.message.received .message-attach {
    background: #edf2f7; color: #3182ce;
}
.message-attach:hover { filter: brightness(0.95); text-decoration: underline; }
.message-attach svg { width: 14px; height: 14px; flex-shrink: 0; }

.chat-input-area {
    padding: .75rem 1rem;
    border-top: 1px solid #edf2f7;
    display: flex; gap: .5rem; align-items: flex-end;
}
.chat-input {
    flex: 1; padding: .55rem .85rem;
    border: 1px solid #e2e8f0; border-radius: 20px;
    font-size: .9rem; resize: none; min-height: 38px; max-height: 120px;
    font-family: inherit;
}
.chat-input:focus { outline: none; border-color: #3182ce; }
.chat-attach-btn, .chat-send-btn {
    width: 38px; height: 38px;
    border-radius: 50%;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.chat-attach-btn {
    background: #edf2f7; color: #4a5568;
}
.chat-attach-btn:hover { background: #e2e8f0; color: #3182ce; }
.chat-send-btn { background: #3182ce; color: white; }
.chat-send-btn:hover { background: #2c5282; }
.chat-send-btn:disabled { background: #a0aec0; cursor: not-allowed; }
.attach-preview {
    display: none;
    margin: 0 1rem .5rem;
    padding: .45rem .7rem;
    background: #ebf8ff;
    border: 1px solid #bee3f8;
    border-radius: 6px;
    font-size: .78rem;
    align-items: center; gap: .5rem;
    color: #2c5282;
}
.attach-preview.show { display: flex; }
.attach-preview button {
    margin-left: auto; background: none; border: none; cursor: pointer;
    color: #c53030; font-size: 1rem;
}

.chat-empty {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: #a0aec0; text-align: center; padding: 2rem;
}
.chat-empty svg { width: 56px; height: 56px; margin-bottom: 1rem; opacity: .4; }

.chat-back-mobile {
    display: none;
    width: 36px; height: 36px;
    border: none; background: transparent; color: #2d3748;
    border-radius: 8px; cursor: pointer;
    align-items: center; justify-content: center;
    text-decoration: none;
}
.chat-back-mobile:hover { background: #f7fafc; }

@media (max-width: 768px) {
    .chat-container {
        grid-template-columns: 1fr;
        height: calc(100vh - 140px);
        gap: 0;
    }
    .chat-container:not(.has-active) .chat-main { display: none; }
    .chat-container.has-active .chat-sidebar { display: none; }
    .chat-back-mobile { display: inline-flex; }
    .message { max-width: 88%; }
}
</style>

<div class="chat-container <?= $selectedConvId ? 'has-active' : '' ?>">
    <!-- Sidebar: Conversazioni + Tutti i contatti -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-search">
            <input type="search" id="chatSearch" placeholder="Cerca conversazioni o contatti...">
        </div>

        <div class="chat-sidebar-list" id="chatSidebarList">
            <?php if (!empty($conversations)): ?>
                <div class="chat-sidebar-section" data-section="conv">
                    <div class="chat-sidebar-title">
                        Conversazioni
                        <span class="count"><?= count($conversations) ?></span>
                    </div>
                    <?php foreach ($conversations as $conv):
                        $name = $conv['other_name'] ?? '';
                        $initials = strtoupper(mb_substr($name, 0, 2));
                    ?>
                        <a href="?conv=<?= (int)$conv['id'] ?>"
                           class="chat-item <?= $selectedConvId == $conv['id'] ? 'active' : '' ?>"
                           data-search="<?= e(strtolower($name . ' ' . ($conv['last_message'] ?? ''))) ?>">
                            <div class="chat-avatar">
                                <?php if (!empty($conv['other_photo'])): ?>
                                    <img src="<?= e(PUBLIC_URL . '/' . ltrim($conv['other_photo'], '/')) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="chat-info">
                                <div class="chat-name"><?= e($name) ?></div>
                                <div class="chat-preview"><?= e(mb_substr($conv['last_message'] ?? '', 0, 40)) ?></div>
                            </div>
                            <div class="chat-meta">
                                <?php if (!empty($conv['last_message_time'])): ?>
                                    <span class="chat-time"><?= formatDateTime($conv['last_message_time'], 'd/m H:i') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($conv['unread_count']) && $conv['unread_count'] > 0): ?>
                                    <span class="chat-unread"><?= (int)$conv['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            // Contatti raggruppati, escludendo quelli gia' nelle conversazioni
            foreach ($contacts as $groupKey => $list) {
                if (empty($list)) continue;

                $filtered = array_values(array_filter($list, function($c) use ($groupKey, $activeContactIds, $userType, $userId) {
                    $cid = (int)($c['id'] ?? 0);
                    if (!$cid) return false;
                    // Escludi se stesso
                    if ($groupKey === $userType && $cid === $userId) return false;
                    // Escludi se ha gia' una conversazione
                    return empty($activeContactIds[$groupKey . ':' . $cid]);
                }));

                if (empty($filtered)) continue;
                $label = $groupLabels[$groupKey] ?? $groupKey;
            ?>
                <div class="chat-sidebar-section" data-section="<?= e($groupKey) ?>">
                    <div class="chat-sidebar-title">
                        <?= e($label) ?>
                        <span class="count"><?= count($filtered) ?></span>
                    </div>
                    <?php foreach ($filtered as $c):
                        $cId = (int)$c['id'];
                        $cName = ($groupKey === 'employee')
                            ? trim(($c['last_name'] ?? '') . ' ' . ($c['first_name'] ?? ''))
                            : ($c['name'] ?? 'Utente #' . $cId);
                        $initials = strtoupper(mb_substr($cName, 0, 2));
                        $photo = $c['photo_path'] ?? null;
                    ?>
                        <div class="chat-item"
                             role="button" tabindex="0"
                             data-other-type="<?= e($groupKey) ?>" data-other-id="<?= $cId ?>"
                             data-search="<?= e(strtolower($cName)) ?>"
                             onclick="startConversation('<?= e($groupKey) ?>', <?= $cId ?>)">
                            <div class="chat-avatar">
                                <?php if ($photo): ?>
                                    <img src="<?= e(PUBLIC_URL . '/' . ltrim($photo, '/')) ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="chat-info">
                                <div class="chat-name"><?= e($cName) ?></div>
                                <div class="chat-sub">
                                    <?php if ($groupKey === 'employee' && !empty($c['department_name'])): ?>
                                        <?= e($c['department_name']) ?>
                                    <?php elseif (!empty($c['email'])): ?>
                                        <?= e($c['email']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- Main chat area -->
    <div class="chat-main">
        <?php if ($selectedConvId && $selectedConv): ?>
            <div class="chat-header">
                <a href="?" class="chat-back-mobile" aria-label="Indietro">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                </a>
                <div class="chat-avatar">
                    <?php
                        $name = $selectedConv['other_name'] ?? '';
                        $photo = $selectedConv['other_photo'] ?? null;
                        if (!empty($photo)) {
                            echo '<img src="' . e(PUBLIC_URL . '/' . ltrim($photo, '/')) . '" alt="' . e($name) . '" loading="lazy" decoding="async">';
                        } else {
                            echo e(strtoupper(mb_substr($name, 0, 2)));
                        }
                    ?>
                </div>
                <div style="flex:1;">
                    <div class="chat-header-name"><?= e($selectedConv['other_name'] ?? '') ?></div>
                    <div class="chat-header-status"><?= e($groupLabels[$selectedConv['other_type']] ?? '') ?></div>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <?php foreach ($selectedMessages as $msg):
                    $isSent = ($msg['sender_type'] === $userType && (int)$msg['sender_id'] === $userId);
                ?>
                    <div class="message <?= $isSent ? 'sent' : 'received' ?>">
                        <?php if (!empty(trim($msg['message']))): ?>
                            <?= nl2br(e($msg['message'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($msg['attachment_path'])): ?>
                            <a class="message-attach" href="?download_attachment=<?= (int)$msg['id'] ?>" target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 015 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 005 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                                <?= e($msg['attachment_name'] ?? 'Allegato') ?>
                            </a>
                        <?php endif; ?>
                        <div class="message-time"><?= date('d/m H:i', strtotime($msg['created_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="attach-preview" id="attachPreview">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                <span id="attachPreviewName"></span>
                <button type="button" onclick="clearAttachment()" title="Rimuovi">&times;</button>
            </div>

            <form class="chat-input-area" id="chatForm" enctype="multipart/form-data" onsubmit="sendMessage(event)">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="conversation_id" value="<?= $selectedConvId ?>">
                <input type="file" id="attachInput" name="attachment" style="display:none;" onchange="onAttachChange()">
                <button type="button" class="chat-attach-btn" onclick="document.getElementById('attachInput').click()" title="Allega file">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 015 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 005 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                </button>
                <textarea class="chat-input" name="message" id="messageInput"
                          placeholder="Scrivi un messaggio..." rows="1"
                          onkeydown="handleKeyDown(event)"></textarea>
                <button type="submit" class="chat-send-btn" title="Invia">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </form>
        <?php else: ?>
            <div class="chat-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <p>Seleziona una conversazione o clicca su un contatto a sinistra per iniziare a chattare.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
const csrfToken = csrfMeta ? csrfMeta.content : '';
const _userType = <?= json_encode($userType) ?>;
const _userId   = <?= (int)$userId ?>;

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

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const container = document.getElementById('chatMessages');
            container.innerHTML = data.messages.map(msg => {
                const isSent = msg.sender_type === _userType && parseInt(msg.sender_id) === _userId;
                let html = `<div class="message ${isSent ? 'sent' : 'received'}">`;
                if (msg.message && msg.message.trim()) {
                    html += msg.message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>');
                }
                if (msg.attachment_path) {
                    const safeName = (msg.attachment_name || 'Allegato').replace(/&/g, '&amp;').replace(/</g, '&lt;');
                    html += `<a class="message-attach" href="?download_attachment=${msg.id}" target="_blank">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 015 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 005 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                        ${safeName}
                    </a>`;
                }
                const d = new Date(msg.created_at);
                const time = d.toLocaleString('it-IT', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
                html += `<div class="message-time">${time}</div></div>`;
                return html;
            }).join('');
            container.scrollTop = container.scrollHeight;
        })
        .catch(err => console.error(err));
}

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
        document.querySelectorAll('#chatSidebarList .chat-item').forEach(item => {
            const hay = item.getAttribute('data-search') || '';
            item.style.display = (!q || hay.includes(q)) ? '' : 'none';
        });
        // Nascondi sezioni vuote
        document.querySelectorAll('#chatSidebarList .chat-sidebar-section').forEach(sec => {
            const visible = Array.from(sec.querySelectorAll('.chat-item')).some(i => i.style.display !== 'none');
            sec.style.display = visible ? '' : 'none';
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const cm = document.getElementById('chatMessages');
    if (cm) cm.scrollTop = cm.scrollHeight;
});

<?php if ($selectedConvId): ?>
setInterval(loadMessages, 5000);
<?php endif; ?>
</script>

<?php
$__chatFooter = $userType === 'employee' ? 'footer-employee.php' : 'footer-admin.php';
include __DIR__ . '/' . $__chatFooter;
?>
