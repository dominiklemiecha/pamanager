<?php
/**
 * Area Responsabile formazione: l'unica sezione e l'Academy.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
Auth::requireUser('formatore');
header('Location: ' . PUBLIC_URL . '/formatore/academy.php');
exit;
