import { Injectable, Logger } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { FileCaricato, contenutoCoerente, estensione, fileSicuro } from '../../common/file/caricamenti';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { escapeHtml, MailerService } from '../notifiche/mailer.service';
import { PushService } from '../notifiche/push.service';

type Ctx = ContestoAudit & { tipo: string; id: number };

const ESTENSIONI = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'odt', 'ods'];
const MASSIMO = 30 * 1024 * 1024;
const DATA = /^\d{4}-\d{2}-\d{2}$/;

/** Tipo del file dal contenuto (finfo del PHP) per i formati ammessi. */
function mimeDocumento(ext: string): string {
  return ({
    pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', xls: 'application/vnd.ms-excel',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', csv: 'text/csv', txt: 'text/plain',
    odt: 'application/vnd.oasis.opendocument.text', ods: 'application/vnd.oasis.opendocument.spreadsheet',
  } as Record<string, string>)[ext] ?? 'application/octet-stream';
}

/**
 * Documenti personali e distribuzioni massive (EmployeeDocument.php) dal
 * lato dello staff. Correzioni: tutto resta nell'azienda di lavoro (il PHP
 * modificava ed eliminava documenti di altre aziende conoscendo l'id) e il
 * file si controlla dal contenuto, non solo dall'estensione.
 */
