import { Injectable, Logger } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { erroriPassword, hashPassword, passwordTemporanea, verificaPassword } from '../../common/sicurezza/password';
import { urlPubblico } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { escapeHtml, MailerService } from '../notifiche/mailer.service';
import { AuthService } from './auth.service';
import { SessioniService } from './sessioni.service';

export type EsitoPassword = { ok: true } | { ok: false; errore?: string; errori?: string[] };

const impronta = (t: string) => createHash('sha256').update(t).digest('hex');

/**
 * Cambio e recupero della password, come Auth.php del PHP.
 *
 * Differenze volute (sicurezza):
 *  - il token del link di recupero si salva come impronta SHA-256, non in chiaro;
 *  - il link usa APP_URL e non l'header Host della richiesta;
 *  - dopo un cambio o un recupero le altre sessioni dell'account si chiudono.
 */
@Injectable()
export class PasswordService {
  private readonly logger = new Logger('Password');

  constructor(
    private readonly ds: DataSource,
    private readonly audit: AuditService,
    private readonly mailer: MailerService,
    private readonly auth: AuthService,
    private readonly sessioni: SessioniService,
  ) {}

  /** Cambio dalla propria area (profilo o cambio obbligatorio al primo accesso). */
  async cambia(tipo: 'user' | 'employee', id: number, ruolo: string, attuale: string, nuova: string, conferma: string,
    ctx: ContestoAudit & { sessioneId: string }): Promise<EsitoPassword> {
    if (nuova !== conferma) return { ok: false, errore: 'La nuova password e la conferma non coincidono' };
    const errori = erroriPassword(nuova);
    if (errori.length) return { ok: false, errori };
    const tabella = tipo === 'user' ? 'users' : 'employees';
    const [r] = await senzaIsolamento(() => this.ds.query(`SELECT password_hash FROM ${tabella} WHERE id = $1`, [id]));
    if (!r || !(await verificaPassword(attuale, r.password_hash))) return { ok: false, errore: 'Password attuale non corretta' };
    if (await verificaPassword(nuova, r.password_hash)) return { ok: false, errore: 'La nuova password deve essere diversa dalla precedente' };
    const hash = await hashPassword(nuova);
    await senzaIsolamento(() => this.ds.query(
      `UPDATE ${tabella} SET password_hash = $2, must_change_password = false, password_changed_at = CURRENT_TIMESTAMP WHERE id = $1`,
      [id, hash]));
    await this.audit.log('password_change', ruolo, id, ctx);
    await this.sessioni.chiudiTutte(tipo, id, 'password_cambiata', ctx.sessioneId);
    return { ok: true };
  }

  /**
   * Richiesta di recupero: risposta sempre uguale, che l'account esista o no.
   * Username o codice fiscale indicano un account preciso; un'email condivisa
   * da piu' account riceve un link per ciascuno.
   */
  async richiedi(identificativo: string, ctx: ContestoAudit): Promise<void> {
    let account = await this.auth.trovaAccount(identificativo);
    const esatti = account.filter((a) => !a.perEmail);
    if (esatti.length) account = esatti;
    for (const a of account) {
      try {
        await this.inviaLink(a.tabella, a.riga, ctx);
      } catch (e: any) {
        this.logger.error(`recupero password non riuscito: ${e?.message ?? e}`);
      }
    }
  }

