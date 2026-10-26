import { Injectable, Logger } from '@nestjs/common';
import * as fs from 'fs';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import { erroriPassword, hashPassword, passwordTemporanea } from '../../common/sicurezza/password';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { SessioniService } from '../auth/sessioni.service';
import { escapeHtml, MailerService } from '../notifiche/mailer.service';
import { SaldiService, TIPI_SALDO } from '../saldi/saldi.service';

type Ctx = ContestoAudit & { tipo: string; id: number };
export type Esito = { success: true; id?: number } | { success: false; error: string };

const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;
const DATA = /^\d{4}-\d{2}-\d{2}$/;
const vuotoNull = (v: unknown) => (v === '' || v === null || v === undefined ? null : v);
const numero = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number.parseFloat(String(v).replace(',', '.')) || 0);

/** Codice fiscale italiano con carattere di controllo (Employee::validateFiscalCode). */
export function codiceFiscaleValido(cf: string): boolean {
  const c = String(cf ?? '').trim().toUpperCase();
  if (!/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/.test(c)) return false;
  const DISPARI: Record<string, number> = {
    0: 1, 1: 0, 2: 5, 3: 7, 4: 9, 5: 13, 6: 15, 7: 17, 8: 19, 9: 21, A: 1, B: 0, C: 5, D: 7, E: 9, F: 13, G: 15, H: 17, I: 19, J: 21, K: 2, L: 4, M: 18,
    N: 20, O: 11, P: 3, Q: 6, R: 8, S: 12, T: 14, U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23,
  };
  let somma = 0;
  for (let i = 0; i < 15; i++) {
    const ch = c[i];
    somma += i % 2 === 0 ? DISPARI[ch] : /\d/.test(ch) ? Number(ch) : ch.charCodeAt(0) - 65;
  }
  return c[15] === String.fromCharCode(65 + (somma % 26));
}

/**
 * Anagrafica dei dipendenti lato HR (Employee.php e le parti di User.php
 * per l'accesso HR). Ogni operazione resta nell'azienda di lavoro.
 */
