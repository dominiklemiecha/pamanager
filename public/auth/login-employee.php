<?php
// Endpoint legacy: redirect permanente al login unificato.
require_once dirname(__DIR__, 2) . '/config/config.php';
header('Location: ' . PUBLIC_URL . '/auth/login.php', true, 301);
exit;
