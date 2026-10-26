import { Injectable } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import PDFDocument = require('pdfkit');
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import { contenutoCoerente, estensione, FileCaricato, fileSicuro } from '../../common/file/caricamenti';
import { cartellaStorage } from '../../config/app';
import { AcademyService, Attore } from './academy.service';

export const STATI_CORSO: Record<string, string> = { draft: 'Bozza', published: 'Pubblicato', archived: 'Archiviato' };
export const ETICHETTE_EVENTI: Record<string, string> = {
  course_created: 'Corso creato', course_published: 'Corso pubblicato', course_archived: 'Corso archiviato', material_added: 'Materiale aggiunto',
  material_removed: 'Materiale rimosso', assigned: 'Corso assegnato', unassigned: 'Assegnazione rimossa', notified: 'Notifica inviata', started: 'Corso iniziato',
  material_opened: 'Materiale aperto', completed: 'Corso completato', reminded: 'Sollecito inviato', due_reminder: 'Promemoria scadenza inviato',
  certificate_generated: 'Attestato generato', certificate_downloaded: 'Attestato scaricato',
};
/** Tipi dei materiali: estensione -> tipo MIME (riconosciuti dal contenuto). */
const TIPI_MATERIALI: Record<string, string> = {
  pdf: 'application/pdf', ppt: 'application/vnd.ms-powerpoint', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', odt: 'application/vnd.oasis.opendocument.text',
  odp: 'application/vnd.oasis.opendocument.presentation', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp',
  mp4: 'video/mp4', webm: 'video/webm', mp3: 'audio/mpeg',
};
export const MATERIALE_MAX = 200 * 1024 * 1024;
const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;
const ATTORI: Record<string, string> = { admin: 'HR', consulente_lavoro: 'Consulente', admin_reparto: 'Resp. reparto', accountant: 'Commercialista', formatore: 'Resp. formazione' };
const dmy = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;
const dmyhms = (d: string) => `${dmy(d)} ${d.slice(11, 19)}`;
const taglia = (s: string, n: number) => [...s].slice(0, n).join('');
type Esito = { success: true; id?: number } | { success: false; error: string };

/** Etichetta di chi ha eseguito un evento del registro (Academy::actorLabel). */
export function etichettaAttore(ev: Record<string, any>): string {
  if (ev.actor_type === 'employee') return `${ev.first_name ?? ''} ${ev.last_name ?? ''}`.trim() || `Dipendente #${ev.actor_id}`;
  if (ev.actor_type === 'system') return 'Sistema';
  return (ATTORI[ev.actor_type] ?? ev.actor_type) + (ev.actor_username ? ` (${ev.actor_username})` : '');
}

/**
 * Academy lato staff (Academy.php): corsi, materiali, assegnazioni,
 * notifiche e solleciti, registro eventi ed esportazioni.
 * Ogni operazione resta nell'azienda di lavoro.
 *
 * Correzioni: i materiali si riconoscono dal contenuto (il PHP accettava
 * qualsiasi file con l'estensione giusta, purche' non fosse HTML/PHP);
 * spostare un materiale tocca solo quelli dello stesso corso e azienda.
 */
@Injectable()
export class AcademyStaffService {
  constructor(private readonly ds: DataSource, private readonly academy: AcademyService) {}

  corsi(cid: number, reparto: number | null) {
    const p: unknown[] = [cid];
    let filtro = '';
    if (reparto !== null) { p.push(reparto); filtro = ' AND aa.employee_id IN (SELECT id FROM employees WHERE department_id = $2)'; }
    return this.ds.query(
      `SELECT c.*, (SELECT COUNT(*) FROM academy_materials m WHERE m.course_id = c.id)::int AS material_count,
              COUNT(aa.id)::int AS assigned_count, COUNT(aa.completed_at)::int AS completed_count,
              COUNT(*) FILTER (WHERE aa.started_at IS NOT NULL AND aa.completed_at IS NULL)::int AS in_progress_count,
              (SELECT COUNT(*) FROM academy_certificates ce JOIN academy_assignments a2 ON a2.id = ce.assignment_id WHERE a2.course_id = c.id)::int AS certificate_count,
              COUNT(*) FILTER (WHERE aa.completed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM academy_certificates c3 WHERE c3.assignment_id = aa.id))::int AS to_generate_count
         FROM academy_courses c LEFT JOIN academy_assignments aa ON aa.course_id = c.id${filtro}
        WHERE c.company_id = $1 GROUP BY c.id
        ORDER BY CASE c.status WHEN 'published' THEN 1 WHEN 'draft' THEN 2 WHEN 'archived' THEN 3 ELSE 0 END, c.created_at DESC, c.id DESC`, p);
  }

