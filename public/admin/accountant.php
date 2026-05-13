<?php
/**
 * Gestione Commercialisti - Admin
 * PAManager - Comune
 *
 * Pagina condivisa nello stile con la gestione consulenti del lavoro.
 * Per modificarne il look, modificare entrambe in parallelo.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

// ---- Configurazione specifica del ruolo gestito ----
$ROLE         = 'accountant';
$LABEL        = 'Commercialista';
$LABEL_PLURAL = 'Commercialisti';
$SELF         = 'accountant.php';
$LIST_FN      = [User::class, 'getAccountants'];
$ICON_PATH    = 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z';
// ---------------------------------------------------

require __DIR__ . '/_user-role-manager.inc.php';
