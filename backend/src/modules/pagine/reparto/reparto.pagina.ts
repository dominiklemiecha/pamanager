import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { AcademyStaffService } from '../../academy/academy-staff.service';
import { AttestatiService } from '../../academy/attestati.service';
import { PasswordService } from '../../auth/password.service';
import { ComunicazioniAdminService } from '../../comunicazioni/comunicazioni-admin.service';
import { DocumentiPersonaliService } from '../../documenti/documenti-personali.service';
import { DocumentiService } from '../../documenti/documenti.service';
import { FerieService } from '../../ferie/ferie.service';
import { scaricaDocumentoPersonale } from '../admin/documenti-aziendali.pagina';
import { ContestoPagina, EsitoPagina, json, Pagina, PaginaPhp, redirect, vista } from '../pagina';
import { AcademyStaffPagina } from '../staff/academy.pagina';
import { compleanni } from '../widget/compleanni';
import { HeatmapService } from '../widget/heatmap.service';

const h = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const intero = (v: unknown) => Number.parseInt(String(v ?? ''), 10) || 0;
/** Pagina di cortesia del PHP quando l'utente non ha un reparto. */
const SENZA_REPARTO = { __senzaReparto: true };

/** Reparto del responsabile, solo se e' dell'azienda attiva. */
async function reparto(ds: DataSource, c: ContestoPagina): Promise<{ id: number; name: string } | null> {
  const dep = c.u.departmentId ?? 0;
  if (!dep) return null;
  const [d] = await ds.query('SELECT * FROM departments WHERE id = $1 AND company_id = $2', [dep, c.u.companyId]);
  return d ?? null;
}

/** Dipendenti del reparto (Department::getEmployees), solo dell'azienda. */
function dipendentiReparto(ds: DataSource, dep: number, cid: number, soloAttivi: boolean) {
  return ds.query(`SELECT e.* FROM employees e WHERE e.department_id = $1 AND e.company_id = $2${soloAttivi ? ' AND e.is_active = true' : ''}
    ORDER BY e.last_name, e.first_name, e.id`, [dep, cid]);
}

/**
 * Area del responsabile di reparto (public/admin-reparto/): dashboard,
 * dipendenti, ferie, comunicazioni, documenti dei dipendenti, reset
 * password, Academy in sola lettura, chat e calendario.
 *
 * Correzione generale: il PHP filtrava solo per reparto (department_id), e
 * i reparti hanno id globali; qui ogni lettura e scrittura e' anche
 * dell'azienda attiva.
 */
@Injectable()
@PaginaPhp('admin-reparto/index')
export class DashboardRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource, private readonly heatmap: HeatmapService) {}

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const cid = c.u.companyId!;
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const stati = await this.ds.query(
      `SELECT lr.status, COUNT(*)::int AS count FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
        WHERE e.company_id = $1 AND e.department_id = $2 GROUP BY lr.status`, [cid, dep.id]);
    const leaveStats: Record<string, number> = { pending: 0, approved: 0, rejected: 0, total: 0 };
    for (const s of stati) { leaveStats[s.status] = s.count; leaveStats.total += s.count; }
    const recentRequests = await this.ds.query(
      `SELECT lr.*, e.first_name, e.last_name, e.photo_path FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
        WHERE e.company_id = $1 AND e.department_id = $2 ORDER BY lr.created_at DESC, lr.id DESC LIMIT 5`, [cid, dep.id]);
    const [me] = await this.ds.query('SELECT id, name, username FROM users WHERE id = $1', [c.u.id]);
    const scope = c.query.scope === 'all' ? 'all' : 'mine';
    const cmp = await compleanni(this.ds, cid);
    return vista(`Dashboard - ${dep.name}`, {
      // Nel PHP il widget sovrascrive $employees: prima della heatmap si contano i dipendenti del reparto, dopo si usano quelli del widget.
      user: { id: c.u.id, name: me?.name ?? '' }, departmentId: dep.id, department: dep, __deptEmployees: await dipendentiReparto(this.ds, dep.id, cid, true),
      leaveStats, recentRequests: recentRequests.map((r: any) => ({ ...r, attachment_path: r.attachment_path ? '1' : null, certificate_path: r.certificate_path ? '1' : null })),
      _GET: c.query, __wCompleanni: cmp.todayOthers.length > 0 || cmp.tomorrowOthers.length > 0, todayViewerName: null, ...cmp,
      ...(await this.heatmap.dati(cid, {
        departmentId: scope === 'mine' ? dep.id : null, baseUrl: '/admin-reparto/index.php', scopeToggle: true, mioReparto: dep.id, scopePredefinito: 'mine',
        ruolo: 'admin_reparto', employeeId: c.u.id,
      }, c.query)),
    });
  }
}

