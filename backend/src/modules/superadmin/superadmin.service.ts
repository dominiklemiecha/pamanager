import { Injectable, Logger } from '@nestjs/common';
import * as bcrypt from 'bcrypt';
import { createHash, randomBytes, timingSafeEqual } from 'crypto';
import * as fs from 'fs';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { hashPassword } from '../../common/sicurezza/password';
import { verificaTotp } from '../../common/sicurezza/totp';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { AuditService } from '../audit/audit.service';
import { DA_ELIMINARE } from '../pagine/admin/aziende.pagina';
import { escapeHtml, MailerService } from '../notifiche/mailer.service';

const impronta = (t: string) => createHash('sha256').update(t).digest('hex');
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;
/** Durata massima di inattivita' della sessione del superadmin. */
const DURATA_MS = 3600_000;

interface SessioneSa { fase: 'password' | 'completa'; ultimo: number }
type Esito = { success: true } | { success: false; error: string };

/**
 * Superadmin (SuperAdmin.php): credenziali fuori dal database
 * (SUPERADMIN_USER, SUPERADMIN_PASS_HASH bcrypt anche in base64,
 * SUPERADMIN_TOTP_SECRET) da ambiente o da storage/superadmin.env,
 * sessione separata a due passaggi (password poi codice), gestione tenant.
 *
 * Correzioni rispetto al PHP: il token del primo accesso si salva come
 * impronta (il PHP lo salvava in chiaro); niente pagina di diagnostica che
 * mostrava file e variabili d'ambiente; tentativi limitati per indirizzo;
 * l'eliminazione di un tenant toglie tutti i dati in una transazione (il PHP
 * ne lasciava molti); un admin globale senza aziende assegnate non si puo'
 * eliminare (il PHP cancellava tutte le aziende del sistema).
 */
@Injectable()
export class SuperadminService {
  private readonly logger = new Logger('Superadmin');
  private readonly sessioni = new Map<string, SessioneSa>();
  constructor(private readonly ds: DataSource, private readonly mailer: MailerService, private readonly audit: AuditService) {}

