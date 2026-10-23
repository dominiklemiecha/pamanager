import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { FerieService } from '../../ferie/ferie.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const MESSAGGI: Record<string, string> = {
  approved: 'Richiesta approvata con successo',
  rejected: 'Richiesta rifiutata',
  updated: 'Richiesta aggiornata',
  deleted: 'Richiesta eliminata',
  sick_docs: 'Documenti malattia aggiornati',
  cert_waived: 'Certificato segnato come non richiesto',
  cert_required: 'Certificato di nuovo obbligatorio',
};

/** Malattia con protocollo o certificato mancanti (stessa condizione del PHP). */
const DOC_MANCANTI = `(lr.protocol_number IS NULL OR lr.protocol_number = ''
  OR ((lr.certificate_path IS NULL OR lr.certificate_path = '') AND COALESCE(lr.certificate_waived, false) = false))`;

const SELEZIONE = `SELECT lr.*, e.first_name, e.last_name, e.fiscal_code, e.photo_path,
       d.name AS department_name, d.code AS department_code, u.name AS approved_by_name
  FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
  LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN users u ON lr.approved_by = u.id
 WHERE e.company_id = $1`;

/** urlencode() del PHP (spazi come +). */
const urlencode = (s: string) => new URLSearchParams({ x: s }).toString().slice(2);

/**
 * Ferie e permessi dell'HR (admin/leave-requests.php): elenco per stato e
 * reparto, approvazione, rifiuto, modifica, eliminazione, inserimento
 * manuale (anche a tutti i dipendenti), documenti della malattia.
 *
 * Correzione: il messaggio dopo il redirect si mostra solo se e' uno di
 * quelli della pagina o il riepilogo dell'inserimento manuale (il PHP
 * rifletteva qualsiasi testo passato in ?message=); le richieste di altre
 * aziende risultano inesistenti (il PHP ne confermava l'esistenza).
 */
