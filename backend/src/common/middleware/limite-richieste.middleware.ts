import type { NextFunction, Request, Response } from 'express';

/**
 * Limite di richieste per indirizzo IP sugli endpoint che si possono provare
 * a forza: login, recupero password, API esterne, link pubblici con codice,
 * console del superadmin.
 *
 * In memoria, per istanza: basta contro i tentativi a raffica. Il blocco per
 * account (5 errori) resta; questo impedisce di provare tante email o di
 * bloccare apposta l'account di qualcun altro.
 */
interface Regola { nome: string; metodo?: string; percorso: RegExp; massimo: number; finestraMs: number }

const MIN = 60_000;
const REGOLE: Regola[] = [
  // Raffiche sul login: un ufficio intero puo' entrare dallo stesso IP alle 9, quindi
  // il tetto e' largo; i tentativi SBAGLIATI hanno un limite a parte (AuthService).
  { nome: 'login', metodo: 'POST', percorso: /^\/api\/v1\/auth\/(login|mfa)\/?$/i, massimo: 30, finestraMs: 5 * MIN },
  // Come il PHP: 3 richieste di recupero ogni 15 minuti (ma per IP, non per sessione).
  { nome: 'recupero', metodo: 'POST', percorso: /^\/api\/v1\/auth\/recupero\/?$/i, massimo: 3, finestraMs: 15 * MIN },
  { nome: 'reset', metodo: 'POST', percorso: /^\/api\/v1\/auth\/reset(\/verifica)?\/?$/i, massimo: 20, finestraMs: 15 * MIN },
  { nome: 'rinnovo', metodo: 'POST', percorso: /^\/api\/v1\/auth\/refresh\/?$/i, massimo: 60, finestraMs: 5 * MIN },
  // API usate da sistemi esterni con le vecchie URL (CRM, porta, verifica attestati).
  { nome: 'api-esterne', metodo: 'POST', percorso: /^\/api\/(auth|door)\.php$/i, massimo: 30, finestraMs: 5 * MIN },
  { nome: 'pubblico', percorso: /^\/api\/v1\/pubblico\//i, massimo: 30, finestraMs: 10 * MIN },
  { nome: 'superadmin', percorso: /^\/api\/v1\/superadmin\//i, massimo: 60, finestraMs: 10 * MIN },
];

const contatori = new Map<string, { n: number; inizio: number }>();
let ultimaPulizia = Date.now();

/** IP del client: dietro Traefik l'ultimo di X-Forwarded-For e' quello che ha visto il proxy. */
function ipDi(req: Request): string {
  const xff = String(req.headers['x-forwarded-for'] || '').split(',').map((x) => x.trim()).filter(Boolean);
  return xff[xff.length - 1] || req.ip || req.socket?.remoteAddress || 'sconosciuto';
}

export function limiteRichieste(req: Request, res: Response, next: NextFunction): void {
  const percorso = (req.originalUrl || req.url || '').split('?')[0];
  const regola = REGOLE.find((r) => (!r.metodo || r.metodo === req.method) && r.percorso.test(percorso));
  if (!regola) { next(); return; }

  const ora = Date.now();
  if (ora - ultimaPulizia > 5 * MIN) {
    for (const [k, v] of contatori) if (ora - v.inizio > 60 * MIN) contatori.delete(k);
    ultimaPulizia = ora;
  }
  const chiave = `${regola.nome}|${ipDi(req)}`;
  const c = contatori.get(chiave);
  if (!c || ora - c.inizio > regola.finestraMs) {
    contatori.set(chiave, { n: 1, inizio: ora });
    next();
    return;
  }
  c.n += 1;
  if (c.n > regola.massimo) {
    const secondi = Math.ceil((regola.finestraMs - (ora - c.inizio)) / 1000);
    res.setHeader('Retry-After', String(secondi));
    res.status(429).json({ statusCode: 429, message: `Troppi tentativi: riprova tra ${Math.ceil(secondi / 60)} minuti` });
    return;
  }
  next();
}
