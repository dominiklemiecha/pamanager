import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { NotificheService } from '../notifiche/notifiche.service';
import { SaldiService } from '../saldi/saldi.service';

/**
 * Priorita' delle comunicazioni come la ordinava MariaDB (ENUM per posizione:
 * low < normal < high). In Postgres sono testo e andrebbero in ordine alfabetico.
 */
export const ORDINE_PRIORITA = `CASE c.priority WHEN 'high' THEN 3 WHEN 'normal' THEN 2 WHEN 'low' THEN 1 ELSE 0 END`;

const foto = (p: string | null | undefined) => (p ? '/' + String(p).replace(/^\/+/, '') : '');

/**
 * Dati dell'area dipendente (cornice Cora e Home), come header-employee.php,
 * employee/index.php e _cora/home.inc.php. Ogni query parte dall'id del
 * dipendente collegato: nessun identificativo arriva dal client.
 */
@Injectable()
export class DipendenteService {
  constructor(private readonly ds: DataSource, private readonly notifiche: NotificheService, private readonly saldi: SaldiService) {}

  private async uno<T = any>(sql: string, p: unknown[]): Promise<T | undefined> {
    const [r] = await this.ds.query(sql, p);
    return r;
  }

  /** Comunicazioni attive non lette, filtrate per reparto (Communication::countUnread). */
  async comunicazioniNonLette(employeeId: number, reparto: number | null): Promise<number> {
    const e = await this.uno('SELECT company_id, COALESCE(hire_date, created_at::date) AS since FROM employees WHERE id = $1', [employeeId]);
    if (!e?.company_id) return 0;
    const p: unknown[] = [e.company_id, e.since ?? oggiRoma(), employeeId];
    let sql = `SELECT COUNT(*)::int AS n FROM communications c
      WHERE c.company_id = $1 AND c.is_published = true AND c.publish_date <= CURRENT_DATE AND c.publish_date >= $2
        AND (c.expire_date IS NULL OR c.expire_date >= CURRENT_DATE)
        AND NOT EXISTS (SELECT 1 FROM communication_reads cr WHERE cr.communication_id = c.id AND cr.employee_id = $3)`;
    if (reparto !== null) { p.push(reparto); sql += ' AND (c.is_global = true OR c.department_id = $4)'; }
    return (await this.uno(sql, p))?.n ?? 0;
  }

  /**
   * Comunicazioni attive del dipendente (Communication::getActive).
   * Correzione: la Home del PHP non passava il reparto e mostrava anche gli
   * avvisi riservati ad altri reparti; qui il filtro vale sempre.
   */
  async comunicazioniAttive(employeeId: number, companyId: number, reparto: number | null) {
    const p: unknown[] = [employeeId, companyId];
    let sql = `SELECT c.*, u.name AS author_name, d.name AS department_name, d.code AS department_code,
        (SELECT 1 FROM communication_reads cr WHERE cr.communication_id = c.id AND cr.employee_id = $1) AS is_read
      FROM communications c JOIN users u ON c.created_by = u.id LEFT JOIN departments d ON c.department_id = d.id
      WHERE c.company_id = $2 AND c.is_published = true AND c.publish_date <= CURRENT_DATE
        AND (c.expire_date IS NULL OR c.expire_date >= CURRENT_DATE)
        AND c.publish_date >= (SELECT COALESCE(hire_date, created_at::date) FROM employees WHERE id = $1)`;
    p.push(reparto);
    sql += ` AND (c.is_global = true OR c.department_id = $3)`;
    sql += ` ORDER BY ${ORDINE_PRIORITA} DESC, c.publish_date DESC, c.id DESC`;
    return this.ds.query(sql, p);
  }

  async documentiNonLetti(employeeId: number): Promise<number> {
    const a = await this.uno(
      `SELECT COUNT(*)::int AS n FROM documents d WHERE d.employee_id = $1
         AND NOT EXISTS (SELECT 1 FROM document_downloads dd WHERE dd.document_id = d.id AND dd.user_type = 'employee' AND dd.user_id = $1)`, [employeeId]);
    const b = await this.uno(
      `SELECT COUNT(*)::int AS n FROM employee_documents ed WHERE ed.employee_id = $1 AND ed.visible_to_employee = true
         AND NOT EXISTS (SELECT 1 FROM employee_document_downloads dd WHERE dd.employee_document_id = ed.id AND dd.user_type = 'employee' AND dd.user_id = $1)`, [employeeId]);
    return (a?.n ?? 0) + (b?.n ?? 0);
  }

