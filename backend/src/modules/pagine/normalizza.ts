/**
 * Variabili per i template PHP convertiti, nella forma in cui il PHP le
 * riceveva da MariaDB: date e ore "AAAA-MM-GG HH:MM:SS" nell'ora di Roma,
 * booleani come 1/0 (TINYINT).
 */
const FORMATO = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });

export function dataOraRoma(d: Date): string {
  const p = Object.fromEntries(FORMATO.formatToParts(d).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;
}

/**
 * Colonne segrete che non devono mai arrivare al browser, anche se una
 * query della pagina le seleziona (il PHP le teneva sul server).
 */
const SEGRETE = new Set(['password_hash', 'mfa_secret', 'door_api_key', 'email_unsubscribe_token', 'token_hash', 'access_token']);

export function normalizza(v: unknown): unknown {
  if (v instanceof Date) return Number.isNaN(v.getTime()) ? null : dataOraRoma(v);
  if (typeof v === 'boolean') return v ? 1 : 0;
  if (Array.isArray(v)) return v.map(normalizza);
  if (v && typeof v === 'object' && !Buffer.isBuffer(v)) {
    const o: Record<string, unknown> = {};
    for (const [k, x] of Object.entries(v)) if (!SEGRETE.has(k) && !/^wrike_.*token/.test(k)) o[k] = normalizza(x);
    return o;
  }
  return v;
}