@Injectable()
@PaginaPhp('admin-reparto/employees')
export class DipendentiRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource) {}

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    let employees = await dipendentiReparto(this.ds, dep.id, c.u.companyId!, false);
    const search = String(c.query.search ?? '');
    if (search) {
      const s = search.toLowerCase();
      employees = employees.filter((e: any) => `${e.first_name} ${e.last_name}`.toLowerCase().includes(s) || String(e.fiscal_code ?? '').toLowerCase().includes(s)
        || String(e.email ?? '').toLowerCase().includes(s));
    }
    const attivi = employees.filter((e: any) => e.is_active).length;
    const sicuri = employees.map(({ password_hash, mfa_secret, ...e }: any) => e);
    return vista(`Dipendenti - ${dep.name}`, { department: dep, employees: sicuri, search, activeCount: attivi, inactiveCount: employees.length - attivi, _GET: c.query });
  }
}

const MESSAGGI_FERIE: Record<string, string> = {
  approved: 'Richiesta approvata con successo', rejected: 'Richiesta rifiutata', sick_docs: 'Documenti malattia aggiornati',
  cert_waived: 'Certificato segnato come non richiesto', cert_required: 'Certificato di nuovo obbligatorio',
};
const DOC_MANCANTI = `(lr.protocol_number IS NULL OR lr.protocol_number = ''
  OR ((lr.certificate_path IS NULL OR lr.certificate_path = '') AND COALESCE(lr.certificate_waived, false) = false))`;

