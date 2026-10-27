import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { testoPdf } from '../../../common/file/testo-pdf';
import { periodoDiProva } from '../../anagrafica/prova-contratto';
import { AssunzioniService } from '../../assunzioni/assunzioni.service';
import { PasswordService } from '../../auth/password.service';
import { leggiBustaPaga } from '../../documenti/busta-paga';
import { DocumentiPersonaliService } from '../../documenti/documenti-personali.service';
import { DocumentiService } from '../../documenti/documenti.service';
import { FerieService } from '../../ferie/ferie.service';
import { NotificheService } from '../../notifiche/notifiche.service';
import { PresenzeExportService } from '../../presenze/presenze-export.service';
import { ProvaService } from '../../prova/prova.service';
import { SaldiService, TipoSaldo } from '../../saldi/saldi.service';
import { WrikeService } from '../../wrike/wrike.service';
import { PresenzeExportPagina } from '../admin/presenze-export.pagina';
import { scaricaDocumentoPersonale } from '../admin/documenti-aziendali.pagina';
import { ContestoPagina, EsitoPagina, json, Pagina, PaginaPhp, redirect, vista } from '../pagina';
import { emailStaffOccupata } from '../comune';

const intero = (v: unknown) => Number.parseInt(String(v ?? ''), 10) || 0;
const AREA: Record<string, string> = { admin: 'admin', consulente_lavoro: 'consulente-lavoro', formatore: 'formatore' };
const area = (c: ContestoPagina) => AREA[c.u.ruolo] ?? 'consulente-lavoro';
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;

/** Dipendenti attivi dell'azienda (Employee::getAll(true, $search)). */
function dipendentiAttivi(ds: DataSource, cid: number, ricerca = '') {
  const p: unknown[] = [cid];
  let sql = `SELECT e.*, d.name AS department_name, d.code AS department_code,
               (SELECT COUNT(*) FROM documents doc WHERE doc.employee_id = e.id)::int AS document_count
             FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.company_id = $1 AND e.is_active = true`;
  if (ricerca) {
    p.push(`%${ricerca}%`);
    sql += ` AND (e.fiscal_code ILIKE $2 OR e.first_name ILIKE $2 OR e.last_name ILIKE $2 OR e.email ILIKE $2 OR e.username ILIKE $2)`;
  }
  return ds.query(`${sql} ORDER BY e.last_name ASC, e.first_name ASC, e.id ASC`, p);
}

/**
 * Dashboard del consulente del lavoro (consulente-lavoro/index.php).
 */
@Injectable()
export abstract class DashboardEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource, protected readonly notifiche: NotificheService) {}

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const oggi = oggiRoma();
    const n = async (sql: string, p: unknown[]) => Number((await this.ds.query(sql, p))[0]?.n ?? 0);
    const [me] = await senzaIsolamento(() => this.ds.query('SELECT id, name, username, role FROM users WHERE id = $1', [c.u.id]));
    const V: Record<string, unknown> = {
      user: me,
      employeeCount: await n('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]),
      documentCount: await n('SELECT COUNT(*)::int AS n FROM documents WHERE uploaded_by = $1 AND company_id = $2', [c.u.id, cid]),
      thisMonthDocs: await n(`SELECT COUNT(*)::int AS n FROM documents WHERE uploaded_by = $1 AND company_id = $2
        AND to_char(created_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM') = $3`, [c.u.id, cid, oggi.slice(0, 7)]),
      pendingLeave: await n(`SELECT COUNT(*)::int AS n FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE e.company_id = $1 AND lr.status = 'pending'`, [cid]),
      approvedLeaveYear: await n(`SELECT COUNT(*)::int AS n FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
        WHERE e.company_id = $1 AND lr.status = 'approved' AND extract(year FROM lr.start_date) = $2`, [cid, Number(oggi.slice(0, 4))]),
      recentDocuments: await this.ds.query(
        `SELECT d.id, d.type, d.title, d.month, d.year, d.original_name, d.created_at, d.employee_id, e.first_name, e.last_name, e.fiscal_code
           FROM documents d JOIN employees e ON d.employee_id = e.id WHERE d.uploaded_by = $1 AND d.company_id = $2 ORDER BY d.created_at DESC, d.id DESC LIMIT 8`, [c.u.id, cid]),
      _GET: c.query,
    };
    if (c.u.ruolo === 'consulente_lavoro') {
      const notif = await this.notifiche.perUtente(cid, 'consulente_lavoro', c.u.id, false, 8);
      V.__notifs = notif;
      V.__notifUnread = notif.filter((x: any) => !x.is_read).length;
    }
    return vista('Dashboard', V);
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/index') export class DashboardConsulentePagina extends DashboardEsternoPagina { readonly ruoli = ['consulente_lavoro']; }

/**
 * Anagrafica dipendenti in lettura (consulente-lavoro/employees.php).
 */
@Injectable()
export abstract class DipendentiEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource) {}

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const search = String(c.query.search ?? '');
    const employees = (await dipendentiAttivi(this.ds, cid, search)).map(({ password_hash, mfa_secret, nfc_uid, ...e }: any) => e);
    return vista('Anagrafica dipendenti', { user: { id: c.u.id }, search, employees, __cid: cid });
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/employees') export class DipendentiConsulentePagina extends DipendentiEsternoPagina { readonly ruoli = ['consulente_lavoro']; }

