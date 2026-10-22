<?php
/**
 * Chat — interfaccia Cora, identica alla chat interna del CRM Connecteed
 * (ChatPage.tsx, design "largo" al computer e "telefono" sotto gli 820px),
 * con l'arancio di Cora al posto del lime. Tempo reale con Socket.IO
 * (servizio realtime/), polling di riserva come nel CRM.
 * Inclusa da includes/_chat-page.inc.php, dove stanno le azioni AJAX (cora_*).
 * Il disegno e' tutto in assets/js/cora-chat.js a partire da window.CORA_CHAT.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }

$__me = CoraChat::person($userType, (int) $userId);
$__convs = [];
foreach ($conversations as $__c) {
    $__convs[] = CoraChat::conversation($__c, $userType, (int) $userId);
}
$__cid = class_exists('Tenant') ? (int) Tenant::currentCompanyId() : 1;

$__cfg = [
    'me'            => $__me,
    'open'          => (int) $selectedConvId,
    'conversations' => $__convs,
    'contacts'      => CoraChat::contacts($contacts),
    'presence'      => Chat::presenceAll(),
    'editMinutes'   => Chat::EDIT_MINUTES,
    'assets'        => PUBLIC_URL . '/assets/images/cora-chat/',
    'realtime'      => class_exists('Realtime') && Realtime::enabled() ? [
        'path'  => '/realtime/socket.io',
        'token' => Realtime::token($userType, (int) $userId, $__cid),
    ] : null,
];
$__a = PUBLIC_URL . '/assets/images/cora-chat/';
echo CoraUi::topbar('Chat', '', '<button type="button" class="cc-nuovo" data-cc-new><img src="' . $__a . 'nuovo-piu.svg" alt="">Nuova chat</button>');
?>
<div class="cc-frame cora-reveal" id="coraChat"></div>

<script>window.CORA_CHAT = <?= json_encode($__cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php if ($__cfg['realtime']): ?>
<script src="/realtime/socket.io/socket.io.min.js" defer></script>
<?php endif; ?>
<script src="<?= PUBLIC_URL ?>/assets/js/cora-chat.js?v=<?= time() ?>" defer></script>