@Injectable()
@PaginaPhp('admin-reparto/leave-requests')
export class FerieRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource, private readonly ferie: FerieService) {}

  async file(c: ContestoPagina, res: Response) {
    const quale = c.query.download_attachment !== undefined ? 'attachment' : c.query.download_cert !== undefined ? 'certificate' : null;
    if (!quale) return false;
    const r = await this.ferie.file(intero(c.query[quale === 'attachment' ? 'download_attachment' : 'download_cert']), quale, c.u, c.audit);
    if (!r.ok) { res.status(403).type('text/plain').send(h(r.error)); return true; }
    res.download(r.percorso, r.nome.replace(/[^a-zA-Z0-9._-]/g, '_'), { headers: { 'Content-Type': r.mime, 'Cache-Control': 'private, no-store' } });
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const cid = c.u.companyId!;
    const id = intero(corpo.request_id);
    if (!id) return this.dati(c);
    const r = await this.ferie.perId(id);
    if (!r || r.department_id !== dep.id || r.company_id !== cid) return this.dati(c, 'Richiesta non trovata o non appartenente al tuo reparto');
    let esito: { success: boolean; error?: string } | null = null;
    let fatto = '';
    switch (corpo.action ?? '') {
      case 'approve': esito = await this.ferie.approva(id, cid, c.audit); fatto = 'approved'; break;
      case 'reject': esito = await this.ferie.rifiuta(id, cid, corpo.rejection_reason ?? '', c.audit); fatto = 'rejected'; break;
      case 'admin_sick_docs':
        esito = await this.ferie.salvaDocumentiMalattia(r, corpo.protocol_number ?? null, file.certificate?.[0] ?? null, c.audit, 'leave_sick_docs_admin_updated', false);
        fatto = 'sick_docs';
        break;
      case 'admin_waive_cert': {
        const esonero = !!corpo.waived && corpo.waived !== '0';
        esito = r.leave_type !== 'malattia' ? { success: false, error: 'Operazione valida solo per richieste di malattia' } : await this.ferie.esoneroCertificato(r, esonero, c.audit);
        fatto = esonero ? 'cert_waived' : 'cert_required';
        break;
      }
      default: return this.dati(c);
    }
    if (esito.success) return redirect(`/admin-reparto/leave-requests.php?message=${fatto}`);
    return this.dati(c, esito.error ?? '');
  }

  async dati(c: ContestoPagina, errore = ''): Promise<EsitoPagina> {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const cid = c.u.companyId!;
    const filterStatus = c.query.status !== undefined ? String(c.query.status) : 'pending';
    const p: unknown[] = [dep.id, cid];
    if (filterStatus !== 'all') p.push(filterStatus);
    const requests = await this.ds.query(
      `SELECT lr.*, e.first_name, e.last_name, e.fiscal_code, e.photo_path, u.name AS approved_by_name
         FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id LEFT JOIN users u ON lr.approved_by = u.id
        WHERE e.department_id = $1 AND e.company_id = $2${filterStatus !== 'all' ? ' AND lr.status = $3' : ''}
        ORDER BY CASE WHEN lr.status = 'pending' AND lr.leave_type = 'malattia' AND ${DOC_MANCANTI} THEN 0 ELSE 1 END, lr.created_at DESC, lr.id DESC`, p);
    const [n] = await this.ds.query(
      `SELECT COUNT(*)::int AS n FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE e.department_id = $1 AND e.company_id = $2 AND lr.status = 'pending'`, [dep.id, cid]);
    const senzaPercorsi = (r: any) => ({ ...r, attachment_path: r.attachment_path ? '1' : null, certificate_path: r.certificate_path ? '1' : null });
    const tardive = (await this.ferie.malattieSenzaDocumenti(cid, 24)).filter((r: any) => r.department_id === dep.id);
    return vista(`Ferie e Permessi - ${dep.name}`, {
      message: MESSAGGI_FERIE[String(c.query.message ?? '')] ?? '', error: errore, filterStatus, requests: requests.map(senzaPercorsi), departmentId: dep.id,
      department: dep, __sickLate: tardive.map(senzaPercorsi), __pendingCountDept: n.n, _GET: c.query,
    });
  }
}

const MESSAGGI_COMUNICAZIONI: Record<string, string> = { created: 'Comunicazione creata con successo', toggled: 'Stato comunicazione aggiornato', deleted: 'Comunicazione eliminata' };

@Injectable()
@PaginaPhp('admin-reparto/communications')
export class ComunicazioniRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource, private readonly comunicazioni: ComunicazioniAdminService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const cid = c.u.companyId!;
    switch (corpo.action ?? '') {
      case 'create': {
        const r = await this.comunicazioni.crea({
          title: corpo.title ?? '', content: corpo.content ?? '', priority: corpo.priority ?? 'normal', publish_date: corpo.publish_date || oggiRoma(),
          expire_date: corpo.expire_date || null, is_published: corpo.is_published !== undefined, department_id: dep.id,
        }, cid, c.audit);
        if (r.success) {
          const f = file.attachment?.[0];
          if (f && f.size) await this.comunicazioni.allega(f, r.id!, false);
          return redirect('/admin-reparto/communications.php?message=created');
        }
        return this.dati(c, r.error ?? 'Errore durante la creazione');
      }
      case 'toggle':
      case 'delete': {
        const id = intero(corpo.communication_id);
        const comm = await this.comunicazioni.perId(id, cid);
        if (!comm || comm.department_id !== dep.id) return this.dati(c, corpo.action === 'toggle' ? 'Non puoi modificare questa comunicazione' : 'Non puoi eliminare questa comunicazione');
        const r = corpo.action === 'toggle' ? await this.comunicazioni.pubblicazione(id, cid, c.audit) : await this.comunicazioni.elimina(id, cid, c.audit);
        return r.success ? redirect(`/admin-reparto/communications.php?message=${corpo.action === 'toggle' ? 'toggled' : 'deleted'}`) : this.dati(c, r.error);
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, errore = ''): Promise<EsitoPagina> {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    // Il template mostra il testo senza tag (strip_tags + escape): serve anche il contenuto.
    const elenco = await this.comunicazioni.elenco(c.u.companyId!, '', dep.id);
    const testi = new Map<number, string>((await this.ds.query('SELECT id, content FROM communications WHERE id = ANY($1::int[])', [elenco.map((x: any) => x.id)]))
      .map((r: any) => [r.id, r.content]));
    return vista(`Comunicazioni - ${dep.name}`, {
      message: MESSAGGI_COMUNICAZIONI[String(c.query.message ?? '')] ?? '', error: errore, department: dep, departmentId: dep.id,
      communications: elenco.map((x: any) => ({ ...x, content: testi.get(x.id) ?? '' })), user: { id: c.u.id }, _GET: c.query,
    });
  }
}

