<?php
/**
 * Disattivazione iscrizione email - endpoint pubblico (no login richiesto).
 * Disattiva il flag notify_email del dipendente tramite token persistente.
 *
 * Uso: /employee/unsubscribe.php?token=<token>
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

setSecurityHeaders();

$token = $_GET['token'] ?? '';
$status = 'invalid';
$employeeName = '';

if (!empty($token) && preg_match('/^[a-f0-9]{32,}$/', $token)) {
    $emp = Database::fetchOne(
        "SELECT id, first_name, last_name, notify_email FROM employees WHERE email_unsubscribe_token = ?",
        [$token]
    );
    if ($emp) {
        $employeeName = trim($emp['first_name'] . ' ' . $emp['last_name']);
        if ((int) $emp['notify_email'] === 0) {
            $status = 'already';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                Database::update('employees', ['notify_email' => 0], 'id = ?', [$emp['id']]);
                $status = 'unsubscribed';
            } catch (Throwable $e) {
                $status = 'error';
            }
        } else {
            $status = 'confirm';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Disattiva email - PAManager</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f7fafc; color: #2d3748; margin: 0; padding: 40px 20px; }
    .box { max-width: 500px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 12px rgba(0,0,0,.06); text-align: center; }
    h1 { font-size: 1.25rem; margin: 0 0 1rem; }
    p { line-height: 1.6; color: #4a5568; }
    .btn { display: inline-block; padding: .75rem 1.5rem; border-radius: 8px; background: #c53030; color: white; text-decoration: none; font-weight: 600; border: none; cursor: pointer; font-size: 1rem; }
    .btn:hover { background: #9b2c2c; }
    .ok { color: #2f855a; }
    .err { color: #c53030; }
</style>
</head>
<body>
<div class="box">
<?php if ($status === 'confirm'): ?>
    <h1>Disattivare le notifiche email?</h1>
    <p>Ciao <strong><?= htmlspecialchars($employeeName) ?></strong>, confermi di voler smettere di ricevere email di notifica dal portale?</p>
    <p style="font-size: .85rem; color: #718096;">Potrai sempre riattivarle dal tuo profilo dopo aver effettuato l'accesso.</p>
    <form method="POST">
        <button type="submit" class="btn">Si, disattiva le email</button>
    </form>
<?php elseif ($status === 'unsubscribed'): ?>
    <h1 class="ok">Email disattivate</h1>
    <p>Ciao <strong><?= htmlspecialchars($employeeName) ?></strong>, non riceverai piu' email di notifica dal portale.</p>
    <p style="font-size: .85rem; color: #718096;">Puoi riattivarle dal tuo profilo accedendo al portale.</p>
<?php elseif ($status === 'already'): ?>
    <h1>Email gia' disattivate</h1>
    <p>Ciao <strong><?= htmlspecialchars($employeeName) ?></strong>, le notifiche email sono gia' disattivate per il tuo account.</p>
<?php elseif ($status === 'error'): ?>
    <h1 class="err">Errore</h1>
    <p>Si e' verificato un errore. Riprova piu' tardi o contatta l'amministratore.</p>
<?php else: ?>
    <h1 class="err">Link non valido</h1>
    <p>Il link che hai usato non e' valido o e' scaduto.</p>
<?php endif; ?>
</div>
</body>
</html>
