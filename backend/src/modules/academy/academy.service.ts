import { Injectable, Logger } from '@nestjs/common';
import { createHash } from 'crypto';
import { DataSource, EntityManager } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { fileSicuro } from '../../common/file/caricamenti';
import { urlPubblico } from '../../config/app';
import { MailerService, escapeHtml } from '../notifiche/mailer.service';
import { PushService } from '../notifiche/push.service';

export const DICHIARAZIONE = 'Dichiaro di aver seguito integralmente il corso e di aver consultato tutti i materiali didattici.';
export const GIORNI_PROMEMORIA = 3;
export const ETICHETTE_STATO: Record<string, string> = { not_started: 'Da iniziare', in_progress: 'In corso', completed: 'Completato', overdue: 'Scaduto' };
const INLINE = ['application/pdf', 'image/', 'video/', 'audio/'];

/** Chi compie l'azione registrata e da dove. */
export interface Attore { tipo: string; id: number; ip?: string | null; userAgent?: string | null; sistema?: boolean }

const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;

/** "2026-10-23 14:05:09" ora di Roma (date('Y-m-d H:i:s') del PHP). */
function adessoRoma(): string {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date()).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;
}

/**
 * Academy dal lato del dipendente (Academy.php, AcademyCertificate.php,
 * academy-file.php): corsi assegnati, avvio, apertura dei materiali,
 * completamento con dichiarazione, attestati, promemoria di scadenza e
 * registro eventi a catena di hash (stesso calcolo del PHP: la catena
 * esistente resta verificabile).
 */
@Injectable()
export class AcademyService {
  private readonly logger = new Logger('Academy');
  constructor(private readonly ds: DataSource, private readonly push: PushService, private readonly mailer: MailerService) {}

  statoDi(a: any, scadenza: string | null): string {
    if (a.completed_at) return 'completed';
    if (scadenza && scadenza < oggiRoma()) return 'overdue';
    return a.started_at ? 'in_progress' : 'not_started';
  }

  inLinea(mime: string | null): boolean { return !!mime && INLINE.some((p) => mime.startsWith(p)); }

  async corsiDelDipendente(employeeId: number, companyId: number) {
    return this.ds.query(
      `SELECT a.id, a.course_id, ${ORA('a.started_at')} AS started_at, ${ORA('a.completed_at')} AS completed_at,
              c.title, c.description, c.duration_hours, c.due_date, c.status AS course_status,
              (SELECT COUNT(*)::int FROM academy_materials m WHERE m.course_id = c.id) AS material_count,
              (SELECT COUNT(*)::int FROM academy_material_views v JOIN academy_materials m ON m.id = v.material_id WHERE v.assignment_id = a.id AND m.course_id = c.id) AS opened_count,
              ce.id AS certificate_id, ce.employee_downloaded_at
         FROM academy_assignments a JOIN academy_courses c ON c.id = a.course_id
         LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
        WHERE a.employee_id = $1 AND a.company_id = $2 AND (c.status = 'published' OR a.started_at IS NOT NULL)
        ORDER BY (a.completed_at IS NOT NULL), c.due_date IS NULL, c.due_date, a.assigned_at DESC, a.id DESC`, [employeeId, companyId]);
  }

  async assegnazione(employeeId: number, courseId: number, companyId: number) {
    const [a] = await this.ds.query(
      `SELECT a.id, a.company_id, a.course_id, a.employee_id, ${ORA('a.started_at')} AS started_at, ${ORA('a.completed_at')} AS completed_at,
              ce.id AS certificate_id, ce.number AS certificate_number, ce.employee_downloaded_at
         FROM academy_assignments a JOIN academy_courses c ON c.id = a.course_id
         LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
        WHERE a.employee_id = $1 AND a.course_id = $2 AND a.company_id = $3 AND (c.status = 'published' OR a.started_at IS NOT NULL)`,
      [employeeId, courseId, companyId]);
    return a ?? null;
  }

  async corso(id: number, companyId: number) {
    const [c] = await this.ds.query('SELECT id, title, description, duration_hours, due_date, status FROM academy_courses WHERE id = $1 AND company_id = $2', [id, companyId]);
    return c ?? null;
  }

  async materiali(courseId: number, companyId: number) {
    return this.ds.query('SELECT id, course_id, kind, title, original_name, mime_type, url FROM academy_materials WHERE course_id = $1 AND company_id = $2 ORDER BY sort_order, id', [courseId, companyId]);
  }

  async aperti(assegnazione: number): Promise<number[]> {
    return (await this.ds.query('SELECT material_id FROM academy_material_views WHERE assignment_id = $1 ORDER BY id', [assegnazione])).map((r: any) => r.material_id);
  }

