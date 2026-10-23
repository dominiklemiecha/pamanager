import { Body, Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Query, Req, Res } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { ContrattoService } from './contratto.service';

/** Firma del contratto (employee/contract-sign.php). */
@Controller('dipendente/contratto')
@Ruoli('employee')
export class ContrattoController {
  constructor(private readonly contratto: ContrattoService, private readonly ds: DataSource) {}

  @Get(':id')
  async vista(@Param('id', ParseIntPipe) id: number, @Utente() u: UtenteCorrente) {
    const v = await this.contratto.vista(id, u.id);
    if (!v) throw new HttpException({ statusCode: 404, message: 'Contratto non trovato' }, 404);
    return v;
  }

  /** Immagine della firma (?action=signature del PHP). */
  @Get(':id/firma')
  async immagine(@Param('id', ParseIntPipe) id: number, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const f = (await this.contratto.pratica(id, u.id)) ? await this.contratto.file(id, 'signature_image') : null;
    const p = f ? this.contratto.percorso(f.file_path) : null;
    if (!p) { res.status(404).type('text/plain').send('Firma non trovata'); return; }
    res.set({ 'Content-Type': 'image/png', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'private, no-store' });
    res.sendFile(p);
  }

  @Post(':id/firma')
  @HttpCode(200)
  async firma(@Param('id', ParseIntPipe) id: number, @Body() b: { signature?: string }, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    if (!(await this.contratto.pratica(id, u.id))) throw new HttpException({ statusCode: 404, message: 'Contratto non trovato' }, 404);
    const [e] = await this.ds.query('SELECT id, first_name, last_name FROM employees WHERE id = $1', [u.id]);
    const r = await this.contratto.firma(id, e, String(b?.signature ?? ''), req.ip ?? '', String(req.headers['user-agent'] ?? ''));
    if (!r.success) throw new HttpException({ statusCode: 400, message: r.error }, 400);
    return { ok: true };
  }
}

/** PDF del contratto incorporabile nella pagina (employee/contract-pdf.php). */
@Controller('employee')
@Ruoli('employee')
export class ContrattoPdfController {
  constructor(private readonly contratto: ContrattoService) {}

  @Get('contract-pdf.php')
  async pdf(@Query('id') id: string, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const n = Number.parseInt(String(id ?? ''), 10) || 0;
    if (!(await this.contratto.pratica(n, u.id))) { res.status(404).type('text/plain').send('Non autorizzato'); return; }
    const f = await this.contratto.file(n, 'contract');
    if (!f) { res.status(404).type('text/plain').send('Contratto non disponibile'); return; }
    const p = this.contratto.percorso(f.file_path);
    if (!p) { res.status(404).type('text/plain').send('File non trovato'); return; }
    res.set({
      'X-Frame-Options': 'SAMEORIGIN', 'Content-Security-Policy': "default-src 'self'; frame-ancestors 'self'",
      'Content-Type': 'application/pdf', 'Content-Disposition': 'inline; filename="contratto.pdf"', 'X-Content-Type-Options': 'nosniff',
    });
    res.sendFile(p);
  }
}