  private async inviaLink(tabella: 'users' | 'employees', r: any, ctx: ContestoAudit): Promise<void> {
    const tipo = tabella === 'users' ? r.role : 'employee';
    const nome = tabella === 'users' ? String(r.name ?? '') : `${r.first_name ?? ''} ${r.last_name ?? ''}`.trim();
    const username = tabella === 'users' ? String(r.username) : String(r.username || r.fiscal_code);
    return senzaIsolamento(async () => {
      // Una richiesta ancora aperta negli ultimi 15 minuti: niente doppioni.
      const [aperta] = await this.ds.query(
        `SELECT id FROM password_reset_requests WHERE user_type = $1 AND user_id = $2 AND status = 'pending'
           AND created_at > CURRENT_TIMESTAMP - interval '15 minutes'`, [tipo, r.id]);
      if (aperta) return;
      const token = randomBytes(32).toString('hex');
      const [t] = await this.ds.query(
        `INSERT INTO password_reset_tokens (user_type, user_id, token, expires_at, ip_address, user_agent)
         VALUES ($1, $2, $3, CURRENT_TIMESTAMP + interval '1 hour', $4, $5) RETURNING id`,
        [tipo, r.id, impronta(token), ctx.ip ?? null, ctx.userAgent ?? null]);
      let inviata = false;
      if (r.email && this.mailer.configurato()) {
        const link = urlPubblico(`/auth/reset-password.php?token=${encodeURIComponent(token)}`);
        const html = `<p>Ciao ${escapeHtml(nome)},</p>`
          + `<p>Hai richiesto il reset della password per il tuo account Connecteed HR <strong>${escapeHtml(username)}</strong>.</p>`
          + `<p><a href="${link}">Clicca qui per impostare una nuova password</a></p>`
          + '<p>Il link è valido per 1 ora. Se non hai effettuato tu la richiesta puoi ignorare questa email.</p>';
        const testo = `Ciao ${nome},\n\nReset password Connecteed HR (account ${username}): ${link}\nLink valido 1 ora.`;
        inviata = await this.mailer.invia(r.email, nome, 'Recupero password Connecteed HR', html, testo);
      }
      await this.ds.query(
        `INSERT INTO password_reset_requests (user_type, user_id, token_id, requested_ip, status, resolved_at, notes)
         VALUES ($1, $2, $3, $4, $5, ${inviata ? 'CURRENT_TIMESTAMP' : 'NULL'}, $6)`,
        [tipo, r.id, t.id, ctx.ip ?? null, inviata ? 'sent' : 'pending', inviata ? 'Email inviata automaticamente' : null]);
      await this.audit.log(inviata ? 'password_reset_email_sent' : 'password_reset_requested', tipo, r.id, ctx, null, null, null,
        { ip: ctx.ip, email_sent: inviata });
    });
  }

