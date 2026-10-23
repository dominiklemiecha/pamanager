import { Injectable } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { contenutoCoerente, fileSicuro } from '../../common/file/caricamenti';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { MailerService, escapeHtml } from '../notifiche/mailer.service';
import { PushService } from '../notifiche/push.service';
import { pdfConFiligrana } from '../../common/file/filigrana';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import type { UtenteCorrente } from '../auth/auth.types';

export type EsitoDownload =
  | { ok: true; contenuto: Buffer; nome: string; mime: string }
  | { ok: false; error: string };

const pulisciNome = (n: string) => n.replace(/[^a-zA-Z0-9._-]/g, '_');

/** "23/10/2026 14:05:09" ora di Roma (date('d/m/Y H:i:s') del PHP). */
function adessoRoma(): string {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date()).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}:${p.second}`;
}

/**
 * Buste paga, CU e documenti personali (Document.php, EmployeeDocument.php):
 * elenco per il dipendente e download con controllo di proprieta', azienda e
 * reparto, limite di 50 download l'ora, tracciamento e filigrana sui PDF.
 */
@Injectable()
export class DocumentiService {
  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly limiti: LimitiDbService,
    private readonly push: PushService, private readonly mailer: MailerService) {}

  /**
   * Documento creato dal sistema a partire da un file (Document::uploadFromPath),
   * es. il contratto firmato: copia nello storage, riga, push ed email al
   * dipendente come per un caricamento dell'HR.
   */
  async caricaDaPercorso(sorgente: string, d: { employee_id: number; type: 'payslip' | 'cud' | 'other'; month: number; year: number; title: string; description?: string; original_name: string; uploaded_by: number }) {
    const [e] = await this.ds.query('SELECT id, company_id, first_name, last_name, email FROM employees WHERE id = $1', [d.employee_id]);
    if (!e || !fs.existsSync(sorgente)) return { success: false, error: 'File sorgente non trovato' };
    const buf = fs.readFileSync(sorgente);
    if (!contenutoCoerente('pdf', buf)) return { success: false, error: 'Tipo file non consentito' };
    const nome = `${randomBytes(16).toString('hex')}.pdf`;
    const dir = cartellaStorage('documents', String(d.employee_id), String(d.year), String(d.month));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const dest = path.join(dir, nome);
    fs.writeFileSync(dest, buf, { mode: 0o640 });
    let caricatoDa = d.uploaded_by;
    if (caricatoDa <= 0) {
      const [a] = await this.ds.query(`SELECT id FROM users WHERE role = 'admin' AND is_active = true AND (company_id = $1 OR company_id IS NULL) ORDER BY id LIMIT 1`, [e.company_id]);
      caricatoDa = a?.id ?? 1;
    }
    let id: number;
    try {
      [{ id }] = await this.ds.query(
        `INSERT INTO documents (company_id, employee_id, type, title, description, file_path, file_name, original_name, file_size, mime_type, month, year, uploaded_by)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 'application/pdf', $10, $11, $12) RETURNING id`,
        [e.company_id, d.employee_id, d.type, d.title, d.description ?? null, dest, nome, d.original_name, buf.length, d.month, d.year, caricatoDa]);
    } catch {
      fs.rmSync(dest, { force: true });
      return { success: false, error: 'Errore durante il salvataggio del documento' };
    }
    const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    const periodo = `${MESI[d.month]} ${d.year}`;
    const tipo = ({ payslip: 'Busta paga', cud: 'CUD', other: 'Documento' } as Record<string, string>)[d.type];
    await this.push.aUtente('employee', d.employee_id, { title: 'Nuovo documento disponibile', body: `${tipo} ${periodo}`, url: '/employee/documents.php', tag: `document-${Math.floor(Date.now() / 1000)}`, icon: '/assets/images/icon.php?size=192' }).catch(() => undefined);
    if (e.email) {
      const etichetta = ({ payslip: 'Busta Paga', cud: 'CUD/CU', other: 'Altro' } as Record<string, string>)[d.type];
      const accesso = urlPubblico('/auth/login.php');
      const nomeCompleto = `${e.first_name} ${e.last_name}`.trim();
      const html = `<p>Ciao ${escapeHtml(nomeCompleto)},</p><p>È stato caricato un nuovo documento nella tua area personale:</p><ul>`
        + `<li><strong>Tipo:</strong> ${escapeHtml(etichetta)}</li><li><strong>Titolo:</strong> ${escapeHtml(d.title)}</li><li><strong>Periodo:</strong> ${escapeHtml(periodo)}</li></ul>`
        + `<p><a href="${accesso}">Accedi al portale per consultarlo</a></p>`;
      const testo = `Ciao ${nomeCompleto},\n\nNuovo documento disponibile:\n- Tipo: ${etichetta}\n- Titolo: ${d.title}\n- Periodo: ${periodo}\n\nAccedi: ${accesso}`;
      await this.mailer.inviaADipendente(d.employee_id, `Nuovo documento disponibile: ${etichetta} ${periodo}`, html, testo).catch(() => undefined);
    }
    return { success: true, id };
  }

  /** Dati della pagina Documenti del dipendente (documents.php + _cora/documents.inc.php). */
  async perDipendente(employeeId: number, tipo: string | null = null) {
    const buste = await this.ds.query(
      `SELECT d.id, d.type, d.title, d.year, d.month, d.file_name, d.original_name, d.file_size, d.mime_type, d.created_at,
              EXISTS (SELECT 1 FROM document_downloads dd WHERE dd.document_id = d.id AND dd.user_type = 'employee' AND dd.user_id = $1) AS is_downloaded
         FROM documents d JOIN employees e ON d.employee_id = e.id JOIN users u ON d.uploaded_by = u.id
        WHERE d.employee_id = $1 ${tipo !== null ? 'AND d.type = $2' : ''}
        ORDER BY d.year DESC, d.month DESC, d.created_at DESC, d.id DESC`, tipo !== null ? [employeeId, tipo] : [employeeId]);
    const personali = await this.ds.query(
      `SELECT ed.id, ed.name, ed.file_name, ed.original_name, ed.file_size, ed.mime_type, ed.created_at, ed.expires_on, ed.bulk_group,
              EXISTS (SELECT 1 FROM employee_document_downloads dd WHERE dd.employee_document_id = ed.id AND dd.user_type = 'employee' AND dd.user_id = $1) AS visto
         FROM employee_documents ed JOIN users u ON ed.uploaded_by = u.id
        WHERE ed.employee_id = $1 AND ed.visible_to_employee = true ORDER BY ed.created_at DESC, ed.id DESC`, [employeeId]);
    const anni = (await this.ds.query('SELECT DISTINCT year FROM documents WHERE employee_id = $1 ORDER BY year DESC', [employeeId])).map((r: any) => r.year);
    return { buste, personali, anni };
  }

  /** Busta paga / CU / altro documento: con filigrana se PDF. */
  async scaricaBusta(id: number, u: UtenteCorrente, ctx: ContestoAudit): Promise<EsitoDownload> {
    const [d] = await this.ds.query('SELECT d.*, e.department_id FROM documents d JOIN employees e ON e.id = d.employee_id WHERE d.id = $1', [id]);
    if (!d) return { ok: false, error: 'Documento non trovato' };
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    if (!(await this.puoVedere(d, u, false))) {
      await this.audit.log('unauthorized_access', tipo, u.id, ctx, null, null, null,
        u.tipo === 'employee' ? { resource: 'document', document_id: id, employee_id: u.id, owner_id: d.employee_id, action: 'download_attempt' }
          : { resource: 'document', document_id: id, user_id: u.id, reason: d.company_id !== u.companyId ? 'cross_tenant' : 'department_scope' }, 'critical');
      return { ok: false, error: 'Accesso non autorizzato' };
    }
    if (!(await this.limiti.consenti(`${tipo}_${u.id}`, 'download', 50, 3600))) {
      await this.audit.log('rate_limit_exceeded', tipo, u.id, ctx, 'document', id, null, { action: 'download', limit_type: 'hourly' }, 'warning');
      return { ok: false, error: 'Limite download raggiunto. Riprova tra 60 minuti.' };
    }
    const percorso = fileSicuro(d.file_path);
    if (!percorso) return { ok: false, error: 'File non trovato sul server' };
    await this.ds.query('INSERT INTO document_downloads (document_id, user_type, user_id, ip_address, user_agent) VALUES ($1, $2, $3, $4, $5)',
      [id, tipo, u.id, ctx.ip ?? null, ctx.userAgent ?? null]).catch(() => undefined);
    await this.audit.log('document_download', tipo, u.id, ctx, 'document', id, null,
      { title: d.title, type: d.type, employee_id: d.employee_id, file_size: d.file_size, mime_type: d.mime_type });
    await this.downloadMassivi(tipo, u.id, ctx);
    let contenuto: Buffer | null = null;
    if (d.mime_type === 'application/pdf') contenuto = await pdfConFiligrana(percorso, await this.testoFiligrana(u, ctx, id));
    return { ok: true, contenuto: contenuto ?? fs.readFileSync(percorso), nome: pulisciNome(d.original_name ?? d.file_name), mime: d.mime_type };
  }

  /** Documento personale o aziendale (nessuna filigrana, come il PHP). */
  async scaricaPersonale(id: number, u: UtenteCorrente, ctx: ContestoAudit): Promise<EsitoDownload> {
    const [d] = await this.ds.query('SELECT ed.*, e.department_id FROM employee_documents ed JOIN employees e ON e.id = ed.employee_id WHERE ed.id = $1', [id]);
    if (!d) return { ok: false, error: 'Documento non trovato' };
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    if (!(await this.puoVedere(d, u, true))) {
      await this.audit.log('unauthorized_access', tipo, u.id, ctx, null, null, null,
        u.tipo === 'employee'
          ? { resource: 'employee_document', document_id: id, employee_id: u.id, owner_id: d.employee_id, visible: d.visible_to_employee ? 1 : 0 }
          : { resource: 'employee_document', document_id: id, user_id: u.id, reason: d.company_id !== u.companyId ? 'cross_tenant' : 'department_scope' }, 'critical');
      return { ok: false, error: 'Accesso non autorizzato' };
    }
    if (!(await this.limiti.consenti(`${tipo}_${u.id}`, 'download', 50, 3600))) {
      await this.audit.log('rate_limit_exceeded', tipo, u.id, ctx, 'employee_document', id, null, { action: 'download', limit_type: 'hourly' }, 'warning');
      return { ok: false, error: 'Limite download raggiunto. Riprova tra 60 minuti.' };
    }
    const percorso = fileSicuro(d.file_path);
    if (!percorso) return { ok: false, error: 'File non trovato sul server' };
    await this.ds.query('INSERT INTO employee_document_downloads (employee_document_id, user_type, user_id, ip_address, user_agent) VALUES ($1, $2, $3, $4, $5)',
      [id, tipo, u.id, ctx.ip ?? null, ctx.userAgent ?? null]).catch(() => undefined);
    await this.audit.log('employee_document_downloaded', tipo, u.id, ctx, 'employee_document', id, null, { user_type: tipo, user_id: u.id });
    return { ok: true, contenuto: fs.readFileSync(percorso), nome: pulisciNome(d.original_name ?? d.file_name), mime: d.mime_type };
  }

  /**
   * Chi puo' scaricare: il dipendente solo i suoi (e i personali solo se
   * visibili); lo staff solo nell'azienda in cui lavora; il responsabile di
   * reparto solo il suo reparto (il PHP non lo controllava sulle buste paga:
   * falla corretta).
   */
  private async puoVedere(d: any, u: UtenteCorrente, personale: boolean): Promise<boolean> {
    if (u.tipo === 'employee') return d.employee_id === u.id && (!personale || d.visible_to_employee === true);
    if (d.company_id !== u.companyId) return false;
    if (u.ruolo === 'admin_reparto') return d.department_id === u.departmentId;
    return ['admin', 'accountant', 'consulente_lavoro'].includes(u.ruolo);
  }

  private async testoFiligrana(u: UtenteCorrente, ctx: ContestoAudit, id: number): Promise<string> {
    let chi = 'Unknown';
    if (u.tipo === 'employee') {
      const [e] = await this.ds.query('SELECT first_name, last_name, fiscal_code FROM employees WHERE id = $1', [u.id]);
      if (e) chi = `${e.first_name} ${e.last_name} (${e.fiscal_code})`;
    } else {
      const [x] = await this.ds.query('SELECT name, role FROM users WHERE id = $1', [u.id]);
      if (x) chi = `${x.name ?? 'Utente'} (${x.role ?? '-'})`;
    }
    return `Scaricato da: ${chi} | Data: ${adessoRoma()} | IP: ${ctx.ip ?? ''} | Doc ID: ${id}`;
  }

  /** Piu' di 30 download in un'ora: attivita' sospetta (AuditLog::checkMassDownload). */
  private async downloadMassivi(tipo: string, id: number, ctx: ContestoAudit) {
    const n = await this.audit.conta('document_download', tipo, id, 60);
    if (n > 30) {
      await this.audit.log('suspicious_activity', tipo, id, ctx, null, null, null, { description: 'Mass download detected', download_count: n, window: '1 hour' }, 'critical');
    }
  }
}
