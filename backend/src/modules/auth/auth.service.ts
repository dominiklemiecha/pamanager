import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { hashPassword, verificaPassword } from '../../common/sicurezza/password';
import { usaCodiceRiserva, verificaTotp } from '../../common/sicurezza/totp';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { BLOCCO_SECONDI, MAX_TENTATIVI, RUOLI_MFA } from './auth.types';
import { SessioniService } from './sessioni.service';

interface AccountTrovato {
  tabella: 'users' | 'employees';
  riga: any;
  perEmail: boolean;
}

/**
 * Tentativi sbagliati per IP (in memoria, per istanza). Il PHP teneva il conto
 * nella sessione di chi provava: bastava non mandare il cookie per azzerarlo.
 */
const FALLITI_PER_IP = 20;
const FINESTRA_IP_MS = 15 * 60_000;
const fallitiIp = new Map<string, { n: number; inizio: number }>();

function ipBloccato(ip: string): boolean {
  const c = fallitiIp.get(ip);
  if (!c) return false;
  if (Date.now() - c.inizio > FINESTRA_IP_MS) { fallitiIp.delete(ip); return false; }
  return c.n >= FALLITI_PER_IP;
}
function segnaFallito(ip: string): void {
  const c = fallitiIp.get(ip);
  if (!c || Date.now() - c.inizio > FINESTRA_IP_MS) fallitiIp.set(ip, { n: 1, inizio: Date.now() });
  else c.n++;
  if (fallitiIp.size > 10_000) fallitiIp.clear();
}

/**
 * Hash di confronto per gli account che non esistono: la verifica costa lo
 * stesso tempo, cosi' dalla durata della risposta non si capisce se un
 * username, un'email o un codice fiscale sono registrati.
 */
let hashFinto: Promise<string> | null = null;
const hashDiConfronto = () => (hashFinto ??= hashPassword(`non-esiste-${Date.now()}-${Math.random()}`));

const DURATA_MINIMA_ERRORE_MS = 450;

export type EsitoLogin =
  | { ok: true; token: string; tipo: 'user' | 'employee'; ruolo: string; mfa: boolean }
  | { ok: false; errore: string };

/**
 * Login unico per staff e dipendenti (Auth::login del PHP), con username,
 * email o codice fiscale. La password si prova su ogni account attivo trovato;
 * se nessuno corrisponde il tentativo fallito si conta su tutti quelli non
 * bloccati. Messaggi, blocchi e registro uguali al PHP.
 */
@Injectable()
export class AuthService {
  constructor(
    private readonly ds: DataSource,
    private readonly sessioni: SessioniService,
    private readonly audit: AuditService,
  ) {}

  /** Stessa ricerca del PHP: prima username/codice fiscale, poi email; a parita' lo staff prima. */
  async trovaAccount(identificativo: string): Promise<AccountTrovato[]> {
    const id = identificativo.trim();
    if (!id) return [];
    return senzaIsolamento(async () => {
      const out: AccountTrovato[] = [];
      const utenti = await this.ds.query(
        `SELECT * FROM users WHERE (username = $1 OR email = $1) AND is_active = true
          ORDER BY last_login DESC NULLS LAST, id DESC`, [id]);
      for (const r of utenti) out.push({ tabella: 'users', riga: r, perEmail: String(r.username).toLowerCase() !== id.toLowerCase() });
      const dip = await this.ds.query(
        `SELECT * FROM employees WHERE (username = $1 OR fiscal_code = $2 OR email = $1) AND is_active = true
          ORDER BY last_login DESC NULLS LAST, id DESC`, [id, id.toUpperCase()]);
      for (const r of dip) {
        out.push({
          tabella: 'employees', riga: r,
          perEmail: String(r.username).toLowerCase() !== id.toLowerCase() && String(r.fiscal_code).toLowerCase() !== id.toLowerCase(),
        });
      }
      // Ordinamento stabile: chi corrisponde per username/codice fiscale prima di chi corrisponde per email.
      return out.map((a, i) => ({ a, i })).sort((x, y) => Number(x.a.perEmail) - Number(y.a.perEmail) || x.i - y.i).map((x) => x.a);
    });
  }