@Injectable()
@PaginaPhp('admin/leave-requests')
export class FerieAdminPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly ferie: FerieService) {}

  async file(c: ContestoPagina, res: Response) {
    const quale = c.query.download_attachment !== undefined ? 'attachment' : c.query.download_cert !== undefined ? 'certificate' : null;
    if (!quale) return false;
    const id = Number.parseInt(String(c.query[quale === 'attachment' ? 'download_attachment' : 'download_cert']), 10) || 0;
    const r = await this.ferie.file(id, quale, c.u, c.audit);
    if (!r.ok) { res.status(403).type('text/plain').send(r.error.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')); return true; }
    res.download(r.percorso, r.nome.replace(/[^a-zA-Z0-9._-]/g, '_'), { headers: { 'Content-Type': r.mime, 'Cache-Control': 'private, no-store' } });
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const azione = corpo.action ?? '';
    const id = Number.parseInt(corpo.request_id ?? '', 10) || 0;

    if (azione === 'admin_create') {
      const [me] = await senzaIsolamento(() => this.ds.query('SELECT name, username FROM users WHERE id = $1', [c.u.id]));
      const intera = !!corpo.is_full_day;
      const inizio = corpo.start_date ?? '';
      const motivo = String(corpo.reason ?? '').trim() || `Inserito da admin (${me?.name ?? me?.username ?? ''})`;
      const reparto = corpo.target_department_id ? Number.parseInt(corpo.target_department_id, 10) || null : null;
      let dipendenti: number[] = [];
      if (corpo.apply_all) {
        const p: unknown[] = [cid];
        let sql = 'SELECT id FROM employees WHERE is_active = true AND company_id = $1';
        if (reparto) { p.push(reparto); sql += ' AND department_id = $2'; }
        dipendenti = (await this.ds.query(sql + ' ORDER BY id', p)).map((r: any) => r.id);
      } else if (corpo.employee_id) {
        dipendenti = [Number.parseInt(corpo.employee_id, 10) || 0];
      }
      if (!dipendenti.length) return this.dati(c, 'Seleziona almeno un dipendente o spunta "applica a tutti".');
      let create = 0;
      let saltate = 0;
      for (const eid of dipendenti) {
        const r = await this.ferie.creaDaAdmin({
          employee_id: eid, leave_type: corpo.leave_type ?? '', start_date: inizio, end_date: corpo.end_date ?? inizio, is_full_day: intera,
          start_time: intera ? null : (corpo.start_time ?? null), end_time: intera ? null : (corpo.end_time ?? null),
          reason: motivo, notes: String(corpo.notes ?? '').trim(),
        }, c.audit, cid);
        if (r.success) create++;
        else saltate++;
      }
      let msg = `Create ${create} richieste`;
      if (saltate > 0) msg += ` (saltate ${saltate} — sovrapposizione o errore)`;
      return redirect(`/admin/leave-requests.php?status=approved&message=${urlencode(msg)}`);
    }

    if (!id) return this.dati(c);
    let r: { success: boolean; error?: string } | null = null;
    let fatto = '';
    switch (azione) {
      case 'approve':
        r = await this.ferie.approva(id, cid, c.audit); fatto = 'approved'; break;
      case 'reject':
        r = await this.ferie.rifiuta(id, cid, corpo.rejection_reason ?? '', c.audit); fatto = 'rejected'; break;
      case 'admin_edit':
        r = await this.ferie.aggiornaDaAdmin(id, cid, {
          leave_type: corpo.leave_type ?? null, start_date: corpo.start_date ?? null, end_date: corpo.end_date ?? null,
          is_full_day: corpo.is_full_day !== undefined, start_time: corpo.start_time ?? null, end_time: corpo.end_time ?? null,
          reason: corpo.reason ?? null, notes: corpo.notes ?? null, status: corpo.status ?? null,
        }, c.audit);
        fatto = 'updated';
        break;
      case 'admin_delete':
        r = await this.ferie.elimina(id, cid, c.audit); fatto = 'deleted'; break;
      case 'admin_sick_docs':
        r = await this.documentiMalattia(id, cid, corpo.protocol_number ?? null, file.certificate?.[0] ?? null, c);
        fatto = 'sick_docs';
        break;
      case 'admin_waive_cert': {
        const esonero = !!corpo.waived && corpo.waived !== '0';
        r = await this.esonero(id, cid, esonero, c);
        fatto = esonero ? 'cert_waived' : 'cert_required';
        break;
      }
      default:
        return this.dati(c);
    }
    if (r.success) return redirect(`/admin/leave-requests.php?message=${fatto}`);
    return this.dati(c, r.error ?? '');
  }

  /** LeaveRequest::adminSaveSickDocs: solo l'azienda attiva, solo malattie. */
  private async documentiMalattia(id: number, cid: number, protocollo: string | null, cert: FileCaricato | null, c: ContestoPagina) {
    const r = await this.ferie.perId(id);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== cid) return { success: false, error: "Richiesta non appartiene all'azienda attiva" };
    return this.ferie.salvaDocumentiMalattia(r, protocollo, cert, c.audit, 'leave_sick_docs_admin_updated', false);
  }

  /** LeaveRequest::setCertificateWaived. */
  private async esonero(id: number, cid: number, esonero: boolean, c: ContestoPagina) {
    const r = await this.ferie.perId(id);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== cid) return { success: false, error: "Richiesta non appartiene all'azienda attiva" };
    if (r.leave_type !== 'malattia') return { success: false, error: 'Operazione valida solo per richieste di malattia' };
    return this.ferie.esoneroCertificato(r, esonero, c.audit);
  }

  async dati(c: ContestoPagina, errore = '') {
    const cid = c.u.companyId!;
    const q = c.query;
    let message = '';
    if (q.message !== undefined) {
      const m = String(q.message);
      message = MESSAGGI[m] ?? (/^Create \d+ richieste( \(saltate \d+ — sovrapposizione o errore\))?$/.test(m) ? m : '');
    }

    const activeEmployees = await this.ds.query(
      'SELECT id, first_name, last_name, department_id FROM employees WHERE is_active = true AND company_id = $1 ORDER BY last_name, first_name', [cid]);
    const filterStatus = q.status !== undefined ? String(q.status) : 'pending';
    const filterDept = q.department ? Number.parseInt(String(q.department), 10) || null : null;

    let requests: any[];
    const p: unknown[] = [cid];
    if (filterStatus === 'pending') {
      let sql = `${SELEZIONE} AND (lr.status = 'pending' OR (lr.leave_type = 'malattia' AND lr.status IN ('pending','approved')
                   AND lr.created_at <= CURRENT_TIMESTAMP - interval '24 hours' AND ${DOC_MANCANTI}))`;
      if (filterDept) { p.push(filterDept); sql += ` AND e.department_id = $${p.length}`; }
      requests = await this.ds.query(`${sql} ORDER BY CASE WHEN lr.leave_type = 'malattia' AND ${DOC_MANCANTI} THEN 0 ELSE 1 END, lr.created_at DESC`, p);
    } else {
      let sql = SELEZIONE;
      if (filterStatus !== 'all') { p.push(filterStatus); sql += ` AND lr.status = $${p.length}`; }
      if (filterDept) { p.push(filterDept); sql += ` AND e.department_id = $${p.length}`; }
      requests = await this.ds.query(`${sql} ORDER BY CASE WHEN lr.status = 'pending' AND lr.leave_type = 'malattia' AND ${DOC_MANCANTI} THEN 0 ELSE 1 END, lr.created_at DESC`, p);
    }
    const departments = await this.ds.query(
      `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_active = true) AS employee_count,
              (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'admin_reparto' AND u.is_active = true) AS admin_count
         FROM departments d WHERE d.company_id = $1 AND d.is_active = true ORDER BY d.name ASC`, [cid]);

    const oggi = oggiRoma();
    const n = async (sql: string, par: unknown[]) => Number((await this.ds.query(sql, par))[0]?.n ?? 0);
    const __lrPending = await n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'pending'`, [cid]);
    const __lrApproved = await n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'approved' AND end_date >= $2`, [cid, oggi]);
    const __lrTotal = await n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1
                                AND extract(year FROM created_at AT TIME ZONE 'Europe/Rome') = $2`, [cid, Number(oggi.slice(0, 4))]);
    const __pendingCount = await n(
      `SELECT COUNT(DISTINCT lr.id)::int AS n FROM leave_requests lr WHERE lr.company_id = $1
          AND (lr.status = 'pending' OR (lr.leave_type = 'malattia' AND lr.status IN ('pending','approved')
               AND lr.created_at <= CURRENT_TIMESTAMP - interval '24 hours' AND ${DOC_MANCANTI}))`, [cid]);
    const [pausa] = await senzaIsolamento(() => this.ds.query(
      'SELECT lunch_break_start, lunch_break_end FROM companies WHERE id = $1', [cid]));

    return vista('Ferie e Permessi', {
      message, error: errore, activeEmployees, filterStatus, filterDept, requests, departments,
      __lrPending, __lrApproved, __lrTotal,
      __sickLate: await this.ferie.malattieSenzaDocumenti(cid, 24),
      __pendingCountDb: __pendingCount,
      __lunchCfgDb: pausa ?? false,
    });
  }
}
