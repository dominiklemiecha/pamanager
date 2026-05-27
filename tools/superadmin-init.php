<?php
/**
 * Genera le credenziali per il superadmin.
 *
 * USO:
 *   php tools/superadmin-init.php
 *
 * Output: stampa le tre variabili da aggiungere a .env (o env del container Dokploy):
 *   SUPERADMIN_USER
 *   SUPERADMIN_PASS_HASH
 *   SUPERADMIN_TOTP_SECRET
 *
 * E il provisioning URI / link QR per registrare l'app authenticator.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Esegui da CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config/config.php';

echo "=== PAManager — Superadmin init ===\n\n";

echo "Username: ";
$username = trim((string) fgets(STDIN));
if ($username === '') { fwrite(STDERR, "Username obbligatorio\n"); exit(1); }

echo "Password (sara' hashata): ";
// disable echo if possible (linux)
if (function_exists('shell_exec')) @shell_exec('stty -echo 2>/dev/null');
$password = trim((string) fgets(STDIN));
if (function_exists('shell_exec')) @shell_exec('stty echo 2>/dev/null');
echo "\n";
if (strlen($password) < 10) { fwrite(STDERR, "Password troppo corta (min 10)\n"); exit(1); }

$hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$secret = MFA::generateSecret();
$uri    = MFA::getProvisioningUri($secret, $username, 'PAManager-SuperAdmin');
$qrUrl  = MFA::getQrCodeUrl($uri);

echo "\nAggiungi questi valori al file .env (o alle env del container):\n\n";
echo "SUPERADMIN_USER={$username}\n";
echo "SUPERADMIN_PASS_HASH={$hash}\n";
echo "SUPERADMIN_TOTP_SECRET={$secret}\n";
echo "\nQR code per Google Authenticator / 1Password / Authy:\n{$qrUrl}\n";
echo "\nProvisioning URI (in alternativa al QR):\n{$uri}\n";
echo "\nFatto. Salva queste credenziali in un posto sicuro: non saranno mostrate di nuovo.\n";
