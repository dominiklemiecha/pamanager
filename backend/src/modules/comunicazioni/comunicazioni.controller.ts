import { Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Query, Res } from '@nestjs/common';
import type { Response } from 'express';
import { Ruoli, Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';
import { ComunicazioniService } from './comunicazioni.service';

/** Comunicazioni del dipendente (employee/communications.php). */
@Controller('dipendente/comunicazioni')
@Ruoli('employee')
export class ComunicazioniDipendenteController {
  constructor(private readonly comunicazioni: ComunicazioniService) {}

  @Get()
  elenco(@Utente() u: UtenteCorrente) {
    return this.comunicazioni.elenco(u);
  }

  /** Comunicazione aperta: 404 se non visibile (il PHP mostrava l'elenco). */
  @Get(':id')
  async apri(@Param('id', ParseIntPipe) id: number, @Utente() u: UtenteCorrente) {
    const c = await this.comunicazioni.apri(id, u);
    if (!c) throw new HttpException({ statusCode: 404, message: 'Comunicazione non trovata' }, 404);
    return c;
  }

  @Post(':id/conferma')
  @HttpCode(200)
  async conferma(@Param('id', ParseIntPipe) id: number, @Utente() u: UtenteCorrente) {
    if (!(await this.comunicazioni.conferma(id, u))) throw new HttpException({ statusCode: 404, message: 'Comunicazione non trovata' }, 404);
    return { ok: true };
  }
}

/** Allegati e immagini delle comunicazioni allo stesso indirizzo del PHP. */
@Controller()
export class AllegatiComunicazioniController {
  constructor(private readonly comunicazioni: ComunicazioniService) {}

  @Get('communication_file.php')
  async file(@Query('id') id: string, @Query('inline') inline: string, @Res() res: Response, @Utente() u: UtenteCorrente) {
    const n = Number.parseInt(String(id ?? ''), 10) || 0;
    if (!n) { res.status(400).type('text/plain').send('id mancante'); return; }
    const r = await this.comunicazioni.allegato(n, u);
    if (!r.ok) { res.status(r.status).type('text/plain').send(r.testo); return; }
    const inLinea = !!inline || r.mime.startsWith('image/');
    res.set({
      'X-Content-Type-Options': 'nosniff',
      'Content-Type': r.mime,
      'Content-Disposition': `${inLinea ? 'inline' : 'attachment'}; filename="${r.nome}"`,
      'Cache-Control': 'private, max-age=60',
      // Aperto da solo nel browser il file non esegue nulla (es. SVG con script).
      'Content-Security-Policy': "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
    });
    res.sendFile(r.percorso);
  }
}
