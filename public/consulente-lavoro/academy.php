<?php
/**
 * Academy - corsi, partecipanti, registro frequenza e attestati.
 * Logica e vista condivise in includes/_academy-staff.inc.php.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

require dirname(__DIR__) . '/includes/_academy-staff.inc.php';