@Injectable()
@PaginaPhp('admin-reparto/employee-documents')
export class DocumentiRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource, private readonly personali: DocumentiPersonaliService, private readonly documenti: DocumentiService) {}

  /** Dipendente del reparto e dell'azienda (ed_assert_in_dept). */
  private async delReparto(c: ContestoPagina, employeeId: number) {
    const dep = c.u.departmentId ?? 0;
    const [e] = await this.ds.query('SELECT id, first_name, last_name FROM employees WHERE id = $1 AND department_id = $2 AND company_id = $3', [employeeId, dep, c.u.companyId]);
    return e ?? null;
  }

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download === undefined) return false;
    await scaricaDocumentoPersonale(this.documenti, c, res, intero(c.query.download));
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const emp = intero(corpo.employee_id);
    if (!emp) return json('employee_id mancante', 400);
    if (!(await this.delReparto(c, emp))) return json('Dipendente fuori dal tuo reparto', 403);
    const torna = (stato: string) => redirect(`/admin-reparto/employee-documents.php?employee_id=${emp}&ed_status=${new URLSearchParams({ x: stato }).toString().slice(2)}#docs`);
    if (corpo.action === 'upload') {
      const f = [...(file['documents[]'] ?? []), ...(file.documents ?? []), ...(file.document ?? [])];
      if (!f.length) return torna('no_file');
      return torna(await this.personali.caricaMolti(f, { employee_id: emp, name: corpo.name ?? '', visible_to_employee: !!corpo.visible_to_employee, expires_on: corpo.expires_on || null }, cid, c.audit));
    }
    if (['rename', 'toggle_visibility', 'update_expiry', 'delete'].includes(corpo.action ?? '')) {
      const doc = intero(corpo.document_id);
      const attuale = doc ? await this.personali.perId(doc, cid) : null;
      if (!attuale) return torna('notfound');
      if (!(await this.delReparto(c, attuale.employee_id))) return json('Dipendente fuori dal tuo reparto', 403);
      let r: { success: boolean; error?: string };
      if (corpo.action === 'delete') r = await this.personali.elimina(doc, cid, c.audit);
      else {
        const dati = corpo.action === 'rename' ? { name: corpo.name ?? '' } : corpo.action === 'toggle_visibility' ? { visible_to_employee: !attuale.visible_to_employee } : { expires_on: corpo.expires_on ?? '' };
        r = await this.personali.aggiorna(doc, dati, cid, c.audit);
      }
      return torna(r.success ? (corpo.action === 'delete' ? 'deleted' : 'updated') : `error_${r.error ?? 'unknown'}`);
    }
    return torna('');
  }

  async dati(c: ContestoPagina): Promise<EsitoPagina> {
    if (!(c.u.departmentId ?? 0)) return json('Nessun reparto assegnato', 403);
    const emp = intero(c.query.employee_id);
    if (!emp) return redirect('/admin-reparto/employees.php');
    const e = await this.delReparto(c, emp);
    if (!e) return json('Dipendente fuori dal tuo reparto', 403);
    return vista('Documenti dipendente', { employee: e, employeeId: emp, documents: await this.personali.perDipendente(emp), edStatus: String(c.query.ed_status ?? ''), __edDocs: await this.personali.perDipendente(emp) });
  }
}

