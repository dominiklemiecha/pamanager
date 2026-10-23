import { Injectable, Logger } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { sanitizzaHtmlRicco } from '../../common/html/sanitizza';
import { DIMENSIONE_MASSIMA, ESTENSIONI_DOCUMENTI, FileCaricato, contenutoCoerente, estensione, mimeDaContenuto } from '../../common/file/caricamenti';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { escapeHtml, MailerService } from '../notifiche/mailer.service';
import { PushService } from '../notifiche/push.service';

export const PRIORITA: Record<string, string> = { low: 'Bassa', normal: 'Normale', high: 'Alta' };
const IMMAGINI = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const DATA = /^\d{4}-\d{2}-\d{2}$/;

type Ctx = ContestoAudit & { tipo: string; id: number };
export type EsitoComunicazione = { success: true; id?: number } | { success: false; error: string };

export interface DatiComunicazione {
  title: string;
  content: string;
  priority: string;
  is_published: boolean;
  publish_date: string;
  expire_date: string | null;
  department_id?: number | null;
}

/**
 * Comunicazioni dal lato dell'HR e del responsabile di reparto
 * (Communication.php). Ogni operazione resta nell'azienda di lavoro.
 */
@Injectable()
export class ComunicazioniAdminService {
  private readonly logger = new Logger('Comunicazioni');
  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly push: PushService,
    private readonly mailer: MailerService) {}

  /** Communication::getAll: con letture e destinatari attivi. */
  elenco(cid: number, ricerca = '', repartoId: number | null = null) {
    const p: unknown[] = [cid];
    let sql = `SELECT c.id, c.company_id, c.title, c.priority, c.is_published, c.publish_date, c.expire_date, c.is_global, c.department_id,
        c.created_by, c.created_at, u.name AS author_name, d.name AS department_name, d.code AS department_code,
        (SELECT COUNT(*) FROM communication_reads cr JOIN employees e ON e.id = cr.employee_id
          WHERE cr.communication_id = c.id AND e.company_id = c.company_id AND e.is_active = true
            AND (c.is_global = true OR e.department_id = c.department_id))::int AS read_count,
        (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.company_id AND e.is_active = true
            AND (c.is_global = true OR e.department_id = c.department_id))::int AS total_employees
      FROM communications c JOIN users u ON c.created_by = u.id LEFT JOIN departments d ON c.department_id = d.id
      WHERE c.company_id = $1`;
    if (ricerca) { p.push(`%${ricerca}%`); sql += ` AND (c.title ILIKE $${p.length} OR c.content ILIKE $${p.length})`; }
    if (repartoId !== null) { p.push(repartoId); sql += ` AND (c.is_global = true OR c.department_id = $${p.length})`; }
    return this.ds.query(`${sql} ORDER BY c.created_at DESC, c.id DESC`, p);
  }

  /** Communication::getById, solo nell'azienda di lavoro; il testo esce gia' ripulito. */
  async perId(id: number, cid: number) {
    const [c] = await this.ds.query(
      `SELECT c.*, u.name AS author_name FROM communications c JOIN users u ON c.created_by = u.id WHERE c.id = $1 AND c.company_id = $2`, [id, cid]);
    return c ? { ...c, content: sanitizzaHtmlRicco(c.content ?? '') } : null;
  }

  allegati(id: number) {
    return this.ds.query(
      `SELECT id, original_name, mime_type, size_bytes, is_inline_image, created_at FROM communication_attachments
        WHERE communication_id = $1 AND is_inline_image = false ORDER BY id ASC`, [id]);
  }

  /** Communication::getReadStats. */
  async statistiche(id: number, c: { company_id: number; is_global: boolean; department_id: number | null }) {
    const p: unknown[] = [c.company_id];
    let ambito = 'e.company_id = $1 AND e.is_active = true';
    if (!c.is_global && c.department_id) { p.push(c.department_id); ambito += ' AND e.department_id = $2'; }
    const [t] = await this.ds.query(`SELECT COUNT(*)::int AS n FROM employees e WHERE ${ambito}`, p);
    const [l] = await this.ds.query(
      `SELECT COUNT(*)::int AS n FROM communication_reads cr JOIN employees e ON e.id = cr.employee_id WHERE cr.communication_id = $${p.length + 1} AND ${ambito}`, [...p, id]);
    const readers = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, cr.read_at FROM communication_reads cr JOIN employees e ON cr.employee_id = e.id
        WHERE cr.communication_id = $1 ORDER BY cr.read_at DESC, e.id`, [id]);
    const totale = t.n;
    const lette = l.n;
    return { total_employees: totale, read_count: lette, unread_count: totale - lette, percentage: totale > 0 ? Math.round((lette / totale) * 1000) / 10 : 0, readers };
  }

  /**
   * Communication::create. Correzione: "Pubblica immediatamente" non
   * spuntato crea una bozza (il PHP pubblicava e avvisava comunque tutti).
   */
  async crea(d: DatiComunicazione, cid: number, ctx: Ctx): Promise<EsitoComunicazione> {
    if (!String(d.title ?? '').trim()) return { success: false, error: 'Titolo obbligatorio' };
    if (!d.content) return { success: false, error: 'Contenuto obbligatorio' };
    const pubblica = d.publish_date || oggiRoma();
    if (!DATA.test(pubblica) || (d.expire_date && !DATA.test(d.expire_date))) return { success: false, error: 'Data non valida' };
    if (d.expire_date && d.expire_date < pubblica) return { success: false, error: 'Data scadenza non valida' };
    if (String(d.title).trim().length > 255) return { success: false, error: 'Titolo troppo lungo (max 255 caratteri)' };
    const globale = !d.department_id;
    if (!globale && !(await this.ds.query('SELECT 1 FROM departments WHERE id = $1 AND company_id = $2', [d.department_id, cid])).length) {
      return { success: false, error: 'Reparto non trovato' };
    }
    const priorita = PRIORITA[d.priority] ? d.priority : 'normal';
    try {
      const [r] = await this.ds.query(
        `INSERT INTO communications (company_id, title, content, priority, is_published, publish_date, expire_date, is_global, department_id, created_by)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10) RETURNING id`,
        [cid, String(d.title).trim(), d.content, priorita, d.is_published, pubblica, d.expire_date || null, globale, globale ? null : d.department_id, ctx.id]);
      await this.audit.log('communication_created', ctx.tipo, ctx.id, ctx, 'communication', r.id, null, { ...d });
      if (d.is_published && pubblica <= oggiRoma()) await this.avvisa(r.id, cid, String(d.title).trim(), d.content, priorita, globale, globale ? null : d.department_id!);
      return { success: true, id: r.id };
    } catch (e: any) {
      this.logger.error(`creazione comunicazione non riuscita: ${e?.message ?? e}`);
      return { success: false, error: 'Errore durante il salvataggio' };
    }
  }

  /**
   * Communication::update (stesse regole: titolo e testo vuoti non cambiano).
   * Correzione: togliere "Pubblica" in modifica funziona (nel PHP dava errore).
   */
  async aggiorna(id: number, d: Partial<DatiComunicazione>, cid: number, ctx: Ctx): Promise<EsitoComunicazione> {
    const c = await this.perId(id, cid);
    if (!c) return { success: false, error: 'Comunicazione non trovata' };
    const campi: Record<string, unknown> = {};
    if (d.title) campi.title = String(d.title).trim().slice(0, 255);
    if (d.content) campi.content = d.content;
    if (d.priority && PRIORITA[d.priority]) campi.priority = d.priority;
    if (d.is_published !== undefined) campi.is_published = !!d.is_published;
    if (d.publish_date !== undefined) {
      if (!DATA.test(String(d.publish_date))) return { success: false, error: 'Data non valida' };
      campi.publish_date = d.publish_date;
    }
    if ('expire_date' in d) {
      if (d.expire_date && !DATA.test(d.expire_date)) return { success: false, error: 'Data non valida' };
      campi.expire_date = d.expire_date || null;
    }
    const k = Object.keys(campi);
    if (!k.length) return { success: false, error: 'Nessun dato da aggiornare' };
    try {
      await this.ds.query(`UPDATE communications SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [id, ...k.map((x) => campi[x])]);
      await this.audit.log('communication_updated', ctx.tipo, ctx.id, ctx, 'communication', id, c, campi);
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'aggiornamento" };
    }
  }

  /** Eliminazione con letture e allegati (anche i file). */
  async elimina(id: number, cid: number, ctx: Ctx): Promise<EsitoComunicazione> {
    const c = await this.perId(id, cid);
    if (!c) return { success: false, error: 'Comunicazione non trovata' };
    try {
      await this.ds.query('DELETE FROM communications WHERE id = $1', [id]);
      fs.rmSync(cartellaStorage('communications', String(id)), { recursive: true, force: true });
      await this.audit.log('communication_deleted', ctx.tipo, ctx.id, ctx, 'communication', id, c, null);
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'eliminazione" };
    }
  }

  async pubblicazione(id: number, cid: number, ctx: Ctx): Promise<EsitoComunicazione> {
    const c = await this.perId(id, cid);
    if (!c) return { success: false, error: 'Comunicazione non trovata' };
    await this.ds.query('UPDATE communications SET is_published = $2 WHERE id = $1', [id, !c.is_published]);
    await this.audit.log('communication_toggled', ctx.tipo, ctx.id, ctx, 'communication', id, { is_published: c.is_published }, { is_published: !c.is_published });
    return { success: true };
  }

  /**
   * Communication::addUploadedFile. Correzione: si controlla anche il
   * contenuto del file, non solo l'estensione.
   */
  async allega(f: FileCaricato, id: number, inline: boolean): Promise<{ success: true; id: number; url: string } | { success: false; error: string }> {
    if (!f || !f.size) return { success: false, error: 'Errore upload' };
    if (f.size > DIMENSIONE_MASSIMA) return { success: false, error: 'File troppo grande' };
    const ext = estensione(f.originalname);
    if (!(inline ? IMMAGINI : ESTENSIONI_DOCUMENTI).includes(ext) || !contenutoCoerente(ext, f.buffer)) return { success: false, error: 'Tipo file non consentito' };
    const dir = cartellaStorage('communications', String(id));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const nome = `${randomBytes(12).toString('hex')}.${ext}`;
    const dest = path.join(dir, nome);
    fs.writeFileSync(dest, f.buffer, { mode: 0o640 });
    try {
      const [r] = await this.ds.query(
        `INSERT INTO communication_attachments (communication_id, original_name, stored_name, mime_type, size_bytes, is_inline_image)
         VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`, [id, f.originalname.slice(0, 255), nome, mimeDaContenuto(dest, f.originalname), f.size, inline]);
      return { success: true, id: r.id, url: `/communication_file.php?id=${r.id}` };
    } catch {
      fs.rmSync(dest, { force: true });
      return { success: false, error: 'Errore DB' };
    }
  }

  /** Allegato della comunicazione indicata, nell'azienda di lavoro (il PHP eliminava qualsiasi allegato). */
  async eliminaAllegato(allegatoId: number, comunicazioneId: number, cid: number): Promise<boolean> {
    const [a] = await this.ds.query(
      `SELECT a.id, a.communication_id, a.stored_name FROM communication_attachments a JOIN communications c ON c.id = a.communication_id
        WHERE a.id = $1 AND a.communication_id = $2 AND c.company_id = $3`, [allegatoId, comunicazioneId, cid]);
    if (!a) return false;
    const dir = cartellaStorage('communications', String(a.communication_id));
    const p = path.resolve(dir, path.basename(String(a.stored_name)));
    if (p.startsWith(dir + path.sep)) fs.rmSync(p, { force: true });
    await this.ds.query('DELETE FROM communication_attachments WHERE id = $1', [allegatoId]);
    return true;
  }

  /**
   * Push e email ai dipendenti destinatari (sendPushNotifications /
   * sendEmailNotifications). Correzione: la push a tutta l'azienda rispetta
   * il "no push" del dipendente.
   */
  private async avvisa(id: number, cid: number, titolo: string, contenuto: string, priorita: string, globale: boolean, repartoId: number | null) {
    const avviso = { title: 'Nuova comunicazione', body: titolo, url: `/employee/communications.php?id=${id}`, icon: '/assets/images/icon.php?size=192' };
    try {
      if (globale) {
        await this.push.aIscrizioni(
          `SELECT s.id, s.endpoint, s.p256dh, s.auth FROM push_subscriptions s JOIN employees e ON e.id = s.user_id
            WHERE s.user_type = 'employee' AND s.company_id = $1 AND e.company_id = $1 AND e.notify_push IS DISTINCT FROM false`,
          [cid], { ...avviso, tag: `broadcast-${Math.floor(Date.now() / 1000)}` });
      } else if (repartoId) {
        for (const e of await this.ds.query('SELECT id FROM employees WHERE department_id = $1 AND company_id = $2 AND is_active = true ORDER BY last_name, first_name', [repartoId, cid])) {
          await this.push.aUtente('employee', e.id, { ...avviso, tag: `communication-${id}` });
        }
      }
    } catch (e: any) {
      this.logger.error(`push comunicazione non inviate: ${e?.message ?? e}`);
    }
    try {
      if (!this.mailer.configurato() || (!globale && !repartoId)) return;
      const p: unknown[] = [cid];
      let sql = `SELECT id, first_name, last_name FROM employees WHERE is_active = true AND email IS NOT NULL AND email <> '' AND company_id = $1`;
      if (!globale) { p.push(repartoId); sql += ' AND department_id = $2'; }
      const login = urlPubblico('/auth/login.php');
      const etichetta = ({ low: 'Bassa', normal: 'Normale', high: 'Alta', urgent: 'Urgente' } as Record<string, string>)[priorita] ?? priorita;
      const testo = String(contenuto).replace(/<[^>]*>/g, '').trim();
      const estratto = [...testo].length > 400 ? `${[...testo].slice(0, 400).join('')}…` : testo;
      const oggetto = `${priorita === 'urgent' ? '[URGENTE] ' : ''}Nuova comunicazione: ${titolo}`;
      for (const e of await this.ds.query(sql + ' ORDER BY id', p)) {
        const nome = `${e.first_name} ${e.last_name}`.trim();
        const html = `<p>Ciao ${escapeHtml(nome)},</p><p>È stata pubblicata una nuova comunicazione${globale ? '' : ' per il tuo reparto'}:</p>`
          + `<p><strong>${escapeHtml(titolo)}</strong> <small style="color:#666;">(priorità: ${escapeHtml(etichetta)})</small></p>`
          + `<blockquote style="border-left:3px solid #ccc;padding-left:1em;color:#444;">${escapeHtml(estratto).replace(/\r?\n/g, '<br />\n')}</blockquote>`
          + `<p><a href="${login}">Accedi al portale per leggere la comunicazione completa</a></p>`;
        const txt = `Ciao ${nome},\n\nNuova comunicazione${globale ? '' : ' per il tuo reparto'}:\n\nTitolo: ${titolo}\nPriorità: ${etichetta}\n\n${estratto}\n\nAccedi: ${login}`;
        await this.mailer.inviaADipendente(e.id, oggetto, html, txt);
      }
    } catch (e: any) {
      this.logger.error(`email comunicazione non inviate: ${e?.message ?? e}`);
    }
  }
}
