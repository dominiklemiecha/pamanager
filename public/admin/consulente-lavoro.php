<?php
/**
 * Gestione Consulenti del lavoro - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$ROLE         = 'consulente_lavoro';
$LABEL        = 'Consulente del lavoro';
$LABEL_PLURAL = 'Consulenti del lavoro';
$SELF         = 'consulente-lavoro.php';
$LIST_FN      = [User::class, 'getConsulentiLavoro'];
$ICON_PATH    = 'M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z';

require __DIR__ . '/_user-role-manager.inc.php';
