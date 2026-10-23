import { Controller, Get, Param, ParseIntPipe, Query, Req, Res } from '@nestjs/common';
import type { Response } from 'express';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { DocumentiService, EsitoDownload } from './documenti.service';

/** Codici d'errore passati alla pagina: niente testo libero nell'indirizzo. */
const CODICI: Record<string, string> = {
  'Documento non trovato': 'non_trovato',
  'Accesso non autorizzato': 'non_autorizzato',
  'Limite download raggiunto. Riprova tra 60 minuti.': 'limite',
  'File non trovato sul server': 'file_mancante',
};

const ctx = (req: RichiestaAutenticata, u: UtenteCorrente) => ({ ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId });

/** Documenti del dipendente (employee/documents.php). */
@Controller('dipendente/documenti')
@Ruoli('employee')
export class DocumentiDipendenteController {
  constructor(private readonly documenti: DocumentiService) {}

  @Get()
  elenco(@Utente() u: UtenteCorrente, @Query('type') tipo?: string) {
    return this.documenti.perDipendente(u.id, tipo ? String(tipo) : null);
  }

  /** Busta paga, CU o altro (?download= del PHP), con filigrana sui PDF. */
  @Get(':id/scarica')
  async busta(@Param('id', ParseIntPipe) id: number, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    invia(res, await this.documenti.scaricaBusta(id, u, ctx(req, u)));
  }

  /** Documento personale o aziendale (?download_personal= del PHP). */
  @Get('personali/:id/scarica')
  async personale(@Param('id', ParseIntPipe) id: number, @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    invia(res, await this.documenti.scaricaPersonale(id, u, ctx(req, u)));
  }
}

/** Intestazioni di setDownloadHeaders() del PHP; in caso di errore si torna alla pagina. */
function invia(res: Response, r: EsitoDownload) {
  if (!r.ok) {
    res.redirect(302, `/employee/documents.php?errore=${CODICI[r.error] ?? 'non_autorizzato'}`);
    return;
  }
  res.set({
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Content-Disposition': `attachment; filename="${r.nome}"`,
    'Content-Type': r.mime || 'application/octet-stream',
    'Content-Length': String(r.contenuto.length),
    'Cache-Control': 'private, no-cache, no-store, must-revalidate',
    Pragma: 'no-cache',
    Expires: '0',
    'Content-Security-Policy': "default-src 'none'",
  });
  res.end(r.contenuto);
}
