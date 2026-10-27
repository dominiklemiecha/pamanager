import { Injectable, Logger } from '@nestjs/common';
import { createHash } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';
import { DataSource } from 'typeorm';
import { cartellaUpload } from '../../config/app';
import { DocumentiService } from '../documenti/documenti.service';
import { escapeHtml } from '../notifiche/mailer.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { WrikeService } from '../wrike/wrike.service';

const MM = 72 / 25.4;
const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;

function adessoRoma(formato: 'dmyhm' | 'dmyhms'): string {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date()).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}` + (formato === 'dmyhms' ? `:${p.second}` : '');
}

/**
 * Firma del contratto di assunzione da parte del dipendente
 * (employee/contract-sign.php, contract-pdf.php, HireRequest::signContract).
 *
 * Correzioni: avvisati solo gli admin dell'azienda (o collegati, o globali
 * veri), non tutti quelli senza azienda; file solo dentro la cartella dei
 * caricamenti delle assunzioni.
 */
@Injectable()
export class ContrattoService {
  private readonly logger = new Logger('Contratto');
  constructor(private readonly ds: DataSource, private readonly documenti: DocumentiService, private readonly notifiche: NotificheService, private readonly wrike: WrikeService) {}

  /** Pratica del dipendente (404 se non sua). */
  async pratica(id: number, employeeId: number) {
    const [hr] = await this.ds.query('SELECT * FROM hire_requests WHERE id = $1', [id]);
    return hr && hr.employee_id === employeeId ? hr : null;
  }

  /** Contratto in attesa di firma: il dipendente non puo' usare il resto del portale. */
  async daFirmare(employeeId: number): Promise<number | null> {
    const [c] = await this.ds.query(`SELECT id FROM hire_requests WHERE employee_id = $1 AND status = 'contract_pending' ORDER BY id DESC LIMIT 1`, [employeeId]);
    return c?.id ?? null;
  }

  async file(id: number, categoria: 'contract' | 'signature_image') {
    const [f] = await this.ds.query(
      `SELECT id, file_path, ${ORA('uploaded_at')} AS uploaded_at, signed_ip, signature_hash FROM hire_request_files
        WHERE hire_request_id = $1 AND category = $2 ORDER BY id DESC LIMIT 1`, [id, categoria]);
    return f ?? null;
  }

  /** Percorso del file (HireRequest::fileFsPath), solo dentro la cartella delle assunzioni. */
  percorso(rel: string): string | null {
    const radice = cartellaUpload('hire-requests');
    const p = path.resolve(cartellaUpload(), String(rel).replace(/^\/+/, ''));
    return p.startsWith(radice + path.sep) && fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
  }

  async vista(id: number, employeeId: number) {
    const hr = await this.pratica(id, employeeId);
    if (!hr) return null;
    const contratto = await this.file(id, 'contract');
    const firma = await this.file(id, 'signature_image');
    const [az] = await this.ds.query('SELECT name FROM companies WHERE id = $1', [hr.company_id]);
    return {
      id, stato: hr.status, azienda: az?.name ?? 'Connecteed HR', contratto: !!contratto,
      firma: firma ? { uploaded_at: firma.uploaded_at, signed_ip: firma.signed_ip, signature_hash: firma.signature_hash } : null,
    };
  }

  async firma(id: number, emp: { id: number; first_name: string; last_name: string }, dataUrl: string, ip: string, userAgent: string): Promise<{ success: boolean; error?: string }> {
    const [hr] = await this.ds.query('SELECT * FROM hire_requests WHERE id = $1', [id]);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (hr.employee_id !== emp.id) return { success: false, error: 'Questo contratto non e tuo' };
    if (hr.status !== 'contract_pending') return { success: false, error: 'Stato non valido per firmare' };
    const m = /^data:image\/png;base64,(.+)$/s.exec(String(dataUrl ?? ''));
    if (!m) return { success: false, error: 'Firma non valida (atteso PNG base64)' };
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(m[1])) return { success: false, error: 'Firma troppo breve o corrotta' };
    const png = Buffer.from(m[1], 'base64');
    if (png.length < 200) return { success: false, error: 'Firma troppo breve o corrotta' };
    if (png.length > 2 * 1024 * 1024) return { success: false, error: 'Firma troppo grande' };
    if (!png.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return { success: false, error: 'Firma non valida (atteso PNG base64)' };

    const contratto = await this.file(id, 'contract');
    if (!contratto) return { success: false, error: 'Contratto non disponibile' };
    const pdfContratto = this.percorso(contratto.file_path);
    if (!pdfContratto) return { success: false, error: 'File contratto non trovato sul filesystem' };
    const hash = createHash('sha256').update(fs.readFileSync(pdfContratto)).digest('hex');

    const dirFirma = cartellaUpload('hire-requests', String(id), 'signature_image');
    fs.mkdirSync(dirFirma, { recursive: true, mode: 0o775 });
    const pngPath = path.join(dirFirma, 'signature.png');
    fs.writeFileSync(pngPath, png, { mode: 0o640 });

    const firmato = await this.contrattoFirmato(pdfContratto, png, hr.id, emp, ip, hash);
    await this.ds.query(
      `INSERT INTO hire_request_files (hire_request_id, category, file_path, original_name, mime_type, file_size, uploaded_by_employee_id, signed_ip, signed_user_agent, signature_hash)
       VALUES ($1, 'signature_image', $2, 'firma.png', 'image/png', $3, $4, $5, $6, $7)`,
      [id, `hire-requests/${id}/signature_image/signature.png`, png.length, emp.id, ip, [...userAgent].slice(0, 255).join(''), hash]);
    await this.ds.query(`UPDATE hire_requests SET status = 'contract_signed' WHERE id = $1`, [id]);

    const adesso = adessoRoma('dmyhm');
    const mese = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', month: 'numeric' }).format(new Date()));
    const anno = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric' }).format(new Date()));
    const r = await this.documenti.caricaDaPercorso(firmato ?? pdfContratto, {
      employee_id: emp.id, type: 'other', month: mese, year: anno, title: 'Contratto di assunzione firmato',
      description: `Firmato il ${adesso} - IP: ${ip} - SHA256 originale: ${hash.slice(0, 16)}...`, original_name: 'contratto-firmato.pdf',
      uploaded_by: hr.decided_by_user_id || hr.created_by_user_id || 0,
    });
    if (!r.success) this.logger.warn(`documento del contratto firmato non creato: ${r.error}`);

    const chi = `${emp.first_name} ${emp.last_name}`;
    try {
      const admin = await this.ds.query(
        `SELECT u.id FROM users u WHERE u.role = 'admin' AND u.is_active = true AND (u.company_id = $1
           OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = $1)
           OR u.is_global) ORDER BY u.id`, [hr.company_id]);
      for (const a of admin) {
        await this.notifiche.crea(hr.company_id, { recipient_type: 'admin', recipient_id: a.id, type: 'contract_signed', title: 'Contratto firmato', message: `${chi} ha firmato il contratto.`, link: `/admin/hire-requests.php?id=${id}` });
      }
      if (hr.assigned_consulente_user_id) {
        await this.notifiche.crea(hr.company_id, { recipient_type: 'consulente_lavoro', recipient_id: hr.assigned_consulente_user_id, type: 'contract_signed', title: 'Contratto firmato', message: `${chi} ha firmato il contratto.`, link: `/consulente-lavoro/hire-requests.php?id=${id}` });
        await this.wrike.passaggioAssunzione(hr.assigned_consulente_user_id, id, `🎉 <b>Contratto firmato</b> da ${escapeHtml(chi)} il ${adesso}. Pratica completata.`, true, 'contract_signed');
      }
    } catch (e: any) {
      this.logger.warn(`avvisi firma: ${e?.message ?? e}`);
    }
    return { success: true };
  }

  /**
   * Contratto con la firma sull'ultima pagina (in basso a destra, 65x22 mm) e
   * i dati legali sotto, come buildSignedContractPdf del PHP. null se il PDF
   * non si apre: si conserva l'originale.
   */
  private async contrattoFirmato(sorgente: string, png: Buffer, hrId: number, emp: { id: number; first_name: string; last_name: string }, ip: string, hash: string): Promise<string | null> {
    try {
      const doc = await PDFDocument.load(fs.readFileSync(sorgente), { ignoreEncryption: true });
      const img = await doc.embedPng(png);
      const normale = await doc.embedFont(StandardFonts.Helvetica);
      const grassetto = await doc.embedFont(StandardFonts.HelveticaBold);
      const pagine = doc.getPages();
      const p = pagine[pagine.length - 1];
      const { width } = p.getSize();
      const [w, h, mr, mb] = [65 * MM, 22 * MM, 12 * MM, 14 * MM];
      const x = width - w - mr;
      p.drawImage(img, { x, y: mb, width: w, height: h });
      const grigio = rgb(60 / 255, 60 / 255, 60 / 255);
      // Celle TCPDF sotto la firma: 4, 4, 3, 3 mm (testo sulla linea di base di ciascuna).
      let alto = mb - 0.5 * MM;
      const riga = (t: string, font: typeof normale, size: number, hCella: number) => {
        alto -= hCella * MM;
        p.drawText(t, { x: x + 1 * MM, y: alto + (hCella * MM - size * 0.7) / 2, size, font, color: grigio });
      };
      riga('Firmato digitalmente da:', grassetto, 8, 4);
      riga(`${emp.first_name} ${emp.last_name}`, normale, 8, 4);
      riga(`${adessoRoma('dmyhms')} - IP ${ip}`, normale, 6.5, 3);
      riga(`SHA256: ${hash.slice(0, 32)}...`, normale, 6.5, 3);
      const dir = cartellaUpload('hire-requests', String(hrId), 'signed_contract');
      fs.mkdirSync(dir, { recursive: true, mode: 0o775 });
      const out = path.join(dir, 'contratto-firmato.pdf');
      const dati = Buffer.from(await doc.save());
      fs.writeFileSync(out, dati);
      await this.ds.query(
        `INSERT INTO hire_request_files (hire_request_id, category, file_path, original_name, mime_type, file_size, uploaded_by_employee_id, signed_ip, signature_hash)
         VALUES ($1, 'signed_contract', $2, 'contratto-firmato.pdf', 'application/pdf', $3, $4, $5, $6)`,
        [hrId, `hire-requests/${hrId}/signed_contract/contratto-firmato.pdf`, dati.length, emp.id, ip, hash]);
      return out;
    } catch (e: any) {
      this.logger.warn(`contratto firmato non generato: ${e?.message ?? e}`);
      return null;
    }
  }
}