  async avvia(a: any, attore: Attore) {
    if (a.started_at) return;
    const [, n] = await this.ds.query('UPDATE academy_assignments SET started_at = CURRENT_TIMESTAMP, started_ip = $2 WHERE id = $1 AND started_at IS NULL', [a.id, attore.ip ?? null]);
    if (n > 0) await this.evento('started', a.company_id, { course_id: a.course_id, assignment_id: a.id, employee_id: a.employee_id }, attore);
  }

  async aperturaMateriale(a: any, m: any, attore: Attore) {
    await this.avvia(a, attore);
    await this.ds.query(
      `INSERT INTO academy_material_views (assignment_id, material_id, first_opened_at, last_opened_at, open_count) VALUES ($1, $2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
       ON CONFLICT (assignment_id, material_id) DO UPDATE SET last_opened_at = CURRENT_TIMESTAMP, open_count = academy_material_views.open_count + 1`, [a.id, m.id]);
    await this.evento('material_opened', a.company_id, { course_id: a.course_id, assignment_id: a.id, employee_id: a.employee_id, material_id: m.id, details: m.title }, attore);
  }

  async completa(a: any, dichiarazione: boolean, attore: Attore): Promise<{ success: boolean; error?: string }> {
    if (a.completed_at) return { success: true };
    if (!dichiarazione) return { success: false, error: 'Per completare devi spuntare la dichiarazione' };
    const materiali = await this.materiali(a.course_id, a.company_id);
    const aperti = await this.aperti(a.id);
    if (materiali.some((m: any) => !aperti.includes(m.id))) return { success: false, error: 'Apri tutti i materiali del corso prima di completarlo' };
    const [, n] = await this.ds.query(
      `UPDATE academy_assignments SET completed_at = CURRENT_TIMESTAMP, completed_ip = $2, completed_user_agent = $3, declaration_text = $4,
         started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = $1 AND completed_at IS NULL`,
      [a.id, attore.ip ?? null, [...String(attore.userAgent ?? '')].slice(0, 500).join(''), DICHIARAZIONE]);
    if (n > 0) {
      await this.evento('completed', a.company_id, {
        course_id: a.course_id, assignment_id: a.id, employee_id: a.employee_id,
        details: `Dichiarazione accettata: ${DICHIARAZIONE} | Materiali aperti: ${aperti.length}/${materiali.length}`,
      }, attore);
      await this.avvisaStaff(a).catch((e) => this.logger.warn(`avviso completamento: ${e?.message ?? e}`));
    }
    return { success: true };
  }

  /** HR, consulenti collegati e responsabili formazione dell'azienda. */
  async destinatariStaff(companyId: number) {
    return this.ds.query(
      `SELECT u.id, u.role, u.name, u.username, u.email FROM users u
        WHERE u.is_active = true AND u.role IN ('admin', 'consulente_lavoro', 'formatore')
          AND (u.company_id = $1 OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = $1)
               OR (u.role = 'admin' AND u.company_id IS NULL AND NOT EXISTS (SELECT 1 FROM user_companies uc2 WHERE uc2.user_id = u.id)))
        ORDER BY u.id`, [companyId]);
  }

  private async avvisaStaff(a: any) {
    const corso = await this.corso(a.course_id, a.company_id);
    const [e] = await this.ds.query('SELECT first_name, last_name FROM employees WHERE id = $1', [a.employee_id]);
    if (!corso || !e) return;
    const chi = `${e.first_name} ${e.last_name}`.trim();
    const titolo = `Corso completato: ${corso.title}`;
    const messaggio = `${chi} ha completato il corso "${corso.title}". Puoi generare l'attestato.`;
    for (const u of await this.destinatariStaff(a.company_id)) {
      const area = ({ consulente_lavoro: 'consulente-lavoro', formatore: 'formatore' } as Record<string, string>)[u.role] ?? 'admin';
      const percorso = `/${area}/academy.php?course=${corso.id}&tab=people`;
      await this.ds.query(
        `INSERT INTO notifications (company_id, recipient_type, recipient_id, type, title, message, link, is_read) VALUES ($1, $2, $3, 'academy_completed', $4, $5, $6, false)`,
        [a.company_id, u.role, u.id, [...titolo].slice(0, 255).join(''), messaggio, percorso]).catch(() => undefined);
      await this.push.aUtente(u.role, u.id, { title: titolo, body: messaggio, url: percorso, tag: `academy-completed-${a.id}` }).catch(() => undefined);
      if (u.email) {
        const url = urlPubblico(percorso);
        const nome = u.name || u.username;
        await this.mailer.invia(u.email, nome, titolo, `<p>Ciao ${escapeHtml(nome)},</p><p>${escapeHtml(messaggio)}</p><p><a href="${escapeHtml(url)}">Apri il corso nell'Academy</a></p>`, `${messaggio}\n\n${url}`).catch(() => undefined);
      }
    }
  }

