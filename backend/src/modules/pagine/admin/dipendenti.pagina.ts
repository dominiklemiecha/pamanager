import { Injectable } from '@nestjs/common';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { testoPdf } from '../../../common/file/testo-pdf';
import { verificaPassword } from '../../../common/sicurezza/password';
import { urlPubblico } from '../../../config/app';
import { AnagraficaService } from '../../anagrafica/anagrafica.service';
import { periodoDiProva } from '../../anagrafica/prova-contratto';
import { DocumentiPersonaliService } from '../../documenti/documenti-personali.service';
import { DocumentiService } from '../../documenti/documenti.service';
import { escapeHtml, MailerService } from '../../notifiche/mailer.service';
import { SaldiService, TIPI_SALDO } from '../../saldi/saldi.service';
import { ContestoPagina, json, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const MESSAGGI: Record<string, string> = {
  updated: 'Dipendente aggiornato con successo', deleted: 'Dipendente eliminato con successo', balance_updated: 'Saldi ferie/permessi aggiornati',
  hr_revoked: 'Accesso HR revocato. Il dipendente mantiene il suo account da dipendente.', created: 'Dipendente creato con successo. Le credenziali di accesso sono state inviate via email.',
};
const CAMPI_IN_LINEA = ['first_name', 'last_name', 'position', 'email', 'phone', 'birth_date', 'address', 'hire_date', 'probation_end_date', 'job_level', 'iban',
  'ral_amount', 'monthly_salary', 'department_id', 'nfc_uid'];
const GIORNI = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const lista = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v === undefined || v === null || v === '' ? [] : [String(v)]);
const decimale = (v: unknown) => Number.parseFloat(String(v ?? '0').replace(',', '.')) || 0;
const vuotoNull = (v: unknown) => (v === undefined || v === null || String(v).trim() === '' ? null : v);

/** Tentativi falliti di conferma password per Rendi HR / Revoca (in memoria, per utente). */
const tentativiHr = new Map<number, { n: number; t: number }>();

/**
 * Dipendenti dell'HR (admin/employees.php): elenco, scheda con modifiche in
 * linea, creazione e modifica, saldi, periodo di prova dal contratto,
 * documenti, accesso HR, reset password.
 *
 * Correzioni: tutte le azioni solo su dipendenti dell'azienda attiva (il PHP
 * reimpostava la password, leggeva il contratto e cambiava i saldi di
 * dipendenti di qualsiasi azienda); la password temporanea non passa
 * nell'indirizzo della pagina; reparto e CCNL devono essere dell'azienda;
 * dopo il reset la password va cambiata al primo accesso.
 */