  async corso(id: number, cid: number) {
    const [c] = await this.ds.query(
      `SELECT c.*, t.name AS template_name FROM academy_courses c LEFT JOIN academy_certificate_templates t ON t.id = c.certificate_template_id
        WHERE c.id = $1 AND c.company_id = $2`, [id, cid]);
    return c ?? null;
  }

  async salvaCorso(d: Record<string, any>, id: number | null, cid: number, attore: Attore): Promise<Esito> {
    const titolo = String(d.title ?? '').trim();
    if (titolo === '') return { success: false, error: 'Il titolo del corso è obbligatorio' };
    const o = String(d.duration_hours ?? '').trim();
    const ore = o === '' ? null : Number.parseFloat(o.replace(',', '.')) || 0;
    if (ore !== null && (ore < 0 || ore > 9999)) return { success: false, error: 'Durata non valida' };
    const scadenza = String(d.due_date ?? '').trim();
    if (scadenza !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(scadenza)) return { success: false, error: 'Data di scadenza non valida' };
    const modello = Number.parseInt(String(d.certificate_template_id ?? '0'), 10) || 0;
    if (modello > 0 && !(await this.ds.query('SELECT 1 FROM academy_certificate_templates WHERE id = $1 AND company_id = $2', [modello, cid])).length) {
      return { success: false, error: 'Modello attestato non valido' };
    }
    const riga = [taglia(titolo, 200), String(d.description ?? '').trim() || null, ore, scadenza || null, modello > 0 ? modello : null];
    if (id !== null) {
      const c = await this.corso(id, cid);
      if (!c) return { success: false, error: 'Corso non trovato' };
      // Cambiando la scadenza il promemoria automatico deve poter ripartire
      if ((c.due_date ?? null) !== riga[3]) await this.ds.query('UPDATE academy_assignments SET due_reminder_sent_at = NULL WHERE course_id = $1 AND company_id = $2', [id, cid]);
      await this.ds.query(
        `UPDATE academy_courses SET title = $3, description = $4, duration_hours = $5, due_date = $6, certificate_template_id = $7, updated_at = CURRENT_TIMESTAMP
          WHERE id = $1 AND company_id = $2`, [id, cid, ...riga]);
      return { success: true, id };
    }
    const [{ id: nuovo }] = await this.ds.query(
      `INSERT INTO academy_courses (company_id, title, description, duration_hours, due_date, certificate_template_id, status, created_by_user_id)
       VALUES ($1, $2, $3, $4, $5, $6, 'draft', $7) RETURNING id`, [cid, ...riga, attore.id]);
    await this.academy.evento('course_created', cid, { course_id: nuovo, details: riga[0] as string }, attore);
    return { success: true, id: nuovo };
  }

  async statoCorso(id: number, stato: string, cid: number, attore: Attore): Promise<Esito> {
    if (!STATI_CORSO[stato]) return { success: false, error: 'Stato non valido' };
    if (!(await this.corso(id, cid))) return { success: false, error: 'Corso non trovato' };
    if (stato === 'published' && !(await this.ds.query('SELECT 1 FROM academy_materials WHERE course_id = $1 LIMIT 1', [id])).length) {
      return { success: false, error: 'Aggiungi almeno un materiale prima di pubblicare il corso' };
    }
    await this.ds.query('UPDATE academy_courses SET status = $3, updated_at = CURRENT_TIMESTAMP WHERE id = $1 AND company_id = $2', [id, cid, stato]);
    if (stato === 'published') await this.academy.evento('course_published', cid, { course_id: id }, attore);
    else if (stato === 'archived') await this.academy.evento('course_archived', cid, { course_id: id }, attore);
    return { success: true };
  }