  /** Messaggi chat non letti (Chat::countUnread, note interne escluse per il dipendente). */
  async chatNonLette(companyId: number, employeeId: number): Promise<number> {
    const r = await this.uno(
      `SELECT COUNT(*)::int AS n FROM chat_messages cm
         JOIN chat_conversations cc ON cm.conversation_id = cc.id
         LEFT JOIN chat_reads cr ON cr.conversation_id = cc.id AND cr.user_type = 'employee' AND cr.user_id = $2
        WHERE cc.company_id = $1
          AND ((cc.conv_type <> 'group' AND ((cc.participant1_type = 'employee' AND cc.participant1_id = $2)
               OR (cc.participant2_type = 'employee' AND cc.participant2_id = $2)))
               OR EXISTS (SELECT 1 FROM chat_extra_participants ep WHERE ep.conversation_id = cc.id AND ep.participant_type = 'employee' AND ep.participant_id = $2))
          AND NOT (cm.sender_type = 'employee' AND cm.sender_id = $2)
          AND ((cr.last_read_at IS NULL AND cm.is_read = false) OR (cr.last_read_at IS NOT NULL AND cm.created_at > cr.last_read_at))
          AND cm.is_internal = false
          AND NOT EXISTS (SELECT 1 FROM chat_conversation_prefs hp WHERE hp.conversation_id = cc.id AND hp.user_type = 'employee' AND hp.user_id = $2
                            AND hp.hidden_at IS NOT NULL AND hp.hidden_at >= cm.created_at)
          AND NOT EXISTS (SELECT 1 FROM employees ei WHERE ei.is_active = false
                            AND ((cc.participant1_type = 'employee' AND cc.participant1_id = ei.id) OR (cc.participant2_type = 'employee' AND cc.participant2_id = ei.id)))`,
      [companyId, employeeId]);
    return r?.n ?? 0;
  }

  /** Dati della cornice Cora: contatori del dock, profilo, notifiche della campanella. */
  async cornice(employeeId: number, companyId: number, sessioneCreata = 0) {
    const e = await this.uno('SELECT first_name, last_name, photo_path, availability_status, department_id, tour_status FROM employees WHERE id = $1', [employeeId]);
    const reparto: number | null = e?.department_id ?? null;
    const academyDaFare = (await this.uno(
      `SELECT COUNT(*)::int AS n FROM academy_assignments a JOIN academy_courses c ON c.id = a.course_id
        WHERE a.employee_id = $1 AND a.company_id = $2 AND a.completed_at IS NULL AND c.status = 'published'`, [employeeId, companyId]))?.n ?? 0;
    const attestatiNuovi = (await this.uno(
      `SELECT COUNT(*)::int AS n FROM academy_certificates ce JOIN academy_assignments a ON a.id = ce.assignment_id
        WHERE a.employee_id = $1 AND ce.company_id = $2 AND ce.employee_downloaded_at IS NULL`, [employeeId, companyId]))?.n ?? 0;
    const inviti = (await this.uno(
      `SELECT COUNT(*)::int AS n FROM calendar_event_participants ep JOIN calendar_events ev ON ev.id = ep.event_id
        WHERE ep.user_type = 'employee' AND ep.user_id = $1 AND ep.status = 'pending' AND ev.end_at >= CURRENT_TIMESTAMP`, [employeeId]))?.n ?? 0;
    const notifiche = await this.notifiche.perUtente(companyId, 'employee', employeeId, false, 8);
    return {
      nome: e?.first_name ?? '',
      cognome: e?.last_name ?? '',
      foto: foto(e?.photo_path),
      disponibilita: e?.availability_status || 'operative',
      contatori: {
        comunicazioni: await this.comunicazioniNonLette(employeeId, reparto),
        documenti: await this.documentiNonLetti(employeeId),
        chat: await this.chatNonLette(companyId, employeeId),
        academyDaFare,
        attestatiNuovi,
        inviti,
      },
      notifiche: notifiche.map((n: any) => ({ id: n.id, titolo: n.title, messaggio: n.message, link: NotificheService.url(n.link), letta: n.is_read, creata: n.created_at })),
      notificheNonLette: await this.notifiche.nonLette(companyId, 'employee', employeeId),
      guida: { id: employeeId, sessione: String(sessioneCreata), stato: e?.tour_status || null },
      // Contratto da firmare: il portale porta subito alla firma (header-employee.php).
      contrattoDaFirmare: (await this.uno(`SELECT id FROM hire_requests WHERE employee_id = $1 AND status = 'contract_pending' ORDER BY id DESC LIMIT 1`, [employeeId]))?.id ?? null,
    };
  }