  /**
   * Promemoria di scadenza (Academy::runDueReminders), all'apertura della
   * pagina come nel PHP: prima si marca, poi si invia (niente doppioni).
   */
  async promemoriaScadenza(companyId: number, richiesta: { ip?: string | null; userAgent?: string | null } = {}) {
    try {
      const righe = await this.ds.query(
        `SELECT a.id, a.employee_id, a.course_id, c.title, c.due_date FROM academy_assignments a
           JOIN academy_courses c ON c.id = a.course_id JOIN employees e ON e.id = a.employee_id
          WHERE a.company_id = $1 AND c.status = 'published' AND c.due_date IS NOT NULL AND a.completed_at IS NULL AND a.due_reminder_sent_at IS NULL
            AND e.is_active = true AND c.due_date >= CURRENT_DATE AND c.due_date <= CURRENT_DATE + $2::int ORDER BY a.id LIMIT 30`, [companyId, GIORNI_PROMEMORIA]);
      for (const r of righe) {
        const [, n] = await this.ds.query('UPDATE academy_assignments SET due_reminder_sent_at = CURRENT_TIMESTAMP WHERE id = $1 AND due_reminder_sent_at IS NULL', [r.id]);
        if (!n) continue;
        const d = String(r.due_date);
        await this.avvisaDipendente(r.employee_id, `Corso in scadenza: ${r.title}`, `Il corso "${r.title}" va completato entro il ${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}.`, r.course_id, companyId);
        await this.evento('due_reminder', companyId, { course_id: r.course_id, assignment_id: r.id, employee_id: r.employee_id }, { tipo: 'system', id: 0, sistema: true, ip: richiesta.ip, userAgent: richiesta.userAgent });
      }
    } catch (e: any) {
      this.logger.warn(`promemoria: ${e?.message ?? e}`);
    }
  }

  /** Notifica in-app, push ed email al dipendente (Academy::notifyEmployee). */
  async avvisaDipendente(employeeId: number, titolo: string, messaggio: string, courseId: number, companyId: number) {
    const link = `/employee/academy.php?course=${courseId}`;
    await this.ds.query(`INSERT INTO notifications (company_id, recipient_type, recipient_id, type, title, message, link, is_read) VALUES ($1, 'employee', $2, 'academy', $3, $4, $5, false)`,
      [companyId, employeeId, [...titolo].slice(0, 255).join(''), messaggio, link]).catch(() => undefined);
    await this.push.aUtente('employee', employeeId, { title: titolo, body: messaggio, url: link, tag: `academy-${courseId}` }).catch(() => undefined);
    const [e] = await this.ds.query('SELECT first_name, last_name FROM employees WHERE id = $1', [employeeId]);
    if (e) {
      const url = urlPubblico(link);
      const nome = `${e.first_name} ${e.last_name}`.trim();
      await this.mailer.inviaADipendente(employeeId, titolo, `<p>Ciao ${escapeHtml(nome)},</p><p>${escapeHtml(messaggio)}</p><p><a href="${escapeHtml(url)}">Apri il corso nell'Academy</a></p>`,
        `Ciao ${nome},\n\n${messaggio}\n\nApri il corso: ${url}`).catch(() => undefined);
    }
  }

  /** Attestato del dipendente: solo il suo; il primo scaricamento si segna. */
  async attestatoDipendente(certId: number, employeeId: number, companyId: number, attore: Attore) {
    const [c] = await this.ds.query(
      `SELECT ce.id, ce.assignment_id, ce.number, ce.file_path, ce.employee_downloaded_at, a.employee_id, a.course_id, e.first_name, e.last_name
         FROM academy_certificates ce JOIN academy_assignments a ON a.id = ce.assignment_id JOIN employees e ON e.id = a.employee_id
        WHERE ce.id = $1 AND ce.company_id = $2`, [certId, companyId]);
    const percorso = c ? fileSicuro(c.file_path) : null;
    if (!c || !percorso) return { ok: false as const, errore: 'Attestato non trovato' };
    if (c.employee_id !== employeeId) return { ok: false as const, errore: 'Accesso non autorizzato' };
    if (!c.employee_downloaded_at) await this.ds.query('UPDATE academy_certificates SET employee_downloaded_at = CURRENT_TIMESTAMP WHERE id = $1', [certId]);
    await this.evento('certificate_downloaded', companyId, { course_id: c.course_id, assignment_id: c.assignment_id, employee_id: c.employee_id, details: c.number }, attore);
    const nome = `attestato_${c.number}_${c.last_name}_${c.first_name}`.replace(/[^A-Za-z0-9_-]/g, '_') + '.pdf';
    return { ok: true as const, percorso, nome };
  }