  /** Solo un corso mai assegnato si elimina (altrimenti si archivia). */
  async eliminaCorso(id: number, cid: number): Promise<Esito> {
    if (!(await this.corso(id, cid))) return { success: false, error: 'Corso non trovato' };
    if ((await this.ds.query('SELECT 1 FROM academy_assignments WHERE course_id = $1 LIMIT 1', [id])).length) {
      return { success: false, error: 'Il corso è già stato assegnato: puoi solo archiviarlo' };
    }
    const file = (await this.materiali(id, cid)).filter((m: any) => m.kind === 'file').map((m: any) => fileSicuro(m.file_path)).filter(Boolean) as string[];
    await this.ds.query('DELETE FROM academy_courses WHERE id = $1 AND company_id = $2', [id, cid]);
    for (const f of file) fs.rmSync(f, { force: true });
    return { success: true };
  }

  materiali(courseId: number, cid: number) {
    return this.ds.query('SELECT * FROM academy_materials WHERE course_id = $1 AND company_id = $2 ORDER BY sort_order, id', [courseId, cid]);
  }

  private async materiale(id: number, cid: number) {
    const [m] = await this.ds.query('SELECT * FROM academy_materials WHERE id = $1 AND company_id = $2', [id, cid]);
    return m ?? null;
  }

  private async ultimoOrdine(courseId: number): Promise<number> {
    const [r] = await this.ds.query('SELECT COALESCE(MAX(sort_order), 0)::int AS n FROM academy_materials WHERE course_id = $1', [courseId]);
    return r.n;
  }

  /** Inizio del file (per riconoscere il tipo), anche quando e' su disco. */
  private testa(f: FileCaricato): Buffer {
    if (f.buffer) return f.buffer.subarray(0, 64);
    const b = Buffer.alloc(64);
    const fd = fs.openSync(f.path!, 'r');
    try { fs.readSync(fd, b, 0, 64, 0); } finally { fs.closeSync(fd); }
    return b;
  }

  /** Uno o piu' file: ogni file diventa un materiale col proprio nome come titolo. */
  async aggiungiFile(courseId: number, file: FileCaricato[], cid: number, attore: Attore) {
    if (!(await this.corso(courseId, cid))) return { success: false, added: 0, errors: [] as string[], error: 'Corso non trovato' };
    const lista = file.filter((f) => f && f.originalname);
    if (!lista.length) return { success: false, added: 0, errors: [] as string[], error: 'Seleziona almeno un file' };
    const dir = cartellaStorage('documents', 'academy', String(cid), 'materials', String(courseId));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    let ordine = await this.ultimoOrdine(courseId);
    let aggiunti = 0;
    const errori: string[] = [];
    for (const f of lista) {
      const ext = estensione(f.originalname);
      if (f.size > MATERIALE_MAX) { errori.push(`${f.originalname}: file troppo grande (massimo ${MATERIALE_MAX / 1024 / 1024}MB)`); continue; }
      if (!TIPI_MATERIALI[ext]) { errori.push(`${f.originalname}: formato .${ext} non consentito`); continue; }
      if (!contenutoCoerente(ext, this.testa(f))) { errori.push(`${f.originalname}: contenuto non consentito`); continue; }
      const dest = path.join(dir, `${randomBytes(16).toString('hex')}.${ext}`);
      try {
        if (f.path) fs.renameSync(f.path, dest);
        else fs.writeFileSync(dest, f.buffer, { mode: 0o640 });
      } catch {
        try { if (f.path) { fs.copyFileSync(f.path, dest); } else throw new Error(); } catch { errori.push(`${f.originalname}: errore di salvataggio`); continue; }
      }
      const titolo = path.basename(f.originalname, path.extname(f.originalname)).trim() || f.originalname;
      const [{ id }] = await this.ds.query(
        `INSERT INTO academy_materials (company_id, course_id, kind, title, file_path, original_name, mime_type, file_size, sort_order)
         VALUES ($1, $2, 'file', $3, $4, $5, $6, $7, $8) RETURNING id`,
        [cid, courseId, taglia(titolo, 255), dest, taglia(f.originalname, 255), TIPI_MATERIALI[ext], f.size, ++ordine]);
      await this.academy.evento('material_added', cid, { course_id: courseId, material_id: id, details: f.originalname }, attore);
      aggiunti++;
    }
    return { success: aggiunti > 0, added: aggiunti, errors: errori, error: aggiunti === 0 ? errori.join(' - ') : null };
  }