@Injectable()
export class DocumentiPersonaliService {
  private readonly logger = new Logger('DocumentiPersonali');
  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly push: PushService,
    private readonly mailer: MailerService) {}

  private valida(f: FileCaricato | undefined): { ok: true; ext: string; mime: string } | { ok: false; error: string } {
    if (!f || !f.size) return { ok: false, error: 'Errore upload (codice 4)' };
    if (f.size > MASSIMO) return { ok: false, error: 'File troppo grande. Massimo 30MB' };
    const ext = estensione(f.originalname);
    if (!ESTENSIONI.includes(ext)) return { ok: false, error: `Estensione non consentita: .${ext} (permesse: ${ESTENSIONI.join(', ')})` };
    if (!contenutoCoerente(ext, f.buffer)) return { ok: false, error: 'Tipo MIME non consentito: il contenuto non corrisponde al tipo di file' };
    return { ok: true, ext, mime: mimeDocumento(ext) };
  }

  private async dipendente(id: number, cid: number) {
    const [e] = await this.ds.query('SELECT id, company_id, first_name, last_name, department_id FROM employees WHERE id = $1 AND company_id = $2', [id, cid]);
    return e ?? null;
  }

  /** EmployeeDocument::getById, solo nell'azienda di lavoro. */
  async perId(id: number, cid: number) {
    const [d] = await this.ds.query(
      `SELECT ed.*, u.name AS uploaded_by_name, e.first_name, e.last_name, e.fiscal_code, concat(e.last_name, ' ', e.first_name) AS employee_name
         FROM employee_documents ed JOIN employees e ON ed.employee_id = e.id JOIN users u ON ed.uploaded_by = u.id
        WHERE ed.id = $1 AND ed.company_id = $2`, [id, cid]);
    return d ?? null;
  }

  perDipendente(employeeId: number) {
    return this.ds.query(
      `SELECT ed.id, ed.employee_id, ed.bulk_group, ed.name, ed.original_name, ed.file_size, ed.mime_type, ed.visible_to_employee,
              ed.expires_on, ed.created_at, ed.uploaded_by, u.name AS uploaded_by_name
         FROM employee_documents ed JOIN users u ON ed.uploaded_by = u.id WHERE ed.employee_id = $1 ORDER BY ed.created_at DESC, ed.id DESC`, [employeeId]);
  }

  /** EmployeeDocument::upload. */
  async carica(f: FileCaricato, d: { employee_id: number; name: string; visible_to_employee: boolean; expires_on: string | null }, cid: number, ctx: Ctx) {
    if (!d.employee_id) return { success: false as const, error: 'Dipendente non specificato' };
    let nome = String(d.name ?? '').trim();
    if (nome === '') return { success: false as const, error: 'Nome documento obbligatorio' };
    nome = [...nome].slice(0, 255).join('');
    const e = await this.dipendente(d.employee_id, cid);
    if (!e) return { success: false as const, error: 'Dipendente non trovato' };
    const v = this.valida(f);
    if (!v.ok) return { success: false as const, error: v.error };
    const scade = d.expires_on || null;
    if (scade !== null && !DATA.test(scade)) return { success: false as const, error: 'Data scadenza non valida' };
    const nomeFile = `${randomBytes(16).toString('hex')}.${v.ext}`;
    const dir = cartellaStorage('documents', 'employee-docs', String(d.employee_id));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const dest = path.join(dir, nomeFile);
    fs.writeFileSync(dest, f.buffer, { mode: 0o640 });
    try {
      const [r] = await this.ds.query(
        `INSERT INTO employee_documents (company_id, employee_id, name, file_path, file_name, original_name, file_size, mime_type, visible_to_employee, expires_on, uploaded_by)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11) RETURNING id`,
        [e.company_id, e.id, nome, dest, nomeFile, f.originalname.slice(0, 255), f.size, v.mime, d.visible_to_employee, scade, ctx.id]);
      await this.audit.log('employee_document_uploaded', ctx.tipo, ctx.id, ctx, 'employee_document', r.id, null,
        { employee_id: e.id, name: nome, visible: d.visible_to_employee ? 1 : 0 });
      if (d.visible_to_employee) await this.notifica(e, nome, true);
      return { success: true as const, id: r.id as number };
    } catch (err: any) {
      fs.rmSync(dest, { force: true });
      this.logger.error(`documento non salvato: ${err?.message ?? err}`);
      return { success: false as const, error: 'Errore durante il salvataggio' };
    }
  }

  /** EmployeeDocument::uploadMany: stesso codice di stato del PHP (ed_status). */
  async caricaMolti(file: FileCaricato[], d: { employee_id: number; name: string; visible_to_employee: boolean; expires_on: string | null }, cid: number, ctx: Ctx) {
    const files = file.filter((f) => f.originalname);
    if (!files.length) return 'no_file';
    const base = String(d.name ?? '').trim();
    const errori: string[] = [];
    let caricati = 0;
    for (const [i, f] of files.entries()) {
      const stem = path.basename(f.originalname, path.extname(f.originalname)).trim();
      const nome = base === '' ? (stem !== '' ? stem : f.originalname) : files.length > 1 ? `${base} ${i + 1}` : base;
      const r = await this.carica(f, { ...d, name: nome }, cid, ctx);
      if (r.success) caricati++; else errori.push(`${f.originalname}: ${r.error}`);
    }
    if (!caricati) return `error_${errori[0] ?? 'upload fallito'}`;
    return caricati === files.length ? `uploaded_${caricati}` : `partial_${caricati}_${files.length}_${errori[0] ?? ''}`;
  }

  /** EmployeeDocument::update. */
  async aggiorna(id: number, d: { name?: string; visible_to_employee?: boolean; expires_on?: string | null }, cid: number, ctx: Ctx) {
    const doc = await this.perId(id, cid);
    if (!doc) return { success: false, error: 'Documento non trovato' };
    const campi: Record<string, unknown> = {};
    if (d.name !== undefined) {
      const n = String(d.name).trim();
      if (n === '') return { success: false, error: 'Il nome non puo essere vuoto' };
      campi.name = [...n].slice(0, 255).join('');
    }
    let diventaVisibile = false;
    if (d.visible_to_employee !== undefined) {
      campi.visible_to_employee = d.visible_to_employee;
      diventaVisibile = d.visible_to_employee && !doc.visible_to_employee;
    }
    if (d.expires_on !== undefined) {
      if (d.expires_on === '' || d.expires_on === null) campi.expires_on = null;
      else if (DATA.test(d.expires_on)) campi.expires_on = d.expires_on;
      else return { success: false, error: 'Data scadenza non valida' };
    }
    const k = Object.keys(campi);
    if (!k.length) return { success: false, error: 'Nessun dato da aggiornare' };
    await this.ds.query(`UPDATE employee_documents SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [id, ...k.map((x) => campi[x])]);
    await this.audit.log('employee_document_updated', ctx.tipo, ctx.id, ctx, 'employee_document', id, doc, campi);
    if (diventaVisibile) {
      const e = await this.dipendente(doc.employee_id, cid);
      if (e) await this.notifica(e, String(campi.name ?? doc.name), true);
    }
    return { success: true };
  }

  async elimina(id: number, cid: number, ctx: Ctx) {
    const doc = await this.perId(id, cid);
    if (!doc) return { success: false, error: 'Documento non trovato' };
    await this.ds.query('DELETE FROM employee_documents WHERE id = $1', [id]);
    await this.eliminaFileSeLibero(doc.file_path);
    await this.audit.log('employee_document_deleted', ctx.tipo, ctx.id, ctx, 'employee_document', id, doc, null);
    return { success: true };
  }

  private async eliminaFileSeLibero(percorso: string) {
    const [n] = await this.ds.query('SELECT COUNT(*)::int AS n FROM employee_documents WHERE file_path = $1', [percorso]);
    const p = fileSicuro(percorso);
    if (n.n === 0 && p) fs.rmSync(p, { force: true });
  }

  // ─── Distribuzioni a tutta l'azienda ──────────────────────────────────

  /** EmployeeDocument::uploadForAllEmployees: un file, una riga per dipendente attivo, notifiche in coda. */
  async caricaPerTutti(f: FileCaricato, d: { name: string; expires_on: string | null; send_email: boolean }, cid: number, ctx: Ctx) {
    let nome = String(d.name ?? '').trim();
    if (nome === '') return { success: false as const, error: 'Nome documento obbligatorio' };
    nome = [...nome].slice(0, 255).join('');
    const v = this.valida(f);
    if (!v.ok) return { success: false as const, error: v.error };
    const scade = d.expires_on || null;
    if (scade !== null && !DATA.test(scade)) return { success: false as const, error: 'Data scadenza non valida' };
    const dipendenti = await this.ds.query('SELECT id FROM employees WHERE company_id = $1 AND is_active = true ORDER BY last_name ASC, first_name ASC', [cid]);
    if (!dipendenti.length) return { success: false as const, error: 'Nessun dipendente attivo a cui agganciare il documento' };
    const gruppo = randomBytes(8).toString('hex');
    const nomeFile = `${randomBytes(16).toString('hex')}.${v.ext}`;
    const dir = cartellaStorage('documents', 'employee-docs', '_bulk', String(cid));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const dest = path.join(dir, nomeFile);
    fs.writeFileSync(dest, f.buffer, { mode: 0o640 });
    let primo = 0;
    try {
      await this.ds.transaction(async (m) => {
        for (const e of dipendenti) {
          const [r] = await m.query(
            `INSERT INTO employee_documents (company_id, bulk_group, employee_id, name, file_path, file_name, original_name, file_size, mime_type,
               visible_to_employee, notify_pending, notify_email, expires_on, uploaded_by)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, true, true, $10, $11, $12) RETURNING id`,
            [cid, gruppo, e.id, nome, dest, nomeFile, f.originalname.slice(0, 255), f.size, v.mime, d.send_email, scade, ctx.id]);
          primo ||= r.id;
        }
      });
    } catch (err: any) {
      fs.rmSync(dest, { force: true });
      this.logger.error(`distribuzione non salvata: ${err?.message ?? err}`);
      return { success: false as const, error: 'Errore durante il salvataggio' };
    }
    await this.audit.log('employee_document_bulk_uploaded', ctx.tipo, ctx.id, ctx, 'employee_document', primo, null,
      { bulk_group: gruppo, name: nome, employees: dipendenti.length, visible: 1 });
    return { success: true as const, count: dipendenti.length as number, bulk_group: gruppo };
  }

  /** EmployeeDocument::processNotificationBatch. */
  async lottoNotifiche(gruppo: string, limite: number, cid: number) {
    const g = String(gruppo ?? '').trim();
    if (g === '') return { success: false, error: 'Gruppo non valido' };
    const n = Math.max(1, Math.min(20, Number.isFinite(limite) ? Math.trunc(limite) : 4));
    const righe = await this.ds.query(
      `SELECT id, employee_id, name, notify_email FROM employee_documents WHERE company_id = $1 AND bulk_group = $2 AND notify_pending = true ORDER BY id LIMIT ${n}`, [cid, g]);
    let sent = 0;
    for (const r of righe) {
      // Prima si sblocca la riga: se l'invio si blocca non si ripete all'infinito
      await this.ds.query('UPDATE employee_documents SET notify_pending = false WHERE id = $1', [r.id]);
      const e = await this.dipendente(r.employee_id, cid);
      if (e) { await this.notifica(e, r.name, !!r.notify_email); sent++; }
    }
    return { success: true, sent, remaining: await this.inSospeso(cid, g) };
  }

  async inSospeso(cid: number, gruppo: string | null = null): Promise<number> {
    const [r] = gruppo !== null && gruppo.trim() !== ''
      ? await this.ds.query('SELECT COUNT(*)::int AS n FROM employee_documents WHERE company_id = $1 AND bulk_group = $2 AND notify_pending = true', [cid, gruppo.trim()])
      : await this.ds.query('SELECT COUNT(*)::int AS n FROM employee_documents WHERE company_id = $1 AND bulk_group IS NOT NULL AND notify_pending = true', [cid]);
    return r.n;
  }

  async gruppoInSospeso(cid: number): Promise<string | null> {
    const [r] = await this.ds.query(
      'SELECT bulk_group FROM employee_documents WHERE company_id = $1 AND bulk_group IS NOT NULL AND notify_pending = true ORDER BY id LIMIT 1', [cid]);
    return r?.bulk_group ?? null;
  }

  gruppi(cid: number) {
    return this.ds.query(
      `SELECT ed.bulk_group, MIN(ed.name) AS name, MIN(ed.original_name) AS original_name, MIN(ed.file_size) AS file_size, MIN(ed.mime_type) AS mime_type,
              MIN(ed.expires_on) AS expires_on, bool_or(ed.visible_to_employee) AS visible_to_employee, MIN(ed.created_at) AS created_at,
              MIN(ed.id) AS sample_id, COUNT(*)::int AS employee_count, COUNT(DISTINCT dd.user_id)::int AS downloaded_count,
              SUM(CASE WHEN ed.notify_pending THEN 1 ELSE 0 END)::int AS pending_notifications, MIN(u.name) AS uploaded_by_name
         FROM employee_documents ed JOIN users u ON ed.uploaded_by = u.id
         LEFT JOIN employee_document_downloads dd ON dd.employee_document_id = ed.id AND dd.user_type = 'employee'
        WHERE ed.company_id = $1 AND ed.bulk_group IS NOT NULL GROUP BY ed.bulk_group ORDER BY MIN(ed.created_at) DESC`, [cid]);
  }

  async eliminaGruppo(gruppo: string, cid: number, ctx: Ctx) {
    const g = String(gruppo ?? '').trim();
    if (g === '') return { success: false as const, error: 'Gruppo non valido' };
    const righe = await this.ds.query('SELECT id, file_path FROM employee_documents WHERE company_id = $1 AND bulk_group = $2 ORDER BY id', [cid, g]);
    if (!righe.length) return { success: false as const, error: 'Distribuzione non trovata' };
    await this.ds.query('DELETE FROM employee_documents WHERE company_id = $1 AND bulk_group = $2', [cid, g]);
    await this.eliminaFileSeLibero(righe[0].file_path);
    await this.audit.log('employee_document_bulk_deleted', ctx.tipo, ctx.id, ctx, 'employee_document', righe[0].id, null, { bulk_group: g, rows: righe.length });
    return { success: true as const, count: righe.length as number };
  }

  /** Push ed email "nuovo documento" (EmployeeDocument::notifyEmployee). */
  private async notifica(e: { id: number; first_name: string; last_name: string }, nomeDoc: string, email: boolean) {
    await this.push.aUtente('employee', e.id, {
      title: 'Nuovo documento disponibile', body: `Documento ${nomeDoc}`, url: '/employee/documents.php',
      tag: `document-${Math.floor(Date.now() / 1000)}`, icon: '/assets/images/icon.php?size=192',
    }).catch((err) => this.logger.error(`push documento: ${err?.message ?? err}`));
    if (!email || !this.mailer.configurato()) return;
    try {
      const login = urlPubblico('/auth/login.php');
      const nome = `${e.first_name} ${e.last_name}`.trim();
      const html = `<p>Ciao ${escapeHtml(nome)},</p><p>E' stato caricato un nuovo documento nella tua area personale:</p>`
        + `<p><strong>${escapeHtml(nomeDoc)}</strong></p><p><a href="${login}">Accedi al portale per consultarlo</a></p>`;
      await this.mailer.inviaADipendente(e.id, `Nuovo documento disponibile: ${nomeDoc}`, html, `Ciao ${nome},\n\nNuovo documento disponibile: ${nomeDoc}\n\nAccedi: ${login}`);
    } catch (err: any) {
      this.logger.error(`email documento: ${err?.message ?? err}`);
    }
  }
}
