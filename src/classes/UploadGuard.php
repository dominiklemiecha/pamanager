<?php
/**
 * UploadGuard — protegge le directory di upload servite solo via script PHP.
 *
 * I file sensibili (certificati medici, documenti di assunzione) vivono sotto
 * public/uploads/ per motivi storici, ma non devono essere raggiungibili via URL:
 * l'accesso passa sempre da una pagina autenticata che legge dal filesystem.
 * Le foto profilo restano invece pubbliche e non vengono toccate.
 */
class UploadGuard
{
    private const DENY_ALL = "# Generato automaticamente: contenuti riservati, accesso solo via script PHP.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    /** Scrive un .htaccess "deny all" nella directory, se non c'e' gia'. */
    public static function denyDirectAccess(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $file = rtrim($dir, "/\\") . '/.htaccess';
        if (is_file($file)) {
            return;
        }
        @file_put_contents($file, self::DENY_ALL);
    }
}
