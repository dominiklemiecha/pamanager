/**
 * Variabili d'ambiente senza spazi in testa e in coda.
 *
 * In Dokploy basta scrivere `SMTP_HOST= smtp.example.com` (spazio dopo
 * l'uguale) e l'indirizzo non e' piu' valido: le email non partono.
 * Si importa per primo in main.ts, prima che chiunque legga la configurazione.
 */
for (const [k, v] of Object.entries(process.env)) {
  if (typeof v === 'string' && v !== v.trim()) process.env[k] = v.trim();
}

export {};