  private daFile(): Record<string, string> {
    const out: Record<string, string> = {};
    const p = process.env.SUPERADMIN_ENV_FILE || cartellaStorage('superadmin.env');
    try {
      for (const riga of fs.readFileSync(p, 'utf8').split(/\r?\n/)) {
        const r = riga.trim();
        if (!r || r.startsWith('#') || !r.includes('=')) continue;
        const k = r.slice(0, r.indexOf('=')).trim();
        const v = r.slice(r.indexOf('=') + 1).trim().replace(/^["']|["']$/g, '');
        if (k) out[k] = v;
      }
    } catch { /* file assente */ }
    return out;
  }

  /** Tutti i valori non vuoti di una chiave (file e ambiente), come envAll del PHP. */
  private valori(k: string): string[] {
    return [...new Set([this.daFile()[k], process.env[k]].filter((v): v is string => !!v && v.trim() !== '').map((v) => v.trim()))];
  }

  configurato(): boolean {
    return ['SUPERADMIN_USER', 'SUPERADMIN_PASS_HASH', 'SUPERADMIN_TOTP_SECRET'].every((k) => this.valori(k).length > 0);
  }

  private hash(v: string): string {
    if (v.startsWith('$')) return v;
    const d = Buffer.from(v, 'base64').toString();
    return d.startsWith('$2') ? d : v;
  }

  async controllaPassword(username: string, password: string): Promise<boolean> {
    const u = username.trim();
    const uguali = (a: string, b: string) => a.length === b.length && timingSafeEqual(Buffer.from(a), Buffer.from(b));
    if (!this.valori('SUPERADMIN_USER').some((x) => uguali(x, u))) return false;
    for (const h of this.valori('SUPERADMIN_PASS_HASH')) {
      try { if (await bcrypt.compare(password, this.hash(h).replace(/^\$2y\$/, '$2b$'))) return true; } catch { /* hash non valido */ }
    }
    return false;
  }

  /** Codici gia' accettati: ognuno vale una volta sola nella sua finestra di 90 secondi. */
  private readonly codiciUsati = new Map<string, number>();

  controllaCodice(codice: string): boolean {
    const s = this.valori('SUPERADMIN_TOTP_SECRET')[0];
    const c = codice.replace(/\s+/g, '');
    const ora = Date.now();
    for (const [k, scade] of this.codiciUsati) if (scade < ora) this.codiciUsati.delete(k);
    if (!s || this.codiciUsati.has(c) || !verificaTotp(s, c)) return false;
    this.codiciUsati.set(c, ora + 90_000);
    return true;
  }

  // ─── Sessione (cookie dedicato, solo impronta nel server) ─────────────────

  apri(fase: SessioneSa['fase'], vecchio?: string): string {
    if (vecchio) this.sessioni.delete(impronta(vecchio));
    const t = randomBytes(32).toString('hex');
    this.sessioni.set(impronta(t), { fase, ultimo: Date.now() });
    return t;
  }

  fase(token: string | undefined): SessioneSa['fase'] | null {
    if (!token) return null;
    const s = this.sessioni.get(impronta(token));
    if (!s) return null;
    if (Date.now() - s.ultimo > DURATA_MS) { this.sessioni.delete(impronta(token)); return null; }
    if (s.fase === 'completa') s.ultimo = Date.now();
    return s.fase;
  }

  chiudi(token: string | undefined) { if (token) this.sessioni.delete(impronta(token)); }

  // ─── Tenant ────────────────────────────────────────────────────────────────

  private q(sql: string, p: unknown[] = []) { return senzaIsolamento(() => this.ds.query(sql, p)); }

  private async aziendeAdmin(userId: number, principale: number | null, globale: boolean) {
    const collegate: number[] = (await this.q('SELECT company_id FROM user_companies WHERE user_id = $1', [userId])).map((r: any) => r.company_id);
    if (globale) return this.q('SELECT id, name, is_active FROM companies ORDER BY name, id');
    const ids = [...new Set([...(principale !== null ? [principale] : []), ...collegate.filter((x) => x > 0)])];
    return ids.length ? this.q('SELECT id, name, is_active FROM companies WHERE id = ANY($1::int[]) ORDER BY name, id', [ids]) : [];
  }

  async elencoAdmin() {
    const admin = await this.q(`SELECT id, username, name, email, company_id, is_global, is_active, last_login, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC, id DESC`);
    for (const a of admin) {
      a.companies = await this.aziendeAdmin(a.id, a.company_id, a.is_global === true);
      const ids = a.companies.map((c: any) => c.id);
      a.employees_count = ids.length ? (await this.q('SELECT COUNT(*)::int AS n FROM employees WHERE is_active = true AND company_id = ANY($1::int[])', [ids]))[0].n : 0;
    }
    return admin;
  }

  tutteLeAziende() { return this.q('SELECT id, name, is_active FROM companies ORDER BY name, id'); }

  private async benvenuto(email: string, nome: string, azienda: string, token: string): Promise<boolean> {
    if (!this.mailer.configurato()) return false;
    const url = urlPubblico(`/auth/reset-password.php?token=${encodeURIComponent(token)}`);
    const html = `<p>Ciao ${escapeHtml(nome)},</p><p>E' stato creato un account amministratore per <strong>${escapeHtml(azienda)}</strong> sul portale Connecteed HR.</p>`
      + '<p>Per attivarlo e impostare la tua password clicca qui sotto (link valido 48 ore):</p>'
      + `<p><a href="${escapeHtml(url)}" style="display:inline-block;padding:12px 24px;background:#0b3aa4;color:white;border-radius:8px;text-decoration:none;font-weight:600;">Attiva account</a></p>`
      + `<p style="font-size:12px;color:#64748b;">Se il bottone non funziona copia questo link: ${escapeHtml(url)}</p>`;
    const testo = `Ciao ${nome},\n\nE' stato creato un account amministratore per ${azienda} sul portale Connecteed HR.\n\nAttiva il tuo account impostando la password al seguente link (valido 48h):\n${url}\n`;
    return this.mailer.invia(email, nome, 'Benvenuto su Connecteed HR — attiva il tuo account', html, testo);
  }

  private async tokenPrimoAccesso(userId: number, ip: string | null, ua: string): Promise<string> {
    const token = randomBytes(32).toString('hex');
    await this.q(`INSERT INTO password_reset_tokens (user_type, user_id, token, expires_at, ip_address, user_agent) VALUES ('admin', $1, $2, CURRENT_TIMESTAMP + interval '48 hours', $3, $4)`,
      [userId, impronta(token), ip, ua.slice(0, 255)]);
    return token;
  }

  async creaTenant(d: Record<string, string>, ip: string | null, ua: string): Promise<{ success: true; email_sent: boolean } | { success: false; error: string }> {
    const azienda = String(d.company_name ?? '').trim();
    const nome = String(d.admin_name ?? '').trim();
    const email = String(d.admin_email ?? '').trim();
    const username = String(d.admin_username ?? '').trim();
    if (azienda === '') return { success: false, error: 'Nome azienda obbligatorio' };
    if (nome === '') return { success: false, error: 'Nome admin obbligatorio' };
    if (!EMAIL.test(email)) return { success: false, error: 'Email non valida' };
    if (!/^[a-zA-Z0-9_.]{3,50}$/.test(username)) return { success: false, error: 'Username non valido (3-50 char, lettere/numeri/._)' };
    if ((await this.q('SELECT 1 FROM users WHERE username = $1', [username])).length) return { success: false, error: 'Username gia in uso' };
    if ((await this.q('SELECT 1 FROM users WHERE lower(email) = lower($1)', [email])).length) return { success: false, error: 'Email gia in uso su un altro utente' };
    let userId: number;
    let cid: number;
    try {
      ({ cid, userId } = await senzaIsolamento(() => this.ds.transaction(async (m) => {
        const [c] = await m.query('INSERT INTO companies (name, is_active, needs_setup) VALUES ($1, true, false) RETURNING id', [azienda.slice(0, 100)]);
        const [u] = await m.query(
          `INSERT INTO users (company_id, username, password_hash, role, name, email, is_active) VALUES ($1, $2, $3, 'admin', $4, $5, true) RETURNING id`,
          [c.id, username, await hashPassword(randomBytes(16).toString('hex')), nome.slice(0, 100), email]);
        return { cid: c.id as number, userId: u.id as number };
      })));
    } catch (e: any) {
      this.logger.error(`creazione tenant: ${e?.message ?? e}`);
      return { success: false, error: 'Errore durante la creazione' };
    }
    const inviata = await this.benvenuto(email, nome, azienda, await this.tokenPrimoAccesso(userId, ip, ua));
    await this.audit.log('superadmin_tenant_created', 'system', 0, { ip, userAgent: ua, sessioneId: null }, 'company', cid, null, { admin_user_id: userId, email_sent: inviata }).catch(() => undefined);
    return { success: true, email_sent: inviata };
  }

  async reinviaBenvenuto(userId: number, ip: string | null, ua: string): Promise<Esito> {
    const [u] = await this.q(`SELECT id, name, email, company_id FROM users WHERE id = $1 AND role = 'admin'`, [userId]);
    if (!u) return { success: false, error: 'Admin non trovato' };
    if (!u.email) return { success: false, error: 'Admin senza email' };
    const [c] = await this.q('SELECT name FROM companies WHERE id = $1', [u.company_id]);
    const ok = await this.benvenuto(u.email, u.name ?? '', c?.name ?? '', await this.tokenPrimoAccesso(userId, ip, ua));
    return ok ? { success: true } : { success: false, error: 'Invio email fallito: SMTP non configurato o non raggiungibile (controlla le env SMTP_*)' };
  }

  async stato(userId: number, attivo: boolean): Promise<Esito> {
    const [u] = await this.q(`SELECT id, company_id FROM users WHERE id = $1 AND role = 'admin'`, [userId]);
    if (!u) return { success: false, error: 'Admin non trovato' };
    const aziende = await this.aziendeAdmin(userId, u.company_id, false);
    await senzaIsolamento(() => this.ds.transaction(async (m) => {
      await m.query('UPDATE users SET is_active = $2 WHERE id = $1', [userId, attivo]);
      for (const c of aziende) await m.query('UPDATE companies SET is_active = $2 WHERE id = $1', [c.id, attivo]);
    }));
    await this.audit.log(attivo ? 'superadmin_admin_activated' : 'superadmin_admin_deactivated', 'system', 0, { ip: null, userAgent: '', sessioneId: null }, 'user', userId).catch(() => undefined);
    return { success: true };
  }

  async elimina(userId: number): Promise<Esito> {
    const [u] = await this.q(`SELECT id, company_id, is_global FROM users WHERE id = $1 AND role = 'admin'`, [userId]);
    if (!u) return { success: false, error: 'Admin non trovato' };
    if (u.is_global) return { success: false, error: "Admin globale: assegnagli prima le aziende da eliminare (altrimenti verrebbero eliminate tutte)" };
    const aziende = await this.aziendeAdmin(userId, u.company_id, false);
    try {
      await senzaIsolamento(() => this.ds.transaction(async (m) => {
        for (const c of aziende) {
          for (const t of DA_ELIMINARE) await m.query(`DELETE FROM ${t} WHERE company_id = $1`, [c.id]);
          await m.query('DELETE FROM user_companies WHERE company_id = $1', [c.id]);
          await m.query('DELETE FROM companies WHERE id = $1', [c.id]);
        }
        await m.query('DELETE FROM user_companies WHERE user_id = $1', [userId]);
        await m.query('DELETE FROM users WHERE id = $1', [userId]);
      }));
    } catch (e: any) {
      this.logger.error(`eliminazione tenant: ${e?.message ?? e}`);
      return { success: false, error: 'Errore eliminazione: dati collegati ad altre aziende' };
    }
    await this.audit.log('superadmin_admin_deleted', 'system', 0, { ip: null, userAgent: '', sessioneId: null }, 'user', userId, null, null, 'critical').catch(() => undefined);
    return { success: true };
  }

  async assegnaAziende(userId: number, ids: unknown[]): Promise<Esito> {
    if (!(await this.q(`SELECT 1 FROM users WHERE id = $1 AND role = 'admin'`, [userId])).length) return { success: false, error: 'Admin non trovato' };
    const lista = [...new Set(ids.map((x) => Number.parseInt(String(x), 10) || 0).filter((x) => x > 0))];
    await senzaIsolamento(() => this.ds.transaction(async (m) => {
      await m.query('DELETE FROM user_companies WHERE user_id = $1', [userId]);
      for (const c of lista) await m.query('INSERT INTO user_companies (user_id, company_id) SELECT $1, id FROM companies WHERE id = $2 ON CONFLICT DO NOTHING', [userId, c]);
    }));
    return { success: true };
  }
}
