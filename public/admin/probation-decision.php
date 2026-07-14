<?php
/**
 * Endpoint decisione periodo di prova.
 * L'admin conferma ("confirmed") o non conferma ("not_confirmed") l'assunzione
 * dall'alert/widget dashboard. Notifica il consulente in entrambi i casi.
 * Su "non conferma" la disattivazione del dipendente avviene alla data di fine prova.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

CSRF::verifyOrDie();

$user       = Auth::getUser();
$employeeId = (int)($_POST['employee_id'] ?? 0);
$decision   = $_POST['decision'] ?? '';

$flash = 'err';
if ($employeeId > 0 && in_array($decision, ['confirmed', 'not_confirmed'], true)) {
    $res = Probation::decide($employeeId, $decision, (int)$user['id']);
    if (!empty($res['success'])) {
        $flash = $decision === 'confirmed' ? 'confirmed' : 'not_confirmed';
    }
}

header('Location: index.php?probation=' . $flash);
exit;
