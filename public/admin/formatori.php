<?php
/**
 * Gestione Responsabili formazione - Admin
 * Utenze dedicate all'Academy: vedono e gestiscono solo corsi, partecipanti,
 * registro e attestati dell'azienda corrente.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$ROLE          = 'formatore';
$LABEL         = 'Responsabile formazione';
$LABEL_PLURAL  = 'Responsabili formazione';
$SELF          = 'formatori.php';
$LIST_FN       = [User::class, 'getFormatori'];
$ICON_PATH     = 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5';
$MULTI_COMPANY = false;
$BACK_URL      = 'academy.php';
$BACK_LABEL    = 'Academy';

require __DIR__ . '/_user-role-manager.inc.php';