  /** Guida conclusa o saltata: si registra solo la prima volta (EmployeeTour::setStatus). */
  async statoGuida(employeeId: number, stato: string): Promise<boolean> {
    if (!['completed', 'skipped'].includes(stato)) return false;
    await this.ds.query(`UPDATE employees SET tour_status = $2, tour_status_at = CURRENT_TIMESTAMP WHERE id = $1 AND tour_status IS NULL`, [employeeId, stato]);
    return true;
  }

  /** Stato di disponibilita' scelto dal dipendente (api/availability.php). */
  async disponibilita(employeeId: number, stato: string): Promise<boolean> {
    if (!['operative', 'in_call', 'in_meeting'].includes(stato)) return false;
    await this.ds.query('UPDATE employees SET availability_status = $2, availability_set_at = CURRENT_TIMESTAMP WHERE id = $1', [employeeId, stato]);
    return true;
  }

  /** Home Cora: saldi, presenze di oggi, compleanni, avvisi, documenti e richieste recenti. */
  async home(employeeId: number, companyId: number) {
    const e = await this.uno('SELECT first_name, department_id FROM employees WHERE id = $1', [employeeId]);
    const oggi = oggiRoma();
    const saldi = await this.saldi.perDipendente(employeeId);
    const inAttesa: Record<string, number> = { ferie: 0, permesso: 0 };
    for (const r of await this.ds.query(
      `SELECT leave_type, COUNT(*)::int AS n FROM leave_requests WHERE employee_id = $1 AND status = 'pending'
         AND leave_type IN ('ferie','permesso') GROUP BY leave_type`, [employeeId])) inAttesa[r.leave_type] = r.n;

    const compleanni = await this.ds.query(
      `SELECT id, first_name, last_name, photo_path FROM employees
        WHERE is_active = true AND birth_date IS NOT NULL AND company_id = $1 AND to_char(birth_date, 'MM-DD') = $2
        ORDER BY (id = $3) DESC, first_name`, [companyId, oggi.slice(5), employeeId]);

    const persone = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.photo_path,
              EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.employee_id = e.id AND lr.status = 'approved'
                       AND lr.leave_type <> 'smart_working' AND lr.start_date <= $1 AND lr.end_date >= $1) AS is_absent
         FROM employees e WHERE e.is_active = true AND e.company_id = $2
        ORDER BY is_absent DESC, e.last_name, e.first_name`, [oggi, companyId]);

    const docBuste = await this.ds.query(
      `SELECT d.id, d.title, d.created_at FROM documents d JOIN users u ON d.uploaded_by = u.id
        WHERE d.employee_id = $1 ORDER BY d.year DESC, d.month DESC, d.created_at DESC, d.id DESC`, [employeeId]);
    const docPersonali = await this.ds.query(
      `SELECT ed.id, ed.name, ed.created_at FROM employee_documents ed JOIN users u ON ed.uploaded_by = u.id
        WHERE ed.employee_id = $1 AND ed.visible_to_employee = true ORDER BY ed.created_at DESC, ed.id DESC`, [employeeId]);
    const documenti = [
      ...docBuste.map((d: any) => ({ titolo: d.title, data: d.created_at, url: `/employee/documents.php?download=${d.id}` })),
      ...docPersonali.map((d: any) => ({ titolo: d.name, data: d.created_at, url: `/employee/documents.php?download_personal=${d.id}` })),
    ].sort((a, b) => new Date(b.data).getTime() - new Date(a.data).getTime()).slice(0, 4);

    const comunicazioni = (await this.comunicazioniAttive(employeeId, companyId, e?.department_id ?? null)).slice(0, 3)
      .map((c: any) => ({ id: c.id, titolo: c.title, data: c.publish_date, letta: !!c.is_read }));

    const richieste = await this.ds.query(
      `SELECT id, leave_type, start_date, end_date, is_full_day, start_time, end_time, status, created_at
         FROM leave_requests WHERE employee_id = $1 ORDER BY created_at DESC, id DESC LIMIT 4`, [employeeId]);

    return {
      mioId: employeeId,
      nome: e?.first_name ?? '',
      saldi: {
        ferie: { ...saldi.ferie, inAttesa: inAttesa.ferie },
        permesso: { ...saldi.permesso, inAttesa: inAttesa.permesso },
      },
      compleanni: compleanni.map((p: any) => ({ id: p.id, nome: p.first_name, cognome: p.last_name, foto: foto(p.photo_path) })),
      persone: persone.map((p: any) => ({ id: p.id, nome: p.first_name, cognome: p.last_name, foto: foto(p.photo_path), assente: p.is_absent })),
      documenti,
      comunicazioni,
      richieste,
    };
  }
}