/** Documenti personali dei dipendenti (accountant/employee-documents.php). */
@Injectable()
export abstract class DocumentiDipendenteEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource, protected readonly personali: DocumentiPersonaliService, protected readonly documenti: DocumentiService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download === undefined) return false;
    await scaricaDocumentoPersonale(this.documenti, c, res, intero(c.query.download));
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const emp = intero(corpo.employee_id);
    let stato = '';
    if (corpo.action === 'upload' && emp) {
      const f = [...(file['documents[]'] ?? []), ...(file.documents ?? []), ...(file.document ?? [])];
      stato = f.length ? await this.personali.caricaMolti(f, { employee_id: emp, name: corpo.name ?? '', visible_to_employee: !!corpo.visible_to_employee, expires_on: corpo.expires_on || null }, cid, c.audit) : 'no_file';
    } else if (['rename', 'toggle_visibility', 'delete'].includes(corpo.action ?? '')) {
      const doc = intero(corpo.document_id);
      const attuale = doc ? await this.personali.perId(doc, cid) : null;
      if (attuale) {
        const r = corpo.action === 'delete' ? await this.personali.elimina(doc, cid, c.audit)
          : await this.personali.aggiorna(doc, corpo.action === 'rename' ? { name: corpo.name ?? '' } : { visible_to_employee: !attuale.visible_to_employee }, cid, c.audit);
        stato = r.success ? (corpo.action === 'delete' ? 'deleted' : 'updated') : `error_${r.error ?? ''}`;
      }
    }
    return redirect(`/${area(c)}/employee-documents.php?employee_id=${emp}&ed_status=${new URLSearchParams({ x: stato }).toString().slice(2)}`);
  }

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const sel = intero(c.query.employee_id);
    const [e] = sel ? await this.ds.query('SELECT id, first_name, last_name, fiscal_code, email, department_id FROM employees WHERE id = $1 AND company_id = $2', [sel, cid]) : [];
    return vista('Documenti dipendente', {
      employees: (await dipendentiAttivi(this.ds, cid)).map((x: any) => ({ id: x.id, first_name: x.first_name, last_name: x.last_name, fiscal_code: x.fiscal_code, department_name: x.department_name })),
      selectedId: sel, selectedEmployee: e ?? null, documents: e ? await this.personali.perDipendente(sel) : [], edStatus: String(c.query.ed_status ?? ''), _GET: c.query,
    });
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/employee-documents') export class DocumentiDipendenteConsulentePagina extends DocumentiDipendenteEsternoPagina { readonly ruoli = ['consulente_lavoro']; }

/**
 * Buste paga e documenti (accountant/documents.php): caricamento singolo,
 * caricamento massivo con lettura di codice fiscale, mese e saldi dal PDF,
 * eliminazione.
 */