  async login(identificativo: string, password: string, ctx: ContestoAudit & { userAgent: string; ip: string }): Promise<EsitoLogin> {
    const inizio = Date.now();
    const esito = await this.provaLogin(identificativo, password, ctx);
    // Le risposte negative durano tutte almeno lo stesso tempo: gli hash bcrypt
    // (circa 330 ms) e Argon2id (circa 120 ms) altrimenti farebbero capire chi esiste.
    if (!esito.ok) {
      const resta = DURATA_MINIMA_ERRORE_MS - (Date.now() - inizio);
      if (resta > 0) await new Promise((r) => setTimeout(r, resta));
    }
    return esito;
  }

  private async provaLogin(identificativo: string, password: string, ctx: ContestoAudit & { userAgent: string; ip: string }): Promise<EsitoLogin> {
    if (ipBloccato(ctx.ip)) return { ok: false, errore: 'Troppi tentativi. Riprova tra qualche minuto.' };
    const account = await this.trovaAccount(identificativo);
    if (!account.length) {
      await verificaPassword(password, await hashDiConfronto());
      segnaFallito(ctx.ip);
      await this.loginFallito('unknown', 0, 'user_not_found', ctx, { username: identificativo });
      return { ok: false, errore: 'Credenziali non valide' };
    }
    let minutiBlocco = 0;
    const falliti: Array<[AccountTrovato['tabella'], number, string]> = [];
    for (const a of account) {
      const r = a.riga;
      const ruolo = a.tabella === 'users' ? r.role : 'employee';
      if (r.locked_until && new Date(r.locked_until).getTime() > Date.now()) {
        minutiBlocco = Math.max(minutiBlocco, Math.ceil((new Date(r.locked_until).getTime() - Date.now()) / 60000));
        await this.loginFallito(ruolo, r.id, 'account_locked', ctx);
        continue;
      }
      if (await verificaPassword(password, r.password_hash)) {
        return a.tabella === 'users' ? this.iniziaStaff(r, ctx) : this.completa('employee', r, ctx);
      }
      falliti.push([a.tabella, r.id, ruolo]);
    }
    if (falliti.length) segnaFallito(ctx.ip);
    for (const [tabella, id, ruolo] of falliti) {
      await this.contaTentativo(tabella, id, ctx);
      await this.loginFallito(ruolo, id, 'wrong_password', ctx);
    }
    if (minutiBlocco > 0 && !falliti.length) return { ok: false, errore: `Account bloccato. Riprova tra ${minutiBlocco} minuti.` };
    return { ok: false, errore: 'Credenziali non valide' };
  }

  private async iniziaStaff(u: any, ctx: ContestoAudit & { userAgent: string; ip: string }): Promise<EsitoLogin> {
    // MFA: solo se configurata sull'account e il ruolo la richiede (come il PHP).
    // Nel PHP questo passo portava a una pagina che non esisteva: ora si completa davvero.
    if (RUOLI_MFA.includes(u.role) && u.mfa_secret && u.mfa_enabled) {
      const { token } = await this.sessioni.crea({ tipo: 'user', id: u.id, ruolo: u.role, companyId: null, mfa: true, userAgent: ctx.userAgent, ip: ctx.ip });
      return { ok: true, token, tipo: 'user', ruolo: u.role, mfa: true };
    }
    return this.completa('user', u, ctx);
  }