@Injectable()
@PaginaPhp('admin/employees')
export class DipendentiPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly anagrafica: AnagraficaService, private readonly saldi: SaldiService,
    private readonly documenti: DocumentiService, private readonly personali: DocumentiPersonaliService,
    private readonly mailer: MailerService) {}

  /** Campi del modulo comuni a creazione e modifica. */
  private datiModulo(corpo: Record<string, any>) {
    const d: Record<string, any> = {
      username: corpo.username ?? '', fiscal_code: corpo.fiscal_code ?? '', first_name: corpo.first_name ?? '', last_name: corpo.last_name ?? '',
      email: corpo.email ?? '', phone: corpo.phone ?? '', department_id: corpo.department_id ? Number.parseInt(corpo.department_id, 10) || null : null,
      position: corpo.position ?? '', hire_date: corpo.hire_date ?? null, probation_end_date: corpo.probation_end_date ?? null,
      address: corpo.address ?? null, birth_date: corpo.birth_date ?? null, job_level: corpo.job_level ?? null, ral_amount: corpo.ral_amount ?? null,
      monthly_salary: corpo.monthly_salary ?? null, iban: corpo.iban ?? null, ccnl_id: corpo.ccnl_id ? Number.parseInt(corpo.ccnl_id, 10) || null : null,
      ferie_year_override: corpo.ferie_year_override !== undefined && corpo.ferie_year_override !== '' ? decimale(corpo.ferie_year_override) : null,
      permessi_year_override: corpo.permessi_year_override !== undefined && corpo.permessi_year_override !== '' ? decimale(corpo.permessi_year_override) : null,
    };
    if (corpo.working_days_override === '1') {
      d.working_days = GIORNI.filter((g) => lista(corpo['working_days[]'] ?? corpo.working_days).includes(g)).join(',');
      const h = String(corpo.hours_per_day ?? '').trim();
      d.hours_per_day = h === '' ? null : decimale(h);
    } else { d.working_days = null; d.hours_per_day = null; }
    d.smart_working_days = corpo.smart_working_override === '1'
      ? GIORNI.filter((g) => lista(corpo['smart_working_days[]'] ?? corpo.smart_working_days).includes(g)).join(',') : null;
    const bp = String(corpo.buoni_pasto_min_hours_override ?? '').trim();
    d.buoni_pasto_min_hours_override = bp === '' ? null : decimale(bp);
    const sw = String(corpo.buoni_pasto_sw_eligible_override ?? '');
    d.buoni_pasto_sw_eligible_override = sw === '' ? null : Number.parseInt(sw, 10) || 0;
    d.buoni_pasto_excluded = corpo.buoni_pasto_excluded !== undefined ? 1 : 0;
    return d;
  }

  private async salvaSaldi(id: number, cid: number, corpo: Record<string, any>, uid: number, conNote: boolean) {
    if (!corpo.balance || typeof corpo.balance !== 'object') return;
    for (const t of TIPI_SALDO) {
      const b = corpo.balance[t] ?? {};
      const anno = Number.parseInt(String(b.year ?? oggiRoma().slice(0, 4)), 10) || 0;
      if (anno > 1900 && anno < 2100) {
        await this.saldi.salva(id, cid, anno, t, decimale(b.entitled), decimale(b.carried), decimale(b.manual_used),
          conNote ? String(b.notes ?? '').trim() || null : null, uid);
      }
    }
  }

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const id = corpo.id !== undefined && corpo.id !== '' ? Number.parseInt(String(corpo.id), 10) || 0 : Number.parseInt(String(c.query.id ?? ''), 10) || null;
    switch (corpo.action ?? '') {
      case 'inline_update': {
        const eid = Number.parseInt(String(corpo.id ?? '0'), 10) || 0;
        const campo = String(corpo.field ?? '');
        const valore = String(corpo.value ?? '').trim();
        if (eid <= 0 || !CAMPI_IN_LINEA.includes(campo)) return json({ success: false, error: 'Campo non valido' });
        let v: unknown = valore !== '' ? valore : null;
        if (['ral_amount', 'monthly_salary'].includes(campo)) v = valore !== '' ? decimale(valore) : null;
        if (campo === 'department_id') v = valore !== '' ? Number.parseInt(valore, 10) || null : null;
        const r = await this.anagrafica.aggiorna(eid, { [campo]: v }, cid, c.audit);
        return json({ success: r.success, error: r.success ? null : r.error });
      }
      case 'create': {
        const d = this.datiModulo(corpo);
        const r = await this.anagrafica.crea(d, cid, c.audit);
        if (!r.success) return this.dati(c, { azione: 'new', errore: r.error, post: corpo });
        await this.salvaSaldi(r.id, cid, corpo, c.u.id, false);
        if (r.email_sent) return redirect('/admin/employees.php?message=created&email=sent');
        // Correzione: la password temporanea si mostra qui, non nell'indirizzo della pagina
        const msg = `Dipendente creato, ma invio email fallito${r.email_error ? ` (${escapeHtml(r.email_error)})` : ''}. Comunica manualmente la password temporanea: <strong><code>${escapeHtml(r.temp_password ?? '')}</code></strong>`;
        return this.dati(c, { azione: 'list', messaggio: msg });
      }
      case 'update': {
        if (!id) break;
        const d = { ...this.datiModulo(corpo), is_active: corpo.is_active !== undefined };
        const r = await this.anagrafica.aggiorna(id, d, cid, c.audit);
        if (r.success) {
          await this.salvaSaldi(id, cid, corpo, c.u.id, true);
          return redirect('/admin/employees.php?message=updated');
        }
        return this.dati(c, { azione: 'edit', id, errore: r.error, post: corpo });
      }
      case 'read_probation_contract': {
        const eid = Number.parseInt(String(corpo.id ?? '0'), 10) || 0;
        const e = eid ? await this.anagrafica.perId(eid, cid) : null;
        if (!e) return this.dati(c, { errore: 'Dipendente non trovato' });
        const scheda = `/admin/employees.php?action=view&id=${eid}`;
        const f = file.contract_pdf?.[0];
        if (!f || !f.size) return redirect(`${scheda}&probc=nofile`);
        if (path.extname(f.originalname).toLowerCase() !== '.pdf') return redirect(`${scheda}&probc=notpdf`);
        const tmp = path.join(os.tmpdir(), `contratto-${process.pid}-${Date.now()}.pdf`);
        fs.writeFileSync(tmp, f.buffer, { mode: 0o600 });
        try {
          const prova = periodoDiProva(await testoPdf(tmp), e.hire_date ?? null);
          const adesso = oggiRoma();
          const doc = await this.documenti.caricaDaPercorso(tmp, {
            employee_id: eid, type: 'other', month: Number(adesso.slice(5, 7)), year: Number(adesso.slice(0, 4)), title: 'Contratto di lavoro',
            description: 'Caricato per la lettura del periodo di prova', original_name: f.originalname, uploaded_by: c.u.id,
          });
          if (!doc.success) return redirect(`${scheda}&probc=err`);
          const q = (s: string) => new URLSearchParams({ x: s }).toString().slice(2);
          if (prova.end_date) {
            if (!e.probation_decision) {
              await this.anagrafica.aggiorna(eid, { probation_end_date: prova.end_date }, cid, c.audit);
              return redirect(`${scheda}&probc=found&d=${q(prova.end_date)}`);
            }
            return redirect(`${scheda}&probc=locked&d=${q(prova.end_date)}`);
          }
          if (prova.months || prova.days || prova.weeks) return redirect(`${scheda}&probc=nostart&raw=${q(prova.raw ?? '')}`);
          return redirect(`${scheda}&probc=notfound`);
        } finally {
          fs.rmSync(tmp, { force: true });
        }
      }
      case 'update_balance_inline': {
        if (!id) break;
        const e = await this.anagrafica.perId(id, cid);
        if (!e) return this.dati(c, { errore: 'Dipendente non trovato' });
        await this.anagrafica.aggiorna(id, {
          ccnl_id: corpo.ccnl_id ? Number.parseInt(corpo.ccnl_id, 10) || null : null,
          ferie_year_override: corpo.ferie_year_override !== undefined && corpo.ferie_year_override !== '' ? decimale(corpo.ferie_year_override) : null,
          permessi_year_override: corpo.permessi_year_override !== undefined && corpo.permessi_year_override !== '' ? decimale(corpo.permessi_year_override) : null,
        }, cid, c.audit);
        const anno = Number.parseInt(String(corpo.year ?? oggiRoma().slice(0, 4)), 10) || Number(oggiRoma().slice(0, 4));
        for (const t of TIPI_SALDO) {
          const b = corpo.balance?.[t];
          if (!b || b.residual === undefined) continue;
          await this.saldi.impostaResiduo(id, e.company_id, anno, t, decimale(b.residual), oggiRoma(), c.u.id);
        }
        return redirect(`/admin/employees.php?action=view&id=${id}&message=balance_updated`);
      }
      case 'delete': {
        if (!id) break;
        const r = await this.anagrafica.elimina(id, cid, c.audit);
        if (r.success) return redirect('/admin/employees.php?message=deleted');
        return this.dati(c, { errore: r.error });
      }
      case 'toggle_active': {
        if (!id) break;
        const e = await this.anagrafica.perId(id, cid);
        if (!e) break;
        const r = await this.anagrafica.aggiorna(id, { is_active: !e.is_active }, cid, c.audit);
        if (r.success) return redirect('/admin/employees.php?message=updated');
        return this.dati(c, { errore: r.error });
      }
      case 'grant_hr':
      case 'revoke_hr': {
        const e = id ? await this.anagrafica.perId(id, cid) : null;
        if (!e) return this.dati(c, { azione: 'view', id: id ?? undefined, errore: 'Dipendente non trovato' });
        let t = tentativiHr.get(c.u.id) ?? { n: 0, t: Date.now() };
        if (Date.now() - t.t > 900_000) t = { n: 0, t: Date.now() };
        if (t.n >= 5) return this.dati(c, { azione: 'view', id, errore: 'Troppi tentativi con password errata. Riprova tra qualche minuto.' });
        const [u] = await senzaIsolamento(() => this.ds.query('SELECT password_hash FROM users WHERE id = $1', [c.u.id]));
        if (!u || !(await verificaPassword(String(corpo.confirm_password ?? ''), u.password_hash))) {
          t.n++;
          tentativiHr.set(c.u.id, t);
          return this.dati(c, { azione: 'view', id, errore: 'Password non corretta: operazione annullata.' });
        }
        tentativiHr.delete(c.u.id);
        if (corpo.action === 'revoke_hr') {
          const r = await this.anagrafica.revocaHr(id!, c.audit);
          if (r.success) return redirect(`/admin/employees.php?action=view&id=${id}&message=hr_revoked`);
          return this.dati(c, { azione: 'view', id, errore: r.error });
        }
        const r = await this.anagrafica.rendiHr(e, cid, c.audit);
        if (!r.success) return this.dati(c, { azione: 'view', id, errore: r.error });
        let inviata = false;
        if (e.email && this.mailer.configurato()) {
          const nome = `${e.first_name} ${e.last_name}`.trim();
          const login = urlPubblico('/auth/login.php');
          const html = `<p>Ciao ${escapeHtml(nome)},</p><p>Ti e' stato dato l'accesso <strong>HR</strong> al portale Connecteed HR. E' un account separato da quello da dipendente, che resta invariato.</p>`
            + `<ul><li><strong>Username:</strong> ${escapeHtml(r.username)}</li><li><strong>Password temporanea:</strong> <code>${escapeHtml(r.password)}</code></li></ul>`
            + `<p><a href="${escapeHtml(login)}">Accedi al portale</a></p><p style="font-size:13px;color:#718096;">Cambia la password al primo accesso e non condividerla.</p>`;
          const testo = `Ciao ${nome},\n\nTi e' stato dato l'accesso HR al portale Connecteed HR (account separato da quello da dipendente).\n\nUsername: ${r.username}\nPassword temporanea: ${r.password}\n\nLogin: ${login}\n`;
          inviata = await this.mailer.invia(e.email, nome, 'Accesso HR al portale Connecteed HR', html, testo);
        }
        const msg = `Accesso HR ${r.reactivated ? 'riattivato' : 'creato'}. Username: <strong><code>${escapeHtml(r.username)}</code></strong>`
          + ` &mdash; password temporanea: <strong><code>${escapeHtml(r.password)}</code></strong>`
          + (inviata ? ` (inviate anche via email a ${escapeHtml(e.email)}).` : " &mdash; <strong>email non inviata, comunicale tu: non saranno piu' visibili.</strong>");
        return this.dati(c, { azione: 'view', id, messaggio: msg });
      }
      case 'reset_password': {
        if (!id) break;
        const r = await this.anagrafica.reimpostaPassword(id, cid, c.audit);
        if (r.success) return this.dati(c, { azione: 'view', id, messaggio: 'Password reimpostata con successo', password: r.password });
        return this.dati(c, { errore: r.error });
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, o: { azione?: string; id?: number | null; errore?: string; messaggio?: string; password?: string; post?: Record<string, any> } = {}) {
    const cid = c.u.companyId!;
    const q = c.query;
    const action = o.azione ?? (q.action !== undefined ? String(q.action) : 'list');
    const id = o.id !== undefined ? o.id : q.id !== undefined ? Number.parseInt(String(q.id), 10) || 0 : null;
    let message = o.messaggio ?? '';
    if (!message && q.message !== undefined) {
      message = q.message === 'created' && q.email !== 'sent' ? '' : MESSAGGI[String(q.message)] ?? '';
    }
    const search = q.search !== undefined ? String(q.search) : '';
    const onlyActive = q.only_active !== undefined;
    const V: Record<string, unknown> = {
      action, id, message, error: o.errore ?? '', generatedPassword: o.password ?? null, search, onlyActive,
      user: { id: c.u.id }, _GET: q, _POST: o.post ?? {}, __cid: cid,
      departments: await this.ds.query(
        `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_active = true)::int AS employee_count
           FROM departments d WHERE d.company_id = $1 AND d.is_active = true ORDER BY d.name ASC`, [cid]),
    };
    const [az] = await senzaIsolamento(() => this.ds.query(
      `SELECT timbratura_enabled, buoni_pasto_enabled, buoni_pasto_min_hours_enabled, buoni_pasto_min_hours, buoni_pasto_sw_eligible, smart_working_days,
              default_ccnl_id, working_days, hours_per_day FROM companies WHERE id = $1`, [cid]));
    V.__timbratureDb = az ? (az.timbratura_enabled ? 1 : 0) : null;
    V.__bpCompDb = az ? { buoni_pasto_enabled: az.buoni_pasto_enabled, buoni_pasto_min_hours_enabled: az.buoni_pasto_min_hours_enabled,
      buoni_pasto_min_hours: az.buoni_pasto_min_hours, buoni_pasto_sw_eligible: az.buoni_pasto_sw_eligible, smart_working_days: az.smart_working_days } : false;
    V.__defaultCcnlDb = az ? { default_ccnl_id: az.default_ccnl_id } : false;
    V.__compDefaults = { days: String(az?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean), hours: az?.hours_per_day != null ? Number(az.hours_per_day) : 8.0 };
    V.__ccnls = await this.saldi.ccnlDisponibili(cid);

    if (action === 'list') {
      V.employees = await this.anagrafica.elenco(cid, onlyActive, search);
      const [n] = await this.ds.query(
        `SELECT COUNT(*) FILTER (WHERE is_active)::int AS attivi, COUNT(*) FILTER (WHERE NOT is_active)::int AS cessati FROM employees WHERE company_id = $1`, [cid]);
      V.countActive = n.attivi;
      V.countInactive = n.cessati;
      V.countTotal = n.attivi + n.cessati;
      const iscritti: Record<number, boolean> = {};
      for (const r of await senzaIsolamento(() => this.ds.query(
        `SELECT DISTINCT user_id FROM push_subscriptions WHERE user_type = 'employee' AND company_id = $1`, [cid]))) iscritti[r.user_id] = true;
      V._pushSubscribed = iscritti;
    } else if ((action === 'edit' || action === 'view') && id) {
      const e = await this.anagrafica.perId(id, cid);
      if (!e) return redirect('/admin/employees.php?error=not_found');
      V.employee = e;
      V.__saldi = await this.saldi.perDipendente(id, Number(oggiRoma().slice(0, 4)));
      if (action === 'view') {
        V.documents = (await this.ds.query(
          `SELECT d.id, d.employee_id, d.type, d.title, d.description, d.original_name, d.file_size, d.mime_type, d.month, d.year, d.created_at, d.uploaded_by,
                  u.name AS uploaded_by_name
             FROM documents d JOIN users u ON d.uploaded_by = u.id WHERE d.employee_id = $1 ORDER BY d.year DESC, d.month DESC, d.created_at DESC, d.id DESC`, [id]));
        V.hrAccount = await this.anagrafica.accountHr(id);
        V.__edDocs = await this.personali.perDipendente(id);
        V.__deptsInlineDb = await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 AND is_active = true ORDER BY name', [cid]);
      }
    }
    const titolo = action === 'new' ? 'Nuovo Dipendente' : action === 'edit' ? 'Modifica Dipendente' : action === 'view' ? 'Dettaglio Dipendente' : 'Gestione Dipendenti';
    return vista(titolo, V);
  }
}