@Injectable()
@PaginaPhp('admin-reparto/password-resets')
export class PasswordRepartoPagina implements Pagina {
  readonly ruoli = ['admin_reparto'];
  constructor(private readonly ds: DataSource, private readonly password: PasswordService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const cid = c.u.companyId!;
    const ctx = { ...c.audit, tipo: 'admin_reparto' };
    const id = intero(corpo.request_id);
    if (id && ['approve', 'reject'].includes(corpo.action ?? '')) {
      if (corpo.action === 'approve') {
        const r = await this.password.approvaRichiesta(id, cid, false, ctx, dep.id);
        if (!r.ok) return this.dati(c, '', r.errore === 'Richiesta non trovata' ? 'Richiesta non trovata o non appartenente al tuo reparto' : r.errore);
        return this.dati(c, `Richiesta approvata! Invia questo link al dipendente:<br><br>
                            <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:1rem;margin:0.5rem 0;">
                                <input type="text" value="${h(r.link)}"
                                       id="resetLink" readonly
                                       style="width:100%;padding:0.5rem;border:1px solid #cbd5e0;border-radius:4px;font-size:0.85rem;">
                                <button onclick="copyResetLink()" style="margin-top:0.5rem;padding:0.4rem 1rem;background:#38a169;color:white;border:none;border-radius:4px;cursor:pointer;">
                                    Copia Link
                                </button>
                                <span id="copyMsg" style="margin-left:0.5rem;color:#276749;display:none;">Copiato!</span>
                            </div>
                            <small style="color:#718096;">Il link scade tra 24 ore.</small>`);
      }
      const r = await this.password.rifiutaRichiesta(id, cid, false, String(corpo.notes ?? '').trim(), ctx, dep.id);
      return r.ok ? this.dati(c, 'Richiesta rifiutata') : this.dati(c, '', 'Richiesta non trovata o non appartenente al tuo reparto');
    }
    if (corpo.action === 'manual_reset') {
      const r = await this.password.reimpostaDaAdmin('employee', intero(corpo.employee_id), cid, false, ctx, dep.id);
      return r.ok ? this.dati(c, `Password resettata. Nuova password temporanea: <code style="background:#edf2f7;padding:0.25rem 0.5rem;border-radius:4px;">${h(r.password)}</code>`)
        : this.dati(c, '', r.errore);
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, message = '', error = ''): Promise<EsitoPagina> {
    const dep = await reparto(this.ds, c);
    if (!dep) return vista('PAManager', SENZA_REPARTO);
    const cid = c.u.companyId!;
    const filterStatus = c.query.status ? String(c.query.status) : null;
    const p: unknown[] = [dep.id, cid];
    if (filterStatus) p.push(filterStatus);
    const requests = await this.ds.query(
      `SELECT prr.id, prr.user_type, prr.user_id, prr.status, prr.created_at, prr.resolved_at, prr.notes, e.username AS user_username,
              concat(e.last_name, ' ', e.first_name) AS user_name, e.email AS user_email, resolver.name AS resolved_by_name
         FROM password_reset_requests prr JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id LEFT JOIN users resolver ON prr.resolved_by = resolver.id
        WHERE e.department_id = $1 AND e.company_id = $2${filterStatus ? ' AND prr.status = $3' : ''} ORDER BY prr.created_at DESC, prr.id DESC`, p);
    const [n] = await this.ds.query(
      `SELECT COUNT(*)::int AS n FROM password_reset_requests prr JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
        WHERE prr.status = 'pending' AND e.department_id = $1 AND e.company_id = $2`, [dep.id, cid]);
    const employees = (await dipendentiReparto(this.ds, dep.id, cid, true)).map((e: any) => ({ id: e.id, first_name: e.first_name, last_name: e.last_name, fiscal_code: e.fiscal_code }));
    return vista(`Reset Password - ${dep.name}`, { message, error, filterStatus, requests, pendingCount: n.n, employees, department: dep, departmentId: dep.id, _GET: c.query });
  }
}

/** Academy del responsabile di reparto: sola lettura sul suo reparto. */
@Injectable()
@PaginaPhp('admin-reparto/academy')
export class AcademyRepartoPagina extends AcademyStaffPagina {
  readonly ruoli = ['admin_reparto'];
  constructor(ds: DataSource, staff: AcademyStaffService, attestati: AttestatiService) { super(ds, staff, attestati); }
}