  /** Attestato per lo staff: stessa azienda, il responsabile di reparto solo il suo reparto. */
  async attestatoStaff(certId: number, ruolo: string, reparto: number | null, companyId: number) {
    const [c] = await this.ds.query(
      `SELECT ce.number, ce.file_path, e.department_id, e.first_name, e.last_name FROM academy_certificates ce
         JOIN academy_assignments a ON a.id = ce.assignment_id JOIN employees e ON e.id = a.employee_id WHERE ce.id = $1 AND ce.company_id = $2`, [certId, companyId]);
    const percorso = c ? fileSicuro(c.file_path) : null;
    if (!c || !percorso) return { ok: false as const, errore: 'Attestato non trovato' };
    if (!['admin', 'consulente_lavoro', 'admin_reparto', 'formatore'].includes(ruolo)) return { ok: false as const, errore: 'Accesso non autorizzato' };
    if (ruolo === 'admin_reparto' && c.department_id !== (reparto ?? -1)) return { ok: false as const, errore: 'Accesso non autorizzato' };
    const nome = `attestato_${c.number}_${c.last_name}_${c.first_name}`.replace(/[^A-Za-z0-9_-]/g, '_') + '.pdf';
    return { ok: true as const, percorso, nome };
  }

  async materiale(id: number, companyId: number) {
    const [m] = await this.ds.query('SELECT * FROM academy_materials WHERE id = $1 AND company_id = $2', [id, companyId]);
    return m ?? null;
  }

  // ─── Registro a catena di hash ───────────────────────────────────────────

  /** Stesso calcolo di Academy::hashRow. */
  static hash(r: Record<string, unknown>): string {
    const v = (x: unknown) => (x === null || x === undefined ? '' : String(x));
    return createHash('sha256').update([
      v(r.prev_hash), v(r.company_id), v(r.course_id), v(r.assignment_id), v(r.employee_id), v(r.material_id),
      v(r.event), v(r.actor_type), v(r.actor_id), v(r.details), v(r.ip_address), v(r.user_agent), v(r.created_at),
    ].join('|')).digest('hex');
  }

  /**
   * Evento nel registro: l'hash concatena quello della riga precedente della
   * stessa azienda. Un lock per azienda serializza le scritture (GET_LOCK nel PHP).
   */
  async evento(nome: string, companyId: number, ctx: { course_id?: number; assignment_id?: number; employee_id?: number; material_id?: number; details?: string }, attore: Attore) {
    try {
      const riga: Record<string, unknown> = {
        company_id: companyId, course_id: ctx.course_id ?? null, assignment_id: ctx.assignment_id ?? null, employee_id: ctx.employee_id ?? null,
        material_id: ctx.material_id ?? null, event: nome, actor_type: attore.sistema ? 'system' : attore.tipo, actor_id: attore.sistema ? 0 : attore.id,
        details: ctx.details !== undefined ? [...String(ctx.details)].slice(0, 1000).join('') : null,
        ip_address: attore.ip ?? null, user_agent: [...String(attore.userAgent ?? '')].slice(0, 500).join(''),
        created_at: adessoRoma(),
      };
      await this.ds.transaction(async (m: EntityManager) => {
        await m.query('SELECT pg_advisory_xact_lock(hashtext($1))', [`academy_chain_${companyId}`]);
        const [prev] = await m.query('SELECT hash FROM academy_events WHERE company_id = $1 ORDER BY id DESC LIMIT 1', [companyId]);
        riga.prev_hash = prev?.hash ?? null;
        riga.hash = AcademyService.hash(riga);
        await m.query(
          `INSERT INTO academy_events (company_id, course_id, assignment_id, employee_id, material_id, event, actor_type, actor_id, details, ip_address, user_agent, created_at, prev_hash, hash)
           VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12::timestamp AT TIME ZONE 'Europe/Rome', $13, $14)`,
          [riga.company_id, riga.course_id, riga.assignment_id, riga.employee_id, riga.material_id, riga.event, riga.actor_type, riga.actor_id,
            riga.details, riga.ip_address, riga.user_agent, riga.created_at, riga.prev_hash, riga.hash]);
      });
    } catch (e: any) {
      this.logger.error(`registro eventi: ${e?.message ?? e}`);
    }
  }

  /** Ricalcola la catena (Academy::verifyChain). */
  async verificaCatena(companyId: number) {
    const righe = await this.ds.query(
      `SELECT id, company_id, course_id, assignment_id, employee_id, material_id, event, actor_type, actor_id, details, ip_address, user_agent,
              ${ORA('created_at')} AS created_at, prev_hash, hash FROM academy_events WHERE company_id = $1 ORDER BY id`, [companyId]);
    let prev: string | null = null;
    for (const r of righe) {
      if ((r.prev_hash ?? null) !== prev || AcademyService.hash(r) !== r.hash) return { ok: false, checked: righe.length, broken_id: Number(r.id) };
      prev = r.hash;
    }
    return { ok: true, checked: righe.length, broken_id: null };
  }
}
