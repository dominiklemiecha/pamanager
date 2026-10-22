<?php
/**
 * Tempo reale della chat: token per collegarsi al servizio Socket.IO
 * (cartella realtime/) e consegna degli eventi dopo il salvataggio.
 *
 * Stesso contratto del CRM Connecteed: gli eventi arrivano sul canale
 * `evento` con la busta { v, type, at, source, data }. Il socket porta solo
 * avvisi: se il servizio non risponde il dato e' comunque nel database e la
 * pagina lo ritrova con il polling di riserva.
 *
 * ENV: REALTIME_SECRET (uguale nei due servizi), REALTIME_URL (interno,
 * default http://realtime:3001), REALTIME_ENABLED=false per spegnere.
 */
class Realtime
{
    public const TOKEN_TTL = 3600;

    private static function env(string $k, string $default = ''): string
    {
        $v = getenv($k);
        if ($v === false || $v === '') $v = $_ENV[$k] ?? $_SERVER[$k] ?? $default;
        return (string) $v;
    }

    public static function enabled(): bool
    {
        if (self::env('REALTIME_SECRET') === '') return false;
        return !in_array(strtolower(self::env('REALTIME_ENABLED', 'true')), ['off', 'false', '0', 'no'], true);
    }

    /** Chiave della persona nelle stanze del socket: "employee:12", "admin:3". */
    public static function sub(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Token di accesso al socket (HS256), valido un'ora. */
    public static function token(string $type, int $id, int $companyId): ?string
    {
        if (!self::enabled()) return null;
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode([
            'sub' => self::sub($type, $id),
            'cid' => $companyId,
            'exp' => time() + self::TOKEN_TTL,
        ]));
        $sig = self::b64(hash_hmac('sha256', $head . '.' . $body, self::env('REALTIME_SECRET'), true));
        return $head . '.' . $body . '.' . $sig;
    }

    /**
     * Evento a piu' persone, con un contenuto diverso per ciascuna
     * ($perPersona: sub => data). Non blocca: al massimo mezzo secondo.
     */
    public static function emitEach(int $companyId, string $type, array $perPersona): void
    {
        if (!$perPersona) return;
        $eventi = [];
        foreach ($perPersona as $sub => $data) $eventi[] = ['sub' => $sub, 'data' => $data];
        self::call('/emit', ['cid' => $companyId, 'type' => $type, 'eventi' => $eventi]);
    }

    /** Stesso evento a piu' persone. */
    public static function emit(int $companyId, string $type, array $subs, $data): void
    {
        $per = [];
        foreach (array_unique($subs) as $s) $per[$s] = $data;
        self::emitEach($companyId, $type, $per);
    }

    /** Evento a tutti i collegati dell'azienda (presenza). */
    public static function emitCompany(int $companyId, string $type, $data): void
    {
        self::call('/emit', ['cid' => $companyId, 'type' => $type, 'azienda' => true, 'data' => $data]);
    }

    /**
     * Chi ha la chat aperta in primo piano e chi sta usando il sito adesso:
     * a loro il push del telefono non serve. [sub => ['guarda'=>bool,'attivo'=>bool]]
     */
    public static function viewing(int $companyId, int $conversationId, array $subs): array
    {
        $r = self::call('/stato', ['cid' => $companyId, 'conv' => $conversationId, 'subs' => array_values($subs)]);
        return is_array($r['stato'] ?? null) ? $r['stato'] : [];
    }

    private static function call(string $path, array $body): ?array
    {
        if (!self::enabled() || !function_exists('curl_init')) return null;
        $ch = curl_init(rtrim(self::env('REALTIME_URL', 'http://realtime:3001'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Realtime-Secret: ' . self::env('REALTIME_SECRET')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_TIMEOUT_MS     => 600,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false || $code !== 200) return null;
        $j = json_decode((string) $out, true);
        return is_array($j) ? $j : null;
    }
}