@Injectable()
export abstract class DocumentiEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  readonly caricamenti = { dimensione: 25 * 1024 * 1024, file: 200 };
  constructor(protected readonly ds: DataSource, protected readonly documenti: DocumentiService, protected readonly saldi: SaldiService) {}

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const staging = this.documenti.cartellaStaging(c.u.sessioneId);
    switch (String(corpo.action ?? '')) {
      case 'bulk_analyze': {
        const lista = [...(file['files[]'] ?? []), ...(file.files ?? [])];
        if (!lista.length) return json({ success: false, error: 'Nessun file ricevuto' });
        const perCf = new Map<string, any>();
        for (const e of await dipendentiAttivi(this.ds, cid)) if (e.fiscal_code) perCf.set(String(e.fiscal_code).trim().toUpperCase(), e);
        const righe: any[] = [];
        for (const f of lista) {
          const nome = f.originalname;
          if (path.extname(nome).toLowerCase() !== '.pdf') { righe.push({ filename: nome, status: 'error', error: 'Non e un PDF' }); continue; }
          if (f.buffer.subarray(0, 5).toString('latin1') !== '%PDF-') { righe.push({ filename: nome, status: 'error', error: 'Non e un PDF' }); continue; }
          const id = randomBytes(8).toString('hex');
          const p = path.join(staging, `${id}.pdf`);
          fs.writeFileSync(p, f.buffer, { mode: 0o640 });
          const letto = await leggiBustaPaga(p);
          const e = letto.cf ? perCf.get(letto.cf.toUpperCase()) ?? null : null;
          righe.push({
            filename: nome, staging_id: id, cf: letto.cf, employee_id: e ? e.id : null, employee_name: e ? `${e.first_name} ${e.last_name}`.trim() : null,
            month: letto.period?.month ?? null, year: letto.period?.year ?? null, ferie_residuo: letto.balances.ferie?.residuo ?? null, perm_residuo: letto.balances.permesso?.residuo ?? null,
            status: e && letto.period ? 'ready' : letto.cf ? 'partial' : 'unmatched',
          });
        }
        return json({ success: true, rows: righe });
      }
      case 'bulk_commit': {
        let righe: any[] = [];
        try { righe = typeof corpo.rows === 'string' ? JSON.parse(corpo.rows) : Array.isArray(corpo.rows) ? corpo.rows : []; } catch { righe = []; }
        if (!Array.isArray(righe)) righe = [];
        let create = 0;
        const errori: Array<{ filename: string; error: string }> = [];
        for (const r of righe) {
          const id = String(r?.staging_id ?? '').replace(/[^a-f0-9]/g, '');
          const emp = intero(r?.employee_id);
          const mese = intero(r?.month);
          const anno = intero(r?.year);
          const nome = String(r?.filename ?? 'busta.pdf');
          const p = path.join(staging, `${id}.pdf`);
          if (id === '' || !fs.existsSync(p) || emp <= 0 || mese < 1 || mese > 12 || anno < 2000) { errori.push({ filename: nome, error: 'Dati riga incompleti' }); continue; }
          // Correzione: il dipendente deve essere dell'azienda attiva
          if (!(await this.ds.query('SELECT 1 FROM employees WHERE id = $1 AND company_id = $2', [emp, cid])).length) { errori.push({ filename: nome, error: 'Dipendente non trovato' }); continue; }
          const applica = !!r?.apply_balance && r.apply_balance !== '0';
          const saldiPdf = applica ? (await leggiBustaPaga(p)).balances : null;
          const esito = await this.documenti.caricaDaPercorso(p, {
            employee_id: emp, type: 'payslip', title: `Busta paga ${String(mese).padStart(2, '0')}/${anno}`, description: '', month: mese, year: anno, original_name: nome, uploaded_by: c.u.id,
          });
          if (esito.success) { fs.rmSync(p, { force: true }); create++; } else errori.push({ filename: nome, error: esito.error ?? 'Errore upload' });
          if (applica) {
            const num = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number.parseFloat(String(v)) || 0);
            try {
              await this.saldi.daBustaPaga(emp, cid, (saldiPdf ?? { ferie: null, permesso: null }) as Partial<Record<TipoSaldo, any>>, anno, mese, c.u.id,
                { ferie: num(r.ferie_residuo), permesso: num(r.perm_residuo) });
            } catch {
              errori.push({ filename: nome, error: 'Saldo non aggiornato' });
            }
          }
        }
        return json({ success: true, created: create, errors: errori });
      }
      case 'upload': {
        const f = file.document?.[0];
        if (!f || !f.size) return this.dati(c, 'Seleziona un file da caricare');
        const m = /^(\d{4})-(\d{2})$/.exec(String(corpo.period ?? ''));
        const r = await this.documenti.carica(f, {
          employee_id: corpo.employee_id ?? '', type: String(corpo.type ?? ''), title: String(corpo.title ?? ''), description: String(corpo.description ?? ''),
          month: m ? Number(m[2]) : 0, year: m ? Number(m[1]) : 0,
        }, cid, c.u.id);
        return r.success ? redirect(`/${area(c)}/documents.php?message=uploaded`) : this.dati(c, r.error);
      }
      case 'delete': {
        const id = intero(corpo.document_id);
        if (!id) return this.dati(c);
        const r = await this.documenti.elimina(id, cid, c.audit);
        return r.success ? redirect(`/${area(c)}/documents.php?message=deleted`) : this.dati(c, r.error);
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, errore = ''): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const q = c.query;
    const m = /^(\d{4})-(\d{2})$/.exec(String(q.filter_period ?? ''));
    const filterEmployee = q.employee_id ? intero(q.employee_id) || null : null;
    const filterType = q.type ? String(q.type) : null;
    let documents = await this.documenti.elenco(cid, m ? Number(m[1]) : null, m ? Number(m[2]) : null, filterType);
    if (filterEmployee) documents = documents.filter((d: any) => d.employee_id === filterEmployee);
    const messaggi: Record<string, string> = { uploaded: 'Documento caricato con successo', deleted: 'Documento eliminato con successo' };
    return vista(c.u.ruolo === 'admin' ? 'Buste paga e documenti' : 'Carica Documenti - Consulente del lavoro', {
      user: { id: c.u.id }, message: messaggi[String(q.message ?? '')] ?? '', error: errore,
      employees: (await dipendentiAttivi(this.ds, cid)).map((x: any) => ({ id: x.id, first_name: x.first_name, last_name: x.last_name, fiscal_code: x.fiscal_code, department_name: x.department_name })),
      filterEmployee, filterType, filterPeriod: String(q.filter_period ?? ''), filterYear: m ? Number(m[1]) : null, filterMonth: m ? Number(m[2]) : null, documents, _GET: q,
    });
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/documents') export class DocumentiConsulentePagina extends DocumentiEsternoPagina { readonly ruoli = ['consulente_lavoro']; }
/** Anche l'HR carica le buste paga, singole o in blocco (stessa pagina del consulente). */
@Injectable() @PaginaPhp('admin/documents') export class DocumentiAdminPagina extends DocumentiEsternoPagina { readonly ruoli = ['admin']; }

