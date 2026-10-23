import { Injectable, Logger } from '@nestjs/common';
import * as fs from 'fs';
import { DataSource } from 'typeorm';
import { DIMENSIONE_MASSIMA, ESTENSIONI_DOCUMENTI, FileCaricato, contenutoCoerente, estensione, fileSicuro, mimeDaContenuto, salvaPrivato } from '../../common/file/caricamenti';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import type { UtenteCorrente } from '../auth/auth.types';
import { NotificheService } from '../notifiche/notifiche.service';
import { PushService } from '../notifiche/push.service';

/** Tipi di assenza con le etichette del PHP (LeaveRequest::LEAVE_TYPES). */
export const TIPI: Record<string, string> = {
  ferie: 'Ferie', permesso: 'Permesso', malattia: 'Malattia', permesso_104: 'Permesso L.104',
  congedo_parentale: 'Congedo Parentale', congedo_separazione: 'Congedo Separazione', congedo_mestruale: 'Congedo Mestruale',
  altro: 'Altro', chiusura: 'Chiusura aziendale', smart_working: 'Smart working',
};
export const STATI: Record<string, string> = { pending: 'In Attesa', approved: 'Approvata', rejected: 'Rifiutata', cancelled: 'Annullata' };

/**
 * Tipi che un dipendente puo' chiedere. Correzione: il PHP accettava anche
 * "chiusura" (la chiusura aziendale la registra l'HR).
 */
const TIPI_DIPENDENTE = Object.keys(TIPI).filter((t) => t !== 'chiusura');

export interface DatiRichiesta {
  employee_id: number;
  leave_type: string;
  start_date: string;
  end_date: string;
  is_full_day: boolean;
  start_time?: string | null;
  end_time?: string | null;
  reason: string;
  notes?: string | null;
  protocol_number?: string | null;
}

export type Esito = { success: true; id?: number } | { success: false; error: string };

const DATA = /^\d{4}-\d{2}-\d{2}$/;
const ORA = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
const dataValida = (s: string) => DATA.test(s) && !Number.isNaN(Date.parse(`${s}T00:00:00Z`)) && new Date(`${s}T00:00:00Z`).toISOString().slice(0, 10) === s;
const ddmmyyyy = (s: string) => `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}`;
const periodo = (s: string, e: string) => ddmmyyyy(s) + (s !== e ? ` - ${ddmmyyyy(e)}` : '');

/**
 * Richieste di ferie, permessi e malattia (LeaveRequest.php).
 * Ogni operazione verifica proprieta' o azienda della richiesta lato server.
 */
