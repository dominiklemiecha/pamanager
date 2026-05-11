<?php
/**
 * Chat - Commercialista
 * PAManager - Comune
 */

// Per richieste POST, cattura qualsiasi output indesiderato
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    ini_set('display_errors', '0');
}

require_once dirname(__DIR__, 2) . '/config/config.php';

// Gestisci errori catturati
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unexpectedOutput = ob_get_clean();
    if (!empty($unexpectedOutput)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Output inatteso: ' . substr($unexpectedOutput, 0, 200)]);
        exit;
    }
}

// Verifica che le classi necessarie esistano
if (!class_exists('Chat')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Classe Chat non trovata. Verificare che src/classes/Chat.php esista.']);
        exit;
    }
    die('Errore: Classe Chat non trovata. Verificare che src/classes/Chat.php sia stato caricato sul server.');
}

Auth::init();
setSecurityHeaders();

// Per richieste AJAX, restituisci JSON se non autenticato invece di redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isUserLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta. Ricarica la pagina.', 'session_expired' => true]);
    exit;
}

Auth::requireUser('accountant');

$user = Auth::getUser();
$userType = 'accountant';
$userId = $user['id'];

$message = '';
$error = '';

// Gestione azioni POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'send':
            try {
                $convId = (int) ($_POST['conversation_id'] ?? 0);
                $msg = $_POST['message'] ?? '';
                $result = Chat::sendMessage($convId, $userType, $userId, $msg);
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
            }
            exit;

        case 'get_messages':
            try {
                $convId = (int) ($_POST['conversation_id'] ?? 0);
                Chat::markAsRead($convId, $userType, $userId);
                $messages = Chat::getMessages($convId);
                echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()]);
            }
            exit;

        case 'start_conversation':
            try {
                $otherType = $_POST['other_type'] ?? '';
                $otherId = (int) ($_POST['other_id'] ?? 0);

                // Verifica permessi
                if (!Chat::canContact($userType, $userId, null, $otherType, $otherId)) {
                    echo json_encode(['success' => false, 'error' => 'Non puoi contattare questo utente']);
                    exit;
                }

                $result = Chat::getOrCreateConversation($userType, $userId, $otherType, $otherId);
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Azione non valida']);
            exit;
    }
}

// Carica conversazioni
$conversations = Chat::getConversations($userType, $userId);

// Carica contatti disponibili
$contacts = Chat::getAvailableContacts($userType, $userId, null);

// Conversazione selezionata
$selectedConvId = (int) ($_GET['conv'] ?? 0);
$selectedMessages = [];
if ($selectedConvId) {
    Chat::markAsRead($selectedConvId, $userType, $userId);
    $selectedMessages = array_reverse(Chat::getMessages($selectedConvId));
}

$pageTitle = 'Chat';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.chat-container {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 1rem;
    height: calc(100vh - 180px);
    min-height: 500px;
}

