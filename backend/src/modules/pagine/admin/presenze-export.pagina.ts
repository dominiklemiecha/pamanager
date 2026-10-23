import { Injectable, Logger } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { giorniNelMese, oggiRoma } from '../../../common/calcoli/php';
import { PresenzeExportService } from '../../presenze/presenze-export.service';
import { ContestoPagina, PaginaPhp, Pagina, vista } from '../pagina';

const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

/** Mese e anno scelti, come il PHP (mese 1-12, anno 2024-2099, altrimenti oggi). */
function periodo(q: Record<string, string>) {
  const oggi = oggiRoma();
  const intero = (v: unknown) => Number.parseInt(String(v), 10) || 0;
  const mese = q.month !== undefined ? Math.max(1, Math.min(12, intero(q.month))) : Number(oggi.slice(5, 7));
  const anno = q.year !== undefined ? Math.max(2024, Math.min(2099, intero(q.year))) : Number(oggi.slice(0, 4));
  return { mese, anno, oggi };
}

/**
 * Export presenze per il consulente paghe (admin/presenze-export.php).
 * Correzione: in caso di errore il dettaglio tecnico resta nei log.
 */
@Injectable()
@PaginaPhp('admin/presenze-export')
export class PresenzeExportPagina implements Pagina {
  readonly ruoli = ['admin'];
  private readonly logger = new Logger('PresenzeExport');
  constructor(private readonly ds: DataSource, private readonly presenze: PresenzeExportService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.action !== 'download') return false;
    const { mese, anno } = periodo(c.query);
    try {
      const bin = await this.presenze.genera(c.u.companyId!, mese, anno);
      res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      res.setHeader('Content-Disposition', `attachment; filename="${this.presenze.nomeFile(mese, anno)}"`);
      res.setHeader('Cache-Control', 'no-store');
      res.send(bin);
    } catch (e: any) {
      this.logger.error(`export presenze non riuscito: ${e?.stack ?? e}`);
      res.status(500).type('html').send('<h2>Errore generazione file</h2><p><a href="presenze-export.php">Torna indietro</a></p>');
    }
    return true;
  }

  async dati(c: ContestoPagina) {
    const { mese, anno, oggi } = periodo(c.query);
    const inizio = `${anno}-${String(mese).padStart(2, '0')}-01`;
    const fine = `${anno}-${String(mese).padStart(2, '0')}-${String(giorniNelMese(anno, mese)).padStart(2, '0')}`;
    const [r] = await this.ds.query(
      `SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'pending' AND start_date <= $2 AND end_date >= $3`, [c.u.companyId, fine, inizio]);
    return vista('Export presenze', {
      selMonth: mese, selYear: anno, pendingCount: r.n, months: MESI, now: oggi,
    });
  }
}