@Injectable()
export class FerieService {
  private readonly logger = new Logger('Ferie');

  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly notifiche: NotificheService, private readonly push: PushService) {}

  async perId(id: number) {
    const [r] = await this.ds.query(
      `SELECT lr.*, e.first_name, e.last_name, e.fiscal_code, e.email AS employee_email, e.department_id,
              d.name AS department_name, d.code AS department_code, u.name AS approved_by_name
         FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
         LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.id = $1`, [id]);
    return r ?? null;
  }

  /** Richieste del dipendente, dalla piu' recente. */
  async delDipendente(employeeId: number, stato?: string | null, anno?: number | null) {
    const p: unknown[] = [employeeId];
    let sql = `SELECT lr.*, u.name AS approved_by_name FROM leave_requests lr LEFT JOIN users u ON lr.approved_by = u.id WHERE lr.employee_id = $1`;
    if (stato) { p.push(stato); sql += ` AND lr.status = $${p.length}`; }
    if (anno) { p.push(anno); sql += ` AND EXTRACT(YEAR FROM lr.start_date) = $${p.length}`; }
    return this.ds.query(sql + ' ORDER BY lr.created_at DESC, lr.id DESC', p);
  }

  /** Validazioni e inserimento, come LeaveRequest::create (piu' i controlli di formato che mancavano). */
  async crea(d: DatiRichiesta, ctx: ContestoAudit & { tipo: string; id: number }, daDipendente = true): Promise<Esito> {
    if (!d.employee_id) return { success: false, error: 'Dipendente obbligatorio' };
    if (!d.leave_type || !TIPI[d.leave_type] || (daDipendente && !TIPI_DIPENDENTE.includes(d.leave_type))) return { success: false, error: 'Tipo assenza non valido' };
    if (!d.start_date) return { success: false, error: 'Data inizio obbligatoria' };
    if (!d.end_date) return { success: false, error: 'Data fine obbligatoria' };
    if (!dataValida(d.start_date) || !dataValida(d.end_date)) return { success: false, error: 'Data non valida' };
    if (d.start_date > d.end_date) return { success: false, error: 'La data di fine deve essere uguale o successiva alla data di inizio' };
    if (!d.reason || !String(d.reason).trim()) return { success: false, error: 'Motivazione obbligatoria' };
    const [e] = await this.ds.query('SELECT * FROM employees WHERE id = $1', [d.employee_id]);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    const intera = d.is_full_day !== false;
    const inizio = !intera && d.start_time ? d.start_time : null;
    const fine = !intera && d.end_time ? d.end_time : null;
    if ((inizio && !ORA.test(inizio)) || (fine && !ORA.test(fine))) return { success: false, error: 'Orario non valido' };
    if (inizio && fine && fine <= inizio) return { success: false, error: "L'ora di fine deve essere successiva all'ora di inizio" };
    if (await this.sovrapposta(d.employee_id, d.start_date, d.end_date, null, intera, inizio, fine)) {
      return { success: false, error: 'Esiste già una richiesta per questo periodo' };
    }
    // Permesso L.104: diritto del dipendente, niente approvazione.
    const auto = d.leave_type === 'permesso_104';
    const protocollo = d.protocol_number && String(d.protocol_number).trim() !== '' ? String(d.protocol_number).trim() : null;
    try {
      const [r] = await this.ds.query(
        `INSERT INTO leave_requests (company_id, employee_id, leave_type, start_date, end_date, is_full_day, start_time, end_time, reason, notes, protocol_number, status, approved_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, ${auto ? 'CURRENT_TIMESTAMP' : 'NULL'}) RETURNING id`,
        [e.company_id, d.employee_id, d.leave_type, d.start_date, d.end_date, intera, inizio, fine, String(d.reason).trim(),
          (d.notes ?? '').trim() || null, protocollo, auto ? 'approved' : 'pending']);
      await this.registra(auto ? 'leave_request_auto_approved' : 'leave_request_created', r.id, null, { ...d }, ctx);
      if (!auto) await this.avvisaResponsabili(e, d);
      return { success: true, id: r.id };
    } catch (err: any) {
      this.logger.error(`richiesta non creata: ${err?.message ?? err}`);
      return { success: false, error: 'Errore durante la creazione della richiesta' };
    }
  }

  /** Sovrapposizioni (LeaveRequest::hasOverlap), orari disgiunti nello stesso giorno ammessi. */
  async sovrapposta(employeeId: number, da: string, a: string, escludi: number | null, intera: boolean, inizio: string | null, fine: string | null): Promise<boolean> {
    const p: unknown[] = [employeeId, a, da, da, da, da, a];
    let sql = `SELECT COUNT(*)::int AS n FROM leave_requests WHERE employee_id = $1 AND status NOT IN ('rejected', 'cancelled')
      AND ((start_date <= $2 AND end_date >= $3) OR (start_date <= $4 AND end_date >= $5) OR (start_date >= $6 AND end_date <= $7))`;
    if (!intera && inizio !== null && fine !== null) {
      p.push(da, a, fine, inizio);
      sql += ` AND NOT (is_full_day = false AND start_time IS NOT NULL AND end_time IS NOT NULL AND start_date = $8 AND end_date = $9
               AND (start_time >= $10 OR end_time <= $11))`;
    }
    if (escludi !== null) { p.push(escludi); sql += ` AND id <> $${p.length}`; }
    const [r] = await this.ds.query(sql, p);
    return r.n > 0;
  }

  /** Allegato generico della richiesta (non malattia). */
  async allega(requestId: number, f: FileCaricato): Promise<Esito> {
    const r = await this.perId(requestId);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    const v = this.validaFile(f, 'File troppo grande', 'Tipo file non consentito');
    if (v) return { success: false, error: v };
    const percorso = salvaPrivato('leave_requests', '', f);
    const vecchio = fileSicuro(r.attachment_path);
    if (vecchio) fs.unlinkSync(vecchio);
    await this.ds.query('UPDATE leave_requests SET attachment_path = $2, attachment_name = $3 WHERE id = $1', [requestId, percorso, f.originalname]);
    return { success: true };
  }

  private validaFile(f: FileCaricato, troppoGrande: string, tipoNo: string): string | null {
    if (f.size > DIMENSIONE_MASSIMA) return troppoGrande;
    const ext = estensione(f.originalname);
    if (!ESTENSIONI_DOCUMENTI.includes(ext) || !contenutoCoerente(ext, f.buffer)) return tipoNo;
    return null;
  }

  /** Protocollo INPS e certificato su una propria malattia in attesa o approvata. */
  async documentiMalattia(requestId: number, employeeId: number, protocollo: string | null, cert: FileCaricato | null, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const r = await this.perId(requestId);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.employee_id !== employeeId) return { success: false, error: 'Accesso non autorizzato' };
    return this.salvaDocumentiMalattia(r, protocollo, cert, ctx, 'leave_sick_docs_updated', true);
  }

  async salvaDocumentiMalattia(r: any, protocollo: string | null, cert: FileCaricato | null, ctx: ContestoAudit & { tipo: string; id: number }, azione: string, daDipendente: boolean): Promise<Esito> {
    if (r.leave_type !== 'malattia') return { success: false, error: daDipendente ? 'I documenti sono richiesti solo per le malattie' : 'Operazione valida solo per richieste di malattia' };
    if (daDipendente && !['pending', 'approved'].includes(r.status)) return { success: false, error: 'Non puoi aggiornare richieste in questo stato' };
    const agg: Record<string, string> = {};
    const p = protocollo !== null ? protocollo.trim() : '';
    if (p !== '') agg.protocol_number = p.slice(0, 100);
    if (cert) {
      const v = this.validaFile(cert, daDipendente ? 'Certificato troppo grande' : 'Certificato troppo grande',
        daDipendente ? 'Tipo file certificato non consentito' : 'Tipo file non consentito');
      if (v) return { success: false, error: v };
      agg.certificate_path = salvaPrivato('leave_requests', 'cert_', cert, 12);
      agg.certificate_name = cert.originalname;
      const vecchio = fileSicuro(r.certificate_path);
      if (vecchio) fs.unlinkSync(vecchio);
    }
    const chiavi = Object.keys(agg);
    if (!chiavi.length) return { success: false, error: 'Nessun documento fornito' };
    await this.ds.query(`UPDATE leave_requests SET ${chiavi.map((k, i) => `${k} = $${i + 2}`).join(', ')} WHERE id = $1`, [r.id, ...chiavi.map((k) => agg[k])]);
    await this.registra(azione, r.id, r, chiavi as unknown as Record<string, unknown>, ctx);
    return { success: true };
  }

  /** Certificato segnato come non richiesto (o di nuovo obbligatorio) dall'HR. */
  async esoneroCertificato(r: any, esonero: boolean, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    await this.ds.query('UPDATE leave_requests SET certificate_waived = $2 WHERE id = $1', [r.id, esonero]);
    await this.registra(esonero ? 'leave_sick_cert_waived' : 'leave_sick_cert_required', r.id, r, [] as unknown as Record<string, unknown>, ctx);
    return { success: true };
  }

  async annulla(requestId: number, employeeId: number, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const r = await this.perId(requestId);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.employee_id !== employeeId) return { success: false, error: 'Non puoi annullare richieste di altri dipendenti' };
    if (r.status !== 'pending') return { success: false, error: 'Solo le richieste in attesa possono essere annullate' };
    await this.ds.query("UPDATE leave_requests SET status = 'cancelled' WHERE id = $1", [requestId]);
    await this.registra('leave_request_cancelled', requestId, { status: 'pending' }, { status: 'cancelled' }, ctx);
    return { success: true };
  }

  // ─── Operazioni dell'HR ─────────────────────────────────────────────────

  /**
   * Approvazione (LeaveRequest::approve). La malattia si approva solo con
   * protocollo e certificato (o certificato segnato come non richiesto).
   */
  async approva(id: number, companyId: number, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const r = await this.perId(id);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== companyId) return { success: false, error: "Richiesta non appartiene all'azienda corrente" };
    if (r.status !== 'pending') return { success: false, error: 'Solo le richieste in attesa possono essere approvate' };
    if (r.leave_type === 'malattia') {
      const manca = [];
      if (!r.protocol_number) manca.push('numero protocollo');
      if (!r.certificate_path && !r.certificate_waived) manca.push('certificato medico');
      if (manca.length) {
        return { success: false, error: `Impossibile approvare: manca ${manca.join(' e ')}. La richiesta resta in attesa finché i documenti non vengono caricati o il certificato segnato come non richiesto.` };
      }
    }
    try {
      await this.ds.query(`UPDATE leave_requests SET status = 'approved', approved_by = $2, approved_at = CURRENT_TIMESTAMP WHERE id = $1`, [id, ctx.id]);
      await this.registra('leave_request_approved', id, { status: 'pending' }, { status: 'approved' }, ctx);
      await this.esitoAlDipendente(r, 'leave_approved');
      await this.avvisaCommercialisti(r);
      return { success: true };
    } catch (err: any) {
      this.logger.error(`approvazione non riuscita: ${err?.message ?? err}`);
      return { success: false, error: "Errore durante l'approvazione" };
    }
  }

  /** Rifiuto con motivo facoltativo (LeaveRequest::reject). */
  async rifiuta(id: number, companyId: number, motivo: string, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const r = await this.perId(id);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== companyId) return { success: false, error: "Richiesta non appartiene all'azienda corrente" };
    if (r.status !== 'pending') return { success: false, error: 'Solo le richieste in attesa possono essere rifiutate' };
    try {
      await this.ds.query(`UPDATE leave_requests SET status = 'rejected', approved_by = $2, approved_at = CURRENT_TIMESTAMP, rejection_reason = $3 WHERE id = $1`,
        [id, ctx.id, String(motivo ?? '').trim() || null]);
      await this.registra('leave_request_rejected', id, { status: 'pending' }, { status: 'rejected', rejection_reason: motivo ?? '' }, ctx);
      await this.esitoAlDipendente(r, 'leave_rejected', motivo ?? '');
      return { success: true };
    } catch (err: any) {
      this.logger.error(`rifiuto non riuscito: ${err?.message ?? err}`);
      return { success: false, error: 'Errore durante il rifiuto' };
    }
  }

  /** Richiesta inserita dall'HR: gia' approvata (LeaveRequest::createByAdmin). */
  async creaDaAdmin(d: DatiRichiesta, ctx: ContestoAudit & { tipo: string; id: number }, companyId: number): Promise<Esito> {
    const [e] = await this.ds.query('SELECT company_id FROM employees WHERE id = $1', [d.employee_id]);
    if (!e || e.company_id !== companyId) return { success: false, error: 'Dipendente non trovato' };
    const r = await this.crea(d, ctx, false);
    if (!r.success) return r;
    await this.ds.query(`UPDATE leave_requests SET status = 'approved', approved_by = $2, approved_at = CURRENT_TIMESTAMP, created_by_admin = true WHERE id = $1`, [r.id, ctx.id])
      .catch((err) => this.logger.error(`createByAdmin: ${err?.message ?? err}`));
    return r;
  }

  /** Eliminazione (con l'allegato) da parte dell'HR. */
  async elimina(id: number, companyId: number, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const [r] = await this.ds.query('SELECT * FROM leave_requests WHERE id = $1', [id]);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== companyId) return { success: false, error: "Richiesta non appartiene all'azienda corrente" };
    try {
      const p = fileSicuro(r.attachment_path);
      if (p) fs.rmSync(p, { force: true });
      await this.ds.query('DELETE FROM leave_requests WHERE id = $1', [id]);
      await this.registra('leave_request_deleted', id, r, null, ctx);
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'eliminazione" };
    }
  }

  /** Modifica forzata dell'HR (LeaveRequest::adminUpdate): niente controllo sovrapposizioni. */
  async aggiornaDaAdmin(id: number, companyId: number, d: Record<string, any>, ctx: ContestoAudit & { tipo: string; id: number }): Promise<Esito> {
    const [r] = await this.ds.query('SELECT * FROM leave_requests WHERE id = $1', [id]);
    if (!r) return { success: false, error: 'Richiesta non trovata' };
    if (r.company_id !== companyId) return { success: false, error: "Richiesta non appartiene all'azienda corrente" };
    const campi: Record<string, unknown> = {};
    if (d.leave_type != null && TIPI[d.leave_type]) campi.leave_type = d.leave_type;
    if (d.start_date && dataValida(String(d.start_date))) campi.start_date = d.start_date;
    if (d.end_date && dataValida(String(d.end_date))) campi.end_date = d.end_date;
    if (d.is_full_day !== undefined) campi.is_full_day = !!d.is_full_day;
    if ('start_time' in d) campi.start_time = d.start_time && ORA.test(String(d.start_time)) ? d.start_time : null;
    if ('end_time' in d) campi.end_time = d.end_time && ORA.test(String(d.end_time)) ? d.end_time : null;
    if (d.reason != null) campi.reason = String(d.reason).trim();
    if ('notes' in d) campi.notes = d.notes !== '' && d.notes != null ? String(d.notes) : null;
    if (d.status != null && STATI[d.status]) campi.status = d.status;
    const chiavi = Object.keys(campi);
    if (!chiavi.length) return { success: false, error: 'Nessun dato da aggiornare' };
    try {
      await this.ds.query(`UPDATE leave_requests SET ${chiavi.map((k, i) => `${k} = $${i + 2}`).join(', ')} WHERE id = $1`, [id, ...chiavi.map((k) => campi[k])]);
      await this.registra('leave_request_admin_update', id, r, campi, ctx);
      return { success: true };
    } catch (err: any) {
      this.logger.error(`aggiornamento non riuscito: ${err?.message ?? err}`);
      return { success: false, error: "Errore durante l'aggiornamento" };
    }
  }

  /**
   * Esito al dipendente: notifica nel portale e push (LeaveRequest::createNotification).
   * Correzione: la notifica va nell'azienda del dipendente (nel PHP sempre l'azienda 1).
   */
  private async esitoAlDipendente(r: any, tipo: 'leave_approved' | 'leave_rejected', motivo = '') {
    try {
      const nomeTipo = TIPI[r.leave_type] ?? r.leave_type;
      let messaggio = `La tua richiesta di ${nomeTipo} per il ${periodo(r.start_date, r.end_date)} è stata ` + (tipo === 'leave_approved' ? 'approvata.' : 'rifiutata.');
      if (tipo === 'leave_rejected' && motivo) messaggio += ` Motivo: ${motivo}`;
      await this.notifiche.crea(r.company_id, {
        recipient_type: 'employee', recipient_id: r.employee_id, type: tipo,
        title: tipo === 'leave_approved' ? 'Richiesta Approvata' : 'Richiesta Rifiutata', message: messaggio, link: '/employee/leave-requests.php',
      }, false);
      const PUSH: Record<string, string> = { ferie: 'Ferie', permesso: 'Permesso', malattia: 'Malattia', permesso_104: 'Permesso L.104', congedo_parentale: 'Congedo Parentale', altro: 'Richiesta' };
      const stato = tipo === 'leave_approved' ? 'approvata' : 'rifiutata';
      await this.push.aUtente('employee', r.employee_id, {
        title: `${PUSH[r.leave_type] ?? 'Richiesta'} ${stato}`, body: `La tua richiesta di ${(PUSH[r.leave_type] ?? r.leave_type).toLowerCase()} è stata ${stato}`,
        url: '/employee/leave-requests.php', tag: `leave-response-${Math.floor(Date.now() / 1000)}`, icon: '/assets/images/icon.php?size=192',
      }).catch(() => undefined);
    } catch (err: any) {
      this.logger.error(`esito non notificato: ${err?.message ?? err}`);
    }
  }

  /**
   * Commercialisti avvisati di un'assenza approvata (LeaveRequest::notifyAccountants).
   * Correzione: solo quelli dell'azienda della richiesta (o collegati), non di altre aziende.
   */
  private async avvisaCommercialisti(r: any) {
    try {
      const acc = await this.ds.query(
        `SELECT DISTINCT u.id FROM users u WHERE u.role = 'accountant' AND (u.company_id = $1 OR u.id IN (SELECT user_id FROM user_companies WHERE company_id = $1)) ORDER BY u.id`, [r.company_id]);
      const tipo = TIPI[r.leave_type] ?? r.leave_type;
      for (const a of acc) {
        await this.notifiche.crea(r.company_id, {
          recipient_type: 'accountant', recipient_id: a.id, type: 'leave_approved', title: `${tipo} Approvata`,
          message: `${r.first_name} ${r.last_name}: ${tipo} approvata per il ${periodo(r.start_date, r.end_date)}`, link: '/accountant/leave-requests.php',
        });
      }
    } catch (err: any) {
      this.logger.error(`commercialisti non avvisati: ${err?.message ?? err}`);
    }
  }

  /** Malattie senza documenti da piu' di N ore (LeaveRequest::sickPendingDocs). */
  async malattieSenzaDocumenti(companyId: number, ore = 24, employeeId?: number) {
    const p: unknown[] = [companyId, ore];
    let sql = `SELECT lr.*, e.first_name, e.last_name FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
      WHERE lr.company_id = $1 AND lr.leave_type = 'malattia' AND lr.status IN ('pending','approved')
        AND ((lr.protocol_number IS NULL OR lr.protocol_number = '')
             OR ((lr.certificate_path IS NULL OR lr.certificate_path = '') AND COALESCE(lr.certificate_waived, false) = false))
        AND lr.created_at <= CURRENT_TIMESTAMP - make_interval(hours => $2)`;
    if (employeeId !== undefined) { p.push(employeeId); sql += ` AND lr.employee_id = $${p.length}`; }
    return this.ds.query(sql + ' ORDER BY lr.created_at ASC, lr.id ASC', p);
  }

  /**
   * File di una richiesta (allegato o certificato) per chi lo chiede:
   * il dipendente solo i suoi, il responsabile solo il suo reparto, lo staff
   * solo l'azienda in cui sta lavorando.
   */
  async file(requestId: number, quale: 'attachment' | 'certificate', u: UtenteCorrente, ctx: ContestoAudit): Promise<{ ok: true; percorso: string; nome: string; mime: string } | { ok: false; error: string }> {
    const r = await this.perId(requestId);
    if (!r) return { ok: false, error: 'Richiesta non trovata' };
    const percorso = fileSicuro(r[`${quale}_path`]);
    if (!percorso) return { ok: false, error: quale === 'certificate' ? 'Certificato non disponibile' : 'Allegato non disponibile' };
    let consentito: boolean;
    if (u.tipo === 'employee') consentito = r.employee_id === u.id;
    else if (r.company_id !== u.companyId) consentito = false;
    else if (u.ruolo === 'admin_reparto') consentito = r.department_id === u.departmentId;
    else consentito = ['admin', 'accountant', 'consulente_lavoro'].includes(u.ruolo);
    if (!consentito) {
      await this.audit.log('unauthorized_access', u.ruolo, u.id, ctx, null, null, null, { resource: `leave_${quale}`, request_id: requestId }, 'critical');
      return { ok: false, error: 'Accesso non autorizzato' };
    }
    const nome = r[`${quale}_name`] || percorso.split(/[\\/]/).pop();
    return { ok: true, percorso, nome, mime: mimeDaContenuto(percorso, nome) };
  }

  /**
   * Avvisa chi deve approvare: responsabili del reparto e admin dell'azienda.
   * Correzioni: gli admin senza azienda si avvisano solo se sono davvero
   * globali (o collegati a questa azienda) e arriva una sola push, non due.
   */
  private async avvisaResponsabili(e: any, d: DatiRichiesta): Promise<void> {
    try {
      const destinatari = new Map<string, { id: number; role: string }>();
      if (e.department_id) {
        for (const a of await this.ds.query(`SELECT id, role FROM users WHERE department_id = $1 AND role = 'admin_reparto' AND is_active = true ORDER BY name`, [e.department_id])) {
          destinatari.set(`${a.role}:${a.id}`, a);
        }
      }
      for (const a of await this.ds.query(
        `SELECT id, role FROM users u WHERE role = 'admin' AND is_active = true
           AND (company_id = $1 OR (company_id IS NULL AND (NOT EXISTS (SELECT 1 FROM user_companies x WHERE x.user_id = u.id)
                                     OR EXISTS (SELECT 1 FROM user_companies y WHERE y.user_id = u.id AND y.company_id = $1))))`, [e.company_id])) {
        if (!destinatari.has(`admin:${a.id}`)) destinatari.set(`admin:${a.id}`, a);
      }
      const nome = `${e.first_name} ${e.last_name}`;
      const tipo = TIPI[d.leave_type] ?? d.leave_type;
      for (const a of destinatari.values()) {
        await this.notifiche.crea(e.company_id, {
          recipient_type: a.role, recipient_id: a.id, type: 'new_leave_request', title: `Nuova Richiesta ${tipo}`,
          message: `${nome} ha richiesto ${tipo} per il ${periodo(d.start_date, d.end_date)}`,
          link: a.role === 'admin' ? '/admin/' : '/admin-reparto/leave-requests.php',
        });
      }
    } catch (err: any) {
      this.logger.error(`avviso ai responsabili non riuscito: ${err?.message ?? err}`);
    }
  }

  private async registra(azione: string, id: number, vecchi: Record<string, unknown> | null, nuovi: Record<string, unknown> | null, ctx: ContestoAudit & { tipo: string; id: number }) {
    await this.audit.log(azione, ctx.tipo, ctx.id, ctx, 'leave_request', id, vecchi, nuovi);
  }
}
