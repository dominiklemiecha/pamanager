<?php
/**
 * Mailer SMTP minimale (no dipendenze esterne).
 * Supporta: PLAIN/LOGIN auth, STARTTLS, SMTPS, encoding UTF-8.
 */
class Mailer
{
    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function isConfigured(): bool
    {
        $cfg = Settings::getSmtpConfig();
        return $cfg['enabled'] && !empty($cfg['host']) && !empty($cfg['from_email']);
    }

    /**
     * Invia email a un dipendente rispettando il flag notify_email.
     * Aggiunge footer con link unsubscribe usando il token persistente.
     */
    public static function sendToEmployee(int $employeeId, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $emp = Database::fetchOne(
            "SELECT id, email, first_name, last_name, notify_email, email_unsubscribe_token FROM employees WHERE id = ?",
            [$employeeId]
        );
        if (!$emp || empty($emp['email']) || (int) ($emp['notify_email'] ?? 1) === 0) {
            return false;
        }

        // Garantisce token unsubscribe
        $token = $emp['email_unsubscribe_token'];
        if (empty($token)) {
            $token = bin2hex(random_bytes(24));
            try {
                Database::update('employees', ['email_unsubscribe_token' => $token], 'id = ?', [$employeeId]);
            } catch (Throwable $e) {
                error_log('[Mailer] failed to persist unsubscribe token: ' . $e->getMessage());
            }
        }

        $unsubUrl = function_exists('buildPublicUrl')
            ? buildPublicUrl('/employee/unsubscribe.php?token=' . urlencode($token))
            : (defined('PUBLIC_URL') ? PUBLIC_URL . '/employee/unsubscribe.php?token=' . urlencode($token) : '');

        $footerHtml = "<hr style=\"border:none;border-top:1px solid #e2e8f0;margin:24px 0 12px;\">"
                    . "<p style=\"color:#a0aec0;font-size:12px;line-height:1.5;\">"
                    . "Ricevi questa email perche' sei iscritto al portale aziendale. "
                    . "Se non vuoi piu' ricevere queste notifiche, "
                    . "<a href=\"" . htmlspecialchars($unsubUrl) . "\" style=\"color:#3182ce;\">disattiva le email qui</a>."
                    . "</p>";
        $footerText = "\n\n---\nNon vuoi piu' ricevere queste email? Disattivale qui: " . $unsubUrl;

        $fullName = trim($emp['first_name'] . ' ' . $emp['last_name']);
        return self::send($emp['email'], $fullName, $subject, $htmlBody . $footerHtml, $textBody . $footerText);
    }

    /**
     * Invia email tramite SMTP configurato.
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        self::$lastError = null;
        $cfg = Settings::getSmtpConfig();

        if (!$cfg['enabled']) {
            self::$lastError = 'SMTP non abilitato';
            return false;
        }
        if (empty($cfg['host']) || empty($cfg['from_email'])) {
            self::$lastError = 'Configurazione SMTP incompleta';
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Email destinatario non valida';
            return false;
        }

        $host = $cfg['host'];
        $port = $cfg['port'];
        $enc  = strtolower($cfg['encryption']);
        $remote = ($enc === 'ssl') ? "ssl://{$host}:{$port}" : "{$host}:{$port}";

        $errno = 0; $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            self::$lastError = "Connessione SMTP fallita: {$errstr}";
            return false;
        }
        stream_set_timeout($socket, 15);

        try {
            if (!self::expect($socket, 220)) return false;

            $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
            self::write($socket, "EHLO {$hostname}");
            if (!self::expect($socket, 250)) return false;

            if ($enc === 'tls') {
                self::write($socket, 'STARTTLS');
                if (!self::expect($socket, 220)) return false;
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    self::$lastError = 'Errore avvio TLS';
                    return false;
                }
                self::write($socket, "EHLO {$hostname}");
                if (!self::expect($socket, 250)) return false;
            }

            if (!empty($cfg['username'])) {
                self::write($socket, 'AUTH LOGIN');
                if (!self::expect($socket, 334)) return false;
                self::write($socket, base64_encode($cfg['username']));
                if (!self::expect($socket, 334)) return false;
                self::write($socket, base64_encode($cfg['password']));
                if (!self::expect($socket, 235)) return false;
            }

            $fromEmail = $cfg['from_email'];
            self::write($socket, "MAIL FROM:<{$fromEmail}>");
            if (!self::expect($socket, 250)) return false;
            self::write($socket, "RCPT TO:<{$toEmail}>");
            if (!self::expect($socket, [250, 251])) return false;

            self::write($socket, 'DATA');
            if (!self::expect($socket, 354)) return false;

            $boundary = 'pam_' . bin2hex(random_bytes(8));
            $fromName = self::encodeHeader($cfg['from_name']);
            $toNameEnc = self::encodeHeader($toName);
            $subjectEnc = self::encodeHeader($subject);
            $date = date('r');
            $messageId = '<' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>';

            $headers = [];
            $headers[] = "Date: {$date}";
            $headers[] = "From: {$fromName} <{$fromEmail}>";
            $headers[] = $toName !== '' ? "To: {$toNameEnc} <{$toEmail}>" : "To: <{$toEmail}>";
            $headers[] = "Subject: {$subjectEnc}";
            $headers[] = "Message-ID: {$messageId}";
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

            $textPart = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));

            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textPart . "\r\n\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= "--{$boundary}--\r\n";

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            // Dot-stuffing
            $message = preg_replace('/^\./m', '..', $message);

            self::write($socket, $message . "\r\n.");
            if (!self::expect($socket, 250)) return false;

            self::write($socket, 'QUIT');
            return true;
        } finally {
            @fclose($socket);
        }
    }

    private static function write($socket, string $cmd): void
    {
        fwrite($socket, $cmd . "\r\n");
    }

    /**
     * Legge la risposta multi-linea del server SMTP e verifica il codice atteso.
     */
    private static function expect($socket, $expected): bool
    {
        $expected = is_array($expected) ? $expected : [$expected];
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) break;
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int) substr(trim($response), 0, 3);
        if (!in_array($code, $expected, true)) {
            self::$lastError = "SMTP risposta inattesa: " . trim($response);
            return false;
        }
        return true;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
