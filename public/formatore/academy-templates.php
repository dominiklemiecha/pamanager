<?php
/**
 * Academy - modelli attestato (editor visuale).
 * Logica e vista condivise in includes/_academy-templates.inc.php.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('formatore');

require dirname(__DIR__) . '/includes/_academy-templates.inc.php';
