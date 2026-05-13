<?php
/**
 * Entry Point Pubblico - PAManager.
 * Redirect al login unificato (se non gia autenticato) o all'area utente.
 */

require_once dirname(__DIR__) . '/config/config.php';

Auth::init();
setSecurityHeaders();

if (Auth::isUserLoggedIn()) {
    $u = Auth::getUser();
    $redirect = match($u['role'] ?? '') {
        'admin'             => PUBLIC_URL . '/admin/',
        'admin_reparto'     => PUBLIC_URL . '/admin-reparto/',
        'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
        'accountant'        => PUBLIC_URL . '/accountant/',
        default             => PUBLIC_URL . '/auth/login.php',
    };
    header('Location: ' . $redirect);
    exit;
}
if (Auth::isEmployeeLoggedIn()) {
    header('Location: ' . PUBLIC_URL . '/employee/');
    exit;
}

header('Location: ' . PUBLIC_URL . '/auth/login.php');
exit;
