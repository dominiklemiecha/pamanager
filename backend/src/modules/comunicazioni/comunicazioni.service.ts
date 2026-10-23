import { Injectable } from '@nestjs/common';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { sanitizzaHtmlRicco } from '../../common/html/sanitizza';
import { cartellaStorage } from '../../config/app';
import type { UtenteCorrente } from '../auth/auth.types';
import { DipendenteService } from '../dipendente/dipendente.service';

const ALTA = ['high', 'urgent'];

/**
 * Comunicazioni dal lato del dipendente (employee/communications.php e
 * _cora/communications.inc.php) e allegati (communication_file.php).
 */
@Injectable()
export class ComunicazioniService {
  constructor(private readonly ds: DataSource, private readonly dipendente: DipendenteService) {}

  /**
   * Elenco: comunicazioni attive dal giorno di assunzione.
   * Correzione: il PHP mostrava in questa pagina anche quelle riservate ad
   * altri reparti; qui vale lo stesso filtro della Home e del contatore.
   */
  async elenco(u: UtenteCorrente) {
    const righe = await this.dipendente.comunicazioniAttive(u.id, u.companyId!, u.departmentId ?? null);
    return righe.map((c: any) => ({
      id: c.id, title: c.title, priority: c.priority, publish_date: c.publish_date, author_name: c.author_name, is_read: c.is_read ? 1 : null,
    }));
  }

  /**
   * Comunicazione aperta, se il dipendente la puo' vedere: pubblicata, nella
   * finestra di validita', della sua azienda e (correzione) del suo reparto
   * se riservata. Le comunicazioni ad alta priorita' chiedono la conferma
   * esplicita, le altre risultano lette all'apertura.
   */
  async apri(id: number, u: UtenteCorrente) {
    const c = await this.visibile(id, u);
    if (!c) return null;
    const conferma = ALTA.includes(c.priority);
    if (!conferma) await this.segnaLetta(id, u.id);
    const [letta] = await this.ds.query('SELECT 1 FROM communication_reads WHERE communication_id = $1 AND employee_id = $2', [id, u.id]);
    const allegati = await this.ds.query(
      'SELECT id, original_name, mime_type, size_bytes FROM communication_attachments WHERE communication_id = $1 AND is_inline_image = false ORDER BY id ASC', [id]);
    return {
      id: c.id, title: c.title, priority: c.priority, publish_date: c.publish_date, author_name: c.author_name,
      content: sanitizzaHtmlRicco(c.content), letta: !!letta, allegati,
    };
  }

  /** "Ho letto" delle comunicazioni ad alta priorita'. */
  async conferma(id: number, u: UtenteCorrente): Promise<boolean> {
    const c = await this.visibile(id, u);
    if (!c || !ALTA.includes(c.priority)) return false;
    await this.segnaLetta(id, u.id);
    return true;
  }

  private async segnaLetta(id: number, employeeId: number) {
    await this.ds.query('INSERT INTO communication_reads (communication_id, employee_id) VALUES ($1, $2) ON CONFLICT DO NOTHING', [id, employeeId]);
  }

  private async visibile(id: number, u: UtenteCorrente) {
    const [c] = await this.ds.query('SELECT c.*, u.name AS author_name FROM communications c JOIN users u ON c.created_by = u.id WHERE c.id = $1', [id]);
    if (!c || !this.inFinestra(c)) return null;
    if (c.company_id !== u.companyId) return null;
    if (!c.is_global && c.department_id !== null && c.department_id !== (u.departmentId ?? null)) return null;
    return c;
  }

  private inFinestra(c: any): boolean {
    const oggi = oggiRoma();
    return !!c.is_published && c.publish_date <= oggi && !(c.expire_date && c.expire_date < oggi);
  }

  /**
   * Allegato o immagine nel testo (communication_file.php).
   * Correzione: il PHP non controllava l'azienda, chiunque fosse collegato
   * scaricava gli allegati di tutte le aziende conoscendo l'id. Ora: il
   * dipendente solo se vede la comunicazione, lo staff solo della sua azienda.
   */
  async allegato(id: number, u: UtenteCorrente): Promise<{ ok: true; percorso: string; nome: string; mime: string } | { ok: false; status: number; testo: string }> {
    if (u.tipo !== 'employee' && u.ruolo === 'formatore') return { ok: false, status: 403, testo: 'Non autorizzato' };
    const [a] = await this.ds.query('SELECT * FROM communication_attachments WHERE id = $1', [id]);
    if (!a) return { ok: false, status: 404, testo: 'Non trovato' };
    const [c] = await this.ds.query('SELECT * FROM communications WHERE id = $1', [a.communication_id]);
    if (!c) return { ok: false, status: 404, testo: 'Non trovato' };
    if (u.tipo === 'employee') {
      if (!this.inFinestra(c)) return { ok: false, status: 403, testo: 'Non accessibile' };
      if (!(await this.visibile(c.id, u))) return { ok: false, status: 404, testo: 'Non trovato' };
    } else if (c.company_id !== u.companyId) {
      return { ok: false, status: 404, testo: 'Non trovato' };
    }
    const dir = cartellaStorage('communications', String(c.id));
    const percorso = path.resolve(dir, path.basename(String(a.stored_name)));
    if (!percorso.startsWith(dir + path.sep) || !fs.existsSync(percorso)) return { ok: false, status: 404, testo: 'File mancante' };
    return { ok: true, percorso, nome: String(a.original_name).replace(/[^a-zA-Z0-9._-]/g, '_'), mime: a.mime_type || 'application/octet-stream' };
  }
}