  /** Completa il login: azzera tentativi, salva l'ultimo accesso, apre una sessione nuova. */
  private async completa(tipo: 'user' | 'employee', r: any, ctx: ContestoAudit & { userAgent: string; ip: string }): Promise<EsitoLogin> {
    const tabella = tipo === 'user' ? 'users' : 'employees';
    await senzaIsolamento(() => this.ds.query(
      `UPDATE ${tabella} SET failed_attempts = 0, locked_until = NULL, last_login = CURRENT_TIMESTAMP WHERE id = $1`, [r.id]));
    const ruolo = tipo === 'user' ? r.role : 'employee';
    const { token, sessione } = await this.sessioni.crea({
      tipo, id: r.id, ruolo, companyId: tipo === 'employee' ? r.company_id : null, mfa: false, userAgent: ctx.userAgent, ip: ctx.ip,
    });
    await this.audit.log('login_success', ruolo, r.id, { ...ctx, sessioneId: sessione.id }, null, null, null,
      { username: r.username, ip: ctx.ip });
    return { ok: true, token, tipo, ruolo, mfa: false };
  }

  /** Secondo passo per lo staff con MFA: codice a tempo o codice di riserva. */
  async verificaMfa(sessioneId: string, idUtente: number, codice: string, ctx: ContestoAudit & { userAgent: string; ip: string }): Promise<EsitoLogin> {
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT * FROM users WHERE id = $1 AND is_active = true', [idUtente]));
    if (!u || !u.mfa_secret) return { ok: false, errore: 'MFA non configurato' };
    let valido = verificaTotp(u.mfa_secret, codice);
    if (!valido) {
      const resto = await usaCodiceRiserva(codice, u.mfa_backup_codes);
      if (resto !== null) {
        await senzaIsolamento(() => this.ds.query('UPDATE users SET mfa_backup_codes = $2 WHERE id = $1', [u.id, resto]));
        valido = true;
      }
    }
    if (!valido) {
      await this.audit.log('mfa_failed', 'admin', u.id, ctx, 'users', u.id, null, { reason: 'invalid_code' }, 'warning');
      return { ok: false, errore: 'Codice non valido' };
    }
    // Sessione nuova dopo l'MFA (come session_regenerate_id del PHP): quella di attesa si chiude.
    await this.sessioni.chiudi(sessioneId, 'mfa_completata');
    return this.completa('user', u, ctx);
  }

  private async contaTentativo(tabella: 'users' | 'employees', id: number, ctx: ContestoAudit): Promise<void> {
    // UPDATE ... RETURNING: TypeORM restituisce [righe, numero di righe toccate].
    const [[r]] = await senzaIsolamento(() => this.ds.query(
      `UPDATE ${tabella} SET failed_attempts = COALESCE(failed_attempts, 0) + 1 WHERE id = $1 RETURNING failed_attempts`, [id]));
    if ((r?.failed_attempts ?? 0) >= MAX_TENTATIVI) {
      await senzaIsolamento(() => this.ds.query(
        `UPDATE ${tabella} SET locked_until = CURRENT_TIMESTAMP + make_interval(secs => $2) WHERE id = $1`, [id, BLOCCO_SECONDI]));
      await this.audit.log('suspicious_activity', tabella === 'users' ? 'user' : 'employee', id, ctx, null, null, null,
        { description: 'Account locked after max failed attempts', attempts: r.failed_attempts, lockout_minutes: BLOCCO_SECONDI / 60 }, 'critical');
    }
  }

  private async loginFallito(ruolo: string, id: number, motivo: string, ctx: ContestoAudit, extra: Record<string, unknown> = {}): Promise<void> {
    await this.audit.log('login_failed', ruolo, id, ctx, null, null, null, { reason: motivo, ...extra }, 'warning');
    // Piu' di 10 tentativi falliti in 15 minuti sullo stesso account: attivita' sospetta.
    const n = await this.audit.conta('login_failed', ruolo, id, 15);
    if (n > 10) {
      await this.audit.log('suspicious_activity', ruolo, id, ctx, null, null, null,
        { description: 'Multiple failed login attempts detected', failed_count: n, window: '15 minutes' }, 'critical');
    }
  }
}