/**
 * Ferie e permessi approvati (accountant/leave-requests.php), in lettura.
 * Correzione: i reparti del filtro sono solo dell'azienda (il PHP li
 * elencava tutti).
 */
@Injectable()
export abstract class FerieEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource, protected readonly ferie: FerieService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download_cert === undefined) return false;
    const r = await this.ferie.file(intero(c.query.download_cert), 'certificate', c.u, c.audit);
    if (!r.ok) { res.status(403).type('text/plain').send(r.error.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')); return true; }
    res.download(r.percorso, r.nome.replace(/[^a-zA-Z0-9._-]/g, '_'), { headers: { 'Content-Type': r.mime, 'Cache-Control': 'private, no-store' } });
    return true;
  }

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const q = c.query;
    const filterYear = q.year ? intero(q.year) : Number(oggiRoma().slice(0, 4));
    const filterMonth = q.month ? intero(q.month) || null : null;
    const filterDepartment = q.department_id ? intero(q.department_id) || null : null;
    const p: unknown[] = [cid, filterYear];
    if (filterMonth) p.push(filterMonth);
    let requests = await this.ds.query(
      `SELECT lr.*, e.first_name, e.last_name, e.fiscal_code, e.photo_path, d.name AS department_name, d.code AS department_code, u.name AS approved_by_name
         FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.status = 'approved' AND e.company_id = $1 AND extract(year FROM lr.start_date) = $2${filterMonth ? ' AND extract(month FROM lr.start_date) = $3' : ''}
        ORDER BY lr.start_date DESC, e.last_name, e.first_name, lr.id DESC`, p);
    if (filterDepartment) requests = requests.filter((r: any) => r.department_id === filterDepartment);
    const senzaPercorsi = (r: any) => ({ ...r, attachment_path: r.attachment_path ? '1' : null, certificate_path: r.certificate_path ? '1' : null });
    return vista('Ferie e Permessi Approvati', {
      filterYear, filterMonth, filterDepartment, requests: requests.map(senzaPercorsi),
      departments: await this.ds.query('SELECT id, name, code FROM departments WHERE company_id = $1 AND is_active = true ORDER BY name ASC', [cid]), _GET: q,
    });
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/leave-requests') export class FerieConsulentePagina extends FerieEsternoPagina { readonly ruoli = ['consulente_lavoro']; }

/** Export presenze (accountant/presenze-export.php): stessa logica della pagina HR. */
@Injectable() @PaginaPhp('consulente-lavoro/presenze-export')
export class PresenzeConsulentePagina extends PresenzeExportPagina { override readonly ruoli = ['consulente_lavoro']; constructor(ds: DataSource, p: PresenzeExportService) { super(ds, p); } }

const MESSAGGI_PROFILO: Record<string, string> = {
  updated: 'Profilo aggiornato.', password: 'Password cambiata.', wrike_connected: 'Wrike collegato! Ora scegli la cartella di destinazione e gli eventi.',
  wrike_saved: 'Impostazioni Wrike salvate.', wrike_disconnected: 'Wrike scollegato.',
};

/**
 * Profilo di consulente e formatore (dal PHP accountant/profile.php,
 * consulente-lavoro/profile.php con l'integrazione Wrike).
 * Correzioni: il formatore apre il suo profilo (nel PHP la pagina lo
 * respingeva); l'errore del database non esce al browser; il token Wrike
 * non arriva mai al browser.
 */
@Injectable()
export abstract class ProfiloEsternoPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource, protected readonly password: PasswordService, protected readonly wrike: WrikeService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const id = c.u.id;
    const self = `/${area(c)}/profile.php`;
    switch (corpo.action ?? '') {
      case 'update_info': {
        const nome = String(corpo.name ?? '').trim();
        const email = String(corpo.email ?? '').trim();
        if (nome === '') return this.dati(c, 'Nome obbligatorio');
        if (email !== '' && (!EMAIL.test(email) || email.length > 100)) return this.dati(c, 'Email non valida');
        if (nome.length > 100) return this.dati(c, 'Nome troppo lungo (max 100 caratteri)');
        if (await emailStaffOccupata(this.ds, email, id)) return this.dati(c, 'Email gia in uso da un altro account');
        await senzaIsolamento(() => this.ds.query('UPDATE users SET name = $2, email = $3 WHERE id = $1', [id, nome, email || null]));
        return redirect(`${self}?message=updated`);
      }
      case 'change_password': {
        const nuova = corpo.new_password ?? '';
        if (nuova !== (corpo.confirm_password ?? '')) return this.dati(c, 'Le password non coincidono');
        const r = await this.password.cambia('user', id, c.u.ruolo, corpo.current_password ?? '', nuova, nuova, c.audit);
        if (r.ok) return redirect(`${self}?message=password`);
        return this.dati(c, r.errore ?? (r.errori ?? ['Errore cambio password']).join(' '));
      }
    }
    if (c.u.ruolo === 'consulente_lavoro') {
      switch (corpo.action ?? '') {
        case 'wrike_connect': {
          const r = await this.wrike.collega(id, String(corpo.wrike_token ?? ''));
          return r.success ? redirect(`${self}?message=wrike_connected`) : this.dati(c, r.error);
        }
        case 'wrike_settings': {
          const cartella = String(corpo.wrike_folder ?? '').trim();
          const nome = cartella ? (await this.wrike.cartelle(id)).find((f) => f.id === cartella)?.title ?? '' : '';
          const r = await this.wrike.impostazioni(id, cartella || null, nome || null, !!corpo.wrike_on_hire, !!corpo.wrike_on_chat);
          return r.success ? redirect(`${self}?message=wrike_saved`) : this.dati(c, r.error);
        }
        case 'wrike_disconnect':
          await this.wrike.scollega(id);
          return redirect(`${self}?message=wrike_disconnected`);
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, error = ''): Promise<EsitoPagina> {
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT id, username, name, email, role, photo_path, created_at, last_login FROM users WHERE id = $1', [c.u.id]));
    const V: Record<string, unknown> = { user: u, userId: c.u.id, message: MESSAGGI_PROFILO[String(c.query.message ?? '')] ?? '', error, wrike: null, wrikeFolders: [] };
    if (c.u.ruolo === 'consulente_lavoro') {
      const w = await this.wrike.perUtente(c.u.id);
      if (w) {
        const [r] = await senzaIsolamento(() => this.ds.query('SELECT is_active, last_error, last_success_at, created_at FROM user_integrations WHERE id = $1', [w.id]));
        // Il token resta sul server: al template basta sapere che c'e'
        V.wrike = { ...r, config: w.config, token: w.token ? '1' : null };
        if (w.token) V.wrikeFolders = await this.wrike.cartelle(c.u.id);
      }
    }
    return vista('Il mio profilo', V);
  }
}
@Injectable() @PaginaPhp('consulente-lavoro/profile') export class ProfiloConsulentePagina extends ProfiloEsternoPagina { readonly ruoli = ['consulente_lavoro']; }
@Injectable() @PaginaPhp('formatore/profile') export class ProfiloFormatorePagina extends ProfiloEsternoPagina { readonly ruoli = ['formatore']; }

/** Il formatore entra direttamente nell'Academy (formatore/index.php). */
@Injectable() @PaginaPhp('formatore/index')
export class IndiceFormatorePagina implements Pagina {
  readonly ruoli = ['formatore'];
  async dati(): Promise<EsitoPagina> { return redirect('/formatore/academy.php'); }
}

/**
 * Richieste di assunzione del consulente (consulente-lavoro/hire-requests.php):
 * elenco, dettaglio, prospetti, contratto, file.
 * Correzione: testo libero nell'indirizzo (?err=) non mostrato; gli errori
 * restano nella pagina.
 */
@Injectable()
@PaginaPhp('consulente-lavoro/hire-requests')
export class AssunzioniConsulentePagina implements Pagina {
  readonly ruoli = ['consulente_lavoro'];
  constructor(private readonly ds: DataSource, private readonly assunzioni: AssunzioniService, private readonly prova: ProvaService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.action !== 'file') return false;
    const id = intero(c.query.id);
    const hr = id ? await this.assunzioni.perConsulente(id, c.u.companyId!, c.u.id) : null;
    if (!hr) { res.status(404).type('text/plain').send('Non trovata'); return true; }
    const [f] = await this.ds.query('SELECT * FROM hire_request_files WHERE id = $1 AND hire_request_id = $2', [intero(c.query.file_id), id]);
    const p = f ? this.assunzioni.percorso(f.file_path) : null;
    if (!f) { res.status(404).type('text/plain').send('File non trovato'); return true; }
    if (!p) { res.status(404).type('text/plain').send('File non disponibile'); return true; }
    const { mimeDaContenuto } = await import('../../../common/file/caricamenti');
    res.set({ 'Content-Type': mimeDaContenuto(p, f.original_name ?? ''), 'Content-Disposition': `inline; filename="${String(f.original_name ?? '').replace(/[^a-zA-Z0-9._-]/g, '_')}"`,
      'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'SAMEORIGIN' });
    fs.createReadStream(p).pipe(res);
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const id = intero(corpo.id);
    if (corpo.action === 'upload_contract') {
      const r = await this.assunzioni.aggiungiContratto(id, file.contract?.[0], cid, c.u.id,
        async (p, inizio) => periodoDiProva(await testoPdf(p), inizio).end_date ?? null, (eid, fine) => this.prova.suggerimentoDaContratto(eid, fine, cid));
      return r.success ? redirect(`/consulente-lavoro/hire-requests.php?id=${id}&contract_uploaded=1`) : this.dati(c, { id, err: r.error });
    }
    if (corpo.action === 'upload_prospects') {
      const nomi = (Array.isArray(corpo['display_names[]'] ?? corpo.display_names) ? (corpo['display_names[]'] ?? corpo.display_names) : [corpo['display_names[]'] ?? corpo.display_names ?? '']).map(String);
      const r = await this.assunzioni.aggiungiProspetti(id, [...(file['prospects[]'] ?? []), ...(file.prospects ?? [])], nomi, cid, c.u.id);
      return r.success ? redirect(`/consulente-lavoro/hire-requests.php?id=${id}&uploaded=1`) : this.dati(c, { id, err: r.error });
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, o: { id?: number; err?: string } = {}): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const q = { ...c.query };
    delete q.err;
    const id = o.id ?? intero(c.query.id);
    const _GET = o.err ? { ...q, id: String(id), err: o.err } : q;
    if (id > 0) {
      const hr = await this.assunzioni.perConsulente(id, cid, c.u.id);
      return vista('Richieste di assunzione', { id, user: { id: c.u.id }, _GET, __hr: hr, __files: hr ? await this.assunzioni.allegati(id) : [] });
    }
    return vista('Richieste di assunzione', { id: 0, user: { id: c.u.id }, _GET, rows: await this.assunzioni.elencoConsulente(cid, c.u.id), __cid: cid });
  }
}