  async aggiungiLink(courseId: number, titolo: string, url: string, cid: number, attore: Attore): Promise<Esito> {
    if (!(await this.corso(courseId, cid))) return { success: false, error: 'Corso non trovato' };
    const u = url.trim();
    let host = '';
    try { host = /^https?:\/\//i.test(u) ? new URL(u).host : ''; } catch { host = ''; }
    if (!host) return { success: false, error: 'Inserisci un link valido (http:// o https://)' };
    const t = titolo.trim() || host || 'Link';
    const [{ id }] = await this.ds.query(
      `INSERT INTO academy_materials (company_id, course_id, kind, title, url, sort_order) VALUES ($1, $2, 'link', $3, $4, $5) RETURNING id`,
      [cid, courseId, taglia(t, 255), taglia(u, 1000), (await this.ultimoOrdine(courseId)) + 1]);
    await this.academy.evento('material_added', cid, { course_id: courseId, material_id: id, details: u }, attore);
    return { success: true };
  }

  async rinominaMateriale(id: number, titolo: string, cid: number): Promise<Esito> {
    const t = titolo.trim();
    if (t === '' || !(await this.materiale(id, cid))) return { success: false, error: 'Titolo non valido' };
    await this.ds.query('UPDATE academy_materials SET title = $3 WHERE id = $1 AND company_id = $2', [id, cid, taglia(t, 255)]);
    return { success: true };
  }

  async eliminaMateriale(id: number, cid: number, attore: Attore): Promise<Esito> {
    const m = await this.materiale(id, cid);
    if (!m) return { success: false, error: 'Materiale non trovato' };
    await this.ds.query('DELETE FROM academy_materials WHERE id = $1 AND company_id = $2', [id, cid]);
    const f = m.kind === 'file' ? fileSicuro(m.file_path) : null;
    if (f) fs.rmSync(f, { force: true });
    await this.academy.evento('material_removed', cid, { course_id: m.course_id, material_id: id, details: m.title }, attore);
    return { success: true };
  }

  async spostaMateriale(id: number, verso: string, cid: number) {
    const m = await this.materiale(id, cid);
    if (!m) return;
    const ids: number[] = (await this.materiali(m.course_id, cid)).map((r: any) => r.id);
    const pos = ids.indexOf(id);
    const altro = verso === 'up' ? pos - 1 : pos + 1;
    if (pos < 0 || altro < 0 || altro >= ids.length) return;
    [ids[pos], ids[altro]] = [ids[altro], ids[pos]];
    for (const [i, mid] of ids.entries()) await this.ds.query('UPDATE academy_materials SET sort_order = $2 WHERE id = $1 AND company_id = $3', [mid, i + 1, cid]);
  }

  /** Assegna il corso pubblicato ai dipendenti attivi dell'azienda; le notifiche restano in coda. */
  async assegna(courseId: number, idDipendenti: unknown[], email: boolean, cid: number, attore: Attore) {
    const c = await this.corso(courseId, cid);
    if (!c) return { success: false as const, error: 'Corso non trovato' };
    if (c.status !== 'published') return { success: false as const, error: 'Pubblica il corso prima di assegnarlo' };
    const ids = [...new Set(idDipendenti.map((x) => Number.parseInt(String(x), 10) || 0).filter((x) => x !== 0))];
    if (!ids.length) return { success: false as const, error: 'Seleziona almeno un dipendente' };
    const validi = await this.ds.query(
      `SELECT e.id FROM employees e WHERE e.company_id = $1 AND e.is_active = true AND e.id = ANY($2::int[])
          AND NOT EXISTS (SELECT 1 FROM academy_assignments a WHERE a.course_id = $3 AND a.employee_id = e.id) ORDER BY e.id`, [cid, ids, courseId]);
    for (const r of validi) {
      const [{ id }] = await this.ds.query(
        `INSERT INTO academy_assignments (company_id, course_id, employee_id, assigned_by_user_id, assigned_at, notify_pending, notify_email)
         VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP, true, $5) RETURNING id`, [cid, courseId, r.id, attore.id, email]);
      await this.academy.evento('assigned', cid, { course_id: courseId, assignment_id: id, employee_id: r.id }, attore);
    }
    return { success: true as const, count: validi.length, skipped: ids.length - validi.length };
  }

  async assegnazione(id: number, cid: number) {
    const [a] = await this.ds.query(
      `SELECT a.*, e.first_name, e.last_name, e.email, e.fiscal_code, e.department_id FROM academy_assignments a JOIN employees e ON e.id = a.employee_id
        WHERE a.id = $1 AND a.company_id = $2`, [id, cid]);
    return a ?? null;
  }

  /** Solo se il dipendente non ha ancora iniziato. */
  async rimuoviAssegnazione(id: number, cid: number, attore: Attore): Promise<Esito> {
    const a = await this.assegnazione(id, cid);
    if (!a) return { success: false, error: 'Assegnazione non trovata' };
    if (a.started_at) return { success: false, error: "Il dipendente ha già iniziato il corso: l'assegnazione resta nel registro" };
    await this.ds.query('DELETE FROM academy_assignments WHERE id = $1 AND company_id = $2', [id, cid]);
    await this.academy.evento('unassigned', cid, { course_id: a.course_id, assignment_id: id, employee_id: a.employee_id }, attore);
    return { success: true };
  }

  assegnazioni(courseId: number, cid: number, reparto: number | null) {
    const p: unknown[] = [courseId, cid];
    if (reparto !== null) p.push(reparto);
    return this.ds.query(
      `SELECT a.*, e.first_name, e.last_name, e.fiscal_code, e.is_active, d.name AS department_name,
              (SELECT COUNT(*) FROM academy_material_views v JOIN academy_materials m ON m.id = v.material_id WHERE v.assignment_id = a.id AND m.course_id = $1)::int AS opened_count,
              ce.id AS certificate_id, ce.number AS certificate_number, ce.issued_at AS certificate_issued_at
         FROM academy_assignments a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
        WHERE a.course_id = $1 AND a.company_id = $2${reparto !== null ? ' AND e.department_id = $3' : ''}
        ORDER BY e.last_name, e.first_name, a.id`, p);
  }

  async notificheInCoda(courseId: number, cid: number): Promise<number> {
    const [r] = await this.ds.query('SELECT COUNT(*)::int AS n FROM academy_assignments WHERE company_id = $1 AND course_id = $2 AND notify_pending = true', [cid, courseId]);
    return r.n;
  }

  /** Blocco di notifiche di assegnazione (chiamato in sequenza dalla pagina). */
  async inviaNotifiche(courseId: number, limite: number, cid: number, attore: Attore) {
    const n = Math.max(1, Math.min(20, limite || 4));
    const c = await this.corso(courseId, cid);
    if (!c) return { success: false, error: 'Corso non trovato' };
    const righe = await this.ds.query(
      'SELECT id, employee_id, notify_email FROM academy_assignments WHERE company_id = $1 AND course_id = $2 AND notify_pending = true ORDER BY id LIMIT $3', [cid, courseId, n]);
    let inviate = 0;
    for (const r of righe) {
      // Prima si sblocca la riga: se l'invio si blocca non resta in coda per sempre
      await this.ds.query('UPDATE academy_assignments SET notify_pending = false WHERE id = $1', [r.id]);
      const scad = c.due_date ? ` Da completare entro il ${dmy(String(c.due_date))}.` : '';
      await this.academy.avvisaDipendente(r.employee_id, `Nuovo corso: ${c.title}`, `Ti è stato assegnato il corso "${c.title}".${scad}`, c.id, cid, !!r.notify_email);
      await this.academy.evento('notified', cid, { course_id: courseId, assignment_id: r.id, employee_id: r.employee_id }, attore);
      inviate++;
    }
    return { success: true, sent: inviate, remaining: await this.notificheInCoda(courseId, cid) };
  }

  async sollecita(id: number, cid: number, attore: Attore): Promise<Esito> {
    const a = await this.assegnazione(id, cid);
    if (!a) return { success: false, error: 'Assegnazione non trovata' };
    if (a.completed_at) return { success: false, error: 'Il corso è già completato' };
    const c = await this.corso(a.course_id, cid);
    if (!c) return { success: false, error: 'Dati non trovati' };
    const scad = c.due_date ? ` La scadenza è il ${dmy(String(c.due_date))}.` : '';
    await this.academy.avvisaDipendente(a.employee_id, `Promemoria corso: ${c.title}`, `Ti ricordiamo di completare il corso "${c.title}".${scad}`, c.id, cid, true);
    await this.ds.query('UPDATE academy_assignments SET reminder_count = reminder_count + 1, last_reminder_at = CURRENT_TIMESTAMP WHERE id = $1', [id]);
    await this.academy.evento('reminded', cid, { course_id: a.course_id, assignment_id: id, employee_id: a.employee_id }, attore);
    return { success: true };
  }

  async sollecitaTutti(courseId: number, cid: number, attore: Attore) {
    let n = 0;
    for (const r of await this.ds.query('SELECT id FROM academy_assignments WHERE company_id = $1 AND course_id = $2 AND completed_at IS NULL ORDER BY id', [cid, courseId])) {
      if ((await this.sollecita(r.id, cid, attore)).success) n++;
    }
    return n;
  }

  /** Eventi del registro con nomi leggibili (Academy::getEvents). */
  eventi(cid: number, corso: number | null, assegnazione: number | null, reparto: number | null) {
    const p: unknown[] = [cid];
    let sql = `SELECT ev.id, ev.company_id, ev.course_id, ev.assignment_id, ev.employee_id, ev.material_id, ev.event, ev.actor_type, ev.actor_id, ev.details,
                      ev.ip_address, ev.user_agent, ${ORA('ev.created_at')} AS created_at, ev.prev_hash, ev.hash,
                      e.first_name, e.last_name, e.fiscal_code, m.title AS material_title, c.title AS course_title, u.username AS actor_username
                 FROM academy_events ev LEFT JOIN employees e ON e.id = ev.employee_id LEFT JOIN academy_materials m ON m.id = ev.material_id
                 LEFT JOIN academy_courses c ON c.id = ev.course_id LEFT JOIN users u ON u.id = ev.actor_id AND ev.actor_type <> 'employee'
                WHERE ev.company_id = $1`;
    if (corso !== null) { p.push(corso); sql += ` AND ev.course_id = $${p.length}`; }
    if (assegnazione !== null) { p.push(assegnazione); sql += ` AND ev.assignment_id = $${p.length}`; }
    if (reparto !== null) { p.push(reparto); sql += ` AND e.department_id = $${p.length}`; }
    return this.ds.query(`${sql} ORDER BY ev.id`, p);
  }

  /** Registro in CSV (separatore ;, con BOM per Excel). */
  csvEventi(eventi: any[]): Buffer {
    const cella = (v: unknown) => {
      const s = String(v ?? '');
      return /[;"\n\r\t ]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const righe = [['N.', 'Data e ora (server)', 'Evento', 'Corso', 'Dipendente', 'Codice fiscale', 'Materiale', 'Eseguito da', 'Dettagli', 'IP', 'Browser', 'Hash']];
    for (const ev of eventi) {
      righe.push([ev.id, dmyhms(ev.created_at), ETICHETTE_EVENTI[ev.event] ?? ev.event, ev.course_title ?? '', `${ev.first_name ?? ''} ${ev.last_name ?? ''}`.trim(),
        ev.fiscal_code ?? '', ev.material_title ?? '', etichettaAttore(ev), ev.details ?? '', ev.ip_address ?? '', ev.user_agent ?? '', ev.hash]);
    }
    return Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(righe.map((r) => r.map(cella).join(';')).join('\n') + '\n', 'utf8')]);
  }

  /** Registro in PDF (A4 orizzontale) con l'esito della verifica della catena. */
  async pdfEventi(eventi: any[], titolo: string, cid: number): Promise<Buffer> {
    const catena = await this.academy.verificaCatena(cid);
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT name FROM companies WHERE id = $1', [cid]));
    const doc = new PDFDocument({ size: 'A4', layout: 'landscape', margin: 28, info: { Title: titolo, Creator: 'Academy' } });
    const parti: Buffer[] = [];
    doc.on('data', (b: Buffer) => parti.push(b));
    const fine = new Promise<Buffer>((ok) => doc.on('end', () => ok(Buffer.concat(parti))));
    const adesso = new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date()).replace(',', '');
    doc.font('Helvetica-Bold').fontSize(14).text(titolo);
    doc.font('Helvetica').fontSize(9).text(`${az?.name ?? ''} - generato il ${adesso} - ${eventi.length} eventi`);
    doc.text(`Integrità registro: ${catena.ok ? `verificata (${catena.checked} righe, catena di hash SHA-256 integra)` : `ATTENZIONE: catena interrotta alla riga ${catena.broken_id}`}`);
    doc.moveDown(0.5);
    const larghezza = doc.page.width - 56;
    const colonne = [7, 11, 13, 14, 16, 12, 9, 18].map((p) => (larghezza * p) / 100);
    const intestazione = ['N.', 'Data e ora', 'Evento', 'Dipendente', 'Materiale / dettagli', 'Eseguito da', 'IP', 'Browser'];
    const riga = (celle: string[], grassetto: boolean) => {
      doc.font(grassetto ? 'Helvetica-Bold' : 'Helvetica').fontSize(7);
      const alt = Math.max(...celle.map((t, i) => doc.heightOfString(t, { width: colonne[i] - 6 }))) + 6;
      if (doc.y + alt > doc.page.height - 34) doc.addPage();
      const y = doc.y;
      let x = 28;
      if (grassetto) doc.rect(28, y, larghezza, alt).fill('#e8edf7').fillColor('#000');
      for (const [i, t] of celle.entries()) {
        doc.rect(x, y, colonne[i], alt).lineWidth(0.3).stroke();
        doc.text(t, x + 3, y + 3, { width: colonne[i] - 6 });
        x += colonne[i];
      }
      doc.x = 28;
      doc.y = y + alt;
    };
    riga(intestazione, true);
    for (const ev of eventi) {
      const dettaglio = ev.material_title || (ev.event === 'completed' ? 'Dichiarazione accettata' : ev.details ?? '');
      riga([String(ev.id), dmyhms(ev.created_at), ETICHETTE_EVENTI[ev.event] ?? ev.event, `${`${ev.first_name ?? ''} ${ev.last_name ?? ''}`.trim()}\n${ev.fiscal_code ?? ''}`,
        taglia(String(dettaglio), 120), etichettaAttore(ev), ev.ip_address ?? '', taglia(String(ev.user_agent ?? ''), 90)], false);
    }
    doc.end();
    return fine;
  }

  /** Dipendenti attivi dell'azienda (Employee::getAll(true)), per assegnazioni e anteprime. */
  dipendentiAttivi(cid: number) {
    return this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.fiscal_code, e.birth_date, e.position, e.department_id, d.name AS department_name
         FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.company_id = $1 AND e.is_active = true
        ORDER BY e.last_name ASC, e.first_name ASC, e.id ASC`, [cid]);
  }

  /** Responsabili della formazione collegati all'azienda (User::getFormatori). */
  async formatori(cid: number): Promise<number> {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT COUNT(*)::int AS n FROM users u WHERE u.role = 'formatore' AND u.company_id = $1`, [cid]));
    return r.n;
  }

  oggi() { return oggiRoma(); }

  catena(cid: number) { return this.academy.verificaCatena(cid); }
}