  /** Dati del link di recupero, se valido (non scaduto, non usato). */
  async verificaToken(token: string): Promise<{ id: number; user_type: string; user_id: number; nome: string; username: string } | null> {
    if (!/^[a-f0-9]{64}$/.test(token)) return null;
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT prt.id, prt.user_type, prt.user_id,
              CASE WHEN prt.user_type <> 'employee' THEN u.name ELSE e.first_name END AS nome,
              CASE WHEN prt.user_type <> 'employee' THEN u.username ELSE COALESCE(NULLIF(e.username, ''), e.fiscal_code) END AS username
         FROM password_reset_tokens prt
         LEFT JOIN users u ON prt.user_type <> 'employee' AND prt.user_id = u.id
         LEFT JOIN employees e ON prt.user_type = 'employee' AND prt.user_id = e.id
        WHERE prt.token = $1 AND prt.expires_at > CURRENT_TIMESTAMP AND prt.used_at IS NULL`, [impronta(token)]));
    return r ?? null;
  }

  async completa(token: string, password: string, conferma: string, ctx: ContestoAudit): Promise<EsitoPassword> {
    const t = await this.verificaToken(token);
    if (!t) return { ok: false, errore: 'Link non valido o scaduto' };
    if (password !== conferma) return { ok: false, errore: 'Le password non coincidono' };
    const errori = erroriPassword(password);
    if (errori.length) return { ok: false, errori };
    const tipo = t.user_type === 'employee' ? 'employee' : 'user';
    const tabella = tipo === 'employee' ? 'employees' : 'users';
    await senzaIsolamento(async () => {
      await this.ds.query(
        `UPDATE ${tabella} SET password_hash = $2, failed_attempts = 0, locked_until = NULL, password_changed_at = CURRENT_TIMESTAMP WHERE id = $1`,
        [t.user_id, await hashPassword(password)]);
      await this.ds.query('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = $1', [t.id]);
      await this.ds.query(`UPDATE password_reset_requests SET status = 'completed', resolved_at = CURRENT_TIMESTAMP WHERE token_id = $1`, [t.id]);
    });
    await this.audit.log('password_reset_completed', t.user_type, t.user_id, ctx);
    await this.sessioni.chiudiTutte(tipo, t.user_id, 'password_recuperata');
    return { ok: true };
  }

  // ─── Operazioni dell'HR (admin/password-resets.php) ───────────────────

  /**
   * Account che l'HR puo' reimpostare: utenti e dipendenti attivi della sua
   * azienda. Correzione: nel PHP qualsiasi id (anche di altre aziende o
   * dell'admin globale) e gli admin senza azienda erano nell'elenco.
   */
  async accountReimpostabili(companyId: number, veroGlobale: boolean) {
    const users = await senzaIsolamento(() => this.ds.query(
      `SELECT id, username, name, email, role FROM users WHERE is_active = true AND (company_id = $1 ${veroGlobale ? 'OR company_id IS NULL' : ''}) ORDER BY name`,
      [companyId]));
    const employees = await this.ds.query(
      `SELECT id, username, fiscal_code, concat(last_name, ' ', first_name) AS name, email FROM employees
        WHERE is_active = true AND company_id = $1 ORDER BY last_name, first_name`, [companyId]);
    return { users, employees };
  }

  /** Password temporanea (Auth::adminResetPassword), cambio obbligatorio al prossimo accesso. */
  async reimpostaDaAdmin(tipo: string, id: number, companyId: number, veroGlobale: boolean, ctx: ContestoAudit & { id: number; tipo?: string }): Promise<{ ok: true; password: string } | { ok: false; errore: string }> {
    const { users, employees } = await this.accountReimpostabili(companyId, veroGlobale);
    const ammesso = tipo === 'employee' ? employees.some((e: any) => e.id === id) : tipo === 'admin' && users.some((u: any) => u.id === id && u.role === tipo);
    if (!ammesso) return { ok: false, errore: 'Utente non trovato' };
    const tabella = tipo === 'employee' ? 'employees' : 'users';
    const password = passwordTemporanea();
    try {
      await senzaIsolamento(async () => this.ds.query(
        `UPDATE ${tabella} SET password_hash = $2, failed_attempts = 0, locked_until = NULL, must_change_password = true, password_changed_at = CURRENT_TIMESTAMP WHERE id = $1`,
        [id, await hashPassword(password)]));
      await this.audit.log('admin_password_reset', ctx.tipo ?? 'admin', ctx.id, ctx, tabella, id, null, { target_user_type: tipo, target_user_id: id });
      await this.sessioni.chiudiTutte(tipo === 'employee' ? 'employee' : 'user', id, 'password_reimpostata');
      return { ok: true, password };
    } catch {
      return { ok: false, errore: 'Errore durante il reset' };
    }
  }

  /** Richiesta di recupero di un account dell'azienda (stesso perimetro di countPendingResetRequests, senza admin di altre aziende). */
  private async richiestaDellAzienda(id: number, companyId: number, veroGlobale: boolean) {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT prr.* FROM password_reset_requests prr
         LEFT JOIN users u ON prr.user_type <> 'employee' AND prr.user_id = u.id
         LEFT JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
        WHERE prr.id = $1 AND ((prr.user_type = 'employee' AND e.company_id = $2)
          OR (prr.user_type <> 'employee' AND (u.company_id = $2 ${veroGlobale ? 'OR u.company_id IS NULL' : ''})))`, [id, companyId]));
    return r ?? null;
  }

  /** Approvazione di una richiesta: link valido 24 ore (Auth::approveResetRequest). */
  async approvaRichiesta(id: number, companyId: number, veroGlobale: boolean, ctx: ContestoAudit & { id: number; tipo?: string }): Promise<{ ok: true; link: string } | { ok: false; errore: string }> {
    const r = await this.richiestaDellAzienda(id, companyId, veroGlobale);
    if (!r || r.status !== 'pending') return { ok: false, errore: 'Richiesta non trovata' };
    const token = randomBytes(32).toString('hex');
    try {
      await senzaIsolamento(async () => {
        const [t] = await this.ds.query(
          `INSERT INTO password_reset_tokens (user_type, user_id, token, expires_at, ip_address, user_agent)
           VALUES ($1, $2, $3, CURRENT_TIMESTAMP + interval '24 hours', $4, 'Admin approved') RETURNING id`,
          [r.user_type, r.user_id, impronta(token), ctx.ip ?? null]);
        await this.ds.query(`UPDATE password_reset_requests SET status = 'sent', token_id = $2, resolved_by = $3, resolved_at = CURRENT_TIMESTAMP WHERE id = $1`,
          [id, t.id, ctx.id]);
      });
      await this.audit.log('password_reset_approved', ctx.tipo ?? 'admin', ctx.id, ctx, 'password_reset_requests', id);
      return { ok: true, link: urlPubblico(`/auth/reset-password.php?token=${token}`) };
    } catch {
      return { ok: false, errore: "Errore durante l'approvazione" };
    }
  }

  async rifiutaRichiesta(id: number, companyId: number, veroGlobale: boolean, note: string, ctx: ContestoAudit & { id: number; tipo?: string }): Promise<{ ok: true } | { ok: false; errore: string }> {
    if (!(await this.richiestaDellAzienda(id, companyId, veroGlobale))) return { ok: false, errore: 'Richiesta non trovata' };
    await senzaIsolamento(() => this.ds.query(
      `UPDATE password_reset_requests SET status = 'rejected', resolved_by = $2, resolved_at = CURRENT_TIMESTAMP, notes = $3 WHERE id = $1`, [id, ctx.id, note]));
    await this.audit.log('password_reset_rejected', ctx.tipo ?? 'admin', ctx.id, ctx, 'password_reset_requests', id);
    return { ok: true };
  }
}

