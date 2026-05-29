<?php
/**
 * SMTP ora e' configurato a livello piattaforma (variabili d'ambiente), non piu'
 * per-azienda. Pagina mantenuta solo come redirect per vecchi bookmark.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
Auth::requireUser('admin');
header('Location: ' . PUBLIC_URL . '/admin/profile.php');
exit;