@Injectable()
export class AnagraficaService {
  private readonly logger = new Logger('Anagrafica');
  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly mailer: MailerService,
    private readonly saldi: SaldiService, private readonly sessioni: SessioniService) {}

  /** Employee::getById, solo nell'azienda di lavoro. */
  async perId(id: number, cid: number) {
    const [e] = await this.ds.query(
      `SELECT e.*, u.name AS created_by_name, d.name AS department_name, d.code AS department_code FROM employees e
         LEFT JOIN users u ON e.created_by = u.id LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = $1 AND e.company_id = $2`, [id, cid]);
    return e ?? null;
  }

  /** Employee::getAll. */
  elenco(cid: number, soloAttivi: boolean, ricerca = '') {
    const p: unknown[] = [cid];
    let sql = `SELECT e.*, d.name AS department_name, d.code AS department_code,
        (SELECT COUNT(*) FROM documents doc WHERE doc.employee_id = e.id)::int AS document_count
      FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.company_id = $1`;
    if (soloAttivi) sql += ' AND e.is_active = true';
    if (ricerca) {
      p.push(`%${ricerca}%`);
      sql += ` AND (e.fiscal_code ILIKE $2 OR e.first_name ILIKE $2 OR e.last_name ILIKE $2 OR e.email ILIKE $2 OR e.username ILIKE $2)`;
    }
    return this.ds.query(`${sql} ORDER BY e.last_name ASC, e.first_name ASC, e.id ASC`, p);
  }

  private async reparto(id: unknown, cid: number) {
    if (!id) return true;
    return (await this.ds.query('SELECT 1 FROM departments WHERE id = $1 AND company_id = $2 AND is_active = true', [id, cid])).length > 0;
  }

  /**
   * Employee::create con password temporanea e email di benvenuto.
   * Correzione: il reparto deve essere dell'azienda (il PHP accettava un
   * reparto attivo di qualsiasi azienda).
   */
  async crea(d: Record<string, any>, cid: number, ctx: Ctx): Promise<{ success: true; id: number; temp_password: string | null; email_sent: boolean; email_error: string | null } | { success: false; error: string }> {
    const username = String(d.username ?? '');
    if (Buffer.byteLength(username) < 3) return { success: false, error: 'Username deve avere almeno 3 caratteri' };
    if (!/^[a-zA-Z0-9_.]+$/.test(username) || username.length > 50) return { success: false, error: 'Username può contenere solo lettere, numeri, underscore e punti' };
    // Password scelta dall'HR (assunzione diretta) o generata. Correzione: la
    // scelta segue la stessa politica degli altri account (prima bastavano 8 caratteri).
    const scelta = String(d.password ?? '');
    if (scelta !== '') {
      const errori = erroriPassword(scelta);
      if (errori.length) return { success: false, error: errori[0] };
    }
    const password = scelta !== '' ? scelta : passwordTemporanea(14);
    if (!d.fiscal_code || !codiceFiscaleValido(d.fiscal_code)) return { success: false, error: 'Codice fiscale non valido' };
    if (!d.first_name) return { success: false, error: 'Nome obbligatorio' };
    if (!d.last_name) return { success: false, error: 'Cognome obbligatorio' };
    if (!d.department_id) return { success: false, error: 'Reparto obbligatorio' };
    if (!(await this.reparto(d.department_id, cid))) return { success: false, error: 'Reparto non valido o non attivo' };
    if ((await this.ds.query('SELECT 1 FROM employees WHERE username = $1 AND company_id = $2', [username, cid])).length) return { success: false, error: 'Username gia esistente in questa azienda' };
    const cf = String(d.fiscal_code).trim().toUpperCase();
    if ((await this.ds.query('SELECT 1 FROM employees WHERE fiscal_code = $1 AND company_id = $2', [cf, cid])).length) return { success: false, error: 'Codice fiscale gia registrato in questa azienda' };
    if (!d.email) return { success: false, error: 'Email obbligatoria: necessaria per inviare le credenziali al dipendente' };
    if (!EMAIL.test(String(d.email))) return { success: false, error: 'Email non valida' };
    for (const k of ['hire_date', 'birth_date', 'probation_end_date']) {
      if (d[k] && !DATA.test(String(d[k]))) return { success: false, error: 'Data non valida' };
    }
    const iban = d.iban ? String(d.iban).replace(/\s+/g, '').toUpperCase() : null;
    let id: number;
    try {
      [{ id }] = await this.ds.query(
        `INSERT INTO employees (company_id, username, password_hash, fiscal_code, first_name, last_name, email, phone, department_id, position, hire_date,
           probation_end_date, address, birth_date, job_level, ral_amount, monthly_salary, iban, ccnl_id, ferie_year_override, permessi_year_override,
           working_days, hours_per_day, smart_working_days, buoni_pasto_min_hours_override, buoni_pasto_sw_eligible_override, buoni_pasto_excluded,
           is_active, must_change_password, created_by)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23,$24,$25,$26,$27,true,true,$28) RETURNING id`,
        [cid, username, await hashPassword(password), cf, String(d.first_name).trim(), String(d.last_name).trim(), vuotoNull(d.email), vuotoNull(d.phone),
          vuotoNull(d.department_id), vuotoNull(d.position), vuotoNull(d.hire_date), vuotoNull(d.probation_end_date), vuotoNull(d.address), vuotoNull(d.birth_date),
          vuotoNull(d.job_level), numero(d.ral_amount), numero(d.monthly_salary), iban, vuotoNull(d.ccnl_id), numero(d.ferie_year_override),
          numero(d.permessi_year_override), vuotoNull(d.working_days), numero(d.hours_per_day), d.smart_working_days ?? null,
          numero(d.buoni_pasto_min_hours_override), d.buoni_pasto_sw_eligible_override === null || d.buoni_pasto_sw_eligible_override === undefined || d.buoni_pasto_sw_eligible_override === '' ? null : !!Number(d.buoni_pasto_sw_eligible_override),
          !!d.buoni_pasto_excluded, ctx.id]);
    } catch (e: any) {
      this.logger.error(`creazione dipendente non riuscita: ${e?.message ?? e}`);
      return { success: false, error: 'Errore durante la creazione del dipendente' };
    }
    fs.mkdirSync(cartellaStorage('documents', String(id)), { recursive: true, mode: 0o755 });
    // Nuovo assunto di quest'anno: ferie e permessi partono da zero dal mese di assunzione.
    const assunzione = (vuotoNull(d.hire_date) as string | null) ?? oggiRoma();
    if (assunzione.slice(0, 4) === oggiRoma().slice(0, 4)) {
      for (const t of TIPI_SALDO) await this.saldi.impostaResiduo(id, cid, Number(assunzione.slice(0, 4)), t, 0, assunzione, ctx.id).catch(() => undefined);
    }
    await this.audit.log('employee_created', ctx.tipo, ctx.id, ctx, 'employee', id, null, { username, fiscal_code: cf, name: `${d.first_name} ${d.last_name}` });
    let inviata = false;
    let errore: string | null = null;
    if (this.mailer.configurato()) {
      const login = urlPubblico('/auth/login.php');
      const nome = `${d.first_name} ${d.last_name}`.trim();
      const html = `<p>Ciao ${escapeHtml(nome)},</p><p>È stato creato un account per te su Connecteed HR. Queste sono le tue credenziali temporanee di accesso:</p><ul>`
        + `<li><strong>Username:</strong> ${escapeHtml(username)}</li><li><strong>Password temporanea:</strong> <code>${escapeHtml(password)}</code></li></ul>`
        + `<p><a href="${login}">Accedi al portale</a></p><p>Al primo accesso ti verrà richiesto di impostare una nuova password personale.</p>`
        + '<p>Per motivi di sicurezza non condividere queste credenziali con altre persone.</p>';
      const testo = `Ciao ${nome},\n\nÈ stato creato un account per te su Connecteed HR.\n\nUsername: ${username}\nPassword temporanea: ${password}\n\nLogin: ${login}\n\nAl primo accesso ti verrà richiesto di impostare una nuova password.`;
      inviata = await this.mailer.invia(String(d.email), nome, 'Le tue credenziali di accesso Connecteed HR', html, testo);
      if (!inviata) errore = 'Invio email fallito';
    } else errore = 'SMTP non configurato';
    if (!inviata) this.logger.warn(`credenziali non inviate al dipendente #${id}: ${errore}`);
    return { success: true, id, temp_password: scelta !== '' ? null : password, email_sent: inviata, email_error: errore };
  }

  /** Employee::update (stesse regole: campi vuoti non cancellano nome e cognome, prova riaperta se la data cambia). */
  async aggiorna(id: number, d: Record<string, any>, cid: number, ctx: Ctx): Promise<Esito> {
    const e = await this.perId(id, cid);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    const c: Record<string, unknown> = {};
    if (d.username !== undefined && d.username !== e.username) {
      const u = String(d.username);
      if (Buffer.byteLength(u) < 3) return { success: false, error: 'Username deve avere almeno 3 caratteri' };
      if (!/^[a-zA-Z0-9_.]+$/.test(u) || u.length > 50) return { success: false, error: 'Username può contenere solo lettere, numeri, underscore e punti' };
      if ((await this.ds.query('SELECT 1 FROM employees WHERE username = $1 AND company_id = $2 AND id <> $3', [u, cid, id])).length) return { success: false, error: 'Username gia esistente in questa azienda' };
      c.username = u;
    }
    if (d.fiscal_code !== undefined) {
      const cf = String(d.fiscal_code).trim().toUpperCase();
      if (cf !== e.fiscal_code) {
        if (!codiceFiscaleValido(cf)) return { success: false, error: 'Codice fiscale non valido' };
        if ((await this.ds.query('SELECT 1 FROM employees WHERE fiscal_code = $1 AND company_id = $2 AND id <> $3', [cf, cid, id])).length) return { success: false, error: 'Codice fiscale gia registrato in questa azienda' };
        c.fiscal_code = cf;
      }
    }
    if (d.first_name) c.first_name = String(d.first_name).trim().slice(0, 50);
    if (d.last_name) c.last_name = String(d.last_name).trim().slice(0, 50);
    if (d.email !== undefined && d.email !== null) {
      if (d.email && !EMAIL.test(String(d.email))) return { success: false, error: 'Email non valida' };
      c.email = d.email || null;
    }
    if (d.phone !== undefined && d.phone !== null) c.phone = d.phone || null;
    if ('department_id' in d) {
      if (d.department_id && !(await this.reparto(d.department_id, cid))) return { success: false, error: 'Reparto non valido o non attivo' };
      c.department_id = d.department_id || null;
    }
    if (d.position !== undefined && d.position !== null) c.position = d.position || null;
    for (const k of ['hire_date', 'birth_date', 'probation_end_date']) {
      if (k in d && d[k] && !DATA.test(String(d[k]))) return { success: false, error: 'Data non valida' };
    }
    if (d.hire_date !== undefined && d.hire_date !== null) c.hire_date = d.hire_date || null;
    if ('probation_end_date' in d) {
      const nuova = d.probation_end_date || null;
      const vecchia = e.probation_end_date ?? null;
      c.probation_end_date = nuova;
      if (String(nuova ?? '') !== String(vecchia ?? '')) {
        c.probation_alert_notified_at = null;
        if (e.probation_decision && nuova !== null && String(nuova) > String(vecchia ?? '')) {
          c.probation_decision = null;
          c.probation_decided_at = null;
          c.probation_decided_by_user_id = null;
        }
      }
    }
    if ('address' in d) c.address = d.address || null;
    if ('birth_date' in d) c.birth_date = d.birth_date || null;
    if ('job_level' in d) c.job_level = d.job_level || null;
    if ('ral_amount' in d) c.ral_amount = numero(d.ral_amount);
    if ('monthly_salary' in d) c.monthly_salary = numero(d.monthly_salary);
    if ('iban' in d) c.iban = d.iban ? String(d.iban).replace(/\s+/g, '').toUpperCase() : null;
    if ('nfc_uid' in d) { const uid = String(d.nfc_uid ?? '').replace(/[^0-9A-Fa-f]/g, '').toUpperCase(); c.nfc_uid = uid || null; }
    for (const k of ['working_days', 'hours_per_day', 'smart_working_days', 'buoni_pasto_min_hours_override', 'ferie_year_override', 'permessi_year_override']) {
      if (k in d) c[k] = d[k];
    }
    if ('buoni_pasto_sw_eligible_override' in d) c.buoni_pasto_sw_eligible_override = d.buoni_pasto_sw_eligible_override === null ? null : !!Number(d.buoni_pasto_sw_eligible_override);
    if ('buoni_pasto_excluded' in d) c.buoni_pasto_excluded = !!d.buoni_pasto_excluded;
    if ('ccnl_id' in d) {
      if (d.ccnl_id && !(await this.ds.query('SELECT 1 FROM ccnl_templates WHERE id = $1 AND (company_id IS NULL OR company_id = $2)', [d.ccnl_id, cid])).length) {
        return { success: false, error: 'CCNL non valido' };
      }
      c.ccnl_id = d.ccnl_id || null;
    }
    if (d.is_active !== undefined && d.is_active !== null) c.is_active = !!d.is_active;
    const k = Object.keys(c);
    if (!k.length) return { success: false, error: 'Nessun dato da aggiornare' };
    try {
      await this.ds.query(`UPDATE employees SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [id, ...k.map((x) => c[x])]);
    } catch (err: any) {
      this.logger.error(`aggiornamento dipendente ${id}: ${err?.message ?? err}`);
      return { success: false, error: "Errore aggiornamento: dati non validi" };
    }
    if (e.is_active && c.is_active === false) await this.sessioni.chiudiTutte('employee', id, 'account_disattivato');
    await this.audit.log('employee_updated', ctx.tipo, ctx.id, ctx, 'employee', id, e, c);
    return { success: true };
  }

  async elimina(id: number, cid: number, ctx: Ctx): Promise<Esito> {
    const e = await this.perId(id, cid);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    try {
      await this.ds.query('DELETE FROM employees WHERE id = $1', [id]);
    } catch (err: any) {
      this.logger.error(`eliminazione dipendente ${id}: ${err?.message ?? err}`);
      return { success: false, error: "Errore durante l'eliminazione: il dipendente ha dati collegati. Disattivalo invece di eliminarlo." };
    }
    fs.rmSync(cartellaStorage('documents', String(id)), { recursive: true, force: true });
    await this.audit.log('employee_deleted', ctx.tipo, ctx.id, ctx, 'employee', id, e, null);
    return { success: true };
  }

  /**
   * Employee::resetPassword. Correzioni: solo dipendenti dell'azienda (il PHP
   * accettava qualsiasi id); cambio password obbligatorio e sessioni chiuse.
   */
  async reimpostaPassword(id: number, cid: number, ctx: Ctx): Promise<{ success: true; password: string } | { success: false; error: string }> {
    const e = await this.perId(id, cid);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    const password = passwordTemporanea(12);
    await this.ds.query('UPDATE employees SET password_hash = $2, failed_attempts = 0, locked_until = NULL, must_change_password = true WHERE id = $1',
      [id, await hashPassword(password)]);
    await this.audit.log('password_reset', ctx.tipo, ctx.id, ctx, 'employee', id);
    await this.sessioni.chiudiTutte('employee', id, 'password_reimpostata');
    return { success: true, password };
  }

  /** Utenza HR nata dal dipendente (User::getHrAccountForEmployee). */
  async accountHr(employeeId: number) {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT id, username, email, is_active, last_login, created_at FROM users WHERE employee_id = $1 AND role = 'admin' ORDER BY id DESC LIMIT 1`, [employeeId]));
    return r ?? null;
  }

  /** User::grantHrToEmployee: nuova utenza admin "<username>.hr" o riattivazione di quella revocata. */
  async rendiHr(e: any, cid: number, ctx: Ctx): Promise<{ success: true; username: string; password: string; reactivated: boolean } | { success: false; error: string }> {
    if (!e.is_active) return { success: false, error: "Il dipendente non e' attivo" };
    const esistente = await this.accountHr(e.id);
    if (esistente?.is_active) return { success: false, error: "Il dipendente ha gia' un accesso HR" };
    const password = passwordTemporanea(12);
    if (esistente) {
      await senzaIsolamento(async () => this.ds.query(
        'UPDATE users SET password_hash = $2, failed_attempts = 0, locked_until = NULL, is_active = true, must_change_password = true WHERE id = $1',
        [esistente.id, await hashPassword(password)]));
      await this.audit.log('hr_granted_to_employee', ctx.tipo, ctx.id, ctx, 'user', esistente.id, null, { employee_id: e.id, reactivated: true });
      return { success: true, username: esistente.username, password, reactivated: true };
    }
    const base = `${String(e.username).slice(0, 45)}.hr`;
    let username = base;
    for (let i = 2; (await senzaIsolamento(() => this.ds.query('SELECT 1 FROM users WHERE username = $1', [username]))).length; i++) username = `${base}${i}`;
    const email = String(e.email ?? '').trim();
    if (email && (await senzaIsolamento(() => this.ds.query('SELECT 1 FROM users WHERE lower(email) = lower($1)', [email]))).length) {
      return { success: false, error: "L'email del dipendente e' gia' usata da un altro utente staff: cambiala prima di renderlo HR" };
    }
    const [u] = await senzaIsolamento(async () => this.ds.query(
      `INSERT INTO users (company_id, username, password_hash, role, name, email, employee_id, is_active, must_change_password)
       VALUES ($1, $2, $3, 'admin', $4, $5, $6, true, true) RETURNING id`,
      [cid, username, await hashPassword(password), `${e.first_name} ${e.last_name}`.trim(), email || null, e.id]));
    await this.audit.log('hr_granted_to_employee', ctx.tipo, ctx.id, ctx, 'user', u.id, null, { employee_id: e.id });
    return { success: true, username, password, reactivated: false };
  }

  /** User::revokeHrFromEmployee. */
  async revocaHr(employeeId: number, ctx: Ctx): Promise<Esito> {
    const a = await this.accountHr(employeeId);
    if (!a || !a.is_active) return { success: false, error: 'Il dipendente non ha un accesso HR attivo' };
    if (a.id === ctx.id) return { success: false, error: 'Non puoi revocare il tuo stesso accesso HR' };
    await senzaIsolamento(() => this.ds.query('UPDATE users SET is_active = false WHERE id = $1', [a.id]));
    await this.sessioni.chiudiTutte('user', a.id, 'accesso_revocato');
    await this.audit.log('hr_revoked_from_employee', ctx.tipo, ctx.id, ctx, 'user', a.id, null, { employee_id: employeeId });
    return { success: true };
  }
}
