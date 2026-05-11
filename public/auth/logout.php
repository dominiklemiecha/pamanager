<?php
/**
 * Logout
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
Auth::logout();

// Redirect alla home
header('Location: ' . PUBLIC_URL . '/');
exit;