/* Sidebar */
.chat-sidebar {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat-sidebar-header {
    padding: 1rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.chat-sidebar-header h3 {
    margin: 0;
    font-size: 0.95rem;
    color: #2d3748;
}

.new-chat-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.new-chat-btn:hover {
    background: #5a67d8;
}

.chat-list {
    flex: 1;
    overflow-y: auto;
}

.chat-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f7fafc;
    transition: background 0.2s;
    text-decoration: none;
    color: inherit;
}

.chat-item:hover {
    background: #f7fafc;
}

.chat-item.active {
    background: #ebf4ff;
    border-left: 3px solid #667eea;
}

.chat-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.chat-info {
    flex: 1;
    min-width: 0;
}

.chat-name {
    font-weight: 500;
    font-size: 0.9rem;
    color: #2d3748;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-preview {
    font-size: 0.75rem;
    color: #a0aec0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.25rem;
}

.chat-time {
    font-size: 0.65rem;
    color: #a0aec0;
}

.chat-unread {
    background: #667eea;
    color: white;
    font-size: 0.65rem;
    padding: 0.15rem 0.4rem;
    border-radius: 10px;
    font-weight: 600;
}

/* Main Chat Area */
.chat-main {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat-header {
    padding: 1rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.chat-header-info {
    flex: 1;
}

.chat-header-name {
    font-weight: 600;
    font-size: 1rem;
    color: #2d3748;
}

.chat-header-status {
    font-size: 0.75rem;
    color: #a0aec0;
}

.chat-messages {
    flex: 1;
    padding: 1rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    background: #f7fafc;
}

.message {
    max-width: 70%;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    font-size: 0.9rem;
}

.message.sent {
    align-self: flex-end;
    background: #667eea;
    color: white;
    border-bottom-right-radius: 4px;
}

.message.received {
    align-self: flex-start;
    background: white;
    color: #2d3748;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.message-time {
    font-size: 0.65rem;
    margin-top: 0.25rem;
    opacity: 0.7;
}

.chat-input-area {
    padding: 1rem;
    border-top: 1px solid #edf2f7;
    display: flex;
    gap: 0.75rem;
}

.chat-input {
    flex: 1;
    padding: 0.75rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    font-size: 0.9rem;
    resize: none;
}

.chat-input:focus {
    outline: none;
    border-color: #667eea;
}

.chat-send-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-send-btn:hover {
    background: #5a67d8;
}

.chat-send-btn:disabled {
    background: #a0aec0;
    cursor: not-allowed;
}

.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #a0aec0;
    text-align: center;
    padding: 2rem;
}

.chat-empty svg {
    width: 64px;
    height: 64px;
    margin-bottom: 1rem;
    opacity: 0.5;
}

/* New Chat Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-overlay.show {
    display: flex;
}

.modal {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    width: 90%;
    max-width: 400px;
    max-height: 80vh;
    overflow-y: auto;
}

.modal h3 {
    margin: 0 0 1rem;
    color: #2d3748;
}

.contact-group {
    margin-bottom: 1rem;
}

.contact-group-title {
    font-size: 0.7rem;
    font-weight: 600;
    color: #a0aec0;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}

.contact-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    background: #f7fafc;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
}

.contact-item:hover {
    background: #ebf4ff;
}

.contact-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.8rem;
}

.contact-name {
    flex: 1;
    font-size: 0.9rem;
    color: #2d3748;
}

.chat-back-mobile {
    display: none;
    width: 36px; height: 36px;
    border: none; background: transparent; color: #2d3748;
    border-radius: 8px; cursor: pointer;
    align-items: center; justify-content: center;
    margin-right: 0.25rem; text-decoration: none;
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
    .chat-sidebar, .chat-main { max-height: none; min-height: 0; }
    .chat-back-mobile { display: inline-flex; }
    .message { max-width: 85%; }
}
</style>

<div class="chat-container <?= $selectedConvId ? 'has-active' : '' ?>">
    <!-- Sidebar -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-header">
            <h3>Conversazioni</h3>
            <button class="new-chat-btn" onclick="showNewChatModal()" title="Nuova chat">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                </svg>
            </button>
        </div>
        <div class="chat-list">
            <?php if (empty($conversations)): ?>
                <div style="padding: 2rem; text-align: center; color: #a0aec0;">
                    <p>Nessuna conversazione</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <a href="?conv=<?= $conv['id'] ?>"
                       class="chat-item <?= $selectedConvId == $conv['id'] ? 'active' : '' ?>">
                        <?= chatAvatarHtml($conv['other_name'], $conv['other_photo'] ?? null, 'chat-avatar') ?>
                        <div class="chat-info">
                            <div class="chat-name"><?= e($conv['other_name']) ?></div>
                            <div class="chat-preview"><?= e(mb_substr($conv['last_message'] ?? '', 0, 30)) ?></div>
                        </div>
                        <div class="chat-meta">
                            <?php if ($conv['last_message_time']): ?>
                                <span class="chat-time"><?= formatDateTime($conv['last_message_time'], 'd/m H:i') ?></span>
                            <?php endif; ?>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <span class="chat-unread"><?= $conv['unread_count'] ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat -->
    <div class="chat-main">
        <?php if ($selectedConvId && !empty($conversations)):
            $selectedConv = array_filter($conversations, fn($c) => $c['id'] == $selectedConvId);
            $selectedConv = reset($selectedConv);
        ?>
            <div class="chat-header">
                <a href="?" class="chat-back-mobile" aria-label="Torna alle conversazioni">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                </a>
                <?= chatAvatarHtml($selectedConv['other_name'], $selectedConv['other_photo'] ?? null, 'chat-avatar') ?>
                <div class="chat-header-info">
                    <div class="chat-header-name"><?= e($selectedConv['other_name']) ?></div>
                    <div class="chat-header-status"><?= ucfirst($selectedConv['other_type']) ?></div>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <?php foreach ($selectedMessages as $msg):
                    $isSent = $msg['sender_type'] === $userType && $msg['sender_id'] == $userId;
                ?>
                    <div class="message <?= $isSent ? 'sent' : 'received' ?>">
                        <?= nl2br(e($msg['message'])) ?>
                        <div class="message-time"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form class="chat-input-area" id="chatForm" onsubmit="sendMessage(event)">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="conversation_id" value="<?= $selectedConvId ?>">
                <textarea class="chat-input" name="message" id="messageInput"
                          placeholder="Scrivi un messaggio..." rows="1"
                          onkeydown="handleKeyDown(event)"></textarea>
                <button type="submit" class="chat-send-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </form>
        <?php else: ?>
            <div class="chat-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                <p>Seleziona una conversazione o iniziane una nuova</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Nuova Chat -->
<div class="modal-overlay" id="newChatModal">
    <div class="modal">
        <h3>Nuova Conversazione</h3>

        <?php if (!empty($contacts['admin'])): ?>
            <div class="contact-group">
                <div class="contact-group-title">Amministratori</div>
                <div class="contact-list">
                    <?php foreach ($contacts['admin'] as $c): ?>
                        <div class="contact-item" onclick="startConversation('admin', <?= $c['id'] ?>)">
                            <div class="contact-avatar"><?= strtoupper(substr($c['name'], 0, 2)) ?></div>
                            <div class="contact-name"><?= e($c['name']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($contacts['admin_reparto'])): ?>
            <div class="contact-group">
                <div class="contact-group-title">Admin Reparto</div>
                <div class="contact-list">
                    <?php foreach ($contacts['admin_reparto'] as $c): ?>
                        <div class="contact-item" onclick="startConversation('admin_reparto', <?= $c['id'] ?>)">
                            <div class="contact-avatar"><?= strtoupper(substr($c['name'], 0, 2)) ?></div>
                            <div class="contact-name"><?= e($c['name']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($contacts['employee'])): ?>
            <div class="contact-group">
                <div class="contact-group-title">Dipendenti</div>
                <div class="contact-list">
                    <?php foreach ($contacts['employee'] as $c): ?>
                        <div class="contact-item" onclick="startConversation('employee', <?= $c['id'] ?>)">
                            <?= employeeAvatarHtml($c, 'contact-avatar') ?>
                            <div class="contact-name"><?= e($c['last_name'] . ' ' . $c['first_name']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <button class="btn btn-secondary" style="margin-top: 1rem; width: 100%;" onclick="hideNewChatModal()">
            Annulla
        </button>
    </div>
</div>

<script>
// Ottieni CSRF token in modo sicuro
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const csrfToken = csrfMeta ? csrfMeta.content : '';

function showNewChatModal() {
    document.getElementById('newChatModal').classList.add('show');
}

function hideNewChatModal() {
    document.getElementById('newChatModal').classList.remove('show');
}

function startConversation(type, id) {
    const formData = new FormData();
    formData.append('action', 'start_conversation');
    formData.append('other_type', type);
    formData.append('other_id', id);
    formData.append('csrf_token', csrfToken);

    fetch('', { method: 'POST', body: formData })
    .then(r => {
        if (!r.ok) throw new Error('Errore di rete');
        return r.json();
    })
    .then(data => {
        if (data.success) {
            window.location.href = '?conv=' + data.conversation.id;
        } else {
            alert(data.error || 'Errore');
        }
    })
    .catch(err => {
        console.error('Errore:', err);
        alert('Errore di connessione');
    });
}

function sendMessage(e) {
    e.preventDefault();
    const form = document.getElementById('chatForm');
    const input = document.getElementById('messageInput');
    const btn = form.querySelector('button[type="submit"]');
    const message = input.value.trim();

    if (!message) return;

    btn.disabled = true;

    const formData = new FormData(form);

    fetch('', { method: 'POST', body: formData })
    .then(r => {
        if (!r.ok) throw new Error('Errore ' + r.status);
        return r.json();
    })
    .then(data => {
        if (data.success) {
            input.value = '';
            loadMessages();
        } else {
            alert(data.error || 'Errore nell\'invio');
        }
    })
    .catch(err => {
        console.error('Errore invio:', err);
        alert('Errore di connessione. Riprova.');
    })
    .finally(() => {
        btn.disabled = false;
        input.focus();
    });
}

function loadMessages() {
    const convId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!convId) return;

    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('conversation_id', convId);
    formData.append('csrf_token', csrfToken);

    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const container = document.getElementById('chatMessages');
            container.innerHTML = data.messages.map(msg => {
                const isSent = msg.sender_type === '<?= $userType ?>' && msg.sender_id == <?= $userId ?>;
                return `<div class="message ${isSent ? 'sent' : 'received'}">
                    ${msg.message.replace(/\n/g, '<br>')}
                    <div class="message-time">${new Date(msg.created_at).toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'})}</div>
                </div>`;
            }).join('');
            container.scrollTop = container.scrollHeight;
        }
    })
    .catch(err => console.error('Errore caricamento messaggi:', err));
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage(e);
    }
}

// Scroll to bottom on load
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});

// Auto-refresh messages
<?php if ($selectedConvId): ?>
setInterval(loadMessages, 5000);
<?php endif; ?>

// Close modal on overlay click
document.getElementById('newChatModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideNewChatModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
